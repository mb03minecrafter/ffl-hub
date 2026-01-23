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

/**
 * Cart + checkout validation for distributor compliance rules.
 *
 * Design:
 * - Build ONE DistributorOrderRequest (DOR) per distributor from the cart
 * - Do NOT split into FFL/non-FFL here (distributors split internally via DOR->ffl_lines()/non_ffl_lines())
 * - If cart contains ANY FFL-required lines for a distributor, we must have:
 *     - receiving_ffl_number
 *     - ship_to_ffl (resolved from imported FFL table)
 *   Otherwise: block checkout (do not "pass" validation by validating only accessories).
 */
final class CartCompliance
{
    /**
     * Toggle debug logs.
     *
     * Enable by setting:
     *   define('FFLHUB_CART_COMPLIANCE_DEBUG', true);
     * in wp-config.php (preferred), OR set env var:
     *   FFLHUB_CART_COMPLIANCE_DEBUG=1
     */
    private const DEBUG_CONST = 'FFLHUB_CART_COMPLIANCE_DEBUG';

    /**
     * WC session key (set by CheckoutFields::handle_set_additional_field_value()).
     */
    private const SESSION_KEY_RECEIVING_FFL = 'fflhub_receiving_ffl_number';

    public static function init(): void
    {
        // Cart + many checkout flows (classic + some Blocks paths).
        add_action('woocommerce_check_cart_items', [__CLASS__, 'validate_cart_for_compliance']);

        // Blocks-safe: hard-stop checkout submission.
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

        // Cart page: generally no checkout payload; use WC()->customer values where possible.
        $checkout_data = [];

        $ship_customer = CheckoutOrderRequestBuilder::build_ship_to_customer_or_null($checkout_data);
        if (!($ship_customer instanceof DistributorShipTo)) {
            // Customer hasn't entered enough shipping info yet (common on cart page).
            return;
        }

        [$receiving_ffl_number, $ship_ffl] = self::resolve_ffl_context($checkout_data, false);

        $blocked = self::run_distributor_validations($handler, $ship_customer, $ship_ffl, $receiving_ffl_number);

        self::emit_blocked_notices($blocked, null);
    }

    /**
     * Woo passes $data (array) and $errors (WP_Error) here.
     *
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

        $checkout_data = $data;

        $ship_customer = CheckoutOrderRequestBuilder::build_ship_to_customer_or_null($checkout_data);
        if (!($ship_customer instanceof DistributorShipTo)) {
            self::debug_log('checkout validation skip: customer ship-to incomplete');
            return;
        }

        // Resolve (session-first in your new builder) + persist for later cart-page validation.
        [$receiving_ffl_number, $ship_ffl] = self::resolve_ffl_context($checkout_data, true);

        $blocked = self::run_distributor_validations($handler, $ship_customer, $ship_ffl, $receiving_ffl_number);

        self::emit_blocked_notices($blocked, $errors);
    }

    /**
     * Resolve receiving FFL number + ship_to_ffl (if present/valid).
     *
     * @param array<string,mixed> $checkout_data
     * @return array{0:?string,1:?DistributorShipTo}
     */
    private static function resolve_ffl_context(array $checkout_data, bool $persist_to_session): array
    {
        // NOTE: You changed CheckoutOrderRequestBuilder::resolve_receiving_ffl_number() to session-only.
        $receiving_ffl_number = CheckoutOrderRequestBuilder::resolve_receiving_ffl_number(
            self::SESSION_KEY_RECEIVING_FFL,
            [__CLASS__, 'debug_log']
        );

        if ($persist_to_session) {
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
        } else {
            self::debug_log('no receiving FFL resolved', [
                'session_ffl' => CheckoutOrderRequestBuilder::dbg_val(
                    CheckoutOrderRequestBuilder::get_session_receiving_ffl_number(self::SESSION_KEY_RECEIVING_FFL)
                ),
                'data_keys' => !empty($checkout_data) ? implode(',', array_keys($checkout_data)) : '',
            ]);
        }

        return [$receiving_ffl_number !== null ? (string) $receiving_ffl_number : null, $ship_ffl];
    }

