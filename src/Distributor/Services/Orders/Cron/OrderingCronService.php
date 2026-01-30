<?php

namespace FFLHub\Distributor\Services\Orders\Cron;

use FFLHub\Distributor\Core\DistributorHandler;
use WC_Order;

use FFLHub\Distributor\Services\Cron\AbstractCronService;

use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementPipelineMetaStore;

use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;

use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobRunner;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Order placement dispatcher (recurring).
 *
 * Pulls DB-marked jobs (scheduled / retry_scheduled) that are ready to run,
 * ordered by next_run_at ASC, and executes them via OrderPlacementJobRunner.
 */
final class OrderingCronService extends AbstractCronService
{
    private const LOG_PREFIX  = '[FFLHUB][PlaceDispatcher]';
    private const DEBUG_CONST = 'FFLHUB_DEBUG_PLACE_DISPATCH';

    /**
     * Action Scheduler hook name.
     */
    public const CRON_HOOK = 'fflhub_place_order_poll';

    /**
     * How many jobs to process per run.
     */
    private const BATCH_LIMIT = 50;


    private DistributorHandler $handler;
    private OrderPlacementJobsTable $jobs_table;

    public function __construct(DistributorHandler $handler, OrderPlacementJobsTable $jobs_table)
    {
        $this->handler = $handler;
        $this->jobs_table = $jobs_table;
    }


    /**
     * How often the dispatcher runs.
     */
    protected function get_interval_seconds(): int
    {
        return 15; // 1 minute (tune later)
    }

