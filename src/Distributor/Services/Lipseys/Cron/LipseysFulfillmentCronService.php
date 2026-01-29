<?php

namespace FFLHub\Distributor\Services\Lipseys\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Lipseys\LipseysFulfillmentImporterService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * WP-Cron job to pull the Lipsey's Catalog feed into a double-buffered table
 * and atomically swap staging ↔ live.
 */
final class LipseysFulfillmentCronService extends AbstractTableCronService
{
    /**
     * Cron hook name for Lipsey's catalog refresh.
     */
    public const CRON_HOOK = 'fflhub_lipseys_fulfillment_update';

    /**
     * Debug gate constant (define('FFLHUB_CRON_DEBUG', true);).
     */
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';

    /**
     * Log prefix.
     */
    private const LOG_PREFIX = '[FFLHUB][LipseysFulfillmentCron]';

    /**
     * @var LipseysFulfillmentImporterService|null
     */
    private ?LipseysFulfillmentImporterService $importer = null;

    /**
     * @param DoubleBufferedFulfillmentTable $table
     */
    public function __construct(DoubleBufferedFulfillmentTable $table)
    {
        parent::__construct($table);
    }

    /**
     * Lazily create / return the importer bound to our table.
     */
    private function get_importer(): LipseysFulfillmentImporterService
    {
        if ($this->importer === null) {
            $this->importer = new LipseysFulfillmentImporterService($this->table);
        }
        return $this->importer;
    }

    /**
     * Unique cron hook name.
     */
    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    /**
     * Interval length in seconds.
     */
    protected function get_interval_seconds(): int
    {
        return 4 * HOUR_IN_SECONDS;
    }

    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    /**
     * Delay before first run (keeps your old 5-minute initial delay).
     */
    protected function get_initial_delay_seconds(): int
    {
        return 5 * MINUTE_IN_SECONDS;
    }

