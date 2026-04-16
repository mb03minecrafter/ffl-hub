<?php

namespace FFLHub\Product;

use FFLHub\Product\Tables\QuoteEmailJobsSchema;
use FFLHub\Product\Tables\QuoteEmailJobsTable;
use FFLHub\Settings\Options;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

class MapPriceVisibility
{
    private const BRAND_TAXONOMY_CANDIDATES = ['product_brand', 'pa_brand'];
    private const EMAIL_FOR_QUOTE_FORM_ACTION = 'fflhub_email_for_quote_submit';
    private const QUOTE_SUBMISSION_DEDUPE_TTL_SECONDS = 180;
    private const HOLOSUN_BRAND_DEFAULT_ALIAS = 'holosun';

    /** @var array<string,string>|null */
    private static ?array $policy_lookup_cache = null;

    /** @var array<int,array<int,string>> */
    private static array $brand_names_by_product_id = [];
    private static bool $holosun_notice_rendered = false;

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

        // Render single-product notices/CTAs in the product summary area.
        add_action('woocommerce_single_product_summary', [self::class, 'render_email_for_quote_button'], 31);
        add_action('woocommerce_single_product_summary', [self::class, 'render_holosun_brand_notice_near_image'], 30);
        add_action('wp_footer', [self::class, 'render_email_for_quote_modal']);
    }

    public static function enqueue_assets(): void
    {
        if (!function_exists('is_product') || !is_product()) {
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

    /**
     * Hide Email-for-Quote flows when the product is out of stock.
     */
    private static function is_out_of_stock_for_quote(WC_Product $product): bool
    {
        $stock_status = strtolower(trim((string) $product->get_stock_status()));
        if ($stock_status === 'outofstock') {
            return true;
        }

        if (method_exists($product, 'is_in_stock') && !$product->is_in_stock()) {
            return true;
        }

        return self::is_truthy_value($product->get_meta(ProductMeta::FFLHUB_STOCK_OOS_OVERRIDE_META, true));
    }

    /**
     * @param mixed $value
     */
    private static function is_truthy_value($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $raw = strtolower(trim((string) $value));
        return in_array($raw, ['1', 'true', 'yes', 'y', 'on'], true);
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

        // "No Email, No Add to Cart" brands should show MAP price.
        if (self::is_no_email_no_add_to_cart_policy($product, $parent)) {
            return false;
        }

        // "Email for Quote" brands should still show an explicit price (MSRP).
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

        if (self::should_force_email_quote_msrp_price($product, null)) {
            $msrp_html = self::msrp_price_html($product, null);
            if ($msrp_html !== null) {
                return $msrp_html;
            }
        }

        if (self::should_force_holosun_msrp_price($product, null)) {
            $msrp_html = self::msrp_price_html($product, null);
            if ($msrp_html !== null) {
                return $msrp_html;
            }
        }

        if (self::should_force_map_price($product, null)) {
            $map_html = self::map_price_html($product, null);
            if ($map_html !== null) {
                return $map_html;
            }
        }

        // Fallback: if MSRP is unavailable, keep Woo regular/sale rendering unchanged.
        if (self::is_email_for_quote_policy($product, null)) {
            return $price_html;
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

        if (self::should_force_email_quote_msrp_price($variation, $parent_product)) {
            $msrp = self::msrp_price_for_product($variation, $parent_product);
            $msrp_html = self::msrp_price_html($variation, $parent_product);

            if ($msrp_html !== null) {
                $data['price_html'] = $msrp_html;
            }

            if (is_numeric($msrp) && (float) $msrp > 0.0) {
                $msrp_value = (float) $msrp;
                $msrp_decimal = function_exists('wc_format_decimal')
                    ? wc_format_decimal($msrp_value, wc_get_price_decimals())
                    : (string) $msrp_value;
                $data['display_price'] = $msrp_value;
                $data['display_regular_price'] = $msrp_value;
                $data['price'] = $msrp_decimal;
                $data['regular_price'] = $msrp_decimal;
                $data['sale_price'] = '';
            }

            return $data;
        }

        if (self::should_force_holosun_msrp_price($variation, $parent_product)) {
            $msrp = self::msrp_price_for_product($variation, $parent_product);
            $msrp_html = self::msrp_price_html($variation, $parent_product);

            if ($msrp_html !== null) {
                $data['price_html'] = $msrp_html;
            }

            if (is_numeric($msrp) && (float) $msrp > 0.0) {
                $msrp_value = (float) $msrp;
                $msrp_decimal = function_exists('wc_format_decimal')
                    ? wc_format_decimal($msrp_value, wc_get_price_decimals())
                    : (string) $msrp_value;
                $data['display_price'] = $msrp_value;
                $data['display_regular_price'] = $msrp_value;
                $data['price'] = $msrp_decimal;
                $data['regular_price'] = $msrp_decimal;
                $data['sale_price'] = '';
            }

            return $data;
        }

        if (self::should_force_map_price($variation, $parent_product)) {
            $map = self::map_price_for_product($variation, $parent_product);
            $map_html = self::map_price_html($variation, $parent_product);

            if ($map_html !== null) {
                $data['price_html'] = $map_html;
            }

            if (is_numeric($map) && (float) $map > 0.0) {
                $map_value = (float) $map;
                $map_decimal = function_exists('wc_format_decimal')
                    ? wc_format_decimal($map_value, wc_get_price_decimals())
                    : (string) $map_value;
                $data['display_price'] = $map_value;
                $data['display_regular_price'] = $map_value;
                $data['price'] = $map_decimal;
                $data['regular_price'] = $map_decimal;
                $data['sale_price'] = '';
            }

            return $data;
        }

        // Fallback: if MSRP is unavailable, keep Woo variation pricing data unchanged.
        if (self::is_email_for_quote_policy($variation, $parent_product)) {
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

        if (self::should_force_email_quote_msrp_price($product, null)) {
            $msrp = self::msrp_price_for_product($product, null);
            if (!is_numeric($msrp) || (float) $msrp <= 0.0 || !is_array($offer)) {
                return $offer;
            }

            $msrp_decimal = function_exists('wc_format_decimal')
                ? wc_format_decimal((float) $msrp, wc_get_price_decimals())
                : (string) $msrp;

            foreach (['price', 'lowPrice', 'highPrice'] as $price_key) {
                if (isset($offer[$price_key])) {
                    $offer[$price_key] = $msrp_decimal;
                }
            }

            if (isset($offer['priceSpecification']) && is_array($offer['priceSpecification'])) {
                foreach (['price', 'minPrice', 'maxPrice'] as $price_spec_key) {
                    if (isset($offer['priceSpecification'][$price_spec_key])) {
                        $offer['priceSpecification'][$price_spec_key] = $msrp_decimal;
                    }
                }
            }

            return $offer;
        }

        if (self::should_force_holosun_msrp_price($product, null)) {
            $msrp = self::msrp_price_for_product($product, null);
            if (!is_numeric($msrp) || (float) $msrp <= 0.0 || !is_array($offer)) {
                return $offer;
            }

            $msrp_decimal = function_exists('wc_format_decimal')
                ? wc_format_decimal((float) $msrp, wc_get_price_decimals())
                : (string) $msrp;

            foreach (['price', 'lowPrice', 'highPrice'] as $price_key) {
                if (isset($offer[$price_key])) {
                    $offer[$price_key] = $msrp_decimal;
                }
            }

            if (isset($offer['priceSpecification']) && is_array($offer['priceSpecification'])) {
                foreach (['price', 'minPrice', 'maxPrice'] as $price_spec_key) {
                    if (isset($offer['priceSpecification'][$price_spec_key])) {
                        $offer['priceSpecification'][$price_spec_key] = $msrp_decimal;
                    }
                }
            }

            return $offer;
        }

        if (self::should_force_map_price($product, null)) {
            $map = self::map_price_for_product($product, null);
            if (!is_numeric($map) || (float) $map <= 0.0 || !is_array($offer)) {
                return $offer;
            }

            $map_decimal = function_exists('wc_format_decimal')
                ? wc_format_decimal((float) $map, wc_get_price_decimals())
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

        // Fallback: if MSRP is unavailable, keep Woo structured offer untouched.
        if (self::is_email_for_quote_policy($product, null)) {
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

        self::render_quote_notice(self::quote_request_status());

        echo '<p class="fflhub-email-for-quote-wrap">';
        echo '<button type="button" class="button alt wp-element-button fflhub-email-for-quote-button" aria-label="' . esc_attr($label) . '" data-fflhub-quote-open="1">';
        echo esc_html($label);
        echo '</button>';
        echo '</p>';
    }

    public static function render_holosun_brand_notice_near_image(): void
    {
        if (self::$holosun_notice_rendered) {
            return;
        }

        if (!Options::get_holosun_image_notice_enabled()) {
            return;
        }

        $product = self::current_product_for_quote();
        if (!($product instanceof WC_Product)) {
            return;
        }

        if (!self::is_holosun_branded_product($product, null)) {
            return;
        }

        $message = (string) apply_filters(
            'fflhub_holosun_brand_notice_text',
            'Unfortunately, Holosun has placed us on the Do Not Sell List with no prior contact or warning. This is depsite the fact that we are in full compliance of all policies set forth by them down to the T. If you would like, you can file a compliant by contacting them at:',
            $product
        );
        $message = trim($message);
        if ($message === '') {
            return;
        }

        $contactEmail = (string) apply_filters('fflhub_holosun_brand_notice_email', 'info@holosun.com', $product);
        $contactPhone = (string) apply_filters('fflhub_holosun_brand_notice_phone', '909 594 2888', $product);
        $footerMessage = (string) apply_filters(
            'fflhub_holosun_brand_notice_footer',
            'If you choose to file a complaint, please be respectful and kind. Strong dealer-brand relationships matter just as much as customer relationships, and a professional tone helps everyone work toward a better outcome.',
            $product
        );
        $contactEmail = trim($contactEmail);
        $contactPhone = trim($contactPhone);
        $footerMessage = trim($footerMessage);

        // Intentionally shown regardless of stock status.
        self::$holosun_notice_rendered = true;
        echo '<div class="fflhub-holosun-image-notice">';
        echo '<p><strong>' . esc_html($message) . '</strong></p>';
        echo '<p class="fflhub-holosun-image-notice-contact"><strong>' . esc_html('Email : ' . $contactEmail) . '</strong></p>';
        echo '<p class="fflhub-holosun-image-notice-contact"><strong>' . esc_html('Phone: ' . $contactPhone) . '</strong></p>';
        if ($footerMessage !== '') {
            echo '<p class="fflhub-holosun-image-notice-contact"><strong>' . esc_html($footerMessage) . '</strong></p>';
        }
        echo '</div>';
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

        echo '<h2 id="fflhub-email-for-quote-title" class="fflhub-email-for-quote-modal__title">' . esc_html__('Request a Custom Price Quote', 'ffl-hub') . '</h2>';
        echo '<p>' . esc_html__('Thank you for your interest in a custom price quote!', 'ffl-hub') . '</p>';
        echo '<p>' . esc_html__('Please enter your name and email address and we will send you a promo code.', 'ffl-hub') . '</p>';
        echo '<p><strong>' . esc_html__('We do not store or sell customer email information. We sell firearms and accessories, not email lists.', 'ffl-hub') . '</strong></p>';
        echo '<p>' . esc_html__('This form will be sent to and reviewed by a store associate who will evaluate each request individually and then contact you concerning product info and pricing. Any discount or promo code you may receive is specific to your email address. It cannot be shared or used by anyone else. It will be a one time use only code for YOU only.', 'ffl-hub') . '</p>';
        echo '<p>' . esc_html__('Requests are only reviewed during business hours.', 'ffl-hub') . '</p>';
        echo '<p><strong>' . esc_html__('Business Hours:', 'ffl-hub') . '</strong> ' . esc_html__('7am-6pm CST every day', 'ffl-hub') . '</p>';
        $sales_phone = self::store_phone_for_quote();
        if ($sales_phone !== '') {
            echo '<p>' . sprintf(
                /* translators: %s = sales phone number */
                esc_html__('You may also contact our Sales team with any questions at %s option 1 during business hours. Thank you!', 'ffl-hub'),
                esc_html($sales_phone)
            ) . '</p>';
        }

        echo '<form class="fflhub-email-for-quote-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::EMAIL_FOR_QUOTE_FORM_ACTION) . '">';
        echo '<input type="hidden" name="fflhub_product_id" value="' . esc_attr((string) $product->get_id()) . '">';
        echo '<input type="hidden" name="fflhub_redirect_url" value="' . esc_url($redirect_url) . '">';
        wp_nonce_field('fflhub_email_for_quote_submit_' . $product->get_id(), 'fflhub_email_for_quote_nonce');
        echo '<p><strong>' . esc_html__('PLEASE DO NOT SHARE EMAIL QUOTE PRICES. THEY ARE PRIVATE. We want to remain MAP compliant.', 'ffl-hub') . '</strong></p>';

        echo '<label for="fflhub-quote-first-name">' . esc_html__('First Name', 'ffl-hub') . '</label>';
        echo '<input id="fflhub-quote-first-name" name="fflhub_first_name" type="text" required maxlength="100">';

        echo '<label for="fflhub-quote-last-name">' . esc_html__('Last Name', 'ffl-hub') . '</label>';
        echo '<input id="fflhub-quote-last-name" name="fflhub_last_name" type="text" required maxlength="100">';

        echo '<label for="fflhub-quote-email">' . esc_html__('Email Address', 'ffl-hub') . '</label>';
        echo '<input id="fflhub-quote-email" name="fflhub_email" type="email" required maxlength="190">';

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

        if ($first_name === '' || $last_name === '' || $email === '') {
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
            sprintf(__('Last Name: %s', 'ffl-hub'), $last_name),
            sprintf(__('Email: %s', 'ffl-hub'), $email),
            sprintf(__('Product: %s', 'ffl-hub'), $product->get_name()),
            sprintf(__('SKU: %s', 'ffl-hub'), (string) $product->get_sku()),
            sprintf(__('Product URL: %s', 'ffl-hub'), $product_url),
            '',
            __('Business Hours stated to customer: 7am-6pm CST every day.', 'ffl-hub'),
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
        $saved_job = self::insert_quote_email_job($product, $first_name, $last_name, $email);
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
        return self::map_policy_for_product($product, $parent) === Options::MAP_POLICY_EMAIL_FOR_QUOTE;
    }

    private static function is_no_email_no_add_to_cart_policy(WC_Product $product, ?WC_Product $parent = null): bool
    {
        return self::map_policy_for_product($product, $parent) === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART;
    }

    private static function is_holosun_branded_product(WC_Product $product, ?WC_Product $parent = null): bool
    {
        $aliases = (array) apply_filters(
            'fflhub_holosun_brand_aliases',
            [self::HOLOSUN_BRAND_DEFAULT_ALIAS],
            $product,
            $parent
        );

        $normalized_aliases = [];
        foreach ($aliases as $alias) {
            $value = strtolower(trim((string) $alias));
            if ($value === '') {
                continue;
            }
            $normalized_aliases[$value] = true;
        }

        if (empty($normalized_aliases)) {
            $normalized_aliases[self::HOLOSUN_BRAND_DEFAULT_ALIAS] = true;
        }

        $brand_names = self::brand_names_for_product($product);
        if (empty($brand_names) && $parent instanceof WC_Product) {
            $brand_names = self::brand_names_for_product($parent);
        }

        foreach ($brand_names as $brand_name) {
            $normalized_brand = strtolower(trim((string) $brand_name));
            if ($normalized_brand === '') {
                continue;
            }

            foreach ($normalized_aliases as $alias => $_true) {
                if ($alias !== '' && strpos($normalized_brand, $alias) !== false) {
                    return true;
                }
            }
        }

        // Last-resort fallback: match common storefront product names.
        $names_to_check = [strtolower(trim((string) $product->get_name()))];
        if ($parent instanceof WC_Product) {
            $names_to_check[] = strtolower(trim((string) $parent->get_name()));
        }
        foreach ($names_to_check as $candidate_name) {
            if ($candidate_name === '') {
                continue;
            }
            foreach ($normalized_aliases as $alias => $_true) {
                if ($alias !== '' && strpos($candidate_name, $alias) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function should_force_map_price(WC_Product $product, ?WC_Product $parent = null): bool
    {
        if (self::in_cart_flow()) {
            return false;
        }

        if (!self::is_no_email_no_add_to_cart_policy($product, $parent)) {
            return false;
        }

        if (!self::is_fflhub_managed($product, $parent)) {
            return false;
        }

        return self::map_price_for_product($product, $parent) !== null;
    }

    private static function should_force_holosun_msrp_price(WC_Product $product, ?WC_Product $parent = null): bool
    {
        if (!Options::get_holosun_image_notice_enabled()) {
            return false;
        }

        if (!self::is_holosun_branded_product($product, $parent)) {
            return false;
        }

        return self::msrp_price_for_product($product, $parent) !== null;
    }

    private static function should_force_email_quote_msrp_price(WC_Product $product, ?WC_Product $parent = null): bool
    {
        if (!self::is_email_for_quote_policy($product, $parent)) {
            return false;
        }

        return self::msrp_price_for_product($product, $parent) !== null;
    }

    private static function map_price_for_product(WC_Product $product, ?WC_Product $parent = null): ?float
    {
        $map = (float) $product->get_meta(ProductMeta::FFLHUB_LAST_MAP_META, true);
        if ($map <= 0.0 && $parent instanceof WC_Product) {
            $map = (float) $parent->get_meta(ProductMeta::FFLHUB_LAST_MAP_META, true);
        }

        if ($map <= 0.0) {
            return null;
        }

        return $map;
    }

    private static function msrp_price_for_product(WC_Product $product, ?WC_Product $parent = null): ?float
    {
        $msrp = (float) $product->get_meta(ProductMeta::FFLHUB_LAST_MSRP_META, true);
        if ($msrp <= 0.0 && $parent instanceof WC_Product) {
            $msrp = (float) $parent->get_meta(ProductMeta::FFLHUB_LAST_MSRP_META, true);
        }

        if ($msrp <= 0.0) {
            return null;
        }

        return $msrp;
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

    private static function msrp_price_html(WC_Product $product, ?WC_Product $parent = null): ?string
    {
        $msrp = self::msrp_price_for_product($product, $parent);
        if (!is_numeric($msrp) || (float) $msrp <= 0.0) {
            return null;
        }

        $display_price = (float) $msrp;
        if (function_exists('wc_get_price_to_display')) {
            $display_price = (float) wc_get_price_to_display($product, ['price' => (float) $msrp]);
        }

        if (function_exists('wc_price')) {
            return (string) wc_price($display_price);
        }

        return (string) $display_price;
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
            $message = __('Thanks, your quote request was submitted. A store associate will review it during business hours.', 'ffl-hub');
        } else {
            $class .= ' is-error';
            if ($status === 'missing_fields') {
                $message = __('Please complete First Name, Last Name, and Email Address.', 'ffl-hub');
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

    private static function insert_quote_email_job(
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
        $submitted_at = (string) current_time('mysql', true);
        $random_delay_minutes = self::preferred_quote_delay_minutes();

        if (self::has_recent_duplicate_quote_job_values($table_name, $first_name, $last_name, $email, $upc, $product_name)) {
            return true;
        }

        $inserted = $wpdb->insert(
            $table_name,
            [
                'request_first_name'   => self::truncate_quote_job_value($first_name, 100),
                'request_last_name'    => self::truncate_quote_job_value($last_name, 100),
                'request_email'        => self::truncate_quote_job_value($email, 190),
                'quote_upc'            => self::truncate_quote_job_value($upc, 64),
                'quote_product_name'   => $product_name,
                'submitted_at'         => $submitted_at,
                'random_delay_minutes' => $random_delay_minutes,
                // This flag tracks the delayed customer-facing quote email, not
                // the immediate internal/store notification sent on form submit.
                'email_sent'           => 0,
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
            ]
        );

        return $inserted === 1;
    }

    /**
     * Generate a 5-15 minute delay biased toward values closer to 5.
     */
    private static function preferred_quote_delay_minutes(): int
    {
        $min = 5;
        $max = 15;
        $choices = ($max - $min) + 1; // inclusive count

        // Uniform [0,1), squared to bias toward 0 (lower delays).
        $u = (float) wp_rand(0, 999999) / 1000000.0;
        $biased = $u * $u;
        $offset = (int) floor($biased * $choices);
        if ($offset < 0) {
            $offset = 0;
        }
        if ($offset >= $choices) {
            $offset = $choices - 1;
        }

        return $min + $offset;
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
        $meta_keys = [
            ProductMeta::FFLHUB_UPC_META,
            '_upc',
            'upc',
        ];

        foreach ($meta_keys as $meta_key) {
            $candidate = trim((string) $product->get_meta($meta_key, true));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
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
        $policy_lookup = self::map_policy_lookup();
        if (empty($policy_lookup)) {
            return Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
        }

        $brand_names = self::brand_names_for_product($product);
        if (empty($brand_names) && $parent instanceof WC_Product) {
            $brand_names = self::brand_names_for_product($parent);
        }

        foreach ($brand_names as $brand_name) {
            $policy = Options::get_map_policy_for_brand($brand_name);
            $policy = strtolower(trim((string) $policy));
            if ($policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE) {
                return Options::MAP_POLICY_EMAIL_FOR_QUOTE;
            }

            if ($policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) {
                return Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART;
            }
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

        // Fallback: support stores using non-standard brand taxonomies.
        if (empty($names)) {
            $taxonomies = get_object_taxonomies('product', 'names');
            if (is_array($taxonomies)) {
                foreach ($taxonomies as $taxonomy) {
                    $taxonomy = (string) $taxonomy;
                    if ($taxonomy === '') {
                        continue;
                    }
                    if (in_array($taxonomy, self::BRAND_TAXONOMY_CANDIDATES, true)) {
                        continue;
                    }
                    if (stripos($taxonomy, 'brand') === false) {
                        continue;
                    }
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
            }
        }

        self::$brand_names_by_product_id[$product_id] = array_values($names);
        return self::$brand_names_by_product_id[$product_id];
    }

}
