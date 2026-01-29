<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Diagnostics;

use WC_Order;

use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobDiagnosticsStore
 *
 * Responsibility:
 * - “Quick glance” operational fields on the job row:
 *     - last_step
 *     - last_error
 *     - last_codes_json
 *     - next_run_at
 *     - action_id
 *
 * These fields are NOT business outcomes (like merchant_po, external IDs, shipping merges, etc.).
 * They exist primarily for:
 * - retries / scheduling / observability
 * - admin UI debugging
 * - runner/state-machine bookkeeping
 *
 * Write strategy:
 * - Uses OrderPlacementJobWriter::apply_patch_for_order() (preferred).
 *
 * Read strategy:
 * - Lightweight scalar reads directly from the jobs table to avoid pulling full DTO rows.
 *   (Once OrderPlacementJobsRepository exists, you can swap reads to the repo if desired.)
 */
final class OrderPlacementJobDiagnosticsStore
{
    /* ============================================================
     * last_error
     * ============================================================ */

    public static function set_job_last_error(WC_Order $order, string $job_key, string $message): void
    {
        $patch = OrderPlacementJobPatch::empty()
            ->with_last_error((string) $message);

        OrderPlacementJobWriter::apply_patch_for_order($order, $job_key, $patch);
    }

    public static function get_job_last_error(WC_Order $order, string $job_key): string
    {
        $v = self::read_job_scalar((int) $order->get_id(), $job_key, 'last_error');
        return is_string($v) ? (string) $v : '';
    }

    /* ============================================================
     * last_codes_json
     * ============================================================ */

    public static function set_job_last_error_codes(WC_Order $order, string $job_key, array $codes): void
    {
        $patch = OrderPlacementJobPatch::empty()
            ->with_last_codes(is_array($codes) ? $codes : []);

        OrderPlacementJobWriter::apply_patch_for_order($order, $job_key, $patch);
    }

    public static function get_job_last_error_codes(WC_Order $order, string $job_key): array
    {
        $json = self::read_job_scalar((int) $order->get_id(), $job_key, 'last_codes_json');

        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Normalize as string[]
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

    public static function set_job_last_step(WC_Order $order, string $job_key, string $step): void
    {
        $patch = OrderPlacementJobPatch::empty()
            ->with_last_step((string) $step);

        OrderPlacementJobWriter::apply_patch_for_order($order, $job_key, $patch);
    }

    public static function get_job_last_step(WC_Order $order, string $job_key): string
    {
        $v = self::read_job_scalar((int) $order->get_id(), $job_key, 'last_step');
        return is_string($v) ? (string) $v : '';
    }

    /* ============================================================
     * next_run_at
     * ============================================================ */

    public static function set_job_next_run_at(WC_Order $order, string $job_key, string $next_run_at_iso): void
    {
        $mysql = OrderPlacementTimeUtil::iso_to_mysql_utc($next_run_at_iso);

        $patch = OrderPlacementJobPatch::empty()
            ->with_next_run_at_mysql(($mysql !== '') ? $mysql : null);

        OrderPlacementJobWriter::apply_patch_for_order($order, $job_key, $patch);
    }

    public static function get_job_next_run_at(WC_Order $order, string $job_key): string
    {
        $mysql = self::read_job_scalar((int) $order->get_id(), $job_key, 'next_run_at');
        if (!is_string($mysql) || trim($mysql) === '') {
            return '';
        }

        return OrderPlacementTimeUtil::mysql_utc_to_iso((string) $mysql);
    }

    /* ============================================================
     * action_id
     * ============================================================ */

    public static function set_job_action_id(WC_Order $order, string $job_key, string $action_id): void
    {
        $aid = trim((string) $action_id);
        $aid_i = ($aid !== '' && ctype_digit($aid)) ? (int) $aid : 0;

        $patch = OrderPlacementJobPatch::empty()
            ->with_action_id(($aid_i > 0) ? $aid_i : null);

        OrderPlacementJobWriter::apply_patch_for_order($order, $job_key, $patch);
    }

    public static function get_job_action_id(WC_Order $order, string $job_key): string
    {
        $v = self::read_job_scalar((int) $order->get_id(), $job_key, 'action_id');

        if ($v === null) {
            return '';
        }

        $i = (int) $v;
        return ($i > 0) ? (string) $i : '';
    }

    public static function clear_job_action_id(WC_Order $order, string $job_key): void
    {
        $patch = OrderPlacementJobPatch::empty()
            ->with_action_id(null);

        OrderPlacementJobWriter::apply_patch_for_order($order, $job_key, $patch);
    }

    /* ============================================================
     * Internal helpers (lightweight reads)
     * ============================================================ */

    private static function read_job_scalar(int $order_id, string $job_key, string $column)
    {
        global $wpdb;

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

        $table = OrderPlacementJobsTable::get_table_name();

        // Column is allowlisted; safe to interpolate.
        $sql = $wpdb->prepare(
            "SELECT {$column} FROM {$table} WHERE order_id = %d AND job_key = %s LIMIT 1",
            $order_id,
            $job_key
        );

        return $wpdb->get_var($sql);
    }
}
