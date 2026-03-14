<?php

namespace FFLHub\Shipping\Wordpress;

use FFLHub\Product\ProductMeta;
use FFLHub\Shipping\Methods\FFLHubShippingMethod;
use FFLHub\Util\DebugLogUtil;

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
        self::log_ctx('split.start', [
            'incoming_packages' => count($packages),
        ]);

        $split_packages = [];

        foreach ($packages as $idx => $package) {
            if (!is_array($package) || empty($package['contents']) || !is_array($package['contents'])) {
                self::log_ctx('split.pass_through_invalid', [
                    'index' => (int) $idx,
                ]);
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
                self::log_ctx('split.single_type', [
                    'index' => (int) $idx,
                    'package_type' => (string) $package['fflhub_package_type'],
                    'items' => count($package['contents']),
                    'fflhub_items' => count($fflhub_contents),
                    'external_items' => count($external_contents),
                ]);
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

            self::log_ctx('split.mixed', [
                'index' => (int) $idx,
                'fflhub_items' => count($fflhub_contents),
                'external_items' => count($external_contents),
            ]);
        }

        self::log_ctx('split.end', [
            'outgoing_packages' => count($split_packages),
        ]);

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
        $incoming_method_ids = [];
        foreach ($rates as $rate) {
            $incoming_method_ids[] = self::get_rate_method_id($rate);
        }

        if (empty($rates)) {
            self::log_ctx('rates.empty', [
                'package_type' => (string) ($package['fflhub_package_type'] ?? ''),
            ]);
            return $rates;
        }

        $package_type = isset($package['fflhub_package_type']) ? (string) $package['fflhub_package_type'] : '';
        if ($package_type === '') {
            $package_type = self::detect_package_type($package);
        }

        self::log_ctx('rates.before', [
            'package_type' => $package_type,
            'incoming_methods' => $incoming_method_ids,
            'item_count' => is_array($package['contents'] ?? null) ? count($package['contents']) : 0,
        ]);

        if ($package_type === 'fflhub') {
            foreach ($rates as $rate_id => $rate) {
                $method_id = self::get_rate_method_id($rate);
                if ($method_id !== 'fflhub_shipping') {
                    unset($rates[$rate_id]);
                }
            }

            self::log_ctx('rates.after_fflhub', [
                'package_type' => $package_type,
                'outgoing_methods' => self::method_ids_from_rates($rates),
            ]);

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

        self::log_ctx('rates.after_external_or_unknown', [
            'package_type' => $package_type,
            'outgoing_methods' => self::method_ids_from_rates($rates),
        ]);

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

    /**
     * @param array<string,\WC_Shipping_Rate> $rates
     * @return array<int,string>
     */
    private static function method_ids_from_rates(array $rates): array
    {
        $ids = [];
        foreach ($rates as $rate) {
            $ids[] = self::get_rate_method_id($rate);
        }
        return array_values(array_filter($ids, static function ($v): bool {
            return is_string($v) && $v !== '';
        }));
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private static function log_ctx(string $msg, array $ctx): void
    {
        DebugLogUtil::log_ctx('FFLHUB_DEBUG_SHIPPING', '[FFLHub][ShippingRegistrar]', $msg, $ctx);
    }
}