    /**
     * Emit blocked messages as WC notices, and (when possible) also attach to checkout errors object.
     *
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

                    // Some checkout flows (esp Blocks) prefer errors->add().
                    if ($errors instanceof \WP_Error) {
                        $errors->add('fflhub_cart_compliance', $msg);
                    }
                }
                continue;
            }

            $fallback = sprintf(
                __('Checkout blocked by %1$s validation: %2$s', 'ffl-hub'),
                (string) ($b['label'] ?? $b['id']),
                (string) ($b['message'] ?? 'Validation failed')
            );

            wc_add_notice($fallback, 'error');
            if ($errors instanceof \WP_Error) {
                $errors->add('fflhub_cart_compliance', $fallback);
            }
        }
    }

    /**
     * One DOR per cart distributor (no split here).
     *
     * NEW:
     * - For each cart distributor DOR, validate it against ALL enabled distributors.
     *
     * If any cart distributor has FFL-required lines and we can't resolve receiving FFL:
     *   - block checkout with a clear message
     *   - do NOT call any distributor validations for that DOR (otherwise we'd validate only accessories)
     *
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

        // Pre-resolve the list of enabled distributor instances once.
        $enabled_distributors = $handler->get_enabled_distributors_for_validation();

        if (empty($enabled_distributors)) {
            self::debug_log('skip: no enabled distributors with validate_order_request');
            return [];
        }

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
                    'message' => 'This cart contains items that must ship to a receiving FFL. Please select a receiving FFL to continue checkout.',
                    'codes'   => ['FFLHUB_RECEIVING_FFL_REQUIRED'],
                    'details' => [
                        'has_ffl_lines'      => true,
                        'ffl_number_present' => $receiving_ffl_number ? 1 : 0,
                        'ship_ffl_present'   => ($ship_ffl instanceof DistributorShipTo) ? 1 : 0,
                    ],
                    'bucket'  => 'ffl_missing',
                    'pretty'  => [
                        'This cart contains items that must ship to a receiving FFL. Please select a receiving FFL to continue checkout.',
                    ],
                ];
                continue;
            }

            // Choose destination state context.
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

            // Build the DOR ONCE, then feed to all distributors.
            $req = new DistributorOrderRequest(
                $lines,
                $ship_customer,
                ($ship_ffl instanceof DistributorShipTo) ? $ship_ffl : null,
                $merchant_order_id,
                $dest_state,
                $receiving_ffl_number ? (string) $receiving_ffl_number : '',
                'FFLHub checkout validation (single DOR; validate against all distributors)'
            );

            // Validate this DOR against ALL enabled distributors.
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
                    // If validation hard-failed and we returned null, skip silently.
                    // (If you prefer: convert this into a block with a generic message.)
                    continue;
                }

                if ($vr->ok) {
                    continue;
                }

                // ✅ No “result-to-array” helper: use the object directly.
                $message = ($vr->message !== '' ? (string) $vr->message : 'Validation failed');
                $codes   = is_array($vr->codes) ? $vr->codes : [];
                $details = is_array($vr->details) ? $vr->details : [];

                $pretty_msgs = CheckoutOrderRequestBuilder::build_pretty_validation_messages(
                    $label,
                    $vr,
                    'single',
                    $ship_customer,
                    $ship_ffl
                );

                $blocked[] = [
                    'id'      => (string) $voter_id,
                    'label'   => $label,
                    'message' => $message,
                    'codes'   => $codes,
                    'details' => $details,
                    'bucket'  => 'single',
                    'pretty'  => $pretty_msgs,
                ];
            }
        }

        return $blocked;
    }

    /**
     * Validate against a distributor, returning the raw validation result object (or null).
     *
     * Keeping try/catch here prevents a single distributor exception from hard-fataling checkout.
     */
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

            // Best-effort: return a blocked result (so the batch fails).
            $synthetic = new DistributorOrderValidationResult(
                false, 
                'Validation error: ' . $e->getMessage(), 
                ['FFLHUB_VALIDATE_EXCEPTION'], 
                []);
            
            return $synthetic;
        }

        self::debug_log('validation result', [
            'id'      => $dist->get_id(),
            'ok'      => $vr->ok ? 'true' : 'false',
            'message' => $vr->message,
            'codes'   => implode(',', is_array($vr->codes) ? $vr->codes : []),
        ]);

        return $vr;
    }

    /* ---------------- Debug helpers ---------------- */

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
}
