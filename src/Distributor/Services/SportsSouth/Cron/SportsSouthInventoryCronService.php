<?php

namespace FFLHub\Distributor\Services\SportsSouth\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\SportsSouth\API\SportsSouthInventoryClient;
use FFLHub\Distributor\Services\SportsSouth\SportsSouthProductImporterService;
use FFLHub\Distributor\Services\SportsSouth\SportsSouthProductParser;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

final class SportsSouthInventoryCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_sports_south_pricing_quantity_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][SportsSouthInventoryCron]';
    private const SINCE_OPTION = 'fflhub_sports_south_inventory_since_datetime_cursor';

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
        $t_start = microtime(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        update_option('fflhub_sports_south_inventory_last_run', current_time('mysql'), false);

        $client = $this->make_client();
        if (!$client->has_credentials()) {
            update_option('fflhub_sports_south_inventory_last_error', current_time('mysql'), false);
            $this->log('Missing Sports South credentials; inventory update skipped.');
            return;
        }

        $parser = new SportsSouthProductParser();
        $since = $this->resolve_since_datetime($parser);
        $request_cursor = gmdate('Y-m-d\TH:i:s.00+00.00');

        $response = $client->incremental_onhand_update($since);
        if (empty($response['ok'])) {
            update_option('fflhub_sports_south_inventory_last_error', current_time('mysql'), false);
            $this->log('Sports South IncrementalOnhandUpdate failed.', [
                'status' => (int) ($response['status'] ?? 0),
                'error' => (string) ($response['error'] ?? ''),
                'since' => $since,
            ]);
            return;
        }

        $xml = (string) ($response['xml'] ?? '');
        $xml_path = $this->write_xml_file('incremental_onhand_update', $xml);
        if ($xml_path === '') {
            update_option('fflhub_sports_south_inventory_last_error', current_time('mysql'), false);
            return;
        }

        $t_apply = microtime(true);
        $quantity_is_delta = (bool) apply_filters('fflhub_sports_south_incremental_quantity_is_delta', true);
        $importer = new SportsSouthProductImporterService($this->table, $parser);
        $stats = $importer->apply_onhand_delta_file_to_live($xml_path, $quantity_is_delta);
        $stats['apply_ms'] = number_format((microtime(true) - $t_apply) * 1000.0, 2, '.', '');

        $next_since = $parser->extract_next_since_datetime($xml);
        if ($next_since === '') {
            $next_since = $request_cursor;
        }

        update_option(self::SINCE_OPTION, $next_since, false);
        update_option('fflhub_sports_south_inventory_last_update', current_time('mysql'), false);
        update_option('fflhub_sports_south_inventory_last_update_count', (int) ($stats['rows_loaded'] ?? 0), false);
        update_option('fflhub_sports_south_inventory_last_download_path', $xml_path, false);
        update_option('fflhub_sports_south_inventory_last_download_size', (string) (file_exists($xml_path) ? filesize($xml_path) : 0), false);
        delete_option('fflhub_sports_south_inventory_last_error');

        $this->log('Sports South inventory update complete.', array_merge($stats, [
            'since' => $since,
            'next_since' => $next_since,
            'xml_path' => $xml_path,
            'elapsed_ms' => number_format((microtime(true) - $t_start) * 1000.0, 2, '.', ''),
        ]));
    }

    private function make_client(): SportsSouthInventoryClient
    {
        $customer = Options::get_distributor_option('sports_south', 'customer_number', '');
        $username = Options::get_distributor_option('sports_south', 'username', '');
        $password = Options::get_distributor_option('sports_south', 'password', '');
        $source = Options::get_distributor_option('sports_south', 'source', '');
        $base_url = Options::get_distributor_option('sports_south', 'inventory_api_base_url', SportsSouthInventoryClient::DEFAULT_BASE_URL);

        $base_url = (string) apply_filters('fflhub_sports_south_inventory_api_base_url', $base_url);

        return new SportsSouthInventoryClient($customer, $username, $password, $source, $base_url, 90);
    }

    private function resolve_since_datetime(SportsSouthProductParser $parser): string
    {
        $cursor = trim((string) get_option(self::SINCE_OPTION, ''));
        if ($cursor !== '') {
            return $parser->format_since_datetime($cursor);
        }

        $configured = trim(Options::get_distributor_option('sports_south', 'inventory_since_datetime', ''));
        if ($configured !== '') {
            return $parser->format_since_datetime($configured);
        }

        return gmdate('Y-m-d\TH:i:s.00+00.00', time() - (10 * MINUTE_IN_SECONDS));
    }

    private function write_xml_file(string $prefix, string $xml): string
    {
        $dir = $this->uploads_subdir();
        if ($dir === '') {
            return '';
        }

        $path = $dir . '/' . $prefix . '_' . gmdate('Ymd_His') . '.xml';
        $bytes = file_put_contents($path, $xml);
        if ($bytes === false) {
            $this->log('Failed to write Sports South onhand XML file.', [
                'path' => $path,
            ]);
            return '';
        }

        return $path;
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
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $message);
            return;
        }

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $message, $ctx);
    }
}
