<?php

namespace FFLHub\Distributor\Services\Orders;

use WC_Order;

use FFLHub\Plugin;
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
use FFLHub\Distributor\Services\Orders\Jobs\Lifecycle\OrderPlacementJobLifecycle;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\Snapshots\OrderPlacementJobSnapshotsStore;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementPOUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementSnapshotUtil;

use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

final class OrderPlacementJobRunner
{
    private const LOG_PREFIX  = '[FFLHUB][OrderPlacementJobRunner]';
    private const DEBUG_CONST = 'FFLHUB_PLACE_ORDER_JOB_RUNNER_DEBUG';

    public function run(WC_Order $order, string $job_key): void
    {
        $run_started = microtime(true);

        // -----------------------------
        // Segment timers (ms)
        // -----------------------------
        $seg = [
            'normalize_ms'             => 0,
            'check_already_success_ms' => 0,
            'mark_running_ms'          => 0,
            'repo_get_job_ms'          => 0,
            'job_row_load_total_ms'    => 0,

            'validate_total_ms'        => 0,
            'sm_after_validate_ms'     => 0,

            'place_total_ms'           => 0,
            'sm_after_place_ms'        => 0,

            'mark_success_ms'          => 0,
            'mark_failed_ms'           => 0,
        ];

        // Some metadata we’ll keep for the final run_finish line
        $meta = [
            'order_id'   => 0,
            'job_key'    => '',
            'dist_id'    => '',
            'bucket'     => '',
            'attempt_n'  => 0,
            'exit_stage' => '',
        ];

        // -----------------------------
        // Normalize inputs
        // -----------------------------
        $t0 = microtime(true);

        $order_id  = (int) $order->get_id();
        $job_key_in = (string) $job_key;
        $job_key   = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);

        $seg['normalize_ms'] = (int) round((microtime(true) - $t0) * 1000);

        $meta['order_id'] = $order_id;
        $meta['job_key']  = $job_key;

        $this->log_ctx('run_start', [
            'order_id'      => $order_id,
            'job_key_in'    => $job_key_in,
            'job_key'       => $job_key,
            'order_status'  => method_exists($order, 'get_status') ? (string) $order->get_status() : 'unknown',
            'normalize_ms'  => $seg['normalize_ms'],
        ]);

        if ($order_id <= 0 || $job_key === '') {
            $meta['exit_stage'] = 'exit_invalid_inputs';
            $this->log_ctx('exit_invalid_inputs', [
                'order_id'  => $order_id,
                'job_key'   => $job_key,
                'elapsed_ms' => (int) round((microtime(true) - $run_started) * 1000),
            ]);
            return;
        }

        // -----------------------------
        // Fast exit if already success
        // -----------------------------
        $t1 = microtime(true);

        $existing_status = (string) OrderPlacementJobLifecycle::get_job_status($order, $job_key);

        $seg['check_already_success_ms'] = (int) round((microtime(true) - $t1) * 1000);

        if ($existing_status === OrderPlacementKeys::JOB_STATUS_SUCCESS) {
            $meta['exit_stage'] = 'exit_already_success';
            $this->log_ctx('exit_already_success', [
                'order_id'   => $order_id,
                'job_key'    => $job_key,
                'job_status' => $existing_status,
                'check_success_ms' => $seg['check_already_success_ms'],
                'elapsed_ms' => (int) round((microtime(true) - $run_started) * 1000),
            ]);
            return;
        }

        // -----------------------------
        // Mark running (attempts++)
        // -----------------------------
        $t2 = microtime(true);

        $attempt_n = 0;
        try {
            $attempt_n = (int) OrderPlacementJobLifecycle::increment_job_attempts_and_mark_running($order, $job_key);
        } catch (\Throwable $e) {
            $seg['mark_running_ms'] = (int) round((microtime(true) - $t2) * 1000);

            $meta['exit_stage'] = 'lifecycle_mark_running_exception';
            $this->log_ctx('lifecycle_mark_running_exception', [
                'order_id'   => $order_id,
                'job_key'    => $job_key,
                'mark_running_ms' => $seg['mark_running_ms'],
                'err'        => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'elapsed_ms' => (int) round((microtime(true) - $run_started) * 1000),
            ]);
            // If we can't mark running, bail to avoid double-processing.
            return;
        }

