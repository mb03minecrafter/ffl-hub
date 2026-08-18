<?php

namespace FFLHub\Shipping\Wordpress;

use FFLHub\Product\State\ProductStateStore;
use FFLHub\Settings\Options;
use FFLHub\Shipping\Methods\FFLHubShippingMethod;
use FFLHub\Shipping\PhoenixProductShippingMeta;

if (!defined('ABSPATH')) exit;

class ShippingRegistrar
{
    private const SHIPPING_MODEL_VERSION = '5';

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
     * - fflhub: products managed by legacy Product State or Phoenix product meta
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
                    if (self::is_fflhub_product($product)) {
                        $fflhub_contents[$item_key] = $item;
                        continue;
                    }
                }

                $external_contents[$item_key] = $item;
            }

            // Not mixed: keep package intact and tag it for downstream rate gating.
            if (empty($fflhub_contents) || empty($external_contents)) {
                $package['fflhub_package_type'] = empty($fflhub_contents) ? 'external' : 'fflhub';
                $split_packages[] = self::attach_coupon_shipping_context($package);
                continue;
            }

            $fflhub_package = $package;
            $fflhub_package['contents'] = $fflhub_contents;
            $fflhub_package['contents_cost'] = self::compute_contents_cost($fflhub_contents);
            $fflhub_package['fflhub_package_type'] = 'fflhub';
            $split_packages[] = self::attach_coupon_shipping_context($fflhub_package);

            $external_package = $package;
            $external_package['contents'] = $external_contents;
            $external_package['contents_cost'] = self::compute_contents_cost($external_contents);
            $external_package['fflhub_package_type'] = 'external';
            $split_packages[] = self::attach_coupon_shipping_context($external_package);
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

        if (($package['phoenix_package_type'] ?? '') === 'phoenix') {
            return $rates;
        }

        $package_type = isset($package['fflhub_package_type']) ? (string) $package['fflhub_package_type'] : '';
        if ($package_type === '') {
            $package_type = self::detect_package_type($package);
        }
        if ($package_type === 'fflhub') {
            foreach ($rates as $rate_id => $rate) {
                $method_id = self::get_rate_method_id($rate);
                if ($method_id !== 'fflhub_shipping') {
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
     * Woo caches shipping rates by package hash. Include applied free-shipping
     * coupon state so the FFLHub fulfillment rate recalculates when a qualifying
     * coupon is applied or removed.
     *
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    private static function attach_coupon_shipping_context(array $package): array
    {
        $package['fflhub_free_shipping_coupon_codes'] = implode(',', self::applied_free_shipping_coupon_codes());
        $package['fflhub_use_product_state_usps_shipping'] = Options::get_use_product_state_usps_shipping() ? '1' : '0';
        $package['fflhub_shipping_model_version'] = self::SHIPPING_MODEL_VERSION;
        return $package;
    }

    /**
     * @return array<int, string>
     */
    private static function applied_free_shipping_coupon_codes(): array
    {
        if (!function_exists('WC') || !WC() || !WC()->cart) {
            return [];
        }

        $cart = WC()->cart;
        $coupons = method_exists($cart, 'get_coupons') ? (array) $cart->get_coupons() : [];

        if (empty($coupons) && method_exists($cart, 'get_applied_coupons')) {
            foreach ((array) $cart->get_applied_coupons() as $code) {
                $code = trim((string) $code);
                if ($code !== '' && class_exists('\WC_Coupon')) {
                    $coupons[$code] = new \WC_Coupon($code);
                }
            }
        }

        $codes = [];
        foreach ($coupons as $code => $coupon) {
            if (!($coupon instanceof \WC_Coupon) || !$coupon->get_free_shipping()) {
                continue;
            }

            $coupon_code = trim((string) $coupon->get_code());
            if ($coupon_code === '') {
                $coupon_code = trim((string) $code);
            }
            if ($coupon_code !== '') {
                $codes[] = $coupon_code;
            }
        }

        return array_values(array_unique($codes));
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

            if (self::is_fflhub_product($product)) {
                return 'fflhub';
            }
        }

        return 'external';
    }

    private static function is_fflhub_product(\WC_Product $product): bool
    {
        if (PhoenixProductShippingMeta::is_managed_product($product)) {
            return true;
        }

        return ProductStateStore::get_primary_distributor_for_product($product) !== '';
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
