<?php

namespace FFLHub\Checkout;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Product\ProductMeta;
use FFLHub\Admin\FFLImporterPage;
use FFLHub\Distributor\Product\DistributorOrderLine;
use FFLHub\Distributor\Product\DistributorShipTo;

/**
 * CheckoutOrderRequestBuilder
 *
 * Centralizes the “data assembly” logic that will be shared by:
 * - Cart compliance validation (current)
 * - Place-order execution (future)
 *
 * Responsibilities:
 * - Build DistributorShipTo for customer shipping address from checkout payload or WC()->customer
 * - Resolve receiving FFL number from multiple possible sources (Blocks, POST, REQUEST, WC session)
 * - Persist receiving FFL number into WC session (so cart-page checks can see it)
 * - Lookup receiving FFL address (ship_to_ffl) from imported FFL table
 * - Build normalized DistributorOrderLine sets from the current cart, grouped per distributor
 */
final class CheckoutOrderRequestBuilder
{
    /**
     * Create a compact debug value structure (safe: length + last4).
     *
     * @return array{present:int,len:int,tail4:string}
     */
    public static function dbg_val($v): array
    {
        $s = trim((string) $v);
        if ($s === '') {
            return ['present' => 0, 'len' => 0, 'tail4' => ''];
        }
        $tail = strlen($s) >= 4 ? substr($s, -4) : $s;
        return ['present' => 1, 'len' => strlen($s), 'tail4' => (string) $tail];
    }

    /**
     * Build a unique-ish merchant_order_id for validation/place-order.
     */
    public static function current_merchant_order_id(string $dist_id): string
    {
        $session = function_exists('WC') && WC()->session ? (string) WC()->session->get_customer_id() : '';
        $suffix = $session !== '' ? substr((string) preg_replace('/[^A-Za-z0-9]/', '', $session), 0, 10) : 'nosess';
        return 'WC-REQ-' . $dist_id . '-' . gmdate('YmdHis') . '-' . $suffix;
    }

    /**
     * Build ONE set of lines per distributor from the current cart.
     *
     * Output shape:
     *   [
     *     'rsr' => [
     *       'lines' => DistributorOrderLine[],
     *       'has_ffl' => bool,
     *     ],
     *     ...
     *   ]
     *
     * Notes:
     * - Aggregates quantity by (UPC + ffl_required) so same UPC in different buckets stays separate.
     *
     * @param callable|null $debug function(string $msg, array $ctx):void
     */
    public static function build_lines_grouped_by_distributor_from_cart(?callable $debug = null): array
    {
        if (!function_exists('WC') || !WC()->cart) {
            return [];
        }

        $agg = [];

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            if ($product_id <= 0) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
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

            $upc = preg_replace('/\D+/', '', $upc_raw);
            $upc = is_string($upc) ? $upc : '';
            if ($upc === '') {
                continue;
            }

            $ffl_required = ((int) $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true) === 1);

            $qty = isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 1;
            $qty = max(1, $qty);

            if (is_callable($debug)) {
                $debug('cart item picked', [
                    'product_id' => $product_id,
                    'managed' => $managed,
                    'dist_id' => $dist_id,
                    'upc' => $upc,
                    'ffl_required' => $ffl_required ? 1 : 0,
                    'qty' => $qty,
                ]);
            }