    /**
     * Main cron callback:
     *  1) Create LipseysClient with dealer credentials.
     *  2) Call Catalog() to get the full feed.
     *  3) Extract the items array.
     *  4) Import items into the STAGING table via importer.
     *  5) Swap staging ↔ live if import succeeded.
     */
    public function run(): void
    {
        $t_start   = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $this->log('---- RUN START ----', [
            'pid'          => function_exists('getmypid') ? (int) getmypid() : 0,
            'memory_kb'    => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
            'hook'         => self::CRON_HOOK,
            'group'        => $this->get_action_group(),
            'interval_sec' => $this->get_interval_seconds(),
        ]);

        // 1) Pull credentials via centralized Options helper.
        $t_creds         = microtime(true);
        $dealer_email    = trim((string) Options::get_distributor_option('lipseys', 'dealer_email', ''));
        $dealer_password = trim((string) Options::get_distributor_option('lipseys', 'dealer_password', ''));

        $this->profile('Credentials retrieval', $t_creds, [
            'has_email'    => ($dealer_email !== ''),
            'has_password' => ($dealer_password !== ''),
        ]);

        if ($dealer_email === '' || $dealer_password === '') {
            $this->log('ERROR: dealer_email or dealer_password not set.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing credentials)');
            return;
        }

        // 2) Create the client.
        $t_client = microtime(true);
        try {
            $client = new \lipseys\ApiIntegration\LipseysClient(
                (string) $dealer_email,
                (string) $dealer_password
            );
        } catch (\Throwable $e) {
            $this->log('ERROR: exception creating LipseysClient', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Client creation (failed)', $t_client);
            $this->finalize_run($t_start, $mem_start, 'ERROR (client creation)');
            return;
        }
        $this->profile('Client creation', $t_client);

        // 3) Call Catalog() to get the feed.
        $t_catalog = microtime(true);
        try {
            $result = $client->Catalog();
        } catch (\Throwable $e) {
            $this->log('ERROR: exception calling Catalog()', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Catalog() call (failed)', $t_catalog);
            $this->finalize_run($t_start, $mem_start, 'ERROR (Catalog exception)');
            return;
        }
        $this->profile('Catalog() call', $t_catalog);

        if (!is_array($result)) {
            $this->log('ERROR: Catalog() did not return an array.', [
                'type' => is_object($result) ? get_class($result) : gettype($result),
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (Catalog not array)');
            return;
        }

        // 4) Extract items array from the response.
        $t_items = microtime(true);
        $items   = [];

        if (isset($result['items']) && is_array($result['items'])) {
            $items = $result['items'];
        } elseif (isset($result['data']) && is_array($result['data'])) {
            $items = $result['data'];
        } elseif ($this->is_list_of_items($result)) {
            $items = $result;
        }

        $items_count = is_array($items) ? count($items) : 0;

        $mem_mid = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        $this->profile('Items extraction', $t_items, [
            'count'          => (int) $items_count,
            'memory_kb_now'  => $mem_mid > 0 ? (int) round($mem_mid / 1024) : 0,
        ]);

        if (empty($items)) {
            $this->log('ERROR: no items found in Catalog() response.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (no items)');
            return;
        }

        // 5) Import into the STAGING table via the importer service.
        $t_import = microtime(true);
        $count = 0;

        try {
            $count = (int) $this->get_importer()->import_items_array($items);
        } catch (\Throwable $e) {
            $this->log('ERROR: exception during import', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Import into staging (failed)', $t_import, [
                'requested_items' => (int) $items_count,
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (import exception)');
            return;
        }

        $this->profile('Import into staging', $t_import, [
            'requested_items' => (int) $items_count,
            'imported_rows'   => (int) $count,
        ]);

        if ($count <= 0) {
            $this->log('WARNING: import completed but 0 rows processed, not swapping tables.', [
                'requested_items' => (int) $items_count,
            ]);
            $this->finalize_run($t_start, $mem_start, 'NO SWAP (0 imported)');
            return;
        }

        // 6) Swap staging ↔ live using the injected table.
        $t_swap = microtime(true);
        $new_live = '';

        try {
            $new_live = (string) $this->table->swap_live_and_staging();
        } catch (\Throwable $e) {
            $this->log('ERROR: exception during swap', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Swap staging ↔ live (failed)', $t_swap, [
                'imported_rows' => (int) $count,
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (swap exception)');
            return;
        }

        $this->profile('Swap staging ↔ live', $t_swap, [
            'new_live' => (string) $new_live,
        ]);

        // Mark success.
        update_option('fflhub_lipseys_fulfillment_last_refresh', current_time('mysql'));
        update_option('fflhub_lipseys_fulfillment_last_refresh_count', (int) $count);
        update_option('fflhub_lipseys_fulfillment_last_swap', current_time('mysql'));

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'imported_rows' => (int) $count,
            'new_live'      => (string) $new_live,
        ]);
    }

    /**
     * Heuristic: is this array basically a list of item arrays?
     *
     * @param array<string|int,mixed> $arr
     */
    protected function is_list_of_items(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }

        foreach ($arr as $key => $value) {
            if (!is_int($key) || !is_array($value)) {
                return false;
            }
        }

        return true;
    }

    // --------------------------------------------------
    // Debug / profiling helpers (DebugLogUtil)
    // --------------------------------------------------

    /** @param array<string,mixed> $ctx */
    private function log(string $msg, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $msg);
            return;
        }

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $msg, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $elapsed_ms = (microtime(true) - $t0) * 1000.0;

        $ctx = array_merge($ctx, [
            'elapsed_ms' => number_format($elapsed_ms, 2, '.', ''),
        ]);

        $this->log("PROFILE: {$label}", $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function finalize_run(float $t_start, int $mem_start, string $status, array $ctx = []): void
    {
        $this->profile('Total cron run', $t_start, [
            'status' => (string) $status,
        ]);

        $mem_end = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        if ($mem_start > 0 && $mem_end > 0) {
            $this->log('Memory usage summary', [
                'start_kb' => (int) round($mem_start / 1024),
                'end_kb'   => (int) round($mem_end / 1024),
                'delta_kb' => (int) round(($mem_end - $mem_start) / 1024),
            ]);
        }

        if (!empty($ctx)) {
            $this->log("---- RUN END ({$status}) ----", $ctx);
        } else {
            $this->log("---- RUN END ({$status}) ----");
        }
    }
}
