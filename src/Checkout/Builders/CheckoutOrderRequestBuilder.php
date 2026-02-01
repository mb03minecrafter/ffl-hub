<?php
declare(strict_types=1);

namespace FFLHub\Checkout\Builders;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Product\ProductMeta;

use FFLHub\FFL\Data\FFLRepository;
use FFLHub\FFL\Data\FFLRowMapper;
use FFLHub\FFL\Tables\FFLTable;

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorShipTo;

use WC_Product;

final class CheckoutOrderRequestBuilder
{
    /**
     * Debug value summary (safe: presence + length + last4).
     *
     * @return array{present:int,len:int,tail4:string}
     */
    public static function dbg_val(mixed $v): array
    {
        $s = trim((string) $v);
        if ($s === '') {
            return ['present' => 0, 'len' => 0, 'tail4' => ''];
        }
        $tail = strlen($s) >= 4 ? substr($s, -4) : $s;

        return ['present' => 1, 'len' => strlen($s), 'tail4' => (string) $tail];
    }

    public static function current_merchant_order_id(string $dist_id): string
    {
        $session = (function_exists('WC') && WC()->session) ? (string) WC()->session->get_customer_id() : '';
        $suffix  = $session !== '' ? substr((string) preg_replace('/[^A-Za-z0-9]/', '', $session), 0, 10) : 'nosess';

        return 'WC-REQ-' . $dist_id . '-' . gmdate('YmdHis') . '-' . $suffix;
    }

    /**
     * Build a stable fingerprint for the current cart's FFL-required lines (FFLHub-managed only).
     */
    public static function current_cart_ffl_fingerprint(): string
    {
        if (!function_exists('WC') || !WC()->cart) {
            return '';
        }

        $parts = [];

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!is_array($cart_item)) {
                continue;
            }

