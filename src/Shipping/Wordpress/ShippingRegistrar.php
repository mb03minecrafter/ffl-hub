<?php

namespace FFLHub\Shipping\Wordpress;

use FFLHub\Shipping\Methods\FFLHubShippingMethod;

if (!defined('ABSPATH')) exit;

class ShippingRegistrar
{
    public static function init(): void
    {
        add_action('woocommerce_shipping_init', function () {
            require_once __DIR__ . '/FFLHubShippingMethod.php';
        });

        add_filter('woocommerce_shipping_methods', function ($methods) {
            $methods['fflhub_shipping'] = FFLHubShippingMethod::class;
            return $methods;
        });
    }
}
