<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services;

use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Product\State\ProductStateStore;

if (!defined('ABSPATH')) {
    exit;
}

final class DistributorTableSyncService
{
    /**
     * @param array{
     *     distributor_id:string,
     *     label:string,
     *     live_table_label:string,
     *     source_live_table:string,
     *     source_alias:string,
     *     matched_count_key:string,
     *     source_columns:array<string,string>
     * } $config
     * @return array<string,mixed>
     */
    public static function normalize_product_offers(array $config): array
    {
        global $wpdb;

        $started = microtime(true);
        $matched_key = (string) ($config['matched_count_key'] ?? 'matched_active_upcs');
        $result = self::base_result($matched_key);

        if (!$wpdb) {
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        DistributorOffersStore::ensure_schema();
        ProductStateStore::ensure_schema();

        $dist_id = (string) ($config['distributor_id'] ?? '');
        $label = (string) ($config['label'] ?? $dist_id);
        $live_table_label = (string) ($config['live_table_label'] ?? $label);
        $live_table = trim((string) ($config['source_live_table'] ?? ''));
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($config['source_alias'] ?? 'src'));
        $source_columns = isset($config['source_columns']) && is_array($config['source_columns'])
            ? $config['source_columns']
            : [];

        if ($live_table === '') {
            $result['errors'][] = "A valid {$live_table_label} product table is required.";
            return self::finish_result($result, $started);
        }

        if (!self::table_exists_by_name($live_table)) {
            $result['errors'][] = "Resolved {$live_table_label} product table does not exist: " . $live_table;
            $result['source_live_table'] = $live_table;
            return self::finish_result($result, $started);
        }

        if ($dist_id === '' || $alias === '' || empty($source_columns['upc'])) {
            $result['errors'][] = "{$label} offer normalization config is incomplete.";
            $result['source_live_table'] = $live_table;
            return self::finish_result($result, $started);
        }

        $offers_table = DistributorOffersStore::table_name();
        $product_state_table = ProductStateStore::table_name();
        $result['source_live_table'] = $live_table;

        $result['active_product_state_total'] = self::active_product_state_count($product_state_table);
        $result[$matched_key] = self::matched_active_upc_count($product_state_table, $live_table, $alias);

        $insert_columns = ['upc', 'distributor_id'];
        $select_columns = [
            $source_columns['upc'] . ' AS upc',
            '%s AS distributor_id',
        ];

        foreach ($source_columns as $column => $expression) {
            if ($column === 'upc') {
                continue;
            }

            $insert_columns[] = $column;
            $select_columns[] = "{$expression} AS {$column}";
        }

        $insert_columns[] = 'normalized_at';
        $select_columns[] = 'NOW() AS normalized_at';

        $update_lines = [];
        $comparison_lines = [];
        foreach (array_keys($source_columns) as $column) {
            if ($column === 'upc') {
                continue;
            }

            $update_lines[] = "o.{$column} = {$source_columns[$column]}";
            $comparison_lines[] = "NOT (o.{$column} <=> {$source_columns[$column]})";
        }
        $update_lines[] = 'o.normalized_at = NOW()';

        $t_insert = microtime(true);
        $insert_sql = $wpdb->prepare(
            "
                INSERT IGNORE INTO {$offers_table} (
                    " . implode(",\n                    ", $insert_columns) . "
                )
                SELECT
                    " . implode(",\n                    ", $select_columns) . "
                FROM {$product_state_table} ps
                INNER JOIN {$live_table} {$alias}
                    ON {$alias}.upc = ps.upc
                WHERE ps.status = %s
                  AND {$alias}.upc <> ''
            ",
            $dist_id,
            'active'
        );

        $inserted = $wpdb->query($insert_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($inserted === false) {
            $result['errors'][] = "{$label} missing offer insert failed: " . (string) $wpdb->last_error;
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
                INNER JOIN {$live_table} {$alias}
                    ON {$alias}.upc = o.upc
                   AND {$alias}.upc <> ''
                SET
                    " . implode(",\n                    ", $update_lines) . "
                WHERE o.distributor_id = %s
                  AND (
                    " . implode("\n                    OR ", $comparison_lines) . "
                  )
            ",
            'active',
            $dist_id
        );

        $updated = $wpdb->query($update_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($updated === false) {
            $result['errors'][] = "{$label} changed offer update failed: " . (string) $wpdb->last_error;
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
        $stale_sql = $wpdb->prepare(
            "
                UPDATE {$offers_table} o
                INNER JOIN {$product_state_table} ps
                    ON ps.upc = o.upc
                   AND ps.status = %s
                LEFT JOIN {$live_table} {$alias}
                    ON {$alias}.upc = o.upc
                   AND {$alias}.upc <> ''
                SET
                    o.enabled = 0,
                    o.dropship_enabled = 0,
                    o.qty = 0,
                    o.stock_status = 'outofstock',
                    o.normalized_at = NOW()
                WHERE o.distributor_id = %s
                  AND {$alias}.upc IS NULL
                  AND (
                    NOT (o.enabled <=> 0)
                    OR NOT (o.dropship_enabled <=> 0)
                    OR NOT (o.qty <=> 0)
                    OR NOT (o.stock_status <=> 'outofstock')
                  )
            ",
            'active',
            $dist_id
        );

        $stale_disabled = $wpdb->query($stale_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($stale_disabled === false) {
            $result['errors'][] = "{$label} stale offer cleanup failed: " . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['stale_disabled_offers'] = is_numeric($stale_disabled) ? (int) $stale_disabled : 0;
        $result['stale_disabled'] = (int) $result['stale_disabled_offers'];
        $result['stale_cleanup_elapsed_ms'] = number_format((microtime(true) - $t_stale) * 1000.0, 2, '.', '');
        $result['ok'] = true;

        return self::finish_result($result, $started);
    }

    public static function table_exists_by_name(string $table): bool
    {
        global $wpdb;

        if ($table === '') {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($found) && $found === $table;
    }

    public static function table_has_column(string $table, string $column): bool
    {
        global $wpdb;

        if ($table === '' || $column === '') {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_string($found) && $found === $column;
    }

    public static function unsigned_quantity_expr(string $alias, string $column): string
    {
        return "CAST(COALESCE(NULLIF(TRIM({$alias}.{$column}), ''), '0') AS UNSIGNED)";
    }

    public static function decimal_expr(string $alias, string $column, int $precision = 12, int $scale = 4): string
    {
        return "CAST(NULLIF(TRIM({$alias}.{$column}), '') AS DECIMAL({$precision},{$scale}))";
    }

    public static function stock_status_expr(string $qty_expr): string
    {
        return "CASE WHEN {$qty_expr} > 0 THEN 'instock' ELSE 'outofstock' END";
    }

    public static function landed_cost_expr(string $dealer_price_expr, string $shipping_cost_expr): string
    {
        return "
            CASE
                WHEN {$dealer_price_expr} IS NULL THEN NULL
                ELSE {$dealer_price_expr} + COALESCE({$shipping_cost_expr}, 0.0000)
            END
        ";
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public static function finish_result(array $result, float $started): array
    {
        $elapsed_ms = (microtime(true) - $started) * 1000.0;
        $result['elapsed_ms'] = number_format($elapsed_ms, 2, '.', '');
        $result['elapsed_sec'] = number_format($elapsed_ms / 1000.0, 3, '.', '');

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private static function base_result(string $matched_count_key): array
    {
        return [
            'ok' => false,
            'source_live_table' => '',
            'active_product_state_total' => 0,
            $matched_count_key => 0,
            'inserted_missing_offers' => 0,
            'updated_changed_offers' => 0,
            'stale_disabled_offers' => 0,
            'upsert_mysql_affected_rows' => 0,
            'stale_disabled' => 0,
            'insert_missing_elapsed_ms' => '0.00',
            'update_changed_elapsed_ms' => '0.00',
            'upsert_elapsed_ms' => '0.00',
            'stale_cleanup_elapsed_ms' => '0.00',
            'elapsed_ms' => '0.00',
            'elapsed_sec' => '0.000',
            'errors' => [],
        ];
    }

    private static function active_product_state_count(string $product_state_table): int
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "
                SELECT COUNT(*)
                FROM {$product_state_table}
                WHERE status = %s
            ",
            'active'
        );

        return (int) $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function matched_active_upc_count(string $product_state_table, string $live_table, string $alias): int
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "
                SELECT COUNT(*)
                FROM {$product_state_table} ps
                INNER JOIN {$live_table} {$alias}
                    ON {$alias}.upc = ps.upc
                WHERE ps.status = %s
                  AND {$alias}.upc <> ''
            ",
            'active'
        );

        return (int) $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
}
