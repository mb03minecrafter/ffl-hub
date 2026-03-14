<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Orders\Tables;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for the Order Placement Jobs table schema.
 *
 * IMPORTANT (dbDelta quirks):
 * - Do NOT put SQL comments (--) inside the CREATE TABLE body.
 * - Avoid blank lines inside CREATE TABLE parentheses.
 * - Keep one field/key per line.
 */
final class OrderPlacementJobsSchema
{
    /**
     * Base key used to build the table name (without $wpdb->prefix).
     */
    private const BASE_TABLE_KEY = 'fflhub_place_jobs';

    public function get_base_table_key(): string
    {
        return self::BASE_TABLE_KEY;
    }

    /**
     * Writable columns for safe partial updates.
     *
     * Excludes:
     * - id (auto-increment)
     * - order_id/job_key (row identifiers)
     * - created_at (insert-time field)
     *
     * Includes:
     * - updated_at (writer stamps it on every update)
     *
     * @return string[]
     */
    public function get_writable_columns(): array
    {
        return [
            'dist_id',
            'bucket',
            'status',
            'attempts',
            'action_id',
            'next_run_at',
            'last_step',
            'last_error',
            'last_codes_json',
            'done_at',
            'payload_json',
            'validate_result_json',
            'place_result_json',
            'merchant_po',
            'external_order_ids_json',
            'external_order_id',

            // shipping fields
            'shipped_at',
            'tracking_numbers_json',
            'invoice_numbers_json',
            'shipping_service',
            'shipping_weight',
            'shipment_raw_json',
            'last_shipping_poll_at',

            // writer-stamped
            'updated_at',
        ];
    }

    /**
     * Column definitions.
     *
     * @return array<string,string> column_name => SQL definition
     */
    public function get_column_definitions(): array
    {
        return [
            'id'                      => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'order_id'                => 'BIGINT UNSIGNED NOT NULL',
            'job_key'                 => 'VARCHAR(80) NOT NULL',
            'dist_id'                 => 'VARCHAR(32) NOT NULL',
            'bucket'                  => 'VARCHAR(32) NOT NULL',
            'status'                  => 'VARCHAR(24) NOT NULL',
            'attempts'                => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'created_at'              => 'DATETIME NOT NULL',
            'updated_at'              => 'DATETIME NOT NULL',
            'action_id'               => 'BIGINT UNSIGNED NULL',
            'next_run_at'             => 'DATETIME NULL',
            'last_step'               => "VARCHAR(16) NOT NULL DEFAULT ''",
            'last_error'              => 'TEXT NULL',
            'last_codes_json'         => 'TEXT NULL',
            'done_at'                 => 'DATETIME NULL',
            'payload_json'            => 'LONGTEXT NOT NULL',
            'validate_result_json'    => 'LONGTEXT NULL',
            'place_result_json'       => 'LONGTEXT NULL',
            'merchant_po'             => 'VARCHAR(32) NULL',
            'external_order_id'       => 'VARCHAR(64) NULL',
            'external_order_ids_json' => 'TEXT NULL',

            // shipping
            'shipped_at'              => 'DATETIME NULL',
            'tracking_numbers_json'   => 'TEXT NULL',
            'invoice_numbers_json'    => 'TEXT NULL',
            'shipping_service'        => 'VARCHAR(64) NULL',
            'shipping_weight'         => 'VARCHAR(32) NULL',
            'shipment_raw_json'       => 'LONGTEXT NULL',
            'last_shipping_poll_at'   => 'DATETIME NULL',
        ];
    }

    /**
     * Index definitions (dbDelta expects these as lines inside CREATE TABLE()).
     *
     * @return string[]
     */
    public function get_index_definitions(): array
    {
        return [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uq_order_job (order_id, job_key)',
            'KEY idx_order_id (order_id)',
            'KEY idx_status_next_run (status, next_run_at)',
            'KEY idx_action_id (action_id)',
            'KEY idx_dist_bucket (dist_id, bucket)',
            'KEY idx_merchant_po (merchant_po)',
            'KEY idx_external_order_id (external_order_id)',
            'KEY idx_last_ship_poll (last_shipping_poll_at)',
            'KEY idx_shipped_at (shipped_at)',
        ];
    }

    /**
     * Insert columns (excludes auto-increment id).
     *
     * @return string[]
     */
    public function get_insert_columns(): array
    {
        $columns = array_keys($this->get_column_definitions());

        return array_values(
            array_filter(
                $columns,
                static fn(string $col): bool => $col !== 'id'
            )
        );
    }
}
