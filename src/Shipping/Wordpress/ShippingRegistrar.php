<?php

namespace FFLHub\Shipping\Wordpress;

use FFLHub\Product\ProductMeta;
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

        add_filter('woocommerce_cart_shipping_packages', [self::class, 'split_cart_shipping_packages'], 20);
        add_filter('woocommerce_package_rates', [self::class, 'filter_package_rates'], 20, 2);
    }

    /**
     * Split mixed carts into two shipping packages:
     * - fflhub: products managed by FFLHub (have primary distributor meta)
     * - external: everything else (e.g. Printify)
     *
     * This allows shipping to be additive across packages.
     *
     * @param array<int, array<string, mixed>> $packages
     * @return array<int, array<string, mixed>>
     */
    public static function split_cart_shipping_packages(array $packages): array
    {
        $split_packages = [];

        foreach ($packages as $package) {
            if (!is_array($package) || empty($package['contents']) || !is_array($package['contents'])) {
                $split_packages[] = $package;
                continue;
            }

            $fflhub_contents = [];
            $external_contents = [];

            foreach ($package['contents'] as $item_key => $item) {
                $product = $item['data'] ?? null;

                if ($product instanceof \WC_Product) {
                    $dist_id = (string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true);
                    if ($dist_id !== '') {
                        $fflhub_contents[$item_key] = $item;
                        continue;
                    }
                }

                $external_contents[$item_key] = $item;
            }

            // Not mixed: keep package intact and tag it for downstream rate gating.
            if (empty($fflhub_contents) || empty($external_contents)) {
                $package['fflhub_package_type'] = empty($fflhub_contents) ? 'external' : 'fflhub';
                $split_packages[] = $package;
                continue;
            }

            $fflhub_package = $package;
            $fflhub_package['contents'] = $fflhub_contents;
            $fflhub_package['contents_cost'] = self::compute_contents_cost($fflhub_contents);
            $fflhub_package['fflhub_package_type'] = 'fflhub';
            $split_packages[] = $fflhub_package;

            $external_package = $package;
            $external_package['contents'] = $external_contents;
            $external_package['contents_cost'] = self::compute_contents_cost($external_contents);
            $external_package['fflhub_package_type'] = 'external';
            $split_packages[] = $external_package;
        }

        return $split_packages;
    }

    /**
     * Keep method visibility aligned with package type.
     *
     * - fflhub package: only allow FFL Hub shipping method
     * - external package: disallow FFL Hub shipping method
     *
     * @param array<string, \WC_Shipping_Rate> $rates
     * @param array<string, mixed> $package
     * @return array<string, \WC_Shipping_Rate>
     */
    public static function filter_package_rates(array $rates, array $package): array
    {
        if (empty($rates)) {
            return $rates;
        }

        $package_type = isset($package['fflhub_package_type']) ? (string) $package['fflhub_package_type'] : '';
        if ($package_type === '') {
            $package_type = self::detect_package_type($package);
        }
        $allow_coupon_free_shipping = self::has_active_free_shipping_coupon($package);

        if ($package_type === 'fflhub') {
            foreach ($rates as $rate_id => $rate) {
                $method_id = self::get_rate_method_id($rate);
                $allow_free_shipping_rate = $allow_coupon_free_shipping && $method_id === 'free_shipping';
                if ($method_id !== 'fflhub_shipping' && !$allow_free_shipping_rate) {
                    unset($rates[$rate_id]);
                }
            }

            return $rates;
        }

        if ($package_type === 'external') {
            foreach ($rates as $rate_id => $rate) {
                $method_id = self::get_rate_method_id($rate);
                if ($method_id === 'fflhub_shipping') {
                    unset($rates[$rate_id]);
                }
            }
        }

        return $rates;
    }

    /**
     * @param array<int|string, mixed> $contents
     */
    private static function compute_contents_cost(array $contents): float
    {
        $total = 0.0;

        foreach ($contents as $item) {
            $total += isset($item['line_total']) ? (float) $item['line_total'] : 0.0;
        }

        return max(0.0, $total);
    }

    /**
     * Return true when an applied coupon grants free shipping.
     *
     * @param array<string, mixed> $package
     */
    private static function has_active_free_shipping_coupon(array $package): bool
    {
        if (function_exists('WC') && WC() && WC()->cart && method_exists(WC()->cart, 'get_coupons')) {
            $coupons = WC()->cart->get_coupons();
            if (is_array($coupons)) {
                foreach ($coupons as $coupon) {
                    if ($coupon instanceof \WC_Coupon && $coupon->get_free_shipping()) {
                        return true;
                    }
                }
            }
        }

        $coupon_codes = [];
        if (isset($package['applied_coupons']) && is_array($package['applied_coupons'])) {
            foreach ($package['applied_coupons'] as $code) {
                $code = trim((string) $code);
                if ($code !== '') {
                    $coupon_codes[] = $code;
                }
            }
        }

        foreach (array_values(array_unique($coupon_codes)) as $code) {
            $coupon = new \WC_Coupon($code);
            if ($coupon instanceof \WC_Coupon && $coupon->get_id() > 0 && $coupon->get_free_shipping()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Best-effort fallback if a package lost its split tag.
     *
     * @param array<string, mixed> $package
     */
    private static function detect_package_type(array $package): string
    {
        $contents = $package['contents'] ?? null;
        if (!is_array($contents) || empty($contents)) {
            return '';
        }

        foreach ($contents as $item) {
            $product = $item['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $dist_id = (string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true);
            if ($dist_id !== '') {
                return 'fflhub';
            }
        }

        return 'external';
    }

    /**
     * @param mixed $rate
     */
    private static function get_rate_method_id($rate): string
    {
        if (!is_object($rate)) {
            return '';
        }

        if (method_exists($rate, 'get_method_id')) {
            return (string) $rate->get_method_id();
        }

        if (isset($rate->method_id)) {
            return (string) $rate->method_id;
        }

        return '';
    }
}