            if (!isset($agg[$dist_id])) {
                $agg[$dist_id] = [
                    'by_key' => [], // key => ['upc'=>string,'qty'=>int,'ffl'=>bool]
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

        $out = [];
        foreach ($agg as $dist_id => $row) {
            $lines = [];
            foreach (($row['by_key'] ?? []) as $r) {
                $lines[] = new DistributorOrderLine((string) $r['upc'], (int) $r['qty'], (bool) $r['ffl']);
            }

            if (!empty($lines)) {
                $out[$dist_id] = [
                    'lines' => $lines,
                    'has_ffl' => !empty($row['has_ffl']),
                ];
            }
        }

        return $out;
    }

    /**
     * Build customer ship-to using checkout payload (Blocks/classic) with WC()->customer fallback.
     */
    public static function build_ship_to_customer_or_null(array $checkout_data): ?DistributorShipTo
    {
        $name = self::pick_first_nonempty([
            self::combine_name($checkout_data, 'shipping'),
            self::combine_name($checkout_data, 'billing'),
            self::combine_name_from_customer('shipping'),
            self::combine_name_from_customer('billing'),
        ]);

        $addr1 = self::pick_first_nonempty([
            self::get_checkout_field($checkout_data, 'shipping', 'address_1'),
            self::get_checkout_field($checkout_data, 'billing', 'address_1'),
            function_exists('WC') && WC()->customer ? (string) WC()->customer->get_shipping_address_1() : '',
            function_exists('WC') && WC()->customer ? (string) WC()->customer->get_billing_address_1() : '',
        ]);

        $addr2 = self::pick_first_nonempty([
            self::get_checkout_field($checkout_data, 'shipping', 'address_2'),
            self::get_checkout_field($checkout_data, 'billing', 'address_2'),
            function_exists('WC') && WC()->customer ? (string) WC()->customer->get_shipping_address_2() : '',
            function_exists('WC') && WC()->customer ? (string) WC()->customer->get_billing_address_2() : '',
        ]);

        $city = self::pick_first_nonempty([
            self::get_checkout_field($checkout_data, 'shipping', 'city'),
            self::get_checkout_field($checkout_data, 'billing', 'city'),
            function_exists('WC') && WC()->customer ? (string) WC()->customer->get_shipping_city() : '',
            function_exists('WC') && WC()->customer ? (string) WC()->customer->get_billing_city() : '',
        ]);

        $state = strtoupper(trim(self::pick_first_nonempty([
            self::get_checkout_field($checkout_data, 'shipping', 'state'),
            self::get_checkout_field($checkout_data, 'billing', 'state'),
            function_exists('WC') && WC()->customer ? (string) WC()->customer->get_shipping_state() : '',
            function_exists('WC') && WC()->customer ? (string) WC()->customer->get_billing_state() : '',
        ])));

        $zip = self::pick_first_nonempty([
            self::get_checkout_field($checkout_data, 'shipping', 'postcode'),
            self::get_checkout_field($checkout_data, 'billing', 'postcode'),
            function_exists('WC') && WC()->customer ? (string) WC()->customer->get_shipping_postcode() : '',
            function_exists('WC') && WC()->customer ? (string) WC()->customer->get_billing_postcode() : '',
        ]);

        $phone = self::pick_first_nonempty([
            self::get_checkout_field($checkout_data, 'billing', 'phone'),
            function_exists('WC') && WC()->customer ? (string) WC()->customer->get_billing_phone() : '',
        ]);

        $email = self::pick_first_nonempty([
            self::get_checkout_field($checkout_data, 'billing', 'email'),
            function_exists('WC') && WC()->customer ? (string) WC()->customer->get_billing_email() : '',
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
     * Build ship-to for the receiving FFL from the imported FFL table.
     *
     * @param callable|null $debug function(string $msg, array $ctx):void
     */
    public static function build_ship_to_ffl_or_null(string $ffl_number, ?callable $debug = null): ?DistributorShipTo
    {
        global $wpdb;

        $ffl_number = strtoupper(trim(sanitize_text_field($ffl_number)));
        if ($ffl_number === '') {
            return null;
        }

        $table = FFLImporterPage::get_table_name_public();
        if (!$table) {
            if (is_callable($debug)) {
                $debug('build_ship_to_ffl: missing ffl table', []);
            }
            return null;
        }

        $sql = "
            SELECT
                license_name,
                premise_street,
                premise_city,
                premise_state,
                premise_zip,
                mail_street,
                mail_city,
                mail_state,
                mail_zip,
                voice_phone
            FROM {$table}
            WHERE ffl_number = %s
            LIMIT 1
        ";

        $row = $wpdb->get_row($wpdb->prepare($sql, $ffl_number), ARRAY_A);
        if (!is_array($row) || empty($row)) {
            return null;
        }

        $name = trim((string) ($row['license_name'] ?? ''));

        $prem_street = trim((string) ($row['premise_street'] ?? ''));
        $prem_city   = trim((string) ($row['premise_city'] ?? ''));
        $prem_state  = strtoupper(trim((string) ($row['premise_state'] ?? '')));
        $prem_zip    = trim((string) ($row['premise_zip'] ?? ''));

        $mail_street = trim((string) ($row['mail_street'] ?? ''));
        $mail_city   = trim((string) ($row['mail_city'] ?? ''));
        $mail_state  = strtoupper(trim((string) ($row['mail_state'] ?? '')));
        $mail_zip    = trim((string) ($row['mail_zip'] ?? ''));

        $addr1 = $prem_street !== '' ? $prem_street : $mail_street;
        $city  = $prem_city !== '' ? $prem_city : $mail_city;
        $state = preg_match('/^[A-Z]{2}$/', $prem_state) ? $prem_state : $mail_state;
        $zip   = $prem_zip !== '' ? $prem_zip : $mail_zip;

        if ($addr1 === '' || $city === '' || !preg_match('/^[A-Z]{2}$/', $state) || $zip === '') {
            return null;
        }

        $phone = trim((string) ($row['voice_phone'] ?? ''));

        if ($name === '') {
            $name = 'Receiving FFL';
        }

        // (name, company, addr1, addr2, city, state, zip, phone, email)
        return new DistributorShipTo($name, $name, $addr1, '', $city, $state, $zip, $phone, '');
    }

    /**
     * Resolve receiving FFL number from multiple sources (in priority order).
     *
     * Priority:
     *  1) $checkout_data additional_fields (Blocks)
     *  2) $checkout_data flattened keys
     *  3) $_POST additional_fields (array, then JSON string)
     *  4) $_POST[field_id]
     *  5) $_REQUEST[field_id]
     *  6) WC session[session_key]
     *
     * @param callable|null $debug function(string $msg, array $ctx):void
     */
    public static function resolve_receiving_ffl_number(
        array $checkout_data,
        string $field_id,
        string $session_key,
        ?callable $debug = null
    ): ?string {
        $sources = [];

        // 1) Checkout data: additional_fields (Blocks)
        $v1 = null;
        if (isset($checkout_data['additional_fields']) && is_array($checkout_data['additional_fields'])) {
            $v1 = $checkout_data['additional_fields'][$field_id] ?? null;
        }
        $sources[] = ['src' => 'data.additional_fields[field_id]', 'val' => self::dbg_val($v1)];

        // 2) Flattened variants (depends on Blocks payload shape)
        $v2 = $checkout_data[$field_id] ?? null;
        $sources[] = ['src' => 'data[field_id]', 'val' => self::dbg_val($v2)];

        $v3 = $checkout_data['ffl-hub/receiving-ffl'] ?? null;
        $sources[] = ['src' => 'data["ffl-hub/receiving-ffl"]', 'val' => self::dbg_val($v3)];

        // 3) Request: $_POST
        $p1 = null;
        if (isset($_POST['additional_fields']) && is_array($_POST['additional_fields'])) {
            $p1 = $_POST['additional_fields'][$field_id] ?? null;
        }
        $sources[] = ['src' => 'POST.additional_fields[field_id]', 'val' => self::dbg_val($p1)];

        $p2 = null;
        if (isset($_POST['additional_fields']) && is_string($_POST['additional_fields'])) {
            $decoded = json_decode((string) $_POST['additional_fields'], true);
            if (is_array($decoded)) {
                $p2 = $decoded[$field_id] ?? null;
            }
        }
        $sources[] = ['src' => 'POST.additional_fields(json)[field_id]', 'val' => self::dbg_val($p2)];

        $p3 = $_POST[$field_id] ?? null;
        $sources[] = ['src' => 'POST[field_id]', 'val' => self::dbg_val($p3)];

        // 4) Request: $_REQUEST
        $r1 = $_REQUEST[$field_id] ?? null;
        $sources[] = ['src' => 'REQUEST[field_id]', 'val' => self::dbg_val($r1)];

        // 5) Session
        $s1 = self::get_session_receiving_ffl_number_raw($session_key);
        $sources[] = ['src' => 'SESSION[' . $session_key . ']', 'val' => self::dbg_val($s1)];

        if (is_callable($debug)) {
            $debug('ffl resolve candidates', [
                'field_id' => $field_id,
                'sources' => $sources,
            ]);
        }

        $candidates = [$v1, $v2, $v3, $p1, $p2, $p3, $r1, $s1];

        foreach ($candidates as $raw) {
            $val = strtoupper(trim(sanitize_text_field((string) $raw)));
            if ($val !== '' && preg_match('/^[A-Z0-9-]+$/', $val)) {
                if (is_callable($debug)) {
                    $debug('ffl resolved', ['resolved' => self::dbg_val($val)]);
                }
                return $val;
            }
        }

        if (is_callable($debug)) {
            $debug('ffl resolve failed', ['session_raw' => self::dbg_val($s1)]);
        }

        return null;
    }

    public static function get_session_receiving_ffl_number(string $session_key): ?string
    {
        $raw = self::get_session_receiving_ffl_number_raw($session_key);
        $v = strtoupper(trim((string) $raw));
        return ($v !== '' && preg_match('/^[A-Z0-9-]+$/', $v)) ? $v : null;
    }

    public static function get_session_receiving_ffl_number_raw(string $session_key)
    {
        if (!function_exists('WC') || !WC()->session) {
            return null;
        }
        return WC()->session->get($session_key);
    }

    /**
     * Persist receiving FFL number into WC session.
     *
     * @param callable|null $debug function(string $msg, array $ctx):void
     */
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

    private static function combine_name(array $data, string $section): string
    {
        $first = trim((string) self::get_checkout_field($data, $section, 'first_name'));
        $last  = trim((string) self::get_checkout_field($data, $section, 'last_name'));
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
     * @param array<int, string|callable> $candidates
     */
    private static function pick_first_nonempty(array $candidates): string
    {
        foreach ($candidates as $c) {
            $val = is_callable($c) ? (string) $c() : (string) $c;
            $val = trim($val);
            if ($val !== '') {
                return $val;
            }
        }
        return '';
    }






    /**
     * Build friendly, customer-facing messages from a distributor validation result.
     *
     * @param string $dist_id
     * @param string $label
     * @param array $res Output of call_validate() (blocked/message/codes/details)
     * @param string $bucket 'non' | 'ffl' | 'single' | etc
     * @param DistributorShipTo $ship_customer
     * @param DistributorShipTo|null $ship_ffl
     * @return string[] list of notices to show
     */
    public static function build_pretty_validation_messages(
        string $dist_id,
        string $label,
        array $res,
        string $bucket,
        DistributorShipTo $ship_customer,
        ?DistributorShipTo $ship_ffl
    ): array {
        $details = isset($res['details']) && is_array($res['details']) ? $res['details'] : [];
        if (empty($details)) {
            // Fallback to generic
            return [sprintf('Cannot ship one or more items in your cart (via %s).', $label)];
        }

        // RSR puts per-bucket result under details['non'] / details['ffl']
        // but other distributors may have different shapes later.
        $candidate = null;

        if (isset($details['non']) && is_array($details['non']) && $bucket === 'non') {
            $candidate = $details['non'];
        } elseif (isset($details['ffl']) && is_array($details['ffl']) && $bucket === 'ffl') {
            $candidate = $details['ffl'];
        } elseif (isset($details['non']) && is_array($details['non'])) {
            $candidate = $details['non'];
        } elseif (isset($details['ffl']) && is_array($details['ffl'])) {
            $candidate = $details['ffl'];
        } else {
            $candidate = $details;
        }

        $items = isset($candidate['items']) && is_array($candidate['items']) ? $candidate['items'] : [];

        // Determine which state to reference for the notice.
        $state = '';
        if ($bucket === 'ffl' && $ship_ffl instanceof DistributorShipTo) {
            $state = strtoupper(trim((string) $ship_ffl->state));
        } else {
            $state = strtoupper(trim((string) $ship_customer->state));
        }
        if (!preg_match('/^[A-Z]{2}$/', $state)) {
            $state = ''; // don't show invalid
        }

        // If we can extract UPCs, generate per-item notices.
        $out = [];

        $upcs = self::extract_upcs_from_validation_items($items);
        $upcs = array_values(array_unique(array_filter($upcs)));

        if (!empty($upcs)) {
            foreach ($upcs as $upc) {
                $name = self::find_cart_item_name_by_upc($upc) ?: ('Item (UPC ' . $upc . ')');
                $out[] = $state !== ''
                    ? sprintf('Cannot ship “%s” to your state of residence (%s).', $name, $state)
                    : sprintf('Cannot ship “%s” to your state of residence.', $name);
            }
            return $out;
        }

        // Otherwise, degrade gracefully with a nicer generic message.
        $out[] = $state !== ''
            ? sprintf('Cannot ship one or more items in your cart to your state of residence (%s).', $state)
            : 'Cannot ship one or more items in your cart to your state of residence.';

        return $out;
    }

    /**
     * Try to pull UPCs out of a distributor validation items[] payload.
     * RSR check-catalog often includes UPC or UPCcode in each item row.
     *
     * @param array<int,array<string,mixed>> $items
     * @return string[]
     */
    public static function extract_upcs_from_validation_items(array $items): array
    {
        $upcs = [];

        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Common key names we might see:
            foreach (['UPC', 'Upc', 'upc', 'UPCcode', 'upccode'] as $k) {
                if (isset($row[$k])) {
                    $v = preg_replace('/\D+/', '', (string) $row[$k]);
                    if (is_string($v) && $v !== '') {
                        $upcs[] = $v;
                    }
                }
            }

            // Some distributors only include PartNum; you *could* add a PartNum→UPC mapping later if you want.
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

        $needle = preg_replace('/\D+/', '', $upc);
        $needle = is_string($needle) ? $needle : '';
        if ($needle === '') {
            return null;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            if ($product_id <= 0) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            $managed = (int) $product->get_meta(ProductMeta::FFLHUB_MANAGED_META, true);
            if ($managed !== 1) {
                continue;
            }

            $raw = (string) $product->get_meta(ProductMeta::FFLHUB_UPC_META, true);
            $have = preg_replace('/\D+/', '', $raw);
            $have = is_string($have) ? $have : '';

            if ($have !== '' && $have === $needle) {
                return (string) $product->get_name();
            }
        }

        return null;
    }
}
