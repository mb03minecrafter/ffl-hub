<?php

namespace FFLHub\Distributor\Services\Tables;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper responsible ONLY for the Order Placement Jobs DB table schema.
 *
 * IMPORTANT (dbDelta quirks):
 * - Do NOT put SQL comments (--) inside the CREATE TABLE body.
 * - Avoid blank lines inside the CREATE TABLE parentheses.
 * - Keep one field/key per line.
 */
final class OrderPlacementJobsTable
{
    const TABLE_NAME_KEY = 'fflhub_place_jobs';

    public static function get_table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME_KEY;
    }

    /**
     * @return string[]
     */
    private static function columns(): array
    {
        return [
            "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
            "order_id BIGINT UNSIGNED NOT NULL",
            "job_key VARCHAR(80) NOT NULL",
            "dist_id VARCHAR(32) NOT NULL",
            "bucket VARCHAR(8) NOT NULL",
            "status VARCHAR(24) NOT NULL",
            "attempts INT UNSIGNED NOT NULL DEFAULT 0",
            "created_at DATETIME NOT NULL",
            "updated_at DATETIME NOT NULL",
            "action_id BIGINT UNSIGNED NULL",
            "next_run_at DATETIME NULL",
            "last_step VARCHAR(16) NOT NULL DEFAULT ''",
            "last_error TEXT NULL",
            "last_codes_json TEXT NULL",
            "done_at DATETIME NULL",
            "payload_json LONGTEXT NOT NULL",
            "validate_result_json LONGTEXT NULL",
            "place_result_json LONGTEXT NULL",
            "merchant_po VARCHAR(32) NULL",
            "external_order_id VARCHAR(64) NULL",
            "external_order_ids_json TEXT NULL",
            "shipped_at DATETIME NULL",
            "tracking_numbers_json TEXT NULL",
            "invoice_numbers_json TEXT NULL",
            "shipping_service VARCHAR(64) NULL",
            "shipping_weight VARCHAR(32) NULL",
            "shipment_raw_json LONGTEXT NULL",
            "last_shipping_poll_at DATETIME NULL",
        ];
    }

    /**
     * @return string[]
     */
    private static function keys(): array
    {
        return [
            "PRIMARY KEY (id)",
            "UNIQUE KEY uq_order_job (order_id, job_key)",
            "KEY idx_order_id (order_id)",
            "KEY idx_status_next_run (status, next_run_at)",
            "KEY idx_action_id (action_id)",
            "KEY idx_dist_bucket (dist_id, bucket)",
            "KEY idx_merchant_po (merchant_po)",
            "KEY idx_external_order_id (external_order_id)",
            "KEY idx_last_ship_poll (last_shipping_poll_at)",
            "KEY idx_shipped_at (shipped_at)",
        ];
    }

    public static function create_table(): void
    {
        global $wpdb;

        $table_name      = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $lines = array_merge(self::columns(), self::keys());

        // IMPORTANT: no blank lines in dbDelta CREATE TABLE body
        $sql = "CREATE TABLE {$table_name} (\n" . implode(",\n", $lines) . "\n) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
