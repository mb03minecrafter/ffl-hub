<?php

namespace FFLHub\Distributor\Services\RSR;

use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Distributor\Services\RSR\Tables\RSRProductTableSchema;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Coordinator for importing the RSR fulfillment catalog into the STAGING table.
 *
 * It:
 *  - reads a local file (semicolons),
 *  - truncates the staging table,
 *  - bulk-inserts rows.
 *
 * You can call RSRProductImporterService::import_from_downloaded_file()
 * (or legacy FFLHub_RSR_Fulfillment_Importer) after your FTP cron has fetched
 * the latest rsrinventory-new.txt.
 */
class RSRProductImporterService
{
    /**
     * Department numbers to exclude by default for accessory-only catalogs.
     *
     * Mapped from RSR department definitions:
     * 1=Handguns, 2=Used Handguns, 3=Used Long Guns, 5=Long Guns, 6=NFA Products.
     */
    private const DEFAULT_EXCLUDED_DEPARTMENT_NUMBERS = [1, 2, 3, 5, 6];

    /** @var DoubleBufferedProductTable */
    private $table;

    public function __construct(DoubleBufferedProductTable $table)
    {
        $this->table = $table;
    }

    /**
     * Import from standard downloaded file (uploads/fflhub-rsr/rsrinventory-new.txt).
     *
     * @return int Number of rows imported.
     */
    public function import_from_downloaded_file(): int
    {
        $uploads   = wp_upload_dir();
        $base_dir  = trailingslashit($uploads['basedir']) . 'fflhub-rsr';
        $file_path = trailingslashit($base_dir) . 'rsrinventory-new.txt';

        if (! file_exists($file_path) || ! is_readable($file_path)) {
            $this->log_debug('[FFLHub][RSR Import] File missing or unreadable at ' . $file_path);
            return 0;
        }

        return $this->import_fulfillment_file($file_path);
    }

    /**
     * Attempts to import a file into the staging table.
     * Uses LOAD DATA LOCAL INFILE if available, falls back to PHP batch insert.
     *
     * @param string $file_path
     * @return int Number of rows imported.
     */
    public function import_fulfillment_file(string $file_path): int
    {
        if ($this->can_use_load_data_local_infile()) {
            $rows = $this->import_fulfillment_file_via_load_data($file_path);
            if ($rows >= 0) {
                return $rows;
            }
            $this->log_debug('[FFLHub][RSR Import] LOAD DATA path failed, falling back to PHP importer.');
        }

        return $this->import_fulfillment_file_via_php($file_path);
    }

