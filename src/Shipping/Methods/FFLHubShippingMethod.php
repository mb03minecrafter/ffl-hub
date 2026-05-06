<?php

namespace FFLHub\Shipping\Methods;

use FFLHub\Distributor\Services\Routing\DealerFulfillmentRoutingPlanner;
use FFLHub\FFL\Data\FFLRepository;
use FFLHub\FFL\Data\FFLRowMapper;
use FFLHub\FFL\Tables\FFLSchema;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Product\ProductMeta;
use FFLHub\Settings\Options;
use FFLHub\Shipping\USPS\USPSRateHelper;
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
 *   - dealer outbound home/ffl costs (USPS API when enabled; formula fallback).
 *
 * FREE SHIPPING RULE:
 * - Product MAP quote free-shipping override removes that product's routed
 *   shipping from the customer charge only; internal cost remains for audit.
 * - CA drop-ship surcharge is not treated as cost-to-us; it becomes
 *   customer-facing only if the full cart fails the free-shipping rule.
 * - Compute cart profit P_total (net after processor fee) using stored dealer cost meta.
 * - If shipping cost is within the configured profit-spend allowance, customer shipping = 0.
 * - Else customer pays chargeable shipping grossed-up so you net it after processor fee:
 *      customer_charge = S_total / (1 - f)
 *
 * DEBUG:
 * - Controlled by constant FFLHUB_DEBUG_SHIPPING (true/false).
 */
class FFLHubShippingMethod extends WC_Shipping_Method
{
    private const CA_SHIPPING_SURCHARGE = 10.0;
    private const MIN_PROFIT_AFTER_FREE_SHIPPING = 0.01;

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
                'title'       => 'Fallback shipping (per LANE)',
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
                '---- START ---- items=%d fee_percent=%.2f fallback=%s clamp_min=%s clamp_max=%s',
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
        $local_available_by_product = [];

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

