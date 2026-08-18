<?php
declare(strict_types=1);

namespace FFLHub\Shipping;

use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

final class PhoenixProductShippingMeta
{
    public const SHIPPING_RECOVERY_INCLUDED = 'included';
    public const SHIPPING_RECOVERY_SEPARATE = 'separate';
    public const SHIPPING_RECOVERY_ACTUAL_AT_CHECKOUT = 'actual_at_checkout';

    public static function is_managed_product(WC_Product $product): bool
    {
        return self::source_product($product) instanceof WC_Product;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function line_for_product(WC_Product $product, int $qty, float $fallback_shipping): ?array
    {
        $source = self::source_product($product);
        if (!$source instanceof WC_Product) {
            return null;
        }

        $qty = max(1, $qty);
        $fallback_shipping = max(0.0, $fallback_shipping);
        $policy = strtolower(self::meta($source, '_phoenix_shipping_recovery_policy'));
        if ($policy === '') {
            $policy = self::SHIPPING_RECOVERY_SEPARATE;
        }

        $customer_unit_charge = 0.0;
        $customer_charge_source = $policy;
        $synced_customer_shipping_charge = self::decimal_meta($source, '_phoenix_customer_shipping_charge');
        if ($policy === self::SHIPPING_RECOVERY_SEPARATE) {
            $customer_unit_charge = $synced_customer_shipping_charge ?? $fallback_shipping;
            $customer_charge_source = $synced_customer_shipping_charge === null
                ? 'fallback_missing_phoenix_customer_shipping_charge'
                : '_phoenix_customer_shipping_charge';
        } elseif ($policy === self::SHIPPING_RECOVERY_ACTUAL_AT_CHECKOUT) {
            $customer_unit_charge = $synced_customer_shipping_charge ?? $fallback_shipping;
            $customer_charge_source = $synced_customer_shipping_charge === null
                ? 'fallback_actual_at_checkout_without_live_quote'
                : '_phoenix_customer_shipping_charge';
        }

        $fulfillment = strtolower(self::meta($source, '_phoenix_selected_fulfillment_method'));
        $is_drop_ship = in_array($fulfillment, ['drop_ship', 'direct_ship', 'dropship'], true);
        $is_ffl = self::bool_meta($source, '_phoenix_ffl_required', false);
        $source_shipping_unit = self::decimal_meta($source, '_phoenix_selected_shipping_cost') ?? 0.0;

        $source_product_id = method_exists($source, 'get_id') ? (int) $source->get_id() : 0;
        $product_id = method_exists($product, 'get_id') ? (int) $product->get_id() : $source_product_id;

        return [
            'product_id' => $product_id > 0 ? $product_id : $source_product_id,
            'source_product_id' => $source_product_id,
            'upc' => self::meta($source, '_phoenix_upc'),
            'dist_id' => strtolower(self::meta($source, '_phoenix_source_distributor_code')) ?: 'phoenix',
            'source_offer_id' => self::meta($source, '_phoenix_source_offer_id'),
            'qty' => $qty,
            'ffl_required' => $is_ffl,
            'dropship' => $is_drop_ship,
            'fulfillment_method' => $fulfillment !== '' ? $fulfillment : ($is_drop_ship ? 'drop_ship' : 'dealer_fulfilled'),
            'shipping_recovery_policy' => $policy,
            'customer_shipping_unit_charge' => max(0.0, $customer_unit_charge),
            'customer_shipping_charge_total' => max(0.0, $customer_unit_charge) * (float) $qty,
            'customer_shipping_charge_source' => $customer_charge_source,
            'source_shipping_unit_cost' => max(0.0, $source_shipping_unit),
            'dealer_cost' => self::decimal_meta($source, '_phoenix_selected_dealer_cost') ?? 0.0,
            'line_revenue' => 0.0,
        ];
    }

    private static function source_product(WC_Product $product): ?WC_Product
    {
        if (self::has_phoenix_meta($product)) {
            return $product;
        }

        if (method_exists($product, 'get_parent_id')) {
            $parent_id = (int) $product->get_parent_id();
            if ($parent_id > 0 && function_exists('wc_get_product')) {
                $parent = wc_get_product($parent_id);
                if ($parent instanceof WC_Product && self::has_phoenix_meta($parent)) {
                    return $parent;
                }
            }
        }

        return null;
    }

    private static function has_phoenix_meta(WC_Product $product): bool
    {
        return self::meta($product, '_phoenix_upc') !== ''
            || self::meta($product, '_phoenix_source_offer_id') !== ''
            || self::meta($product, '_phoenix_public_price') !== ''
            || self::meta($product, '_phoenix_backend_price') !== '';
    }

    private static function meta(WC_Product $product, string $key): string
    {
        $value = method_exists($product, 'get_meta')
            ? $product->get_meta($key, true, 'edit')
            : '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function decimal_meta(WC_Product $product, string $key): ?float
    {
        $raw = self::meta($product, $key);
        if ($raw === '') {
            return null;
        }

        $normalized = str_replace([',', '$'], '', $raw);
        if (!is_numeric($normalized)) {
            return null;
        }

        $value = (float) $normalized;
        return is_finite($value) ? max(0.0, $value) : null;
    }

    private static function bool_meta(WC_Product $product, string $key, bool $default): bool
    {
        $raw = strtolower(self::meta($product, $key));
        if ($raw === '') {
            return $default;
        }

        if (in_array($raw, ['1', 'true', 't', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($raw, ['0', 'false', 'f', 'no', 'n', 'off'], true)) {
            return false;
        }

        return $default;
    }
}
