<?php

declare(strict_types=1);

namespace FFLHub\Brand;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gives WooCommerce product-tag archive pages customer-facing collection URLs.
 *
 * The taxonomy stays `product_tag`, so the existing Woo tag editor, Yoast term
 * SEO fields, archive templates, FAQs, hero images, and product assignments all
 * continue to work. Only the public rewrite base changes:
 *
 *   /product-tag/example/ -> /collections/example/
 */
final class ProductCollectionRewrite
{
    private const COLLECTION_BASE = 'collections';
    private const OLD_TAG_BASE = 'product-tag';
    private const FLUSH_OPTION = 'fflhub_product_collection_rewrite_version';
    private const FLUSH_VERSION = '1';

    public static function init(): void
    {
        add_filter('woocommerce_taxonomy_args_product_tag', [self::class, 'product_tag_taxonomy_args']);
        add_action('template_redirect', [self::class, 'redirect_legacy_product_tag_urls'], 1);
        add_action('init', [self::class, 'maybe_flush_rewrite_rules'], 99);
    }

    /**
     * Change the canonical product-tag rewrite base. Because this alters the
     * taxonomy registration itself, every future product tag automatically gets
     * a /collections/{slug}/ URL through get_term_link(), Yoast, and Woo blocks.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function product_tag_taxonomy_args(array $args): array
    {
        $rewrite = isset($args['rewrite']) && is_array($args['rewrite'])
            ? $args['rewrite']
            : [];

        $rewrite['slug'] = self::COLLECTION_BASE;
        $rewrite['with_front'] = false;

        $args['rewrite'] = $rewrite;

        return $args;
    }

    public static function maybe_flush_rewrite_rules(): void
    {
        if (!function_exists('flush_rewrite_rules')) {
            return;
        }

        $current = (string) get_option(self::FLUSH_OPTION, '');
        if ($current === self::FLUSH_VERSION) {
            return;
        }

        flush_rewrite_rules(false);
        update_option(self::FLUSH_OPTION, self::FLUSH_VERSION, false);
    }

    public static function redirect_legacy_product_tag_urls(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($request_uri === '') {
            return;
        }

        $parts = wp_parse_url($request_uri);
        $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
        if ($path === '') {
            return;
        }

        $old_base = self::OLD_TAG_BASE . '/';
        if (strpos($path, $old_base) !== 0) {
            return;
        }

        $rest = trim(substr($path, strlen($old_base)), '/');
        if ($rest === '') {
            return;
        }

        $target = home_url('/' . self::COLLECTION_BASE . '/' . $rest . '/');
        if (!empty($parts['query'])) {
            $target .= '?' . (string) $parts['query'];
        }

        wp_safe_redirect($target, 301, 'FFLHub');
        exit;
    }
}
