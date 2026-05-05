<?php

if (!defined('ABSPATH')) {
    if (defined('STDERR')) {
        fwrite(STDERR, "This script must be run through WP-CLI eval-file.\n");
    }
    return;
}

if (!function_exists('wc_get_order')) {
    fwrite(STDERR, "WooCommerce is not loaded. Confirm WooCommerce is active.\n");
    return;
}

$order_id = isset($args[0]) ? (int) $args[0] : 8169;
if ($order_id <= 0) {
    fwrite(STDERR, "Usage: wp eval-file wp-content/plugins/ffl-hub/scripts/debug-order-shipping-decision.php -- <order_id>\n");
    return;
}

$order = wc_get_order($order_id);
if (!$order instanceof WC_Order) {
    fwrite(STDERR, "Order not found: {$order_id}\n");
    return;
}

if (!function_exists('fflhub_debug_number')) {
    /**
     * @param mixed $value
     */
    function fflhub_debug_number($value, float $default = 0.0): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return $default;
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', $raw);
        if (!is_string($normalized) || $normalized === '' || !is_numeric($normalized)) {
            return $default;
        }

        return (float) $normalized;
    }
}

if (!function_exists('fflhub_debug_money')) {
    function fflhub_debug_money(float $value): string
    {
        return function_exists('wc_format_decimal')
            ? (string) wc_format_decimal($value, 4)
            : number_format($value, 4, '.', '');
    }
}

if (!function_exists('fflhub_debug_decode_array')) {
    /**
     * @param mixed $value
     * @return array<int|string,mixed>
     */
    function fflhub_debug_decode_array($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            if (function_exists('maybe_unserialize')) {
                $unserialized = maybe_unserialize($value);
                if (is_array($unserialized)) {
                    return $unserialized;
                }
            }
        }

        return [];
    }
}

if (!function_exists('fflhub_debug_product_meta')) {
    /**
     * @return array<string,mixed>
     */
    function fflhub_debug_product_meta(?WC_Product $product): array
    {
        if (!$product instanceof WC_Product) {
            return [];
        }

        $keys = [
            '_fflhub_primary_distributor',
            '_fflhub_last_dealer_price',
            '_fflhub_last_shipping_cost',
            '_fflhub_markup_mode',
            '_fflhub_map_real_price_free_shipping_override',
            '_fflhub_dropship_enabled',
            '_fflhub_ffl_required',
            '_fflhub_shipping_weight',
            '_fflhub_shipping_length_in',
            '_fflhub_shipping_width_in',
            '_fflhub_shipping_height_in',
            '_fflhub_local_stock_override_enabled',
            '_fflhub_local_stock_override_qty',
            '_fflhub_local_stock_free_shipping',
        ];

        $meta = [];
        foreach ($keys as $key) {
            $meta[$key] = $product->get_meta($key, true);
        }

        return $meta;
    }
}

$fee_percent = fflhub_debug_number(get_option('fflhub_payment_processor_fee_percent', '2.9'), 2.9);
if ($fee_percent < 0.0) {
    $fee_percent = 0.0;
}
$fee_fraction = min(0.99, $fee_percent / 100.0);
$free_shipping_max_profit_spend_percent = fflhub_debug_number(
    get_option('fflhub_free_shipping_max_profit_spend_percent', '50'),
    50.0
);
if ($free_shipping_max_profit_spend_percent < 0.0) {
    $free_shipping_max_profit_spend_percent = 0.0;
}
if ($free_shipping_max_profit_spend_percent > 100.0) {
    $free_shipping_max_profit_spend_percent = 100.0;
}
$minimum_profit_after_free_shipping = 0.01;

$profit_net_total = 0.0;
$line_rows = [];

