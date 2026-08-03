<?php

namespace FFLHub\Distributor\Services\Orders\Shipping\Cron;

use WC_Order;

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Models\PartialShipmentEmailContext;
use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Distributor\Services\Orders\Cron\DealerBatchCronRegistry;
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
 * Polls distributors for shipment/tracking details for jobs that have successfully placed an order
 * (status = success) but are not yet marked shipped.
 */
final class ShippingCronService extends AbstractCronService
{
    private const LOG_PREFIX  = '[FFLHUB][ShippingPoller]';
    private const DEBUG_CONST = 'FFLHUB_DEBUG_SHIPPING';

    public const CRON_HOOK = 'fflhub_place_shipping_poll';

    private const JOB_MIN_POLL_INTERVAL_MINUTES = 60;
    private const BATCH_LIMIT = 50;
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

    public function run(): void
    {
        $run_started = microtime(true);

        $cutoff_unix      = time() - ((int) self::JOB_MIN_POLL_INTERVAL_MINUTES * 60);
        $cutoff_mysql_utc = OrderPlacementTimeUtil::unix_to_mysql_utc($cutoff_unix);

        $ship_cutoff_unix      = time() - ((int) self::MAX_DAYS_AFTER_FIRST_SHIP * DAY_IN_SECONDS);
        $ship_cutoff_mysql_utc = OrderPlacementTimeUtil::unix_to_mysql_utc($ship_cutoff_unix);

        $limit = max(1, (int) self::BATCH_LIMIT);

        $stats = [
            'eligible'           => 0,
            'polled'             => 0,
            'lookup_exception'   => 0,
            'persist_exception'  => 0,
            'shipment_found'     => 0,
            'tracking_added'     => 0,
            'email_fired'        => 0,
            'order_completed'    => 0,
            'skipped_invalid'    => 0,
            'skipped_no_po'      => 0,
            'skipped_suspended'  => 0,
            'skipped_disabled'   => 0,
            'skipped_no_lookup'  => 0,
            'skipped_unsupported_lane' => 0,
            'skipped_ca_relay'   => 0,
            'touch_failed'       => 0,
            'shipment_none'      => 0,
            'result_no_changes'  => 0,
            'complete_skipped_status' => 0,
        ];

        $this->log_ctx('run_start', [
            'pid'                => function_exists('getmypid') ? (int) getmypid() : null,
            'memory_kb'           => (int) (memory_get_usage(true) / 1024),
            'cutoff_mysql_utc'    => $cutoff_mysql_utc,
            'ship_cutoff_mysql_utc' => $ship_cutoff_mysql_utc,
            'limit'              => $limit,
            'job_min_poll_min'   => (int) self::JOB_MIN_POLL_INTERVAL_MINUTES,
            'max_days_after_ship'=> (int) self::MAX_DAYS_AFTER_FIRST_SHIP,
        ]);

        try {
            $t0 = microtime(true);
            $jobs = OrderPlacementJobsRepository::find_jobs_for_shipping_poll(
                $this->jobs_table,
                OrderPlacementKeys::JOB_STATUS_SUCCESS,
                $cutoff_mysql_utc,
                $ship_cutoff_mysql_utc,
                $limit
            );
            $this->log_ctx('repo_results', [
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                'count'      => is_array($jobs) ? count($jobs) : 0,
            ]);
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
            $this->log_ctx('run_end', [
                'reason'      => 'no_jobs',
                'elapsed_ms'  => (int) round((microtime(true) - $run_started) * 1000),
                'memory_kb'   => (int) (memory_get_usage(true) / 1024),
            ]);
            return;
        }

        $stats['eligible'] = count($jobs);

        foreach ($jobs as $job) {
            $order_id = (int) ($job->order_id ?? 0);
            $job_key  = OrderPlacementKeysUtil::normalize_job_key((string) ($job->job_key ?? ''));
            $dist_id  = (string) ($job->dist_id ?? '');
            $lane     = (string) ($job->lane ?? '');
            $po       = (string) ($job->merchant_po ?? '');

            $this->log_ctx('job_candidate', [
                'order_id' => $order_id,
                'job_key'  => $job_key,
                'dist_id'  => $dist_id,
                'lane'     => $lane,
                'po'       => $po,
                'last_shipping_poll_at' => $job->last_shipping_poll_at ?? null,
                'shipped_at'            => $job->shipped_at ?? null,
                'tracking_numbers_json' => $job->tracking_numbers_json ?? null,
            ]);

            if ($order_id <= 0 || $job_key === '' || $dist_id === '') {
                $stats['skipped_invalid']++;
                $this->log_ctx('skip_invalid_row', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                ]);
                continue;
            }

            if ($po === '') {
                $stats['skipped_no_po']++;
                $this->log_ctx('skip_no_po', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                ]);
                continue;
            }

