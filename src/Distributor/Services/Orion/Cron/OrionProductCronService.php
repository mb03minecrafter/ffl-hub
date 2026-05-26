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
    private const DEFAULT_CATALOG_TIMEOUT_SECONDS = 120;
    private const DEFAULT_INVENTORY_TIMEOUT_SECONDS = 120;
    private const INVENTORY_FAILURE_COOLDOWN_TRANSIENT = 'fflhub_orion_inventory_failure_cooldown';

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
        $catalog_timeout_seconds = $this->get_catalog_timeout_seconds();
        $inventory_timeout_seconds = $this->get_inventory_timeout_seconds();
        $refresh_inventory_during_import = $this->should_refresh_inventory_during_import();

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        update_option('fflhub_orion_fulfillment_last_run', current_time('mysql'), false);

        $this->log('---- RUN START ----', [
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'hook' => self::CRON_HOOK,
            'group' => $this->get_action_group(),
            'catalog_timeout_sec' => $catalog_timeout_seconds,
            'inventory_timeout_sec' => $inventory_timeout_seconds,
            'refresh_inventory_during_import' => $refresh_inventory_during_import ? 1 : 0,
            'memory_kb' => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
        ]);

        $catalog_client = $this->make_client($catalog_timeout_seconds);
        if (!$catalog_client->has_credentials()) {
            update_option('fflhub_orion_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Missing Orion connection key; product import skipped.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing connection key)');
            return;
        }

        $t_catalog = microtime(true);
        $this->log('PHASE START: get_catalog', [
            'timeout_sec' => $catalog_timeout_seconds,
            'memory_kb' => $this->memory_kb(),
            'memory_peak_kb' => $this->memory_peak_kb(),
        ]);
        $catalog = $catalog_client->get_catalog();
        $catalog_data = (array) ($catalog['data'] ?? []);
        $products = $catalog_data['products'] ?? null;
        $this->profile('get_catalog', $t_catalog, [
            'ok' => empty($catalog['ok']) ? 0 : 1,
            'status' => (int) ($catalog['status'] ?? 0),
            'timeout_sec' => $catalog_timeout_seconds,
            'response_bytes' => (int) ($catalog['response_bytes'] ?? 0),
            'data_keys' => array_values(array_keys($catalog_data)),
            'products_seen' => is_array($products) ? count($products) : 0,
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

        if (!is_array($products) || empty($products)) {
            update_option('fflhub_orion_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Orion get_catalog returned no products.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (no products)');
            return;
        }

        $importer = new OrionProductImporterService($this->table);
        $inventory_data = [];

        if ($refresh_inventory_during_import) {
            $t_inventory = microtime(true);
            $inventory_client = $this->make_client($inventory_timeout_seconds);
            $this->log('PHASE START: get_catalog_inventory', [
                'timeout_sec' => $inventory_timeout_seconds,
                'products_seen' => count($products),
                'source' => 'orion_api',
                'memory_kb' => $this->memory_kb(),
                'memory_peak_kb' => $this->memory_peak_kb(),
            ]);
            $inventory = $inventory_client->get_catalog_inventory();
            $inventory_data = (array) ($inventory['data'] ?? []);
            $this->profile('get_catalog_inventory', $t_inventory, [
                'ok' => empty($inventory['ok']) ? 0 : 1,
                'status' => (int) ($inventory['status'] ?? 0),
                'timeout_sec' => $inventory_timeout_seconds,
                'response_bytes' => (int) ($inventory['response_bytes'] ?? 0),
                'data_keys' => array_values(array_keys($inventory_data)),
                'inventory_rows' => $this->count_inventory_rows($inventory_data),
                'source' => 'orion_api',
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

            $this->clear_inventory_failure_cooldown();
        } else {
            $t_inventory_snapshot = microtime(true);
            $this->log('PHASE START: load_live_inventory_snapshot', [
                'products_seen' => count($products),
                'source' => 'live_table',
                'memory_kb' => $this->memory_kb(),
                'memory_peak_kb' => $this->memory_peak_kb(),
            ]);
            $inventory_data = $importer->get_live_inventory_rows();
            $this->profile('load_live_inventory_snapshot', $t_inventory_snapshot, [
                'inventory_rows' => $this->count_inventory_rows($inventory_data),
                'source' => 'live_table',
            ]);
        }

        $t_import = microtime(true);
        $this->log('PHASE START: import_products_array', [
            'products_seen' => count($products),
            'inventory_rows' => $this->count_inventory_rows($inventory_data),
            'inventory_source' => $refresh_inventory_during_import ? 'orion_api' : 'live_table',
            'memory_kb' => $this->memory_kb(),
            'memory_peak_kb' => $this->memory_peak_kb(),
        ]);
        $count = $importer->import_products_array($products, $inventory_data);
        $this->profile('import_products_array', $t_import, [
            'products_seen' => count($products),
            'inventory_rows' => $this->count_inventory_rows($inventory_data),
            'inventory_source' => $refresh_inventory_during_import ? 'orion_api' : 'live_table',
            'rows_imported' => (int) $count,
        ]);

        if ($count <= 0) {
            update_option('fflhub_orion_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Orion product import produced zero rows; not swapping.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (0 imported)');
            return;
        }

        $t_swap = microtime(true);
        $this->log('PHASE START: swap_live_and_staging', [
            'rows_imported' => (int) $count,
            'memory_kb' => $this->memory_kb(),
            'memory_peak_kb' => $this->memory_peak_kb(),
        ]);
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

    private function make_client(int $timeoutSeconds): OrionApiClient
    {
        $connection_key = Options::get_distributor_option('orion', 'connection_key', '');
        $base_url = (string) apply_filters('fflhub_orion_api_base_url', OrionApiClient::DEFAULT_BASE_URL);

        return new OrionApiClient($connection_key, $base_url, $timeoutSeconds);
    }

    /**
     * @param array<string,mixed> $inventoryData
     */
    private function count_inventory_rows(array $inventoryData): int
    {
        if (isset($inventoryData['product_inventory']) && is_array($inventoryData['product_inventory'])) {
            return count($inventoryData['product_inventory']);
        }

        return count($inventoryData);
    }

    private function get_catalog_timeout_seconds(): int
    {
        $timeout_seconds = (int) apply_filters(
            'fflhub_orion_product_catalog_timeout_seconds',
            self::DEFAULT_CATALOG_TIMEOUT_SECONDS
        );

        return max(10, min(180, $timeout_seconds));
    }

    private function get_inventory_timeout_seconds(): int
    {
        $timeout_seconds = (int) apply_filters(
            'fflhub_orion_product_inventory_timeout_seconds',
            self::DEFAULT_INVENTORY_TIMEOUT_SECONDS
        );

        return max(10, min(180, $timeout_seconds));
    }

    private function should_refresh_inventory_during_import(): bool
    {
        return (bool) apply_filters('fflhub_orion_product_refresh_inventory_during_import', false);
    }

    private function clear_inventory_failure_cooldown(): void
    {
        if (!function_exists('delete_transient')) {
            return;
        }

        $had_cooldown = function_exists('get_transient')
            && is_array(get_transient(self::INVENTORY_FAILURE_COOLDOWN_TRANSIENT));

        delete_transient(self::INVENTORY_FAILURE_COOLDOWN_TRANSIENT);

        if ($had_cooldown) {
            $this->log('Cleared Orion inventory API failure cooldown after product inventory request succeeded.');
        }
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
