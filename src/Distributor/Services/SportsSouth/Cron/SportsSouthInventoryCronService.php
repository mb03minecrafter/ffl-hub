<?php

namespace FFLHub\Distributor\Services\SportsSouth\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Cron\CronRunLogger;
use FFLHub\Distributor\Services\SportsSouth\API\SportsSouthInventoryClient;
use FFLHub\Distributor\Services\SportsSouth\SportsSouthProductImporterService;
use FFLHub\Distributor\Services\SportsSouth\SportsSouthProductParser;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;

final class SportsSouthInventoryCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_sports_south_pricing_quantity_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][SportsSouthInventoryCron]';
    private const SINCE_OPTION = 'fflhub_sports_south_inventory_since_datetime_cursor';
    private const LOCK_OPTION = 'fflhub_sports_south_inventory_sync_lock';
    private const LOCK_TTL_SECONDS = 30 * MINUTE_IN_SECONDS;
    private const FALLBACK_CURSOR_OVERLAP_SECONDS = 120;

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
        $lock_owner = $this->acquire_inventory_sync_lock('inventory_update');
        if ($lock_owner === '') {
            update_option('fflhub_sports_south_inventory_last_stage', 'inventory_sync_lock_held', false);
            return;
        }

        try {
            $this->run_locked($lock_owner);
        } finally {
            $this->release_inventory_sync_lock($lock_owner);
        }
    }

    private function run_locked(string $lockOwner): void
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
            'lock_owner' => $lockOwner,
            'memory_kb' => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
        ]);

        update_option('fflhub_sports_south_inventory_last_run', current_time('mysql'), false);
        update_option('fflhub_sports_south_inventory_last_stage', 'run_start', false);

        update_option('fflhub_sports_south_inventory_last_stage', 'make_client', false);
        $client = $this->make_client();
        if (!$client->has_credentials()) {
            update_option('fflhub_sports_south_inventory_last_error', current_time('mysql'), false);
            update_option('fflhub_sports_south_inventory_last_stage', 'missing_credentials', false);
            $this->log('Missing Sports South credentials; inventory update skipped.');
            return;
        }

        $parser = new SportsSouthProductParser();
        $t_since = microtime(true);
        update_option('fflhub_sports_south_inventory_last_stage', 'resolve_since_datetime', false);
        $since = $this->resolve_since_datetime($parser);
        $this->profile('resolve_since_datetime', $t_since, [
            'since' => $since,
        ]);

        $xml_path = $this->build_xml_file_path('incremental_onhand_update');
        if ($xml_path === '') {
            update_option('fflhub_sports_south_inventory_last_error', current_time('mysql'), false);
            update_option('fflhub_sports_south_inventory_last_stage', 'resolve_xml_path_failed', false);
            return;
        }

        $t_request = microtime(true);
        $request_started_ts = time();
        $request_started_utc = gmdate('Y-m-d H:i:s', $request_started_ts);
        update_option('fflhub_sports_south_inventory_last_stage', 'request_inventory', false);
        $response = $client->incremental_onhand_update_to_file($xml_path, $since);
        $request_finished_utc = gmdate('Y-m-d H:i:s');
        $this->profile('IncrementalOnhandUpdate request', $t_request, [
            'ok' => empty($response['ok']) ? 0 : 1,
            'status' => (int) ($response['status'] ?? 0),
            'since_sent' => $since,
            'xml_path' => (string) ($response['xml_path'] ?? $xml_path),
            'raw_path' => (string) ($response['raw_path'] ?? ''),
            'xml_bytes' => (int) ($response['xml_bytes'] ?? 0),
            'body_bytes' => (int) ($response['body_bytes'] ?? 0),
            'request_started_utc' => $request_started_utc,
            'request_finished_utc' => $request_finished_utc,
        ]);
        if (empty($response['ok'])) {
            update_option('fflhub_sports_south_inventory_last_error', current_time('mysql'), false);
            update_option('fflhub_sports_south_inventory_last_stage', 'request_failed', false);
            $this->log('Sports South IncrementalOnhandUpdate failed.', [
                'status' => (int) ($response['status'] ?? 0),
                'error' => (string) ($response['error'] ?? ''),
                'since_sent' => $since,
                'xml_path' => $xml_path,
            ]);
            return;
        }

        if (!is_readable($xml_path)) {
            update_option('fflhub_sports_south_inventory_last_error', current_time('mysql'), false);
            update_option('fflhub_sports_south_inventory_last_stage', 'xml_file_missing', false);
            $this->log('Sports South IncrementalOnhandUpdate XML file missing after successful response.', [
                'xml_path' => $xml_path,
            ]);
            return;
        }

        $t_apply = microtime(true);
        update_option('fflhub_sports_south_inventory_last_stage', 'apply_onhand_file_to_live', false);
        $importer = new SportsSouthProductImporterService($this->table, $parser);
        $stats = $importer->apply_onhand_file_to_live($xml_path);
        $stats['apply_ms'] = number_format((microtime(true) - $t_apply) * 1000.0, 2, '.', '');
        if (!empty($stats['error'])) {
            update_option('fflhub_sports_south_inventory_last_error', current_time('mysql'), false);
            update_option('fflhub_sports_south_inventory_last_stage', 'apply_failed', false);
            $this->log('Sports South inventory update failed during apply; cursor not advanced.', array_merge($stats, [
                'since_sent' => $since,
                'xml_path' => $xml_path,
            ]));
            return;
        }

        $t_cursor = microtime(true);
        update_option('fflhub_sports_south_inventory_last_stage', 'extract_next_since_datetime', false);
        $vendor_next_since = $parser->extract_next_since_datetime_from_file($xml_path);
        $overlap_seconds = 0;
        $cursor_source = 'vendor_response';
        $next_since = $vendor_next_since;
        if ($next_since === '') {
            $overlap_seconds = (int) apply_filters(
                'fflhub_sports_south_inventory_cursor_fallback_overlap_seconds',
                self::FALLBACK_CURSOR_OVERLAP_SECONDS
            );
            $overlap_seconds = max(0, min(600, $overlap_seconds));
            $next_since = gmdate('Y-m-d\TH:i:s.00+00.00', $request_started_ts - $overlap_seconds);
            $cursor_source = 'fallback_request_start_overlap';
        }
        $this->profile('extract_next_since_datetime', $t_cursor, [
            'vendor_next_since' => $vendor_next_since,
            'persisted_next_since' => $next_since,
            'cursor_source' => $cursor_source,
            'overlap_seconds' => $overlap_seconds,
        ]);

        $t_options = microtime(true);
        update_option('fflhub_sports_south_inventory_last_stage', 'persist_inventory_options', false);
        update_option(self::SINCE_OPTION, $next_since, false);
        update_option('fflhub_sports_south_inventory_last_update', current_time('mysql'), false);
        update_option('fflhub_sports_south_inventory_last_update_count', (int) ($stats['rows_loaded'] ?? 0), false);
        update_option('fflhub_sports_south_inventory_last_download_path', $xml_path, false);
        update_option('fflhub_sports_south_inventory_last_raw_download_path', (string) ($response['raw_path'] ?? ''), false);
        update_option('fflhub_sports_south_inventory_last_download_size', (string) (file_exists($xml_path) ? filesize($xml_path) : 0), false);
        update_option('fflhub_sports_south_inventory_last_since_sent', $since, false);
        update_option('fflhub_sports_south_inventory_last_vendor_next_since', $vendor_next_since, false);
        update_option('fflhub_sports_south_inventory_last_cursor_source', $cursor_source, false);
        update_option('fflhub_sports_south_inventory_last_request_started_utc', $request_started_utc, false);
        update_option('fflhub_sports_south_inventory_last_request_finished_utc', $request_finished_utc, false);
        update_option('fflhub_sports_south_inventory_last_cursor_overlap_seconds', (string) $overlap_seconds, false);
        update_option('fflhub_sports_south_inventory_last_stage', 'success', false);
        delete_option('fflhub_sports_south_inventory_last_error');
        $this->profile('persist_inventory_options', $t_options, [
            'since_sent' => $since,
            'vendor_next_since' => $vendor_next_since,
            'persisted_next_since' => $next_since,
            'cursor_source' => $cursor_source,
            'request_started_utc' => $request_started_utc,
            'request_finished_utc' => $request_finished_utc,
            'overlap_seconds' => $overlap_seconds,
            'rows_loaded' => (int) ($stats['rows_loaded'] ?? 0),
        ]);

        $this->log('Sports South inventory update complete.', array_merge($stats, [
            'since_sent' => $since,
            'vendor_next_since' => $vendor_next_since,
            'persisted_next_since' => $next_since,
            'cursor_source' => $cursor_source,
            'request_started_utc' => $request_started_utc,
            'request_finished_utc' => $request_finished_utc,
            'overlap_seconds' => $overlap_seconds,
            'xml_path' => $xml_path,
            'raw_path' => (string) ($response['raw_path'] ?? ''),
            'xml_bytes' => (int) ($response['xml_bytes'] ?? 0),
            'body_bytes' => (int) ($response['body_bytes'] ?? 0),
            'elapsed_ms' => number_format((microtime(true) - $t_start) * 1000.0, 2, '.', ''),
            'memory_start_kb' => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
            'memory_end_kb' => function_exists('memory_get_usage') ? (int) round(memory_get_usage(true) / 1024) : 0,
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

    private function acquire_inventory_sync_lock(string $mode): string
    {
        $ttl = (int) apply_filters(
            'fflhub_sports_south_inventory_sync_lock_ttl_seconds',
            self::LOCK_TTL_SECONDS
        );
        $ttl = max(5 * MINUTE_IN_SECONDS, $ttl);
        $owner = sprintf(
            '%s:%d:%s',
            function_exists('gethostname') ? (string) gethostname() : 'unknown-host',
            function_exists('getmypid') ? (int) getmypid() : 0,
            function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid('', true))
        );

        $payload = [
            'owner' => $owner,
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'mode' => $mode,
            'started_at_utc' => gmdate('Y-m-d H:i:s'),
            'started_ts' => time(),
            'ttl_seconds' => $ttl,
        ];

        if (add_option(self::LOCK_OPTION, $this->encode_json($payload), '', 'no')) {
            $this->log('Sports South inventory sync lock acquired.', $payload);
            return $owner;
        }

        $existing = $this->decode_lock_payload((string) get_option(self::LOCK_OPTION, ''));
        $age = time() - (int) ($existing['started_ts'] ?? 0);
        $existing_ttl = max(1, (int) ($existing['ttl_seconds'] ?? $ttl));
        if ($age > $existing_ttl) {
            delete_option(self::LOCK_OPTION);
            $payload['replaced_stale_lock_owner'] = (string) ($existing['owner'] ?? '');
            $payload['replaced_stale_lock_age'] = $age;
            if (add_option(self::LOCK_OPTION, $this->encode_json($payload), '', 'no')) {
                $this->log('Sports South inventory sync stale lock replaced.', $payload);
                return $owner;
            }
        }

        $this->log('Sports South inventory sync skipped because lock is held.', [
            'requested_mode' => $mode,
            'existing_owner' => (string) ($existing['owner'] ?? ''),
            'existing_pid' => (int) ($existing['pid'] ?? 0),
            'existing_mode' => (string) ($existing['mode'] ?? ''),
            'existing_started_at_utc' => (string) ($existing['started_at_utc'] ?? ''),
            'existing_age_seconds' => $age,
            'existing_ttl_seconds' => $existing_ttl,
        ]);

        return '';
    }

    private function release_inventory_sync_lock(string $owner): void
    {
        $existing = $this->decode_lock_payload((string) get_option(self::LOCK_OPTION, ''));
        if ((string) ($existing['owner'] ?? '') !== $owner) {
            $this->log('Sports South inventory sync lock not released; owner changed.', [
                'expected_owner' => $owner,
                'existing_owner' => (string) ($existing['owner'] ?? ''),
                'existing_mode' => (string) ($existing['mode'] ?? ''),
            ]);
            return;
        }

        delete_option(self::LOCK_OPTION);
        $this->log('Sports South inventory sync lock released.', [
            'owner' => $owner,
            'mode' => (string) ($existing['mode'] ?? ''),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function decode_lock_payload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $value
     */
    private function encode_json(array $value): string
    {
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '{}';
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function cron_logger(): CronRunLogger
    {
        return CronRunLogger::create(self::DEBUG_FLAG, self::LOG_PREFIX);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        $this->cron_logger()->logAlways($message, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $this->cron_logger()->profileAlways($label, $t0, $ctx);
    }
}
