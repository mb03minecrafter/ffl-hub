<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Cancellation;

use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobsCancellationStore
 *
 * Responsibility:
 * - Table-side helpers used when a WooCommerce order is trashed/restored/deleted:
 *   - locate Action Scheduler action_ids representing FUTURE work we can cancel
 *   - clear action_id + next_run_at for those future-work rows
 *   - delete job rows for an order on permanent deletion
 *
 * Semantics:
 * - Only certain statuses represent "future work" that is safe to cancel:
 *   - queued
 *   - retry_scheduled
 * - We intentionally do NOT cancel "running" work to avoid interrupting in-flight execution.
 *
 * Notes:
 * - This class performs direct SQL updates because we need atomic, set-based operations
 *   across multiple rows for a single order.
 * - Used by OrderTrashJobsService (order-level trash policy).
 *
 * Dependency:
 * - Requires an instantiated OrderPlacementJobsTable manager for table name resolution.
 */
final class OrderPlacementJobsCancellationStore
{
    /**
     * Get Action Scheduler action_ids for jobs that represent FUTURE work we can cancel.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param int                     $order_id   WooCommerce order ID.
     * @return int[] Unique positive Action Scheduler action IDs.
     */
    public static function get_future_action_ids_for_order(OrderPlacementJobsTable $jobs_table, int $order_id): array
    {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return [];
        }

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return [];
        }

        // Statuses that represent future work which is safe to cancel.
        $future_statuses = [
            OrderPlacementKeys::JOB_STATUS_QUEUED,
            OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED,
        ];

        $placeholders = implode(',', array_fill(0, count($future_statuses), '%s'));

        $sql = $wpdb->prepare(
            "
            SELECT action_id
            FROM {$table}
            WHERE order_id = %d
              AND action_id IS NOT NULL
              AND action_id > 0
              AND status IN ({$placeholders})
            ",
            array_merge([$order_id], $future_statuses)
        );

        $rows = $wpdb->get_col($sql);

        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $v) {
                $i = (int) $v;
                if ($i > 0) {
                    $out[] = $i;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Clear action scheduling fields for an order's future-status jobs.
     *
     * What it does:
     * - Sets action_id = NULL
     * - Sets next_run_at = NULL
     * - Stamps updated_at = now (UTC)
     * - Optionally stores a human-readable reason in last_error (for admin UI visibility)
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param int                     $order_id   WooCommerce order ID.
     * @param string                  $reason     Optional reason stored in last_error.
     */
    public static function clear_actions_for_order(OrderPlacementJobsTable $jobs_table, int $order_id, string $reason = ''): void
    {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return;
        }

        $now = OrderPlacementTimeUtil::now_mysql_utc();

        $future_statuses = [
            OrderPlacementKeys::JOB_STATUS_QUEUED,
            OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED,
        ];

        $placeholders = implode(',', array_fill(0, count($future_statuses), '%s'));

        // Clear schedule metadata.
        $sql = "
            UPDATE {$table}
            SET action_id = NULL,
                next_run_at = NULL,
                updated_at = %s
            WHERE order_id = %d
              AND status IN ({$placeholders})
        ";

        $args = array_merge([$now, $order_id], $future_statuses);
        $wpdb->query($wpdb->prepare($sql, $args));

        // Optionally store the reason as last_error to aid debugging/visibility in admin UI.
        $reason = trim((string) $reason);
        if ($reason !== '') {
            $sql2 = "
                UPDATE {$table}
                SET last_error = %s,
                    updated_at = %s
                WHERE order_id = %d
                  AND status IN ({$placeholders})
            ";

            $args2 = array_merge([$reason, $now, $order_id], $future_statuses);
            $wpdb->query($wpdb->prepare($sql2, $args2));
        }
    }

    /**
     * Delete ALL job rows for an order (used on permanent deletion).
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param int                     $order_id   WooCommerce order ID.
     */
    public static function delete_jobs_for_order(OrderPlacementJobsTable $jobs_table, int $order_id): void
    {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return;
        }

        $wpdb->delete($table, ['order_id' => $order_id], ['%d']);
    }
}
