<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Diagnostics;

use WC_Order;

use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobDiagnosticsStore
 *
 * Responsibility:
 * - Maintain "quick glance" operational / diagnostic fields on the job row:
 *   - last_step        (e.g. validate|place|'' depending on your state machine)
 *   - last_error       (human-readable message for operators/logs)
 *   - last_codes_json  (machine-friendly string[] codes)
 *   - next_run_at      (scheduler hint; MySQL UTC DATETIME)
 *   - action_id        (Action Scheduler action ID, if applicable)
 *
 * These fields are NOT business outcomes:
 * - Not correlation identifiers (merchant_po / external IDs).
 * - Not shipping merges/tracking.
 * - Not snapshots of validate/place responses.
 *
 * Primary use cases:
 * - Retry scheduling/backoff coordination
 * - Runner/state-machine bookkeeping
 * - Admin UI and operational debugging
 *
 * Write strategy:
 * - Uses OrderPlacementJobWriter patch application (preferred; allowlist + updated_at stamping).
 *
 * Read strategy:
 * - Scalar reads directly from the jobs table to avoid fetching full DTO rows.
 *   (You can swap to repository-based reads later if you decide the DTO cost is acceptable.)
 *
 * Dependency:
 * - Requires an instantiated OrderPlacementJobsTable manager for table name resolution.
 */
final class OrderPlacementJobDiagnosticsStore
{
    /* ============================================================
     * last_error
     * ============================================================ */

    /**
     * Persist last_error for a job.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key    Job key (dist|lane).
     * @param string                  $message    Error message (caller should avoid secrets).
     */
    public static function set_job_last_error(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        string $message
    ): void {
        $patch = OrderPlacementJobPatch::empty()
            ->with_last_error((string) $message);

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }

    /**
     * Read last_error for a job.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key    Job key (dist|lane).
     * @return string Last error message, or '' if none/missing.
     */
    public static function get_job_last_error(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key
    ): string {
        $v = self::read_job_scalar($jobs_table, (int) $order->get_id(), $job_key, 'last_error');
        return is_string($v) ? trim((string) $v) : '';
    }

    /* ============================================================
     * last_codes_json
     * ============================================================ */

    /**
     * Persist last_codes_json (error code list) for a job.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key    Job key (dist|lane).
     * @param array<int,mixed>        $codes      Code list; will be normalized by patch writer.
     */
    public static function set_job_last_error_codes(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        array $codes
    ): void {
        $patch = OrderPlacementJobPatch::empty()
            ->with_last_codes(is_array($codes) ? $codes : []);

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }

