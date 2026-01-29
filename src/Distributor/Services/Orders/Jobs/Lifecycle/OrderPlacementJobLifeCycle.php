<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Lifecycle;

use WC_Order;

use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobLifecycle
 *
 * Responsibility:
 * - Job creation/upsert (init)
 * - Core lifecycle state transitions used heavily by JobRunner + StateMachine:
 *     - queued/scheduled/running/success/failed/retry_scheduled
 *     - attempt counting
 *     - schedule/action id clearing in terminal/running transitions
 *
 * Non-responsibilities (deliberately NOT here):
 * - Reading DTOs (repository)
 * - Arbitrary column writes (writer)
 * - Snapshots (validate/place)
 * - Shipping field merges
 * - Cancellation/trash/delete helpers
 *
 * Notes:
 * - This class *does* contain some SQL because init/upsert and atomic increments are
 *   lifecycle-specific and need to be strongly consistent.
 * - Patch writes are delegated to OrderPlacementJobWriter.
 */
final class OrderPlacementJobLifecycle
{
    /* ============================================================
     * Job init / upsert
     * ============================================================ */

    /**
     * Initialize a job row if missing; always overwrite payload_json.
     *
     * @param WC_Order $order The WooCommerce order owning the job rows.
     * @param string $job_key Job key (dist|bucket).
     * @param array<string,mixed> $payload Minimal payload used by JobRunner (dist_id, bucket, lines, etc).
     */
    public static function init_job_meta(WC_Order $order, string $job_key, array $payload): void
    {
        global $wpdb;

        $oid = (int) $order->get_id();
        if ($oid <= 0) {
            return;
        }

        $table = OrderPlacementJobsTable::get_table_name();

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return;
        }

        // Derive dist_id + bucket from payload first, then fallback to job_key convention.
        $dist_id = OrderPlacementKeysUtil::normalize_dist_id((string) ($payload['dist_id'] ?? ''));
        $bucket  = OrderPlacementKeysUtil::normalize_bucket((string) ($payload['bucket'] ?? ''));

        if ($dist_id === '' || $bucket === '') {
            $parts = explode('|', $job_key, 2);
            if ($dist_id === '' && isset($parts[0])) {
                $dist_id = OrderPlacementKeysUtil::normalize_dist_id((string) $parts[0]);
            }
            if ($bucket === '' && isset($parts[1])) {
                $bucket = OrderPlacementKeysUtil::normalize_bucket((string) $parts[1]);
            }
        }

        if ($dist_id === '') {
            $dist_id = 'unknown';
        }
        if (!OrderPlacementKeysUtil::is_valid_bucket($bucket)) {
            $bucket = 'unknown';
        }

        $now = OrderPlacementTimeUtil::now_mysql_utc();

        $payload_json = wp_json_encode($payload);
        if (!is_string($payload_json) || $payload_json === '') {
            // Extremely defensive fallback: never write invalid JSON.
            $payload_json = wp_json_encode([]);
        }

        $sql = "
            INSERT INTO {$table}
            (order_id, job_key, dist_id, bucket, status, attempts, created_at, updated_at,
             action_id, next_run_at, last_step, last_error, last_codes_json, done_at,
             payload_json, validate_result_json, place_result_json, merchant_po, external_order_ids_json)
            VALUES
            (%d, %s, %s, %s, %s, %d, %s, %s,
             NULL, NULL, %s, %s, %s, NULL,
             %s, NULL, NULL, NULL, NULL)
            ON DUPLICATE KEY UPDATE
              payload_json = VALUES(payload_json),
              updated_at   = VALUES(updated_at),
              dist_id      = VALUES(dist_id),
              bucket       = VALUES(bucket)
        ";

