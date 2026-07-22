<?php

namespace FFLHub\Product;

use FFLHub\Product\State\ProductStateStore;
use FFLHub\Product\Tables\QuoteEmailJobsSchema;
use FFLHub\Product\Tables\QuoteEmailJobsTable;
use FFLHub\Settings\Options;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

class MapPriceVisibility
{
    private const MAP_POLICY_NONE = 'none';
    private const EMAIL_FOR_QUOTE_FORM_ACTION = 'fflhub_email_for_quote_submit';
    private const QUOTE_SUBMISSION_DEDUPE_TTL_SECONDS = 180;
    private const BLOCKED_QUOTE_EMAILS = [
        'richard.smith9299@yahoo.com',
    ];

    public static function init(): void
    {
        // Front-end style rules for MAP visibility + quote CTA.
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);

        // Handle quote form submissions from product pages.
        add_action('admin_post_' . self::EMAIL_FOR_QUOTE_FORM_ACTION, [self::class, 'handle_email_for_quote_submit']);
        add_action('admin_post_nopriv_' . self::EMAIL_FOR_QUOTE_FORM_ACTION, [self::class, 'handle_email_for_quote_submit']);

        // Replace price HTML everywhere except cart/checkout
        add_filter('woocommerce_get_price_html', [self::class, 'filter_price_html'], 99, 2);

        // Variable products / variation JSON (prevents price appearing on selection UI)
        add_filter('woocommerce_available_variation', [self::class, 'filter_available_variation'], 99, 3);

