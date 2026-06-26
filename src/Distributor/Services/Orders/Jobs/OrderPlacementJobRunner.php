<?php

namespace FFLHub\Distributor\Services\Orders\Jobs;

use WC_Order;

use FFLHub\Settings\Options;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Core\DistributorBase;

use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorShipTo;

use FFLHub\Checkout\Builders\CheckoutOrderRequestBuilder;
use FFLHub\Distributor\Models\OrderPlacementJobRow;

use FFLHub\Distributor\Services\Orders\Jobs\Identifiers\OrderPlacementJobIdentifiersStore;
use FFLHub\Distributor\Services\Orders\Jobs\Lifecycle\OrderPlacementJobLifeCycle;
use FFLHub\Distributor\Services\Orders\Jobs\Snapshots\OrderPlacementJobSnapshotsStore;
use FFLHub\Distributor\Services\Orders\Cron\DealerBatchCronRegistry;
use FFLHub\Distributor\Services\Orders\Util\DealerShipToResolver;

use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementPOUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementSnapshotUtil;

use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\FFL\Tables\FFLTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobRunner
 *
 * Executes exactly one Order Placement job (one row in the jobs table).
 *
 * Logging/profiling intentionally removed.
 */
final class OrderPlacementJobRunner
{
    /**
     * Execute a single job for a given order + job_key.
     *
     * @param OrderPlacementJobsTable $jobs_table Jobs table helper.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key    Job key (dist|lane). Runner normalizes.
     * @param DistributorHandler      $handler    Distributor registry/handler.
     */
    public static function run(
        OrderPlacementJobsTable $jobs_table,
        FFLTable $ffl_table,
        WC_Order $order,
        string $job_key,
        DistributorHandler $handler
    ): void {
        $order_id = (int) $order->get_id();
        $job_key  = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);

        if ($order_id <= 0 || $job_key === '') {
            return;
        }

        // Fast exit on automation-terminal statuses.
        $existing_status = (string) OrderPlacementJobLifeCycle::get_job_status($jobs_table, $order, $job_key);
        if (
            $existing_status === OrderPlacementKeys::JOB_STATUS_SUCCESS
            || $existing_status === OrderPlacementKeys::JOB_STATUS_MANUAL
            || $existing_status === OrderPlacementKeys::JOB_STATUS_AWAITING_ACK
        ) {
            return;
        }

        // Claim job (attempts++, status => running). Concurrency gate.
        try {
            $attempt_n = (int) OrderPlacementJobLifeCycle::increment_job_attempts_and_mark_running($jobs_table, $order, $job_key);
        } catch (\Throwable $e) {
            // If we can't claim safely, treat as failed (best-effort).
            try {
                OrderPlacementJobLifeCycle::mark_job_failed($jobs_table, $order, $job_key, 'Failed to claim job: ' . $e->getMessage());
            } catch (\Throwable $ignored) {
                // swallow
            }
            return;
        }

        if ($attempt_n <= 0) {
            // Not claimed by this runner.
            return;
        }

        // Load job row DTO.
        try {
            $job = OrderPlacementJobsRepository::get_job_for_order($jobs_table, $order, $job_key);
        } catch (\Throwable $e) {
            $job = null;
        }

        if (!($job instanceof OrderPlacementJobRow)) {
            try {
                OrderPlacementJobLifeCycle::mark_job_failed($jobs_table, $order, $job_key, 'Job row not found for order/job_key');
            } catch (\Throwable $ignored) {
                // swallow
            }
            return;
        }

        // Canonical key from row.
        $job_key = $job->job_key_norm();

        $dist_id = (string) $job->dist_id_norm();
        $lane    = (string) $job->lane_norm();

        $sm = new OrderPlacementJobStateMachine();
        $place_step_started = false;

