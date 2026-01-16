<?php

namespace FFLHub\Product;

use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

class MapPriceVisibility
{
    public static function init(): void
    {
        // Replace price HTML everywhere except cart/checkout
        add_filter('woocommerce_get_price_html', [self::class, 'filter_price_html'], 99, 2);

        // Variable products / variation JSON (prevents price appearing on selection UI)
        add_filter('woocommerce_available_variation', [self::class, 'filter_available_variation'], 99, 3);

        // Hide offer/price from Woo structured data (prevents Google showing price)
        add_filter('woocommerce_structured_data_product_offer', [self::class, 'filter_structured_offer'], 99, 2);
    }

    private static function in_cart_flow(): bool
    {
        return (function_exists('is_cart') && is_cart())
            || (function_exists('is_checkout') && is_checkout());
    }

    private static function hidden_text(): string
    {
        return (string) apply_filters(
            'fflhub_map_hidden_price_text',
            __('Add to cart to see price', 'ffl-hub')
        );
    }

    private static function is_fflhub_managed(WC_Product $product, ?WC_Product $parent = null): bool
    {
        $val = $product->get_meta(ProductMeta::FFLHUB_MANAGED_META, true);

        // variations may not carry the flag; allow parent to control
        if (($val === '' || $val === null) && $parent instanceof WC_Product) {
            $val = $parent->get_meta(ProductMeta::FFLHUB_MANAGED_META, true);
        }

        if (function_exists('wc_string_to_bool')) {
            return wc_string_to_bool((string) $val);
        }

        // Fallback: treat common truthy values as true
        return in_array((string) $val, ['1', 'true', 'yes', 'on'], true) || $val === true;
    }

    private static function is_map_restricted(WC_Product $product, ?WC_Product $parent = null): bool
    {
        // Only enforce MAP hiding on FFLHub-managed products
        if (!self::is_fflhub_managed($product, $parent)) {
            return false;
        }

        // MAP: prefer variation meta, fallback to parent meta
        $map = (float) $product->get_meta(ProductMeta::FFLHUB_LAST_MAP_META, true);
        if ($map <= 0.0 && $parent instanceof WC_Product) {
            $map = (float) $parent->get_meta(ProductMeta::FFLHUB_LAST_MAP_META, true);
        }
        if ($map <= 0.0) {
            return false;
        }

        // Current sell price (what would normally be displayed)
        $price = (float) $product->get_price();
        if ($price <= 0.0) {
            return false;
        }

        // Price below MAP => hide advertised price
        return $price < $map;
    }

    private static function should_hide_price(WC_Product $product, ?WC_Product $parent = null): bool
    {
        if (self::in_cart_flow()) {
            return false;
        }

        return self::is_map_restricted($product, $parent);
    }

    public static function filter_price_html(string $price_html, $product): string
    {
        if (!($product instanceof WC_Product)) {
            return $price_html;
        }

        if (!self::should_hide_price($product, null)) {
            return $price_html;
        }

        return '<span class="fflhub-map-hidden-price">' . esc_html(self::hidden_text()) . '</span>';
    }

    public static function filter_available_variation(array $data, $parent, $variation): array
    {
        if (!($variation instanceof WC_Product)) {
            return $data;
        }

        $parent_product = ($parent instanceof WC_Product) ? $parent : null;

        if (!self::should_hide_price($variation, $parent_product)) {
            return $data;
        }

        $data['price_html'] = '<span class="fflhub-map-hidden-price">' . esc_html(self::hidden_text()) . '</span>';

        // Optional hardening (prevents themes/JS from showing numbers)
        $data['display_price'] = 0;
        $data['display_regular_price'] = 0;
        $data['price'] = '';
        $data['regular_price'] = '';
        $data['sale_price'] = '';

        return $data;
    }

    public static function filter_structured_offer($offer, $product)
    {
        if (!($product instanceof WC_Product)) {
            return $offer;
        }

        if (self::should_hide_price($product, null)) {
            // Remove price/offer from structured data
            return [];
        }

        return $offer;
    }
}
