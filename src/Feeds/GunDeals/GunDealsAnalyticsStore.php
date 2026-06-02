<?php

namespace FFLHub\Feeds\GunDeals;

if (!defined('ABSPATH')) {
    exit;
}

final class GunDealsAnalyticsStore
{
    private const SCHEMA_OPTION = 'fflhub_gundeals_analytics_schema_version';
    private const SCHEMA_VERSION = '1';

    public static function ensure_schema(): void
    {
        $installed = (string) get_option(self::SCHEMA_OPTION, '');
        if ($installed === self::SCHEMA_VERSION) {
            return;
        }

        global $wpdb;
        if (!$wpdb) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        dbDelta("
            CREATE TABLE " . self::click_events_table() . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                occurred_at_gmt datetime NOT NULL,
                day date NOT NULL,
                product_id bigint(20) unsigned NOT NULL DEFAULT 0,
                upc varchar(32) NOT NULL DEFAULT '',
                raw_click tinyint(1) unsigned NOT NULL DEFAULT 1,
                deduped_click tinyint(1) unsigned NOT NULL DEFAULT 0,
                source varchar(32) NOT NULL DEFAULT 'gundeals',
                request_url text NULL,
                referrer text NULL,
                user_agent_hash char(64) NULL,
                ip_hash char(64) NULL,
                session_hash char(64) NULL,
                PRIMARY KEY  (id),
                KEY day (day),
                KEY upc_day (upc, day),
                KEY product_day (product_id, day),
                KEY occurred_at_gmt (occurred_at_gmt),
                KEY deduped_day (deduped_click, day)
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE " . self::click_rollups_table() . " (
                day date NOT NULL,
                upc varchar(32) NOT NULL DEFAULT '',
                product_id bigint(20) unsigned NOT NULL DEFAULT 0,
                raw_clicks int(10) unsigned NOT NULL DEFAULT 0,
                deduped_clicks int(10) unsigned NOT NULL DEFAULT 0,
                updated_at_gmt datetime NOT NULL,
                PRIMARY KEY  (day, upc, product_id),
                KEY upc_day (upc, day),
                KEY product_day (product_id, day)
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE " . self::feed_snapshots_table() . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                generated_at_gmt datetime NOT NULL,
                product_id bigint(20) unsigned NOT NULL DEFAULT 0,
                upc varchar(32) NOT NULL DEFAULT '',
                included tinyint(1) unsigned NOT NULL DEFAULT 1,
                stock_status varchar(32) NOT NULL DEFAULT '',
                price decimal(12,2) NULL,
                price_hide varchar(120) NOT NULL DEFAULT '',
                shipping_info varchar(120) NOT NULL DEFAULT '',
                shipping_charge decimal(10,2) NULL,
                source varchar(64) NOT NULL DEFAULT '',
                last_stock_update varchar(64) NOT NULL DEFAULT '',
                raw_json longtext NULL,
                PRIMARY KEY  (id),
                KEY generated_at_gmt (generated_at_gmt),
                KEY upc_generated (upc, generated_at_gmt),
                KEY product_generated (product_id, generated_at_gmt),
                KEY included_generated (included, generated_at_gmt)
            ) {$charset_collate};
        ");

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
    }

    public static function click_events_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'fflhub_gundeals_click_events';
    }