    /**
     * Read last_codes_json (error codes) for a job.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key    Job key (dist|lane).
     * @return string[] Normalized, unique, capped list of codes.
     */
    public static function get_job_last_error_codes(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key
    ): array {
        $json = self::read_job_scalar($jobs_table, (int) $order->get_id(), $job_key, 'last_codes_json');

        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Normalize as string[].
        $out = [];
        foreach ($decoded as $v) {
            $s = trim((string) $v);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        $out = array_values(array_unique($out));
        if (count($out) > 25) {
            $out = array_slice($out, 0, 25);
        }

        return $out;
    }

    /* ============================================================
     * last_step
     * ============================================================ */

    /**
     * Persist last_step for a job.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key    Job key (dist|lane).
     * @param string                  $step       Step label (e.g. validate|place|'').
     */
    public static function set_job_last_step(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        string $step
    ): void {
        $patch = OrderPlacementJobPatch::empty()
            ->with_last_step((string) $step);

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }

    /**
     * Read last_step for a job.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key    Job key (dist|lane).
     * @return string Step label, or '' if missing.
     */
    public static function get_job_last_step(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key
    ): string {
        $v = self::read_job_scalar($jobs_table, (int) $order->get_id(), $job_key, 'last_step');
        return is_string($v) ? trim((string) $v) : '';
    }

    /* ============================================================
     * next_run_at
     * ============================================================ */

    /**
     * Persist next_run_at for a job (from ISO -> MySQL UTC).
     *
     * @param OrderPlacementJobsTable $jobs_table      Table manager instance.
     * @param WC_Order                $order           WooCommerce order.
     * @param string                  $job_key         Job key (dist|lane).
     * @param string                  $next_run_at_iso ISO 8601 timestamp; invalid/empty clears next_run_at.
     */
    public static function set_job_next_run_at(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        string $next_run_at_iso
    ): void {
        $mysql = OrderPlacementTimeUtil::iso_to_mysql_utc($next_run_at_iso);

        $patch = OrderPlacementJobPatch::empty()
            ->with_next_run_at_mysql(($mysql !== '') ? $mysql : null);

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }

    /**
     * Read next_run_at for a job (MySQL UTC -> ISO).
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key    Job key (dist|lane).
     * @return string ISO timestamp, or '' if missing.
     */
    public static function get_job_next_run_at(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key
    ): string {
        $mysql = self::read_job_scalar($jobs_table, (int) $order->get_id(), $job_key, 'next_run_at');
        if (!is_string($mysql) || trim($mysql) === '') {
            return '';
        }

        return OrderPlacementTimeUtil::mysql_utc_to_iso((string) $mysql);
    }

    /* ============================================================
     * action_id
     * ============================================================ */

    /**
     * Persist Action Scheduler action_id for a job.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key    Job key (dist|lane).
     * @param string                  $action_id  Action ID string (digits). Invalid/empty clears action_id.
     */
    public static function set_job_action_id(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        string $action_id
    ): void {
        $aid = trim((string) $action_id);
        $aid_i = ($aid !== '' && ctype_digit($aid)) ? (int) $aid : 0;

        $patch = OrderPlacementJobPatch::empty()
            ->with_action_id(($aid_i > 0) ? $aid_i : null);

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }

    /**
     * Read Action Scheduler action_id for a job.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key    Job key (dist|lane).
     * @return string Action ID as string, or '' if missing.
     */
    public static function get_job_action_id(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key
    ): string {
        $v = self::read_job_scalar($jobs_table, (int) $order->get_id(), $job_key, 'action_id');

        if ($v === null) {
            return '';
        }

        $i = (int) $v;
        return ($i > 0) ? (string) $i : '';
    }

    /**
     * Clear action_id for a job.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order.
     * @param string                  $job_key    Job key (dist|lane).
     */
    public static function clear_job_action_id(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key
    ): void {
        $patch = OrderPlacementJobPatch::empty()
            ->with_action_id(null);

        OrderPlacementJobWriter::apply_patch_for_order($jobs_table, $order, $job_key, $patch);
    }

    /* ============================================================
     * Internal helpers (lightweight reads)
     * ============================================================ */

    /**
     * Lightweight scalar read from the jobs table.
     *
     * Safety:
     * - Column name is hard-allowlisted to prevent SQL injection.
     * - job_key is normalized.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param int                     $order_id   Woo order ID.
     * @param string                  $job_key    Job key (dist|lane).
     * @param string                  $column     Allowlisted column name.
     * @return mixed Scalar value or null if missing/invalid.
     */
    private static function read_job_scalar(
        OrderPlacementJobsTable $jobs_table,
        int $order_id,
        string $job_key,
        string $column
    ) {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return null;
        }

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return null;
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return null;
        }

        // Only allow known columns to avoid SQL injection through column name.
        $allowed = [
            'last_error',
            'last_codes_json',
            'last_step',
            'next_run_at',
            'action_id',
        ];

        if (!in_array($column, $allowed, true)) {
            return null;
        }

        // Column is allowlisted; safe to interpolate.
        $sql = $wpdb->prepare(
            "SELECT {$column} FROM {$table} WHERE order_id = %d AND job_key = %s LIMIT 1",
            $order_id,
            $job_key
        );

        return $wpdb->get_var($sql);
    }
}
