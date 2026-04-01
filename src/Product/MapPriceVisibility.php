<?php

namespace FFLHub\Product;

use FFLHub\Settings\Options;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

class MapPriceVisibility
{
    private const BRAND_TAXONOMY_CANDIDATES = ['product_brand', 'pa_brand'];

    /** @var array<string,string>|null */
    private static ?array $policy_lookup_cache = null;

    /** @var array<int,array<int,string>> */
    private static array $brand_names_by_product_id = [];

    public static function init(): void
    {
        // Replace price HTML everywhere except cart/checkout
        add_filter('woocommerce_get_price_html', [self::class, 'filter_price_html'], 99, 2);

        // Variable products / variation JSON (prevents price appearing on selection UI)
        add_filter('woocommerce_available_variation', [self::class, 'filter_available_variation'], 99, 3);

        // Hide offer/price from Woo structured data (prevents Google showing price)
        add_filter('woocommerce_structured_data_product_offer', [self::class, 'filter_structured_offer'], 99, 2);

        // Render an email CTA on single-product pages for "Email for Quote" brands.
        add_action('woocommerce_single_product_summary', [self::class, 'render_email_for_quote_button'], 31);
    }

    private static function in_cart_flow(): bool
    {
        return (function_exists('is_cart') && is_cart())
            || (function_exists('is_checkout') && is_checkout());
    }

    private static function hidden_text(?WC_Product $product = null, ?WC_Product $parent = null): string
    {
        $policy = Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
        if ($product instanceof WC_Product) {
            $policy = self::map_policy_for_product($product, $parent);
        }

        $default_text = __('Add to cart to see price', 'ffl-hub');
        if ($policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE) {
            $default_text = __('Email for quote', 'ffl-hub');
        }

        return (string) apply_filters(
            'fflhub_map_hidden_price_text',
            $default_text,
            $product,
            $parent,
            $policy
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

        if (!self::is_map_restricted($product, $parent)) {
            return false;
        }

        // "Email for Quote" brands should still show MAP price.
        if (self::is_email_for_quote_policy($product, $parent)) {
            return false;
        }

        return true;
    }

    public static function filter_price_html(string $price_html, $product): string
    {
        if (!($product instanceof WC_Product)) {
            return $price_html;
        }

        if (self::in_cart_flow()) {
            return $price_html;
        }

        if (self::is_email_for_quote_policy($product, null)) {
            $map_price = self::map_price_for_product($product, null);
            if ($map_price !== null) {
                return self::map_price_html($map_price);
            }
        }

        if (!self::should_hide_price($product, null)) {
            return $price_html;
        }

        return '<span class="fflhub-map-hidden-price">' . esc_html(self::hidden_text($product, null)) . '</span>';
    }

    public static function filter_available_variation(array $data, $parent, $variation): array
    {
        if (!($variation instanceof WC_Product)) {
            return $data;
        }

        $parent_product = ($parent instanceof WC_Product) ? $parent : null;

        if (self::is_email_for_quote_policy($variation, $parent_product)) {
            $map_price = self::map_price_for_product($variation, $parent_product);
            if ($map_price === null) {
                return $data;
            }

            $price_raw = (string) wc_format_decimal($map_price, wc_get_price_decimals());
            $price_num = (float) $price_raw;

            $data['price_html'] = self::map_price_html($map_price);
            $data['display_price'] = $price_num;
            $data['display_regular_price'] = $price_num;
            $data['price'] = $price_raw;
            $data['regular_price'] = $price_raw;
            $data['sale_price'] = '';

            return $data;
        }

        if (!self::should_hide_price($variation, $parent_product)) {
            return $data;
        }

        $data['price_html'] = '<span class="fflhub-map-hidden-price">' . esc_html(self::hidden_text($variation, $parent_product)) . '</span>';

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

        if (self::is_email_for_quote_policy($product, null)) {
            $map_price = self::map_price_for_product($product, null);
            if ($map_price !== null) {
                $price_raw = (string) wc_format_decimal($map_price, wc_get_price_decimals());

                if (is_array($offer)) {
                    $offer['price'] = $price_raw;

                    if (isset($offer['lowPrice'])) {
                        $offer['lowPrice'] = $price_raw;
                    }
                    if (isset($offer['highPrice'])) {
                        $offer['highPrice'] = $price_raw;
                    }
                }
            }

            return $offer;
        }

        if (self::should_hide_price($product, null)) {
            // Remove price/offer from structured data
            return [];
        }

        return $offer;
    }

    public static function render_email_for_quote_button(): void
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        global $product;
        if (!($product instanceof WC_Product)) {
            return;
        }

        if (!self::is_email_for_quote_policy($product, null)) {
            return;
        }

        $href = self::email_for_quote_href($product);
        if ($href === '') {
            return;
        }

        $label = (string) apply_filters(
            'fflhub_email_for_quote_button_label',
            __('Email for quote', 'ffl-hub'),
            $product
        );

        echo '<p class="fflhub-email-for-quote-wrap">';
        echo '<a class="button alt fflhub-email-for-quote-button" href="' . esc_url($href) . '">';
        echo esc_html($label);
        echo '</a>';
        echo '</p>';
    }