        // Hide offer/price from Woo structured data (prevents Google showing price)
        add_filter('woocommerce_structured_data_product_offer', [self::class, 'filter_structured_offer'], 99, 2);
        // Render single-product notices/CTAs around the purchase controls.
        add_action('woocommerce_after_add_to_cart_form', [self::class, 'render_email_for_quote_button'], 10);
        add_action('wp_footer', [self::class, 'render_email_for_quote_modal']);
    }

    public static function enqueue_assets(): void
    {
        if (!self::is_product_surface_page()) {
            return;
        }

        $css_rel_path = 'assets/css/fflhub-map-price-visibility.css';
        $css_abs_path = plugin_dir_path(FFLHUB_PLUGIN_FILE) . $css_rel_path;
        if (!file_exists($css_abs_path)) {
            return;
        }

        wp_enqueue_style(
            'fflhub-map-price-visibility',
            plugins_url($css_rel_path, FFLHUB_PLUGIN_FILE),
            [],
            (string) filemtime($css_abs_path)
        );

        $js_rel_path = 'assets/js/fflhub-map-price-visibility.js';
        $js_abs_path = plugin_dir_path(FFLHUB_PLUGIN_FILE) . $js_rel_path;
        if (!file_exists($js_abs_path)) {
            return;
        }

        wp_enqueue_script(
            'fflhub-map-price-visibility',
            plugins_url($js_rel_path, FFLHUB_PLUGIN_FILE),
            [],
            (string) filemtime($js_abs_path),
            true
        );
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
            $default_text = __('Email for Quote', 'ffl-hub');
        }

        return (string) apply_filters(
            'fflhub_map_hidden_price_text',
            $default_text,
            $product,
            $parent,
            $policy
        );
    }

    /**
     * Hide Email-for-Quote flows when the product is out of stock.
     */
    private static function is_out_of_stock_for_quote(WC_Product $product): bool
    {
        $state_product_id = self::state_product_id($product, null);
        if ($state_product_id <= 0) {
            return true;
        }

        $local_qty = ProductStateStore::get_local_stock_override_qty_for_product($state_product_id);
        if ($local_qty !== null && $local_qty > 0) {
            return false;
        }

        if (ProductStateStore::get_stock_oos_override_for_product($state_product_id)) {
            return true;
        }

        $stock_status = strtolower(trim((string) ProductStateStore::get_stock_status_for_product($state_product_id)));
        if ($stock_status === 'outofstock') {
            return true;
        }

        $qty = ProductStateStore::get_qty_for_product($state_product_id);
        return $qty !== null && $qty <= 0;
    }

    private static function is_map_restricted(WC_Product $product, ?WC_Product $parent = null): bool
    {
        if (self::map_policy_for_product($product, $parent) === self::MAP_POLICY_NONE) {
            return false;
        }

        if (!self::has_product_level_map_policy_requirements($product, $parent)) {
            return false;
        }

        $map = self::map_price_for_product($product, $parent);
        $price = self::sell_price_for_map_check($product, $parent);
        if ($map === null || $price === null || $price <= 0.0) {
            return false;
        }

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

        // "No Email, No Add to Cart" products should show MAP price.
        if (self::is_no_email_no_add_to_cart_policy($product, $parent)) {
            return false;
        }

        // "Email for Quote" products should still show an explicit MAP price.
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

        if (self::should_show_map_price($product, null)) {
            $map_html = self::map_price_html($product, null);
            if ($map_html !== null) {
                return $map_html;
            }
        }

        if (!self::should_hide_price($product, null)) {
            return self::public_price_html($product, null) ?? $price_html;
        }

        return '<span class="fflhub-map-hidden-price">' . esc_html(self::hidden_text($product, null)) . '</span>';
    }

    public static function filter_available_variation(array $data, $parent, $variation): array
    {
        if (!($variation instanceof WC_Product)) {
            return $data;
        }

        $parent_product = ($parent instanceof WC_Product) ? $parent : null;

        if (self::should_show_map_price($variation, $parent_product)) {
            return self::apply_map_price_to_variation($data, $variation, $parent_product);
        }

        if (!self::should_hide_price($variation, $parent_product)) {
            return self::apply_public_price_to_variation($data, $variation, $parent_product);
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

        if (self::should_show_map_price($product, null)) {
            return self::apply_map_price_to_structured_offer($offer, $product, null);
        }

        if (self::should_hide_price($product, null)) {
            // Remove price/offer from structured data
            return [];
        }

        return self::apply_public_price_to_structured_offer($offer, $product, null);
    }

    public static function render_email_for_quote_button(): void
    {
        $product = self::current_product_for_quote();
        if (!($product instanceof WC_Product)) {
            return;
        }
        if (!self::is_email_for_quote_policy($product, null)) {
            return;
        }
        if (self::is_out_of_stock_for_quote($product)) {
            return;
        }

        $label = (string) apply_filters(
            'fflhub_email_for_quote_button_label',
            __('Email for Quote', 'ffl-hub'),
            $product
        );

        echo '<p class="fflhub-email-for-quote-wrap">';
        echo '<button type="button" class="button alt wp-element-button fflhub-email-for-quote-button" aria-label="' . esc_attr($label) . '" data-fflhub-quote-open="1">';
        echo esc_html($label);
        echo '</button>';
        echo '</p>';

        self::render_quote_notice(self::quote_request_status());
    }

    public static function render_email_for_quote_modal(): void
    {
        $product = self::current_product_for_quote();
        if (!($product instanceof WC_Product)) {
            return;
        }
        if (!self::is_email_for_quote_policy($product, null)) {
            return;
        }
        if (self::is_out_of_stock_for_quote($product)) {
            return;
        }

        $status = self::quote_request_status();
        $open_on_load = self::should_auto_open_quote_modal($status);
        $modal_classes = 'fflhub-email-for-quote-modal';
        if ($open_on_load) {
            $modal_classes .= ' is-open';
        }

        $redirect_url = self::quote_redirect_url((int) $product->get_id());

        echo '<div id="fflhub-email-for-quote-modal" class="' . esc_attr($modal_classes) . '" aria-hidden="' . ($open_on_load ? 'false' : 'true') . '" data-open-on-load="' . ($open_on_load ? '1' : '0') . '">';
        echo '<div class="fflhub-email-for-quote-modal__overlay" data-fflhub-quote-close="1"></div>';
        echo '<div class="fflhub-email-for-quote-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="fflhub-email-for-quote-title">';
        echo '<button type="button" class="fflhub-email-for-quote-modal__close" aria-label="' . esc_attr__('Close quote form', 'ffl-hub') . '" data-fflhub-quote-close="1">&times;</button>';

        echo '<h2 id="fflhub-email-for-quote-title" class="fflhub-email-for-quote-modal__title">' . esc_html__('Get Your Custom Quote', 'ffl-hub') . '</h2>';
        echo '<p>' . esc_html__('Enter your email and we will send a private checkout link with your quoted price shortly. Quote links are tied to your email, one time use, and expire automatically.', 'ffl-hub') . '</p>';

        echo '<form class="fflhub-email-for-quote-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::EMAIL_FOR_QUOTE_FORM_ACTION) . '">';
        echo '<input type="hidden" name="fflhub_product_id" value="' . esc_attr((string) $product->get_id()) . '">';
        echo '<input type="hidden" name="fflhub_redirect_url" value="' . esc_url($redirect_url) . '">';
        wp_nonce_field('fflhub_email_for_quote_submit_' . $product->get_id(), 'fflhub_email_for_quote_nonce');

        echo '<label for="fflhub-quote-first-name">' . esc_html__('First Name', 'ffl-hub') . '</label>';
        echo '<input id="fflhub-quote-first-name" name="fflhub_first_name" type="text" required maxlength="100">';
        echo '<input type="hidden" name="fflhub_last_name" value="">';

        echo '<label for="fflhub-quote-email">' . esc_html__('Email Address', 'ffl-hub') . '</label>';
        echo '<input id="fflhub-quote-email" name="fflhub_email" type="email" required maxlength="190">';

        echo '<input type="hidden" name="fflhub_receive_deals_updates" value="0">';
        echo '<label class="fflhub-email-for-quote-opt-in" for="fflhub-quote-receive-deals-updates">';
        echo '<input id="fflhub-quote-receive-deals-updates" name="fflhub_receive_deals_updates" type="checkbox" value="1" checked>';
        echo '<span>' . esc_html__('Yes, I wish to receive deals and updates.', 'ffl-hub') . '</span>';
        echo '</label>';

        echo '<button type="submit" class="button alt wp-element-button fflhub-email-for-quote-submit" data-submitting-label="' . esc_attr__('Sending...', 'ffl-hub') . '">' . esc_html__('Send Request', 'ffl-hub') . '</button>';
        echo '</form>';

        echo '</div>';
        echo '</div>';
    }

    public static function handle_email_for_quote_submit(): void
    {
        $product_id = isset($_POST['fflhub_product_id']) ? absint(wp_unslash((string) $_POST['fflhub_product_id'])) : 0;
        $posted_redirect = isset($_POST['fflhub_redirect_url']) ? (string) wp_unslash($_POST['fflhub_redirect_url']) : '';
        $redirect_url = self::resolve_posted_redirect_url($product_id, $posted_redirect);

        $nonce = isset($_POST['fflhub_email_for_quote_nonce']) ? (string) wp_unslash($_POST['fflhub_email_for_quote_nonce']) : '';
        if ($product_id <= 0 || !wp_verify_nonce($nonce, 'fflhub_email_for_quote_submit_' . $product_id)) {
            self::redirect_with_quote_status($redirect_url, 'invalid_request');
        }

        $first_name = self::normalize_quote_first_name(
            sanitize_text_field((string) wp_unslash($_POST['fflhub_first_name'] ?? ''))
        );
        $last_name = sanitize_text_field((string) wp_unslash($_POST['fflhub_last_name'] ?? ''));
        $email = sanitize_email((string) wp_unslash($_POST['fflhub_email'] ?? ''));
        $receive_deals_updates = ((string) wp_unslash($_POST['fflhub_receive_deals_updates'] ?? '0') === '1');

        if ($first_name === '' || $email === '') {
            self::redirect_with_quote_status($redirect_url, 'missing_fields');
        }
        if (!is_email($email)) {
            self::redirect_with_quote_status($redirect_url, 'invalid_email');
        }

        $product = wc_get_product($product_id);
        if (!($product instanceof WC_Product)) {
            self::redirect_with_quote_status($redirect_url, 'invalid_request');
        }
        if (self::is_out_of_stock_for_quote($product)) {
            self::redirect_with_quote_status($redirect_url, 'invalid_request');
        }
        if (!self::is_email_for_quote_policy($product, null)) {
            self::redirect_with_quote_status($redirect_url, 'invalid_request');
        }
        if (self::is_blocked_quote_name($first_name, $last_name)) {
            self::send_quote_block_warning_email(
                'blocked_name_dennis_joe',
                [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'product_id' => (int) $product->get_id(),
                    'product_name' => (string) $product->get_name(),
                ]
            );
            self::redirect_with_quote_status($redirect_url, 'invalid_request');
        }
        if (self::is_blocked_quote_email($email)) {
            self::send_quote_block_warning_email(
                'blocked_email_prohibited',
                [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'product_id' => (int) $product->get_id(),
                    'product_name' => (string) $product->get_name(),
                ]
            );
            self::redirect_with_quote_status($redirect_url, 'invalid_request');
        }

        $geo_block_ctx = self::quote_geo_block_context();
        if (!empty($geo_block_ctx['blocked'])) {
            self::send_quote_block_warning_email(
                'blocked_geo_city_of_industry_ca',
                [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'product_id' => (int) $product->get_id(),
                    'product_name' => (string) $product->get_name(),
                    'ip' => (string) ($geo_block_ctx['ip'] ?? ''),
                    'country' => (string) ($geo_block_ctx['country'] ?? ''),
                    'state' => (string) ($geo_block_ctx['state'] ?? ''),
                    'city' => (string) ($geo_block_ctx['city'] ?? ''),
                    'postcode' => (string) ($geo_block_ctx['postcode'] ?? ''),
                ]
            );
            self::redirect_with_quote_status($redirect_url, 'invalid_request');
        }

        self::maybe_send_quote_klaviyo_opt_in($first_name, $last_name, $email, $receive_deals_updates, $product);

        $submission_lock_key = self::quote_submission_lock_key($product_id, $first_name, $last_name, $email);
        if (self::is_quote_submission_locked($submission_lock_key)) {
            self::redirect_with_quote_status($redirect_url, 'success');
        }

        if (self::has_recent_duplicate_quote_job($product, $first_name, $last_name, $email)) {
            self::set_quote_submission_lock($submission_lock_key);
            self::redirect_with_quote_status($redirect_url, 'success');
        }

        self::set_quote_submission_lock($submission_lock_key);

        $recipient = sanitize_email((string) apply_filters(
            'fflhub_email_for_quote_recipient',
            (string) get_option('admin_email'),
            $product
        ));
        if ($recipient === '') {
            self::redirect_with_quote_status($redirect_url, 'invalid_request');
        }

        $subject = (string) apply_filters(
            'fflhub_email_for_quote_subject',
            sprintf(__('Quote request: %s', 'ffl-hub'), $product->get_name()),
            $product
        );

        $product_url = get_permalink($product_id);
        if (!is_string($product_url) || $product_url === '') {
            $product_url = '';
        }

        $body_lines = [
            __('A new custom price quote request has been submitted.', 'ffl-hub'),
            '',
            sprintf(__('First Name: %s', 'ffl-hub'), $first_name),
            sprintf(__('Email: %s', 'ffl-hub'), $email),
            sprintf(__('Receive deals and updates: %s', 'ffl-hub'), $receive_deals_updates ? __('Yes', 'ffl-hub') : __('No', 'ffl-hub')),
            sprintf(__('Product: %s', 'ffl-hub'), $product->get_name()),
            sprintf(__('SKU: %s', 'ffl-hub'), (string) $product->get_sku()),
            sprintf(__('Product URL: %s', 'ffl-hub'), $product_url),
        ];

        $message = (string) apply_filters(
            'fflhub_email_for_quote_body',
            implode("\n", $body_lines),
            $product
        );

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . trim($first_name . ' ' . $last_name) . ' <' . $email . '>',
        ];

        $sent = wp_mail($recipient, $subject, $message, $headers);
        $saved_job = self::insert_quote_email_job($product, $first_name, $last_name, $email, $receive_deals_updates);
        if (!$saved_job) {
            self::clear_quote_submission_lock($submission_lock_key);
            self::redirect_with_quote_status($redirect_url, 'mail_error');
        }

        self::redirect_with_quote_status($redirect_url, $sent ? 'success' : 'mail_error');
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
        return self::has_product_level_map_policy_requirements($product, $parent)
            && self::map_policy_for_product($product, $parent) === Options::MAP_POLICY_EMAIL_FOR_QUOTE;
    }

    private static function is_no_email_no_add_to_cart_policy(WC_Product $product, ?WC_Product $parent = null): bool
    {
        return self::has_product_level_map_policy_requirements($product, $parent)
            && self::map_policy_for_product($product, $parent) === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART;
    }

    private static function has_product_level_map_policy_requirements(WC_Product $product, ?WC_Product $parent = null): bool
    {
        $state_product_id = self::state_product_id($product, $parent);
        return $state_product_id > 0
            && ProductStateStore::get_map_applicable_for_product($state_product_id)
            && ProductStateStore::get_map_price_for_product($state_product_id) !== null;
    }

    private static function should_show_map_price(WC_Product $product, ?WC_Product $parent = null): bool
    {
        if (self::in_cart_flow()) {
            return false;
        }

        return self::is_email_for_quote_policy($product, $parent)
            || self::is_no_email_no_add_to_cart_policy($product, $parent);
    }

    private static function map_price_for_product(WC_Product $product, ?WC_Product $parent = null): ?float
    {
        $state_product_id = self::state_product_id($product, $parent);
        if ($state_product_id <= 0 || !ProductStateStore::get_map_applicable_for_product($state_product_id)) {
            return null;
        }

        $map = ProductStateStore::get_map_price_for_product($state_product_id);
        return ($map !== null && $map > 0.0) ? $map : null;
    }

    private static function map_price_html(WC_Product $product, ?WC_Product $parent = null): ?string
    {
        $map = self::map_price_for_product($product, $parent);
        if (!is_numeric($map) || (float) $map <= 0.0) {
            return null;
        }

        $display_price = (float) $map;
        if (function_exists('wc_get_price_to_display')) {
            $display_price = (float) wc_get_price_to_display($product, ['price' => (float) $map]);
        }

        if (function_exists('wc_price')) {
            return (string) wc_price($display_price);
        }

        return (string) $display_price;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function apply_map_price_to_variation(array $data, WC_Product $variation, ?WC_Product $parent = null): array
    {
        $map = self::map_price_for_product($variation, $parent);
        $map_html = self::map_price_html($variation, $parent);

        if ($map_html !== null) {
            $data['price_html'] = $map_html;
        }

        if ($map !== null && $map > 0.0) {
            $map_decimal = function_exists('wc_format_decimal')
                ? wc_format_decimal($map, wc_get_price_decimals())
                : (string) $map;
            $data['display_price'] = $map;
            $data['display_regular_price'] = $map;
            $data['price'] = $map_decimal;
            $data['regular_price'] = $map_decimal;
            $data['sale_price'] = '';
        }

        return $data;
    }

    private static function apply_map_price_to_structured_offer($offer, WC_Product $product, ?WC_Product $parent = null)
    {
        $map = self::map_price_for_product($product, $parent);
        if ($map === null || $map <= 0.0 || !is_array($offer)) {
            return $offer;
        }

        $map_decimal = function_exists('wc_format_decimal')
            ? wc_format_decimal($map, wc_get_price_decimals())
            : (string) $map;

        foreach (['price', 'lowPrice', 'highPrice'] as $price_key) {
            if (isset($offer[$price_key])) {
                $offer[$price_key] = $map_decimal;
            }
        }

        if (isset($offer['priceSpecification']) && is_array($offer['priceSpecification'])) {
            foreach (['price', 'minPrice', 'maxPrice'] as $price_spec_key) {
                if (isset($offer['priceSpecification'][$price_spec_key])) {
                    $offer['priceSpecification'][$price_spec_key] = $map_decimal;
                }
            }
        }

        return $offer;
    }

    private static function public_price_html(WC_Product $product, ?WC_Product $parent = null): ?string
    {
        $prices = self::public_prices_for_product($product, $parent);
        if ($prices === null) {
            return null;
        }

        $active_display = self::display_price($product, $prices['active']);
        $active_html = function_exists('wc_price') ? (string) wc_price($active_display) : (string) $active_display;

        if ($prices['sale'] !== null && $prices['regular'] > $prices['sale']) {
            $regular_display = self::display_price($product, $prices['regular']);
            $regular_html = function_exists('wc_price') ? (string) wc_price($regular_display) : (string) $regular_display;
            $active_html = function_exists('wc_format_sale_price')
                ? (string) wc_format_sale_price($regular_html, $active_html)
                : $active_html;
        }

        if (method_exists($product, 'get_price_suffix')) {
            $active_html .= (string) $product->get_price_suffix();
        }

        return $active_html;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function apply_public_price_to_variation(array $data, WC_Product $variation, ?WC_Product $parent = null): array
    {
        $prices = self::public_prices_for_product($variation, $parent);
        if ($prices === null) {
            return $data;
        }

        $data['price_html'] = self::public_price_html($variation, $parent) ?? ($data['price_html'] ?? '');
        $data['display_price'] = $prices['active'];
        $data['display_regular_price'] = $prices['regular'];
        $data['price'] = self::decimal_price($prices['active']);
        $data['regular_price'] = self::decimal_price($prices['regular']);
        $data['sale_price'] = ($prices['sale'] !== null && $prices['regular'] > $prices['sale'])
            ? self::decimal_price($prices['sale'])
            : '';

        return $data;
    }

    private static function apply_public_price_to_structured_offer($offer, WC_Product $product, ?WC_Product $parent = null)
    {
        $prices = self::public_prices_for_product($product, $parent);
        if ($prices === null || !is_array($offer)) {
            return $offer;
        }

        $price_decimal = self::decimal_price($prices['active']);
        foreach (['price', 'lowPrice', 'highPrice'] as $price_key) {
            if (isset($offer[$price_key])) {
                $offer[$price_key] = $price_decimal;
            }
        }

        if (isset($offer['priceSpecification']) && is_array($offer['priceSpecification'])) {
            foreach (['price', 'minPrice', 'maxPrice'] as $price_spec_key) {
                if (isset($offer['priceSpecification'][$price_spec_key])) {
                    $offer['priceSpecification'][$price_spec_key] = $price_decimal;
                }
            }
        }

        return $offer;
    }

    /**
     * @return array{regular:float,sale:?float,active:float}|null
     */
    private static function public_prices_for_product(WC_Product $product, ?WC_Product $parent = null): ?array
    {
        $state_product_id = self::state_product_id($product, $parent);
        if ($state_product_id <= 0) {
            return null;
        }

        $regular = self::positive_state_price(ProductStateStore::get_public_regular_price_for_product($state_product_id));
        $sale = self::positive_state_price(ProductStateStore::get_public_sale_price_for_product($state_product_id));
        if ($regular === null && $sale === null) {
            return null;
        }

        if ($regular === null) {
            $regular = $sale;
        }

        $active = ($sale !== null && $sale < $regular) ? $sale : $regular;

        return [
            'regular' => $regular,
            'sale' => $sale,
            'active' => $active,
        ];
    }

    private static function sell_price_for_map_check(WC_Product $product, ?WC_Product $parent = null): ?float
    {
        $state_product_id = self::state_product_id($product, $parent);
        if ($state_product_id <= 0) {
            return null;
        }

        return self::positive_state_price(
            ProductStateStore::get_computed_sell_price_for_product($state_product_id)
        ) ?? self::positive_state_price(
            ProductStateStore::get_public_sale_price_for_product($state_product_id)
        ) ?? self::positive_state_price(
            ProductStateStore::get_public_regular_price_for_product($state_product_id)
        );
    }

    private static function positive_state_price(?float $price): ?float
    {
        return ($price !== null && $price > 0.0) ? $price : null;
    }

    private static function display_price(WC_Product $product, float $price): float
    {
        if (function_exists('wc_get_price_to_display')) {
            return (float) wc_get_price_to_display($product, ['price' => $price]);
        }

        return $price;
    }

    private static function decimal_price(float $price): string
    {
        return function_exists('wc_format_decimal')
            ? wc_format_decimal($price, function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2)
            : (string) $price;
    }

    private static function state_product_id(WC_Product $product, ?WC_Product $parent = null): int
    {
        $product_id = (int) $product->get_id();
        if ($product_id > 0 && ProductStateStore::is_active_product($product_id)) {
            return $product_id;
        }

        if ($parent instanceof WC_Product) {
            $parent_id = (int) $parent->get_id();
            if ($parent_id > 0 && ProductStateStore::is_active_product($parent_id)) {
                return $parent_id;
            }
        }

        if (method_exists($product, 'get_parent_id')) {
            $parent_id = (int) $product->get_parent_id();
            if ($parent_id > 0 && ProductStateStore::is_active_product($parent_id)) {
                return $parent_id;
            }
        }

        return 0;
    }

    private static function is_product_surface_page(): bool
    {
        if (function_exists('is_product') && is_product()) {
            return true;
        }

        if (function_exists('is_shop') && is_shop()) {
            return true;
        }

        if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
            return true;
        }

        if (function_exists('is_post_type_archive') && is_post_type_archive('product')) {
            return true;
        }

        return false;
    }

    private static function current_product_for_quote(): ?WC_Product
    {
        if (!function_exists('is_product') || !is_product()) {
            return null;
        }

        global $product;
        if ($product instanceof WC_Product) {
            return $product;
        }

        $queried_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
        if ($queried_id <= 0) {
            return null;
        }

        $queried_product = wc_get_product($queried_id);
        return ($queried_product instanceof WC_Product) ? $queried_product : null;
    }

    private static function quote_request_status(): string
    {
        if (!isset($_GET['fflhub_quote_request'])) {
            return '';
        }

        return sanitize_key((string) wp_unslash($_GET['fflhub_quote_request']));
    }

    private static function should_auto_open_quote_modal(string $status): bool
    {
        return in_array($status, ['missing_fields', 'invalid_email', 'invalid_request', 'mail_error'], true);
    }

    private static function render_quote_notice(string $status): void
    {
        if ($status === '') {
            return;
        }

        $message = '';
        $class = 'fflhub-email-for-quote-notice';

        if ($status === 'success') {
            $class .= ' is-success';
            $message = __('Thanks, your quote request was submitted. Check your inbox shortly for your private checkout link.', 'ffl-hub');
        } else {
            $class .= ' is-error';
            if ($status === 'missing_fields') {
                $message = __('Please complete First Name and Email Address.', 'ffl-hub');
            } elseif ($status === 'invalid_email') {
                $message = __('Please enter a valid email address.', 'ffl-hub');
            } else {
                $sales_phone = self::store_phone_for_quote();
                if ($sales_phone !== '') {
                    $message = sprintf(
                        /* translators: %s = sales phone number */
                        __('We could not submit your request right now. Please try again or call Sales at %s option 1.', 'ffl-hub'),
                        $sales_phone
                    );
                } else {
                    $message = __('We could not submit your request right now. Please try again.', 'ffl-hub');
                }
            }
        }

        echo '<div class="' . esc_attr($class) . '">' . esc_html($message) . '</div>';
    }

    private static function quote_redirect_url(int $product_id): string
    {
        $permalink = ($product_id > 0) ? get_permalink($product_id) : home_url('/');
        if (!is_string($permalink) || $permalink === '') {
            $permalink = home_url('/');
        }

        return (string) remove_query_arg('fflhub_quote_request', $permalink);
    }

    private static function resolve_posted_redirect_url(int $product_id, string $posted_redirect): string
    {
        $fallback = self::quote_redirect_url($product_id);
        $posted_redirect = trim($posted_redirect);
        if ($posted_redirect === '') {
            return $fallback;
        }

        $validated = wp_validate_redirect($posted_redirect, '');
        return ($validated !== '') ? $validated : $fallback;
    }

    private static function redirect_with_quote_status(string $redirect_url, string $status): void
    {
        $target = add_query_arg(
            'fflhub_quote_request',
            $status,
            (string) remove_query_arg('fflhub_quote_request', $redirect_url)
        );

        wp_safe_redirect($target);
        exit;
    }

    private static function store_phone_for_quote(): string
    {
        $phone = (string) get_option('woocommerce_store_phone', '');
        $phone = trim(sanitize_text_field($phone));
        return $phone;
    }

    private static function maybe_send_quote_klaviyo_opt_in(
        string $first_name,
        string $last_name,
        string $email,
        bool $receive_deals_updates,
        WC_Product $product
    ): void {
        if (!$receive_deals_updates || !is_email($email)) {
            return;
        }

        $settings = get_option('klaviyo_settings');
        if (!is_array($settings)) {
            return;
        }

        $public_key = trim((string) ($settings['klaviyo_public_api_key'] ?? ''));
        $list_id = trim((string) ($settings['klaviyo_newsletter_list_id'] ?? ''));
        if ($public_key === '' || $list_id === '') {
            return;
        }

        $first_name = trim($first_name);
        $last_name = trim($last_name);

        $body = [
            'data' => [
                [
                    'customer' => [
                        'email' => $email,
                        'phone' => '',
                    ],
                    'consent' => true,
                    'updated_at' => gmdate(DATE_ATOM),
                    'consent_type' => 'email',
                    'group_id' => $list_id,
                ],
            ],
        ];

        $body = (array) apply_filters(
            'fflhub_quote_klaviyo_opt_in_payload',
            $body,
            $product,
            $email,
            $first_name,
            $last_name
        );

        $encoded_body = wp_json_encode($body);
        if (!is_string($encoded_body) || $encoded_body === '') {
            return;
        }

        wp_remote_post(
            'https://a.klaviyo.com/api/webhook/integration/woocommerce?c=' . rawurlencode($public_key),
            [
                'method' => 'POST',
                'httpversion' => '1.0',
                'blocking' => false,
                'headers' => [
                    'X-WC-Webhook-Topic' => 'custom/consent',
                    'Content-Type' => 'application/json',
                ],
                'body' => $encoded_body,
                'data_format' => 'body',
            ]
        );
    }

    private static function insert_quote_email_job(
        WC_Product $product,
        string $first_name,
        string $last_name,
        string $email,
        bool $receive_deals_updates
    ): bool {
        global $wpdb;

        $schema = new QuoteEmailJobsSchema();
        $table = new QuoteEmailJobsTable($schema);
        $table_name = $table->get_table_name();

        $upc = self::quote_product_upc($product);
        $product_name = self::truncate_quote_job_value((string) $product->get_name(), 255);
        $submitted_at = (string) current_time('mysql', true);
        $random_delay_minutes = 0;

        if (self::has_recent_duplicate_quote_job_values($table_name, $first_name, $last_name, $email, $upc, $product_name)) {
            return true;
        }

        $inserted = $wpdb->insert(
            $table_name,
            [
                'request_first_name'   => self::truncate_quote_job_value($first_name, 100),
                'request_last_name'    => self::truncate_quote_job_value($last_name, 100),
                'request_email'         => self::truncate_quote_job_value($email, 190),
                'receive_deals_updates' => $receive_deals_updates ? 1 : 0,
                'quote_upc'             => self::truncate_quote_job_value($upc, 64),
                'quote_product_name'    => $product_name,
                'submitted_at'          => $submitted_at,
                'random_delay_minutes'  => $random_delay_minutes,
                // This flag tracks the delayed customer-facing quote email, not
                // the immediate internal/store notification sent on form submit.
                'email_sent'            => 0,
            ],
            [
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
            ]
        );

        return $inserted === 1;
    }

    private static function has_recent_duplicate_quote_job(
        WC_Product $product,
        string $first_name,
        string $last_name,
        string $email
    ): bool {
        global $wpdb;

        $schema = new QuoteEmailJobsSchema();
        $table = new QuoteEmailJobsTable($schema);
        $table_name = $table->get_table_name();

        $upc = self::quote_product_upc($product);
        $product_name = self::truncate_quote_job_value((string) $product->get_name(), 255);

        return self::has_recent_duplicate_quote_job_values($table_name, $first_name, $last_name, $email, $upc, $product_name);
    }

    private static function has_recent_duplicate_quote_job_values(
        string $table_name,
        string $first_name,
        string $last_name,
        string $email,
        string $upc,
        string $product_name
    ): bool {
        global $wpdb;

        $since_utc = gmdate('Y-m-d H:i:s', time() - self::quote_submission_dedupe_ttl_seconds());
        $first_name = self::truncate_quote_job_value($first_name, 100);
        $last_name = self::truncate_quote_job_value($last_name, 100);
        $email = self::truncate_quote_job_value($email, 190);
        $upc = self::truncate_quote_job_value($upc, 64);
        $product_name = self::truncate_quote_job_value($product_name, 255);

        if ($upc !== '') {
            $sql = $wpdb->prepare(
                "SELECT id
                 FROM {$table_name}
                 WHERE request_email = %s
                   AND request_first_name = %s
                   AND request_last_name = %s
                   AND quote_upc = %s
                   AND submitted_at >= %s
                 ORDER BY id DESC
                 LIMIT 1",
                $email,
                $first_name,
                $last_name,
                $upc,
                $since_utc
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT id
                 FROM {$table_name}
                 WHERE request_email = %s
                   AND request_first_name = %s
                   AND request_last_name = %s
                   AND quote_product_name = %s
                   AND submitted_at >= %s
                 ORDER BY id DESC
                 LIMIT 1",
                $email,
                $first_name,
                $last_name,
                $product_name,
                $since_utc
            );
        }

        $existing_id = (int) $wpdb->get_var($sql);
        return $existing_id > 0;
    }

    private static function quote_product_upc(WC_Product $product): string
    {
        $state_product_id = self::state_product_id($product, null);
        if ($state_product_id <= 0) {
            return '';
        }

        return ProductStateStore::get_upc_for_product($state_product_id) ?? '';
    }

    private static function truncate_quote_job_value(string $value, int $max_length): string
    {
        $value = trim(sanitize_text_field($value));
        if ($max_length <= 0 || strlen($value) <= $max_length) {
            return $value;
        }

        return substr($value, 0, $max_length);
    }

    private static function normalize_quote_first_name(string $first_name): string
    {
        $first_name = trim(sanitize_text_field($first_name));
        if ($first_name === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $first_name);
        if (is_array($parts) && !empty($parts)) {
            $candidate = trim((string) ($parts[0] ?? ''));
            if ($candidate !== '') {
                $first_name = $candidate;
            }
        }

        $lower = strtolower($first_name);
        $normalized = preg_replace_callback(
            "/(^|[\\s\\-'])([a-z])/i",
            static function (array $matches): string {
                $prefix = isset($matches[1]) ? (string) $matches[1] : '';
                $letter = isset($matches[2]) ? (string) $matches[2] : '';
                return $prefix . strtoupper($letter);
            },
            $lower
        );

        if (!is_string($normalized) || $normalized === '') {
            return $first_name;
        }

        return $normalized;
    }

    private static function is_blocked_quote_name(string $first_name, string $last_name): bool
    {
        $first = strtolower(trim(sanitize_text_field($first_name)));
        $last = strtolower(trim(sanitize_text_field($last_name)));
        return ($first === 'dennis' && $last === 'joe');
    }

    private static function is_blocked_quote_email(string $email): bool
    {
        $email = strtolower(trim(sanitize_email($email)));
        if ($email === '') {
            return false;
        }

        $blocked_emails = (array) apply_filters('fflhub_blocked_quote_emails', self::BLOCKED_QUOTE_EMAILS);
        foreach ($blocked_emails as $blocked_email) {
            if ($email === strtolower(trim(sanitize_email((string) $blocked_email)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{blocked:bool,ip:string,country:string,state:string,city:string,postcode:string}
     */
    private static function quote_geo_block_context(): array
    {
        $ctx = [
            'blocked' => false,
            'ip' => '',
            'country' => '',
            'state' => '',
            'city' => '',
            'postcode' => '',
        ];

        if (!class_exists('\WC_Geolocation')) {
            return $ctx;
        }

        $ip = (string) \WC_Geolocation::get_ip_address();
        $ctx['ip'] = trim($ip);
        if ($ctx['ip'] === '') {
            return $ctx;
        }

        $geo = \WC_Geolocation::geolocate_ip($ctx['ip'], true, true);
        if (!is_array($geo)) {
            return $ctx;
        }

        $ctx['country'] = strtoupper(trim((string) ($geo['country'] ?? '')));
        $ctx['state'] = strtoupper(trim((string) ($geo['state'] ?? '')));
        $ctx['city'] = trim((string) ($geo['city'] ?? ''));
        $ctx['postcode'] = trim((string) ($geo['postcode'] ?? ''));

        $city_lc = strtolower($ctx['city']);
        $is_city_of_industry = ($city_lc === 'city of industry') || ($city_lc === 'industry');
        $is_ca = ($ctx['state'] === 'CA') || (strtolower($ctx['state']) === 'california');
        $is_us = ($ctx['country'] === 'US');

        if ($is_city_of_industry && $is_ca && $is_us) {
            $ctx['blocked'] = true;
        }

        return $ctx;
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function send_quote_block_warning_email(string $reason_code, array $context): void
    {
        $recipient = sanitize_email((string) apply_filters(
            'fflhub_quote_request_block_warning_recipient',
            (string) get_option('admin_email'),
            $reason_code,
            $context
        ));
        if ($recipient === '' || !is_email($recipient)) {
            return;
        }

        $subject = (string) apply_filters(
            'fflhub_quote_request_block_warning_subject',
            sprintf('[FFLHub] Quote request blocked (%s)', $reason_code),
            $reason_code,
            $context
        );

        $lines = [
            'A quote request was blocked.',
            '',
            'Reason: ' . $reason_code,
        ];

        $fields = [
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'email' => 'Email',
            'product_id' => 'Product ID',
            'product_name' => 'Product Name',
            'ip' => 'IP',
            'country' => 'Country',
            'state' => 'State',
            'city' => 'City',
            'postcode' => 'Postcode',
        ];

        foreach ($fields as $key => $label) {
            $value = trim((string) ($context[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $lines[] = $label . ': ' . $value;
        }

        $message = (string) apply_filters(
            'fflhub_quote_request_block_warning_body',
            implode("\n", $lines),
            $reason_code,
            $context
        );

        wp_mail($recipient, $subject, $message, ['Content-Type: text/plain; charset=UTF-8']);
    }

    private static function quote_submission_dedupe_ttl_seconds(): int
    {
        $ttl = (int) apply_filters('fflhub_quote_submission_dedupe_ttl_seconds', self::QUOTE_SUBMISSION_DEDUPE_TTL_SECONDS);
        return max(30, $ttl);
    }

    private static function quote_submission_lock_key(
        int $product_id,
        string $first_name,
        string $last_name,
        string $email
    ): string {
        $first_name = strtolower(self::truncate_quote_job_value($first_name, 100));
        $last_name = strtolower(self::truncate_quote_job_value($last_name, 100));
        $email = strtolower(self::truncate_quote_job_value($email, 190));

        $fingerprint = md5($product_id . '|' . $first_name . '|' . $last_name . '|' . $email);
        return 'fflhub_quote_submit_lock_' . $fingerprint;
    }

    private static function is_quote_submission_locked(string $lock_key): bool
    {
        return get_transient($lock_key) === '1';
    }

    private static function set_quote_submission_lock(string $lock_key): void
    {
        set_transient($lock_key, '1', self::quote_submission_dedupe_ttl_seconds());
    }

    private static function clear_quote_submission_lock(string $lock_key): void
    {
        delete_transient($lock_key);
    }

    private static function map_policy_for_product(WC_Product $product, ?WC_Product $parent = null): string
    {
        $state_product_id = self::state_product_id($product, $parent);
        if ($state_product_id <= 0) {
            return Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
        }

        $policy = strtolower(trim((string) ProductStateStore::get_map_visibility_policy_for_product($state_product_id)));
        if ($policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE || $policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) {
            return $policy;
        }

        if ($policy === self::MAP_POLICY_NONE) {
            return self::MAP_POLICY_NONE;
        }

        return Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
    }

}
