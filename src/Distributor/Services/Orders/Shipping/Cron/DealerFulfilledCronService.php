<?php

namespace FFLHub\Distributor\Services\Orders\Shipping\Cron;

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Models\ShippingUpdateResult;
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
 * DealerFulfilledCronService
 *
 * Polls distributors for shipment/tracking details for dealer-fulfilled jobs.
 * Mirrors ShippingCronService behavior, but sends an admin alert email instead
 * of firing customer partial-shipment email actions.
 */
final class DealerFulfilledCronService extends AbstractCronService
{
    private const LOG_PREFIX  = '[FFLHUB][DealerFulfilledPoller]';
    private const DEBUG_CONST = 'FFLHUB_DEBUG_SHIPPING';
    private const FORCE_NO_COOLDOWN_CONST  = 'FFLHUB_DEALER_SHIPPING_FORCE_NO_COOLDOWN';
    private const FORCE_NO_COOLDOWN_OPTION = 'fflhub_dealer_shipping_force_no_cooldown';

    public const CRON_HOOK = 'fflhub_place_dealer_fulfilled_poll';

    private const JOB_MIN_POLL_INTERVAL_MINUTES = 60;
    private const BATCH_LIMIT = 50;
    private const MAX_DAYS_AFTER_FIRST_SHIP = 14;

    private DistributorHandler $handler;
    private OrderPlacementJobsTable $jobs_table;

