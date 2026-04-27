<?php

namespace FFLHub\Distributor\Services\Orders;

use FFLHub\Distributor\Services\Orders\Cron\DealerBatchCronRegistry;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderTrashJobsService
 *
 * DB-queue model (no per-job Action Scheduler actions):
 * - Trashed order:
 *   - set order meta "suspended"
 *   - pause all job rows (status=paused, next_run_at=NULL)
 * - Untrashed order:
 *   - remove "suspended"
 *   - resume paused rows:
 *     - dealer-batch distributor dealer_fulfilled rows => batch_pending
 *     - CA relay direct_ship_non_ffl rows => batch_pending
 *     - all other rows => scheduled
 *     - next_run_at=now
 * - Permanently deleted order:
 *   - delete all job rows for order_id
 *
 * Notes:
 * - This service is intentionally quiet (no verbose logging).
 * - Failures are best-effort; if DB writes fail, we log only when WP_DEBUG is enabled.
 */
final class OrderTrashJobsService
{
    private OrderPlacementJobsTable $jobs_table;

    public function __construct(OrderPlacementJobsTable $jobs_table)
    {
        $this->jobs_table = $jobs_table;
    }

    /**
     * Register WooCommerce hooks.
     */
    public function register(): void
    {
        add_action('woocommerce_trash_order', [$this, 'handle_order_trashed'], 10, 1);
        add_action('woocommerce_untrash_order', [$this, 'handle_order_untrashed'], 10, 1);
        add_action('woocommerce_before_delete_order', [$this, 'handle_order_deleted_permanently'], 10, 1);
    }