        $seg['mark_running_ms'] = (int) round((microtime(true) - $t2) * 1000);

        $meta['attempt_n'] = $attempt_n;

        $this->log_ctx('marked_running', [
            'order_id'        => $order_id,
            'job_key'         => $job_key,
            'attempt_n'       => $attempt_n,
            'mark_running_ms' => $seg['mark_running_ms'],
        ]);

        // -----------------------------
        // Load job row
        // -----------------------------
        $t3 = microtime(true);

        $job = null;
        try {
            $job = OrderPlacementJobsRepository::get_job_for_order($order, $job_key);
        } catch (\Throwable $e) {
            $seg['repo_get_job_ms'] = (int) round((microtime(true) - $t3) * 1000);

            $this->log_ctx('repo_get_job_exception', [
                'order_id'   => $order_id,
                'job_key'    => $job_key,
                'repo_get_job_ms' => $seg['repo_get_job_ms'],
                'err'        => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ]);
        }

        $seg['repo_get_job_ms'] = (int) round((microtime(true) - $t3) * 1000);

        if (!($job instanceof OrderPlacementJobRow)) {
            $meta['exit_stage'] = 'job_row_not_found';

            $this->log_ctx('job_row_not_found', [
                'order_id'        => $order_id,
                'job_key'         => $job_key,
                'repo_get_job_ms' => $seg['repo_get_job_ms'],
            ]);

            $t_fail = microtime(true);
            try {
                OrderPlacementJobLifecycle::mark_job_failed($order, $job_key, 'Job row not found for order/job_key');
            } catch (\Throwable $e) {
                $this->log_ctx('lifecycle_mark_failed_exception', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'err'      => $e->getMessage(),
                ]);
            }
            $seg['mark_failed_ms'] = (int) round((microtime(true) - $t_fail) * 1000);

            $this->log_ctx('run_finish', [
                'order_id'   => $order_id,
                'job_key'    => $job_key,
                'attempt_n'  => $attempt_n,
                'exit_stage' => $meta['exit_stage'],
                'segments'   => $seg,
                'elapsed_ms' => (int) round((microtime(true) - $run_started) * 1000),
            ]);

