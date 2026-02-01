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
        $this->handler   = $handler;
        $this->jobs_table = $jobs_table;
    }

    protected function get_interval_seconds(): int
    {
        // Tune later (you had 15 seconds during profiling)
        return 15;
    }

    protected function get_initial_delay_seconds(): int
    {
        return 60;
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
        $now_mysql_utc = OrderPlacementTimeUtil::now_mysql_utc();
        $limit = max(1, (int) self::BATCH_LIMIT);

        $eligible_statuses = [
            OrderPlacementKeys::JOB_STATUS_SCHEDULED,
            OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED,
        ];

        try {
            $jobs = OrderPlacementJobsRepository::find_jobs_ready_for_processing(
                $this->jobs_table,
                $eligible_statuses,
                $now_mysql_utc,
                $limit
            );
        } catch (\Throwable $e) {
            // Keep silent in production; dispatcher will try again next tick.
            // Optionally: error_log('[FFLHUB][PlaceDispatcher] repo exception: ' . $e->getMessage());
            return;
        }

        if (empty($jobs)) {
            return;
        }

        foreach ($jobs as $job) {
            $order_id = (int) ($job->order_id ?? 0);
            $job_key  = OrderPlacementKeysUtil::normalize_job_key((string) ($job->job_key ?? ''));

            if ($order_id <= 0 || $job_key === '') {
                continue;
            }

            // Order-level gate: trashed/suspended orders do not dispatch jobs.
            if (OrderPlacementPipelineMetaStore::is_order_suspended($order_id)) {
                continue;
            }

            $order = wc_get_order($order_id);
            if (!($order instanceof WC_Order)) {
                continue;
            }

            try {
                OrderPlacementJobRunner::run($this->jobs_table, $order, $job_key, $this->handler);
            } catch (\Throwable $e) {
                // Intentionally do not rethrow; keep draining other rows.
                // Optionally: error_log('[FFLHUB][PlaceDispatcher] runner exception: ' . $e->getMessage());
                continue;
            }
        }
    }
}
