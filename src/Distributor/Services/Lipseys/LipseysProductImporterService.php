<?php

namespace FFLHub\Distributor\Services\Lipseys;

use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Util\DebugLogUtil;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Service for importing Lipsey's catalog items into the STAGING table
 * of a double-buffered fulfillment table.
 */
class LipseysProductImporterService
{
    private DoubleBufferedProductTable $table;

    public function __construct(DoubleBufferedProductTable $table)
    {
        $this->table = $table;
    }

    /**
     * Import an array of item arrays into the staging table.
     *
     * @param array<int,array<string,mixed>> $items
     * @return int Number of rows successfully inserted.
     */
    public function import_items_array(array $items): int
    {
        $t_start   = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $log_timing = function (string $label, float $t0): void {
            $elapsed_ms = (microtime(true) - $t0) * 1000;
            $this->log_debug(
                sprintf(
                    '[FFLHub][Lipseys Import] %s took %.2f ms',
                    $label,
                    $elapsed_ms
                )
            );
        };

        $this->log_debug('[FFLHub][Lipseys Import] ---- IMPORT START ----');
        if ($mem_start > 0) {
            $this->log_debug(
                sprintf(
                    '[FFLHub][Lipseys Import] PHP PID=%d, memory_start=%d KB',
                    function_exists('getmypid') ? getmypid() : 0,
                    (int) round($mem_start / 1024)
                )
            );
        }

        // Start with a clean staging table via the table helper.
        $t_trunc = microtime(true);
        try {
            $this->table->truncate_staging();
        } catch (\Throwable $e) {
            $this->log_debug('[FFLHub][Lipseys Import] ERROR: truncate_staging() threw: ' . $e->getMessage());
            $log_timing('truncate_staging (failed)', $t_trunc);
            $log_timing('Total import (truncate failed)', $t_start);
            $this->log_memory_summary($mem_start);
            $this->log_debug('[FFLHub][Lipseys Import] ---- IMPORT END (ERROR) ----');
            return 0;
        }
        $log_timing('truncate_staging', $t_trunc);

        $parser              = new LipseysProductParser();
        $batch_size          = 250;
        $batch_rows          = [];
        $total_import        = 0;
        $skipped_missing_upc = 0;
        $skipped_dupe_upc    = 0;
        $seen_upcs           = [];

        $batch_flushes       = 0;
        $batch_failures      = 0;

        $t_parse_total = 0.0;
        $t_insert_total = 0.0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $t_parse = microtime(true);

            $row = $parser->parse_item($item);

            $t_parse_total += (microtime(true) - $t_parse);

            if ($row === null) {
                continue;
            }

            $upc = isset($row['upc']) ? trim((string) $row['upc']) : '';
            if ($upc === '' || strcasecmp($upc, 'null') === 0) {
                $skipped_missing_upc++;
                continue;
            }

            // De-dupe by UPC (first wins).
            if (isset($seen_upcs[$upc])) {
                $skipped_dupe_upc++;
                continue;
            }
            $seen_upcs[$upc] = true;

            $batch_rows[] = $row;

            if (count($batch_rows) >= $batch_size) {
                $batch_flushes++;

                $t_ins = microtime(true);
                try {
                    $inserted = $this->table->insert_rows_into_staging($batch_rows);
                } catch (\Throwable $e) {
                    $batch_failures++;
                    $this->log_debug('[FFLHub][Lipseys Import] ERROR: insert_rows_into_staging() threw: ' . $e->getMessage());
                    $inserted = 0;
                }
                $t_insert_total += (microtime(true) - $t_ins);

                $total_import += (int) $inserted;
                $batch_rows = [];
            }
        }

        // Flush any remaining rows.
        if (! empty($batch_rows)) {
            $batch_flushes++;

            $t_ins = microtime(true);
            try {
                $inserted = $this->table->insert_rows_into_staging($batch_rows);
            } catch (\Throwable $e) {
                $batch_failures++;
                $this->log_debug('[FFLHub][Lipseys Import] ERROR: final insert_rows_into_staging() threw: ' . $e->getMessage());
                $inserted = 0;
            }
            $t_insert_total += (microtime(true) - $t_ins);

            $total_import += (int) $inserted;
        }

        if ($total_import > 0) {
            update_option('fflhub_lipseys_fulfillment_last_import', current_time('mysql'));
            update_option('fflhub_lipseys_fulfillment_last_import_count', (int) $total_import);
        }

        $this->log_debug(
            sprintf(
                '[FFLHub][Lipseys Import] import_items_array(): items_in=%d, rows_inserted=%d, skipped_missing_upc=%d, skipped_dupe_upc=%d, batch_flushes=%d, batch_failures=%d',
                count($items),
                (int) $total_import,
                (int) $skipped_missing_upc,
                (int) $skipped_dupe_upc,
                (int) $batch_flushes,
                (int) $batch_failures
            )
        );

