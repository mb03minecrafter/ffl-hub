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
     * Apply loaded RSR inventory stage quantities to existing normalized offer rows.
     *
     * This is the inventory-cron path. It intentionally updates only inventory
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

        $set = [
            'o.qty = S.qty',
            "o.stock_status = CASE WHEN S.qty > 0 THEN 'instock' ELSE 'outofstock' END",
            'o.normalized_at = NOW()',
        ];

        if ($has_inventory_normalized_at) {
            $set[] = 'o.inventory_normalized_at = NOW()';
        }

        $sql = $wpdb->prepare(
            "
                UPDATE {$offers_table} o
                INNER JOIN {$stage_table} S
                    ON S.rsr_stock_number = o.distributor_product_id
                SET
                    " . implode(",\n                    ", $set) . "
                WHERE o.distributor_id = %s
                    AND NOT (o.qty <=> S.qty)
            ",
            self::DIST_ID
        );

        $updated = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($updated === false) {
            throw new \RuntimeException('RSR distributor offers update failed: ' . (string) $wpdb->last_error);
        }

        return [
            'rows' => is_numeric($updated) ? (int) $updated : 0,
            'elapsed_ms' => (microtime(true) - $started) * 1000.0,
        ];
    }

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
            'inserted_missing_offers' => 0,
            'updated_changed_offers' => 0,
            'stale_disabled_offers' => 0,
            'upsert_mysql_affected_rows' => 0,
            'stale_disabled' => 0,
            'insert_missing_elapsed_ms' => '0.00',
            'update_changed_elapsed_ms' => '0.00',
            'upsert_elapsed_ms' => '0.00',
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
        $source_columns = [
            'upc' => 'r.upc',
            'distributor_product_id' => "NULLIF(TRIM(r.rsr_stock_number), '')",
            'distributor_sku' => "NULLIF(TRIM(r.rsr_stock_number), '')",
            'manufacturer_norm' => "NULLIF(TRIM(r.manufacturer_id), '')",
            'qty' => $qty_expr,
            'stock_status' => "CASE WHEN {$qty_expr} > 0 THEN 'instock' ELSE 'outofstock' END",
            'dealer_price' => $dealer_price_expr,
            'shipping_cost' => $shipping_cost_expr,
            'landed_cost' => $landed_cost_expr,
            'map_price' => "CAST(NULLIF(TRIM(r.retail_map), '') AS DECIMAL(12,4))",
            'msrp' => "CAST(NULLIF(TRIM(r.retail_msrp), '') AS DECIMAL(12,4))",
            'ffl_required' => '0',
            'sot_required' => 'CAST(COALESCE(r.sot_required, 0) AS UNSIGNED)',
            'dropship_enabled' => 'CAST(COALESCE(r.dropship_enabled, 1) AS UNSIGNED)',
            'enabled' => '1',
        ];

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

        $select_columns = [
            "{$source_columns['upc']} AS upc",
            '%s AS distributor_id',
            "{$source_columns['distributor_product_id']} AS distributor_product_id",
            "{$source_columns['distributor_sku']} AS distributor_sku",
            "{$source_columns['manufacturer_norm']} AS manufacturer_norm",
            "{$source_columns['qty']} AS qty",
            "{$source_columns['stock_status']} AS stock_status",
            "{$source_columns['dealer_price']} AS dealer_price",
            "{$source_columns['shipping_cost']} AS shipping_cost",
            "{$source_columns['landed_cost']} AS landed_cost",
            "{$source_columns['map_price']} AS map_price",
            "{$source_columns['msrp']} AS msrp",
            "{$source_columns['ffl_required']} AS ffl_required",
            "{$source_columns['sot_required']} AS sot_required",
            "{$source_columns['dropship_enabled']} AS dropship_enabled",
            "{$source_columns['enabled']} AS enabled",
        ];

        if ($has_dropship_block_reason) {
            $source_columns['dropship_block_reason'] = "NULLIF(TRIM(r.dropship_block_reason), '')";
            $insert_columns[] = 'dropship_block_reason';
            $select_columns[] = "{$source_columns['dropship_block_reason']} AS dropship_block_reason";
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

            $source_columns[$target_column] = "CAST(NULLIF(TRIM(r.{$source_column}), '') AS {$source['cast']})";
            $insert_columns[] = $target_column;
            $select_columns[] = "{$source_columns[$target_column]} AS {$target_column}";
        }

        $insert_columns[] = 'normalized_at';
        $select_columns[] = 'NOW() AS normalized_at';

        if ($has_product_normalized_at) {
            $insert_columns[] = 'product_normalized_at';
            $select_columns[] = 'NOW() AS product_normalized_at';
        }

        $update_columns = array_values(array_diff(array_keys($source_columns), ['upc']));
        $update_lines = [];
        $comparison_lines = [];
        foreach ($update_columns as $column) {
            $update_lines[] = "o.{$column} = {$source_columns[$column]}";
            $comparison_lines[] = "NOT (o.{$column} <=> {$source_columns[$column]})";
        }
        $update_lines[] = 'o.normalized_at = NOW()';

        if ($has_product_normalized_at) {
            $update_lines[] = 'o.product_normalized_at = NOW()';
        }

        $t_insert = microtime(true);
        $insert_sql = $wpdb->prepare(
            "
                INSERT IGNORE INTO {$offers_table} (
                    " . implode(",\n                    ", $insert_columns) . "
                )
                SELECT
                    " . implode(",\n                    ", $select_columns) . "
                FROM {$product_state_table} ps
                INNER JOIN {$live_table} r
                    ON r.upc = ps.upc
                WHERE ps.status = %s
                  AND r.upc <> ''
            ",
            self::DIST_ID,
            'active'
        );

        $inserted = $wpdb->query($insert_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($inserted === false) {
            $result['errors'][] = 'RSR missing offer insert failed: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['inserted_missing_offers'] = is_numeric($inserted) ? (int) $inserted : 0;
        $result['insert_missing_elapsed_ms'] = number_format((microtime(true) - $t_insert) * 1000.0, 2, '.', '');

        $t_update = microtime(true);
        $update_sql = $wpdb->prepare(
            "
                UPDATE {$offers_table} o
                INNER JOIN {$product_state_table} ps
                    ON ps.upc = o.upc
                   AND ps.status = %s
                INNER JOIN {$live_table} r
                    ON r.upc = o.upc
                   AND r.upc <> ''
                SET
                    " . implode(",\n                    ", $update_lines) . "
                WHERE o.distributor_id = %s
                  AND (
                    " . implode("\n                    OR ", $comparison_lines) . "
                )
            ",
            'active',
            self::DIST_ID
        );

        $updated = $wpdb->query($update_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($updated === false) {
            $result['errors'][] = 'RSR changed offer update failed: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['updated_changed_offers'] = is_numeric($updated) ? (int) $updated : 0;
        $result['update_changed_elapsed_ms'] = number_format((microtime(true) - $t_update) * 1000.0, 2, '.', '');
        $result['upsert_mysql_affected_rows'] = (int) $result['inserted_missing_offers'] + (int) $result['updated_changed_offers'];
        $result['upsert_elapsed_ms'] = number_format(
            (float) $result['insert_missing_elapsed_ms'] + (float) $result['update_changed_elapsed_ms'],
            2,
            '.',
            ''
        );

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
                  AND (
                    NOT (o.enabled <=> 0)
                    OR NOT (o.dropship_enabled <=> 0)
                    OR NOT (o.qty <=> 0)
                    OR NOT (o.stock_status <=> 'outofstock')
                  )
            ",
            'active',
            self::DIST_ID
        );

        $stale_disabled = $wpdb->query($stale_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($stale_disabled === false) {
            $result['errors'][] = 'RSR stale offer cleanup failed: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['stale_disabled_offers'] = is_numeric($stale_disabled) ? (int) $stale_disabled : 0;
        $result['stale_disabled'] = (int) $result['stale_disabled_offers'];
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
