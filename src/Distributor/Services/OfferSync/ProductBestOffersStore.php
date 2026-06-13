<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

if (!defined('ABSPATH')) {
    exit;
}

final class ProductBestOffersStore
{
    private const SCHEMA_OPTION = 'fflhub_product_best_offers_schema_version';
    private const SCHEMA_VERSION = '3';
    private const TABLE_SUFFIX = 'fflhub_product_best_offers';

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
        if ($installed === self::SCHEMA_VERSION && self::table_exists() && self::has_expected_columns() && self::has_expected_indexes() && !self::has_legacy_columns()) {
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
                has_changed TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY  (product_id),
                UNIQUE KEY upc (upc),
                KEY has_changed (has_changed, product_id),
                KEY distributor_id (distributor_id),
                KEY selection_status (selection_status),
                KEY selected_at (selected_at)
            ) {$charset};
        ");

        self::ensure_columns($table);
        self::drop_legacy_columns($table);
        self::ensure_column_order($table);
        self::ensure_indexes($table);

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

    private static function has_expected_columns(): bool
    {
        $table = self::table_name();

        foreach (self::expected_column_names() as $column) {
            if (!self::table_has_column($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private static function has_legacy_columns(): bool
    {
        $table = self::table_name();

        return self::table_has_column($table, 'woo_synced_at');
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

    private static function ensure_columns(string $table): void
    {
        global $wpdb;

        $missing_columns = [
            'manufacturer_norm' => 'ADD COLUMN manufacturer_norm VARCHAR(191) DEFAULT NULL AFTER distributor_sku',
            'source_offer_normalized_at' => 'ADD COLUMN source_offer_normalized_at DATETIME DEFAULT NULL AFTER source_updated_at',
            'selection_status' => "ADD COLUMN selection_status VARCHAR(32) NOT NULL DEFAULT 'no_offer' AFTER source_offer_normalized_at",
            'selected_at' => 'ADD COLUMN selected_at DATETIME DEFAULT NULL AFTER selection_status',
            'has_changed' => 'ADD COLUMN has_changed TINYINT(1) NOT NULL DEFAULT 0 AFTER selected_at',
        ];

        foreach ($missing_columns as $column => $definition) {
            if (self::table_has_column($table, $column)) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} {$definition}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    private static function drop_legacy_columns(string $table): void
    {
        global $wpdb;

        if (self::table_has_index($table, 'woo_synced_at')) {
            $wpdb->query("ALTER TABLE {$table} DROP INDEX woo_synced_at"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        if (self::table_has_column($table, 'woo_synced_at')) {
            $wpdb->query("ALTER TABLE {$table} DROP COLUMN woo_synced_at"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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

    private static function has_expected_indexes(): bool
    {
        global $wpdb;

        $table = self::table_name();
        $rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if (!is_array($rows)) {
            return false;
        }

        $keys = [];
        foreach ($rows as $row) {
            $key = isset($row['Key_name']) ? (string) $row['Key_name'] : '';
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        foreach (self::expected_index_names() as $name) {
            if (!isset($keys[$name])) {
                return false;
            }
        }

        return true;
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
            'upc' => 'ADD UNIQUE KEY upc (upc)',
            'has_changed' => 'ADD KEY has_changed (has_changed, product_id)',
            'distributor_id' => 'ADD KEY distributor_id (distributor_id)',
            'selection_status' => 'ADD KEY selection_status (selection_status)',
            'selected_at' => 'ADD KEY selected_at (selected_at)',
        ];

        foreach ($missing_indexes as $name => $definition) {
            if (isset($keys[$name])) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} {$definition}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    /**
     * @return string[]
     */
    private static function expected_column_names(): array
    {
        return array_keys(self::ordered_column_definitions());
    }

    /**
     * @return string[]
     */
    private static function expected_index_names(): array
    {
        return [
            'PRIMARY',
            'upc',
            'has_changed',
            'distributor_id',
            'selection_status',
            'selected_at',
        ];
    }

    /**
     * Keep product_best_offers as the canonical selected-offer snapshot shape.
     * Product state mirrors these columns first, then appends its own policy
     * and Woo-sync fields, with has_changed staying at the far right.
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
            'has_changed' => 'has_changed TINYINT(1) NOT NULL DEFAULT 0',
        ];
    }
}
