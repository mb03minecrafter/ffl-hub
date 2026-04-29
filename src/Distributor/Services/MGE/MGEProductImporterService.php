<?php

namespace FFLHub\Distributor\Services\MGE;

use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Imports the MGE full catalog CSV into the staging table.
 */
class MGEProductImporterService
{
    private const LOCAL_DIR = 'fflhub-mge';
    private const LOCAL_FULL_FILE = 'vendorname_items.csv';

    /** @var DoubleBufferedProductTable */
    private $table;

    public function __construct(DoubleBufferedProductTable $table)
    {
        $this->table = $table;
    }

    /**
     * Import from uploads/fflhub-mge/vendorname_items.csv
     */
    public function import_from_downloaded_file(): int
    {
        $uploads = wp_upload_dir();
        $base_dir = trailingslashit((string) ($uploads['basedir'] ?? '')) . self::LOCAL_DIR;
        $file_path = trailingslashit($base_dir) . self::LOCAL_FULL_FILE;

        if (!file_exists($file_path) || !is_readable($file_path)) {
            $this->log_debug('[FFLHub][MGE Import] File missing or unreadable at ' . $file_path);
            return 0;
        }

        return $this->import_fulfillment_file($file_path);
    }

    public function import_fulfillment_file(string $file_path): int
    {
        if ($this->can_use_load_data_local_infile()) {
            $rows = $this->import_fulfillment_file_via_load_data($file_path);
            if ($rows >= 0) {
                return $rows;
            }
            $this->log_debug('[FFLHub][MGE Import] LOAD DATA path failed, falling back to PHP importer.');
        }

        return $this->import_fulfillment_file_via_php($file_path);
    }

