<?php

namespace FFLHub\Checkout;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Plugin;
use FFLHub\Settings\Options;
use FFLHub\Distributor\Product\DistributorOrderRequest;
use FFLHub\Distributor\Product\DistributorShipTo;
use FFLHub\Distributor\Product\DistributorOrderValidationResult;

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

    /**
     * Blocks Additional Checkout Fields API field id.
     */
    private const FIELD_ID_RECEIVING_FFL = 'ffl-hub/receiving-ffl';

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
        if (!$handler || !method_exists($handler, 'get_distributor_by_id')) {
            self::debug_log('skip: distributor handler missing');
            return;
        }

        // Cart page: generally no checkout payload; use WC()->customer values where possible.
        $checkout_data = [];

        $ship_customer = CheckoutOrderRequestBuilder::build_ship_to_customer_or_null($checkout_data);
        if (!($ship_customer instanceof DistributorShipTo)) {
            self::debug_log('skip: customer ship-to incomplete (cart page)');
            return;
        }

        $receiving_ffl_number = CheckoutOrderRequestBuilder::resolve_receiving_ffl_number(
            $checkout_data,
            self::FIELD_ID_RECEIVING_FFL,
            self::SESSION_KEY_RECEIVING_FFL,
            [__CLASS__, 'debug_log']
        );

        $ship_ffl = null;
        if ($receiving_ffl_number) {
            $ship_ffl = CheckoutOrderRequestBuilder::build_ship_to_ffl_or_null($receiving_ffl_number, [__CLASS__, 'debug_log']);
            if (!($ship_ffl instanceof DistributorShipTo)) {
                self::debug_log('cart validation: receiving FFL present but DB lookup failed', [
                    'ffl_number' => CheckoutOrderRequestBuilder::dbg_val($receiving_ffl_number),
                ]);
                $ship_ffl = null;
            }
        } else {
            self::debug_log('cart validation: no receiving FFL resolved', [
                'session_ffl' => CheckoutOrderRequestBuilder::dbg_val(
                    CheckoutOrderRequestBuilder::get_session_receiving_ffl_number(self::SESSION_KEY_RECEIVING_FFL)
                ),
            ]);
        }

        $blocked = self::run_distributor_validations($handler, $ship_customer, $ship_ffl, $receiving_ffl_number);

        foreach ($blocked as $b) {
            $pretty = isset($b['pretty']) && is_array($b['pretty']) ? $b['pretty'] : [];

            if (!empty($pretty)) {
                foreach ($pretty as $msg) {
                    wc_add_notice((string) $msg, 'error');
                }
                continue;
            }

            // fallback
            wc_add_notice(
                sprintf(
                    __('Checkout blocked by %1$s validation: %2$s', 'ffl-hub'),
                    (string) ($b['label'] ?? $b['id']),
                    (string) ($b['message'] ?? 'Validation failed')
                ),
                'error'
            );
        }
    }

    public static function validate_checkout_submission($data, $errors): void
    {
        if (!function_exists('WC') || !WC()->cart) {
            self::debug_log('checkout validation skip: WC/cart not available');
            return;
        }

        $handler = Plugin::instance()->distributor_handler ?? null;
        if (!$handler || !method_exists($handler, 'get_distributor_by_id')) {
            self::debug_log('checkout validation skip: distributor handler missing');
            return;
        }

        $checkout_data = is_array($data) ? $data : [];

        $ship_customer = CheckoutOrderRequestBuilder::build_ship_to_customer_or_null($checkout_data);
        if (!($ship_customer instanceof DistributorShipTo)) {
            self::debug_log('checkout validation skip: customer ship-to incomplete');
            return;
        }

        // Resolve from $data/POST/REQUEST/session (in that order).
        $receiving_ffl_number = CheckoutOrderRequestBuilder::resolve_receiving_ffl_number(
            $checkout_data,
            self::FIELD_ID_RECEIVING_FFL,
            self::SESSION_KEY_RECEIVING_FFL,
            [__CLASS__, 'debug_log']
        );

        // Persist for later cart-page validation.
        CheckoutOrderRequestBuilder::persist_receiving_ffl_to_session(
            $receiving_ffl_number,
            self::SESSION_KEY_RECEIVING_FFL,
            [__CLASS__, 'debug_log']
        );

        $ship_ffl = null;
        if ($receiving_ffl_number) {
            $ship_ffl = CheckoutOrderRequestBuilder::build_ship_to_ffl_or_null($receiving_ffl_number, [__CLASS__, 'debug_log']);
            if (!($ship_ffl instanceof DistributorShipTo)) {
                self::debug_log('checkout validation: receiving FFL provided but DB lookup failed', [
                    'ffl_number' => CheckoutOrderRequestBuilder::dbg_val($receiving_ffl_number),
                ]);
                $ship_ffl = null;
            }
        } else {
            self::debug_log('checkout validation: no receiving FFL resolved', [
                'session_ffl' => CheckoutOrderRequestBuilder::dbg_val(
                    CheckoutOrderRequestBuilder::get_session_receiving_ffl_number(self::SESSION_KEY_RECEIVING_FFL)
                ),
                'data_keys' => implode(',', array_keys($checkout_data)),
            ]);
        }

        $blocked = self::run_distributor_validations($handler, $ship_customer, $ship_ffl, $receiving_ffl_number);

        foreach ($blocked as $b) {
            $pretty = isset($b['pretty']) && is_array($b['pretty']) ? $b['pretty'] : [];

            if (!empty($pretty)) {
                foreach ($pretty as $msg) {
                    wc_add_notice((string) $msg, 'error');
                }
                continue;
            }

            // fallback
            wc_add_notice(
                sprintf(
                    __('Checkout blocked by %1$s validation: %2$s', 'ffl-hub'),
                    (string) ($b['label'] ?? $b['id']),
                    (string) ($b['message'] ?? 'Validation failed')
                ),
                'error'
            );
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
    private static function run_distributor_validations($handler, DistributorShipTo $ship_customer, ?DistributorShipTo $ship_ffl, ?string $receiving_ffl_number): array
    {
        $by_dist = CheckoutOrderRequestBuilder::build_lines_grouped_by_distributor_from_cart([__CLASS__, 'debug_log']);
        if (empty($by_dist)) {
            self::debug_log('skip: no FFLHub-managed cart items with source distributor');
            return [];
        }
        // Pre-resolve the list of enabled distributor instances once.
        $enabled_distributors = [];
        foreach ($handler->get_distributors()  as $id => $d) { //CHANGED THIS
            $id = strtolower(trim((string) $id));
            if ($id === '') {
                continue;
            }
            if (!Options::is_distributor_enabled($id)) {
                continue;
            }
            if (!$d || !method_exists($d, 'validate_order_request')) {
                continue;
            }

            $enabled_distributors[$id] = [
                'instance' => $d,
                'label'    => method_exists($d, 'label') ? (string) $d->label() : $id,
            ];
        }

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

            self::debug_log('cart dist summary', [
                'cart_dist_id'   => $cart_dist_id,
                'lines_count'    => count($lines),
                'has_ffl_lines'  => $has_ffl ? 1 : 0,
                'ffl_resolved'   => $receiving_ffl_number ? 1 : 0,
                'ship_ffl_present' => ($ship_ffl instanceof DistributorShipTo) ? 1 : 0,
                'enabled_dist_count' => count($enabled_distributors),
            ]);

            // If there are FFL-required lines, require receiving FFL + ship_to_ffl.
            if ($has_ffl && (!$receiving_ffl_number || !($ship_ffl instanceof DistributorShipTo))) {
                $blocked[] = [
                    'id'      => $cart_dist_id,
                    'label'   => $cart_dist_id,
                    'message' => 'This cart contains items that must ship to a receiving FFL. Please select a receiving FFL to continue checkout.',
                    'codes'   => ['FFLHUB_RECEIVING_FFL_REQUIRED'],
                    'details' => [
                        'has_ffl_lines' => true,
                        'ffl_number_present' => $receiving_ffl_number ? 1 : 0,
                        'ship_ffl_present' => ($ship_ffl instanceof DistributorShipTo) ? 1 : 0,
                    ],
                    'bucket'  => 'ffl_missing',
                    'pretty'  => [
                        'This cart contains items that must ship to a receiving FFL. Please select a receiving FFL to continue checkout.',
                    ],
                ];

                self::debug_log('block: missing receiving FFL for cart dist', [
                    'cart_dist_id' => $cart_dist_id,
                    'session_ffl'  => CheckoutOrderRequestBuilder::dbg_val(
                        CheckoutOrderRequestBuilder::get_session_receiving_ffl_number(self::SESSION_KEY_RECEIVING_FFL)
                    ),
                ]);

                // Don't validate this DOR against anyone (it would be incomplete).
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
                $dist  = $voter['instance'];
                $label = $voter['label'];

                self::debug_log('validate voter', [
                    'cart_dist_id' => $cart_dist_id,
                    'voter_id'     => $voter_id,
                    'merchant_order_id' => $merchant_order_id,
                ]);



                $lines_for_voter = method_exists($dist, 'filter_lines_for_validation')
                    ? $dist->filter_lines_for_validation($req->valid_lines())
                    : $req->valid_lines();

                if (empty($lines_for_voter)) {
                    self::debug_log('skip voter (no validatable lines)', [
                        'cart_dist_id' => $cart_dist_id,
                        'voter_id' => $voter_id,
                    ]);
                    continue;
                }

                // Rebuild DOR for voter with filtered lines (keep ship-to contexts the same)
                $voter_req = new DistributorOrderRequest(
                    $lines_for_voter,
                    $req->ship_to_customer,
                    $req->ship_to_ffl,
                    $req->merchant_order_id,
                    $req->dest_state,
                    $req->receiving_ffl_number,
                    $req->notes
                );

                $res = self::call_validate($dist, $voter_id, $voter_req);
                if ($res !== null && $res['blocked'] === true) {
                    $pretty_msgs = CheckoutOrderRequestBuilder::build_pretty_validation_messages(
                        $voter_id,
                        $label,
                        $res,
                        'single',
                        $ship_customer,
                        $ship_ffl
                    );

                    $blocked[] = [
                        'id'      => $voter_id,
                        'label'   => $label,
                        'message' => $res['message'],
                        'codes'   => $res['codes'],
                        'details' => $res['details'],
                        'bucket'  => 'single',
                        'pretty'  => $pretty_msgs,
                        // helpful context for debugging
                        'cart_dist_id' => $cart_dist_id,
                    ];
                }
            }
        }

        return $blocked;
    }

    /**
     * @return array{blocked:bool,message:string,codes:array,details:array}|null
     */
    private static function call_validate($dist, string $dist_id, DistributorOrderRequest $req): ?array
    {
        try {
            /** @var DistributorOrderValidationResult $vr */
            $vr = $dist->validate_order_request($req);
        } catch (\Throwable $e) {
            self::debug_log('validate_order_request threw exception', [
                'id' => $dist_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'blocked' => true,
                'message' => 'Validation error: ' . $e->getMessage(),
                'codes'   => ['FFLHUB_VALIDATE_EXCEPTION'],
                'details' => [],
            ];
        }

        if (!($vr instanceof DistributorOrderValidationResult)) {
            self::debug_log('validate_order_request returned unexpected type; skipping block', [
                'id' => $dist_id,
                'type' => is_object($vr) ? get_class($vr) : gettype($vr),
            ]);
            return null;
        }

        self::debug_log('validation result', [
            'id' => $dist_id,
            'ok' => $vr->ok ? 'true' : 'false',
            'message' => $vr->message,
            'codes' => implode(',', is_array($vr->codes) ? $vr->codes : []),
        ]);

        return [
            'blocked' => !$vr->ok,
            'message' => ($vr->message !== '' ? $vr->message : ($vr->ok ? 'OK' : 'Validation failed')),
            'codes'   => is_array($vr->codes) ? $vr->codes : [],
            'details' => is_array($vr->details) ? $vr->details : [],
        ];
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
     * @param string $msg
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