    /**
     * Trash behavior: suspend order and pause DB rows.
     */
    public function suspend_order_and_pause_jobs(int $order_id, string $reason = 'Order trashed'): void
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        update_post_meta($order_id, OrderPlacementKeys::META_ORDER_SUSPENDED, '1');
        $this->pause_all_jobs_for_order($order_id, $reason);
    }

    /**
     * Untrash behavior: unsuspend order and resume paused DB rows.
     */
    public function unsuspend_order_and_resume_jobs(int $order_id, string $reason = 'Order restored from trash'): void
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        delete_post_meta($order_id, OrderPlacementKeys::META_ORDER_SUSPENDED);
        $this->resume_paused_jobs_for_order($order_id, $reason);
    }

    /**
     * Permanent delete behavior: delete DB job rows.
     */
    public function delete_jobs_for_order(int $order_id): void
    {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        $table = $this->jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return;
        }

        try {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE order_id = %d",
                    $order_id
                )
            );
        } catch (\Throwable $e) {
            $this->debug_error('delete_jobs_failed', $order_id, $e);
        }
    }

    /* ============================ Woo hooks ============================ */

    /**
     * Hook: order moved to trash.
     *
     * @param int $order_id Woo order ID.
     */
    public function handle_order_trashed(int $order_id): void
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        // In Woo this should already be an order, but keep a cheap guard.
        if (!$this->is_shop_order_post($order_id)) {
            return;
        }

        $this->suspend_order_and_pause_jobs($order_id, 'Order trashed');
    }

    /**
     * Hook: order restored from trash.
     *
     * @param int $order_id Woo order ID.
     */
    public function handle_order_untrashed(int $order_id): void
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        if (!$this->is_shop_order_post($order_id)) {
            return;
        }

        $this->unsuspend_order_and_resume_jobs($order_id, 'Order untrashed');
    }

    /**
     * Hook: order permanently deleted.
     *
     * @param int $order_id Woo order ID.
     */
    public function handle_order_deleted_permanently(int $order_id): void
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        if (!$this->is_shop_order_post($order_id)) {
            return;
        }

        $this->delete_jobs_for_order($order_id);
    }

    /**
     * Very small guard: ensure the underlying post type looks like a Woo order.
     */
    private function is_shop_order_post(int $post_id): bool
    {
        $post_type = (string) get_post_type($post_id);
        return ($post_type === 'shop_order' || $post_type === 'shop_order_placehold');
    }

    /* ============================ DB mutations ============================ */

    /**
     * Pause all jobs for an order:
     * - status => paused
     * - next_run_at => NULL
     * - last_error => "Paused: <reason>"
     *
     * Does not pause terminal jobs (success/manual).
     *
     * @return int Number of rows affected.
     */
    private function pause_all_jobs_for_order(int $order_id, string $reason = ''): int
    {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return 0;
        }

        $table = $this->jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return 0;
        }

        $now = OrderPlacementTimeUtil::now_mysql_utc();

        try {
            $affected = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                     SET status = %s,
                         next_run_at = NULL,
                         updated_at = %s,
                         last_error = %s
                     WHERE order_id = %d
                       AND status NOT IN (%s, %s)",
                     (string) OrderPlacementKeys::JOB_STATUS_PAUSED,
                     (string) $now,
                     ($reason !== '' ? 'Paused: ' . $reason : 'Paused'),
                     $order_id,
                     (string) OrderPlacementKeys::JOB_STATUS_SUCCESS,
                     (string) OrderPlacementKeys::JOB_STATUS_MANUAL
                 )
             );

            return is_numeric($affected) ? (int) $affected : 0;
        } catch (\Throwable $e) {
            $this->debug_error('pause_jobs_failed', $order_id, $e);
            return 0;
        }
    }

    /**
     * Resume paused jobs:
     * - status => scheduled (default)
     * - status => batch_pending for dealer-batch distributor + dealer_fulfilled rows
     * - status => batch_pending for CA relay direct_ship_non_ffl rows
     * - next_run_at => now (dispatcher will pick them up)
     * - last_error => "Resumed: <reason>"
     *
     * Only resumes rows in paused status.
     *
     * @return int Number of rows affected.
     */
    private function resume_paused_jobs_for_order(int $order_id, string $reason = ''): int
    {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return 0;
        }

        $table = $this->jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return 0;
        }

        $now = OrderPlacementTimeUtil::now_mysql_utc();
        $batch_dist_ids = DealerBatchCronRegistry::distributor_ids();
        if (empty($batch_dist_ids)) {
            $batch_dist_ids = ['rsr'];
        }
        $dist_placeholders = implode(',', array_fill(0, count($batch_dist_ids), '%s'));
        $relay_dist_ids = DealerBatchCronRegistry::ca_relay_distributor_ids();
        $relay_placeholders = implode(',', array_fill(0, count($relay_dist_ids), '%s'));

        try {
            $case_sql = "WHEN dist_id IN ({$dist_placeholders}) AND lane = %s THEN %s";
            $case_params = array_merge(
                $batch_dist_ids,
                [
                    'dealer_fulfilled',
                    (string) OrderPlacementKeys::JOB_STATUS_BATCH_PENDING,
                ]
            );

            if (!empty($relay_dist_ids)) {
                $case_sql .= " WHEN dist_id IN ({$relay_placeholders}) AND lane = %s AND payload_json LIKE %s THEN %s";
                $case_params = array_merge(
                    $case_params,
                    $relay_dist_ids,
                    [
                        'direct_ship_non_ffl',
                        '%"ca_relay"%',
                        (string) OrderPlacementKeys::JOB_STATUS_BATCH_PENDING,
                    ]
                );
            }

            $affected = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                     SET status = CASE
                            {$case_sql}
                            ELSE %s
                          END,
                          next_run_at = %s,
                         updated_at = %s,
                         last_error = %s
                     WHERE order_id = %d
                       AND status = %s",
                    array_merge(
                        $case_params,
                        [
                            (string) OrderPlacementKeys::JOB_STATUS_SCHEDULED,
                            (string) $now,
                            (string) $now,
                            ($reason !== '' ? 'Resumed: ' . $reason : 'Resumed'),
                            $order_id,
                            (string) OrderPlacementKeys::JOB_STATUS_PAUSED,
                        ]
                    )
                )
            );

            return is_numeric($affected) ? (int) $affected : 0;
        } catch (\Throwable $e) {
            $this->debug_error('resume_jobs_failed', $order_id, $e);
            return 0;
        }
    }

    /* ============================ minimal debug ============================ */

    private function debug_error(string $op, int $order_id, \Throwable $e): void
    {
        // One line, compact. No ctx spam.
        DebugLogUtil::log(
            'WP_DEBUG',
            '[FFLHUB][OrderTrashJobs]',
            $op . ' order_id=' . $order_id . ' err=' . $e->getMessage()
        );
    }
}
