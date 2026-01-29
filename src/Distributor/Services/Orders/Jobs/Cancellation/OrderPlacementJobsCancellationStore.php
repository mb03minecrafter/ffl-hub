<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Cancellation;

use FFLHub\Distributor\Services\Orders\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobsCancellationStore
 *
 * Responsibility:
 * - Table-side helpers used when an order is trashed/restored/deleted:
 *     - find Action Scheduler action_ids that represent FUTURE work we can cancel
 *     - clear action_id + next_run_at fields for those rows
 *     - delete job rows for an order on permanent delete
 *
 * Important semantics:
 * - We only consider certain statuses "future work" (queued, retry_scheduled).
 * - We deliberately avoid cancelling "running" work.
 *
 * This store is used by OrderTrashJobsService (order-level trash policy).
 */
final class OrderPlacementJobsCancellationStore
{
    /**
     * Get action_id values for jobs that represent FUTURE work we can cancel.
     *
     * @param int $order_id WooCommerce order id.
     * @return int[] Unique positive Action Scheduler action ids.
     */
    public static function get_future_action_ids_for_order(int $order_id): array
    {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return [];
        }

        $table = OrderPlacementJobsTable::get_table_name();

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

        $out = array_values(array_unique($out));
        return $out;
    }

    /**
     * Clear action scheduling fields for an order's future-status jobs.
     *
     * @param int $order_id WooCommerce order id.
     * @param string $reason Optional reason to store as last_error.
     */
    public static function clear_actions_for_order(int $order_id, string $reason = ''): void
    {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        $table = OrderPlacementJobsTable::get_table_name();
        $now   = OrderPlacementTimeUtil::now_mysql_utc();

        $future_statuses = [
            OrderPlacementKeys::JOB_STATUS_QUEUED,
            OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED,
        ];

        $placeholders = implode(',', array_fill(0, count($future_statuses), '%s'));

        // Clear schedule metadata first.
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
     * Delete ALL job rows for an order.
     *
     * @param int $order_id WooCommerce order id.
     */
    public static function delete_jobs_for_order(int $order_id): void
    {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        $table = OrderPlacementJobsTable::get_table_name();

        $wpdb->delete($table, ['order_id' => $order_id], ['%d']);
    }
}
