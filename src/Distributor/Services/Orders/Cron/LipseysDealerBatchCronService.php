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
use FFLHub\Distributor\Services\Orders\Notifications\BatchOrderNotificationEmail;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * LipseysDealerBatchCronService
 *
 * Runs every minute and processes only Lipseys dealer-fulfilled rows in batch_pending status.
 *
 * Behavior:
 * - Low-stock risk is computed from SUMMED UPC demand across currently queued rows.
 * - Risky rows are flushed immediately as one aggregate "priority" batch call.
 * - Remaining rows are held until the next daily dispatch window (or force flush), then sent as one aggregate scheduled batch call.
 * - Scheduled dispatch is allowed at most once per Central-time day after the configured HH:MM gate opens.
 * - Aggregate retryable failures are re-queued in batch_pending with next_run_at delay.
 * - Aggregate non-retryable outcomes fall back to per-row dispatch for better salvage.
 */
final class LipseysDealerBatchCronService extends AbstractCronService
{
    /** Action Scheduler / WP-Cron hook polled every minute for Lipseys dealer batch work. */
    public const CRON_HOOK = 'fflhub_lipseys_dealer_batch_poll';

    /** Debug flag and log prefix used by log_ctx(). */
    private const DEBUG_CONST = 'FFLHUB_DEBUG_ORDER_BATCH';
    private const LOG_PREFIX  = '[FFLHub][LipseysDealerBatchCronService]';

    /** Soft distributed lock (option row) to prevent concurrent batch runs. */
    private const LOCK_OPTION = 'fflhub_lipseys_dealer_batch_lock';
    private const LOCK_TTL_SECONDS = 300;

    /** Runtime option names for batch behavior. */
    private const OPT_ENABLED            = 'fflhub_lipseys_dealer_batch_enabled';
    private const OPT_DISPATCH_TIME      = 'fflhub_lipseys_dealer_batch_dispatch_time';
    private const OPT_LOW_STOCK_THRESHOLD = 'fflhub_lipseys_dealer_batch_low_stock_threshold';
    private const OPT_RETRY_DELAY_SECONDS = 'fflhub_lipseys_dealer_batch_retry_delay_seconds';
    private const OPT_MAX_ROWS_PER_RUN    = 'fflhub_lipseys_dealer_batch_max_rows_per_run';
    private const OPT_FORCE_FLUSH         = 'fflhub_lipseys_dealer_batch_force_flush';
    private const OPT_LAST_SCHEDULED_FLUSH_AT_UTC = 'fflhub_lipseys_dealer_batch_last_scheduled_flush_at_utc';

    /** Safe defaults used when options are missing/invalid. */
    private const DEFAULT_DISPATCH_TIME = '17:00';
    private const DEFAULT_LOW_STOCK_THRESHOLD = 3;
    private const DEFAULT_RETRY_DELAY_SECONDS = 300;
    private const DEFAULT_MAX_ROWS_PER_RUN = 200;
    private const DISPATCH_TZ = 'America/Chicago';

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
        // Unique run id for traceability across all log lines in this run.
        $run_id = substr(md5((string) microtime(true) . '|' . (string) wp_rand()), 0, 10);
        $now_mysql_utc = OrderPlacementTimeUtil::now_mysql_utc();
        $run_started = microtime(true);

        // Hard gate: do not run if Lipseys is globally disabled or batch mode is disabled.
        if (!Options::is_distributor_enabled('lipseys') || !$this->is_enabled()) {
            $this->log_ctx('skip_disabled', ['run_id' => $run_id]);
            return;
        }

        // Soft lock gate: ensures only one worker performs batch placement at a time.
        if (!$this->acquire_lock($run_id)) {
            $this->log_ctx('skip_locked', ['run_id' => $run_id]);
            return;
        }

