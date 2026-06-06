<?php

namespace FFLHub\Distributor\Services\SportsSouth;

use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Imports Sports South catalog XML into the staging table and applies onhand
 * delta XML against the live table.
 */
final class SportsSouthProductImporterService
{
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][SportsSouthImporter]';
    private const DEEP_PROFILE_FLAG = 'FFLHUB_SPORTS_SOUTH_PRODUCT_DEEP_PROFILE';
    private const INVENTORY_STAGE_TABLE_SUFFIX = 'fflhub_sports_south_onhand_stage';
    private const PRODUCT_DELTA_STAGE_TABLE_SUFFIX = 'fflhub_sports_south_product_delta_stage';
    private const NON_FFL_SHIPPING_COST = '7.95';
    private const FFL_SHIPPING_COST = '8.95';

    private DoubleBufferedProductTable $table;
    private SportsSouthProductParser $parser;
    /** @var string[] */
    private array $last_artifact_paths = [];

    public function __construct(DoubleBufferedProductTable $table, ?SportsSouthProductParser $parser = null)
    {
        $this->table = $table;
        $this->parser = $parser ?: new SportsSouthProductParser();
    }

    /**
     * @param array<string,array<string,mixed>> $brandMap
     * @param array<string,array<string,mixed>> $categoryMap
     */
    public function import_catalog_file(string $xmlFilePath, array $brandMap = [], array $categoryMap = []): int
    {
        $t_start = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        $this->last_artifact_paths = [];

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (!is_readable($xmlFilePath)) {
            $this->log('Sports South catalog XML missing/unreadable.', [
                'xml_path' => $xmlFilePath,
            ]);
            return 0;
        }

        $columns = $this->table->get_schema()->get_insert_columns();
        $tsv_path = $this->catalog_tsv_path();
        if ($tsv_path !== '') {
            $this->last_artifact_paths[] = $tsv_path;
        }
        if ($tsv_path === '' || empty($columns)) {
            return $this->import_catalog_file_via_batches($xmlFilePath, $t_start, $mem_start, $brandMap, $categoryMap);
        }

        $write_stats = $this->write_catalog_tsv($xmlFilePath, $tsv_path, $columns, $brandMap, $categoryMap);
        if ((int) ($write_stats['rows_written'] ?? 0) <= 0) {
            $this->log('Sports South catalog import wrote zero TSV rows; not loading.', $write_stats);
            return 0;
        }

        $count = $this->import_tsv_into_staging($tsv_path, $columns);

        if ($count > 0) {
            update_option('fflhub_sports_south_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_sports_south_fulfillment_last_import_count', (int) $count, false);
        }

        $ctx = array_merge($write_stats, [
            'rows_inserted' => (int) $count,
            'elapsed_ms' => number_format((microtime(true) - $t_start) * 1000.0, 2, '.', ''),
        ]);

        if ($mem_start > 0 && function_exists('memory_get_usage')) {
            $mem_end = (int) memory_get_usage(true);
            $ctx['memory_start_kb'] = (int) round($mem_start / 1024);
            $ctx['memory_end_kb'] = (int) round($mem_end / 1024);
            $ctx['memory_delta_kb'] = (int) round(($mem_end - $mem_start) / 1024);
        }

        $this->log('Sports South catalog import complete.', $ctx);

        return (int) $count;
    }

    public function cleanup_last_artifacts(): int
    {
        $deleted = 0;
        foreach (array_values(array_unique($this->last_artifact_paths)) as $path) {
            if (!is_string($path) || $path === '' || !is_file($path)) {
                continue;
            }

            if (@unlink($path)) {
                $deleted++;
            }
        }

        $this->last_artifact_paths = [];
        return $deleted;
    }

    /**
     * Apply a partial DailyItemUpdate catalog response to the current live table.
     *
     * The Sports South distributor row is treated as vendor-owned data. For a
     * matching UPC, the incoming normalized delta row replaces the existing row
     * fields the same way a full rebuild would once the table is swapped.
     *
     * DailyItemUpdate is allowed to reconcile quantity/price fields because
     * Sports South's incremental onhand feed can drift between catalog snapshots.
     *
     * @param array<string,array<string,mixed>> $brandMap
     * @param array<string,array<string,mixed>> $categoryMap
     * @return array<string,mixed>
     */
    public function import_catalog_delta_file(string $xmlFilePath, array $brandMap = [], array $categoryMap = []): array
    {
        $t_start = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        $this->last_artifact_paths = [];

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $live_count_before = $this->count_live_rows();
        $delta_stage_table = $this->ensure_catalog_delta_stage_table();
        if ($delta_stage_table === '') {
            return [
                'mode' => 'incremental_catalog_update',
                'error' => 'Failed to ensure Sports South catalog delta stage table.',
                'xml_path' => $xmlFilePath,
                'live_rows_before' => $live_count_before,
                'live_rows_after' => $live_count_before,
                'rows_loaded' => 0,
                'rows_updated' => 0,
                'rows_inserted' => 0,
            ];
        }

        if (!is_readable($xmlFilePath)) {
            return [
                'mode' => 'incremental_catalog_update',
                'error' => 'Sports South catalog XML missing/unreadable.',
                'xml_path' => $xmlFilePath,
                'live_rows_before' => $live_count_before,
                'live_rows_after' => $live_count_before,
                'rows_loaded' => 0,
                'rows_updated' => 0,
                'rows_inserted' => 0,
            ];
        }

        $columns = $this->table->get_schema()->get_insert_columns();
        $tsv_path = $this->catalog_tsv_path();
        if ($tsv_path !== '') {
            $this->last_artifact_paths[] = $tsv_path;
        }

        if ($tsv_path === '' || empty($columns)) {
            return [
                'mode' => 'incremental_catalog_update',
                'error' => 'Failed to resolve Sports South delta TSV path or schema columns.',
                'xml_path' => $xmlFilePath,
                'live_rows_before' => $live_count_before,
                'live_rows_after' => $live_count_before,
                'rows_loaded' => 0,
                'rows_updated' => 0,
                'rows_inserted' => 0,
            ];
        }

        $t_write = microtime(true);
        $write_stats = $this->write_catalog_tsv($xmlFilePath, $tsv_path, $columns, $brandMap, $categoryMap);
        $write_stats['delta_write_ms'] = $this->format_ms((microtime(true) - $t_write) * 1000.0);

        if ((int) ($write_stats['rows_written'] ?? 0) <= 0) {
            $stats = array_merge($write_stats, [
                'mode' => 'incremental_catalog_update',
                'rows_loaded' => 0,
                'rows_updated' => 0,
                'rows_inserted' => 0,
                'live_rows_before' => $live_count_before,
                'live_rows_after' => $live_count_before,
                'elapsed_ms' => $this->format_ms((microtime(true) - $t_start) * 1000.0),
            ]);
            $this->log('Sports South catalog delta contained no importable rows.', $stats);
            return $stats;
        }

        $t_load = microtime(true);
        $rows_loaded = $this->import_tsv_into_table($tsv_path, $columns, $delta_stage_table);
        $load_ms = $this->format_ms((microtime(true) - $t_load) * 1000.0);

        if ($rows_loaded < 0) {
            return array_merge($write_stats, [
                'mode' => 'incremental_catalog_update',
                'error' => 'Failed to load Sports South catalog delta staging table.',
                'rows_loaded' => 0,
                'rows_updated' => 0,
                'rows_inserted' => 0,
                'delta_stage_load_ms' => $load_ms,
                'live_rows_before' => $live_count_before,
                'live_rows_after' => $this->count_live_rows(),
                'elapsed_ms' => $this->format_ms((microtime(true) - $t_start) * 1000.0),
            ]);
        }

        $t_apply = microtime(true);
        $apply_stats = $this->apply_catalog_delta_from_stage_table($columns, $delta_stage_table);
        $apply_ms = $this->format_ms((microtime(true) - $t_apply) * 1000.0);
        $live_count_after = $this->count_live_rows();

        $stats = array_merge($write_stats, $apply_stats, [
            'mode' => 'incremental_catalog_update',
            'rows_loaded' => (int) $rows_loaded,
            'delta_stage_load_ms' => $load_ms,
            'delta_apply_ms' => $apply_ms,
            'live_rows_before' => $live_count_before,
            'live_rows_after' => $live_count_after,
            'live_rows_delta' => $live_count_after - $live_count_before,
            'elapsed_ms' => $this->format_ms((microtime(true) - $t_start) * 1000.0),
        ]);

        if ($mem_start > 0 && function_exists('memory_get_usage')) {
            $mem_end = (int) memory_get_usage(true);
            $stats['memory_start_kb'] = (int) round($mem_start / 1024);
            $stats['memory_end_kb'] = (int) round($mem_end / 1024);
            $stats['memory_delta_kb'] = (int) round(($mem_end - $mem_start) / 1024);
        }

        $this->log('Sports South catalog delta import complete.', $stats);

        return $stats;
    }

    public function apply_onhand_delta_file_to_live(string $xmlFilePath, bool $treatQuantityAsDelta = true): array
    {
        $t_total = microtime(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (!is_readable($xmlFilePath)) {
            return [
                'processed_rows' => 0,
                'rows_loaded' => 0,
                'join_updated' => 0,
                'error' => 'XML file missing/unreadable',
            ];
        }

        $t_ensure = microtime(true);
        $stage_table = $this->ensure_inventory_stage_table();
        $ensure_ms = $this->format_ms((microtime(true) - $t_ensure) * 1000.0);
        if ($stage_table === '') {
            return [
                'processed_rows' => 0,
                'rows_loaded' => 0,
                'join_updated' => 0,
                'error' => 'Failed to ensure stage table',
            ];
        }

        global $wpdb;
        $t_truncate = microtime(true);
        $wpdb->query("TRUNCATE TABLE {$stage_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $truncate_ms = $this->format_ms((microtime(true) - $t_truncate) * 1000.0);

        $t_insert = microtime(true);
        $rows_loaded = $this->insert_onhand_stage_rows($stage_table, $xmlFilePath);
        $insert_ms = $this->format_ms((microtime(true) - $t_insert) * 1000.0);
        if ($rows_loaded <= 0) {
            return [
                'processed_rows' => 0,
                'rows_loaded' => 0,
                'join_updated' => 0,
                'ensure_stage_ms' => $ensure_ms,
                'truncate_stage_ms' => $truncate_ms,
                'insert_stage_ms' => $insert_ms,
            ];
        }

        $live_table = $this->table->get_live_table_name();
        $t_update_item = microtime(true);
        $updated_item = $this->update_live_inventory_by_item_number($live_table, $stage_table, $treatQuantityAsDelta);
        $update_item_ms = $this->format_ms((microtime(true) - $t_update_item) * 1000.0);
        $t_update_upc = microtime(true);
        $updated_upc = $this->update_live_inventory_by_upc($live_table, $stage_table, $treatQuantityAsDelta);
        $update_upc_ms = $this->format_ms((microtime(true) - $t_update_upc) * 1000.0);

        $stats = [
            'processed_rows' => (int) $rows_loaded,
            'rows_loaded' => (int) $rows_loaded,
            'join_updated' => (int) max(0, $updated_item) + (int) max(0, $updated_upc),
            'join_updated_item' => (int) max(0, $updated_item),
            'join_updated_upc' => (int) max(0, $updated_upc),
            'quantity_mode' => $treatQuantityAsDelta ? 'quantity_delta' : 'current_quantity',
            'live_table' => $live_table,
            'stage_table' => $stage_table,
            'ensure_stage_ms' => $ensure_ms,
            'truncate_stage_ms' => $truncate_ms,
            'insert_stage_ms' => $insert_ms,
            'update_item_ms' => $update_item_ms,
            'update_upc_ms' => $update_upc_ms,
            'apply_total_ms' => $this->format_ms((microtime(true) - $t_total) * 1000.0),
        ];

        $this->log('Sports South onhand update applied.', $stats);

        return $stats;
    }

    /**
     * @param array<string,array<string,mixed>> $brandMap
     * @param array<string,array<string,mixed>> $categoryMap
     */
    private function import_catalog_file_via_batches(string $xmlFilePath, float $tStart, int $memStart, array $brandMap = [], array $categoryMap = []): int
    {
        try {
            $this->table->truncate_staging();
        } catch (\Throwable $e) {
            $this->log('ERROR: truncate_staging() failed: ' . $e->getMessage());
            return 0;
        }

        $batch = [];
        $total = 0;
        $seen = [];
        $skipped_dupes = 0;
        $brand_hits = 0;
        $category_hits = 0;
        $fulfillment_policy_blocks = 0;
        $accessories_only_enabled = SportsSouthAccessoriesOnlyPolicy::is_enabled();
        $accessories_only_skipped = 0;

        $this->parser->each_catalog_row($xmlFilePath, function (array $row) use (&$batch, &$total, &$seen, &$skipped_dupes, &$brand_hits, &$category_hits, &$fulfillment_policy_blocks, $accessories_only_enabled, &$accessories_only_skipped, $brandMap, $categoryMap): void {
            $upc = trim((string) ($row['upc'] ?? ''));
            if ($upc === '') {
                return;
            }
            if (isset($seen[$upc])) {
                $skipped_dupes++;
                return;
            }
            $seen[$upc] = true;

            $row = $this->apply_brand_map($row, $brandMap, $brand_hits);
            $row = $this->apply_category_map($row, $categoryMap, $category_hits);
            $row = SportsSouthFulfillmentPolicy::apply_to_row($row);
            $row = SigDropshipApproval::apply_to_row('sports_south', $row);
            $row = $this->apply_shipping_cost_rule($row);
            if (SportsSouthFulfillmentPolicy::is_policy_blocked_row($row)) {
                $fulfillment_policy_blocks++;
            }
            if ($accessories_only_enabled && SportsSouthAccessoriesOnlyPolicy::row_is_ffl_or_sot($row)) {
                $accessories_only_skipped++;
                return;
            }

            $batch[] = $row;
            if (count($batch) >= 500) {
                $total += $this->flush_staging_batch($batch);
                $batch = [];
            }
        });

        if (!empty($batch)) {
            $total += $this->flush_staging_batch($batch);
        }

        $this->log('Sports South catalog import complete.', [
            'mode' => 'batched_insert_fallback',
            'rows_inserted' => (int) $total,
            'skipped_dupes' => (int) $skipped_dupes,
            'brand_map_count' => count($brandMap),
            'brand_map_hits' => (int) $brand_hits,
            'category_map_count' => count($categoryMap),
            'category_map_hits' => (int) $category_hits,
            'fulfillment_policy_blocks' => (int) $fulfillment_policy_blocks,
            'accessories_only_enabled' => $accessories_only_enabled ? 1 : 0,
            'accessories_only_skipped' => (int) $accessories_only_skipped,
            'elapsed_ms' => number_format((microtime(true) - $tStart) * 1000.0, 2, '.', ''),
            'memory_start_kb' => $memStart > 0 ? (int) round($memStart / 1024) : 0,
        ]);

        return (int) $total;
    }

    /**
     * @param string[] $columns
     * @param array<string,array<string,mixed>> $brandMap
     * @param array<string,array<string,mixed>> $categoryMap
     * @return array<string,mixed>
     */
    private function write_catalog_tsv(string $xmlFilePath, string $tsvPath, array $columns, array $brandMap = [], array $categoryMap = []): array
    {
        $t_start = microtime(true);
        $handle = fopen($tsvPath, 'w');
        if (!$handle) {
            return [
                'tsv_path' => $tsvPath,
                'rows_written' => 0,
                'write_error' => 'fopen failed',
            ];
        }

        $rows_written = 0;
        $skipped_dupes = 0;
        $brand_hits = 0;
        $category_hits = 0;
        $fulfillment_policy_blocks = 0;
        $accessories_only_enabled = SportsSouthAccessoriesOnlyPolicy::is_enabled();
        $accessories_only_skipped = 0;
        $rows_before_accessories_skip = 0;
        $rows_with_blank_upc_after_parse = 0;
        $seen = [];
        $deep_profile = $this->deep_profile_enabled();
        $detail_ms = [
            'parser_callback_ms' => 0.0,
            'brand_map_ms' => 0.0,
            'category_map_ms' => 0.0,
            'fulfillment_policy_ms' => 0.0,
            'sig_policy_ms' => 0.0,
            'accessories_skip_check_ms' => 0.0,
            'values_array_build_ms' => 0.0,
            'fputcsv_ms' => 0.0,
        ];

        $t_parse = microtime(true);
        $xml_rows_seen = $this->parser->each_catalog_row($xmlFilePath, function (array $row) use ($handle, $columns, &$rows_written, &$skipped_dupes, &$brand_hits, &$category_hits, &$fulfillment_policy_blocks, $accessories_only_enabled, &$accessories_only_skipped, &$rows_before_accessories_skip, &$rows_with_blank_upc_after_parse, &$seen, $brandMap, $categoryMap, $deep_profile, &$detail_ms): void {
            $t_callback = $deep_profile ? microtime(true) : 0.0;

            try {
                $upc = trim((string) ($row['upc'] ?? ''));
                if ($upc === '') {
                    $rows_with_blank_upc_after_parse++;
                    return;
                }
                if (isset($seen[$upc])) {
                    $skipped_dupes++;
                    return;
                }
                $seen[$upc] = true;

                if ($deep_profile) {
                    $t = microtime(true);
                }
                $row = $this->apply_brand_map($row, $brandMap, $brand_hits);
                if ($deep_profile) {
                    $detail_ms['brand_map_ms'] += (microtime(true) - $t) * 1000.0;
                    $t = microtime(true);
                }

                $row = $this->apply_category_map($row, $categoryMap, $category_hits);
                if ($deep_profile) {
                    $detail_ms['category_map_ms'] += (microtime(true) - $t) * 1000.0;
                    $t = microtime(true);
                }

                $row = SportsSouthFulfillmentPolicy::apply_to_row($row);
                if ($deep_profile) {
                    $detail_ms['fulfillment_policy_ms'] += (microtime(true) - $t) * 1000.0;
                    $t = microtime(true);
                }

                $row = SigDropshipApproval::apply_to_row('sports_south', $row);
                if ($deep_profile) {
                    $detail_ms['sig_policy_ms'] += (microtime(true) - $t) * 1000.0;
                }

                $row = $this->apply_shipping_cost_rule($row);

                if (SportsSouthFulfillmentPolicy::is_policy_blocked_row($row)) {
                    $fulfillment_policy_blocks++;
                }

                $rows_before_accessories_skip++;
                if ($deep_profile) {
                    $t = microtime(true);
                }
                $skip_accessories_only = $accessories_only_enabled && SportsSouthAccessoriesOnlyPolicy::row_is_ffl_or_sot($row);
                if ($deep_profile) {
                    $detail_ms['accessories_skip_check_ms'] += (microtime(true) - $t) * 1000.0;
                }
                if ($skip_accessories_only) {
                    $accessories_only_skipped++;
                    return;
                }

                if ($deep_profile) {
                    $t = microtime(true);
                }
                $values = [];
                foreach ($columns as $column) {
                    $values[] = array_key_exists($column, $row) ? (string) $row[$column] : '';
                }
                if ($deep_profile) {
                    $detail_ms['values_array_build_ms'] += (microtime(true) - $t) * 1000.0;
                    $t = microtime(true);
                }

                fputcsv($handle, $values, "\t", '"', '\\');
                if ($deep_profile) {
                    $detail_ms['fputcsv_ms'] += (microtime(true) - $t) * 1000.0;
                }
                $rows_written++;
            } finally {
                if ($deep_profile) {
                    $detail_ms['parser_callback_ms'] += (microtime(true) - $t_callback) * 1000.0;
                }
            }
        });
        $xml_parse_callback_ms = (microtime(true) - $t_parse) * 1000.0;

        fclose($handle);
        clearstatcache(true, $tsvPath);
        $parser_stats = $this->parser->get_last_catalog_stats();
        $detail_profile = [];
        if ($deep_profile) {
            foreach ($detail_ms as $key => $ms) {
                $detail_profile[$key] = $this->format_ms($ms);
            }
        }

        $stats = [
            'xml_path' => $xmlFilePath,
            'tsv_path' => $tsvPath,
            'tsv_bytes' => file_exists($tsvPath) ? (int) filesize($tsvPath) : 0,
            'xml_rows_seen' => (int) $xml_rows_seen,
            'catalog_rows_parsed' => (int) ($parser_stats['catalog_rows_parsed'] ?? 0),
            'rows_with_blank_upc' => (int) ($parser_stats['rows_with_blank_upc'] ?? 0) + (int) $rows_with_blank_upc_after_parse,
            'parse_product_null' => (int) ($parser_stats['parse_product_null'] ?? 0),
            'rows_before_accessories_skip' => (int) $rows_before_accessories_skip,
            'rows_written' => (int) $rows_written,
            'skipped_dupes' => (int) $skipped_dupes,
            'brand_map_count' => count($brandMap),
            'brand_map_hits' => (int) $brand_hits,
            'category_map_count' => count($categoryMap),
            'category_map_hits' => (int) $category_hits,
            'fulfillment_policy_blocks' => (int) $fulfillment_policy_blocks,
            'accessories_only_enabled' => $accessories_only_enabled ? 1 : 0,
            'accessories_only_skipped' => (int) $accessories_only_skipped,
            'deep_profile_enabled' => $deep_profile ? 1 : 0,
            'xml_parse_callback_ms' => $this->format_ms($xml_parse_callback_ms),
            'write_ms' => number_format((microtime(true) - $t_start) * 1000.0, 2, '.', ''),
        ];

        if (!empty($detail_profile)) {
            $stats['detail_profile_ms'] = $detail_profile;
        }

        return $stats;
    }

    /**
     * @param string[] $columns
     */
    private function import_tsv_into_staging(string $tsvPath, array $columns): int
    {
        return $this->import_tsv_into_table($tsvPath, $columns, $this->table->get_staging_table_name());
    }

    /**
     * @param string[] $columns
     */
    private function import_tsv_into_table(string $tsvPath, array $columns, string $tableName): int
    {
        if (!is_readable($tsvPath)) {
            return 0;
        }

        if ($this->can_use_load_data_local_infile()) {
            $rows = $this->import_tsv_via_load_data($tsvPath, $columns, $tableName);
            if ($rows >= 0) {
                return $rows;
            }
        }

        return $this->import_tsv_via_php($tsvPath, $columns, $tableName);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array<string,mixed>> $brandMap
     * @return array<string,mixed>
     */
    private function apply_brand_map(array $row, array $brandMap, int &$brandHits): array
    {
        if (empty($brandMap)) {
            return $row;
        }

        $brand_number = trim((string) ($row['brand_number'] ?? ''));
        if ($brand_number === '' || !isset($brandMap[$brand_number])) {
            return $row;
        }

        $brand = $brandMap[$brand_number];
        $brand_name = trim((string) ($brand['brand_name'] ?? ''));
        if ($brand_name === '') {
            return $row;
        }

        $row['manufacturer'] = $brand_name;
        $brandHits++;

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array<string,mixed>> $categoryMap
     * @return array<string,mixed>
     */
    private function apply_category_map(array $row, array $categoryMap, int &$categoryHits): array
    {
        if (empty($categoryMap)) {
            return $row;
        }

        $category_id = trim((string) ($row['category_id'] ?? ''));
        if ($category_id === '' || !isset($categoryMap[$category_id])) {
            return $row;
        }

        $categoryHits++;

        return SportsSouthCategoryPolicy::apply_to_row($row, $categoryMap);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function apply_shipping_cost_rule(array $row): array
    {
        $is_ffl = ((int) ($row['ffl_required'] ?? 0)) === 1;
        $row['shipping_cost'] = $is_ffl ? self::FFL_SHIPPING_COST : self::NON_FFL_SHIPPING_COST;

        return $row;
    }

    /**
     * @param string[] $columns
     */
    private function import_tsv_via_load_data(string $tsvPath, array $columns, string $tableName): int
    {
        global $wpdb;

        $column_list = implode(
            ', ',
            array_map(
                static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
                $columns
            )
        );

        $sql = "
            LOAD DATA LOCAL INFILE %s
            INTO TABLE {$tableName}
            CHARACTER SET utf8mb4
            FIELDS TERMINATED BY '\\t' ENCLOSED BY '\"' ESCAPED BY '\\\\'
            LINES TERMINATED BY '\\n'
            ({$column_list})
        ";

        try {
            $t_total = microtime(true);
            $t_truncate = microtime(true);
            $wpdb->query("TRUNCATE TABLE {$tableName}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $truncate_ms = (microtime(true) - $t_truncate) * 1000.0;

            $t_load = microtime(true);
            $result = $wpdb->query($wpdb->prepare($sql, $tsvPath));
            $load_ms = (microtime(true) - $t_load) * 1000.0;
            if ($result === false) {
                $this->log('Sports South LOAD DATA query failed.', [
                    'error' => (string) $wpdb->last_error,
                    'tsv_path' => $tsvPath,
                    'load_data_ms' => $this->format_ms($load_ms),
                ]);
                return -1;
            }

            $t_delete = microtime(true);
            $deleted = $wpdb->query("DELETE FROM {$tableName} WHERE upc IS NULL OR upc = '' OR LOWER(upc) = 'null'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $delete_ms = (microtime(true) - $t_delete) * 1000.0;

            $t_count = microtime(true);
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tableName}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $count_ms = (microtime(true) - $t_count) * 1000.0;

            $this->log('Sports South LOAD DATA import profile.', [
                'table_name' => $tableName,
                'tsv_path' => $tsvPath,
                'load_result' => is_numeric($result) ? (int) $result : 0,
                'post_load_deleted' => is_numeric($deleted) ? (int) $deleted : 0,
                'staging_count' => (int) $count,
                'truncate_ms' => $this->format_ms($truncate_ms),
                'load_data_ms' => $this->format_ms($load_ms),
                'post_load_delete_ms' => $this->format_ms($delete_ms),
                'staging_count_ms' => $this->format_ms($count_ms),
                'elapsed_ms' => $this->format_ms((microtime(true) - $t_total) * 1000.0),
            ]);

            return $count;
        } catch (\Throwable $e) {
            $this->log('Sports South LOAD DATA exception.', [
                'error' => $e->getMessage(),
                'tsv_path' => $tsvPath,
            ]);
            return -1;
        }
    }

    /**
     * @param string[] $columns
     */
    private function import_tsv_via_php(string $tsvPath, array $columns, string $tableName): int
    {
        $handle = fopen($tsvPath, 'r');
        if (!$handle) {
            return 0;
        }

        try {
            global $wpdb;
            $wpdb->query("TRUNCATE TABLE {$tableName}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        } catch (\Throwable $e) {
            fclose($handle);
            $this->log('Sports South PHP TSV fallback truncate failed.', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        $batch = [];
        $total = 0;
        while (($values = fgetcsv($handle, 0, "\t", '"', '\\')) !== false) {
            $values = array_pad((array) $values, count($columns), '');
            if (count($values) > count($columns)) {
                $values = array_slice($values, 0, count($columns));
            }

            $row = [];
            foreach ($columns as $index => $column) {
                $row[$column] = $values[$index] ?? '';
            }

            $batch[] = $row;
            if (count($batch) >= 1000) {
                $total += $this->flush_table_batch($tableName, $batch, $columns);
                $batch = [];
            }
        }

        fclose($handle);

        if (!empty($batch)) {
            $total += $this->flush_table_batch($tableName, $batch, $columns);
        }

        return (int) $total;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function flush_staging_batch(array $rows): int
    {
        try {
            return (int) $this->table->insert_rows_into_staging($rows);
        } catch (\Throwable $e) {
            $this->log('ERROR: insert_rows_into_staging() failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param string[] $columns
     */
    private function flush_table_batch(string $tableName, array $rows, array $columns): int
    {
        if (empty($rows) || empty($columns)) {
            return 0;
        }

        global $wpdb;

        $column_sql = implode(', ', array_map([$this, 'quote_identifier'], $columns));
        $row_placeholder = '(' . implode(', ', array_fill(0, count($columns), '%s')) . ')';
        $placeholders = [];
        $values = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $placeholders[] = $row_placeholder;
            foreach ($columns as $column) {
                $values[] = array_key_exists($column, $row) ? (string) $row[$column] : '';
            }
        }

        if (empty($placeholders)) {
            return 0;
        }

        $sql = "INSERT IGNORE INTO {$tableName} ({$column_sql}) VALUES " . implode(', ', $placeholders);
        $result = $wpdb->query($wpdb->prepare($sql, $values)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_numeric($result) ? (int) $result : 0;
    }

    private function ensure_inventory_stage_table(): string
    {
        global $wpdb;

        $stage_table = $wpdb->prefix . self::INVENTORY_STAGE_TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();

        $sql = "
            CREATE TABLE IF NOT EXISTS {$stage_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                item_number VARCHAR(64) NOT NULL DEFAULT '',
                upc VARCHAR(32) NOT NULL DEFAULT '',
                quantity_delta INT NOT NULL DEFAULT 0,
                catalog_price VARCHAR(32) NULL,
                customer_price VARCHAR(32) NULL,
                PRIMARY KEY (id),
                KEY item_number (item_number),
                KEY upc (upc)
            ) {$charset};
        ";

        $created = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($created === false) {
            $this->log('ERROR: failed to ensure Sports South onhand stage table: ' . (string) $wpdb->last_error);
            return '';
        }

        return $stage_table;
    }

    private function insert_onhand_stage_rows(string $stageTable, string $xmlFilePath): int
    {
        if ($this->can_use_load_data_local_infile()) {
            $rows_loaded = $this->load_onhand_stage_xml($stageTable, $xmlFilePath);
            if ($rows_loaded >= 0) {
                return (int) $rows_loaded;
            }
        }

        return $this->insert_onhand_stage_rows_via_php($stageTable, $xmlFilePath);
    }

    private function load_onhand_stage_xml(string $stageTable, string $xmlFilePath): int
    {
        global $wpdb;

        $sql = "
            LOAD XML LOCAL INFILE %s
            INTO TABLE {$stageTable}
            CHARACTER SET utf8mb4
            ROWS IDENTIFIED BY '<Onhand>'
            (@I, @Q, @P, @C, @U)
            SET
                item_number = COALESCE(@I, ''),
                upc = COALESCE(@U, ''),
                quantity_delta = CAST(COALESCE(NULLIF(TRIM(@Q), ''), '0') AS SIGNED),
                catalog_price = COALESCE(@P, ''),
                customer_price = COALESCE(@C, '')
        ";

        $t_load = microtime(true);
        $result = $wpdb->query($wpdb->prepare($sql, $xmlFilePath)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $load_ms = (microtime(true) - $t_load) * 1000.0;
        if ($result === false) {
            $this->log('Sports South onhand LOAD XML query failed.', [
                'stage_table' => $stageTable,
                'xml_path' => $xmlFilePath,
                'error' => (string) $wpdb->last_error,
                'load_xml_ms' => $this->format_ms($load_ms),
            ]);
            return -1;
        }

        $t_delete = microtime(true);
        $deleted = $wpdb->query("DELETE FROM {$stageTable} WHERE item_number = '' AND upc = ''"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $delete_ms = (microtime(true) - $t_delete) * 1000.0;

        $t_count = microtime(true);
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$stageTable}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count_ms = (microtime(true) - $t_count) * 1000.0;

        $this->log('Sports South onhand LOAD XML loaded.', [
            'stage_table' => $stageTable,
            'xml_path' => $xmlFilePath,
            'load_result' => is_numeric($result) ? (int) $result : 0,
            'blank_rows_deleted' => is_numeric($deleted) ? (int) $deleted : 0,
            'rows_loaded' => $count,
            'load_xml_ms' => $this->format_ms($load_ms),
            'delete_blank_ms' => $this->format_ms($delete_ms),
            'stage_count_ms' => $this->format_ms($count_ms),
        ]);

        return $count;
    }

    private function insert_onhand_stage_rows_via_php(string $stageTable, string $xmlFilePath): int
    {
        global $wpdb;

        $batch_size = 500;
        $values = [];
        $placeholders = [];
        $loaded = 0;
        $batches = 0;
        $t_start = microtime(true);

        $flush = function () use (&$values, &$placeholders, &$loaded, &$batches, $stageTable, $wpdb): void {
            if (empty($placeholders)) {
                return;
            }

            $sql = "INSERT INTO {$stageTable} (item_number, upc, quantity_delta, catalog_price, customer_price) VALUES " . implode(', ', $placeholders);
            $result = $wpdb->query($wpdb->prepare($sql, $values));
            if ($result !== false) {
                $loaded += (int) $result;
                $batches++;
            } else {
                $this->log('ERROR: Sports South onhand stage insert failed: ' . (string) $wpdb->last_error);
            }

            $values = [];
            $placeholders = [];
        };

        $xml_rows_seen = $this->parser->each_onhand_row($xmlFilePath, function (array $row) use (&$values, &$placeholders, $batch_size, $flush): void {
            $item_number = trim((string) ($row['item_number'] ?? ''));
            $upc = trim((string) ($row['upc'] ?? ''));
            if ($item_number === '' && $upc === '') {
                return;
            }

            $placeholders[] = '(%s, %s, %d, %s, %s)';
            $values[] = $item_number;
            $values[] = $upc;
            $values[] = (int) ($row['quantity_delta'] ?? 0);
            $values[] = (string) ($row['catalog_price'] ?? '');
            $values[] = (string) ($row['customer_price'] ?? '');

            if (count($placeholders) >= $batch_size) {
                $flush();
            }
        });

        $flush();

        $this->log('Sports South onhand stage insert profile.', [
            'stage_table' => $stageTable,
            'xml_path' => $xmlFilePath,
            'xml_rows_seen' => (int) $xml_rows_seen,
            'rows_loaded' => (int) $loaded,
            'batch_size' => (int) $batch_size,
            'batches' => (int) $batches,
            'mode' => 'php_batch',
            'elapsed_ms' => $this->format_ms((microtime(true) - $t_start) * 1000.0),
        ]);

        return (int) $loaded;
    }

    private function update_live_inventory_by_item_number(string $liveTable, string $stageTable, bool $treatQuantityAsDelta): int
    {
        global $wpdb;

        $current_qty_expression = "CAST(COALESCE(NULLIF(L.inventory_quantity, ''), '0') AS SIGNED)";
        $incoming_qty_expression = 'GREATEST(S.quantity_delta, 0)';
        $qty_expression = $treatQuantityAsDelta
            ? "GREATEST({$current_qty_expression} + S.quantity_delta, 0)"
            : $incoming_qty_expression;
        $quantity_changed_condition = $treatQuantityAsDelta
            ? 'S.quantity_delta <> 0'
            : "{$current_qty_expression} <> {$incoming_qty_expression}";

        $sql = "
            UPDATE {$liveTable} L
            INNER JOIN {$stageTable} S
                ON S.item_number <> '' AND L.sports_south_item_number = S.item_number
            SET
                L.inventory_quantity = CAST({$qty_expression} AS CHAR),
                L.allocation_status = CASE WHEN {$qty_expression} > 0 THEN 'in_stock' ELSE 'out_of_stock' END,
                L.catalog_price = CASE WHEN S.catalog_price <> '' THEN S.catalog_price ELSE L.catalog_price END,
                L.distributor_price = CASE WHEN S.customer_price <> '' THEN S.customer_price ELSE L.distributor_price END,
                L.last_onhand_utc = %s
            WHERE
                {$quantity_changed_condition}
                OR (S.customer_price <> '' AND COALESCE(L.distributor_price, '') <> S.customer_price)
                OR (S.catalog_price <> '' AND COALESCE(L.catalog_price, '') <> S.catalog_price)
        ";

        $result = $wpdb->query($wpdb->prepare($sql, gmdate('Y-m-d H:i:s'))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return is_numeric($result) ? (int) $result : 0;
    }

    private function update_live_inventory_by_upc(string $liveTable, string $stageTable, bool $treatQuantityAsDelta): int
    {
        global $wpdb;

        $current_qty_expression = "CAST(COALESCE(NULLIF(L.inventory_quantity, ''), '0') AS SIGNED)";
        $incoming_qty_expression = 'GREATEST(S.quantity_delta, 0)';
        $qty_expression = $treatQuantityAsDelta
            ? "GREATEST({$current_qty_expression} + S.quantity_delta, 0)"
            : $incoming_qty_expression;
        $quantity_changed_condition = $treatQuantityAsDelta
            ? 'S.quantity_delta <> 0'
            : "{$current_qty_expression} <> {$incoming_qty_expression}";

        $sql = "
            UPDATE {$liveTable} L
            INNER JOIN {$stageTable} S
                ON S.upc <> '' AND L.upc = S.upc
            LEFT JOIN {$stageTable} SI
                ON SI.item_number <> '' AND L.sports_south_item_number = SI.item_number
            SET
                L.inventory_quantity = CAST({$qty_expression} AS CHAR),
                L.allocation_status = CASE WHEN {$qty_expression} > 0 THEN 'in_stock' ELSE 'out_of_stock' END,
                L.catalog_price = CASE WHEN S.catalog_price <> '' THEN S.catalog_price ELSE L.catalog_price END,
                L.distributor_price = CASE WHEN S.customer_price <> '' THEN S.customer_price ELSE L.distributor_price END,
                L.last_onhand_utc = %s
            WHERE
                SI.id IS NULL
                AND (
                    {$quantity_changed_condition}
                    OR (S.customer_price <> '' AND COALESCE(L.distributor_price, '') <> S.customer_price)
                    OR (S.catalog_price <> '' AND COALESCE(L.catalog_price, '') <> S.catalog_price)
                )
        ";

        $result = $wpdb->query($wpdb->prepare($sql, gmdate('Y-m-d H:i:s'))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * @param string[] $columns
     * @return array<string,mixed>
     */
    private function ensure_catalog_delta_stage_table(): string
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::PRODUCT_DELTA_STAGE_TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();
        $columns = $this->table->get_schema()->get_column_definitions();
        $indexes = $this->table->get_schema()->get_index_definitions();
        $lines = [];

        foreach ($columns as $name => $definition) {
            $lines[] = "{$name} {$definition}";
        }

        foreach ($indexes as $index_definition) {
            $lines[] = $index_definition;
        }

        if (empty($lines)) {
            return '';
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table_name} (\n" . implode(",\n", $lines) . "\n) {$charset};");

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        if ($exists !== $table_name) {
            $this->log('ERROR: failed to ensure Sports South catalog delta stage table.', [
                'delta_stage_table' => $table_name,
                'db_error' => (string) $wpdb->last_error,
            ]);
            return '';
        }

        return $table_name;
    }

    /**
     * @param string[] $columns
     * @return array<string,mixed>
     */
    private function apply_catalog_delta_from_stage_table(array $columns, string $stageTable): array
    {
        global $wpdb;

        $live_table = $this->table->get_live_table_name();
        $safe_columns = array_values(array_filter($columns, static fn($column): bool => is_string($column) && $column !== ''));
        $local_only_columns = $this->local_only_columns();
        $update_columns = array_values(array_filter($safe_columns, static fn($column): bool => !in_array($column, $local_only_columns, true)));
        $quantity_reconciliation_columns = array_values(array_intersect($update_columns, [
            'inventory_quantity',
            'allocation_status',
            'distributor_price',
            'catalog_price',
            'last_onhand_utc',
        ]));

        $stage_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$stageTable}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $blank_upc_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$stageTable} WHERE upc IS NULL OR upc = '' OR LOWER(upc) = 'null'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $duplicate_upc_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT upc FROM {$stageTable} WHERE upc IS NOT NULL AND upc <> '' GROUP BY upc HAVING COUNT(*) > 1) d"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $matched_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$stageTable} S INNER JOIN {$live_table} L ON L.upc = S.upc"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $missing_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$stageTable} S LEFT JOIN {$live_table} L ON L.upc = S.upc WHERE L.upc IS NULL"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $assignments = [];
        $comparisons = [];
        foreach ($update_columns as $column) {
            $quoted = $this->quote_identifier($column);
            $assignments[] = "L.{$quoted} = S.{$quoted}";
            $comparisons[] = "L.{$quoted} <=> S.{$quoted}";
        }

        $updated = 0;
        $update_ms = '0.00';
        if (!empty($assignments) && !empty($comparisons)) {
            $t_update = microtime(true);
            $update_sql = "
                UPDATE {$live_table} L
                INNER JOIN {$stageTable} S
                    ON L.upc = S.upc
                SET
                    " . implode(",\n                    ", $assignments) . "
                WHERE NOT (
                    " . implode("\n                    AND ", $comparisons) . "
                )
            ";
            $result = $wpdb->query($update_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $update_ms = $this->format_ms((microtime(true) - $t_update) * 1000.0);
            $updated = is_numeric($result) ? (int) $result : 0;
        }

        $quoted_columns = array_map([$this, 'quote_identifier'], $safe_columns);
        $column_sql = implode(', ', $quoted_columns);
        $select_sql = implode(', ', array_map(static fn(string $column): string => 'S.' . $column, $quoted_columns));

        $t_insert = microtime(true);
        $insert_sql = "
            INSERT INTO {$live_table} ({$column_sql})
            SELECT {$select_sql}
            FROM {$stageTable} S
            LEFT JOIN {$live_table} L
                ON L.upc = S.upc
            WHERE L.upc IS NULL
        ";
        $insert_result = $wpdb->query($insert_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $insert_ms = $this->format_ms((microtime(true) - $t_insert) * 1000.0);
        $inserted = is_numeric($insert_result) ? (int) $insert_result : 0;

        return [
            'live_table' => $live_table,
            'delta_stage_table' => $stageTable,
            'delta_stage_rows' => $stage_count,
            'blank_upc_count' => $blank_upc_count,
            'duplicate_upc_count' => $duplicate_upc_count,
            'matched_existing_rows' => $matched_count,
            'missing_live_rows' => $missing_count,
            'vendor_owned_columns_updated_count' => count($update_columns),
            'local_only_columns_preserved_count' => count($local_only_columns),
            'local_only_columns_preserved' => implode(',', $local_only_columns),
            'quantity_reconciliation_columns' => implode(',', $quantity_reconciliation_columns),
            'quantity_reconciliation_column_count' => count($quantity_reconciliation_columns),
            'daily_item_update_reconciles_quantity' => 1,
            'last_seen_utc_updates_on_overlap' => in_array('last_seen_utc', $update_columns, true) ? 1 : 0,
            'catalog_update_columns' => count($update_columns),
            'rows_updated' => $updated,
            'rows_inserted' => $inserted,
            'delta_update_ms' => $update_ms,
            'delta_insert_ms' => $insert_ms,
        ];
    }

    /**
     * @return string[]
     */
    private function local_only_columns(): array
    {
        // The current Sports South product table does not contain local admin
        // overrides, Woo binding IDs, or internal notes. Its columns are vendor
        // snapshot fields or importer-derived metadata, so the vendor delta row
        // is allowed to replace them. Keep this list explicit for future schema
        // additions that should not be overwritten by DailyItemUpdate.
        return [];
    }

    private function count_live_rows(): int
    {
        global $wpdb;

        $live_table = $this->table->get_live_table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$live_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function catalog_tsv_path(): string
    {
        $dir = $this->uploads_subdir();
        if ($dir === '') {
            return '';
        }

        return $dir . '/daily_item_update_catalog_' . gmdate('Ymd_His') . '.tsv';
    }

    private function uploads_subdir(): string
    {
        $uploads = wp_upload_dir();
        $base_dir = rtrim((string) ($uploads['basedir'] ?? ''), '/\\');
        if ($base_dir === '') {
            return '';
        }

        $dir = $base_dir . '/fflhub-sports-south';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            $this->log('Failed to create Sports South import directory.', [
                'dir' => $dir,
            ]);
            return '';
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            $this->log('Sports South import directory is not writable.', [
                'dir' => $dir,
            ]);
            return '';
        }

        return $dir;
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

        $this->log('Sports South LOAD DATA LOCAL INFILE capability check.', [
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

    private function deep_profile_enabled(): bool
    {
        return defined(self::DEEP_PROFILE_FLAG) && (bool) constant(self::DEEP_PROFILE_FLAG);
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
            DebugLogUtil::log_if(true, self::LOG_PREFIX, $message, self::DEBUG_FLAG);
            return;
        }

        DebugLogUtil::log_if_ctx(true, self::LOG_PREFIX, $message, $ctx, self::DEBUG_FLAG);
    }
}
