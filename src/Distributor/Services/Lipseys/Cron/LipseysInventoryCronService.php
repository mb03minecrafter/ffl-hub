<?php

namespace FFLHub\Distributor\Services\Lipseys\Cron;

if (!defined('ABSPATH')) exit;

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Lipseys\LipseysRawAPI\LipseysClient;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

final class LipseysInventoryCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_lipseys_pricing_quantity_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHUB][LipseysInventoryCron]';

    private const OPT_LAST_SEEN_VERSION     = 'fflhub_lipseys_last_seen_version';
    private const OPT_NEXT_UPDATE_UNIX      = 'fflhub_lipseys_next_update_unix';

    private const FILE_LOG_NAME = 'lipseys_cron.log';

    // Singleton worker identity (dedupe key): hook + args + group
    private const WORKER_ARGS  = ['singleton' => 1];
    private const WORKER_GROUP = 'fflhub_catalog';

    // Buffer after vendor says update is ready
    private const WORKER_SKEW_SEC = 60;

    // If vendor unix missing/invalid, schedule soon so we recover quickly.
    private const BOOTSTRAP_DELAY_SEC = 60;

    public function __construct(DoubleBufferedFulfillmentTable $table)
    {
        parent::__construct($table);

        add_action(
            LipseysInventoryWorkerJob::HOOK,
            function ($singleton = null) use ($table) {
                LipseysInventoryWorkerJob::run($table);
            },
            10,
            1
        );
    }

    public function get_cron_hook_name(): string { return self::CRON_HOOK; }
    protected function get_interval_seconds(): int { return 600; }
    public function get_action_group(): string { return self::WORKER_GROUP; }

    /**
     * Bootstrap/repair loop:
     * - If singleton worker is already scheduled: do nothing (no vendor call).
     * - If missing: call NextUpdateFast() ONCE to seed nextUpdate, persist it, schedule worker.
     *
     * In steady state, the worker schedules itself, so this should almost never call vendor.
     */
    public function run(): void
    {
        $t0 = microtime(true);

        $this->log('RUN START', [
            'pid'    => function_exists('getmypid') ? (int) getmypid() : 0,
            'mem_kb' => function_exists('memory_get_usage') ? (int) round(memory_get_usage(true) / 1024) : 0,
        ]);

        if (!function_exists('as_next_scheduled_action') || !function_exists('as_schedule_single_action')) {
            $this->log('Action Scheduler unavailable — cannot bootstrap worker');
            $this->log('RUN END', ['elapsed_ms' => (int) round((microtime(true) - $t0) * 1000)]);
            return;
        }

        $hook  = LipseysInventoryWorkerJob::HOOK;
        $args  = self::WORKER_ARGS;
        $group = self::WORKER_GROUP;

        $already = as_next_scheduled_action($hook, $args, $group);
        if ($already !== false) {
            $this->log('Worker already scheduled — skip vendor call', [
                'scheduled_for' => (int) $already,
            ]);
            $this->log('RUN END', ['elapsed_ms' => (int) round((microtime(true) - $t0) * 1000)]);
            return;
        }

        $email = (string) Options::get_distributor_option('lipseys', 'dealer_email', '');
        $pass  = (string) Options::get_distributor_option('lipseys', 'dealer_password', '');

        if ($email === '' || $pass === '') {
            $this->log('Missing credentials — cannot bootstrap worker');
            $this->log('RUN END', ['elapsed_ms' => (int) round((microtime(true) - $t0) * 1000)]);
            return;
        }

        try {
            $client = new LipseysClient($email, $pass);
        } catch (\Throwable $e) {
            $this->log('Client creation failed', ['error' => $e->getMessage()]);
            $this->log('RUN END', ['elapsed_ms' => (int) round((microtime(true) - $t0) * 1000)]);
            return;
        }

        $tFast = microtime(true);
        try {
            $r = $client->PricingAndQuantityNextUpdateFast();
        } catch (\Throwable $e) {
            $this->log('NextUpdateFast exception', ['error' => $e->getMessage()]);
            $this->log('RUN END', ['elapsed_ms' => (int) round((microtime(true) - $t0) * 1000)]);
            return;
        }

        $this->log('NEXT UPDATE FAST RESULT (bootstrap)', [
            'elapsed_ms' => round((microtime(true) - $tFast) * 1000, 2),
            'success'    => !empty($r['success']),
            'next_raw'   => $r['next_update_raw'] ?? null,
            'next_unix'  => $r['next_update_unix'] ?? null,
        ]);

        if (empty($r['success'])) {
            $this->log('Bootstrap fast path failed', is_array($r) ? $r : ['result' => $r]);
            $this->log('RUN END', ['elapsed_ms' => (int) round((microtime(true) - $t0) * 1000)]);
            return;
        }

        $nextVersion = (string) ($r['next_update_raw'] ?? '');
        $nextUnix    = (int) ($r['next_update_unix'] ?? 0);

        if ($nextVersion !== '') {
            update_option(self::OPT_LAST_SEEN_VERSION, $nextVersion);
        }
        if ($nextUnix > 0) {
            update_option(self::OPT_NEXT_UPDATE_UNIX, $nextUnix);
        }

        $target = ($nextUnix > 0)
            ? (int) max(time() + 10, $nextUnix + self::WORKER_SKEW_SEC)
            : (int) (time() + self::BOOTSTRAP_DELAY_SEC);

        as_schedule_single_action($target, $hook, $args, $group);

        $this->log('Worker scheduled (bootstrap)', [
            'next_version' => $nextVersion,
            'next_unix'    => $nextUnix,
            'target_unix'  => $target,
            'skew_sec'     => self::WORKER_SKEW_SEC,
        ]);

        $this->log('RUN END', [
            'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);
    }

    private function log(string $msg, array $ctx = []): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $msg, $ctx);
        $this->file_log(self::LOG_PREFIX . ' ' . $msg, $ctx);
    }

    private function file_log(string $msg, array $ctx = []): void
    {
        try {
            $up = wp_upload_dir();
            $basedir = (string) ($up['basedir'] ?? '');
            if ($basedir === '') return;

            $dir = rtrim($basedir, '/\\') . '/fflhub/logs';
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            if (!is_dir($dir) || !is_writable($dir)) return;

            $file = $dir . '/' . self::FILE_LOG_NAME;

            $pid = function_exists('getmypid') ? (int) getmypid() : 0;
            $line = '[' . gmdate('Y-m-d H:i:s') . '][pid:' . $pid . '] ' . $msg;
            if (!empty($ctx)) $line .= ' ' . wp_json_encode($ctx);
            $line .= PHP_EOL;

            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // never break cron
        }
    }
}
