<?php

namespace FFLHub\Distributor\Services\Zanders;

use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;

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
 * Source columns:
 * available,category,desc1,desc2,itemnumber,manufacturer,mfgpnumber,msrp,
 * price1,price2,price3,qty1,qty2,qty3,upc,weight,serialized,mapprice
 */
class ZandersFulfillmentImporterService
{
    /** @var DoubleBufferedFulfillmentTable */
    private $table;

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
         * 0 available
         * 1 category
         * 2 desc1
         * 3 desc2
         * 4 itemnumber
         * 5 manufacturer
         * 6 mfgpnumber
         * 7 msrp
         * 8 price1
         * 9 price2
         * 10 price3
         * 11 qty1
         * 12 qty2
         * 13 qty3
         * 14 upc
         * 15 weight
         * 16 serialized
         * 17 mapprice
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
        available                = TRIM(BOTH '\\r' FROM @c0),
        category                 = TRIM(BOTH '\\r' FROM @c1),
        desc1                    = TRIM(BOTH '\\r' FROM @c2),
        desc2                    = TRIM(BOTH '\\r' FROM @c3),
        zanders_item_number      = TRIM(BOTH '\\r' FROM @c4),
        manufacturer             = TRIM(BOTH '\\r' FROM @c5),
        manufacturer_part_number = TRIM(BOTH '\\r' FROM @c6),
        msrp                     = TRIM(BOTH '\\r' FROM @c7),
        price_1                  = TRIM(BOTH '\\r' FROM @c8),
        price_2                  = TRIM(BOTH '\\r' FROM @c9),
        price_3                  = TRIM(BOTH '\\r' FROM @c10),
        bulk_qty_1               = TRIM(BOTH '\\r' FROM @c11),
        bulk_qty_2               = TRIM(BOTH '\\r' FROM @c12),
        bulk_qty_3               = TRIM(BOTH '\\r' FROM @c13),
        upc                      = TRIM(BOTH '\\r' FROM @c14),
        weight_lb                = TRIM(BOTH '\\r' FROM @c15),
        serialized               = CASE
                                    WHEN UPPER(TRIM(BOTH '\\r' FROM @c16)) IN ('YES','Y','1','TRUE','T')
                                    THEN '1' ELSE '0'
                                  END,
        map_price = CASE
            WHEN TRIM(BOTH '\\r' FROM @c17) IN ('', '\"\"') THEN '0'
            ELSE TRIM(BOTH '\\r' FROM @c17)
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
     *  - "serialized" => 1/0
     *  - blank/"" map_price => "0"
     *  - blank/"" available => "0"
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

        // Normalize header keys for mapping.
        $header_map = [];
        foreach ($header as $idx => $name) {
            $k = strtolower(trim((string) $name));
            if ($k !== '') {
                // Strip UTF-8 BOM if it ever appears on first header cell
                $k = (string) preg_replace('/^\xEF\xBB\xBF/', '', $k);
                $header_map[$k] = (int) $idx;
            }
        }

        // Required columns for us to do anything useful.
        if (!isset($header_map['upc']) || !isset($header_map['itemnumber'])) {
            fclose($handle);
            $this->log_debug('[FFLHub][Zanders Import] header missing required columns upc/itemnumber');
            return 0;
        }

        $batch_size          = 1000;
        $batch_rows          = [];
        $total_import        = 0;
        $line_number         = 1; // already consumed header
        $skipped_missing_upc = 0;

        $batch_flushes  = 0;
        $batch_failures = 0;

        $columns     = $this->table->get_schema()->get_insert_columns();
        $num_cols    = count($columns);
        $column_list = implode(', ', $columns);

        $row_placeholder = '(' . implode(', ', array_fill(0, $num_cols, '%s')) . ')';
        $insert_prefix   = 'INSERT INTO ' . $table_name . ' (' . $column_list . ') VALUES ';

        $to_bool = static function ($v): string {
            $v = strtoupper(trim((string) $v));
            return in_array($v, ['YES', 'Y', '1', 'TRUE', 'T'], true) ? '1' : '0';
        };

