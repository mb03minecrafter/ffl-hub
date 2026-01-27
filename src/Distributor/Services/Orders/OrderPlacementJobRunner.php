<?php

namespace FFLHub\Distributor\Services\Orders;

use FFLHub\Plugin;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Core\DistributorBase;

use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorShipTo;

use FFLHub\Distributor\Services\Orders\OrderPlacementJobStateMachine;

use FFLHub\Checkout\Builders\CheckoutOrderRequestBuilder;
use FFLHub\Settings\Options;

use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

final class OrderPlacementJobRunner
{
    private const LOG_PREFIX  = '[FFLHUB][OrderPlacementJobRunner]';
    private const DEBUG_CONST = 'FFLHUB_PLACE_ORCH_DEBUG';

    public function run(WC_Order $order, string $job_key): void
    {
        $order_id = (int) $order->get_id();
        $job_key  = (string) $job_key;

        // Idempotency
        if (OrderPlacementJobsStore::get_job_status($order, $job_key) === OrderPlacementKeys::JOB_STATUS_SUCCESS) {
            return;
        }

        // Mark running + bump attempts (Store clears next_run_at and action_id)
        $attempt_n = OrderPlacementJobsStore::increment_job_attempts_and_mark_running($order, $job_key);

        // Runner-level safety: ensure action_id never means "currently running"
        OrderPlacementJobsStore::clear_job_action_id($order, $job_key);

        error_log(self::LOG_PREFIX . " running key={$job_key} order={$order_id} attempt={$attempt_n}");

        $payload = OrderPlacementJobsStore::get_job_payload($order, $job_key);
        if (!is_array($payload)) {
            $this->fail_job($order, $job_key, 'Missing/invalid payload for job');
            return;
        }

        $sm = new OrderPlacementJobStateMachine();

        try {
            // ---------------- Parse payload ----------------
            $dist_id = strtolower(trim((string) ($payload['dist_id'] ?? '')));
            $bucket  = strtolower(trim((string) ($payload['bucket'] ?? '')));
            $ffl_required = ($bucket === 'ffl');

            if ($dist_id === '' || ($bucket !== 'ffl' && $bucket !== 'non')) {
                throw new \RuntimeException('Invalid payload: missing dist_id or invalid bucket');
            }

            // ---------------- Build order lines ----------------
            $lines = $this->build_order_lines_from_payload($payload['lines'] ?? [], $ffl_required);

            // ---------------- Resolve ship-to ----------------
            $ship_customer = $this->build_ship_to_customer_from_order($order);
            if (!($ship_customer instanceof DistributorShipTo)) {
                throw new \RuntimeException('Customer ship-to incomplete on order');
            }

            [$ship_ffl, $receiving_ffl_number] = $this->resolve_ship_to_ffl_if_needed($order, $ffl_required);

            // ---------------- Derived fields ----------------
            $dest_state = $ffl_required && ($ship_ffl instanceof DistributorShipTo)
                ? (string) $ship_ffl->state
                : (string) $ship_customer->state;

            // ---------------- Correlation ID (merchant PO) ----------------
            // We store this early as a correlation id (recommended).
            // It only "corresponds to a real distributor order" once validation passes AND place succeeds.
            $merchant_order_id = $this->build_merchant_po(
                $order_id,
                (string) $dist_id,
                (string) $bucket,
                1 // no indexing just yet
            );

            // Persist correlation id (idempotent: first writer wins)
            if ($merchant_order_id !== '') {
                OrderPlacementJobsStore::set_job_merchant_po($order, $job_key, $merchant_order_id, false);
            }

            if ($this->debug_enabled()) {
                error_log(self::LOG_PREFIX . " Customer " . $ship_customer->to_debug_string());
                if ($ffl_required && ($ship_ffl instanceof DistributorShipTo)) {
                    error_log(self::LOG_PREFIX . " FFL " . $ship_ffl->to_debug_string());
                }
                error_log(self::LOG_PREFIX . " MERCHANT ORDER ID (correlation): " . $merchant_order_id);
            }

            // ---------------- Build request ----------------
            $req = new DistributorOrderRequest(
                $lines,
                $ship_customer,
                $ship_ffl,
                $merchant_order_id,
                $dest_state,
                $receiving_ffl_number,
                'FFLHub order placement job'
            );

            // ---------------- Distributor lookup (enabled only) ----------------
            $handler = Plugin::instance()->distributor_handler ?? null;
            if (!($handler instanceof DistributorHandler)) {
                throw new \RuntimeException('Distributor handler not available');
            }

            if (!Options::is_distributor_enabled($dist_id)) {
                throw new \RuntimeException('Distributor is disabled: ' . $dist_id);
            }

            $dist = $handler->get_distributor_by_id($dist_id);
            if (!($dist instanceof DistributorBase)) {
                throw new \RuntimeException('Distributor not found: ' . $dist_id);
            }

            // ---------------- Validate before placing ----------------
            $vr = $this->validate_and_persist_result($order, $job_key, $dist, $req, [
                'order_id' => $order_id,
                'job_key'  => $job_key,
                'dist_id'  => $dist_id,
                'bucket'   => $bucket,
                'lines'    => count($req->valid_lines()),
                'dest'     => $req->dest_state,
                'ffl_num'  => $req->receiving_ffl_number !== '' ? 'Y' : 'N',
                'attempt'  => $attempt_n,
            ]);

            // State machine decides what to do with validation outcome
            $dec = $sm->apply_validation_result($order, $job_key, $vr, $attempt_n);
            if (($dec['action'] ?? '') === 'exit') {
                // retry_scheduled or failed — state machine already persisted status/fields
                return;
            }

            // ---------------- Place order ----------------
            $or = $this->place_and_persist_result($order, $job_key, $dist, $req, [
                'order_id' => $order_id,
                'job_key'  => $job_key,
                'dist_id'  => $dist_id,
                'bucket'   => $bucket,
                'attempt'  => $attempt_n,
            ]);

            $dec2 = $sm->apply_place_order_result($order, $job_key, $or, $attempt_n);
            if (($dec2['action'] ?? '') === 'exit') {
                return; // retry_scheduled or failed already persisted by state machine
            }

            // OK path: mark success
            OrderPlacementJobsStore::mark_job_success($order, $job_key, gmdate('c'));

            error_log(self::LOG_PREFIX . " success key={$job_key} order={$order_id}");
            return;
        } catch (\Throwable $e) {
            // Any unexpected exception is terminal (it’s a bug / config issue)
            $this->fail_job($order, $job_key, $e->getMessage());
        }
    }