        // Human-readable end-state label and structured run counters for diagnostics.
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
            'deferred_post_window_rows' => 0,
        ];

        try {
            // Resolve runtime controls once per run for consistent behavior.
            $max_rows = $this->max_rows_per_run();
            $low_threshold = $this->low_stock_threshold();
            $retry_delay = $this->retry_delay_seconds();

            // Pull only rows that are batch-pending and due now (bounded by max rows).
            $jobs = OrderPlacementJobsRepository::find_jobs_for_dealer_batch_processing(
                $this->jobs_table,
                'lipseys',
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

            // Distributor handle is required for stock lookup + place_order aggregate call.
            $distributor = $this->handler->get_distributor_by_id('lipseys');
            if (!($distributor instanceof DistributorBase)) {
                $this->log_ctx('error_missing_lipseys_distributor', ['run_id' => $run_id]);
                $run_status = 'missing_lipseys';
                return;
            }

            $skipped_suspended = 0;
            $failed_rows = 0;
            /** @var array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $eligible_entries */
            $eligible_entries = [];

            // Validate each candidate row before allowing it into any aggregate request.
            foreach ($jobs as $job) {
                $order_id = (int) $job->order_id;
                $job_key = (string) $job->job_key_norm();
                $order = wc_get_order($order_id);

                // Rows without a resolvable order or job key cannot be recovered here.
                if (!($order instanceof WC_Order) || $job_key === '') {
                    $this->mark_failed($order_id, $job_key, 'Batch row skipped: missing order or job key.');
                    $failed_rows++;
                    continue;
                }

                // Suspended orders are skipped without mutation so manual workflows remain intact.
                if (OrderPlacementPipelineMetaStore::is_order_suspended($order_id)) {
                    $skipped_suspended++;
                    continue;
                }

                // Empty payload cannot be sent; mark failed for operator visibility.
                $lines = $job->payload_lines();
                if (empty($lines)) {
                    $this->mark_failed($order_id, $job_key, 'Batch row has no valid payload lines.');
                    $failed_rows++;
                    continue;
                }

                // Every line must have a normalizable UPC key.
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

            // Build total demand per UPC across ALL eligible rows in this run.
            $demand_by_upc = $this->build_demand_by_upc($eligible_entries);
            // Resolve current stock once per demanded UPC.
            $stock_cache = $this->build_stock_cache($distributor, $demand_by_upc);
            // Risk set uses threshold and summed demand semantics.
            $risky_upcs = $this->find_risky_upcs($demand_by_upc, $stock_cache, $low_threshold);

            /** @var array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $priority_candidates */
            $priority_candidates = [];
            /** @var array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates */
            $batch_candidates = [];

            // Partition rows:
            // - priority_candidates: contains any risky UPC (flush immediately)
            // - batch_candidates: safe to hold for dispatch window
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
            // Flush risky rows now as ONE aggregate to reduce fragmentation/shipping overhead.
            if (!empty($priority_candidates)) {
                try {
                    $this->flush_aggregate_batch($distributor, $priority_candidates, $retry_delay, $run_id, 'priority_low_stock');
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

            // Force flush is one-shot: consume the flag this run, then evaluate dispatch gate.
            $force_flush = $this->consume_force_flush();
            $dispatch_due = $force_flush || $this->is_dispatch_window_open();
            $run_stats['force_flush'] = $force_flush ? 1 : 0;
            $run_stats['dispatch_due'] = $dispatch_due ? 1 : 0;
            $run_stats['priority_flushed_rows'] = $priority_flushed_rows;

            $scheduled_candidates = $batch_candidates;
            $deferred_candidates = [];
            if ($dispatch_due && !$force_flush && !empty($batch_candidates)) {
                [$scheduled_candidates, $deferred_candidates] = $this->split_scheduled_candidates_for_current_window($batch_candidates);
                if (!empty($deferred_candidates)) {
                    $this->defer_candidates_until_next_dispatch_window($deferred_candidates);
                }
            }
            $run_stats['deferred_post_window_rows'] = count($deferred_candidates);

            $this->log_ctx('batch_gate', [
                'run_id' => $run_id,
                'priority_rows' => count($priority_candidates),
                'scheduled_rows' => count($batch_candidates),
                'scheduled_ready_rows' => count($scheduled_candidates),
                'deferred_post_window_rows' => count($deferred_candidates),
                'priority_flushed_rows' => $priority_flushed_rows,
                'force_flush' => $force_flush ? 1 : 0,
                'dispatch_due' => $dispatch_due ? 1 : 0,
            ]);

            $scheduled_flushed_rows = 0;
            try {
                // Only flush scheduled rows when gate is open.
                if ($dispatch_due && !empty($scheduled_candidates)) {
                    // Consume today's scheduled dispatch slot before attempting flush.
                    if (!$force_flush) {
                        $this->mark_scheduled_flush_attempt((string) $now_mysql_utc);
                    }
                    $this->flush_aggregate_batch($distributor, $scheduled_candidates, $retry_delay, $run_id, 'scheduled_batch');
                    $scheduled_flushed_rows = count($scheduled_candidates);
                }
            } catch (\Throwable $e) {
                $this->log_ctx('scheduled_flush_exception', [
                    'run_id' => $run_id,
                    'error' => $e->getMessage(),
                ]);
                $this->requeue_batch_candidates(
                    $scheduled_candidates,
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
            } elseif ($dispatch_due && empty($scheduled_candidates) && !empty($batch_candidates)) {
                $run_status = 'post_window_deferred';
            } elseif (!$dispatch_due && !empty($batch_candidates)) {
                $run_status = 'holding';
            } else {
                $run_status = 'no_flush';
            }
        } finally {
            // Always log final run metrics and always release lock.
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
        DistributorBase $distributor,
        array $batch_candidates,
        int $retry_delay_seconds,
        string $run_id,
        string $batch_kind = 'scheduled_batch'
    ): void
    {
        // Collapse per-row lines into a single aggregate payload.
        $aggregate_lines = $this->aggregate_lines($batch_candidates);
        if (empty($aggregate_lines)) {
            $this->log_ctx('aggregate_skip_no_lines', ['run_id' => $run_id, 'batch_kind' => $batch_kind]);
            return;
        }

        // Aggregate order uses ship-to from first order in the selected candidate set.
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

        // Shared batch PO for all rows covered by this aggregate call.
        $po = $this->build_batch_po($batch_candidates);
        $request = new DistributorOrderRequest(
            $aggregate_lines,
            $ship_to,
            null,
            $po,
            (string) $ship_to->state,
            '',
            'Lipseys dealer batch aggregate [' . $batch_kind . ']',
            'dealer_fulfilled'
        );

        // Transition all covered jobs to RUNNING before the external call.
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
            // One upstream order placement call for the full aggregate payload.
            $result = $distributor->place_order($request);
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
            // Success path: stamp snapshots/IDs/PO and mark each contributing row successful.
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

            BatchOrderNotificationEmail::send(
                'lipseys',
                $po,
                $batch_kind,
                $result,
                $batch_candidates,
                $aggregate_lines,
                $ship_to,
                false
            );
            return;
        }

        if ($result->code === DistributorOrderResult::CODE_BLOCK_RETRYABLE) {
            // Retryable path: send all rows back to batch_pending with a delayed next_run_at.
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
        // Running total keyed by normalized UPC.
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
    private function build_stock_cache(DistributorBase $distributor, array $demand_by_upc): array
    {
        // Resolve each UPC once per run; avoid repeated distributor lookups.
        $stock_cache = [];
        foreach ($demand_by_upc as $upc => $_qty) {
            $stock_cache[$upc] = $distributor->get_stock_quantity_by_upc((string) $upc);
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
        // UPC is risky when:
        // - stock unknown, or
        // - stock at/below threshold, or
        // - stock less than total queued demand in this run.
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
        // Any invalid/blank UPC is treated as risky to avoid accidentally holding unsafe rows.
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
        // Prefer digit-only canonical UPC key; fallback to uppercase raw if needed.
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
        // Common requeue path used on unexpected exceptions around aggregate flush.
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
        // Collapse multiple rows into unique lines by {upc, ffl_required}, summing quantities.
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
        // Fallback for fatal/manual aggregate outcomes:
        // convert row back to immediate scheduled execution and run state machine now.
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
        // Hard-fail helper for unrecoverable row-level issues detected during prep.
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
        // One-shot toggle: read and immediately clear so only one run consumes it.
        $enabled = $this->truthy_option(self::OPT_FORCE_FLUSH, false);
        if ($enabled) {
            update_option(self::OPT_FORCE_FLUSH, '0', false);
        }
        return $enabled;
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     * @return array{
     *   0:array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}>,
     *   1:array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}>
     * }
     */
    private function split_scheduled_candidates_for_current_window(array $batch_candidates): array
    {
        $ctx = $this->dispatch_window_context();
        /** @var \DateTimeImmutable $dispatch_local */
        $dispatch_local = $ctx['dispatch_local'];
        /** @var \DateTimeZone $tz */
        $tz = $ctx['tz'];

        $ready = [];
        $deferred = [];

        foreach ($batch_candidates as $entry) {
            $job = $entry['job'] ?? null;
            if (!($job instanceof OrderPlacementJobRow)) {
                $ready[] = $entry;
                continue;
            }

            $created_at_utc = trim((string) ($job->created_at ?? ''));
            if ($created_at_utc === '') {
                $ready[] = $entry;
                continue;
            }

            $created_utc = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $created_at_utc, new \DateTimeZone('UTC'));
            if (!($created_utc instanceof \DateTimeImmutable)) {
                $ready[] = $entry;
                continue;
            }

            $created_local = $created_utc->setTimezone($tz);
            if ($created_local > $dispatch_local) {
                $deferred[] = $entry;
                continue;
            }

            $ready[] = $entry;
        }

        return [$ready, $deferred];
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $candidates
     */
    private function defer_candidates_until_next_dispatch_window(array $candidates): void
    {
        if (empty($candidates)) {
            return;
        }

        $next_dispatch_utc = $this->next_dispatch_boundary_utc_mysql();
        foreach ($candidates as $entry) {
            $job = $entry['job'] ?? null;
            if (!($job instanceof OrderPlacementJobRow)) {
                continue;
            }

            OrderPlacementJobWriter::apply_patch(
                $this->jobs_table,
                (int) $job->order_id,
                (string) $job->job_key_norm(),
                OrderPlacementJobPatch::empty()
                    ->with_status(OrderPlacementKeys::JOB_STATUS_BATCH_PENDING)
                    ->with_next_run_at_mysql($next_dispatch_utc)
            );
        }
    }

    private function next_dispatch_boundary_utc_mysql(): string
    {
        $ctx = $this->dispatch_window_context();
        /** @var \DateTimeImmutable $dispatch_local */
        $dispatch_local = $ctx['dispatch_local'];

        return $dispatch_local
            ->modify('+1 day')
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * @return array{
     *   hour:int,
     *   minute:int,
     *   tz:\DateTimeZone,
     *   now_local:\DateTimeImmutable,
     *   dispatch_local:\DateTimeImmutable
     * }
     */
    private function dispatch_window_context(): array
    {
        // Parse configured HH:MM in fixed Central timezone.
        $hhmm = trim((string) get_option(self::OPT_DISPATCH_TIME, self::DEFAULT_DISPATCH_TIME));
        if (!preg_match('/^([0-1]?\d|2[0-3]):([0-5]\d)$/', $hhmm, $m)) {
            $hhmm = self::DEFAULT_DISPATCH_TIME;
            preg_match('/^([0-1]?\d|2[0-3]):([0-5]\d)$/', $hhmm, $m);
        }

        $hour = isset($m[1]) ? (int) $m[1] : 17;
        $minute = isset($m[2]) ? (int) $m[2] : 0;

        $tz = new \DateTimeZone(self::DISPATCH_TZ);
        $now_local = new \DateTimeImmutable('now', $tz);
        $dispatch_local = $now_local->setTime($hour, $minute, 0);

        return [
            'hour' => $hour,
            'minute' => $minute,
            'tz' => $tz,
            'now_local' => $now_local,
            'dispatch_local' => $dispatch_local,
        ];
    }

    private function is_dispatch_window_open(): bool
    {
        $ctx = $this->dispatch_window_context();
        /** @var \DateTimeImmutable $now_local */
        $now_local = $ctx['now_local'];
        /** @var \DateTimeImmutable $dispatch_local */
        $dispatch_local = $ctx['dispatch_local'];
        /** @var \DateTimeZone $tz */
        $tz = $ctx['tz'];

        if ($now_local < $dispatch_local) {
            return false;
        }

        // Allow only one scheduled flush per local day after dispatch time.
        $last_flush_utc = trim((string) get_option(self::OPT_LAST_SCHEDULED_FLUSH_AT_UTC, ''));
        if ($last_flush_utc === '') {
            return true;
        }

        $last_utc = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $last_flush_utc, new \DateTimeZone('UTC'));
        if (!($last_utc instanceof \DateTimeImmutable)) {
            return true;
        }

        $last_local = $last_utc->setTimezone($tz);
        if ($last_local >= $dispatch_local) {
            return false;
        }

        return true;
    }

    private function mark_scheduled_flush_attempt(string $now_mysql_utc): void
    {
        $now_mysql_utc = trim($now_mysql_utc);
        if ($now_mysql_utc === '') {
            $now_mysql_utc = gmdate('Y-m-d H:i:s');
        }

        update_option(self::OPT_LAST_SCHEDULED_FLUSH_AT_UTC, $now_mysql_utc, false);
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     */
    private function build_batch_po(array $batch_candidates): string
    {
        // Format: LIPB-{firstWooOrderNumber}-{lastWooOrderNumber}
        // Keep each order token short so the final PO stays <= 22 chars (Lipseys cap).
        if (empty($batch_candidates)) {
            return 'LIPB-' . gmdate('mdHi') . '-' . (string) wp_rand(1000, 9999);
        }

        $first = $this->batch_po_segment_from_candidate($batch_candidates[0]);
        $last = $this->batch_po_segment_from_candidate($batch_candidates[count($batch_candidates) - 1]);

        return 'LIPB-' . $first . '-' . $last;
    }

    /**
     * @param array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>} $candidate
     */
    private function batch_po_segment_from_candidate(array $candidate): string
    {
        // Segment source is Woo order number only (explicit business requirement).
        $order = $candidate['order'] ?? null;
        if ($order instanceof WC_Order) {
            $num = trim((string) $order->get_order_number());
            if ($num !== '') {
                return $this->sanitize_po_segment($num);
            }
        }

        $job = $candidate['job'] ?? null;
        $raw = '';
        if ($job instanceof OrderPlacementJobRow) {
            $oid = (int) $job->order_id;
            if ($oid > 0) {
                $raw = (string) $oid;
            }
        }

        if ($raw === '') {
            $raw = 'NA';
        }

        return $this->sanitize_po_segment($raw);
    }

    private function sanitize_po_segment(string $raw): string
    {
        $raw = strtoupper($raw);
        $raw = preg_replace('/[^A-Z0-9\-]+/', '-', $raw);
        $raw = is_string($raw) ? $raw : '';
        $raw = preg_replace('/\-{2,}/', '-', $raw);
        $raw = is_string($raw) ? trim($raw, '-') : '';

        if ($raw === '') {
            return 'NA';
        }

        // 4 + 1 + 8 + 1 + 8 = 22 max total for "LIPB-{first}-{last}".
        if (strlen($raw) > 8) {
            $raw = substr($raw, -8);
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
        // Try to atomically create lock option first.
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

        // Existing lock expired; take ownership.
        update_option(self::LOCK_OPTION, $payload, false);
        return true;
    }

    private function release_lock(string $run_id): void
    {
        // Remove lock only if unowned or owned by this run id.
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


