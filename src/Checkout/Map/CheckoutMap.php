<?php

namespace FFLHub\Checkout\Map;

use FFLHub\Product\State\ProductStateStore;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handles showing the FFL picker on the Checkout page when an FFL item is in the cart.
 *
 * - Injects an FFL picker UI after the "Additional information" block (Checkout block).
 * - Only runs on the front-end checkout page.
 * - Only shows if the cart contains at least one FFL-required product.
 * - Uses the public REST API /fflhub/v1/ffls to fetch FFLs by ZIP.
 */
class CheckoutMap
{
    /**
     * Initialize hooks.
     */
    public static function init(): void
    {
        // Front-end styles & JS (only when needed).
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));

        // Inject HTML after the Additional information block on Checkout.
        add_filter('render_block', array(__CLASS__, 'inject_picker_after_additional_information'), 10, 2);
    }

    /**
     * Enqueue CSS + JS on the checkout page when at least one FFL item is in the cart.
     */
    public static function enqueue_assets(): void
    {
        if (! function_exists('is_checkout') || ! is_checkout() || is_order_received_page()) {
            return;
        }

        // No FFL items in cart? No need to add assets.
        if (! self::cart_contains_ffl_required_item()) {
            return;
        }

        // CSS for layout / styling.
        $css_rel_path = 'assets/css/fflhub-checkout-map.css';
        $css_abs_path = plugin_dir_path(FFLHUB_PLUGIN_FILE) . $css_rel_path;

        if (file_exists($css_abs_path)) {
            wp_enqueue_style(
                'fflhub-checkout-map',
                plugins_url($css_rel_path, FFLHUB_PLUGIN_FILE),
                array(),
                filemtime($css_abs_path)
            );
        }

        // JS for interactive picker.
        $js_rel_path = 'assets/js/fflhub-checkout-ffl-picker.js';
        $js_abs_path = plugin_dir_path(FFLHUB_PLUGIN_FILE) . $js_rel_path;

        if (file_exists($js_abs_path)) {
            wp_enqueue_script(
                'fflhub-checkout-ffl-picker',
                plugins_url($js_rel_path, FFLHUB_PLUGIN_FILE),
                array(),
                filemtime($js_abs_path),
                true
            );

            // Expose REST settings to JS, using your existing API:
            // GET /wp-json/fflhub/v1/ffls?zip=XXXXX&limit=50
            wp_localize_script(
                'fflhub-checkout-ffl-picker',
                'fflhubFFLPickerSettings',
                array(
                    'restUrl'      => esc_url_raw(rest_url('fflhub/v1/ffls')),
                    'defaultLimit' => 50,
                )
            );
        }
    }

    /**
     * Append the FFL picker HTML after the "Additional information" Checkout block
     * when the cart contains at least one FFL-required product.
     *
     * @param string $block_content HTML content for the current block.
     * @param array  $block         Block data (name, attrs, etc.).
     * @return string
     */
    public static function inject_picker_after_additional_information(string $block_content, array $block): string
    {
        // Only front-end (not admin/editor).
        if (is_admin()) {
            return $block_content;
        }

        if (! function_exists('is_checkout') || ! is_checkout() || is_order_received_page()) {
            return $block_content;
        }

        // Only target the Additional information block.
        if (empty($block['blockName']) || $block['blockName'] !== 'woocommerce/checkout-additional-information-block') {
            return $block_content;
        }

        // If cart doesn't contain any FFL-required items, bail.
        if (! self::cart_contains_ffl_required_item()) {
            return $block_content;
        }

        // Build the picker markup.
        ob_start();
        ?>
        <div class="fflhub-checkout-map-wrapper">
            <h3><?php esc_html_e('Select Receiving FFL', 'ffl-hub'); ?></h3>
            <p>
                <?php esc_html_e(
                    'Firearms must ship to a licensed dealer. Enter your ZIP Code, choose a nearby FFL from the list, and we will ship to that location.',
                    'ffl-hub'
                ); ?>
            </p>

            <div class="fflhub-ffl-picker">
                <div class="fflhub-ffl-picker__zip-row">
                    <label for="fflhub-ffl-picker-zip">
                        <?php esc_html_e('ZIP Code:', 'ffl-hub'); ?>
                    </label>
                    <input
                        type="text"
                        id="fflhub-ffl-picker-zip"
                        class="fflhub-ffl-picker__zip-input"
                        placeholder="<?php esc_attr_e('Enter ZIP (e.g. 70801)', 'ffl-hub'); ?>" />
                    <button
                        type="button"
                        class="fflhub-ffl-picker__search-button">
                        <?php esc_html_e('Search', 'ffl-hub'); ?>
                    </button>
                </div>

                <div class="fflhub-ffl-picker__content">
                    <div class="fflhub-ffl-picker__list">
                        <p class="fflhub-ffl-picker__placeholder">
                            <?php esc_html_e(
                                'Enter your ZIP and click Search to see nearby FFLs.',
                                'ffl-hub'
                            ); ?>
                        </p>
                        <ul class="fflhub-ffl-picker__list-inner"></ul>
                    </div>

                    <div class="fflhub-ffl-picker__map">
                        <iframe
                            title="<?php esc_attr_e('FFL map', 'ffl-hub'); ?>"
                            class="fflhub-ffl-picker__map-iframe"
                            src="https://www.google.com/maps?q=United+States&z=3&output=embed"
                            loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                </div>

                <!-- NOTE: Please contact your receiving FFL and confirm they accept transfers! -->
                <input
                    type="hidden"
                    id="fflhub-ffl-picker-selected"
                    value="" />
            </div>
        </div>
        <?php
        $picker_html = ob_get_clean();

        return $block_content . $picker_html;
    }

    /**
     * Check whether the current cart contains at least one FFL-required item.
     *
     * @return bool
     */
    private static function cart_contains_ffl_required_item(): bool
    {
        static $cached_result = null;

        if (null !== $cached_result) {
            return $cached_result;
        }

        $cached_result = false;

        if (! function_exists('WC') || ! WC()->cart) {
            return $cached_result;
        }

        $cart = WC()->cart->get_cart();
        if (empty($cart)) {
            return $cached_result;
        }

        foreach ($cart as $cart_item) {
            $product = self::product_from_cart_item(is_array($cart_item) ? $cart_item : array());
            if (! $product instanceof \WC_Product) {
                continue;
            }

            if (ProductStateStore::get_ffl_required_for_product($product)) {
                $cached_result = true;
                break;
            }
        }

        return $cached_result;
    }

    /**
     * @param array<string,mixed> $cart_item
     */
    private static function product_from_cart_item(array $cart_item): ?\WC_Product
    {
        $product = $cart_item['data'] ?? null;
        if ($product instanceof \WC_Product) {
            return $product;
        }

        $variation_id = ! empty($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0;
        $product_id = ! empty($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
        $lookup_id = $variation_id > 0 ? $variation_id : $product_id;
        if ($lookup_id <= 0) {
            return null;
        }

        $product = wc_get_product($lookup_id);
        return ($product instanceof \WC_Product) ? $product : null;
    }
}