        $this->log_debug(
            sprintf(
                '[FFLHub][Lipseys Import] Timing: parse_total=%.2f ms, insert_total=%.2f ms',
                $t_parse_total * 1000,
                $t_insert_total * 1000
            )
        );

        $log_timing('Total import', $t_start);
        $this->log_memory_summary($mem_start);
        $this->log_debug('[FFLHub][Lipseys Import] ---- IMPORT END (SUCCESS) ----');

        return (int) $total_import;
    }

    // ============================================================
    // NEW: TSV IMPORT PIPELINE (LOAD DATA LOCAL INFILE + FALLBACK)
    // ============================================================

    /**
     * Import TSV into staging (fast path: LOAD DATA LOCAL INFILE).
     * Falls back to PHP line-by-line batching using insert_rows_into_staging().
     *
     * @param string   $file_path Absolute path to TSV.
     * @param string[] $columns   Exact TSV column order (usually schema insert columns).
     * @return int Number of rows imported.
     */
    public function import_from_tsv_file(string $file_path, array $columns): int
    {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            $this->log_debug('[FFLHub][Lipseys Import] TSV missing/unreadable at ' . $file_path);
            return 0;
        }

        if (empty($columns)) {
            $this->log_debug('[FFLHub][Lipseys Import] TSV import: columns empty');
            return 0;
        }

        if ($this->can_use_load_data_local_infile()) {
            $rows = $this->import_tsv_via_load_data($file_path, $columns);
            if ($rows >= 0) {
                return $rows;
            }
            $this->log_debug('[FFLHub][Lipseys Import] LOAD DATA path failed, falling back to PHP importer.');
        }

        return $this->import_tsv_via_php($file_path, $columns);
    }

    private function can_use_load_data_local_infile(): bool
    {
        global $wpdb;

        $row      = $wpdb->get_row("SHOW VARIABLES LIKE 'local_infile'");
        $mysql_ok = $row && isset($row->Value) && in_array(strtolower((string) $row->Value), ['on', '1'], true);

        $ini_val = ini_get('mysqli.allow_local_infile');
        $php_ok  = in_array(strtolower((string) $ini_val), ['on', '1'], true);

        $result = ($mysql_ok && $php_ok);

        $this->log_debug(
            sprintf(
                '[FFLHub][Lipseys Import][DEBUG] LOAD DATA check: mysql_ok=%s, php_ok=%s, result=%s',
                $mysql_ok ? 'true' : 'false',
                $php_ok ? 'true' : 'false',
                $result ? 'true' : 'false'
            )
        );

        return $result;
    }

    /**
     * FAST PATH: LOAD DATA LOCAL INFILE for TSV.
     *
     * Returns:
     *  >=0 inserted rows
     *  -1  failure (caller should fall back)
     *
     * @param string   $file_path
     * @param string[] $columns
     */
    private function import_tsv_via_load_data(string $file_path, array $columns): int
    {
        global $wpdb;

        $t_start = microtime(true);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $table_name = $this->table->get_staging_table_name();

        // Backtick columns defensively.
        $col_list = implode(', ', array_map(static function (string $c): string {
            return '`' . str_replace('`', '``', $c) . '`';
        }, $columns));

        $sql = "
            LOAD DATA LOCAL INFILE %s
            INTO TABLE {$table_name}
            FIELDS TERMINATED BY '\\t'
            LINES TERMINATED BY '\\n'
            ({$col_list})
        ";

        try {
            // Always start clean staging (same as RSR).
            $this->table->truncate_staging();

            $prepared    = $wpdb->prepare($sql, $file_path);
            $t_sql_start = microtime(true);
            $result      = $wpdb->query($prepared);
            $t_sql_ms    = (microtime(true) - $t_sql_start) * 1000.0;

            if ($result === false) {
                $this->log_debug('[FFLHub][Lipseys Import][LOAD DATA] query failed: ' . $wpdb->last_error);
                return -1;
            }

            // Post-clean: remove rows with empty UPC.
            $wpdb->query("DELETE FROM {$table_name} WHERE upc IS NULL OR upc = ''"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        } catch (\Throwable $e) {
            $this->log_debug('[FFLHub][Lipseys Import][LOAD DATA] exception: ' . $e->getMessage());
            return -1;
        }

        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($rows > 0) {
            update_option('fflhub_lipseys_fulfillment_last_import', current_time('mysql'));
            update_option('fflhub_lipseys_fulfillment_last_import_count', (int) $rows);
        }

        $t_total_ms = (microtime(true) - $t_start) * 1000.0;

        $this->log_debug(
            sprintf(
                '[FFLHub][Lipseys Import] import_tsv_via_load_data(): total=%.2f ms, rows=%d',
                $t_total_ms,
                $rows
            )
        );

        return $rows;
    }

    /**
     * FALLBACK: PHP batching — reads TSV line-by-line and uses insert_rows_into_staging()
     * so you have one place that owns insert logic.
     *
     * IMPORTANT: TSV is assumed to have NO header row.
     *
     * @param string   $file_path
     * @param string[] $columns
     */
    private function import_tsv_via_php(string $file_path, array $columns): int
    {
        $t_start   = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $this->log_debug('[FFLHub][Lipseys Import][TSV PHP] fopen failed for ' . $file_path);
            return 0;
        }

        // Clean staging first (same as load-data path).
        try {
            $this->table->truncate_staging();
        } catch (\Throwable $e) {
            fclose($handle);
            $this->log_debug('[FFLHub][Lipseys Import][TSV PHP] truncate_staging threw: ' . $e->getMessage());
            return 0;
        }

        $batch_size          = 1000;
        $batch_rows          = [];
        $total_import        = 0;
        $skipped_missing_upc = 0;
        $line_number         = 0;

        $num_cols = count($columns);

        while (($line = fgets($handle)) !== false) {
            $line_number++;
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line);

            // Normalize number of fields.
            if (count($parts) < $num_cols) {
                $parts = array_pad($parts, $num_cols, '');
            } elseif (count($parts) > $num_cols) {
                $parts = array_slice($parts, 0, $num_cols);
            }

            $row = [];
            for ($i = 0; $i < $num_cols; $i++) {
                $row[$columns[$i]] = $parts[$i];
            }

            // Skip missing UPC.
            $upc = isset($row['upc']) ? trim((string) $row['upc']) : '';
            if ($upc === '' || strcasecmp($upc, 'null') === 0) {
                $skipped_missing_upc++;
                continue;
            }

            $batch_rows[] = $row;

            if (count($batch_rows) >= $batch_size) {
                try {
                    $inserted = (int) $this->table->insert_rows_into_staging($batch_rows);
                } catch (\Throwable $e) {
                    $this->log_debug('[FFLHub][Lipseys Import][TSV PHP] insert_rows_into_staging threw: ' . $e->getMessage());
                    $inserted = 0;
                }

                $total_import += $inserted;
                $batch_rows = [];
            }
        }

        fclose($handle);

        // Flush remainder.
        if (!empty($batch_rows)) {
            try {
                $inserted = (int) $this->table->insert_rows_into_staging($batch_rows);
            } catch (\Throwable $e) {
                $this->log_debug('[FFLHub][Lipseys Import][TSV PHP] final insert_rows_into_staging threw: ' . $e->getMessage());
                $inserted = 0;
            }

            $total_import += $inserted;
        }

        // Post-clean: remove rows with empty UPC (paranoia / consistency).
        global $wpdb;
        $table_name = $this->table->get_staging_table_name();
        $wpdb->query("DELETE FROM {$table_name} WHERE upc IS NULL OR upc = ''"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($total_import > 0) {
            update_option('fflhub_lipseys_fulfillment_last_import', current_time('mysql'));
            update_option('fflhub_lipseys_fulfillment_last_import_count', (int) $total_import);
        }

        $t_total_ms = (microtime(true) - $t_start) * 1000.0;

        $this->log_debug(
            sprintf(
                '[FFLHub][Lipseys Import][TSV PHP] imported_rows=%d, skipped_missing_upc=%d, total=%.2f ms, lines=%d',
                (int) $total_import,
                (int) $skipped_missing_upc,
                $t_total_ms,
                (int) $line_number
            )
        );

        if ($mem_start > 0) {
            $this->log_memory_summary((int) $mem_start);
        }

        return (int) $total_import;
    }

    // ------------------------------------------------------------

    private function log_debug(string $message): void
    {
        DebugLogUtil::log('FFLHUB_CRON_DEBUG', '[FFLHub][LipseysImporter]', $message);
    }

    private function log_memory_summary(int $mem_start): void
    {
        $mem_end = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
        if ($mem_start > 0 && $mem_end > 0) {
            $this->log_debug(
                sprintf(
                    '[FFLHub][Lipseys Import] Memory usage summary: start=%d KB, end=%d KB, delta=%+d KB',
                    (int) round($mem_start / 1024),
                    (int) round($mem_end / 1024),
                    (int) round(($mem_end - $mem_start) / 1024)
                )
            );
        }
    }
}