        $wpdb->query(
            $wpdb->prepare(
                $sql,
                $oid,
                $job_key,
                $dist_id,
                $bucket,
                OrderPlacementKeys::JOB_STATUS_QUEUED,
                0,
                $now,
                $now,
                '',
                '',
                wp_json_encode([]),
                $payload_json
            )
        );
    }

    /* ============================================================
     * Status
     * ============================================================ */

    public static function set_job_status(WC_Order $order, string $job_key, string $status): void
    {
        $patch = OrderPlacementJobPatch::empty()
            ->with_status((string) $status);

        OrderPlacementJobWriter::apply_patch_for_order($order, $job_key, $patch);
    }

    public static function get_job_status(WC_Order $order, string $job_key): string
    {
        global $wpdb;

        $oid = (int) $order->get_id();
        if ($oid <= 0) {
            return '';
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return '';
        }

        $table = OrderPlacementJobsTable::get_table_name();

        $status = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM {$table} WHERE order_id = %d AND job_key = %s LIMIT 1",
                $oid,
                $job_key
            )
        );

        return is_string($status) ? (string) $status : '';
    }

    /* ============================================================
     * Attempts + running transition
     * ============================================================ */

    public static function increment_job_attempts_and_mark_running(WC_Order $order, string $job_key): int
    {
        global $wpdb;

        $oid = (int) $order->get_id();
        if ($oid <= 0) {
            return 0;
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return 0;
        }

        $table = OrderPlacementJobsTable::get_table_name();
        $now   = OrderPlacementTimeUtil::now_mysql_utc();

        // Only claim if currently eligible.
        $eligible = [
            (string) OrderPlacementKeys::JOB_STATUS_SCHEDULED,
            (string) OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED,
        ];

        $in_placeholders = implode(',', array_fill(0, count($eligible), '%s'));

        $sql = "
        UPDATE {$table}
        SET
            attempts   = attempts + 1,
            status     = %s,
            next_run_at = NULL,
            action_id  = NULL,
            updated_at = %s
        WHERE
            order_id = %d
            AND job_key = %s
            AND status IN ({$in_placeholders})
    ";

        $params = array_merge(
            [(string) OrderPlacementKeys::JOB_STATUS_RUNNING, (string) $now, $oid, (string) $job_key],
            $eligible
        );

        $affected = $wpdb->query($wpdb->prepare($sql, $params));

        // If we didn't claim it, someone else has it (or it's not eligible anymore).
        if (!is_numeric($affected) || (int) $affected !== 1) {
            return 0;
        }

        // Best-effort read-back of attempts for logging/backoff decisions.
        $attempts = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT attempts FROM {$table} WHERE order_id = %d AND job_key = %s LIMIT 1",
                $oid,
                $job_key
            )
        );

        return is_numeric($attempts) ? (int) $attempts : 0;
    }


    /* ============================================================
     * Terminal transitions
     * ============================================================ */

    public static function mark_job_success(WC_Order $order, string $job_key, string $done_at = ''): void
    {
        $done_mysql = ($done_at !== '')
            ? OrderPlacementTimeUtil::iso_to_mysql_utc((string) $done_at)
            : OrderPlacementTimeUtil::now_mysql_utc();

        if ($done_mysql === '') {
            $done_mysql = OrderPlacementTimeUtil::now_mysql_utc();
        }

        $patch = OrderPlacementJobPatch::empty()
            ->with_status(OrderPlacementKeys::JOB_STATUS_SUCCESS)
            ->with_field('done_at', $done_mysql)
            ->with_field('last_error', '')
            ->with_last_codes([])
            ->clear_action_and_schedule();

        OrderPlacementJobWriter::apply_patch_for_order($order, $job_key, $patch);
    }

    public static function mark_job_failed(WC_Order $order, string $job_key, string $error_message): void
    {
        $patch = OrderPlacementJobPatch::empty()
            ->with_status(OrderPlacementKeys::JOB_STATUS_FAILED)
            ->with_last_error((string) $error_message)
            ->clear_action_and_schedule();

        OrderPlacementJobWriter::apply_patch_for_order($order, $job_key, $patch);
    }

    public static function mark_job_retry_scheduled(
        WC_Order $order,
        string $job_key,
        string $next_run_at_iso,
        string $reason,
        array $codes = [],
        string $step = ''
    ): void {
        $step = strtolower(trim((string) $step));
        if ($step !== 'validate' && $step !== 'place') {
            $step = '';
        }

        $next_mysql = OrderPlacementTimeUtil::iso_to_mysql_utc((string) $next_run_at_iso);

        $patch = OrderPlacementJobPatch::empty()
            ->with_status(OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED)
            ->with_last_error((string) $reason)
            ->with_last_codes(is_array($codes) ? $codes : [])
            ->with_next_run_at_mysql(($next_mysql !== '') ? $next_mysql : null)
            ->with_last_step($step)
            ->with_action_id(null);

        OrderPlacementJobWriter::apply_patch_for_order($order, $job_key, $patch);
    }
}
