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
}
