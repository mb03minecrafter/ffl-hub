<?php
declare(strict_types=1);

namespace FFLHub\BOM\Services;

use FFLHub\Util\DebugLogUtil;
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
    private const DEBUG_CONST = 'FFLHUB_ADMIN_DEBUG';
    private const LOG_PREFIX = '[FFLHub][BOM][LinkResolver]';

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

        $request_args = self::build_request_args($url, false, 'external_url');
        self::debug_ctx('external_url request', self::request_debug_context($url, $request_args));

        $response = wp_remote_get($url, $request_args);

        if (is_wp_error($response)) {
            self::debug_ctx('external_url request failed', [
                'url' => $url,
                'error' => $response->get_error_message(),
            ]);

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
        $headers = self::normalize_headers(wp_remote_retrieve_headers($response));
        $block_fingerprint = self::detect_block_fingerprint($status_code, $headers, $html);
        self::debug_ctx('external_url response', [
            'url' => $url,
            'status_code' => $status_code,
            'html_len' => strlen($html),
            'block_fingerprint' => $block_fingerprint,
            'header_signals' => self::header_signals($headers),
            'body_hash' => ($html !== '') ? substr(hash('sha256', $html), 0, 16) : '',
            'body_preview' => self::body_preview($html),
        ]);
        if ($status_code < 200 || $status_code >= 400 || $html === '') {
            $api_fallback = self::resolve_external_woo_store_api($url);
            if ($api_fallback !== null) {
                self::$external_cache[$url] = $api_fallback;
                return $api_fallback;
            }

            $error_code = ($status_code > 0)
                ? ('external_http_' . (string) $status_code . ($block_fingerprint !== '' ? '_' . $block_fingerprint : ''))
                : 'external_bad_response';
            $out = self::result(true, 'external_url', null, self::STOCK_UNKNOWN, null, $url, $error_code);
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
            self::debug_ctx('store_api fallback skip: missing slug', ['url' => $url]);
            return null;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            self::debug_ctx('store_api fallback skip: invalid url parts', ['url' => $url]);
            return null;
        }

        $base = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        $api_candidates = [
            $base . '/wp-json/wc/store/v1/products?slug=' . rawurlencode($slug),
            $base . '/wp-json/wc/store/products?slug=' . rawurlencode($slug),
        ];

        foreach ($api_candidates as $api) {
            $request_args = self::build_request_args($api, true, 'store_api');
            self::debug_ctx('store_api request', self::request_debug_context($api, $request_args));

            $response = wp_remote_get($api, $request_args);

            if (is_wp_error($response)) {
                self::debug_ctx('store_api request failed', [
                    'api' => $api,
                    'error' => $response->get_error_message(),
                ]);
                continue;
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            $headers = self::normalize_headers(wp_remote_retrieve_headers($response));
            $block_fingerprint = self::detect_block_fingerprint($status_code, $headers, $body);
            self::debug_ctx('store_api response', [
                'api' => $api,
                'status_code' => $status_code,
                'body_len' => strlen($body),
                'block_fingerprint' => $block_fingerprint,
                'header_signals' => self::header_signals($headers),
                'body_hash' => ($body !== '') ? substr(hash('sha256', $body), 0, 16) : '',
                'body_preview' => self::body_preview($body),
            ]);

            if ($status_code < 200 || $status_code >= 400 || $body === '') {
                continue;
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded) || empty($decoded) || !is_array($decoded[0])) {
                continue;
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

            self::debug_ctx('store_api resolved', [
                'api' => $api,
                'slug' => $slug,
                'price' => $price,
                'stock_state' => $stock_state,
            ]);
            return self::result(true, 'external_woo_api', $price, $stock_state, null, $url, '');
        }

        self::debug_ctx('store_api fallback exhausted', [
            'url' => $url,
            'slug' => $slug,
        ]);
        return null;
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

        // Most reliable Woo selectors first.
        if (strpos($hay, 'class="stock out-of-stock"') !== false || strpos($hay, "class='stock out-of-stock'") !== false) {
            return self::STOCK_OUT_OF_STOCK;
        }
        if (strpos($hay, 'class="stock in-stock"') !== false || strpos($hay, "class='stock in-stock'") !== false) {
            return self::STOCK_IN_STOCK;
        }

        // Structured data availability is usually reliable on product pages.
        if (preg_match('/"availability"\s*:\s*"https?:\/\/schema\.org\/instock"/i', $html) === 1) {
            return self::STOCK_IN_STOCK;
        }
        if (preg_match('/"availability"\s*:\s*"https?:\/\/schema\.org\/outofstock"/i', $html) === 1) {
            return self::STOCK_OUT_OF_STOCK;
        }

        $out_of_stock_needles = [
            'out of stock',
            'out-of-stock',
            'sold out',
            'unavailable',
            'currently unavailable',
        ];
        $found_out = false;
        foreach ($out_of_stock_needles as $needle) {
            if (strpos($hay, $needle) !== false) {
                $found_out = true;
                break;
            }
        }

        $in_stock_needles = [
            'in stock',
            'in-stock',
            'instock',
            'available',
        ];
        $found_in = false;
        foreach ($in_stock_needles as $needle) {
            if (strpos($hay, $needle) !== false) {
                $found_in = true;
                break;
            }
        }

        // If both phrases appear (common on related products/widgets), use add-to-cart state as a tiebreaker.
        $has_add_to_cart = (bool) preg_match('/single_add_to_cart_button/i', $html);
        $has_disabled_add_to_cart = (bool) preg_match('/single_add_to_cart_button[^>]*\bdisabled\b/i', $html);
        if ($has_add_to_cart && !$has_disabled_add_to_cart) {
            return self::STOCK_IN_STOCK;
        }

        if ($found_in && !$found_out) {
            return self::STOCK_IN_STOCK;
        }
        if ($found_out && !$found_in) {
            return self::STOCK_OUT_OF_STOCK;
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

    /**
     * @param mixed $headers_raw
     * @return array<string,string>
     */
    private static function normalize_headers($headers_raw): array
    {
        $headers = [];

        if (is_array($headers_raw)) {
            $headers = $headers_raw;
        } elseif (is_object($headers_raw)) {
            if (method_exists($headers_raw, 'getAll')) {
                $all = $headers_raw->getAll();
                if (is_array($all)) {
                    $headers = $all;
                }
            }

            if (empty($headers)) {
                $headers = (array) $headers_raw;
            }
        }

        $out = [];
        foreach ($headers as $k => $v) {
            $key = strtolower(trim((string) $k));
            if ($key === '') {
                continue;
            }

            if (is_array($v)) {
                $v = implode(', ', array_map(static fn($x): string => (string) $x, $v));
            }

            $out[$key] = trim((string) $v);
        }

        return $out;
    }

    /**
     * @param array<string,string> $headers
     */
    private static function detect_block_fingerprint(int $status_code, array $headers, string $body): string
    {
        if ($status_code < 400) {
            return '';
        }

        $header_blob = strtolower(implode(' ', array_keys($headers)) . ' ' . implode(' ', $headers));
        $body_blob = strtolower($body);
        $hay = $header_blob . ' ' . $body_blob;

        if (isset($headers['cf-ray']) || strpos($hay, 'cloudflare') !== false) {
            return 'cloudflare';
        }
        if (isset($headers['x-sucuri-id']) || strpos($hay, 'sucuri') !== false) {
            return 'sucuri';
        }
        if (strpos($hay, 'incapsula') !== false || strpos($hay, 'imperva') !== false) {
            return 'incapsula';
        }
        if (strpos($hay, 'akamai') !== false) {
            return 'akamai';
        }
        if (strpos($hay, 'captcha') !== false || strpos($hay, 'are you human') !== false) {
            return 'captcha';
        }

        return 'waf';
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    private static function header_signals(array $headers): array
    {
        $keys = [
            'server',
            'cf-ray',
            'cf-cache-status',
            'x-sucuri-id',
            'x-sucuri-block',
            'x-cache',
            'x-powered-by',
        ];

        $out = [];
        foreach ($keys as $k) {
            if (!isset($headers[$k]) || $headers[$k] === '') {
                continue;
            }
            $out[$k] = $headers[$k];
        }

        return $out;
    }

    private static function body_preview(string $body): string
    {
        if ($body === '') {
            return '';
        }

        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = is_string($text) ? trim($text) : '';

        if ($text === '') {
            return '';
        }

        return function_exists('mb_substr')
            ? (string) mb_substr($text, 0, 180)
            : substr($text, 0, 180);
    }

    /**
     * Build browser-like request args and allow per-domain overrides.
     *
     * @param string $context Example: "external_url" or "store_api".
     * @return array<string,mixed>
     */
    private static function build_request_args(string $url, bool $expects_json, string $context): array
    {
        $accept = $expects_json
            ? 'application/json, text/plain;q=0.9, */*;q=0.8'
            : 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';

        $headers = [
            'Accept' => $accept,
            'Accept-Language' => 'en-US,en;q=0.9',
            'Cache-Control' => 'max-age=0',
        ];

        if (!$expects_json) {
            $headers['Upgrade-Insecure-Requests'] = '1';
        }

        $parts = wp_parse_url($url);
        if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
            $headers['Referer'] = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . '/';
        }

        $args = [
            'timeout' => 10,
            'redirection' => 4,
            // Browser-like UA helps avoid basic bot rejections on some retail sites.
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',
            'headers' => $headers,
        ];

        $cookie_header = self::domain_cookie_header_for_url($url, $context);
        if ($cookie_header !== '') {
            $args['headers']['Cookie'] = $cookie_header;
        }

        $filtered = apply_filters('fflhub_bom_link_resolver_request_args', $args, $url, $context, $expects_json);
        if (!is_array($filtered)) {
            return $args;
        }

        $out = $args;
        if (isset($filtered['timeout'])) {
            $out['timeout'] = max(1, (int) $filtered['timeout']);
        }
        if (isset($filtered['redirection'])) {
            $out['redirection'] = max(0, (int) $filtered['redirection']);
        }
        if (isset($filtered['user-agent'])) {
            $ua = trim((string) $filtered['user-agent']);
            if ($ua !== '') {
                $out['user-agent'] = $ua;
            }
        }
        if (isset($filtered['headers']) && is_array($filtered['headers'])) {
            $out['headers'] = $filtered['headers'];
        }

        return $out;
    }

    /**
     * Optional cookie injection for blocked domains (for example Cloudflare clearance).
     * Reads from option "fflhub_bom_external_domain_cookies" and filter.
     */
    private static function domain_cookie_header_for_url(string $url, string $context): string
    {
        $parts = wp_parse_url($url);
        $host = '';
        if (is_array($parts) && !empty($parts['host'])) {
            $host = strtolower(trim((string) $parts['host']));
        }

        $cookie = '';
        $raw = get_option('fflhub_bom_external_domain_cookies', []);
        $map = [];

        if (is_array($raw)) {
            $map = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $map = $decoded;
            }
        }

        if ($host !== '' && !empty($map)) {
            $cookie = self::cookie_for_host($map, $host);
        }

        $cookie = (string) apply_filters('fflhub_bom_link_resolver_cookie_header', $cookie, $url, $context, $host);
        $cookie = trim(preg_replace('/[\r\n]+/', ' ', $cookie) ?? '');

        return $cookie;
    }

    /**
     * @param array<mixed> $map
     */
    private static function cookie_for_host(array $map, string $host): string
    {
        if (isset($map[$host]) && is_scalar($map[$host])) {
            return trim((string) $map[$host]);
        }

        $parts = explode('.', $host);
        while (count($parts) > 2) {
            array_shift($parts);
            $candidate = implode('.', $parts);
            if (isset($map[$candidate]) && is_scalar($map[$candidate])) {
                return trim((string) $map[$candidate]);
            }
            $dot_candidate = '.' . $candidate;
            if (isset($map[$dot_candidate]) && is_scalar($map[$dot_candidate])) {
                return trim((string) $map[$dot_candidate]);
            }
            $wildcard = '*.' . $candidate;
            if (isset($map[$wildcard]) && is_scalar($map[$wildcard])) {
                return trim((string) $map[$wildcard]);
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function request_debug_context(string $url, array $args): array
    {
        $timeout = isset($args['timeout']) ? (int) $args['timeout'] : 0;
        $redirection = isset($args['redirection']) ? (int) $args['redirection'] : 0;
        $user_agent = isset($args['user-agent']) ? trim((string) $args['user-agent']) : '';
        $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];

        $normalized_headers = [];
        foreach ($headers as $key => $value) {
            $k = trim((string) $key);
            if ($k === '') {
                continue;
            }
            $normalized_headers[$k] = trim((string) $value);
        }

        $safe_headers = self::redact_sensitive_headers($normalized_headers);
        $cookie_header = '';
        foreach ($normalized_headers as $k => $v) {
            if (strtolower($k) === 'cookie') {
                $cookie_header = $v;
                break;
            }
        }

        return [
            'url' => $url,
            'timeout' => $timeout,
            'redirection' => $redirection,
            'user_agent' => $user_agent,
            'headers' => $safe_headers,
            'has_cookie' => $cookie_header !== '',
            'cookie_len' => ($cookie_header !== '') ? strlen($cookie_header) : 0,
            'cookie_hash' => ($cookie_header !== '') ? substr(hash('sha256', $cookie_header), 0, 16) : '',
            'curl' => self::build_debug_curl_command($url, $timeout, $user_agent, $safe_headers),
        ];
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    private static function redact_sensitive_headers(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $key_l = strtolower($k);
            if (in_array($key_l, ['cookie', 'authorization', 'proxy-authorization', 'x-api-key'], true)) {
                $out[$k] = '[redacted len=' . strlen($v) . ' sha=' . substr(hash('sha256', $v), 0, 12) . ']';
                continue;
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * @param array<string,string> $headers
     */
    private static function build_debug_curl_command(string $url, int $timeout, string $user_agent, array $headers): string
    {
        $parts = ['curl', '-i', '-L'];

        if ($timeout > 0) {
            $parts[] = '--max-time';
            $parts[] = (string) $timeout;
        }

        if ($user_agent !== '') {
            $parts[] = '-A';
            $parts[] = self::shell_escape_single($user_agent);
        }

        foreach ($headers as $k => $v) {
            $parts[] = '-H';
            $parts[] = self::shell_escape_single($k . ': ' . $v);
        }

        $parts[] = self::shell_escape_single($url);
        return implode(' ', $parts);
    }

    private static function shell_escape_single(string $raw): string
    {
        return "'" . str_replace("'", "'\"'\"'", $raw) . "'";
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private static function debug_ctx(string $msg, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $msg, $ctx);
    }
}
