<?php

namespace FFLHub\Checkout;

if ( ! \defined('ABSPATH') ) {
    exit;
}

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use FFLHub\Product\ProductMeta;
/**
 * Adds FFL-related data to the WooCommerce Store API cart endpoint.
 *
 * Exposes:
 *   cart.extensions["ffl-hub"].requires_ffl : boolean
 *
 * This follows the official "Extending the Store API" docs:
 * - https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/
 * - https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/extend-store-api-add-data/
 */
class FFLRequiredCartExtension
{
    /**
     * Namespace used under the "extensions" key in Store API responses.
     *
     * Example:
     *   cart.extensions["ffl-hub"].requires_ffl
     */
    private const EXTENSION_NAMESPACE = 'ffl-hub';

    /**
     * Bootstrap the Store API integration.
     *
     * Called from FFLHub\Plugin::register_services().
     */
    public static function init(): void
    {
        // Use the recommended hook for Store API / Blocks integration.
        add_action('woocommerce_blocks_loaded', array(__CLASS__, 'register_cart_extension'));
    }

    /**
     * Register our extra cart data using ExtendSchema via the helper function.
     *
     * This extends the `wc/store/cart` endpoint identified by CartSchema::IDENTIFIER.
     */
    public static function register_cart_extension(): void
    {
        // Bail out gracefully if Store API helpers are not available (older Woo/Blocks).
        if (! function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data(
            array(
                // The endpoint we are extending (cart).
                'endpoint'        => CartSchema::IDENTIFIER,

                // Our namespace under cart.extensions.
                'namespace'       => self::EXTENSION_NAMESPACE,

                // Callback that returns the actual data.
                'data_callback'   => array(__CLASS__, 'extend_cart_data'),

                // Callback that returns the JSON schema for our data.
                'schema_callback' => array(__CLASS__, 'extend_cart_schema'),

                // We're returning an associative array.
                'schema_type'     => ARRAY_A,
            )
        );
    }

    /**
     * Data callback for the cart endpoint.
     *
     * Called with no arguments for CartSchema::IDENTIFIER,
     * as described in the docs.
     *
     * @return array<string,mixed>
     */
    public static function extend_cart_data(): array
    {
        $requires_ffl = false;

        if (function_exists('WC') && WC()->cart) {
            $cart_items = WC()->cart->get_cart();

            foreach ($cart_items as $cart_item) {
                $product_id   = ! empty($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
                $variation_id = ! empty($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0;

                // Check variation first (most specific), then parent product.
                foreach (array($variation_id, $product_id) as $id) {
                    if (! $id) {
                        continue;
                    }

                    $product = wc_get_product($id);
                    if (! $product) {
                        continue;
                    }

                    // Note: global class name is explicitly referenced to avoid namespace issues.
                    $ffl_required = $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true);

                    // Handle common stored forms: 1, "1", true, "yes"
                    if ((string) $ffl_required === '1' || wc_string_to_bool((string) $ffl_required)) {
                        $requires_ffl = true;
                        break 2;
                    }
                }
            }
        }

        // This becomes:
        //   cart.extensions["ffl-hub"].requires_ffl
        return array(
            'requires_ffl' => $requires_ffl,
        );
    }

    /**
     * Schema callback for the cart endpoint.
     *
     * Follows the documented pattern of returning a "properties" array.
     *
     * @return array<string,mixed>
     */
    public static function extend_cart_schema(): array
    {
        return array(
            'properties' => array(
                'requires_ffl' => array(
                    'description' => __(
                        'Whether the current cart contains any items that require shipment to an FFL.',
                        'ffl-hub'
                    ),
                    'type'     => 'boolean',
                    'context'  => array('view'),
                    'readonly' => true,
                ),
            ),
        );
    }
}
