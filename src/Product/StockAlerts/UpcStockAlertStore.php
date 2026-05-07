<?php

namespace FFLHub\Product\StockAlerts;

use FFLHub\Product\ProductMeta;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

final class UpcStockAlertStore
{
    public const OPTION_ENABLED = 'fflhub_upc_stock_alerts_enabled';
    public const OPTION_RECIPIENTS = 'fflhub_upc_stock_alert_recipients';
    public const OPTION_WATCHLIST = 'fflhub_upc_stock_alert_watchlist';

    private function __construct() {}

    public static function is_enabled(): bool
    {
        return self::truthy(get_option(self::OPTION_ENABLED, '1'));
    }

    public static function set_enabled(bool $enabled): void
    {
        update_option(self::OPTION_ENABLED, $enabled ? '1' : '0', false);
    }

    public static function get_recipients_raw(): string
    {
        $raw = (string) get_option(self::OPTION_RECIPIENTS, '');
        if (trim($raw) !== '') {
            return $raw;
        }

        $fallback = trim((string) Options::get_batch_order_notification_email());
        if ($fallback !== '') {
            return $fallback;
        }

        return (string) get_option('admin_email', '');
    }

    public static function set_recipients_raw(string $raw): void
    {
        $emails = self::split_valid_emails($raw);
        update_option(self::OPTION_RECIPIENTS, implode(', ', $emails), false);
    }

    /**
     * @return string[]
     */
    public static function get_recipient_emails(): array
    {
        $emails = self::split_valid_emails(self::get_recipients_raw());
        if (!empty($emails)) {
            return $emails;
        }

        $fallback = sanitize_email((string) get_option('admin_email', ''));
        return is_email($fallback) ? [$fallback] : [];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function get_watchlist(): array
    {
        $raw = get_option(self::OPTION_WATCHLIST, []);
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $key => $row) {
            $upc = self::normalize_upc((string) ($row['upc'] ?? $key));
            if ($upc === '') {
                continue;
            }
            $rows[$upc] = self::normalize_row($upc, is_array($row) ? $row : []);
        }

        ksort($rows, SORT_STRING);
        return $rows;
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     */
    public static function save_watchlist(array $rows): void
    {
        $clean = [];
        foreach ($rows as $key => $row) {
            $upc = self::normalize_upc((string) ($row['upc'] ?? $key));
            if ($upc === '') {
                continue;
            }
            $clean[$upc] = self::normalize_row($upc, is_array($row) ? $row : []);
        }

        ksort($clean, SORT_STRING);
        update_option(self::OPTION_WATCHLIST, $clean, false);
    }

    /**
     * @return array{added:int,existing:int,invalid:int,upcs:string[]}
     */
    public static function add_upcs_from_text(string $text): array
    {
        $watchlist = self::get_watchlist();
        $upcs = self::extract_upcs($text);
        $added = 0;
        $existing = 0;

        foreach ($upcs as $upc) {
            if (isset($watchlist[$upc])) {
                $watchlist[$upc]['enabled'] = true;
                $existing++;
                continue;
            }

            $watchlist[$upc] = self::new_row($upc);
            $added++;
        }

        self::save_watchlist($watchlist);

        return [
            'added' => $added,
            'existing' => $existing,
            'invalid' => max(0, count(self::raw_tokens($text)) - count($upcs)),
            'upcs' => $upcs,
        ];
    }

    public static function remove_upc(string $upc): bool
    {
        $upc = self::normalize_upc($upc);
        if ($upc === '') {
            return false;
        }

        $watchlist = self::get_watchlist();
        if (!isset($watchlist[$upc])) {
            return false;
        }

        unset($watchlist[$upc]);
        self::save_watchlist($watchlist);
        return true;
    }

    public static function reset_upc(string $upc): bool
    {
        $upc = self::normalize_upc($upc);
        if ($upc === '') {
            return false;
        }

        $watchlist = self::get_watchlist();
        if (!isset($watchlist[$upc])) {
            return false;
        }

        $watchlist[$upc]['last_in_stock'] = false;
        $watchlist[$upc]['last_notified_at'] = '';
        $watchlist[$upc]['last_error'] = '';
        self::save_watchlist($watchlist);
        return true;
    }

    /**
     * @param array<string,mixed> $patch
     */
    public static function update_row(string $upc, array $patch): bool
    {
        $upc = self::normalize_upc($upc);
        if ($upc === '') {
            return false;
        }

        $watchlist = self::get_watchlist();
        if (!isset($watchlist[$upc])) {
            return false;
        }

        $watchlist[$upc] = array_merge($watchlist[$upc], $patch);
        self::save_watchlist($watchlist);
        return true;
    }

    public static function normalize_upc(string $upc): string
    {
        $digits = preg_replace('/\D+/', '', $upc);
        $digits = is_string($digits) ? trim($digits) : '';
        return $digits;
    }

    /**
     * @return array{
     *   product_id:int,
     *   edit_product_id:int,
     *   edit_url:string,
     *   name:string,
     *   stock_quantity:?int,
     *   stock_status:string,
     *   is_in_stock:bool,
     *   price:?float
     * }
     */
    public static function get_product_context_for_upc(string $upc): array
    {
        $upc = self::normalize_upc($upc);
        if ($upc === '' || !function_exists('wc_get_product')) {
            return self::empty_product_context();
        }

        $product_id = self::find_product_id_by_upc($upc);
        if ($product_id <= 0) {
            return self::empty_product_context();
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return self::empty_product_context();
        }

        $edit_product_id = $product_id;
        if (method_exists($product, 'get_parent_id')) {
            $parent_id = (int) $product->get_parent_id();
            if ($parent_id > 0) {
                $edit_product_id = $parent_id;
            }
        }

        $stock_quantity = $product->get_stock_quantity();
        $stock_quantity = is_numeric($stock_quantity) ? max(0, (int) $stock_quantity) : null;
        $stock_status = method_exists($product, 'get_stock_status') ? trim((string) $product->get_stock_status()) : '';
        $price = $product->get_price();
        $price = is_numeric($price) ? max(0.0, (float) $price) : null;
        $name = trim((string) $product->get_name());
        if ($name === '') {
            $name = 'Woo product #' . (string) $product_id;
        }

        return [
            'product_id' => $product_id,
            'edit_product_id' => $edit_product_id,
            'edit_url' => admin_url('post.php?post=' . $edit_product_id . '&action=edit'),
            'name' => $name,
            'stock_quantity' => $stock_quantity,
            'stock_status' => $stock_status,
            'is_in_stock' => (bool) $product->is_in_stock(),
            'price' => $price,
        ];
    }

    /**
     * @return string[]
     */
    private static function extract_upcs(string $text): array
    {
        $out = [];
        foreach (self::raw_tokens($text) as $token) {
            $upc = self::normalize_upc($token);
            if ($upc === '' || strlen($upc) < 8) {
                continue;
            }
            $out[$upc] = $upc;
        }

        return array_values($out);
    }

    /**
     * @return string[]
     */
    private static function raw_tokens(string $text): array
    {
        $parts = preg_split('/[\s,;]+/', $text);
        if (!is_array($parts)) {
            return [];
        }

        $tokens = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $tokens[] = $part;
            }
        }

