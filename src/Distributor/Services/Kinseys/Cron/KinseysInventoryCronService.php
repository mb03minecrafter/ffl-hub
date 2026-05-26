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

final class KinseysInventoryCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_kinseys_pricing_quantity_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][KinseysInventoryCron]';
    private const DEFAULT_TIMEOUT_SECONDS = 180;
    private const FAILURE_COOLDOWN_SECONDS = 900;
    private const FAILURE_COOLDOWN_TRANSIENT = 'fflhub_kinseys_inventory_failure_cooldown';

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
        $interval = (int) apply_filters('fflhub_kinseys_inventory_interval_seconds', HOUR_IN_SECONDS);
        return max(15 * MINUTE_IN_SECONDS, $interval);
    }

    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    protected function get_initial_delay_seconds(): int
    {
        return 3 * MINUTE_IN_SECONDS;
    }

    public function run(): void
    {
        $t_start = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        $timeout_seconds = $this->get_timeout_seconds();

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        update_option('fflhub_kinseys_inventory_last_run', current_time('mysql'), false);

        $table_ctx = $this->table_context();
        $this->log('---- RUN START ----', [
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'hook' => self::CRON_HOOK,
            'group' => $this->get_action_group(),
            'timeout_sec' => $timeout_seconds,
            'live_table' => $table_ctx['live_table'],
            'memory_kb' => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
            'memory_peak_kb' => $this->memory_peak_kb(),
        ]);

        $client = $this->make_client($timeout_seconds);
        if (!$client->has_credentials()) {
            update_option('fflhub_kinseys_inventory_last_error', current_time('mysql'), false);
            $this->log('Missing Kinsey\'s API credentials; inventory update skipped.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing credentials)');
            return;
        }

        $cooldown = $this->active_failure_cooldown();
        if ($cooldown !== null) {
            $this->log('Skipping Kinsey\'s inventory update due to recent API failure cooldown.', $cooldown);
            $this->finalize_run($t_start, $mem_start, 'SUCCESS (failure cooldown)', $cooldown);
            return;
        }

        $parser = new KinseysProductParser();

        $t_inventory = microtime(true);
        $this->log('PHASE START: get_inventory', [
            'timeout_sec' => $timeout_seconds,
            'memory_kb' => $this->memory_kb(),
            'memory_peak_kb' => $this->memory_peak_kb(),
        ]);
        $inventory = $client->get_inventory();
        $inventory_data = (array) ($inventory['data'] ?? []);
        $inventory_rows = $parser->normalize_inventory_rows($inventory_data);
        $this->profile('get_inventory', $t_inventory, array_merge([
            'ok' => empty($inventory['ok']) ? 0 : 1,
            'status' => (int) ($inventory['status'] ?? 0),
            'timeout_sec' => $timeout_seconds,
            'response_bytes' => (int) ($inventory['response_bytes'] ?? 0),
        ], DebugLogUtil::summarize_array_keys($inventory_data), [
            'inventory_rows' => count($inventory_rows),
        ]));

        if (empty($inventory['ok'])) {
            update_option('fflhub_kinseys_inventory_last_error', current_time('mysql'), false);
            $this->log('Kinsey\'s inventory request failed.', [
                'status' => (int) ($inventory['status'] ?? 0),
                'error' => (string) ($inventory['error'] ?? ''),
            ]);
            $this->set_failure_cooldown($inventory);
            $this->finalize_run($t_start, $mem_start, 'ERROR (inventory request failed)', [
                'status' => (int) ($inventory['status'] ?? 0),
                'error' => (string) ($inventory['error'] ?? ''),
            ]);
            return;
        }

        $t_apply = microtime(true);
        $importer = new KinseysProductImporterService($this->table, $parser);
        $this->log('PHASE START: apply_inventory_array_to_live', [
            'inventory_rows' => count($inventory_rows),
            'live_table' => $table_ctx['live_table'],
            'memory_kb' => $this->memory_kb(),
            'memory_peak_kb' => $this->memory_peak_kb(),
        ]);
        $stats = $importer->apply_inventory_array_to_live($inventory_data);
        $this->profile('apply_inventory_array_to_live', $t_apply, $stats);

        update_option('fflhub_kinseys_inventory_last_update', current_time('mysql'), false);
        update_option('fflhub_kinseys_inventory_last_update_count', (int) ($stats['rows_loaded'] ?? 0), false);
        delete_option('fflhub_kinseys_inventory_last_error');
        $this->clear_failure_cooldown();

        $this->log('Kinsey\'s inventory update complete.', $stats);
        $this->finalize_run($t_start, $mem_start, 'SUCCESS', $stats);
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
            'fflhub_kinseys_inventory_timeout_seconds',
            self::DEFAULT_TIMEOUT_SECONDS
        );

        return max(30, min(300, $timeout_seconds));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function active_failure_cooldown(): ?array
    {
        if (!function_exists('get_transient')) {
            return null;
        }

        $cooldown = get_transient(self::FAILURE_COOLDOWN_TRANSIENT);
        if (!is_array($cooldown)) {
            return null;
        }

        $until = (int) ($cooldown['until'] ?? 0);
        $now = time();
        if ($until <= $now) {
            $this->clear_failure_cooldown();
            return null;
        }

        return [
            'skip_for_sec' => max(0, $until - $now),
            'failed_at' => (string) ($cooldown['failed_at'] ?? ''),
            'status' => (int) ($cooldown['status'] ?? 0),
            'error' => (string) ($cooldown['error'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $inventory
     */
    private function set_failure_cooldown(array $inventory): void
    {
        if (!function_exists('set_transient') || !$this->is_cooldown_worthy_failure($inventory)) {
            return;
        }

        $cooldown_seconds = $this->get_failure_cooldown_seconds();
        if ($cooldown_seconds <= 0) {
            return;
        }

        $payload = [
            'until' => time() + $cooldown_seconds,
            'failed_at' => current_time('mysql'),
            'status' => (int) ($inventory['status'] ?? 0),
            'error' => (string) ($inventory['error'] ?? ''),
        ];

        set_transient(self::FAILURE_COOLDOWN_TRANSIENT, $payload, $cooldown_seconds);
        $this->log('Kinsey\'s inventory API failure cooldown armed.', [
            'cooldown_sec' => $cooldown_seconds,
            'status' => $payload['status'],
            'error' => $payload['error'],
        ]);
    }

    private function clear_failure_cooldown(): void
    {
        if (function_exists('delete_transient')) {
            delete_transient(self::FAILURE_COOLDOWN_TRANSIENT);
        }
    }

    /**
     * @param array<string,mixed> $inventory
     */
    private function is_cooldown_worthy_failure(array $inventory): bool
    {
        $status = (int) ($inventory['status'] ?? 0);
        $error = strtolower((string) ($inventory['error'] ?? ''));

        return $status === 0
            || $status === 429
            || strpos($error, 'timed out') !== false
            || strpos($error, 'timeout') !== false
            || strpos($error, 'could not resolve') !== false
            || strpos($error, 'couldn\'t connect') !== false;
    }

    private function get_failure_cooldown_seconds(): int
    {
        $cooldown_seconds = (int) apply_filters(
            'fflhub_kinseys_inventory_failure_cooldown_seconds',
            self::FAILURE_COOLDOWN_SECONDS
        );

        return max(0, min(3600, $cooldown_seconds));
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
     * @return array{live_table:string}
     */
    private function table_context(): array
    {
        try {
            return [
                'live_table' => (string) $this->table->get_live_table_name(),
            ];
        } catch (\Throwable $e) {
            return [
                'live_table' => '',
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
