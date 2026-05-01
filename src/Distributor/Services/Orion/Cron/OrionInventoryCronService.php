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

final class OrionInventoryCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_orion_pricing_quantity_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][OrionInventoryCron]';

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
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        update_option('fflhub_orion_inventory_last_run', current_time('mysql'), false);

        $client = $this->make_client();
        if (!$client->has_credentials()) {
            update_option('fflhub_orion_inventory_last_error', current_time('mysql'), false);
            $this->log('Missing Orion connection key; inventory update skipped.');
            return;
        }

        $inventory = $client->get_catalog_inventory();
        if (empty($inventory['ok'])) {
            update_option('fflhub_orion_inventory_last_error', current_time('mysql'), false);
            $this->log('Orion get_catalog_inventory failed.', [
                'status' => (int) ($inventory['status'] ?? 0),
                'error' => (string) ($inventory['error'] ?? ''),
            ]);
            return;
        }

        $importer = new OrionProductImporterService($this->table);
        $stats = $importer->apply_inventory_array_to_live((array) ($inventory['data'] ?? []));

        update_option('fflhub_orion_inventory_last_update', current_time('mysql'), false);
        update_option('fflhub_orion_inventory_last_update_count', (int) ($stats['rows_loaded'] ?? 0), false);
        delete_option('fflhub_orion_inventory_last_error');

        $this->log('Orion inventory update complete.', $stats);
    }

    private function make_client(): OrionApiClient
    {
        $connection_key = Options::get_distributor_option('orion', 'connection_key', '');
        $base_url = (string) apply_filters('fflhub_orion_api_base_url', OrionApiClient::DEFAULT_BASE_URL);

        return new OrionApiClient($connection_key, $base_url, 60);
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
