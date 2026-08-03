<?php

namespace FFLHub\Distributor\Services\Orders\Jobs;

use WC_Order;

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Services\Orders\Cron\DealerBatchCronRegistry;
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
     * - CA relay rows are excluded because distributor tracking is only the inbound leg to the dealer/relay address
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
                AND payload_json NOT LIKE %s
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
            '%"ca_relay"%',
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
            if (!is_array($row)) {
                continue;
            }

            $job = new OrderPlacementJobRow($row);
            if (DealerBatchCronRegistry::is_ca_relay_batch_job($job)) {
                continue;
            }

            $out[] = $job;
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
     * Returns true if every order placement job has been resolved with tracking.
     *
     * Manual rows are allowed here because dealer-fulfilled tracking updates can
     * attach tracking without changing the row status to success.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param int                     $order_id   Woo order ID.
     * @return bool True when every job is success/manual and has real tracking.
     */
    public static function are_all_order_jobs_shipped(OrderPlacementJobsTable $jobs_table, int $order_id): bool
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

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT status, tracking_numbers_json
                FROM {$table}
                WHERE order_id = %d
                ",
                $order_id
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return false;
        }

        $terminal_statuses = [
            OrderPlacementKeys::JOB_STATUS_SUCCESS,
            OrderPlacementKeys::JOB_STATUS_MANUAL,
        ];

        foreach ($rows as $row) {
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if (!in_array($status, $terminal_statuses, true)) {
                return false;
            }

            if (!self::tracking_numbers_json_has_real_value($row['tracking_numbers_json'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Backward-compatible wrapper for older callers.
     *
     * The old implementation only looked at success rows, which let mixed orders
     * complete while a manual dealer-fulfilled row was still unresolved.
     */
    public static function are_all_success_jobs_shipped(OrderPlacementJobsTable $jobs_table, int $order_id): bool
    {
        return self::are_all_order_jobs_shipped($jobs_table, $order_id);
    }

    private static function tracking_numbers_json_has_real_value($tracking_numbers_json): bool
    {
        $tracking_numbers_json = trim((string) $tracking_numbers_json);
        if ($tracking_numbers_json === '' || $tracking_numbers_json === '[]') {
            return false;
        }

        $decoded = json_decode($tracking_numbers_json, true);
        if (!is_array($decoded)) {
            return false;
        }

        foreach ($decoded as $tracking_number) {
            if (is_scalar($tracking_number) && trim((string) $tracking_number) !== '') {
                return true;
            }
        }

        return false;
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
     * Select dealer-fulfilled rows that are waiting for distributor batch placement.
     *
     * Criteria:
     * - dist_id = requested distributor
     * - lane = dealer_fulfilled
     * - status = batch_pending
     * - next_run_at is NULL/zero OR next_run_at <= $now_mysql_utc
     *
     * Order:
     * - next_run_at ASC when present, then created_at ASC, then id ASC
     *
     * @param OrderPlacementJobsTable $jobs_table
     * @param string                  $dist_id
     * @param string                  $now_mysql_utc
     * @param int                     $limit
     * @param bool                    $ignore_schedule Include future-scheduled rows for an operator force flush.
     * @return OrderPlacementJobRow[]
     */
    public static function find_jobs_for_dealer_batch_processing(
        OrderPlacementJobsTable $jobs_table,
        string $dist_id,
        string $now_mysql_utc,
        int $limit,
        bool $ignore_schedule = false
    ): array {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return [];
        }

        $limit = max(1, (int) $limit);
        $dist_id = OrderPlacementKeysUtil::normalize_dist_id($dist_id);
        if ($dist_id === '') {
            return [];
        }
        $lane = OrderPlacementKeysUtil::LANE_DEALER_FULFILLED;
        $status = OrderPlacementKeys::JOB_STATUS_BATCH_PENDING;
        $schedule_where = $ignore_schedule
            ? ''
            : "AND (
                    next_run_at IS NULL
                    OR next_run_at = '0000-00-00 00:00:00'
                    OR next_run_at <= %s
                )";
        $prepare_args = [$dist_id, $lane, $status];
        if (!$ignore_schedule) {
            $prepare_args[] = (string) $now_mysql_utc;
        }
        $prepare_args[] = $limit;

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
                {$schedule_where}
            ORDER BY
                CASE
                    WHEN next_run_at IS NULL OR next_run_at = '0000-00-00 00:00:00'
                    THEN created_at
                    ELSE next_run_at
                END ASC,
                id ASC
            LIMIT %d
            ",
            ...$prepare_args
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
     * Select CA relay rows that are waiting for distributor batch placement.
     *
     * Relay rows use the distributor direct-ship non-FFL lane, but they are
     * batch-pending because the ship-to address is rewritten to the configured
     * dealer/relay address during placement.
     *
     * @param OrderPlacementJobsTable $jobs_table
     * @param string                  $dist_id
     * @param string                  $now_mysql_utc
     * @param int                     $limit
     * @param bool                    $ignore_schedule Include future-scheduled rows for an operator force flush.
     * @return OrderPlacementJobRow[]
     */
    public static function find_jobs_for_ca_relay_batch_processing(
        OrderPlacementJobsTable $jobs_table,
        string $dist_id,
        string $now_mysql_utc,
        int $limit,
        bool $ignore_schedule = false
    ): array {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return [];
        }

        $limit = max(1, (int) $limit);
        $dist_id = OrderPlacementKeysUtil::normalize_dist_id($dist_id);
        if ($dist_id === '') {
            return [];
        }

        $lane = OrderPlacementKeysUtil::LANE_DIRECT_SHIP_NON_FFL;
        $status = OrderPlacementKeys::JOB_STATUS_BATCH_PENDING;
        $schedule_where = $ignore_schedule
            ? ''
            : "AND (
                    next_run_at IS NULL
                    OR next_run_at = '0000-00-00 00:00:00'
                    OR next_run_at <= %s
                )";
        $prepare_args = [$dist_id, $lane, $status, '%"ca_relay"%'];
        if (!$ignore_schedule) {
            $prepare_args[] = (string) $now_mysql_utc;
        }
        $prepare_args[] = $limit;

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
                AND payload_json LIKE %s
                {$schedule_where}
            ORDER BY
                CASE
                    WHEN next_run_at IS NULL OR next_run_at = '0000-00-00 00:00:00'
                    THEN created_at
                    ELSE next_run_at
                END ASC,
                id ASC
            LIMIT %d
            ",
            ...$prepare_args
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $job = new OrderPlacementJobRow($row);
            if (DealerBatchCronRegistry::is_ca_relay_batch_job($job)) {
                $out[] = $job;
            }
        }

        return $out;
    }

    /**
     * Backward-compatible RSR-specific wrapper.
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
        return self::find_jobs_for_dealer_batch_processing(
            $jobs_table,
            'rsr',
            $now_mysql_utc,
            $limit
        );
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

    /**
     * Select failed placement job rows for admin triage.
     *
     * @param OrderPlacementJobsTable $jobs_table Table manager instance.
     * @param int                     $limit      Max rows to return.
     * @param string                  $dist_id    Optional distributor filter.
     * @param string                  $lane       Optional lane filter.
     * @param string                  $last_step  Optional last_step filter.
     * @param int                     $order_id   Optional Woo order id filter.
     * @return OrderPlacementJobRow[] List of failed job DTOs.
     */
    public static function find_failed_jobs(
        OrderPlacementJobsTable $jobs_table,
        int $limit,
        string $dist_id = '',
        string $lane = '',
        string $last_step = '',
        int $order_id = 0
    ): array {
        global $wpdb;

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return [];
        }

        $where = ['status = %s'];
        $args = [OrderPlacementKeys::JOB_STATUS_FAILED];

        $dist_id = OrderPlacementKeysUtil::normalize_dist_id((string) $dist_id);
        if ($dist_id !== '') {
            $where[] = 'dist_id = %s';
            $args[] = $dist_id;
        }

        $lane = OrderPlacementKeysUtil::normalize_lane((string) $lane);
        if ($lane !== '' && OrderPlacementKeysUtil::is_valid_lane($lane)) {
            $where[] = 'lane = %s';
            $args[] = $lane;
        }

        $last_step = strtolower(trim((string) $last_step));
        if (in_array($last_step, ['validate', 'place', 'shipping'], true)) {
            $where[] = 'last_step = %s';
            $args[] = $last_step;
        }

        $order_id = (int) $order_id;
        if ($order_id > 0) {
            $where[] = 'order_id = %d';
            $args[] = $order_id;
        }

        $limit = max(1, (int) $limit);
        $args[] = $limit;

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
            WHERE " . implode(' AND ', $where) . "
            ORDER BY
                updated_at DESC,
                id DESC
            LIMIT %d
            ",
            ...$args
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
}
