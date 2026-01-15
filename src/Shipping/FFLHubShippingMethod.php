<?php

namespace FFLHub\Shipping;

use FFLHub\Product\ProductMeta;
use WC_Shipping_Method;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * FFL Hub Shipping (Smooth Discount, per distributor)
 *
 * For each distributor shipment:
 *   - S = max(_fflhub_last_shipping_cost) across items from that distributor (cost-to-you estimate)
 *   - P = net profit on those items (after processor % fee) computed using stored true cost meta
 *
 * Smooth discount rule:
 *   r = P / S
 *   if r <= k0: customer pays full shipping (grossed-up): S/(1-f)
 *   if r >= k1: customer pays 0
 *   else: linearly interpolate between full and free:
 *         u = (r - k0) / (k1 - k0)  clamped to [0,1]
 *         customer_charge = (S/(1-f)) * (1 - u)
 *
 * Total shipping = sum of customer charges across distributors.
 */
class FFLHubShippingMethod extends WC_Shipping_Method
{
    public function __construct($instance_id = 0)
    {
        $this->id                 = 'fflhub_shipping';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = 'FFL Hub Shipping';
        $this->method_description = 'Shipping based on FFL Hub product meta, grouped by distributor (smooth discount).';
        $this->supports           = ['shipping-zones', 'instance-settings'];

        $this->init();
    }

    public function init(): void
    {
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', 'Shipping');

        add_action(
            'woocommerce_update_options_shipping_' . $this->id,
            [$this, 'process_admin_options']
        );
    }

    public function init_form_fields(): void
    {
        $this->instance_form_fields = [
            'title' => [
                'title'       => 'Method title',
                'type'        => 'text',
                'description' => 'Shown to customers at checkout.',
                'default'     => 'Shipping',
            ],

            // Smooth discount thresholds
            'k0' => [
                'title'       => 'k0 (discount starts)',
                'type'        => 'number',
                'description' => 'If Profit/Shipping <= k0, customer pays full shipping. Default: 1.0',
                'default'     => '1.0',
                'custom_attributes' => [
                    'step' => '0.1',
                    'min'  => '0',
                ],
            ],
            'k1' => [
                'title'       => 'k1 (free shipping)',
                'type'        => 'number',
                'description' => 'If Profit/Shipping >= k1, shipping is free. Default: 2.0',
                'default'     => '2.0',
                'custom_attributes' => [
                    'step' => '0.1',
                    'min'  => '0',
                ],
            ],

            'fallback_shipping' => [
                'title'       => 'Fallback shipping (per distributor)',
                'type'        => 'price',
                'description' => 'Used when product shipping meta is missing/empty.',
                'default'     => '15.00',
            ],

            // Optional cart-level clamps
            'min_shipping' => [
                'title'       => 'Minimum shipping (cart)',
                'type'        => 'price',
                'description' => 'Minimum shipping charge for the entire cart.',
                'default'     => '0',
            ],
            'max_shipping' => [
                'title'       => 'Maximum shipping (cart)',
                'type'        => 'price',
                'description' => '0 = no cap.',
                'default'     => '0',
            ],
        ];
    }

    public function calculate_shipping($package = []): void
    {
        // Processor percent fee (e.g. 2.9)
        $fee_percent = (float) get_option('fflhub_payment_processor_fee_percent', '2.9');
        $f = $fee_percent / 100.0;

        // Clamp
        if ($f < 0.0) $f = 0.0;
        if ($f >= 0.99) $f = 0.99;

        $k0 = (float) $this->get_option('k0', '1.0');
        $k1 = (float) $this->get_option('k1', '2.0');

        // Ensure k1 > k0; if misconfigured, force a sane gap.
        if ($k1 <= $k0) {
            $k1 = $k0 + 0.01;
        }

        $fallback_ship = (float) $this->get_option('fallback_shipping', '15.00');
        $min_cart_ship = (float) $this->get_option('min_shipping', '0');
        $max_cart_ship = (float) $this->get_option('max_shipping', '0');

        // dist_id => ['ship_max' => float, 'profit_net' => float]
        $by_dist = [];

        foreach (($package['contents'] ?? []) as $item) {
            if (empty($item['data']) || empty($item['quantity'])) {
                continue;
            }

            /** @var WC_Product $product */
            $product = $item['data'];
            $qty     = (int) $item['quantity'];
            if ($qty < 1) $qty = 1;

            // Distributor id (grouping key)
            $dist_id = (string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true);
            if ($dist_id === '') {
                $dist_id = 'unknown';
            }

            // Max shipping estimate per item (your "max possible they could charge")
            $ship = $product->get_meta(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, true);
            $ship = ($ship === '' || $ship === null) ? $fallback_ship : (float) $ship;




            // Stored true cost (break-even price grossed-up for % fee; no shipping)
            $true_cost = $product->get_meta(ProductMeta::FFLHUB_LAST_TRUE_COST_META, true);
            $true_cost = ($true_cost === '' || $true_cost === null) ? 0.0 : (float) $true_cost;

            // Revenue ex-tax, qty-adjusted, after coupons
            $line_revenue = isset($item['line_total']) ? (float) $item['line_total'] : 0.0;

            // Net profit after processor % fee:
            // If true_cost = cost/(1-f), net profit = (revenue - true_cost*qty) * (1-f)
            $line_profit_net = ($line_revenue - ($true_cost * $qty)) * (1.0 - $f);

            if (!isset($by_dist[$dist_id])) {
                $by_dist[$dist_id] = [
                    'ship_max'   => 0.0,
                    'profit_net' => 0.0,
                ];
            }

            $by_dist[$dist_id]['ship_max']   = max($by_dist[$dist_id]['ship_max'], $ship);
            $by_dist[$dist_id]['profit_net'] += $line_profit_net;
        }

        // Compute customer shipping using smooth discount per distributor
        $total_shipping = 0.0;

        foreach ($by_dist as $dist_id => $g) {
            $S = (float) $g['ship_max'];    // cost-to-you estimate (max)
                        error_log("Shipping cost of: " . $S);

            $P = (float) $g['profit_net'];  // net profit on items from this dist

            if ($S <= 0.0) {
                // No shipping cost estimate => charge nothing (or you could charge fallback)
                continue;
            }

            // Full customer charge to net S after fees
            $full_charge = ($f >= 0.99) ? $S : ($S / (1.0 - $f));

            // Profit-to-shipping ratio
            $r = $P / $S;

            // Smooth subsidy fraction u in [0,1]
            $u = ($r - $k0) / ($k1 - $k0);
            if ($u < 0.0) $u = 0.0;
            if ($u > 1.0) $u = 1.0;

            // Customer pays (1-u) of the full charge
            $charge = $full_charge * (1.0 - $u);

            // Never negative
            if ($charge < 0.0) {
                $charge = 0.0;
            }

            $total_shipping += $charge;
        }

        // Cart-level clamps
        $total_shipping = max($min_cart_ship, $total_shipping);
        if ($max_cart_ship > 0.0) {
            $total_shipping = min($max_cart_ship, $total_shipping);
        }

        $this->add_rate([
            'id'    => $this->id . ':' . $this->instance_id,
            'label' => $this->title,
            'cost'  => wc_format_decimal($total_shipping, wc_get_price_decimals()),
        ]);
    }
}