            if (DealerBatchCronRegistry::is_ca_relay_batch_job($job)) {
                $stats['skipped_ca_relay']++;
                $this->log_ctx('skip_ca_relay_inbound_shipment', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'po'       => $po,
                    'reason'   => 'CA relay distributor tracking is inbound to dealer/relay address, not customer delivery.',
                ]);
                continue;
            }

            if (OrderPlacementPipelineMetaStore::is_order_suspended($order_id)) {
                $stats['skipped_suspended']++;
                $this->log_ctx('skip_suspended', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                ]);
                continue;
            }

            if (!Options::is_distributor_enabled($dist_id)) {
                $stats['skipped_disabled']++;
                $this->log_ctx('skip_distributor_disabled', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                ]);
                continue;
            }

            $dist = $this->handler->get_distributor_by_id($dist_id);
            if (!($dist instanceof DistributorBase) || !method_exists($dist, 'get_shipment_by_po')) {
                $stats['skipped_no_lookup']++;
                $this->log_ctx('skip_no_lookup_support', [
                    'order_id'     => $order_id,
                    'job_key'      => $job_key,
                    'dist_id'      => $dist_id,
                    'dist_class'   => is_object($dist) ? get_class($dist) : gettype($dist),
                    'has_method'   => is_object($dist) ? (bool) method_exists($dist, 'get_shipment_by_po') : false,
                ]);
                continue;
            }

            if (!$dist->supports_shipment_polling_for_lane($lane)) {
                $stats['skipped_unsupported_lane']++;
                $this->log_ctx('skip_unsupported_shipment_lane', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'lane'     => $lane,
                    'po'       => $po,
                ]);
                continue;
            }

            $stats['polled']++;

            // Touch pacing timestamp
            try {
                ShippingJobStore::touch_last_shipping_poll_at($this->jobs_table, $order_id, $job_key);
                $this->log_ctx('touch_ok', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                ]);
            } catch (\Throwable $e) {
                $stats['touch_failed']++;
                $this->log_ctx('touch_failed', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'err'      => $e->getMessage(),
                ]);
            }

            // Shipment lookup
            $shipment = null;
            try {
                $t0 = microtime(true);
                $shipment = $dist->get_shipment_by_po_for_lane($po, $lane);
                $this->log_ctx('shipment_lookup_done', [
                    'order_id'    => $order_id,
                    'job_key'     => $job_key,
                    'dist_id'     => $dist_id,
                    'lane'        => $lane,
                    'po'          => $po,
                    'elapsed_ms'  => (int) round((microtime(true) - $t0) * 1000),
                    'found'       => (bool) $shipment,
                ]);
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

            if (!($shipment instanceof DistributorShipment)) {
                $stats['shipment_none']++;
                $this->log_ctx('shipment_none', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'po'       => $po,
                ]);
                continue;
            }

            $stats['shipment_found']++;

            // Persist shipment
            try {
                $t0 = microtime(true);
                $result = ShippingJobStore::mark_job_shipped($this->jobs_table, $order_id, $job_key, $shipment);
                $this->log_ctx('persist_done', [
                    'order_id'   => $order_id,
                    'job_key'    => $job_key,
                    'dist_id'    => $dist_id,
                    'po'         => $po,
                    'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                    'has_changes' => (bool) (is_object($result) && method_exists($result, 'has_changes') ? $result->has_changes() : null),
                ]);
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

            $did_change = (is_object($result) && method_exists($result, 'has_changes')) ? (bool) $result->has_changes() : false;

            if ($did_change) {
                $stats['tracking_added']++;

                $lines = [];
                try {
                    $t0 = microtime(true);
                    $lines = OrderPlacementJobsRepository::get_job_payload_lines($this->jobs_table, $order_id, $job_key);
                    $this->log_ctx('payload_lines_loaded', [
                        'order_id'   => $order_id,
                        'job_key'    => $job_key,
                        'count'      => is_array($lines) ? count($lines) : 0,
                        'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                    ]);
                } catch (\Throwable $e) {
                    $this->log_ctx('payload_lines_exception', [
                        'order_id' => $order_id,
                        'job_key'  => $job_key,
                        'err'      => $e->getMessage(),
                    ]);
                }

                $ctx = new PartialShipmentEmailContext($job, $result, $shipment, $lines);

                try {
                    if (function_exists('WC') && WC()) {
                        WC()->mailer()->get_emails();
                    }
                } catch (\Throwable $e) {
                    $this->log_ctx('mailer_init_exception', [
                        'order_id' => $order_id,
                        'err'      => $e->getMessage(),
                    ]);
                }

                $this->log_ctx('email_fire', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                ]);

                do_action('fflhub_trigger_partial_shipment_email', $order_id, $ctx);
                $stats['email_fired']++;
            } else {
                $stats['result_no_changes']++;
                $this->log_ctx('no_changes', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                ]);
            }

            // Complete order if all shipped
            try {
                $all_shipped = OrderPlacementJobsRepository::are_all_order_jobs_shipped($this->jobs_table, $order_id);
                $this->log_ctx('complete_check', [
                    'order_id'    => $order_id,
                    'all_shipped' => (bool) $all_shipped,
                ]);

                if ($all_shipped) {
                    $order = wc_get_order($order_id);
                    if ($order instanceof WC_Order) {
                        $status = (string) $order->get_status();

                        if ($order->has_status(['processing', 'on-hold'])) {
                            $order->update_status('completed', 'FFL Hub: all distributor jobs have tracking numbers.');
                            $stats['order_completed']++;

                            $this->log_ctx('order_completed', [
                                'order_id' => $order_id,
                                'to'       => 'completed',
                                'from'     => $status,
                            ]);
                        } else {
                            $stats['complete_skipped_status']++;
                            $this->log_ctx('complete_skip_status', [
                                'order_id' => $order_id,
                                'status'   => $status,
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->log_ctx('complete_exception', [
                    'order_id' => $order_id,
                    'err'      => $e->getMessage(),
                ]);
            }
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
