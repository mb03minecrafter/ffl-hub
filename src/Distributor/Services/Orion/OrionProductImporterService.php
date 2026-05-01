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
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $inventory_lookup = $this->parser->build_inventory_lookup($inventoryResponseOrRows);

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

            $row = $this->parser->parse_product($product, $inventory_lookup);
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
            'products_in' => count($products),
            'rows_inserted' => (int) $total_inserted,
            'skipped_missing_upc' => (int) $skipped_missing_upc,
            'skipped_dupe_upc' => (int) $skipped_dupe_upc,
        ]);

        return (int) $total_inserted;
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
