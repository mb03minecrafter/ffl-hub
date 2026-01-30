<?php

namespace FFLHub\Distributor\Services\Orders\Shipping\Cron;

use WC_Order;

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Core\DistributorHandler;

use FFLHub\Distributor\Services\Cron\AbstractCronService;

use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Models\PartialShipmentEmailContext;
use FFLHub\Distributor\Models\DistributorOrderLine;

use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementPipelineMetaStore;

use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Shipping\ShippingJobStore;
use FFLHub\Settings\Options;

use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shipping poll scheduler + worker.
 *
 * Selects eligible placement jobs and polls distributor APIs
 * to determine if shipments have been created yet.
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
     */
    private const JOB_MIN_POLL_INTERVAL_MINUTES = 0;

    /**
     * How many jobs to process per run.
     */
    private const BATCH_LIMIT = 50;



    private DistributorHandler $handler;

    public function __construct(DistributorHandler $handler)
    {
        $this->handler = $handler;
    }

    /**
     * Unique cron hook name.
     */
    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    /**
     * Action Scheduler group.
     */
    public function get_action_group(): string
    {
        return 'fflhub_shipping';
    }

    /**
     * How often the poller runs.
     */
    protected function get_interval_seconds(): int
    {
        return 15 * MINUTE_IN_SECONDS;
    }

    /**
     * Initial delay before first run.
     */
    protected function get_initial_delay_seconds(): int
    {
        return 60; // 1 minute
    }

    /**
     * Worker entrypoint.
     */
    public function run(): void
    {
        $run_started = microtime(true);

        // Poll spacing per row
        $min_interval_seconds = (int) (self::JOB_MIN_POLL_INTERVAL_MINUTES * 60);
        $cutoff_unix          = time() - max(0, $min_interval_seconds);
        $cutoff_mysql_utc     = OrderPlacementTimeUtil::unix_to_mysql_utc($cutoff_unix);

        $limit = max(1, (int) self::BATCH_LIMIT);

        // Optional: stop polling after X days since first shipment detected
        $max_days_after_first_ship = 14;
        $ship_cutoff_unix      = time() - ((int) $max_days_after_first_ship * DAY_IN_SECONDS);
        $ship_cutoff_mysql_utc = OrderPlacementTimeUtil::unix_to_mysql_utc($ship_cutoff_unix);

        $this->log_ctx('run_start', [
            'hook'                  => self::CRON_HOOK,
            'group'                 => $this->get_action_group(),
            'interval_seconds'      => $this->get_interval_seconds(),
            'initial_delay_seconds' => $this->get_initial_delay_seconds(),
            'min_poll_interval_min' => self::JOB_MIN_POLL_INTERVAL_MINUTES,
            'poll_cutoff_mysql_utc' => $cutoff_mysql_utc,
            'ship_cutoff_mysql_utc' => $ship_cutoff_mysql_utc,
            'batch_limit'           => $limit,
            'status_filter'         => OrderPlacementKeys::JOB_STATUS_SUCCESS,
        ]);

        $jobs = [];
        $repo_started = microtime(true);
        try {
            $jobs = OrderPlacementJobsRepository::find_jobs_for_shipping_poll(
                OrderPlacementKeys::JOB_STATUS_SUCCESS,
                $cutoff_mysql_utc,
                $ship_cutoff_mysql_utc,
                $limit
            );
            $this->log_ctx('repo_done', [
                'op'      => 'find_jobs_for_shipping_poll',
                'rows'    => is_array($jobs) ? count($jobs) : 0,
                'repo_ms' => (int) round((microtime(true) - $repo_started) * 1000),
            ]);
        } catch (\Throwable $e) {
            $this->log_ctx('repo_exception', [
                'op'      => 'find_jobs_for_shipping_poll',
                'err'     => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'repo_ms' => (int) round((microtime(true) - $repo_started) * 1000),
            ]);
            return;
        }

        if (empty($jobs)) {
            $this->log('no jobs eligible for shipping poll');
            $this->log_ctx('run_finish', [
                'eligible_jobs' => 0,
                'elapsed_ms'    => (int) round((microtime(true) - $run_started) * 1000),
            ]);
            return;
        }

        $this->log_ctx('eligible_jobs', [
            'count'  => count($jobs),
            'cutoff' => $cutoff_mysql_utc,
            'limit'  => $limit,
        ]);

        $stats = [
            'total'                => 0,
            'skipped_invalid'       => 0,
            'skipped_suspended'     => 0,
            'skipped_no_handler'    => 0,
            'skipped_disabled_dist' => 0,
            'skipped_missing_dist'  => 0,
            'skipped_no_lookup'     => 0,
            'skipped_no_po'         => 0,
            'lookup_ok'             => 0,
            'lookup_null'           => 0,
            'lookup_exception'      => 0,
            'persist_ok'            => 0,
            'persist_exception'     => 0,
            'email_fired'           => 0,
            'order_completed'       => 0,
        ];

        foreach ($jobs as $job) {
            $job_started = microtime(true);
            $stats['total']++;

            $seg = [
                'parse_ms'         => 0,
                'suspended_ms'     => 0,
                'handler_ms'       => 0,
                'enabled_ms'       => 0,
                'dist_lookup_ms'   => 0,
                'touch_ms'         => 0,
                'shipment_ms'      => 0,
                'persist_ms'       => 0,
                'payload_lines_ms' => 0,
                'wc_mailer_ms'     => 0,
                'email_ms'         => 0,
                'all_shipped_ms'   => 0,
                'wc_order_ms'      => 0,
                'wc_complete_ms'   => 0,
            ];

            $order_id = (int) ($job->order_id ?? 0);
            $job_key  = OrderPlacementKeysUtil::normalize_job_key((string) ($job->job_key ?? ''));

            $dist_id  = (string) ($job->dist_id ?? '');
            $bucket   = (string) ($job->bucket ?? '');
            $po       = (string) ($job->merchant_po ?? '');
            $ext      = (string) ($job->external_order_id ?? '');

            $seg['parse_ms'] = (int) round((microtime(true) - $job_started) * 1000);

            if ($order_id <= 0 || $job_key === '' || $dist_id === '') {
                $stats['skipped_invalid']++;
                $this->log_ctx('skip_invalid_job_row', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'bucket'   => $bucket,
                    'po'       => $po,
                    'external' => $ext,
                    'segments' => $seg,
                ]);
                continue;
            }

            if ($po === '') {
                $stats['skipped_no_po']++;
                $this->log_ctx('skip_missing_po', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'bucket'   => $bucket,
                    'external' => $ext,
                    'segments' => $seg,
                ]);
                continue;
            }

            // Skip trashed/suspended orders (order-level gate)
            $suspended_started = microtime(true);
            $is_suspended = OrderPlacementPipelineMetaStore::is_order_suspended($order_id);
            $seg['suspended_ms'] = (int) round((microtime(true) - $suspended_started) * 1000);

            if ($is_suspended) {
                $stats['skipped_suspended']++;
                $this->log_ctx('skip_suspended_order', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'bucket'   => $bucket,
                    'po'       => $po,
                    'segments' => $seg,
                ]);
                continue;
            }

            // ---------------- Distributor lookup ----------------
            $handler_started = microtime(true);
            $seg['handler_ms'] = (int) round((microtime(true) - $handler_started) * 1000);

            if (!($this->handler instanceof DistributorHandler)) {
                $stats['skipped_no_handler']++;
                $this->log_ctx('skip_no_distributor_handler', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'segments' => $seg,
                ]);
                continue;
            }

            $enabled_started = microtime(true);
            $enabled = Options::is_distributor_enabled($dist_id);
            $seg['enabled_ms'] = (int) round((microtime(true) - $enabled_started) * 1000);

            if (!$enabled) {
                $stats['skipped_disabled_dist']++;
                $this->log_ctx('skip_distributor_disabled', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'segments' => $seg,
                ]);
                continue;
            }

            $dist_lookup_started = microtime(true);
            $dist = $this->handler->get_distributor_by_id($dist_id);
            $seg['dist_lookup_ms'] = (int) round((microtime(true) - $dist_lookup_started) * 1000);

            if (!($dist instanceof DistributorBase)) {
                $stats['skipped_missing_dist']++;
                $this->log_ctx('skip_distributor_not_found', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'segments' => $seg,
                ]);
                continue;
            }

            if (!method_exists($dist, 'get_shipment_by_po')) {
                $stats['skipped_no_lookup']++;
                $this->log_ctx('skip_no_shipment_lookup', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'po'       => $po,
                    'segments' => $seg,
                ]);
                continue;
            }

            // At this point, we are actually going to poll → touch timestamp here (not earlier)
            $touch_started = microtime(true);
            try {
                ShippingJobStore::touch_last_shipping_poll_at($order_id, $job_key);
            } catch (\Throwable $e) {
                // Non-fatal, but worth logging because it breaks pacing
                $this->log_ctx('touch_last_poll_failed', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'err'      => $e->getMessage(),
                ]);
            }
            $seg['touch_ms'] = (int) round((microtime(true) - $touch_started) * 1000);

            $this->log_ctx('poll_start', [
                'order_id' => $order_id,
                'job_key'  => $job_key,
                'dist_id'  => $dist_id,
                'bucket'   => $bucket,
                'po'       => $po,
                'external' => $ext,
            ]);

            // ---------------- Shipment lookup ----------------
            $shipment = null;
            $shipment_started = microtime(true);

            try {
                $shipment = $dist->get_shipment_by_po($po);
                $stats['lookup_ok']++;
            } catch (\Throwable $e) {
                $seg['shipment_ms'] = (int) round((microtime(true) - $shipment_started) * 1000);
                $stats['lookup_exception']++;
                $this->log_ctx('shipment_lookup_exception', [
                    'order_id'  => $order_id,
                    'job_key'   => $job_key,
                    'dist_id'   => $dist_id,
                    'po'        => $po,
                    'err'       => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'segments'  => $seg,
                    'elapsed_ms'=> (int) round((microtime(true) - $job_started) * 1000),
                ]);
                continue;
            }

            $seg['shipment_ms'] = (int) round((microtime(true) - $shipment_started) * 1000);

            // TEMP TEST BLOCK — REMOVE AFTER VERIFYING EMAIL FLOW
            $shipment = new DistributorShipment(
                ['TEST-TRACK-12345'],
                ['TEST-INVOICE-1'],
                'UPS Ground',
                '5 lb',
                ['debug' => true]
            );

            if (!$shipment) {
                $stats['lookup_null']++;
                $this->log_ctx('no_shipment_found', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'po'       => $po,
                    'segments' => $seg,
                ]);
                continue;
            }

            $this->log_ctx('shipment_found', [
                'order_id' => $order_id,
                'job_key'  => $job_key,
                'dist_id'  => $dist_id,
                'po'       => $po,
                'has_tracking_numbers' => (property_exists($shipment, 'tracking_numbers') && is_array($shipment->tracking_numbers))
                    ? (!empty($shipment->tracking_numbers) ? '1' : '0')
                    : 'unknown',
                'tracking_count' => (property_exists($shipment, 'tracking_numbers') && is_array($shipment->tracking_numbers))
                    ? count($shipment->tracking_numbers)
                    : null,
                'has_invoice_numbers' => (property_exists($shipment, 'invoice_numbers') && is_array($shipment->invoice_numbers))
                    ? (!empty($shipment->invoice_numbers) ? '1' : '0')
                    : 'unknown',
                'invoice_count' => (property_exists($shipment, 'invoice_numbers') && is_array($shipment->invoice_numbers))
                    ? count($shipment->invoice_numbers)
                    : null,
                'segments' => $seg,
            ]);

            // ---------------- Persist shipment ----------------
            $persist_started = microtime(true);
            try {
                $result = ShippingJobStore::mark_job_shipped($order_id, $job_key, $shipment);
                $stats['persist_ok']++;
            } catch (\Throwable $e) {
                $seg['persist_ms'] = (int) round((microtime(true) - $persist_started) * 1000);
                $stats['persist_exception']++;
                $this->log_ctx('persist_exception', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'po'       => $po,
                    'err'      => $e->getMessage(),
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine(),
                    'segments' => $seg,
                ]);
                continue;
            }
            $seg['persist_ms'] = (int) round((microtime(true) - $persist_started) * 1000);

            $this->log_ctx('persist_result', [
                'order_id'    => $order_id,
                'job_key'     => $job_key,
                'dist_id'     => $dist_id,
                'po'          => $po,
                'has_changes' => method_exists($result, 'has_changes') ? ($result->has_changes() ? '1' : '0') : 'unknown',
                'segments'    => $seg,
            ]);

            if (method_exists($result, 'has_changes') && $result->has_changes()) {
                /** @var DistributorOrderLine[] $lines */
                $lines = [];
                $payload_lines_started = microtime(true);
                try {
                    $lines = OrderPlacementJobsRepository::get_job_payload_lines($order_id, $job_key);
                } catch (\Throwable $e) {
                    $this->log_ctx('payload_lines_exception', [
                        'order_id' => $order_id,
                        'job_key'  => $job_key,
                        'err'      => $e->getMessage(),
                    ]);
                }
                $seg['payload_lines_ms'] = (int) round((microtime(true) - $payload_lines_started) * 1000);

                $ctx = new PartialShipmentEmailContext(
                    $job,
                    $result,
                    $shipment,
                    $lines
                );

                // ensure Woo email classes loaded
                $wc_mailer_started = microtime(true);
                try {
                    if (function_exists('WC') && WC()) {
                        WC()->mailer()->get_emails();
                    }
                } catch (\Throwable $e) {
                    $this->log_ctx('wc_mailer_exception', [
                        'order_id' => $order_id,
                        'job_key'  => $job_key,
                        'err'      => $e->getMessage(),
                    ]);
                }
                $seg['wc_mailer_ms'] = (int) round((microtime(true) - $wc_mailer_started) * 1000);

                $this->log_ctx('email_trigger', [
                    'hook'     => 'fflhub_trigger_partial_shipment_email',
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'lines'    => is_array($lines) ? count($lines) : 0,
                    'segments' => $seg,
                ]);

                $email_started = microtime(true);
                do_action('fflhub_trigger_partial_shipment_email', $order_id, $ctx);
                $seg['email_ms'] = (int) round((microtime(true) - $email_started) * 1000);

                $stats['email_fired']++;
            }

            // Complete order if all shipped
            $all_shipped = false;
            $all_shipped_started = microtime(true);
            try {
                $all_shipped = OrderPlacementJobsRepository::are_all_success_jobs_shipped($order_id);
            } catch (\Throwable $e) {
                $this->log_ctx('are_all_shipped_exception', [
                    'order_id' => $order_id,
                    'err'      => $e->getMessage(),
                ]);
            }
            $seg['all_shipped_ms'] = (int) round((microtime(true) - $all_shipped_started) * 1000);

            if ($all_shipped) {
                $wc_order_started = microtime(true);
                $order = wc_get_order($order_id);
                $seg['wc_order_ms'] = (int) round((microtime(true) - $wc_order_started) * 1000);

                if ($order instanceof WC_Order) {
                    $this->log_ctx('all_jobs_shipped', [
                        'order_id'        => $order_id,
                        'current_status'  => method_exists($order, 'get_status') ? (string) $order->get_status() : 'unknown',
                        'segments'        => $seg,
                    ]);

                    if ($order->has_status(['processing', 'on-hold'])) {
                        $complete_started = microtime(true);
                        $order->update_status('completed', 'FFL Hub: all distributor jobs have tracking numbers.');
                        $seg['wc_complete_ms'] = (int) round((microtime(true) - $complete_started) * 1000);

                        $stats['order_completed']++;

                        $this->log_ctx('order_completed', [
                            'order_id'     => $order_id,
                            'from_status'  => 'processing/on-hold',
                            'to_status'    => 'completed',
                            'segments'     => $seg,
                        ]);
                    } else {
                        $this->log_ctx('order_not_completed_due_to_status', [
                            'order_id' => $order_id,
                            'status'   => method_exists($order, 'get_status') ? (string) $order->get_status() : 'unknown',
                            'segments' => $seg,
                        ]);
                    }
                } else {
                    $this->log_ctx('wc_order_not_found', [
                        'order_id' => $order_id,
                        'segments' => $seg,
                    ]);
                }
            } else {
                $this->log_ctx('not_all_jobs_shipped', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'segments' => $seg,
                ]);
            }

            $primary_tracking = method_exists($shipment, 'tracking_number')
                ? (string) ($shipment->tracking_number() ?? '')
                : '';

            $tracking_count = (property_exists($shipment, 'tracking_numbers') && is_array($shipment->tracking_numbers))
                ? count($shipment->tracking_numbers)
                : 0;

            $this->log_ctx('poll_finish', [
                'order_id'       => $order_id,
                'job_key'        => $job_key,
                'dist_id'        => $dist_id,
                'po'             => $po,
                'primary_track'  => $primary_tracking,
                'tracking_count' => $tracking_count,
                'segments'       => $seg,
                'elapsed_ms'     => (int) round((microtime(true) - $job_started) * 1000),
            ]);
        }

        $this->log_ctx('run_finish', [
            'eligible_jobs' => count($jobs),
            'stats'         => $stats,
            'elapsed_ms'    => (int) round((microtime(true) - $run_started) * 1000),
        ]);
    }

    // --------------------------------------------------
    // Logging helpers (use DebugLogUtil)
    // --------------------------------------------------

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
