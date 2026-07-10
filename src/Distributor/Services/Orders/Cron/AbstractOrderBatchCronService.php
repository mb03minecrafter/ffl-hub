<?php

namespace FFLHub\Distributor\Services\Orders\Cron;

use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product;

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Offers\DistributorOffersStore;
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
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementProductUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementSnapshotUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Notifications\BatchOrderNotificationEmail;
use FFLHub\Distributor\Services\Orders\Optimization\DealerBatchOptimizerConfig;
use FFLHub\Distributor\Services\Orders\Optimization\DealerBatchShippingOptimizer;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Distributor\Services\Orders\Util\DealerShipToResolver;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Order\OrderProfitAuditMeta;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared batch engine for dealer-fulfilled and CA-relay aggregate placement.
 *
 * Subclasses provide distributor/mode config (hook, option prefix, PO prefix, labels).
 */
abstract class AbstractOrderBatchCronService extends AbstractCronService
{
    protected const MODE_DEALER = 'dealer';
    protected const MODE_CA_RELAY = 'ca_relay';

    /** Debug flag and lock TTL are common for all batch services. */
    private const DEBUG_CONST = 'FFLHUB_DEBUG_ORDER_BATCH';
    private const LOCK_TTL_SECONDS = 300;

    /** Safe defaults used when options are missing/invalid. */
    private const DEFAULT_DISPATCH_TIME = '17:00';
    private const DEFAULT_LOW_STOCK_THRESHOLD = 3;
    private const DEFAULT_RETRY_DELAY_SECONDS = 300;
    private const DEFAULT_MAX_ROWS_PER_RUN = 200;
    private const DISPATCH_TZ = 'America/Chicago';

    protected DistributorHandler $handler;
    protected OrderPlacementJobsTable $jobs_table;
    protected FFLTable $ffl_table;