    public static function click_rollups_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'fflhub_gundeals_click_rollups';
    }

    public static function feed_snapshots_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'fflhub_gundeals_feed_snapshots';
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function record_click(int $product_id, string $upc, bool $deduped, array $context = []): void
    {
        global $wpdb;
        if (!$wpdb) {
            return;
        }

        $upc = self::normalize_upc($upc);
        $product_id = max(0, $product_id);
        $now = gmdate('Y-m-d H:i:s');
        $day = gmdate('Y-m-d');

        $wpdb->insert(
            self::click_events_table(),
            [
                'occurred_at_gmt' => $now,
                'day' => $day,
                'product_id' => $product_id,
                'upc' => $upc,
                'raw_click' => 1,
                'deduped_click' => $deduped ? 1 : 0,
                'source' => self::clean_short((string) ($context['source'] ?? 'gundeals'), 32),
                'request_url' => (string) ($context['request_url'] ?? ''),
                'referrer' => (string) ($context['referrer'] ?? ''),
                'user_agent_hash' => self::hash_or_null((string) ($context['user_agent'] ?? '')),
                'ip_hash' => self::hash_or_null((string) ($context['ip'] ?? '')),
                'session_hash' => self::hash_or_null((string) ($context['session'] ?? '')),
            ],
            ['%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . self::click_rollups_table() . '
                    (day, upc, product_id, raw_clicks, deduped_clicks, updated_at_gmt)
                 VALUES (%s, %s, %d, 1, %d, %s)
                 ON DUPLICATE KEY UPDATE
                    raw_clicks = raw_clicks + 1,
                    deduped_clicks = deduped_clicks + VALUES(deduped_clicks),
                    updated_at_gmt = VALUES(updated_at_gmt)',
                $day,
                $upc,
                $product_id,
                $deduped ? 1 : 0,
                $now
            )
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public static function replace_feed_snapshot(string $generated_at_gmt, array $rows): void
    {
        global $wpdb;
        if (!$wpdb) {
            return;
        }

        $generated_at_gmt = self::normalize_datetime($generated_at_gmt);
        if ($generated_at_gmt === '') {
            $generated_at_gmt = gmdate('Y-m-d H:i:s');
        }

        $table = self::feed_snapshots_table();
        $wpdb->delete($table, ['generated_at_gmt' => $generated_at_gmt], ['%s']);

        foreach ($rows as $row) {
            if (empty($row['included'])) {
                continue;
            }

            $upc = self::normalize_upc((string) ($row['upc'] ?? ''));
            if ($upc === '') {
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'generated_at_gmt' => $generated_at_gmt,
                    'product_id' => max(0, (int) ($row['product_id'] ?? 0)),
                    'upc' => $upc,
                    'included' => 1,
                    'stock_status' => self::clean_short((string) ($row['stock_status'] ?? ''), 32),
                    'price' => self::nullable_money($row['price'] ?? null),
                    'price_hide' => self::clean_short((string) ($row['price_hide'] ?? ''), 120),
                    'shipping_info' => self::clean_short((string) ($row['shipping_info'] ?? ''), 120),
                    'shipping_charge' => self::nullable_money($row['shipping_charge'] ?? null),
                    'source' => self::clean_short((string) ($row['source'] ?? ''), 64),
                    'last_stock_update' => self::clean_short((string) ($row['last_stock_update'] ?? ''), 64),
                    'raw_json' => wp_json_encode($row),
                ],
                ['%s', '%d', '%s', '%d', '%s', '%f', '%s', '%s', '%f', '%s', '%s', '%s']
            );
        }
    }

    public static function latest_snapshot_time(): string
    {
        global $wpdb;
        if (!$wpdb) {
            return '';
        }

        return (string) $wpdb->get_var('SELECT MAX(generated_at_gmt) FROM ' . self::feed_snapshots_table());
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function latest_feed_rows(): array
    {
        global $wpdb;
        if (!$wpdb) {
            return [];
        }

        $latest = self::latest_snapshot_time();
        if ($latest === '') {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::feed_snapshots_table() . ' WHERE generated_at_gmt = %s AND included = 1',
                $latest
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public static function normalize_upc(string $upc): string
    {
        $normalized = preg_replace('/\D+/', '', $upc);
        return is_string($normalized) ? trim($normalized) : '';
    }

    private static function normalize_datetime(string $datetime): string
    {
        $datetime = trim($datetime);
        if ($datetime === '') {
            return '';
        }

        $timestamp = strtotime($datetime);
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : '';
    }

    private static function clean_short(string $value, int $max_length): string
    {
        $value = trim(wp_strip_all_tags($value));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max_length);
        }

        return substr($value, 0, $max_length);
    }

    /**
     * @param mixed $value
     */
    private static function nullable_money($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $float = (float) $value;
        return is_finite($float) ? round($float, 2) : null;
    }

    private static function hash_or_null(string $value): ?string
    {
        $value = trim($value);
        return $value !== '' ? hash('sha256', $value) : null;
    }
}
