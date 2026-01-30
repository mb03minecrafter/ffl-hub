<?php

namespace FFLHub\Distributor\Services\Orders;

use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;

use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * DB-queue model:
 * - Trashed order => suspend order (order meta) + pause all job rows (status=paused, next_run_at=NULL)
 * - Untrashed order => unsuspend + resume paused rows (status=scheduled, next_run_at=now)
 * - Permanently deleted => delete job rows for order_id
 *
 * No Action Scheduler per-job cancellation anymore.
 */
final class OrderTrashJobsService
{
    private const LOG_PREFIX  = '[FFLHUB][OrderTrashJobs]';
    private const DEBUG_CONST = 'FFLHUB_TRASH_ORDER_JOBS_DEBUG';


    
    private OrderPlacementJobsTable $jobs_table;

    public function __construct(OrderPlacementJobsTable $jobs_table)
    {
        $this->jobs_table = $jobs_table;
    }



    /**
     * Order meta flag: when set, this order is ineligible for polling / dispatch.
     */

    public function register(): void
    {
        add_action('woocommerce_trash_order', [$this, 'handle_post_trashed'], 10, 1);
        add_action('woocommerce_untrash_order', [$this, 'handle_post_untrashed'], 10, 1);
        add_action('woocommerce_before_delete_order', [$this, 'handle_post_deleted_permanently'], 10, 1);
    }

    

    /**
     * Trash behavior: suspend order and pause DB rows.
     */
    public function suspend_order_and_pause_jobs(int $order_id, string $reason = 'Order trashed'): void
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            $this->log_ctx('suspend_skip_invalid_order_id', ['order_id' => $order_id, 'reason' => $reason]);
            return;
        }

        update_post_meta($order_id, OrderPlacementKeys::META_ORDER_SUSPENDED, '1');
        $paused = $this->pause_all_jobs_for_order($order_id, $reason);
    }

    /**
     * Untrash behavior: unsuspend order and resume DB rows.
     */
    public function unsuspend_order_and_resume_jobs(int $order_id, string $reason = 'Order restored from trash'): void
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            $this->log_ctx('unsuspend_skip_invalid_order_id', ['order_id' => $order_id, 'reason' => $reason]);
            return;
        }

        delete_post_meta($order_id, OrderPlacementKeys::META_ORDER_SUSPENDED);
        $resumed = $this->resume_paused_jobs_for_order($order_id, $reason);
    }

    /**
     * Permanent delete behavior: delete DB rows.
     */
    public function delete_jobs_for_order(int $order_id): void
    {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            $this->log_ctx('delete_jobs_skip_invalid_order_id', ['order_id' => $order_id]);
            return;
        }

        $table = $this->jobs_table->get_table_name();
        $deleted = 0;

        try {
            $deleted = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE order_id = %d",
                    $order_id
                )
            );
        } catch (\Throwable $e) {
            $this->log_ctx('delete_jobs_exception', [
                'order_id' => $order_id,
                'err'      => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ]);
            return;
        }
    }

    /* ============================ WP hooks ============================ */

    public function handle_post_trashed(int $post_id): void
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            $this->log_ctx('hook_trash_skip_invalid_id', ['post_id' => $post_id]);
            return;
        }

        if (!$this->is_shop_order_post($post_id)) {
            $this->log_ctx('hook_trash_skip_not_order', [
                'post_id'   => $post_id,
                'post_type' => (string) get_post_type($post_id),
            ]);
            return;
        }
        $this->suspend_order_and_pause_jobs($post_id, 'Order trashed');
    }

    public function handle_post_untrashed(int $post_id): void
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            $this->log_ctx('hook_untrash_skip_invalid_id', ['post_id' => $post_id]);
            return;
        }

        if (!$this->is_shop_order_post($post_id)) {
            $this->log_ctx('hook_untrash_skip_not_order', [
                'post_id'   => $post_id,
                'post_type' => (string) get_post_type($post_id),
            ]);
            return;
        }
        $this->unsuspend_order_and_resume_jobs($post_id, 'Order untrashed');
    }

    public function handle_post_deleted_permanently(int $post_id): void
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            $this->log_ctx('hook_delete_skip_invalid_id', ['post_id' => $post_id]);
            return;
        }

        if (!$this->is_shop_order_post($post_id)) {
            $this->log_ctx('hook_delete_skip_not_order', [
                'post_id'   => $post_id,
                'post_type' => (string) get_post_type($post_id),
            ]);
            return;
        }
        $this->delete_jobs_for_order($post_id);
    }

    private function is_shop_order_post(int $post_id): bool
    {
        $post_type = (string) get_post_type($post_id);

        if ($post_type === 'shop_order') {
            return true;
        }
        if ($post_type === 'shop_order_placehold') {
            return true;
        }

        return false;
    }

    /* ============================ DB mutations ============================ */

    /**
     * Pause all jobs for an order:
     * - status => paused
     * - next_run_at => NULL (avoid invalid DATETIME like '')
     */
    private function pause_all_jobs_for_order(int $order_id, string $reason = ''): int
    {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return 0;
        }

        $table = $this->jobs_table->get_table_name();
        $now   = OrderPlacementTimeUtil::now_mysql_utc();

        $paused_status = (string) OrderPlacementKeys::JOB_STATUS_PAUSED;

        try {
            $affected = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                     SET status = %s,
                         next_run_at = NULL,
                         updated_at = %s,
                         last_error = %s
                     WHERE order_id = %d
                       AND status <> %s",
                    $paused_status,
                    $now,
                    ($reason !== '' ? 'Paused: ' . $reason : 'Paused'),
                    $order_id,
                    (string) OrderPlacementKeys::JOB_STATUS_SUCCESS
                )
            );
            return is_numeric($affected) ? (int) $affected : 0;
        } catch (\Throwable $e) {
            $this->log_ctx('pause_jobs_exception', [
                'order_id' => $order_id,
                'err'      => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Resume paused jobs:
     * - status => scheduled
     * - next_run_at => now (so dispatcher will pick them up immediately)
     *
     * NOTE: we intentionally only resume paused rows.
     */
    private function resume_paused_jobs_for_order(int $order_id, string $reason = ''): int
    {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return 0;
        }

        $table = $this->jobs_table->get_table_name();
        $now   = OrderPlacementTimeUtil::now_mysql_utc();

        $scheduled_status = (string) OrderPlacementKeys::JOB_STATUS_SCHEDULED;
        $paused_status    = (string) OrderPlacementKeys::JOB_STATUS_PAUSED;

        try {
            $affected = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                     SET status = %s,
                         next_run_at = %s,
                         updated_at = %s,
                         last_error = %s
                     WHERE order_id = %d
                       AND status = %s",
                    $scheduled_status,
                    $now,
                    $now,
                    ($reason !== '' ? 'Resumed: ' . $reason : 'Resumed'),
                    $order_id,
                    $paused_status
                )
            );

            return is_numeric($affected) ? (int) $affected : 0;
        } catch (\Throwable $e) {
            $this->log_ctx('resume_jobs_exception', [
                'order_id' => $order_id,
                'err'      => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /* ============================ logging ============================ */

   
    /** @param array<string,mixed> $ctx */
    private static function log_ctx(string $msg, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $msg, $ctx);
    }
}
