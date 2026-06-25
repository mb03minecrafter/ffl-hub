<?php

namespace FFLHub\Distributor\Services\BillHicks;

use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Imports the Bill Hicks full catalog CSV into the double-buffered staging table.
 */
final class BillHicksProductImporterService
{
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][BillHicksImporter]';

    private DoubleBufferedProductTable $table;
    private BillHicksProductParser $parser;

    public function __construct(DoubleBufferedProductTable $table, ?BillHicksProductParser $parser = null)
    {
        $this->table = $table;
        $this->parser = $parser ?: new BillHicksProductParser();
    }

    public function import_file(string $file_path): int
    {
        if (!is_readable($file_path)) {
            $this->log('Bill Hicks catalog file missing or unreadable.', [
                'file_path' => $file_path,
            ]);
            return 0;
        }

        if ($this->can_use_load_data_local_infile()) {
            $rows = $this->import_file_via_load_data($file_path);
            if ($rows >= 0) {
                return $rows;
            }

            $this->log('Bill Hicks LOAD DATA path failed; falling back to PHP CSV importer.');
        }

        return $this->import_file_via_php($file_path);
    }

    private function import_file_via_load_data(string $file_path): int
    {
        global $wpdb;

        $started = microtime(true);
        $table_name = $this->table->get_staging_table_name();
        $trim = static function (string $value): string {
            return "TRIM(BOTH '\\r' FROM TRIM({$value}))";
        };

        $clean_upc = "REGEXP_REPLACE({$trim('@c1')}, '[^0-9]', '')";
        $clean_price = "NULLIF(REGEXP_REPLACE({$trim('@c6')}, '[^0-9.\\\\-]', ''), '')";
        $price_decimal = "CAST(COALESCE({$clean_price}, '0') AS DECIMAL(12,4))";
        $clean_map = "NULLIF(REGEXP_REPLACE({$trim('@c9')}, '[^0-9.\\\\-]', ''), '')";
        $clean_msrp = "NULLIF(REGEXP_REPLACE({$trim('@c10')}, '[^0-9.\\\\-]', ''), '')";
        $category_code = "UPPER({$trim('@c4')})";
        $manufacturer_norm = "REGEXP_REPLACE(UPPER(REPLACE({$trim('@c7')}, '&', 'AND')), '[^A-Z0-9]+', '')";

        $sql = "
            LOAD DATA LOCAL INFILE %s
            IGNORE INTO TABLE {$table_name}
            CHARACTER SET utf8mb4
            FIELDS
                TERMINATED BY ','
                ENCLOSED BY '\"'
                ESCAPED BY '\\\\'
            LINES TERMINATED BY '\\n'
            IGNORE 1 LINES
            (
                @c0, @c1, @c2, @c3, @c4, @c5, @c6, @c7, @c8, @c9, @c10
            )
            SET
                bill_hicks_item_number = {$trim('@c0')},
                upc = {$clean_upc},
                manufacturer_number = '',

                inventory_quantity = '0',
                allocation_status = 'out_of_stock',
                distributor_price = {$clean_price},
                shipping_cost = CASE
                    WHEN {$price_decimal} >= 500 THEN '0'
                    WHEN {$category_code} = 'H602' THEN '20'
                    ELSE '15'
                END,
                retail_map = CASE WHEN {$clean_map} IS NULL THEN '0' ELSE {$clean_map} END,
                retail_msrp = CASE WHEN {$clean_msrp} IS NULL THEN '0' ELSE {$clean_msrp} END,

                product_name = {$trim('@c2')},
                product_description = CASE
                    WHEN {$trim('@c3')} <> '' THEN {$trim('@c3')}
                    ELSE {$trim('@c2')}
                END,
                manufacturer = {$trim('@c7')},
                manufacturer_norm = {$manufacturer_norm},
                model = '',
                caliber_gauge = '',
                item_type = {$trim('@c5')},
                category = {$category_code},
                image_url = '',

                shipping_weight = CASE
                    WHEN NULLIF(REGEXP_REPLACE({$trim('@c8')}, '[^0-9.\\\\-]', ''), '') IS NULL THEN NULL
                    ELSE ROUND(CAST(REGEXP_REPLACE({$trim('@c8')}, '[^0-9.\\\\-]', '') AS DECIMAL(10,4)) * 16, 2)
                END,
                shipping_length = NULL,
                shipping_width = NULL,
                shipping_height = NULL,

                ffl_required = CASE
                    WHEN {$category_code} IN ('H600','H601','H602','H603','H605','H606','H607','H608') THEN '1'
                    ELSE '0'
                END,
                sot_required = CASE
                    WHEN {$category_code} IN ('H606','H607','H608') THEN '1'
                    ELSE '0'
                END,
                dropship_enabled = '1',
                dropship_block_reason = '',

                status_code = '',
                last_change_date = '',
                last_change_time = ''
        ";

        try {
            $t_truncate = microtime(true);
            $truncate = $wpdb->query("TRUNCATE TABLE {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if ($truncate === false) {
                throw new \RuntimeException('Failed to truncate staging table: ' . (string) $wpdb->last_error);
            }

            $prepared = $wpdb->prepare($sql, $file_path);
            $t_load = microtime(true);
            $loaded = $wpdb->query($prepared); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if ($loaded === false) {
                throw new \RuntimeException('LOAD DATA failed: ' . (string) $wpdb->last_error);
            }

            $t_delete_blank = microtime(true);
            $deleted_blank = $wpdb->query("DELETE FROM {$table_name} WHERE upc = '' OR upc IS NULL"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if ($deleted_blank === false) {
                throw new \RuntimeException('Blank UPC cleanup failed: ' . (string) $wpdb->last_error);
            }

            $t_policy = microtime(true);
            $policy_rows = BillHicksFulfillmentPolicy::apply_to_table($table_name);

            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->log('Bill Hicks catalog imported via LOAD DATA.', [
                'stage_table' => $table_name,
                'load_affected_rows' => is_numeric($loaded) ? (int) $loaded : 0,
                'rows_inserted' => $count,
                'blank_upcs_deleted' => is_numeric($deleted_blank) ? (int) $deleted_blank : 0,
                'policy_rows_touched' => (int) $policy_rows,
                'truncate_ms' => $this->format_ms((microtime(true) - $t_truncate) * 1000.0),
                'load_ms' => $this->format_ms((microtime(true) - $t_load) * 1000.0),
                'blank_cleanup_ms' => $this->format_ms((microtime(true) - $t_delete_blank) * 1000.0),
                'policy_ms' => $this->format_ms((microtime(true) - $t_policy) * 1000.0),
                'elapsed_ms' => $this->format_ms((microtime(true) - $started) * 1000.0),
            ]);

            return $count;
        } catch (\Throwable $e) {
            $this->log('Bill Hicks LOAD DATA import failed.', [
                'error' => $e->getMessage(),
                'file_path' => $file_path,
            ]);
            return -1;
        }
    }

    private function import_file_via_php(string $file_path): int
    {
        global $wpdb;

        $started = microtime(true);
        $table_name = $this->table->get_staging_table_name();
        $columns = $this->table->get_schema()->get_insert_columns();
        $column_sql = implode(', ', $columns);
        $row_placeholder = '(' . implode(', ', array_fill(0, count($columns), '%s')) . ')';
        $insert_prefix = "INSERT IGNORE INTO {$table_name} ({$column_sql}) VALUES ";

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return 0;
        }

        $wpdb->query("TRUNCATE TABLE {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // Header row.
        fgetcsv($handle, 0, ',', '"', '\\');

        $total = 0;
        $batch_rows = [];
        $flush = function () use (&$batch_rows, &$total, $columns, $row_placeholder, $insert_prefix, $wpdb): void {
            if (empty($batch_rows)) {
                return;
            }

            $placeholders = [];
            $values = [];
            foreach ($batch_rows as $row) {
                $placeholders[] = $row_placeholder;
                foreach ($columns as $column) {
                    $values[] = array_key_exists($column, $row) ? $row[$column] : '';
                }
            }

            $result = $wpdb->query($wpdb->prepare($insert_prefix . implode(', ', $placeholders), $values)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if (is_numeric($result)) {
                $total += (int) $result;
            }

            $batch_rows = [];
        };

        while (($csv = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $row = $this->parser->parse_row($csv);
            if (!is_array($row)) {
                continue;
            }

            $batch_rows[] = $row;
            if (count($batch_rows) >= 500) {
                $flush();
            }
        }

        fclose($handle);
        $flush();

        $this->log('Bill Hicks catalog imported via PHP fallback.', [
            'stage_table' => $table_name,
            'rows_inserted' => (int) $total,
            'elapsed_ms' => $this->format_ms((microtime(true) - $started) * 1000.0),
        ]);

        return $total;
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
        $this->log('Bill Hicks LOAD DATA LOCAL INFILE capability check.', [
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

    private function format_ms(float $ms): string
    {
        return number_format($ms, 2, '.', '');
    }

    /**
     * @param array<string,mixed> $context
     */
    private function log(string $message, array $context = []): void
    {
        if (empty($context)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $message);
            return;
        }

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $message, $context);
    }
}
