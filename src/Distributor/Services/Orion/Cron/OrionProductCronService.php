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
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        update_option('fflhub_orion_fulfillment_last_run', current_time('mysql'), false);

        $client = $this->make_client();
        if (!$client->has_credentials()) {
            update_option('fflhub_orion_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Missing Orion connection key; product import skipped.');
            return;
        }

        $catalog = $client->get_catalog();
        if (empty($catalog['ok'])) {
            update_option('fflhub_orion_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Orion get_catalog failed.', [
                'status' => (int) ($catalog['status'] ?? 0),
                'error' => (string) ($catalog['error'] ?? ''),
            ]);
            return;
        }

        $products = $catalog['data']['products'] ?? null;
        if (!is_array($products) || empty($products)) {
            update_option('fflhub_orion_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Orion get_catalog returned no products.');
            return;
        }

        $inventory = $client->get_catalog_inventory();
        if (empty($inventory['ok'])) {
            update_option('fflhub_orion_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Orion get_catalog_inventory failed during product import; not swapping catalog.', [
                'status' => (int) ($inventory['status'] ?? 0),
                'error' => (string) ($inventory['error'] ?? ''),
            ]);
            return;
        }

        $importer = new OrionProductImporterService($this->table);
        $count = $importer->import_products_array($products, (array) ($inventory['data'] ?? []));
        if ($count <= 0) {
            update_option('fflhub_orion_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Orion product import produced zero rows; not swapping.');
            return;
        }

        $new_live = $this->table->swap_live_and_staging();

        update_option('fflhub_orion_fulfillment_last_refresh', current_time('mysql'), false);
        update_option('fflhub_orion_fulfillment_last_refresh_count', (int) $count, false);
        update_option('fflhub_orion_fulfillment_last_swap', current_time('mysql'), false);
        delete_option('fflhub_orion_fulfillment_last_error');

        $this->log('Orion product import complete.', [
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
}
