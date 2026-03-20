<?php
declare(strict_types=1);

namespace FFLHub\BOM\Services;

use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared resolver for BOM product-link sources (local Woo product or external URL).
 */
final class ProductLinkResolver
{
    private const STOCK_IN_STOCK = 'in_stock';
    private const STOCK_OUT_OF_STOCK = 'out_of_stock';
    private const STOCK_UNKNOWN = 'unknown';

    /**
     * @var array<string,array{
     *   resolved:bool,
     *   source:string,
     *   unit_price:?float,
     *   stock_state:string,
     *   product_id:?int,
     *   url:string,
     *   error_code:string
     * }>
     */
    private static array $external_cache = [];

    public static function normalize_source_ref(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (ctype_digit($raw)) {
            $id = (int) $raw;
            return $id > 0 ? (string) $id : '';
        }

        $url = esc_url_raw($raw);
        if ($url !== '') {
            return $url;
        }

        return sanitize_text_field($raw);
    }

    /**
     * Resolve a product-link reference into a normalized price/stock snapshot.
     *
     * @return array{
     *   resolved:bool,
     *   source:string,
     *   unit_price:?float,
     *   stock_state:string,
     *   product_id:?int,
     *   url:string,
     *   error_code:string
     * }
     */
    public static function resolve(string $source_ref): array
    {
        $source_ref = trim($source_ref);
        if ($source_ref === '') {
            return self::result(false, 'unknown', null, self::STOCK_UNKNOWN, null, '', 'missing_ref');
        }

        $product_id = self::resolve_product_id_from_ref($source_ref);
        if ($product_id > 0) {
            $product = wc_get_product($product_id);
            if (!($product instanceof WC_Product)) {
                return self::result(false, 'local_product', null, self::STOCK_UNKNOWN, $product_id, '', 'local_product_missing');
            }

            $price_raw = (string) $product->get_price();
            $price = is_numeric($price_raw) ? (float) $price_raw : null;
            $stock_state = $product->is_in_stock() ? self::STOCK_IN_STOCK : self::STOCK_OUT_OF_STOCK;
            $url = (string) get_permalink($product_id);

            return self::result(true, 'local_product', $price, $stock_state, $product_id, $url, '');
        }

        if (self::looks_like_url($source_ref)) {
            return self::resolve_external_url($source_ref);
        }

        return self::result(false, 'unknown', null, self::STOCK_UNKNOWN, null, '', 'unresolved');
    }

