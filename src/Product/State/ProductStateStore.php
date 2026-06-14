<?php
declare(strict_types=1);

namespace FFLHub\Product\State;

use FFLHub\Product\ProductMeta;
use FFLHub\Settings\Options;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

final class ProductStateStore
{
    private const SCHEMA_OPTION = 'fflhub_product_state_schema_version';
    private const SCHEMA_VERSION = '8';
    private const TABLE_SUFFIX = 'fflhub_product_state';
    private const DEFAULT_BATCH_SIZE = 500;

    /** @var array<int,array<string,mixed>|null> */
    private static array $row_cache_by_product_id = [];

    /** @var array<string,array<string,mixed>|null> */
    private static array $row_cache_by_upc = [];

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
                distributor_id VARCHAR(64) DEFAULT NULL,
                distributor_product_id VARCHAR(128) DEFAULT NULL,
                distributor_sku VARCHAR(128) DEFAULT NULL,
                manufacturer_norm VARCHAR(191) DEFAULT NULL,
                qty INT UNSIGNED NOT NULL DEFAULT 0,
                stock_status VARCHAR(32) DEFAULT NULL,
                dealer_price DECIMAL(12,4) DEFAULT NULL,
                shipping_cost DECIMAL(12,4) DEFAULT NULL,
                landed_cost DECIMAL(12,4) DEFAULT NULL,
                map_price DECIMAL(12,4) DEFAULT NULL,
                msrp DECIMAL(12,4) DEFAULT NULL,
                ffl_required TINYINT(1) NOT NULL DEFAULT 0,
                sot_required TINYINT(1) NOT NULL DEFAULT 0,
                dropship_enabled TINYINT(1) NOT NULL DEFAULT 1,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                shipping_weight_oz DECIMAL(10,3) DEFAULT NULL,
                shipping_length_in DECIMAL(10,3) DEFAULT NULL,
                shipping_width_in DECIMAL(10,3) DEFAULT NULL,
                shipping_height_in DECIMAL(10,3) DEFAULT NULL,
                source_updated_at DATETIME DEFAULT NULL,
                source_offer_normalized_at DATETIME DEFAULT NULL,
                selection_status VARCHAR(32) NOT NULL DEFAULT 'no_offer',
                selected_at DATETIME DEFAULT NULL,
                woo_synced_at DATETIME DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                pricing_mode VARCHAR(32) DEFAULT NULL,
                pricing_percent DECIMAL(8,4) DEFAULT NULL,
                pricing_fixed_price DECIMAL(12,4) DEFAULT NULL,
                pricing_fixed_profit DECIMAL(12,4) DEFAULT NULL,
                map_visibility_policy VARCHAR(32) DEFAULT NULL,
                quote_free_shipping_override TINYINT(1) NOT NULL DEFAULT 0,
                computed_sell_price DECIMAL(12,4) DEFAULT NULL,
                map_applicable TINYINT(1) NOT NULL DEFAULT 0,
                public_regular_price DECIMAL(12,4) DEFAULT NULL,
                public_sale_price DECIMAL(12,4) DEFAULT NULL,
                manual_shipping_override TINYINT(1) NOT NULL DEFAULT 0,
                stock_oos_override TINYINT(1) NOT NULL DEFAULT 0,
                local_stock_override_qty INT UNSIGNED DEFAULT NULL,
                local_stock_free_shipping TINYINT(1) NOT NULL DEFAULT 0,
                allowed_distributors_json LONGTEXT DEFAULT NULL,
                bom_total_cost DECIMAL(12,4) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                has_changed TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY  (product_id),
                UNIQUE KEY upc (upc),
                KEY status_upc (status, upc),
                KEY status_product_id (status, product_id),
                KEY has_changed (has_changed, product_id),
                KEY distributor_id (distributor_id),
                KEY selection_status (selection_status),
                KEY selected_at (selected_at),
                KEY woo_synced_at (woo_synced_at)
            ) {$charset};
        ");

        self::ensure_columns($table);
        self::drop_legacy_columns($table);
        self::ensure_column_order($table);
        self::ensure_indexes($table);

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
    }

    private static function ensure_indexes(string $table): void
    {
        global $wpdb;

        $rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if (!is_array($rows)) {
            return;
        }

        $keys = [];
        foreach ($rows as $row) {
            $key = isset($row['Key_name']) ? (string) $row['Key_name'] : '';
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        $missing_indexes = [
            'status_upc' => 'ADD KEY status_upc (status, upc)',
            'status_product_id' => 'ADD KEY status_product_id (status, product_id)',
            'has_changed' => 'ADD KEY has_changed (has_changed, product_id)',
            'distributor_id' => 'ADD KEY distributor_id (distributor_id)',
            'selection_status' => 'ADD KEY selection_status (selection_status)',
            'selected_at' => 'ADD KEY selected_at (selected_at)',
            'woo_synced_at' => 'ADD KEY woo_synced_at (woo_synced_at)',
        ];

        foreach ($missing_indexes as $name => $definition) {
            if (isset($keys[$name])) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} {$definition}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    private static function ensure_columns(string $table): void
    {
        global $wpdb;

        foreach (self::ordered_column_definitions() as $column => $definition) {
            if (self::table_has_column($table, $column)) {
                continue;
            }

            $after = self::column_after($column);
            $placement = ($after === null) ? ' FIRST' : " AFTER {$after}";
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$definition}{$placement}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    private static function drop_legacy_columns(string $table): void
    {
        global $wpdb;

        foreach (['status_last_sync_at', 'last_sync_at', 'primary_distributor'] as $index) {
            if (!self::table_has_index($table, $index)) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} DROP INDEX {$index}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        foreach (self::legacy_column_names() as $column) {
            if (!self::table_has_column($table, $column)) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} DROP COLUMN {$column}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    private static function ensure_column_order(string $table): void
    {
        global $wpdb;

        $previous = null;
        foreach (self::ordered_column_definitions() as $column => $definition) {
            if (!self::table_has_column($table, $column)) {
                continue;
            }

            $placement = ($previous === null) ? ' FIRST' : " AFTER {$previous}";
            $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN {$definition}{$placement}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $previous = $column;
        }
    }

    private static function table_has_column(string $table, string $column): bool
    {
        global $wpdb;

        if ($table === '' || $column === '') {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_string($found) && $found === $column;
    }

    private static function table_has_index(string $table, string $index): bool
    {
        global $wpdb;

        if ($table === '' || $index === '') {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return $found !== null;
    }

    private static function column_after(string $column): ?string
    {
        $previous = null;
        foreach (array_keys(self::ordered_column_definitions()) as $current) {
            if ($current === $column) {
                return $previous;
            }

            $previous = $current;
        }

        return null;
    }

    /**
     * Product state starts with the selected best-offer snapshot, then keeps
     * the product-specific policy/meta mirror fields that do not belong to a
     * distributor offer.
     *
     * @return array<string,string>
     */
    private static function ordered_column_definitions(): array
    {
        return [
            'product_id' => 'product_id BIGINT UNSIGNED NOT NULL',
            'upc' => 'upc VARCHAR(32) NOT NULL',
            'distributor_id' => 'distributor_id VARCHAR(64) DEFAULT NULL',
            'distributor_product_id' => 'distributor_product_id VARCHAR(128) DEFAULT NULL',
            'distributor_sku' => 'distributor_sku VARCHAR(128) DEFAULT NULL',
            'manufacturer_norm' => 'manufacturer_norm VARCHAR(191) DEFAULT NULL',
            'qty' => 'qty INT UNSIGNED NOT NULL DEFAULT 0',
            'stock_status' => 'stock_status VARCHAR(32) DEFAULT NULL',
            'dealer_price' => 'dealer_price DECIMAL(12,4) DEFAULT NULL',
            'shipping_cost' => 'shipping_cost DECIMAL(12,4) DEFAULT NULL',
            'landed_cost' => 'landed_cost DECIMAL(12,4) DEFAULT NULL',
            'map_price' => 'map_price DECIMAL(12,4) DEFAULT NULL',
            'msrp' => 'msrp DECIMAL(12,4) DEFAULT NULL',
            'ffl_required' => 'ffl_required TINYINT(1) NOT NULL DEFAULT 0',
            'sot_required' => 'sot_required TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_enabled' => 'dropship_enabled TINYINT(1) NOT NULL DEFAULT 1',
            'enabled' => 'enabled TINYINT(1) NOT NULL DEFAULT 0',
            'shipping_weight_oz' => 'shipping_weight_oz DECIMAL(10,3) DEFAULT NULL',
            'shipping_length_in' => 'shipping_length_in DECIMAL(10,3) DEFAULT NULL',
            'shipping_width_in' => 'shipping_width_in DECIMAL(10,3) DEFAULT NULL',
            'shipping_height_in' => 'shipping_height_in DECIMAL(10,3) DEFAULT NULL',
            'source_updated_at' => 'source_updated_at DATETIME DEFAULT NULL',
            'source_offer_normalized_at' => 'source_offer_normalized_at DATETIME DEFAULT NULL',
            'selection_status' => "selection_status VARCHAR(32) NOT NULL DEFAULT 'no_offer'",
            'selected_at' => 'selected_at DATETIME DEFAULT NULL',
            'woo_synced_at' => 'woo_synced_at DATETIME DEFAULT NULL',
            'status' => "status VARCHAR(20) NOT NULL DEFAULT 'active'",
            'pricing_mode' => 'pricing_mode VARCHAR(32) DEFAULT NULL',
            'pricing_percent' => 'pricing_percent DECIMAL(8,4) DEFAULT NULL',
            'pricing_fixed_price' => 'pricing_fixed_price DECIMAL(12,4) DEFAULT NULL',
            'pricing_fixed_profit' => 'pricing_fixed_profit DECIMAL(12,4) DEFAULT NULL',
            'map_visibility_policy' => 'map_visibility_policy VARCHAR(32) DEFAULT NULL',
            'quote_free_shipping_override' => 'quote_free_shipping_override TINYINT(1) NOT NULL DEFAULT 0',
            'computed_sell_price' => 'computed_sell_price DECIMAL(12,4) DEFAULT NULL',
            'map_applicable' => 'map_applicable TINYINT(1) NOT NULL DEFAULT 0',
            'public_regular_price' => 'public_regular_price DECIMAL(12,4) DEFAULT NULL',
            'public_sale_price' => 'public_sale_price DECIMAL(12,4) DEFAULT NULL',
            'manual_shipping_override' => 'manual_shipping_override TINYINT(1) NOT NULL DEFAULT 0',
            'stock_oos_override' => 'stock_oos_override TINYINT(1) NOT NULL DEFAULT 0',
            'local_stock_override_qty' => 'local_stock_override_qty INT UNSIGNED DEFAULT NULL',
            'local_stock_free_shipping' => 'local_stock_free_shipping TINYINT(1) NOT NULL DEFAULT 0',
            'allowed_distributors_json' => 'allowed_distributors_json LONGTEXT DEFAULT NULL',
            'bom_total_cost' => 'bom_total_cost DECIMAL(12,4) DEFAULT NULL',
            'created_at' => 'created_at DATETIME NOT NULL',
            'updated_at' => 'updated_at DATETIME NOT NULL',
            'has_changed' => 'has_changed TINYINT(1) NOT NULL DEFAULT 0',
        ];
    }

    /**
     * @return string[]
     */
    private static function legacy_column_names(): array
    {
        return [
            'last_sync_at',
            'primary_distributor',
            'last_true_cost',
            'last_dealer_price',
            'last_shipping_cost',
            'last_map',
            'last_msrp',
            'last_computed_price',
            'markup_mode',
            'markup_percent',
            'fixed_price',
            'map_policy',
            'map_real_price_mode',
            'map_real_price_offset',
            'map_real_price_percent',
            'map_real_price_fixed_profit',
            'map_real_price_free_shipping_override',
            'quote_price',
            'public_active_price',
        ];
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
     * @return array<string,mixed>|null
     */
    private static function get_row_for_product_id(int $product_id): ?array
    {
        global $wpdb;

        if ($product_id <= 0 || !$wpdb) {
            return null;
        }

        if (array_key_exists($product_id, self::$row_cache_by_product_id)) {
            return self::$row_cache_by_product_id[$product_id];
        }

        $table = self::table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE product_id = %d LIMIT 1", $product_id), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );

        self::$row_cache_by_product_id[$product_id] = is_array($row) ? $row : null;
        self::seed_upc_cache_from_row(self::$row_cache_by_product_id[$product_id]);

        return self::$row_cache_by_product_id[$product_id];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get_row_for_upc(string $upc): ?array
    {
        global $wpdb;

        $upc = self::normalize_upc($upc);
        if ($upc === '' || !$wpdb) {
            return null;
        }

        if (array_key_exists($upc, self::$row_cache_by_upc)) {
            return self::$row_cache_by_upc[$upc];
        }

        $table = self::table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE upc = %s LIMIT 1", $upc), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );

        self::$row_cache_by_upc[$upc] = is_array($row) ? $row : null;
        self::seed_product_cache_from_row(self::$row_cache_by_upc[$upc]);

        return self::$row_cache_by_upc[$upc];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get_row_for_product(WC_Product $product): ?array
    {
        $product_id = (int) $product->get_id();
        if ($product_id <= 0) {
            return null;
        }

        $row = self::get_row_for_product_id($product_id);
        if (is_array($row)) {
            return $row;
        }

        $parent_id = (int) $product->get_parent_id();
        if ($parent_id <= 0 || $parent_id === $product_id) {
            return null;
        }

        return self::get_row_for_product_id($parent_id);
    }

    public static function is_active_product(int $product_id): bool
    {
        return self::get_status_for_product($product_id) === 'active';
    }

    public static function get_upc_for_product(int $product_id): ?string
    {
        return self::string_column_for_product($product_id, 'upc');
    }

    public static function get_status_for_product(int $product_id): ?string
    {
        return self::string_column_for_product($product_id, 'status');
    }

    public static function get_stock_status_for_product(int $product_id): ?string
    {
        return self::string_column_for_product($product_id, 'stock_status');
    }

    public static function get_qty_for_product(int $product_id): ?int
    {
        return self::int_column_for_product($product_id, 'qty');
    }

    public static function get_map_visibility_policy_for_product(int $product_id): ?string
    {
        return self::string_column_for_product($product_id, 'map_visibility_policy');
    }

    public static function get_map_applicable_for_product(int $product_id): bool
    {
        return self::bool_column_for_product($product_id, 'map_applicable');
    }

    public static function get_map_price_for_product(int $product_id): ?float
    {
        return self::float_column_for_product($product_id, 'map_price');
    }

    public static function get_computed_sell_price_for_product(int $product_id): ?float
    {
        return self::float_column_for_product($product_id, 'computed_sell_price');
    }

    public static function get_public_regular_price_for_product(int $product_id): ?float
    {
        return self::float_column_for_product($product_id, 'public_regular_price');
    }

    public static function get_public_sale_price_for_product(int $product_id): ?float
    {
        return self::float_column_for_product($product_id, 'public_sale_price');
    }

    public static function get_stock_oos_override_for_product(int $product_id): bool
    {
        return self::bool_column_for_product($product_id, 'stock_oos_override');
    }

    public static function get_local_stock_override_qty_for_product(int $product_id): ?int
    {
        return self::int_column_for_product($product_id, 'local_stock_override_qty');
    }

    public static function clear_product_cache(int $product_id): void
    {
        if ($product_id <= 0) {
            return;
        }

        $row = self::$row_cache_by_product_id[$product_id] ?? null;
        if (is_array($row)) {
            $upc = self::normalize_upc((string) ($row['upc'] ?? ''));
            if ($upc !== '') {
                unset(self::$row_cache_by_upc[$upc]);
            }
        }

        unset(self::$row_cache_by_product_id[$product_id]);
    }

    private static function string_column_for_product(int $product_id, string $column): ?string
    {
        $row = self::get_row_for_product_id($product_id);
        if (!is_array($row) || !array_key_exists($column, $row)) {
            return null;
        }

        $value = trim((string) $row[$column]);
        return ($value === '') ? null : $value;
    }

    private static function int_column_for_product(int $product_id, string $column): ?int
    {
        $row = self::get_row_for_product_id($product_id);
        if (!is_array($row) || !array_key_exists($column, $row)) {
            return null;
        }

        $value = $row[$column];
        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private static function float_column_for_product(int $product_id, string $column): ?float
    {
        $row = self::get_row_for_product_id($product_id);
        if (!is_array($row) || !array_key_exists($column, $row)) {
            return null;
        }

        $value = $row[$column];
        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private static function bool_column_for_product(int $product_id, string $column): bool
    {
        return (int) (self::int_column_for_product($product_id, $column) ?? 0) === 1;
    }

    private static function normalize_upc(string $upc): string
    {
        $upc = preg_replace('/\D+/', '', trim($upc));

        return is_string($upc) ? $upc : '';
    }

    /**
     * @param array<string,mixed>|null $row
     */
    private static function seed_product_cache_from_row(?array $row): void
    {
        if (!is_array($row)) {
            return;
        }

        $product_id = (int) ($row['product_id'] ?? 0);
        if ($product_id > 0) {
            self::$row_cache_by_product_id[$product_id] = $row;
        }
    }

    /**
     * @param array<string,mixed>|null $row
     */
    private static function seed_upc_cache_from_row(?array $row): void
    {
        if (!is_array($row)) {
            return;
        }

        $upc = self::normalize_upc((string) ($row['upc'] ?? ''));
        if ($upc !== '') {
            self::$row_cache_by_upc[$upc] = $row;
        }
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
     * Save the product_state controls owned by the admin product editor.
     *
     * This deliberately edits only product_state. It does not write old product
     * meta and does not touch Woo prices/stock. Calculated product_state outputs
     * are refreshed from the newly saved controls so the row remains internally
     * consistent for the future product_state-to-Woo writer.
     *
     * @param array<string,mixed> $raw
     * @return array{ok:bool,updated:int,changed:bool,message:string}
     */
    public static function update_admin_controls(int $product_id, array $raw): array
    {
        global $wpdb;

        self::ensure_schema();

        $table = self::table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE product_id = %d LIMIT 1", $product_id), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );

        if (!is_array($row)) {
            return [
                'ok' => false,
                'updated' => 0,
                'changed' => false,
                'message' => "No product_state row exists for product #{$product_id}.",
            ];
        }

        $pricing_mode = self::admin_pricing_mode($raw['pricing_mode'] ?? '');
        $pricing_percent = null;
        if ($pricing_mode === 'global_percent') {
            $pricing_percent = max(0.0, (float) Options::get_global_markup());
        } elseif ($pricing_mode === 'fixed_percent') {
            $pricing_percent = max(0.0, self::float_or_null($raw['pricing_percent'] ?? null) ?? 0.0);
        }

        $fixed_price = ($pricing_mode === 'fixed_price')
            ? self::float_or_null($raw['pricing_fixed_price'] ?? null)
            : null;
        $fixed_profit = ($pricing_mode === 'fixed_profit')
            ? max(0.0, self::float_or_null($raw['pricing_fixed_profit'] ?? null) ?? 0.0)
            : null;

        $map_applicable = self::float_or_null($row['map_price'] ?? null) !== null
            && (float) $row['map_price'] > 0.0;
        $visibility_policy = self::admin_map_visibility_policy($raw['map_visibility_policy'] ?? '', $map_applicable);

        $computed_sell_price = self::computed_sell_price(
            $pricing_mode,
            $pricing_percent,
            $fixed_price,
            $fixed_profit,
            self::nullable_string($row['dealer_price'] ?? null),
            self::nullable_string($row['shipping_cost'] ?? null),
            self::nullable_string($row['landed_cost'] ?? null),
            self::nullable_string($row['map_price'] ?? null),
            $row['computed_sell_price'] ?? null,
            get_post_meta($product_id, '_regular_price', true),
            get_post_meta($product_id, '_price', true)
        );

        $public_prices = self::public_price_fields(
            $computed_sell_price,
            self::nullable_string($row['map_price'] ?? null),
            self::nullable_string($row['msrp'] ?? null),
            $visibility_policy,
            $map_applicable,
            get_post_meta($product_id, '_regular_price', true),
            get_post_meta($product_id, '_sale_price', true)
        );

        $allowed_distributors_enabled = !empty($raw['allowed_distributors_enabled']);
        $allowed_distributors_json = $allowed_distributors_enabled
            ? self::allowed_distributors_json(true, $raw['allowed_distributors'] ?? [])
            : null;

        $status = strtolower(trim((string) ($raw['status'] ?? 'active')));
        if (!in_array($status, ['active', 'ignored'], true)) {
            $status = 'active';
        }

        $updates = [
            'status' => $status,
            'pricing_mode' => $pricing_mode,
            'pricing_percent' => self::money_or_null($pricing_percent, 4),
            'pricing_fixed_price' => self::money_or_null($fixed_price, 4),
            'pricing_fixed_profit' => self::money_or_null($fixed_profit, 4),
            'map_visibility_policy' => $visibility_policy,
            'quote_free_shipping_override' => !empty($raw['quote_free_shipping_override']) ? 1 : 0,
            'computed_sell_price' => self::money_or_null($computed_sell_price, 4),
            'map_applicable' => $map_applicable ? 1 : 0,
            'public_regular_price' => self::money_or_null($public_prices['regular'], 4),
            'public_sale_price' => self::money_or_null($public_prices['sale'], 4),
            'manual_shipping_override' => !empty($raw['manual_shipping_override']) ? 1 : 0,
            'stock_oos_override' => !empty($raw['stock_oos_override']) ? 1 : 0,
            'local_stock_override_qty' => self::admin_nullable_absint($raw['local_stock_override_qty'] ?? null),
            'local_stock_free_shipping' => !empty($raw['local_stock_free_shipping']) ? 1 : 0,
            'allowed_distributors_json' => $allowed_distributors_json,
        ];

        $changed = false;
        foreach ($updates as $column => $value) {
            if (self::admin_value_changed($row[$column] ?? null, $value)) {
                $changed = true;
                break;
            }
        }

        if (!$changed) {
            return [
                'ok' => true,
                'updated' => 0,
                'changed' => false,
                'message' => 'No product_state changes detected.',
            ];
        }

        $updates['updated_at'] = current_time('mysql');
        $updates['has_changed'] = 1;

        $updated = $wpdb->update(
            $table,
            $updates,
            ['product_id' => $product_id],
            self::formats_for_row($updates),
            ['%d']
        );

        if ($updated === false) {
            return [
                'ok' => false,
                'updated' => 0,
                'changed' => true,
                'message' => 'Product_state update failed: ' . (string) $wpdb->last_error,
            ];
        }

        self::clear_product_cache($product_id);

        return [
            'ok' => true,
            'updated' => (int) $updated,
            'changed' => true,
            'message' => 'Product_state controls saved.',
        ];
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
        $source_distributor = self::text(get_post_meta($product_id, ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true), 64);
        $dealer_price = self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, true), 4);
        $shipping_cost = self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, true), 4);
        $landed_cost = self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_TRUE_COST_META, true), 4);
        $map_price = self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_MAP_META, true), 4);
        $msrp = self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_MSRP_META, true), 4);
        $ffl_required = self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_FFL_REQUIRED_META, true)) ? 1 : 0;
        $sot_required = self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_SOT_REQUIRED_META, true)) ? 1 : 0;
        $dropship_enabled = self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_DROPSHIP_ENABLED_META, true), true) ? 1 : 0;
        $shipping_weight_oz = self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_SHIPPING_WEIGHT_META, true), 3);
        $shipping_length_in = self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_SHIPPING_LENGTH_IN_META, true), 3);
        $shipping_width_in = self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_SHIPPING_WIDTH_IN_META, true), 3);
        $shipping_height_in = self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_SHIPPING_HEIGHT_IN_META, true), 3);
        $stock_qty = self::int_or_null(get_post_meta($product_id, '_stock', true));
        $stock_status = self::text(get_post_meta($product_id, '_stock_status', true), 32);
        $pricing = self::pricing_state_from_product_meta(
            get_post_meta($product_id, ProductMeta::FFLHUB_MARKUP_MODE_META, true),
            get_post_meta($product_id, ProductMeta::FFLHUB_MARKUP_PERCENT_META, true),
            get_post_meta($product_id, ProductMeta::FFLHUB_FIXED_PRICE_META, true),
            get_post_meta($product_id, ProductMeta::FFLHUB_MAP_POLICY_META, true),
            get_post_meta($product_id, ProductMeta::FFLHUB_MAP_REAL_PRICE_MODE_META, true),
            get_post_meta($product_id, ProductMeta::FFLHUB_MAP_REAL_PRICE_OFFSET_META, true),
            get_post_meta($product_id, ProductMeta::FFLHUB_MAP_REAL_PRICE_PERCENT_META, true),
            get_post_meta($product_id, ProductMeta::FFLHUB_MAP_REAL_PRICE_FIXED_PROFIT_META, true),
            get_post_meta($product_id, ProductMeta::FFLHUB_MAP_REAL_PRICE_FREE_SHIPPING_OVERRIDE_META, true),
            get_post_meta($product_id, ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META, true),
            get_post_meta($product_id, '_regular_price', true),
            get_post_meta($product_id, '_sale_price', true),
            get_post_meta($product_id, '_price', true),
            $dealer_price,
            $shipping_cost,
            $landed_cost,
            $map_price,
            $msrp
        );

        return [
            'product_id' => $product_id,
            'upc' => $upc,
            'distributor_id' => $source_distributor,
            'qty' => max(0, (int) ($stock_qty ?? 0)),
            'stock_status' => $stock_status,
            'dealer_price' => $dealer_price,
            'shipping_cost' => $shipping_cost,
            'landed_cost' => $landed_cost,
            'map_price' => $map_price,
            'msrp' => $msrp,
            'ffl_required' => $ffl_required,
            'sot_required' => $sot_required,
            'dropship_enabled' => $dropship_enabled,
            'enabled' => 1,
            'shipping_weight_oz' => $shipping_weight_oz,
            'shipping_length_in' => $shipping_length_in,
            'shipping_width_in' => $shipping_width_in,
            'shipping_height_in' => $shipping_height_in,
            'selection_status' => ($stock_status === 'instock' && (int) ($stock_qty ?? 0) > 0) ? 'instock' : 'no_offer',
            'status' => 'active',
            'pricing_mode' => $pricing['pricing_mode'],
            'pricing_percent' => $pricing['pricing_percent'],
            'pricing_fixed_price' => $pricing['pricing_fixed_price'],
            'pricing_fixed_profit' => $pricing['pricing_fixed_profit'],
            'computed_sell_price' => $pricing['computed_sell_price'],
            'map_applicable' => $pricing['map_applicable'],
            'map_visibility_policy' => $pricing['map_visibility_policy'],
            'quote_free_shipping_override' => $pricing['quote_free_shipping_override'],
            'public_regular_price' => $pricing['public_regular_price'],
            'public_sale_price' => $pricing['public_sale_price'],
            'manual_shipping_override' => self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_MANUAL_SHIPPING_OVERRIDE_META, true)) ? 1 : 0,
            'stock_oos_override' => self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_STOCK_OOS_OVERRIDE_META, true)) ? 1 : 0,
            'local_stock_override_qty' => $local_stock_enabled ? self::int_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_QTY_META, true)) : null,
            'local_stock_free_shipping' => ($local_stock_enabled && self::truthy(get_post_meta($product_id, ProductMeta::FFLHUB_LOCAL_STOCK_FREE_SHIPPING_META, true))) ? 1 : 0,
            'allowed_distributors_json' => self::allowed_distributors_json(
                get_post_meta($product_id, ProductMeta::FFLHUB_DISTRIBUTOR_LOCK_ENABLED_META, true),
                get_post_meta($product_id, ProductMeta::FFLHUB_DISTRIBUTOR_LOCK_IDS_META, true)
            ),
            'bom_total_cost' => $bom_enabled ? self::decimal_or_null(get_post_meta($product_id, ProductMeta::FFLHUB_BOM_TOTAL_COST_META, true), 4) : null,
            'has_changed' => 0,
        ];
    }

    /**
     * Translate the old product-meta pricing shape into the clearer product
     * state model:
     * - pricing_mode answers how we calculate the sell/quote price.
     * - map_applicable answers whether a usable MAP exists right now.
     * - map_visibility_policy answers the configured display policy.
     *
     * @return array<string,mixed>
     */
    private static function pricing_state_from_product_meta(
        $markup_mode_raw,
        $markup_percent_raw,
        $fixed_price_raw,
        $map_policy_raw,
        $map_real_price_mode_raw,
        $map_real_price_offset_raw,
        $map_real_price_percent_raw,
        $map_real_price_fixed_profit_raw,
        $map_real_price_free_shipping_override_raw,
        $last_computed_raw,
        $woo_regular_raw,
        $woo_sale_raw,
        $woo_active_raw,
        ?string $dealer_price,
        ?string $shipping_cost,
        ?string $landed_cost,
        ?string $map_price,
        ?string $msrp
    ): array {
        $markup_mode = self::old_markup_mode($markup_mode_raw);
        $map_real_price_mode = self::old_map_real_price_mode($map_real_price_mode_raw);
        $map_applicable = self::float_or_null($map_price) !== null && (float) $map_price > 0.0;
        $visibility_policy = self::map_visibility_policy($map_policy_raw, $map_applicable);

        $old_map_real_price = self::old_map_real_price(
            $map_real_price_mode,
            $map_real_price_offset_raw,
            $map_real_price_percent_raw,
            $map_real_price_fixed_profit_raw,
            $last_computed_raw,
            $woo_regular_raw,
            $woo_active_raw,
            $dealer_price,
            $shipping_cost,
            $landed_cost,
            $map_price
        );

        $pricing_mode = self::pricing_mode_from_old_meta(
            $markup_mode,
            $visibility_policy,
            $map_real_price_mode,
            $old_map_real_price,
            $map_real_price_fixed_profit_raw
        );

        $fixed_price = self::float_or_null($fixed_price_raw);
        $fixed_profit = self::float_or_null($map_real_price_fixed_profit_raw);
        $pricing_percent = self::pricing_percent_for_column($pricing_mode, $markup_percent_raw);

        if ($pricing_mode === 'fixed_price' && $fixed_price === null && $old_map_real_price !== null) {
            $fixed_price = $old_map_real_price;
        }

        $computed_sell_price = self::computed_sell_price(
            $pricing_mode,
            $pricing_percent,
            $fixed_price,
            $fixed_profit,
            $dealer_price,
            $shipping_cost,
            $landed_cost,
            $map_price,
            $last_computed_raw,
            $woo_regular_raw,
            $woo_active_raw
        );

        $public_prices = self::public_price_fields(
            $computed_sell_price,
            $map_price,
            $msrp,
            $visibility_policy,
            $map_applicable,
            $woo_regular_raw,
            $woo_sale_raw
        );

        return [
            'pricing_mode' => $pricing_mode,
            'pricing_percent' => self::money_or_null($pricing_percent, 4),
            'pricing_fixed_price' => self::money_or_null($pricing_mode === 'fixed_price' ? $fixed_price : null, 4),
            'pricing_fixed_profit' => self::money_or_null($pricing_mode === 'fixed_profit' ? $fixed_profit : null, 4),
            'map_visibility_policy' => $visibility_policy,
            'quote_free_shipping_override' => self::truthy($map_real_price_free_shipping_override_raw) ? 1 : 0,
            'computed_sell_price' => self::money_or_null($computed_sell_price, 4),
            'map_applicable' => $map_applicable ? 1 : 0,
            'public_regular_price' => self::money_or_null($public_prices['regular'], 4),
            'public_sale_price' => self::money_or_null($public_prices['sale'], 4),
        ];
    }

    private static function old_markup_mode($raw): int
    {
        if ($raw === '' || $raw === null) {
            return ProductMeta::MARKUP_MODE_GLOBAL;
        }

        return (int) $raw;
    }

    private static function old_map_real_price_mode($raw): int
    {
        if ($raw === '' || $raw === null) {
            return ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED;
        }

        $mode = (int) $raw;
        return in_array($mode, [
            ProductMeta::MAP_REAL_PRICE_MODE_FIXED_OFFSET,
            ProductMeta::MAP_REAL_PRICE_MODE_PERCENTAGE,
            ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED,
            ProductMeta::MAP_REAL_PRICE_MODE_FIXED_PROFIT,
        ], true) ? $mode : ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED;
    }

    private static function map_visibility_policy($raw, bool $map_applicable): string
    {
        $policy = strtolower(trim((string) $raw));
        if ($policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE || $policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) {
            return $policy;
        }

        return Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
    }

    private static function pricing_mode_from_old_meta(
        int $markup_mode,
        string $visibility_policy,
        int $map_real_price_mode,
        ?float $old_map_real_price,
        $map_real_price_fixed_profit_raw
    ): string {
        if ($markup_mode === ProductMeta::MARKUP_MODE_FIXED_PCT) {
            return 'fixed_percent';
        }

        if ($markup_mode === ProductMeta::MARKUP_MODE_FIXED_PRICE) {
            return 'fixed_price';
        }

        if ($markup_mode === ProductMeta::MARKUP_MODE_MAP_PRICE) {
            if ($visibility_policy === 'none') {
                return 'global_percent';
            }

            if (
                $visibility_policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE
                && $map_real_price_mode === ProductMeta::MAP_REAL_PRICE_MODE_FIXED_PROFIT
                && self::float_or_null($map_real_price_fixed_profit_raw) !== null
            ) {
                return 'fixed_profit';
            }

            if (
                $visibility_policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE
                && in_array($map_real_price_mode, [ProductMeta::MAP_REAL_PRICE_MODE_FIXED_OFFSET, ProductMeta::MAP_REAL_PRICE_MODE_PERCENTAGE], true)
                && $old_map_real_price !== null
            ) {
                return 'fixed_price';
            }

            if (
                $visibility_policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE
                && $map_real_price_mode === ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED
            ) {
                return 'global_percent';
            }

            return 'map_price';
        }

        return 'global_percent';
    }

    private static function pricing_percent_for_column(string $pricing_mode, $markup_percent_raw): ?float
    {
        if ($pricing_mode === 'global_percent') {
            return max(0.0, (float) Options::get_global_markup());
        }

        if ($pricing_mode !== 'fixed_percent') {
            return null;
        }

        $percent = self::float_or_null($markup_percent_raw);
        if ($percent === null || $percent < 0.0) {
            return null;
        }

        return ($percent <= 1.0) ? ($percent * 100.0) : $percent;
    }

    private static function computed_sell_price(
        string $pricing_mode,
        ?float $pricing_percent,
        ?float $fixed_price,
        ?float $fixed_profit,
        ?string $dealer_price,
        ?string $shipping_cost,
        ?string $landed_cost,
        ?string $map_price,
        $last_computed_raw,
        $woo_regular_raw,
        $woo_active_raw
    ): ?float {
        if ($pricing_mode === 'fixed_price' && $fixed_price !== null && $fixed_price > 0.0) {
            return round($fixed_price, 2);
        }

        if ($pricing_mode === 'fixed_profit' && $fixed_profit !== null && $fixed_profit >= 0.0) {
            $fixed_profit_price = self::fixed_profit_price($dealer_price, $shipping_cost, $landed_cost, $fixed_profit);
            if ($fixed_profit_price !== null) {
                return $fixed_profit_price;
            }
        }

        if ($pricing_mode === 'map_price') {
            $map = self::float_or_null($map_price);
            if ($map !== null && $map > 0.0) {
                return round($map, 2);
            }
        }

        if ($pricing_mode === 'global_percent' || $pricing_mode === 'fixed_percent') {
            $percent = self::float_or_null($pricing_percent);
            $base = self::cost_base_without_shipping($dealer_price, $landed_cost);
            if ($percent !== null && $base !== null && $base > 0.0) {
                return (float) (ceil($base * (1.0 + ($percent / 100.0))) - 0.01);
            }
        }

        return self::float_or_null($last_computed_raw)
            ?? self::float_or_null($woo_active_raw)
            ?? self::float_or_null($woo_regular_raw);
    }

    /**
     * @return array{regular:?float,sale:?float}
     */
    private static function public_price_fields(
        ?float $computed_sell_price,
        ?string $map_price,
        ?string $msrp,
        string $visibility_policy,
        bool $map_applicable,
        $woo_regular_raw,
        $woo_sale_raw
    ): array {
        $map = self::float_or_null($map_price);
        if (
            $map_applicable
            && $map !== null
            && ($visibility_policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE || $visibility_policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART)
        ) {
            $msrp_float = self::float_or_null($msrp);
            $regular = ($msrp_float !== null && $msrp_float > $map)
                ? $msrp_float
                : $map;

            return [
                'regular' => round($regular, 2),
                'sale' => round($map, 2),
            ];
        }

        if ($computed_sell_price === null || $computed_sell_price <= 0.0) {
            return [
                'regular' => self::float_or_null($woo_regular_raw),
                'sale' => self::float_or_null($woo_sale_raw),
            ];
        }

        $regular = round($computed_sell_price, 2);
        $sale = null;
        $msrp_float = self::float_or_null($msrp);

        if ($map !== null && $map > $computed_sell_price) {
            $regular = round($map, 2);
            $sale = round($computed_sell_price, 2);
        } elseif ($msrp_float !== null && $msrp_float > $computed_sell_price) {
            $regular = round($msrp_float, 2);
            $sale = round($computed_sell_price, 2);
        }

        return [
            'regular' => $regular,
            'sale' => $sale,
        ];
    }

    private static function old_map_real_price(
        int $real_mode,
        $offset_raw,
        $percent_raw,
        $fixed_profit_raw,
        $last_computed_raw,
        $woo_regular_raw,
        $woo_active_raw,
        ?string $dealer_price,
        ?string $shipping_cost,
        ?string $landed_cost,
        ?string $map_price
    ): ?float {
        $map = self::float_or_null($map_price);
        if ($map === null || $map <= 0.0) {
            return null;
        }

        if ($real_mode === ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED) {
            return self::float_or_null($last_computed_raw)
                ?? self::float_or_null($woo_regular_raw)
                ?? self::float_or_null($woo_active_raw);
        }

        if ($real_mode === ProductMeta::MAP_REAL_PRICE_MODE_FIXED_OFFSET) {
            $base = self::cost_base_without_shipping($dealer_price, $landed_cost);
            $offset = self::float_or_null($offset_raw) ?? 0.0;
            return ($base !== null) ? round($base + max(0.0, $offset), 2) : null;
        }

        if ($real_mode === ProductMeta::MAP_REAL_PRICE_MODE_FIXED_PROFIT) {
            $profit = self::float_or_null($fixed_profit_raw) ?? 0.0;
            return self::fixed_profit_price($dealer_price, $shipping_cost, $landed_cost, $profit);
        }

        $percent = self::float_or_null($percent_raw) ?? 0.0;
        $real_price = $map - ($map * (max(0.0, $percent) / 100.0));

        return ($real_price > 0.0) ? round($real_price, 2) : null;
    }

    private static function cost_base(?string $dealer_price, ?string $shipping_cost, ?string $landed_cost): ?float
    {
        $dealer = self::float_or_null($dealer_price);
        if ($dealer !== null && $dealer > 0.0) {
            $shipping = self::float_or_null($shipping_cost) ?? 0.0;
            return $dealer + max(0.0, $shipping);
        }

        $landed = self::float_or_null($landed_cost);
        return ($landed !== null && $landed > 0.0) ? $landed : null;
    }

    private static function cost_base_without_shipping(?string $dealer_price, ?string $landed_cost): ?float
    {
        $dealer = self::float_or_null($dealer_price);
        if ($dealer !== null && $dealer > 0.0) {
            return $dealer;
        }

        $landed = self::float_or_null($landed_cost);
        return ($landed !== null && $landed > 0.0) ? $landed : null;
    }

    private static function fixed_profit_price(
        ?string $dealer_price,
        ?string $shipping_cost,
        ?string $true_cost,
        float $profit_target
    ): ?float {
        $cost_base = self::cost_base_without_shipping($dealer_price, $true_cost);
        if ($cost_base === null || $cost_base <= 0.0) {
            return null;
        }

        $shipping = self::float_or_null($shipping_cost) ?? 0.0;
        $profit = max(0.0, $profit_target);
        $fee_fraction = self::payment_fee_fraction();
        $denominator = 1.0 - $fee_fraction;
        if ($denominator <= 0.0) {
            return null;
        }

        // Match DistributorProductHelper::get_map_real_price_for_product():
        // price = true/dealer cost + ((profit + shipping + cost fee) / (1 - fee)).
        $offset = ($profit + max(0.0, $shipping) + ($cost_base * $fee_fraction)) / $denominator;
        $price = round($cost_base + $offset, 2);

        return ($price > 0.0) ? $price : null;
    }

    private static function payment_fee_fraction(): float
    {
        $fee_percent = (float) Options::get_payment_processor_fee_percent();
        if (!is_finite($fee_percent) || $fee_percent < 0.0) {
            return 0.0;
        }

        return min(0.99, $fee_percent / 100.0);
    }

    private static function admin_pricing_mode($raw): string
    {
        $mode = strtolower(trim((string) $raw));
        return in_array($mode, ['global_percent', 'fixed_percent', 'fixed_price', 'fixed_profit', 'map_price'], true)
            ? $mode
            : 'global_percent';
    }

    private static function admin_map_visibility_policy($raw, bool $map_applicable): string
    {
        $policy = strtolower(trim((string) $raw));
        return in_array($policy, [
            'none',
            Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE,
            Options::MAP_POLICY_EMAIL_FOR_QUOTE,
            Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART,
        ], true) ? $policy : Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
    }

    private static function admin_nullable_absint($raw): ?int
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        return max(0, (int) $raw);
    }

    private static function nullable_string($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return ($value === '') ? null : $value;
    }

    /**
     * Product_state values are stored as strings by wpdb for most scalar types,
     * so compare by the normalized storage representation we are about to save.
     *
     * @param mixed $old
     * @param mixed $new
     */
    private static function admin_value_changed($old, $new): bool
    {
        if ($new === null) {
            return trim((string) $old) !== '';
        }

        if (is_int($new)) {
            return (int) $old !== $new;
        }

        return trim((string) $old) !== (string) $new;
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
            if ($ok === false) {
                return "Insert failed for product #{$product_id}: {$wpdb->last_error}";
            }

            self::clear_product_cache($product_id);
            return 'inserted';
        }

        $row['updated_at'] = $now;
        $ok = $wpdb->update(
            $table,
            $row,
            ['product_id' => $product_id],
            self::formats_for_row($row),
            ['%d']
        );

        if ($ok === false) {
            return "Update failed for product #{$product_id}: {$wpdb->last_error}";
        }

        self::clear_product_cache($product_id);
        return 'updated';
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
            'distributor_id' => '%s',
            'distributor_product_id' => '%s',
            'distributor_sku' => '%s',
            'manufacturer_norm' => '%s',
            'qty' => '%d',
            'stock_status' => '%s',
            'dealer_price' => '%f',
            'shipping_cost' => '%f',
            'landed_cost' => '%f',
            'map_price' => '%f',
            'msrp' => '%f',
            'ffl_required' => '%d',
            'sot_required' => '%d',
            'dropship_enabled' => '%d',
            'enabled' => '%d',
            'shipping_weight_oz' => '%f',
            'shipping_length_in' => '%f',
            'shipping_width_in' => '%f',
            'shipping_height_in' => '%f',
            'source_updated_at' => '%s',
            'source_offer_normalized_at' => '%s',
            'selection_status' => '%s',
            'selected_at' => '%s',
            'woo_synced_at' => '%s',
            'status' => '%s',
            'pricing_mode' => '%s',
            'pricing_percent' => '%f',
            'pricing_fixed_price' => '%f',
            'pricing_fixed_profit' => '%f',
            'map_visibility_policy' => '%s',
            'quote_free_shipping_override' => '%d',
            'computed_sell_price' => '%f',
            'map_applicable' => '%d',
            'public_regular_price' => '%f',
            'public_sale_price' => '%f',
            'manual_shipping_override' => '%d',
            'stock_oos_override' => '%d',
            'local_stock_override_qty' => '%d',
            'local_stock_free_shipping' => '%d',
            'allowed_distributors_json' => '%s',
            'bom_total_cost' => '%f',
            'created_at' => '%s',
            'updated_at' => '%s',
            'has_changed' => '%d',
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

    private static function float_or_null($value): ?float
    {
        $value = preg_replace('/[^0-9.\-]/', '', trim((string) $value));
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }

    private static function money_or_null(?float $value, int $scale): ?string
    {
        if ($value === null || !is_finite($value)) {
            return null;
        }

        return number_format($value, $scale, '.', '');
    }

    private static function int_or_null($value): ?int
    {
        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
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