        try {
            // Validate job row basics.
            $lane_ok = OrderPlacementKeysUtil::is_valid_lane($lane);
            if ($dist_id === '' || !$lane_ok) {
                throw new \RuntimeException('Invalid job: missing dist_id or invalid lane');
            }

            $ffl_required = (bool) $job->ffl_required();

            $lines = $job->payload_lines();
            if (empty($lines) || !is_array($lines)) {
                throw new \RuntimeException('Invalid payload: no valid order lines');
            }

            // Resolve customer ship-to (shipping fallback billing).
            $ship_customer = DistributorShipTo::from_order_shipping_fallback_billing($order);
            if (!($ship_customer instanceof DistributorShipTo)) {
                throw new \RuntimeException('Customer ship-to incomplete on order');
            }
            $original_customer_name = trim((string) $ship_customer->name);

            // Resolve FFL ship-to (if needed).
            [$ship_ffl, $receiving_ffl_number] = self::resolve_ship_to_ffl_if_needed($ffl_table, $order, $ffl_required);

            $is_ca_relay = DealerBatchCronRegistry::is_ca_relay_batch_job($job);
            if ($is_ca_relay) {
                if ($ffl_required) {
                    throw new \RuntimeException('CA relay job contains FFL-required lines; relay path is non-FFL only');
                }

                $relay_ship_to = DealerShipToResolver::resolve_relay();
                if (!($relay_ship_to instanceof DistributorShipTo)) {
                    throw new \RuntimeException('CA relay job missing configured dealer ship-to address');
                }

                $ship_customer = $relay_ship_to;
                $ship_ffl = null;
                $receiving_ffl_number = '';
            }

            $dest_state = $ffl_required && ($ship_ffl instanceof DistributorShipTo)
                ? (string) $ship_ffl->state
                : (string) $ship_customer->state;

            // Merchant PO build + persist (best-effort, first writer wins).
            $merchant_order_id = OrderPlacementPOUtil::build_merchant_po($job, 1);
            if ($merchant_order_id !== '') {
                OrderPlacementJobIdentifiersStore::set_job_merchant_po(
                    $jobs_table,
                    $order,
                    $job_key,
                    $merchant_order_id,
                    false
                );
            }

            // Build DistributorOrderRequest.
            $customer_name = trim((string) $ship_customer->name);
            if ($customer_name === '') {
                $customer_name = 'Unknown';
            }
            $job_note = 'Woo Order ID: ' . $order_id . ' - Customer: ' . $customer_name;
            if ($is_ca_relay) {
                $job_note = 'Woo Order ID: ' . $order_id . ' - CA relay inbound for Customer: ' . ($original_customer_name !== '' ? $original_customer_name : 'Unknown');
            }
            $req = new DistributorOrderRequest(
                $lines,
                $ship_customer,
                $ship_ffl,
                $merchant_order_id,
                $dest_state,
                $receiving_ffl_number,
                $job_note,
                $lane
            );

            // Distributor resolve.
            if (!Options::is_distributor_enabled($dist_id)) {
                throw new \RuntimeException('Distributor is disabled: ' . $dist_id);
            }

            $dist = $handler->get_distributor_by_id($dist_id);
            if (!($dist instanceof DistributorBase)) {
                throw new \RuntimeException('Distributor not found: ' . $dist_id);
            }

            // Validate + persist snapshot.
            $vr = self::validate_and_persist_result($jobs_table, $order, $job, $dist, $req, $attempt_n);

            // State machine after validation.
            $dec = $sm->apply_validation_result($jobs_table, $order, $job_key, $vr, $attempt_n);
            if (($dec['action'] ?? '') === 'exit') {
                return;
            }

            // Place + persist snapshot.
            $place_step_started = true;
            $or = self::place_and_persist_result($jobs_table, $order, $job, $dist, $req, $attempt_n);

            // State machine after place.
            $dec2 = $sm->apply_place_order_result($jobs_table, $order, $job_key, $or, $attempt_n);
            if (($dec2['action'] ?? '') === 'exit') {
                if (($dec2['reason'] ?? '') === 'failed') {
                    self::send_place_failure_email($jobs_table, $order, $job_key, $dist_id, $lane, $attempt_n, null);
                }
                return;
            }

            // Mark success (terminal transition).
            OrderPlacementJobLifeCycle::mark_job_success($jobs_table, $order, $job_key);
        } catch (\Throwable $e) {
            // Terminal failure.
            try {
                OrderPlacementJobLifeCycle::mark_job_failed($jobs_table, $order, $job_key, $e->getMessage());
            } catch (\Throwable $ignored) {
                // swallow
            }
            if ($place_step_started) {
                self::send_place_failure_email($jobs_table, $order, $job_key, $dist_id, $lane, $attempt_n ?? 0, $e->getMessage());
            }
        }
    }

    /**
     * Validate a DistributorOrderRequest and persist a machine-readable snapshot.
     *
     * @throws \RuntimeException on exception or invalid return type
     */
    private static function validate_and_persist_result(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        OrderPlacementJobRow $job,
        DistributorBase $dist,
        DistributorOrderRequest $req,
        int $attempt_n = 0
    ): DistributorOrderValidationResult {
        $job_key = $job->job_key_norm();

        try {
            $vr = $dist->validate_order_request($req, true);
        } catch (\Throwable $e) {
            $snap = OrderPlacementSnapshotUtil::invalid_validate_return_snapshot($job->ctx($attempt_n));
            OrderPlacementJobSnapshotsStore::set_job_validation_result($jobs_table, $order, $job_key, $snap);
            throw new \RuntimeException('validate_order_request threw: ' . $e->getMessage());
        }

        if (!($vr instanceof DistributorOrderValidationResult)) {
            $snap = OrderPlacementSnapshotUtil::invalid_validate_return_snapshot($job->ctx($attempt_n));
            OrderPlacementJobSnapshotsStore::set_job_validation_result($jobs_table, $order, $job_key, $snap);
            throw new \RuntimeException((string) ($snap['message'] ?? 'Invalid validate return'));
        }

        OrderPlacementJobSnapshotsStore::set_job_validation_result(
            $jobs_table,
            $order,
            $job_key,
            OrderPlacementSnapshotUtil::validation_snapshot($vr, $job->ctx($attempt_n))
        );

        return $vr;
    }

    /**
     * Place an order with the distributor and persist a machine-readable snapshot.
     *
     * @throws \RuntimeException on exception or invalid return type
     */
    private static function place_and_persist_result(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        OrderPlacementJobRow $job,
        DistributorBase $dist,
        DistributorOrderRequest $req,
        int $attempt_n = 0
    ): DistributorOrderResult {
        $job_key = $job->job_key_norm();

        try {
            $or = $dist->place_order($req);
        } catch (\Throwable $e) {
            $snap = OrderPlacementSnapshotUtil::invalid_place_return_snapshot($job->ctx($attempt_n));
            OrderPlacementJobSnapshotsStore::set_job_place_result($jobs_table, $order, $job_key, $snap);
            throw new \RuntimeException('place_order threw: ' . $e->getMessage());
        }

        if (!($or instanceof DistributorOrderResult)) {
            $snap = OrderPlacementSnapshotUtil::invalid_place_return_snapshot($job->ctx($attempt_n));
            OrderPlacementJobSnapshotsStore::set_job_place_result($jobs_table, $order, $job_key, $snap);
            throw new \RuntimeException((string) ($snap['message'] ?? 'Invalid place return'));
        }

        OrderPlacementJobSnapshotsStore::set_job_place_result(
            $jobs_table,
            $order,
            $job_key,
            OrderPlacementSnapshotUtil::place_snapshot($or, $job->ctx($attempt_n))
        );

        return $or;
    }

    /**
     * Resolve the receiving FFL ship-to destination if this job requires it.
     *
     * @return array{0:?DistributorShipTo,1:string}
     * @throws \RuntimeException
     */
    private static function resolve_ship_to_ffl_if_needed(FFLTable $ffl_table, WC_Order $order, bool $ffl_required): array
    {
        if (!$ffl_required) {
            return [null, ''];
        }

        $receiving_ffl_number = strtoupper(trim((string) $order->get_meta('fflhub_receiving_ffl_number', true)));
        if ($receiving_ffl_number === '') {
            throw new \RuntimeException('Job contains FFL-required lines but missing receiving FFL number on order');
        }

        $ship_ffl = CheckoutOrderRequestBuilder::build_ship_to_ffl_or_null(
            $ffl_table,
            $receiving_ffl_number,
            function (): void {
                // silent
            }
        );

        if (!($ship_ffl instanceof DistributorShipTo)) {
            throw new \RuntimeException('Job contains FFL-required lines but failed to resolve ship_to_ffl from DB');
        }

        return [$ship_ffl, $receiving_ffl_number];
    }

    /**
     * Send a best-effort admin alert when place-order reaches terminal failure.
     *
     * Recipients default to admin_email and are filterable with:
     * - fflhub_place_failure_email_recipients
     * - fflhub_place_failure_email_subject
     * - fflhub_place_failure_email_body
     */
    private static function send_place_failure_email(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        string $dist_id,
        string $lane,
        int $attempt_n,
        ?string $fallback_error = null
    ): void {
        if (!function_exists('wp_mail')) {
            return;
        }

        $order_id = (int) $order->get_id();
        $job_key  = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($order_id <= 0 || $job_key === '') {
            return;
        }

        $job = null;
        try {
            $job = OrderPlacementJobsRepository::get_job_for_order($jobs_table, $order, $job_key);
        } catch (\Throwable $ignored) {
            $job = null;
        }

        $status = ($job instanceof OrderPlacementJobRow) ? trim((string) $job->status) : '';
        $step   = ($job instanceof OrderPlacementJobRow) ? trim((string) $job->last_step) : '';

        if ($status !== OrderPlacementKeys::JOB_STATUS_FAILED || $step !== 'place') {
            return;
        }

        $last_error = ($job instanceof OrderPlacementJobRow) ? trim((string) $job->last_error) : '';
        if ($last_error === '') {
            $last_error = trim((string) $fallback_error);
        }
        if ($last_error === '') {
            $last_error = 'Unknown place-order failure';
        }

        $merchant_po       = ($job instanceof OrderPlacementJobRow) ? trim((string) $job->merchant_po) : '';
        $external_order_id = ($job instanceof OrderPlacementJobRow) ? trim((string) $job->external_order_id) : '';

        $recipient_candidates = [];
        $admin_email = trim((string) get_option('admin_email', ''));
        if ($admin_email !== '') {
            $recipient_candidates[] = $admin_email;
        }

        /** @var mixed $filtered */
        $filtered = apply_filters(
            'fflhub_place_failure_email_recipients',
            $recipient_candidates,
            $order_id,
            $job_key,
            $dist_id,
            $lane
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
            return;
        }

        $edit_url = function_exists('admin_url')
            ? admin_url('post.php?post=' . $order_id . '&action=edit')
            : '';

        $subject = sprintf(
            '[FFL Hub] Place-order FAILED - Order #%d - %s/%s',
            $order_id,
            $dist_id !== '' ? $dist_id : '-',
            $lane !== '' ? $lane : '-'
        );

        $body_lines = [
            'An order placement job failed at the PLACE step.',
            '',
            'Order ID: ' . $order_id,
            'Job Key: ' . $job_key,
            'Distributor: ' . ($dist_id !== '' ? $dist_id : '-'),
            'Lane: ' . ($lane !== '' ? $lane : '-'),
            'Attempt: ' . max(0, (int) $attempt_n),
            'Merchant PO: ' . ($merchant_po !== '' ? $merchant_po : '-'),
            'External Order ID: ' . ($external_order_id !== '' ? $external_order_id : '-'),
            'Error: ' . $last_error,
        ];

        if ($edit_url !== '') {
            $body_lines[] = 'Edit Order: ' . $edit_url;
        }

        $body = implode("\n", $body_lines);

        /** @var mixed $subject_filtered */
        $subject_filtered = apply_filters(
            'fflhub_place_failure_email_subject',
            $subject,
            $order_id,
            $job_key,
            $dist_id,
            $lane
        );
        if (is_string($subject_filtered) && trim($subject_filtered) !== '') {
            $subject = trim($subject_filtered);
        }

        /** @var mixed $body_filtered */
        $body_filtered = apply_filters(
            'fflhub_place_failure_email_body',
            $body,
            $order_id,
            $job_key,
            $dist_id,
            $lane,
            $last_error
        );
        if (is_string($body_filtered) && trim($body_filtered) !== '') {
            $body = $body_filtered;
        }

        wp_mail(array_keys($recipients), $subject, $body);
    }
}
