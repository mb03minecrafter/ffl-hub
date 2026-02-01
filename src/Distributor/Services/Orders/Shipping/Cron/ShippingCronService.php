<?php

namespace FFLHub\Distributor\Services\Orders\Shipping\Cron;

use WC_Order;

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Models\PartialShipmentEmailContext;
use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementPipelineMetaStore;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Shipping\ShippingJobStore;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ShippingCronService
 *
 * Responsibility:
 * - Periodically poll distributors for shipment/tracking details for jobs that have
 *   successfully placed an order (status = success) but are not yet marked shipped.
 *
 * High-level flow:
 * 1) Compute a poll cutoff time (per-job pacing) and batch limit.
 * 2) Query jobs eligible for shipping polling (repo).
 * 3) For each eligible job:
 *    - skip if order is suspended/trashed (order-level gate)
 *    - resolve distributor + verify shipping lookup support
 *    - "touch" last_shipping_poll_at *only when we are actually polling*
 *    - call distributor->get_shipment_by_po(merchant_po)
 *    - if shipment found, persist it (tracking, invoices, service, weight, raw payload)
 *    - if the persist introduces new tracking, fire partial shipment email hook
 *    - if all successful jobs have tracking, mark the Woo order completed
 *
 * What the "cutoff" does:
 * - The repo query selects rows where last_shipping_poll_at is NULL/zero/older than cutoff.
 * - This throttles polling so we don't hammer distributor APIs.
 *
 * Notes:
 * - "ship_cutoff_mysql_utc" is intended to stop polling after a certain age threshold,
 *   but your current repository implementation must actually apply it in SQL for it
 *   to have any effect.
 */
final class ShippingCronService extends AbstractCronService
{
    private const LOG_PREFIX  = '[FFLHUB][ShippingPoller]';
    private const DEBUG_CONST = 'FFLHUB_DEBUG_SHIPPING';

    /**
     * Action Scheduler hook name.
     */
    public const CRON_HOOK = 'fflhub_place_shipping_poll';

    /**
     * Minimum spacing between polls per job (minutes).
     * 0 means "no spacing" (every run is eligible).
     */
    private const JOB_MIN_POLL_INTERVAL_MINUTES = 0;

    /**
     * How many jobs to process per run.
     */
    private const BATCH_LIMIT = 50;

    /**
     * Safety valve: stop polling jobs older than this many days since "first ship" detection.
     * (Only effective if repository query uses ship_cutoff_mysql_utc.)
     */
    private const MAX_DAYS_AFTER_FIRST_SHIP = 14;

    private DistributorHandler $handler;
    private OrderPlacementJobsTable $jobs_table;

    public function __construct(DistributorHandler $handler, OrderPlacementJobsTable $jobs_table)
    {
        $this->handler    = $handler;
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
        return 60; // 1 minute
    }