    /**
     * @return array{
     *   resolved:bool,
     *   source:string,
     *   unit_price:?float,
     *   stock_state:string,
     *   product_id:?int,
     *   url:string,
     *   error_code:string
     * }
     */
    private static function resolve_external_url(string $url): array
    {
        $url = esc_url_raw(trim($url));
        if ($url === '') {
            return self::result(false, 'external_url', null, self::STOCK_UNKNOWN, null, '', 'invalid_url');
        }

        if (isset(self::$external_cache[$url])) {
            return self::$external_cache[$url];
        }

        $fetch_args = [
            'timeout'     => 10,
            'redirection' => 4,
            // Browser-like UA helps avoid basic bot rejections on some retail sites.
            'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'headers'     => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ];

        $response = wp_remote_get($url, [
            'timeout'     => (int) $fetch_args['timeout'],
            'redirection' => (int) $fetch_args['redirection'],
            'user-agent'  => (string) $fetch_args['user-agent'],
            'headers'     => (array) $fetch_args['headers'],
        ]);

        if (is_wp_error($response)) {
            $api_fallback = self::resolve_external_woo_store_api($url);
            if ($api_fallback !== null) {
                self::$external_cache[$url] = $api_fallback;
                return $api_fallback;
            }

            $out = self::result(true, 'external_url', null, self::STOCK_UNKNOWN, null, $url, 'external_request_failed');
            self::$external_cache[$url] = $out;
            return $out;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $html = (string) wp_remote_retrieve_body($response);
        if ($status_code < 200 || $status_code >= 400 || $html === '') {
            $api_fallback = self::resolve_external_woo_store_api($url);
            if ($api_fallback !== null) {
                self::$external_cache[$url] = $api_fallback;
                return $api_fallback;
            }

            $out = self::result(true, 'external_url', null, self::STOCK_UNKNOWN, null, $url, 'external_bad_response');
            self::$external_cache[$url] = $out;
            return $out;
        }

        $price = self::extract_price_from_html($html);
        $stock_state = self::extract_stock_state_from_html($html);

        $out = self::result(true, 'external_url', $price, $stock_state, null, $url, '');
        self::$external_cache[$url] = $out;
        return $out;
    }

    /**
     * Fallback for WooCommerce storefronts that block direct HTML scraping.
     *
     * @return array{
     *   resolved:bool,
     *   source:string,
     *   unit_price:?float,
     *   stock_state:string,
     *   product_id:?int,
     *   url:string,
     *   error_code:string
     * }|null
     */
    private static function resolve_external_woo_store_api(string $url): ?array
    {
        $slug = self::extract_product_slug_from_url($url);
        if ($slug === '') {
            return null;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $base = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        $api = $base . '/wp-json/wc/store/products?slug=' . rawurlencode($slug);

        $response = wp_remote_get($api, [
            'timeout'     => 10,
            'redirection' => 4,
            'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'headers'     => [
                'Accept' => 'application/json, text/plain;q=0.9, */*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 400) {
            return null;
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded) || !is_array($decoded[0])) {
            return null;
        }

        $item = $decoded[0];

        $price = null;
        if (isset($item['prices']) && is_array($item['prices'])) {
            $raw_minor = isset($item['prices']['price']) ? (string) $item['prices']['price'] : '';
            $minor_unit = isset($item['prices']['currency_minor_unit']) ? (int) $item['prices']['currency_minor_unit'] : 2;

            if ($raw_minor !== '' && ctype_digit($raw_minor)) {
                $divisor = 1;
                for ($i = 0; $i < max(0, min(6, $minor_unit)); $i++) {
                    $divisor *= 10;
                }
                $price = ((float) $raw_minor) / (float) $divisor;
            }
        }

        $stock_state = self::STOCK_UNKNOWN;
        $stock_status_raw = strtolower(trim((string) ($item['stock_status'] ?? '')));
        if ($stock_status_raw === 'instock') {
            $stock_state = self::STOCK_IN_STOCK;
        } elseif ($stock_status_raw === 'outofstock') {
            $stock_state = self::STOCK_OUT_OF_STOCK;
        } elseif (array_key_exists('is_in_stock', $item)) {
            $stock_state = !empty($item['is_in_stock']) ? self::STOCK_IN_STOCK : self::STOCK_OUT_OF_STOCK;
        }

        return self::result(true, 'external_woo_api', $price, $stock_state, null, $url, '');
    }

    private static function extract_product_slug_from_url(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['path'])) {
            return '';
        }

        $path = trim((string) $parts['path']);
        if ($path === '') {
            return '';
        }

        // Prefer /product/{slug}/ style URLs.
        if (preg_match('~/(?:product|products)/([^/?#]+)/?~i', $path, $m)) {
            return sanitize_title((string) ($m[1] ?? ''));
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn($s): bool => $s !== ''));
        if (empty($segments)) {
            return '';
        }

        return sanitize_title((string) end($segments));
    }

