<?php

namespace FFLHub\Checkout;

use WC_Coupon;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles quote email links that add the quoted product to cart and apply the
 * generated quote coupon in one click.
 */
final class QuoteCartLinkHandler
{
    private const QUERY_PRODUCT_ID = 'fflhub_quote_add';
    private const QUERY_COUPON = 'fflhub_quote_coupon';
    private const QUERY_REDIRECT = 'fflhub_quote_redirect';

    public static function init(): void
    {
        add_action('template_redirect', [self::class, 'maybe_handle_quote_cart_link'], 0);
    }

    public static function build_url(int $product_id, string $coupon_code, string $redirect = 'checkout'): string
    {
        $product_id = max(0, $product_id);
        $coupon_code = trim($coupon_code);
        $redirect = in_array($redirect, ['cart', 'checkout'], true) ? $redirect : 'checkout';

        if ($product_id <= 0 || $coupon_code === '') {
            return '';
        }

        return add_query_arg(
            [
                self::QUERY_PRODUCT_ID => $product_id,
                self::QUERY_COUPON => $coupon_code,
                self::QUERY_REDIRECT => $redirect,
            ],
            home_url('/')
        );
    }

    public static function maybe_handle_quote_cart_link(): void
    {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        if (!isset($_GET[self::QUERY_PRODUCT_ID], $_GET[self::QUERY_COUPON])) {
            return;
        }

        if (!function_exists('WC') || !function_exists('wc_get_product')) {
            return;
        }

        $product_id = absint(self::query_string_value(self::QUERY_PRODUCT_ID));
        $coupon_code = sanitize_text_field(self::query_string_value(self::QUERY_COUPON));

        if ($product_id <= 0 || $coupon_code === '') {
            self::redirect_with_notice(__('This quote link is missing required information.', 'ffl-hub'));
        }

        $product = wc_get_product($product_id);
        if (!($product instanceof WC_Product)) {
            self::redirect_with_notice(__('The quoted product could not be found.', 'ffl-hub'));
        }

        $coupon = new WC_Coupon($coupon_code);
        if ((int) $coupon->get_id() <= 0) {
            self::redirect_with_notice(__('The quote coupon could not be found or has expired.', 'ffl-hub'));
        }

        if (self::coupon_is_unusable($coupon)) {
            self::redirect_with_notice(__('This quote coupon is no longer available.', 'ffl-hub'));
        }

        if (!self::coupon_allows_product($coupon, $product_id, $product)) {
            self::redirect_with_notice(__('This quote coupon does not match the quoted product.', 'ffl-hub'));
        }

        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        $woo = WC();
        if (!$woo || !$woo->cart) {
            self::redirect_with_notice(__('The cart is not available right now. Please try again.', 'ffl-hub'));
        }

        if (!self::cart_contains_product($product_id)) {
            $added = $woo->cart->add_to_cart($product_id, 1);
            if (!$added) {
                self::redirect_with_notice(__('The quoted product could not be added to cart.', 'ffl-hub'));
            }
        }

        if (!$woo->cart->has_discount($coupon_code)) {
            $woo->cart->apply_coupon($coupon_code);
        }

        $woo->cart->calculate_totals();

        wp_safe_redirect(self::redirect_url());
        exit;
    }

    private static function coupon_allows_product(WC_Coupon $coupon, int $product_id, WC_Product $product): bool
    {
        $allowed_product_ids = array_map('absint', (array) $coupon->get_product_ids());
        $allowed_product_ids = array_filter($allowed_product_ids);
        if (!$allowed_product_ids) {
            return true;
        }

        if (in_array($product_id, $allowed_product_ids, true)) {
            return true;
        }

        $parent_id = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        return $parent_id > 0 && in_array($parent_id, $allowed_product_ids, true);
    }

    private static function query_string_value(string $key): string
    {
        if (!isset($_GET[$key]) || is_array($_GET[$key])) {
            return '';
        }

        return (string) wp_unslash($_GET[$key]);
    }

    private static function cart_contains_product(int $product_id): bool
    {
        $cart = (function_exists('WC') && WC() && WC()->cart) ? WC()->cart : null;
        if (!$cart) {
            return false;
        }

        foreach ((array) $cart->get_cart() as $cart_item) {
            $cart_product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            $cart_variation_id = isset($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0;
            if ($cart_product_id === $product_id || $cart_variation_id === $product_id) {
                return true;
            }
        }

        return false;
    }

    private static function coupon_is_unusable(WC_Coupon $coupon): bool
    {
        $date_expires = $coupon->get_date_expires();
        if ($date_expires && $date_expires->getTimestamp() < time()) {
            return true;
        }

        $usage_limit = (int) $coupon->get_usage_limit();
        if ($usage_limit > 0 && (int) $coupon->get_usage_count() >= $usage_limit) {
            return true;
        }

        return false;
    }

    private static function redirect_with_notice(string $message): void
    {
        if (function_exists('wc_add_notice') && $message !== '') {
            wc_add_notice($message, 'error');
        }

        wp_safe_redirect(function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/'));
        exit;
    }

    private static function redirect_url(): string
    {
        $redirect = sanitize_key(self::query_string_value(self::QUERY_REDIRECT));

        if ($redirect === 'cart') {
            return function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/');
        }

        return function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout/');
    }
}
