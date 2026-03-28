<?php

namespace FFLHub\Distributor\Services\Orders\Shipping\Cron;

use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * DealerFulfilledCronService
 *
 * Iterates dealer-fulfilled job rows only.
 * No write/business logic is performed yet.
 */
final class DealerFulfilledCronService extends AbstractCronService
{
    private const LOG_PREFIX  = '[FFLHUB][DealerFulfilledPoller]';
    private const DEBUG_CONST = 'FFLHUB_DEBUG_SHIPPING';

    public const CRON_HOOK = 'fflhub_place_dealer_fulfilled_poll';

    private const BATCH_LIMIT = 50;

    private OrderPlacementJobsTable $jobs_table;

    public function __construct(OrderPlacementJobsTable $jobs_table)
    {
        $this->jobs_table = $jobs_table;
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    public function get_action_group(): string
    {
        return 'fflhub_shipping';
    }

    protected function get_interval_seconds(): int
    {
        return 15 * MINUTE_IN_SECONDS;
    }

    protected function get_initial_delay_seconds(): int
    {
        return 60;
    }

    public function run(): void
    {
        $run_started = microtime(true);
        $limit = max(1, (int) self::BATCH_LIMIT);

        $stats = [
            'eligible' => 0,
            'iterated' => 0,
            'invalid'  => 0,
        ];

        $this->log_ctx('run_start', [
            'pid'       => function_exists('getmypid') ? (int) getmypid() : null,
            'memory_kb' => (int) (memory_get_usage(true) / 1024),
            'limit'     => $limit,
        ]);

        try {
            $t0 = microtime(true);
            $jobs = OrderPlacementJobsRepository::find_jobs_for_dealer_fulfilled_iteration(
                $this->jobs_table,
                OrderPlacementKeys::JOB_STATUS_SUCCESS,
                $limit
            );
            $this->log_ctx('repo_results', [
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                'count'      => is_array($jobs) ? count($jobs) : 0,
            ]);
        } catch (\Throwable $e) {
            $this->log_ctx('repo_exception', [
                'op'   => 'find_jobs_for_dealer_fulfilled_iteration',
                'err'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return;
        }

        if (empty($jobs)) {
            $this->log_ctx('run_end', [
                'reason'     => 'no_jobs',
                'elapsed_ms' => (int) round((microtime(true) - $run_started) * 1000),
                'memory_kb'  => (int) (memory_get_usage(true) / 1024),
                'stats'      => $stats,
            ]);
            return;
        }

        $stats['eligible'] = count($jobs);

        foreach ($jobs as $job) {
            $order_id = (int) ($job->order_id ?? 0);
            $job_key  = OrderPlacementKeysUtil::normalize_job_key((string) ($job->job_key ?? ''));
            $dist_id  = (string) ($job->dist_id ?? '');
            $lane     = (string) ($job->lane ?? '');

            if ($order_id <= 0 || $job_key === '' || $dist_id === '') {
                $stats['invalid']++;
                $this->log_ctx('skip_invalid_row', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'lane'     => $lane,
                ]);
                continue;
            }

            $stats['iterated']++;

            $this->log_ctx('job_candidate', [
                'order_id'              => $order_id,
                'job_key'               => $job_key,
                'dist_id'               => $dist_id,
                'lane'                  => $lane,
                'status'                => (string) ($job->status ?? ''),
                'merchant_po'           => (string) ($job->merchant_po ?? ''),
                'last_shipping_poll_at' => $job->last_shipping_poll_at ?? null,
                'shipped_at'            => $job->shipped_at ?? null,
                'tracking_numbers_json' => $job->tracking_numbers_json ?? null,
            ]);
        }

        $this->log_ctx('run_end', [
            'elapsed_ms' => (int) round((microtime(true) - $run_started) * 1000),
            'memory_kb'  => (int) (memory_get_usage(true) / 1024),
            'stats'      => $stats,
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

