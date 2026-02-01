<?php

declare(strict_types=1);

namespace FFLHub\Checkout\Compliance;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Core\DistributorHandler;

use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;

use FFLHub\Checkout\Builders\CheckoutOrderRequestBuilder;

use FFLHub\FFL\Tables\FFLTable;

final class CartCompliance
{
    private const SESSION_KEY_RECEIVING_FFL    = 'fflhub_receiving_ffl_number';
    private const SESSION_KEY_RECEIVING_FFL_FP = 'fflhub_receiving_ffl_cart_fp';

    // -----------------------------
    // PROFILING (unchanged behavior)
    // -----------------------------
    private const PROFILING_ENV  = 'FFLHUB_CART_COMPLIANCE_PROFILE';
    private const PROFILE_PREFIX = '[FFLHub CartCompliance PROFILE] ';

    /**
     * @var array<string,array{t0:float,mem0:int}>
     */
    private static array $profile_spans = [];

    private FFLTable $ffl_table;
    private DistributorHandler $handler;

    /** @var string */
    private string $run_id = '';

    public function __construct(FFLTable $ffl_table, DistributorHandler $handler)
    {
        $this->ffl_table = $ffl_table;
        $this->handler   = $handler;
    }

    /**
     * Wire hooks (instance-based).
     *
     * IMPORTANT: caller should create ONE instance and call register().
     */
    public function register(): void
    {
        add_action(
            'woocommerce_store_api_checkout_update_order_meta',
            [$this, 'storeapi_checkout_gate'],
            1000,
            1
        );
    }

    /* ---------------- Profiling helpers ---------------- */

