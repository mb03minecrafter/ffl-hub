<?php

namespace FFLHub\Feeds\GunDeals;

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

        self::increment_post_counter($product_id, self::META_RAW_CLICKS);
        self::increment_option_counter(self::OPTION_RAW_TOTAL);
        self::increment_daily_counter(self::OPTION_RAW_DAILY);
        update_post_meta($product_id, self::META_LAST_CLICK_AT, gmdate('Y-m-d H:i:s'));

        $cookie_name = self::COOKIE_PREFIX . $product_id;
        if (!empty($_COOKIE[$cookie_name])) {
            return;
        }

        self::increment_post_counter($product_id, self::META_DEDUPED_CLICKS);
        self::increment_option_counter(self::OPTION_DEDUPED_TOTAL);
        self::increment_daily_counter(self::OPTION_DEDUPED_DAILY);
        self::set_dedupe_cookie($cookie_name);
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

    private static function increment_post_counter(int $product_id, string $meta_key): int
    {
        $current = max(0, (int) get_post_meta($product_id, $meta_key, true));
        $next = $current + 1;
        update_post_meta($product_id, $meta_key, $next);

        return $next;
    }

    private static function increment_option_counter(string $option_key): int
    {
        $current = max(0, (int) get_option($option_key, 0));
        $next = $current + 1;
        update_option($option_key, $next, false);

        return $next;
    }

    private static function increment_daily_counter(string $option_key): void
    {
        $day = gmdate('Y-m-d');
        $counts = get_option($option_key, []);
        if (!is_array($counts)) {
            $counts = [];
        }

        $counts[$day] = max(0, (int) ($counts[$day] ?? 0)) + 1;
        ksort($counts);

        if (count($counts) > self::DAILY_RETENTION_DAYS) {
            $counts = array_slice($counts, -self::DAILY_RETENTION_DAYS, null, true);
        }

        update_option($option_key, $counts, false);
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
