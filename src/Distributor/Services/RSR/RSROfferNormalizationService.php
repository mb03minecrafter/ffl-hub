<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\RSR;

use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Distributor\Services\RSR\Tables\RSRProductTableSchema;
use FFLHub\Product\State\ProductStateStore;

if (!defined('ABSPATH')) {
    exit;
}

final class RSROfferNormalizationService
{
    private const DIST_ID = 'rsr';

    /**
     * Normalize the current live RSR product table into distributor offers.
     *
     * This is intentionally set-based and only touches rows for UPCs already
     * present in active product_state.
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
            'matched_active_rsr_upcs' => 0,
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
            $result['errors'][] = 'Could not resolve a valid live RSR product table.';
            return self::finish_result($result, $started);
        }

        if (!self::table_exists_by_name($live_table)) {
            $result['errors'][] = 'Resolved live RSR product table does not exist: ' . $live_table;
            $result['source_live_table'] = $live_table;
            return self::finish_result($result, $started);
        }

        $offers_table = DistributorOffersStore::table_name();
        $product_state_table = ProductStateStore::table_name();

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
                FROM {$product_state_table} ps
                INNER JOIN {$live_table} r
                    ON r.upc = ps.upc
                WHERE ps.status = %s
                  AND r.upc <> ''
            ",
            'active'
        );
        $result['matched_active_rsr_upcs'] = (int) $wpdb->get_var($matched_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $has_product_normalized_at = self::table_has_column($offers_table, 'product_normalized_at');
        $has_dropship_block_reason = self::table_has_column($offers_table, 'dropship_block_reason');

        $insert_columns = [
            'upc',
            'distributor_id',
            'distributor_product_id',
            'distributor_sku',
            'manufacturer_norm',
            'qty',
            'stock_status',
            'dealer_price',
            'shipping_cost',
            'landed_cost',
            'map_price',
            'msrp',
            'ffl_required',
            'sot_required',
            'dropship_enabled',
            'enabled',
        ];

        $qty_expr = "CAST(COALESCE(NULLIF(TRIM(r.inventory_quantity), ''), '0') AS UNSIGNED)";
        $dealer_price_expr = "CAST(NULLIF(TRIM(r.distributor_price), '') AS DECIMAL(12,4))";
        $shipping_cost_expr = self::table_has_column($live_table, 'shipping_cost')
            ? "COALESCE(CAST(NULLIF(TRIM(r.shipping_cost), '') AS DECIMAL(12,4)), 10.0000)"
            : '10.0000';
        $landed_cost_expr = "
            CASE
                WHEN {$dealer_price_expr} IS NULL THEN NULL
                ELSE {$dealer_price_expr} + {$shipping_cost_expr}
            END
        ";

        $select_columns = [
            'r.upc',
            '%s AS distributor_id',
            "NULLIF(TRIM(r.rsr_stock_number), '') AS distributor_product_id",
            "NULLIF(TRIM(r.rsr_stock_number), '') AS distributor_sku",
            "NULLIF(TRIM(r.manufacturer_id), '') AS manufacturer_norm",
            "{$qty_expr} AS qty",
            "CASE WHEN {$qty_expr} > 0 THEN 'instock' ELSE 'outofstock' END AS stock_status",
            "{$dealer_price_expr} AS dealer_price",
            "{$shipping_cost_expr} AS shipping_cost",
            "{$landed_cost_expr} AS landed_cost",
            "CAST(NULLIF(TRIM(r.retail_map), '') AS DECIMAL(12,4)) AS map_price",
            "CAST(NULLIF(TRIM(r.retail_msrp), '') AS DECIMAL(12,4)) AS msrp",
            '0 AS ffl_required',
            'CAST(COALESCE(r.sot_required, 0) AS UNSIGNED) AS sot_required',
            'CAST(COALESCE(r.dropship_enabled, 1) AS UNSIGNED) AS dropship_enabled',
            '1 AS enabled',
        ];

        $update_lines = [
            'distributor_product_id = VALUES(distributor_product_id)',
            'distributor_sku = VALUES(distributor_sku)',
            'manufacturer_norm = VALUES(manufacturer_norm)',
            'qty = VALUES(qty)',
            'stock_status = VALUES(stock_status)',
            'dealer_price = VALUES(dealer_price)',
            'shipping_cost = VALUES(shipping_cost)',
            'landed_cost = VALUES(landed_cost)',
            'map_price = VALUES(map_price)',
            'msrp = VALUES(msrp)',
            'ffl_required = VALUES(ffl_required)',
            'sot_required = VALUES(sot_required)',
            'dropship_enabled = VALUES(dropship_enabled)',
            'enabled = VALUES(enabled)',
        ];

        if ($has_dropship_block_reason) {
            $insert_columns[] = 'dropship_block_reason';
            $select_columns[] = "NULLIF(TRIM(r.dropship_block_reason), '') AS dropship_block_reason";
            $update_lines[] = 'dropship_block_reason = VALUES(dropship_block_reason)';
        }

        $dimension_map = [
            'shipping_weight_oz' => ['source' => 'shipping_weight', 'cast' => 'DECIMAL(10,3)'],
            'shipping_length_in' => ['source' => 'shipping_length_in', 'cast' => 'DECIMAL(10,3)'],
            'shipping_width_in' => ['source' => 'shipping_width_in', 'cast' => 'DECIMAL(10,3)'],
            'shipping_height_in' => ['source' => 'shipping_height_in', 'cast' => 'DECIMAL(10,3)'],
        ];

        foreach ($dimension_map as $target_column => $source) {
            $source_column = (string) $source['source'];
            if (!self::table_has_column($offers_table, $target_column) || !self::table_has_column($live_table, $source_column)) {
                continue;
            }

            $insert_columns[] = $target_column;
            $select_columns[] = "CAST(NULLIF(TRIM(r.{$source_column}), '') AS {$source['cast']}) AS {$target_column}";
            $update_lines[] = "{$target_column} = VALUES({$target_column})";
        }

        $insert_columns[] = 'normalized_at';
        $select_columns[] = 'NOW() AS normalized_at';
        $update_lines[] = 'normalized_at = VALUES(normalized_at)';

        if ($has_product_normalized_at) {
            $insert_columns[] = 'product_normalized_at';
            $select_columns[] = 'NOW() AS product_normalized_at';
            $update_lines[] = 'product_normalized_at = VALUES(product_normalized_at)';
        }

        $t_upsert = microtime(true);
        $upsert_sql = $wpdb->prepare(
            "
                INSERT INTO {$offers_table} (
                    " . implode(",\n                    ", $insert_columns) . "
                )
                SELECT
                    " . implode(",\n                    ", $select_columns) . "
                FROM {$product_state_table} ps
                INNER JOIN {$live_table} r
                    ON r.upc = ps.upc
                WHERE ps.status = %s
                  AND r.upc <> ''
                ON DUPLICATE KEY UPDATE
                    " . implode(",\n                    ", $update_lines) . "
            ",
            self::DIST_ID,
            'active'
        );

        $upserted = $wpdb->query($upsert_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($upserted === false) {
            $result['errors'][] = 'RSR offer upsert failed: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['upsert_mysql_affected_rows'] = is_numeric($upserted) ? (int) $upserted : 0;
        $result['upsert_elapsed_ms'] = number_format((microtime(true) - $t_upsert) * 1000.0, 2, '.', '');

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
                INNER JOIN {$product_state_table} ps
                    ON ps.upc = o.upc
                   AND ps.status = %s
                LEFT JOIN {$live_table} r
                    ON r.upc = o.upc
                   AND r.upc <> ''
                SET
                    " . implode(",\n                    ", $stale_set) . "
                WHERE o.distributor_id = %s
                  AND r.upc IS NULL
            ",
            'active',
            self::DIST_ID
        );

        $stale_disabled = $wpdb->query($stale_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($stale_disabled === false) {
            $result['errors'][] = 'RSR stale offer cleanup failed: ' . (string) $wpdb->last_error;
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

        $v1 = (string) ($wpdb->prefix . RSRProductTableSchema::BASE_TABLE_KEY . '_v1');
        $v2 = (string) ($wpdb->prefix . RSRProductTableSchema::BASE_TABLE_KEY . '_v2');
        $stored = $requested;

        if ($stored === null || $stored === '') {
            $stored = get_option(RSRProductTableSchema::LIVE_TABLE_OPTION, '');
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