    private function can_use_load_data_local_infile(): bool
    {
        global $wpdb;

        $row = $wpdb->get_row("SHOW VARIABLES LIKE 'local_infile'");
        $mysql_ok = $row && isset($row->Value) && in_array(strtolower((string) $row->Value), ['on', '1'], true);

        $mysqli = ini_get('mysqli.allow_local_infile');
        $pdo = ini_get('pdo_mysql.allow_local_infile');

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
                '[FFLHub][MGE Import][DEBUG] LOAD DATA check: mysql_ok=%s, php_ok=%s, result=%s',
                $mysql_ok ? 'true' : 'false',
                $php_ok ? 'true' : 'false',
                $result ? 'true' : 'false'
            )
        );

        return $result;
    }

    /**
     * @return int >= 0 inserted rows, -1 on failure
     */
    private function import_fulfillment_file_via_load_data(string $file_path): int
    {
        global $wpdb;

        $t_start = microtime(true);

        if (!file_exists($file_path) || !is_readable($file_path)) {
            $this->log_debug('[FFLHub][MGE Import][LOAD DATA] file missing or not readable at ' . $file_path);
            return -1;
        }

        $table_name = $this->table->get_staging_table_name();

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $ignore_lines = 1;

        $sql = "
            LOAD DATA LOCAL INFILE %s
            IGNORE INTO TABLE {$table_name}
            CHARACTER SET utf8mb4
            FIELDS
                TERMINATED BY ','
                ENCLOSED BY '\"'
                ESCAPED BY '\\\\'
            LINES TERMINATED BY '\\n'
            IGNORE {$ignore_lines} LINES
            (
                @c0,  @c1,  @c2,  @c3,  @c4,  @c5,  @c6,  @c7,
                @c8,  @c9,  @c10, @c11, @c12, @c13, @c14, @c15
            )
            SET
                product_description = TRIM(BOTH '\\r' FROM TRIM(@c0)),
                sub_category        = TRIM(BOTH '\\r' FROM TRIM(@c1)),
                model               = TRIM(BOTH '\\r' FROM TRIM(@c2)),

                inventory_quantity  = CASE
                                        WHEN TRIM(BOTH '\\r' FROM TRIM(@c3)) = '' THEN '0'
                                        ELSE TRIM(BOTH '\\r' FROM TRIM(@c3))
                                      END,

                @map_raw            := TRIM(BOTH '\\r' FROM TRIM(@c4)),
                retail_map          = CASE
                                        WHEN @map_raw = '' OR UPPER(@map_raw) = 'N/A' THEN ''
                                        ELSE REPLACE(REPLACE(@map_raw, '$', ''), ',', '')
                                      END,
                retail_msrp         = '',

                @barcod_raw         := TRIM(BOTH '\\r' FROM TRIM(@c5)),
                upc                 = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(BOTH '#' FROM @barcod_raw), ' ', ''), '-', ''), '.', ''), ',', ''), '\t', ''),
                barcod_raw          = @barcod_raw,

                image_url           = TRIM(BOTH '\\r' FROM TRIM(@c6)),
                manufacturer        = TRIM(BOTH '\\r' FROM TRIM(@c7)),
                manufacturer_id     = TRIM(BOTH '\\r' FROM TRIM(@c7)),
                vendor_id           = TRIM(BOTH '\\r' FROM TRIM(@c8)),
                item_type           = TRIM(BOTH '\\r' FROM TRIM(@c9)),

                distributor_price   = NULLIF(REPLACE(REPLACE(TRIM(BOTH '\\r' FROM TRIM(@c10)), '$', ''), ',', ''), ''),
                allocation_status   = TRIM(BOTH '\\r' FROM TRIM(@c11)),
                status_code         = TRIM(BOTH '\\r' FROM TRIM(@c11)),

                mge_item_number     = TRIM(BOTH '\\r' FROM TRIM(@c12)),
                drop_ship_source    = TRIM(BOTH '\\r' FROM TRIM(@c13)),
                vendor_item_number  = TRIM(BOTH '\\r' FROM TRIM(@c14)),
                row_index           = TRIM(BOTH '\\r' FROM TRIM(@c15)),

                ffl_required        = CASE
                                        WHEN UPPER(CONCAT(TRIM(BOTH '\\r' FROM TRIM(@c9)), ' ', TRIM(BOTH '\\r' FROM TRIM(@c1)), ' ', TRIM(BOTH '\\r' FROM TRIM(@c0)), ' ', TRIM(BOTH '\\r' FROM TRIM(@c2)))) REGEXP 'PISTOL|REVOLVER|RIFLE|SHOTGUN|FIREARM|RECEIVER|FRAME|LOWER|HANDGUN'
                                        THEN '1'
                                        ELSE '0'
                                      END,

                sot_required        = CASE
                                        WHEN UPPER(CONCAT(TRIM(BOTH '\\r' FROM TRIM(@c9)), ' ', TRIM(BOTH '\\r' FROM TRIM(@c1)), ' ', TRIM(BOTH '\\r' FROM TRIM(@c0)), ' ', TRIM(BOTH '\\r' FROM TRIM(@c2)))) REGEXP 'SUPPRESS|SILENCER|NFA'
                                        THEN '1'
                                        ELSE '0'
                                      END,

                dropship_enabled      = '0',
                dropship_block_reason = 'dropship_not_supported'
        ";

        $t_sql_ms = 0.0;

        try {
            $wpdb->query("TRUNCATE TABLE {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            $prepared = $wpdb->prepare($sql, $file_path);
            $t_sql_start = microtime(true);
            $result = $wpdb->query($prepared);
            $t_sql_ms = (microtime(true) - $t_sql_start) * 1000.0;

            if ($result === false) {
                $this->log_debug('[FFLHub][MGE Import][LOAD DATA] query failed: ' . (string) $wpdb->last_error);
                return -1;
            }

            $wpdb->query(
                "
                DELETE FROM {$table_name}
                WHERE
                    upc IS NULL
                    OR TRIM(upc) = ''
                    OR mge_item_number IS NULL
                    OR TRIM(mge_item_number) = ''
                "
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sig_approved_forced = SigDropshipApproval::apply_to_table('mge', $table_name);
            if ($sig_approved_forced > 0) {
                $this->log_debug(
                    sprintf('[FFLHub][MGE Import][LOAD DATA] sig_approved_forced=%d', $sig_approved_forced)
                );
            }
        } catch (\Throwable $e) {
            $this->log_debug('[FFLHub][MGE Import][LOAD DATA] exception: ' . $e->getMessage());
            return -1;
        }

        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($rows > 0) {
            update_option('fflhub_mge_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_mge_fulfillment_last_import_count', $rows, false);
        }

        $t_total_ms = (microtime(true) - $t_start) * 1000.0;

        $this->log_debug(
            sprintf(
                '[FFLHub][MGE Import] import_fulfillment_file_via_load_data(): total=%.2f ms (sql=%.2f ms), rows=%d, ignore_lines=%d',
                $t_total_ms,
                $t_sql_ms,
                $rows,
                $ignore_lines
            )
        );

        return $rows;
    }

    private function import_fulfillment_file_via_php(string $file_path): int
    {
        global $wpdb;

        $t_import_start = microtime(true);
        $t_parse_total = 0.0;
        $t_flush_total = 0.0;

        if (!file_exists($file_path) || !is_readable($file_path)) {
            return 0;
        }

        $table_name = $this->table->get_staging_table_name();

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $this->log_debug('[FFLHub][MGE Import] could not fopen ' . $file_path);
            return 0;
        }

        $wpdb->query("TRUNCATE TABLE {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($header) || empty($header)) {
            fclose($handle);
            $this->log_debug('[FFLHub][MGE Import] missing/invalid header in ' . $file_path);
            return 0;
        }

        $parser = new MGEProductParser();
        $header_map = $parser->build_header_map($header);
        if (!$parser->has_required_columns($header_map)) {
            fclose($handle);
            $this->log_debug('[FFLHub][MGE Import] header missing required columns id/barcod');
            return 0;
        }

        $batch_size = 1000;
        $batch_rows = [];
        $skipped_missing_ids = 0;
        $batch_flushes = 0;
        $batch_failures = 0;

        $columns = $this->table->get_schema()->get_insert_columns();
        $num_cols = count($columns);
        $column_list = implode(', ', $columns);
        $row_placeholder = '(' . implode(', ', array_fill(0, $num_cols, '%s')) . ')';
        $insert_prefix = 'INSERT IGNORE INTO ' . $table_name . ' (' . $column_list . ') VALUES ';

        $flush_batch = function () use (
            &$batch_rows,
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
            $values = [];

            foreach ($batch_rows as $row) {
                $placeholders[] = $row_placeholder;
                foreach ($columns as $col) {
                    $values[] = array_key_exists($col, $row) ? $row[$col] : '';
                }
            }

            $sql = $insert_prefix . implode(', ', $placeholders);
            $prepared = $wpdb->prepare($sql, $values);
            $result = $wpdb->query($prepared);

            if ($result === false) {
                $batch_failures++;
                $this->log_debug('[FFLHub][MGE Import] Batch INSERT failed: ' . (string) $wpdb->last_error);
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

            $upc = trim((string) ($row['upc'] ?? ''));
            $item = trim((string) ($row['mge_item_number'] ?? ''));
            if ($upc === '' || $item === '') {
                $skipped_missing_ids++;
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

        $wpdb->query(
            "
            DELETE FROM {$table_name}
            WHERE
                upc IS NULL
                OR TRIM(upc) = ''
                OR mge_item_number IS NULL
                OR TRIM(mge_item_number) = ''
            "
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($rows > 0) {
            update_option('fflhub_mge_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_mge_fulfillment_last_import_count', $rows, false);
        }

        $t_import_total_ms = (microtime(true) - $t_import_start) * 1000.0;
        $t_parse_ms = $t_parse_total * 1000.0;
        $t_flush_ms = $t_flush_total * 1000.0;

        $this->log_debug(
            sprintf(
                '[FFLHub][MGE Import] import_fulfillment_file_via_php(): total=%.2f ms, parse+loop=%.2f ms, db_flush=%.2f ms, rows=%d, skipped_missing_ids=%d, batch_flushes=%d, batch_failures=%d',
                $t_import_total_ms,
                $t_parse_ms,
                $t_flush_ms,
                $rows,
                $skipped_missing_ids,
                $batch_flushes,
                $batch_failures
            )
        );

        return $rows;
    }

    private function log_debug(string $message): void
    {
        DebugLogUtil::log('FFLHUB_CRON_DEBUG', '[FFLHub][MGEImporter]', $message);
    }
}
