<?php

declare(strict_types=1);

namespace FFLHub\Checkout\Compliance;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Plugin;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Core\DistributorHandler;

use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;

use FFLHub\Checkout\Builders\CheckoutOrderRequestBuilder;

final class CartCompliance
{
    private const DEBUG_CONST = 'FFLHUB_CART_COMPLIANCE_DEBUG';

    private const SESSION_KEY_RECEIVING_FFL = 'fflhub_receiving_ffl_number';
    private const SESSION_KEY_RECEIVING_FFL_FP = 'fflhub_receiving_ffl_cart_fp';

    public static function init(): void
    {
        add_action('woocommerce_check_cart_items', [__CLASS__, 'validate_cart_for_compliance']);
        add_action('woocommerce_after_checkout_validation', [__CLASS__, 'validate_checkout_submission'], 10, 2);
    }

    public static function validate_cart_for_compliance(): void
    {
        if (!function_exists('WC') || !WC()->cart) {
            self::debug_log('skip: WC/cart not available');
            return;
        }

        $handler = Plugin::instance()->distributor_handler ?? null;
        if (!($handler instanceof DistributorHandler)) {
            self::debug_log('skip: distributor handler missing or wrong type');
            return;
        }

        $checkout_data = [];

        $ship_customer = CheckoutOrderRequestBuilder::build_ship_to_customer_or_null($checkout_data);
        if (!($ship_customer instanceof DistributorShipTo)) {
            return;
        }

        [$receiving_ffl_number, $ship_ffl] = self::resolve_ffl_context(true);

        $blocked = self::run_distributor_validations($handler, $ship_customer, $ship_ffl, $receiving_ffl_number);

        self::emit_blocked_notices($blocked, null);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function validate_checkout_submission(array $data, \WP_Error $errors): void
    {
        if (!function_exists('WC') || !WC()->cart) {
            self::debug_log('checkout validation skip: WC/cart not available');
            return;
        }

        $handler = Plugin::instance()->distributor_handler ?? null;
        if (!($handler instanceof DistributorHandler)) {
            self::debug_log('checkout validation skip: distributor handler missing or wrong type');
            return;
        }

        $ship_customer = CheckoutOrderRequestBuilder::build_ship_to_customer_or_null($data);
        if (!($ship_customer instanceof DistributorShipTo)) {
            self::debug_log('checkout validation skip: customer ship-to incomplete');
            return;
        }

        [$receiving_ffl_number, $ship_ffl] = self::resolve_ffl_context(true);

        $blocked = self::run_distributor_validations($handler, $ship_customer, $ship_ffl, $receiving_ffl_number);

        self::emit_blocked_notices($blocked, $errors);
    }

    /**
     * Session-only resolution (intended).
     *
     * @return array{0:?string,1:?DistributorShipTo}
     */
    private static function resolve_ffl_context(bool $verify_session): array
    {
        $receiving_ffl_number = CheckoutOrderRequestBuilder::resolve_receiving_ffl_number(
            self::SESSION_KEY_RECEIVING_FFL,
            [__CLASS__, 'debug_log']
        );

        if ($verify_session) {
            CheckoutOrderRequestBuilder::persist_receiving_ffl_to_session(
                $receiving_ffl_number,
                self::SESSION_KEY_RECEIVING_FFL,
                [__CLASS__, 'debug_log']
            );
        }

        $ship_ffl = null;

        if ($receiving_ffl_number) {
            $ship_ffl = CheckoutOrderRequestBuilder::build_ship_to_ffl_or_null(
                $receiving_ffl_number,
                [__CLASS__, 'debug_log']
            );

            if (!($ship_ffl instanceof DistributorShipTo)) {
                self::debug_log('receiving FFL provided but DB lookup failed', [
                    'ffl_number' => CheckoutOrderRequestBuilder::dbg_val($receiving_ffl_number),
                ]);
                $ship_ffl = null;
            }
        }

        return [$receiving_ffl_number !== null ? (string) $receiving_ffl_number : null, $ship_ffl];
    }

    /**
     * @param array<int,array{id:string,label:string,message:string,codes:array,details:array,bucket:string,pretty?:array}> $blocked
     */
    private static function emit_blocked_notices(array $blocked, ?\WP_Error $errors = null): void
    {
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
    }

    /**
     * @return array<int,array{id:string,label:string,message:string,codes:array,details:array,bucket:string,pretty?:array}>
     */
    private static function run_distributor_validations(
        DistributorHandler $handler,
        DistributorShipTo $ship_customer,
        ?DistributorShipTo $ship_ffl,
        ?string $receiving_ffl_number
    ): array {
        $by_dist = CheckoutOrderRequestBuilder::build_lines_grouped_by_distributor_from_cart([__CLASS__, 'debug_log']);
        if (empty($by_dist)) {
            self::debug_log('skip: no FFLHub-managed cart items with source distributor');
            return [];
        }

        $enabled_distributors = $handler->get_enabled_distributors_for_validation();
        if (empty($enabled_distributors)) {
            self::debug_log('skip: no enabled distributors with validate_order_request');
            return [];
        }

        // Determine if cart has any FFL-required items (any distributor)
        $cart_has_any_ffl = false;
        foreach ($by_dist as $ctx) {
            if (!empty($ctx['has_ffl'])) {
                $cart_has_any_ffl = true;
                break;
            }
        }

        // --- stale session guard (requires the fp to have been set at selection-time) ---
        $fp_now = CheckoutOrderRequestBuilder::current_cart_ffl_fingerprint();
        $fp_set = (function_exists('WC') && WC()->session) ? (string) WC()->session->get(self::SESSION_KEY_RECEIVING_FFL_FP) : '';

        if ($cart_has_any_ffl) {
            if ($fp_now !== '' && $fp_set === '') {
                self::debug_log('ffl fp missing (cart has ffl) -> treating session ffl as missing', [
                    'ffl_in_session' => CheckoutOrderRequestBuilder::dbg_val($receiving_ffl_number),
                    'fp_now_tail8' => substr($fp_now, -8),
                ]);
                $receiving_ffl_number = null;
                $ship_ffl = null;
            }

            if ($fp_now !== '' && $fp_set !== '' && !hash_equals($fp_set, $fp_now)) {
                self::debug_log('ffl fp mismatch -> treating session ffl as missing', [
                    'ffl_in_session' => CheckoutOrderRequestBuilder::dbg_val($receiving_ffl_number),
                    'fp_set_tail8' => substr($fp_set, -8),
                    'fp_now_tail8' => substr($fp_now, -8),
                ]);
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
                self::debug_log('skip DOR (dest_state invalid)', [
                    'cart_dist_id' => $cart_dist_id,
                    'dest_state'   => $dest_state,
                ]);
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

            foreach ($enabled_distributors as $voter_id => $voter) {
                $dist = $voter['instance'] ?? null;
                if (!($dist instanceof DistributorBase)) {
                    continue;
                }

                $label = isset($voter['label']) ? (string) $voter['label'] : (string) $voter_id;

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

                $vr = self::call_validate($dist, $voter_req);
                if (!($vr instanceof DistributorOrderValidationResult)) {
                    continue;
                }

                if ($vr->ok) {
                    continue;
                }

                // ✅ NEW: ignore "out of stock" votes unless the voter is the cart's source distributor.
                if (self::should_ignore_vote_for_cart_source($cart_dist_id, (string) $voter_id, $vr)) {
                    self::debug_log('ignored vote (OOS on non-source voter)', [
                        'cart_dist_id' => $cart_dist_id,
                        'voter_id'     => (string) $voter_id,
                        'codes'        => implode(',', is_array($vr->codes) ? $vr->codes : []),
                    ]);
                    continue;
                }

                // ✅ vague only for system-ish failures; otherwise show pretty restriction messaging
                if (self::should_use_vague_customer_message($vr)) {
                    $blocked[] = [
                        'id'      => (string) $voter_id,
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

                $buckets = self::resolve_buckets_for_pretty($vr, $voter_req);

                foreach ($buckets as $bucket) {
                    $pretty_msgs = CheckoutOrderRequestBuilder::build_pretty_validation_messages(
                        $label,
                        $vr,
                        $bucket,
                        $ship_customer,
                        $ship_ffl
                    );

                    $blocked[] = [
                        'id'      => (string) $voter_id,
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
    }

    private static function should_use_vague_customer_message(DistributorOrderValidationResult $vr): bool
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
    private static function resolve_buckets_for_pretty(
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

    private static function call_validate(
        DistributorBase $dist,
        DistributorOrderRequest $req
    ): ?DistributorOrderValidationResult {
        try {
            $vr = $dist->validate_order_request($req);
        } catch (\Throwable $e) {
            self::debug_log('validate_order_request threw exception', [
                'id'    => $dist->get_id(),
                'error' => $e->getMessage(),
            ]);

            return new DistributorOrderValidationResult(
                false,
                'Validation error: ' . $e->getMessage(),
                ['FFLHUB_VALIDATE_EXCEPTION'],
                []
            );
        }

        self::debug_log('validation result', [
            'id'      => $dist->get_id(),
            'ok'      => $vr->ok ? 'true' : 'false',
            'message' => $vr->message,
            'codes'   => implode(',', is_array($vr->codes) ? $vr->codes : []),
        ]);

        return $vr;
    }

    private static function debug_enabled(): bool
    {
        if (defined(self::DEBUG_CONST)) {
            return (bool) constant(self::DEBUG_CONST);
        }

        $env = getenv(self::DEBUG_CONST);
        if ($env !== false) {
            $env = strtolower(trim((string) $env));
            return in_array($env, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function debug_log(string $msg, array $context = []): void
    {
        if (!self::debug_enabled()) {
            return;
        }

        $prefix = '[FFLHub CartCompliance] ';
        if (!empty($context)) {
            error_log($prefix . $msg . ' ' . wp_json_encode($context));
            return;
        }

        error_log($prefix . $msg);
    }



    /**
     * Returns true if this validation result represents an out-of-stock condition.
     *
     * We detect via machine codes (preferred) and also tolerate common variations.
     */
    private static function is_out_of_stock_vote(DistributorOrderValidationResult $vr): bool
    {
        $codes = is_array($vr->codes) ? $vr->codes : [];
        foreach ($codes as $c) {
            $c = strtoupper(trim((string) $c));
            if ($c === '') {
                continue;
            }

            // Common patterns you may already use across distributors
            // (keep broad so it works across RSR/Lipseys/etc without hardcoding every code)
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

        // Optional: if you ever put a structured hint in details
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
    private static function should_ignore_vote_for_cart_source(
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
        if ($cart_dist_id !== $voter_id && self::is_out_of_stock_vote($vr)) {
            return true;
        }

        return false;
    }
}
