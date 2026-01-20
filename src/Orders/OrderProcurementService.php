<?php

namespace FFLHub\Orders;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Plugin;
use FFLHub\Product\ProductMeta;
use FFLHub\Admin\FFLImporterPage;

use FFLHub\Distributor\Product\DistributorOrderLine;
use FFLHub\Distributor\Product\DistributorShipTo;

/**
 * Submits distributor purchase orders when Woo orders are ready to fulfill.
 *
 * Fulfillment rule enforced here:
 *  - FFL-required items ship to receiving FFL premise address.
 *  - Non-FFL items ship to buyer shipping address.
 *  - Never mix destinations within a single PO request.
 *
 * DRY RUN:
 *   define('FFLHUB_ORDERING_DRY_RUN', true);
 *
 * DEBUG:
 *   define('FFLHUB_ORDERING_DEBUG', true);
 *
 * Receiving FFL order meta compatibility:
 *  - Some flows store "fflhub_receiving_ffl_number" (no underscore).
 *  - This service reads BOTH keys and writes BOTH keys.
 *
 * Shipping meta:
 *  - Copies internal shipping calculation meta from the chosen shipping rate onto the order.
 */
final class OrderProcurementService
{
    private const DRY_RUN_CONST = 'FFLHUB_ORDERING_DRY_RUN';
    private const DEBUG_CONST   = 'FFLHUB_ORDERING_DEBUG';

