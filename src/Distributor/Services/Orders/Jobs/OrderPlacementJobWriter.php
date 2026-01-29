<?php

namespace FFLHub\Distributor\Services\Orders\Jobs;

use WC_Order;

use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Tables\OrderPlacementJobsTable;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;

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
 * - This class does NOT contain business logic (success/fail/retry/shipping rules).
 * - It only knows how to persist changes safely, including NULL-safe updates.
 */
final class OrderPlacementJobWriter
{
    /* ============================================================
     * Patch apply (public API)
     * ============================================================ */

    /**
     * Apply a patch to a job row.
     *
     * @param int $order_id Woo order id.
     * @param string $job_key Job key (dist|bucket).
     * @param OrderPlacementJobPatch $patch The patch representing changes to persist.
     */
    public static function apply_patch(int $order_id, string $job_key, OrderPlacementJobPatch $patch): void
    {
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

        self::update_job_fields($order_id, $job_key, $fields);
    }

    /**
     * Convenience: apply a patch using a WC_Order object.
     *
     * @param WC_Order $order Woo order object.
     * @param string $job_key Job key (dist|bucket).
     * @param OrderPlacementJobPatch $patch Patch representing changes to persist.
     */
    public static function apply_patch_for_order(WC_Order $order, string $job_key, OrderPlacementJobPatch $patch): void
    {
        self::apply_patch((int) $order->get_id(), (string) $job_key, $patch);
    }

    /* ============================================================
     * Internal update primitive (NULL-safe)
     * ============================================================ */

    /**
     * Update fields on a job row (partial update). NULL-safe.
     *
     * @param int $order_id Woo order id.
     * @param string $job_key Normalized job key.
     * @param array<string,mixed> $fields Partial column => value map.
     */
    private static function update_job_fields(int $order_id, string $job_key, array $fields): void
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return;
        }

        $fields = is_array($fields) ? $fields : [];
        if (empty($fields)) {
            return;
        }

        // Always stamp updated_at for observability + UI freshness.
        $fields['updated_at'] = OrderPlacementTimeUtil::now_mysql_utc();

        // Filter to allowlisted columns (single source of truth: table schema helper).
        $allowed = OrderPlacementJobsTable::writable_columns();
        $allowed_set = array_fill_keys($allowed, true);

        $data = [];
        foreach ($fields as $k => $v) {
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
                $format[] = is_int($v) ? '%d' : '%s';
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
            if ($v === null) {
                $sets[] = "{$k} = NULL";
                continue;
            }

            if (is_int($v)) {
                $sets[] = "{$k} = %d";
                $args[] = $v;
                continue;
            }

            $sets[] = "{$k} = %s";
            $args[] = (string) $v;
        }

        // WHERE clause args
        $args[] = $order_id;
        $args[] = $job_key;

        $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE order_id = %d AND job_key = %s";
        $wpdb->query($wpdb->prepare($sql, $args));
    }
}
