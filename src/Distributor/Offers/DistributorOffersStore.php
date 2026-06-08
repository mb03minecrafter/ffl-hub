<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Offers;

if (!defined('ABSPATH')) {
    exit;
}

final class DistributorOffersStore
{
    private const SCHEMA_OPTION = 'fflhub_distributor_offers_schema_version';
    private const SCHEMA_VERSION = '4';
    private const TABLE_SUFFIX = 'fflhub_distributor_offers';

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
        if (
            $installed === self::SCHEMA_VERSION
            && self::table_exists()
            && self::has_expected_columns()
            && self::has_expected_indexes()
            && !self::has_deprecated_columns()
        ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = (string) $wpdb->get_charset_collate();

        dbDelta("
            CREATE TABLE {$table} (
                upc VARCHAR(32) NOT NULL,
                distributor_id VARCHAR(64) NOT NULL,
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
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                shipping_weight_oz DECIMAL(10,3) DEFAULT NULL,
                shipping_length_in DECIMAL(10,3) DEFAULT NULL,
                shipping_width_in DECIMAL(10,3) DEFAULT NULL,
                shipping_height_in DECIMAL(10,3) DEFAULT NULL,
                source_updated_at DATETIME DEFAULT NULL,
                normalized_at DATETIME NOT NULL,
                PRIMARY KEY  (upc, distributor_id),
                KEY distributor_upc (distributor_id, upc),
                KEY distributor_product_id (distributor_id, distributor_product_id),
                KEY upc_available_landed (upc, enabled, dropship_enabled, qty, landed_cost),
                KEY normalized_at (normalized_at)
            ) {$charset};
        ");

        self::ensure_columns($table);
        self::ensure_indexes($table);
        self::drop_true_cost_column_if_exists($table);
        self::drop_manufacturer_column_if_exists($table);

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

    private static function has_deprecated_columns(): bool
    {
        $table = self::table_name();

        return self::table_has_column($table, 'true_cost')
            || self::table_has_column($table, 'manufacturer');
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

    private static function drop_true_cost_column_if_exists(string $table): void
    {
        global $wpdb;

        if ($table === '' || !self::table_has_column($table, 'true_cost')) {
            return;
        }

        $wpdb->query("ALTER TABLE {$table} DROP COLUMN true_cost"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function drop_manufacturer_column_if_exists(string $table): void
    {
        global $wpdb;

        if ($table === '' || !self::table_has_column($table, 'manufacturer')) {
            return;
        }

        $wpdb->query("ALTER TABLE {$table} DROP COLUMN manufacturer"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function ensure_columns(string $table): void
    {
        global $wpdb;

        $missing_columns = [
            'manufacturer_norm' => 'ADD COLUMN manufacturer_norm VARCHAR(191) DEFAULT NULL AFTER distributor_sku',
        ];

        foreach ($missing_columns as $column => $definition) {
            if (self::table_has_column($table, $column)) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} {$definition}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
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
            'distributor_upc' => 'ADD KEY distributor_upc (distributor_id, upc)',
            'distributor_product_id' => 'ADD KEY distributor_product_id (distributor_id, distributor_product_id)',
            'upc_available_landed' => 'ADD KEY upc_available_landed (upc, enabled, dropship_enabled, qty, landed_cost)',
            'normalized_at' => 'ADD KEY normalized_at (normalized_at)',
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
    private static function expected_index_names(): array
    {
        return [
            'PRIMARY',
            'distributor_upc',
            'distributor_product_id',
            'upc_available_landed',
            'normalized_at',
        ];
    }

    /**
     * @return string[]
     */
    private static function expected_column_names(): array
    {
        return [
            'manufacturer_norm',
        ];
    }
}
