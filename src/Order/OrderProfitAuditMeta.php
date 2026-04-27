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
    private const VERSION = 'order_profit_v2';

    public static function init(): void
    {
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'capture_checkout_order'], 90, 2);
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'capture_processed_order'], 20, 3);
    }

    public static function version(): string
    {
        return self::VERSION;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function capture_checkout_order(WC_Order $order, array $data = []): void
    {
        self::recalculate_order($order, false);
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
            self::recalculate_order($order, true);
        }
    }

    /**
     * Rebuild the order-level profit audit snapshot from the best currently available product/order data.
     *
     * @return array<string,mixed>
     */
    public static function recalculate_order(WC_Order $order, bool $save = true): array
    {
        $audit = self::build_profit_audit($order);

        $order->update_meta_data('fflhub_order_profit_audit_version', self::VERSION);
        $order->update_meta_data('fflhub_order_total', self::money($audit['order_total']));
        $order->update_meta_data('fflhub_order_tax_total', self::money($audit['tax_total']));
        $order->update_meta_data('fflhub_order_revenue_total', self::money($audit['revenue_total']));
        $order->update_meta_data('fflhub_order_item_cost_total', self::money($audit['item_cost_total']));
        $order->update_meta_data('fflhub_order_item_cost_by_dist', wp_json_encode($audit['item_cost_by_dist']));
        $order->update_meta_data('fflhub_order_shipping_cost_total', self::money($audit['shipping_cost_total']));
        $order->update_meta_data('fflhub_order_distributor_shipping_cost_total', self::money($audit['distributor_shipping_cost_total']));
        $order->update_meta_data('fflhub_order_distributor_shipping_by_dist', wp_json_encode($audit['distributor_shipping_by_dist']));
        $order->update_meta_data('fflhub_order_distributor_shipping_source', (string) $audit['distributor_shipping_source']);
        $order->update_meta_data('fflhub_order_shipping_label_cost_total', self::money($audit['shipping_label_cost_total']));
        $order->update_meta_data('fflhub_order_shipping_label_count', (string) (int) $audit['shipping_label_count']);
        $order->update_meta_data('fflhub_order_shipping_label_source', (string) $audit['shipping_label_source']);
        $order->update_meta_data('fflhub_order_shipping_label_lines', wp_json_encode($audit['shipping_label_lines']));
        $order->update_meta_data('fflhub_order_customer_shipping_charge', self::money($audit['customer_shipping_charge']));
        $order->update_meta_data('fflhub_order_processor_fee_percent', self::money($audit['processor_fee_percent']));
        $order->update_meta_data('fflhub_order_processor_fee_amount', self::money($audit['processor_fee_amount']));
        $order->update_meta_data('fflhub_order_actual_profit_total', self::money($audit['actual_profit_total']));
        $order->update_meta_data('fflhub_order_profit_lines', wp_json_encode($audit['lines']));
        $order->delete_meta_data('fflhub_order_shipping_planned_cost_total');
        $order->delete_meta_data('fflhub_order_shipping_non_label_cost_total');
        self::remove_obsolete_shipping_item_meta($order, $save);

        if ($save) {
            $order->save();
        }

        return $audit;
    }

    /**
     * @return array<string,mixed>
     */
    public static function preview_order(WC_Order $order): array
    {
        return self::build_profit_audit($order);
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

        $distributor_shipping = self::distributor_shipping_cost_summary($order);
        $label_shipping = self::woo_shipping_label_cost_summary($order);
        $shipping_cost_total = (float) $distributor_shipping['total'] + (float) $label_shipping['total'];
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
            'distributor_shipping_cost_total' => (float) $distributor_shipping['total'],
            'distributor_shipping_by_dist' => $distributor_shipping['by_dist'],
            'distributor_shipping_source' => (string) $distributor_shipping['source'],
            'shipping_label_cost_total' => (float) $label_shipping['total'],
            'shipping_label_count' => (int) $label_shipping['count'],
            'shipping_label_source' => (string) $label_shipping['source'],
            'shipping_label_lines' => $label_shipping['lines'],
            'customer_shipping_charge' => $customer_shipping_charge,
            'processor_fee_percent' => $fee_percent,
            'processor_fee_amount' => $processor_fee_amount,
            'actual_profit_total' => $actual_profit_total,
            'lines' => $lines,
        ];
    }

    /**
     * Distributor shipping is the real distributor lane fee from the FFLHub checkout plan.
     * It intentionally excludes dealer outbound estimates; bought Woo labels cover those
     * only after a label is actually purchased.
     *
     * @return array{total:float,source:string,by_dist:array<int,array<string,mixed>>}
     */
    private static function distributor_shipping_cost_summary(WC_Order $order): array
    {
        $total = 0.0;
        $sources = [];
        $by_dist = [];

        foreach ($order->get_items('shipping') as $item_id => $shipping_item) {
            if (!($shipping_item instanceof WC_Order_Item_Shipping)) {
                continue;
            }

            $plan = self::decode_array($shipping_item->get_meta('fflhub_shipping_plan', true));
            $rows = isset($plan['by_dist']) && is_array($plan['by_dist'])
                ? $plan['by_dist']
                : self::decode_array($shipping_item->get_meta('fflhub_shipping_by_dist', true));

            $row_total = self::sum_distributor_shipping_rows($rows, $by_dist, (int) $item_id);
            if ($row_total > 0.0) {
                $total += $row_total;
                $sources['shipping_item_by_dist'] = true;
                continue;
            }

            $plan_total = self::non_negative_float($plan['distributor_cost_total'] ?? null);
            if ($plan_total !== null && $plan_total > 0.0) {
                $total += $plan_total;
                $sources['shipping_plan_distributor_cost_total'] = true;
                self::add_distributor_shipping_total($by_dist, 'unknown', $plan_total, (int) $item_id);
            }
        }

        return [
            'total' => $total,
            'source' => implode(',', array_keys($sources)),
            'by_dist' => array_values($by_dist),
        ];
    }

    /**
     * @param array<int|string,mixed> $rows
     * @param array<string,array<string,mixed>> $by_dist
     */
    private static function sum_distributor_shipping_rows(array $rows, array &$by_dist, int $shipping_item_id = 0): float
    {
        $total = 0.0;

        foreach ($rows as $dist_id => $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (!is_array($row)) {
                continue;
            }

            $cost = self::non_negative_float($row['cost'] ?? null);
            if ($cost === null || $cost <= 0.0) {
                continue;
            }

            $dist_key = strtolower(trim((string) ($row['dist_id'] ?? $dist_id)));
            if ($dist_key === '' || is_numeric($dist_key)) {
                $dist_key = 'unknown';
            }

            $total += $cost;
            self::add_distributor_shipping_total($by_dist, $dist_key, $cost, $shipping_item_id, $row);
        }

        return $total;
    }

    /**
     * @param array<string,array<string,mixed>> $by_dist
     * @param array<string,mixed> $row
     */
    private static function add_distributor_shipping_total(
        array &$by_dist,
        string $dist_id,
        float $cost,
        int $shipping_item_id = 0,
        array $row = []
    ): void {
        $dist_id = strtolower(trim($dist_id));
        if ($dist_id === '') {
            $dist_id = 'unknown';
        }

        if (!isset($by_dist[$dist_id])) {
            $by_dist[$dist_id] = [
                'dist_id' => $dist_id,
                'cost' => '0.0000',
                'shipping_item_ids' => [],
                'active_lanes' => 0,
                'direct_home' => false,
                'direct_ffl' => false,
                'dealer_inbound' => false,
            ];
        }

        $by_dist[$dist_id]['cost'] = self::money((float) $by_dist[$dist_id]['cost'] + $cost);

        if ($shipping_item_id > 0 && !in_array($shipping_item_id, $by_dist[$dist_id]['shipping_item_ids'], true)) {
            $by_dist[$dist_id]['shipping_item_ids'][] = $shipping_item_id;
        }

        if (isset($row['active_lanes'])) {
            $by_dist[$dist_id]['active_lanes'] += max(0, (int) $row['active_lanes']);
        }
        if (!empty($row['direct_home'])) {
            $by_dist[$dist_id]['direct_home'] = true;
        }
        if (!empty($row['direct_ffl'])) {
            $by_dist[$dist_id]['direct_ffl'] = true;
        }
        if (!empty($row['dealer_inbound'])) {
            $by_dist[$dist_id]['dealer_inbound'] = true;
        }
    }

    /**
     * @return array{total:float,count:int,source:string,lines:array<int,array<string,mixed>>}
     */
    private static function woo_shipping_label_cost_summary(WC_Order $order): array
    {
        $label_groups = [
            'wcshipping_labels' => self::normalize_label_collection($order->get_meta('wcshipping_labels', true)),
            'wc_connect_labels' => self::normalize_label_collection($order->get_meta('wc_connect_labels', true)),
            'wcshipping_fulfillments' => self::fulfillment_label_collection($order),
        ];

        $total = 0.0;
        $count = 0;
        $sources = [];
        $lines = [];
        $seen = [];

        foreach ($label_groups as $source => $labels) {
            foreach ($labels as $label) {
                $label_id = (string) ($label['label_id'] ?? $label['id'] ?? '');
                $seen_key = $label_id !== ''
                    ? 'label_id:' . $label_id
                    : $source . ':' . md5(wp_json_encode($label));
                if (isset($seen[$seen_key])) {
                    continue;
                }
                $seen[$seen_key] = true;

                if (self::is_ignored_label($label)) {
                    continue;
                }

                $cost = self::non_negative_float($label['refundable_amount'] ?? $label['cost'] ?? null);
                if ($cost === null || $cost <= 0.0) {
                    continue;
                }

                $total += $cost;
                $count++;
                $sources[$source] = true;
                $lines[] = [
                    'label_id' => $label_id,
                    'source' => $source,
                    'cost' => self::money($cost),
                    'status' => (string) ($label['status'] ?? ''),
                    'carrier_id' => (string) ($label['carrier_id'] ?? ''),
                    'service_name' => (string) ($label['service_name'] ?? ''),
                    'tracking' => (string) ($label['tracking'] ?? ''),
                    'created' => (string) ($label['created'] ?? ''),
                    'is_return' => !empty($label['is_return']) ? '1' : '0',
                ];
            }
        }

        return [
            'total' => $total,
            'count' => $count,
            'source' => implode(',', array_keys($sources)),
            'lines' => $lines,
        ];
    }

    /**
     * @param mixed $value
     * @return array<int|string,mixed>
     */
    private static function decode_array($value): array
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

            $unserialized = maybe_unserialize($value);
            if (is_array($unserialized)) {
                return $unserialized;
            }
        }

        return [];
    }

    /**
     * @param mixed $value
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_label_collection($value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = maybe_unserialize($value);
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $labels = [];
        foreach ($value as $label) {
            $normalized = self::normalize_label($label);
            if (!empty($normalized)) {
                $labels[] = $normalized;
            }
        }

        return $labels;
    }

    /**
     * @param mixed $label
     * @return array<string,mixed>
     */
    private static function normalize_label($label): array
    {
        if (is_object($label)) {
            $label = (array) $label;
        }

        if (!is_array($label)) {
            return [];
        }

        foreach ($label as $key => $value) {
            if (is_object($value)) {
                $label[$key] = (array) $value;
            }
        }

        return $label;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function fulfillment_label_collection(WC_Order $order): array
    {
        global $wpdb;

        $fulfillments_table = $wpdb->prefix . 'wc_order_fulfillments';
        $meta_table = $wpdb->prefix . 'wc_order_fulfillment_meta';

        $has_fulfillments_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fulfillments_table));
        $has_meta_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $meta_table));
        if ($has_fulfillments_table !== $fulfillments_table || $has_meta_table !== $meta_table) {
            return [];
        }

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT fm.meta_value
                 FROM {$fulfillments_table} f
                 INNER JOIN {$meta_table} fm ON f.fulfillment_id = fm.fulfillment_id
                 WHERE f.entity_type = %s
                   AND f.entity_id = %s
                   AND f.date_deleted IS NULL
                   AND fm.meta_key = %s",
                'WC_Order',
                (string) $order->get_id(),
                '_shipping_labels'
            )
        );

        $labels = [];
        foreach ($rows as $raw_labels) {
            $labels = array_merge($labels, self::normalize_label_collection($raw_labels));
        }

        return $labels;
    }

    /**
     * @param array<string,mixed> $label
     */
    private static function is_ignored_label(array $label): bool
    {
        $status = strtoupper(trim((string) ($label['status'] ?? '')));
        if (in_array($status, ['PURCHASE_ERROR', 'ANONYMIZED'], true)) {
            return true;
        }

        if ($status !== '' && $status !== 'PURCHASED') {
            return true;
        }

        $refund = $label['refund'] ?? null;
        if (is_object($refund)) {
            $refund = (array) $refund;
        }

        if (!empty($refund)) {
            $refund_status = is_array($refund) ? strtolower((string) ($refund['status'] ?? '')) : '';
            return $refund_status !== 'rejected';
        }

        return false;
    }

    private static function remove_obsolete_shipping_item_meta(WC_Order $order, bool $save): void
    {
        foreach ($order->get_items('shipping') as $shipping_item) {
            if (!($shipping_item instanceof WC_Order_Item_Shipping)) {
                continue;
            }

            $shipping_item->delete_meta_data('fflhub_profit_net_total');
            $shipping_item->delete_meta_data('fflhub_processor_fee_percent');

            if ($save) {
                $shipping_item->save();
            }
        }
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
