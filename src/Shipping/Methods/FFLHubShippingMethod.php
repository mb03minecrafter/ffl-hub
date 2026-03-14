<?php

namespace FFLHub\Shipping\Methods;

use FFLHub\Distributor\Services\Routing\DealerFulfillmentRoutingPlanner;
use FFLHub\Product\ProductMeta;
use FFLHub\Util\DebugLogUtil;
use WC_Shipping_Method;
use WC_Product;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FFL Hub Shipping (routing-aware, cart-level free shipping rule)
 *
 * Routing model:
 * - Non-dropship lines are always dealer_fulfilled.
 * - Dropship-enabled lines are optimized as direct_ship vs dealer_fulfilled.
 * - Cost includes:
 *   - distributor lane fees (dealer_inbound/direct_home/direct_ffl)
 *   - dealer outbound home/ffl costs from total weight.
 *
 * FREE SHIPPING RULE:
 * - Compute cart profit P_total (net after processor fee) using stored true cost meta.
 * - If S_total < 0.5 * P_total, customer shipping = 0.
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
        $this->method_description = 'Shipping optimized across direct-ship and dealer-fulfilled lanes with cart-level free shipping rule.';
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

    public function is_available($package): bool
    {
        if (! parent::is_available($package)) {
            return false;
        }

        $package_type = isset($package['fflhub_package_type']) ? (string) $package['fflhub_package_type'] : '';
        if ($package_type === 'external') {
            return false;
        }
        if ($package_type === 'fflhub') {
            return true;
        }

        return $this->package_has_fflhub_items($package);
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
                'START items=%d fee_percent=%.2f fallback=%s clamp_min=%s clamp_max=%s',
                is_array($package['contents'] ?? null) ? count($package['contents']) : 0,
                $fee_percent,
                $this->fmt_money($fallback_ship),
                $this->fmt_money($min_cart_ship),
                $this->fmt_money($max_cart_ship)
            )
        );

        $by_dist = [];
        $plan = [];

        // Planner input lines (shared model with future order routing work)
        $routing_lines = [];
        $line_debug_rows = [];

        // Cart-level profit (net after fee) across ALL FFLHub items in this package
        $profit_net_total = 0.0;

        foreach (($package['contents'] ?? []) as $item_key => $item) {
            if (empty($item['data']) || empty($item['quantity'])) {
                $this->log_debug(sprintf('SKIP line_id=%s reason=missing_data_or_quantity', (string) $item_key));
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
                $this->log_debug(
                    sprintf(
                        'SKIP product_id=%d reason=non_fflhub_item',
                        $product_id
                    )
                );
                continue;
            }

            // FFL bucket?
            $ffl_required_raw = $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true);
            $is_ffl = ! empty($ffl_required_raw) && (string) $ffl_required_raw !== '0';

            // Dropship eligibility (default true if unset).
            $dropship_enabled_raw = $product->get_meta(ProductMeta::FFLHUB_DROPSHIP_ENABLED_META, true);
            $dropship_enabled = $this->to_boolish($dropship_enabled_raw, true);

            // Distributor lane fee for this line (used as per-lane fee by planner).
            $ship_raw = $product->get_meta(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, true);
            $ship = ($ship_raw === '' || $ship_raw === null) ? $fallback_ship : (float) $ship_raw;
            if (! is_finite($ship) || $ship < 0) {
                $ship = $fallback_ship;
            }

            // Per-unit shipping weight in ounces.
            $weight_raw = $product->get_meta(ProductMeta::FFLHUB_SHIPPING_WEIGHT_META, true);
            $weight_oz  = $this->to_non_negative_float($weight_raw, 0.0);
            $line_weight_oz = $weight_oz * (float) $qty;

            $routing_lines[] = [
                'line_id'          => (string) $item_key,
                'dist_id'          => strtolower(trim((string) $dist_id)),
                'qty'              => $qty,
                'weight_oz'        => $weight_oz,
                'ffl_required'     => $is_ffl ? 1 : 0,
                'dropship_enabled' => $dropship_enabled ? 1 : 0,
                'dist_lane_fee'    => $ship,
            ];

            // Stored true cost (used exactly like your previous method)
            $true_cost_raw = $product->get_meta(ProductMeta::FFLHUB_LAST_TRUE_COST_META, true);
            $true_cost = ($true_cost_raw === '' || $true_cost_raw === null) ? 0.0 : (float) $true_cost_raw;

            // Revenue ex-tax, after coupons (Woo line_total includes qty)
            $line_revenue = isset($item['line_total']) ? (float) $item['line_total'] : 0.0;

            // Net profit after processor % fee (same behavior as before)
            $line_profit_net = ($line_revenue - ($true_cost * $qty)) * (1.0 - $f);
            $profit_net_total += $line_profit_net;

            $line_debug_rows[] = [
                'line_id'        => (string) $item_key,
                'product_id'     => $product_id,
                'dist_id'        => strtolower(trim((string) $dist_id)),
                'qty'            => $qty,
                'ffl_required'   => $is_ffl,
                'dropship'       => $dropship_enabled,
                'lane_fee'       => $ship,
                'weight_oz'      => $weight_oz,
                'line_weight_oz' => $line_weight_oz,
                'line_revenue'   => $line_revenue,
                'true_cost'      => $true_cost,
                'profit_net'     => $line_profit_net,
            ];
        }

        // 1) Compute optimal shipping cost-to-you from routing planner
        $plan = DealerFulfillmentRoutingPlanner::find_cheapest_plan($routing_lines);
        $shipping_cost_total = max(0.0, (float) ($plan['total_cost'] ?? 0.0));
        $by_dist = (isset($plan['by_dist']) && is_array($plan['by_dist'])) ? $plan['by_dist'] : [];
        $assignments = (isset($plan['assignments']) && is_array($plan['assignments'])) ? $plan['assignments'] : [];

        foreach ($line_debug_rows as $idx => $row) {
            $line_id = (string) ($row['line_id'] ?? '');
            $route = isset($assignments[$line_id]) ? (string) $assignments[$line_id] : (!empty($row['dropship']) ? 'direct_ship' : 'dealer_fulfilled');

            $this->log_debug(
                sprintf(
                    'LINE %d product=%d dist=%s qty=%d ffl=%d dropship=%d route=%s lane_fee=%s wt_oz=%.2f line_wt_oz=%.2f revenue=%s true_cost=%s profit_net=%s',
                    $idx + 1,
                    (int) ($row['product_id'] ?? 0),
                    (string) ($row['dist_id'] ?? ''),
                    (int) ($row['qty'] ?? 0),
                    !empty($row['ffl_required']) ? 1 : 0,
                    !empty($row['dropship']) ? 1 : 0,
                    $route,
                    $this->fmt_money((float) ($row['lane_fee'] ?? 0.0)),
                    (float) ($row['weight_oz'] ?? 0.0),
                    (float) ($row['line_weight_oz'] ?? 0.0),
                    $this->fmt_money((float) ($row['line_revenue'] ?? 0.0)),
                    $this->fmt_money((float) ($row['true_cost'] ?? 0.0)),
                    $this->fmt_money((float) ($row['profit_net'] ?? 0.0))
                )
            );
        }

        foreach ($by_dist as $dist_id => $dist_row) {
            $lanes = [];
            if (!empty($dist_row['dealer_inbound'])) {
                $lanes[] = 'dealer_inbound';
            }
            if (!empty($dist_row['direct_home'])) {
                $lanes[] = 'direct_home';
            }
            if (!empty($dist_row['direct_ffl'])) {
                $lanes[] = 'direct_ffl';
            }

            $this->log_debug(
                sprintf(
                    'DIST %s lane_fee=%s lanes=%s active=%d dist_cost=%s',
                    (string) $dist_id,
                    $this->fmt_money((float) ($dist_row['lane_fee'] ?? 0.0)),
                    empty($lanes) ? '-' : implode(',', $lanes),
                    (int) ($dist_row['active_lanes'] ?? 0),
                    $this->fmt_money((float) ($dist_row['cost'] ?? 0.0))
                )
            );
        }

        $dealer_home_cost = (float) ($plan['dealer_outbound_home_cost'] ?? 0.0);
        $dealer_ffl_cost = (float) ($plan['dealer_outbound_ffl_cost'] ?? 0.0);
        $dealer_home_oz = (float) ($plan['dealer_outbound_home_oz'] ?? 0.0);
        $dealer_ffl_oz = (float) ($plan['dealer_outbound_ffl_oz'] ?? 0.0);
        $outbound_total = $dealer_home_cost + $dealer_ffl_cost;

        $this->log_debug(
            sprintf(
                'OUTBOUND dealer_home wt_oz=%.2f cost=%s | dealer_ffl wt_oz=%.2f cost=%s',
                $dealer_home_oz,
                $this->fmt_money($dealer_home_cost),
                $dealer_ffl_oz,
                $this->fmt_money($dealer_ffl_cost)
            )
        );

        $this->log_debug(
            sprintf(
                'PLAN total=%s dist_total=%s outbound_total=%s decision_lines=%d combos=%d',
                $this->fmt_money($shipping_cost_total),
                $this->fmt_money((float) ($plan['distributor_cost_total'] ?? 0.0)),
                $this->fmt_money($outbound_total),
                (int) (($plan['meta']['decision_lines'] ?? 0)),
                (int) (($plan['meta']['combinations_evaluated'] ?? 0))
            )
        );

        // 2) Apply cart-level free shipping rule
        $customer_charge = 0.0;

        if ($shipping_cost_total <= 0.0) {
            $customer_charge = 0.0;
            $this->log_debug('RULE shipping_cost_total=0 so customer_charge=$0.00');
        } else {
            $free_threshold = 0.5 * (float) $profit_net_total;

            $this->log_debug(
                sprintf(
                    'RULE profit_net_total=%s shipping_cost_total=%s free_threshold=%s',
                    $this->fmt_money($profit_net_total),
                    $this->fmt_money($shipping_cost_total),
                    $this->fmt_money($free_threshold)
                )
            );

            if ($profit_net_total > 0.0 && $shipping_cost_total < $free_threshold) {
                $customer_charge = 0.0;
                $this->log_debug('RULE free_shipping=yes');
            } else {
                $customer_charge = ($f >= 0.99) ? $shipping_cost_total : ($shipping_cost_total / (1.0 - $f));
                $this->log_debug(
                    sprintf(
                        'RULE free_shipping=no customer_charge=%s net_shipping_cost=%s',
                        $this->fmt_money($customer_charge),
                        $this->fmt_money($shipping_cost_total)
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
                    'CLAMP before=%s after=%s min=%s max=%s',
                    $this->fmt_money($before_clamp),
                    $this->fmt_money($customer_charge),
                    $this->fmt_money($min_cart_ship),
                    $this->fmt_money($max_cart_ship)
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

                // Planner output details (distributor lanes + routing assignments)
                'fflhub_shipping_by_dist' => wp_json_encode($by_dist),
                'fflhub_shipping_plan_version' => 'dealer_fulfilled_v1',
                'fflhub_shipping_plan' => wp_json_encode($plan),

                // Optional: record whether free shipping rule triggered
                'fflhub_free_shipping_applied' => ($customer_charge <= 0.0001) ? '1' : '0',
            ],
        ]);


        $elapsed_ms = (microtime(true) - $t0) * 1000.0;

        $this->log_debug(
            sprintf(
                'END customer_charge=%s elapsed_ms=%.2f',
                $this->fmt_money($customer_charge),
                $elapsed_ms
            )
        );
    }

    private function log_debug(string $message): void
    {
        DebugLogUtil::log('FFLHUB_DEBUG_SHIPPING', '[FFLHub][ShippingMethod]', $message);
    }

    private function fmt_money(float $value): string
    {
        return '$' . number_format($value, 2, '.', '');
    }

    /**
     * Parse truthy/falsey values from product meta with a default fallback.
     *
     * @param mixed $value
     */
    private function to_boolish($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return $default;
        }

        if (in_array($raw, ['1', 'true', 't', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($raw, ['0', 'false', 'f', 'no', 'n', 'off'], true)) {
            return false;
        }

        if (is_numeric($raw)) {
            return ((float) $raw) !== 0.0;
        }

        return $default;
    }

    /**
     * Parse a non-negative float from product meta.
     *
     * @param mixed $value
     */
    private function to_non_negative_float($value, float $default = 0.0): float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return max(0.0, $default);
        }

        $num = $raw;
        if (!is_numeric($num)) {
            $num = trim((string) preg_replace('/[^0-9\.\-]/', '', $raw));
        }

        if ($num === '' || !is_numeric($num)) {
            return max(0.0, $default);
        }

        $v = (float) $num;
        if (!is_finite($v) || $v < 0.0) {
            return max(0.0, $default);
        }

        return $v;
    }

    private function package_has_fflhub_items($package): bool
    {
        foreach (($package['contents'] ?? []) as $item) {
            $product = $item['data'] ?? null;
            if (! $product instanceof WC_Product) {
                continue;
            }

            $dist_id = (string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true);
            if ($dist_id !== '') {
                return true;
            }
        }

        return false;
    }
}
