<?php

namespace FFLHub\Distributor\Services\SportsSouth\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\SportsSouth\API\SportsSouthInventoryClient;
use FFLHub\Distributor\Services\SportsSouth\SportsSouthProductImporterService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

final class SportsSouthProductCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_sports_south_product_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][SportsSouthProductCron]';

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

        $this->log('---- RUN START ----', [
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'hook' => self::CRON_HOOK,
            'group' => $this->get_action_group(),
            'memory_kb' => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
        ]);
        update_option('fflhub_sports_south_fulfillment_last_stage', 'run_start', false);
        update_option('fflhub_sports_south_fulfillment_last_run', current_time('mysql'), false);

        update_option('fflhub_sports_south_fulfillment_last_stage', 'make_client', false);
        $client = $this->make_client();
        if (!$client->has_credentials()) {
            update_option('fflhub_sports_south_fulfillment_last_stage', 'missing_credentials', false);
            update_option('fflhub_sports_south_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Missing Sports South credentials; product import skipped.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing credentials)');
            return;
        }

        $last_update = trim(Options::get_distributor_option('sports_south', 'daily_item_last_update', '1/1/1990'));
        if ($last_update === '') {
            $last_update = '1/1/1990';
        }

        $last_item = (int) Options::get_distributor_option('sports_south', 'daily_item_last_item', '-1');

        update_option('fflhub_sports_south_fulfillment_last_stage', 'daily_item_update_request', false);
        $this->log('DailyItemUpdate request starting.', [
            'last_update' => $last_update,
            'last_item' => $last_item,
        ]);

        update_option('fflhub_sports_south_fulfillment_last_stage', 'download_xml', false);
        $xml_path = $this->build_xml_file_path('daily_item_update');
        if ($xml_path === '') {
            update_option('fflhub_sports_south_fulfillment_last_stage', 'xml_path_failed', false);
            update_option('fflhub_sports_south_fulfillment_last_error', current_time('mysql'), false);
            $this->finalize_run($t_start, $mem_start, 'ERROR (xml path failed)');
            return;
        }

        $t_api = microtime(true);
        $response = $client->daily_item_update_to_file($xml_path, $last_update, $last_item);
        $this->profile('DailyItemUpdate request', $t_api, [
            'ok' => empty($response['ok']) ? 0 : 1,
            'status' => (int) ($response['status'] ?? 0),
            'last_update' => $last_update,
            'last_item' => $last_item,
            'xml_path' => $xml_path,
            'xml_bytes' => (int) ($response['xml_bytes'] ?? 0),
            'body_bytes' => (int) ($response['body_bytes'] ?? 0),
            'raw_path' => (string) ($response['raw_path'] ?? ''),
        ]);

        if (empty($response['ok'])) {
            update_option('fflhub_sports_south_fulfillment_last_stage', 'daily_item_update_failed', false);
            update_option('fflhub_sports_south_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Sports South DailyItemUpdate failed.', [
                'status' => (int) ($response['status'] ?? 0),
                'error' => (string) ($response['error'] ?? ''),
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (DailyItemUpdate failed)');
            return;
        }

        update_option('fflhub_sports_south_fulfillment_last_download', current_time('mysql'), false);
        update_option('fflhub_sports_south_fulfillment_last_download_path', $xml_path, false);
        update_option('fflhub_sports_south_fulfillment_last_download_size', (string) (file_exists($xml_path) ? filesize($xml_path) : 0), false);
        delete_option('fflhub_sports_south_fulfillment_last_download_error');

        update_option('fflhub_sports_south_fulfillment_last_stage', 'import_xml', false);
        $t_import = microtime(true);
        $importer = new SportsSouthProductImporterService($this->table);
        $count = $importer->import_catalog_file($xml_path);
        $this->profile('Import DailyItemUpdate XML', $t_import, [
            'rows_imported' => (int) $count,
            'xml_path' => $xml_path,
        ]);

        if ($count <= 0) {
            update_option('fflhub_sports_south_fulfillment_last_stage', 'zero_rows_imported', false);
            update_option('fflhub_sports_south_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Sports South catalog import produced zero rows; not swapping.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (0 imported)');
            return;
        }

        update_option('fflhub_sports_south_fulfillment_last_stage', 'swap_live_table', false);
        $t_swap = microtime(true);
        $new_live = $this->table->swap_live_and_staging();
        $this->profile('Swap staging/live', $t_swap, [
            'new_live' => (string) $new_live,
        ]);

        update_option('fflhub_sports_south_fulfillment_last_refresh', current_time('mysql'), false);
        update_option('fflhub_sports_south_fulfillment_last_refresh_count', (int) $count, false);
        update_option('fflhub_sports_south_fulfillment_last_swap', current_time('mysql'), false);
        update_option('fflhub_sports_south_fulfillment_last_stage', 'success', false);
        delete_option('fflhub_sports_south_fulfillment_last_error');

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'rows_imported' => (int) $count,
            'new_live' => (string) $new_live,
        ]);
    }

    private function make_client(): SportsSouthInventoryClient
    {
        $customer = Options::get_distributor_option('sports_south', 'customer_number', '');
        $username = Options::get_distributor_option('sports_south', 'username', '');
        $password = Options::get_distributor_option('sports_south', 'password', '');
        $source = Options::get_distributor_option('sports_south', 'source', '');
        $base_url = Options::get_distributor_option('sports_south', 'inventory_api_base_url', SportsSouthInventoryClient::DEFAULT_BASE_URL);

        $base_url = (string) apply_filters('fflhub_sports_south_inventory_api_base_url', $base_url);

        return new SportsSouthInventoryClient($customer, $username, $password, $source, $base_url, 240);
    }

    private function build_xml_file_path(string $prefix): string
    {
        $dir = $this->uploads_subdir();
        if ($dir === '') {
            return '';
        }

        return $dir . '/' . $prefix . '_' . gmdate('Ymd_His') . '.xml';
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
            $this->log('Failed to create Sports South XML directory.', [
                'dir' => $dir,
            ]);
            return '';
        }

        return $dir;
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log_if(true, self::LOG_PREFIX, $message, self::DEBUG_FLAG);
            return;
        }

        DebugLogUtil::log_if_ctx(true, self::LOG_PREFIX, $message, $ctx, self::DEBUG_FLAG);
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
    private function finalize_run(float $tStart, int $memStart, string $status, array $ctx = []): void
    {
        $ctx['status'] = $status;
        $ctx['elapsed_ms'] = number_format((microtime(true) - $tStart) * 1000.0, 2, '.', '');

        if ($memStart > 0 && function_exists('memory_get_usage')) {
            $mem_end = (int) memory_get_usage(true);
            $ctx['memory_start_kb'] = (int) round($memStart / 1024);
            $ctx['memory_end_kb'] = (int) round($mem_end / 1024);
            $ctx['memory_delta_kb'] = (int) round(($mem_end - $memStart) / 1024);
        }

        $this->log('---- RUN END ----', $ctx);
    }
}
