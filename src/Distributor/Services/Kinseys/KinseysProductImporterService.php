<?php

namespace FFLHub\Distributor\Services\Kinseys;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Util\DebugLogUtil;

/**
 * Imports Kinsey's catalog rows and applies lightweight inventory updates.
 */
final class KinseysProductImporterService
{
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][KinseysImporter]';
    private const INVENTORY_STAGE_TABLE_SUFFIX = 'fflhub_kinseys_inventory_stage';

    private DoubleBufferedProductTable $table;
    private KinseysProductParser $parser;

    public function __construct(DoubleBufferedProductTable $table, ?KinseysProductParser $parser = null)
    {
        $this->table = $table;
        $this->parser = $parser ?: new KinseysProductParser();
    }

    /**
     * @param array<int,array<string,mixed>> $products
     * @param array<string,mixed> $inventoryResponseOrRows
     */
    public function import_products_array(array $products, array $inventoryResponseOrRows = []): int
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $t_start = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        $phase_ms = [];

        $t_phase = microtime(true);
        $inventory_lookup = $this->parser->build_inventory_lookup($inventoryResponseOrRows);
        $phase_ms['build_inventory_lookup'] = $this->elapsed_ms($t_phase);

        $t_phase = microtime(true);
        $columns = $this->table->get_schema()->get_insert_columns();
        $tsv_path = $this->resolve_catalog_tsv_path();
        $phase_ms['resolve_import_inputs'] = $this->elapsed_ms($t_phase);

        $this->log('Kinsey\'s product import start.', [
            'products_in' => count($products),
            'inventory_lookup_rows' => count($inventory_lookup),
            'column_count' => count($columns),
            'tsv_path' => (string) $tsv_path,
            'phase_ms' => $phase_ms,
            'memory_kb' => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
        ]);

        if ($tsv_path === '' || empty($columns)) {
            $this->log('Kinsey\'s product import falling back to batched inserts; TSV path or columns unavailable.', [
                'tsv_path' => (string) $tsv_path,
                'column_count' => count($columns),
            ]);

            return $this->import_products_array_via_batches($products, $inventory_lookup, $t_start, $mem_start, $phase_ms);
        }

        $t_phase = microtime(true);
        $write_stats = $this->write_catalog_tsv($products, $inventory_lookup, $columns, $tsv_path);
        $phase_ms['write_catalog_tsv'] = $this->elapsed_ms($t_phase);

        if ((int) ($write_stats['rows_written'] ?? 0) <= 0) {
            $write_stats['phase_ms'] = $phase_ms;
            $this->log('Kinsey\'s product import wrote zero TSV rows; not loading.', $write_stats);
            return 0;
        }

        $t_phase = microtime(true);
        $count = $this->import_from_tsv_file($tsv_path, $columns);
        $phase_ms['load_tsv_into_staging'] = $this->elapsed_ms($t_phase);

        if ($count > 0) {
            update_option('fflhub_kinseys_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_kinseys_fulfillment_last_import_count', (int) $count, false);
        }

        $ctx = array_merge($write_stats, [
            'mode' => 'tsv_load',
            'rows_inserted' => (int) $count,
            'phase_ms' => $phase_ms,
            'elapsed_ms' => $this->elapsed_ms($t_start),
        ]);

        if ($mem_start > 0 && function_exists('memory_get_usage')) {
            $mem_end = (int) memory_get_usage(true);
            $ctx['memory_start_kb'] = (int) round($mem_start / 1024);
            $ctx['memory_end_kb'] = (int) round($mem_end / 1024);
            $ctx['memory_delta_kb'] = (int) round(($mem_end - $mem_start) / 1024);
        }

        if (function_exists('memory_get_peak_usage')) {
            $ctx['memory_peak_kb'] = (int) round(memory_get_peak_usage(true) / 1024);
        }

        $this->log('Kinsey\'s product import complete.', $ctx);

        return (int) $count;
    }

    /**
     * @param string[]|int[] $allowedProductIds
     * @return array<string,mixed>
     */
    public function prune_staging_to_allowed_product_ids(array $allowedProductIds): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $t_start = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        $phase_ms = [];

        $t_phase = microtime(true);
        $ids = [];
        foreach ($allowedProductIds as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        $phase_ms['normalize_allowed_ids'] = $this->elapsed_ms($t_phase);

        if (empty($ids)) {
            return $this->finish_allowed_product_prune_stats([
                'ok' => false,
                'error' => 'No allowed product IDs provided.',
                'allowed_product_ids' => 0,
                'allowed_ids_loaded' => 0,
                'staging_rows_before' => 0,
                'rows_deleted' => 0,
                'staging_rows_after' => 0,
            ], $t_start, $mem_start, $phase_ms);
        }

        global $wpdb;

        $staging_table = $this->table->get_staging_table_name();
        $temp_table = $wpdb->prefix . 'fflhub_kinseys_allowed_product_ids_tmp';

        try {
            $t_phase = microtime(true);
            $this->table->createTables();
            $phase_ms['ensure_product_tables'] = $this->elapsed_ms($t_phase);

            $t_phase = microtime(true);
            $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$staging_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $phase_ms['count_staging_before'] = $this->elapsed_ms($t_phase);

            $t_phase = microtime(true);
            $charset = $wpdb->get_charset_collate();
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $created = $wpdb->query("
                CREATE TEMPORARY TABLE {$temp_table} (
                    product_id VARCHAR(128) NOT NULL,
                    PRIMARY KEY (product_id)
                ) {$charset}
            "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $phase_ms['create_allowed_temp_table'] = $this->elapsed_ms($t_phase);
            if ($created === false) {
                return $this->finish_allowed_product_prune_stats([
                    'ok' => false,
                    'error' => 'Failed to create allowed product temp table: ' . (string) $wpdb->last_error,
                    'allowed_product_ids' => count($ids),
                    'allowed_ids_loaded' => 0,
                    'staging_rows_before' => $before,
                    'rows_deleted' => 0,
                    'staging_rows_after' => $before,
                    'staging_table' => $staging_table,
                    'temp_table' => $temp_table,
                ], $t_start, $mem_start, $phase_ms);
            }

            $t_phase = microtime(true);
            $loaded = $this->insert_allowed_product_id_rows($temp_table, $ids);
            $phase_ms['insert_allowed_temp_rows'] = $this->elapsed_ms($t_phase);
            if ($loaded <= 0) {
                return $this->finish_allowed_product_prune_stats([
                    'ok' => false,
                    'error' => 'Failed to load allowed product IDs into temp table.',
                    'allowed_product_ids' => count($ids),
                    'allowed_ids_loaded' => 0,
                    'staging_rows_before' => $before,
                    'rows_deleted' => 0,
                    'staging_rows_after' => $before,
                    'staging_table' => $staging_table,
                    'temp_table' => $temp_table,
                ], $t_start, $mem_start, $phase_ms);
            }

            $t_phase = microtime(true);
            $deleted = $wpdb->query("
                DELETE S
                FROM {$staging_table} S
                LEFT JOIN {$temp_table} A1
                    ON S.kinseys_product_id <> '' AND A1.product_id = S.kinseys_product_id
                LEFT JOIN {$temp_table} A2
                    ON S.remote_identifier <> '' AND A2.product_id = S.remote_identifier
                LEFT JOIN {$temp_table} A3
                    ON S.north_item_number <> '' AND A3.product_id = S.north_item_number
                LEFT JOIN {$temp_table} A4
                    ON S.south_item_number <> '' AND A4.product_id = S.south_item_number
                LEFT JOIN {$temp_table} A5
                    ON S.vendor_item_number <> '' AND A5.product_id = S.vendor_item_number
                WHERE
                    A1.product_id IS NULL
                    AND A2.product_id IS NULL
                    AND A3.product_id IS NULL
                    AND A4.product_id IS NULL
                    AND A5.product_id IS NULL
            "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $phase_ms['delete_not_allowed_rows'] = $this->elapsed_ms($t_phase);
            if ($deleted === false) {
                return $this->finish_allowed_product_prune_stats([
                    'ok' => false,
                    'error' => 'Failed to prune staging rows: ' . (string) $wpdb->last_error,
                    'allowed_product_ids' => count($ids),
                    'allowed_ids_loaded' => (int) $loaded,
                    'staging_rows_before' => $before,
                    'rows_deleted' => 0,
                    'staging_rows_after' => $before,
                    'staging_table' => $staging_table,
                    'temp_table' => $temp_table,
                ], $t_start, $mem_start, $phase_ms);
            }

            $t_phase = microtime(true);
            $after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$staging_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $phase_ms['count_staging_after'] = $this->elapsed_ms($t_phase);

            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        } catch (\Throwable $e) {
            return $this->finish_allowed_product_prune_stats([
                'ok' => false,
                'error' => $e->getMessage(),
                'allowed_product_ids' => count($ids),
                'allowed_ids_loaded' => 0,
                'staging_rows_before' => 0,
                'rows_deleted' => 0,
                'staging_rows_after' => 0,
                'staging_table' => $staging_table,
                'temp_table' => $temp_table,
            ], $t_start, $mem_start, $phase_ms);
        }

        return $this->finish_allowed_product_prune_stats([
            'ok' => true,
            'allowed_product_ids' => count($ids),
            'allowed_ids_loaded' => (int) $loaded,
            'staging_rows_before' => $before,
            'rows_deleted' => (int) $deleted,
            'staging_rows_after' => $after,
            'staging_table' => $staging_table,
            'temp_table' => $temp_table,
        ], $t_start, $mem_start, $phase_ms);
    }

    /**
     * @param string[] $ids
     */
    private function insert_allowed_product_id_rows(string $tempTable, array $ids): int
    {
        global $wpdb;

        $batch_size = 1000;
        $values = [];
        $placeholders = [];
        $loaded = 0;

        $flush = function () use (&$values, &$placeholders, &$loaded, $tempTable, $wpdb): void {
            if (empty($placeholders)) {
                return;
            }

            $sql = "INSERT IGNORE INTO {$tempTable} (product_id) VALUES " . implode(', ', $placeholders);
            $result = $wpdb->query($wpdb->prepare($sql, $values));
            if ($result !== false) {
                $loaded += (int) $result;
            } else {
                $this->log('ERROR: Kinsey\'s allowed product temp insert failed: ' . (string) $wpdb->last_error);
            }

            $values = [];
            $placeholders = [];
        };

        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }

            $placeholders[] = '(%s)';
            $values[] = $id;

            if (count($placeholders) >= $batch_size) {
                $flush();
            }
        }

        $flush();

        return (int) $loaded;
    }

    /**
     * @param array<string,mixed> $stats
     * @param array<string,string> $phaseMs
     * @return array<string,mixed>
     */
    private function finish_allowed_product_prune_stats(
        array $stats,
        float $tStart,
        int $memStart,
        array $phaseMs
    ): array {
        $stats['phase_ms'] = $phaseMs;
        $stats['elapsed_ms'] = $this->elapsed_ms($tStart);

        if ($memStart > 0 && function_exists('memory_get_usage')) {
            $mem_end = (int) memory_get_usage(true);
            $stats['memory_start_kb'] = (int) round($memStart / 1024);
            $stats['memory_end_kb'] = (int) round($mem_end / 1024);
            $stats['memory_delta_kb'] = (int) round(($mem_end - $memStart) / 1024);
        }

        if (function_exists('memory_get_peak_usage')) {
            $stats['memory_peak_kb'] = (int) round(memory_get_peak_usage(true) / 1024);
        }

        $this->log(
            empty($stats['ok'])
                ? 'Kinsey\'s allowed product staging prune failed.'
                : 'Kinsey\'s allowed product staging prune complete.',
            $stats
        );

        return $stats;
    }

    /**
     * @param array<int,array<string,mixed>> $products
     * @param array<string,array<string,mixed>> $inventoryLookup
     * @param array<string,string> $phaseMs
     */
    private function import_products_array_via_batches(
        array $products,
        array $inventoryLookup,
        float $t_start,
        int $mem_start,
        array $phaseMs
    ): int {
        try {
            $t_phase = microtime(true);
            $this->table->createTables();
            $this->table->truncate_staging();
            $phaseMs['prepare_staging_table'] = $this->elapsed_ms($t_phase);
        } catch (\Throwable $e) {
            $this->log('ERROR: Kinsey\'s staging table preparation failed.', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        $batch_size = 500;
        $batch_rows = [];
        $total_inserted = 0;
        $skipped_missing_upc = 0;
        $skipped_non_dropship = 0;
        $skipped_dupe_upc = 0;
        $seen_upcs = [];
        $batch_flushes = 0;
        $profile_detail = $this->profile_detail_enabled();
        $parse_profile = [
            'rows' => 0,
            'total_ms' => 0.0,
            'max_ms' => 0.0,
        ];
        $approval_profile = [
            'rows' => 0,
            'total_ms' => 0.0,
            'max_ms' => 0.0,
        ];
        $flush_profile = [
            'batches' => 0,
            'rows' => 0,
            'max_rows' => 0,
            'total_ms' => 0.0,
            'max_ms' => 0.0,
        ];

        $t_phase = microtime(true);
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            if (!$this->parser->can_drop_ship($product)) {
                $skipped_non_dropship++;
                continue;
            }

            $t_parse = $profile_detail ? microtime(true) : 0.0;
            $row = $this->parser->parse_product($product, $inventoryLookup);
            if ($profile_detail) {
                $this->add_timing_profile($parse_profile, $t_parse, 'rows');
            }

            if (!is_array($row)) {
                $skipped_missing_upc++;
                continue;
            }

            $upc = trim((string) ($row['upc'] ?? ''));
            if ($upc === '') {
                $skipped_missing_upc++;
                continue;
            }

            if (isset($seen_upcs[$upc])) {
                $skipped_dupe_upc++;
                continue;
            }
            $seen_upcs[$upc] = true;

            $t_approval = $profile_detail ? microtime(true) : 0.0;
            $batch_rows[] = SigDropshipApproval::apply_to_row('kinseys', $row);
            if ($profile_detail) {
                $this->add_timing_profile($approval_profile, $t_approval, 'rows');
            }

            if (count($batch_rows) >= $batch_size) {
                $flush_rows = count($batch_rows);
                $t_flush = $profile_detail ? microtime(true) : 0.0;
                $total_inserted += $this->flush_staging_batch($batch_rows);
                if ($profile_detail) {
                    $this->add_timing_profile($flush_profile, $t_flush, 'batches');
                    $flush_profile['rows'] += $flush_rows;
                    $flush_profile['max_rows'] = max((int) $flush_profile['max_rows'], $flush_rows);
                }
                $batch_flushes++;
                $batch_rows = [];
            }
        }

        if (!empty($batch_rows)) {
            $flush_rows = count($batch_rows);
            $t_flush = $profile_detail ? microtime(true) : 0.0;
            $total_inserted += $this->flush_staging_batch($batch_rows);
            if ($profile_detail) {
                $this->add_timing_profile($flush_profile, $t_flush, 'batches');
                $flush_profile['rows'] += $flush_rows;
                $flush_profile['max_rows'] = max((int) $flush_profile['max_rows'], $flush_rows);
            }
            $batch_flushes++;
        }
        $phaseMs['parse_and_insert_rows'] = $this->elapsed_ms($t_phase);

        if ($total_inserted > 0) {
            update_option('fflhub_kinseys_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_kinseys_fulfillment_last_import_count', (int) $total_inserted, false);
        }

        $ctx = [
            'mode' => 'batched_insert_fallback',
            'products_in' => count($products),
            'rows_inserted' => (int) $total_inserted,
            'skipped_missing_upc' => (int) $skipped_missing_upc,
            'skipped_non_dropship' => (int) $skipped_non_dropship,
            'skipped_dupe_upc' => (int) $skipped_dupe_upc,
            'batch_size' => (int) $batch_size,
            'batch_flushes' => (int) $batch_flushes,
            'phase_ms' => $phaseMs,
            'elapsed_ms' => $this->elapsed_ms($t_start),
        ];

        if ($mem_start > 0 && function_exists('memory_get_usage')) {
            $mem_end = (int) memory_get_usage(true);
            $ctx['memory_start_kb'] = (int) round($mem_start / 1024);
            $ctx['memory_end_kb'] = (int) round($mem_end / 1024);
            $ctx['memory_delta_kb'] = (int) round(($mem_end - $mem_start) / 1024);
        }

        if ($profile_detail) {
            $ctx['detail_profile'] = [
                'parse_product' => $this->timing_profile_summary($parse_profile, 'rows'),
                'sig_approval' => $this->timing_profile_summary($approval_profile, 'rows'),
                'db_flush' => $this->timing_profile_summary($flush_profile, 'batches') + [
                    'rows' => (int) $flush_profile['rows'],
                    'max_rows' => (int) $flush_profile['max_rows'],
                ],
            ];
        }

        $this->log('Kinsey\'s product import complete.', $ctx);

        return (int) $total_inserted;
    }

    /**
     * Import TSV into the staging table, preferring LOAD DATA LOCAL INFILE.
     *
     * @param string[] $columns
     */
    private function import_from_tsv_file(string $file_path, array $columns): int
    {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            $this->log('Kinsey\'s TSV import file missing/unreadable.', [
                'file_path' => $file_path,
            ]);
            return 0;
        }

        if ($this->can_use_load_data_local_infile()) {
            $rows = $this->import_tsv_via_load_data($file_path, $columns);
            if ($rows >= 0) {
                return $rows;
            }

            $this->log('Kinsey\'s LOAD DATA path failed; falling back to PHP TSV batching.', [
                'file_path' => $file_path,
            ]);
        }

        return $this->import_tsv_via_php($file_path, $columns);
    }

    /**
     * @param string[] $columns
     */
    private function import_tsv_via_load_data(string $file_path, array $columns): int
    {
        global $wpdb;

        $t_start = microtime(true);
        $table_name = $this->table->get_staging_table_name();

        $column_list = implode(
            ', ',
            array_map(
                static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
                $columns
            )
        );

        $sql = "
            LOAD DATA LOCAL INFILE %s
            INTO TABLE {$table_name}
            CHARACTER SET utf8mb4
            FIELDS TERMINATED BY '\\t' ENCLOSED BY '\"' ESCAPED BY '\\\\'
            LINES TERMINATED BY '\\n'
            ({$column_list})
        ";

        try {
            $this->table->createTables();
            $this->table->truncate_staging();

            $prepared = $wpdb->prepare($sql, $file_path);
            $result = $wpdb->query($prepared);
            if ($result === false) {
                $this->log('Kinsey\'s LOAD DATA query failed.', [
                    'error' => (string) $wpdb->last_error,
                    'file_path' => $file_path,
                ]);
                return -1;
            }

            $wpdb->query("DELETE FROM {$table_name} WHERE upc IS NULL OR upc = '' OR LOWER(upc) = 'null'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sig_approved_forced = SigDropshipApproval::apply_to_table('kinseys', $table_name);
        } catch (\Throwable $e) {
            $this->log('Kinsey\'s LOAD DATA exception.', [
                'error' => $e->getMessage(),
                'file_path' => $file_path,
            ]);
            return -1;
        }

        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $this->log('Kinsey\'s TSV loaded via LOAD DATA LOCAL INFILE.', [
            'file_path' => $file_path,
            'rows' => (int) $rows,
            'sig_approved_forced' => (int) $sig_approved_forced,
            'elapsed_ms' => $this->elapsed_ms($t_start),
        ]);

        return $rows;
    }

    /**
     * @param string[] $columns
     */
    private function import_tsv_via_php(string $file_path, array $columns): int
    {
        $t_start = microtime(true);
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $this->log('Kinsey\'s PHP TSV fallback fopen failed.', [
                'file_path' => $file_path,
            ]);
            return 0;
        }

        try {
            $this->table->createTables();
            $this->table->truncate_staging();
        } catch (\Throwable $e) {
            fclose($handle);
            $this->log('Kinsey\'s PHP TSV fallback staging preparation failed.', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        $batch_size = 1000;
        $batch_rows = [];
        $inserted_total = 0;
        $line_count = 0;

        while (($values = fgetcsv($handle, 0, "\t", '"', '\\')) !== false) {
            $line_count++;
            if (!is_array($values) || empty($values)) {
                continue;
            }

            $values = array_pad($values, count($columns), '');
            if (count($values) > count($columns)) {
                $values = array_slice($values, 0, count($columns));
            }

            $row = [];
            foreach ($columns as $index => $column) {
                $row[$column] = $values[$index] ?? '';
            }

            $upc = trim((string) ($row['upc'] ?? ''));
            if ($upc === '' || strtolower($upc) === 'null') {
                continue;
            }

            $batch_rows[] = $row;
            if (count($batch_rows) >= $batch_size) {
                $inserted_total += $this->flush_staging_batch($batch_rows);
                $batch_rows = [];
            }
        }

        fclose($handle);

        if (!empty($batch_rows)) {
            $inserted_total += $this->flush_staging_batch($batch_rows);
        }

        $this->log('Kinsey\'s TSV imported via PHP fallback.', [
            'file_path' => $file_path,
            'lines_seen' => (int) $line_count,
            'rows_inserted' => (int) $inserted_total,
            'elapsed_ms' => $this->elapsed_ms($t_start),
        ]);

        return (int) $inserted_total;
    }

    /**
     * @param array<int,array<string,mixed>> $products
     * @param array<string,array<string,mixed>> $inventoryLookup
     * @param string[] $columns
     * @return array<string,mixed>
     */
    private function write_catalog_tsv(array $products, array $inventoryLookup, array $columns, string $tsvPath): array
    {
        $t_start = microtime(true);
        $handle = fopen($tsvPath, 'w');
        if (!$handle) {
            return [
                'tsv_path' => $tsvPath,
                'products_in' => count($products),
                'rows_written' => 0,
                'skipped_missing_upc' => 0,
                'skipped_non_dropship' => 0,
                'skipped_dupe_upc' => 0,
                'write_failures' => 0,
                'write_error' => 'fopen failed',
                'write_ms' => $this->elapsed_ms($t_start),
            ];
        }

        $rows_written = 0;
        $skipped_missing_upc = 0;
        $skipped_non_dropship = 0;
        $skipped_dupe_upc = 0;
        $write_failures = 0;
        $seen_upcs = [];
        $profile_detail = $this->profile_detail_enabled();
        $parse_profile = [
            'rows' => 0,
            'total_ms' => 0.0,
            'max_ms' => 0.0,
        ];
        $approval_profile = [
            'rows' => 0,
            'total_ms' => 0.0,
            'max_ms' => 0.0,
        ];

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            if (!$this->parser->can_drop_ship($product)) {
                $skipped_non_dropship++;
                continue;
            }

            $t_parse = $profile_detail ? microtime(true) : 0.0;
            $row = $this->parser->parse_product($product, $inventoryLookup);
            if ($profile_detail) {
                $this->add_timing_profile($parse_profile, $t_parse, 'rows');
            }

            if (!is_array($row)) {
                $skipped_missing_upc++;
                continue;
            }

            $upc = trim((string) ($row['upc'] ?? ''));
            if ($upc === '') {
                $skipped_missing_upc++;
                continue;
            }

            if (isset($seen_upcs[$upc])) {
                $skipped_dupe_upc++;
                continue;
            }
            $seen_upcs[$upc] = true;

            $t_approval = $profile_detail ? microtime(true) : 0.0;
            $row = SigDropshipApproval::apply_to_row('kinseys', $row);
            if ($profile_detail) {
                $this->add_timing_profile($approval_profile, $t_approval, 'rows');
            }

            $values = [];
            foreach ($columns as $column) {
                $values[] = array_key_exists($column, $row) ? (string) $row[$column] : '';
            }

            $written = fputcsv($handle, $values, "\t", '"', '\\');
            if ($written === false) {
                $write_failures++;
                continue;
            }

            $rows_written++;
        }

        fclose($handle);
        clearstatcache(true, $tsvPath);

        $stats = [
            'tsv_path' => $tsvPath,
            'tsv_bytes' => file_exists($tsvPath) ? (int) filesize($tsvPath) : 0,
            'products_in' => count($products),
            'rows_written' => (int) $rows_written,
            'skipped_missing_upc' => (int) $skipped_missing_upc,
            'skipped_non_dropship' => (int) $skipped_non_dropship,
            'skipped_dupe_upc' => (int) $skipped_dupe_upc,
            'write_failures' => (int) $write_failures,
            'write_ms' => $this->elapsed_ms($t_start),
        ];

        if ($profile_detail) {
            $stats['detail_profile'] = [
                'parse_product' => $this->timing_profile_summary($parse_profile, 'rows'),
                'sig_approval' => $this->timing_profile_summary($approval_profile, 'rows'),
            ];
        }

        return $stats;
    }

    private function resolve_catalog_tsv_path(): string
    {
        $uploads = wp_upload_dir();
        $base_dir = rtrim((string) ($uploads['basedir'] ?? ''), '/\\');
        if ($base_dir === '') {
            return '';
        }

        $dir = $base_dir . '/fflhub/kinseys';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            $this->log('Failed to create Kinsey\'s catalog TSV directory.', [
                'dir' => $dir,
            ]);
            return '';
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            $this->log('Kinsey\'s catalog TSV directory is not writable.', [
                'dir' => $dir,
            ]);
            return '';
        }

        return $dir . '/catalog_' . gmdate('Ymd_His') . '.tsv';
    }

    private function can_use_load_data_local_infile(): bool
    {
        global $wpdb;

        $row = $wpdb->get_row("SHOW VARIABLES LIKE 'local_infile'", ARRAY_A);
        $mysql_value = is_array($row) ? strtolower((string) ($row['Value'] ?? $row['value'] ?? '')) : '';
        $mysql_ok = in_array($mysql_value, ['on', '1', 'true'], true);

        $mysqli = ini_get('mysqli.allow_local_infile');
        $pdo = ini_get('pdo_mysql.allow_local_infile');

        $php_ok = $this->ini_truthy($mysqli) || $this->ini_truthy($pdo);
        $ok = $mysql_ok && $php_ok;

        $this->log('Kinsey\'s LOAD DATA LOCAL INFILE capability check.', [
            'mysql_local_infile' => $mysql_value,
            'mysql_ok' => $mysql_ok ? 1 : 0,
            'mysqli_allow_local_infile' => $mysqli !== false ? (string) $mysqli : '',
            'pdo_mysql_allow_local_infile' => $pdo !== false ? (string) $pdo : '',
            'php_ok' => $php_ok ? 1 : 0,
            'result' => $ok ? 1 : 0,
        ]);

        return $ok;
    }

    /**
     * @param mixed $value
     */
    private function ini_truthy($value): bool
    {
        if ($value === false || $value === null) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * Snapshot current live inventory so product imports stay catalog-only while
     * preserving the most recently refreshed stock and pricing data.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_live_inventory_rows(): array
    {
        global $wpdb;

        try {
            $this->table->createTables();
        } catch (\Throwable $e) {
            $this->log('Unable to ensure Kinsey\'s tables before live inventory snapshot.', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $live_table = $this->table->get_live_table_name();
        if ($live_table === '') {
            return [];
        }

        $sql = "
            SELECT
                kinseys_product_id AS productId,
                vendor_item_number AS manufacturerId,
                upc,
                distributor_price AS price,
                retail_map AS map,
                inventory_quantity AS quantityOnHand,
                restock_eta AS RestockETA,
                warehouses_json AS Warehouses
            FROM {$live_table}
            WHERE
                COALESCE(kinseys_product_id, '') <> ''
                OR COALESCE(vendor_item_number, '') <> ''
                OR COALESCE(upc, '') <> ''
        ";

        $rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            if (isset($row['Warehouses']) && is_string($row['Warehouses'])) {
                $decoded = json_decode($row['Warehouses'], true);
                $row['Warehouses'] = is_array($decoded) ? $decoded : [];
            }
        }
        unset($row);

        return array_values(array_filter($rows, static function ($row): bool {
            return is_array($row);
        }));
    }

    /**
     * @param array<string,mixed> $inventoryResponseOrRows
     * @return array<string,mixed>
     */
    public function apply_inventory_array_to_live(array $inventoryResponseOrRows): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $t_start = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        $phase_ms = [];

        $t_phase = microtime(true);
        $rows = $this->parser->normalize_inventory_rows($inventoryResponseOrRows);
        $phase_ms['normalize_inventory_rows'] = $this->elapsed_ms($t_phase);

        if (empty($rows)) {
            return $this->finish_apply_inventory_stats([
                'processed_rows' => 0,
                'rows_loaded' => 0,
                'join_updated' => 0,
                'sig_approved_forced' => 0,
            ], $t_start, $mem_start, $phase_ms);
        }

        global $wpdb;

        $t_phase = microtime(true);
        $this->table->createTables();
        $stage_table = $this->ensure_inventory_stage_table();
        $phase_ms['ensure_inventory_stage_table'] = $this->elapsed_ms($t_phase);
        if ($stage_table === '') {
            return $this->finish_apply_inventory_stats([
                'processed_rows' => count($rows),
                'rows_loaded' => 0,
                'join_updated' => 0,
                'sig_approved_forced' => 0,
            ], $t_start, $mem_start, $phase_ms);
        }

        $t_phase = microtime(true);
        $wpdb->query("TRUNCATE TABLE {$stage_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $phase_ms['truncate_inventory_stage'] = $this->elapsed_ms($t_phase);

        $t_phase = microtime(true);
        $rows_loaded = $this->insert_inventory_stage_rows($stage_table, $rows);
        $phase_ms['insert_inventory_stage_rows'] = $this->elapsed_ms($t_phase);
        if ($rows_loaded <= 0) {
            return $this->finish_apply_inventory_stats([
                'processed_rows' => count($rows),
                'rows_loaded' => 0,
                'join_updated' => 0,
                'sig_approved_forced' => 0,
            ], $t_start, $mem_start, $phase_ms, '', $stage_table);
        }

        $live_table = $this->table->get_live_table_name();

        $t_phase = microtime(true);
        $join_updated_upc = $this->update_live_inventory_by_upc($live_table, $stage_table);
        $phase_ms['update_live_inventory_by_upc'] = $this->elapsed_ms($t_phase);

        $t_phase = microtime(true);
        $join_updated_id = $this->update_live_inventory_by_product_id($live_table, $stage_table);
        $phase_ms['update_live_inventory_by_product_id'] = $this->elapsed_ms($t_phase);

        $t_phase = microtime(true);
        $join_updated_manufacturer = $this->update_live_inventory_by_manufacturer_id($live_table, $stage_table);
        $phase_ms['update_live_inventory_by_manufacturer_id'] = $this->elapsed_ms($t_phase);

        $t_phase = microtime(true);
        $sig_approved_forced = SigDropshipApproval::apply_to_table('kinseys', $live_table);
        $phase_ms['apply_sig_dropship_approval'] = $this->elapsed_ms($t_phase);

        $join_updated = max(0, $join_updated_upc) + max(0, $join_updated_id) + max(0, $join_updated_manufacturer);

        update_option('fflhub_kinseys_inventory_last_update', current_time('mysql'), false);
        update_option('fflhub_kinseys_inventory_last_update_count', (int) $rows_loaded, false);

        return $this->finish_apply_inventory_stats([
            'processed_rows' => count($rows),
            'rows_loaded' => (int) $rows_loaded,
            'join_updated' => (int) $join_updated,
            'join_updated_upc' => (int) max(0, $join_updated_upc),
            'join_updated_id' => (int) max(0, $join_updated_id),
            'join_updated_manufacturer' => (int) max(0, $join_updated_manufacturer),
            'sig_approved_forced' => (int) $sig_approved_forced,
        ], $t_start, $mem_start, $phase_ms, $live_table, $stage_table);
    }

    /**
     * @param array<string,mixed> $stats
     * @param array<string,string> $phaseMs
     * @return array<string,mixed>
     */
    private function finish_apply_inventory_stats(
        array $stats,
        float $tStart,
        int $memStart,
        array $phaseMs,
        string $liveTable = '',
        string $stageTable = ''
    ): array {
        $stats['phase_ms'] = $phaseMs;
        $stats['elapsed_ms'] = $this->elapsed_ms($tStart);

        if ($liveTable !== '') {
            $stats['live_table'] = $liveTable;
        }

        if ($stageTable !== '') {
            $stats['stage_table'] = $stageTable;
        }

        if ($memStart > 0 && function_exists('memory_get_usage')) {
            $mem_end = (int) memory_get_usage(true);
            $stats['memory_start_kb'] = (int) round($memStart / 1024);
            $stats['memory_end_kb'] = (int) round($mem_end / 1024);
            $stats['memory_delta_kb'] = (int) round(($mem_end - $memStart) / 1024);
        }

        if (function_exists('memory_get_peak_usage')) {
            $stats['memory_peak_kb'] = (int) round(memory_get_peak_usage(true) / 1024);
        }

        return $stats;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function flush_staging_batch(array $rows): int
    {
        try {
            return (int) $this->table->insert_rows_into_staging($rows, null, 500);
        } catch (\Throwable $e) {
            $this->log('ERROR: insert_rows_into_staging() failed.', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    public function ensure_inventory_stage_table(): string
    {
        global $wpdb;

        $stage_table = $wpdb->prefix . self::INVENTORY_STAGE_TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();

        $sql = "
            CREATE TABLE {$stage_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id VARCHAR(64) NOT NULL DEFAULT '',
                manufacturer_id VARCHAR(128) NOT NULL DEFAULT '',
                upc VARCHAR(32) NOT NULL DEFAULT '',
                price VARCHAR(32) NULL,
                map_price VARCHAR(32) NULL,
                quantity_on_hand INT UNSIGNED NOT NULL DEFAULT 0,
                restock_eta VARCHAR(255) NULL,
                warehouses_json LONGTEXT NULL,
                PRIMARY KEY (id),
                KEY product_id (product_id),
                KEY manufacturer_id (manufacturer_id),
                KEY upc (upc)
            ) {$charset};
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $stage_table));
        if ($exists !== $stage_table) {
            $this->log('ERROR: failed to ensure Kinsey\'s inventory stage table: ' . (string) $wpdb->last_error);
            return '';
        }

        return $stage_table;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function insert_inventory_stage_rows(string $stageTable, array $rows): int
    {
        global $wpdb;

        $batch_size = 500;
        $values = [];
        $placeholders = [];
        $loaded = 0;

        $flush = function () use (&$values, &$placeholders, &$loaded, $stageTable, $wpdb): void {
            if (empty($placeholders)) {
                return;
            }

            $sql = "INSERT INTO {$stageTable} (product_id, manufacturer_id, upc, price, map_price, quantity_on_hand, restock_eta, warehouses_json) VALUES " . implode(', ', $placeholders);
            $result = $wpdb->query($wpdb->prepare($sql, $values));
            if ($result !== false) {
                $loaded += (int) $result;
            } else {
                $this->log('ERROR: Kinsey\'s inventory stage insert failed: ' . (string) $wpdb->last_error);
            }

            $values = [];
            $placeholders = [];
        };

        foreach ($rows as $row) {
            $product_id = trim((string) ($row['productId'] ?? ''));
            $manufacturer_id = trim((string) ($row['manufacturerId'] ?? ''));
            $upc = preg_replace('/\D+/', '', (string) ($row['upc'] ?? ''));
            $upc = is_string($upc) ? trim($upc) : '';

            if ($product_id === '' && $manufacturer_id === '' && $upc === '') {
                continue;
            }

            $quantity = isset($row['quantityOnHand']) && is_numeric($row['quantityOnHand'])
                ? max(0, (int) floor((float) $row['quantityOnHand']))
                : 0;

            $placeholders[] = '(%s, %s, %s, %s, %s, %d, %s, %s)';
            $values[] = $product_id;
            $values[] = $manufacturer_id;
            $values[] = $upc;
            $values[] = $this->money_string((string) ($row['price'] ?? ''));
            $values[] = $this->money_string((string) ($row['map'] ?? ''));
            $values[] = $quantity;
            $values[] = trim((string) ($row['RestockETA'] ?? ''));
            $values[] = $this->encode_json($row['Warehouses'] ?? []);

            if (count($placeholders) >= $batch_size) {
                $flush();
            }
        }

        $flush();

        return (int) $loaded;
    }

    private function update_live_inventory_by_upc(string $liveTable, string $stageTable): int
    {
        global $wpdb;

        $sql = $this->live_update_sql(
            $liveTable,
            $stageTable,
            "S.upc <> '' AND L.upc = S.upc",
            ''
        );

        $result = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return is_numeric($result) ? (int) $result : 0;
    }

    private function update_live_inventory_by_product_id(string $liveTable, string $stageTable): int
    {
        global $wpdb;

        $sql = $this->live_update_sql(
            $liveTable,
            $stageTable,
            "S.product_id <> '' AND L.kinseys_product_id = S.product_id",
            "LEFT JOIN {$stageTable} SU ON SU.upc <> '' AND L.upc = SU.upc"
        );

        $sql = str_replace('WHERE', 'WHERE SU.id IS NULL AND', $sql);
        $result = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return is_numeric($result) ? (int) $result : 0;
    }

    private function update_live_inventory_by_manufacturer_id(string $liveTable, string $stageTable): int
    {
        global $wpdb;

        $sql = $this->live_update_sql(
            $liveTable,
            $stageTable,
            "S.manufacturer_id <> '' AND L.vendor_item_number = S.manufacturer_id",
            "LEFT JOIN {$stageTable} SU ON SU.upc <> '' AND L.upc = SU.upc
             LEFT JOIN {$stageTable} SI ON SI.product_id <> '' AND L.kinseys_product_id = SI.product_id"
        );

        $sql = str_replace('WHERE', 'WHERE SU.id IS NULL AND SI.id IS NULL AND', $sql);
        $result = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return is_numeric($result) ? (int) $result : 0;
    }

    private function live_update_sql(string $liveTable, string $stageTable, string $joinCondition, string $extraJoin): string
    {
        return "
            UPDATE {$liveTable} L
            INNER JOIN {$stageTable} S
                ON {$joinCondition}
            {$extraJoin}
            SET
                L.inventory_quantity = CAST(S.quantity_on_hand AS CHAR),
                L.allocation_status = CASE WHEN S.quantity_on_hand > 0 THEN 'in_stock' ELSE 'out_of_stock' END,
                L.distributor_price = CASE WHEN COALESCE(S.price, '') <> '' THEN S.price ELSE L.distributor_price END,
                L.retail_map = CASE WHEN COALESCE(S.map_price, '') <> '' THEN S.map_price ELSE L.retail_map END,
                L.restock_eta = S.restock_eta,
                L.warehouses_json = S.warehouses_json
            WHERE
                COALESCE(L.inventory_quantity, '') <> CAST(S.quantity_on_hand AS CHAR)
                OR COALESCE(L.allocation_status, '') <> CASE WHEN S.quantity_on_hand > 0 THEN 'in_stock' ELSE 'out_of_stock' END
                OR (COALESCE(S.price, '') <> '' AND COALESCE(L.distributor_price, '') <> S.price)
                OR (COALESCE(S.map_price, '') <> '' AND COALESCE(L.retail_map, '') <> S.map_price)
                OR COALESCE(L.restock_eta, '') <> COALESCE(S.restock_eta, '')
                OR COALESCE(L.warehouses_json, '') <> COALESCE(S.warehouses_json, '')
        ";
    }

    private function money_string(string $value): string
    {
        $value = preg_replace('/[^0-9.\-]/', '', trim($value));
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return '';
        }

        $num = (float) $value;
        if (!is_finite($num) || $num < 0.0) {
            return '';
        }

        return number_format($num, 2, '.', '');
    }

    /**
     * @param mixed $value
     */
    private function encode_json($value): string
    {
        $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '';
    }

    private function elapsed_ms(float $tStart): string
    {
        return number_format((microtime(true) - $tStart) * 1000.0, 2, '.', '');
    }

    private function profile_detail_enabled(): bool
    {
        return defined(self::DEBUG_FLAG) && (bool) constant(self::DEBUG_FLAG);
    }

    /**
     * @param array<string,int|float> $profile
     */
    private function add_timing_profile(array &$profile, float $tStart, string $countKey): void
    {
        $elapsed = (microtime(true) - $tStart) * 1000.0;
        $profile[$countKey] = (int) $profile[$countKey] + 1;
        $profile['total_ms'] = (float) $profile['total_ms'] + $elapsed;
        $profile['max_ms'] = max((float) $profile['max_ms'], $elapsed);
    }

    /**
     * @param array<string,int|float> $profile
     * @return array<string,mixed>
     */
    private function timing_profile_summary(array $profile, string $countKey): array
    {
        $count = (int) ($profile[$countKey] ?? 0);
        $total = (float) ($profile['total_ms'] ?? 0.0);

        return [
            $countKey => $count,
            'total_ms' => $this->format_ms($total),
            'avg_ms' => $count > 0 ? $this->format_ms($total / $count) : '0.00',
            'max_ms' => $this->format_ms((float) ($profile['max_ms'] ?? 0.0)),
        ];
    }

    private function format_ms(float $ms): string
    {
        return number_format($ms, 2, '.', '');
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $message);
            return;
        }

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $message, $ctx);
    }
}
