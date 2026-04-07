<?php

namespace FFLHub\Distributor\Services\CSSI\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\CSSI\API\CSSIClient;
use FFLHub\Distributor\Services\CSSI\CSSIProductParser;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * 5-minute CSSI pricing/quantity refresh using GET /items pagination.
 */
final class CSSIInventoryCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_cssi_pricing_quantity_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][CSSIInventoryCron]';
    private const AUTH_ONLY_MODE = true;
    private const STAGE_TABLE_SUFFIX = 'fflhub_cssi_pq_stage';
    private const PER_PAGE = 50;

    public function __construct(DoubleBufferedProductTable $table)
    {
        parent::__construct($table);
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_interval_seconds(): int
    {
        return 5 * MINUTE_IN_SECONDS;
    }

    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    protected function get_initial_delay_seconds(): int
    {
        return 2 * MINUTE_IN_SECONDS;
    }

    public function run(): void
    {
        $tStart = microtime(true);
        $memStart = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        $runId = substr(sha1((string) microtime(true) . '|' . mt_rand()), 0, 10);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        update_option('fflhub_cssi_inventory_last_run', current_time('mysql'));

        $this->log('---- RUN START ----', [
            'run_id' => $runId,
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'memory_kb' => $memStart > 0 ? (int) round($memStart / 1024) : 0,
            'hook' => self::CRON_HOOK,
            'interval_seconds' => (int) $this->get_interval_seconds(),
            'group' => $this->get_action_group(),
            'per_page' => self::PER_PAGE,
        ]);

        $tCreds = microtime(true);
        $creds = $this->get_api_credentials();
        $this->profile('resolve API credentials', $tCreds, [
            'ok' => is_array($creds) ? 1 : 0,
            'sid_prefix' => is_array($creds) ? $this->mask_sid((string) ($creds['sid'] ?? '')) : '[missing]',
        ]);
        if (!is_array($creds)) {
            update_option('fflhub_cssi_inventory_last_download_error', current_time('mysql'));
            $this->finalize_run($tStart, $memStart, 'ERROR (missing credentials)', ['run_id' => $runId]);
            return;
        }

        $this->log('AUTH ONLY MODE enabled - skipping inventory apply and probing auth endpoint only.', [
            'run_id' => $runId,
            'auth_only_mode' => self::AUTH_ONLY_MODE ? 1 : 0,
        ]);

        $client = new CSSIClient((string) $creds['sid'], (string) $creds['token']);

        $tFetch = microtime(true);
        $probe = $client->get_items_page(1, 1);
        $probeOk = (bool) ($probe['ok'] ?? false);
        $items = is_array($probe['items'] ?? null) ? (array) $probe['items'] : [];
        $pagination = is_array($probe['pagination'] ?? null) ? (array) $probe['pagination'] : [];
        $this->profile('auth probe: GET /items', $tFetch, [
            'ok' => $probeOk ? 1 : 0,
            'status' => (int) ($probe['status'] ?? 0),
            'error' => $probeOk ? '' : (string) ($probe['error'] ?? 'Unknown error'),
            'items_count' => count($items),
            'page' => (int) ($pagination['page'] ?? 0),
            'page_count' => (int) ($pagination['page_count'] ?? 0),
        ]);

        if (!$probeOk) {
            update_option('fflhub_cssi_inventory_last_download_error', current_time('mysql'));
            $this->log('ERROR: CSSI auth probe failed on GET /items.', [
                'status' => (int) ($probe['status'] ?? 0),
                'error' => (string) ($probe['error'] ?? 'Unknown error'),
                'run_id' => $runId,
            ]);
            $this->finalize_run($tStart, $memStart, 'ERROR (auth probe)', ['run_id' => $runId]);
            return;
        }

        update_option('fflhub_cssi_inventory_last_download', current_time('mysql'));
        update_option('fflhub_cssi_inventory_last_download_ts', (string) time());
        update_option('fflhub_cssi_inventory_last_download_count', count($items));
        update_option('fflhub_cssi_inventory_last_download_pages', (int) ($pagination['page_count'] ?? 1));
        update_option('fflhub_cssi_inventory_last_update', current_time('mysql'));
        update_option('fflhub_cssi_inventory_last_update_count', 0);
        delete_option('fflhub_cssi_inventory_last_download_error');
        delete_option('fflhub_cssi_inventory_last_update_error');

        $this->log('CSSI inventory auth probe complete (auth-only mode).', [
            'run_id' => $runId,
            'items_ok' => $probeOk ? 1 : 0,
            'status' => (int) ($probe['status'] ?? 0),
            'items_count' => count($items),
        ]);

        $this->finalize_run($tStart, $memStart, 'SUCCESS', [
            'run_id' => $runId,
            'auth_only_mode' => self::AUTH_ONLY_MODE ? 1 : 0,
            'items_ok' => $probeOk ? 1 : 0,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function fetch_inventory_rows(CSSIClient $client, string $runId): array
    {
        $parser = new CSSIProductParser();

        $rowsByKey = [];
        $page = 1;
        $pagesSeen = 0;

        $itemsSeen = 0;
        $rowsParsed = 0;
        $parseSkipped = 0;
        $rowsNoKey = 0;
        $dedupeReplaced = 0;

        while (true) {
            $tPage = microtime(true);
            $res = $client->get_items_page($page, self::PER_PAGE);

            if (!(bool) ($res['ok'] ?? false)) {
                $this->profile('fetch page failed', $tPage, [
                    'run_id' => $runId,
                    'page' => $page,
                    'status' => (int) ($res['status'] ?? 0),
                    'error' => (string) ($res['error'] ?? 'Failed to fetch CSSI items page.'),
                ]);

                return [
                    'ok' => false,
                    'status' => (int) ($res['status'] ?? 0),
                    'error' => (string) ($res['error'] ?? 'Failed to fetch CSSI items page.'),
                    'page' => $page,
                    'pages' => $pagesSeen,
                    'items_seen' => $itemsSeen,
                    'rows_parsed' => $rowsParsed,
                    'rows_no_key' => $rowsNoKey,
                    'parse_skipped' => $parseSkipped,
                    'dedupe_replaced' => $dedupeReplaced,
                ];
            }

            $items = isset($res['items']) && is_array($res['items']) ? (array) $res['items'] : [];
            $pagination = isset($res['pagination']) && is_array($res['pagination']) ? (array) $res['pagination'] : [];
            $pageCount = max(1, (int) ($pagination['page_count'] ?? $page));

            $pageParsed = 0;
            $pageSkipped = 0;
            $pageNoKey = 0;
            $pageDedupe = 0;

            foreach ($items as $item) {
                if (!is_array($item)) {
                    $pageSkipped++;
                    $parseSkipped++;
                    continue;
                }

                $row = $parser->parse_api_item($item);
                if (!is_array($row)) {
                    $pageSkipped++;
                    $parseSkipped++;
                    continue;
                }

                $rowsParsed++;
                $pageParsed++;

                $key = $this->stage_row_key($row);
                if ($key === '') {
                    $pageNoKey++;
                    $rowsNoKey++;
                    continue;
                }

                if (isset($rowsByKey[$key])) {
                    $pageDedupe++;
                    $dedupeReplaced++;
                }

                $rowsByKey[$key] = $row;
            }

            $itemsSeen += count($items);
            $pagesSeen = max($pagesSeen, $page);

            $this->profile('fetch page', $tPage, [
                'run_id' => $runId,
                'page' => $page,
                'page_count' => $pageCount,
                'raw_items' => count($items),
                'parsed_rows' => $pageParsed,
                'parse_skipped' => $pageSkipped,
                'rows_no_key' => $pageNoKey,
                'dedupe_replaced' => $pageDedupe,
                'accumulated_unique_rows' => count($rowsByKey),
            ]);

            if (empty($items) || $page >= $pageCount) {
                break;
            }

            $page++;
            if ($page > 2000) {
                $this->log('ERROR: Exceeded CSSI pagination safety limit.', [
                    'run_id' => $runId,
                    'page' => $page,
                    'pages_seen' => $pagesSeen,
                    'row_count' => count($rowsByKey),
                ]);

                return [
                    'ok' => false,
                    'status' => 0,
                    'error' => 'Exceeded CSSI pagination safety limit.',
                    'page' => $page,
                    'pages' => $pagesSeen,
                    'items_seen' => $itemsSeen,
                    'rows_parsed' => $rowsParsed,
                    'rows_no_key' => $rowsNoKey,
                    'parse_skipped' => $parseSkipped,
                    'dedupe_replaced' => $dedupeReplaced,
                ];
            }
        }

        return [
            'ok' => true,
            'rows' => array_values($rowsByKey),
            'row_count' => count($rowsByKey),
            'pages' => $pagesSeen,
            'items_seen' => $itemsSeen,
            'rows_parsed' => $rowsParsed,
            'rows_no_key' => $rowsNoKey,
            'parse_skipped' => $parseSkipped,
            'dedupe_replaced' => $dedupeReplaced,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function apply_inventory_rows(array $rows): array
    {
        global $wpdb;

        $tSqlStart = microtime(true);

        $liveTable = (string) $this->table->get_live_table_name();
        if ($liveTable === '') {
            throw new \RuntimeException('Could not resolve CSSI live table name.');
        }

        $stageTable = $wpdb->prefix . self::STAGE_TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();

        $createSql = "
            CREATE TABLE IF NOT EXISTS {$stageTable} (
                row_key VARCHAR(96) NOT NULL,
                upc VARCHAR(32) NOT NULL DEFAULT '',
                cssi_item_number VARCHAR(64) NOT NULL DEFAULT '',
                inventory_quantity VARCHAR(32) NULL,
                in_stock_flag TINYINT(1) NOT NULL DEFAULT 0,
                allocation_status VARCHAR(64) NULL,
                distributor_price VARCHAR(32) NULL,
                retail_map VARCHAR(32) NULL,
                retail_msrp VARCHAR(32) NULL,
                drop_ship_price VARCHAR(32) NULL,
                product_name VARCHAR(255) NULL,
                product_description TEXT NULL,
                manufacturer VARCHAR(255) NULL,
                model VARCHAR(255) NULL,
                mfg_model_number VARCHAR(128) NULL,
                caliber_gauge VARCHAR(64) NULL,
                item_type VARCHAR(128) NULL,
                serialized_flag TINYINT(1) NOT NULL DEFAULT 0,
                ffl_required TINYINT(1) NOT NULL DEFAULT 0,
                sot_required TINYINT(1) NOT NULL DEFAULT 0,
                dropship_enabled TINYINT(1) NOT NULL DEFAULT 0,
                dropship_block_reason VARCHAR(255) NULL,
                drop_ship_delivery_options VARCHAR(255) NULL,
                shipping_weight VARCHAR(32) NULL,
                shipping_length_in VARCHAR(32) NULL,
                shipping_width_in VARCHAR(32) NULL,
                shipping_height_in VARCHAR(32) NULL,
                last_seen_utc VARCHAR(64) NULL,
                PRIMARY KEY (row_key),
                KEY upc (upc),
                KEY cssi_item_number (cssi_item_number)
            ) {$charset};
        ";

        $tCreate = microtime(true);
        $created = $wpdb->query($createSql);
        if ($created === false) {
            throw new \RuntimeException('Failed to ensure CSSI stage table: ' . (string) $wpdb->last_error);
        }
        $createMs = (microtime(true) - $tCreate) * 1000.0;

        $tTruncate = microtime(true);
        $truncated = $wpdb->query("TRUNCATE TABLE {$stageTable}");
        if ($truncated === false) {
            throw new \RuntimeException('Failed to truncate CSSI stage table: ' . (string) $wpdb->last_error);
        }
        $truncateMs = (microtime(true) - $tTruncate) * 1000.0;

        $tInsert = microtime(true);
        $insertStats = $this->insert_rows_into_stage($stageTable, $rows);
        $stageInsertMs = (microtime(true) - $tInsert) * 1000.0;

        $rowsLoaded = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$stageTable}");

        $joinUpcSql = "
            UPDATE {$liveTable} L
            INNER JOIN {$stageTable} S
                ON (S.upc IS NOT NULL AND S.upc <> '' AND L.upc = S.upc)
            SET
                L.cssi_item_number = CASE WHEN S.cssi_item_number <> '' THEN S.cssi_item_number ELSE L.cssi_item_number END,
                L.inventory_quantity = S.inventory_quantity,
                L.in_stock_flag = S.in_stock_flag,
                L.allocation_status = S.allocation_status,
                L.distributor_price = CASE WHEN S.distributor_price <> '' THEN S.distributor_price ELSE L.distributor_price END,
                L.retail_map = CASE WHEN S.retail_map <> '' THEN S.retail_map ELSE L.retail_map END,
                L.retail_msrp = CASE WHEN S.retail_msrp <> '' THEN S.retail_msrp ELSE L.retail_msrp END,
                L.drop_ship_price = CASE WHEN S.drop_ship_price <> '' THEN S.drop_ship_price ELSE L.drop_ship_price END,
                L.product_name = CASE WHEN S.product_name <> '' THEN S.product_name ELSE L.product_name END,
                L.product_description = CASE WHEN S.product_description <> '' THEN S.product_description ELSE L.product_description END,
                L.manufacturer = CASE WHEN S.manufacturer <> '' THEN S.manufacturer ELSE L.manufacturer END,
                L.model = CASE WHEN S.model <> '' THEN S.model ELSE L.model END,
                L.mfg_model_number = CASE WHEN S.mfg_model_number <> '' THEN S.mfg_model_number ELSE L.mfg_model_number END,
                L.caliber_gauge = CASE WHEN S.caliber_gauge <> '' THEN S.caliber_gauge ELSE L.caliber_gauge END,
                L.item_type = CASE WHEN S.item_type <> '' THEN S.item_type ELSE L.item_type END,
                L.serialized_flag = S.serialized_flag,
                L.ffl_required = S.ffl_required,
                L.sot_required = S.sot_required,
                L.dropship_enabled = S.dropship_enabled,
                L.dropship_block_reason = S.dropship_block_reason,
                L.drop_ship_delivery_options = CASE WHEN S.drop_ship_delivery_options <> '' THEN S.drop_ship_delivery_options ELSE L.drop_ship_delivery_options END,
                L.shipping_weight = CASE WHEN S.shipping_weight <> '' THEN S.shipping_weight ELSE L.shipping_weight END,
                L.shipping_length_in = CASE WHEN S.shipping_length_in <> '' THEN S.shipping_length_in ELSE L.shipping_length_in END,
                L.shipping_width_in = CASE WHEN S.shipping_width_in <> '' THEN S.shipping_width_in ELSE L.shipping_width_in END,
                L.shipping_height_in = CASE WHEN S.shipping_height_in <> '' THEN S.shipping_height_in ELSE L.shipping_height_in END,
                L.last_seen_utc = CASE WHEN S.last_seen_utc <> '' THEN S.last_seen_utc ELSE L.last_seen_utc END
            WHERE
                COALESCE(L.inventory_quantity, '') <> COALESCE(S.inventory_quantity, '')
                OR COALESCE(L.in_stock_flag, 0) <> COALESCE(S.in_stock_flag, 0)
                OR COALESCE(L.allocation_status, '') <> COALESCE(S.allocation_status, '')
                OR COALESCE(L.distributor_price, '') <> COALESCE(S.distributor_price, '')
                OR COALESCE(L.retail_map, '') <> COALESCE(S.retail_map, '')
                OR COALESCE(L.retail_msrp, '') <> COALESCE(S.retail_msrp, '')
                OR COALESCE(L.drop_ship_price, '') <> COALESCE(S.drop_ship_price, '')
                OR COALESCE(L.serialized_flag, 0) <> COALESCE(S.serialized_flag, 0)
                OR COALESCE(L.ffl_required, 0) <> COALESCE(S.ffl_required, 0)
                OR COALESCE(L.dropship_enabled, 0) <> COALESCE(S.dropship_enabled, 0)
                OR COALESCE(L.last_seen_utc, '') <> COALESCE(S.last_seen_utc, '')
        ";

        $tJoinUpc = microtime(true);
        $joinUpdatedUpc = $wpdb->query($joinUpcSql);
        if ($joinUpdatedUpc === false) {
            throw new \RuntimeException('CSSI join update (UPC) failed: ' . (string) $wpdb->last_error);
        }
        $joinUpcMs = (microtime(true) - $tJoinUpc) * 1000.0;

        $joinItemSql = "
            UPDATE {$liveTable} L
            INNER JOIN {$stageTable} S
                ON (S.cssi_item_number IS NOT NULL AND S.cssi_item_number <> '' AND L.cssi_item_number = S.cssi_item_number)
            LEFT JOIN {$liveTable} LU
                ON (S.upc IS NOT NULL AND S.upc <> '' AND LU.upc = S.upc)
            SET
                L.inventory_quantity = S.inventory_quantity,
                L.in_stock_flag = S.in_stock_flag,
                L.allocation_status = S.allocation_status,
                L.distributor_price = CASE WHEN S.distributor_price <> '' THEN S.distributor_price ELSE L.distributor_price END,
                L.retail_map = CASE WHEN S.retail_map <> '' THEN S.retail_map ELSE L.retail_map END,
                L.retail_msrp = CASE WHEN S.retail_msrp <> '' THEN S.retail_msrp ELSE L.retail_msrp END,
                L.drop_ship_price = CASE WHEN S.drop_ship_price <> '' THEN S.drop_ship_price ELSE L.drop_ship_price END,
                L.product_name = CASE WHEN S.product_name <> '' THEN S.product_name ELSE L.product_name END,
                L.product_description = CASE WHEN S.product_description <> '' THEN S.product_description ELSE L.product_description END,
                L.manufacturer = CASE WHEN S.manufacturer <> '' THEN S.manufacturer ELSE L.manufacturer END,
                L.model = CASE WHEN S.model <> '' THEN S.model ELSE L.model END,
                L.mfg_model_number = CASE WHEN S.mfg_model_number <> '' THEN S.mfg_model_number ELSE L.mfg_model_number END,
                L.caliber_gauge = CASE WHEN S.caliber_gauge <> '' THEN S.caliber_gauge ELSE L.caliber_gauge END,
                L.item_type = CASE WHEN S.item_type <> '' THEN S.item_type ELSE L.item_type END,
                L.serialized_flag = S.serialized_flag,
                L.ffl_required = S.ffl_required,
                L.sot_required = S.sot_required,
                L.dropship_enabled = S.dropship_enabled,
                L.dropship_block_reason = S.dropship_block_reason,
                L.drop_ship_delivery_options = CASE WHEN S.drop_ship_delivery_options <> '' THEN S.drop_ship_delivery_options ELSE L.drop_ship_delivery_options END,
                L.shipping_weight = CASE WHEN S.shipping_weight <> '' THEN S.shipping_weight ELSE L.shipping_weight END,
                L.shipping_length_in = CASE WHEN S.shipping_length_in <> '' THEN S.shipping_length_in ELSE L.shipping_length_in END,
                L.shipping_width_in = CASE WHEN S.shipping_width_in <> '' THEN S.shipping_width_in ELSE L.shipping_width_in END,
                L.shipping_height_in = CASE WHEN S.shipping_height_in <> '' THEN S.shipping_height_in ELSE L.shipping_height_in END,
                L.last_seen_utc = CASE WHEN S.last_seen_utc <> '' THEN S.last_seen_utc ELSE L.last_seen_utc END
            WHERE
                LU.upc IS NULL
                AND (
                    COALESCE(L.inventory_quantity, '') <> COALESCE(S.inventory_quantity, '')
                    OR COALESCE(L.in_stock_flag, 0) <> COALESCE(S.in_stock_flag, 0)
                    OR COALESCE(L.allocation_status, '') <> COALESCE(S.allocation_status, '')
                    OR COALESCE(L.distributor_price, '') <> COALESCE(S.distributor_price, '')
                    OR COALESCE(L.retail_map, '') <> COALESCE(S.retail_map, '')
                    OR COALESCE(L.retail_msrp, '') <> COALESCE(S.retail_msrp, '')
                    OR COALESCE(L.drop_ship_price, '') <> COALESCE(S.drop_ship_price, '')
                    OR COALESCE(L.serialized_flag, 0) <> COALESCE(S.serialized_flag, 0)
                    OR COALESCE(L.ffl_required, 0) <> COALESCE(S.ffl_required, 0)
                    OR COALESCE(L.dropship_enabled, 0) <> COALESCE(S.dropship_enabled, 0)
                    OR COALESCE(L.last_seen_utc, '') <> COALESCE(S.last_seen_utc, '')
                )
        ";

        $tJoinItem = microtime(true);
        $joinUpdatedItem = $wpdb->query($joinItemSql);
        if ($joinUpdatedItem === false) {
            throw new \RuntimeException('CSSI join update (item fallback) failed: ' . (string) $wpdb->last_error);
        }
        $joinItemMs = (microtime(true) - $tJoinItem) * 1000.0;

        $insertSql = "
            INSERT INTO {$liveTable}
            (
                upc,
                cssi_item_number,
                inventory_quantity,
                in_stock_flag,
                allocation_status,
                distributor_price,
                retail_map,
                retail_msrp,
                drop_ship_price,
                product_name,
                product_description,
                manufacturer,
                model,
                mfg_model_number,
                caliber_gauge,
                item_type,
                serialized_flag,
                ffl_required,
                sot_required,
                dropship_enabled,
                dropship_block_reason,
                drop_ship_delivery_options,
                shipping_weight,
                shipping_length_in,
                shipping_width_in,
                shipping_height_in,
                last_seen_utc
            )
            SELECT
                S.upc,
                S.cssi_item_number,
                S.inventory_quantity,
                S.in_stock_flag,
                S.allocation_status,
                S.distributor_price,
                S.retail_map,
                S.retail_msrp,
                S.drop_ship_price,
                S.product_name,
                S.product_description,
                S.manufacturer,
                S.model,
                S.mfg_model_number,
                S.caliber_gauge,
                S.item_type,
                S.serialized_flag,
                S.ffl_required,
                S.sot_required,
                S.dropship_enabled,
                S.dropship_block_reason,
                S.drop_ship_delivery_options,
                S.shipping_weight,
                S.shipping_length_in,
                S.shipping_width_in,
                S.shipping_height_in,
                S.last_seen_utc
            FROM {$stageTable} S
            LEFT JOIN {$liveTable} LU
                ON (S.upc IS NOT NULL AND S.upc <> '' AND LU.upc = S.upc)
            LEFT JOIN {$liveTable} LI
                ON (S.cssi_item_number IS NOT NULL AND S.cssi_item_number <> '' AND LI.cssi_item_number = S.cssi_item_number)
            WHERE
                S.upc IS NOT NULL
                AND S.upc <> ''
                AND LU.upc IS NULL
                AND LI.cssi_item_number IS NULL
        ";

        $tInsertNew = microtime(true);
        $insertedNew = $wpdb->query($insertSql);
        if ($insertedNew === false) {
            throw new \RuntimeException('CSSI insert-new rows failed: ' . (string) $wpdb->last_error);
        }
        $insertNewMs = (microtime(true) - $tInsertNew) * 1000.0;

        $totalSqlMs = (microtime(true) - $tSqlStart) * 1000.0;

        $stats = [
            'processed_rows' => count($rows),
            'rows_loaded' => (int) $rowsLoaded,
            'join_updated_upc' => (int) $joinUpdatedUpc,
            'join_updated_item' => (int) $joinUpdatedItem,
            'inserted_new' => (int) $insertedNew,
            'stage_table' => $stageTable,
            'insert_batches' => (int) ($insertStats['batches'] ?? 0),
            'insert_batch_failures' => (int) ($insertStats['batch_failures'] ?? 0),
            'insert_rows_inserted' => (int) ($insertStats['rows_inserted'] ?? 0),
            'insert_rows_skipped' => (int) ($insertStats['rows_skipped'] ?? 0),
            'create_ms' => number_format($createMs, 2, '.', ''),
            'truncate_ms' => number_format($truncateMs, 2, '.', ''),
            'stage_insert_ms' => number_format($stageInsertMs, 2, '.', ''),
            'join_upc_ms' => number_format($joinUpcMs, 2, '.', ''),
            'join_item_ms' => number_format($joinItemMs, 2, '.', ''),
            'insert_new_ms' => number_format($insertNewMs, 2, '.', ''),
            'total_sql_ms' => number_format($totalSqlMs, 2, '.', ''),
        ];

        $this->log('PROFILE: apply_inventory_rows SQL breakdown', $stats);

        return $stats;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function insert_rows_into_stage(string $stageTable, array $rows): array
    {
        global $wpdb;

        $columns = [
            'row_key',
            'upc',
            'cssi_item_number',
            'inventory_quantity',
            'in_stock_flag',
            'allocation_status',
            'distributor_price',
            'retail_map',
            'retail_msrp',
            'drop_ship_price',
            'product_name',
            'product_description',
            'manufacturer',
            'model',
            'mfg_model_number',
            'caliber_gauge',
            'item_type',
            'serialized_flag',
            'ffl_required',
            'sot_required',
            'dropship_enabled',
            'dropship_block_reason',
            'drop_ship_delivery_options',
            'shipping_weight',
            'shipping_length_in',
            'shipping_width_in',
            'shipping_height_in',
            'last_seen_utc',
        ];

        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '%s')) . ')';
        $batchSize = 250;
        $placeholders = [];
        $values = [];

        $stats = [
            'batches' => 0,
            'batch_failures' => 0,
            'rows_inserted' => 0,
            'rows_skipped' => 0,
        ];

        $flush = function () use (&$placeholders, &$values, &$stats, $wpdb, $stageTable, $columns): void {
            if (empty($placeholders)) {
                return;
            }

            $batchRows = count($placeholders);
            $stats['batches']++;
            $tBatch = microtime(true);

            $sql = 'INSERT INTO ' . $stageTable . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $placeholders);
            $prepared = $wpdb->prepare($sql, $values);
            $result = $wpdb->query($prepared);

            if ($result === false) {
                $stats['batch_failures']++;
                $this->profile('stage insert batch failed', $tBatch, [
                    'batch_no' => (int) $stats['batches'],
                    'batch_rows' => $batchRows,
                    'error' => (string) $wpdb->last_error,
                ]);
                throw new \RuntimeException('Failed inserting CSSI stage rows: ' . (string) $wpdb->last_error);
            }

            $stats['rows_inserted'] += (int) $result;

            $this->profile('stage insert batch', $tBatch, [
                'batch_no' => (int) $stats['batches'],
                'batch_rows' => $batchRows,
                'rows_inserted' => (int) $result,
            ]);

            $placeholders = [];
            $values = [];
        };

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $stats['rows_skipped']++;
                continue;
            }

            $normalized = $this->normalize_stage_row($row);
            if ($normalized['row_key'] === '') {
                $stats['rows_skipped']++;
                continue;
            }

            $placeholders[] = $rowPlaceholder;
            foreach ($columns as $col) {
                $values[] = (string) ($normalized[$col] ?? '');
            }

            if (count($placeholders) >= $batchSize) {
                $flush();
            }
        }

        $flush();

        return $stats;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private function normalize_stage_row(array $row): array
    {
        $normalized = [];

        $normalized['upc'] = trim((string) ($row['upc'] ?? ''));
        $normalized['cssi_item_number'] = trim((string) ($row['cssi_item_number'] ?? ''));
        $normalized['row_key'] = $this->stage_row_key($row);

        $normalized['inventory_quantity'] = trim((string) ($row['inventory_quantity'] ?? '0'));
        $normalized['in_stock_flag'] = $this->to_int01($row['in_stock_flag'] ?? 0);
        $normalized['allocation_status'] = trim((string) ($row['allocation_status'] ?? ''));
        $normalized['distributor_price'] = trim((string) ($row['distributor_price'] ?? ''));
        $normalized['retail_map'] = trim((string) ($row['retail_map'] ?? ''));
        $normalized['retail_msrp'] = trim((string) ($row['retail_msrp'] ?? ''));
        $normalized['drop_ship_price'] = trim((string) ($row['drop_ship_price'] ?? ''));

        $normalized['product_name'] = trim((string) ($row['product_name'] ?? ''));
        $normalized['product_description'] = trim((string) ($row['product_description'] ?? ''));
        $normalized['manufacturer'] = trim((string) ($row['manufacturer'] ?? ''));
        $normalized['model'] = trim((string) ($row['model'] ?? ''));
        $normalized['mfg_model_number'] = trim((string) ($row['mfg_model_number'] ?? ''));
        $normalized['caliber_gauge'] = trim((string) ($row['caliber_gauge'] ?? ''));
        $normalized['item_type'] = trim((string) ($row['item_type'] ?? ''));
        $normalized['serialized_flag'] = $this->to_int01($row['serialized_flag'] ?? 0);

        $normalized['ffl_required'] = $this->to_int01($row['ffl_required'] ?? 0);
        $normalized['sot_required'] = $this->to_int01($row['sot_required'] ?? 0);
        $normalized['dropship_enabled'] = $this->to_int01($row['dropship_enabled'] ?? 0);
        $normalized['dropship_block_reason'] = trim((string) ($row['dropship_block_reason'] ?? ''));
        $normalized['drop_ship_delivery_options'] = trim((string) ($row['drop_ship_delivery_options'] ?? ''));

        $normalized['shipping_weight'] = trim((string) ($row['shipping_weight'] ?? ''));
        $normalized['shipping_length_in'] = trim((string) ($row['shipping_length_in'] ?? ''));
        $normalized['shipping_width_in'] = trim((string) ($row['shipping_width_in'] ?? ''));
        $normalized['shipping_height_in'] = trim((string) ($row['shipping_height_in'] ?? ''));
        $normalized['last_seen_utc'] = trim((string) ($row['last_seen_utc'] ?? ''));

        return $normalized;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function stage_row_key(array $row): string
    {
        $upc = trim((string) ($row['upc'] ?? ''));
        if ($upc !== '') {
            return 'upc:' . $upc;
        }

        $itemNumber = trim((string) ($row['cssi_item_number'] ?? ''));
        if ($itemNumber !== '') {
            return 'item:' . strtoupper($itemNumber);
        }

        return '';
    }

    /**
     * @param mixed $value
     */
    private function to_int01($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value)) {
            return ((float) $value > 0) ? '1' : '0';
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return '1';
        }

        return '0';
    }

    /**
     * @return array{sid:string,token:string}|null
     */
    private function get_api_credentials(): ?array
    {
        $sid = trim((string) Options::get_distributor_option('cssi', 'sid', ''));
        $token = trim((string) Options::get_distributor_option('cssi', 'token', ''));

        if ($sid === '' || $token === '') {
            $this->log('Missing CSSI API credentials.', [
                'has_sid' => $sid !== '' ? 1 : 0,
                'has_token' => $token !== '' ? 1 : 0,
            ]);
            return null;
        }

        return [
            'sid' => $sid,
            'token' => $token,
        ];
    }

    private function mask_sid(string $sid): string
    {
        $sid = trim($sid);
        if ($sid === '') {
            return '[empty]';
        }

        if (strlen($sid) <= 4) {
            return str_repeat('*', strlen($sid));
        }

        return substr($sid, 0, 2) . str_repeat('*', strlen($sid) - 4) . substr($sid, -2);
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

    /**
     * @param array<string,mixed> $ctx
     */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $ctx['elapsed_ms'] = number_format((microtime(true) - $t0) * 1000, 2);
        $this->log('PROFILE: ' . $label, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function finalize_run(float $tStart, int $memStart, string $status, array $ctx = []): void
    {
        $ctx['status'] = $status;
        $this->profile('Total cron run', $tStart, $ctx);

        $memEnd = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        if ($memStart > 0 && $memEnd > 0) {
            $this->log('Memory usage summary', [
                'start_kb' => (int) round($memStart / 1024),
                'end_kb' => (int) round($memEnd / 1024),
                'delta_kb' => (int) round(($memEnd - $memStart) / 1024),
            ]);
        }

        $this->log('---- RUN END (' . $status . ') ----');
    }
}
