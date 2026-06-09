<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Zanders;

use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Distributor\Services\Zanders\Tables\ZandersProductTableSchema;
use FFLHub\Product\State\ProductStateStore;

if (!defined('ABSPATH')) {
    exit;
}

final class ZandersOfferNormalizationService
{
    private const DIST_ID = 'zanders';

    /**
     * Update existing normalized Zanders offer rows from the current/new live
     * Zanders product table.
     *
     * This is the product-cron path. It intentionally does not read
     * product_state and never inserts new offer rows.
     *
     * @return array<string,mixed>
     */
    public static function update_existing_from_product_table(?string $source_live_table = null): array
    {
        global $wpdb;

        $started = microtime(true);
        $result = [
            'ok' => false,
            'source_live_table' => '',
            'matched_existing_zanders_offers' => 0,
            'product_update_rows' => 0,
            'stale_disabled' => 0,
            'product_update_elapsed_ms' => '0.00',
            'stale_cleanup_elapsed_ms' => '0.00',
            'elapsed_ms' => '0.00',
            'elapsed_sec' => '0.000',
            'errors' => [],
        ];

        if (!$wpdb) {
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        DistributorOffersStore::ensure_schema();

        $live_table = self::resolve_live_table($source_live_table);
        if ($live_table === '') {
            $result['errors'][] = 'Could not resolve a valid live Zanders product table.';
            return self::finish_result($result, $started);
        }

        if (!self::table_exists_by_name($live_table)) {
            $result['errors'][] = 'Resolved live Zanders product table does not exist: ' . $live_table;
            $result['source_live_table'] = $live_table;
            return self::finish_result($result, $started);
        }

        $offers_table = DistributorOffersStore::table_name();
        $has_product_normalized_at = self::table_has_column($offers_table, 'product_normalized_at');
        $has_dropship_block_reason = self::table_has_column($offers_table, 'dropship_block_reason');

        $result['source_live_table'] = $live_table;

        $matched_sql = $wpdb->prepare(
            "
                SELECT COUNT(*)
                FROM {$offers_table} o
                INNER JOIN {$live_table} z
                    ON z.zanders_item_number = o.distributor_product_id
                WHERE o.distributor_id = %s
            ",
            self::DIST_ID
        );
        $result['matched_existing_zanders_offers'] = (int) $wpdb->get_var($matched_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $qty_expr = "CAST(COALESCE(NULLIF(TRIM(z.inventory_quantity), ''), '0') AS UNSIGNED)";
        $dealer_price_expr = "CAST(NULLIF(TRIM(z.distributor_price), '') AS DECIMAL(12,4))";
        $shipping_cost_expr = "CAST(NULLIF(TRIM(z.shipping_cost), '') AS DECIMAL(12,4))";
        $landed_cost_expr = "
            CASE
                WHEN {$dealer_price_expr} IS NULL THEN NULL
                ELSE {$dealer_price_expr} + COALESCE({$shipping_cost_expr}, 0.0000)
            END
        ";

        $set = [
            "o.distributor_product_id = NULLIF(TRIM(z.zanders_item_number), '')",
            "o.distributor_sku = NULLIF(TRIM(z.zanders_item_number), '')",
            "o.manufacturer_norm = NULLIF(TRIM(z.manufacturer_norm), '')",
            "o.qty = {$qty_expr}",
            "o.stock_status = CASE WHEN {$qty_expr} > 0 THEN 'instock' ELSE 'outofstock' END",
            "o.dealer_price = {$dealer_price_expr}",
            "o.shipping_cost = {$shipping_cost_expr}",
            "o.landed_cost = {$landed_cost_expr}",
            "o.map_price = CAST(NULLIF(TRIM(z.retail_map), '') AS DECIMAL(12,4))",
            "o.msrp = CAST(NULLIF(TRIM(z.retail_msrp), '') AS DECIMAL(12,4))",
            'o.ffl_required = CAST(COALESCE(z.ffl_required, 0) AS UNSIGNED)',
            'o.sot_required = CAST(COALESCE(z.sot_required, 0) AS UNSIGNED)',
            'o.dropship_enabled = CAST(COALESCE(z.dropship_enabled, 1) AS UNSIGNED)',
            'o.enabled = 1',
            "o.shipping_weight_oz = CAST(NULLIF(TRIM(z.shipping_weight), '') AS DECIMAL(10,3))",
            'o.normalized_at = NOW()',
        ];

        if ($has_dropship_block_reason) {
            $set[] = "o.dropship_block_reason = NULLIF(TRIM(z.dropship_block_reason), '')";
        }

        if ($has_product_normalized_at) {
            $set[] = 'o.product_normalized_at = NOW()';
        }

        $t_update = microtime(true);
        $update_sql = $wpdb->prepare(
            "
                UPDATE {$offers_table} o
                INNER JOIN {$live_table} z
                    ON z.zanders_item_number = o.distributor_product_id
                SET
                    " . implode(",\n                    ", $set) . "
                WHERE o.distributor_id = %s
            ",
            self::DIST_ID
        );

        $updated = $wpdb->query($update_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($updated === false) {
            $result['errors'][] = 'Zanders existing offer product update failed: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['product_update_rows'] = is_numeric($updated) ? (int) $updated : 0;
        $result['product_update_elapsed_ms'] = number_format((microtime(true) - $t_update) * 1000.0, 2, '.', '');

        $t_stale = microtime(true);
        $stale_set = [
            'o.enabled = 0',
            'o.dropship_enabled = 0',
            'o.qty = 0',
            "o.stock_status = 'outofstock'",
            'o.normalized_at = NOW()',
        ];

        if ($has_product_normalized_at) {
            $stale_set[] = 'o.product_normalized_at = NOW()';
        }

        $stale_sql = $wpdb->prepare(
            "
                UPDATE {$offers_table} o
                LEFT JOIN {$live_table} z
                    ON z.zanders_item_number = o.distributor_product_id
                SET
                    " . implode(",\n                    ", $stale_set) . "
                WHERE o.distributor_id = %s
                  AND z.zanders_item_number IS NULL
            ",
            self::DIST_ID
        );

        $stale_disabled = $wpdb->query($stale_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($stale_disabled === false) {
            $result['errors'][] = 'Zanders existing offer stale cleanup failed: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['stale_disabled'] = is_numeric($stale_disabled) ? (int) $stale_disabled : 0;
        $result['stale_cleanup_elapsed_ms'] = number_format((microtime(true) - $t_stale) * 1000.0, 2, '.', '');
        $result['ok'] = true;

        return self::finish_result($result, $started);
    }

    /**
     * Apply loaded Zanders inventory stage rows to existing normalized offer rows.
     *
     * This is the inventory-cron path. It intentionally updates only volatile
     * fields and never inserts rows.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    public static function update_existing_from_inventory_stage(string $stage_table): array
    {
        global $wpdb;

        if (!$wpdb) {
            throw new \RuntimeException('WordPress database connection is unavailable.');
        }

        $started = microtime(true);

        DistributorOffersStore::ensure_schema();

        $offers_table = DistributorOffersStore::table_name();
        $has_inventory_normalized_at = self::table_has_column($offers_table, 'inventory_normalized_at');
        $has_dropship_block_reason = self::table_has_column($offers_table, 'dropship_block_reason');
        $sig_approval_enabled = SigDropshipApproval::is_distributor_sig_approved(self::DIST_ID);
        $sig_offer_where_sql = $sig_approval_enabled
            ? self::offer_sig_approval_where_sql('o')
            : '0 = 1';

        $set = [
            'o.qty = IFNULL(S.available, 0)',
            "o.stock_status = CASE WHEN IFNULL(S.available, 0) > 0 THEN 'instock' ELSE 'outofstock' END",
            'o.dealer_price = S.price1',
            'o.shipping_cost = CASE WHEN S.price1 >= 500 THEN 0 ELSE 15 END',
            'o.landed_cost = CASE WHEN S.price1 IS NULL THEN NULL ELSE S.price1 + CASE WHEN S.price1 >= 500 THEN 0 ELSE 15 END END',
            "o.dropship_enabled = CASE WHEN {$sig_offer_where_sql} THEN 1 ELSE o.dropship_enabled END",
            'o.normalized_at = NOW()',
        ];

        if ($has_dropship_block_reason) {
            $set[] = "o.dropship_block_reason = CASE WHEN {$sig_offer_where_sql} THEN '' ELSE o.dropship_block_reason END";
        }

        if ($has_inventory_normalized_at) {
            $set[] = 'o.inventory_normalized_at = NOW()';
        }

        $sig_offer_changed_sql = $has_dropship_block_reason
            ? "(
                {$sig_offer_where_sql}
                AND (
                    NOT (o.dropship_enabled <=> 1)
                    OR NOT (o.dropship_block_reason <=> '')
                )
            )"
            : "(
                {$sig_offer_where_sql}
                AND NOT (o.dropship_enabled <=> 1)
            )";

        $sql = $wpdb->prepare(
            "
                UPDATE {$offers_table} o
                INNER JOIN {$stage_table} S
                    ON S.itemnumber = o.distributor_product_id
                SET
                    " . implode(",\n                    ", $set) . "
                WHERE o.distributor_id = %s
                    AND (
                        NOT (o.qty <=> IFNULL(S.available, 0))
                        OR NOT (o.dealer_price <=> S.price1)
                        OR {$sig_offer_changed_sql}
                    )
            ",
            self::DIST_ID
        );

        $updated = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($updated === false) {
            throw new \RuntimeException('Zanders distributor offers update failed: ' . (string) $wpdb->last_error);
        }

        return [
            'rows' => is_numeric($updated) ? (int) $updated : 0,
            'elapsed_ms' => (microtime(true) - $started) * 1000.0,
        ];
    }

    /**
     * Normalize the current live Zanders product table into distributor offers.
     *
     * This is intentionally set-based and only touches rows for UPCs that are
     * already present in active product_state.
     *
     * @return array<string,mixed>
     */
    public static function normalize_from_product_table(?string $source_live_table = null): array
    {
        global $wpdb;

        $started = microtime(true);
        $result = [
            'ok' => false,
            'source_live_table' => '',
            'active_product_state_total' => 0,
            'matched_active_zanders_upcs' => 0,
            'upsert_mysql_affected_rows' => 0,
            'stale_disabled' => 0,
            'elapsed_ms' => '0.00',
            'elapsed_sec' => '0.000',
            'errors' => [],
        ];

        if (!$wpdb) {
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        DistributorOffersStore::ensure_schema();
        ProductStateStore::ensure_schema();

        $live_table = self::resolve_live_table($source_live_table);
        if ($live_table === '') {
            $result['errors'][] = 'Could not resolve a valid live Zanders product table.';
            return self::finish_result($result, $started);
        }

        if (!self::table_exists_by_name($live_table)) {
            $result['errors'][] = 'Resolved live Zanders product table does not exist: ' . $live_table;
            $result['source_live_table'] = $live_table;
            return self::finish_result($result, $started);
        }

        $offers_table = DistributorOffersStore::table_name();
        $product_state_table = ProductStateStore::table_name();
        $has_product_normalized_at = self::table_has_column($offers_table, 'product_normalized_at');
        $has_dropship_block_reason = self::table_has_column($offers_table, 'dropship_block_reason');

        $result['source_live_table'] = $live_table;

        $active_state_sql = $wpdb->prepare(
            "
                SELECT COUNT(*)
                FROM {$product_state_table}
                WHERE status = %s
            ",
            'active'
        );
        $result['active_product_state_total'] = (int) $wpdb->get_var($active_state_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

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
        $result['matched_active_zanders_upcs'] = (int) $wpdb->get_var($matched_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $product_normalized_insert = $has_product_normalized_at ? ",\n                product_normalized_at" : '';
        $product_normalized_select = $has_product_normalized_at ? ",\n                NOW()" : '';
        $product_normalized_update = $has_product_normalized_at ? ",\n                product_normalized_at = VALUES(product_normalized_at)" : '';
        $product_normalized_stale = $has_product_normalized_at ? ",\n                o.product_normalized_at = NOW()" : '';
        $dropship_block_reason_insert = $has_dropship_block_reason ? "dropship_block_reason,\n                    " : '';
        $dropship_block_reason_select = $has_dropship_block_reason ? "NULLIF(TRIM(z.dropship_block_reason), '') AS dropship_block_reason,\n                    " : '';
        $dropship_block_reason_update = $has_dropship_block_reason ? "dropship_block_reason = VALUES(dropship_block_reason),\n                    " : '';

        $dealer_price_expr = "CAST(NULLIF(TRIM(z.distributor_price), '') AS DECIMAL(12,4))";
        $shipping_cost_expr = "CAST(NULLIF(TRIM(z.shipping_cost), '') AS DECIMAL(12,4))";
        $landed_cost_expr = "
            CASE
                WHEN {$dealer_price_expr} IS NULL THEN NULL
                ELSE {$dealer_price_expr} + COALESCE({$shipping_cost_expr}, 0.0000)
            END
        ";

        $t_upsert = microtime(true);
        $upsert_sql = $wpdb->prepare(
            "
                INSERT INTO {$offers_table} (
                    upc,
                    distributor_id,
                    distributor_product_id,
                    distributor_sku,
                    manufacturer_norm,
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
                    {$dropship_block_reason_insert}
                    shipping_weight_oz,
                    normalized_at
                    {$product_normalized_insert}
                )
                SELECT
                    z.upc,
                    %s AS distributor_id,
                    NULLIF(TRIM(z.zanders_item_number), '') AS distributor_product_id,
                    NULLIF(TRIM(z.zanders_item_number), '') AS distributor_sku,
                    NULLIF(TRIM(z.manufacturer_norm), '') AS manufacturer_norm,
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
                    {$dropship_block_reason_select}
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
                    manufacturer_norm = VALUES(manufacturer_norm),
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
                    {$dropship_block_reason_update}
                    shipping_weight_oz = VALUES(shipping_weight_oz),
                    normalized_at = VALUES(normalized_at)
                    {$product_normalized_update}
            ",
            self::DIST_ID,
            'active'
        );

        $upserted = $wpdb->query($upsert_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($upserted === false) {
            $result['errors'][] = 'Zanders offer upsert failed: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['upsert_mysql_affected_rows'] = is_numeric($upserted) ? (int) $upserted : 0;
        $result['upsert_elapsed_ms'] = number_format((microtime(true) - $t_upsert) * 1000.0, 2, '.', '');

        $t_stale = microtime(true);
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
            self::DIST_ID
        );

        $stale_disabled = $wpdb->query($stale_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($stale_disabled === false) {
            $result['errors'][] = 'Zanders stale offer cleanup failed: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['stale_disabled'] = is_numeric($stale_disabled) ? (int) $stale_disabled : 0;
        $result['stale_cleanup_elapsed_ms'] = number_format((microtime(true) - $t_stale) * 1000.0, 2, '.', '');
        $result['ok'] = true;

        return self::finish_result($result, $started);
    }

    private static function resolve_live_table(?string $requested): string
    {
        global $wpdb;

        $v1 = (string) ($wpdb->prefix . ZandersProductTableSchema::BASE_TABLE_KEY . '_v1');
        $v2 = (string) ($wpdb->prefix . ZandersProductTableSchema::BASE_TABLE_KEY . '_v2');
        $stored = $requested;

        if ($stored === null || $stored === '') {
            $stored = get_option(ZandersProductTableSchema::LIVE_TABLE_OPTION, '');
        }

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

    private static function offer_sig_approval_where_sql(string $alias): string
    {
        $alias = trim($alias);
        $prefix = $alias !== '' ? $alias . '.' : '';

        return "
            COALESCE({$prefix}sot_required, 0) = 0
            AND (
                   {$prefix}manufacturer_norm IN ('SIG', 'SIGSAUER', 'SIG SAUER', 'SIGARMS', 'SIG ARMS')
                OR {$prefix}manufacturer_norm LIKE 'SIGSAUER%'
                OR {$prefix}manufacturer_norm LIKE 'SIG SAUER%'
                OR {$prefix}manufacturer_norm LIKE 'SIGARMS%'
                OR {$prefix}manufacturer_norm LIKE 'SIG ARMS%'
            )
        ";
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
}
