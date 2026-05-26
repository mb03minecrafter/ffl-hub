<?php

namespace FFLHub\Distributor\Services\Kinseys\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Kinseys\API\KinseysApiClient;
use FFLHub\Distributor\Services\Kinseys\KinseysProductImporterService;
use FFLHub\Distributor\Services\Kinseys\KinseysProductParser;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

final class KinseysProductCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_kinseys_product_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][KinseysProductCron]';
    private const DEFAULT_TIMEOUT_SECONDS = 180;

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
        $interval = (int) apply_filters('fflhub_kinseys_product_interval_seconds', DAY_IN_SECONDS);
        return max(HOUR_IN_SECONDS, $interval);
    }

    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    protected function get_initial_delay_seconds(): int
    {
        return 6 * MINUTE_IN_SECONDS;
    }

    public function run(): void
    {
        $t_start = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        $timeout_seconds = $this->get_timeout_seconds();

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        update_option('fflhub_kinseys_product_last_run', current_time('mysql'), false);

        $table_ctx = $this->table_context();
        $this->log('---- RUN START ----', [
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'hook' => self::CRON_HOOK,
            'group' => $this->get_action_group(),
            'timeout_sec' => $timeout_seconds,
            'live_table' => $table_ctx['live_table'],
            'staging_table' => $table_ctx['staging_table'],
            'memory_kb' => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
            'memory_peak_kb' => $this->memory_peak_kb(),
        ]);

        $client = $this->make_client($timeout_seconds);
        if (!$client->has_credentials()) {
            update_option('fflhub_kinseys_product_last_error', current_time('mysql'), false);
            $this->log('Missing Kinsey\'s API credentials; product update skipped.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing credentials)');
            return;
        }

        $parser = new KinseysProductParser();

        $t_products = microtime(true);
        $this->log('PHASE START: get_products', [
            'timeout_sec' => $timeout_seconds,
            'memory_kb' => $this->memory_kb(),
            'memory_peak_kb' => $this->memory_peak_kb(),
        ]);
        $products = $client->get_products();
        $product_data = (array) ($products['data'] ?? []);
        $product_rows = $parser->normalize_product_rows($product_data);
        $this->profile('get_products', $t_products, array_merge([
            'ok' => empty($products['ok']) ? 0 : 1,
            'status' => (int) ($products['status'] ?? 0),
            'timeout_sec' => $timeout_seconds,
            'response_bytes' => (int) ($products['response_bytes'] ?? 0),
        ], DebugLogUtil::summarize_array_keys($product_data), [
            'products_seen' => count($product_rows),
        ]));

        if (empty($products['ok'])) {
            update_option('fflhub_kinseys_product_last_error', current_time('mysql'), false);
            $this->log('Kinsey\'s product request failed.', [
                'status' => (int) ($products['status'] ?? 0),
                'error' => (string) ($products['error'] ?? ''),
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (product request failed)', [
                'status' => (int) ($products['status'] ?? 0),
                'error' => (string) ($products['error'] ?? ''),
            ]);
            return;
        }

        if (empty($product_rows)) {
            update_option('fflhub_kinseys_product_last_error', current_time('mysql'), false);
            $this->log('Kinsey\'s product request returned zero rows; swap skipped.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (0 products)');
            return;
        }

        $importer = new KinseysProductImporterService($this->table, $parser);

        $t_snapshot = microtime(true);
        $this->log('PHASE START: load_live_inventory_snapshot', [
            'products_seen' => count($product_rows),
            'source' => 'live_table',
            'memory_kb' => $this->memory_kb(),
            'memory_peak_kb' => $this->memory_peak_kb(),
        ]);
        $inventory_rows = $importer->get_live_inventory_rows();
        $this->profile('load_live_inventory_snapshot', $t_snapshot, [
            'inventory_rows' => count($inventory_rows),
            'source' => 'live_table',
            'live_table' => $table_ctx['live_table'],
        ]);

        $t_import = microtime(true);
        $this->log('PHASE START: import_products_array', [
            'products_seen' => count($product_rows),
            'inventory_rows' => count($inventory_rows),
            'inventory_source' => 'live_table',
            'staging_table' => $table_ctx['staging_table'],
            'memory_kb' => $this->memory_kb(),
            'memory_peak_kb' => $this->memory_peak_kb(),
        ]);
        $imported = $importer->import_products_array($product_rows, $inventory_rows);
        $this->profile('import_products_array', $t_import, [
            'products_seen' => count($product_rows),
            'inventory_rows' => count($inventory_rows),
            'inventory_source' => 'live_table',
            'rows_imported' => (int) $imported,
            'staging_table' => $table_ctx['staging_table'],
        ]);

        if ($imported <= 0) {
            update_option('fflhub_kinseys_product_last_error', current_time('mysql'), false);
            $this->log('Kinsey\'s product import produced zero rows; swap skipped.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (0 imported)');
            return;
        }

        $t_swap = microtime(true);
        $this->log('PHASE START: swap_live_and_staging', [
            'rows_imported' => (int) $imported,
            'old_live' => $table_ctx['live_table'],
            'old_staging' => $table_ctx['staging_table'],
            'memory_kb' => $this->memory_kb(),
            'memory_peak_kb' => $this->memory_peak_kb(),
        ]);
        $new_live = (string) $this->table->swap_live_and_staging();
        $this->profile('swap_live_and_staging', $t_swap, [
            'new_live' => $new_live,
        ]);

        update_option('fflhub_kinseys_product_last_update', current_time('mysql'), false);
        update_option('fflhub_kinseys_product_last_update_count', (int) $imported, false);
        delete_option('fflhub_kinseys_product_last_error');

        $this->log('Kinsey\'s product import complete.', [
            'products_seen' => count($product_rows),
            'rows_imported' => (int) $imported,
            'new_live' => $new_live,
        ]);
        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'products_seen' => count($product_rows),
            'rows_imported' => (int) $imported,
            'old_live' => $table_ctx['live_table'],
            'new_live' => $new_live,
        ]);
    }

    private function make_client(int $timeoutSeconds): KinseysApiClient
    {
        $api_identifier = Options::get_distributor_option('kinseys', 'api_identifier', '');
        $api_key = Options::get_distributor_option('kinseys', 'api_key', '');
        $source = Options::get_distributor_option('kinseys', 'source', 'FFLHub');
        $base_url = (string) apply_filters('fflhub_kinseys_api_base_url', KinseysApiClient::DEFAULT_BASE_URL);

        return new KinseysApiClient($api_identifier, $api_key, $source, $base_url, $timeoutSeconds);
    }

    private function get_timeout_seconds(): int
    {
        $timeout_seconds = (int) apply_filters(
            'fflhub_kinseys_product_timeout_seconds',
            self::DEFAULT_TIMEOUT_SECONDS
        );

        return max(30, min(300, $timeout_seconds));
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
        $ctx['elapsed_ms'] = number_format((microtime(true) - $t0) * 1000.0, 2, '.', '');
        $ctx['memory_kb'] = $this->memory_kb();
        $ctx['memory_peak_kb'] = $this->memory_peak_kb();
        $this->log('PROFILE: ' . $label, $ctx);
    }

    private function memory_kb(): int
    {
        return function_exists('memory_get_usage') ? (int) round(memory_get_usage(true) / 1024) : 0;
    }

    private function memory_peak_kb(): int
    {
        return function_exists('memory_get_peak_usage') ? (int) round(memory_get_peak_usage(true) / 1024) : 0;
    }

    /**
     * @return array{live_table:string,staging_table:string}
     */
    private function table_context(): array
    {
        try {
            return [
                'live_table' => (string) $this->table->get_live_table_name(),
                'staging_table' => (string) $this->table->get_staging_table_name(),
            ];
        } catch (\Throwable $e) {
            return [
                'live_table' => '',
                'staging_table' => '',
            ];
        }
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function finalize_run(float $t_start, int $mem_start, string $status, array $ctx = []): void
    {
        $ctx['status'] = $status;
        $ctx['elapsed_ms'] = number_format((microtime(true) - $t_start) * 1000.0, 2, '.', '');

        if ($mem_start > 0 && function_exists('memory_get_usage')) {
            $mem_end = (int) memory_get_usage(true);
            $ctx['memory_start_kb'] = (int) round($mem_start / 1024);
            $ctx['memory_end_kb'] = (int) round($mem_end / 1024);
            $ctx['memory_delta_kb'] = (int) round(($mem_end - $mem_start) / 1024);
            $ctx['memory_peak_kb'] = $this->memory_peak_kb();
        }

        $this->log('---- RUN END ----', $ctx);
    }
}
