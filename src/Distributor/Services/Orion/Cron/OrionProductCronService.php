<?php

namespace FFLHub\Distributor\Services\Orion\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Orion\API\OrionApiClient;
use FFLHub\Distributor\Services\Orion\OrionProductImporterService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

final class OrionProductCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_orion_product_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][OrionProductCron]';

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
        return DAY_IN_SECONDS;
    }

    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    protected function get_initial_delay_seconds(): int
    {
        return 5 * MINUTE_IN_SECONDS;
    }

    public function run(): void
    {
        $t_start = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        update_option('fflhub_orion_fulfillment_last_run', current_time('mysql'), false);

        $this->log('---- RUN START ----', [
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'hook' => self::CRON_HOOK,
            'group' => $this->get_action_group(),
            'memory_kb' => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
        ]);

        $client = $this->make_client();
        if (!$client->has_credentials()) {
            update_option('fflhub_orion_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Missing Orion connection key; product import skipped.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing connection key)');
            return;
        }

        $t_catalog = microtime(true);
        $catalog = $client->get_catalog();
        $this->profile('get_catalog', $t_catalog, [
            'ok' => empty($catalog['ok']) ? 0 : 1,
            'status' => (int) ($catalog['status'] ?? 0),
        ]);

        if (empty($catalog['ok'])) {
            update_option('fflhub_orion_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Orion get_catalog failed.', [
                'status' => (int) ($catalog['status'] ?? 0),
                'error' => (string) ($catalog['error'] ?? ''),
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (catalog request failed)');
            return;
        }

        $products = $catalog['data']['products'] ?? null;
        if (!is_array($products) || empty($products)) {
            update_option('fflhub_orion_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Orion get_catalog returned no products.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (no products)');
            return;
        }

        $t_inventory = microtime(true);
        $inventory = $client->get_catalog_inventory();
        $this->profile('get_catalog_inventory', $t_inventory, [
            'ok' => empty($inventory['ok']) ? 0 : 1,
            'status' => (int) ($inventory['status'] ?? 0),
        ]);

        if (empty($inventory['ok'])) {
            update_option('fflhub_orion_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Orion get_catalog_inventory failed during product import; not swapping catalog.', [
                'status' => (int) ($inventory['status'] ?? 0),
                'error' => (string) ($inventory['error'] ?? ''),
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (inventory request failed)');
            return;
        }

        $t_import = microtime(true);
        $importer = new OrionProductImporterService($this->table);
        $count = $importer->import_products_array($products, (array) ($inventory['data'] ?? []));
        $this->profile('import_products_array', $t_import, [
            'products_seen' => count($products),
            'rows_imported' => (int) $count,
        ]);

        if ($count <= 0) {
            update_option('fflhub_orion_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Orion product import produced zero rows; not swapping.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (0 imported)');
            return;
        }

        $t_swap = microtime(true);
        $new_live = $this->table->swap_live_and_staging();
        $this->profile('swap_live_and_staging', $t_swap, [
            'new_live' => (string) $new_live,
        ]);

        update_option('fflhub_orion_fulfillment_last_refresh', current_time('mysql'), false);
        update_option('fflhub_orion_fulfillment_last_refresh_count', (int) $count, false);
        update_option('fflhub_orion_fulfillment_last_swap', current_time('mysql'), false);
        delete_option('fflhub_orion_fulfillment_last_error');

        $this->log('Orion product import complete.', [
            'products_seen' => count($products),
            'rows_imported' => (int) $count,
            'new_live' => (string) $new_live,
        ]);

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'products_seen' => count($products),
            'rows_imported' => (int) $count,
            'new_live' => (string) $new_live,
        ]);
    }

    private function make_client(): OrionApiClient
    {
        $connection_key = Options::get_distributor_option('orion', 'connection_key', '');
        $base_url = (string) apply_filters('fflhub_orion_api_base_url', OrionApiClient::DEFAULT_BASE_URL);

        return new OrionApiClient($connection_key, $base_url, 120);
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
        $this->log('PROFILE: ' . $label, $ctx);
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
        }

        $this->log('---- RUN END ----', $ctx);
    }
}
