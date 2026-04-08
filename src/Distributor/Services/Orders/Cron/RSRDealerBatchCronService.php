<?php

namespace FFLHub\Distributor\Services\Orders\Cron;

use WC_Order;

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Distributor\Services\Orders\Jobs\Identifiers\OrderPlacementJobIdentifiersStore;
use FFLHub\Distributor\Services\Orders\Jobs\Lifecycle\OrderPlacementJobLifeCycle;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobRunner;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementPipelineMetaStore;
use FFLHub\Distributor\Services\Orders\Jobs\Snapshots\OrderPlacementJobSnapshotsStore;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementSnapshotUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RSRDealerBatchCronService
 *
 * Runs every minute and processes only RSR dealer-fulfilled rows in batch_pending status.
 *
 * Behavior:
 * - Low-stock risk is computed from SUMMED UPC demand across currently queued rows.
 * - Risky rows are flushed immediately as one aggregate "priority" batch call.
 * - Remaining rows are held until dispatch window (or force flush), then sent as one aggregate scheduled batch call.
 * - Aggregate retryable failures are re-queued in batch_pending with next_run_at delay.
 * - Aggregate non-retryable outcomes fall back to per-row dispatch for better salvage.
 */
final class RSRDealerBatchCronService extends AbstractCronService
{
    public const CRON_HOOK = 'fflhub_rsr_dealer_batch_poll';

    private const DEBUG_CONST = 'FFLHUB_DEBUG_ORDER_BATCH';
    private const LOG_PREFIX  = '[FFLHub][RSRDealerBatchCron]';

    private const LOCK_OPTION = 'fflhub_rsr_dealer_batch_lock';
    private const LOCK_TTL_SECONDS = 300;

    private const OPT_ENABLED            = 'fflhub_rsr_dealer_batch_enabled';
    private const OPT_DISPATCH_TIME      = 'fflhub_rsr_dealer_batch_dispatch_time';
    private const OPT_LOW_STOCK_THRESHOLD = 'fflhub_rsr_dealer_batch_low_stock_threshold';
    private const OPT_RETRY_DELAY_SECONDS = 'fflhub_rsr_dealer_batch_retry_delay_seconds';
    private const OPT_MAX_ROWS_PER_RUN    = 'fflhub_rsr_dealer_batch_max_rows_per_run';
    private const OPT_FORCE_FLUSH         = 'fflhub_rsr_dealer_batch_force_flush';

    private const DEFAULT_DISPATCH_TIME = '17:00';
    private const DEFAULT_LOW_STOCK_THRESHOLD = 3;
    private const DEFAULT_RETRY_DELAY_SECONDS = 300;
    private const DEFAULT_MAX_ROWS_PER_RUN = 200;

    private DistributorHandler $handler;
    private OrderPlacementJobsTable $jobs_table;
    private FFLTable $ffl_table;

