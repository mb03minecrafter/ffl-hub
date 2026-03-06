<?php

namespace FFLHub\Distributor\Services\Zanders;

use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
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
 * shipping_weight
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
 * - We MUST NOT import "restricted drop ship" manufacturers (Zanders-provided list).
 * - We MUST keep CSV parsing settings exact (quoted CSV, CRLF, escaped backslashes).
 */
class ZandersFulfillmentImporterService
{
    /** @var DoubleBufferedFulfillmentTable */
    private $table;

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

    public function __construct(DoubleBufferedFulfillmentTable $table)
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
         * - We can't truly "skip" rows during LOAD DATA without a user variable hack.
         * - Strategy:
         *    1) LOAD DATA into staging normally
         *    2) DELETE restricted manufacturers (normalized matching) immediately after
         *
         * This still ensures restricted rows are never present after the import completes.
         */
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
                manufacturer         = TRIM(BOTH '\\r' FROM @c5),
                mfg_model_number     = TRIM(BOTH '\\r' FROM @c6),

                retail_msrp          = TRIM(BOTH '\\r' FROM @c7),

                distributor_price    = TRIM(BOTH '\\r' FROM @c8),
                price_2              = TRIM(BOTH '\\r' FROM @c9),
                price_3              = TRIM(BOTH '\\r' FROM @c10),

                bulk_qty_1           = TRIM(BOTH '\\r' FROM @c11),
                bulk_qty_2           = TRIM(BOTH '\\r' FROM @c12),
                bulk_qty_3           = TRIM(BOTH '\\r' FROM @c13),

                upc                  = TRIM(BOTH '\\r' FROM @c14),

                shipping_weight      = CASE
                                        WHEN TRIM(BOTH '\\r' FROM @c15) IN ('', '\"\"') THEN NULL
                                        ELSE CAST(TRIM(BOTH '\\r' FROM @c15) AS DECIMAL(10,2))
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
                                      END
        ";

        $t_sql_ms = 0.0;

        try {
            $wpdb->query("TRUNCATE TABLE {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            $prepared    = $wpdb->prepare($sql, $file_path);
            $t_sql_start = microtime(true);
            $result      = $wpdb->query($prepared);
            $t_sql_ms    = (microtime(true) - $t_sql_start) * 1000.0;

            if ($result === false) {
                $this->log_debug('[FFLHub][Zanders Import][LOAD DATA] query failed: ' . $wpdb->last_error);
                return -1;
            }

            // Post-clean: remove rows with empty/NULL UPC.
            $wpdb->query("DELETE FROM {$table_name} WHERE upc IS NULL OR upc = '' OR LOWER(upc) = 'null'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            // Drop-ship restricted: delete restricted manufacturers.
            $deleted_restricted = $this->delete_restricted_manufacturers_from_table($table_name);

            $this->log_debug(
                sprintf(
                    '[FFLHub][Zanders Import][LOAD DATA] deleted_restricted_manufacturers=%d',
                    $deleted_restricted
                )
            );
        } catch (\Throwable $e) {
            $this->log_debug('[FFLHub][Zanders Import][LOAD DATA] exception: ' . $e->getMessage());
            return -1;
        }

        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($rows > 0) {
            update_option('fflhub_zanders_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_zanders_fulfillment_last_import_count', $rows, false);
        }

        $t_total_ms = (microtime(true) - $t_start) * 1000.0;

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
     *  - restricted manufacturers are skipped BEFORE batching
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

        $parser = new ZandersFulfillmentParser();
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

        $skipped_restricted_mfr = 0;

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
            if ($this->is_restricted_drop_ship_manufacturer($manufacturer)) {
                $skipped_restricted_mfr++;
                $t_parse_total += (microtime(true) - $t0);
                continue;
            }

            $batch_rows[] = $row;

            $t_parse_total += (microtime(true) - $t0);

            if (count($batch_rows) >= $batch_size) {
                $flush_batch();
            }
        }

        fclose($handle);

        $flush_batch();

        // Post-clean: remove rows with empty/NULL UPC (match LOAD DATA post-clean)
        $wpdb->query("DELETE FROM {$table_name} WHERE upc IS NULL OR upc = '' OR LOWER(upc) = 'null'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($total_import > 0) {
            update_option('fflhub_zanders_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_zanders_fulfillment_last_import_count', $total_import, false);
        }

        $t_import_total_ms = (microtime(true) - $t_import_start) * 1000.0;
        $t_parse_ms        = $t_parse_total * 1000.0;
        $t_flush_ms        = $t_flush_total * 1000.0;

        $this->log_debug(
            sprintf(
                '[FFLHub][Zanders Import] import_fulfillment_file_via_php(): total=%.2f ms, parse+loop=%.2f ms, db_flush=%.2f ms, inserted_rows=%d, skipped_missing_upc=%d, skipped_restricted_mfr=%d, batch_flushes=%d, batch_failures=%d',
                $t_import_total_ms,
                $t_parse_ms,
                $t_flush_ms,
                $total_import,
                $skipped_missing_upc,
                $skipped_restricted_mfr,
                $batch_flushes,
                $batch_failures
            )
        );

        return $total_import;
    }

    /**
     * Delete restricted manufacturers from a given table (used for LOAD DATA fast path).
     *
     * @param string $table_name
     * @return int rows deleted
     */
    private function delete_restricted_manufacturers_from_table(string $table_name): int
    {
        global $wpdb;

        // Build (manufacturer IS NOT NULL AND (normalized match OR token match...)) as OR clauses.
        // We avoid regex here for portability; this is fast enough and happens once per import.
        $restricted = self::RESTRICTED_DROP_SHIP_MANUFACTURERS;

        $clauses = [];
        foreach ($restricted as $raw) {
            $raw = (string) $raw;
            $raw_norm = $this->normalize_mfr_key($raw);

            // Skip empty just in case
            if ($raw_norm === '') {
                continue;
            }

            // Exact normalized match OR "contains" match.
            // We normalize manufacturer in SQL by stripping common punctuation/spaces.
            $clauses[] = sprintf(
                "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(manufacturer)),' ',''),'&',''),'/',''),'-',''),'.','') = '%s'
                 OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(manufacturer)),' ',''),'&',''),'/',''),'-',''),'.','') LIKE '%%%s%%'",
                esc_sql($raw_norm),
                esc_sql($raw_norm)
            );

            // Also handle some common synonyms in the restricted list that won't normalize well
            // (e.g., "S&W" vs "SMITHWESSON", "HK" vs "HECKLERKOCH").
            foreach ($this->restricted_alias_norms($raw_norm) as $alias_norm) {
                if ($alias_norm === '') {
                    continue;
                }
                $clauses[] = sprintf(
                    "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(manufacturer)),' ',''),'&',''),'/',''),'-',''),'.','') = '%s'
                     OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(manufacturer)),' ',''),'&',''),'/',''),'-',''),'.','') LIKE '%%%s%%'",
                    esc_sql($alias_norm),
                    esc_sql($alias_norm)
                );
            }
        }

        if (empty($clauses)) {
            return 0;
        }

        $where = '(' . implode(' OR ', $clauses) . ')';

        $sql = "DELETE FROM {$table_name} WHERE manufacturer IS NOT NULL AND TRIM(manufacturer) <> '' AND ({$where})";
        $res = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_numeric($res) ? (int) $res : 0;
    }

    /**
     * Is a manufacturer restricted for drop ship?
     */
    private function is_restricted_drop_ship_manufacturer(string $manufacturer): bool
    {
        $m_norm = $this->normalize_mfr_key($manufacturer);
        if ($m_norm === '') {
            return false;
        }

        foreach (self::RESTRICTED_DROP_SHIP_MANUFACTURERS as $raw) {
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
        $v = strtoupper(trim($v));
        $v = preg_replace('/^\xEF\xBB\xBF/', '', $v);
        $v = str_replace([' ', "\t", "\r", "\n"], '', $v);
        $v = str_replace(['&', '/', '-', '.', ',', '\'', '"'], '', $v);

        // Also strip parentheses content markers without trying to parse them
        $v = str_replace(['(', ')'], '', $v);

        return (string) $v;
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