    public function __construct(DistributorHandler $handler, OrderPlacementJobsTable $jobs_table)
    {
        $this->handler = $handler;
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

        $force_no_cooldown = $this->should_force_no_cooldown();
        if ($force_no_cooldown) {
            // last_shipping_poll_at < cutoff, so a far-future cutoff effectively disables pace gating.
            $cutoff_mysql_utc = '9999-12-31 23:59:59';
        } else {
            $cutoff_unix      = time() - ((int) self::JOB_MIN_POLL_INTERVAL_MINUTES * 60);
            $cutoff_mysql_utc = OrderPlacementTimeUtil::unix_to_mysql_utc($cutoff_unix);
        }

        $ship_cutoff_unix      = time() - ((int) self::MAX_DAYS_AFTER_FIRST_SHIP * DAY_IN_SECONDS);
        $ship_cutoff_mysql_utc = OrderPlacementTimeUtil::unix_to_mysql_utc($ship_cutoff_unix);

        $limit = max(1, (int) self::BATCH_LIMIT);

        $stats = [
            'eligible'                => 0,
            'polled'                  => 0,
            'lookup_exception'        => 0,
            'persist_exception'       => 0,
            'shipment_found'          => 0,
            'tracking_added'          => 0,
            'email_fired'             => 0,
            'skipped_zanders'         => 0,
            'skipped_invalid'         => 0,
            'skipped_no_po'           => 0,
            'skipped_suspended'       => 0,
            'skipped_disabled'        => 0,
            'skipped_no_lookup'       => 0,
            'touch_failed'            => 0,
            'shipment_none'           => 0,
            'result_no_changes'       => 0,
        ];

        $this->log_ctx('run_start', [
            'pid'                  => function_exists('getmypid') ? (int) getmypid() : null,
            'memory_kb'            => (int) (memory_get_usage(true) / 1024),
            'cutoff_mysql_utc'     => $cutoff_mysql_utc,
            'ship_cutoff_mysql_utc'=> $ship_cutoff_mysql_utc,
            'limit'                => $limit,
            'job_min_poll_min'     => (int) self::JOB_MIN_POLL_INTERVAL_MINUTES,
            'max_days_after_ship'  => (int) self::MAX_DAYS_AFTER_FIRST_SHIP,
            'force_no_cooldown'    => $force_no_cooldown ? 1 : 0,
        ]);

        try {
            $t0 = microtime(true);
            $jobs = OrderPlacementJobsRepository::find_jobs_for_dealer_shipping_poll(
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
                'op'   => 'find_jobs_for_dealer_shipping_poll',
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
            $po       = (string) ($job->merchant_po ?? '');

            $this->log_ctx('job_candidate', [
                'order_id'              => $order_id,
                'job_key'               => $job_key,
                'dist_id'               => $dist_id,
                'lane'                  => $lane,
                'status'                => (string) ($job->status ?? ''),
                'merchant_po'           => $po,
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
                    'lane'     => $lane,
                ]);
                continue;
            }

            // Temporary policy: ignore Zanders in dealer-fulfilled shipment polling.
            if (strtolower(trim($dist_id)) === 'zanders') {
                $stats['skipped_zanders']++;
                $this->log_ctx('skip_zanders_for_dealer_poll', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'lane'     => $lane,
                    'po'       => $po,
                ]);
                continue;
            }

            if ($po === '') {
                $stats['skipped_no_po']++;
                $this->log_ctx('skip_no_po', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'lane'     => $lane,
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
                    'order_id'   => $order_id,
                    'job_key'    => $job_key,
                    'dist_id'    => $dist_id,
                    'dist_class' => is_object($dist) ? get_class($dist) : gettype($dist),
                    'has_method' => is_object($dist) ? (bool) method_exists($dist, 'get_shipment_by_po') : false,
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
                $shipment = $dist->get_shipment_by_po($po);
                $this->log_ctx('shipment_lookup_done', [
                    'order_id'    => $order_id,
                    'job_key'     => $job_key,
                    'dist_id'     => $dist_id,
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
                    'order_id'    => $order_id,
                    'job_key'     => $job_key,
                    'dist_id'     => $dist_id,
                    'po'          => $po,
                    'elapsed_ms'  => (int) round((microtime(true) - $t0) * 1000),
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

                if ($result instanceof ShippingUpdateResult && $shipment instanceof DistributorShipment) {
                    $email_sent = $this->send_admin_shipment_email(
                        $order_id,
                        $job_key,
                        $dist_id,
                        $lane,
                        $po,
                        $result,
                        $shipment
                    );

                    if ($email_sent) {
                        $stats['email_fired']++;
                    }
                }
            } else {
                $stats['result_no_changes']++;
                $this->log_ctx('no_changes', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                ]);
            }

        }

        $this->log_ctx('run_end', [
            'elapsed_ms' => (int) round((microtime(true) - $run_started) * 1000),
            'memory_kb'  => (int) (memory_get_usage(true) / 1024),
            'stats'      => $stats,
        ]);
    }

    private function should_force_no_cooldown(): bool
    {
        if (defined(self::FORCE_NO_COOLDOWN_CONST) && (bool) constant(self::FORCE_NO_COOLDOWN_CONST)) {
            return true;
        }

        $raw = get_option(self::FORCE_NO_COOLDOWN_OPTION, false);
        $enabled = false;

        if (is_bool($raw)) {
            $enabled = $raw;
        } elseif (is_numeric($raw)) {
            $enabled = ((int) $raw) === 1;
        } elseif (is_string($raw)) {
            $enabled = in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) apply_filters('fflhub_dealer_shipping_force_no_cooldown', $enabled);
    }

    private function log(string $msg): void
    {
        DebugLogUtil::log(self::DEBUG_CONST, self::LOG_PREFIX, $msg);
    }

    /**
     * Sends an admin alert email for dealer-fulfilled tracking updates.
     *
     * Recipients default to admin_email and are filterable via:
     * - fflhub_dealer_shipping_alert_recipients
     * - fflhub_dealer_shipping_alert_subject
     * - fflhub_dealer_shipping_alert_body
     */
    private function send_admin_shipment_email(
        int $order_id,
        string $job_key,
        string $dist_id,
        string $lane,
        string $po,
        ShippingUpdateResult $result,
        DistributorShipment $shipment
    ): bool {
        if (!function_exists('wp_mail')) {
            return false;
        }

        $recipient_candidates = [];
        $admin_email = trim((string) get_option('admin_email', ''));
        if ($admin_email !== '') {
            $recipient_candidates[] = $admin_email;
        }

        /** @var mixed $filtered */
        $filtered = apply_filters(
            'fflhub_dealer_shipping_alert_recipients',
            $recipient_candidates,
            $order_id,
            $job_key,
            $dist_id,
            $lane,
            $po
        );

        $recipient_candidates = [];
        if (is_string($filtered)) {
            $recipient_candidates = preg_split('/[,;\\s]+/', $filtered) ?: [];
        } elseif (is_array($filtered)) {
            $recipient_candidates = $filtered;
        }

        $recipients = [];
        foreach ($recipient_candidates as $r) {
            $email = sanitize_email((string) $r);
            if ($email !== '' && is_email($email)) {
                $recipients[$email] = true;
            }
        }

        if (empty($recipients)) {
            return false;
        }

        $edit_url = function_exists('admin_url')
            ? admin_url('post.php?post=' . $order_id . '&action=edit')
            : '';

        $subject = sprintf(
            '[FFL Hub] Dealer shipment update - Order #%d - %s',
            $order_id,
            $dist_id !== '' ? $dist_id : '-'
        );

        $added_tracking = !empty($result->added_tracking) ? implode(', ', $result->added_tracking) : '-';
        $all_tracking   = !empty($result->all_tracking) ? implode(', ', $result->all_tracking) : '-';
        $all_invoices   = !empty($result->all_invoices) ? implode(', ', $result->all_invoices) : '-';
        $shipping_service = trim((string) ($shipment->shipping_service ?? ''));
        $shipping_weight  = trim((string) ($shipment->shipping_weight ?? ''));
        $added_tracking_links = $this->build_tracking_link_lines((array) $result->added_tracking, $shipping_service);
        $all_tracking_links   = $this->build_tracking_link_lines((array) $result->all_tracking, $shipping_service);

        $body_lines = [
            'Dealer-fulfilled shipment update detected.',
            '',
            'Order ID: ' . $order_id,
            'Job Key: ' . $job_key,
            'Distributor: ' . ($dist_id !== '' ? $dist_id : '-'),
            'Lane: ' . ($lane !== '' ? $lane : '-'),
            'Merchant PO: ' . ($po !== '' ? $po : '-'),
            'New Tracking: ' . $added_tracking,
            'All Tracking: ' . $all_tracking,
            'Invoices: ' . $all_invoices,
            'Service: ' . ($shipping_service !== '' ? $shipping_service : '-'),
            'Weight: ' . ($shipping_weight !== '' ? $shipping_weight : '-'),
        ];

        if (!empty($added_tracking_links)) {
            $body_lines[] = 'New Tracking Links:';
            foreach ($added_tracking_links as $line) {
                $body_lines[] = '- ' . $line;
            }
        }

        if (!empty($all_tracking_links)) {
            $body_lines[] = 'All Tracking Links:';
            foreach ($all_tracking_links as $line) {
                $body_lines[] = '- ' . $line;
            }
        }

        if ($edit_url !== '') {
            $body_lines[] = 'Edit Order: ' . $edit_url;
        }

        $body = implode("\n", $body_lines);

        /** @var mixed $subject_filtered */
        $subject_filtered = apply_filters(
            'fflhub_dealer_shipping_alert_subject',
            $subject,
            $order_id,
            $job_key,
            $dist_id,
            $lane,
            $po,
            $result
        );
        if (is_string($subject_filtered) && trim($subject_filtered) !== '') {
            $subject = trim($subject_filtered);
        }

        /** @var mixed $body_filtered */
        $body_filtered = apply_filters(
            'fflhub_dealer_shipping_alert_body',
            $body,
            $order_id,
            $job_key,
            $dist_id,
            $lane,
            $po,
            $result,
            $shipment
        );
        if (is_string($body_filtered) && trim($body_filtered) !== '') {
            $body = $body_filtered;
        }

        $sent = wp_mail(array_keys($recipients), $subject, $body);
        $this->log_ctx('admin_email_fire', [
            'order_id'  => $order_id,
            'job_key'   => $job_key,
            'dist_id'   => $dist_id,
            'lane'      => $lane,
            'po'        => $po,
            'recipients'=> array_keys($recipients),
            'sent'      => (bool) $sent,
        ]);

        return (bool) $sent;
    }

    /** @param array<string,mixed> $ctx */
    private function log_ctx(string $msg, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $msg, $ctx);
    }

