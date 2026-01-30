<?php

namespace FFLHub\Distributor\Services\Orders\Jobs;

use WC_Order;

use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobWriter
 *
 * Responsibility:
 * - The ONLY low-level write primitive for the Order Placement Jobs table.
 * - Applies patches (preferred) and performs partial field updates (internal).
 *
 * Rules:
 * - No business logic (success/fail/retry/shipping rules). This layer is persistence only.
 * - Enforces schema allowlist via OrderPlacementJobsTable::get_writable_columns().
 * - Always stamps updated_at (UTC) for observability.
 * - Supports NULL-safe partial updates (wpdb->update() does not reliably write NULL).
 *
 * Dependency:
 * - Requires an instantiated OrderPlacementJobsTable (table manager).
 *   This centralizes table naming/prefixing and the writable column allowlist.
 */
final class OrderPlacementJobWriter
{
    /* ============================================================
     * Patch apply (public API)
     * ============================================================ */

    /**
     * Apply a patch to a job row identified by (order_id, job_key).
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param int                     $order_id   Woo order ID.
     * @param string                  $job_key    Job key (dist|bucket). Normalized before use.
     * @param OrderPlacementJobPatch  $patch      Patch representing changes to persist.
     */
    public static function apply_patch(
        OrderPlacementJobsTable $jobs_table,
        int $order_id,
        string $job_key,
        OrderPlacementJobPatch $patch
    ): void {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return;
        }

        $fields = $patch->to_write_array();
        if (!is_array($fields) || empty($fields)) {
            return; // nothing to write
        }

        self::update_job_fields($jobs_table, $order_id, $job_key, $fields);
    }

    /**
     * Convenience: apply a patch using a WC_Order object.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      Woo order object.
     * @param string                  $job_key    Job key (dist|bucket). Normalized before use.
     * @param OrderPlacementJobPatch  $patch      Patch representing changes to persist.
     */
    public static function apply_patch_for_order(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        OrderPlacementJobPatch $patch
    ): void {
        self::apply_patch($jobs_table, (int) $order->get_id(), (string) $job_key, $patch);
    }

    /* ============================================================
     * Internal update primitive (NULL-safe)
     * ============================================================ */

    /**
     * Update fields on a job row (partial update). NULL-safe.
     *
     * - Filters updates to allowlisted writable columns from the table manager.
     * - Always stamps updated_at (UTC).
     * - Uses wpdb->update() when no NULLs are present, otherwise builds a manual UPDATE.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param int                     $order_id   Woo order ID.
     * @param string                  $job_key    Normalized job key.
     * @param array<string,mixed>     $fields     Partial column => value map.
     */
    private static function update_job_fields(
        OrderPlacementJobsTable $jobs_table,
        int $order_id,
        string $job_key,
        array $fields
    ): void {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return;
        }

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return;
        }

        if (empty($fields)) {
            return;
        }

        // Always stamp updated_at for observability + UI freshness.
        $fields['updated_at'] = OrderPlacementTimeUtil::now_mysql_utc();

        // Filter to allowlisted columns (single source of truth: table manager/schema).
        $allowed = $jobs_table->get_writable_columns();
        if (!is_array($allowed) || empty($allowed)) {
            return;
        }

        $allowed_set = array_fill_keys($allowed, true);

        $data = [];
        foreach ($fields as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            if (!isset($allowed_set[$k])) {
                continue;
            }
            $data[$k] = $v;
        }

        if (empty($data)) {
            return;
        }

        // Detect whether any field is NULL (wpdb->update doesn't support NULL cleanly).
        $has_null = false;
        foreach ($data as $v) {
            if ($v === null) {
                $has_null = true;
                break;
            }
        }

        // Fast path: no NULLs => use $wpdb->update().
        if (!$has_null) {
            $format = [];
            foreach ($data as $v) {
                // Treat ints and digit-only numeric strings as integers for formatting.
                if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                    $format[] = '%d';
                } else {
                    $format[] = '%s';
                }
            }

            $wpdb->update(
                $table,
                $data,
                ['order_id' => $order_id, 'job_key' => $job_key],
                $format,
                ['%d', '%s']
            );

            return;
        }

        /**
         * NULL-safe path:
         * Build a manual UPDATE statement that writes "col = NULL" where needed.
         */
        $sets = [];
        $args = [];

        foreach ($data as $k => $v) {
            // Allowlist already protects identifiers; this is just extra hardening.
            $col = preg_replace('/[^a-zA-Z0-9_]/', '', $k);
            if ($col === '') {
                continue;
            }

            if ($v === null) {
                $sets[] = "{$col} = NULL";
                continue;
            }

            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                $sets[] = "{$col} = %d";
                $args[] = (int) $v;
                continue;
            }

            $sets[] = "{$col} = %s";
            $args[] = (string) $v;
        }

        if (empty($sets)) {
            return;
        }

        // WHERE clause args
        $args[] = $order_id;
        $args[] = $job_key;

        $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE order_id = %d AND job_key = %s";
        $wpdb->query($wpdb->prepare($sql, $args));
    }
}