foreach ($order->get_items('line_item') as $item_id => $item) {
    if (!$item instanceof WC_Order_Item_Product) {
        continue;
    }

    $product = $item->get_product();
    $qty = max(0, (int) $item->get_quantity());
    $line_revenue = (float) $item->get_total();
    $saved_unit_cost_raw = $item->get_meta('_fflhub_order_distributor_unit_cost', true);
    $product_unit_cost_raw = ($product instanceof WC_Product)
        ? $product->get_meta('_fflhub_last_dealer_price', true)
        : '';

    $saved_unit_cost = is_numeric($saved_unit_cost_raw) ? (float) $saved_unit_cost_raw : null;
    $product_unit_cost = is_numeric($product_unit_cost_raw) ? (float) $product_unit_cost_raw : null;
    $unit_cost = ($saved_unit_cost !== null)
        ? $saved_unit_cost
        : (($product_unit_cost !== null) ? $product_unit_cost : 0.0);

    $line_profit_net = ($line_revenue * (1.0 - $fee_fraction)) - ($unit_cost * (float) $qty);
    $profit_net_total += $line_profit_net;

    $line_rows[] = [
        'item_id' => (int) $item_id,
        'product_id' => ($product instanceof WC_Product) ? (int) $product->get_id() : (int) $item->get_product_id(),
        'sku' => ($product instanceof WC_Product) ? (string) $product->get_sku() : '',
        'name' => (string) $item->get_name(),
        'qty' => $qty,
        'line_revenue_ex_tax' => fflhub_debug_money($line_revenue),
        'unit_cost_used' => fflhub_debug_money($unit_cost),
        'unit_cost_source' => ($saved_unit_cost !== null) ? '_fflhub_order_distributor_unit_cost' : '_fflhub_last_dealer_price',
        'line_profit_net_for_checkout_rule' => fflhub_debug_money($line_profit_net),
        'order_item_source_distributor' => (string) $item->get_meta('_fflhub_order_source_distributor', true),
        'order_item_unit_cost_meta' => $saved_unit_cost_raw,
        'product_meta' => fflhub_debug_product_meta($product instanceof WC_Product ? $product : null),
    ];
}

$shipping_items = [];
$shipping_cost_total = 0.0;
$customer_chargeable_shipping_cost_total = 0.0;
$customer_shipping_charge_from_meta = 0.0;
$customer_free_shipping_credit_total = 0.0;
$ca_surcharge_total = 0.0;
$free_meta_seen = false;