    public static function init(): void
    {
        // Lock in distributor + UPC + FFL-required on order line items.
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'lock_in_line_item_meta'], 10, 4);

        // Persist receiving FFL number onto the order for later use (best-effort).
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'lock_in_order_meta'], 10, 2);

        // ✅ Copy chosen shipping rate meta to the order.
        add_action('woocommerce_checkout_create_order_shipping_item', [__CLASS__, 'lock_in_shipping_meta'], 10, 4);

        // COD testing / universal: trigger when order becomes processing.
        add_action('woocommerce_order_status_processing', [__CLASS__, 'submit_purchase_orders_on_processing'], 20, 1);
    }

    /**
     * @param \WC_Order_Item_Product $item
     * @param string $cart_item_key
     * @param array $values
     * @param \WC_Order $order
     */
    public static function lock_in_line_item_meta($item, $cart_item_key, $values, $order): void
    {
        $product = $item->get_product();
        if (! $product) {
            return;
        }

        // Only for FFLHub-managed products.
        $managed = (int) $product->get_meta(ProductMeta::FFLHUB_MANAGED_META, true);
        if ($managed !== 1) {
            return;
        }

        $upc = trim((string) $product->get_meta(ProductMeta::FFLHUB_UPC_META, true));
        if ($upc === '') {
            return;
        }

        $selected_dist = strtolower(trim((string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true)));
        $ffl_required  = ((int) $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true) === 1);

        $item->add_meta_data('_fflhub_managed', 1, true);
        $item->add_meta_data('_fflhub_upc', $upc, true);
        $item->add_meta_data('_fflhub_selected_distributor', $selected_dist, true);
        $item->add_meta_data('_fflhub_ffl_required', $ffl_required ? 1 : 0, true);

        // Optional audit.
        $true_cost = $product->get_meta(ProductMeta::FFLHUB_LAST_TRUE_COST_META, true);
        if ($true_cost !== '') {
            $item->add_meta_data('_fflhub_true_cost_snapshot', $true_cost, true);
        }
    }

    /**
     * @param \WC_Order $order
     * @param array $data
     */
    public static function lock_in_order_meta($order, $data): void
    {
        $ffl = self::read_receiving_ffl_number_from_request();

        self::debug_log('lock_in_order_meta', [
            'order_id' => is_object($order) ? $order->get_id() : null,
            'ffl_from_request' => $ffl ?: '(none)',
        ]);

        if ($ffl) {
            self::set_receiving_ffl_on_order($order, $ffl);
        }
    }

    /**
     * Copies internal shipping meta from the chosen rate onto the order meta.
     *
     * @param \WC_Order_Item_Shipping $shipping_item
     * @param string $package_key
     * @param array $package
     * @param \WC_Order $order
     */
    public static function lock_in_shipping_meta($shipping_item, $package_key, $package, $order): void
    {
        if (! $shipping_item || ! is_object($shipping_item) || ! $order || ! is_object($order)) {
            return;
        }

        // Only capture from our shipping method.
        $method_id = (string) $shipping_item->get_method_id();
        if ($method_id !== 'fflhub_shipping') {
            return;
        }

        $map = [
            'fflhub_shipping_cost_total'      => '_fflhub_shipping_cost_total',
            'fflhub_customer_shipping_charge' => '_fflhub_customer_shipping_charge',
            'fflhub_profit_net_total'         => '_fflhub_profit_net_total',
            'fflhub_processor_fee_percent'    => '_fflhub_processor_fee_percent',
            'fflhub_shipping_by_dist'         => '_fflhub_shipping_by_dist',
            'fflhub_free_shipping_applied'    => '_fflhub_free_shipping_applied',
        ];

        $captured = [];

        foreach ($map as $from => $to) {
            $val = $shipping_item->get_meta($from, true);
            if ($val === '' || $val === null) {
                continue;
            }
            $order->update_meta_data($to, $val);
            $captured[$to] = $val;
        }

        self::debug_log('lock_in_shipping_meta', [
            'order_id' => $order->get_id(),
            'method_id' => $method_id,
            'captured_keys' => array_keys($captured),
        ]);
    }

    /**
     * Submit POs per distributor after order is marked "processing".
     *
     * @param int $order_id
     */
    public static function submit_purchase_orders_on_processing($order_id): void
    {
        $order = wc_get_order($order_id);
        if (! $order) {
            self::debug_log('submit: order not found', ['order_id' => $order_id]);
            return;
        }

        self::debug_log('submit: begin', [
            'order_id' => $order->get_id(),
            'status' => $order->get_status(),
            'dry_run' => self::dry_run_enabled() ? 1 : 0,
            'already_submitted' => $order->get_meta('_fflhub_pos_submitted', true) ? 1 : 0,

            // Helpful to see the shipping meta we captured:
            'shipping_cost_total' => (string) $order->get_meta('_fflhub_shipping_cost_total', true),
            'customer_shipping_charge' => (string) $order->get_meta('_fflhub_customer_shipping_charge', true),
        ]);

        // Prevent double-submit.
        if ($order->get_meta('_fflhub_pos_submitted', true)) {
            self::debug_log('submit: skip (already submitted)', ['order_id' => $order->get_id()]);
            return;
        }

        $handler = Plugin::instance()->distributor_handler;
        $distributors = $handler->get_distributors();

        self::debug_log('submit: distributors registered', [
            'count' => is_array($distributors) ? count($distributors) : 0,
            'ids' => is_array($distributors) ? implode(',', array_keys($distributors)) : '',
        ]);

        if (empty($distributors)) {
            $order->add_order_note('[FFLHub] No distributors registered; cannot submit POs.');
            return;
        }

        /**
         * Groups by distributor AND destination bucket.
         * - 'ffl' lines ship to receiving FFL
         * - 'customer' lines ship to buyer shipping address
         *
         * @var array<string,array{ffl:DistributorOrderLine[], customer:DistributorOrderLine[]}> $groups
         */
        $groups = [];

        $has_ffl_lines = false;

        foreach ($order->get_items('line_item') as $item_id => $item) {
            $name = method_exists($item, 'get_name') ? (string) $item->get_name() : 'Line Item';
            $qty  = (int) $item->get_quantity();

            $managed = (int) $item->get_meta('_fflhub_managed', true);
            $upc = trim((string) $item->get_meta('_fflhub_upc', true));
            $dist_id = strtolower(trim((string) $item->get_meta('_fflhub_selected_distributor', true)));
            $ffl_required = ((int) $item->get_meta('_fflhub_ffl_required', true) === 1);

            self::debug_log('submit: item meta', [
                'item_id' => $item_id,
                'name' => $name,
                'qty' => $qty,
                'managed' => $managed,
                'upc' => $upc ?: '(none)',
                'selected_dist' => $dist_id ?: '(none)',
                'ffl_required' => $ffl_required ? 1 : 0,
            ]);

            if ($managed !== 1 || $upc === '' || $qty < 1) {
                continue;
            }

            if ($dist_id === '') {
                $order->add_order_note('[FFLHub] Missing selected distributor for an item (UPC ' . $upc . '); holding PO submission.');
                continue;
            }

            if (! isset($groups[$dist_id])) {
                $groups[$dist_id] = ['ffl' => [], 'customer' => []];
            }

            $line = new DistributorOrderLine($upc, $qty, $ffl_required);

            if ($ffl_required) {
                $has_ffl_lines = true;
                $groups[$dist_id]['ffl'][] = $line;
            } else {
                $groups[$dist_id]['customer'][] = $line;
            }
        }

        self::debug_log('submit: grouping summary', [
            'has_ffl_lines' => $has_ffl_lines ? 1 : 0,
            'group_count' => count($groups),
            'groups' => array_map(static function ($bucket) {
                $fmt = static function (array $lines): string {
                    $out = [];
                    foreach ($lines as $l) {
                        $out[] = $l->upc . ' x' . $l->quantity . ($l->ffl_required ? ' (FFL)' : '');
                    }
                    return implode('; ', $out);
                };

                return [
                    'customer' => $fmt($bucket['customer'] ?? []),
                    'ffl' => $fmt($bucket['ffl'] ?? []),
                ];
            }, $groups),
        ]);

        if (empty($groups)) {
            $order->add_order_note('[FFLHub] No FFLHub-managed line items found; no POs submitted.');
            $order->update_meta_data('_fflhub_pos_submitted', 1);
            $order->save();
            return;
        }

        $dest_state = self::resolve_destination_state_from_order($order) ?: '';
        $receiving_ffl = self::get_receiving_ffl_from_order($order);

        self::debug_log('submit: order context', [
            'dest_state' => $dest_state ?: '(none)',
            'receiving_ffl_order_meta' => $receiving_ffl ?: '(none)',
            'has_ffl_lines' => $has_ffl_lines ? 1 : 0,
        ]);

        // Always build customer ship-to.
        $ship_to_customer = self::build_ship_to_from_customer($order);
        if (! $ship_to_customer) {
            $order->add_order_note('[FFLHub] Unable to determine customer ship-to address; no POs submitted.');
            return;
        }

        // Build FFL ship-to only if there are FFL lines.
        $ship_to_ffl = null;
        if ($has_ffl_lines) {
            if ($receiving_ffl === '') {
                $order->add_order_note('[FFLHub] Missing receiving FFL number; FFL items will NOT be submitted.');
            } else {
                $ship_to_ffl = self::build_ship_to_from_receiving_ffl($order, $receiving_ffl);
                if (! $ship_to_ffl) {
                    $order->add_order_note('[FFLHub] Unable to determine receiving FFL ship-to address; FFL items will NOT be submitted.');
                }
            }
        }

        // DRY RUN: write notes; real submission comes later.
        if (self::dry_run_enabled()) {
            foreach ($groups as $dist_id => $bucket) {

                if (! empty($bucket['customer'])) {
                    $summary = [];
                    foreach ($bucket['customer'] as $l) {
                        $summary[] = $l->upc . ' x' . $l->quantity;
                    }

                    $order->add_order_note(
                        '[FFLHub][DRY RUN] Would submit CUSTOMER PO to ' . strtoupper($dist_id)
                        . ' | ShipToCustomer=' . self::format_shipto_one_line($ship_to_customer)
                        . ' | DestState=' . ($dest_state !== '' ? $dest_state : '(none)')
                        . ' | Lines: ' . implode('; ', $summary)
                    );
                }

                if (! empty($bucket['ffl'])) {
                    if (! ($ship_to_ffl instanceof DistributorShipTo)) {
                        $order->add_order_note(
                            '[FFLHub][DRY RUN] FFL PO to ' . strtoupper($dist_id)
                            . ' would be submitted, but receiving FFL ship-to is missing.'
                        );
                    } else {
                        $summary = [];
                        foreach ($bucket['ffl'] as $l) {
                            $summary[] = $l->upc . ' x' . $l->quantity . ' (FFL)';
                        }

                        $order->add_order_note(
                            '[FFLHub][DRY RUN] Would submit FFL PO to ' . strtoupper($dist_id)
                            . ' | ShipToFFL=' . self::format_shipto_one_line($ship_to_ffl)
                            . ' | ReceivingFFL=' . ($receiving_ffl !== '' ? $receiving_ffl : '(none)')
                            . ' | Lines: ' . implode('; ', $summary)
                        );
                    }
                }
            }

            $order->update_meta_data('_fflhub_pos_submitted', 1);
            $order->save();
            return;
        }

        $order->add_order_note('[FFLHub] Dry run disabled but distributor ordering not implemented yet.');
    }

    /* ---------------- Receiving FFL meta compatibility ---------------- */

    private static function get_receiving_ffl_from_order(\WC_Order $order): string
    {
        $a = strtoupper(trim((string) $order->get_meta('_fflhub_receiving_ffl_number', true)));
        if ($a !== '') {
            return $a;
        }

        $b = strtoupper(trim((string) $order->get_meta('fflhub_receiving_ffl_number', true)));
        return $b;
    }

    private static function set_receiving_ffl_on_order(\WC_Order $order, string $ffl): void
    {
        $ffl = strtoupper(trim($ffl));
        if ($ffl === '') {
            return;
        }

        $order->update_meta_data('_fflhub_receiving_ffl_number', $ffl);
        $order->update_meta_data('fflhub_receiving_ffl_number', $ffl);
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

        $prefix = '[FFLHub OrderProcurement] ';
        if (! empty($context)) {
            error_log($prefix . $msg . ' ' . wp_json_encode($context));
            return;
        }
        error_log($prefix . $msg);
    }

    private static function dry_run_enabled(): bool
    {
        if (defined(self::DRY_RUN_CONST)) {
            return (bool) constant(self::DRY_RUN_CONST);
        }

        $env = getenv(self::DRY_RUN_CONST);
        if ($env !== false) {
            $env = strtolower(trim((string) $env));
            return in_array($env, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private static function format_shipto_one_line(DistributorShipTo $s): string
    {
        $parts = [
            trim($s->name),
            trim($s->company),
            trim($s->address1),
            trim($s->city) . ', ' . trim($s->state) . ' ' . trim($s->zip),
        ];
        $parts = array_filter($parts, static fn($v) => $v !== '');
        return implode(' | ', $parts);
    }

    /* ---------------- Shipping + FFL helpers ---------------- */

    private static function build_ship_to_from_customer(\WC_Order $order): ?DistributorShipTo
    {
        $name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
        $company = (string) $order->get_shipping_company();
        $address1 = (string) $order->get_shipping_address_1();
        $address2 = (string) $order->get_shipping_address_2();
        $city = (string) $order->get_shipping_city();
        $state = (string) $order->get_shipping_state();
        $zip = (string) $order->get_shipping_postcode();

        if ($address1 === '' || $city === '' || $state === '' || $zip === '') {
            return null;
        }

        $phone = (string) $order->get_billing_phone();
        $email = (string) $order->get_billing_email();

        return new DistributorShipTo(
            $name !== '' ? $name : (string) $order->get_formatted_billing_full_name(),
            $company,
            $address1,
            $address2,
            $city,
            $state,
            $zip,
            $phone,
            $email
        );
    }

    private static function build_ship_to_from_receiving_ffl(\WC_Order $order, string $ffl_number): ?DistributorShipTo
    {
        $row = self::lookup_ffl_row($ffl_number);

        self::debug_log('ffl lookup', [
            'ffl_number' => $ffl_number,
            'found' => $row ? 1 : 0,
        ]);

        if (! $row) {
            return null;
        }

        $license_name   = (string) ($row['license_name'] ?? '');
        $premise_street = (string) ($row['premise_street'] ?? '');
        $premise_city   = (string) ($row['premise_city'] ?? '');
        $premise_state  = (string) ($row['premise_state'] ?? '');
        $premise_zip    = (string) ($row['premise_zip'] ?? '');
        $voice_phone    = (string) ($row['voice_phone'] ?? '');

        if ($premise_street === '' || $premise_city === '' || $premise_state === '' || $premise_zip === '') {
            return null;
        }

        $phone = $voice_phone !== '' ? $voice_phone : (string) $order->get_billing_phone();
        $email = (string) $order->get_billing_email();

        $ship_name = $license_name !== '' ? $license_name : 'Receiving FFL';

        return new DistributorShipTo(
            $ship_name,
            $license_name,
            $premise_street,
            '',
            $premise_city,
            $premise_state,
            $premise_zip,
            $phone,
            $email
        );
    }

    private static function resolve_destination_state_from_order(\WC_Order $order): ?string
    {
        $state = strtoupper(trim((string) $order->get_shipping_state()));
        if (preg_match('/^[A-Z]{2}$/', $state)) {
            return $state;
        }

        $state = strtoupper(trim((string) $order->get_billing_state()));
        return preg_match('/^[A-Z]{2}$/', $state) ? $state : null;
    }

    /**
     * Best-effort read of the receiving FFL number from checkout request payload.
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

    /**
     * @return array<string,mixed>|null
     */
    private static function lookup_ffl_row(string $ffl_number): ?array
    {
        global $wpdb;

        $ffl_number = strtoupper(trim(sanitize_text_field($ffl_number)));
        if ($ffl_number === '') {
            return null;
        }

        $table = FFLImporterPage::get_table_name_public();
        if (! $table) {
            return null;
        }

        $sql = "SELECT * FROM {$table} WHERE ffl_number = %s LIMIT 1";
        $prepared = $wpdb->prepare($sql, $ffl_number);
        $row = $wpdb->get_row($prepared, ARRAY_A);

        return is_array($row) ? $row : null;
    }
}