    /* ======================================================
     * Validation snapshot
     * ====================================================== */

    /**
     * @param array<string,mixed> $ctx
     */
    private function validate_and_persist_result(
        WC_Order $order,
        string $job_key,
        DistributorBase $dist,
        DistributorOrderRequest $req,
        array $ctx = []
    ): DistributorOrderValidationResult {

        $vr = $dist->validate_order_request($req);

        if (!($vr instanceof DistributorOrderValidationResult)) {
            $snap = [
                'ok'      => false,
                'code'    => 'INVALID_RESULT',
                'message' => 'validate_order_request() did not return a DistributorOrderValidationResult',
                'codes'   => ['FFLHUB_VALIDATE_INVALID_RETURN'],
                'details' => [],
                'at'      => gmdate('c'),
                'ctx'     => $this->sanitize_meta_ctx($ctx),
            ];
            OrderPlacementJobsStore::set_job_validation_result($order, $job_key, $snap);

            // quick-glance
            OrderPlacementJobsStore::set_job_last_step($order, $job_key, 'validate');
            OrderPlacementJobsStore::set_job_last_error_codes($order, $job_key, $snap['codes']);

            throw new \RuntimeException($snap['message']);
        }

        $snap = $this->snapshot_validation_result($vr, $ctx);
        OrderPlacementJobsStore::set_job_validation_result($order, $job_key, $snap);

        // quick-glance
        OrderPlacementJobsStore::set_job_last_step($order, $job_key, 'validate');
        OrderPlacementJobsStore::set_job_last_error_codes($order, $job_key, is_array($vr->codes) ? $vr->codes : []);

        return $vr;
    }

    /* ======================================================
     * Place-order snapshot
     * ====================================================== */

