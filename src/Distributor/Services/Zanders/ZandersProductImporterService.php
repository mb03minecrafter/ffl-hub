<?php

namespace FFLHub\Distributor\Services\Zanders;

use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinator for importing the Zanders inventory CSV into the STAGING table.
 *
 * It:
 *  - reads a local CSV (commas, quoted fields),
 *  - truncates the staging table,
 *  - bulk loads via LOAD DATA LOCAL INFILE when available,
 *  - falls back to PHP batch insert when not.
 *
 * Expected file (downloaded by cron):
 *   uploads/fflhub-zanders/zandersinv.csv
 *
 * Source columns (CSV):
 * available,category,desc1,desc2,itemnumber,manufacturer,mfgpnumber,msrp,
 * price1,price2,price3,qty1,qty2,qty3,upc,weight,serialized,mapprice
 *
 * Normalized internal columns:
 * upc
 * zanders_item_number
 * inventory_quantity
 * allocation_status
 * distributor_price
 * retail_map
 * retail_msrp
 * product_description
 * item_type
 * manufacturer
 * mfg_model_number
 * shipping_weight (stored in ounces; source `weight` is pounds)
 * price_2
 * price_3
 * bulk_qty_1
 * bulk_qty_2
 * bulk_qty_3
 * ffl_required (derived)
 * sot_required (derived)
 * serialized
 *
 * IMPORTANT:
 * - Restricted drop-ship manufacturers are retained but marked as non-dropship.
 * - We MUST keep CSV parsing settings exact (quoted CSV, CRLF, escaped backslashes).
 */
class ZandersProductImporterService
{
    /** @var DoubleBufferedProductTable */
    private $table;

    private const RESTRICTED_ALIAS_TABLE = 'fflhub_restricted_manufacturer_aliases';

    private static bool $restrictedAliasTableReady = false;

    /**
     * Zanders "restricted drop ship" manufacturers / brands (as provided).
     *
     * We normalize comparisons (uppercase + remove spaces/punct) and check:
     *  - exact normalized match
     *  - token-based contains match for entries like "COLT/CZ", "S&W FIREARMS", etc.
     */
    private const RESTRICTED_DROP_SHIP_MANUFACTURERS = [
        'ATN',
        'AOB CUTLERY',
        'ARSENAL',
        'BARRETT',
        'BERETTA FIREARMS',
        'BUBBA BLADE',
        'COBRA ARCHERY',
        'COLT/CZ',
        'COLUMBIA RIVER KNIFE & TOOL/CRKT',
        'CRIMSON TRACE',
        'FN',
        'FROGG TOGGS',
        'FSDC',
        'GLOCK',
        'GSS',
        'HAVALON',
        'HK/HECKLER & KOCH FIREARMS',
        'HOLOSUN',
        'INSIGHTS HUNTING',
        'IWI',
        'KYNSHOT',
        'LEUPOLD',
        'LONGSHOT',
        'MAG STORAGE SOLUTIONS',
        'MANTIS',
        'RUGER FIREARMS',
        'SIG SAUER',
        'SLOGAN',
        'SOG',
        'SPORTDOG',
        'SPRINGFIELD FIREARMS',
        'S&W FIREARMS',
        'TACTACAM',
        'TIKKA',
        'TIMNEY TRIGGERS',
        'THOMPSON CENTER',
        'UMAREX (RWS, AXEON)',
        'WALTHER',
        '1791 GUN LEATHER',
        '2 SISTERS MAGNETIC GUN REST',
    ];

    /**
     * Some Zanders brand restrictions apply to firearms, not accessories.
     *
     * These manufacturers stay on the restricted list, but non-FFL rows under
     * these brands can still dropship. The restriction pass uses this list to
     * block their firearms while preserving accessory fulfillment.
     */
    private const FFL_ONLY_RESTRICTED_DROP_SHIP_MANUFACTURERS = [
        'BERETTA FIREARMS',
        'HK/HECKLER & KOCH FIREARMS',
        'RUGER FIREARMS',
        'S&W FIREARMS',
        'SPRINGFIELD FIREARMS',
    ];

    public function __construct(DoubleBufferedProductTable $table)
    {
        $this->table = $table;
    }

