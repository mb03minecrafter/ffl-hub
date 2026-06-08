<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Offers;

if (!defined('ABSPATH')) {
    exit;
}

final class DistributorOffersStore
{
    private const SCHEMA_OPTION = 'fflhub_distributor_offers_schema_version';
    private const SCHEMA_VERSION = '1';
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
        if ($installed === self::SCHEMA_VERSION && self::table_exists() && self::has_expected_indexes()) {
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
                qty INT UNSIGNED NOT NULL DEFAULT 0,
                stock_status VARCHAR(32) DEFAULT NULL,
                dealer_price DECIMAL(12,4) DEFAULT NULL,
                true_cost DECIMAL(12,4) DEFAULT NULL,
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
}