            return;
        }

        // After we have the job row, use canonical key everywhere.
        $job_key = $job->job_key_norm();
        $meta['job_key'] = $job_key;

        $dist_id = (string) $job->dist_id_norm();
        $bucket  = (string) $job->bucket_norm();

        $meta['dist_id'] = $dist_id;
        $meta['bucket']  = $bucket;

        $this->log_ctx('job_row_loaded', [
            'order_id'     => $order_id,
            'job_key'      => $job_key,
            'dist_id'      => $dist_id,
            'bucket'       => $bucket,
            'ffl_required' => method_exists($job, 'ffl_required') ? ($job->ffl_required() ? '1' : '0') : null,
            'attempt_n'    => $attempt_n,
            'repo_get_job_ms' => $seg['repo_get_job_ms'],
        ]);

        $sm = new OrderPlacementJobStateMachine();

        try {
            // -----------------------------
            // Validate job row basics
            // -----------------------------
            $bucket_ok = ($job->is_ffl_bucket() || $job->is_non_ffl_bucket());

            if ($dist_id === '' || !$bucket_ok) {
                $this->log_ctx('invalid_job_row', [
                    'order_id'  => $order_id,
                    'job_key'   => $job_key,
                    'dist_id'   => $dist_id,
                    'bucket'    => $bucket,
                    'bucket_ok' => $bucket_ok ? '1' : '0',
                ]);
                throw new \RuntimeException('Invalid job: missing dist_id or invalid bucket');
            }

            $ffl_required = $job->ffl_required();

            $lines = $job->payload_lines();
            $line_count = is_array($lines) ? count($lines) : 0;

            if (empty($lines)) {
                $this->log_ctx('invalid_payload_no_lines', [
                    'order_id'    => $order_id,
                    'job_key'     => $job_key,
                    'dist_id'     => $dist_id,
                    'bucket'      => $bucket,
                    'line_count'  => $line_count,
                ]);
                throw new \RuntimeException('Invalid payload: no valid order lines');
            }

            // -----------------------------
            // Resolve customer ship-to
            // -----------------------------
            $t_ship_customer = microtime(true);

            $ship_customer = DistributorShipTo::from_order_shipping_fallback_billing($order);

            $ship_customer_ms = (int) round((microtime(true) - $t_ship_customer) * 1000);

            if (!($ship_customer instanceof DistributorShipTo)) {
                $this->log_ctx('ship_customer_invalid', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'ship_customer_ms' => $ship_customer_ms,
                ]);
                throw new \RuntimeException('Customer ship-to incomplete on order');
            }

            $this->log_ctx('ship_customer_resolved', [
                'order_id' => $order_id,
                'job_key'  => $job_key,
                'dist_id'  => $dist_id,
                'state'    => (string) ($ship_customer->state ?? ''),
                'ship_customer_ms' => $ship_customer_ms,
            ]);

            // -----------------------------
            // Resolve FFL ship-to (if needed)
            // -----------------------------
            $t_ffl = microtime(true);

            [$ship_ffl, $receiving_ffl_number] = $this->resolve_ship_to_ffl_if_needed($order, $ffl_required);

            $ffl_resolve_ms = (int) round((microtime(true) - $t_ffl) * 1000);

            if ($ffl_required) {
                $this->log_ctx('ffl_bucket_resolved', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'receiving_ffl_number' => $receiving_ffl_number,
                    'ship_ffl_ok' => ($ship_ffl instanceof DistributorShipTo) ? '1' : '0',
                    'ffl_state'   => ($ship_ffl instanceof DistributorShipTo) ? (string) ($ship_ffl->state ?? '') : '',
                    'ffl_resolve_ms' => $ffl_resolve_ms,
                ]);
            } else {
                $this->log_ctx('non_ffl_bucket', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'ffl_resolve_ms' => $ffl_resolve_ms,
                ]);
            }

            $dest_state = $ffl_required && ($ship_ffl instanceof DistributorShipTo)
                ? (string) $ship_ffl->state
                : (string) $ship_customer->state;

            // -----------------------------
            // Merchant PO build + persist
            // -----------------------------
            $t_po = microtime(true);

            $merchant_order_id = OrderPlacementPOUtil::build_merchant_po($job, 1);
            if ($merchant_order_id !== '') {
                OrderPlacementJobIdentifiersStore::set_job_merchant_po($order, $job_key, $merchant_order_id, false);
            }

            $po_ms = (int) round((microtime(true) - $t_po) * 1000);

            $this->log_ctx('merchant_po', [
                'order_id'          => $order_id,
                'job_key'           => $job_key,
                'dist_id'           => $dist_id,
                'bucket'            => $bucket,
                'merchant_order_id' => $merchant_order_id,
                'dest_state'        => $dest_state,
                'line_count'        => $line_count,
                'po_ms'             => $po_ms,
            ]);

            // -----------------------------
            // Build DistributorOrderRequest
            // -----------------------------
            $t_req = microtime(true);

            $req = new DistributorOrderRequest(
                $lines,
                $ship_customer,
                $ship_ffl,
                $merchant_order_id,
                $dest_state,
                $receiving_ffl_number,
                'FFLHub order placement job'
            );

            $req_ms = (int) round((microtime(true) - $t_req) * 1000);

            $this->log_ctx('req_built', [
                'order_id'   => $order_id,
                'job_key'    => $job_key,
                'dist_id'    => $dist_id,
                'bucket'     => $bucket,
                'attempt_n'  => $attempt_n,
                'req_ms'     => $req_ms,
            ]);

            // -----------------------------
            // Distributor resolve
            // -----------------------------
            $t_dist = microtime(true);

            $handler = Plugin::instance()->distributor_handler ?? null;
            if (!($handler instanceof DistributorHandler)) {
                $dist_resolve_ms = (int) round((microtime(true) - $t_dist) * 1000);
                $this->log_ctx('dist_handler_missing', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_resolve_ms' => $dist_resolve_ms,
                ]);
                throw new \RuntimeException('Distributor handler not available');
            }

            if (!Options::is_distributor_enabled($dist_id)) {
                $dist_resolve_ms = (int) round((microtime(true) - $t_dist) * 1000);
                $this->log_ctx('dist_disabled', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'dist_resolve_ms' => $dist_resolve_ms,
                ]);
                throw new \RuntimeException('Distributor is disabled: ' . $dist_id);
            }

            $dist = $handler->get_distributor_by_id($dist_id);
            if (!($dist instanceof DistributorBase)) {
                $dist_resolve_ms = (int) round((microtime(true) - $t_dist) * 1000);
                $this->log_ctx('dist_not_found', [
                    'order_id' => $order_id,
                    'job_key'  => $job_key,
                    'dist_id'  => $dist_id,
                    'dist_resolve_ms' => $dist_resolve_ms,
                ]);
                throw new \RuntimeException('Distributor not found: ' . $dist_id);
            }

            $dist_resolve_ms = (int) round((microtime(true) - $t_dist) * 1000);

            $this->log_ctx('dist_resolved', [
                'order_id'    => $order_id,
                'job_key'     => $job_key,
                'dist_id'     => $dist_id,
                'dist_class'  => is_object($dist) ? get_class($dist) : '',
                'dist_resolve_ms' => $dist_resolve_ms,
            ]);

            // -----------------------------
            // Validate + persist snapshot
            // -----------------------------
            $t_validate_total = microtime(true);

            $vr = $this->validate_and_persist_result($order, $job, $dist, $req, $attempt_n);

            $seg['validate_total_ms'] = (int) round((microtime(true) - $t_validate_total) * 1000);

            $vr_code = is_object($vr) ? (string) ($vr->code ?? '') : '';
            $this->log_ctx('validation_done', [
                'order_id'    => $order_id,
                'job_key'     => $job_key,
                'dist_id'     => $dist_id,
                'attempt_n'   => $attempt_n,
                'vr_class'    => is_object($vr) ? get_class($vr) : '',
                'code'        => $vr_code,
                'validate_ms' => $seg['validate_total_ms'],
            ]);

            // -----------------------------
            // State machine after validation
            // -----------------------------
            $t_sm1 = microtime(true);

            $dec = $sm->apply_validation_result($order, $job_key, $vr, $attempt_n);

            $seg['sm_after_validate_ms'] = (int) round((microtime(true) - $t_sm1) * 1000);

            $this->log_ctx('sm_after_validation', [
                'order_id'   => $order_id,
                'job_key'    => $job_key,
                'dist_id'    => $dist_id,
                'attempt_n'  => $attempt_n,
                'sm_ms'      => $seg['sm_after_validate_ms'],
                'decision'   => is_array($dec) ? $dec : ['_non_array' => true],
            ]);

            if (($dec['action'] ?? '') === 'exit') {
                $meta['exit_stage'] = 'exit_after_validation';
                $this->log_ctx('exit_after_validation', [
                    'order_id'   => $order_id,
                    'job_key'    => $job_key,
                    'dist_id'    => $dist_id,
                    'attempt_n'  => $attempt_n,
                    'segments'   => $seg,
                    'elapsed_ms' => (int) round((microtime(true) - $run_started) * 1000),
                ]);
                return;
            }

            // -----------------------------
            // Place + persist snapshot
            // -----------------------------
            $t_place_total = microtime(true);

            $or = $this->place_and_persist_result($order, $job, $dist, $req, $attempt_n);

            $seg['place_total_ms'] = (int) round((microtime(true) - $t_place_total) * 1000);

            $this->log_ctx('place_done', [
                'order_id'  => $order_id,
                'job_key'   => $job_key,
                'dist_id'   => $dist_id,
                'attempt_n' => $attempt_n,
                'or_class'  => is_object($or) ? get_class($or) : '',
                'code'      => (string) ($or->code ?? ''),
                'place_ms'  => $seg['place_total_ms'],
            ]);

            // -----------------------------
            // State machine after place
            // -----------------------------
            $t_sm2 = microtime(true);

            $dec2 = $sm->apply_place_order_result($order, $job_key, $or, $attempt_n);

            $seg['sm_after_place_ms'] = (int) round((microtime(true) - $t_sm2) * 1000);

            $this->log_ctx('sm_after_place', [
                'order_id'   => $order_id,
                'job_key'    => $job_key,
                'dist_id'    => $dist_id,
                'attempt_n'  => $attempt_n,
                'sm_ms'      => $seg['sm_after_place_ms'],
                'decision'   => is_array($dec2) ? $dec2 : ['_non_array' => true],
            ]);

            if (($dec2['action'] ?? '') === 'exit') {
                $meta['exit_stage'] = 'exit_after_place';
                $this->log_ctx('exit_after_place', [
                    'order_id'   => $order_id,
                    'job_key'    => $job_key,
                    'dist_id'    => $dist_id,
                    'attempt_n'  => $attempt_n,
                    'segments'   => $seg,
                    'elapsed_ms' => (int) round((microtime(true) - $run_started) * 1000),
                ]);
                return;
            }

            // -----------------------------
            // Mark success
            // -----------------------------
            $t_succ = microtime(true);

            OrderPlacementJobLifecycle::mark_job_success($order, $job_key);

            $seg['mark_success_ms'] = (int) round((microtime(true) - $t_succ) * 1000);

            $meta['exit_stage'] = 'mark_success';

            $this->log_ctx('mark_success', [
                'order_id'   => $order_id,
                'job_key'    => $job_key,
                'dist_id'    => $dist_id,
                'attempt_n'  => $attempt_n,
                'mark_success_ms' => $seg['mark_success_ms'],
                'segments'   => $seg,
                'elapsed_ms' => (int) round((microtime(true) - $run_started) * 1000),
            ]);
        } catch (\Throwable $e) {
            $meta['exit_stage'] = 'exception';

            $this->log_ctx('exception', [
                'order_id'   => $order_id,
                'job_key'    => $job_key,
                'dist_id'    => $meta['dist_id'],
                'bucket'     => $meta['bucket'],
                'attempt_n'  => $attempt_n,
                'err'        => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'segments'   => $seg,
                'elapsed_ms' => (int) round((microtime(true) - $run_started) * 1000),
            ]);

            $t_fail = microtime(true);
            try {
                OrderPlacementJobLifecycle::mark_job_failed($order, $job_key, $e->getMessage());
            } catch (\Throwable $e2) {
                $this->log_ctx('mark_failed_exception', [
                    'order_id'  => $order_id,
                    'job_key'   => $job_key,
                    'attempt_n' => $attempt_n,
                    'err'       => $e2->getMessage(),
                ]);
            }
            $seg['mark_failed_ms'] = (int) round((microtime(true) - $t_fail) * 1000);

            // Always finish-line log for failures too (easy grepping)
            $this->log_ctx('run_finish', [
                'order_id'   => $order_id,
                'job_key'    => $job_key,
                'dist_id'    => $meta['dist_id'],
                'bucket'     => $meta['bucket'],
                'attempt_n'  => $attempt_n,
                'exit_stage' => $meta['exit_stage'],
                'mark_failed_ms' => $seg['mark_failed_ms'],
                'segments'   => $seg,
                'elapsed_ms' => (int) round((microtime(true) - $run_started) * 1000),
            ]);
        }
    }

    private function validate_and_persist_result(
        WC_Order $order,
        OrderPlacementJobRow $job,
        DistributorBase $dist,
        DistributorOrderRequest $req,
        int $attempt_n = 0
    ): DistributorOrderValidationResult {
        $order_id = (int) $order->get_id();
        $job_key  = $job->job_key_norm();

        // Segment timing (validate call vs persist)
        $t_total = microtime(true);
        $t_call  = microtime(true);

        $this->log_ctx('validate_start', [
            'order_id'  => $order_id,
            'job_key'   => $job_key,
            'attempt_n' => $attempt_n,
            'dist_id'   => $job->dist_id_norm(),
        ]);

        $vr = null;

        try {
            $vr = $dist->validate_order_request($req, true);
        } catch (\Throwable $e) {
            $call_ms = (int) round((microtime(true) - $t_call) * 1000);

            $this->log_ctx('validate_exception', [
                'order_id'  => $order_id,
                'job_key'   => $job_key,
                'attempt_n' => $attempt_n,
                'call_ms'   => $call_ms,
                'err'       => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            $t_persist = microtime(true);
            $snap = OrderPlacementSnapshotUtil::invalid_validate_return_snapshot($job->ctx($attempt_n));
            OrderPlacementJobSnapshotsStore::set_job_validation_result($order, $job_key, $snap);
            $persist_ms = (int) round((microtime(true) - $t_persist) * 1000);

            $this->log_ctx('validate_exception_snapshot_persisted', [
                'order_id'  => $order_id,
                'job_key'   => $job_key,
                'attempt_n' => $attempt_n,
                'persist_ms' => $persist_ms,
            ]);

            throw new \RuntimeException('validate_order_request threw: ' . $e->getMessage());
        }

        $call_ms = (int) round((microtime(true) - $t_call) * 1000);

        if (!($vr instanceof DistributorOrderValidationResult)) {
            $this->log_ctx('validate_invalid_return', [
                'order_id'       => $order_id,
                'job_key'        => $job_key,
                'attempt_n'      => $attempt_n,
                'call_ms'        => $call_ms,
                'returned_type'  => is_object($vr) ? get_class($vr) : gettype($vr),
            ]);

            $t_persist = microtime(true);
            $snap = OrderPlacementSnapshotUtil::invalid_validate_return_snapshot($job->ctx($attempt_n));
            OrderPlacementJobSnapshotsStore::set_job_validation_result($order, $job_key, $snap);
            $persist_ms = (int) round((microtime(true) - $t_persist) * 1000);

            $this->log_ctx('validate_invalid_snapshot_persisted', [
                'order_id'  => $order_id,
                'job_key'   => $job_key,
                'attempt_n' => $attempt_n,
                'persist_ms' => $persist_ms,
            ]);

            throw new \RuntimeException($snap['message']);
        }

        $t_persist = microtime(true);

        OrderPlacementJobSnapshotsStore::set_job_validation_result(
            $order,
            $job_key,
            OrderPlacementSnapshotUtil::validation_snapshot($vr, $job->ctx($attempt_n))
        );

        $persist_ms = (int) round((microtime(true) - $t_persist) * 1000);

        $total_ms = (int) round((microtime(true) - $t_total) * 1000);

        $this->log_ctx('validate_persisted', [
            'order_id'   => $order_id,
            'job_key'    => $job_key,
            'attempt_n'  => $attempt_n,
            'code'       => (string) ($vr->code ?? ''),
            'message'    => (string) ($vr->message ?? ''),
            'call_ms'    => $call_ms,
            'persist_ms' => $persist_ms,
            'total_ms'   => $total_ms,
        ]);

        return $vr;
    }

    private function place_and_persist_result(
        WC_Order $order,
        OrderPlacementJobRow $job,
        DistributorBase $dist,
        DistributorOrderRequest $req,
        int $attempt_n = 0
    ): DistributorOrderResult {
        $order_id = (int) $order->get_id();
        $job_key  = $job->job_key_norm();

        // Segment timing (place call vs persist)
        $t_total = microtime(true);
        $t_call  = microtime(true);

        $this->log_ctx('place_start', [
            'order_id'  => $order_id,
            'job_key'   => $job_key,
            'attempt_n' => $attempt_n,
            'dist_id'   => $job->dist_id_norm(),
        ]);


        try {
            // NOTE: keeping your stub logic, but fixing the stray "null;" typo.
            // Replace with: $or = $dist->place_order($req);
            $roll = rand(1, 2);
            $or = DistributorOrderResult::ok('DEBUG: FAKE SUCCESS/OK', []);

            switch ($roll) {
                case (1):
                    $or = DistributorOrderResult::ok('DEBUG: FAKE SUCCESS/OK', []);
                    break;
                case (2):
                    $or = DistributorOrderResult::block_fatal('DEBUG: FAKE FATAL BLOCK', []);

                    break;
            }
        } catch (\Throwable $e) {
            $call_ms = (int) round((microtime(true) - $t_call) * 1000);

            $this->log_ctx('place_exception', [
                'order_id'  => $order_id,
                'job_key'   => $job_key,
                'attempt_n' => $attempt_n,
                'call_ms'   => $call_ms,
                'err'       => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            $t_persist = microtime(true);
            $snap = OrderPlacementSnapshotUtil::invalid_place_return_snapshot($job->ctx($attempt_n));
            OrderPlacementJobSnapshotsStore::set_job_place_result($order, $job_key, $snap);
            $persist_ms = (int) round((microtime(true) - $t_persist) * 1000);

            $this->log_ctx('place_exception_snapshot_persisted', [
                'order_id'   => $order_id,
                'job_key'    => $job_key,
                'attempt_n'  => $attempt_n,
                'persist_ms' => $persist_ms,
            ]);

            throw new \RuntimeException('place_order threw: ' . $e->getMessage());
        }

        $call_ms = (int) round((microtime(true) - $t_call) * 1000);

        if (!($or instanceof DistributorOrderResult)) {
            $this->log_ctx('place_invalid_return', [
                'order_id'       => $order_id,
                'job_key'        => $job_key,
                'attempt_n'      => $attempt_n,
                'call_ms'        => $call_ms,
                'returned_type'  => is_object($or) ? get_class($or) : gettype($or),
            ]);

            $t_persist = microtime(true);
            $snap = OrderPlacementSnapshotUtil::invalid_place_return_snapshot($job->ctx($attempt_n));
            OrderPlacementJobSnapshotsStore::set_job_place_result($order, $job_key, $snap);
            $persist_ms = (int) round((microtime(true) - $t_persist) * 1000);

            $this->log_ctx('place_invalid_snapshot_persisted', [
                'order_id'   => $order_id,
                'job_key'    => $job_key,
                'attempt_n'  => $attempt_n,
                'persist_ms' => $persist_ms,
            ]);

            throw new \RuntimeException($snap['message']);
        }

        $t_persist = microtime(true);

        OrderPlacementJobSnapshotsStore::set_job_place_result(
            $order,
            $job_key,
            OrderPlacementSnapshotUtil::place_snapshot($or, $job->ctx($attempt_n))
        );

        $persist_ms = (int) round((microtime(true) - $t_persist) * 1000);

        $total_ms = (int) round((microtime(true) - $t_total) * 1000);

        $this->log_ctx('place_persisted', [
            'order_id'   => $order_id,
            'job_key'    => $job_key,
            'attempt_n'  => $attempt_n,
            'code'       => (string) ($or->code ?? ''),
            'message'    => (string) ($or->message ?? ''),
            'call_ms'    => $call_ms,
            'persist_ms' => $persist_ms,
            'total_ms'   => $total_ms,
        ]);

        return $or;
    }

    /** @return array{0:?DistributorShipTo,1:string} */
    private function resolve_ship_to_ffl_if_needed(WC_Order $order, bool $ffl_required): array
    {
        $order_id = (int) $order->get_id();

        if (!$ffl_required) {
            return [null, ''];
        }

        $receiving_ffl_number = strtoupper(trim((string) $order->get_meta('fflhub_receiving_ffl_number', true)));
        if ($receiving_ffl_number === '') {
            $this->log_ctx('ffl_missing_number', [
                'order_id' => $order_id,
            ]);
            throw new \RuntimeException('FFL bucket but missing receiving FFL number on order');
        }

        $this->log_ctx('ffl_lookup_start', [
            'order_id'              => $order_id,
            'receiving_ffl_number'  => $receiving_ffl_number,
        ]);

        $t_lookup = microtime(true);

        $ship_ffl = CheckoutOrderRequestBuilder::build_ship_to_ffl_or_null(
            $receiving_ffl_number,
            function () { /* silent */
            }
        );

        $lookup_ms = (int) round((microtime(true) - $t_lookup) * 1000);

        if (!($ship_ffl instanceof DistributorShipTo)) {
            $this->log_ctx('ffl_lookup_failed', [
                'order_id'             => $order_id,
                'receiving_ffl_number' => $receiving_ffl_number,
                'lookup_ms'            => $lookup_ms,
            ]);
            throw new \RuntimeException('FFL bucket but failed to resolve ship_to_ffl from DB');
        }

        $this->log_ctx('ffl_lookup_ok', [
            'order_id'             => $order_id,
            'receiving_ffl_number' => $receiving_ffl_number,
            'lookup_ms'            => $lookup_ms,
            'state'                => (string) ($ship_ffl->state ?? ''),
        ]);

        return [$ship_ffl, $receiving_ffl_number];
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
