<?php

namespace FFLHub\Distributor\Orders\Shipping;

use FFLHub\Distributor\Orders\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Phase 1 shipping store helpers (table-backed).
 *
 * We keep this separate from OrderPlacementJobsStore so we don’t have to
 * refactor your whole store class yet.
 */
final class OrderPlacementShippingStore
{
    private const LOG_PREFIX = '[FFLHUB][ShippingStore]';

    /**
     * Stamp last_shipping_poll_at = now (UTC).
     */
    public static function touch_last_shipping_poll_at(int $order_id, string $job_key): void
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();
        $now_utc = gmdate('Y-m-d H:i:s');

        $updated = $wpdb->update(
            $table,
            [
                'last_shipping_poll_at' => $now_utc,
                'updated_at'            => $now_utc,
            ],
            [
                'order_id' => (int) $order_id,
                'job_key'  => (string) $job_key,
            ],
            ['%s', '%s'],
            ['%d', '%s']
        );

        if ($updated === false) {
            error_log(self::LOG_PREFIX . " failed touch_last_shipping_poll_at order={$order_id} job={$job_key}");
        }
    }

    /**
     * Phase 1 placeholder: allow setting external_order_id later.
     */
    public static function set_external_order_id(int $order_id, string $job_key, string $external_order_id): void
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();
        $now_utc = gmdate('Y-m-d H:i:s');

        $external_order_id = trim((string) $external_order_id);

        $updated = $wpdb->update(
            $table,
            [
                'external_order_id' => $external_order_id !== '' ? $external_order_id : null,
                'updated_at'        => $now_utc,
            ],
            [
                'order_id' => (int) $order_id,
                'job_key'  => (string) $job_key,
            ],
            ['%s', '%s'],
            ['%d', '%s']
        );

        if ($updated === false) {
            error_log(self::LOG_PREFIX . " failed set_external_order_id order={$order_id} job={$job_key}");
        }
    }
}
