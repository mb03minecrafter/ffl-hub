<?php

namespace FFLHub\Shipping\Methods;

use FFLHub\Product\ProductMeta;
use FFLHub\Util\DebugLogUtil;
use WC_Shipping_Method;
use WC_Product;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FFL Hub Shipping (per distributor, split FFL vs non-FFL, cart-level free shipping rule)
 *
 * RULES:
 * 1) Group cart items by source distributor.
 * 2) For each distributor group:
 *    - Split items into two buckets:
 *        a) FFL-required items
 *        b) Non-FFL items
 *    - Shipping cost for that distributor:
 *        ship_dist = max(ship_cost among FFL items) + max(ship_cost among non-FFL items)
 *      (empty bucket => 0)
 * 3) Total shipping cost-to-you:
 *      S_total = sum(ship_dist) across distributors.
 *
 * FREE SHIPPING RULE:
 * - Compute cart profit P_total (net after processor fee) using stored true cost meta.
 * - If S_total < 2 * P_total, customer shipping = 0.
 * - Else customer pays full shipping grossed-up so you net S_total after processor fee:
 *      customer_charge = S_total / (1 - f)
 *
 * DEBUG:
 * - Controlled by constant FFLHUB_DEBUG_SHIPPING (true/false).
 */
class FFLHubShippingMethod extends WC_Shipping_Method
{
    public function __construct($instance_id = 0)
    {
        $this->id                 = 'fflhub_shipping';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = 'FFL Hub Shipping';
        $this->method_description = 'Shipping grouped by distributor, split into FFL vs non-FFL shipments, with cart-level free shipping rule.';
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

            'fallback_shipping' => [
                'title'       => 'Fallback shipping (per bucket)',
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
        $t0 = microtime(true);

        // Processor percent fee (e.g. 2.9)
        $fee_percent = (float) get_option('fflhub_payment_processor_fee_percent', '2.9');
        $f = $fee_percent / 100.0;

        // Clamp
        if ($f < 0.0) {
            $f = 0.0;
        }
        if ($f >= 0.99) {
            $f = 0.99;
        }

        $fallback_ship = (float) $this->get_option('fallback_shipping', '15.00');
        $min_cart_ship = (float) $this->get_option('min_shipping', '0');
        $max_cart_ship = (float) $this->get_option('max_shipping', '0');

        $this->log_debug(
            sprintf(
                '[FFLHub][Shipping] START fee_percent=%.4f f=%.4f fallback=%.2f min=%.2f max=%.2f items=%d',
                $fee_percent,
                $f,
                $fallback_ship,
                $min_cart_ship,
                $max_cart_ship,
                is_array($package['contents'] ?? null) ? count($package['contents']) : 0
            )
        );

        /**
         * dist_id => [
         *   'ffl_ship_max' => float,
         *   'non_ship_max' => float,
         * ]
         */
        $by_dist = [];

        // Cart-level profit (net after fee) across ALL items
        $profit_net_total = 0.0;

        foreach (($package['contents'] ?? []) as $item_key => $item) {
            if (empty($item['data']) || empty($item['quantity'])) {
                $this->log_debug(sprintf('[FFLHub][Shipping] SKIP item_key=%s missing data/quantity', (string) $item_key));
                continue;
            }

            /** @var WC_Product $product */
            $product = $item['data'];
            $qty     = (int) $item['quantity'];
            if ($qty < 1) {
                $qty = 1;
            }

            $product_id = method_exists($product, 'get_id') ? (int) $product->get_id() : 0;

            // Distributor id (grouping key)
            $dist_id = (string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true);
            if ($dist_id === '') {
                $dist_id = 'unknown';
            }

            // FFL bucket?
            $ffl_required_raw = $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true);
            $is_ffl = ! empty($ffl_required_raw) && (string) $ffl_required_raw !== '0';

            // Shipping estimate for this item
            $ship_raw = $product->get_meta(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, true);
            $ship = ($ship_raw === '' || $ship_raw === null) ? $fallback_ship : (float) $ship_raw;
            if (! is_finite($ship) || $ship < 0) {
                $ship = $fallback_ship;
            }

            if (! isset($by_dist[$dist_id])) {
                $by_dist[$dist_id] = [
                    'ffl_ship_max' => 0.0,
                    'non_ship_max' => 0.0,
                ];
            }

            $prev_ffl = (float) $by_dist[$dist_id]['ffl_ship_max'];
            $prev_non = (float) $by_dist[$dist_id]['non_ship_max'];

            if ($is_ffl) {
                $by_dist[$dist_id]['ffl_ship_max'] = max($prev_ffl, $ship);
            } else {
                $by_dist[$dist_id]['non_ship_max'] = max($prev_non, $ship);
            }

            // Stored true cost (used exactly like your previous method)
            $true_cost_raw = $product->get_meta(ProductMeta::FFLHUB_LAST_TRUE_COST_META, true);
            $true_cost = ($true_cost_raw === '' || $true_cost_raw === null) ? 0.0 : (float) $true_cost_raw;

            // Revenue ex-tax, after coupons (Woo line_total includes qty)
            $line_revenue = isset($item['line_total']) ? (float) $item['line_total'] : 0.0;

            // Net profit after processor % fee (same behavior as before)
            $line_profit_net = ($line_revenue - ($true_cost * $qty)) * (1.0 - $f);
            $profit_net_total += $line_profit_net;

            $this->log_debug(
                sprintf(
                    '[FFLHub][Shipping] item product_id=%d dist=%s bucket=%s qty=%d ship=%.2f (raw=%s) revenue=%.2f true_cost=%.2f profit_net=%.2f',
                    $product_id,
                    $dist_id,
                    $is_ffl ? 'FFL' : 'NON',
                    $qty,
                    $ship,
                    ($ship_raw === '' || $ship_raw === null) ? 'fallback' : (string) $ship_raw,
                    $line_revenue,
                    $true_cost,
                    $line_profit_net
                )
            );
        }

        // 1) Compute total shipping cost-to-you as sum of per-dist split maxima
        $shipping_cost_total = 0.0;

        foreach ($by_dist as $dist_id => $g) {
            $ffl_max = (float) ($g['ffl_ship_max'] ?? 0.0);
            $non_max = (float) ($g['non_ship_max'] ?? 0.0);

            $ship_dist = max(0.0, $ffl_max) + max(0.0, $non_max);
            $shipping_cost_total += $ship_dist;

            $this->log_debug(
                sprintf(
                    '[FFLHub][Shipping] dist=%s ffl_max=%.2f non_max=%.2f ship_dist=%.2f',
                    (string) $dist_id,
                    $ffl_max,
                    $non_max,
                    $ship_dist
                )
            );
        }

        // 2) Apply cart-level free shipping rule
        $customer_charge = 0.0;

        if ($shipping_cost_total <= 0.0) {
            $customer_charge = 0.0;
            $this->log_debug('[FFLHub][Shipping] shipping_cost_total <= 0, customer_charge=0');
        } else {
            $free_threshold = 0.5 * (float) $profit_net_total;

            $this->log_debug(
                sprintf(
                    '[FFLHub][Shipping] totals profit_net_total=%.2f shipping_cost_total=%.2f free_threshold(0.5xprofit)=%.2f',
                    $profit_net_total,
                    $shipping_cost_total,
                    $free_threshold
                )
            );

            if ($profit_net_total > 0.0 && $shipping_cost_total < $free_threshold) {
                $customer_charge = 0.0;
                $this->log_debug('[FFLHub][Shipping] FREE SHIPPING applied (shipping_cost_total < .5x profit)');
            } else {
                $customer_charge = ($f >= 0.99) ? $shipping_cost_total : ($shipping_cost_total / (1.0 - $f));
                $this->log_debug(
                    sprintf(
                        '[FFLHub][Shipping] charged full shipping grossed-up: customer_charge=%.2f (net_to_you=%.2f)',
                        $customer_charge,
                        $shipping_cost_total
                    )
                );
            }
        }

        // 3) Cart-level clamps
        $before_clamp = $customer_charge;

        $customer_charge = max($min_cart_ship, $customer_charge);
        if ($max_cart_ship > 0.0) {
            $customer_charge = min($max_cart_ship, $customer_charge);
        }

        if (abs($customer_charge - $before_clamp) > 0.0001) {
            $this->log_debug(
                sprintf(
                    '[FFLHub][Shipping] clamps applied: before=%.2f after=%.2f (min=%.2f max=%.2f)',
                    $before_clamp,
                    $customer_charge,
                    $min_cart_ship,
                    $max_cart_ship
                )
            );
        }

        $this->add_rate([
            'id'    => $this->id . ':' . $this->instance_id,
            'label' => $this->title,
            'cost'  => wc_format_decimal($customer_charge, wc_get_price_decimals()),

            // ✅ Internal meta that will be copied onto the order later
            'meta_data' => [
                // What YOU pay (net) for shipping this order, per your dist/bucket rule
                'fflhub_shipping_cost_total' => (string) wc_format_decimal($shipping_cost_total, 4),

                // What customer was charged at checkout for shipping (already in 'cost', but nice to have)
                'fflhub_customer_shipping_charge' => (string) wc_format_decimal($customer_charge, 4),

                // Profit net used in the decision
                'fflhub_profit_net_total' => (string) wc_format_decimal($profit_net_total, 4),

                // Processor fee percent used
                'fflhub_processor_fee_percent' => (string) wc_format_decimal($fee_percent, 4),

                // Full grouping details (dist -> ffl_max/non_max) for auditing + PO allocation later
                'fflhub_shipping_by_dist' => wp_json_encode($by_dist),

                // Optional: record whether free shipping rule triggered
                'fflhub_free_shipping_applied' => ($customer_charge <= 0.0001) ? '1' : '0',
            ],
        ]);


        $elapsed_ms = (microtime(true) - $t0) * 1000.0;

        $this->log_debug(
            sprintf(
                '[FFLHub][Shipping] END customer_charge=%.2f elapsed_ms=%.2f',
                $customer_charge,
                $elapsed_ms
            )
        );
    }

    private function log_debug(string $message): void
    {
        DebugLogUtil::log('FFLHUB_DEBUG_SHIPPING', '[FFLHub][ShippingMethod]', $message);
    }
}