    /**
     * Worker entrypoint.
     *
     * Runs inside Action Scheduler. Keep it robust:
     * - Never fatal on single-row errors.
     * - Log enough context to debug.
     */
    public function run(): void
    {
        $run_started = microtime(true);

        $cutoff_unix      = time() - ((int) self::JOB_MIN_POLL_INTERVAL_MINUTES * 60);
        $cutoff_mysql_utc = OrderPlacementTimeUtil::unix_to_mysql_utc($cutoff_unix);

        $ship_cutoff_unix      = time() - ((int) self::MAX_DAYS_AFTER_FIRST_SHIP * DAY_IN_SECONDS);
        $ship_cutoff_mysql_utc = OrderPlacementTimeUtil::unix_to_mysql_utc($ship_cutoff_unix);

        $limit = max(1, (int) self::BATCH_LIMIT);

        // Minimal stats you actually care about
        $stats = [
            'eligible'        => 0,
            'polled'          => 0,
            'lookup_exception' => 0,
            'persist_exception' => 0,
            'shipment_found'  => 0,
            'tracking_added'  => 0,
            'email_fired'     => 0,
            'order_completed' => 0,
            'skipped_suspended' => 0,
            'skipped_disabled' => 0,
            'skipped_no_po'   => 0,
        ];

        try {
            $jobs = OrderPlacementJobsRepository::find_jobs_for_shipping_poll(
                $this->jobs_table,
                OrderPlacementKeys::JOB_STATUS_SUCCESS,
                $cutoff_mysql_utc,
                $ship_cutoff_mysql_utc,
                $limit
            );
        } catch (\Throwable $e) {
            $this->log_ctx('repo_exception', [
                'op'   => 'find_jobs_for_shipping_poll',
                'err'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return;
        }

        if (empty($jobs)) {
            // No log needed; this runs frequently.
            return;
        }

        $stats['eligible'] = count($jobs);

        foreach ($jobs as $job) {
            $order_id = (int) ($job->order_id ?? 0);
            $job_key  = OrderPlacementKeysUtil::normalize_job_key((string) ($job->job_key ?? ''));
            $dist_id  = (string) ($job->dist_id ?? '');
            $po       = (string) ($job->merchant_po ?? '');

            if ($order_id <= 0 || $job_key === '' || $dist_id === '') {
                continue;
            }
            if ($po === '') {
                $stats['skipped_no_po']++;
                continue;
            }

            if (OrderPlacementPipelineMetaStore::is_order_suspended($order_id)) {
                $stats['skipped_suspended']++;
                continue;
            }

            if (!Options::is_distributor_enabled($dist_id)) {
                $stats['skipped_disabled']++;
                continue;
            }

            $dist = $this->handler->get_distributor_by_id($dist_id);
            if (!($dist instanceof DistributorBase) || !method_exists($dist, 'get_shipment_by_po')) {
                continue;
            }

            // We are actually polling now.
            $stats['polled']++;

            // Touch pacing timestamp (best-effort, no spam log)
            try {
                ShippingJobStore::touch_last_shipping_poll_at($this->jobs_table, $order_id, $job_key);
            } catch (\Throwable $e) {
                // Keep this quiet; it’s pacing-only.
            }

            // Shipment lookup
            try {
                $shipment = $dist->get_shipment_by_po($po);
            } catch (\Throwable $e) {
                $stats['lookup_exception']++;
                $this->log_ctx('shipment_lookup_exception', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'po'       => $po,
                    'err'      => $e->getMessage(),
                ]);
                continue;
            }

            if (!$shipment) {
                continue;
            }

            $stats['shipment_found']++;

            // Persist shipment
            try {
                $result = ShippingJobStore::mark_job_shipped($this->jobs_table, $order_id, $job_key, $shipment);
            } catch (\Throwable $e) {
                $stats['persist_exception']++;
                $this->log_ctx('persist_exception', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'po'       => $po,
                    'err'      => $e->getMessage(),
                ]);
                continue;
            }

            // Only do email work when new tracking was actually added
            if (method_exists($result, 'has_changes') && $result->has_changes()) {
                $stats['tracking_added']++;

                $lines = [];
                try {
                    $lines = OrderPlacementJobsRepository::get_job_payload_lines($this->jobs_table, $order_id, $job_key);
                } catch (\Throwable $e) {
                    // non-fatal, no spam
                }

                $ctx = new PartialShipmentEmailContext($job, $result, $shipment, $lines);

                try {
                    if (function_exists('WC') && WC()) {
                        WC()->mailer()->get_emails();
                    }
                } catch (\Throwable $e) {
                    // non-fatal, no spam
                }

                do_action('fflhub_trigger_partial_shipment_email', $order_id, $ctx);
                $stats['email_fired']++;
            }

            // Complete order if all shipped
            try {
                if (OrderPlacementJobsRepository::are_all_success_jobs_shipped($this->jobs_table, $order_id)) {
                    $order = wc_get_order($order_id);
                    if ($order instanceof WC_Order && $order->has_status(['processing', 'on-hold'])) {
                        $order->update_status('completed', 'FFL Hub: all distributor jobs have tracking numbers.');
                        $stats['order_completed']++;

                        // This is a meaningful transition worth logging.
                        $this->log_ctx('order_completed', [
                            'order_id' => $order_id,
                            'to'       => 'completed',
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                // non-fatal
            }
        }
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
