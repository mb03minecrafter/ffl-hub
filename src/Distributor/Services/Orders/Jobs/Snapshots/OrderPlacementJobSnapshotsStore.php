<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Snapshots;

use WC_Order;

use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobSnapshotsStore
 *
 * Responsibility:
 * - Persist and retrieve structured “snapshot” JSON payloads for:
 *     - validation results (validate_result_json)
 *     - place-order results (place_result_json)
 *
 * These snapshots are meant to be:
 * - machine-readable (for debugging and for future analytics)
 * - safe to store (callers should already sanitize/redact secrets)
 * - stable enough for admin UI to display and for logs to reference
 *
 * Notes:
 * - Writes go through the patch writer (preferred).
 * - Reads are “read-only helpers” that decode the JSON column.
 * - This store intentionally does NOT interpret business meaning of snapshots.
 */
final class OrderPlacementJobSnapshotsStore
{
    private function __construct() {}

    /* ============================================================
     * Validation snapshot
     * ============================================================ */

    /**
     * Persist the validation snapshot JSON for a job.
     *
     * @param WC_Order $order WooCommerce order.
     * @param string $job_key Job key (dist|bucket).
     * @param array<string,mixed> $snapshot Structured snapshot array (will be JSON-encoded).
     */
    public static function set_job_validation_result(WC_Order $order, string $job_key, array $snapshot): void
    {
        $json = wp_json_encode(is_array($snapshot) ? $snapshot : []);
        if (!is_string($json) || $json === '') {
            $json = '[]';
        }

        $patch = OrderPlacementJobPatch::empty()
            ->with_field('validate_result_json', $json);

        OrderPlacementJobWriter::apply_patch_for_order($order, $job_key, $patch);
    }

    /**
     * Retrieve the validation snapshot for a job.
     *
     * @param WC_Order $order WooCommerce order.
     * @param string $job_key Job key.
     * @return array<string,mixed>|null Snapshot array, or null if none/invalid.
     */
    public static function get_job_validation_result(WC_Order $order, string $job_key): ?array
    {
        $json = self::read_job_snapshot_json((int) $order->get_id(), $job_key, 'validate_result_json');
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /* ============================================================
     * Place-order snapshot
     * ============================================================ */

    /**
     * Persist the place-order snapshot JSON for a job.
     *
     * @param WC_Order $order WooCommerce order.
     * @param string $job_key Job key.
     * @param array<string,mixed> $snapshot Structured snapshot array (will be JSON-encoded).
     */
    public static function set_job_place_result(WC_Order $order, string $job_key, array $snapshot): void
    {
        $json = wp_json_encode(is_array($snapshot) ? $snapshot : []);
        if (!is_string($json) || $json === '') {
            $json = '[]';
        }

        $patch = OrderPlacementJobPatch::empty()
            ->with_field('place_result_json', $json);

        OrderPlacementJobWriter::apply_patch_for_order($order, $job_key, $patch);
    }

    /**
     * Retrieve the place-order snapshot for a job.
     *
     * @param WC_Order $order WooCommerce order.
     * @param string $job_key Job key.
     * @return array<string,mixed>|null Snapshot array, or null if none/invalid.
     */
    public static function get_job_place_result(WC_Order $order, string $job_key): ?array
    {
        $json = self::read_job_snapshot_json((int) $order->get_id(), $job_key, 'place_result_json');
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /* ============================================================
     * Internal helpers
     * ============================================================ */

    /**
     * Read a snapshot JSON column from the jobs table.
     *
     * @param int $order_id Woo order id.
     * @param string $job_key Job key.
     * @param string $column One of: validate_result_json | place_result_json
     * @return string Raw JSON string, or empty string if missing.
     */
    private static function read_job_snapshot_json(int $order_id, string $job_key, string $column): string
    {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return '';
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return '';
        }

        $allowed_cols = [
            'validate_result_json',
            'place_result_json',
        ];

        if (!in_array($column, $allowed_cols, true)) {
            return '';
        }

        $table = OrderPlacementJobsTable::get_table_name();

        // Column is allowlisted; safe to interpolate.
        $sql = $wpdb->prepare(
            "SELECT {$column} FROM {$table} WHERE order_id = %d AND job_key = %s LIMIT 1",
            $order_id,
            $job_key
        );

        $v = $wpdb->get_var($sql);
        return is_string($v) ? trim($v) : '';
    }
}
