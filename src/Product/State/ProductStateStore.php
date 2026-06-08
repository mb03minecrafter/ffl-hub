<?php
declare(strict_types=1);

namespace FFLHub\Product\State;

use FFLHub\Product\ProductMeta;

if (!defined('ABSPATH')) {
    exit;
}

final class ProductStateStore
{
    private const SCHEMA_OPTION = 'fflhub_product_state_schema_version';
    private const SCHEMA_VERSION = '1';
    private const TABLE_SUFFIX = 'fflhub_product_state';
    private const DEFAULT_BATCH_SIZE = 500;

    public static function table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . self::TABLE_SUFFIX);
    }

    public static function ensure_schema(): void
    {
        global $wpdb;

        if (!$wpdb) {
            return;
        }

        $installed = (string) get_option(self::SCHEMA_OPTION, '');
        if ($installed === self::SCHEMA_VERSION && self::table_exists()) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = (string) $wpdb->get_charset_collate();

        dbDelta("
            CREATE TABLE {$table} (
                product_id BIGINT UNSIGNED NOT NULL,
                upc VARCHAR(32) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                primary_distributor VARCHAR(64) DEFAULT NULL,
                last_sync_at DATETIME DEFAULT NULL,
                last_true_cost DECIMAL(12,4) DEFAULT NULL,
                last_dealer_price DECIMAL(12,4) DEFAULT NULL,
                last_shipping_cost DECIMAL(12,4) DEFAULT NULL,
                last_map DECIMAL(12,4) DEFAULT NULL,
                last_msrp DECIMAL(12,4) DEFAULT NULL,
                last_computed_price DECIMAL(12,4) DEFAULT NULL,
                markup_mode VARCHAR(32) DEFAULT NULL,
                markup_percent DECIMAL(8,4) DEFAULT NULL,
                fixed_price DECIMAL(12,4) DEFAULT NULL,
                map_policy VARCHAR(32) DEFAULT NULL,
                map_real_price_mode VARCHAR(32) DEFAULT NULL,
                map_real_price_offset DECIMAL(12,4) DEFAULT NULL,
                map_real_price_percent DECIMAL(8,4) DEFAULT NULL,
                map_real_price_fixed_profit DECIMAL(12,4) DEFAULT NULL,
                map_real_price_free_shipping_override TINYINT(1) NOT NULL DEFAULT 0,
                ffl_required TINYINT(1) NOT NULL DEFAULT 0,
                sot_required TINYINT(1) NOT NULL DEFAULT 0,
                dropship_enabled TINYINT(1) NOT NULL DEFAULT 1,
                shipping_weight_oz DECIMAL(10,3) DEFAULT NULL,
                shipping_length_in DECIMAL(10,3) DEFAULT NULL,
                shipping_width_in DECIMAL(10,3) DEFAULT NULL,
                shipping_height_in DECIMAL(10,3) DEFAULT NULL,
                manual_shipping_override TINYINT(1) NOT NULL DEFAULT 0,
                stock_oos_override TINYINT(1) NOT NULL DEFAULT 0,
                local_stock_override_qty INT UNSIGNED DEFAULT NULL,
                local_stock_free_shipping TINYINT(1) NOT NULL DEFAULT 0,
                allowed_distributors_json LONGTEXT DEFAULT NULL,
                bom_total_cost DECIMAL(12,4) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (product_id),
                UNIQUE KEY upc (upc),
                KEY status_upc (status, upc),
                KEY primary_distributor (primary_distributor),
                KEY last_sync_at (last_sync_at)
            ) {$charset};
        ");

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
    }

    public static function table_exists(): bool
    {
        global $wpdb;

        if (!$wpdb) {
            return false;
        }

        $table = self::table_name();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($found) && $found === $table;
    }

    /**
     * @return array<string,mixed>
     */
    public static function backfill_from_product_meta(int $batch_size = self::DEFAULT_BATCH_SIZE): array
    {
        global $wpdb;

        self::ensure_schema();

        $batch_size = max(50, min(1000, $batch_size));
        $last_id = 0;
        $stats = [
            'scanned' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped_missing_upc' => 0,
            'skipped_not_managed' => 0,
            'errors' => 0,
            'messages' => [],
        ];

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        do {
            $ids = self::get_product_ids_batch($last_id, $batch_size);
            if (empty($ids)) {
                break;
            }

            update_meta_cache('post', $ids);

            foreach ($ids as $product_id) {
                $last_id = max($last_id, (int) $product_id);
                $stats['scanned']++;

                if (!self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_MANAGED_META, true))) {
                    $stats['skipped_not_managed']++;
                    continue;
                }

                $upc = self::text(get_post_meta($product_id, ProductMeta::FFLHUB_UPC_META, true), 32) ?? '';
                if ($upc === '') {
                    $stats['skipped_missing_upc']++;
                    continue;
                }

                $row = self::row_from_product_meta($product_id, $upc);
                $result = self::upsert_row($row);
                if ($result === 'inserted') {
                    $stats['inserted']++;
                } elseif ($result === 'updated') {
                    $stats['updated']++;
                } else {
                    $stats['errors']++;
                    if (count($stats['messages']) < 20) {
                        $stats['messages'][] = $result;
                    }
                }
            }
        } while (count($ids) === $batch_size);

        return $stats;
    }

    /**
     * @return int[]
     */
    private static function get_product_ids_batch(int $last_id, int $limit): array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            $last_id,
            $limit
        );

        return array_map('intval', (array) $wpdb->get_col($sql));
    }

    /**
     * @return array<string,mixed>
     */
    private static function row_from_product_meta(int $product_id, string $upc): array
    {
        $local_stock_enabled = self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_ENABLED_META, true));
        $bom_enabled = self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_BOM_ENABLED_META, true));

        return [
            'product_id' => $product_id,
            'upc' => $upc,
            'status' => 'active',
            'primary_distributor' => self::text(get_post_meta($product_id, ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true), 64),
            'last_sync_at' => self::datetime_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, true)),
            'last_true_cost' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_TRUE_COST_META, true), 4),
            'last_dealer_price' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, true), 4),
            'last_shipping_cost' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, true), 4),
            'last_map' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_MAP_META, true), 4),
            'last_msrp' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_MSRP_META, true), 4),
            'last_computed_price' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META, true), 4),
            'markup_mode' => self::text(get_post_meta($product_id, ProductMeta::FFLHUB_MARKUP_MODE_META, true), 32),
            'markup_percent' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_MARKUP_PERCENT_META, true), 4),
            'fixed_price' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_FIXED_PRICE_META, true), 4),
            'map_policy' => self::text(get_post_meta($product_id, ProductMeta::FFLHUB_MAP_POLICY_META, true), 32),
            'map_real_price_mode' => self::text(get_post_meta($product_id, ProductMeta::FFLHUB_MAP_REAL_PRICE_MODE_META, true), 32),
            'map_real_price_offset' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_MAP_REAL_PRICE_OFFSET_META, true), 4),
            'map_real_price_percent' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_MAP_REAL_PRICE_PERCENT_META, true), 4),
            'map_real_price_fixed_profit' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_MAP_REAL_PRICE_FIXED_PROFIT_META, true), 4),
            'map_real_price_free_shipping_override' => self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_MAP_REAL_PRICE_FREE_SHIPPING_OVERRIDE_META, true)) ? 1 : 0,
            'ffl_required' => self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_FFL_REQUIRED_META, true)) ? 1 : 0,
            'sot_required' => self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_SOT_REQUIRED_META, true)) ? 1 : 0,
            'dropship_enabled' => self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_DROPSHIP_ENABLED_META, true), true) ? 1 : 0,
            'shipping_weight_oz' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_SHIPPING_WEIGHT_META, true), 3),
            'shipping_length_in' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_SHIPPING_LENGTH_IN_META, true), 3),
            'shipping_width_in' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_SHIPPING_WIDTH_IN_META, true), 3),
            'shipping_height_in' => self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_SHIPPING_HEIGHT_IN_META, true), 3),
            'manual_shipping_override' => self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_MANUAL_SHIPPING_OVERRIDE_META, true)) ? 1 : 0,
            'stock_oos_override' => self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_STOCK_OOS_OVERRIDE_META, true)) ? 1 : 0,
            'local_stock_override_qty' => $local_stock_enabled ? self::int_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_QTY_META, true)) : null,
            'local_stock_free_shipping' => ($local_stock_enabled && self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_LOCAL_STOCK_FREE_SHIPPING_META, true))) ? 1 : 0,
            'allowed_distributors_json' => self::allowed_distributors_json(
                get_post_meta($product_id, ProductMeta::FFLHUB_DISTRIBUTOR_LOCK_ENABLED_META, true),
                get_post_meta($product_id, ProductMeta::FFLHUB_DISTRIBUTOR_LOCK_IDS_META, true)
            ),
            'bom_total_cost' => $bom_enabled ? self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_BOM_TOTAL_COST_META, true), 4) : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function upsert_row(array $row): string
    {
        global $wpdb;

        $table = self::table_name();
        $product_id = (int) $row['product_id'];
        $upc = (string) $row['upc'];
        $now = current_time('mysql');

        $existing_by_upc = $wpdb->get_var($wpdb->prepare(
            "SELECT product_id FROM {$table} WHERE upc = %s LIMIT 1",
            $upc
        ));
        if ($existing_by_upc !== null && (int) $existing_by_upc !== $product_id) {
            return "UPC {$upc} already belongs to product #{$existing_by_upc}; skipped product #{$product_id}.";
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT product_id FROM {$table} WHERE product_id = %d LIMIT 1",
            $product_id
        ));

        if ($exists === null) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            $ok = $wpdb->insert($table, $row, self::formats_for_row($row));
            return ($ok === false) ? "Insert failed for product #{$product_id}: {$wpdb->last_error}" : 'inserted';
        }

        $row['updated_at'] = $now;
        $ok = $wpdb->update(
            $table,
            $row,
            ['product_id' => $product_id],
            self::formats_for_row($row),
            ['%d']
        );

        return ($ok === false) ? "Update failed for product #{$product_id}: {$wpdb->last_error}" : 'updated';
    }

    /**
     * @param array<string,mixed> $row
     * @return string[]
     */
    private static function formats_for_row(array $row): array
    {
        $formats = [
            'product_id' => '%d',
            'upc' => '%s',
            'status' => '%s',
            'primary_distributor' => '%s',
            'last_sync_at' => '%s',
            'last_true_cost' => '%f',
            'last_dealer_price' => '%f',
            'last_shipping_cost' => '%f',
            'last_map' => '%f',
            'last_msrp' => '%f',
            'last_computed_price' => '%f',
            'markup_mode' => '%s',
            'markup_percent' => '%f',
            'fixed_price' => '%f',
            'map_policy' => '%s',
            'map_real_price_mode' => '%s',
            'map_real_price_offset' => '%f',
            'map_real_price_percent' => '%f',
            'map_real_price_fixed_profit' => '%f',
            'map_real_price_free_shipping_override' => '%d',
            'ffl_required' => '%d',
            'sot_required' => '%d',
            'dropship_enabled' => '%d',
            'shipping_weight_oz' => '%f',
            'shipping_length_in' => '%f',
            'shipping_width_in' => '%f',
            'shipping_height_in' => '%f',
            'manual_shipping_override' => '%d',
            'stock_oos_override' => '%d',
            'local_stock_override_qty' => '%d',
            'local_stock_free_shipping' => '%d',
            'allowed_distributors_json' => '%s',
            'bom_total_cost' => '%f',
            'created_at' => '%s',
            'updated_at' => '%s',
        ];

        return array_map(
            static fn(string $column): string => $formats[$column] ?? '%s',
            array_keys($row)
        );
    }

    private static function truthy($value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((float) $value) > 0.0;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, ['1', 'yes', 'true', 'on', 'enabled'], true);
    }

    private static function text($value, int $max_length): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return substr($value, 0, $max_length);
    }

    private static function decimal_or_null($value, int $scale): ?string
    {
        $value = preg_replace('/[^0-9.\-]/', '', trim((string) $value));
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        if (!is_finite($number)) {
            return null;
        }

        return number_format($number, $scale, '.', '');
    }

    private static function int_or_null($value): ?int
    {
        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    private static function datetime_or_null($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private static function allowed_distributors_json($lock_enabled, $lock_ids): ?string
    {
        if (!self::truthy($lock_enabled)) {
            return null;
        }

        $ids = self::normalize_distributor_ids($lock_ids);
        if (empty($ids)) {
            return null;
        }

        $json = wp_json_encode($ids);

        return is_string($json) ? $json : null;
    }

    /**
     * @return string[]
     */
    private static function normalize_distributor_ids($raw): array
    {
        if (is_string($raw)) {
            $maybe = maybe_unserialize($raw);
            if ($maybe !== $raw) {
                $raw = $maybe;
            } else {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $raw = $json;
                } else {
                    $raw = preg_split('/[\s,]+/', $raw);
                }
            }
        }

        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = sanitize_key((string) $value);
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
