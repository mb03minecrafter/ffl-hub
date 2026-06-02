<?php

namespace FFLHub\Feeds\GunDeals;

use FFLHub\Product\ProductMeta;

if (!defined('ABSPATH')) {
    exit;
}

final class GunDealsClickTracker
{
    public const META_RAW_CLICKS = '_fflhub_gundeals_clicks_raw';
    public const META_DEDUPED_CLICKS = '_fflhub_gundeals_clicks_deduped';
    public const META_LAST_CLICK_AT = '_fflhub_gundeals_last_click_at';

    public const OPTION_RAW_TOTAL = 'fflhub_gundeals_clicks_raw_total';
    public const OPTION_DEDUPED_TOTAL = 'fflhub_gundeals_clicks_deduped_total';
    public const OPTION_RAW_DAILY = 'fflhub_gundeals_clicks_raw_daily';
    public const OPTION_DEDUPED_DAILY = 'fflhub_gundeals_clicks_deduped_daily';

    private const COOKIE_PREFIX = 'fflhub_gd_click_';
    private const DEDUPE_SECONDS = 30 * 60;
    private const DAILY_RETENTION_DAYS = 180;

    private function __construct() {}

    public static function init(): void
    {
        add_action('template_redirect', [self::class, 'maybe_track_click'], 20);
    }

    public static function maybe_track_click(): void
    {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            return;
        }

        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        if (self::request_source() !== 'gundeals') {
            return;
        }

        $product_id = (int) get_queried_object_id();
        if ($product_id <= 0 || get_post_type($product_id) !== 'product') {
            return;
        }

        $cookie_name = self::COOKIE_PREFIX . $product_id;
        $is_deduped_click = empty($_COOKIE[$cookie_name]);
        $upc = GunDealsAnalyticsStore::normalize_upc((string) get_post_meta($product_id, ProductMeta::FFLHUB_UPC_META, true));

        GunDealsAnalyticsStore::record_click($product_id, $upc, $is_deduped_click, self::request_context());

        if (!$is_deduped_click) {
            return;
        }

        self::set_dedupe_cookie($cookie_name);
    }

    /**
     * @return array<string,string>
     */
    private static function request_context(): array
    {
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $scheme = (function_exists('is_ssl') && is_ssl()) ? 'https://' : 'http://';

        return [
            'source' => 'gundeals',
            'request_url' => $host !== '' ? $scheme . $host . $uri : $uri,
            'referrer' => isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
            'session' => isset($_COOKIE[LOGGED_IN_COOKIE]) ? (string) $_COOKIE[LOGGED_IN_COOKIE] : '',
        ];
    }

    private static function request_source(): string
    {
        $raw = $_GET['utm_source'] ?? ($_GET['source'] ?? '');
        if (is_array($raw)) {
            return '';
        }

        $raw = function_exists('wp_unslash') ? wp_unslash((string) $raw) : (string) $raw;
        $raw = function_exists('sanitize_key') ? sanitize_key($raw) : strtolower(preg_replace('/[^a-z0-9_\-]/', '', $raw));

        return strtolower(trim((string) $raw));
    }

    private static function set_dedupe_cookie(string $cookie_name): void
    {
        if (headers_sent()) {
            return;
        }

        $path = defined('COOKIEPATH') && (string) COOKIEPATH !== '' ? (string) COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? (string) COOKIE_DOMAIN : '';

        setcookie($cookie_name, '1', [
            'expires' => time() + self::DEDUPE_SECONDS,
            'path' => $path,
            'domain' => $domain,
            'secure' => function_exists('is_ssl') ? is_ssl() : false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[$cookie_name] = '1';
    }
}
