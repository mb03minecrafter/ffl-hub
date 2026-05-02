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
    private const INVENTORY_STAGE_TABLE_SUFFIX = 'fflhub_sports_south_onhand_stage';

    private DoubleBufferedProductTable $table;
    private SportsSouthProductParser $parser;

    public function __construct(DoubleBufferedProductTable $table, ?SportsSouthProductParser $parser = null)
    {
        $this->table = $table;
        $this->parser = $parser ?: new SportsSouthProductParser();
    }

    public function import_catalog_file(string $xmlFilePath): int
    {
        $t_start = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

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
        if ($tsv_path === '' || empty($columns)) {
            return $this->import_catalog_file_via_batches($xmlFilePath, $t_start, $mem_start);
        }

        $write_stats = $this->write_catalog_tsv($xmlFilePath, $tsv_path, $columns);
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

    public function apply_onhand_delta_file_to_live(string $xmlFilePath, bool $treatQuantityAsDelta = true): array
    {
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

        $stage_table = $this->ensure_inventory_stage_table();
        if ($stage_table === '') {
            return [
                'processed_rows' => 0,
                'rows_loaded' => 0,
                'join_updated' => 0,
                'error' => 'Failed to ensure stage table',
            ];
        }

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$stage_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $rows_loaded = $this->insert_onhand_stage_rows($stage_table, $xmlFilePath);
        if ($rows_loaded <= 0) {
            return [
                'processed_rows' => 0,
                'rows_loaded' => 0,
                'join_updated' => 0,
            ];
        }

        $live_table = $this->table->get_live_table_name();
        $updated_item = $this->update_live_inventory_by_item_number($live_table, $stage_table, $treatQuantityAsDelta);
        $updated_upc = $this->update_live_inventory_by_upc($live_table, $stage_table, $treatQuantityAsDelta);
        $sig_approved_forced = SigDropshipApproval::apply_to_table('sports_south', $live_table);

        $stats = [
            'processed_rows' => (int) $rows_loaded,
            'rows_loaded' => (int) $rows_loaded,
            'join_updated' => (int) max(0, $updated_item) + (int) max(0, $updated_upc),
            'join_updated_item' => (int) max(0, $updated_item),
            'join_updated_upc' => (int) max(0, $updated_upc),
            'quantity_mode' => $treatQuantityAsDelta ? 'delta' : 'absolute',
            'sig_approved_forced' => (int) $sig_approved_forced,
        ];

        $this->log('Sports South onhand update applied.', $stats);

        return $stats;
    }

    private function import_catalog_file_via_batches(string $xmlFilePath, float $tStart, int $memStart): int
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

        $this->parser->each_catalog_row($xmlFilePath, function (array $row) use (&$batch, &$total, &$seen, &$skipped_dupes): void {
            $upc = trim((string) ($row['upc'] ?? ''));
            if ($upc === '') {
                return;
            }
            if (isset($seen[$upc])) {
                $skipped_dupes++;
                return;
            }
            $seen[$upc] = true;

            $batch[] = SigDropshipApproval::apply_to_row('sports_south', $row);
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
            'elapsed_ms' => number_format((microtime(true) - $tStart) * 1000.0, 2, '.', ''),
            'memory_start_kb' => $memStart > 0 ? (int) round($memStart / 1024) : 0,
        ]);

        return (int) $total;
    }

    /**
     * @param string[] $columns
     * @return array<string,mixed>
     */
    private function write_catalog_tsv(string $xmlFilePath, string $tsvPath, array $columns): array
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
        $seen = [];

        $this->parser->each_catalog_row($xmlFilePath, function (array $row) use ($handle, $columns, &$rows_written, &$skipped_dupes, &$seen): void {
            $upc = trim((string) ($row['upc'] ?? ''));
            if ($upc === '') {
                return;
            }
            if (isset($seen[$upc])) {
                $skipped_dupes++;
                return;
            }
            $seen[$upc] = true;

            $row = SigDropshipApproval::apply_to_row('sports_south', $row);

            $values = [];
            foreach ($columns as $column) {
                $values[] = array_key_exists($column, $row) ? (string) $row[$column] : '';
            }
            fputcsv($handle, $values, "\t", '"', '\\');
            $rows_written++;
        });

        fclose($handle);
        clearstatcache(true, $tsvPath);

        return [
            'xml_path' => $xmlFilePath,
            'tsv_path' => $tsvPath,
            'tsv_bytes' => file_exists($tsvPath) ? (int) filesize($tsvPath) : 0,
            'rows_written' => (int) $rows_written,
            'skipped_dupes' => (int) $skipped_dupes,
            'write_ms' => number_format((microtime(true) - $t_start) * 1000.0, 2, '.', ''),
        ];
    }

    /**
     * @param string[] $columns
     */
    private function import_tsv_into_staging(string $tsvPath, array $columns): int
    {
        if (!is_readable($tsvPath)) {
            return 0;
        }

        if ($this->can_use_load_data_local_infile()) {
            $rows = $this->import_tsv_via_load_data($tsvPath, $columns);
            if ($rows >= 0) {
                return $rows;
            }
        }

        return $this->import_tsv_via_php($tsvPath, $columns);
    }

    /**
     * @param string[] $columns
     */
    private function import_tsv_via_load_data(string $tsvPath, array $columns): int
    {
        global $wpdb;

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
            $this->table->truncate_staging();
            $result = $wpdb->query($wpdb->prepare($sql, $tsvPath));
            if ($result === false) {
                $this->log('Sports South LOAD DATA query failed.', [
                    'error' => (string) $wpdb->last_error,
                    'tsv_path' => $tsvPath,
                ]);
                return -1;
            }

            $wpdb->query("DELETE FROM {$table_name} WHERE upc IS NULL OR upc = '' OR LOWER(upc) = 'null'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            SigDropshipApproval::apply_to_table('sports_south', $table_name);
        } catch (\Throwable $e) {
            $this->log('Sports South LOAD DATA exception.', [
                'error' => $e->getMessage(),
                'tsv_path' => $tsvPath,
            ]);
            return -1;
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * @param string[] $columns
     */
    private function import_tsv_via_php(string $tsvPath, array $columns): int
    {
        $handle = fopen($tsvPath, 'r');
        if (!$handle) {
            return 0;
        }

        try {
            $this->table->truncate_staging();
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
                $total += $this->flush_staging_batch($batch);
                $batch = [];
            }
        }

        fclose($handle);

        if (!empty($batch)) {
            $total += $this->flush_staging_batch($batch);
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
        global $wpdb;

        $batch_size = 500;
        $values = [];
        $placeholders = [];
        $loaded = 0;

        $flush = function () use (&$values, &$placeholders, &$loaded, $stageTable, $wpdb): void {
            if (empty($placeholders)) {
                return;
            }

            $sql = "INSERT INTO {$stageTable} (item_number, upc, quantity_delta, catalog_price, customer_price) VALUES " . implode(', ', $placeholders);
            $result = $wpdb->query($wpdb->prepare($sql, $values));
            if ($result !== false) {
                $loaded += (int) $result;
            } else {
                $this->log('ERROR: Sports South onhand stage insert failed: ' . (string) $wpdb->last_error);
            }

            $values = [];
            $placeholders = [];
        };

        $this->parser->each_onhand_row($xmlFilePath, function (array $row) use (&$values, &$placeholders, $batch_size, $flush): void {
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

        return (int) $loaded;
    }

    private function update_live_inventory_by_item_number(string $liveTable, string $stageTable, bool $treatQuantityAsDelta): int
    {
        global $wpdb;

        $qty_expression = $treatQuantityAsDelta
            ? "GREATEST(CAST(COALESCE(NULLIF(L.inventory_quantity, ''), '0') AS SIGNED) + S.quantity_delta, 0)"
            : 'GREATEST(S.quantity_delta, 0)';

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
                S.quantity_delta <> 0
                OR (S.customer_price <> '' AND COALESCE(L.distributor_price, '') <> S.customer_price)
                OR (S.catalog_price <> '' AND COALESCE(L.catalog_price, '') <> S.catalog_price)
        ";

        $result = $wpdb->query($wpdb->prepare($sql, gmdate('Y-m-d H:i:s'))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return is_numeric($result) ? (int) $result : 0;
    }

    private function update_live_inventory_by_upc(string $liveTable, string $stageTable, bool $treatQuantityAsDelta): int
    {
        global $wpdb;

        $qty_expression = $treatQuantityAsDelta
            ? "GREATEST(CAST(COALESCE(NULLIF(L.inventory_quantity, ''), '0') AS SIGNED) + S.quantity_delta, 0)"
            : 'GREATEST(S.quantity_delta, 0)';

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
                    S.quantity_delta <> 0
                    OR (S.customer_price <> '' AND COALESCE(L.distributor_price, '') <> S.customer_price)
                    OR (S.catalog_price <> '' AND COALESCE(L.catalog_price, '') <> S.catalog_price)
                )
        ";

        $result = $wpdb->query($wpdb->prepare($sql, gmdate('Y-m-d H:i:s'))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return is_numeric($result) ? (int) $result : 0;
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
