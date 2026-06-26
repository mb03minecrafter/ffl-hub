<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Lifecycle;

use WC_Order;

use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementProductUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobLifeCycle
 *
 * Responsibility:
 * - Create/init job rows (upsert-by order_id+job_key) while keeping stable identifiers.
 * - Provide durable lifecycle transitions used by the JobRunner/StateMachine:
 *     - queued / scheduled / running / success / manual / failed / retry_scheduled
 *     - atomic "claim" for execution (attempts++ and status transition)
 *     - clearing schedule metadata (next_run_at, action_id) on transitions where it must not remain
 *
 * Non-responsibilities:
 * - Reading DTOs (repository)
 * - Arbitrary column writes (writer)
 * - Snapshot persistence (validate/place)
 * - Shipping field merges
 * - Cancellation/trash/delete helpers
 *
 * Architecture note (DB-only retry scheduling):
 * - Per-job Action Scheduler actions are disabled.
 * - action_id is kept NULL for all job rows.
 * - next_run_at (MySQL UTC datetime) is used by a recurring dispatcher to pull ready rows.
 *
 * Safety:
 * - job_key/dist_id/lane are normalized and validated.
 * - Atomic claim is implemented with a single UPDATE constrained by eligible statuses.
 */
final class OrderPlacementJobLifeCycle
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
     *     - payload_json
     *     - updated_at
     *     - dist_id
     *     - lane
     *   Does NOT overwrite status/attempts/merchant_po/external ids/shipping fields/snapshots/etc.
     *
     * Important:
     * - This is the *only* place that creates new rows for lane jobs.
     * - payload_json is considered the canonical "job payload" for the runner.
     *
     * @param OrderPlacementJobsTable $jobs_table Table helper (provides physical table name).
     * @param WC_Order $order WooCommerce order.
     * @param string $job_key Job key (dist|lane). Normalized before use.
     * @param array<string,mixed> $payload Minimal payload used by runner (dist_id,lane,lines,...).
     * @return void
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

        // Derive dist_id + lane from payload first, then fallback to job_key convention.
        $dist_id = OrderPlacementKeysUtil::normalize_dist_id((string) ($payload['dist_id'] ?? ''));
        $lane    = OrderPlacementKeysUtil::normalize_lane((string) ($payload['lane'] ?? ''));

        if ($dist_id === '' || $lane === '') {
            $parts = explode('|', $job_key, 2);
            if ($dist_id === '' && isset($parts[0])) {
                $dist_id = OrderPlacementKeysUtil::normalize_dist_id((string) $parts[0]);
            }
            if ($lane === '' && isset($parts[1])) {
                $lane = OrderPlacementKeysUtil::normalize_lane((string) $parts[1]);
            }
        }

        if ($dist_id === '') {
            $dist_id = 'unknown';
        }
        if (!OrderPlacementKeysUtil::is_valid_lane($lane)) {
            $lane = 'unknown';
        }

        $now = OrderPlacementTimeUtil::now_mysql_utc();

        $payload_json = wp_json_encode($payload);
        if (!is_string($payload_json) || $payload_json === '') {
            $payload_json = '[]';
        }

        $empty_codes_json = wp_json_encode([]);
        if (!is_string($empty_codes_json) || $empty_codes_json === '') {
            $empty_codes_json = '[]';
        }

        // NOTE: Column list must match your table schema.
        $sql = "
            INSERT INTO {$table}
            (order_id, job_key, dist_id, lane, status, attempts, created_at, updated_at,
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
              lane         = VALUES(lane)
        ";

        $wpdb->query(
            $wpdb->prepare(
                $sql,
                $oid,
                $job_key,
                $dist_id,
                $lane,
                (string) OrderPlacementKeys::JOB_STATUS_QUEUED,
                0,
                $now,
                $now,
                '',               // last_step
                '',               // last_error
                $empty_codes_json,// last_codes_json
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
     * This is a low-level helper. Prefer higher-level transitions:
     * - mark_job_success()
     * - mark_job_failed()
     * - mark_job_retry_scheduled()
     * - increment_job_attempts_and_mark_running()
     *
     * @param OrderPlacementJobsTable $jobs_table
     * @param WC_Order $order
     * @param string $job_key Job key (dist|lane).
     * @param string $status New status.
     * @return void
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
     * @param OrderPlacementJobsTable $jobs_table
     * @param WC_Order $order
     * @param string $job_key Job key (dist|lane). Normalized before use.
     * @return string Status, or '' if missing/invalid.
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

        return is_string($status) ? (string) $status : '';
    }

    /* ============================================================
     * Attempts + running transition (atomic claim)
     * ============================================================ */

    /**
     * Atomically claim a job for execution.
     *
     * Semantics:
     * - This is the primary concurrency gate for workers.
     * - Performs a single conditional UPDATE that:
     *     - increments attempts
     *     - transitions status => running
     *     - clears next_run_at + action_id (DB-only; keep action_id NULL always)
     *     - sets updated_at
     * - Only succeeds if current status is in the eligible set:
     *     - scheduled
     *     - retry_scheduled
     *
     * Return:
     * - attempts after increment (>= 1) if claimed
     * - 0 if not claimed (missing row, invalid key, or status not eligible)
     *
     * @param OrderPlacementJobsTable $jobs_table
     * @param WC_Order $order
     * @param string $job_key Job key (dist|lane). Normalized before use.
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

    /**
     * Transition job row to SUCCESS (terminal).
     *
     * Writes:
     * - status=success
     * - done_at=<mysql utc> (either converted from ISO or now)
     * - last_error='' (cleared)
     * - last_codes_json=[] (cleared)
     * - next_run_at=NULL and action_id=NULL (cleared)
     *
     * @param OrderPlacementJobsTable $jobs_table
     * @param WC_Order $order
     * @param string $job_key Job key (dist|lane).
     * @param string $done_at Optional ISO8601 timestamp; if empty uses now.
     * @return void
     */
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
            ->with_last_codes([]) // clears last_codes_json
            ->clear_action_and_schedule();

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }

    /**
     * Transition job row to MANUAL (terminal for automation).
     *
     * Writes:
     * - status=manual
     * - last_error=<message> (optional context for operators)
     * - next_run_at=NULL and action_id=NULL (cleared)
     *
     * @param OrderPlacementJobsTable $jobs_table
     * @param WC_Order $order
     * @param string $job_key Job key (dist|lane).
     * @param string $message Optional manual context.
     * @return void
     */
    public static function mark_job_manual(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        string $message = ''
    ): void {
        $patch = OrderPlacementJobPatch::empty()
            ->with_status(OrderPlacementKeys::JOB_STATUS_MANUAL)
            ->with_last_error((string) $message)
            ->clear_action_and_schedule();

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }

    /**
     * Transition job row to AWAITING_ACK (non-terminal async hold).
     *
     * Bill Hicks uploads an 850 file first, then posts a later 855
     * acknowledgement. This state parks the job between those two events
     * without retrying or marking the order successful too early.
     *
     * @param array<int,string> $codes
     */
    public static function mark_job_awaiting_ack(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        string $message = '',
        array $codes = []
    ): void {
        /** @var string[] $codes_norm */
        $codes_norm = OrderPlacementProductUtil::normalize_external_ids($codes);

        $patch = OrderPlacementJobPatch::empty()
            ->with_status(OrderPlacementKeys::JOB_STATUS_AWAITING_ACK)
            ->with_last_step('place')
            ->with_last_error((string) $message)
            ->with_last_codes($codes_norm)
            ->clear_action_and_schedule();

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }

    /**
     * Transition job row to FAILED (terminal).
     *
     * Writes:
     * - status=failed
     * - last_error=<message>
     * - next_run_at=NULL and action_id=NULL (cleared)
     *
     * @param OrderPlacementJobsTable $jobs_table
     * @param WC_Order $order
     * @param string $job_key Job key (dist|lane).
     * @param string $error_message Failure reason.
     * @return void
     */
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

    /**
     * Transition job row to RETRY_SCHEDULED (non-terminal).
     *
     * IMPORTANT:
     * - This method expects next_run_at as a **MySQL UTC datetime** string.
     *   (That is what the dispatcher queries against.)
     * - action_id remains NULL (DB-only; per-job actions are disabled).
     *
     * Writes (single patch):
     * - status=retry_scheduled
     * - last_error=<reason>
     * - last_codes_json=<codes>
     * - last_step=<step> (optional)
     * - next_run_at=<mysql utc> (nullable if invalid)
     * - action_id=NULL
     *
     * @param OrderPlacementJobsTable $jobs_table
     * @param WC_Order $order
     * @param string $job_key Job key (dist|lane).
     * @param string $next_run_at_mysql_utc MySQL UTC datetime (e.g., '2026-01-31 19:30:00').
     * @param string $reason Human-readable retry reason.
     * @param array<int,string> $codes Optional machine-readable codes (normalized/deduped).
     * @param string $step Optional stage label ('validate'|'place' or freeform).
     * @return void
     */
    public static function mark_job_retry_scheduled(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        string $next_run_at_mysql_utc,
        string $reason,
        array $codes = [],
        string $step = ''
    ): void {
        $step = strtolower(trim((string) $step));

        // Keep step constrained if you want predictable UI filters.
        // If you prefer freeform, delete this allowlist.
        if ($step !== 'validate' && $step !== 'place' && $step !== 'shipping') {
            $step = ($step !== '') ? $step : '';
        }

        /** @var string[] $codes_norm */
        $codes_norm = OrderPlacementProductUtil::normalize_external_ids($codes);

        // Defensive normalize: accept either mysql utc or iso; convert to mysql utc if needed.
        $next_mysql = trim((string) $next_run_at_mysql_utc);

        if ($next_mysql !== '') {
            // If it *looks* like ISO, convert. Otherwise assume already mysql UTC.
            // ISO typically contains 'T' or ends with 'Z' or timezone offset.
            if (strpos($next_mysql, 'T') !== false || strpos($next_mysql, 'Z') !== false || preg_match('/[+\-]\d{2}:\d{2}$/', $next_mysql)) {
                $tmp = OrderPlacementTimeUtil::iso_to_mysql_utc($next_mysql);
                $next_mysql = ($tmp !== '') ? $tmp : '';
            }
        }

        $patch = OrderPlacementJobPatch::empty()
            ->with_status(OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED)
            ->with_last_error((string) $reason)
            ->with_last_codes($codes_norm)
            ->with_next_run_at_mysql(($next_mysql !== '') ? $next_mysql : null)
            ->with_last_step($step)
            ->with_action_id(null);

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }
}