    /**
     * Initial delay before first run.
     */
    protected function get_initial_delay_seconds(): int
    {
        return 60; // 1 minute
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    public function get_action_group(): string
    {
        return 'fflhub_place';
    }

    public function run(): void
    {
        $run_started = microtime(true);

        $now_mysql_utc = OrderPlacementTimeUtil::now_mysql_utc();
        $limit = max(1, (int) self::BATCH_LIMIT);

        $eligible_statuses = [
            OrderPlacementKeys::JOB_STATUS_SCHEDULED,
            OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED,
        ];

        // -----------------------------
        // Perf accumulators (ms)
        // -----------------------------
        $perf = [
            'repo_find_ms'          => 0,
            'job_gate_ms_total'     => 0,
            'job_suspend_ms_total'  => 0,
            'job_getorder_ms_total' => 0,
            'job_runner_ms_total'   => 0,

            'job_gate_ms_max'       => 0,
            'job_suspend_ms_max'    => 0,
            'job_getorder_ms_max'   => 0,
            'job_runner_ms_max'     => 0,

            'job_total_ms_max'      => 0,
        ];

        $this->log_ctx('run_start', [
            'hook'             => self::CRON_HOOK,
            'group'            => $this->get_action_group(),
            'interval_seconds' => $this->get_interval_seconds(),
            'initial_delay_s'  => $this->get_initial_delay_seconds(),
            'now_mysql_utc'    => $now_mysql_utc,
            'batch_limit'      => $limit,
            'status_filter'    => $eligible_statuses,
        ]);

        // -----------------------------
        // Repo fetch timing
        // -----------------------------
        $repo_started = microtime(true);

        $jobs = [];
        try {
            $jobs = OrderPlacementJobsRepository::find_jobs_ready_for_processing(
                $this->jobs_table,
                $eligible_statuses,
                $now_mysql_utc,
                $limit
            );
        } catch (\Throwable $e) {
            $this->log_ctx('repo_exception', [
                'op'   => 'find_jobs_ready_for_processing',
                'err'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return;
        } finally {
            $perf['repo_find_ms'] = (int) round((microtime(true) - $repo_started) * 1000);
            $this->log_ctx('repo_find_finish', [
                'elapsed_ms' => $perf['repo_find_ms'],
                'returned'   => is_array($jobs) ? count($jobs) : 0,
            ]);
        }

        if (empty($jobs)) {
            $this->log('no jobs eligible for placement dispatch');
            $this->log_ctx('run_finish', [
                'eligible_jobs' => 0,
                'perf'          => $perf,
                'elapsed_ms'    => (int) round((microtime(true) - $run_started) * 1000),
            ]);
            return;
        }

        $this->log_ctx('eligible_jobs', [
            'count' => count($jobs),
            'limit' => $limit,
            'now'   => $now_mysql_utc,
        ]);

        $stats = [
            'total'            => 0,
            'skipped_invalid'   => 0,
            'skipped_suspended' => 0,
            'skipped_no_order'  => 0,
            'runner_ok'         => 0,
            'runner_exception'  => 0,
        ];

        foreach ($jobs as $job) {
            $job_started = microtime(true);
            $stats['total']++;

            // -----------------------------
            // Gate/normalize timing
            // -----------------------------
            $t_gate = microtime(true);

            $order_id = (int) ($job->order_id ?? 0);
            $job_key  = OrderPlacementKeysUtil::normalize_job_key((string) ($job->job_key ?? ''));

            $dist_id  = (string) ($job->dist_id ?? '');
            $bucket   = (string) ($job->bucket ?? '');
            $status   = (string) ($job->status ?? '');
            $next_run = (string) ($job->next_run_at ?? '');

            $gate_ms = (int) round((microtime(true) - $t_gate) * 1000);
            $perf['job_gate_ms_total'] += $gate_ms;
            if ($gate_ms > $perf['job_gate_ms_max']) {
                $perf['job_gate_ms_max'] = $gate_ms;
            }

            if ($order_id <= 0 || $job_key === '') {
                $stats['skipped_invalid']++;
                $this->log_ctx('skip_invalid_job_row', [
                    'order_id'  => $order_id,
                    'job_key'   => $job_key,
                    'dist_id'   => $dist_id,
                    'bucket'    => $bucket,
                    'status'    => $status,
                    'next_run'  => $next_run,
                    'gate_ms'   => $gate_ms,
                ]);
                continue;
            }

            // -----------------------------
            // Suspended check timing
            // -----------------------------
            $t_susp = microtime(true);


            //TODO: move this fucntion and the key associated with it to the keys file????
            $is_suspended = OrderPlacementPipelineMetaStore::is_order_suspended($order_id);

            $susp_ms = (int) round((microtime(true) - $t_susp) * 1000);
            $perf['job_suspend_ms_total'] += $susp_ms;
            if ($susp_ms > $perf['job_suspend_ms_max']) {
                $perf['job_suspend_ms_max'] = $susp_ms;
            }

            if ($is_suspended) {
                $stats['skipped_suspended']++;
                $this->log_ctx('skip_suspended_order', [
                    'order_id'     => $order_id,
                    'job_key'      => $job_key,
                    'dist_id'      => $dist_id,
                    'bucket'       => $bucket,
                    'status'       => $status,
                    'next_run'     => $next_run,
                    'gate_ms'      => $gate_ms,
                    'suspended_ms' => $susp_ms,
                ]);
                continue;
            }

            // -----------------------------
            // wc_get_order timing
            // -----------------------------
            $t_get = microtime(true);

            $order = wc_get_order($order_id);

            $get_ms = (int) round((microtime(true) - $t_get) * 1000);
            $perf['job_getorder_ms_total'] += $get_ms;
            if ($get_ms > $perf['job_getorder_ms_max']) {
                $perf['job_getorder_ms_max'] = $get_ms;
            }

            if (!($order instanceof WC_Order)) {
                $stats['skipped_no_order']++;
                $this->log_ctx('skip_order_not_found', [
                    'order_id'     => $order_id,
                    'job_key'      => $job_key,
                    'dist_id'      => $dist_id,
                    'bucket'       => $bucket,
                    'gate_ms'      => $gate_ms,
                    'suspended_ms' => $susp_ms,
                    'get_order_ms' => $get_ms,
                ]);
                continue;
            }

            $this->log_ctx('dispatch_job_start', [
                'order_id' => $order_id,
                'job_key'  => $job_key,
                'dist_id'  => $dist_id,
                'bucket'   => $bucket,
                'status'   => $status,
                'next_run' => $next_run,
                'gate_ms'  => $gate_ms,
                'susp_ms'  => $susp_ms,
                'get_ms'   => $get_ms,
            ]);

            // -----------------------------
            // Runner timing
            // -----------------------------
            $t_runner = microtime(true);

            try {
                OrderPlacementJobRunner::run($order, $job_key, $this->handler);
                $stats['runner_ok']++;
            } catch (\Throwable $e) {
                $stats['runner_exception']++;
                $this->log_ctx('runner_exception', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'bucket'   => $bucket,
                    'err'      => $e->getMessage(),
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine(),
                ]);
                // Intentionally do not rethrow; keep draining other rows.
            } finally {
                $runner_ms = (int) round((microtime(true) - $t_runner) * 1000);
                $perf['job_runner_ms_total'] += $runner_ms;
                if ($runner_ms > $perf['job_runner_ms_max']) {
                    $perf['job_runner_ms_max'] = $runner_ms;
                }

                $job_total_ms = (int) round((microtime(true) - $job_started) * 1000);
                if ($job_total_ms > $perf['job_total_ms_max']) {
                    $perf['job_total_ms_max'] = $job_total_ms;
                }

                $this->log_ctx('dispatch_job_finish', [
                    'order_id'     => $order_id,
                    'job_key'      => $job_key,
                    'dist_id'      => $dist_id,
                    'bucket'       => $bucket,
                    'gate_ms'      => $gate_ms,
                    'suspended_ms' => $susp_ms,
                    'get_order_ms' => $get_ms,
                    'runner_ms'    => $runner_ms,
                    'elapsed_ms'   => $job_total_ms,
                ]);
            }
        }

        $eligible_count = count($jobs);

        // Avoid div-by-zero
        $den = max(1, (int) $stats['total']);

        $perf_rollup = $perf + [
            'job_gate_ms_avg'     => (int) round($perf['job_gate_ms_total'] / $den),
            'job_suspend_ms_avg'  => (int) round($perf['job_suspend_ms_total'] / $den),
            'job_getorder_ms_avg' => (int) round($perf['job_getorder_ms_total'] / $den),
            'job_runner_ms_avg'   => (int) round($perf['job_runner_ms_total'] / $den),
        ];

        $this->log_ctx('run_finish', [
            'eligible_jobs' => $eligible_count,
            'stats'         => $stats,
            'perf'          => $perf_rollup,
            'elapsed_ms'    => (int) round((microtime(true) - $run_started) * 1000),
        ]);
    }

    private function log(string $msg): void
    {
        DebugLogUtil::log(self::DEBUG_CONST, self::LOG_PREFIX, $msg);
    }

    /** @param array<string,mixed> $ctx */
    private function log_ctx(string $msg, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $msg, $ctx);
    }
}