    private static function resolve_product_id_from_ref(string $source_ref): int
    {
        $source_ref = trim($source_ref);
        if ($source_ref === '') {
            return 0;
        }

        if (ctype_digit($source_ref)) {
            $id = (int) $source_ref;
            return $id > 0 ? $id : 0;
        }

        if (function_exists('url_to_postid')) {
            $id = (int) url_to_postid($source_ref);
            if ($id > 0) {
                return $id;
            }
        }

        $url = esc_url_raw($source_ref);
        if ($url === '') {
            return 0;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || !isset($parts['query'])) {
            return 0;
        }

        $query = [];
        parse_str((string) $parts['query'], $query);

        foreach (['product_id', 'product', 'post', 'p'] as $k) {
            if (!isset($query[$k])) {
                continue;
            }

            $v = (string) $query[$k];
            if (!is_numeric($v)) {
                continue;
            }

            $id = (int) $v;
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    private static function looks_like_url(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return (bool) preg_match('~^https?://~i', $value);
    }

    private static function extract_price_from_html(string $html): ?float
    {
        $patterns = [
            '/class=["\'][^"\']*woocommerce-Price-amount[^"\']*["\'][^>]*>\s*(?:<span[^>]*>)?\$?\s*([0-9]{1,4}(?:,[0-9]{3})*(?:\.[0-9]{2})?)/i',
            '/itemprop=["\']price["\'][^>]*content=["\']\s*([0-9]+(?:\.[0-9]{1,4})?)\s*["\']/i',
            '/property=["\']product:price:amount["\'][^>]*content=["\']\s*([0-9]+(?:\.[0-9]{1,4})?)\s*["\']/i',
            '/"price"\s*:\s*"([0-9]+(?:\.[0-9]{1,4})?)"/i',
            '/"price"\s*:\s*([0-9]+(?:\.[0-9]{1,4})?)/i',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $html, $m)) {
                continue;
            }

            $raw = str_replace(',', '', trim((string) ($m[1] ?? '')));
            if ($raw === '' || !is_numeric($raw)) {
                continue;
            }

            $num = (float) $raw;
            if (!is_finite($num) || $num < 0.0) {
                continue;
            }

            return $num;
        }

        if (preg_match('/\$([0-9]{1,4}(?:,[0-9]{3})*(?:\.[0-9]{2})?)/', $html, $m)) {
            $raw = str_replace(',', '', (string) ($m[1] ?? ''));
            if ($raw !== '' && is_numeric($raw)) {
                $num = (float) $raw;
                if (is_finite($num) && $num >= 0.0) {
                    return $num;
                }
            }
        }

        return null;
    }

    private static function extract_stock_state_from_html(string $html): string
    {
        $hay = strtolower($html);

        if (strpos($hay, 'class="stock out-of-stock"') !== false || strpos($hay, "class='stock out-of-stock'") !== false) {
            return self::STOCK_OUT_OF_STOCK;
        }
        if (strpos($hay, 'class="stock in-stock"') !== false || strpos($hay, "class='stock in-stock'") !== false) {
            return self::STOCK_IN_STOCK;
        }

        $out_of_stock_needles = [
            'out of stock',
            'out-of-stock',
            'sold out',
            'unavailable',
            'currently unavailable',
        ];
        foreach ($out_of_stock_needles as $needle) {
            if (strpos($hay, $needle) !== false) {
                return self::STOCK_OUT_OF_STOCK;
            }
        }

        $in_stock_needles = [
            'in stock',
            'in-stock',
            'instock',
            'available',
        ];
        foreach ($in_stock_needles as $needle) {
            if (strpos($hay, $needle) !== false) {
                return self::STOCK_IN_STOCK;
            }
        }

        return self::STOCK_UNKNOWN;
    }

    /**
     * @return array{
     *   resolved:bool,
     *   source:string,
     *   unit_price:?float,
     *   stock_state:string,
     *   product_id:?int,
     *   url:string,
     *   error_code:string
     * }
     */
    private static function result(
        bool $resolved,
        string $source,
        ?float $unit_price,
        string $stock_state,
        ?int $product_id,
        string $url,
        string $error_code
    ): array {
        return [
            'resolved'   => $resolved,
            'source'     => $source,
            'unit_price' => ($unit_price !== null && is_finite($unit_price) && $unit_price >= 0.0)
                ? $unit_price
                : null,
            'stock_state' => in_array($stock_state, [self::STOCK_IN_STOCK, self::STOCK_OUT_OF_STOCK, self::STOCK_UNKNOWN], true)
                ? $stock_state
                : self::STOCK_UNKNOWN,
            'product_id' => ($product_id !== null && $product_id > 0) ? $product_id : null,
            'url'        => trim($url),
            'error_code' => trim($error_code),
        ];
    }
}
