<?php

namespace FFLHub\Distributor\Services\Lipseys\Cron;

if (!defined('ABSPATH')) {
    exit;
}

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

    // Persisted nextUpdate (what Lipsey's says is the next feed epoch)
    private const OPT_NEXT_UPDATE_RAW  = 'fflhub_lipseys_pq_next_update_raw';
    private const OPT_NEXT_UPDATE_UNIX = 'fflhub_lipseys_pq_next_update_unix';
    private const OPT_NEXT_CHECK_AT    = 'fflhub_lipseys_pq_next_update_checked_at';


    private const FILE_LOG_ENABLED = true;
    private const FILE_LOG_NAME    = 'lipseys_cron.log';



    public function __construct(DoubleBufferedFulfillmentTable $table)
    {
        parent::__construct($table);

        // Wire the worker hook here so you don't have to refactor bootstrap code.
        add_action(
            LipseysInventoryWorkerJob::HOOK,
            function ($args = []) use ($table) {
                LipseysInventoryWorkerJob::run($table, is_array($args) ? $args : []);
            },
            10,
            1
        );
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_interval_seconds(): int
    {
        // Keep your existing recurrence unless you change it.
        return 600;
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

        /*
    =========================================================
    0️⃣ Credentials (cheap — OK to always do)
    =========================================================
    */
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

        /*
    =========================================================
    1️⃣ HARD GATE — ZERO NETWORK PATH
    =========================================================
    */

        $now = time();

        $stored_unix = (int) get_option(self::OPT_NEXT_UPDATE_UNIX, 0);
        $stored_raw  = (string) get_option(self::OPT_NEXT_UPDATE_RAW, '');

        $have_epoch = ($stored_unix > 0);

        if ($have_epoch && $now < $stored_unix) {

            // 🔥 THIS IS THE MONEY PATH
            // No client creation
            // No network
            // Instant exit

            $this->log('Gatekeeper FAST EXIT', [
                'now_unix'         => $now,
                'stored_next_unix' => $stored_unix,
                'stored_next_raw'  => $stored_raw !== '' ? $stored_raw : null,
                'seconds_early'    => ($stored_unix - $now),
            ]);

            $this->finalize_run($t_start, $mem_start, 'NOOP (nextUpdate in future)', [
                'next_unix' => $stored_unix,
                'next_raw'  => $stored_raw !== '' ? $stored_raw : null,
            ]);

            return;
        }

        /*
    =========================================================
    2️⃣ ONLY NOW DO WE TOUCH NETWORK
    =========================================================
    */

        $this->log('Gatekeeper PASS — checking vendor nextUpdate', [
            'now_unix'       => $now,
            'stored_unix'    => $stored_unix,
            'reason'         => $have_epoch ? 'now >= stored_next' : 'no stored epoch (bootstrap)',
        ]);

        /*
    ---------- Client ----------
    */
        $t_client = microtime(true);

        try {
            $client = new LipseysClient($dealer_email, $dealer_password);
        } catch (\Throwable $e) {
            $this->log('ERROR: exception creating LipseysClient', ['error' => $e->getMessage()]);
            $this->profile('Client creation (failed)', $t_client);
            $this->finalize_run($t_start, $mem_start, 'ERROR (client creation)');
            return;
        }

        $this->profile('Client creation', $t_client);

        /*
    ---------- Fast NextUpdate ----------
    */
        $t_fast = microtime(true);

        try {
            $r = $client->PricingAndQuantityNextUpdateFast();
        } catch (\Throwable $e) {
            $this->log('ERROR: PricingAndQuantityNextUpdateFast exception', ['error' => $e->getMessage()]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (fast path exception)');
            return;
        }

        $this->profile('PricingAndQuantityNextUpdateFast()', $t_fast, [
            'success'    => !empty($r['success']) ? 1 : 0,
            'next_unix'  => $r['next_update_unix'] ?? null,
            'next_raw'   => $r['next_update_raw'] ?? null,
        ]);

        update_option(self::OPT_NEXT_CHECK_AT, current_time('mysql'));

        /*
    =========================================================
    3️⃣ Persist new vendor epoch
    =========================================================
    */

        if (!empty($r['success']) && !empty($r['next_update_unix'])) {

            $stored_unix = (int) $r['next_update_unix'];
            $stored_raw  = is_string($r['next_update_raw'] ?? null) ? $r['next_update_raw'] : '';

            update_option(self::OPT_NEXT_UPDATE_UNIX, $stored_unix);
            update_option(self::OPT_NEXT_UPDATE_RAW, $stored_raw);
        }

        /*
    =========================================================
    4️⃣ If vendor says future → EXIT
    =========================================================
    */

        if ($stored_unix > 0 && time() < $stored_unix) {

            $this->finalize_run($t_start, $mem_start, 'NOOP (vendor says not ready)', [
                'next_unix' => $stored_unix,
                'next_raw'  => $stored_raw,
            ]);

            return;
        }

        /*
    =========================================================
    5️⃣ Schedule worker
    =========================================================
    */

        $args = [
            'expected_next_update_unix' => (int) $stored_unix,
            'expected_next_update_raw'  => $stored_raw,
        ];

        $group = $this->get_action_group();
        $hook  = LipseysInventoryWorkerJob::HOOK;

        $already = as_next_scheduled_action($hook, $args, $group);

        if ($already !== false) {
            $this->finalize_run($t_start, $mem_start, 'NOOP (worker already scheduled)');
            return;
        }

        $run_at = time() + 10;

        as_schedule_single_action($run_at, $hook, $args, $group);

        $this->finalize_run($t_start, $mem_start, 'SCHEDULED WORKER', [
            'run_at' => $run_at,
        ]);
    }


    private function log(string $msg, array $ctx = []): void
    {
        // Existing WP debug log
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $msg);
        } else {
            DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $msg, $ctx);
        }

        // 🔥 New dedicated file log
        $this->file_log(self::LOG_PREFIX . ' ' . $msg, $ctx);
    }


    /** @param array<string,mixed> $ctx */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $elapsed_ms = (microtime(true) - $t0) * 1000.0;
        $ctx = array_merge($ctx, ['elapsed_ms' => number_format($elapsed_ms, 2, '.', '')]);
        $this->log("PROFILE: {$label}", $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function finalize_run(float $t_start, int $mem_start, string $status, array $ctx = []): void
    {
        $this->profile('Total cron run', $t_start, ['status' => (string) $status]);

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


    private function file_log(string $msg, array $ctx = []): void
    {
        if (!self::FILE_LOG_ENABLED) {
            return;
        }

        try {
            $upload_dir = wp_upload_dir();
            if (empty($upload_dir['basedir'])) {
                return;
            }

            $dir = trailingslashit($upload_dir['basedir']) . 'fflhub/logs';

            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }

            if (!is_dir($dir) || !is_writable($dir)) {
                return;
            }

            $file = trailingslashit($dir) . self::FILE_LOG_NAME;

            $line = sprintf(
                "[%s][pid:%d] %s",
                gmdate('Y-m-d H:i:s'),
                function_exists('getmypid') ? (int)getmypid() : 0,
                $msg
            );

            if (!empty($ctx)) {
                $line .= ' ' . wp_json_encode($ctx);
            }

            $line .= PHP_EOL;

            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Never allow logging to break cron
        }
    }
}