    public function __construct(
        DistributorHandler $handler,
        OrderPlacementJobsTable $jobs_table,
        FFLTable $ffl_table
    ) {
        $this->handler = $handler;
        $this->jobs_table = $jobs_table;
        $this->ffl_table = $ffl_table;
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    public function get_action_group(): string
    {
        return 'fflhub_place';
    }

    protected function get_interval_seconds(): int
    {
        return 60;
    }

    protected function get_initial_delay_seconds(): int
    {
        return 60;
    }

    public function run(): void
    {
        $run_id = substr(md5((string) microtime(true) . '|' . (string) wp_rand()), 0, 10);
        $now_mysql_utc = OrderPlacementTimeUtil::now_mysql_utc();
        $run_started = microtime(true);

        if (!Options::is_distributor_enabled('rsr') || !$this->is_enabled()) {
            $this->log_ctx('skip_disabled', ['run_id' => $run_id]);
            return;
        }

        if (!$this->acquire_lock($run_id)) {
            $this->log_ctx('skip_locked', ['run_id' => $run_id]);
            return;
        }

        $run_status = 'idle';
        $run_stats = [
            'jobs_found' => 0,
            'eligible_rows' => 0,
            'priority_rows' => 0,
            'scheduled_rows' => 0,
            'failed_rows' => 0,
            'skipped_suspended' => 0,
            'dispatch_due' => 0,
            'force_flush' => 0,
            'risky_upcs' => 0,
            'priority_flushed_rows' => 0,
            'scheduled_flushed_rows' => 0,
        ];

        try {
            $max_rows = $this->max_rows_per_run();
            $low_threshold = $this->low_stock_threshold();
            $retry_delay = $this->retry_delay_seconds();

            $jobs = OrderPlacementJobsRepository::find_jobs_for_rsr_batch_processing(
                $this->jobs_table,
                $now_mysql_utc,
                $max_rows
            );

            $this->log_ctx('run_start', [
                'run_id' => $run_id,
                'jobs_found' => count($jobs),
                'max_rows' => $max_rows,
                'low_stock_threshold' => $low_threshold,
                'retry_delay_seconds' => $retry_delay,
            ]);
            $run_stats['jobs_found'] = count($jobs);

            if (empty($jobs)) {
                $run_status = 'no_jobs';
                return;
            }

            $rsr = $this->handler->get_distributor_by_id('rsr');
            if (!($rsr instanceof DistributorBase)) {
                $this->log_ctx('error_missing_rsr_distributor', ['run_id' => $run_id]);
                $run_status = 'missing_rsr';
                return;
            }

            $skipped_suspended = 0;
            $failed_rows = 0;
            /** @var array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $eligible_entries */
            $eligible_entries = [];

            foreach ($jobs as $job) {
                $order_id = (int) $job->order_id;
                $job_key = (string) $job->job_key_norm();
                $order = wc_get_order($order_id);

                if (!($order instanceof WC_Order) || $job_key === '') {
                    $this->mark_failed($order_id, $job_key, 'Batch row skipped: missing order or job key.');
                    $failed_rows++;
                    continue;
                }

                if (OrderPlacementPipelineMetaStore::is_order_suspended($order_id)) {
                    $skipped_suspended++;
                    continue;
                }

                $lines = $job->payload_lines();
                if (empty($lines)) {
                    $this->mark_failed($order_id, $job_key, 'Batch row has no valid payload lines.');
                    $failed_rows++;
                    continue;
                }

                $has_invalid_upc = false;
                foreach ($lines as $line) {
                    $upc_key = $this->normalize_upc_key((string) $line->upc);
                    if ($upc_key === '') {
                        $has_invalid_upc = true;
                        break;
                    }
                }
                if ($has_invalid_upc) {
                    $this->mark_failed($order_id, $job_key, 'Batch row has one or more lines with invalid UPC.');
                    $failed_rows++;
                    continue;
                }

                $eligible_entries[] = [
                    'job' => $job,
                    'order' => $order,
                    'lines' => $lines,
                ];
            }

            $run_stats['eligible_rows'] = count($eligible_entries);
            $run_stats['skipped_suspended'] = $skipped_suspended;
            $run_stats['failed_rows'] = $failed_rows;

            if (empty($eligible_entries)) {
                $run_status = 'no_eligible_rows';
                return;
            }

            $demand_by_upc = $this->build_demand_by_upc($eligible_entries);
            $stock_cache = $this->build_stock_cache($rsr, $demand_by_upc);
            $risky_upcs = $this->find_risky_upcs($demand_by_upc, $stock_cache, $low_threshold);

            /** @var array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $priority_candidates */
            $priority_candidates = [];
            /** @var array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates */
            $batch_candidates = [];

            foreach ($eligible_entries as $entry) {
                if ($this->entry_contains_any_upc($entry['lines'], $risky_upcs)) {
                    $priority_candidates[] = $entry;
                    continue;
                }
                $batch_candidates[] = $entry;
            }

            $run_stats['priority_rows'] = count($priority_candidates);
            $run_stats['scheduled_rows'] = count($batch_candidates);
            $run_stats['risky_upcs'] = count($risky_upcs);

            $this->log_ctx('batch_partition', [
                'run_id' => $run_id,
                'eligible_rows' => count($eligible_entries),
                'priority_rows' => count($priority_candidates),
                'scheduled_rows' => count($batch_candidates),
                'demand_upcs' => count($demand_by_upc),
                'risky_upcs' => count($risky_upcs),
                'risky_upcs_head' => array_slice(array_keys($risky_upcs), 0, 10),
            ]);

            $priority_flushed_rows = 0;
            if (!empty($priority_candidates)) {
                try {
                    $this->flush_aggregate_batch($rsr, $priority_candidates, $retry_delay, $run_id, 'priority_low_stock');
                    $priority_flushed_rows = count($priority_candidates);
                } catch (\Throwable $e) {
                    $this->log_ctx('priority_flush_exception', [
                        'run_id' => $run_id,
                        'error' => $e->getMessage(),
                    ]);
                    $this->requeue_batch_candidates(
                        $priority_candidates,
                        $retry_delay,
                        'Priority batch flush exception: ' . $e->getMessage()
                    );
                }
            }

            $force_flush = $this->consume_force_flush();
            $dispatch_due = $force_flush || $this->is_dispatch_window_open();
            $run_stats['force_flush'] = $force_flush ? 1 : 0;
            $run_stats['dispatch_due'] = $dispatch_due ? 1 : 0;
            $run_stats['priority_flushed_rows'] = $priority_flushed_rows;

            $this->log_ctx('batch_gate', [
                'run_id' => $run_id,
                'priority_rows' => count($priority_candidates),
                'scheduled_rows' => count($batch_candidates),
                'priority_flushed_rows' => $priority_flushed_rows,
                'force_flush' => $force_flush ? 1 : 0,
                'dispatch_due' => $dispatch_due ? 1 : 0,
            ]);

            $scheduled_flushed_rows = 0;
            try {
                if ($dispatch_due && !empty($batch_candidates)) {
                    $this->flush_aggregate_batch($rsr, $batch_candidates, $retry_delay, $run_id, 'scheduled_batch');
                    $scheduled_flushed_rows = count($batch_candidates);
                }
            } catch (\Throwable $e) {
                $this->log_ctx('scheduled_flush_exception', [
                    'run_id' => $run_id,
                    'error' => $e->getMessage(),
                ]);
                $this->requeue_batch_candidates(
                    $batch_candidates,
                    $retry_delay,
                    'Scheduled batch flush exception: ' . $e->getMessage()
                );
            }

            $run_stats['scheduled_flushed_rows'] = $scheduled_flushed_rows;

            if ($priority_flushed_rows > 0 && $scheduled_flushed_rows > 0) {
                $run_status = 'priority_and_scheduled_flushed';
            } elseif ($priority_flushed_rows > 0) {
                $run_status = 'priority_flushed';
            } elseif ($scheduled_flushed_rows > 0) {
                $run_status = 'scheduled_flushed';
            } elseif (!$dispatch_due && !empty($batch_candidates)) {
                $run_status = 'holding';
            } else {
                $run_status = 'no_flush';
            }
        } finally {
            $this->log_ctx('run_end', [
                'run_id' => $run_id,
                'status' => $run_status,
                'elapsed_ms' => (int) round((microtime(true) - $run_started) * 1000),
                'stats' => $run_stats,
                'memory_kb' => (int) (memory_get_usage(true) / 1024),
            ]);
            $this->release_lock($run_id);
        }
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     */
    private function flush_aggregate_batch(
        DistributorBase $rsr,
        array $batch_candidates,
        int $retry_delay_seconds,
        string $run_id,
        string $batch_kind = 'scheduled_batch'
    ): void
    {
        $aggregate_lines = $this->aggregate_lines($batch_candidates);
        if (empty($aggregate_lines)) {
            $this->log_ctx('aggregate_skip_no_lines', ['run_id' => $run_id, 'batch_kind' => $batch_kind]);
            return;
        }

        /** @var WC_Order $first_order */
        $first_order = $batch_candidates[0]['order'];
        $ship_to = DistributorShipTo::from_order_shipping_fallback_billing($first_order);
        if (!($ship_to instanceof DistributorShipTo)) {
            $this->log_ctx('aggregate_missing_ship_to', ['run_id' => $run_id, 'batch_kind' => $batch_kind]);
            foreach ($batch_candidates as $entry) {
                /** @var OrderPlacementJobRow $job */
                $job = $entry['job'];
                $this->mark_failed((int) $job->order_id, (string) $job->job_key_norm(), 'Batch aggregate failed: missing ship-to address.');
            }
            return;
        }

        $po = $this->build_batch_po($batch_candidates);
        $request = new DistributorOrderRequest(
            $aggregate_lines,
            $ship_to,
            null,
            $po,
            (string) $ship_to->state,
            '',
            'RSR dealer batch aggregate [' . $batch_kind . ']',
            'dealer_fulfilled'
        );

        foreach ($batch_candidates as $entry) {
            /** @var OrderPlacementJobRow $job */
            $job = $entry['job'];
            /** @var WC_Order $order */
            $order = $entry['order'];
            OrderPlacementJobWriter::apply_patch_for_order(
                $this->jobs_table,
                $order,
                (string) $job->job_key_norm(),
                OrderPlacementJobPatch::empty()
                    ->with_status(OrderPlacementKeys::JOB_STATUS_RUNNING)
                    ->with_field('attempts', (int) $job->attempts + 1)
                    ->with_last_step('place')
                    ->clear_action_and_schedule()
            );
        }

        try {
            $result = $rsr->place_order($request);
        } catch (\Throwable $e) {
            $result = DistributorOrderResult::block_retryable(
                'Batch aggregate call exception: ' . $e->getMessage(),
                [DistributorOrderResult::REASON_RETRY_UPSTREAM]
            );
        }

        if (!($result instanceof DistributorOrderResult)) {
            $result = DistributorOrderResult::block_fatal(
                'Batch aggregate call returned an invalid result object.',
                [DistributorOrderResult::REASON_FATAL_UNKNOWN]
            );
        }

        $this->log_ctx('aggregate_result', [
            'run_id' => $run_id,
            'batch_kind' => $batch_kind,
            'po' => $po,
            'ok' => $result->ok ? 1 : 0,
            'code' => (string) $result->code,
            'message' => (string) $result->message,
            'codes' => is_array($result->codes) ? $result->codes : [],
            'rows' => count($batch_candidates),
            'unique_lines' => count($aggregate_lines),
        ]);

        if ($result->ok && $result->code === DistributorOrderResult::CODE_OK) {
            foreach ($batch_candidates as $entry) {
                /** @var OrderPlacementJobRow $job */
                $job = $entry['job'];
                /** @var WC_Order $order */
                $order = $entry['order'];
                $job_key = (string) $job->job_key_norm();

                $snapshot = OrderPlacementSnapshotUtil::place_snapshot($result, $job->ctx((int) $job->attempts + 1));
                OrderPlacementJobSnapshotsStore::set_job_place_result($this->jobs_table, $order, $job_key, $snapshot);
                OrderPlacementJobIdentifiersStore::set_job_merchant_po($this->jobs_table, $order, $job_key, $po, true);
                OrderPlacementJobIdentifiersStore::set_job_external_order_ids($this->jobs_table, $order, $job_key, (array) $result->external_order_ids, true);
                OrderPlacementJobWriter::apply_patch_for_order(
                    $this->jobs_table,
                    $order,
                    $job_key,
                    OrderPlacementJobPatch::empty()->with_last_step('place')->with_last_error('')
                );
                OrderPlacementJobLifeCycle::mark_job_success($this->jobs_table, $order, $job_key);
            }
            return;
        }

        if ($result->code === DistributorOrderResult::CODE_BLOCK_RETRYABLE) {
            $next_retry = OrderPlacementTimeUtil::unix_to_mysql_utc(time() + max(30, $retry_delay_seconds));
            foreach ($batch_candidates as $entry) {
                /** @var OrderPlacementJobRow $job */
                $job = $entry['job'];
                OrderPlacementJobWriter::apply_patch(
                    $this->jobs_table,
                    (int) $job->order_id,
                    (string) $job->job_key_norm(),
                    OrderPlacementJobPatch::empty()
                        ->with_status(OrderPlacementKeys::JOB_STATUS_BATCH_PENDING)
                        ->with_next_run_at_mysql($next_retry)
                        ->with_last_step('place')
                        ->with_last_codes((array) $result->codes)
                        ->with_last_error((string) $result->message)
                );
            }
            return;
        }

        // Fatal/manual aggregate outcomes are decomposed into per-row calls for salvage.
        foreach ($batch_candidates as $entry) {
            /** @var WC_Order $order */
            $order = $entry['order'];
            /** @var OrderPlacementJobRow $job */
            $job = $entry['job'];
            $this->dispatch_single_row_now($order, (string) $job->job_key_norm());
        }
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $entries
     * @return array<string,int>
     */
    private function build_demand_by_upc(array $entries): array
    {
        $demand = [];

        foreach ($entries as $entry) {
            foreach ($entry['lines'] as $line) {
                $upc_key = $this->normalize_upc_key((string) $line->upc);
                if ($upc_key === '') {
                    continue;
                }

                $qty = max(1, (int) $line->quantity);
                if (!isset($demand[$upc_key])) {
                    $demand[$upc_key] = 0;
                }
                $demand[$upc_key] += $qty;
            }
        }

        return $demand;
    }

    /**
     * @param array<string,int> $demand_by_upc
     * @return array<string,int|null>
     */
    private function build_stock_cache(DistributorBase $rsr, array $demand_by_upc): array
    {
        $stock_cache = [];
        foreach ($demand_by_upc as $upc => $_qty) {
            $stock_cache[$upc] = $rsr->get_stock_quantity_by_upc((string) $upc);
        }
        return $stock_cache;
    }

    /**
     * @param array<string,int> $demand_by_upc
     * @param array<string,int|null> $stock_cache
     * @return array<string,bool>
     */
    private function find_risky_upcs(array $demand_by_upc, array $stock_cache, int $threshold): array
    {
        $risky = [];
        foreach ($demand_by_upc as $upc => $requested_total) {
            $available = $stock_cache[$upc] ?? null;
            if ($available === null || $available <= $threshold || $available < (int) $requested_total) {
                $risky[$upc] = true;
            }
        }
        return $risky;
    }

    /**
     * @param array<int,DistributorOrderLine> $lines
     * @param array<string,bool> $risky_upcs
     */
    private function entry_contains_any_upc(array $lines, array $risky_upcs): bool
    {
        foreach ($lines as $line) {
            $upc_key = $this->normalize_upc_key((string) $line->upc);
            if ($upc_key === '') {
                return true;
            }
            if (isset($risky_upcs[$upc_key])) {
                return true;
            }
        }
        return false;
    }

    private function normalize_upc_key(string $upc): string
    {
        $upc = trim($upc);
        if ($upc === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $upc);
        if (is_string($digits) && $digits !== '') {
            return $digits;
        }

        return strtoupper($upc);
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $candidates
     */
    private function requeue_batch_candidates(array $candidates, int $retry_delay_seconds, string $error_message): void
    {
        $next_retry = OrderPlacementTimeUtil::unix_to_mysql_utc(time() + max(30, $retry_delay_seconds));
        foreach ($candidates as $entry) {
            /** @var OrderPlacementJobRow $job */
            $job = $entry['job'];
            OrderPlacementJobWriter::apply_patch(
                $this->jobs_table,
                (int) $job->order_id,
                (string) $job->job_key_norm(),
                OrderPlacementJobPatch::empty()
                    ->with_status(OrderPlacementKeys::JOB_STATUS_BATCH_PENDING)
                    ->with_next_run_at_mysql($next_retry)
                    ->with_last_step('place')
                    ->with_last_error($error_message)
            );
        }
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     * @return array<int,DistributorOrderLine>
     */
    private function aggregate_lines(array $batch_candidates): array
    {
        $line_map = [];

        foreach ($batch_candidates as $entry) {
            foreach ($entry['lines'] as $line) {
                $key = strtolower(trim((string) $line->upc)) . '|' . ((int) $line->ffl_required);
                if ($key === '|0' || $key === '|1') {
                    continue;
                }

                if (!isset($line_map[$key])) {
                    $line_map[$key] = new DistributorOrderLine((string) $line->upc, (int) $line->quantity, (bool) $line->ffl_required);
                    continue;
                }

                $existing = $line_map[$key];
                $line_map[$key] = new DistributorOrderLine((string) $line->upc, (int) $existing->quantity + (int) $line->quantity, (bool) $line->ffl_required);
            }
        }

        return array_values($line_map);
    }

    private function dispatch_single_row_now(WC_Order $order, string $job_key): bool
    {
        $job_key = trim((string) $job_key);
        if ($job_key === '') {
            return false;
        }

        $patch = OrderPlacementJobPatch::empty()
            ->with_status(OrderPlacementKeys::JOB_STATUS_SCHEDULED)
            ->with_next_run_at_mysql(OrderPlacementTimeUtil::now_mysql_utc())
            ->with_last_error('');
        OrderPlacementJobWriter::apply_patch_for_order($this->jobs_table, $order, $job_key, $patch);

        try {
            OrderPlacementJobRunner::run($this->jobs_table, $this->ffl_table, $order, $job_key, $this->handler);
            return true;
        } catch (\Throwable $e) {
            OrderPlacementJobLifeCycle::mark_job_failed($this->jobs_table, $order, $job_key, 'Batch single-row fallback failed: ' . $e->getMessage());
            return false;
        }
    }

    private function mark_failed(int $order_id, string $job_key, string $message): void
    {
        $job_key = trim((string) $job_key);
        if ($order_id <= 0 || $job_key === '') {
            return;
        }

        OrderPlacementJobWriter::apply_patch(
            $this->jobs_table,
            $order_id,
            $job_key,
            OrderPlacementJobPatch::empty()
                ->with_status(OrderPlacementKeys::JOB_STATUS_FAILED)
                ->with_last_step('place')
                ->with_last_error($message)
                ->clear_action_and_schedule()
        );
    }

    private function is_enabled(): bool
    {
        return $this->truthy_option(self::OPT_ENABLED, true);
    }

    private function low_stock_threshold(): int
    {
        return max(0, (int) get_option(self::OPT_LOW_STOCK_THRESHOLD, self::DEFAULT_LOW_STOCK_THRESHOLD));
    }

    private function retry_delay_seconds(): int
    {
        return max(30, (int) get_option(self::OPT_RETRY_DELAY_SECONDS, self::DEFAULT_RETRY_DELAY_SECONDS));
    }

    private function max_rows_per_run(): int
    {
        return max(1, (int) get_option(self::OPT_MAX_ROWS_PER_RUN, self::DEFAULT_MAX_ROWS_PER_RUN));
    }

    private function consume_force_flush(): bool
    {
        $enabled = $this->truthy_option(self::OPT_FORCE_FLUSH, false);
        if ($enabled) {
            update_option(self::OPT_FORCE_FLUSH, '0', false);
        }
        return $enabled;
    }

    private function is_dispatch_window_open(): bool
    {
        $hhmm = trim((string) get_option(self::OPT_DISPATCH_TIME, self::DEFAULT_DISPATCH_TIME));
        if (!preg_match('/^([0-1]?\d|2[0-3]):([0-5]\d)$/', $hhmm, $m)) {
            $hhmm = self::DEFAULT_DISPATCH_TIME;
            preg_match('/^([0-1]?\d|2[0-3]):([0-5]\d)$/', $hhmm, $m);
        }

        $hour = isset($m[1]) ? (int) $m[1] : 17;
        $minute = isset($m[2]) ? (int) $m[2] : 0;

        $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $now_local = new \DateTimeImmutable('now', $tz);
        $dispatch_local = $now_local->setTime($hour, $minute, 0);

        return $now_local >= $dispatch_local;
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     */
    private function build_batch_po(array $batch_candidates): string
    {
        if (empty($batch_candidates)) {
            return 'FHRSRB-' . gmdate('ymdHi') . '-to-' . (string) wp_rand(100, 999);
        }

        $first = $this->batch_po_segment_from_candidate($batch_candidates[0]);
        $last = $this->batch_po_segment_from_candidate($batch_candidates[count($batch_candidates) - 1]);

        return 'FHRSRB-' . $first . '-to-' . $last;
    }

    /**
     * @param array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>} $candidate
     */
    private function batch_po_segment_from_candidate(array $candidate): string
    {
        $job = $candidate['job'];
        if (!($job instanceof OrderPlacementJobRow)) {
            return 'NA';
        }

        $raw = trim((string) $job->merchant_po_or_empty());
        if ($raw === '') {
            $raw = trim((string) $job->job_key_norm());
        }
        if ($raw === '') {
            $oid = (int) $job->order_id;
            $raw = $oid > 0 ? ('ORDER' . (string) $oid) : 'NA';
        }

        $raw = strtoupper($raw);
        $raw = preg_replace('/[^A-Z0-9\-]+/', '-', $raw);
        $raw = is_string($raw) ? $raw : '';
        $raw = preg_replace('/\-{2,}/', '-', $raw);
        $raw = is_string($raw) ? trim($raw, '-') : '';

        if ($raw === '') {
            return 'NA';
        }

        return $raw;
    }

    private function truthy_option(string $option_name, bool $default): bool
    {
        $raw = get_option($option_name, $default ? '1' : '0');
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return ((int) $raw) === 1;
        }

        $s = strtolower(trim((string) $raw));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    private function acquire_lock(string $run_id): bool
    {
        $now = time();
        $payload = wp_json_encode([
            'run_id' => $run_id,
            'expires_at' => $now + self::LOCK_TTL_SECONDS,
        ]);

        if (add_option(self::LOCK_OPTION, $payload, '', false)) {
            return true;
        }

        $raw = get_option(self::LOCK_OPTION, '');
        $existing = is_string($raw) ? json_decode($raw, true) : null;
        $expires_at = is_array($existing) ? (int) ($existing['expires_at'] ?? 0) : 0;

        if ($expires_at > $now) {
            return false;
        }

        update_option(self::LOCK_OPTION, $payload, false);
        return true;
    }

    private function release_lock(string $run_id): void
    {
        $raw = get_option(self::LOCK_OPTION, '');
        $existing = is_string($raw) ? json_decode($raw, true) : null;
        $existing_run_id = is_array($existing) ? (string) ($existing['run_id'] ?? '') : '';

        if ($existing_run_id === '' || $existing_run_id === $run_id) {
            delete_option(self::LOCK_OPTION);
        }
    }

    /** @param array<string,mixed> $ctx */
    private function log_ctx(string $msg, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $msg, $ctx);
    }
}
