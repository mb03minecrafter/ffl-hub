<?php

namespace FFLHub\Order;

use FFLHub\Product\ProductMeta;
use FFLHub\Settings\Options;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

final class OrderProfitAuditMeta
{
    private const VERSION = 'order_profit_v1';

    public static function init(): void
    {
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'capture_checkout_order'], 90, 2);
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'capture_processed_order'], 20, 3);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function capture_checkout_order(WC_Order $order, array $data = []): void
    {
        self::write_profit_audit_meta($order, false);
    }

    /**
     * @param mixed $posted_data
     * @param mixed $order
     */
    public static function capture_processed_order(int $order_id, $posted_data = null, $order = null): void
    {
        if (!($order instanceof WC_Order)) {
            $order = wc_get_order($order_id);
        }

        if ($order instanceof WC_Order) {
            self::write_profit_audit_meta($order, true);
        }
    }

    private static function write_profit_audit_meta(WC_Order $order, bool $save): void
    {
        $audit = self::build_profit_audit($order);

        $order->update_meta_data('fflhub_order_profit_audit_version', self::VERSION);
        $order->update_meta_data('fflhub_order_total', self::money($audit['order_total']));
        $order->update_meta_data('fflhub_order_tax_total', self::money($audit['tax_total']));
        $order->update_meta_data('fflhub_order_revenue_total', self::money($audit['revenue_total']));
        $order->update_meta_data('fflhub_order_item_cost_total', self::money($audit['item_cost_total']));
        $order->update_meta_data('fflhub_order_item_cost_by_dist', wp_json_encode($audit['item_cost_by_dist']));
        $order->update_meta_data('fflhub_order_shipping_cost_total', self::money($audit['shipping_cost_total']));
        $order->update_meta_data('fflhub_order_customer_shipping_charge', self::money($audit['customer_shipping_charge']));
        $order->update_meta_data('fflhub_order_processor_fee_percent', self::money($audit['processor_fee_percent']));
        $order->update_meta_data('fflhub_order_processor_fee_amount', self::money($audit['processor_fee_amount']));
        $order->update_meta_data('fflhub_order_actual_profit_total', self::money($audit['actual_profit_total']));
        $order->update_meta_data('fflhub_order_profit_lines', wp_json_encode($audit['lines']));

        if ($save) {
            $order->save();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function build_profit_audit(WC_Order $order): array
    {
        $item_cost_total = 0.0;
        $item_cost_by_dist = [];
        $lines = [];

        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $qty = max(0, (int) $item->get_quantity());
            $product = $item->get_product();
            $product_id = ($product instanceof WC_Product) ? (int) $product->get_id() : (int) $item->get_product_id();
            $sku = ($product instanceof WC_Product) ? (string) $product->get_sku() : '';
            $dist_id = ($product instanceof WC_Product)
                ? strtolower(trim((string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true)))
                : '';

            $unit_cost = null;
            $unit_cost_source = 'missing';

            if ($product instanceof WC_Product) {
                $dealer_price = self::positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, true));

                if ($dealer_price !== null) {
                    $unit_cost = $dealer_price;
                    $unit_cost_source = ProductMeta::FFLHUB_LAST_DEALER_PRICE_META;
                }
            }

            $line_cost = (($unit_cost !== null) ? $unit_cost : 0.0) * (float) $qty;
            $item_cost_total += $line_cost;
            $dist_key = $dist_id !== '' ? $dist_id : 'unknown';

            if (!isset($item_cost_by_dist[$dist_key])) {
                $item_cost_by_dist[$dist_key] = [
                    'dist_id' => $dist_key,
                    'line_count' => 0,
                    'qty' => 0,
                    'item_cost' => '0.0000',
                ];
            }

            $item_cost_by_dist[$dist_key]['line_count']++;
            $item_cost_by_dist[$dist_key]['qty'] += $qty;
            $item_cost_by_dist[$dist_key]['item_cost'] = self::money(
                (float) $item_cost_by_dist[$dist_key]['item_cost'] + $line_cost
            );

            $lines[] = [
                'item_id' => (int) $item_id,
                'product_id' => $product_id,
                'sku' => $sku,
                'name' => (string) $item->get_name(),
                'source_distributor' => $dist_key,
                'qty' => $qty,
                'distributor_unit_cost' => self::money($unit_cost ?? 0.0),
                'unit_cost_source' => $unit_cost_source,
                'distributor_line_cost' => self::money($line_cost),
                'line_revenue' => self::money((float) $item->get_total()),
            ];
        }

        $shipping_cost_total = self::shipping_cost_total($order);
        $customer_shipping_charge = (float) $order->get_shipping_total();
        $order_total = (float) $order->get_total();
        $tax_total = (float) $order->get_total_tax();
        $revenue_total = max(0.0, $order_total - $tax_total);

        $fee_percent = (float) Options::get_payment_processor_fee_percent();
        if ($fee_percent < 0.0) {
            $fee_percent = 0.0;
        }

        $processor_fee_amount = $order_total * ($fee_percent / 100.0);
        $actual_profit_total = $revenue_total - $item_cost_total - $shipping_cost_total - $processor_fee_amount;

        return [
            'order_total' => $order_total,
            'tax_total' => $tax_total,
            'revenue_total' => $revenue_total,
            'item_cost_total' => $item_cost_total,
            'item_cost_by_dist' => array_values($item_cost_by_dist),
            'shipping_cost_total' => $shipping_cost_total,
            'customer_shipping_charge' => $customer_shipping_charge,
            'processor_fee_percent' => $fee_percent,
            'processor_fee_amount' => $processor_fee_amount,
            'actual_profit_total' => $actual_profit_total,
            'lines' => $lines,
        ];
    }

    private static function shipping_cost_total(WC_Order $order): float
    {
        $total = 0.0;

        foreach ($order->get_items('shipping') as $shipping_item) {
            if (!($shipping_item instanceof WC_Order_Item_Shipping)) {
                continue;
            }

            $cost = self::non_negative_float($shipping_item->get_meta('fflhub_shipping_cost_total', true));
            if ($cost !== null) {
                $total += $cost;
                continue;
            }

            $plan = json_decode((string) $shipping_item->get_meta('fflhub_shipping_plan', true), true);
            if (is_array($plan)) {
                $plan_cost = self::non_negative_float($plan['total_cost'] ?? null);
                if ($plan_cost !== null) {
                    $total += $plan_cost;
                }
            }
        }

        return $total;
    }

    /**
     * @param mixed $value
     */
    private static function positive_float($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $float = (float) $value;
        return $float > 0.0 ? $float : null;
    }

    /**
     * @param mixed $value
     */
    private static function non_negative_float($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $float = (float) $value;
        return $float >= 0.0 ? $float : null;
    }

    private static function money(float $value): string
    {
        return (string) wc_format_decimal($value, 4);
    }
}
