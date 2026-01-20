<?php

namespace FFLHub\Checkout;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Plugin;
use FFLHub\Product\ProductMeta;
use FFLHub\Admin\FFLImporterPage;
use FFLHub\Settings\Options;

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

    public static function init(): void
    {
        // Cart + many checkout flows (classic + some Blocks paths).
        add_action('woocommerce_check_cart_items', [__CLASS__, 'validate_cart_for_shipping_restrictions']);

        // Blocks-safe: hard-stop checkout submission.
        add_action('woocommerce_after_checkout_validation', [__CLASS__, 'validate_checkout_submission'], 10, 2);
    }

    /**
     * Enforce distributor-provided per-state shipping restrictions (when available).
     *
     * Compliance policy:
     * - For each item, check ALL enabled distributors.
     * - If ANY distributor returns explicit FALSE => BLOCK.
     * - TRUE means that distributor allows.
     * - NULL means unknown/no data (ignored).
     *
     * Customer-facing output: only product name + state restriction.
     */
    public static function validate_cart_for_shipping_restrictions(): void
    {
        if (! function_exists('WC') || ! WC()->cart) {
            self::debug_log('skip: WC/cart not available');
            return;
        }

        $dest_state = self::resolve_destination_state();
        if (! $dest_state) {
            self::debug_log('skip: dest_state unresolved');
            return;
        }

        $handler = Plugin::instance()->distributor_handler;
        $all_distributors = $handler->get_distributors(); // array<string, DistributorBase>

        if (empty($all_distributors)) {
            self::debug_log('skip: no distributors registered');
            return;
        }

        self::debug_log('begin cart validation', [
            'dest_state' => $dest_state,
            'registered_distributors' => implode(',', array_keys($all_distributors)),
        ]);

        $violations = self::evaluate_cart_violations($dest_state, $all_distributors);

        self::debug_log('cart validation summary', [
            'violations' => count($violations),
        ]);

        if (! empty($violations)) {
            foreach ($violations as $v) {
                wc_add_notice(
                    sprintf(
                        __('"%1$s" cannot be shipped to %2$s due to state restrictions.', 'ffl-hub'),
                        $v['name'],
                        strtoupper($dest_state)
                    ),
                    'error'
                );
            }
        }
    }

    /**
     * Blocks-safe checkout submission validator.
     *
     * Uses WP_Error to hard-stop order placement in both Classic and Checkout Blocks.
     *
     * @param array $data Posted checkout data (varies by checkout type)
     * @param \WP_Error $errors
     */
    public static function validate_checkout_submission($data, $errors): void
    {
        if (! function_exists('WC') || ! WC()->cart) {
            self::debug_log('checkout validation skip: WC/cart not available');
            return;
        }

        $dest_state = self::resolve_destination_state_from_checkout_data(is_array($data) ? $data : []);
        if (! $dest_state) {
            self::debug_log('checkout validation skip: dest_state unresolved');
            return;
        }

        $handler = Plugin::instance()->distributor_handler;
        $all_distributors = $handler->get_distributors();

        if (empty($all_distributors)) {
            self::debug_log('checkout validation skip: no distributors registered');
            return;
        }

        self::debug_log('begin checkout submission validation', [
            'dest_state' => $dest_state,
            'registered_distributors' => implode(',', array_keys($all_distributors)),
        ]);

        $violations = self::evaluate_cart_violations($dest_state, $all_distributors);

        self::debug_log('checkout validation summary', [
            'violations' => count($violations),
        ]);

        if (empty($violations)) {
            return;
        }

        foreach ($violations as $v) {
            $errors->add(
                'fflhub_shipping_restriction',
                sprintf(
                    __('"%1$s" cannot be shipped to %2$s due to state restrictions.', 'ffl-hub'),
                    $v['name'],
                    strtoupper($dest_state)
                )
            );
        }
    }

    /**
     * Returns violations for the current cart, deduped by UPC.
     *
     * Each violation: ['name' => string, 'upc' => string, 'blocked_by' => string]
     * NOTE: 'blocked_by' is ONLY for internal/debug use; customer notices do not include it.
     *
     * @param string $dest_state
     * @param array<string,mixed> $all_distributors
     * @return array<int,array{name:string,upc:string,blocked_by:string}>
     */
    private static function evaluate_cart_violations(string $dest_state, array $all_distributors): array
    {
        $violations = [];
        $seen_upc = [];

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            if ($product_id <= 0) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (! $product) {
                continue;
            }

            // Only enforce for FFLHub-managed products.
            $managed = (int) $product->get_meta(ProductMeta::FFLHUB_MANAGED_META, true);
            if ($managed !== 1) {
                continue;
            }

            $upc = trim((string) $product->get_meta(ProductMeta::FFLHUB_UPC_META, true));
            if ($upc === '') {
                continue;
            }

            // Deduplicate by UPC so customers don't see duplicates.
            if (isset($seen_upc[$upc])) {
                continue;
            }

            // Optional debug-only context
            $preferred_id = strtolower(trim((string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true)));

            self::debug_log('checking item (all distributors)', [
                'product_id' => $product_id,
                'name'       => $product->get_name(),
                'upc'        => $upc,
                'dest_state' => $dest_state,
                'preferred'  => $preferred_id,
            ]);

            $blocked_by = self::blocked_by_any_distributor($upc, $dest_state, $all_distributors);

            if ($blocked_by !== null) {
                $seen_upc[$upc] = true;

                self::debug_log('violation (most restrictive wins)', [
                    'product_id' => $product_id,
                    'upc'        => $upc,
                    'dest_state' => $dest_state,
                    'blocked_by' => $blocked_by,
                    'preferred'  => $preferred_id,
                ]);

                $violations[] = [
                    'name'       => $product->get_name(),
                    'upc'        => $upc,
                    'blocked_by' => $blocked_by,
                ];
            }
        }

        return $violations;
    }

    /**
     * Check ALL enabled distributors for explicit blocking.
     *
     * Returns the distributor ID that blocked (first hit), or null if none explicitly block.
     *
     * @param string $upc
     * @param string $dest_state
     * @param array<string,mixed> $all_distributors
     */
    private static function blocked_by_any_distributor(
        string $upc,
        string $dest_state,
        array $all_distributors
    ): ?string {
        foreach ($all_distributors as $id => $dist) {
            $id = (string) $id;

            // Only use enabled distributors (avoids stale/disabled feeds).
            if (! Options::is_distributor_enabled($id)) {
                self::debug_log('skip distributor (disabled)', ['id' => $id]);
                continue;
            }

            try {
                $can_ship = $dist->can_ship_to_state_by_upc($upc, $dest_state);
            } catch (\Throwable $e) {
                self::debug_log('check threw', [
                    'id'         => $id,
                    'upc'        => $upc,
                    'dest_state' => $dest_state,
                    'error'      => $e->getMessage(),
                ]);
                $can_ship = null;
            }

            self::debug_log('check result', [
                'id'         => $id,
                'upc'        => $upc,
                'dest_state' => $dest_state,
                'can_ship'   => var_export($can_ship, true),
            ]);

            // Most restrictive wins: block immediately if any distributor says no.
            if ($can_ship === false) {
                return $id;
            }
        }

        self::debug_log('no explicit block', [
            'upc'        => $upc,
            'dest_state' => $dest_state,
        ]);

        return null;
    }

    /**
     * Destination state:
     * - If cart requires FFL: try receiving FFL premise_state (best effort)
     * - Else: customer shipping state
     */
    private static function resolve_destination_state(): ?string
    {
        $requires_ffl = self::cart_requires_ffl();

        self::debug_log('resolve_destination_state', [
            'requires_ffl' => $requires_ffl ? 1 : 0,
        ]);

        if ($requires_ffl) {
            $ffl_number = self::read_receiving_ffl_number_from_request();
            self::debug_log('ffl number from request', [
                'ffl_number' => $ffl_number ?: '(none)',
            ]);

            if ($ffl_number) {
                $state = self::lookup_ffl_premise_state($ffl_number);
                self::debug_log('ffl premise state lookup', [
                    'ffl_number' => $ffl_number,
                    'state'      => $state ?: '(none)',
                ]);

                if ($state) {
                    return $state;
                }
            }
        }

        if (! function_exists('WC') || ! WC()->customer) {
            self::debug_log('resolve_destination_state: WC customer not available');
            return null;
        }

        $raw = (string) WC()->customer->get_shipping_state();
        $state = strtoupper(trim($raw));

        self::debug_log('shipping state from customer', [
            'raw'   => $raw,
            'state' => $state,
        ]);

        return preg_match('/^[A-Z]{2}$/', $state) ? $state : null;
    }

    /**
     * Prefer reading state from posted checkout data when available.
     * This helps when customer session state lags behind form state in some checkouts.
     */
    private static function resolve_destination_state_from_checkout_data(array $data): ?string
    {
        // If cart requires FFL, keep FFL-based destination first.
        $requires_ffl = self::cart_requires_ffl();

        if ($requires_ffl) {
            $ffl_number = self::read_receiving_ffl_number_from_request();
            if ($ffl_number) {
                $state = self::lookup_ffl_premise_state($ffl_number);
                if ($state) {
                    return $state;
                }
            }
        }

        $candidates = [];

        $candidates[] = $data['shipping_state'] ?? null;
        $candidates[] = $data['billing_state'] ?? null;

        if (isset($data['shipping']) && is_array($data['shipping'])) {
            $candidates[] = $data['shipping']['state'] ?? null;
        }
        if (isset($data['billing']) && is_array($data['billing'])) {
            $candidates[] = $data['billing']['state'] ?? null;
        }

        foreach ($candidates as $raw) {
            $state = strtoupper(trim((string) $raw));
            if (preg_match('/^[A-Z]{2}$/', $state)) {
                self::debug_log('dest_state from checkout data', ['state' => $state]);
                return $state;
            }
        }

        // Fallback to customer session resolver.
        return self::resolve_destination_state();
    }

    private static function cart_requires_ffl(): bool
    {
        if (! function_exists('WC') || ! WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            if ($product_id <= 0) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (! $product) {
                continue;
            }

            $ffl_required = (int) $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true);
            if ($ffl_required === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Best-effort read of the receiving FFL number from checkout request payload.
     * (Blocks payload varies; this tries multiple common shapes.)
     */
    private static function read_receiving_ffl_number_from_request(): ?string
    {
        $candidates = [];

        if (isset($_POST['additional_fields']) && is_array($_POST['additional_fields'])) {
            $candidates[] = $_POST['additional_fields']['ffl-hub/receiving-ffl'] ?? null;
        }

        if (isset($_POST['additional_fields']) && is_string($_POST['additional_fields'])) {
            $decoded = json_decode((string) $_POST['additional_fields'], true);
            if (is_array($decoded)) {
                $candidates[] = $decoded['ffl-hub/receiving-ffl'] ?? null;
            }
        }

        $candidates[] = $_POST['ffl-hub/receiving-ffl'] ?? null;
        $candidates[] = $_REQUEST['ffl-hub/receiving-ffl'] ?? null;

        foreach ($candidates as $raw) {
            $val = strtoupper(trim(sanitize_text_field((string) $raw)));
            if ($val !== '' && preg_match('/^[A-Z0-9-]+$/', $val)) {
                return $val;
            }
        }

        return null;
    }

    private static function lookup_ffl_premise_state(string $ffl_number): ?string
    {
        global $wpdb;

        $ffl_number = strtoupper(trim(sanitize_text_field($ffl_number)));
        if ($ffl_number === '') {
            return null;
        }

        $table = FFLImporterPage::get_table_name_public();
        if (! $table) {
            self::debug_log('lookup_ffl_premise_state: missing table');
            return null;
        }

        $sql = "
            SELECT premise_state
            FROM {$table}
            WHERE ffl_number = %s
            LIMIT 1
        ";

        $prepared = $wpdb->prepare($sql, $ffl_number);
        $state = $wpdb->get_var($prepared);

        $state = strtoupper(trim((string) $state));
        return preg_match('/^[A-Z]{2}$/', $state) ? $state : null;
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
    private static function debug_log(string $msg, array $context = []): void
    {
        if (! self::debug_enabled()) {
            return;
        }

        $prefix = '[FFLHub CartCompliance] ';
        if (! empty($context)) {
            error_log($prefix . $msg . ' ' . wp_json_encode($context));
            return;
        }

        error_log($prefix . $msg);
    }
}
