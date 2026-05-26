<?php

namespace FFLHub\Distributor\Services\Orion;

use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Imports Orion catalog rows into staging and applies lightweight inventory
 * updates to the live table.
 */
final class OrionProductImporterService
{
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][OrionImporter]';
    private const INVENTORY_STAGE_TABLE_SUFFIX = 'fflhub_orion_inventory_stage';

    private DoubleBufferedProductTable $table;
    private OrionProductParser $parser;

    public function __construct(DoubleBufferedProductTable $table, ?OrionProductParser $parser = null)
    {
        $this->table = $table;
        $this->parser = $parser ?: new OrionProductParser();
    }

    /**
     * @param array<int,array<string,mixed>> $products
     * @param array<string,mixed> $inventoryResponseOrRows
     */
    public function import_products_array(array $products, array $inventoryResponseOrRows = []): int
    {
        $t_start = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $inventory_lookup = $this->parser->build_inventory_lookup($inventoryResponseOrRows);
        $columns = $this->table->get_schema()->get_insert_columns();
        $tsv_path = $this->resolve_catalog_tsv_path();

        $this->log('Orion product import start.', [
            'products_in' => count($products),
            'inventory_lookup_rows' => count($inventory_lookup),
            'column_count' => count($columns),
            'memory_kb' => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
            'tsv_path' => (string) $tsv_path,
        ]);

        if ($tsv_path === '' || empty($columns)) {
            $this->log('Orion product import falling back to batched inserts; TSV path or columns unavailable.', [
                'tsv_path' => (string) $tsv_path,
                'column_count' => count($columns),
            ]);

            return $this->import_products_array_via_batches($products, $inventory_lookup, $t_start, $mem_start);
        }

        $write_stats = $this->write_catalog_tsv($products, $inventory_lookup, $columns, $tsv_path);
        if ((int) ($write_stats['rows_written'] ?? 0) <= 0) {
            $this->log('Orion product import wrote zero TSV rows; not loading.', $write_stats);
            return 0;
        }

        $count = $this->import_from_tsv_file($tsv_path, $columns);

        if ($count > 0) {
            update_option('fflhub_orion_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_orion_fulfillment_last_import_count', (int) $count, false);
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

        $this->log('Orion product import complete.', $ctx);

        return (int) $count;
    }

    /**
     * Snapshot the current live inventory values so catalog imports can stay
     * catalog-only while preserving the most recently refreshed stock state.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_live_inventory_rows(): array
    {
        global $wpdb;

        $live_table = $this->table->get_live_table_name();
        if ($live_table === '') {
            return [];
        }

        $sql = "
            SELECT
                orion_product_id AS product_id,
                orion_product_code AS product_code,
                inventory_quantity AS quantity,
                sale_price
            FROM {$live_table}
            WHERE
                COALESCE(orion_product_id, '') <> ''
                OR COALESCE(orion_product_code, '') <> ''
        ";

        $rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, static function ($row): bool {
            return is_array($row);
        }));
    }

    /**
     * @param array<int,array<string,mixed>> $products
     * @param array<string,array<string,mixed>> $inventoryLookup
     */
    private function import_products_array_via_batches(
        array $products,
        array $inventoryLookup,
        float $t_start,
        int $mem_start
    ): int {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        try {
            $this->table->truncate_staging();
        } catch (\Throwable $e) {
            $this->log('ERROR: truncate_staging() failed: ' . $e->getMessage());
            return 0;
        }

        $batch_size = 500;
        $batch_rows = [];
        $total_inserted = 0;
        $skipped_missing_upc = 0;
        $skipped_dupe_upc = 0;
        $seen_upcs = [];

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $row = $this->parser->parse_product($product, $inventoryLookup);
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

            $batch_rows[] = SigDropshipApproval::apply_to_row('orion', $row);

            if (count($batch_rows) >= $batch_size) {
                $total_inserted += $this->flush_staging_batch($batch_rows);
                $batch_rows = [];
            }
        }

        if (!empty($batch_rows)) {
            $total_inserted += $this->flush_staging_batch($batch_rows);
        }

        if ($total_inserted > 0) {
            update_option('fflhub_orion_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_orion_fulfillment_last_import_count', (int) $total_inserted, false);
        }

        $this->log('Orion product import complete.', [
            'mode' => 'batched_insert_fallback',
            'products_in' => count($products),
            'rows_inserted' => (int) $total_inserted,
            'skipped_missing_upc' => (int) $skipped_missing_upc,
            'skipped_dupe_upc' => (int) $skipped_dupe_upc,
            'elapsed_ms' => number_format((microtime(true) - $t_start) * 1000.0, 2, '.', ''),
            'memory_start_kb' => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
        ]);

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
            $this->log('Orion TSV import file missing/unreadable.', [
                'file_path' => $file_path,
            ]);
            return 0;
        }

        if ($this->can_use_load_data_local_infile()) {
            $rows = $this->import_tsv_via_load_data($file_path, $columns);
            if ($rows >= 0) {
                return $rows;
            }

            $this->log('Orion LOAD DATA path failed; falling back to PHP TSV batching.', [
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
            $this->table->truncate_staging();

            $prepared = $wpdb->prepare($sql, $file_path);
            $result = $wpdb->query($prepared);
            if ($result === false) {
                $this->log('Orion LOAD DATA query failed.', [
                    'error' => (string) $wpdb->last_error,
                    'file_path' => $file_path,
                ]);
                return -1;
            }

            $wpdb->query("DELETE FROM {$table_name} WHERE upc IS NULL OR upc = '' OR LOWER(upc) = 'null'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sig_approved_forced = SigDropshipApproval::apply_to_table('orion', $table_name);
        } catch (\Throwable $e) {
            $this->log('Orion LOAD DATA exception.', [
                'error' => $e->getMessage(),
                'file_path' => $file_path,
            ]);
            return -1;
        }

        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $this->log('Orion TSV loaded via LOAD DATA LOCAL INFILE.', [
            'file_path' => $file_path,
            'rows' => (int) $rows,
            'sig_approved_forced' => (int) $sig_approved_forced,
            'elapsed_ms' => number_format((microtime(true) - $t_start) * 1000.0, 2, '.', ''),
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
            $this->log('Orion PHP TSV fallback fopen failed.', [
                'file_path' => $file_path,
            ]);
            return 0;
        }

        try {
            $this->table->truncate_staging();
        } catch (\Throwable $e) {
            fclose($handle);
            $this->log('Orion PHP TSV fallback truncate failed.', [
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

        $this->log('Orion TSV imported via PHP fallback.', [
            'file_path' => $file_path,
            'lines_seen' => (int) $line_count,
            'rows_inserted' => (int) $inserted_total,
            'elapsed_ms' => number_format((microtime(true) - $t_start) * 1000.0, 2, '.', ''),
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
                'rows_written' => 0,
                'skipped_missing_upc' => 0,
                'skipped_dupe_upc' => 0,
                'write_error' => 'fopen failed',
            ];
        }

        $rows_written = 0;
        $skipped_missing_upc = 0;
        $skipped_dupe_upc = 0;
        $seen_upcs = [];

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $row = $this->parser->parse_product($product, $inventoryLookup);
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

            $row = SigDropshipApproval::apply_to_row('orion', $row);

            $values = [];
            foreach ($columns as $column) {
                $values[] = array_key_exists($column, $row) ? (string) $row[$column] : '';
            }

            fputcsv($handle, $values, "\t", '"', '\\');
            $rows_written++;
        }

        fclose($handle);

        clearstatcache(true, $tsvPath);

        return [
            'tsv_path' => $tsvPath,
            'tsv_bytes' => file_exists($tsvPath) ? (int) filesize($tsvPath) : 0,
            'products_in' => count($products),
            'rows_written' => (int) $rows_written,
            'skipped_missing_upc' => (int) $skipped_missing_upc,
            'skipped_dupe_upc' => (int) $skipped_dupe_upc,
            'write_ms' => number_format((microtime(true) - $t_start) * 1000.0, 2, '.', ''),
        ];
    }

    private function resolve_catalog_tsv_path(): string
    {
        $uploads = wp_upload_dir();
        $base_dir = rtrim((string) ($uploads['basedir'] ?? ''), '/\\');
        if ($base_dir === '') {
            return '';
        }

        $dir = $base_dir . '/fflhub/orion';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            $this->log('Failed to create Orion catalog TSV directory.', [
                'dir' => $dir,
            ]);
            return '';
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            $this->log('Orion catalog TSV directory is not writable.', [
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

        $this->log('Orion LOAD DATA LOCAL INFILE capability check.', [
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
     * @param array<string,mixed> $inventoryResponseOrRows
     * @return array<string,mixed>
     */
    public function apply_inventory_array_to_live(array $inventoryResponseOrRows): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $rows = $this->parser->normalize_inventory_rows($inventoryResponseOrRows);
        if (empty($rows)) {
            return [
                'processed_rows' => 0,
                'rows_loaded' => 0,
                'join_updated' => 0,
                'sig_approved_forced' => 0,
            ];
        }

        global $wpdb;

        $stage_table = $this->ensure_inventory_stage_table();
        if ($stage_table === '') {
            return [
                'processed_rows' => 0,
                'rows_loaded' => 0,
                'join_updated' => 0,
                'sig_approved_forced' => 0,
            ];
        }

        $wpdb->query("TRUNCATE TABLE {$stage_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $rows_loaded = $this->insert_inventory_stage_rows($stage_table, $rows);
        if ($rows_loaded <= 0) {
            return [
                'processed_rows' => count($rows),
                'rows_loaded' => 0,
                'join_updated' => 0,
                'sig_approved_forced' => 0,
            ];
        }

        $live_table = $this->table->get_live_table_name();

        $join_updated_id = $this->update_live_inventory_by_product_id($live_table, $stage_table);
        $join_updated_code = $this->update_live_inventory_by_product_code($live_table, $stage_table);
        $sig_approved_forced = SigDropshipApproval::apply_to_table('orion', $live_table);

        $join_updated = max(0, $join_updated_id) + max(0, $join_updated_code);

        update_option('fflhub_orion_inventory_last_update', current_time('mysql'), false);
        update_option('fflhub_orion_inventory_last_update_count', (int) $rows_loaded, false);

        return [
            'processed_rows' => count($rows),
            'rows_loaded' => (int) $rows_loaded,
            'join_updated' => (int) $join_updated,
            'join_updated_id' => (int) max(0, $join_updated_id),
            'join_updated_code' => (int) max(0, $join_updated_code),
            'sig_approved_forced' => (int) $sig_approved_forced,
        ];
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
                product_id VARCHAR(64) NOT NULL DEFAULT '',
                product_code VARCHAR(128) NOT NULL DEFAULT '',
                quantity INT UNSIGNED NOT NULL DEFAULT 0,
                sale_price VARCHAR(32) NULL,
                PRIMARY KEY (id),
                KEY product_id (product_id),
                KEY product_code (product_code)
            ) {$charset};
        ";

        $created = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($created === false) {
            $this->log('ERROR: failed to ensure Orion inventory stage table: ' . (string) $wpdb->last_error);
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

            $sql = "INSERT INTO {$stageTable} (product_id, product_code, quantity, sale_price) VALUES " . implode(', ', $placeholders);
            $result = $wpdb->query($wpdb->prepare($sql, $values));
            if ($result !== false) {
                $loaded += (int) $result;
            } else {
                $this->log('ERROR: Orion inventory stage insert failed: ' . (string) $wpdb->last_error);
            }

            $values = [];
            $placeholders = [];
        };

        foreach ($rows as $row) {
            $product_id = trim((string) ($row['product_id'] ?? ''));
            $product_code = trim((string) ($row['product_code'] ?? ''));
            if ($product_id === '' && $product_code === '') {
                continue;
            }

            $qty = max(0, (int) ($row['quantity'] ?? 0));
            $sale_price = $this->money_string((string) ($row['sale_price'] ?? ''));

            $placeholders[] = '(%s, %s, %d, %s)';
            $values[] = $product_id;
            $values[] = $product_code;
            $values[] = $qty;
            $values[] = $sale_price;

            if (count($placeholders) >= $batch_size) {
                $flush();
            }
        }

        $flush();

        return (int) $loaded;
    }

    private function update_live_inventory_by_product_id(string $liveTable, string $stageTable): int
    {
        global $wpdb;

        $sql = "
            UPDATE {$liveTable} L
            INNER JOIN {$stageTable} S
                ON S.product_id <> '' AND L.orion_product_id = S.product_id
            SET
                L.inventory_quantity = CAST(S.quantity AS CHAR),
                L.allocation_status = CASE WHEN S.quantity > 0 THEN 'in_stock' ELSE 'out_of_stock' END,
                L.sale_price = S.sale_price,
                L.distributor_price = CASE WHEN S.sale_price <> '' THEN S.sale_price ELSE L.distributor_price END
            WHERE
                COALESCE(L.inventory_quantity, '') <> CAST(S.quantity AS CHAR)
                OR COALESCE(L.allocation_status, '') <> CASE WHEN S.quantity > 0 THEN 'in_stock' ELSE 'out_of_stock' END
                OR COALESCE(L.sale_price, '') <> COALESCE(S.sale_price, '')
                OR (S.sale_price <> '' AND COALESCE(L.distributor_price, '') <> S.sale_price)
        ";

        $result = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return is_numeric($result) ? (int) $result : 0;
    }

    private function update_live_inventory_by_product_code(string $liveTable, string $stageTable): int
    {
        global $wpdb;

        $sql = "
            UPDATE {$liveTable} L
            INNER JOIN {$stageTable} S
                ON S.product_code <> '' AND L.orion_product_code = S.product_code
            LEFT JOIN {$stageTable} SI
                ON SI.product_id <> '' AND L.orion_product_id = SI.product_id
            SET
                L.inventory_quantity = CAST(S.quantity AS CHAR),
                L.allocation_status = CASE WHEN S.quantity > 0 THEN 'in_stock' ELSE 'out_of_stock' END,
                L.sale_price = S.sale_price,
                L.distributor_price = CASE WHEN S.sale_price <> '' THEN S.sale_price ELSE L.distributor_price END
            WHERE
                SI.id IS NULL
                AND (
                    COALESCE(L.inventory_quantity, '') <> CAST(S.quantity AS CHAR)
                    OR COALESCE(L.allocation_status, '') <> CASE WHEN S.quantity > 0 THEN 'in_stock' ELSE 'out_of_stock' END
                    OR COALESCE(L.sale_price, '') <> COALESCE(S.sale_price, '')
                    OR (S.sale_price <> '' AND COALESCE(L.distributor_price, '') <> S.sale_price)
                )
        ";

        $result = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return is_numeric($result) ? (int) $result : 0;
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