    public function __construct(
        DistributorHandler $handler,
        OrderPlacementJobsTable $jobs_table,
        FFLTable $ffl_table
    ) {
        $this->handler = $handler;
        $this->jobs_table = $jobs_table;
        $this->ffl_table = $ffl_table;
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

    abstract protected function get_log_prefix(): string;

    abstract protected function get_distributor_id(): string;

    /**
     * Base option prefix, for example:
     * - fflhub_rsr_dealer_batch
     * - fflhub_lipseys_ca_relay_batch
     */
    abstract protected function get_option_prefix(): string;

    /** 4-char PO prefix, for example RSRB / ZANR / LIPB. */
    abstract protected function get_po_prefix(): string;

    /** Human-readable order request description prefix. */
    abstract protected function get_batch_description_prefix(): string;

    /** One of MODE_DEALER or MODE_CA_RELAY. */
    abstract protected function get_batch_mode(): string;

    public function run(): void
    {
        // Unique run id for traceability across all log lines in this run.
        $run_id = substr(md5((string) microtime(true) . '|' . (string) wp_rand()), 0, 10);
        $now_mysql_utc = OrderPlacementTimeUtil::now_mysql_utc();
        $run_started = microtime(true);
        $dist_id = $this->get_distributor_id();

        // Hard gate: do not run if distributor is globally disabled or batch mode is disabled.
        if (!Options::is_distributor_enabled($dist_id) || !$this->is_enabled()) {
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
            'deferred_post_window_rows' => 0,
            'failed_rows' => 0,
            'skipped_suspended' => 0,
            'dispatch_due' => 0,
            'force_flush' => 0,
            'risky_upcs' => 0,
            'priority_flushed_rows' => 0,
            'scheduled_flushed_rows' => 0,
            'dispatch_blocked_rows' => 0,
            'manual_hold_rows' => 0,
            'optimizer_preflight' => '',
        ];

        try {
            // Consume one-shot force-flush requests at the start of a real cron pass, even
            // if the queue is empty. Otherwise a force request made against an empty queue
            // can linger and accidentally flush the next day's first batch row early.
            $force_flush = $this->consume_force_flush();
            $run_stats['force_flush'] = $force_flush ? 1 : 0;

            $run_stats['optimizer_preflight'] = $this->maybe_run_global_dealer_batch_optimizer_preflight($run_id);

            // Resolve runtime controls once per run for consistent behavior.
            $max_rows = $this->max_rows_per_run();
            $low_threshold = $this->low_stock_threshold();
            $retry_delay = $this->retry_delay_seconds();

            // Pull only rows that are batch-pending and due now (bounded by max rows).
            $jobs = $this->find_jobs_for_batch_processing($now_mysql_utc, $max_rows);

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
            $distributor = $this->handler->get_distributor_by_id($dist_id);
            if (!($distributor instanceof DistributorBase)) {
                $this->log_ctx('error_missing_' . $dist_id . '_distributor', ['run_id' => $run_id]);
                $run_status = 'missing_' . $dist_id;
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

                if (!$this->order_allows_batch_placement($order)) {
                    $this->pause_inactive_order_job($order, $job_key);
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

                if ($this->is_ca_relay_mode()) {
                    if (!DealerBatchCronRegistry::is_ca_relay_batch_job($job)) {
                        $this->mark_failed($order_id, $job_key, 'CA relay batch row is missing the CA relay payload marker.');
                        $failed_rows++;
                        continue;
                    }

                    $has_ffl_line = false;
                    foreach ($lines as $line) {
                        if ($line instanceof DistributorOrderLine && $line->ffl_required) {
                            $has_ffl_line = true;
                            break;
                        }
                    }
                    if ($has_ffl_line) {
                        $this->mark_failed($order_id, $job_key, 'CA relay batch row contains an FFL-required line; relay is non-FFL only.');
                        $failed_rows++;
                        continue;
                    }
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

            /** @var array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $manual_hold_entries */
            $manual_hold_entries = [];
            /** @var array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $automation_entries */
            $automation_entries = [];
            foreach ($eligible_entries as $entry) {
                if ($this->should_hold_batch_entry_for_manual_order($entry)) {
                    $manual_hold_entries[] = $entry;
                    $this->mark_batch_entry_manual($entry);
                    continue;
                }

                $automation_entries[] = $entry;
            }

            $run_stats['manual_hold_rows'] = count($manual_hold_entries);
            $eligible_entries = $automation_entries;

            if (!empty($manual_hold_entries)) {
                $this->log_ctx('manual_hold_partition', [
                    'run_id' => $run_id,
                    'manual_hold_rows' => count($manual_hold_entries),
                    'automation_rows' => count($eligible_entries),
                    'reason' => $this->manual_batch_entry_reason_code(),
                ]);

                if ($this->should_alert_risky_manual_hold_entries()) {
                    $manual_demand_by_upc = $this->build_demand_by_upc($manual_hold_entries);
                    $manual_stock_cache = $this->build_stock_cache($distributor, $manual_demand_by_upc);
                    $manual_risky_upcs = $this->find_risky_upcs(
                        $manual_demand_by_upc,
                        $manual_stock_cache,
                        $low_threshold
                    );

                    if (!empty($manual_risky_upcs)) {
                        $this->send_risky_manual_hold_alert(
                            $manual_hold_entries,
                            $manual_demand_by_upc,
                            $manual_stock_cache,
                            $manual_risky_upcs,
                            $low_threshold,
                            $run_id
                        );

                        $this->log_ctx('manual_hold_risk_alert', [
                            'run_id' => $run_id,
                            'manual_hold_rows' => count($manual_hold_entries),
                            'risky_upcs' => count($manual_risky_upcs),
                            'risky_upcs_head' => array_slice(array_keys($manual_risky_upcs), 0, 10),
                        ]);
                    }
                }
            }

            if (empty($eligible_entries)) {
                $run_status = !empty($manual_hold_entries) ? 'manual_hold_only' : 'no_eligible_rows';
                return;
            }

            // Weekdays plus the configured dispatch time are the hard gates for
            // all automated aggregate dealer/relay placement. This intentionally
            // runs before low-stock partitioning:
            // a batch_pending row can be due in the DB before the dispatch window opens, but
            // it must not place early unless an operator explicitly requested force flush.
            // Force flush may bypass the clock on weekdays, but never the weekend hold.
            $dispatch_block_reason = $this->current_dispatch_block_reason();
            $dispatch_due = $dispatch_block_reason === '' && ($force_flush || $this->is_dispatch_window_open());
            $run_stats['dispatch_due'] = $dispatch_due ? 1 : 0;

            if ($dispatch_block_reason !== '') {
                $run_stats['dispatch_blocked_rows'] = count($eligible_entries);
                $this->log_ctx('dispatch_blocked', [
                    'run_id' => $run_id,
                    'reason' => $dispatch_block_reason,
                    'rows' => count($eligible_entries),
                    'priority_flushed_rows' => 0,
                    'next_dispatch_utc' => $this->next_dispatch_boundary_utc_mysql(),
                ]);
                $run_status = 'dispatch_blocked';
                return;
            }

            if (!$dispatch_due) {
                $this->log_ctx('batch_gate', [
                    'run_id' => $run_id,
                    'priority_rows' => 0,
                    'scheduled_rows' => count($eligible_entries),
                    'scheduled_ready_rows' => 0,
                    'deferred_post_window_rows' => 0,
                    'priority_flushed_rows' => 0,
                    'force_flush' => 0,
                    'dispatch_due' => 0,
                    'reason' => 'before_dispatch_time',
                    'next_dispatch_utc' => $this->next_dispatch_boundary_utc_mysql(),
                ]);
                $run_status = 'holding_until_dispatch';
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
    ): void {
        // Collapse per-row lines into a single aggregate payload.
        $aggregate_lines = $this->aggregate_lines($batch_candidates);
        if (empty($aggregate_lines)) {
            $this->log_ctx('aggregate_skip_no_lines', ['run_id' => $run_id, 'batch_kind' => $batch_kind]);
            return;
        }

        $ship_to = $this->resolve_batch_ship_to($batch_candidates);
        if (!($ship_to instanceof DistributorShipTo)) {
            $this->log_ctx('aggregate_missing_ship_to', ['run_id' => $run_id, 'batch_kind' => $batch_kind]);
            foreach ($batch_candidates as $entry) {
                /** @var OrderPlacementJobRow $job */
                $job = $entry['job'];
                $msg = $this->is_ca_relay_mode()
                    ? 'CA relay batch failed: missing relay ship-to address.'
                    : 'Batch aggregate failed: missing ship-to address.';
                $this->mark_failed((int) $job->order_id, (string) $job->job_key_norm(), $msg);
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
            $this->get_batch_description_prefix() . ' [' . $batch_kind . ']',
            $this->is_ca_relay_mode() ? 'direct_ship_non_ffl' : 'dealer_fulfilled'
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
                    ->with_last_step('place')
                    ->with_last_error('')
                    ->with_last_codes([])
            );
        }

        try {
            // One upstream order placement call for the full aggregate payload.
            $result = $distributor->place_order($request);
        } catch (\Throwable $e) {
            $result = DistributorOrderResult::block_retryable(
                'Batch aggregate call exception: ' . $e->getMessage(),
                [DistributorOrderResult::REASON_RETRY_UNKNOWN],
                [
                    'aggregate_exception' => 1,
                    'exception' => $e->getMessage(),
                ]
            );
        }

        $this->log_ctx('aggregate_result', [
            'run_id' => $run_id,
            'batch_kind' => $batch_kind,
            'po' => $po,
            'ok' => $result->ok ? 1 : 0,
            'code' => $result->code,
            'message' => (string) $result->message,
            'codes' => (array) $result->codes,
            'external_order_ids' => (array) $result->external_order_ids,
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

            $this->apply_successful_dealer_batch_profit_audit_shipping_rule(
                $batch_candidates,
                $po,
                $batch_kind
            );

            BatchOrderNotificationEmail::send(
                $this->get_distributor_id(),
                $po,
                $batch_kind,
                $result,
                $batch_candidates,
                $aggregate_lines,
                $ship_to,
                $this->is_ca_relay_mode()
            );
            return;
        }

        if ($result->code === DistributorOrderResult::CODE_SUBMITTED) {
            // Async path: stamp snapshots/IDs/PO and hold each row until the distributor posts an acknowledgement.
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
                OrderPlacementJobLifeCycle::mark_job_awaiting_ack(
                    $this->jobs_table,
                    $order,
                    $job_key,
                    (string) $result->message,
                    (array) $result->codes
                );
            }

            return;
        }

        if (
            $result->code === DistributorOrderResult::CODE_MANUAL
            && $this->handle_manual_aggregate_result($result, $batch_candidates, $aggregate_lines, $ship_to, $po, $batch_kind, $run_id)
        ) {
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
     * Optional distributor-specific handling for aggregate MANUAL results.
     *
     * Default behavior preserves the historical fallback path: aggregate manual
     * or fatal outcomes are decomposed into single-row placement attempts. A
     * subclass can return true when a MANUAL aggregate result is itself the
     * desired terminal handoff.
     *
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     * @param array<int,DistributorOrderLine> $aggregate_lines
     */
    protected function handle_manual_aggregate_result(
        DistributorOrderResult $result,
        array $batch_candidates,
        array $aggregate_lines,
        ?DistributorShipTo $ship_to,
        string $po,
        string $batch_kind,
        string $run_id
    ): bool {
        return false;
    }

    /**
     * Distributor-specific escape hatch for rows that should stay visible but be
     * manually ordered outside the aggregate batch call.
     *
     * @param array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>} $entry
     */
    protected function should_hold_batch_entry_for_manual_order(array $entry): bool
    {
        return false;
    }

    protected function manual_batch_entry_message(): string
    {
        return 'Batch row requires manual distributor ordering.';
    }

    protected function manual_batch_entry_reason_code(): string
    {
        return 'BATCH_MANUAL_ORDER';
    }

    protected function should_alert_risky_manual_hold_entries(): bool
    {
        return false;
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $manual_hold_entries
     * @param array<string,int> $demand_by_upc
     * @param array<string,int|null> $stock_cache
     * @param array<string,true> $risky_upcs
     */
    protected function send_risky_manual_hold_alert(
        array $manual_hold_entries,
        array $demand_by_upc,
        array $stock_cache,
        array $risky_upcs,
        int $low_threshold,
        string $run_id
    ): void {
    }

    /**
     * @param array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>} $entry
     */
    private function mark_batch_entry_manual(array $entry): void
    {
        $job = $entry['job'] ?? null;
        $order = $entry['order'] ?? null;
        if (!($job instanceof OrderPlacementJobRow) || !($order instanceof WC_Order)) {
            return;
        }

        $job_key = (string) $job->job_key_norm();
        if ($job_key === '') {
            return;
        }

        OrderPlacementJobWriter::apply_patch_for_order(
            $this->jobs_table,
            $order,
            $job_key,
            OrderPlacementJobPatch::empty()
                ->with_status(OrderPlacementKeys::JOB_STATUS_MANUAL)
                ->with_last_step('place')
                ->with_last_error($this->manual_batch_entry_message())
                ->with_last_codes([$this->manual_batch_entry_reason_code()])
                ->clear_action_and_schedule()
        );
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $entries
     * @return array<string,int> normalized_upc => summed_quantity
     */
    private function build_demand_by_upc(array $entries): array
    {
        // Demand is calculated across all queued rows so risk checks reflect aggregate pressure.
        $demand = [];
        foreach ($entries as $entry) {
            foreach ($entry['lines'] as $line) {
                $upc_key = $this->normalize_upc_key((string) $line->upc);
                if ($upc_key === '') {
                    continue;
                }
                $qty = max(0, (int) $line->quantity);
                if ($qty <= 0) {
                    continue;
                }
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
     * @param array<string,int>      $demand_by_upc
     * @param array<string,int|null> $stock_cache
     * @return array<string,true> set of risky normalized UPC keys
     */
    private function find_risky_upcs(array $demand_by_upc, array $stock_cache, int $threshold): array
    {
        // UPC is risky when:
        // - stock unknown (null), or
        // - stock <= threshold, or
        // - stock < total demand queued now.
        $risky = [];
        foreach ($demand_by_upc as $upc => $demand_qty) {
            $stock = $stock_cache[$upc] ?? null;
            if ($stock === null || $stock <= $threshold || $stock < $demand_qty) {
                $risky[$upc] = true;
            }
        }
        return $risky;
    }

    /**
     * @param array<int,DistributorOrderLine> $lines
     * @param array<string,true>              $risky_upcs
     */
    private function entry_contains_any_upc(array $lines, array $risky_upcs): bool
    {
        foreach ($lines as $line) {
            $upc = $this->normalize_upc_key((string) $line->upc);
            if ($upc === '') {
                continue;
            }
            if (isset($risky_upcs[$upc])) {
                return true;
            }
        }
        return false;
    }

    private function normalize_upc_key(string $upc): string
    {
        // Keep UPC normalization conservative and stable with prior behavior:
        // trim, strip non-digits, keep as plain numeric string when possible.
        $upc = trim($upc);
        if ($upc === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $upc);
        $digits = is_string($digits) ? $digits : '';

        return trim($digits);
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

    /**
     * Reconcile estimated per-order inbound freight with the one aggregate
     * distributor shipment after successful placement. A qualifying batch
     * waives inbound freight; a paid batch shares one freight charge across its
     * contributing rows instead of charging that fee once per Woo order.
     *
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     */
    private function apply_successful_dealer_batch_profit_audit_shipping_rule(
        array $batch_candidates,
        string $po,
        string $batch_kind
    ): void {
        if ($this->is_ca_relay_mode()) {
            return;
        }

        $batch_total = $this->dealer_batch_distributor_cost_total($batch_candidates);
        $threshold = DealerBatchOptimizerConfig::free_shipping_threshold($this->get_distributor_id());
        $free_inbound = $threshold > 0.0 && $batch_total >= $threshold;

        $this->log_ctx('dealer_batch_inbound_shipping_rule', [
            'po' => $po,
            'batch_kind' => $batch_kind,
            'distributor' => $this->get_distributor_id(),
            'batch_total' => $batch_total,
            'threshold' => $threshold,
            'free_inbound' => $free_inbound ? 1 : 0,
            'rows' => count($batch_candidates),
        ]);

        if (!$free_inbound) {
            $this->allocate_paid_dealer_batch_inbound_shipping(
                $batch_candidates,
                $po,
                $batch_kind,
                $batch_total,
                $threshold
            );
            return;
        }

        $seen_order_ids = [];
        $adjusted_orders = 0;
        $waived_total = 0.0;

        foreach ($batch_candidates as $entry) {
            $order = $entry['order'] ?? null;
            if (!($order instanceof WC_Order)) {
                continue;
            }

            $order_id = (int) $order->get_id();
            if ($order_id <= 0 || isset($seen_order_ids[$order_id])) {
                continue;
            }
            $seen_order_ids[$order_id] = true;

            $waived = $this->waive_dealer_inbound_shipping_for_order(
                $order,
                $po,
                $batch_kind,
                $batch_total,
                $threshold
            );

            if ($waived <= 0.0) {
                continue;
            }

            $waived_total += $waived;
            $adjusted_orders++;

            OrderProfitAuditMeta::recalculate_order($order, true);
        }

        $this->log_ctx('dealer_batch_inbound_shipping_rule_applied', [
            'po' => $po,
            'batch_kind' => $batch_kind,
            'distributor' => $this->get_distributor_id(),
            'batch_total' => $batch_total,
            'adjusted_orders' => $adjusted_orders,
            'waived_total' => $waived_total,
        ]);
    }

    /**
     * A paid dealer batch incurs one distributor freight charge, not one charge
     * per contributing Woo order. Split that charge across the successful job
     * rows and rewrite only the dealer-inbound portion of each shipping plan.
     *
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     */
    private function allocate_paid_dealer_batch_inbound_shipping(
        array $batch_candidates,
        string $po,
        string $batch_kind,
        float $batch_total,
        float $threshold
    ): void {
        $batch_shipping_cost = $this->dealer_batch_paid_inbound_shipping_cost($batch_candidates);

        /** @var array<int,array{order:WC_Order,row_count:int}> $orders */
        $orders = [];
        $row_count = 0;
        foreach ($batch_candidates as $entry) {
            $order = $entry['order'] ?? null;
            if (!($order instanceof WC_Order)) {
                continue;
            }

            $order_id = (int) $order->get_id();
            if ($order_id <= 0) {
                continue;
            }

            if (!isset($orders[$order_id])) {
                if ($this->dealer_inbound_shipping_match_mode($order, $this->get_distributor_id()) === '') {
                    continue;
                }

                $orders[$order_id] = [
                    'order' => $order,
                    'row_count' => 0,
                ];
            }

            $orders[$order_id]['row_count']++;
            $row_count++;
        }

        if ($row_count < 1) {
            return;
        }

        // Allocate in cents and give any remainder cents to the earliest rows.
        // Grouping afterward lets multiple batch rows from one Woo order receive
        // the correct combined share without rewriting that order repeatedly.
        $total_cents = max(0, (int) round($batch_shipping_cost * 100.0));
        $base_cents = intdiv($total_cents, $row_count);
        $remainder_cents = $total_cents % $row_count;
        $row_index = 0;
        $allocated_total = 0.0;
        $adjusted_orders = 0;

        foreach ($orders as $entry) {
            $order_allocation_cents = 0;
            for ($i = 0; $i < (int) $entry['row_count']; $i++) {
                $order_allocation_cents += $base_cents + ($row_index < $remainder_cents ? 1 : 0);
                $row_index++;
            }

            $allocated_cost = $order_allocation_cents / 100.0;
            /** @var WC_Order $order */
            $order = $entry['order'];
            $changed = $this->set_paid_dealer_batch_inbound_shipping_for_order(
                $order,
                $allocated_cost,
                $batch_shipping_cost,
                $row_count,
                $po,
                $batch_kind,
                $batch_total,
                $threshold
            );

            if (!$changed) {
                continue;
            }

            $allocated_total += $allocated_cost;
            $adjusted_orders++;
            OrderProfitAuditMeta::recalculate_order($order, true);
        }

        $this->log_ctx('dealer_batch_paid_inbound_shipping_allocated', [
            'po' => $po,
            'batch_kind' => $batch_kind,
            'distributor' => $this->get_distributor_id(),
            'batch_total' => $batch_total,
            'threshold' => $threshold,
            'batch_shipping_cost' => $batch_shipping_cost,
            'batch_rows' => $row_count,
            'adjusted_orders' => $adjusted_orders,
            'allocated_total' => $allocated_total,
        ]);
    }

    /**
     * Resolve the one paid freight charge for the aggregate batch from the
     * current target distributor offers. The maximum handles batches whose
     * product classes expose different lane fees; the batch pays one lane fee.
     *
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     */
    private function dealer_batch_paid_inbound_shipping_cost(array $batch_candidates): float
    {
        global $wpdb;

        $upcs = [];
        foreach ($batch_candidates as $entry) {
            $lines = $entry['lines'] ?? [];
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                if (!($line instanceof DistributorOrderLine)) {
                    continue;
                }

                $upc = OrderPlacementProductUtil::normalize_upc((string) $line->upc);
                if ($upc !== '') {
                    $upcs[$upc] = $upc;
                }
            }
        }

        if ($wpdb && !empty($upcs)) {
            $offers_table = DistributorOffersStore::table_name();
            $placeholders = implode(',', array_fill(0, count($upcs), '%s'));
            $params = array_merge([$this->get_distributor_id()], array_values($upcs));
            $sql = $wpdb->prepare(
                "SELECT MAX(shipping_cost)
                 FROM {$offers_table}
                 WHERE distributor_id = %s
                   AND enabled = 1
                   AND upc IN ({$placeholders})",
                ...$params
            ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $shipping_cost = $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if ($shipping_cost !== null && is_numeric($shipping_cost)) {
                return max(0.0, (float) $shipping_cost);
            }
        }

        return max(0.0, DealerBatchOptimizerConfig::shipping_penalty($this->get_distributor_id()));
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     */
    private function dealer_batch_distributor_cost_total(array $batch_candidates): float
    {
        $total = 0.0;

        foreach ($batch_candidates as $entry) {
            $order = $entry['order'] ?? null;
            $lines = $entry['lines'] ?? [];
            if (!($order instanceof WC_Order) || !is_array($lines)) {
                continue;
            }

            $total += $this->dealer_batch_candidate_distributor_cost_total($order, $lines);
        }

        return max(0.0, $total);
    }

    /**
     * @param array<int,DistributorOrderLine> $lines
     */
    private function dealer_batch_candidate_distributor_cost_total(WC_Order $order, array $lines): float
    {
        $needed_by_upc = [];
        foreach ($lines as $line) {
            if (!($line instanceof DistributorOrderLine)) {
                continue;
            }

            $upc = OrderPlacementProductUtil::normalize_upc((string) $line->upc);
            if ($upc === '') {
                continue;
            }

            if (!isset($needed_by_upc[$upc])) {
                $needed_by_upc[$upc] = 0;
            }
            $needed_by_upc[$upc] += max(1, (int) $line->quantity);
        }

        if (empty($needed_by_upc)) {
            return 0.0;
        }

        $distributor = $this->handler->get_distributor_by_id($this->get_distributor_id());
        if ($distributor instanceof DistributorBase) {
            $direct_total = 0.0;
            $all_priced = true;
            foreach ($needed_by_upc as $upc => $qty) {
                try {
                    $unit_price = $distributor->get_distributor_price_by_upc((string) $upc);
                } catch (\Throwable $e) {
                    $unit_price = null;
                }

                $unit_price = is_numeric($unit_price) ? (float) $unit_price : 0.0;
                if ($unit_price <= 0.0) {
                    $all_priced = false;
                    break;
                }

                $direct_total += $unit_price * (float) max(1, (int) $qty);
            }

            if ($all_priced) {
                return max(0.0, $direct_total);
            }
        }

        $total = 0.0;
        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            if (!($product instanceof WC_Product)) {
                $product = $this->resolve_order_item_product($item);
            }

            $source_dist = $this->order_item_source_distributor($item, $product);
            if ($source_dist !== '' && $source_dist !== $this->get_distributor_id()) {
                continue;
            }

            $upc = $this->order_item_upc($item, $product);
            if ($upc === '' || empty($needed_by_upc[$upc])) {
                continue;
            }

            $take_qty = min(max(1, (int) $item->get_quantity()), (int) $needed_by_upc[$upc]);
            $unit_cost = $this->order_item_distributor_unit_cost($item, $product);
            if ($unit_cost <= 0.0) {
                $needed_by_upc[$upc] -= $take_qty;
                continue;
            }

            $total += $unit_cost * (float) $take_qty;
            $needed_by_upc[$upc] -= $take_qty;
        }

        return max(0.0, $total);
    }

    private function set_paid_dealer_batch_inbound_shipping_for_order(
        WC_Order $order,
        float $allocated_cost,
        float $batch_shipping_cost,
        int $batch_row_count,
        string $po,
        string $batch_kind,
        float $batch_total,
        float $threshold
    ): bool {
        $dist_id = $this->get_distributor_id();
        $match_mode = $this->dealer_inbound_shipping_match_mode($order, $dist_id);
        if ($match_mode === '') {
            $this->log_ctx('dealer_batch_paid_inbound_shipping_skip', [
                'order_id' => (int) $order->get_id(),
                'po' => $po,
                'distributor' => $dist_id,
                'reason' => 'no_unambiguous_dealer_inbound_shipping_row',
            ]);
            return false;
        }

        $allocation_written = false;
        $order_changed = false;

        foreach ($order->get_items('shipping') as $shipping_item) {
            if (!($shipping_item instanceof WC_Order_Item_Shipping)) {
                continue;
            }

            $plan = $this->decode_shipping_meta_array($shipping_item->get_meta('fflhub_shipping_plan', true));
            $by_dist = (isset($plan['by_dist']) && is_array($plan['by_dist']))
                ? $plan['by_dist']
                : $this->decode_shipping_meta_array($shipping_item->get_meta('fflhub_shipping_by_dist', true));
            if (empty($by_dist)) {
                continue;
            }

            $old_distributor_cost_total = $this->sum_shipping_by_dist_cost($by_dist);
            $item_changed = false;

            foreach ($by_dist as $key => $raw_row) {
                $row = is_object($raw_row) ? (array) $raw_row : $raw_row;
                if (!is_array($row) || empty($row['dealer_inbound'])) {
                    continue;
                }

                $row_dist_id = strtolower(trim((string) ($row['dist_id'] ?? $key)));
                $matches = $match_mode === 'current_distributor'
                    ? $row_dist_id === $dist_id
                    : true;
                if (!$matches) {
                    continue;
                }

                $row_allocation = $allocation_written ? 0.0 : max(0.0, $allocated_cost);
                $old_cost = $this->non_negative_float($row['cost'] ?? null) ?? 0.0;
                $direct_cost = $this->dealer_batch_direct_distributor_shipping_cost($row);
                $new_cost = max(0.0, $direct_cost + $row_allocation);

                if (!array_key_exists('cost_before_dealer_batch_allocation', $row)) {
                    $row['cost_before_dealer_batch_allocation'] = $this->money4($old_cost);
                }
                $row['dist_id'] = $dist_id;
                $row['cost'] = $this->money4($new_cost);
                $row['dealer_inbound_cost_allocated'] = $this->money4($row_allocation);
                $row['dealer_batch_inbound_shipping_allocated'] = true;
                $row['dealer_batch_inbound_shipping_po'] = $po;
                $row['dealer_batch_inbound_shipping_batch_kind'] = $batch_kind;
                $row['dealer_batch_inbound_shipping_batch_total'] = $this->money4($batch_total);
                $row['dealer_batch_inbound_shipping_threshold'] = $this->money4($threshold);
                $row['dealer_batch_inbound_shipping_batch_cost'] = $this->money4($batch_shipping_cost);
                $row['dealer_batch_inbound_shipping_batch_rows'] = $batch_row_count;
                $row['dealer_batch_inbound_shipping_updated_at_utc'] = gmdate('Y-m-d H:i:s');

                $by_dist[$key] = $row;
                $allocation_written = true;
                $item_changed = true;
                if (abs($new_cost - $old_cost) > 0.00005 || $row_dist_id !== $dist_id) {
                    $order_changed = true;
                }
            }

            if (!$item_changed) {
                continue;
            }

            $new_distributor_cost_total = $this->sum_shipping_by_dist_cost($by_dist);
            $plan['by_dist'] = $by_dist;
            $plan['distributor_cost_total'] = $this->money4($new_distributor_cost_total);
            $plan['total_cost'] = $this->money4(
                $this->adjust_shipping_plan_total_after_distributor_cost_change(
                    $plan,
                    $old_distributor_cost_total,
                    $new_distributor_cost_total
                )
            );
            $plan['dealer_batch_inbound_shipping_po'] = $po;
            $plan['dealer_batch_inbound_shipping_batch_total'] = $this->money4($batch_total);
            $plan['dealer_batch_inbound_shipping_threshold'] = $this->money4($threshold);
            $plan['dealer_batch_inbound_shipping_batch_cost'] = $this->money4($batch_shipping_cost);
            $plan['dealer_batch_inbound_shipping_batch_rows'] = $batch_row_count;
            $plan['dealer_batch_inbound_shipping_allocated_cost'] = $this->money4($allocated_cost);
            $plan['dealer_batch_inbound_shipping_updated_at_utc'] = gmdate('Y-m-d H:i:s');

            $shipping_item->update_meta_data('fflhub_shipping_plan', wp_json_encode($plan));
            $shipping_item->update_meta_data('fflhub_shipping_by_dist', wp_json_encode($by_dist));
            $shipping_item->update_meta_data('fflhub_shipping_cost_total', $plan['total_cost']);
            $shipping_item->save();
        }

        if (!$allocation_written) {
            return false;
        }

        $order->add_order_note(sprintf(
            'FFLHub dealer batch allocated $%s of the $%s inbound distributor shipping charge to this order across %d batch row(s) on PO %s.',
            number_format($allocated_cost, 2, '.', ''),
            number_format($batch_shipping_cost, 2, '.', ''),
            $batch_row_count,
            $po
        ));
        $order->save();

        return $order_changed;
    }

    private function dealer_inbound_shipping_match_mode(WC_Order $order, string $dist_id): string
    {
        $current_distributor_rows = 0;
        $all_dealer_inbound_rows = 0;

        foreach ($order->get_items('shipping') as $shipping_item) {
            if (!($shipping_item instanceof WC_Order_Item_Shipping)) {
                continue;
            }

            $plan = $this->decode_shipping_meta_array($shipping_item->get_meta('fflhub_shipping_plan', true));
            $by_dist = (isset($plan['by_dist']) && is_array($plan['by_dist']))
                ? $plan['by_dist']
                : $this->decode_shipping_meta_array($shipping_item->get_meta('fflhub_shipping_by_dist', true));

            foreach ($by_dist as $key => $raw_row) {
                $row = is_object($raw_row) ? (array) $raw_row : $raw_row;
                if (!is_array($row) || empty($row['dealer_inbound'])) {
                    continue;
                }

                $all_dealer_inbound_rows++;
                $row_dist_id = strtolower(trim((string) ($row['dist_id'] ?? $key)));
                if ($row_dist_id === $dist_id) {
                    $current_distributor_rows++;
                }
            }
        }

        if ($current_distributor_rows > 0) {
            return 'current_distributor';
        }

        // The optimizer may have moved the only dealer lane after checkout,
        // leaving the shipping-plan distributor label stale. One inbound row is
        // unambiguous and can safely be relabeled to the distributor that fired.
        return $all_dealer_inbound_rows === 1 ? 'sole_dealer_inbound' : '';
    }

    /**
     * Preserve distributor-paid direct lanes while replacing dealer inbound.
     *
     * @param array<string,mixed> $row
     */
    private function dealer_batch_direct_distributor_shipping_cost(array $row): float
    {
        $fallback_lane_fee = $this->non_negative_float($row['lane_fee'] ?? null) ?? 0.0;
        $total = 0.0;

        if (!empty($row['direct_home'])) {
            $total += $this->non_negative_float($row['direct_home_lane_fee'] ?? null) ?? $fallback_lane_fee;
        }
        if (!empty($row['direct_ffl'])) {
            $total += $this->non_negative_float($row['direct_ffl_lane_fee'] ?? null) ?? $fallback_lane_fee;
        }

        return max(0.0, $total);
    }

    private function waive_dealer_inbound_shipping_for_order(
        WC_Order $order,
        string $po,
        string $batch_kind,
        float $batch_total,
        float $threshold
    ): float {
        $dist_id = $this->get_distributor_id();
        $waived_total = 0.0;

        foreach ($order->get_items('shipping') as $shipping_item) {
            if (!($shipping_item instanceof WC_Order_Item_Shipping)) {
                continue;
            }

            $plan = $this->decode_shipping_meta_array($shipping_item->get_meta('fflhub_shipping_plan', true));
            $by_dist = (isset($plan['by_dist']) && is_array($plan['by_dist']))
                ? $plan['by_dist']
                : $this->decode_shipping_meta_array($shipping_item->get_meta('fflhub_shipping_by_dist', true));

            if (empty($by_dist)) {
                continue;
            }

            $changed = false;
            $item_waived_total = 0.0;
            foreach ($by_dist as $key => $row) {
                if (is_object($row)) {
                    $row = (array) $row;
                }
                if (!is_array($row)) {
                    continue;
                }

                $row_dist_id = strtolower(trim((string) ($row['dist_id'] ?? $key)));
                if ($row_dist_id !== $dist_id) {
                    continue;
                }

                $waived = $this->waive_dealer_inbound_shipping_row($row, $po, $batch_kind, $batch_total, $threshold);
                if ($waived <= 0.0) {
                    continue;
                }

                $waived_total += $waived;
                $item_waived_total += $waived;
                $by_dist[$key] = $row;
                $changed = true;
            }

            if (!$changed) {
                continue;
            }

            $plan['by_dist'] = $by_dist;
            $distributor_cost_total = $this->sum_shipping_by_dist_cost($by_dist);
            $plan['distributor_cost_total'] = $this->money4($distributor_cost_total);
            $plan['total_cost'] = $this->money4(
                $this->adjust_shipping_plan_total_after_dealer_inbound_waiver(
                    $plan,
                    $distributor_cost_total,
                    $item_waived_total
                )
            );
            $plan['dealer_batch_free_inbound_shipping_po'] = $po;
            $plan['dealer_batch_free_inbound_shipping_batch_total'] = $this->money4($batch_total);
            $plan['dealer_batch_free_inbound_shipping_threshold'] = $this->money4($threshold);
            $plan['dealer_batch_free_inbound_shipping_updated_at_utc'] = gmdate('Y-m-d H:i:s');

            $shipping_item->update_meta_data('fflhub_shipping_plan', wp_json_encode($plan));
            $shipping_item->update_meta_data('fflhub_shipping_by_dist', wp_json_encode($by_dist));
            $shipping_item->update_meta_data('fflhub_shipping_cost_total', $plan['total_cost']);
            $shipping_item->save();
        }

        if ($waived_total > 0.0) {
            $order->add_order_note(sprintf(
                'FFLHub dealer batch %s exceeded $%s. Waived %s inbound distributor shipping for profit audit on PO %s.',
                $batch_kind,
                number_format($threshold, 2, '.', ''),
                '$' . number_format($waived_total, 2, '.', ''),
                $po
            ));
            $order->save();
        }

        return max(0.0, $waived_total);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function waive_dealer_inbound_shipping_row(
        array &$row,
        string $po,
        string $batch_kind,
        float $batch_total,
        float $threshold
    ): float {
        if (empty($row['dealer_inbound'])) {
            return 0.0;
        }

        if (!empty($row['dealer_batch_free_inbound_shipping_applied'])) {
            return 0.0;
        }

        $cost = $this->non_negative_float($row['cost'] ?? null) ?? 0.0;
        if ($cost <= 0.0) {
            return 0.0;
        }

        $active_lanes = max(0, (int) ($row['active_lanes'] ?? 0));
        $direct_lanes = 0;
        if (!empty($row['direct_home'])) {
            $direct_lanes++;
        }
        if (!empty($row['direct_ffl'])) {
            $direct_lanes++;
        }

        $lane_fee = $this->positive_float($row['dealer_inbound_lane_fee'] ?? null);
        if ($lane_fee === null) {
            $lane_fee = $this->positive_float($row['lane_fee'] ?? null);
        }
        if ($lane_fee === null && $active_lanes > 0) {
            $lane_fee = $cost / (float) $active_lanes;
        }

        $waived = ($direct_lanes < 1)
            ? $cost
            : min($cost, max(0.0, (float) ($lane_fee ?? 0.0)));

        if ($waived <= 0.0) {
            return 0.0;
        }

        $row['cost_before_dealer_batch_free_inbound'] = $this->money4($cost);
        $row['dealer_inbound_cost_waived'] = $this->money4($waived);
        $row['cost'] = $this->money4(max(0.0, $cost - $waived));
        $row['dealer_batch_free_inbound_shipping_applied'] = true;
        $row['dealer_batch_free_inbound_shipping_po'] = $po;
        $row['dealer_batch_free_inbound_shipping_batch_kind'] = $batch_kind;
        $row['dealer_batch_free_inbound_shipping_batch_total'] = $this->money4($batch_total);
        $row['dealer_batch_free_inbound_shipping_threshold'] = $this->money4($threshold);
        $row['dealer_batch_free_inbound_shipping_updated_at_utc'] = gmdate('Y-m-d H:i:s');

        return $waived;
    }

    /**
     * @param mixed $value
     * @return array<string|int,mixed>
     */
    private function decode_shipping_meta_array($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            $decoded = json_decode(wp_json_encode($value), true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            $unserialized = maybe_unserialize($value);
            if (is_array($unserialized)) {
                return $unserialized;
            }
        }

        return [];
    }

    /**
     * @param array<string|int,mixed> $by_dist
     */
    private function sum_shipping_by_dist_cost(array $by_dist): float
    {
        $total = 0.0;
        foreach ($by_dist as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (!is_array($row)) {
                continue;
            }

            $total += $this->non_negative_float($row['cost'] ?? null) ?? 0.0;
        }

        return max(0.0, $total);
    }

    /**
     * @param array<string,mixed> $plan
     */
    private function adjust_shipping_plan_total_after_dealer_inbound_waiver(
        array $plan,
        float $distributor_cost_total,
        float $waived_total
    ): float {
        $dealer_home = $this->non_negative_float($plan['dealer_outbound_home_cost'] ?? null);
        $dealer_ffl = $this->non_negative_float($plan['dealer_outbound_ffl_cost'] ?? null);

        if ($dealer_home !== null || $dealer_ffl !== null) {
            return max(0.0, $distributor_cost_total + (float) ($dealer_home ?? 0.0) + (float) ($dealer_ffl ?? 0.0));
        }

        $existing_total = $this->non_negative_float($plan['total_cost'] ?? null);
        if ($existing_total !== null) {
            return max(0.0, $existing_total - $waived_total);
        }

        return max(0.0, $distributor_cost_total);
    }

    /**
     * @param array<string,mixed> $plan
     */
    private function adjust_shipping_plan_total_after_distributor_cost_change(
        array $plan,
        float $old_distributor_cost_total,
        float $new_distributor_cost_total
    ): float {
        $dealer_home = $this->non_negative_float($plan['dealer_outbound_home_cost'] ?? null);
        $dealer_ffl = $this->non_negative_float($plan['dealer_outbound_ffl_cost'] ?? null);

        if ($dealer_home !== null || $dealer_ffl !== null) {
            return max(0.0, $new_distributor_cost_total + (float) ($dealer_home ?? 0.0) + (float) ($dealer_ffl ?? 0.0));
        }

        $existing_total = $this->non_negative_float($plan['total_cost'] ?? null);
        if ($existing_total !== null) {
            return max(0.0, $existing_total - $old_distributor_cost_total + $new_distributor_cost_total);
        }

        return max(0.0, $new_distributor_cost_total);
    }

    private function resolve_order_item_product(WC_Order_Item_Product $item): ?WC_Product
    {
        $variation_id = (int) $item->get_variation_id();
        if ($variation_id > 0) {
            $product = wc_get_product($variation_id);
            if ($product instanceof WC_Product) {
                return $product;
            }
        }

        $product_id = (int) $item->get_product_id();
        if ($product_id > 0) {
            $product = wc_get_product($product_id);
            if ($product instanceof WC_Product) {
                return $product;
            }
        }

        return null;
    }

    private function order_item_upc(WC_Order_Item_Product $item, ?WC_Product $product): string
    {
        if ($product instanceof WC_Product) {
            $upc = OrderPlacementProductUtil::extract_upc_from_product($product);
            if ($upc !== '') {
                return $upc;
            }

            $state_row = ProductStateStore::get_row_for_product($product);
            if (is_array($state_row)) {
                $upc = OrderPlacementProductUtil::normalize_upc((string) ($state_row['upc'] ?? ''));
                if ($upc !== '') {
                    return $upc;
                }
            }
        }

        foreach (['_upc', 'upc', 'UPC'] as $meta_key) {
            $upc = OrderPlacementProductUtil::normalize_upc((string) $item->get_meta($meta_key, true));
            if ($upc !== '') {
                return $upc;
            }
        }

        return OrderPlacementProductUtil::normalize_upc((string) $item->get_meta('_sku', true));
    }

    private function order_item_source_distributor(WC_Order_Item_Product $item, ?WC_Product $product): string
    {
        $saved = strtolower(trim((string) $item->get_meta('_fflhub_order_source_distributor', true)));
        if ($saved !== '') {
            return $saved;
        }

        if ($product instanceof WC_Product) {
            $state_row = ProductStateStore::get_row_for_product($product);
            if (is_array($state_row)) {
                return strtolower(trim((string) ($state_row['distributor_id'] ?? '')));
            }
        }

        return '';
    }

    private function order_item_distributor_unit_cost(WC_Order_Item_Product $item, ?WC_Product $product): float
    {
        $saved = $this->positive_float($item->get_meta('_fflhub_order_distributor_unit_cost', true));
        if ($saved !== null) {
            return $saved;
        }

        if ($product instanceof WC_Product) {
            $state_row = ProductStateStore::get_row_for_product($product);
            $dealer_price = is_array($state_row)
                ? $this->positive_float($state_row['dealer_price'] ?? null)
                : null;
            if ($dealer_price !== null) {
                return $dealer_price;
            }
        }

        return 0.0;
    }

    /**
     * @param mixed $value
     */
    private function positive_float($value): ?float
    {
        $float = $this->non_negative_float($value);
        return ($float !== null && $float > 0.0) ? $float : null;
    }

    /**
     * @param mixed $value
     */
    private function non_negative_float($value): ?float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $num = $raw;
        if (!is_numeric($num)) {
            $num = trim((string) preg_replace('/[^0-9\.\-]/', '', $raw));
        }

        if ($num === '' || !is_numeric($num)) {
            return null;
        }

        $float = (float) $num;
        if (!is_finite($float) || $float < 0.0) {
            return null;
        }

        return $float;
    }

    private function money4(float $value): string
    {
        return function_exists('wc_format_decimal')
            ? (string) wc_format_decimal(max(0.0, $value), 4)
            : number_format(max(0.0, $value), 4, '.', '');
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

    private function order_allows_batch_placement(WC_Order $order): bool
    {
        return $order->has_status(['processing', 'completed']);
    }

    private function pause_inactive_order_job(WC_Order $order, string $job_key): void
    {
        $order_id = (int) $order->get_id();
        $job_key = trim((string) $job_key);
        if ($order_id <= 0 || $job_key === '') {
            return;
        }

        $status = strtolower(trim((string) $order->get_status()));
        $status = $status !== '' ? $status : 'inactive';

        update_post_meta($order_id, OrderPlacementKeys::META_ORDER_SUSPENDED, '1');
        update_post_meta($order_id, OrderPlacementKeys::META_ORDER_STATUS_SUSPENDED, $status);

        OrderPlacementJobWriter::apply_patch(
            $this->jobs_table,
            $order_id,
            $job_key,
            OrderPlacementJobPatch::empty()
                ->with_status(OrderPlacementKeys::JOB_STATUS_PAUSED)
                ->with_last_step('place')
                ->with_last_error('Paused: order status is ' . $status)
                ->clear_action_and_schedule()
        );
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
        if (!$this->is_ca_relay_mode()) {
            return DealerBatchOptimizerConfig::dealer_batch_enabled();
        }

        return $this->truthy_option($this->opt_enabled_name(), true);
    }

    private function low_stock_threshold(): int
    {
        if (!$this->is_ca_relay_mode()) {
            return DealerBatchOptimizerConfig::low_stock_threshold();
        }

        return max(0, (int) get_option($this->opt_low_stock_threshold_name(), self::DEFAULT_LOW_STOCK_THRESHOLD));
    }

    private function retry_delay_seconds(): int
    {
        if (!$this->is_ca_relay_mode()) {
            return DealerBatchOptimizerConfig::retry_delay_seconds();
        }

        return max(30, (int) get_option($this->opt_retry_delay_name(), self::DEFAULT_RETRY_DELAY_SECONDS));
    }

    private function max_rows_per_run(): int
    {
        if (!$this->is_ca_relay_mode()) {
            return DealerBatchOptimizerConfig::max_rows_per_run();
        }

        return max(1, (int) get_option($this->opt_max_rows_name(), self::DEFAULT_MAX_ROWS_PER_RUN));
    }

    private function consume_force_flush(): bool
    {
        if (!$this->is_ca_relay_mode()) {
            return DealerBatchOptimizerConfig::consume_force_flush_for_option_prefix($this->get_option_prefix());
        }

        // One-shot toggle: read and immediately clear so only one run consumes it.
        $enabled = $this->truthy_option($this->opt_force_flush_name(), false);
        if ($enabled) {
            update_option($this->opt_force_flush_name(), '0', false);
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
    private function defer_candidates_until_next_dispatch_window(array $candidates, ?string $next_dispatch_utc = null): void
    {
        if (empty($candidates)) {
            return;
        }

        $next_dispatch_utc = trim((string) $next_dispatch_utc);
        if ($next_dispatch_utc === '') {
            $next_dispatch_utc = $this->next_dispatch_boundary_utc_mysql();
        }

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
        $next_dispatch_local = $this->next_dispatch_boundary_local($ctx);

        return $next_dispatch_local
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function maybe_run_global_dealer_batch_optimizer_preflight(string $run_id): string
    {
        if ($this->is_ca_relay_mode()) {
            return 'skipped_ca_relay';
        }

        if (!DealerBatchOptimizerConfig::optimizer_enabled()) {
            return 'skipped_disabled';
        }

        $ctx = $this->global_optimizer_dispatch_context();
        $token = (string) ($ctx['token'] ?? '');
        if ($token === '') {
            return (string) ($ctx['status'] ?? 'not_due');
        }

        if (!DealerBatchOptimizerConfig::claim_pre_dispatch_optimizer_token($token)) {
            return 'already_ran';
        }

        $horizon_mysql_utc = (string) ($ctx['horizon_mysql_utc'] ?? '');
        $this->log_ctx('optimizer_preflight_start', [
            'run_id' => $run_id,
            'dist_id' => $this->get_distributor_id(),
            'token' => $token,
            'horizon_mysql_utc' => $horizon_mysql_utc,
            'reason' => (string) ($ctx['status'] ?? ''),
        ]);

        (new DealerBatchShippingOptimizer($this->handler, $this->jobs_table))->run(
            'global_pre_dispatch:' . $this->get_distributor_id(),
            $horizon_mysql_utc
        );

        return 'ran';
    }

    /**
     * @return array{status:string,token:string,horizon_mysql_utc:string}
     */
    private function global_optimizer_dispatch_context(): array
    {
        $force_token = DealerBatchOptimizerConfig::force_flush_token();
        if (DealerBatchOptimizerConfig::truthy($force_token, false)) {
            return [
                'status' => 'force_flush',
                'token' => 'force:' . $force_token,
                'horizon_mysql_utc' => OrderPlacementTimeUtil::now_mysql_utc(),
            ];
        }

        $ctx = $this->dispatch_window_context();
        /** @var \DateTimeImmutable $now_local */
        $now_local = $ctx['now_local'];
        /** @var \DateTimeImmutable $dispatch_local */
        $dispatch_local = $ctx['dispatch_local'];

        if (!$this->is_dispatch_day_allowed($now_local)) {
            return [
                'status' => $this->dispatch_day_block_reason($now_local),
                'token' => '',
                'horizon_mysql_utc' => '',
            ];
        }

        if ($now_local < $dispatch_local) {
            return [
                'status' => 'before_dispatch',
                'token' => '',
                'horizon_mysql_utc' => '',
            ];
        }

        $dispatch_mysql_utc = $dispatch_local
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        return [
            'status' => 'dispatch_due',
            'token' => 'dispatch:' . $dispatch_mysql_utc,
            'horizon_mysql_utc' => $dispatch_mysql_utc,
        ];
    }

    /**
     * @param array{
     *   hour:int,
     *   minute:int,
     *   tz:\DateTimeZone,
     *   now_local:\DateTimeImmutable,
     *   dispatch_local:\DateTimeImmutable
     * } $ctx
     */
    private function next_dispatch_boundary_local(array $ctx): \DateTimeImmutable
    {
        /** @var \DateTimeImmutable $now_local */
        $now_local = $ctx['now_local'];
        /** @var \DateTimeImmutable $dispatch_local */
        $dispatch_local = $ctx['dispatch_local'];
        $hour = (int) ($ctx['hour'] ?? 17);
        $minute = (int) ($ctx['minute'] ?? 0);

        $candidate = ($now_local < $dispatch_local)
            ? $dispatch_local
            : $dispatch_local->modify('+1 day')->setTime($hour, $minute, 0);

        for ($i = 0; $i < 14; $i++) {
            if ($this->is_dispatch_day_allowed($candidate)) {
                return $candidate;
            }
            $candidate = $candidate->modify('+1 day')->setTime($hour, $minute, 0);
        }

        return $candidate;
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
        $hhmm = trim((string) get_option($this->opt_dispatch_time_name(), self::DEFAULT_DISPATCH_TIME));
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

        if (!$this->is_dispatch_day_allowed($now_local)) {
            return false;
        }

        if ($now_local < $dispatch_local) {
            return false;
        }

        // Allow only one scheduled flush per local day after dispatch time.
        $last_flush_utc = trim((string) get_option($this->opt_last_flush_name(), ''));
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

    private function current_dispatch_block_reason(): string
    {
        $ctx = $this->dispatch_window_context();
        /** @var \DateTimeImmutable $now_local */
        $now_local = $ctx['now_local'];

        if ($this->is_dispatch_day_allowed($now_local)) {
            return '';
        }

        return $this->dispatch_day_block_reason($now_local);
    }

    protected function is_dispatch_day_allowed(\DateTimeImmutable $local_time): bool
    {
        $day_of_week = (int) $local_time->format('N');
        return $day_of_week >= 1 && $day_of_week <= 5;
    }

    protected function dispatch_day_block_reason(\DateTimeImmutable $local_time): string
    {
        return 'dealer_batch_weekend_hold';
    }

    private function mark_scheduled_flush_attempt(string $now_mysql_utc): void
    {
        $now_mysql_utc = trim($now_mysql_utc);
        if ($now_mysql_utc === '') {
            $now_mysql_utc = gmdate('Y-m-d H:i:s');
        }

        update_option($this->opt_last_flush_name(), $now_mysql_utc, false);
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     */
    private function build_batch_po(array $batch_candidates): string
    {
        // Keep each order token short so the final PO stays <= 22 chars.
        $prefix = $this->sanitize_po_segment((string) $this->get_po_prefix());
        if ($prefix === '') {
            $prefix = 'BCHX';
        }

        if (empty($batch_candidates)) {
            return $prefix . gmdate('mdHi') . (string) wp_rand(1000, 9999);
        }

        $first = $this->batch_po_segment_from_candidate($batch_candidates[0]);
        $last = $this->batch_po_segment_from_candidate($batch_candidates[count($batch_candidates) - 1]);

        return $prefix . $first . $last;
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
        $raw = preg_replace('/[^A-Z0-9]+/', '', $raw);
        $raw = is_string($raw) ? $raw : '';

        if ($raw === '') {
            return 'NA';
        }

        // 4 + 8 + 8 = 20 max total for "{PFX}{first}{last}".
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
        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }

    private function acquire_lock(string $run_id): bool
    {
        // Lock payload is tiny JSON for simple stale-lock detection and traceability.
        $now = time();
        $current = get_option($this->lock_option_name(), '');
        if (is_string($current) && $current !== '') {
            $data = json_decode($current, true);
            $locked_at = is_array($data) ? (int) ($data['t'] ?? 0) : 0;
            if ($locked_at > 0 && ($now - $locked_at) < self::LOCK_TTL_SECONDS) {
                return false;
            }
        }

        $payload = wp_json_encode(['t' => $now, 'run' => $run_id]);
        update_option($this->lock_option_name(), (string) $payload, false);
        return true;
    }

    private function release_lock(string $run_id): void
    {
        $current = get_option($this->lock_option_name(), '');
        if (!is_string($current) || $current === '') {
            return;
        }
        $data = json_decode($current, true);
        $owner = is_array($data) ? (string) ($data['run'] ?? '') : '';
        if ($owner !== '' && $owner !== $run_id) {
            return;
        }
        delete_option($this->lock_option_name());
    }

    /**
     * @param array<string,mixed> $ctx
     */
    protected function log_ctx(string $msg, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, $this->get_log_prefix(), $msg, $ctx);
    }

    /**
     * @return OrderPlacementJobRow[]
     */
    private function find_jobs_for_batch_processing(string $now_mysql_utc, int $limit): array
    {
        if ($this->is_ca_relay_mode()) {
            return OrderPlacementJobsRepository::find_jobs_for_ca_relay_batch_processing(
                $this->jobs_table,
                $this->get_distributor_id(),
                $now_mysql_utc,
                $limit
            );
        }

        return OrderPlacementJobsRepository::find_jobs_for_dealer_batch_processing(
            $this->jobs_table,
            $this->get_distributor_id(),
            $now_mysql_utc,
            $limit
        );
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     */
    private function resolve_batch_ship_to(array $batch_candidates): ?DistributorShipTo
    {
        if ($this->is_ca_relay_mode()) {
            return DealerShipToResolver::resolve_relay();
        }

        // Dealer batches are inbound distributor orders. Use the configured
        // dealer ship-to address so EDI/file-based distributors do not ship an
        // aggregate batch to the first customer in the queue.
        $dealer_ship_to = DealerShipToResolver::resolve();
        if ($dealer_ship_to instanceof DistributorShipTo) {
            return $dealer_ship_to;
        }

        // Fallback keeps older installs operational until the dealer ship-to
        // setting is filled in.
        /** @var WC_Order $first_order */
        $first_order = $batch_candidates[0]['order'];
        return DistributorShipTo::from_order_shipping_fallback_billing($first_order);
    }

    private function is_ca_relay_mode(): bool
    {
        return $this->get_batch_mode() === self::MODE_CA_RELAY;
    }

    private function lock_option_name(): string
    {
        return $this->get_option_prefix() . '_lock';
    }

    private function opt_enabled_name(): string
    {
        if (!$this->is_ca_relay_mode()) {
            return DealerBatchOptimizerConfig::dealer_batch_option_name('enabled');
        }

        return $this->get_option_prefix() . '_enabled';
    }

    private function opt_dispatch_time_name(): string
    {
        if (!$this->is_ca_relay_mode()) {
            return DealerBatchOptimizerConfig::dealer_batch_option_name('dispatch_time');
        }

        return $this->get_option_prefix() . '_dispatch_time';
    }

    private function opt_low_stock_threshold_name(): string
    {
        if (!$this->is_ca_relay_mode()) {
            return DealerBatchOptimizerConfig::dealer_batch_option_name('low_stock_threshold');
        }

        return $this->get_option_prefix() . '_low_stock_threshold';
    }

    private function opt_retry_delay_name(): string
    {
        if (!$this->is_ca_relay_mode()) {
            return DealerBatchOptimizerConfig::dealer_batch_option_name('retry_delay_seconds');
        }

        return $this->get_option_prefix() . '_retry_delay_seconds';
    }

    private function opt_max_rows_name(): string
    {
        if (!$this->is_ca_relay_mode()) {
            return DealerBatchOptimizerConfig::dealer_batch_option_name('max_rows_per_run');
        }

        return $this->get_option_prefix() . '_max_rows_per_run';
    }

    private function opt_force_flush_name(): string
    {
        if (!$this->is_ca_relay_mode()) {
            return DealerBatchOptimizerConfig::dealer_batch_option_name('force_flush');
        }

        return $this->get_option_prefix() . '_force_flush';
    }

    private function opt_last_flush_name(): string
    {
        return $this->get_option_prefix() . '_last_scheduled_flush_at_utc';
    }
}
