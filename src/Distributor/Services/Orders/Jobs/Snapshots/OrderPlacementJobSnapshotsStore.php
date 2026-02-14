<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Snapshots;

use WC_Order;

use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementJobsStoreUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobSnapshotsStore
 *
 * Responsibility:
 * - Persist and retrieve structured snapshot JSON payloads stored on the job row:
 *     - validate_result_json (validation results)
 *     - place_result_json    (place-order results)
 *
 * Snapshot characteristics:
 * - Machine-readable (debugging, support, future analytics).
 * - Callers are responsible for sanitizing/redacting secrets before writing.
 * - This store does NOT interpret business meaning of snapshots; it only stores/reads them.
 *
 * Notes:
 * - Writes go through OrderPlacementJobWriter via patches (preferred).
 * - Reads decode the JSON column into arrays; invalid/missing JSON returns null.
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
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key     Job key (dist|bucket). Normalized downstream.
     * @param array<string,mixed>     $snapshot    Structured snapshot array (JSON-encoded).
     */
    public static function set_job_validation_result(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        array $snapshot
    ): void {
        $json = wp_json_encode($snapshot);
        if (!is_string($json) || $json === '') {
            $json = '[]';
        }

        $patch = OrderPlacementJobPatch::empty()
            ->with_field('validate_result_json', $json);

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }

    /**
     * Retrieve the validation snapshot for a job.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key     Job key (dist|bucket). Normalized downstream.
     * @return array<string,mixed>|null Snapshot array, or null if missing/invalid.
     */
    public static function get_job_validation_result(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key
    ): ?array {
        $json = self::read_job_snapshot_json($jobs_table, (int) $order->get_id(), $job_key, 'validate_result_json');
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
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key     Job key (dist|bucket). Normalized downstream.
     * @param array<string,mixed>     $snapshot    Structured snapshot array (JSON-encoded).
     */
    public static function set_job_place_result(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        array $snapshot
    ): void {
        $json = wp_json_encode($snapshot);
        if (!is_string($json) || $json === '') {
            $json = '[]';
        }

        $patch = OrderPlacementJobPatch::empty()
            ->with_field('place_result_json', $json);

        // ✅ Promote ext_ids into dedicated columns (works for ALL distributors)
        $ext_ids = [];
        if (isset($snapshot['ext_ids']) && is_array($snapshot['ext_ids'])) {
            $ext_ids = OrderPlacementJobsStoreUtil::normalize_external_ids($snapshot['ext_ids']);
        }

        if (!empty($ext_ids)) {
            $patch = $patch
                ->with_field('external_order_ids_json', wp_json_encode($ext_ids))
                ->with_field('external_order_id', (string) $ext_ids[0]);
        }

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }


    /**
     * Retrieve the place-order snapshot for a job.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key     Job key (dist|bucket). Normalized downstream.
     * @return array<string,mixed>|null Snapshot array, or null if missing/invalid.
     */
    public static function get_job_place_result(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key
    ): ?array {
        $json = self::read_job_snapshot_json($jobs_table, (int) $order->get_id(), $job_key, 'place_result_json');
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
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param int                     $order_id   Woo order ID.
     * @param string                  $job_key    Job key (dist|bucket). Normalized before use.
     * @param string                  $column     One of: validate_result_json | place_result_json
     * @return string Raw JSON string, or empty string if missing/invalid inputs.
     */
    private static function read_job_snapshot_json(
        OrderPlacementJobsTable $jobs_table,
        int $order_id,
        string $job_key,
        string $column
    ): string {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return '';
        }

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return '';
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return '';
        }

        // Hard allowlist for safety: only these columns may be read via interpolation.
        $allowed_cols = [
            'validate_result_json',
            'place_result_json',
        ];

        if (!in_array($column, $allowed_cols, true)) {
            return '';
        }

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