            // FFL LANE?
            $ffl_required_raw = $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true);
            $is_ffl = ! empty($ffl_required_raw) && (string) $ffl_required_raw !== '0';

            // Dropship eligibility (default true if unset).
            $dropship_enabled_raw = $product->get_meta(ProductMeta::FFLHUB_DROPSHIP_ENABLED_META, true);
            $dropship_enabled = $this->to_boolish($dropship_enabled_raw, true);
            $customer_free_shipping = $this->product_customer_free_shipping_enabled($product);

            // Dealer cost excludes distributor shipping, which is planned once at the order level.
            $dealer_cost_raw = $product->get_meta(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, true);
            $dealer_cost = ($dealer_cost_raw === '' || $dealer_cost_raw === null) ? 0.0 : (float) $dealer_cost_raw;

            // Revenue ex-tax, after coupons (Woo line_total includes qty)
            $line_revenue = isset($item['line_total']) ? (float) $item['line_total'] : 0.0;

            // Net profit after processor % fee. The card fee is charged on revenue, not margin.
            $line_profit_net = ($line_revenue * (1.0 - $f)) - ($dealer_cost * $qty);
            $profit_net_total += $line_profit_net;

            $qty_for_routing = $qty;
            $local_free_ship_qty = 0;
            $local_free_ship_enabled = $this->to_boolish(
                $product->get_meta(ProductMeta::FFLHUB_LOCAL_STOCK_FREE_SHIPPING_META, true),
                false
            );
            $local_stock_override_enabled = $this->to_boolish(
                $product->get_meta(ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_ENABLED_META, true),
                false
            );

            if ($local_free_ship_enabled && $local_stock_override_enabled && $product_id > 0) {
                if (!array_key_exists($product_id, $local_available_by_product)) {
                    $local_qty_raw = $product->get_meta(ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_QTY_META, true);
                    $local_available_by_product[$product_id] = max(0, (int) $local_qty_raw);
                }

                $local_available_qty = max(0, (int) ($local_available_by_product[$product_id] ?? 0));
                if ($local_available_qty > 0) {
                    $local_free_ship_qty = min($qty_for_routing, $local_available_qty);
                    $qty_for_routing -= $local_free_ship_qty;
                    $local_available_by_product[$product_id] = $local_available_qty - $local_free_ship_qty;

                    $this->log_debug(
                        sprintf(
                            'LOCAL_FREE_SHIP product_id=%d qty_local=%d qty_routed=%d local_remaining=%d',
                            $product_id,
                            $local_free_ship_qty,
                            $qty_for_routing,
                            (int) $local_available_by_product[$product_id]
                        )
                    );
                }
            }

            if ($qty_for_routing <= 0) {
                continue;
            }

            // Distributor lane fee for this line (used as per-lane fee by planner).
            $ship_raw = $product->get_meta(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, true);
            $ship = $this->resolve_distributor_lane_fee($ship_raw, $fallback_ship);

            // Per-unit shipping weight in ounces.
            $weight_raw = $product->get_meta(ProductMeta::FFLHUB_SHIPPING_WEIGHT_META, true);
            $weight_oz  = $this->to_non_negative_float($weight_raw, 0.0);
            $line_weight_oz = $weight_oz * (float) $qty_for_routing;

            // Optional dimensions in inches (may be empty for some distributors).
            $length_raw = $product->get_meta(ProductMeta::FFLHUB_SHIPPING_LENGTH_IN_META, true);
            $width_raw  = $product->get_meta(ProductMeta::FFLHUB_SHIPPING_WIDTH_IN_META, true);
            $height_raw = $product->get_meta(ProductMeta::FFLHUB_SHIPPING_HEIGHT_IN_META, true);

            $length_in = $this->to_non_negative_float($length_raw, 0.0);
            $width_in  = $this->to_non_negative_float($width_raw, 0.0);
            $height_in = $this->to_non_negative_float($height_raw, 0.0);

            $routing_lines[] = [
                'line_id'          => (string) $item_key,
                'dist_id'          => strtolower(trim((string) $dist_id)),
                'qty'              => $qty_for_routing,
                'weight_oz'        => $weight_oz,
                'ffl_required'     => $is_ffl ? 1 : 0,
                'dropship_enabled' => $dropship_enabled ? 1 : 0,
                'dist_lane_fee'    => $ship,
                'length_in'        => $length_in,
                'width_in'         => $width_in,
                'height_in'        => $height_in,
                'customer_free_shipping' => $customer_free_shipping ? 1 : 0,
            ];
            $line_revenue_routed = ($qty > 0)
                ? ((float) $line_revenue * ((float) $qty_for_routing / (float) $qty))
                : 0.0;
            $line_profit_net_routed = ($line_revenue_routed * (1.0 - $f)) - ($dealer_cost * $qty_for_routing);

            $line_debug_rows[] = [
                'line_id'        => (string) $item_key,
                'product_id'     => $product_id,
                'dist_id'        => strtolower(trim((string) $dist_id)),
                'qty'            => $qty_for_routing,
                'qty_ordered'    => $qty,
                'qty_local_free' => $local_free_ship_qty,
                'ffl_required'   => $is_ffl,
                'dropship'       => $dropship_enabled,
                'customer_free_shipping' => $customer_free_shipping,
                'lane_fee'       => $ship,
                'weight_oz'      => $weight_oz,
                'line_weight_oz' => $line_weight_oz,
                'length_in'      => $length_in,
                'width_in'       => $width_in,
                'height_in'      => $height_in,
                'line_revenue'   => $line_revenue_routed,
                'dealer_cost'    => $dealer_cost,
                'profit_net'     => $line_profit_net_routed,
            ];
        }

        // 1) Compute optimal shipping cost-to-you from routing planner
        $plan = DealerFulfillmentRoutingPlanner::find_cheapest_plan($routing_lines);
        $planner_formula_total = (float) ($plan['total_cost'] ?? 0.0);
        $shipping_cost_total = max(0.0, (float) ($plan['total_cost'] ?? 0.0));
        $by_dist = (isset($plan['by_dist']) && is_array($plan['by_dist'])) ? $plan['by_dist'] : [];
        $assignments = (isset($plan['assignments']) && is_array($plan['assignments'])) ? $plan['assignments'] : [];

        foreach ($line_debug_rows as $idx => $row) {
            $line_id = (string) ($row['line_id'] ?? '');
            $route = isset($assignments[$line_id]) ? (string) $assignments[$line_id] : (!empty($row['dropship']) ? 'direct_ship' : 'dealer_fulfilled');

            $this->log_debug(
                sprintf(
                    'LINE %d product=%d dist=%s qty=%d ffl=%d dropship=%d customer_free_ship=%d route=%s lane_fee=%s wt_oz=%.2f line_wt_oz=%.2f revenue=%s dealer_cost=%s profit_net=%s',
                    $idx + 1,
                    (int) ($row['product_id'] ?? 0),
                    (string) ($row['dist_id'] ?? ''),
                    (int) ($row['qty'] ?? 0),
                    !empty($row['ffl_required']) ? 1 : 0,
                    !empty($row['dropship']) ? 1 : 0,
                    !empty($row['customer_free_shipping']) ? 1 : 0,
                    $route,
                    $this->fmt_money((float) ($row['lane_fee'] ?? 0.0)),
                    (float) ($row['weight_oz'] ?? 0.0),
                    (float) ($row['line_weight_oz'] ?? 0.0),
                    $this->fmt_money((float) ($row['line_revenue'] ?? 0.0)),
                    $this->fmt_money((float) ($row['dealer_cost'] ?? 0.0)),
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

        $dealer_home_cost_formula = (float) ($plan['dealer_outbound_home_cost'] ?? 0.0);
        $dealer_ffl_cost_formula = (float) ($plan['dealer_outbound_ffl_cost'] ?? 0.0);

        $dealer_home_pkg = $this->build_dealer_lane_package($line_debug_rows, $assignments, false);
        $dealer_ffl_pkg  = $this->build_dealer_lane_package($line_debug_rows, $assignments, true);

        $dealer_home_cost = $dealer_home_cost_formula;
        $dealer_ffl_cost  = $dealer_ffl_cost_formula;
        $dealer_home_oz   = (float) ($dealer_home_pkg['weight_oz'] ?? 0.0);
        $dealer_ffl_oz    = (float) ($dealer_ffl_pkg['weight_oz'] ?? 0.0);

        $dealer_home_source = 'formula';
        $dealer_ffl_source  = 'formula';

        $home_dest_zip = $this->resolve_home_destination_zip($package);
        $ffl_dest_zip  = $this->resolve_receiving_ffl_zip();

        $usps_helper = new USPSRateHelper();
        if ($usps_helper->is_enabled()) {
            if ($dealer_home_oz > 0.0 && $home_dest_zip !== '') {
                $home_quote = $usps_helper->estimate_rate([
                    'destination_zip' => $home_dest_zip,
                    'weight_oz'       => $dealer_home_oz,
                    'length_in'       => (float) ($dealer_home_pkg['length_in'] ?? 0.0),
                    'width_in'        => (float) ($dealer_home_pkg['width_in'] ?? 0.0),
                    'height_in'       => (float) ($dealer_home_pkg['height_in'] ?? 0.0),
                ]);

                if (!empty($home_quote['ok'])) {
                    $dealer_home_cost = max(0.0, (float) ($home_quote['cost'] ?? 0.0));
                    $dealer_home_source = 'usps_api';
                } else {
                    $this->log_debug(
                        sprintf(
                            'USPS home quote failed, using formula fallback error=%s',
                            (string) ($home_quote['error'] ?? 'unknown')
                        )
                    );
                }
            }

            if ($dealer_ffl_oz > 0.0 && $ffl_dest_zip !== '') {
                $ffl_quote = $usps_helper->estimate_rate([
                    'destination_zip' => $ffl_dest_zip,
                    'weight_oz'       => $dealer_ffl_oz,
                    'length_in'       => (float) ($dealer_ffl_pkg['length_in'] ?? 0.0),
                    'width_in'        => (float) ($dealer_ffl_pkg['width_in'] ?? 0.0),
                    'height_in'       => (float) ($dealer_ffl_pkg['height_in'] ?? 0.0),
                ]);

                if (!empty($ffl_quote['ok'])) {
                    $dealer_ffl_cost = max(0.0, (float) ($ffl_quote['cost'] ?? 0.0));
                    $dealer_ffl_source = 'usps_api';
                } else {
                    $this->log_debug(
                        sprintf(
                            'USPS ffl quote failed, using formula fallback error=%s',
                            (string) ($ffl_quote['error'] ?? 'unknown')
                        )
                    );
                }
            }
        }

        $dist_total = (float) ($plan['distributor_cost_total'] ?? 0.0);
        $outbound_total = $dealer_home_cost + $dealer_ffl_cost;
        $customer_dest_state = $this->resolve_customer_destination_state($package);
        $ca_surcharge_has_drop_ship_lane = $this->shipping_plan_has_drop_ship_lane($plan);
        $ca_surcharge = ($customer_dest_state === 'CA' && $ca_surcharge_has_drop_ship_lane)
            ? self::CA_SHIPPING_SURCHARGE
            : 0.0;
        $shipping_cost_total = max(0.0, $dist_total + $outbound_total);

        $plan['dealer_outbound_home_cost_formula'] = $dealer_home_cost_formula;
        $plan['dealer_outbound_ffl_cost_formula'] = $dealer_ffl_cost_formula;
        $plan['dealer_outbound_home_cost'] = $dealer_home_cost;
        $plan['dealer_outbound_ffl_cost'] = $dealer_ffl_cost;
        $plan['dealer_outbound_home_oz'] = $dealer_home_oz;
        $plan['dealer_outbound_ffl_oz'] = $dealer_ffl_oz;
        $plan['dealer_outbound_home_cost_source'] = $dealer_home_source;
        $plan['dealer_outbound_ffl_cost_source'] = $dealer_ffl_source;
        $plan['total_cost'] = $shipping_cost_total;
        $plan['ca_shipping_surcharge_applied'] = ($ca_surcharge > 0.0) ? 1 : 0;
        $plan['ca_shipping_surcharge'] = $ca_surcharge;
        $plan['ca_shipping_surcharge_customer_state'] = $customer_dest_state;
        $plan['ca_shipping_surcharge_drop_ship_lane'] = $ca_surcharge_has_drop_ship_lane ? 1 : 0;
        $plan['meta']['outbound_pricing'] = [
            'home_source' => $dealer_home_source,
            'ffl_source'  => $dealer_ffl_source,
            'home_zip'    => $home_dest_zip,
            'ffl_zip'     => $ffl_dest_zip,
        ];

        $customer_shipping = $this->customer_chargeable_shipping_cost_summary(
            $line_debug_rows,
            $assignments,
            $usps_helper,
            $dealer_home_source,
            $dealer_ffl_source,
            $home_dest_zip,
            $ffl_dest_zip
        );
        $customer_chargeable_base_total = isset($customer_shipping['total'])
            ? max(0.0, (float) $customer_shipping['total'])
            : $shipping_cost_total;
        $customer_chargeable_shipping_cost_total = min(
            $shipping_cost_total,
            $customer_chargeable_base_total
        );
        $customer_free_shipping_credit_total = max(0.0, $shipping_cost_total - $customer_chargeable_shipping_cost_total);
        $customer_free_shipping_product_applied = $customer_free_shipping_credit_total > 0.0001;

        $plan['customer_chargeable_shipping_cost_total'] = $customer_chargeable_shipping_cost_total;
        $plan['customer_free_shipping_credit_total'] = $customer_free_shipping_credit_total;
        $plan['customer_chargeable_distributor_cost_total'] = (float) ($customer_shipping['distributor_cost_total'] ?? 0.0);
        $plan['customer_chargeable_dealer_outbound_home_cost'] = (float) ($customer_shipping['dealer_outbound_home_cost'] ?? 0.0);
        $plan['customer_chargeable_dealer_outbound_ffl_cost'] = (float) ($customer_shipping['dealer_outbound_ffl_cost'] ?? 0.0);
        $plan['customer_chargeable_by_dist'] = (array) ($customer_shipping['by_dist'] ?? []);
        $plan['customer_free_shipping_product_applied'] = $customer_free_shipping_product_applied ? 1 : 0;

        $this->log_debug(
            sprintf(
                'OUTBOUND dealer_home wt_oz=%.2f dims=%s zip=%s source=%s cost=%s | dealer_ffl wt_oz=%.2f dims=%s zip=%s source=%s cost=%s',
                $dealer_home_oz,
                $this->fmt_dims($dealer_home_pkg),
                ($home_dest_zip !== '' ? $home_dest_zip : '-'),
                $dealer_home_source,
                $this->fmt_money($dealer_home_cost),
                $dealer_ffl_oz,
                $this->fmt_dims($dealer_ffl_pkg),
                ($ffl_dest_zip !== '' ? $ffl_dest_zip : '-'),
                $dealer_ffl_source,
                $this->fmt_money($dealer_ffl_cost)
            )
        );

        $this->log_debug(
            sprintf(
                'PLAN total=%s dist_total=%s outbound_total=%s ca_surcharge=%s customer_chargeable=%s customer_free_credit=%s decision_lines=%d combos=%d',
                $this->fmt_money($shipping_cost_total),
                $this->fmt_money($dist_total),
                $this->fmt_money($outbound_total),
                $this->fmt_money($ca_surcharge),
                $this->fmt_money($customer_chargeable_shipping_cost_total),
                $this->fmt_money($customer_free_shipping_credit_total),
                (int) (($plan['meta']['decision_lines'] ?? 0)),
                (int) (($plan['meta']['combinations_evaluated'] ?? 0))
            )
        );
        if ($ca_surcharge > 0.0) {
            $this->log_debug(
                sprintf(
                    'CA_SURCHARGE state=%s drop_ship_lane=yes amount=%s eligible_customer_surcharge=yes cost_to_us=no',
                    $customer_dest_state,
                    $this->fmt_money($ca_surcharge)
                )
            );
        } elseif ($customer_dest_state === 'CA') {
            $this->log_debug('CA_SURCHARGE skipped because no drop-ship lane is active.');
        }
        $this->log_planner_alternatives($plan, $line_debug_rows, $planner_formula_total);

        // 2) Apply cart-level free shipping rule
        $customer_charge = 0.0;
        $free_shipping_max_profit_spend_percent = Options::get_free_shipping_max_profit_spend_percent();
        $free_threshold = $this->free_shipping_cost_threshold(
            (float) $profit_net_total,
            $free_shipping_max_profit_spend_percent
        );
        $profit_after_free_shipping = (float) $profit_net_total - (float) $shipping_cost_total;
        $profit_based_free_shipping_applies = $this->should_apply_profit_based_free_shipping(
            (float) $profit_net_total,
            (float) $shipping_cost_total,
            $free_shipping_max_profit_spend_percent
        );

        $plan['free_shipping_profit_rule'] = [
            'max_profit_spend_percent' => $free_shipping_max_profit_spend_percent,
            'minimum_profit_after_free_shipping' => self::MIN_PROFIT_AFTER_FREE_SHIPPING,
            'profit_net_total' => (float) $profit_net_total,
            'shipping_cost_threshold' => $free_threshold,
            'profit_after_free_shipping' => $profit_after_free_shipping,
            'matched' => $profit_based_free_shipping_applies ? 1 : 0,
        ];

        if ($shipping_cost_total <= 0.0) {
            $customer_charge = 0.0;
            $this->log_debug('RULE shipping_cost_total=0 so customer_charge=$0.00');
        } else {
            $this->log_debug(
                sprintf(
                    'RULE profit_net_total=%s shipping_cost_total=%s customer_chargeable_shipping_cost_total=%s max_profit_spend_pct=%.2f min_profit_after_free=%s free_threshold=%s profit_after_free=%s',
                    $this->fmt_money($profit_net_total),
                    $this->fmt_money($shipping_cost_total),
                    $this->fmt_money($customer_chargeable_shipping_cost_total),
                    $free_shipping_max_profit_spend_percent,
                    $this->fmt_money(self::MIN_PROFIT_AFTER_FREE_SHIPPING),
                    $this->fmt_money($free_threshold),
                    $this->fmt_money($profit_after_free_shipping)
                )
            );

            if ($profit_based_free_shipping_applies) {
                $customer_charge = 0.0;
                $this->log_debug('RULE free_shipping=yes basis=shipping_cost_total_profit_spend_setting');
            } else {
                $charge_basis_shipping_cost = $customer_chargeable_shipping_cost_total;
                if ($charge_basis_shipping_cost <= 0.0 && $shipping_cost_total > 0.0) {
                    $charge_basis_shipping_cost = $shipping_cost_total;
                    $this->log_debug('RULE charge_basis restored to shipping_cost_total because full shipping failed free threshold');
                }
                if ($ca_surcharge > 0.0) {
                    $charge_basis_shipping_cost += $ca_surcharge;
                    $this->log_debug(
                        sprintf(
                            'RULE CA_SURCHARGE customer-facing because free shipping failed amount=%s charge_basis_after=%s',
                            $this->fmt_money($ca_surcharge),
                            $this->fmt_money($charge_basis_shipping_cost)
                        )
                    );
                }

                $customer_charge = ($f >= 0.99)
                    ? $charge_basis_shipping_cost
                    : ($charge_basis_shipping_cost / (1.0 - $f));
                $this->log_debug(
                    sprintf(
                        'RULE free_shipping=no customer_charge=%s net_shipping_cost=%s basis_shipping_cost=%s charge_basis=%s',
                        $this->fmt_money($customer_charge),
                        $this->fmt_money($customer_chargeable_shipping_cost_total),
                        $this->fmt_money($shipping_cost_total),
                        $this->fmt_money($charge_basis_shipping_cost)
                    )
                );
            }
        }

        // 3) Cart-level clamps
        $before_clamp = $customer_charge;

        if ($customer_chargeable_shipping_cost_total > 0.0001) {
            $customer_charge = max($min_cart_ship, $customer_charge);
            if ($max_cart_ship > 0.0) {
                $customer_charge = min($max_cart_ship, $customer_charge);
            }
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

        $plan['ca_shipping_surcharge_customer_facing'] = ($ca_surcharge > 0.0 && $customer_charge > 0.0001) ? 1 : 0;
        $ca_surcharge_customer_facing = !empty($plan['ca_shipping_surcharge_customer_facing']);

        $plan_version = (($dealer_home_source === 'usps_api') || ($dealer_ffl_source === 'usps_api'))
            ? 'dealer_fulfilled_v2_usps_outbound'
            : 'dealer_fulfilled_v1';

        $this->add_rate([
            'id'    => $this->id . ':' . $this->instance_id,
            'label' => $this->title,
            'cost'  => wc_format_decimal($customer_charge, wc_get_price_decimals()),

            // Internal meta that will be copied onto the order later
            'meta_data' => [
                // What YOU pay (net) for shipping this order, per your dist/LANE rule
                'fflhub_shipping_cost_total' => (string) wc_format_decimal($shipping_cost_total, 4),

                // What customer was charged at checkout for shipping (already in 'cost', but nice to have)
                'fflhub_customer_shipping_charge' => (string) wc_format_decimal($customer_charge, 4),
                'fflhub_customer_chargeable_shipping_cost_total' => (string) wc_format_decimal($customer_chargeable_shipping_cost_total, 4),
                'fflhub_customer_free_shipping_credit_total' => (string) wc_format_decimal($customer_free_shipping_credit_total, 4),
                'fflhub_ca_shipping_surcharge' => (string) wc_format_decimal($ca_surcharge, 4),
                'fflhub_ca_shipping_surcharge_applied' => ($ca_surcharge > 0.0) ? '1' : '0',
                'fflhub_ca_shipping_surcharge_customer_facing' => $ca_surcharge_customer_facing ? '1' : '0',

                // Planner output details (distributor lanes + routing assignments)
                'fflhub_shipping_by_dist' => wp_json_encode($by_dist),
                'fflhub_shipping_plan_version' => $plan_version,
                'fflhub_shipping_plan' => wp_json_encode($plan),

                // Optional: record whether free shipping rule triggered
                'fflhub_free_shipping_applied' => ($customer_charge <= 0.0001) ? '1' : '0',
                'fflhub_customer_free_shipping_product_applied' => $customer_free_shipping_product_applied ? '1' : '0',
            ],
        ]);


        $elapsed_ms = (microtime(true) - $t0) * 1000.0;

        $this->log_debug(
            sprintf(
                '---- END ---- customer_charge=%s elapsed_ms=%.2f',
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

    private function fmt_money_signed(float $value): string
    {
        $sign = ($value >= 0.0) ? '+' : '-';
        return $sign . '$' . number_format(abs($value), 2, '.', '');
    }

    private function should_apply_profit_based_free_shipping(
        float $profit_net_total,
        float $shipping_cost_total,
        float $max_profit_spend_percent
    ): bool {
        if ($profit_net_total <= 0.0 || $shipping_cost_total <= 0.0) {
            return false;
        }

        $free_threshold = $this->free_shipping_cost_threshold($profit_net_total, $max_profit_spend_percent);
        if ($free_threshold <= 0.0) {
            return false;
        }

        return $shipping_cost_total <= ($free_threshold + 0.0001);
    }

    private function free_shipping_cost_threshold(float $profit_net_total, float $max_profit_spend_percent): float
    {
        if ($profit_net_total <= self::MIN_PROFIT_AFTER_FREE_SHIPPING) {
            return 0.0;
        }

        $max_profit_spend_percent = max(0.0, min(100.0, $max_profit_spend_percent));
        $percent_threshold = $profit_net_total * ($max_profit_spend_percent / 100.0);
        $penny_profit_threshold = $profit_net_total - self::MIN_PROFIT_AFTER_FREE_SHIPPING;

        return max(0.0, min($percent_threshold, $penny_profit_threshold));
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

    /**
     * Resolve distributor freight while preserving explicit free-freight values.
     *
     * Blank, invalid, or negative meta means "unknown" and uses fallback. A real
     * zero is valid for distributor programs such as Zanders free freight.
     *
     * @param mixed $value
     */
    private function resolve_distributor_lane_fee($value, float $fallback): float
    {
        $fallback = max(0.0, $fallback);
        $raw = trim((string) $value);
        if ($raw === '') {
            return $fallback;
        }

        $num = $raw;
        if (!is_numeric($num)) {
            $num = trim((string) preg_replace('/[^0-9\.\-]/', '', $raw));
        }

        if ($num === '' || !is_numeric($num)) {
            return $fallback;
        }

        $fee = (float) $num;
        if (!is_finite($fee) || $fee < 0.0) {
            return $fallback;
        }

        return $fee;
    }

    private function product_customer_free_shipping_enabled(WC_Product $product): bool
    {
        $mode_raw = $product->get_meta(ProductMeta::FFLHUB_MARKUP_MODE_META, true);
        $mode = ($mode_raw === '' && (string) $mode_raw !== '0')
            ? ProductMeta::MARKUP_MODE_GLOBAL
            : (int) $mode_raw;
        if ($mode !== ProductMeta::MARKUP_MODE_MAP_PRICE) {
            return false;
        }

        return $this->to_boolish(
            $product->get_meta(ProductMeta::FFLHUB_MAP_REAL_PRICE_FREE_SHIPPING_OVERRIDE_META, true),
            false
        );
    }

    /**
     * Build the shipping amount the customer can be charged after product-level
     * customer-free-shipping flags are removed. Internal distributor freight stays
     * untouched in the primary plan for profit audit.
     *
     * @param array<int,array<string,mixed>> $line_debug_rows
     * @param array<string,string> $assignments
     * @return array<string,mixed>
     */
    private function customer_chargeable_shipping_cost_summary(
        array $line_debug_rows,
        array $assignments,
        USPSRateHelper $usps_helper,
        string $dealer_home_source,
        string $dealer_ffl_source,
        string $home_dest_zip,
        string $ffl_dest_zip
    ): array {
        $dist_summary = $this->customer_chargeable_distributor_shipping_summary($line_debug_rows, $assignments);

        $home_pkg = $this->build_dealer_lane_package($line_debug_rows, $assignments, false, true);
        $ffl_pkg = $this->build_dealer_lane_package($line_debug_rows, $assignments, true, true);

        $home = $this->dealer_outbound_chargeable_cost($home_pkg, $usps_helper, $dealer_home_source, $home_dest_zip, 'home');
        $ffl = $this->dealer_outbound_chargeable_cost($ffl_pkg, $usps_helper, $dealer_ffl_source, $ffl_dest_zip, 'ffl');

        $dist_total = max(0.0, (float) ($dist_summary['total'] ?? 0.0));
        $home_cost = max(0.0, (float) ($home['cost'] ?? 0.0));
        $ffl_cost = max(0.0, (float) ($ffl['cost'] ?? 0.0));

        return [
            'total' => $dist_total + $home_cost + $ffl_cost,
            'distributor_cost_total' => $dist_total,
            'dealer_outbound_home_cost' => $home_cost,
            'dealer_outbound_ffl_cost' => $ffl_cost,
            'dealer_outbound_home_source' => (string) ($home['source'] ?? 'none'),
            'dealer_outbound_ffl_source' => (string) ($ffl['source'] ?? 'none'),
            'dealer_outbound_home_oz' => (float) ($home_pkg['weight_oz'] ?? 0.0),
            'dealer_outbound_ffl_oz' => (float) ($ffl_pkg['weight_oz'] ?? 0.0),
            'by_dist' => (array) ($dist_summary['by_dist'] ?? []),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $line_debug_rows
     * @param array<string,string> $assignments
     * @return array{total:float,by_dist:array<string,array<string,mixed>>}
     */
    private function customer_chargeable_distributor_shipping_summary(array $line_debug_rows, array $assignments): array
    {
        $by_dist = [];

        foreach ($line_debug_rows as $row) {
            if (!is_array($row) || !empty($row['customer_free_shipping'])) {
                continue;
            }

            $dist_id = strtolower(trim((string) ($row['dist_id'] ?? '')));
            if ($dist_id === '') {
                continue;
            }

            if (!isset($by_dist[$dist_id])) {
                $by_dist[$dist_id] = [
                    'dist_id' => $dist_id,
                    'lane_fee' => 0.0,
                    'dealer_inbound' => false,
                    'direct_home' => false,
                    'direct_ffl' => false,
                    'active_lanes' => 0,
                    'cost' => 0.0,
                ];
            }

            $line_fee = max(0.0, (float) ($row['lane_fee'] ?? 0.0));
            if ($line_fee > (float) $by_dist[$dist_id]['lane_fee']) {
                $by_dist[$dist_id]['lane_fee'] = $line_fee;
            }

            $line_id = (string) ($row['line_id'] ?? '');
            $route = isset($assignments[$line_id])
                ? (string) $assignments[$line_id]
                : (!empty($row['dropship']) ? 'direct_ship' : 'dealer_fulfilled');

            if ($route === 'dealer_fulfilled') {
                $by_dist[$dist_id]['dealer_inbound'] = true;
                continue;
            }

            if (!empty($row['ffl_required'])) {
                $by_dist[$dist_id]['direct_ffl'] = true;
            } else {
                $by_dist[$dist_id]['direct_home'] = true;
            }
        }

        $total = 0.0;
        foreach ($by_dist as $dist_id => $row) {
            $lane_count = 0;
            if (!empty($row['dealer_inbound'])) {
                $lane_count++;
            }
            if (!empty($row['direct_home'])) {
                $lane_count++;
            }
            if (!empty($row['direct_ffl'])) {
                $lane_count++;
            }

            $cost = max(0.0, (float) ($row['lane_fee'] ?? 0.0)) * (float) $lane_count;
            $by_dist[$dist_id]['active_lanes'] = $lane_count;
            $by_dist[$dist_id]['cost'] = $cost;
            $total += $cost;
        }

        ksort($by_dist);

        return [
            'total' => max(0.0, $total),
            'by_dist' => $by_dist,
        ];
    }

    /**
     * @param array<string,mixed> $pkg
     * @return array{cost:float,source:string}
     */
    private function dealer_outbound_chargeable_cost(
        array $pkg,
        USPSRateHelper $usps_helper,
        string $original_source,
        string $destination_zip,
        string $lane_label
    ): array {
        $weight_oz = max(0.0, (float) ($pkg['weight_oz'] ?? 0.0));
        $line_count = max(0, (int) ($pkg['line_count'] ?? 0));
        if ($weight_oz <= 0.0 || $line_count < 1) {
            return ['cost' => 0.0, 'source' => 'none'];
        }

        $formula_cost = DealerFulfillmentRoutingPlanner::estimate_dealer_outbound_cost($weight_oz);
        if ($original_source !== 'usps_api' || !$usps_helper->is_enabled() || $destination_zip === '') {
            return ['cost' => max(0.0, $formula_cost), 'source' => 'formula'];
        }

        $quote = $usps_helper->estimate_rate([
            'destination_zip' => $destination_zip,
            'weight_oz' => $weight_oz,
            'length_in' => (float) ($pkg['length_in'] ?? 0.0),
            'width_in' => (float) ($pkg['width_in'] ?? 0.0),
            'height_in' => (float) ($pkg['height_in'] ?? 0.0),
        ]);

        if (!empty($quote['ok'])) {
            return ['cost' => max(0.0, (float) ($quote['cost'] ?? 0.0)), 'source' => 'usps_api'];
        }

        $this->log_debug(
            sprintf(
                'USPS customer-chargeable %s quote failed, using formula fallback error=%s',
                $lane_label,
                (string) ($quote['error'] ?? 'unknown')
            )
        );

        return ['cost' => max(0.0, $formula_cost), 'source' => 'formula'];
    }

    /**
     * @param array<string,mixed> $pkg
     */
    private function fmt_dims(array $pkg): string
    {
        $l = (float) ($pkg['length_in'] ?? 0.0);
        $w = (float) ($pkg['width_in'] ?? 0.0);
        $h = (float) ($pkg['height_in'] ?? 0.0);

        if ($l <= 0.0 || $w <= 0.0 || $h <= 0.0) {
            return '-';
        }

        return sprintf('%.2fx%.2fx%.2f', $l, $w, $h);
    }

    /**
     * Resolve checkout destination ZIP for non-FFL outbound LANE.
     *
     * @param array<string,mixed> $package
     */
    private function resolve_home_destination_zip(array $package): string
    {
        $candidates = [];

        if (!empty($package['destination']['postcode'])) {
            $candidates[] = (string) $package['destination']['postcode'];
        }

        if (function_exists('WC') && WC() && WC()->customer) {
            $candidates[] = (string) WC()->customer->get_shipping_postcode();
            $candidates[] = (string) WC()->customer->get_billing_postcode();
        }

        foreach ($candidates as $raw) {
            $zip = $this->normalize_us_zip($raw);
            if ($zip !== '') {
                return $zip;
            }
        }

        return '';
    }

    /**
     * Resolve receiving FFL ZIP for FFL outbound LANE.
     */
    private function resolve_receiving_ffl_zip(): string
    {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return '';
        }

        $raw_ffl_number = (string) WC()->session->get('fflhub_receiving_ffl_number');
        $ffl_number = FFLRowMapper::normalize_ffl_number($raw_ffl_number);
        if ($ffl_number === '') {
            return '';
        }

        try {
            $ffl_table = new FFLTable(new FFLSchema());
            $row = FFLRepository::find_by_number($ffl_table, $ffl_number);
        } catch (\Throwable $e) {
            $this->log_debug('FFL ZIP lookup failed; no selected FFL ZIP available.');
            return '';
        }

        if (!is_array($row)) {
            return '';
        }

        $candidate_zip = '';
        if (isset($row['premise']['zip'])) {
            $candidate_zip = (string) $row['premise']['zip'];
        }
        if ($candidate_zip === '' && isset($row['mailing']['zip'])) {
            $candidate_zip = (string) $row['mailing']['zip'];
        }

        $zip = $this->normalize_us_zip($candidate_zip);
        if ($zip === '') {
            return '';
        }

        return $zip;
    }

    private function normalize_us_zip(string $zip): string
    {
        $digits = preg_replace('/\D+/', '', trim($zip));
        if (!is_string($digits) || strlen($digits) < 5) {
            return '';
        }

        return substr($digits, 0, 5);
    }

    /**
     * @param array<string,mixed> $package
     */
    private function resolve_customer_destination_state(array $package): string
    {
        $candidates = [];

        if (!empty($package['destination']['state'])) {
            $candidates[] = (string) $package['destination']['state'];
        }

        if (function_exists('WC') && WC() && WC()->customer) {
            $candidates[] = (string) WC()->customer->get_shipping_state();
            $candidates[] = (string) WC()->customer->get_billing_state();
        }

        foreach ($candidates as $raw) {
            $state = strtoupper(trim($raw));
            if ($state === 'CALIFORNIA') {
                return 'CA';
            }
            if (preg_match('/^[A-Z]{2}$/', $state)) {
                return $state;
            }
        }

        return '';
    }

    /**
     * CA surcharge only applies when the selected plan has a direct/drop-ship lane.
     *
     * @param array<string,mixed> $plan
     */
    private function shipping_plan_has_drop_ship_lane(array $plan): bool
    {
        $by_dist = isset($plan['by_dist']) && is_array($plan['by_dist'])
            ? $plan['by_dist']
            : [];

        foreach ($by_dist as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }

            if (!is_array($row)) {
                continue;
            }

            if (!empty($row['direct_home']) || !empty($row['direct_ffl'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build package-level stats for a dealer outbound LANE.
     *
     * @param array<int,array<string,mixed>> $line_debug_rows
     * @param array<string,string> $assignments
     * @return array<string,mixed>
     */
    private function build_dealer_lane_package(
        array $line_debug_rows,
        array $assignments,
        bool $ffl_lane,
        bool $customer_chargeable_only = false
    ): array
    {
        $weight_oz = 0.0;
        $volume_cuin = 0.0;
        $max_length_in = 0.0;
        $line_count = 0;

        foreach ($line_debug_rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($customer_chargeable_only && !empty($row['customer_free_shipping'])) {
                continue;
            }

            $line_id = (string) ($row['line_id'] ?? '');
            $route = isset($assignments[$line_id]) ? (string) $assignments[$line_id] : (!empty($row['dropship']) ? 'direct_ship' : 'dealer_fulfilled');
            if ($route !== 'dealer_fulfilled') {
                continue;
            }

            $is_ffl_line = !empty($row['ffl_required']);
            if ($is_ffl_line !== $ffl_lane) {
                continue;
            }

            $line_count++;

            $line_weight_oz = max(0.0, (float) ($row['line_weight_oz'] ?? 0.0));
            $weight_oz += $line_weight_oz;

            $qty = max(1, (int) ($row['qty'] ?? 1));
            $length_in = max(0.0, (float) ($row['length_in'] ?? 0.0));
            $width_in  = max(0.0, (float) ($row['width_in'] ?? 0.0));
            $height_in = max(0.0, (float) ($row['height_in'] ?? 0.0));

            if ($length_in > 0.0 && $width_in > 0.0 && $height_in > 0.0) {
                $volume_cuin += ($length_in * $width_in * $height_in) * (float) $qty;
                if ($length_in > $max_length_in) {
                    $max_length_in = $length_in;
                }
            }
        }

        if ($weight_oz <= 0.0 || $line_count < 1) {
            return [
                'weight_oz'  => 0.0,
                'length_in'  => 0.0,
                'width_in'   => 0.0,
                'height_in'  => 0.0,
                'line_count' => 0,
                'dim_source' => 'none',
            ];
        }

        if ($volume_cuin > 0.0 && $max_length_in > 0.0) {
            $cross_section = sqrt(max(0.25, $volume_cuin / $max_length_in));

            return [
                'weight_oz'  => $weight_oz,
                'length_in'  => round(max(0.25, $max_length_in), 2),
                'width_in'   => round(max(0.25, $cross_section), 2),
                'height_in'  => round(max(0.25, $cross_section), 2),
                'line_count' => $line_count,
                'dim_source' => 'derived_volume',
            ];
        }

        $fallback = $this->fallback_dimensions_for_weight_oz($weight_oz);

        return [
            'weight_oz'  => $weight_oz,
            'length_in'  => (float) $fallback['length_in'],
            'width_in'   => (float) $fallback['width_in'],
            'height_in'  => (float) $fallback['height_in'],
            'line_count' => $line_count,
            'dim_source' => 'fallback',
        ];
    }

    /**
     * @return array{length_in:float,width_in:float,height_in:float}
     */
    private function fallback_dimensions_for_weight_oz(float $weight_oz): array
    {
        if ($weight_oz <= 16.0) {
            return ['length_in' => 9.0, 'width_in' => 6.0, 'height_in' => 2.0];
        }
        if ($weight_oz <= 64.0) {
            return ['length_in' => 12.0, 'width_in' => 9.0, 'height_in' => 4.0];
        }
        if ($weight_oz <= 160.0) {
            return ['length_in' => 16.0, 'width_in' => 12.0, 'height_in' => 6.0];
        }
        return ['length_in' => 20.0, 'width_in' => 14.0, 'height_in' => 8.0];
    }

    /**
     * Log top rejected planner candidates so route decisions are explainable.
     *
     * @param array<string,mixed> $plan
     * @param array<int,array<string,mixed>> $line_debug_rows
     */
    private function log_planner_alternatives(array $plan, array $line_debug_rows, float $planner_formula_total): void
    {
        $meta = (isset($plan['meta']) && is_array($plan['meta'])) ? $plan['meta'] : [];
        $alternatives = (isset($meta['alternatives']) && is_array($meta['alternatives'])) ? $meta['alternatives'] : [];
        if (empty($alternatives)) {
            return;
        }

        $best_assignments = (isset($plan['assignments']) && is_array($plan['assignments'])) ? $plan['assignments'] : [];
        $best_formula_total = (isset($meta['best_formula_total']) && is_numeric($meta['best_formula_total']))
            ? (float) $meta['best_formula_total']
            : $planner_formula_total;

        $line_to_product = $this->build_line_to_product_map($line_debug_rows);
        $max_to_log = min(6, count($alternatives));

        $this->log_debug(
            sprintf(
                'ALT SUMMARY best_formula_total=%s alternatives=%d showing=%d',
                $this->fmt_money($best_formula_total),
                count($alternatives),
                $max_to_log
            )
        );

        for ($i = 0; $i < $max_to_log; $i++) {
            $alt = $alternatives[$i];
            if (!is_array($alt)) {
                continue;
            }

            $alt_total = (float) ($alt['total_cost'] ?? 0.0);
            $alt_dist_total = (float) ($alt['distributor_cost_total'] ?? 0.0);
            $alt_home = (float) ($alt['dealer_home_cost'] ?? 0.0);
            $alt_ffl = (float) ($alt['dealer_ffl_cost'] ?? 0.0);
            $alt_assignments = (isset($alt['assignments']) && is_array($alt['assignments'])) ? $alt['assignments'] : [];
            $diff = $this->describe_assignment_diff($best_assignments, $alt_assignments, $line_to_product);

            $this->log_debug(
                sprintf(
                    'ALT %d total=%s delta=%s dist_total=%s dealer_home=%s dealer_ffl=%s diff=%s',
                    $i + 1,
                    $this->fmt_money($alt_total),
                    $this->fmt_money_signed($alt_total - $best_formula_total),
                    $this->fmt_money($alt_dist_total),
                    $this->fmt_money($alt_home),
                    $this->fmt_money($alt_ffl),
                    $diff
                )
            );
        }
    }

    /**
     * @param array<int,array<string,mixed>> $line_debug_rows
     * @return array<string,int>
     */
    private function build_line_to_product_map(array $line_debug_rows): array
    {
        $map = [];
        foreach ($line_debug_rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $line_id = (string) ($row['line_id'] ?? '');
            if ($line_id === '') {
                continue;
            }

            $map[$line_id] = (int) ($row['product_id'] ?? 0);
        }

        return $map;
    }

    /**
     * @param array<string,string> $best
     * @param array<string,string> $candidate
     * @param array<string,int> $line_to_product
     */
    private function describe_assignment_diff(array $best, array $candidate, array $line_to_product): string
    {
        $keys = array_unique(array_merge(array_keys($best), array_keys($candidate)));
        sort($keys, SORT_STRING);

        $parts = [];
        foreach ($keys as $line_id) {
            $from = isset($best[$line_id]) ? (string) $best[$line_id] : '-';
            $to   = isset($candidate[$line_id]) ? (string) $candidate[$line_id] : '-';
            if ($from === $to) {
                continue;
            }

            $product_id = (int) ($line_to_product[$line_id] ?? 0);
            $label = ($product_id > 0) ? ('p' . (string) $product_id) : (string) $line_id;
            $parts[] = $label . ':' . $from . '->' . $to;
        }

        if (empty($parts)) {
            return 'none';
        }

        return implode(',', $parts);
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