foreach ($order->get_items('shipping') as $item_id => $shipping_item) {
    if (!$shipping_item instanceof WC_Order_Item_Shipping) {
        continue;
    }

    $plan = fflhub_debug_decode_array($shipping_item->get_meta('fflhub_shipping_plan', true));
    $by_dist = fflhub_debug_decode_array($shipping_item->get_meta('fflhub_shipping_by_dist', true));

    $item_shipping_cost = fflhub_debug_number(
        $shipping_item->get_meta('fflhub_shipping_cost_total', true),
        fflhub_debug_number($plan['total_cost'] ?? null, (float) $shipping_item->get_total())
    );
    $item_chargeable_cost = fflhub_debug_number(
        $shipping_item->get_meta('fflhub_customer_chargeable_shipping_cost_total', true),
        fflhub_debug_number($plan['customer_chargeable_shipping_cost_total'] ?? null, $item_shipping_cost)
    );
    $item_customer_charge = fflhub_debug_number(
        $shipping_item->get_meta('fflhub_customer_shipping_charge', true),
        (float) $shipping_item->get_total()
    );
    $item_free_credit = fflhub_debug_number(
        $shipping_item->get_meta('fflhub_customer_free_shipping_credit_total', true),
        fflhub_debug_number($plan['customer_free_shipping_credit_total'] ?? null, 0.0)
    );
    $item_ca_surcharge = fflhub_debug_number(
        $shipping_item->get_meta('fflhub_ca_shipping_surcharge', true),
        fflhub_debug_number($plan['ca_shipping_surcharge'] ?? null, 0.0)
    );

    $shipping_cost_total += max(0.0, $item_shipping_cost);
    $customer_chargeable_shipping_cost_total += max(0.0, $item_chargeable_cost);
    $customer_shipping_charge_from_meta += max(0.0, $item_customer_charge);
    $customer_free_shipping_credit_total += max(0.0, $item_free_credit);
    $ca_surcharge_total += max(0.0, $item_ca_surcharge);
    $free_meta_seen = $free_meta_seen || (string) $shipping_item->get_meta('fflhub_free_shipping_applied', true) === '1';

    $option_name = 'woocommerce_' . $shipping_item->get_method_id() . '_' . $shipping_item->get_instance_id() . '_settings';
    $method_settings = ($shipping_item->get_instance_id() > 0) ? get_option($option_name, []) : [];

    $shipping_items[] = [
        'item_id' => (int) $item_id,
        'method_id' => (string) $shipping_item->get_method_id(),
        'instance_id' => (int) $shipping_item->get_instance_id(),
        'method_title' => (string) $shipping_item->get_method_title(),
        'customer_total_on_item' => fflhub_debug_money((float) $shipping_item->get_total()),
        'fflhub_shipping_cost_total' => fflhub_debug_money($item_shipping_cost),
        'fflhub_customer_chargeable_shipping_cost_total' => fflhub_debug_money($item_chargeable_cost),
        'fflhub_customer_shipping_charge' => fflhub_debug_money($item_customer_charge),
        'fflhub_customer_free_shipping_credit_total' => fflhub_debug_money($item_free_credit),
        'fflhub_ca_shipping_surcharge' => fflhub_debug_money($item_ca_surcharge),
        'fflhub_free_shipping_applied' => (string) $shipping_item->get_meta('fflhub_free_shipping_applied', true),
        'fflhub_customer_free_shipping_product_applied' => (string) $shipping_item->get_meta('fflhub_customer_free_shipping_product_applied', true),
        'method_settings_option' => $option_name,
        'method_settings' => $method_settings,
        'plan_summary' => [
            'total_cost' => isset($plan['total_cost']) ? fflhub_debug_money(fflhub_debug_number($plan['total_cost'])) : null,
            'distributor_cost_total' => isset($plan['distributor_cost_total']) ? fflhub_debug_money(fflhub_debug_number($plan['distributor_cost_total'])) : null,
            'dealer_outbound_home_cost' => isset($plan['dealer_outbound_home_cost']) ? fflhub_debug_money(fflhub_debug_number($plan['dealer_outbound_home_cost'])) : null,
            'dealer_outbound_ffl_cost' => isset($plan['dealer_outbound_ffl_cost']) ? fflhub_debug_money(fflhub_debug_number($plan['dealer_outbound_ffl_cost'])) : null,
            'customer_chargeable_shipping_cost_total' => isset($plan['customer_chargeable_shipping_cost_total']) ? fflhub_debug_money(fflhub_debug_number($plan['customer_chargeable_shipping_cost_total'])) : null,
            'customer_free_shipping_credit_total' => isset($plan['customer_free_shipping_credit_total']) ? fflhub_debug_money(fflhub_debug_number($plan['customer_free_shipping_credit_total'])) : null,
            'free_shipping_profit_rule' => $plan['free_shipping_profit_rule'] ?? null,
            'ca_shipping_surcharge_applied' => $plan['ca_shipping_surcharge_applied'] ?? null,
            'ca_shipping_surcharge' => isset($plan['ca_shipping_surcharge']) ? fflhub_debug_money(fflhub_debug_number($plan['ca_shipping_surcharge'])) : null,
            'ca_shipping_surcharge_customer_state' => $plan['ca_shipping_surcharge_customer_state'] ?? null,
            'assignments' => $plan['assignments'] ?? [],
            'by_dist' => $plan['by_dist'] ?? $by_dist,
            'customer_chargeable_by_dist' => $plan['customer_chargeable_by_dist'] ?? [],
            'meta' => $plan['meta'] ?? [],
        ],
    ];
}

