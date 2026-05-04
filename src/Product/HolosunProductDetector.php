<?php

namespace FFLHub\Product;

use FFLHub\Distributor\Services\RSR\Tables\RSRProductTableSchema;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

final class HolosunProductDetector
{
    private const BRAND_TAXONOMY_CANDIDATES = ['product_brand', 'pa_brand'];

    /** @var array<string,bool> */
    private static array $upc_cache = [];

    /** @var array<int,bool> */
    private static array $product_cache = [];

    public static function is_holosun_upc(string $upc): bool
    {
        $upc = trim((string) $upc);
        if ($upc === '') {
            return false;
        }

        if (isset(self::$upc_cache[$upc])) {
            return self::$upc_cache[$upc];
        }

        $product_id = self::find_product_id_by_upc($upc);
        self::$upc_cache[$upc] = ($product_id > 0 && self::is_holosun_product_id($product_id))
            || self::rsr_catalog_looks_holosun_for_upc($upc);

        return self::$upc_cache[$upc];
    }

    public static function is_holosun_product_id(int $product_id): bool
    {
        if ($product_id <= 0) {
            return false;
        }

        if (isset(self::$product_cache[$product_id])) {
            return self::$product_cache[$product_id];
        }

        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        self::$product_cache[$product_id] = ($product instanceof WC_Product)
            && self::is_holosun_product($product);

        return self::$product_cache[$product_id];
    }

    public static function is_holosun_product(WC_Product $product): bool
    {
        foreach (self::brand_names_for_product($product) as $brand_name) {
            if (self::is_holosun_brand_name($brand_name)) {
                return true;
            }
        }

        $name = method_exists($product, 'get_name') ? (string) $product->get_name() : '';
        if (self::is_holosun_brand_name($name)) {
            return true;
        }

        $sku = method_exists($product, 'get_sku') ? (string) $product->get_sku() : '';
        return self::is_holosun_brand_name($sku);
    }

    /**
     * @return string[]
     */
    private static function brand_names_for_product(WC_Product $product): array
    {
        $product_id = (int) $product->get_id();
        if ($product_id <= 0) {
            return [];
        }

        $names = [];

        foreach (self::brand_taxonomies() as $taxonomy) {
            $terms = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'names']);
            if (is_wp_error($terms) || !is_array($terms)) {
                continue;
            }

            foreach ($terms as $term_name) {
                $name = trim((string) $term_name);
                if ($name !== '') {
                    $names[$name] = $name;
                }
            }
        }

        return array_values($names);
    }

    /**
     * @return string[]
     */
    private static function brand_taxonomies(): array
    {
        $taxonomies = [];

        foreach (self::BRAND_TAXONOMY_CANDIDATES as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $taxonomies[$taxonomy] = $taxonomy;
            }
        }

        $all = get_object_taxonomies('product', 'names');
        if (is_array($all)) {
            foreach ($all as $taxonomy) {
                $taxonomy = (string) $taxonomy;
                if ($taxonomy === '' || isset($taxonomies[$taxonomy])) {
                    continue;
                }
                if (stripos($taxonomy, 'brand') === false || !taxonomy_exists($taxonomy)) {
                    continue;
                }
                $taxonomies[$taxonomy] = $taxonomy;
            }
        }

        return array_values($taxonomies);
    }

    private static function is_holosun_brand_name(string $brand_name): bool
    {
        $key = strtolower(trim($brand_name));
        if ($key === '') {
            return false;
        }

        if (stripos($key, 'holosun') !== false || stripos($key, 'holoson') !== false) {
            return true;
        }

        $key = (string) preg_replace('/[^a-z0-9]+/', '', $key);
        if (in_array($key, ['holosun', 'holosuntechnologies', 'holosontechnologies', 'hsun'], true)) {
            return true;
        }

        return strpos($key, 'hsun') === 0;
    }

    private static function find_product_id_by_upc(string $upc): int
    {
        global $wpdb;

        $upc = trim($upc);
        if ($upc === '') {
            return 0;
        }

        foreach ([ProductMeta::FFLHUB_UPC_META, '_upc', 'upc', 'UPC'] as $meta_key) {
            $sql = $wpdb->prepare(
                "SELECT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                   AND pm.meta_value = %s
                   AND p.post_type = 'product'
                   AND p.post_status IN ('publish', 'draft', 'pending', 'private')
                 ORDER BY pm.post_id DESC
                 LIMIT 1",
                $meta_key,
                $upc
            );

            $product_id = (int) $wpdb->get_var($sql);
            if ($product_id > 0) {
                return $product_id;
            }
        }

        return 0;
    }

    private static function rsr_catalog_looks_holosun_for_upc(string $upc): bool
    {
        global $wpdb;

        $upc = trim($upc);
        if ($upc === '') {
            return false;
        }

        foreach (self::rsr_catalog_table_candidates() as $table_name) {
            if (!self::is_safe_table_name($table_name)) {
                continue;
            }

            $quoted_table = '`' . str_replace('`', '``', $table_name) . '`';
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
            if ((string) $exists !== $table_name) {
                continue;
            }

            $sql = $wpdb->prepare(
                "SELECT manufacturer, product_description, expanded_product_description, model, manufacturer_part_number
                 FROM {$quoted_table}
                 WHERE upc = %s
                 LIMIT 1",
                $upc
            );

            $row = $wpdb->get_row($sql, ARRAY_A);
            if (!is_array($row) || empty($row)) {
                continue;
            }

            foreach (['manufacturer', 'product_description', 'expanded_product_description', 'model', 'manufacturer_part_number'] as $key) {
                if (self::is_holosun_brand_name((string) ($row[$key] ?? ''))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private static function rsr_catalog_table_candidates(): array
    {
        global $wpdb;

        $base = $wpdb->prefix . RSRProductTableSchema::BASE_TABLE_KEY;
        $candidates = [];
        $stored = get_option(RSRProductTableSchema::LIVE_TABLE_OPTION, '');

        if (is_string($stored) && $stored !== '') {
            if ($stored === 'v1' || $stored === 'v2') {
                $candidates[] = $base . '_' . $stored;
            } else {
                $candidates[] = $stored;
            }
        }

        $candidates[] = $base . '_v1';
        $candidates[] = $base . '_v2';

        return array_values(array_unique(array_filter($candidates, 'is_string')));
    }

    private static function is_safe_table_name(string $table_name): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $table_name) === 1;
    }
}