    /**
     * Import from standard downloaded file (uploads/fflhub-zanders/zandersinv.csv).
     *
     * @return int Number of rows imported.
     */
    public function import_from_downloaded_file(): int
    {
        $uploads   = wp_upload_dir();
        $base_dir  = trailingslashit($uploads['basedir']) . 'fflhub-zanders';
        $file_path = trailingslashit($base_dir) . 'zandersinv.csv';

        if (!file_exists($file_path) || !is_readable($file_path)) {
            $this->log_debug('[FFLHub][Zanders Import] File missing or unreadable at ' . $file_path);
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
            $this->log_debug('[FFLHub][Zanders Import] LOAD DATA path failed, falling back to PHP importer.');
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

        $mysqli = ini_get('mysqli.allow_local_infile');
        $pdo    = ini_get('pdo_mysql.allow_local_infile');

        $php_ok = false;
        if ($mysqli !== false && in_array(strtolower((string) $mysqli), ['on', '1'], true)) {
            $php_ok = true;
        }
        if ($pdo !== false && in_array(strtolower((string) $pdo), ['on', '1'], true)) {
            $php_ok = true;
        }

        $result = ($mysql_ok && $php_ok);

        $this->log_debug(
            sprintf(
                '[FFLHub][Zanders Import][DEBUG] LOAD DATA check: mysql_ok=%s, php_ok=%s, result=%s',
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
     */
    private function import_fulfillment_file_via_load_data(string $file_path): int
    {
        global $wpdb;

        $t_start = microtime(true);

        if (!file_exists($file_path) || !is_readable($file_path)) {
            $this->log_debug('[FFLHub][Zanders Import][LOAD DATA] file missing or not readable at ' . $file_path);
            return -1;
        }

        $table_name = $this->table->get_staging_table_name();

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        // Zanders file has a known header row.
        $ignore_lines = 1;

        /**
         * Source order (CSV):
         * 0  available
         * 1  category
         * 2  desc1
         * 3  desc2
         * 4  itemnumber
         * 5  manufacturer
         * 6  mfgpnumber
         * 7  msrp
         * 8  price1
         * 9  price2
         * 10 price3
         * 11 qty1
         * 12 qty2
         * 13 qty3
         * 14 upc
         * 15 weight
         * 16 serialized
         * 17 mapprice
         *
         * IMPORTANT CSV NOTES (to avoid prior parsing issues):
         * - Keep ENCLOSED BY '"' because Zanders uses quoted fields
         * - Use ESCAPED BY '\\' to match backslash escapes
         * - Use LINES TERMINATED BY '\r\n' (Windows CRLF) — we also TRIM '\r'
         *
         * RESTRICTED DROP SHIP:
         * - We keep all rows and mark restricted manufacturers as non-dropship
         *   immediately after LOAD DATA completes.
         * - Strategy:
         *    1) LOAD DATA into staging normally
         *    2) UPDATE restricted manufacturers with dropship_enabled=0 and reason
         *
         * This keeps a complete product catalog while preserving fulfillment constraints.
         */
        $manufacturer_expr = ZandersManufacturerNormalizer::canonical_display_sql_expression('@c5');
        $manufacturer_norm_expr = ZandersManufacturerNormalizer::canonical_norm_sql_expression('@c5');
        $initial_block_reason_expr = $this->load_data_initial_dropship_block_reason_sql('@c1', '@c5');

        $sql = "
            LOAD DATA LOCAL INFILE %s
            INTO TABLE {$table_name}
            CHARACTER SET utf8mb4
            FIELDS
                TERMINATED BY ','
                ENCLOSED BY '\"'
                ESCAPED BY '\\\\'
            LINES TERMINATED BY '\\r\\n'
            IGNORE {$ignore_lines} LINES
            (
                @c0,  @c1,  @c2,  @c3,  @c4,  @c5,  @c6,  @c7,  @c8,
                @c9,  @c10, @c11, @c12, @c13, @c14, @c15, @c16, @c17
            )
            SET
                inventory_quantity  = CASE
                                        WHEN TRIM(BOTH '\\r' FROM @c0) IN ('', '\"\"') THEN '0'
                                        ELSE TRIM(BOTH '\\r' FROM @c0)
                                      END,

                item_type           = TRIM(BOTH '\\r' FROM @c1),

                product_description = TRIM(
                                        BOTH ' ' FROM
                                        CONCAT(
                                            NULLIF(TRIM(BOTH '\\r' FROM @c2), ''),
                                            CASE
                                                WHEN NULLIF(TRIM(BOTH '\\r' FROM @c2), '') IS NOT NULL
                                                     AND NULLIF(TRIM(BOTH '\\r' FROM @c3), '') IS NOT NULL
                                                THEN ' '
                                                ELSE ''
                                            END,
                                            NULLIF(TRIM(BOTH '\\r' FROM @c3), '')
                                        )
                                      ),

                zanders_item_number  = TRIM(BOTH '\\r' FROM @c4),
                manufacturer         = {$manufacturer_expr},
                manufacturer_norm    = {$manufacturer_norm_expr},
                mfg_model_number     = TRIM(BOTH '\\r' FROM @c6),

                retail_msrp          = TRIM(BOTH '\\r' FROM @c7),

                distributor_price    = TRIM(BOTH '\\r' FROM @c8),
                shipping_cost        = CASE
                                        WHEN TRIM(BOTH '\\r' FROM @c8) IN ('', '\"\"') THEN '15'
                                        WHEN CAST(TRIM(BOTH '\\r' FROM @c8) AS DECIMAL(10,4)) >= 500 THEN '0'
                                        ELSE '15'
                                      END,
                price_2              = TRIM(BOTH '\\r' FROM @c9),
                price_3              = TRIM(BOTH '\\r' FROM @c10),

                bulk_qty_1           = TRIM(BOTH '\\r' FROM @c11),
                bulk_qty_2           = TRIM(BOTH '\\r' FROM @c12),
                bulk_qty_3           = TRIM(BOTH '\\r' FROM @c13),

                upc                  = TRIM(BOTH '\\r' FROM @c14),

                shipping_weight      = CASE
                                        WHEN TRIM(BOTH '\\r' FROM @c15) IN ('', '\"\"') THEN NULL
                                        ELSE ROUND(CAST(TRIM(BOTH '\\r' FROM @c15) AS DECIMAL(10,4)) * 16, 2)
                                      END,

                serialized           = CASE
                                        WHEN UPPER(TRIM(BOTH '\\r' FROM @c16)) IN ('YES','Y','1','TRUE','T')
                                        THEN '1' ELSE '0'
                                      END,

                retail_map           = CASE
                                        WHEN TRIM(BOTH '\\r' FROM @c17) IN ('', '\"\"') THEN '0'
                                        ELSE TRIM(BOTH '\\r' FROM @c17)
                                      END,

                ffl_required         = CASE
                                        WHEN UPPER(TRIM(BOTH '\\r' FROM @c1)) IN (
                                            'PISTOL',
                                            'REVOLVER',
                                            'RIFLE',
                                            'SHOTGUN',
                                            'OTHER FIREARMS',
                                            'RECEIVER',
                                            'PISTOL FRAMES',
                                            'DS SUPPRESSORS'
                                        )
                                        THEN '1' ELSE '0'
                                      END,

                sot_required         = CASE
                                        WHEN UPPER(TRIM(BOTH '\\r' FROM @c1)) IN ('DS SUPPRESSORS')
                                        THEN '1' ELSE '0'
                                      END,

                dropship_block_reason = {$initial_block_reason_expr}
        ";

        $t_truncate_ms        = 0.0;
        $t_sql_ms             = 0.0;
        $t_delete_upc_ms      = 0.0;
        $t_restricted_ms      = 0.0;
        $t_count_ms           = 0.0;
        $t_update_options_ms  = 0.0;
        $deleted_blank_upcs   = 0;
        $marked_restricted    = 0;

        try {
            $t_truncate_start = microtime(true);
            $wpdb->query("TRUNCATE TABLE {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $t_truncate_ms = (microtime(true) - $t_truncate_start) * 1000.0;

            $prepared    = $wpdb->prepare($sql, $file_path);
            $t_sql_start = microtime(true);
            $result      = $wpdb->query($prepared);
            $t_sql_ms    = (microtime(true) - $t_sql_start) * 1000.0;

            if ($result === false) {
                $this->log_debug('[FFLHub][Zanders Import][LOAD DATA] query failed: ' . $wpdb->last_error);
                return -1;
            }

            // Post-clean: remove rows with empty/NULL UPC.
            $t_delete_start = microtime(true);
            $delete_result = $wpdb->query("DELETE FROM {$table_name} WHERE upc IN ('', 'null', 'NULL', 'Null')"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $t_delete_upc_ms = (microtime(true) - $t_delete_start) * 1000.0;
            $deleted_blank_upcs = is_numeric($delete_result) ? (int) $delete_result : 0;

            // Drop-ship restricted: keep rows, flag as non-dropship.
            $t_restricted_start = microtime(true);
            $marked_restricted = $this->mark_restricted_manufacturers_in_table($table_name);
            $t_restricted_ms = (microtime(true) - $t_restricted_start) * 1000.0;

            $this->log_debug(
                sprintf(
                    '[FFLHub][Zanders Import][LOAD DATA] marked_restricted_manufacturers=%d, sig_approval=folded_into_restricted_update',
                    $marked_restricted
                )
            );
        } catch (\Throwable $e) {
            $this->log_debug('[FFLHub][Zanders Import][LOAD DATA] exception: ' . $e->getMessage());
            return -1;
        }

        $t_count_start = microtime(true);
        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $t_count_ms = (microtime(true) - $t_count_start) * 1000.0;

        if ($rows > 0) {
            $t_update_options_start = microtime(true);
            update_option('fflhub_zanders_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_zanders_fulfillment_last_import_count', $rows, false);
            $t_update_options_ms = (microtime(true) - $t_update_options_start) * 1000.0;
        }

        $t_total_ms = (microtime(true) - $t_start) * 1000.0;

        DebugLogUtil::log_ctx('FFLHUB_CRON_DEBUG', '[FFLHub][ZandersImporter]', 'PROFILE: import_fulfillment_file_via_load_data steps', [
            'truncate_ms' => number_format($t_truncate_ms, 2, '.', ''),
            'load_data_sql_ms' => number_format($t_sql_ms, 2, '.', ''),
            'delete_blank_upc_ms' => number_format($t_delete_upc_ms, 2, '.', ''),
            'delete_blank_upc_rows' => $deleted_blank_upcs,
            'restricted_ms' => number_format($t_restricted_ms, 2, '.', ''),
            'restricted_rows' => $marked_restricted,
            'sig_approval_ms' => '0.00',
            'sig_approval_rows' => 0,
            'sig_approval_mode' => 'folded_into_restricted_update',
            'count_rows_ms' => number_format($t_count_ms, 2, '.', ''),
            'update_options_ms' => number_format($t_update_options_ms, 2, '.', ''),
            'total_ms' => number_format($t_total_ms, 2, '.', ''),
            'rows' => $rows,
        ]);

        $this->log_debug(
            sprintf(
                '[FFLHub][Zanders Import] import_fulfillment_file_via_load_data(): total=%.2f ms (sql=%.2f ms), rows=%d, ignore_lines=%d',
                $t_total_ms,
                $t_sql_ms,
                $rows,
                $ignore_lines
            )
        );

        return $rows;
    }

    /**
     * PHP fallback importer (batch insert).
     *
     * Must match LOAD DATA behavior:
     *  - CSV: comma + quoted fields (fgetcsv handles quoting)
     *  - inventory_quantity blank/"" => "0"
     *  - retail_map blank/"" => "0"
     *  - serialized => 1/0
     *  - ffl_required/sot_required derived from category
     *  - restricted manufacturers are retained and flagged as non-dropship
     */
    private function import_fulfillment_file_via_php(string $file_path): int
    {
        global $wpdb;

        $t_import_start = microtime(true);
        $t_parse_total  = 0.0;
        $t_flush_total  = 0.0;

        if (!file_exists($file_path) || !is_readable($file_path)) {
            return 0;
        }

        $table_name = $this->table->get_staging_table_name();

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $this->log_debug('[FFLHub][Zanders Import] could not fopen ' . $file_path);
            return 0;
        }

        // Clean staging.
        $wpdb->query("TRUNCATE TABLE {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // Read header row (expected).
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($header) || empty($header)) {
            fclose($handle);
            $this->log_debug('[FFLHub][Zanders Import] missing/invalid header in ' . $file_path);
            return 0;
        }

        $parser = new ZandersProductParser();
        $header_map = $parser->build_header_map($header);

        // Required columns for us to do anything useful.
        if (!$parser->has_required_columns($header_map)) {
            fclose($handle);
            $this->log_debug('[FFLHub][Zanders Import] header missing required columns upc/itemnumber');
            return 0;
        }

        $batch_size          = 1000;
        $batch_rows          = [];
        $total_import        = 0;
        $skipped_missing_upc = 0;

        $restricted_marked = 0;

        $batch_flushes  = 0;
        $batch_failures = 0;

        $columns     = $this->table->get_schema()->get_insert_columns();
        $num_cols    = count($columns);
        $column_list = implode(', ', $columns);

        $row_placeholder = '(' . implode(', ', array_fill(0, $num_cols, '%s')) . ')';
        $insert_prefix   = 'INSERT INTO ' . $table_name . ' (' . $column_list . ') VALUES ';

        $flush_batch = function () use (
            &$batch_rows,
            &$total_import,
            $wpdb,
            $columns,
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

            $placeholders = [];
            $values       = [];

            foreach ($batch_rows as $row) {
                $placeholders[] = $row_placeholder;

                foreach ($columns as $col) {
                    $values[] = array_key_exists($col, $row) ? $row[$col] : '';
                }
            }

            $sql      = $insert_prefix . implode(', ', $placeholders);
            $prepared = $wpdb->prepare($sql, $values);
            $result   = $wpdb->query($prepared);

            if ($result !== false) {
                $total_import += (int) $result;
            } else {
                $batch_failures++;
                $this->log_debug('[FFLHub][Zanders Import] Batch INSERT failed: ' . $wpdb->last_error);
            }

            $batch_rows = [];
            $t_flush_total += (microtime(true) - $t0);
        };

        while (($csv = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $t0 = microtime(true);

            $row = $parser->parse_csv_row($csv, $header_map);
            if ($row === null) {
                $t_parse_total += (microtime(true) - $t0);
                continue;
            }

            $upc = isset($row['upc']) ? trim((string) $row['upc']) : '';
            if ($upc === '' || strcasecmp($upc, 'null') === 0) {
                $skipped_missing_upc++;
                $t_parse_total += (microtime(true) - $t0);
                continue;
            }

            $manufacturer = isset($row['manufacturer']) ? trim((string) $row['manufacturer']) : '';
            $ffl_required = !empty($row['ffl_required']) && (string) $row['ffl_required'] !== '0';
            if ($this->should_block_restricted_drop_ship_manufacturer($manufacturer, $ffl_required)) {
                $row['dropship_enabled'] = '0';
                $row['dropship_block_reason'] = 'restricted_manufacturer';
                $restricted_marked++;
            } else {
                $row['dropship_enabled'] = '1';
                $row['dropship_block_reason'] = '';
            }
            $row = SigDropshipApproval::apply_to_row('zanders', $row);

            $batch_rows[] = $row;

            $t_parse_total += (microtime(true) - $t0);

            if (count($batch_rows) >= $batch_size) {
                $flush_batch();
            }
        }

        fclose($handle);

        $flush_batch();

        // Post-clean: remove rows with empty/NULL UPC (match LOAD DATA post-clean)
        $wpdb->query("DELETE FROM {$table_name} WHERE upc IN ('', 'null', 'NULL', 'Null')"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($total_import > 0) {
            update_option('fflhub_zanders_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_zanders_fulfillment_last_import_count', $total_import, false);
        }

        $t_import_total_ms = (microtime(true) - $t_import_start) * 1000.0;
        $t_parse_ms        = $t_parse_total * 1000.0;
        $t_flush_ms        = $t_flush_total * 1000.0;

        $this->log_debug(
            sprintf(
                '[FFLHub][Zanders Import] import_fulfillment_file_via_php(): total=%.2f ms, parse+loop=%.2f ms, db_flush=%.2f ms, inserted_rows=%d, skipped_missing_upc=%d, restricted_marked=%d, batch_flushes=%d, batch_failures=%d',
                $t_import_total_ms,
                $t_parse_ms,
                $t_flush_ms,
                $total_import,
                $skipped_missing_upc,
                $restricted_marked,
                $batch_flushes,
                $batch_failures
            )
        );

        return $total_import;
    }

    /**
     * Mark restricted manufacturers as non-dropship on a given table.
     *
     * @param string $table_name
     * @return int rows updated
     */
    private function mark_restricted_manufacturers_in_table(string $table_name): int
    {
        global $wpdb;

        $quoted_table = $this->quote_zanders_product_table($table_name);
        if ($quoted_table === '') {
            $this->log_debug('[FFLHub][Zanders Import][Restricted] invalid product table: ' . $table_name);
            return 0;
        }

        $this->ensure_restricted_aliases_ready();

        $alias_table = self::quote_identifier($this->get_restricted_alias_table_name());
        $total_start = microtime(true);
        $additional_where = $this->restricted_dropship_exclusion_where_sql();
        $manufacturer_match_norm = ZandersManufacturerNormalizer::sql_expression('s.manufacturer_norm');

        $exact = $this->run_restricted_alias_update(
            $quoted_table,
            $alias_table,
            'exact',
            "r.alias_norm = {$manufacturer_match_norm}",
            $additional_where
        );

        $prefix = ['rows' => 0, 'elapsed_ms' => 0.0];
        if ($this->has_active_restricted_alias_type('prefix')) {
            $prefix = $this->run_restricted_alias_update(
                $quoted_table,
                $alias_table,
                'prefix',
                "{$manufacturer_match_norm} LIKE CONCAT(r.alias_norm, '%')",
                $additional_where
            );
        }

        $contains = ['rows' => 0, 'elapsed_ms' => 0.0];
        if ($this->has_active_restricted_alias_type('contains')) {
            $contains = $this->run_restricted_alias_update(
                $quoted_table,
                $alias_table,
                'contains',
                "LOCATE(r.alias_norm, {$manufacturer_match_norm}) > 0 AND CHAR_LENGTH(r.alias_norm) >= 5",
                $additional_where
            );
        }

        $total_rows = (int) $exact['rows'] + (int) $prefix['rows'] + (int) $contains['rows'];
        $total_ms = (microtime(true) - $total_start) * 1000.0;

        DebugLogUtil::log_ctx('FFLHUB_CRON_DEBUG', '[FFLHub][ZandersImporter]', 'PROFILE: mark_restricted_manufacturers_in_table()', [
            'exact_rows' => (int) $exact['rows'],
            'exact_ms' => number_format((float) $exact['elapsed_ms'], 2, '.', ''),
            'prefix_rows' => (int) $prefix['rows'],
            'prefix_ms' => number_format((float) $prefix['elapsed_ms'], 2, '.', ''),
            'contains_rows' => (int) $contains['rows'],
            'contains_ms' => number_format((float) $contains['elapsed_ms'], 2, '.', ''),
            'total_rows' => $total_rows,
            'total_ms' => number_format($total_ms, 2, '.', ''),
        ]);

        return $total_rows;
    }

    /**
     * Additional restricted-update guard for Zanders' SIG approval.
     * Approved non-SOT SIG rows are never marked restricted, so we avoid a second
     * update pass while keeping NFA/SOT SIG rows blocked.
     */
    private function sig_approved_non_sot_exclusion_where_sql(): string
    {
        if (!SigDropshipApproval::is_distributor_sig_approved('zanders')) {
            return '';
        }

        return "
            AND NOT (
                COALESCE(s.sot_required, 0) = 0
                AND s.manufacturer_norm = 'SIG SAUER'
            )
        ";
    }

    /**
     * Extra guards for the restricted-manufacturer update.
     *
     * This SQL runs inside the restricted alias update where:
     * - s is the Zanders product table alias
     * - r is the restricted manufacturer alias table alias
     */
    private function restricted_dropship_exclusion_where_sql(): string
    {
        return $this->sig_approved_non_sot_exclusion_where_sql()
            . $this->non_ffl_brand_accessory_exclusion_where_sql();
    }

    private function non_ffl_brand_accessory_exclusion_where_sql(): string
    {
        $canonical = array_map('esc_sql', self::FFL_ONLY_RESTRICTED_DROP_SHIP_MANUFACTURERS);
        $canonical_sql = "'" . implode("', '", $canonical) . "'";

        return "
            AND NOT (
                COALESCE(s.ffl_required, 0) = 0
                AND r.canonical_manufacturer IN ({$canonical_sql})
            )
        ";
    }

    private function load_data_initial_dropship_block_reason_sql(string $category_expr, string $manufacturer_expr): string
    {
        if (!SigDropshipApproval::is_distributor_sig_approved('zanders')) {
            return 'NULL';
        }

        $manufacturer_norm = ZandersManufacturerNormalizer::canonical_norm_sql_expression($manufacturer_expr);
        $category_norm = "UPPER(TRIM(BOTH '\\r' FROM {$category_expr}))";

        return "
            CASE
                WHEN {$category_norm} NOT IN ('DS SUPPRESSORS')
                 AND {$manufacturer_norm} = 'SIG SAUER'
                THEN ''
                ELSE NULL
            END
        ";
    }

    /**
     * @return array{rows:int,elapsed_ms:float}
     */
    private function run_restricted_alias_update(string $quoted_table, string $quoted_alias_table, string $match_type, string $join_condition, string $additional_where = ''): array
    {
        global $wpdb;

        $t0 = microtime(true);

        $sql = $wpdb->prepare(
            "
                UPDATE {$quoted_table} s
                INNER JOIN {$quoted_alias_table} r
                    ON r.distributor = %s
                   AND r.active = 1
                   AND r.match_type = %s
                   AND {$join_condition}
                SET
                    s.dropship_enabled = 0,
                    s.dropship_block_reason = 'restricted_manufacturer'
                WHERE s.manufacturer_norm <> ''
                  AND s.dropship_enabled <> 0
                  {$additional_where}
            ",
            'zanders',
            $match_type
        );

        $result = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($result === false) {
            throw new \RuntimeException('Restricted manufacturer update failed: ' . (string) $wpdb->last_error);
        }

        return [
            'rows' => is_numeric($result) ? (int) $result : 0,
            'elapsed_ms' => (microtime(true) - $t0) * 1000.0,
        ];
    }

    private function has_active_restricted_alias_type(string $match_type): bool
    {
        global $wpdb;

        $alias_table = self::quote_identifier($this->get_restricted_alias_table_name());
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$alias_table} WHERE distributor = %s AND active = 1 AND match_type = %s",
                'zanders',
                $match_type
            )
        );

        return $count > 0;
    }

    private function ensure_restricted_aliases_ready(): void
    {
        if (self::$restrictedAliasTableReady) {
            return;
        }

        $this->ensure_restricted_alias_table();
        $this->seed_restricted_aliases();

        self::$restrictedAliasTableReady = true;
    }

    private function ensure_restricted_alias_table(): void
    {
        global $wpdb;

        $table = $this->get_restricted_alias_table_name();
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(
            "CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
distributor VARCHAR(32) NOT NULL DEFAULT 'zanders',
canonical_manufacturer VARCHAR(191) NOT NULL,
alias_label VARCHAR(191) NOT NULL,
alias_norm VARCHAR(191) NOT NULL,
match_type VARCHAR(16) NOT NULL DEFAULT 'exact',
active TINYINT(1) NOT NULL DEFAULT 1,
PRIMARY KEY  (id),
UNIQUE KEY uq_restricted_alias (distributor, alias_norm, match_type),
KEY idx_restricted_lookup (distributor, active, match_type, alias_norm)
) {$charset};"
        );
    }

    private function seed_restricted_aliases(): void
    {
        global $wpdb;

        $table = self::quote_identifier($this->get_restricted_alias_table_name());

        foreach ($this->restricted_alias_seed_rows() as $row) {
            $alias_norm = ZandersManufacturerNormalizer::normalize($row['alias_label']);
            if ($alias_norm === '') {
                continue;
            }

            $sql = $wpdb->prepare(
                "
                    INSERT INTO {$table}
                        (distributor, canonical_manufacturer, alias_label, alias_norm, match_type, active)
                    VALUES
                        (%s, %s, %s, %s, %s, 1)
                    ON DUPLICATE KEY UPDATE
                        canonical_manufacturer = VALUES(canonical_manufacturer),
                        alias_label = VALUES(alias_label),
                        active = VALUES(active)
                ",
                'zanders',
                $row['canonical_manufacturer'],
                $row['alias_label'],
                $alias_norm,
                $row['match_type']
            );

            $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    /**
     * @return array<int,array{canonical_manufacturer:string,alias_label:string,match_type:string}>
     */
    private function restricted_alias_seed_rows(): array
    {
        $rows = [];

        foreach (self::RESTRICTED_DROP_SHIP_MANUFACTURERS as $manufacturer) {
            $manufacturer = (string) $manufacturer;
            $rows[] = [
                'canonical_manufacturer' => $manufacturer,
                'alias_label' => $manufacturer,
                'match_type' => 'exact',
            ];
        }

        $aliases = [
            'S&W FIREARMS' => ['SMITH WESSON', 'SMITH AND WESSON'],
            'HK/HECKLER & KOCH FIREARMS' => ['HK', 'HECKLER KOCH', 'HECKLER AND KOCH'],
            'COLT/CZ' => ['COLT', 'CZ', 'CZ USA'],
            'COLUMBIA RIVER KNIFE & TOOL/CRKT' => ['CRKT', 'COLUMBIA RIVER'],
            'BERETTA FIREARMS' => ['BERETTA'],
            'RUGER FIREARMS' => ['RUGER'],
            'SPRINGFIELD FIREARMS' => ['SPRINGFIELD', 'SPRINGFIELD ARMORY'],
            'SIG SAUER' => ['SIG'],
            'UMAREX (RWS, AXEON)' => ['UMAREX', 'RWS', 'AXEON'],
            'TIMNEY TRIGGERS' => ['TIMNEY'],
            'LONGSHOT' => ['LONGSHOT TARGET CAMERA'],
            'LEGACY RESTRICTED MATCH' => [
                'ARMASIGHT',
                'CRKT KNIVES',
                'CZ CUSTOM',
                'DURA SIGHT',
                'FN AMERICA',
                'FRANKFORD ARSENAL',
                'HKS',
                'OSIGHT',
                'SHIELD SIGHTS',
                'SIGHTMARK',
                'SIGHTRON',
                'UMAREX USA',
                'WILLIAMS GUNSIGHT CO',
                'XS SIGHT SYSTEMS',
            ],
        ];

        foreach ($aliases as $canonical => $labels) {
            foreach ($labels as $label) {
                $rows[] = [
                    'canonical_manufacturer' => (string) $canonical,
                    'alias_label' => (string) $label,
                    'match_type' => 'exact',
                ];
            }
        }

        return $rows;
    }

    private function get_restricted_alias_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::RESTRICTED_ALIAS_TABLE;
    }

    private function quote_zanders_product_table(string $table_name): string
    {
        $table_name = trim($table_name);
        $valid = [
            $this->table->get_table_name_with_suffix('v1'),
            $this->table->get_table_name_with_suffix('v2'),
        ];

        if (!in_array($table_name, $valid, true)) {
            return '';
        }

        return self::quote_identifier($table_name);
    }

    private static function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Is a manufacturer restricted for drop ship?
     */
    private function should_block_restricted_drop_ship_manufacturer(string $manufacturer, bool $ffl_required): bool
    {
        if (!$this->is_restricted_drop_ship_manufacturer($manufacturer)) {
            return false;
        }

        if (!$ffl_required && $this->is_ffl_only_restricted_drop_ship_manufacturer($manufacturer)) {
            return false;
        }

        return true;
    }

    private function is_restricted_drop_ship_manufacturer(string $manufacturer): bool
    {
        return $this->manufacturer_matches_any($manufacturer, self::RESTRICTED_DROP_SHIP_MANUFACTURERS);
    }

    private function is_ffl_only_restricted_drop_ship_manufacturer(string $manufacturer): bool
    {
        return $this->manufacturer_matches_any($manufacturer, self::FFL_ONLY_RESTRICTED_DROP_SHIP_MANUFACTURERS);
    }

    /**
     * Is a manufacturer in a normalized manufacturer list?
     *
     * @param string[] $manufacturers
     */
    private function manufacturer_matches_any(string $manufacturer, array $manufacturers): bool
    {
        $m_norm = $this->normalize_mfr_key($manufacturer);
        if ($m_norm === '') {
            return false;
        }

        foreach ($manufacturers as $raw) {
            $r_norm = $this->normalize_mfr_key((string) $raw);
            if ($r_norm === '') {
                continue;
            }

            // Exact match
            if ($m_norm === $r_norm) {
                return true;
            }

            // Contains match (handles "COLT/CZ" vs "COLT", etc.)
            if (strpos($m_norm, $r_norm) !== false || strpos($r_norm, $m_norm) !== false) {
                return true;
            }

            // Alias handling (S&W, HK, etc.)
            foreach ($this->restricted_alias_norms($r_norm) as $alias_norm) {
                if ($alias_norm === '') {
                    continue;
                }
                if ($m_norm === $alias_norm || strpos($m_norm, $alias_norm) !== false || strpos($alias_norm, $m_norm) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Normalize manufacturer key for comparisons.
     *
     * - uppercase
     * - strip spaces and common punctuation
     */
    private function normalize_mfr_key(string $v): string
    {
        return ZandersManufacturerNormalizer::normalize($v);
    }

    /**
     * Some restricted entries are formatted in a way that benefits from aliases.
     * We return additional normalized keys to match against.
     */
    private function restricted_alias_norms(string $restricted_norm): array
    {
        // These are already normalized forms (no spaces/punct).
        $aliases = [];

        // S&W variants
        if ($restricted_norm === 'SWFIREARMS' || $restricted_norm === 'SW') {
            $aliases[] = 'SMITHWESSON';
            $aliases[] = 'SMITHANDWESSON';
        }
        if ($restricted_norm === 'SMITHWESSON' || $restricted_norm === 'SMITHANDWESSON') {
            $aliases[] = 'SW';
            $aliases[] = 'SWFIREARMS';
        }

        // HK variants
        if ($restricted_norm === 'HKHECKLERKOCHFIREARMS' || $restricted_norm === 'HK') {
            $aliases[] = 'HK';
            $aliases[] = 'HECKLERKOCH';
            $aliases[] = 'HECKLERANDKOCH';
        }
        if ($restricted_norm === 'HECKLERKOCH' || $restricted_norm === 'HECKLERANDKOCH') {
            $aliases[] = 'HK';
        }

        // Colt/CZ variants
        if ($restricted_norm === 'COLTCZ') {
            $aliases[] = 'COLT';
            $aliases[] = 'CZ';
            $aliases[] = 'CZUSA';
        }

        // CRKT variants
        if ($restricted_norm === 'COLUMBIARIVERKNIFETOOLCRKT') {
            $aliases[] = 'CRKT';
            $aliases[] = 'COLUMBIARIVER';
        }

        // Beretta variants
        if ($restricted_norm === 'BERETTAFIREARMS') {
            $aliases[] = 'BERETTA';
        }

        // Ruger variants
        if ($restricted_norm === 'RUGERFIREARMS') {
            $aliases[] = 'RUGER';
        }

        // Springfield variants
        if ($restricted_norm === 'SPRINGFIELDFIREARMS') {
            $aliases[] = 'SPRINGFIELD';
            $aliases[] = 'SPRINGFIELDARMORY';
        }

        // Sig variants
        if ($restricted_norm === 'SIGSAUER') {
            $aliases[] = 'SIG';
        }

        // Umarex variants (they listed "Umarex (RWS, AXEON)")
        if ($restricted_norm === 'UMAREXRWSAXEON') {
            $aliases[] = 'UMAREX';
            $aliases[] = 'RWS';
            $aliases[] = 'AXEON';
        }


                // Timney variants
        if ($restricted_norm === 'TIMNEYTRIGGERS') {
            $aliases[] = 'TIMNEY';
        }

        // Longshot variants
        if ($restricted_norm === 'LONGSHOT') {
            $aliases[] = 'LONGSHOTTARGETCAMERA';
        }


        return $aliases;
    }

    private function log_debug(string $message): void
    {
        DebugLogUtil::log('FFLHUB_CRON_DEBUG', '[FFLHub][ZandersImporter]', $message);
    }
}
