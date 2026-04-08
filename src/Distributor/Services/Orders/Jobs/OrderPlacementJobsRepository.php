<?php

namespace FFLHub\Distributor\Services\Orders\Jobs;

use WC_Order;

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;

use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobsRepository
 *
 * Responsibility:
 * - Read-only access to the Order Placement Jobs table.
 * - Returns DTOs (OrderPlacementJobRow) and derived query results.
 *
 * Rules:
 * - NO WRITES in this class (no updates, inserts, patches).
 * - Any mutations belong in a writer/lifecycle/specialized store.
 *
 * Dependency:
 * - Requires an instantiated OrderPlacementJobsTable manager (provides table name).
 */
final class OrderPlacementJobsRepository
{
    /* ============================================================
     * Basic queries
     * ============================================================ */

    /**
     * List all job keys for an order (sorted, unique).
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order object.
     * @return string[] Job keys (normalized; sorted).
     */
    public static function get_jobs_index(OrderPlacementJobsTable $jobs_table, WC_Order $order): array
    {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return [];
        }

        $oid = (int) $order->get_id();
        if ($oid <= 0) {
            return [];
        }

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT job_key FROM {$table} WHERE order_id = %d ORDER BY job_key ASC",
                $oid
            )
        );

        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $k) {
                $k = OrderPlacementKeysUtil::normalize_job_key((string) $k);
                if ($k !== '') {
                    $out[] = $k;
                }
            }
        }

        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /**
     * Fetch a single job row DTO by order_id + job_key.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param int                     $order_id   Woo order ID.
     * @param string                  $job_key    Canonical key (dist|lane). Normalized before use.
     * @return OrderPlacementJobRow|null DTO if found, null if missing.
     */
    public static function get_job(OrderPlacementJobsTable $jobs_table, int $order_id, string $job_key): ?OrderPlacementJobRow
    {
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

        $sql = $wpdb->prepare(
            "
            SELECT
                id, order_id, job_key, dist_id, lane, status,
                attempts, created_at, updated_at,
                action_id, next_run_at,
                last_step, last_error, last_codes_json,
                done_at,
                payload_json, validate_result_json, place_result_json,
                merchant_po, external_order_ids_json, external_order_id,
                shipped_at, tracking_numbers_json, invoice_numbers_json,
                last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json
            FROM {$table}
            WHERE order_id = %d AND job_key = %s
            LIMIT 1
            ",
            $order_id,
            $job_key
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? new OrderPlacementJobRow($row) : null;
    }

    /**
     * Convenience: fetch a job DTO using a WC_Order object.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param WC_Order                $order      WooCommerce order object.
     * @param string                  $job_key    Canonical key (dist|lane). Normalized before use.
     * @return OrderPlacementJobRow|null DTO if found, null if missing.
     */
    public static function get_job_for_order(OrderPlacementJobsTable $jobs_table, WC_Order $order, string $job_key): ?OrderPlacementJobRow
    {
        return self::get_job($jobs_table, (int) $order->get_id(), (string) $job_key);
    }

    /**
     * DTO-powered helper: return DistributorOrderLine[] from the job payload.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param int                     $order_id   Woo order ID.
     * @param string                  $job_key    Canonical key (dist|lane). Normalized before use.
     * @return DistributorOrderLine[] Parsed order lines (may be empty if job/payload missing).
     */
    public static function get_job_payload_lines(OrderPlacementJobsTable $jobs_table, int $order_id, string $job_key): array
    {
        $job = self::get_job($jobs_table, (int) $order_id, (string) $job_key);
        return $job ? $job->payload_lines() : [];
    }

    /* ============================================================
     * Shipping queries
     * ============================================================ */

    /**
     * Select jobs eligible for shipping polling.
     *
     * Eligible jobs:
     * - status matches $status (typically JOB_STATUS_SUCCESS)
     * - lane is direct-ship (dealer_fulfilled is intentionally excluded; shipped manually)
     * - merchant_po present (we need a PO to query shipments)
     * - not shipped yet (shipped_at is NULL/zero)
     * - last_shipping_poll_at is NULL/zero or older than $poll_cutoff_mysql_utc
     * - OPTIONAL: if shipped_at is set, stop polling once shipped_at is older than $ship_cutoff_mysql_utc
     *
     * NOTE:
     * - Read-only. No writes/claims here.
     *
     * @param OrderPlacementJobsTable $jobs_table           Table manager instance.
     * @param string                  $status              Job status to include (usually OrderPlacementKeys::JOB_STATUS_SUCCESS).
     * @param string                  $poll_cutoff_mysql_utc MySQL UTC datetime; job must not have been polled since this time.
     * @param string                  $ship_cutoff_mysql_utc MySQL UTC datetime; stop polling jobs whose shipped_at is older than this.
     * @param int                     $limit               Max rows to return.
     * @return OrderPlacementJobRow[] List of DTOs.
     */
    public static function find_jobs_for_shipping_poll(
        OrderPlacementJobsTable $jobs_table,
        string $status,
        string $poll_cutoff_mysql_utc,
        string $ship_cutoff_mysql_utc,
        int $limit
    ): array {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return [];
        }

        $limit = max(1, (int) $limit);

        // If you ever decide to poll "recently shipped" jobs for extra tracking numbers,
        // this cutoff becomes relevant. Right now we still include it for correctness with the doc.
        $lane_non = OrderPlacementKeysUtil::LANE_DIRECT_SHIP_NON_FFL;
        $lane_ffl = OrderPlacementKeysUtil::LANE_DIRECT_SHIP_FFL;
        $sql = $wpdb->prepare(
            "
            SELECT
                id, order_id, job_key, dist_id, lane, status,
                attempts, created_at, updated_at,
                action_id, next_run_at,
                last_step, last_error, last_codes_json,
                done_at,
                payload_json, validate_result_json, place_result_json,
                merchant_po, external_order_ids_json, external_order_id,
                shipped_at, tracking_numbers_json, invoice_numbers_json,
                last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json
            FROM {$table}
            WHERE
                status = %s
                AND lane IN (%s, %s)
                AND merchant_po IS NOT NULL
                AND merchant_po <> ''
                AND (
                    last_shipping_poll_at IS NULL
                    OR last_shipping_poll_at = '0000-00-00 00:00:00'
                    OR last_shipping_poll_at < %s
                )
                AND (
                    shipped_at IS NULL
                    OR shipped_at = '0000-00-00 00:00:00'
                    OR shipped_at >= %s
                )
            ORDER BY
                last_shipping_poll_at IS NULL DESC,
                last_shipping_poll_at ASC,
                id ASC
            LIMIT %d
            ",
            (string) $status,
            (string) $lane_non,
            (string) $lane_ffl,
            (string) $poll_cutoff_mysql_utc,
            (string) $ship_cutoff_mysql_utc,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = new OrderPlacementJobRow($row);
            }
        }

        return $out;
    }

    /**
     * Select dealer-fulfilled jobs for iteration-only cron workflows.
     *
     * NOTE:
     * - Read-only selector.
     * - No polling/api assumptions yet; callers decide what to do per row.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param string                  $status     Job status to include (typically success).
     * @param int                     $limit      Max rows to return.
     * @return OrderPlacementJobRow[] List of DTOs.
     */
    public static function find_jobs_for_dealer_fulfilled_iteration(
        OrderPlacementJobsTable $jobs_table,
        string $status,
        int $limit
    ): array {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return [];
        }

        $limit = max(1, (int) $limit);
        $lane  = OrderPlacementKeysUtil::LANE_DEALER_FULFILLED;

        $sql = $wpdb->prepare(
            "
            SELECT
                id, order_id, job_key, dist_id, lane, status,
                attempts, created_at, updated_at,
                action_id, next_run_at,
                last_step, last_error, last_codes_json,
                done_at,
                payload_json, validate_result_json, place_result_json,
                merchant_po, external_order_ids_json, external_order_id,
                shipped_at, tracking_numbers_json, invoice_numbers_json,
                last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json
            FROM {$table}
            WHERE
                status = %s
                AND lane = %s
            ORDER BY
                updated_at DESC,
                id DESC
            LIMIT %d
            ",
            (string) $status,
            (string) $lane,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = new OrderPlacementJobRow($row);
            }
        }

        return $out;
    }

    /**
     * Select dealer-fulfilled jobs eligible for shipping polling.
     *
     * Eligible jobs:
     * - status matches $status (typically JOB_STATUS_SUCCESS)
     * - lane is dealer_fulfilled
     * - merchant_po present
     * - last_shipping_poll_at is NULL/zero or older than $poll_cutoff_mysql_utc
     * - shipped_at is NULL/zero or newer than/equal to $ship_cutoff_mysql_utc
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param string                  $status Job status to include.
     * @param string                  $poll_cutoff_mysql_utc Poll pacing cutoff.
     * @param string                  $ship_cutoff_mysql_utc Recent-shipment cutoff.
     * @param int                     $limit Max rows to return.
     * @return OrderPlacementJobRow[] List of DTOs.
     */
    public static function find_jobs_for_dealer_shipping_poll(
        OrderPlacementJobsTable $jobs_table,
        string $status,
        string $poll_cutoff_mysql_utc,
        string $ship_cutoff_mysql_utc,
        int $limit
    ): array {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return [];
        }

        $limit = max(1, (int) $limit);
        $lane  = OrderPlacementKeysUtil::LANE_DEALER_FULFILLED;

        $sql = $wpdb->prepare(
            "
            SELECT
                id, order_id, job_key, dist_id, lane, status,
                attempts, created_at, updated_at,
                action_id, next_run_at,
                last_step, last_error, last_codes_json,
                done_at,
                payload_json, validate_result_json, place_result_json,
                merchant_po, external_order_ids_json, external_order_id,
                shipped_at, tracking_numbers_json, invoice_numbers_json,
                last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json
            FROM {$table}
            WHERE
                status = %s
                AND lane = %s
                AND merchant_po IS NOT NULL
                AND merchant_po <> ''
                AND (
                    last_shipping_poll_at IS NULL
                    OR last_shipping_poll_at = '0000-00-00 00:00:00'
                    OR last_shipping_poll_at < %s
                )
                AND (
                    shipped_at IS NULL
                    OR shipped_at = '0000-00-00 00:00:00'
                    OR shipped_at >= %s
                )
            ORDER BY
                last_shipping_poll_at IS NULL DESC,
                last_shipping_poll_at ASC,
                id ASC
            LIMIT %d
            ",
            (string) $status,
            (string) $lane,
            (string) $poll_cutoff_mysql_utc,
            (string) $ship_cutoff_mysql_utc,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = new OrderPlacementJobRow($row);
            }
        }

        return $out;
    }

    /**
     * Returns true if all SUCCESS jobs for the order have at least one tracking number.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param int                     $order_id   Woo order ID.
     * @return bool True if total_success_jobs > 0 and shipped_count >= total_success_jobs.
     */
    public static function are_all_success_jobs_shipped(OrderPlacementJobsTable $jobs_table, int $order_id): bool
    {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return false;
        }

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return false;
        }

        $sql = $wpdb->prepare(
            "
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN tracking_numbers_json IS NOT NULL AND tracking_numbers_json <> '' THEN 1 ELSE 0 END) AS shipped
            FROM {$table}
            WHERE order_id = %d AND status = %s
            ",
            $order_id,
            OrderPlacementKeys::JOB_STATUS_SUCCESS
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!is_array($row)) {
            return false;
        }

        $total   = (int) ($row['total'] ?? 0);
        $shipped = (int) ($row['shipped'] ?? 0);

        return ($total > 0 && $shipped >= $total);
    }

    /**
     * Select jobs eligible for placement processing (scheduled / retry_scheduled).
     *
     * Rows must have next_run_at <= $now_mysql_utc.
     * Ordered by next_run_at ASC (nearest first), then attempts ASC, then id ASC for stability.
     *
     * IMPORTANT:
     * - Read-only. No writes/claims here.
     * - Concurrency is handled by the runner lifecycle marking the job running.
     *
     * @param OrderPlacementJobsTable $jobs_table    Table manager instance.
     * @param string[]                $statuses      Allowed statuses (e.g., scheduled, retry_scheduled).
     * @param string                  $now_mysql_utc MySQL UTC datetime; row must be ready by this time.
     * @param int                     $limit         Max rows to return.
     * @return OrderPlacementJobRow[] List of DTOs.
     */
    public static function find_jobs_ready_for_processing(
        OrderPlacementJobsTable $jobs_table,
        array $statuses,
        string $now_mysql_utc,
        int $limit
    ): array {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return [];
        }

        $limit = max(1, (int) $limit);

        $statuses = array_values(array_filter(array_map('strval', $statuses)));
        if (empty($statuses)) {
            return [];
        }

        $in_placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = $wpdb->prepare(
            "
            SELECT
                id, order_id, job_key, dist_id, lane, status,
                attempts, created_at, updated_at,
                action_id, next_run_at,
                last_step, last_error, last_codes_json,
                done_at,
                payload_json, validate_result_json, place_result_json,
                merchant_po, external_order_ids_json, external_order_id,
                shipped_at, tracking_numbers_json, invoice_numbers_json,
                last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json
            FROM {$table}
            WHERE
                status IN ({$in_placeholders})
                AND next_run_at IS NOT NULL
                AND next_run_at <> '0000-00-00 00:00:00'
                AND next_run_at <= %s
            ORDER BY
                next_run_at ASC,
                attempts ASC,
                id ASC
            LIMIT %d
            ",
            array_merge($statuses, [(string) $now_mysql_utc, $limit])
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = new OrderPlacementJobRow($row);
            }
        }
        return $out;
    }

    /**
     * Select RSR dealer-fulfilled rows that are waiting for batch placement.
     *
     * Criteria:
     * - dist_id = 'rsr'
     * - lane = dealer_fulfilled
     * - status = batch_pending
     * - next_run_at is NULL/zero OR next_run_at <= $now_mysql_utc
     *
     * Order:
     * - next_run_at ASC when present, then created_at ASC, then id ASC
     *
     * @param OrderPlacementJobsTable $jobs_table
     * @param string                  $now_mysql_utc
     * @param int                     $limit
     * @return OrderPlacementJobRow[]
     */
    public static function find_jobs_for_rsr_batch_processing(
        OrderPlacementJobsTable $jobs_table,
        string $now_mysql_utc,
        int $limit
    ): array {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return [];
        }

        $limit = max(1, (int) $limit);
        $dist_id = 'rsr';
        $lane = OrderPlacementKeysUtil::LANE_DEALER_FULFILLED;
        $status = OrderPlacementKeys::JOB_STATUS_BATCH_PENDING;

        $sql = $wpdb->prepare(
            "
            SELECT
                id, order_id, job_key, dist_id, lane, status,
                attempts, created_at, updated_at,
                action_id, next_run_at,
                last_step, last_error, last_codes_json,
                done_at,
                payload_json, validate_result_json, place_result_json,
                merchant_po, external_order_ids_json, external_order_id,
                shipped_at, tracking_numbers_json, invoice_numbers_json,
                last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json
            FROM {$table}
            WHERE
                dist_id = %s
                AND lane = %s
                AND status = %s
                AND (
                    next_run_at IS NULL
                    OR next_run_at = '0000-00-00 00:00:00'
                    OR next_run_at <= %s
                )
            ORDER BY
                CASE
                    WHEN next_run_at IS NULL OR next_run_at = '0000-00-00 00:00:00'
                    THEN created_at
                    ELSE next_run_at
                END ASC,
                id ASC
            LIMIT %d
            ",
            $dist_id,
            $lane,
            $status,
            (string) $now_mysql_utc,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = new OrderPlacementJobRow($row);
            }
        }

        return $out;
    }

    /**
     * Select jobs for a specific lane, newest first.
     *
     * Useful for admin/operator views where we need to inspect jobs by lane
     * (for example dealer_fulfilled) without mutating any data.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param string                  $lane      Canonical lane value.
     * @param int                     $limit     Max rows to return.
     * @param string|null             $status    Optional status filter; pass null/'' for all.
     * @return OrderPlacementJobRow[] List of DTOs.
     */
    public static function find_jobs_by_lane(
        OrderPlacementJobsTable $jobs_table,
        string $lane,
        int $limit,
        ?string $status = null
    ): array {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return [];
        }

        $lane = OrderPlacementKeysUtil::normalize_lane((string) $lane);
        if (!OrderPlacementKeysUtil::is_valid_lane($lane)) {
            return [];
        }

        $limit = max(1, (int) $limit);
        $status = is_string($status) ? trim($status) : '';

        if ($status !== '') {
            $sql = $wpdb->prepare(
                "
                SELECT
                    id, order_id, job_key, dist_id, lane, status,
                    attempts, created_at, updated_at,
                    action_id, next_run_at,
                    last_step, last_error, last_codes_json,
                    done_at,
                    payload_json, validate_result_json, place_result_json,
                    merchant_po, external_order_ids_json, external_order_id,
                    shipped_at, tracking_numbers_json, invoice_numbers_json,
                    last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json
                FROM {$table}
                WHERE
                    lane = %s
                    AND status = %s
                ORDER BY
                    updated_at DESC,
                    id DESC
                LIMIT %d
                ",
                $lane,
                (string) $status,
                $limit
            );
        } else {
            $sql = $wpdb->prepare(
                "
                SELECT
                    id, order_id, job_key, dist_id, lane, status,
                    attempts, created_at, updated_at,
                    action_id, next_run_at,
                    last_step, last_error, last_codes_json,
                    done_at,
                    payload_json, validate_result_json, place_result_json,
                    merchant_po, external_order_ids_json, external_order_id,
                    shipped_at, tracking_numbers_json, invoice_numbers_json,
                    last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json
                FROM {$table}
                WHERE lane = %s
                ORDER BY
                    updated_at DESC,
                    id DESC
                LIMIT %d
                ",
                $lane,
                $limit
            );
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = new OrderPlacementJobRow($row);
            }
        }

        return $out;
    }

    /**
     * Select jobs for a specific distributor, newest first.
     *
     * Useful for operational/admin views that need a distributor-scoped
     * perspective (for example, failed Davidson's rows impacting credit).
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param string                  $dist_id    Canonical distributor id.
     * @param int                     $limit      Max rows to return.
     * @param string|null             $status     Optional status filter; pass null/'' for all.
     * @return OrderPlacementJobRow[] List of DTOs.
     */
    public static function find_jobs_by_distributor(
        OrderPlacementJobsTable $jobs_table,
        string $dist_id,
        int $limit,
        ?string $status = null
    ): array {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return [];
        }

        $dist_id = OrderPlacementKeysUtil::normalize_dist_id((string) $dist_id);
        if ($dist_id === '') {
            return [];
        }

        $limit = max(1, (int) $limit);
        $status = is_string($status) ? trim($status) : '';

        if ($status !== '') {
            $sql = $wpdb->prepare(
                "
                SELECT
                    id, order_id, job_key, dist_id, lane, status,
                    attempts, created_at, updated_at,
                    action_id, next_run_at,
                    last_step, last_error, last_codes_json,
                    done_at,
                    payload_json, validate_result_json, place_result_json,
                    merchant_po, external_order_ids_json, external_order_id,
                    shipped_at, tracking_numbers_json, invoice_numbers_json,
                    last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json
                FROM {$table}
                WHERE
                    dist_id = %s
                    AND status = %s
                ORDER BY
                    updated_at DESC,
                    id DESC
                LIMIT %d
                ",
                $dist_id,
                (string) $status,
                $limit
            );
        } else {
            $sql = $wpdb->prepare(
                "
                SELECT
                    id, order_id, job_key, dist_id, lane, status,
                    attempts, created_at, updated_at,
                    action_id, next_run_at,
                    last_step, last_error, last_codes_json,
                    done_at,
                    payload_json, validate_result_json, place_result_json,
                    merchant_po, external_order_ids_json, external_order_id,
                    shipped_at, tracking_numbers_json, invoice_numbers_json,
                    last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json
                FROM {$table}
                WHERE dist_id = %s
                ORDER BY
                    updated_at DESC,
                    id DESC
                LIMIT %d
                ",
                $dist_id,
                $limit
            );
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = new OrderPlacementJobRow($row);
            }
        }

        return $out;
    }
}