    /**
     * Determines if LOAD DATA LOCAL INFILE can be used.
     */
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
                '[FFLHub][RSR Import][DEBUG] LOAD DATA check: mysql_ok=%s, php_ok=%s, result=%s',
                $mysql_ok ? 'true' : 'false',
                $php_ok ? 'true' : 'false',
                $result ? 'true' : 'false'
            )
        );

        return $result;
    }

    /**
     * FAST PATH: Import via MySQL's LOAD DATA LOCAL INFILE directly into the staging table.
     *
     * Returns:
     *  - >= 0 : number of rows inserted
     *  - -1   : failure (caller should fall back to PHP importer)
     *
     * @param string $file_path
     * @return int
     */
    private function import_fulfillment_file_via_load_data(string $file_path): int
    {
        global $wpdb;

        $t_start = microtime(true);
        $excluded_dept_numbers = $this->get_excluded_department_numbers();
        $deleted_missing_upc = 0;
        $deleted_excluded_dept = 0;
        $rows_after_load = 0;
        $sig_approved_forced = 0;

        if (! file_exists($file_path) || ! is_readable($file_path)) {
            $this->log_debug('[FFLHub][RSR Import][LOAD DATA] file missing or not readable at ' . $file_path);
            return -1;
        }

        $table_name = $this->table->get_staging_table_name();

        // Allow long-running import if needed.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        // Detect if first line is a header (same logic as parser).
        $ignore_lines = 0;
        $fh           = fopen(($file_path), 'r');
        if ($fh) {
            $first_line = fgets($fh);
            fclose($fh);

            if ($first_line !== false) {
                $first_line = trim($first_line);
                if ($first_line !== '') {
                    $cols      = explode(';', $first_line);
                    $first_col = isset($cols[0]) ? trim($cols[0]) : '';
                    if (
                        stripos($first_col, 'RSR Stock') === 0 ||
                        stripos($first_col, 'RSR#') === 0
                    ) {
                        $ignore_lines = 1;
                    }
                }
            }
        }

        /**
         * Map each semicolon-separated column into @c0..@c75, then SET real columns.
         */
        $sql = "
            LOAD DATA LOCAL INFILE %s
            INTO TABLE {$table_name}
            FIELDS TERMINATED BY ';'
            LINES TERMINATED BY '\\n'
            IGNORE {$ignore_lines} LINES
            (
                @c0,  @c1,  @c2,  @c3,  @c4,  @c5,  @c6,  @c7,  @c8,  @c9,
                @c10, @c11, @c12, @c13, @c14,
                @c15, @c16, @c17, @c18, @c19, @c20, @c21, @c22, @c23, @c24,
                @c25, @c26, @c27, @c28, @c29, @c30, @c31, @c32, @c33, @c34,
                @c35, @c36, @c37, @c38, @c39, @c40, @c41, @c42, @c43, @c44,
                @c45, @c46, @c47, @c48, @c49, @c50, @c51, @c52, @c53, @c54,
                @c55, @c56, @c57, @c58, @c59, @c60, @c61, @c62, @c63, @c64,
                @c65,
                @c66, @c67, @c68,
                @c69, @c70, @c71, @c72, @c73, @c74, @c75
            )
            SET
                upc                          = TRIM(TRIM(BOTH '\\r' FROM @c1)),
                rsr_stock_number             = TRIM(TRIM(BOTH '\\r' FROM @c0)),
                product_description          = TRIM(TRIM(BOTH '\\r' FROM @c2)),
                dept_number                  = TRIM(TRIM(BOTH '\\r' FROM @c3)),
                manufacturer_id              = TRIM(TRIM(BOTH '\\r' FROM @c4)),
                retail_msrp                  = TRIM(TRIM(BOTH '\\r' FROM @c5)),
                distributor_price            = TRIM(TRIM(BOTH '\\r' FROM @c6)),
                shipping_cost                = '10',
                shipping_weight              = TRIM(TRIM(BOTH '\\r' FROM @c7)),
                sot_required                 = CASE
                                                  WHEN CAST(TRIM(TRIM(BOTH '\\r' FROM @c3)) AS UNSIGNED) = 6 THEN '1'
                                                  ELSE '0'
                                               END,
                inventory_quantity           = TRIM(TRIM(BOTH '\\r' FROM @c8)),
                model                        = TRIM(TRIM(BOTH '\\r' FROM @c9)),
                manufacturer                 = TRIM(TRIM(BOTH '\\r' FROM @c10)),
                manufacturer_part_number     = TRIM(TRIM(BOTH '\\r' FROM @c11)),
                allocation_status            = TRIM(TRIM(BOTH '\\r' FROM @c12)),
                expanded_product_description = TRIM(TRIM(BOTH '\\r' FROM @c13)),
                image_name                   = TRIM(TRIM(BOTH '\\r' FROM @c14)),

                ship_ak  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c15))) = 'Y' THEN '1' ELSE '0' END,
                ship_al  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c16))) = 'Y' THEN '1' ELSE '0' END,
                ship_ar  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c17))) = 'Y' THEN '1' ELSE '0' END,
                ship_az  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c18))) = 'Y' THEN '1' ELSE '0' END,
                ship_ca  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c19))) = 'Y' THEN '1' ELSE '0' END,
                ship_co  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c20))) = 'Y' THEN '1' ELSE '0' END,
                ship_ct  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c21))) = 'Y' THEN '1' ELSE '0' END,
                ship_dc  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c22))) = 'Y' THEN '1' ELSE '0' END,
                ship_de  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c23))) = 'Y' THEN '1' ELSE '0' END,
                ship_fl  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c24))) = 'Y' THEN '1' ELSE '0' END,
                ship_ga  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c25))) = 'Y' THEN '1' ELSE '0' END,
                ship_hi  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c26))) = 'Y' THEN '1' ELSE '0' END,
                ship_ia  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c27))) = 'Y' THEN '1' ELSE '0' END,
                ship_id  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c28))) = 'Y' THEN '1' ELSE '0' END,
                ship_il  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c29))) = 'Y' THEN '1' ELSE '0' END,
                ship_in  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c30))) = 'Y' THEN '1' ELSE '0' END,
                ship_ks  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c31))) = 'Y' THEN '1' ELSE '0' END,
                ship_ky  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c32))) = 'Y' THEN '1' ELSE '0' END,
                ship_la  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c33))) = 'Y' THEN '1' ELSE '0' END,
                ship_ma  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c34))) = 'Y' THEN '1' ELSE '0' END,
                ship_md  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c35))) = 'Y' THEN '1' ELSE '0' END,
                ship_me  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c36))) = 'Y' THEN '1' ELSE '0' END,
                ship_mi  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c37))) = 'Y' THEN '1' ELSE '0' END,
                ship_mn  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c38))) = 'Y' THEN '1' ELSE '0' END,
                ship_mo  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c39))) = 'Y' THEN '1' ELSE '0' END,
                ship_ms  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c40))) = 'Y' THEN '1' ELSE '0' END,
                ship_mt  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c41))) = 'Y' THEN '1' ELSE '0' END,
                ship_nc  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c42))) = 'Y' THEN '1' ELSE '0' END,
                ship_nd  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c43))) = 'Y' THEN '1' ELSE '0' END,
                ship_ne  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c44))) = 'Y' THEN '1' ELSE '0' END,
                ship_nh  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c45))) = 'Y' THEN '1' ELSE '0' END,
                ship_nj  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c46))) = 'Y' THEN '1' ELSE '0' END,
                ship_nm  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c47))) = 'Y' THEN '1' ELSE '0' END,
                ship_nv  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c48))) = 'Y' THEN '1' ELSE '0' END,
                ship_ny  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c49))) = 'Y' THEN '1' ELSE '0' END,
                ship_oh  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c50))) = 'Y' THEN '1' ELSE '0' END,
                ship_ok  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c51))) = 'Y' THEN '1' ELSE '0' END,
                ship_or  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c52))) = 'Y' THEN '1' ELSE '0' END,
                ship_ph  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c53))) = 'Y' THEN '1' ELSE '0' END,
                ship_ri  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c54))) = 'Y' THEN '1' ELSE '0' END,
                ship_sc  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c55))) = 'Y' THEN '1' ELSE '0' END,
                ship_sd  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c56))) = 'Y' THEN '1' ELSE '0' END,
                ship_tn  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c57))) = 'Y' THEN '1' ELSE '0' END,
                ship_tx  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c58))) = 'Y' THEN '1' ELSE '0' END,
                ship_ut  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c59))) = 'Y' THEN '1' ELSE '0' END,
                ship_va  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c60))) = 'Y' THEN '1' ELSE '0' END,
                ship_vt  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c61))) = 'Y' THEN '1' ELSE '0' END,
                ship_wa  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c62))) = 'Y' THEN '1' ELSE '0' END,
                ship_wi  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c63))) = 'Y' THEN '1' ELSE '0' END,
                ship_wv  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c64))) = 'Y' THEN '1' ELSE '0' END,
                ship_wy  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c65))) = 'Y' THEN '1' ELSE '0' END,

                ground_shipments_only = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c66))) = 'Y' THEN '1' ELSE '0' END,
                adult_sig_required    = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c67))) = 'Y' THEN '1' ELSE '0' END,
                dropship_enabled      = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c68))) = 'Y' THEN '0' ELSE '1' END,
                dropship_block_reason = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c68))) = 'Y' THEN 'blocked_from_dropship' ELSE NULL END,

                date_entered          = TRIM(TRIM(BOTH '\\r' FROM @c69)),
                retail_map            = TRIM(TRIM(BOTH '\\r' FROM @c70)),
                image_disclaimer      = TRIM(TRIM(BOTH '\\r' FROM @c71)),
                shipping_length_in    = TRIM(TRIM(BOTH '\\r' FROM @c72)),
                shipping_width_in     = TRIM(TRIM(BOTH '\\r' FROM @c73)),
                shipping_height_in    = TRIM(TRIM(BOTH '\\r' FROM @c74)),
                reserved_future       = TRIM(TRIM(BOTH '\\r' FROM @c75))
        ";

        $t_truncate_ms = 0.0;
        $t_sql_ms = 0.0;
        $t_delete_upc_ms = 0.0;
        $t_delete_dept_ms = 0.0;
        $t_sig_approval_ms = 0.0;
        $t_count_ms = 0.0;
        $t_update_options_ms = 0.0;

        try {
            // Clean staging table first.
            $t_truncate_start = microtime(true);
            $wpdb->query("TRUNCATE TABLE {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $t_truncate_ms = (microtime(true) - $t_truncate_start) * 1000.0;

            $prepared    = $wpdb->prepare($sql, $file_path);
            $t_sql_start = microtime(true);
            $result      = $wpdb->query($prepared);
            $t_sql_ms    = (microtime(true) - $t_sql_start) * 1000.0;

            if ($result === false) {
                $this->log_debug(
                    '[FFLHub][RSR Import][LOAD DATA] query failed: ' . $wpdb->last_error
                );
                return -1;
            }
            $rows_after_load = is_numeric($result) ? (int) $result : 0;

            // Post-clean: remove rows with empty UPC.
            $t_delete_upc_start = microtime(true);
            $deleted_missing_upc = (int) $wpdb->query("DELETE FROM {$table_name} WHERE upc IS NULL OR upc = ''"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $t_delete_upc_ms = (microtime(true) - $t_delete_upc_start) * 1000.0;

            // Department-level filter for accessory-only RSR accounts.
            if (!empty($excluded_dept_numbers)) {
                $placeholders = implode(', ', array_fill(0, count($excluded_dept_numbers), '%d'));
                $delete_by_dept_sql = "
                    DELETE FROM {$table_name}
                    WHERE dept_number IS NOT NULL
                      AND TRIM(dept_number) <> ''
                      AND CAST(TRIM(dept_number) AS UNSIGNED) IN ({$placeholders})
                ";
                $prepared_delete_by_dept = $wpdb->prepare($delete_by_dept_sql, $excluded_dept_numbers);
                $t_delete_dept_start = microtime(true);
                $delete_result = $wpdb->query($prepared_delete_by_dept);
                $t_delete_dept_ms = (microtime(true) - $t_delete_dept_start) * 1000.0;

                if ($delete_result === false) {
                    $this->log_debug('[FFLHub][RSR Import][LOAD DATA] department filter delete failed: ' . $wpdb->last_error);
                } else {
                    $deleted_excluded_dept = (int) $delete_result;
                }
            }

            $t_sig_approval_start = microtime(true);
            $sig_approved_forced = SigDropshipApproval::apply_to_table('rsr', $table_name);
            $t_sig_approval_ms = (microtime(true) - $t_sig_approval_start) * 1000.0;
            if ($sig_approved_forced > 0) {
                $this->log_debug(
                    sprintf('[FFLHub][RSR Import][LOAD DATA] sig_approved_forced=%d', $sig_approved_forced)
                );
            }
        } catch (\Throwable $e) {
            $this->log_debug('[FFLHub][RSR Import][LOAD DATA] exception: ' . $e->getMessage());
            return -1;
        }

        // Count rows actually loaded.
        $t_count_start = microtime(true);
        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $t_count_ms = (microtime(true) - $t_count_start) * 1000.0;

        if ($rows > 0) {
            $t_update_options_start = microtime(true);
            update_option('fflhub_rsr_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_rsr_fulfillment_last_import_count', $rows, false);
            $t_update_options_ms = (microtime(true) - $t_update_options_start) * 1000.0;
        }

        $t_total_ms = (microtime(true) - $t_start) * 1000.0;

        DebugLogUtil::log_ctx('FFLHUB_CRON_DEBUG', '[FFLHub][RSRImporter]', 'PROFILE: import_fulfillment_file_via_load_data steps', [
            'truncate_staging_ms' => number_format($t_truncate_ms, 2, '.', ''),
            'load_data_sql_ms' => number_format($t_sql_ms, 2, '.', ''),
            'delete_blank_upc_ms' => number_format($t_delete_upc_ms, 2, '.', ''),
            'delete_excluded_departments_ms' => number_format($t_delete_dept_ms, 2, '.', ''),
            'sig_approval_ms' => number_format($t_sig_approval_ms, 2, '.', ''),
            'count_staging_rows_ms' => number_format($t_count_ms, 2, '.', ''),
            'update_options_ms' => number_format($t_update_options_ms, 2, '.', ''),
            'total_import_ms' => number_format($t_total_ms, 2, '.', ''),
            'rows_after_load' => $rows_after_load,
            'blank_upc_deleted' => $deleted_missing_upc,
            'excluded_department_deleted' => $deleted_excluded_dept,
            'excluded_departments' => empty($excluded_dept_numbers) ? [] : array_values($excluded_dept_numbers),
            'sig_approvals_forced' => $sig_approved_forced,
            'ignore_lines' => $ignore_lines,
            'final_staging_rows' => $rows,
        ]);

        return $rows;
    }

    /**
     * ORIGINAL PHP BATCH IMPORTER (fallback if LOAD DATA is unavailable or fails).
     *
     * @param string $file_path
     * @return int
     */
    private function import_fulfillment_file_via_php(string $file_path): int
    {
        global $wpdb;

        $t_import_start = microtime(true);
        $t_parse_total  = 0.0;
        $t_flush_total  = 0.0;

        if (! file_exists($file_path) || ! is_readable($file_path)) {
            return 0;
        }

        $table_name = $this->table->get_staging_table_name();

        // Allow long-running import if needed.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $handle = fopen($file_path, 'r');
        if (! $handle) {
            $this->log_debug('[FFLHub] RSR fulfillment import: could not fopen ' . $file_path);
            return 0;
        }

        // Start with a clean staging table.
        $wpdb->query("TRUNCATE TABLE {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $parser = new RSRProductParser();

        // Bigger batch = fewer INSERT statements.
        $batch_size          = 1000;
        $batch_rows          = array();
        $total_import        = 0;
        $line_number         = 0;
        $skipped_missing_upc = 0;
        $skipped_excluded_dept = 0;
        $excluded_dept_numbers = $this->get_excluded_department_numbers();
        $excluded_dept_lookup  = array_fill_keys(array_map('strval', $excluded_dept_numbers), true);

        $batch_flushes  = 0;
        $batch_failures = 0;

        // Single source of truth for column order from the schema helper.
        $columns     = $this->table->get_schema()->get_insert_columns();
        $num_cols    = count($columns);
        $column_list = implode(', ', $columns);

        // Precompute placeholders and insert prefix.
        $row_placeholder = '(' . implode(', ', array_fill(0, $num_cols, '%s')) . ')';
        $insert_prefix   = 'INSERT INTO ' . $table_name . ' (' . $column_list . ') VALUES ';

        // Wrap all inserts in a single transaction to avoid per-batch commit overhead.
        $wpdb->query('START TRANSACTION');

        $flush_batch = function () use (
            &$batch_rows,
            &$total_import,
            $wpdb,
            $columns,
            $num_cols,
            &$t_flush_total,
            $row_placeholder,
            $insert_prefix,
            &$batch_flushes,
            &$batch_failures
        ) {
            if (empty($batch_rows)) {
                return;
            }

            $batch_flushes++;
            $t0 = microtime(true);

            $placeholders = array();
            $values       = array();

            foreach ($batch_rows as $row) {
                // Reuse the precomputed row placeholder.
                $placeholders[] = $row_placeholder;

                foreach ($columns as $col) {
                    $values[] = isset($row[$col]) ? $row[$col] : '';
                }
            }

            $sql = $insert_prefix . implode(', ', $placeholders);

            // One big prepared statement per batch.
            $prepared = $wpdb->prepare($sql, $values);
            $result   = $wpdb->query($prepared);

            if ($result !== false) {
                // IMPORTANT: $result is affected rows (actual inserted rows).
                $total_import += (int) $result;
            } else {
                $batch_failures++;
                $this->log_debug('[FFLHub][RSR Import] Batch INSERT failed: ' . $wpdb->last_error);
            }

            $batch_rows = array();
            $t_flush_total += (microtime(true) - $t0);
        };

        // Read + parse loop.
        while (($line = fgets($handle)) !== false) {
            $line_number++;

            $t0  = microtime(true);
            $row = $parser->parse_line($line, $line_number);
            $t1  = microtime(true);

            // Accumulate parsing (includes fgets + parse_line).
            $t_parse_total += ($t1 - $t0);

            if ($row === null) {
                continue;
            }

            // Skip rows where UPC is missing/empty/'null'.
            $upc = isset($row['upc']) ? trim((string) $row['upc']) : '';
            if ($upc === '' || strcasecmp($upc, 'null') === 0) {
                $skipped_missing_upc++;
                continue;
            }

            $dept_num = $this->normalize_department_number($row['dept_number'] ?? null);
            if ($dept_num !== null && isset($excluded_dept_lookup[(string) $dept_num])) {
                $skipped_excluded_dept++;
                continue;
            }

            $batch_rows[] = $row;

            if (count($batch_rows) >= $batch_size) {
                $flush_batch();
            }
        }

        fclose($handle);

        // Flush any remaining rows.
        $flush_batch();

        // Commit all inserts as a single transaction.
        $wpdb->query('COMMIT');

        // Optional: store last-import info.
        if ($total_import > 0) {
            update_option('fflhub_rsr_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_rsr_fulfillment_last_import_count', $total_import, false);
        }

        // Timing logs for deeper insight.
        $t_import_total_ms = (microtime(true) - $t_import_start) * 1000;
        $t_parse_ms        = $t_parse_total * 1000;
        $t_flush_ms        = $t_flush_total * 1000;

        $this->log_debug(
            sprintf(
                '[FFLHub][RSR Import] import_fulfillment_file_via_php(): total=%.2f ms, parse+loop=%.2f ms, db_flush=%.2f ms, inserted_rows=%d, skipped_missing_upc=%d, skipped_excluded_dept=%d, excluded_depts=%s, batch_flushes=%d, batch_failures=%d',
                $t_import_total_ms,
                $t_parse_ms,
                $t_flush_ms,
                $total_import,
                $skipped_missing_upc,
                $skipped_excluded_dept,
                empty($excluded_dept_numbers) ? '[]' : implode(',', $excluded_dept_numbers),
                $batch_flushes,
                $batch_failures
            )
        );

        return $total_import;
    }

    private function log_debug(string $message): void
    {
        DebugLogUtil::log('FFLHUB_CRON_DEBUG', '[FFLHub][RSRImporter]', $message);
    }

    /**
     * @return array<int,int>
     */
    private function get_excluded_department_numbers(): array
    {
        if (! $this->is_accessories_only_enabled()) {
            return [];
        }

        $raw = apply_filters('fflhub_rsr_import_excluded_dept_numbers', self::DEFAULT_EXCLUDED_DEPARTMENT_NUMBERS);
        if (!is_array($raw)) {
            $raw = self::DEFAULT_EXCLUDED_DEPARTMENT_NUMBERS;
        }

        $normalized = [];
        foreach ($raw as $v) {
            $dept = $this->normalize_department_number($v);
            if ($dept === null) {
                continue;
            }
            $normalized[$dept] = $dept;
        }

        ksort($normalized, SORT_NUMERIC);
        return array_values($normalized);
    }

    private function is_accessories_only_enabled(): bool
    {
        $raw = trim((string) Options::get_distributor_option('rsr', 'accessories_only', '1'));
        if ($raw === '') {
            return true;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'y', 'on'], true);
    }

    /**
     * Normalize department number values like "01" => 1.
     */
    private function normalize_department_number($value): ?int
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        $digits = is_string($digits) ? $digits : '';
        if ($digits === '') {
            return null;
        }

        return (int) $digits;
    }
}
