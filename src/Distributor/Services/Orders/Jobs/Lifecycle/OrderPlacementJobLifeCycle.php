<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Lifecycle;

use WC_Order;

use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobLifecycle
 *
 * Responsibility:
 * - Job row init/upsert (create if missing; keep identifiers stable).
 * - Core lifecycle transitions used by JobRunner/StateMachine:
 *   - queued/scheduled/running/success/failed/retry_scheduled
 *   - attempt counting and atomic "claim" for running
 *   - schedule/action id clearing in terminal/running transitions
 *
 * Non-responsibilities:
 * - Reading DTOs (repository)
 * - Arbitrary column writes (writer)
 * - Snapshots (validate/place)
 * - Shipping field merges
 * - Cancellation/trash/delete helpers
 *
 * Notes:
 * - This class contains some SQL where atomicity/consistency matters
 *   (init/upsert + atomic attempts increment + claim).
 * - Patch-style updates are delegated to OrderPlacementJobWriter.
 */
final class OrderPlacementJobLifecycle
{
    /* ============================================================
     * Job init / upsert
     * ============================================================ */

    /**
     * Initialize a job row if missing; always overwrite payload_json.
     *
     * Behavior:
     * - INSERT if missing (creates row with status=queued, attempts=0).
     * - If the row already exists (UNIQUE order_id+job_key), updates ONLY:
     *     payload_json, updated_at, dist_id, bucket
     *   (does NOT overwrite status/attempts/etc).
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order owning the job row.
     * @param string                  $job_key    Job key (dist|bucket). Normalized before use.
     * @param array<string,mixed>     $payload    Minimal payload used by JobRunner (dist_id, bucket, lines, etc).
     */
    public static function init_job_meta(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        array $payload
    ): void {
        global $wpdb;

        $oid = (int) $order->get_id();
        if ($oid <= 0) {
            return;
        }

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return;
        }

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
            $payload_json = '[]';
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
                '',               // last_step
                '',               // last_error
                wp_json_encode([]),// last_codes_json
                $payload_json
            )
        );
    }

    /* ============================================================
     * Status
     * ============================================================ */

    /**
     * Patch-write: set status for a job row.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key    Job key (dist|bucket).
     * @param string                  $status     New status.
     */
    public static function set_job_status(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        string $status
    ): void {
        $patch = OrderPlacementJobPatch::empty()
            ->with_status((string) $status);

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }

    /**
     * Read-only: get current status for a job row.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key    Job key (dist|bucket). Normalized before use.
     * @return string Status, or empty string if missing/invalid.
     */
    public static function get_job_status(OrderPlacementJobsTable $jobs_table, WC_Order $order, string $job_key): string
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

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return '';
        }

        $status = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM {$table} WHERE order_id = %d AND job_key = %s LIMIT 1",
                $oid,
                $job_key
            )
        );

        return is_string($status) ? $status : '';
    }

    /* ============================================================
     * Attempts + running transition
     * ============================================================ */

    /**
     * Atomically claim a job for execution:
     * - increments attempts
     * - transitions status to RUNNING
     * - clears next_run_at and action_id
     *
     * Only succeeds if current status is scheduled or retry_scheduled.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key    Job key (dist|bucket). Normalized before use.
     * @return int Attempts after increment; 0 if not claimed.
     */
    public static function increment_job_attempts_and_mark_running(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key
    ): int {
        global $wpdb;

        $oid = (int) $order->get_id();
        if ($oid <= 0) {
            return 0;
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return 0;
        }

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return 0;
        }

        $now = OrderPlacementTimeUtil::now_mysql_utc();

        $eligible = [
            (string) OrderPlacementKeys::JOB_STATUS_SCHEDULED,
            (string) OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED,
        ];

        $in_placeholders = implode(',', array_fill(0, count($eligible), '%s'));

        $sql = "
            UPDATE {$table}
            SET
                attempts    = attempts + 1,
                status      = %s,
                next_run_at = NULL,
                action_id   = NULL,
                updated_at  = %s
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
        if (!is_numeric($affected) || (int) $affected !== 1) {
            return 0;
        }

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

    public static function mark_job_success(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        string $done_at = ''
    ): void {
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

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }

    public static function mark_job_failed(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        string $error_message
    ): void {
        $patch = OrderPlacementJobPatch::empty()
            ->with_status(OrderPlacementKeys::JOB_STATUS_FAILED)
            ->with_last_error((string) $error_message)
            ->clear_action_and_schedule();

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }

    public static function mark_job_retry_scheduled(
        OrderPlacementJobsTable $jobs_table,
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

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }
}
