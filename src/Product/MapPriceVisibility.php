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
        // Treat checkout as “in-cart flow” (otherwise checkout would hide the price too)
        return (function_exists('is_cart') && is_cart())
            || (function_exists('is_checkout') && is_checkout());
    }

    private static function hidden_text(): string
    {
        // You can filter this if you want different text later.
        return (string) apply_filters('fflhub_map_hidden_price_text', __('Add to Cart to See Price!', 'ffl-hub'));
    }

    private static function is_map_restricted(WC_Product $product): bool
    {
        $map = (float) $product->get_meta(ProductMeta::FFLHUB_LAST_MAP_META, true);
        if ($map <= 0.0) {
            return false;
        }

        // Current sell price (what would normally be displayed)
        $price = (float) $product->get_price();
        if ($price <= 0.0) {
            return false;
        }

        // “List price below MAP” => hide advertised price
        return $price < $map;
    }

    private static function should_hide_price(WC_Product $product): bool
    {
        if (self::in_cart_flow()) {
            return false;
        }
        return self::is_map_restricted($product);
    }

    public static function filter_price_html(string $price_html, $product): string
    {
        if (!($product instanceof WC_Product)) {
            return $price_html;
        }

        if (!self::should_hide_price($product)) {
            return $price_html;
        }

        return '<span class="fflhub-map-hidden-price">' . esc_html(self::hidden_text()) . '</span>';
    }

    public static function filter_available_variation(array $data, $parent, $variation): array
    {
        if (!($variation instanceof WC_Product)) {
            return $data;
        }

        // Determine MAP: prefer variation meta, fall back to parent meta if needed
        $map = (float) $variation->get_meta(ProductMeta::FFLHUB_LAST_MAP_META, true);
        if ($map <= 0.0 && ($parent instanceof WC_Product)) {
            $map = (float) $parent->get_meta(ProductMeta::FFLHUB_LAST_MAP_META, true);
        }

        if ($map <= 0.0) {
            return $data;
        }

        $price = (float) $variation->get_price();
        if ($price > 0.0 && $price < $map) {
            // Replace any variation price display HTML with the MAP message
            $data['price_html'] = '<span class="fflhub-map-hidden-price">' . esc_html(self::hidden_text()) . '</span>';

            // Optional hardening: prevent themes/JS from showing a numeric variation price
            $data['display_price'] = 0;
            $data['display_regular_price'] = 0;
            $data['price'] = '';
            $data['regular_price'] = '';
            $data['sale_price'] = '';
        }

        return $data;
    }

    public static function filter_structured_offer($offer, $product)
    {
        if (!($product instanceof WC_Product)) {
            return $offer;
        }

        if (self::should_hide_price($product)) {
            // Removes price/offer from structured data
            return [];
        }

        return $offer;
    }
}