        return $tokens;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize_row(string $upc, array $row): array
    {
        $created_at = trim((string) ($row['created_at'] ?? ''));
        if ($created_at === '') {
            $created_at = (string) current_time('mysql', true);
        }

        $last_in_stock = $row['last_in_stock'] ?? null;
        if ($last_in_stock !== null) {
            $last_in_stock = self::truthy($last_in_stock);
        }

        $last_quantity = $row['last_quantity'] ?? null;
        $last_quantity = is_numeric($last_quantity) ? max(0, (int) $last_quantity) : null;

        $last_price = $row['last_price'] ?? null;
        $last_price = is_numeric($last_price) ? max(0.0, (float) $last_price) : null;

        return [
            'upc' => $upc,
            'enabled' => self::truthy($row['enabled'] ?? true),
            'created_at' => $created_at,
            'last_checked_at' => trim((string) ($row['last_checked_at'] ?? '')),
            'last_in_stock' => $last_in_stock,
            'last_quantity' => $last_quantity,
            'last_distributors' => trim((string) ($row['last_distributors'] ?? '')),
            'last_product_name' => sanitize_text_field((string) ($row['last_product_name'] ?? '')),
            'last_product_id' => max(0, (int) ($row['last_product_id'] ?? 0)),
            'last_stock_status' => sanitize_text_field((string) ($row['last_stock_status'] ?? '')),
            'last_price' => $last_price,
            'last_notified_at' => trim((string) ($row['last_notified_at'] ?? '')),
            'last_error' => sanitize_text_field((string) ($row['last_error'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function new_row(string $upc): array
    {
        return [
            'upc' => $upc,
            'enabled' => true,
            'created_at' => (string) current_time('mysql', true),
            'last_checked_at' => '',
            'last_in_stock' => null,
            'last_quantity' => null,
            'last_distributors' => '',
            'last_product_name' => '',
            'last_product_id' => 0,
            'last_stock_status' => '',
            'last_price' => null,
            'last_notified_at' => '',
            'last_error' => '',
        ];
    }

    /**
     * @return string[]
     */
    private static function split_valid_emails(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw);
        if (!is_array($parts)) {
            return [];
        }

        $emails = [];
        foreach ($parts as $part) {
            $email = sanitize_email(trim((string) $part));
            if ($email !== '' && is_email($email)) {
                $emails[$email] = $email;
            }
        }

        return array_values($emails);
    }

    private static function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function find_product_id_by_upc(string $upc): int
    {
        global $wpdb;

        $upc = self::normalize_upc($upc);
        if ($upc === '') {
            return 0;
        }

        $meta_keys = [
            ProductMeta::FFLHUB_UPC_META,
            '_global_unique_id',
            '_upc',
            'upc',
            'UPC',
            '_alg_ean',
            '_wpm_gtin_code',
        ];

        foreach ($meta_keys as $meta_key) {
            $sql = $wpdb->prepare(
                "SELECT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                   AND REPLACE(REPLACE(pm.meta_value, ' ', ''), '-', '') = %s
                   AND p.post_type IN ('product', 'product_variation')
                   AND p.post_status IN ('publish', 'private')
                 ORDER BY CASE WHEN p.post_type = 'product' THEN 0 ELSE 1 END ASC, pm.post_id DESC
                 LIMIT 1",
                $meta_key,
                $upc
            );
            $found = (int) $wpdb->get_var($sql);
            if ($found > 0) {
                return $found;
            }
        }

        return 0;
    }

    /**
     * @return array{product_id:int,edit_product_id:int,edit_url:string,name:string,stock_quantity:?int,stock_status:string,is_in_stock:bool,price:?float}
     */
    private static function empty_product_context(): array
    {
        return [
            'product_id' => 0,
            'edit_product_id' => 0,
            'edit_url' => '',
            'name' => '',
            'stock_quantity' => null,
            'stock_status' => '',
            'is_in_stock' => false,
            'price' => null,
        ];
    }
}
