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
     * @param string                  $job_key    Job key (dist|bucket). Runner normalizes.
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

        // Fast exit if already success.
        $existing_status = (string) OrderPlacementJobLifeCycle::get_job_status($jobs_table, $order, $job_key);
        if ($existing_status === OrderPlacementKeys::JOB_STATUS_SUCCESS) {
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
        $bucket  = (string) $job->bucket_norm();

        $sm = new OrderPlacementJobStateMachine();

        try {
            // Validate job row basics.
            $bucket_ok = OrderPlacementKeysUtil::is_valid_bucket($bucket);
            if ($dist_id === '' || !$bucket_ok) {
                throw new \RuntimeException('Invalid job: missing dist_id or invalid bucket');
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

            // Resolve FFL ship-to (if needed).
            [$ship_ffl, $receiving_ffl_number] = self::resolve_ship_to_ffl_if_needed($ffl_table, $order, $ffl_required);

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
            $req = new DistributorOrderRequest(
                $lines,
                $ship_customer,
                $ship_ffl,
                $merchant_order_id,
                $dest_state,
                $receiving_ffl_number,
                'FFLHub order placement job'
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
            $or = self::place_and_persist_result($jobs_table, $order, $job, $dist, $req, $attempt_n);

            // State machine after place.
            $dec2 = $sm->apply_place_order_result($jobs_table, $order, $job_key, $or, $attempt_n);
            if (($dec2['action'] ?? '') === 'exit') {
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
     * NOTE: Still contains DEBUG stub logic (your existing TODO).
     * Replace the stub with $dist->place_order($req) when ready.
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
            //$or = $dist->place_order($req);
            
            $or = DistributorOrderResult::block_fatal("TEST DEBUG BLOCK TO TEST VALIDATION");

            ///WE NEED TO CHANGE THIS TO ORDER FOR REAL WHEN WE GO TO PROD


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


        //here?


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
}
