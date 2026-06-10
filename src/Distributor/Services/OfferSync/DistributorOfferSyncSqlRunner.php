<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Product\State\ProductStateStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Compiles distributor offer sync maps into bulk SQL.
 *
 * Keep the SQL here set-based and explicit. Concrete distributor classes should
 * declare source columns and inventory-stage behavior, not hand-build the same
 * insert/update scaffolding repeatedly.
 */
final class DistributorOfferSyncSqlRunner
{
    /**
     * Run the full product-table sync.
     *
     * @return array<string,mixed>
     */
    public static function sync_product_offers(OfferProductSyncMap $map): array
    {
        global $wpdb;

        $started = microtime(true);
        $matched_key = $map->matched_count_key();
        $result = self::base_result($matched_key);

        if (!$wpdb) {
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        DistributorOffersStore::ensure_schema();
        ProductStateStore::ensure_schema();

        // 1. Validate the concrete distributor config and resolved live table.
        // The map supplies distributor identity, table alias, and source-column
        // expressions. No write happens until the live table and UPC expression
        // are known to be present.
        $dist_id = $map->distributor_id();
        $label = $map->label();
        $live_table_label = $map->live_table_label();
        $live_table = $map->source_live_table();
        $alias = $map->source_alias();
        $source_columns = $map->source_columns();

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

        // Build the shared SQL fragments from the concrete source-column map.
        // Each mapped distributor_offers column becomes both an INSERT SELECT
        // expression and a null-safe changed-row comparison for the UPDATE.
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

        // 2. Insert offer rows that do not yet exist for active carried UPCs.
        // Start from product_state so only UPCs we actively carry are eligible,
        // then join the distributor's live table by UPC. INSERT IGNORE lets the
        // existing unique key keep already-normalized offers untouched here.
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

        // 3. Refresh existing offers only when mapped values actually changed.
        // Join existing offers back to active product_state and the live table,
        // then use <=> comparisons so NULL/blank-sensitive values do not cause
        // needless writes. This keeps product cron runs from hammering rows.
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

        // 4. Disable stale offers when carried UPCs disappear from this live table.
        // Left join the live table and find active carried offers that no longer
        // have a matching distributor row. Those offers are kept for history but
        // marked disabled/out of stock so selection logic will stop using them.
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

    /**
     * Apply an inventory/pricing stage table to existing offer rows.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    public static function update_existing_offers_from_inventory_stage(OfferInventorySyncMap $map): array
    {
        global $wpdb;

        if (!$wpdb) {
            throw new \RuntimeException('WordPress database connection is unavailable.');
        }

        $started = microtime(true);

        DistributorOffersStore::ensure_schema();

        $offers_table = DistributorOffersStore::table_name();
        $stage_table = $map->stage_table();
        $stage_alias = $map->stage_alias();
        $join_condition_sql = $map->join_condition_sql();
        $set_expressions = $map->set_expressions();
        $changed_where_sql = $map->changed_where_sql();

        if ($stage_table === '' || !self::table_exists_by_name($stage_table)) {
            throw new \RuntimeException($map->label() . ' inventory stage table is unavailable: ' . $stage_table);
        }

        if ($stage_alias === '' || $join_condition_sql === '' || !$set_expressions || $changed_where_sql === '') {
            throw new \RuntimeException($map->label() . ' inventory offer sync map is incomplete.');
        }

        $sql = $wpdb->prepare(
            "
                UPDATE {$offers_table} o
                INNER JOIN {$stage_table} {$stage_alias}
                    ON {$join_condition_sql}
                SET
                    " . implode(",\n                    ", $set_expressions) . "
                WHERE o.distributor_id = %s
                  AND (
                    {$changed_where_sql}
                  )
            ",
            $map->distributor_id()
        );

        $updated = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($updated === false) {
            throw new \RuntimeException($map->label() . ' distributor offers inventory update failed: ' . (string) $wpdb->last_error);
        }

        return [
            'rows' => is_numeric($updated) ? (int) $updated : 0,
            'elapsed_ms' => (microtime(true) - $started) * 1000.0,
        ];
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
