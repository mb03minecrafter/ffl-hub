<?php

namespace FFLHub\Shipping\Wordpress;

use FFLHub\Shipping\Methods\FFLHubShippingMethod;

if (!defined('ABSPATH')) exit;

class ShippingRegistrar
{
    public static function init(): void
    {
        // No require_once needed when using autoloading.
        add_action('woocommerce_shipping_init', function () {
            // Touch the class to ensure autoload triggers, if desired:
            if (!class_exists(FFLHubShippingMethod::class)) {
                // If this ever happens, your autoload mapping is broken.
            }
        });

        add_filter('woocommerce_shipping_methods', function ($methods) {
            $methods['fflhub_shipping'] = FFLHubShippingMethod::class;
            return $methods;
        });
    }
}