    /**
     * Place order and persist a compact snapshot to job storage.
     *
     * @param array<string,mixed> $ctx
     */
    private function place_and_persist_result(
        WC_Order $order,
        string $job_key,
        DistributorBase $dist,
        DistributorOrderRequest $req,
        array $ctx = []
    ): DistributorOrderResult {
        // USE THIS LINE IF YOU WANT TO ENABLE LIVE ORDERING... BEWARE DO NOT DO THIS UNTIL THE SITE IS LIVE AND UP
        //$or = $dist->place_order($req);

        $or = DistributorOrderResult::ok('stub: place_order not implemented yet', []);

        

        if (!($or instanceof DistributorOrderResult)) {
            $snap = [
                'ok'      => false,
                'code'    => 'INVALID_RESULT',
                'message' => 'place_order() did not return a DistributorOrderResult',
                'codes'   => ['FFLHUB_PLACE_INVALID_RETURN'],
                'http'    => 0,
                'ext_ids' => [],
                'at'      => gmdate('c'),
                'ctx'     => $this->sanitize_meta_ctx($ctx),
            ];

            OrderPlacementJobsStore::set_job_place_result($order, $job_key, $snap);

            // quick-glance
            OrderPlacementJobsStore::set_job_last_step($order, $job_key, 'place');
            OrderPlacementJobsStore::set_job_last_error_codes($order, $job_key, $snap['codes']);

            throw new \RuntimeException($snap['message']);
        }

        $snap = $this->snapshot_place_result($or, $ctx);
        OrderPlacementJobsStore::set_job_place_result($order, $job_key, $snap);

        // quick-glance
        OrderPlacementJobsStore::set_job_last_step($order, $job_key, 'place');
        OrderPlacementJobsStore::set_job_last_error_codes($order, $job_key, is_array($or->codes) ? $or->codes : []);

        // Optional debug
        if ($or->ok && $or->code === DistributorOrderResult::CODE_OK) {
            $this->debug('place ok', $ctx);
        } else {
            $this->debug('place non-ok', $ctx + [
                'code'  => (string) $or->code,
                'codes' => is_array($or->codes) ? implode(',', $or->codes) : '',
                'msg'   => trim((string) $or->message),
            ]);
        }

        return $or;
    }

    /* ======================================================
     * Snapshots + helpers (unchanged)
     * ====================================================== */