$legacy_half_profit_threshold = 0.5 * $profit_net_total;
$free_threshold = 0.0;
if ($profit_net_total > $minimum_profit_after_free_shipping) {
    $free_threshold = min(
        $profit_net_total * ($free_shipping_max_profit_spend_percent / 100.0),
        $profit_net_total - $minimum_profit_after_free_shipping
    );
}
$free_threshold = max(0.0, $free_threshold);
$current_code_would_free = ($profit_net_total > 0.0 && $shipping_cost_total <= ($free_threshold + 0.0001));
$chargeable_basis_would_free = ($profit_net_total > 0.0 && $customer_chargeable_shipping_cost_total <= ($free_threshold + 0.0001));
$legacy_half_profit_rule_would_free = ($profit_net_total > 0.0 && $shipping_cost_total < $legacy_half_profit_threshold);
$grossed_chargeable_shipping = ($fee_fraction >= 0.99)
    ? $customer_chargeable_shipping_cost_total
    : ($customer_chargeable_shipping_cost_total / (1.0 - $fee_fraction));

$order_meta_keys = [
    'fflhub_order_profit_audit_version',
    'fflhub_order_revenue_total',
    'fflhub_order_item_cost_total',
    'fflhub_order_shipping_cost_total',
    'fflhub_order_distributor_shipping_cost_total',
    'fflhub_order_shipping_label_cost_total',
    'fflhub_order_customer_shipping_charge',
    'fflhub_order_processor_fee_percent',
    'fflhub_order_processor_fee_amount',
    'fflhub_order_actual_profit_total',
];

$order_meta = [];
foreach ($order_meta_keys as $key) {
    $order_meta[$key] = $order->get_meta($key, true);
}

$out = [
    'order' => [
        'id' => $order_id,
        'number' => (string) $order->get_order_number(),
        'status' => (string) $order->get_status(),
        'created' => $order->get_date_created() ? $order->get_date_created()->date('c') : '',
        'billing_state' => (string) $order->get_billing_state(),
        'billing_postcode' => (string) $order->get_billing_postcode(),
        'shipping_state' => (string) $order->get_shipping_state(),
        'shipping_postcode' => (string) $order->get_shipping_postcode(),
        'order_total' => fflhub_debug_money((float) $order->get_total()),
        'tax_total' => fflhub_debug_money((float) $order->get_total_tax()),
        'customer_shipping_total' => fflhub_debug_money((float) $order->get_shipping_total()),
    ],
    'checkout_rule_recomputed_from_order' => [
        'payment_processor_fee_percent' => fflhub_debug_money($fee_percent),
        'free_shipping_max_profit_spend_percent' => fflhub_debug_money($free_shipping_max_profit_spend_percent),
        'minimum_profit_after_free_shipping' => fflhub_debug_money($minimum_profit_after_free_shipping),
        'profit_net_total_for_threshold' => fflhub_debug_money($profit_net_total),
        'free_threshold_configured' => fflhub_debug_money($free_threshold),
        'legacy_free_threshold_half_profit_net_total' => fflhub_debug_money($legacy_half_profit_threshold),
        'shipping_cost_total_basis_used_by_current_code' => fflhub_debug_money($shipping_cost_total),
        'customer_chargeable_shipping_cost_total' => fflhub_debug_money($customer_chargeable_shipping_cost_total),
        'grossed_customer_chargeable_shipping_before_clamps_or_surcharge' => fflhub_debug_money($grossed_chargeable_shipping),
        'current_code_would_free_using_full_shipping_cost' => $current_code_would_free ? 'yes' : 'no',
        'chargeable_basis_would_free_if_rule_used_customer_chargeable_shipping' => $chargeable_basis_would_free ? 'yes' : 'no',
        'legacy_half_profit_rule_would_free' => $legacy_half_profit_rule_would_free ? 'yes' : 'no',
        'fflhub_free_shipping_meta_seen_on_shipping_item' => $free_meta_seen ? 'yes' : 'no',
        'customer_free_shipping_credit_total' => fflhub_debug_money($customer_free_shipping_credit_total),
        'ca_surcharge_total' => fflhub_debug_money($ca_surcharge_total),
        'customer_shipping_charge_from_fflhub_meta' => fflhub_debug_money($customer_shipping_charge_from_meta),
    ],
    'stored_order_profit_audit_meta' => $order_meta,
    'line_items' => $line_rows,
    'shipping_items' => $shipping_items,
];

echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