    /**
     * Public accessor for other frontend/compliance flows.
     */
    public static function get_map_policy_for_product(WC_Product $product, ?WC_Product $parent = null): string
    {
        return self::map_policy_for_product($product, $parent);
    }

    private static function is_email_for_quote_policy(WC_Product $product, ?WC_Product $parent = null): bool
    {
        return self::map_policy_for_product($product, $parent) === Options::MAP_POLICY_EMAIL_FOR_QUOTE;
    }

    private static function map_price_for_product(WC_Product $product, ?WC_Product $parent = null): ?float
    {
        $map = self::to_positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_MAP_META, true));
        if ($map === null && $parent instanceof WC_Product) {
            $map = self::to_positive_float($parent->get_meta(ProductMeta::FFLHUB_LAST_MAP_META, true));
        }

        return $map;
    }

    private static function map_price_html(float $map_price): string
    {
        return '<span class="price fflhub-map-price">' . wc_price($map_price) . '</span>';
    }

    private static function email_for_quote_href(WC_Product $product): string
    {
        $recipient = sanitize_email((string) apply_filters(
            'fflhub_email_for_quote_recipient',
            (string) get_option('admin_email'),
            $product
        ));
        if ($recipient === '') {
            return '';
        }

        $subject = (string) apply_filters(
            'fflhub_email_for_quote_subject',
            sprintf(__('Quote request: %s', 'ffl-hub'), $product->get_name()),
            $product
        );

        $lines = [
            __('Hi, I would like a quote for this product:', 'ffl-hub'),
            '',
            $product->get_name(),
        ];

        $sku = trim((string) $product->get_sku());
        if ($sku !== '') {
            $lines[] = sprintf(__('SKU: %s', 'ffl-hub'), $sku);
        }

        $product_url = get_permalink($product->get_id());
        if (is_string($product_url) && $product_url !== '') {
            $lines[] = sprintf(__('Product URL: %s', 'ffl-hub'), $product_url);
        }

        $body = (string) apply_filters(
            'fflhub_email_for_quote_body',
            implode("\n", $lines),
            $product
        );

        $query = http_build_query(
            ['subject' => $subject, 'body' => $body],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        return 'mailto:' . $recipient . ($query !== '' ? ('?' . $query) : '');
    }

    private static function map_policy_for_product(WC_Product $product, ?WC_Product $parent = null): string
    {
        $policy_lookup = self::map_policy_lookup();
        if (empty($policy_lookup)) {
            return Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
        }

        $brand_names = self::brand_names_for_product($product);
        if (empty($brand_names) && $parent instanceof WC_Product) {
            $brand_names = self::brand_names_for_product($parent);
        }

        foreach ($brand_names as $brand_name) {
            $key = Options::normalize_brand_policy_key($brand_name);
            if ($key === '' || !isset($policy_lookup[$key])) {
                continue;
            }

            $policy = strtolower(trim((string) $policy_lookup[$key]));
            if ($policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE) {
                return Options::MAP_POLICY_EMAIL_FOR_QUOTE;
            }

            return Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
        }

        return Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
    }

    /**
     * @return array<string,string>
     */
    private static function map_policy_lookup(): array
    {
        if (self::$policy_lookup_cache === null) {
            $lookup = Options::get_map_brand_policy_lookup();
            self::$policy_lookup_cache = is_array($lookup) ? $lookup : [];
        }

        return self::$policy_lookup_cache;
    }

    /**
     * @return array<int,string>
     */
    private static function brand_names_for_product(WC_Product $product): array
    {
        $product_id = (int) $product->get_id();
        if ($product_id <= 0) {
            return [];
        }

        if (isset(self::$brand_names_by_product_id[$product_id])) {
            return self::$brand_names_by_product_id[$product_id];
        }

        $names = [];

        foreach (self::BRAND_TAXONOMY_CANDIDATES as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'names']);
            if (is_wp_error($terms) || !is_array($terms)) {
                continue;
            }

            foreach ($terms as $term_name) {
                $name = trim((string) $term_name);
                if ($name === '') {
                    continue;
                }

                $names[$name] = $name;
            }
        }

        self::$brand_names_by_product_id[$product_id] = array_values($names);
        return self::$brand_names_by_product_id[$product_id];
    }

    /**
     * @param mixed $value
     */
    private static function to_positive_float($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $float = (float) $value;
        return $float > 0 ? $float : null;
    }
}