    /**
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private function snapshot_validation_result(DistributorOrderValidationResult $vr, array $ctx = []): array
    {
        $details = is_array($vr->details) ? $vr->details : [];

        return [
            'ok'      => (bool) $vr->ok,
            'code'    => (string) $vr->code,
            'message' => $this->truncate_string((string) $vr->message, 800),
            'codes'   => $this->sanitize_codes(is_array($vr->codes) ? $vr->codes : []),
            'details' => $this->sanitize_details_for_meta($details),
            'at'      => gmdate('c'),
            'ctx'     => $this->sanitize_meta_ctx($ctx),
        ];
    }

    /**
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private function snapshot_place_result(DistributorOrderResult $or, array $ctx = []): array
    {
        return [
            'ok'      => (bool) $or->ok,
            'code'    => (string) $or->code,
            'message' => $this->truncate_string((string) $or->message, 800),
            'codes'   => $this->sanitize_codes(is_array($or->codes) ? $or->codes : []),
            'http'    => isset($or->http_status) ? (int) $or->http_status : 0,
            'ext_ids' => is_array($or->external_order_ids) ? array_values(array_map('strval', $or->external_order_ids)) : [],
            'at'      => gmdate('c'),
            'ctx'     => $this->sanitize_meta_ctx($ctx),
        ];
    }

    /** @param string[] $codes @return string[] */
    private function sanitize_codes(array $codes): array
    {
        $out = [];
        foreach ($codes as $c) {
            $c = trim((string) $c);
            if ($c !== '') $out[] = $c;
        }
        $out = array_values(array_unique($out));
        if (count($out) > 25) $out = array_slice($out, 0, 25);
        return $out;
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    private function sanitize_details_for_meta(array $details): array
    {
        return $this->sanitize_value($details, 0);
    }

    /** @param mixed $v @return mixed */
    private function sanitize_value($v, int $depth)
    {
        if ($depth >= 2) {
            if (is_array($v)) return ['__truncated__' => true, 'count' => count($v)];
            if (is_string($v)) return $this->truncate_string($v, 300);
            return $v;
        }

        if (is_string($v)) return $this->truncate_string($v, 1200);
        if (is_bool($v) || is_int($v) || is_float($v) || $v === null) return $v;

        if (is_array($v)) {
            $out = [];
            $i = 0;
            foreach ($v as $k => $vv) {
                if ($i >= 25) {
                    $out['__more__'] = true;
                    break;
                }
                $i++;

                $ks = is_string($k) ? $k : (string) $k;
                $k_lc = strtolower($ks);

                if (
                    strpos($k_lc, 'password') !== false ||
                    strpos($k_lc, 'passwd') !== false ||
                    strpos($k_lc, 'token') !== false ||
                    strpos($k_lc, 'secret') !== false ||
                    strpos($k_lc, 'authorization') !== false ||
                    $k_lc === 'auth' ||
                    $k_lc === 'creds'
                ) {
                    $out[$ks] = '[REDACTED]';
                    continue;
                }

                $out[$ks] = $this->sanitize_value($vv, $depth + 1);
            }
            return $out;
        }

        if (is_object($v)) return 'object:' . get_class($v);
        if (is_resource($v)) return 'resource';
        return (string) $v;
    }

    private function truncate_string(string $s, int $max): string
    {
        if ($max <= 0) return '';
        if (strlen($s) <= $max) return $s;
        return substr($s, 0, $max) . '…';
    }

    /** @param array<string,mixed> $ctx @return array<string,mixed> */
    private function sanitize_meta_ctx(array $ctx): array
    {
        $out = [];
        $i = 0;
        foreach ($ctx as $k => $v) {
            if ($i >= 20) {
                $out['__more__'] = true;
                break;
            }
            $i++;

            if (is_array($v)) {
                $out[$k] = (count($v) <= 10) ? $v : array_slice($v, 0, 10);
                continue;
            }
            if (is_string($v)) {
                $out[$k] = $this->truncate_string($v, 250);
                continue;
            }
            if (is_object($v)) {
                $out[$k] = 'object:' . get_class($v);
                continue;
            }

            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * @param mixed $payload_lines
     * @return DistributorOrderLine[]
     */
    private function build_order_lines_from_payload($payload_lines, bool $ffl_required): array
    {
        if (!is_array($payload_lines)) {
            throw new \RuntimeException('Invalid payload: lines must be an array');
        }

        $out = [];
        foreach ($payload_lines as $row) {
            if (!is_array($row)) continue;

            $upc = isset($row['upc']) ? trim((string) $row['upc']) : '';
            $qty = isset($row['qty']) ? (int) $row['qty'] : 0;
            if ($upc === '') continue;

            $out[] = new DistributorOrderLine($upc, $qty, $ffl_required);
        }

        if (empty($out)) {
            throw new \RuntimeException('Invalid payload: no valid order lines');
        }

        return $out;
    }

    /** @return array{0:?DistributorShipTo,1:string} */
    private function resolve_ship_to_ffl_if_needed(WC_Order $order, bool $ffl_required): array
    {
        if (!$ffl_required) return [null, ''];

        // TODO: move this meta key into OrderPlacementKeys later
        $receiving_ffl_number = strtoupper(trim((string) $order->get_meta('fflhub_receiving_ffl_number', true)));
        if ($receiving_ffl_number === '') {
            throw new \RuntimeException('FFL bucket but missing receiving FFL number on order');
        }

        $ship_ffl = CheckoutOrderRequestBuilder::build_ship_to_ffl_or_null(
            $receiving_ffl_number,
            function () { /* silent */
            }
        );

        if (!($ship_ffl instanceof DistributorShipTo)) {
            throw new \RuntimeException('FFL bucket but failed to resolve ship_to_ffl from DB');
        }

        return [$ship_ffl, $receiving_ffl_number];
    }

    private function build_ship_to_customer_from_order(WC_Order $order): ?DistributorShipTo
    {
        $first = trim((string) $order->get_shipping_first_name());
        $last  = trim((string) $order->get_shipping_last_name());
        $name  = trim($first . ' ' . $last);

        if ($name === '') {
            $bf = trim((string) $order->get_billing_first_name());
            $bl = trim((string) $order->get_billing_last_name());
            $name = trim($bf . ' ' . $bl);
        }

        $company  = trim((string) $order->get_shipping_company());
        $address1 = trim((string) $order->get_shipping_address_1());
        $address2 = trim((string) $order->get_shipping_address_2());
        $city     = trim((string) $order->get_shipping_city());
        $state    = trim((string) $order->get_shipping_state());
        $zip      = trim((string) $order->get_shipping_postcode());

        if ($address1 === '' || $city === '' || $state === '' || $zip === '') {
            if ($company === '')  $company  = trim((string) $order->get_billing_company());
            if ($address1 === '') $address1 = trim((string) $order->get_billing_address_1());
            if ($address2 === '') $address2 = trim((string) $order->get_billing_address_2());
            if ($city === '')     $city     = trim((string) $order->get_billing_city());
            if ($state === '')    $state    = trim((string) $order->get_billing_state());
            if ($zip === '')      $zip      = trim((string) $order->get_billing_postcode());
        }

        $phone = trim((string) $order->get_billing_phone());
        $email = trim((string) $order->get_billing_email());

        if ($name === '' || $address1 === '' || $city === '' || $state === '' || $zip === '') {
            return null;
        }

        return new DistributorShipTo($name, $company, $address1, $address2, $city, $state, $zip, $phone, $email);
    }

    private function debug_enabled(): bool
    {
        if (defined(self::DEBUG_CONST)) return (bool) constant(self::DEBUG_CONST);

        $env = getenv(self::DEBUG_CONST);
        if ($env !== false) {
            $env = strtolower(trim((string) $env));
            return in_array($env, ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    /** @param array<string,mixed> $ctx */
    private function debug(string $msg, array $ctx = []): void
    {
        if (!$this->debug_enabled()) return;

        if (!empty($ctx)) {
            error_log(self::LOG_PREFIX . ' ' . $msg . ' ' . wp_json_encode($ctx));
            return;
        }
        error_log(self::LOG_PREFIX . ' ' . $msg);
    }

    private function fail_job(WC_Order $order, string $job_key, string $reason): void
    {
        OrderPlacementJobsStore::mark_job_failed($order, $job_key, $reason);

        // Terminal failures should not show a pending action id
        OrderPlacementJobsStore::clear_job_action_id($order, $job_key);

        error_log(self::LOG_PREFIX . " failed key={$job_key} order=" . (int) $order->get_id() . " reason={$reason}");
    }

    /**
     * Build a canonical merchant PO / external reference.
     *
     * Format:
     *   FH-{DIST}-{ORDER}-{B}{i}
     *   Example: FH-RSR-286-N1
     *
     * @param int|string $order_id
     * @param string $dist_id
     * @param string $bucket      "non" | "ffl"
     * @param int $split_index    default = 1
     */
    private function build_merchant_po($order_id, string $dist_id, string $bucket, int $split_index = 1): string
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            $order_id = 0;
        }

        // Raw distributor id (uppercase, trimmed)
        $dist = strtoupper(trim($dist_id));
        if ($dist === '') {
            $dist = 'DIST';
        }

        // Bucket => single letter
        $b = strtolower(trim($bucket));
        if ($b === 'ffl') {
            $bucket_code = 'F';
        } elseif ($b === 'non') {
            $bucket_code = 'N';
        } else {
            $bucket_code = 'U';
        }

        // Split index
        $i = (int) $split_index;
        if ($i < 1) {
            $i = 1;
        }

        $po = sprintf('FH-%s-%d-%s%d', $dist, $order_id, $bucket_code, $i);

        return $this->sanitize_po($po, 22);
    }

    /**
     * Sanitize PO to allowed chars and max length.
     * Allowed: A–Z a–z 0–9 space dash
     */
    private function sanitize_po(string $po, int $max_len = 22): string
    {
        $po = trim($po);
        if ($po === '') {
            return '';
        }

        // Replace illegal chars with dash
        $po = preg_replace('/[^A-Za-z0-9 \-]+/', '-', $po);
        $po = is_string($po) ? $po : '';

        // Collapse whitespace
        $po = preg_replace('/\s+/', ' ', $po);
        $po = is_string($po) ? trim($po) : '';

        // Collapse repeated dashes
        $po = preg_replace('/\-{2,}/', '-', $po);
        $po = is_string($po) ? trim($po, '-') : '';

        if ($max_len > 0 && strlen($po) > $max_len) {
            $po = substr($po, 0, $max_len);
        }

        return $po;
    }
}