            $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            if ($product_id <= 0) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!($product instanceof WC_Product)) {
                continue;
            }

            $managed = (int) $product->get_meta(ProductMeta::FFLHUB_MANAGED_META, true);
            if ($managed !== 1) {
                continue;
            }

            $ffl_required = ((int) $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true) === 1);
            if (!$ffl_required) {
                continue;
            }

            $dist_id = strtolower(trim((string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true)));
            if ($dist_id === '') {
                continue;
            }

            $upc_raw = trim((string) $product->get_meta(ProductMeta::FFLHUB_UPC_META, true));
            $upc     = self::digits_only($upc_raw);
            if ($upc === '') {
                continue;
            }

            $qty = isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 1;
            $qty = max(1, $qty);

            $parts[] = $dist_id . '|' . $upc . '|' . (string) $qty;
        }

        if (empty($parts)) {
            return '';
        }

        sort($parts, SORT_STRING);

        return sha1(implode(';', $parts));
    }

    /**
     * Build ONE set of lines per distributor from the current cart.
     *
     * @param callable(string,array<string,mixed>):void|null $debug
     * @return array<string, array{lines: array<int,DistributorOrderLine>, has_ffl: bool}>
     */
    public static function build_lines_grouped_by_distributor_from_cart(?callable $debug = null): array
    {
        if (!function_exists('WC') || !WC()->cart) {
            return [];
        }

        /** @var array<string, array{by_key: array<string, array{upc:string,qty:int,ffl:bool}>, has_ffl: bool}> $agg */
        $agg = [];

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!is_array($cart_item)) {
                continue;
            }

            $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            if ($product_id <= 0) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!($product instanceof WC_Product)) {
                continue;
            }

            $managed = (int) $product->get_meta(ProductMeta::FFLHUB_MANAGED_META, true);
            if ($managed !== 1) {
                continue;
            }

            $dist_id = strtolower(trim((string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true)));
            if ($dist_id === '') {
                continue;
            }

            $upc_raw = trim((string) $product->get_meta(ProductMeta::FFLHUB_UPC_META, true));
            if ($upc_raw === '') {
                continue;
            }

            $upc = self::digits_only($upc_raw);
            if ($upc === '') {
                continue;
            }

            $ffl_required = ((int) $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true) === 1);

            $qty = isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 1;
            $qty = max(1, $qty);

            if (is_callable($debug)) {
                $debug('cart item picked', [
                    'product_id'    => $product_id,
                    'managed'       => $managed,
                    'dist_id'       => $dist_id,
                    'upc'           => $upc,
                    'ffl_required'  => $ffl_required ? 1 : 0,
                    'qty'           => $qty,
                ]);
            }

            if (!isset($agg[$dist_id])) {
                $agg[$dist_id] = [
                    'by_key'  => [],
                    'has_ffl' => false,
                ];
            }

            $key = $upc . '|' . ($ffl_required ? '1' : '0');

            if (!isset($agg[$dist_id]['by_key'][$key])) {
                $agg[$dist_id]['by_key'][$key] = [
                    'upc' => $upc,
                    'qty' => 0,
                    'ffl' => $ffl_required,
                ];
            }

            $agg[$dist_id]['by_key'][$key]['qty'] += $qty;
            if ($ffl_required) {
                $agg[$dist_id]['has_ffl'] = true;
            }
        }

        /** @var array<string, array{lines: array<int,DistributorOrderLine>, has_ffl: bool}> $out */
        $out = [];

        foreach ($agg as $dist_id => $row) {
            $lines = [];

            foreach (($row['by_key'] ?? []) as $r) {
                $lines[] = new DistributorOrderLine((string) $r['upc'], (int) $r['qty'], (bool) $r['ffl']);
            }

            if (!empty($lines)) {
                $out[$dist_id] = [
                    'lines'   => $lines,
                    'has_ffl' => (bool) ($row['has_ffl'] ?? false),
                ];
            }
        }

        return $out;
    }

    /**
     * Wrap per-distributor lines into DistributorOrderRequest objects.
     *
     * @param array<string, array<int,DistributorOrderLine>> $lines_by_dist
     * @return array<string,DistributorOrderRequest> keyed by distributor id
     */
    public static function build_requests_by_distributor(
        array $lines_by_dist,
        DistributorShipTo $ship_to_customer,
        ?DistributorShipTo $ship_to_ffl,
        string $merchant_order_id,
        string $dest_state,
        string $receiving_ffl_number = '',
        string $notes = ''
    ): array {
        /** @var array<string,DistributorOrderRequest> $out */
        $out = [];

        foreach ($lines_by_dist as $dist_id => $lines) {
            $dist_id = strtolower(trim((string) $dist_id));
            if ($dist_id === '' || empty($lines)) {
                continue;
            }

            $out[$dist_id] = new DistributorOrderRequest(
                $lines,
                $ship_to_customer,
                $ship_to_ffl,
                $merchant_order_id,
                $dest_state,
                $receiving_ffl_number,
                $notes
            );
        }

        return $out;
    }

    /**
     * Build customer ship-to using CART/CHECKOUT payload (Blocks/classic) with WC()->customer fallback.
     *
     * @param array<string,mixed> $checkout_data
     */
    public static function build_ship_to_customer_or_null(array $checkout_data): ?DistributorShipTo
    {
        $name = self::pick_first_nonempty([
            self::combine_name($checkout_data, 'shipping'),
            self::combine_name($checkout_data, 'billing'),
            fn(): string => self::combine_name_from_customer('shipping'),
            fn(): string => self::combine_name_from_customer('billing'),
        ]);

        $addr1 = self::pick_first_nonempty([
            self::get_checkout_field($checkout_data, 'shipping', 'address_1'),
            self::get_checkout_field($checkout_data, 'billing', 'address_1'),
            fn(): string => (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_shipping_address_1() : '',
            fn(): string => (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_billing_address_1() : '',
        ]);

        $addr2 = self::pick_first_nonempty([
            self::get_checkout_field($checkout_data, 'shipping', 'address_2'),
            self::get_checkout_field($checkout_data, 'billing', 'address_2'),
            fn(): string => (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_shipping_address_2() : '',
            fn(): string => (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_billing_address_2() : '',
        ]);

        $city = self::pick_first_nonempty([
            self::get_checkout_field($checkout_data, 'shipping', 'city'),
            self::get_checkout_field($checkout_data, 'billing', 'city'),
            fn(): string => (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_shipping_city() : '',
            fn(): string => (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_billing_city() : '',
        ]);

        $state = strtoupper(trim(self::pick_first_nonempty([
            self::get_checkout_field($checkout_data, 'shipping', 'state'),
            self::get_checkout_field($checkout_data, 'billing', 'state'),
            fn(): string => (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_shipping_state() : '',
            fn(): string => (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_billing_state() : '',
        ])));

        $zip = self::pick_first_nonempty([
            self::get_checkout_field($checkout_data, 'shipping', 'postcode'),
            self::get_checkout_field($checkout_data, 'billing', 'postcode'),
            fn(): string => (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_shipping_postcode() : '',
            fn(): string => (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_billing_postcode() : '',
        ]);

        $phone = self::pick_first_nonempty([
            self::get_checkout_field($checkout_data, 'billing', 'phone'),
            fn(): string => (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_billing_phone() : '',
        ]);

        $email = self::pick_first_nonempty([
            self::get_checkout_field($checkout_data, 'billing', 'email'),
            fn(): string => (function_exists('WC') && WC()->customer) ? (string) WC()->customer->get_billing_email() : '',
        ]);

        if ($addr1 === '' || $city === '' || !preg_match('/^[A-Z]{2}$/', $state) || trim($zip) === '') {
            return null;
        }

        if ($name === '') {
            $name = 'Customer';
        }

        return new DistributorShipTo($name, '', $addr1, $addr2, $city, $state, $zip, $phone, $email);
    }

    /**
     * Build customer ship-to from a WooCommerce order (shipping-first, billing fallback).
     *
     * Mirrors build_ship_to_customer_or_null() semantics but uses ORDER data only
     * (safe for async jobs / retries).
     */
    public static function build_ship_to_customer_from_order_or_null(\WC_Order $order): ?DistributorShipTo
    {
        $ship_name = trim((string) $order->get_shipping_first_name() . ' ' . (string) $order->get_shipping_last_name());
        $bill_name = trim((string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name());
        $name      = $ship_name !== '' ? $ship_name : $bill_name;
        if ($name === '') {
            $name = 'Customer';
        }

        $company = trim((string) $order->get_shipping_company());
        if ($company === '') {
            $company = trim((string) $order->get_billing_company());
        }

        $addr1 = trim((string) $order->get_shipping_address_1());
        if ($addr1 === '') $addr1 = trim((string) $order->get_billing_address_1());

        $addr2 = trim((string) $order->get_shipping_address_2());
        if ($addr2 === '') $addr2 = trim((string) $order->get_billing_address_2());

        $city = trim((string) $order->get_shipping_city());
        if ($city === '') $city = trim((string) $order->get_billing_city());

        $state = strtoupper(trim((string) $order->get_shipping_state()));
        if ($state === '') $state = strtoupper(trim((string) $order->get_billing_state()));

        $zip = trim((string) $order->get_shipping_postcode());
        if ($zip === '') $zip = trim((string) $order->get_billing_postcode());

        $phone = trim((string) $order->get_billing_phone());
        $email = trim((string) $order->get_billing_email());

        if ($addr1 === '' || $city === '' || !preg_match('/^[A-Z]{2}$/', $state) || $zip === '') {
            return null;
        }

        return new DistributorShipTo(
            $name,
            $company,
            $addr1,
            $addr2,
            $city,
            $state,
            $zip,
            $phone,
            $email
        );
    }

    /**
     * Resolve receiving FFL number from order meta and build ship-to from the FFL table.
     *
     * @return array{0:?string,1:?DistributorShipTo} [ffl_number_or_null, ship_to_or_null]
     */
    public static function build_ship_to_ffl_from_order_or_null(
        FFLTable $ffl_table,
        \WC_Order $order,
        ?callable $debug = null
    ): array {
        $ffl_number = FFLRowMapper::normalize_ffl_number((string) $order->get_meta('fflhub_receiving_ffl_number', true));
        if ($ffl_number === '') {
            return [null, null];
        }

        $ship_to_ffl = self::build_ship_to_ffl_or_null($ffl_table, $ffl_number, $debug);

        return [$ffl_number, $ship_to_ffl instanceof DistributorShipTo ? $ship_to_ffl : null];
    }

    /**
     * Build ship-to for an FFL from the registry table (repo-based; no raw SQL here).
     *
     * @param callable(string,array<string,mixed>):void|null $debug
     */
    public static function build_ship_to_ffl_or_null(
        FFLTable $ffl_table,
        string $ffl_number,
        ?callable $debug = null
    ): ?DistributorShipTo {
        $ffl_number = FFLRowMapper::normalize_ffl_number($ffl_number);
        if ($ffl_number === '') {
            return null;
        }

        $ffl = FFLRepository::find_by_number($ffl_table, $ffl_number);
        if (!is_array($ffl)) {
            if (is_callable($debug)) {
                $debug('build_ship_to_ffl: not found in registry', [
                    'ffl_number' => $ffl_number,
                ]);
            }
            return null;
        }

        $name = trim((string) ($ffl['name'] ?? ''));

        $premise = isset($ffl['premise']) && is_array($ffl['premise']) ? $ffl['premise'] : [];
        $mailing = isset($ffl['mailing']) && is_array($ffl['mailing']) ? $ffl['mailing'] : [];

        $prem_street = trim((string) ($premise['street'] ?? ''));
        $prem_city   = trim((string) ($premise['city'] ?? ''));
        $prem_state  = strtoupper(trim((string) ($premise['state'] ?? '')));
        $prem_zip    = trim((string) ($premise['zip'] ?? ''));

        $mail_street = trim((string) ($mailing['street'] ?? ''));
        $mail_city   = trim((string) ($mailing['city'] ?? ''));
        $mail_state  = strtoupper(trim((string) ($mailing['state'] ?? '')));
        $mail_zip    = trim((string) ($mailing['zip'] ?? ''));

        $addr1 = $prem_street !== '' ? $prem_street : $mail_street;
        $city  = $prem_city !== '' ? $prem_city : $mail_city;
        $state = preg_match('/^[A-Z]{2}$/', $prem_state) ? $prem_state : $mail_state;
        $zip   = $prem_zip !== '' ? $prem_zip : $mail_zip;

        if ($addr1 === '' || $city === '' || !preg_match('/^[A-Z]{2}$/', $state) || $zip === '') {
            if (is_callable($debug)) {
                $debug('build_ship_to_ffl: invalid address fields', [
                    'ffl_number' => $ffl_number,
                    'addr1' => $addr1,
                    'city'  => $city,
                    'state' => $state,
                    'zip'   => $zip,
                ]);
            }
            return null;
        }

        $phone = trim((string) ($ffl['phone'] ?? ''));

        if ($name === '') {
            $name = 'Receiving FFL';
        }

        return new DistributorShipTo($name, $name, $addr1, '', $city, $state, $zip, $phone, '');
    }

    /**
     * Resolve receiving FFL number using WC session ONLY.
     *
     * @param callable(string,array<string,mixed>):void|null $debug
     */
    public static function resolve_receiving_ffl_number(string $session_key, ?callable $debug = null): ?string
    {
        $raw = self::get_session_receiving_ffl_number_raw($session_key);

        if (is_callable($debug)) {
            $debug('ffl resolve (session-only)', [
                'session_key' => $session_key,
                'raw' => self::dbg_val($raw),
            ]);
        }

        $val = strtoupper(trim((string) $raw));
        if ($val !== '' && preg_match('/^[A-Z0-9-]+$/', $val)) {
            return $val;
        }

        return null;
    }

    public static function get_session_receiving_ffl_number(string $session_key): ?string
    {
        $raw = self::get_session_receiving_ffl_number_raw($session_key);
        $v   = strtoupper(trim((string) $raw));

        return ($v !== '' && preg_match('/^[A-Z0-9-]+$/', $v)) ? $v : null;
    }

    public static function get_session_receiving_ffl_number_raw(string $session_key): mixed
    {
        if (!function_exists('WC') || !WC()->session) {
            return null;
        }

        return WC()->session->get($session_key);
    }

    public static function persist_receiving_ffl_to_session(?string $ffl_number, string $session_key, ?callable $debug = null): void
    {
        if (!function_exists('WC') || !WC()->session) {
            if (is_callable($debug)) {
                $debug('ffl session persist skip: WC session not available', []);
            }
            return;
        }

        $ffl_number = strtoupper(trim((string) $ffl_number));

        if ($ffl_number === '' || !preg_match('/^[A-Z0-9-]+$/', $ffl_number)) {
            if (is_callable($debug)) {
                $debug('ffl session persist: clearing', ['incoming' => self::dbg_val($ffl_number)]);
            }
            WC()->session->set($session_key, null);
            return;
        }

        if (is_callable($debug)) {
            $debug('ffl session persist: set', ['incoming' => self::dbg_val($ffl_number)]);
        }

        WC()->session->set($session_key, $ffl_number);

        if (is_callable($debug)) {
            $verify = WC()->session->get($session_key);
            $debug('ffl session persist: verify', ['stored' => self::dbg_val($verify)]);
        }
    }

    /* ---------------- Checkout-data helpers ---------------- */

    /**
     * @param array<string,mixed> $data
     */
    private static function get_checkout_field(array $data, string $section, string $key): string
    {
        if (isset($data[$section]) && is_array($data[$section]) && array_key_exists($key, $data[$section])) {
            return (string) $data[$section][$key];
        }

        $flat = $section . '_' . $key;
        if (array_key_exists($flat, $data)) {
            return (string) $data[$flat];
        }

        return '';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function combine_name(array $data, string $section): string
    {
        $first = trim(self::get_checkout_field($data, $section, 'first_name'));
        $last  = trim(self::get_checkout_field($data, $section, 'last_name'));

        return trim($first . ' ' . $last);
    }

    private static function combine_name_from_customer(string $section): string
    {
        if (!function_exists('WC') || !WC()->customer) {
            return '';
        }

        if ($section === 'shipping') {
            $first = trim((string) WC()->customer->get_shipping_first_name());
            $last  = trim((string) WC()->customer->get_shipping_last_name());
            return trim($first . ' ' . $last);
        }

        $first = trim((string) WC()->customer->get_billing_first_name());
        $last  = trim((string) WC()->customer->get_billing_last_name());

        return trim($first . ' ' . $last);
    }

    /**
     * @param array<int, string|callable():string> $candidates
     */
    private static function pick_first_nonempty(array $candidates): string
    {
        foreach ($candidates as $c) {
            $val = is_callable($c) ? $c() : $c;
            $val = trim((string) $val);
            if ($val !== '') {
                return $val;
            }
        }

        return '';
    }

    /* ---------------- Pretty message helpers (RESTORED) ---------------- */

    /**
     * Build friendly, customer-facing messages from a distributor validation result.
     *
     * @return string[] list of notices to show
     */
    public static function build_pretty_validation_messages(
        string $label,
        DistributorOrderValidationResult $vr,
        string $bucket,
        DistributorShipTo $ship_customer,
        ?DistributorShipTo $ship_ffl
    ): array {
        $details = is_array($vr->details) ? $vr->details : [];
        if (empty($details)) {
            return [sprintf(__('Cannot ship one or more items in your cart (via %s).', 'ffl-hub'), $label)];
        }

        $candidate = self::pick_bucket_details($details, $bucket);

        $items = (isset($candidate['items']) && is_array($candidate['items']))
            ? $candidate['items']
            : [];

        $state = self::resolve_state_for_bucket($bucket, $ship_customer, $ship_ffl);

        $upcs = self::extract_upcs_from_validation_items($items);
        $upcs = array_values(array_unique(array_filter($upcs)));

        if (!empty($upcs)) {
            $out = [];
            foreach ($upcs as $upc) {
                $name = self::find_cart_item_name_by_upc($upc) ?: ('Item (UPC ' . $upc . ')');
                $out[] = ($state !== '')
                    ? sprintf(__('Cannot ship “%s” to your state of residence (%s).', 'ffl-hub'), $name, $state)
                    : sprintf(__('Cannot ship “%s” to your state of residence.', 'ffl-hub'), $name);
            }
            return $out;
        }

        return [
            ($state !== '')
                ? sprintf(__('Cannot ship one or more items in your cart to your state of residence (%s).', 'ffl-hub'), $state)
                : __('Cannot ship one or more items in your cart to your state of residence.', 'ffl-hub'),
        ];
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private static function pick_bucket_details(array $details, string $bucket): array
    {
        if ($bucket === 'non' && isset($details['non']) && is_array($details['non'])) {
            return $details['non'];
        }
        if ($bucket === 'ffl' && isset($details['ffl']) && is_array($details['ffl'])) {
            return $details['ffl'];
        }
        if (isset($details['non']) && is_array($details['non'])) {
            return $details['non'];
        }
        if (isset($details['ffl']) && is_array($details['ffl'])) {
            return $details['ffl'];
        }

        return $details;
    }

    private static function resolve_state_for_bucket(
        string $bucket,
        DistributorShipTo $ship_customer,
        ?DistributorShipTo $ship_ffl
    ): string {
        $state = '';
        if ($bucket === 'ffl' && $ship_ffl instanceof DistributorShipTo) {
            $state = strtoupper(trim((string) $ship_ffl->state));
        } else {
            $state = strtoupper(trim((string) $ship_customer->state));
        }

        return preg_match('/^[A-Z]{2}$/', $state) ? $state : '';
    }

    /**
     * Try to pull UPCs out of a distributor validation items[] payload.
     *
     * @param array<int, mixed> $items
     * @return string[]
     */
    public static function extract_upcs_from_validation_items(array $items): array
    {
        $upcs = [];

        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach (['UPC', 'Upc', 'upc', 'UPCcode', 'upccode'] as $k) {
                if (!array_key_exists($k, $row)) {
                    continue;
                }
                $v = self::digits_only((string) $row[$k]);
                if ($v !== '') {
                    $upcs[] = $v;
                }
            }
        }

        return $upcs;
    }

    /**
     * Find a cart product name by matching FFLHub UPC meta.
     */
    public static function find_cart_item_name_by_upc(string $upc): ?string
    {
        if (!function_exists('WC') || !WC()->cart) {
            return null;
        }

        $needle = self::digits_only($upc);
        if ($needle === '') {
            return null;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!is_array($cart_item)) {
                continue;
            }

            $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            if ($product_id <= 0) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!($product instanceof WC_Product)) {
                continue;
            }

            $managed = (int) $product->get_meta(ProductMeta::FFLHUB_MANAGED_META, true);
            if ($managed !== 1) {
                continue;
            }

            $raw  = (string) $product->get_meta(ProductMeta::FFLHUB_UPC_META, true);
            $have = self::digits_only($raw);

            if ($have !== '' && $have === $needle) {
                return (string) $product->get_name();
            }
        }

        return null;
    }

    /* ---------------- Tiny normalizers ---------------- */

    private static function digits_only(string $s): string
    {
        $v = preg_replace('/\D+/', '', $s);
        return is_string($v) ? $v : '';
    }
}