    private static function profiling_enabled(): bool
    {
        if (defined(self::PROFILING_ENV)) {
            return (bool) constant(self::PROFILING_ENV);
        }

        $env = getenv(self::PROFILING_ENV);
        if ($env !== false) {
            $env = strtolower(trim((string) $env));
            return in_array($env, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function prof_start(string $span, array $context = []): void
    {
        if (!self::profiling_enabled()) {
            return;
        }

        self::$profile_spans[$span] = [
            't0'   => microtime(true),
            'mem0' => function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0,
        ];

        if (!empty($context)) {
            error_log(self::PROFILE_PREFIX . "START {$span} " . wp_json_encode($context));
        } else {
            error_log(self::PROFILE_PREFIX . "START {$span}");
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function prof_end(string $span, array $context = []): void
    {
        if (!self::profiling_enabled()) {
            return;
        }

        $t1   = microtime(true);
        $mem1 = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        $t0   = isset(self::$profile_spans[$span]['t0']) ? (float) self::$profile_spans[$span]['t0'] : null;
        $mem0 = isset(self::$profile_spans[$span]['mem0']) ? (int) self::$profile_spans[$span]['mem0'] : 0;

        $ms   = ($t0 !== null) ? (int) round(($t1 - $t0) * 1000.0) : -1;
        $dmem = $mem1 - $mem0;

        $payload = array_merge($context, [
            'ms'        => $ms,
            'mem_delta' => $dmem,
            'mem_now'   => $mem1,
        ]);

        error_log(self::PROFILE_PREFIX . "END {$span} " . wp_json_encode($payload));

        unset(self::$profile_spans[$span]);
    }

    /**
     * @template T
     * @param callable():T $fn
     * @param array<string,mixed> $context
     * @return T
     */
    private static function prof(string $span, callable $fn, array $context = [])
    {
        self::prof_start($span, $context);
        try {
            return $fn();
        } finally {
            self::prof_end($span, $context);
        }
    }

    /* ---------------- Hook gates ---------------- */

    private function is_real_checkout_submit_request(): bool
    {
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
        if ($method !== 'POST') {
            return false;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        // Store API checkout endpoint used by Blocks
        return str_contains($uri, '/wp-json/wc/store/v1/checkout');
    }

    public function storeapi_checkout_gate(\WC_Order $order): void
    {
        // unique id for this request execution
        $this->run_id = gmdate('His') . '-' . substr(sha1((string) microtime(true)), 0, 6);

        if (!$this->is_real_checkout_submit_request()) {
            return;
        }

        try {
            $this->validate_cart_for_compliance();
        } catch (\Throwable $e) {
            $order->add_order_note('FFLHub: Checkout blocked by compliance validation: ' . $e->getMessage());
            $order->save();
            throw $e;
        }
    }

    /* ---------------- Entry points ---------------- */

    public function validate_cart_for_compliance(): void
    {
        self::prof_start('validate_cart_for_compliance.total');

        try {
            if (!function_exists('WC') || !WC()->cart) {
                return;
            }

            $handler = $this->handler;
            $checkout_data = [];

            $ship_customer = self::prof(
                'build_ship_to_customer_or_null(cart)',
                function () use ($checkout_data) {
                    return CheckoutOrderRequestBuilder::build_ship_to_customer_or_null($checkout_data);
                }
            );

            if (!($ship_customer instanceof DistributorShipTo)) {
                return;
            }

            [$receiving_ffl_number, $ship_ffl] = self::prof(
                'resolve_ffl_context(cart)',
                function () {
                    return $this->resolve_ffl_context(true);
                }
            );

            $blocked = self::prof(
                'run_distributor_validations(cart)',
                function () use ($handler, $ship_customer, $ship_ffl, $receiving_ffl_number) {
                    return $this->run_distributor_validations($handler, $ship_customer, $ship_ffl, $receiving_ffl_number);
                }
            );

            self::prof(
                'emit_blocked_notices(cart)',
                function () use ($blocked) {
                    $this->emit_blocked_notices($blocked, null);
                    return null;
                },
                ['blocked_count' => is_array($blocked) ? count($blocked) : 0]
            );
        } finally {
            self::prof_end('validate_cart_for_compliance.total');
        }
    }

    /**
     * Session-only resolution (intended).
     *
     * @return array{0:?string,1:?DistributorShipTo}
     */
    private function resolve_ffl_context(bool $verify_session): array
    {
        self::prof_start('resolve_ffl_context.total', ['verify_session' => $verify_session ? '1' : '0']);

        try {
            $receiving_ffl_number = self::prof(
                'resolve_receiving_ffl_number',
                function () {
                    return CheckoutOrderRequestBuilder::resolve_receiving_ffl_number(
                        self::SESSION_KEY_RECEIVING_FFL,
                        null
                    );
                }
            );

            if ($verify_session) {
                CheckoutOrderRequestBuilder::persist_receiving_ffl_to_session(
                    $receiving_ffl_number,
                    self::SESSION_KEY_RECEIVING_FFL,
                    null
                );
            }

            $ship_ffl = null;

            if ($receiving_ffl_number) {
                $ship_ffl = CheckoutOrderRequestBuilder::build_ship_to_ffl_or_null(
                    $this->ffl_table,
                    (string) $receiving_ffl_number,
                    null
                );

                if (!($ship_ffl instanceof DistributorShipTo)) {
                    $ship_ffl = null;
                }
            }

            return [$receiving_ffl_number !== null ? (string) $receiving_ffl_number : null, $ship_ffl];
        } finally {
            self::prof_end('resolve_ffl_context.total');
        }
    }

    /**
     * @param array<int,array{id:string,label:string,message:string,codes:array,details:array,bucket:string,pretty?:array}> $blocked
     */
    private function emit_blocked_notices(array $blocked, ?\WP_Error $errors = null): void
    {
        self::prof_start('emit_blocked_notices.total', [
            'blocked_count' => is_array($blocked) ? count($blocked) : 0,
        ]);

        try {
            foreach ($blocked as $b) {
                $pretty = isset($b['pretty']) && is_array($b['pretty']) ? $b['pretty'] : [];

                if (!empty($pretty)) {
                    foreach ($pretty as $msg) {
                        $msg = (string) $msg;
                        wc_add_notice($msg, 'error');
                        if ($errors instanceof \WP_Error) {
                            $errors->add('fflhub_cart_compliance', $msg);
                        }
                    }
                    continue;
                }

                $fallback = __('We couldn’t validate one or more items in your cart. Please contact us for help.', 'ffl-hub');
                wc_add_notice($fallback, 'error');
                if ($errors instanceof \WP_Error) {
                    $errors->add('fflhub_cart_compliance', $fallback);
                }
            }
        } finally {
            self::prof_end('emit_blocked_notices.total');
        }
    }

    /**
     * @return array<int,array{id:string,label:string,message:string,codes:array,details:array,bucket:string,pretty?:array}>
     */
    private function run_distributor_validations(
        DistributorHandler $handler,
        DistributorShipTo $ship_customer,
        ?DistributorShipTo $ship_ffl,
        ?string $receiving_ffl_number
    ): array {
        self::prof_start('run_distributor_validations.total');

        try {
            $by_dist = self::prof(
                'build_lines_grouped_by_distributor_from_cart',
                function () {
                    return CheckoutOrderRequestBuilder::build_lines_grouped_by_distributor_from_cart();
                }
            );

            if (empty($by_dist)) {
                return [];
            }

            $enabled_distributors = self::prof(
                'get_enabled_distributors_for_validation',
                function () use ($handler) {
                    return $handler->get_enabled_distributors_for_validation();
                }
            );

            if (empty($enabled_distributors)) {
                return [];
            }

            $cart_has_any_ffl = false;
            foreach ($by_dist as $ctx) {
                if (!empty($ctx['has_ffl'])) {
                    $cart_has_any_ffl = true;
                    break;
                }
            }

            // --- stale session guard ---
            $fp_now = self::prof('current_cart_ffl_fingerprint', function () {
                return CheckoutOrderRequestBuilder::current_cart_ffl_fingerprint();
            });

            $fp_set = self::prof('read_session_ffl_fp', function () {
                return (function_exists('WC') && WC()->session)
                    ? (string) WC()->session->get(self::SESSION_KEY_RECEIVING_FFL_FP)
                    : '';
            });

            if ($cart_has_any_ffl) {
                if ($fp_now !== '' && $fp_set === '') {
                    $receiving_ffl_number = null;
                    $ship_ffl = null;
                }

                if ($fp_now !== '' && $fp_set !== '' && !hash_equals($fp_set, $fp_now)) {
                    $receiving_ffl_number = null;
                    $ship_ffl = null;
                }
            }
            // ------------------------------------------------------------------------------

            $blocked = [];

            foreach ($by_dist as $cart_dist_id => $ctx) {
                $cart_dist_id = strtolower(trim((string) $cart_dist_id));
                if ($cart_dist_id === '') {
                    continue;
                }

                $lines   = isset($ctx['lines']) && is_array($ctx['lines']) ? $ctx['lines'] : [];
                $has_ffl = !empty($ctx['has_ffl']);

                if (empty($lines)) {
                    continue;
                }

                // If there are FFL-required lines, require receiving FFL + ship_to_ffl.
                if ($has_ffl && (!$receiving_ffl_number || !($ship_ffl instanceof DistributorShipTo))) {
                    $blocked[] = [
                        'id'      => $cart_dist_id,
                        'label'   => $cart_dist_id,
                        'message' => 'FFL required but not selected',
                        'codes'   => ['FFLHUB_RECEIVING_FFL_REQUIRED'],
                        'details' => [],
                        'bucket'  => 'ffl_missing',
                        'pretty'  => [
                            __('This cart contains items that must ship to a receiving FFL. Please select a receiving FFL to continue checkout.', 'ffl-hub'),
                        ],
                    ];
                    continue;
                }

                $dest_state = strtoupper(trim((string) (
                    ($has_ffl && $ship_ffl instanceof DistributorShipTo) ? $ship_ffl->state : $ship_customer->state
                )));

                if (!preg_match('/^[A-Z]{2}$/', $dest_state)) {
                    continue;
                }

                $merchant_order_id = CheckoutOrderRequestBuilder::current_merchant_order_id($cart_dist_id);

                $req = new DistributorOrderRequest(
                    $lines,
                    $ship_customer,
                    ($ship_ffl instanceof DistributorShipTo) ? $ship_ffl : null,
                    $merchant_order_id,
                    $dest_state,
                    $receiving_ffl_number ? (string) $receiving_ffl_number : '',
                    'FFLHub checkout validation (single DOR; validate against all distributors)'
                );

                // Vote across all validators
                foreach ($enabled_distributors as $voter_id => $voter) {
                    $voter_id_str = (string) $voter_id;

                    $dist = $voter['instance'] ?? null;
                    if (!($dist instanceof DistributorBase)) {
                        continue;
                    }

                    $label = isset($voter['label']) ? (string) $voter['label'] : $voter_id_str;

                    $lines_for_voter = method_exists($dist, 'filter_lines_for_validation')
                        ? $dist->filter_lines_for_validation($req->valid_lines())
                        : $req->valid_lines();

                    if (empty($lines_for_voter)) {
                        continue;
                    }

                    $voter_req = new DistributorOrderRequest(
                        $lines_for_voter,
                        $req->ship_to_customer,
                        $req->ship_to_ffl,
                        $req->merchant_order_id,
                        $req->dest_state,
                        $req->receiving_ffl_number,
                        $req->notes
                    );

                    $vr = $this->call_validate($dist, $voter_req);
                    if (!($vr instanceof DistributorOrderValidationResult)) {
                        continue;
                    }

                    if ($vr->ok) {
                        continue;
                    }

                    // Ignore out-of-stock blocks unless the voter is the cart's source distributor.
                    if ($this->should_ignore_vote_for_cart_source($cart_dist_id, $voter_id_str, $vr)) {
                        continue;
                    }

                    // Vague message for system-ish failures; otherwise show pretty restriction messaging
                    if ($this->should_use_vague_customer_message($vr)) {
                        $blocked[] = [
                            'id'      => $voter_id_str,
                            'label'   => $label,
                            'message' => (string) ($vr->message ?: 'Validation failed'),
                            'codes'   => is_array($vr->codes) ? $vr->codes : [],
                            'details' => [],
                            'bucket'  => 'single',
                            'pretty'  => [
                                __('We couldn’t validate one or more items in your cart. Please contact us for help.', 'ffl-hub'),
                            ],
                        ];
                        continue;
                    }

                    $buckets = $this->resolve_buckets_for_pretty($vr, $voter_req);

                    foreach ($buckets as $bucket) {
                        $pretty_msgs = CheckoutOrderRequestBuilder::build_pretty_validation_messages(
                            $label,
                            $vr,
                            $bucket,
                            $ship_customer,
                            $ship_ffl
                        );

                        $blocked[] = [
                            'id'      => $voter_id_str,
                            'label'   => $label,
                            'message' => (string) ($vr->message ?: 'Validation failed'),
                            'codes'   => is_array($vr->codes) ? $vr->codes : [],
                            'details' => is_array($vr->details) ? $vr->details : [],
                            'bucket'  => $bucket,
                            'pretty'  => $pretty_msgs,
                        ];
                    }
                }
            }

            return $blocked;
        } finally {
            self::prof_end('run_distributor_validations.total');
        }
    }

    private function should_use_vague_customer_message(DistributorOrderValidationResult $vr): bool
    {
        $codes = is_array($vr->codes) ? $vr->codes : [];
        $msg   = strtolower((string) ($vr->message ?? ''));

        if (in_array('FFLHUB_VALIDATE_EXCEPTION', $codes, true)) {
            return true;
        }

        foreach ($codes as $c) {
            $c = (string) $c;
            if (stripos($c, 'TIMEOUT') !== false) return true;
            if (stripos($c, 'NETWORK') !== false) return true;
            if (stripos($c, 'REMOTE') !== false) return true;
            if (stripos($c, 'HTTP_5') !== false) return true;
            if (stripos($c, 'SERVER_ERROR') !== false) return true;
            if (stripos($c, 'TEMP') !== false) return true;
        }

        if (str_contains($msg, 'exception')) return true;
        if (str_contains($msg, 'timeout')) return true;
        if (str_contains($msg, 'temporar')) return true;
        if (str_contains($msg, 'try again')) return true;

        return false;
    }

    /**
     * @return string[] each of: 'non'|'ffl'
     */
    private function resolve_buckets_for_pretty(
        DistributorOrderValidationResult $vr,
        DistributorOrderRequest $voter_req
    ): array {
        $details = is_array($vr->details) ? $vr->details : [];

        $out = [];
        if (isset($details['non']) && is_array($details['non'])) {
            $out[] = 'non';
        }
        if (isset($details['ffl']) && is_array($details['ffl'])) {
            $out[] = 'ffl';
        }

        if (!empty($out)) {
            return array_values(array_unique($out));
        }

        $has_non = !empty($voter_req->non_ffl_lines());
        $has_ffl = !empty($voter_req->ffl_lines());

        if ($has_ffl && !$has_non) return ['ffl'];
        if ($has_non && !$has_ffl) return ['non'];

        return ['non', 'ffl'];
    }

    private function call_validate(
        DistributorBase $dist,
        DistributorOrderRequest $req
    ): ?DistributorOrderValidationResult {
        try {
            return $dist->validate_order_request($req);
        } catch (\Throwable $e) {
            return new DistributorOrderValidationResult(
                false,
                'Validation error: ' . $e->getMessage(),
                ['FFLHUB_VALIDATE_EXCEPTION'],
                []
            );
        }
    }

    /**
     * Returns true if this validation result represents an out-of-stock condition.
     */
    private function is_out_of_stock_vote(DistributorOrderValidationResult $vr): bool
    {
        $codes = is_array($vr->codes) ? $vr->codes : [];
        foreach ($codes as $c) {
            $c = strtoupper(trim((string) $c));
            if ($c === '') {
                continue;
            }

            if (
                str_contains($c, 'OUT_OF_STOCK') ||
                str_contains($c, 'OOS') ||
                str_contains($c, 'NO_STOCK') ||
                str_contains($c, 'INSUFFICIENT_STOCK') ||
                str_contains($c, 'QTY_UNAVAILABLE')
            ) {
                return true;
            }
        }

        $details = is_array($vr->details) ? $vr->details : [];
        if (isset($details['reason'])) {
            $r = strtoupper(trim((string) $details['reason']));
            if ($r === 'OUT_OF_STOCK' || $r === 'OOS') {
                return true;
            }
        }

        return false;
    }

    /**
     * For non-source voters, ignore OOS blocks entirely.
     */
    private function should_ignore_vote_for_cart_source(
        string $cart_dist_id,
        string $voter_id,
        DistributorOrderValidationResult $vr
    ): bool {
        $cart_dist_id = strtolower(trim($cart_dist_id));
        $voter_id     = strtolower(trim($voter_id));

        if ($cart_dist_id === '' || $voter_id === '') {
            return false;
        }

        // Only ignore OUT_OF_STOCK when voter is NOT the source distributor.
        if ($cart_dist_id !== $voter_id && $this->is_out_of_stock_vote($vr)) {
            return true;
        }

        return false;
    }
}
