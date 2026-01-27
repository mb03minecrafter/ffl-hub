<?php

namespace FFLHub\Distributor\Services\Orders;

use FFLHub\Distributor\Services\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cancels future order-placement actions when an order is trashed,
 * and deletes job rows only when the order is permanently deleted.
 *
 * Source of truth:
 * - "order is trashed/suspended" => order meta
 * - scheduled actions to cancel => jobs table action_id column
 */
final class OrderTrashJobsService
{
    private const LOG_PREFIX = '[FFLHUB][OrderTrashJobs]';

    /**
     * Order meta flag: when set, this order is ineligible for polling / jobs.
     */
    public const META_ORDER_SUSPENDED = 'fflhub_order_jobs_suspended';

    public static function init(): void
    {
        // When order is moved to Trash
        add_action('woocommerce_trash_order', [self::class, 'handle_post_trashed'], 10, 1);

        // When order is restored from Trash
        add_action('woocommerce_trash_order', [self::class, 'handle_post_untrashed'], 10, 1);

        // Permanent delete ONLY (does not run on trash)
        add_action('woocommerce_before_delete_order', [self::class, 'handle_post_deleted_permanently'], 10, 1);
    }

    public static function is_order_suspended(int $order_id): bool
    {
        $v = get_post_meta($order_id, self::META_ORDER_SUSPENDED, true);
        return ((string) $v === '1');
    }

    public static function suspend_order_and_cancel_future_jobs(int $order_id, string $reason = 'Order trashed'): void
    {
        if ($order_id <= 0) return;

        update_post_meta($order_id, self::META_ORDER_SUSPENDED, '1');

        // Cancel future actions using jobs table action_id column.
        $action_ids = OrderPlacementJobsStore::get_future_action_ids_for_order($order_id);

        foreach ($action_ids as $aid) {
            self::cancel_action_id($aid);
        }

        // Optional: clear action_id/next_run_at on those rows so UI reflects reality
        if (!empty($action_ids)) {
            OrderPlacementJobsStore::clear_actions_for_order($order_id, $reason);
        }

        error_log(self::LOG_PREFIX . " suspended order={$order_id} cancelled_actions=" . count($action_ids));
    }

    public static function unsuspend_order(int $order_id): void
    {
        if ($order_id <= 0) return;
        delete_post_meta($order_id, self::META_ORDER_SUSPENDED);
        error_log(self::LOG_PREFIX . " unsuspended order={$order_id}");
    }

    public static function delete_jobs_for_order(int $order_id): void
    {
        if ($order_id <= 0) return;

        // Safety: cancel any future actions we still know about BEFORE deleting rows.
        $action_ids = OrderPlacementJobsStore::get_future_action_ids_for_order($order_id);
        foreach ($action_ids as $aid) {
            self::cancel_action_id($aid);
        }

        OrderPlacementJobsStore::delete_jobs_for_order($order_id);

        error_log(self::LOG_PREFIX . " deleted job rows for order={$order_id} cancelled_actions=" . count($action_ids));
    }

    /* ============================ WP hooks ============================ */

    public static function handle_post_trashed(int $post_id): void
    {
        if (!self::is_shop_order_post($post_id)) return;

        self::suspend_order_and_cancel_future_jobs($post_id, 'Order trashed');
    }

    public static function handle_post_untrashed(int $post_id): void
    {
        if (!self::is_shop_order_post($post_id)) return;

        self::unsuspend_order($post_id);
    }

    public static function handle_post_deleted_permanently(int $post_id): void
    {

        if (!self::is_shop_order_post($post_id)) return;

        // Permanent delete: blow away job rows (and any future actions)
        self::delete_jobs_for_order($post_id);
    }

    private static function is_shop_order_post(int $post_id): bool
    {
        $post_type = get_post_type($post_id);
        return ($post_type === 'shop_order' || $post_type === 'shop_order_placehold'); // keep 2nd only if you use it
    }

    /**
     * Cancel an Action Scheduler action id safely.
     */
    private static function cancel_action_id(int $action_id): void
    {
        $action_id = (int) $action_id;
        if ($action_id <= 0) return;

        if (!class_exists('\ActionScheduler_Store')) {
            error_log(self::LOG_PREFIX . " ActionScheduler_Store not available for cancel action_id={$action_id}");
            return;
        }

        try {
            $store = \ActionScheduler_Store::instance();
            $store->cancel_action($action_id);
        } catch (\Throwable $e) {
            error_log(
                self::LOG_PREFIX .
                    " failed cancel action_id={$action_id} err=" .
                    $e->getMessage()
            );
        }
    }
}
