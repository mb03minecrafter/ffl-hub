<?php

namespace FFLHub\Order;

use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

final class WooShippingLabelCostSync
{
    private static bool $syncing = false;

    public static function init(): void
    {
        add_action('woocommerce_after_order_object_save', [__CLASS__, 'sync_after_order_save'], 90, 2);

        add_action('added_post_meta', [__CLASS__, 'sync_after_label_meta_change'], 20, 4);
        add_action('updated_post_meta', [__CLASS__, 'sync_after_label_meta_change'], 20, 4);
        add_action('deleted_post_meta', [__CLASS__, 'sync_after_label_meta_change'], 20, 4);

        add_action('woocommerce_fulfillment_created_notification', [__CLASS__, 'sync_after_fulfillment_change'], 20, 3);
        add_action('woocommerce_fulfillment_updated_notification', [__CLASS__, 'sync_after_fulfillment_change'], 20, 3);
        add_action('woocommerce_fulfillment_deleted_notification', [__CLASS__, 'sync_after_fulfillment_change'], 20, 3);
    }

    /**
     * WooCommerce Shipping saves classic labels through order meta, then saves the order.
     *
     * @param mixed $order
     * @param mixed $data_store
     */
    public static function sync_after_order_save($order, $data_store = null): void
    {
        if (!($order instanceof WC_Order)) {
            return;
        }

        if (!self::order_has_label_surface($order)) {
            return;
        }

        self::sync_order($order);
    }

    /**
     * Legacy/non-HPOS safety net for direct post meta writes.
     *
     * @param mixed $meta_id
     * @param mixed $object_id
     * @param mixed $meta_key
     * @param mixed $_meta_value
     */
    public static function sync_after_label_meta_change($meta_id, $object_id, $meta_key, $_meta_value = null): void
    {
        if (!in_array((string) $meta_key, ['wcshipping_labels', 'wc_connect_labels'], true)) {
            return;
        }

        self::sync_order_id((int) $object_id);
    }

    /**
     * Newer WooCommerce Shipping stores label data on fulfillment records.
     *
     * @param mixed $order_id
     * @param mixed $fulfillment
     * @param mixed $order
     */
    public static function sync_after_fulfillment_change($order_id, $fulfillment = null, $order = null): void
    {
        if ($order instanceof WC_Order) {
            self::sync_order($order);
            return;
        }

        self::sync_order_id((int) $order_id);
    }

    private static function sync_order_id(int $order_id): void
    {
        if ($order_id <= 0) {
            return;
        }

        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            self::sync_order($order);
        }
    }

    private static function sync_order(WC_Order $order): void
    {
        if (self::$syncing) {
            return;
        }

        self::$syncing = true;
        try {
            OrderProfitAuditMeta::recalculate_order($order, true);
        } finally {
            self::$syncing = false;
        }
    }

    private static function order_has_label_surface(WC_Order $order): bool
    {
        if (self::has_meta_value($order->get_meta('wcshipping_labels', true))) {
            return true;
        }

        if (self::has_meta_value($order->get_meta('wc_connect_labels', true))) {
            return true;
        }

        if ((float) $order->get_meta('fflhub_order_shipping_label_cost_total', true) > 0.0) {
            return true;
        }

        return (int) $order->get_meta('fflhub_order_shipping_label_count', true) > 0;
    }

    /**
     * @param mixed $value
     */
    private static function has_meta_value($value): bool
    {
        if (is_array($value)) {
            return !empty($value);
        }

        if (is_object($value)) {
            return !empty((array) $value);
        }

        return trim((string) $value) !== '';
    }
}
