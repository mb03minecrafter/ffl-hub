<?php

namespace FFLHub\Distributor\Orders\Shipping;

use FFLHub\Distributor\Orders\OrderPlacementKeys;
use FFLHub\Distributor\Orders\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Phase 1: Shipping poll scheduler + selector.
 *
 * This does NOT call distributor APIs yet.
 * It only selects eligible jobs and logs them, then stamps last_shipping_poll_at.
 */
final class OrderPlacementShippingPoller
{
    private const LOG_PREFIX = '[FFLHUB][ShippingPoller]';

    // How often the poller runs (minutes)
    private const SCHEDULE_EVERY_MINUTES = 15;

    // Minimum spacing between polls per job (minutes)
    private const JOB_MIN_POLL_INTERVAL_MINUTES = 60;

    // How many jobs to process per run
    private const BATCH_LIMIT = 50;

    // Hook name for AS job
    public const AS_HOOK = 'fflhub_place_shipping_poll';

    // Optional group
    private const AS_GROUP = 'fflhub';

    /**
     * Register hooks + ensure recurring schedule exists.
     */
    public static function register(): void
    {
        // Worker entrypoint
        add_action(self::AS_HOOK, [self::class, 'run']);

        // Ensure schedule exists (safe to run on init)
        add_action('init', [self::class, 'ensure_scheduled']);
    }

    /**
     * Create/ensure the recurring poll job exists.
     */
    public static function ensure_scheduled(): void
    {
        if (!function_exists('as_next_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            // Action Scheduler missing; nothing we can do.
            return;
        }

        $existing = as_next_scheduled_action(self::AS_HOOK, [], self::AS_GROUP);
        if (is_numeric($existing) && (int) $existing > 0) {
            return;
        }

        $start = time() + 60; // 1 minute from now
        $interval = self::SCHEDULE_EVERY_MINUTES * 60;

        as_schedule_recurring_action($start, $interval, self::AS_HOOK, [], self::AS_GROUP);

        error_log(self::LOG_PREFIX . ' scheduled recurring poll every ' . self::SCHEDULE_EVERY_MINUTES . ' minutes');
    }

    /**
     * Action Scheduler worker: select jobs needing shipping poll and log them.
     */
    public static function run(): void
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();

        // Poll spacing per row
        $min_interval_seconds = self::JOB_MIN_POLL_INTERVAL_MINUTES * 60;
        $cutoff_unix = time() - $min_interval_seconds;
        $cutoff_mysql_utc = gmdate('Y-m-d H:i:s', $cutoff_unix);

        $limit = self::BATCH_LIMIT;

        // Note: use UTC timestamps (DATETIME stored without TZ; we treat as UTC)
        $sql = $wpdb->prepare(
            "
            SELECT
                id, order_id, job_key, dist_id, bucket, status,
                merchant_po, external_order_id,
                shipped_at, last_shipping_poll_at
            FROM {$table}
            WHERE
                status = %s
                AND merchant_po IS NOT NULL
                AND merchant_po <> ''
                AND (shipped_at IS NULL OR shipped_at = '0000-00-00 00:00:00')
                AND (
                    last_shipping_poll_at IS NULL
                    OR last_shipping_poll_at = '0000-00-00 00:00:00'
                    OR last_shipping_poll_at < %s
                )
            ORDER BY
                last_shipping_poll_at IS NULL DESC,
                last_shipping_poll_at ASC,
                id ASC
            LIMIT %d
            ",
            OrderPlacementKeys::JOB_STATUS_SUCCESS,
            $cutoff_mysql_utc,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            error_log(self::LOG_PREFIX . ' no jobs eligible for shipping poll');
            return;
        }

        error_log(self::LOG_PREFIX . ' eligible jobs=' . count($rows) . ' cutoff=' . $cutoff_mysql_utc);

        foreach ($rows as $r) {
            $order_id = isset($r['order_id']) ? (int) $r['order_id'] : 0;
            $job_key  = isset($r['job_key']) ? (string) $r['job_key'] : '';
            $dist_id  = isset($r['dist_id']) ? (string) $r['dist_id'] : '';
            $bucket   = isset($r['bucket']) ? (string) $r['bucket'] : '';
            $po       = isset($r['merchant_po']) ? (string) $r['merchant_po'] : '';
            $ext      = isset($r['external_order_id']) ? (string) $r['external_order_id'] : '';

            // Stamp last_shipping_poll_at now (so we don't re-log forever if AS runs fast)
            OrderPlacementShippingStore::touch_last_shipping_poll_at($order_id, $job_key);

            error_log(self::LOG_PREFIX . " would_poll order={$order_id} job={$job_key} dist={$dist_id} bucket={$bucket} po={$po} external={$ext}");
        }
    }
}