    /**
     * @param string[] $tracking_numbers
     * @return string[]
     */
    private function build_tracking_link_lines(array $tracking_numbers, string $shipping_service): array
    {
        $lines = [];
        $carrier_hint = self::normalize_carrier_hint($shipping_service);

        foreach ($tracking_numbers as $tracking_raw) {
            $tracking = trim((string) $tracking_raw);
            if ($tracking === '') {
                continue;
            }

            $carrier = $carrier_hint ?: self::infer_carrier_from_tracking($tracking);
            $url = self::build_tracking_url($carrier, $tracking);
            if ($url === '') {
                continue;
            }

            $label = ($carrier !== null && $carrier !== '') ? $carrier : 'TRACK';
            $lines[] = $label . ' ' . $tracking . ' -> ' . $url;
        }

        return $lines;
    }

    private static function normalize_carrier_hint(string $shipping_service): ?string
    {
        $v = strtoupper(trim($shipping_service));
        if ($v === '') {
            return null;
        }

        if (strpos($v, 'USPS') !== false || strpos($v, 'POSTAL') !== false) {
            return 'USPS';
        }

        if (strpos($v, 'UPS') !== false) {
            return 'UPS';
        }

        if (strpos($v, 'FEDEX') !== false || strpos($v, 'FED EX') !== false || strpos($v, 'FDX') !== false) {
            return 'FEDEX';
        }

        return null;
    }

    private static function infer_carrier_from_tracking(string $tracking): ?string
    {
        $t = strtoupper(preg_replace('/[^A-Z0-9]/', '', $tracking) ?? '');
        if ($t === '') {
            return null;
        }

        if (strpos($t, '1Z') === 0) {
            return 'UPS';
        }

        if (preg_match('/^9\d{15,29}$/', $t)) {
            return 'USPS';
        }

        if (preg_match('/^\d{12}$|^\d{15}$|^\d{20}$|^\d{22}$/', $t)) {
            return 'FEDEX';
        }

        return null;
    }

    private static function build_tracking_url(?string $carrier, string $tracking): string
    {
        $t = trim((string) $tracking);
        if ($t === '') {
            return '';
        }

        $carrier = strtoupper(trim((string) $carrier));
        if ($carrier === 'UPS') {
            return 'https://www.ups.com/track?tracknum=' . rawurlencode($t);
        }

        if ($carrier === 'USPS') {
            return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . rawurlencode($t);
        }

        if ($carrier === 'FEDEX') {
            return 'https://www.fedex.com/fedextrack/?trknbr=' . rawurlencode($t);
        }

        return '';
    }
}
