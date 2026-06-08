<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Offers;

use FFLHub\Distributor\Services\Zanders\Tables\ZandersProductTableSchema;
use FFLHub\Product\State\ProductStateStore;

if (!defined('ABSPATH')) {
    exit;
}

final class DistributorOffersStore
{
    private const SCHEMA_OPTION = 'fflhub_distributor_offers_schema_version';
    private const SCHEMA_VERSION = '2';
    private const TABLE_SUFFIX = 'fflhub_distributor_offers';
    private const DIST_ZANDERS = 'zanders';

    public static function table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . self::TABLE_SUFFIX);
    }

    public static function ensure_schema(): void
    {
        global $wpdb;

        if (!$wpdb) {
            return;
        }

        $installed = (string) get_option(self::SCHEMA_OPTION, '');
        if ($installed === self::SCHEMA_VERSION && self::table_exists() && self::has_expected_indexes()) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = (string) $wpdb->get_charset_collate();

        dbDelta("
            CREATE TABLE {$table} (
                upc VARCHAR(32) NOT NULL,
                distributor_id VARCHAR(64) NOT NULL,
                distributor_product_id VARCHAR(128) DEFAULT NULL,
                distributor_sku VARCHAR(128) DEFAULT NULL,
                qty INT UNSIGNED NOT NULL DEFAULT 0,
                stock_status VARCHAR(32) DEFAULT NULL,
                dealer_price DECIMAL(12,4) DEFAULT NULL,
                shipping_cost DECIMAL(12,4) DEFAULT NULL,
                landed_cost DECIMAL(12,4) DEFAULT NULL,
                map_price DECIMAL(12,4) DEFAULT NULL,
                msrp DECIMAL(12,4) DEFAULT NULL,
                ffl_required TINYINT(1) NOT NULL DEFAULT 0,
                sot_required TINYINT(1) NOT NULL DEFAULT 0,
                dropship_enabled TINYINT(1) NOT NULL DEFAULT 1,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                shipping_weight_oz DECIMAL(10,3) DEFAULT NULL,
                shipping_length_in DECIMAL(10,3) DEFAULT NULL,
                shipping_width_in DECIMAL(10,3) DEFAULT NULL,
                shipping_height_in DECIMAL(10,3) DEFAULT NULL,
                source_updated_at DATETIME DEFAULT NULL,
                normalized_at DATETIME NOT NULL,
                PRIMARY KEY  (upc, distributor_id),
                KEY distributor_upc (distributor_id, upc),
                KEY distributor_product_id (distributor_id, distributor_product_id),
                KEY upc_available_landed (upc, enabled, dropship_enabled, qty, landed_cost),
                KEY normalized_at (normalized_at)
            ) {$charset};
        ");

        self::ensure_indexes($table);
        self::drop_true_cost_column_if_exists($table);

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
    }

    public static function table_exists(): bool
    {
        global $wpdb;

        if (!$wpdb) {
            return false;
        }

        $table = self::table_name();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($found) && $found === $table;
    }

    /**
     * Normalize the current live Zanders product table into distributor offers.
     *
     * This is intentionally set-based and only touches rows for UPCs that are
     * already present in active product_state.
     *
     * @return array<string,mixed>
     */
    public static function normalize_zanders_offers(): array
    {
        global $wpdb;

        $started = microtime(true);
        $result = [
            'ok' => false,
            'source_live_table' => '',
            'matched_active_upc_count' => 0,
            'upsert_affected_rows' => 0,
            'stale_disabled' => 0,
            'elapsed_ms' => '0.00',
            'elapsed_sec' => '0.000',
            'errors' => [],
        ];

        if (!$wpdb) {
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        self::ensure_schema();
        ProductStateStore::ensure_schema();

        $live_table = self::current_zanders_live_table();
        if ($live_table === '') {
            $result['errors'][] = 'Could not resolve a valid live Zanders product table.';
            return self::finish_result($result, $started);
        }

        if (!self::table_exists_by_name($live_table)) {
            $result['errors'][] = 'Resolved live Zanders product table does not exist: ' . $live_table;
            $result['source_live_table'] = $live_table;
            return self::finish_result($result, $started);
        }

        $offers_table = self::table_name();
        $product_state_table = ProductStateStore::table_name();
        $has_product_normalized_at = self::table_has_column($offers_table, 'product_normalized_at');

        $result['source_live_table'] = $live_table;

        $matched_sql = $wpdb->prepare(
            "
                SELECT COUNT(*)
                FROM {$live_table} z
                INNER JOIN {$product_state_table} ps
                    ON ps.upc = z.upc
                WHERE ps.status = %s
                  AND z.upc <> ''
            ",
            'active'
        );
        $result['matched_active_upc_count'] = (int) $wpdb->get_var($matched_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $product_normalized_insert = $has_product_normalized_at ? ",\n                product_normalized_at" : '';
        $product_normalized_select = $has_product_normalized_at ? ",\n                NOW()" : '';
        $product_normalized_update = $has_product_normalized_at ? ",\n                product_normalized_at = VALUES(product_normalized_at)" : '';
        $product_normalized_stale = $has_product_normalized_at ? ",\n                o.product_normalized_at = NOW()" : '';

        $dealer_price_expr = "CAST(NULLIF(TRIM(z.distributor_price), '') AS DECIMAL(12,4))";
        $shipping_cost_expr = "CAST(NULLIF(TRIM(z.shipping_cost), '') AS DECIMAL(12,4))";
        $landed_cost_expr = "
            CASE
                WHEN {$dealer_price_expr} IS NULL THEN NULL
                ELSE {$dealer_price_expr} + COALESCE({$shipping_cost_expr}, 0.0000)
            END
        ";

        $upsert_sql = $wpdb->prepare(
            "
                INSERT INTO {$offers_table} (
                    upc,
                    distributor_id,
                    distributor_product_id,
                    distributor_sku,
                    qty,
                    stock_status,
                    dealer_price,
                    shipping_cost,
                    landed_cost,
                    map_price,
                    msrp,
                    ffl_required,
                    sot_required,
                    dropship_enabled,
                    enabled,
                    shipping_weight_oz,
                    normalized_at
                    {$product_normalized_insert}
                )
                SELECT
                    z.upc,
                    %s AS distributor_id,
                    NULLIF(TRIM(z.zanders_item_number), '') AS distributor_product_id,
                    NULLIF(TRIM(z.zanders_item_number), '') AS distributor_sku,
                    CAST(COALESCE(NULLIF(TRIM(z.inventory_quantity), ''), '0') AS UNSIGNED) AS qty,
                    CASE
                        WHEN CAST(COALESCE(NULLIF(TRIM(z.inventory_quantity), ''), '0') AS UNSIGNED) > 0 THEN 'instock'
                        ELSE 'outofstock'
                    END AS stock_status,
                    {$dealer_price_expr} AS dealer_price,
                    {$shipping_cost_expr} AS shipping_cost,
                    {$landed_cost_expr} AS landed_cost,
                    CAST(NULLIF(TRIM(z.retail_map), '') AS DECIMAL(12,4)) AS map_price,
                    CAST(NULLIF(TRIM(z.retail_msrp), '') AS DECIMAL(12,4)) AS msrp,
                    CAST(COALESCE(z.ffl_required, 0) AS UNSIGNED) AS ffl_required,
                    CAST(COALESCE(z.sot_required, 0) AS UNSIGNED) AS sot_required,
                    CAST(COALESCE(z.dropship_enabled, 1) AS UNSIGNED) AS dropship_enabled,
                    1 AS enabled,
                    z.shipping_weight AS shipping_weight_oz,
                    NOW() AS normalized_at
                    {$product_normalized_select}
                FROM {$live_table} z
                INNER JOIN {$product_state_table} ps
                    ON ps.upc = z.upc
                WHERE ps.status = %s
                  AND z.upc <> ''
                ON DUPLICATE KEY UPDATE
                    distributor_product_id = VALUES(distributor_product_id),
                    distributor_sku = VALUES(distributor_sku),
                    qty = VALUES(qty),
                    stock_status = VALUES(stock_status),
                    dealer_price = VALUES(dealer_price),
                    shipping_cost = VALUES(shipping_cost),
                    landed_cost = VALUES(landed_cost),
                    map_price = VALUES(map_price),
                    msrp = VALUES(msrp),
                    ffl_required = VALUES(ffl_required),
                    sot_required = VALUES(sot_required),
                    dropship_enabled = VALUES(dropship_enabled),
                    enabled = VALUES(enabled),
                    shipping_weight_oz = VALUES(shipping_weight_oz),
                    normalized_at = VALUES(normalized_at)
                    {$product_normalized_update}
            ",
            self::DIST_ZANDERS,
            'active'
        );

        $upserted = $wpdb->query($upsert_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($upserted === false) {
            $result['errors'][] = 'Zanders offer upsert failed: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['upsert_affected_rows'] = is_numeric($upserted) ? (int) $upserted : 0;

        $stale_sql = $wpdb->prepare(
            "
                UPDATE {$offers_table} o
                INNER JOIN {$product_state_table} ps
                    ON ps.upc = o.upc
                   AND ps.status = %s
                LEFT JOIN {$live_table} z
                    ON z.upc = o.upc
                   AND z.upc <> ''
                SET
                    o.enabled = 0,
                    o.dropship_enabled = 0,
                    o.qty = 0,
                    o.stock_status = 'outofstock',
                    o.normalized_at = NOW()
                    {$product_normalized_stale}
                WHERE o.distributor_id = %s
                  AND z.upc IS NULL
            ",
            'active',
            self::DIST_ZANDERS
        );

        $stale_disabled = $wpdb->query($stale_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($stale_disabled === false) {
            $result['errors'][] = 'Zanders stale offer cleanup failed: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['stale_disabled'] = is_numeric($stale_disabled) ? (int) $stale_disabled : 0;
        $result['ok'] = true;

        return self::finish_result($result, $started);
    }

    private static function has_expected_indexes(): bool
    {
        global $wpdb;

        $table = self::table_name();
        $rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if (!is_array($rows)) {
            return false;
        }

        $keys = [];
        foreach ($rows as $row) {
            $key = isset($row['Key_name']) ? (string) $row['Key_name'] : '';
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        foreach (self::expected_index_names() as $name) {
            if (!isset($keys[$name])) {
                return false;
            }
        }

        return true;
    }

    private static function current_zanders_live_table(): string
    {
        global $wpdb;

        $v1 = (string) ($wpdb->prefix . ZandersProductTableSchema::BASE_TABLE_KEY . '_v1');
        $v2 = (string) ($wpdb->prefix . ZandersProductTableSchema::BASE_TABLE_KEY . '_v2');
        $stored = get_option(ZandersProductTableSchema::LIVE_TABLE_OPTION, '');

        if ($stored === $v1 || $stored === $v2) {
            return (string) $stored;
        }

        if ($stored === 'v1') {
            return $v1;
        }

        if ($stored === 'v2') {
            return $v2;
        }

        if ($stored === '' || $stored === false || $stored === null) {
            return $v1;
        }

        return '';
    }

    private static function table_exists_by_name(string $table): bool
    {
        global $wpdb;

        if ($table === '') {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($found) && $found === $table;
    }

    private static function table_has_column(string $table, string $column): bool
    {
        global $wpdb;

        if ($table === '' || $column === '') {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_string($found) && $found === $column;
    }

    private static function drop_true_cost_column_if_exists(string $table): void
    {
        global $wpdb;

        if ($table === '' || !self::table_has_column($table, 'true_cost')) {
            return;
        }

        $wpdb->query("ALTER TABLE {$table} DROP COLUMN true_cost"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function finish_result(array $result, float $started): array
    {
        $elapsed_ms = (microtime(true) - $started) * 1000.0;
        $result['elapsed_ms'] = number_format($elapsed_ms, 2, '.', '');
        $result['elapsed_sec'] = number_format($elapsed_ms / 1000.0, 3, '.', '');

        return $result;
    }

    private static function ensure_indexes(string $table): void
    {
        global $wpdb;

        $rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if (!is_array($rows)) {
            return;
        }

        $keys = [];
        foreach ($rows as $row) {
            $key = isset($row['Key_name']) ? (string) $row['Key_name'] : '';
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        $missing_indexes = [
            'distributor_upc' => 'ADD KEY distributor_upc (distributor_id, upc)',
            'distributor_product_id' => 'ADD KEY distributor_product_id (distributor_id, distributor_product_id)',
            'upc_available_landed' => 'ADD KEY upc_available_landed (upc, enabled, dropship_enabled, qty, landed_cost)',
            'normalized_at' => 'ADD KEY normalized_at (normalized_at)',
        ];

        foreach ($missing_indexes as $name => $definition) {
            if (isset($keys[$name])) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} {$definition}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    /**
     * @return string[]
     */
    private static function expected_index_names(): array
    {
        return [
            'PRIMARY',
            'distributor_upc',
            'distributor_product_id',
            'upc_available_landed',
            'normalized_at',
        ];
    }
}