        /**
         * Shared hard-normalize (match LOAD DATA trimming intent).
         * Note: fgetcsv already unquotes.
         */
        $norm = static function ($val): string {
            if ($val === null) {
                return '';
            }
            $val = (string) $val;

            // Remove BOM if it sneaks into data
            $val = (string) preg_replace('/^\xEF\xBB\xBF/', '', $val);

            // Trim whitespace + CR/LF (LOAD DATA trims '\r' explicitly)
            $val = trim($val);
            $val = trim($val, "\r\n");

            return $val;
        };

        $get = static function (array $row, array $map, string $key) use ($norm): string {
            if (!isset($map[$key])) {
                return '';
            }
            $idx = (int) $map[$key];
            return $norm($row[$idx] ?? '');
        };

        /**
         * Blank/"" => 0 (match LOAD DATA CASE when IN ('', '""'))
         */
        $blank_to_zero = static function (string $v): string {
            $v = trim($v);
            if ($v === '' || $v === '""') {
                return '0';
            }
            return $v;
        };

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
                    $values[] = isset($row[$col]) ? $row[$col] : '';
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
            $line_number++;

            // Skip completely empty lines (Zanders sometimes has them)
            if ($csv === [null] || $csv === false || count($csv) < 5) {
                continue;
            }

            $t0 = microtime(true);

            // Pull + normalize fields
            $upc  = $get($csv, $header_map, 'upc');
            $item = $get($csv, $header_map, 'itemnumber');

            // UPC must exist
            if ($upc === '' || strcasecmp($upc, 'null') === 0) {
                $skipped_missing_upc++;
                $t_parse_total += (microtime(true) - $t0);
                continue;
            }

            // Build one row in schema terms (match LOAD DATA behavior)
            $row = [
                'upc'                      => $upc,
                'zanders_item_number'      => $item,
                'manufacturer'             => $get($csv, $header_map, 'manufacturer'),
                'manufacturer_part_number' => $get($csv, $header_map, 'mfgpnumber'),
                'category'                 => $get($csv, $header_map, 'category'),
                'desc1'                    => $get($csv, $header_map, 'desc1'),
                'desc2'                    => $get($csv, $header_map, 'desc2'),

                // Blank -> 0 like LOAD DATA CASE
                'available' => $blank_to_zero($get($csv, $header_map, 'available')),
                'msrp'      => $get($csv, $header_map, 'msrp'),

                // Blank -> 0 like LOAD DATA CASE
                'map_price' => $blank_to_zero($get($csv, $header_map, 'mapprice')),

                'price_1'    => $get($csv, $header_map, 'price1'),
                'price_2'    => $get($csv, $header_map, 'price2'),
                'price_3'    => $get($csv, $header_map, 'price3'),
                'bulk_qty_1' => $get($csv, $header_map, 'qty1'),
                'bulk_qty_2' => $get($csv, $header_map, 'qty2'),
                'bulk_qty_3' => $get($csv, $header_map, 'qty3'),
                'weight_lb'  => $get($csv, $header_map, 'weight'),

                'serialized'      => $to_bool($get($csv, $header_map, 'serialized')),
                'reserved_future' => '',
            ];

            $batch_rows[] = $row;

            $t_parse_total += (microtime(true) - $t0);

            if (count($batch_rows) >= $batch_size) {
                $flush_batch();
            }
        }

        fclose($handle);

        $flush_batch();

        if ($total_import > 0) {
            update_option('fflhub_zanders_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_zanders_fulfillment_last_import_count', $total_import, false);
        }

        $t_import_total_ms = (microtime(true) - $t_import_start) * 1000.0;
        $t_parse_ms        = $t_parse_total * 1000.0;
        $t_flush_ms        = $t_flush_total * 1000.0;

        $this->log_debug(
            sprintf(
                '[FFLHub][Zanders Import] import_fulfillment_file_via_php(): total=%.2f ms, parse+loop=%.2f ms, db_flush=%.2f ms, inserted_rows=%d, skipped_missing_upc=%d, batch_flushes=%d, batch_failures=%d',
                $t_import_total_ms,
                $t_parse_ms,
                $t_flush_ms,
                $total_import,
                $skipped_missing_upc,
                $batch_flushes,
                $batch_failures
            )
        );

        return $total_import;
    }


    private function log_debug(string $message): void
    {
        if (!defined('FFLHUB_CRON_DEBUG') || FFLHUB_CRON_DEBUG !== true) {
            return;
        }

        error_log($message);
    }
}
