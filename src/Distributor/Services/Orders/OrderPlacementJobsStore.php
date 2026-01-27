<?php

namespace FFLHub\Distributor\Services\Orders;

use WC_Order;

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Models\ShippingUpdateResult;

use FFLHub\Distributor\Services\Tables\OrderPlacementJobsTable;
use FFLHub\Distributor\Services\Orders\Shipping\OrderPlacementShippingService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table-backed storage for Order Placement jobs.
 *
 * Pipeline meta stays on the WooCommerce order,
 * but per-job state is stored in a first-class DB table.
 */
final class OrderPlacementJobsStore
{
    private function __construct() {}

    /* ===================== Pipeline meta (still on order meta) ===================== */

    public static function get_pipeline_started(WC_Order $order): bool
    {
        return ((string) $order->get_meta(OrderPlacementKeys::META_PIPELINE_STARTED, true) === '1');
    }

    public static function set_pipeline_started(WC_Order $order, bool $started, string $started_at = '', string $started_by = ''): void
    {
        $order->update_meta_data(OrderPlacementKeys::META_PIPELINE_STARTED, $started ? '1' : '0');

        if ($started) {
            if ($started_at !== '') $order->update_meta_data(OrderPlacementKeys::META_PIPELINE_STARTED_AT, $started_at);
            if ($started_by !== '') $order->update_meta_data(OrderPlacementKeys::META_PIPELINE_STARTED_BY, $started_by);
        }
    }

    public static function get_pipeline_started_at(WC_Order $order): string
    {
        return (string) $order->get_meta(OrderPlacementKeys::META_PIPELINE_STARTED_AT, true);
    }

    public static function get_pipeline_started_by(WC_Order $order): string
    {
        return (string) $order->get_meta(OrderPlacementKeys::META_PIPELINE_STARTED_BY, true);
    }

    /* ===================== Jobs index (now derived from table) ===================== */

    /** @return string[] */
    public static function get_jobs_index(WC_Order $order): array
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();
        $oid   = (int) $order->get_id();

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT job_key FROM {$table} WHERE order_id = %d ORDER BY job_key ASC",
                $oid
            )
        );

        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $k) {
                $k = is_string($k) ? trim($k) : '';
                if ($k !== '') $out[] = $k;
            }
        }

        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /**
     * No-op for table storage. Kept for compatibility.
     *
     * @param string[] $job_keys
     */
    public static function set_jobs_index(WC_Order $order, array $job_keys): void
    {
        // Intentionally no-op (index is derived from table).
    }

    /* ===================== Job init/upsert ===================== */

    /**
     * Initialize a job row if missing; always overwrite payload_json.
     *
     * @param array<string,mixed> $payload
     */
    public static function init_job_meta(WC_Order $order, string $job_key, array $payload): void
    {
        global $wpdb;

        $oid   = (int) $order->get_id();
        $table = OrderPlacementJobsTable::get_table_name();

        $job_key = self::normalize_job_key($job_key);

        $dist_id = strtolower(trim((string) ($payload['dist_id'] ?? '')));
        $bucket  = strtolower(trim((string) ($payload['bucket'] ?? '')));

        if ($dist_id === '') {
            $parts = explode('|', $job_key, 2);
            $dist_id = isset($parts[0]) ? strtolower(trim((string) $parts[0])) : '';
        }
        if ($bucket === '') {
            $parts = explode('|', $job_key, 2);
            $bucket = isset($parts[1]) ? strtolower(trim((string) $parts[1])) : '';
        }

        if ($dist_id === '') $dist_id = 'unknown';
        if ($bucket !== 'ffl' && $bucket !== 'non') $bucket = 'unknown';

        $now = self::now_mysql_utc();

        // Insert w/ NULLs for nullable fields; on duplicate update: payload + updated_at + dist/bucket.
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
              updated_at = VALUES(updated_at),
              dist_id = VALUES(dist_id),
              bucket = VALUES(bucket)
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
                wp_json_encode($payload)
            )
        );
    }

    /* ===================== Status / action id / attempts ===================== */

    public static function set_job_status(WC_Order $order, string $job_key, string $status): void
    {
        self::update_job_fields((int) $order->get_id(), $job_key, [
            'status' => (string) $status,
        ]);
    }

    public static function get_job_status(WC_Order $order, string $job_key): string
    {
        $row = self::get_job_row((int) $order->get_id(), $job_key, ['status']);
        return isset($row['status']) ? (string) $row['status'] : '';
    }

    public static function set_job_action_id(WC_Order $order, string $job_key, string $action_id): void
    {
        $aid = trim((string) $action_id);
        $aid_i = ($aid !== '' && ctype_digit($aid)) ? (int) $aid : 0;

        self::update_job_fields((int) $order->get_id(), $job_key, [
            'action_id' => ($aid_i > 0) ? $aid_i : null,
        ]);
    }

    public static function get_job_action_id(WC_Order $order, string $job_key): string
    {
        $row = self::get_job_row((int) $order->get_id(), $job_key, ['action_id']);
        if (!isset($row['action_id']) || $row['action_id'] === null) return '';
        return (string) (int) $row['action_id'];
    }

    public static function clear_job_action_id(WC_Order $order, string $job_key): void
    {
        self::update_job_fields((int) $order->get_id(), $job_key, [
            'action_id' => null,
        ]);
    }

    public static function increment_job_attempts_and_mark_running(WC_Order $order, string $job_key): int
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();
        $oid   = (int) $order->get_id();
        $job_key = self::normalize_job_key($job_key);

        $now = self::now_mysql_utc();

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET attempts = attempts + 1,
                     status = %s,
                     next_run_at = NULL,
                     action_id = NULL,
                     updated_at = %s
                 WHERE order_id = %d AND job_key = %s",
                OrderPlacementKeys::JOB_STATUS_RUNNING,
                $now,
                $oid,
                $job_key
            )
        );

        $row = self::get_job_row($oid, $job_key, ['attempts']);
        return isset($row['attempts']) ? (int) $row['attempts'] : 0;
    }

    public static function mark_job_success(WC_Order $order, string $job_key, string $done_at = ''): void
    {
        $done_mysql = $done_at !== '' ? self::iso_to_mysql_utc($done_at) : self::now_mysql_utc();

        self::update_job_fields((int) $order->get_id(), $job_key, [
            'status'          => OrderPlacementKeys::JOB_STATUS_SUCCESS,
            'done_at'         => $done_mysql,
            'last_error'      => '',
            'last_codes_json' => wp_json_encode([]),
            'next_run_at'     => null,
            'action_id'       => null,
        ]);
    }

    public static function mark_job_failed(WC_Order $order, string $job_key, string $error_message): void
    {
        self::update_job_fields((int) $order->get_id(), $job_key, [
            'status'      => OrderPlacementKeys::JOB_STATUS_FAILED,
            'last_error'  => (string) $error_message,
            'next_run_at' => null,
        ]);
    }

    /* ===================== Payload ===================== */

    /** @return array<string,mixed>|null */
    public static function get_job_payload(WC_Order $order, string $job_key): ?array
    {
        $row = self::get_job_row((int) $order->get_id(), $job_key, ['payload_json']);
        $json = isset($row['payload_json']) ? (string) $row['payload_json'] : '';
        if ($json === '') return null;

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /* ===================== Last error / codes / next run / step ===================== */

    public static function set_job_last_error(WC_Order $order, string $job_key, string $message): void
    {
        self::update_job_fields((int) $order->get_id(), $job_key, [
            'last_error' => (string) $message,
        ]);
    }

    public static function get_job_last_error(WC_Order $order, string $job_key): string
    {
        $row = self::get_job_row((int) $order->get_id(), $job_key, ['last_error']);
        return isset($row['last_error']) ? (string) $row['last_error'] : '';
    }

    public static function set_job_next_run_at(WC_Order $order, string $job_key, string $next_run_at_iso): void
    {
        $mysql = self::iso_to_mysql_utc($next_run_at_iso);

        self::update_job_fields((int) $order->get_id(), $job_key, [
            'next_run_at' => ($mysql !== '') ? $mysql : null,
        ]);
    }

    public static function get_job_next_run_at(WC_Order $order, string $job_key): string
    {
        $row = self::get_job_row((int) $order->get_id(), $job_key, ['next_run_at']);
        $mysql = isset($row['next_run_at']) ? (string) $row['next_run_at'] : '';
        if ($mysql === '') return '';
        return self::mysql_utc_to_iso($mysql);
    }

    /** @param string[] $codes */
    public static function set_job_last_error_codes(WC_Order $order, string $job_key, array $codes): void
    {
        $codes = self::normalize_codes($codes);

        self::update_job_fields((int) $order->get_id(), $job_key, [
            'last_codes_json' => wp_json_encode($codes),
        ]);
    }

    /** @return string[] */
    public static function get_job_last_error_codes(WC_Order $order, string $job_key): array
    {
        $row = self::get_job_row((int) $order->get_id(), $job_key, ['last_codes_json']);
        $json = isset($row['last_codes_json']) ? (string) $row['last_codes_json'] : '';
        if ($json === '') return [];

        $arr = json_decode($json, true);
        if (!is_array($arr)) return [];

        return self::normalize_codes($arr);
    }

    public static function set_job_last_step(WC_Order $order, string $job_key, string $step): void
    {
        $step = strtolower(trim($step));
        if ($step !== 'validate' && $step !== 'place' && $step !== 'shipped') $step = '';

        self::update_job_fields((int) $order->get_id(), $job_key, [
            'last_step' => $step,
        ]);
    }

    public static function get_job_last_step(WC_Order $order, string $job_key): string
    {
        $row = self::get_job_row((int) $order->get_id(), $job_key, ['last_step']);
        return isset($row['last_step']) ? (string) $row['last_step'] : '';
    }

    /**
     * Mark retry scheduled (Option C state machine).
     *
     * @param string[] $codes
     */
    public static function mark_job_retry_scheduled(
        WC_Order $order,
        string $job_key,
        string $next_run_at_iso,
        string $reason,
        array $codes = [],
        string $step = ''
    ): void {
        $codes = self::normalize_codes($codes);
        $step  = strtolower(trim($step));
        if ($step !== 'validate' && $step !== 'place') $step = '';

        $next_mysql = self::iso_to_mysql_utc($next_run_at_iso);

        self::update_job_fields((int) $order->get_id(), $job_key, [
            'status'          => OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED,
            'last_error'      => (string) $reason,
            'last_codes_json' => wp_json_encode($codes),
            'next_run_at'     => ($next_mysql !== '') ? $next_mysql : null,
            'last_step'       => $step,
            'action_id'       => null,
        ]);
    }

    /* ===================== Snapshots ===================== */

    /** @param array<string,mixed> $snapshot */
    public static function set_job_validation_result(WC_Order $order, string $job_key, array $snapshot): void
    {
        self::update_job_fields((int) $order->get_id(), $job_key, [
            'validate_result_json' => wp_json_encode($snapshot),
        ]);
    }

    /** @return array<string,mixed>|null */
    public static function get_job_validation_result(WC_Order $order, string $job_key): ?array
    {
        $row = self::get_job_row((int) $order->get_id(), $job_key, ['validate_result_json']);
        $json = isset($row['validate_result_json']) ? (string) $row['validate_result_json'] : '';
        if ($json === '') return null;

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $snapshot */
    public static function set_job_place_result(WC_Order $order, string $job_key, array $snapshot): void
    {
        self::update_job_fields((int) $order->get_id(), $job_key, [
            'place_result_json' => wp_json_encode($snapshot),
        ]);
    }

    /** @return array<string,mixed>|null */
    public static function get_job_place_result(WC_Order $order, string $job_key): ?array
    {
        $row = self::get_job_row((int) $order->get_id(), $job_key, ['place_result_json']);
        $json = isset($row['place_result_json']) ? (string) $row['place_result_json'] : '';
        if ($json === '') return null;

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /* ===================== Correlation ID (merchant PO) ===================== */

    /**
     * Persist the merchant correlation ID (per job).
     * Safe to write early (before validate/place) since it is NOT a distributor order number.
     *
     * Idempotent: if merchant_po is already set, it will not be overwritten unless $force=true.
     */
    public static function set_job_merchant_po(WC_Order $order, string $job_key, string $merchant_po, bool $force = false): void
    {
        global $wpdb;

        $oid     = (int) $order->get_id();
        $table   = OrderPlacementJobsTable::get_table_name();
        $job_key = self::normalize_job_key($job_key);

        $merchant_po = trim((string) $merchant_po);
        if ($merchant_po === '') return;

        $now = self::now_mysql_utc();

        if ($force) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                     SET merchant_po = %s, updated_at = %s
                     WHERE order_id = %d AND job_key = %s",
                    $merchant_po,
                    $now,
                    $oid,
                    $job_key
                )
            );
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET merchant_po = %s, updated_at = %s
                 WHERE order_id = %d AND job_key = %s
                   AND (merchant_po IS NULL OR merchant_po = '')",
                $merchant_po,
                $now,
                $oid,
                $job_key
            )
        );
    }

    public static function get_job_merchant_po(WC_Order $order, string $job_key): string
    {
        $row = self::get_job_row((int) $order->get_id(), $job_key, ['merchant_po']);
        return isset($row['merchant_po']) ? (string) $row['merchant_po'] : '';
    }

    /* ===================== External order ids (ONLY on successful place) ===================== */

    /**
     * Persist distributor order identifiers for this job.
     *
     * IMPORTANT: Caller should only invoke this after a successful place order
     * (i.e. DistributorOrderResult OK). This is the "real order exists" contract.
     *
     * @param string[] $external_ids
     */
    public static function set_job_external_order_ids(WC_Order $order, string $job_key, array $external_ids, bool $force = false): void
    {
        global $wpdb;

        $oid     = (int) $order->get_id();
        $table   = OrderPlacementJobsTable::get_table_name();
        $job_key = self::normalize_job_key($job_key);

        $external_ids = self::normalize_external_ids($external_ids);
        if (empty($external_ids)) return;

        $json = wp_json_encode($external_ids);
        $now  = self::now_mysql_utc();

        if ($force) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                     SET external_order_ids_json = %s, updated_at = %s
                     WHERE order_id = %d AND job_key = %s",
                    $json,
                    $now,
                    $oid,
                    $job_key
                )
            );
            return;
        }

        // Only set if currently empty/null
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET external_order_ids_json = %s, updated_at = %s
                 WHERE order_id = %d AND job_key = %s
                   AND (external_order_ids_json IS NULL OR external_order_ids_json = '' OR external_order_ids_json = '[]')",
                $json,
                $now,
                $oid,
                $job_key
            )
        );
    }

    /** @return string[] */
    public static function get_job_external_order_ids(WC_Order $order, string $job_key): array
    {
        $row = self::get_job_row((int) $order->get_id(), $job_key, ['external_order_ids_json']);
        $json = isset($row['external_order_ids_json']) ? (string) $row['external_order_ids_json'] : '';
        if ($json === '') return [];

        $arr = json_decode($json, true);
        if (!is_array($arr)) return [];

        return self::normalize_external_ids($arr);
    }

    /**
     * Convenience for the runner:
     * - sets merchant_po if missing
     * - sets external ids if missing (caller must ensure real success)
     *
     * @param string[] $external_ids
     */
    public static function persist_success_ids(
        WC_Order $order,
        string $job_key,
        string $merchant_po,
        array $external_ids
    ): void {
        self::set_job_merchant_po($order, $job_key, $merchant_po, false);
        self::set_job_external_order_ids($order, $job_key, $external_ids, false);
    }

    /* ===================== Shipping: DB-backed fields on jobs table ===================== */

    public static function touch_last_shipping_poll_at(int $order_id, string $job_key): void
    {
        self::update_job_fields((int) $order_id, (string) $job_key, [
            'last_shipping_poll_at' => self::now_mysql_utc(),
        ]);
    }

    public static function set_external_order_id(int $order_id, string $job_key, string $external_order_id): void
    {
        $external_order_id = trim((string) $external_order_id);

        self::update_job_fields((int) $order_id, (string) $job_key, [
            'external_order_id' => ($external_order_id !== '') ? $external_order_id : null,
        ]);
    }

    /**
     * Mark a job as shipped (merge tracking/invoices; keep earliest shipped_at; do not clobber existing service/weight/raw).
     */
    public static function mark_job_shipped(int $order_id, string $job_key, DistributorShipment $shipment): ShippingUpdateResult
    {
        $order_id = (int) $order_id;
        $job_key  = self::normalize_job_key($job_key);

        // Load existing fields needed for merge/patch decisions
        $existing = self::get_job_row($order_id, $job_key, [
            'shipped_at',
            'tracking_numbers_json',
            'invoice_numbers_json',
            'shipping_service',
            'shipping_weight',
            'shipment_raw_json',
            'last_shipping_poll_at',
        ]);

        if (!is_array($existing) || empty($existing)) {
            error_log('[FFLHUB][JobsStore] mark_job_shipped missing row order=' . $order_id . ' job=' . $job_key);
            return new ShippingUpdateResult([], [], [], []);
        }

        $now = self::now_mysql_utc();

        // Pure business rules
        $patch = OrderPlacementShippingService::compute_patch($existing, $shipment, $now);

        // Always touch poll timestamps (even if no tracking found)
        $write = isset($patch['write']) && is_array($patch['write']) ? $patch['write'] : [];
        $write['last_shipping_poll_at'] = $now;
        $write['last_step'] = 'shipped';

        // Apply patch (safe partial update)
        if (!empty($write)) {
            self::update_job_fields($order_id, $job_key, $write);
        }

        return isset($patch['result']) && ($patch['result'] instanceof ShippingUpdateResult)
            ? $patch['result']
            : new ShippingUpdateResult([], [], [], []);
    }

    public static function are_all_success_jobs_shipped(int $order_id): bool
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();
        $order_id = (int) $order_id;

        $sql = $wpdb->prepare("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN tracking_numbers_json IS NOT NULL AND tracking_numbers_json <> '' THEN 1 ELSE 0 END) AS shipped
            FROM {$table}
            WHERE order_id = %d AND status = %s
        ", $order_id, OrderPlacementKeys::JOB_STATUS_SUCCESS);

        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!is_array($row)) return false;

        $total   = (int) ($row['total'] ?? 0);
        $shipped = (int) ($row['shipped'] ?? 0);

        return ($total > 0 && $shipped >= $total);
    }

    /**
     * Returns DistributorOrderLine[] from the job payload.
     *
     * @return DistributorOrderLine[]
     */
    public static function get_job_payload_lines(int $order_id, string $job_key): array
    {
        $row = self::get_job_row((int) $order_id, (string) $job_key, ['payload_json']);
        $payload_json = isset($row['payload_json']) ? (string) $row['payload_json'] : '';
        if ($payload_json === '') return [];

        $payload = json_decode($payload_json, true);
        if (!is_array($payload)) return [];

        $bucket = isset($payload['bucket']) ? strtolower(trim((string) $payload['bucket'])) : '';
        $ffl_required = ($bucket === 'ffl');

        $lines = $payload['lines'] ?? [];
        if (!is_array($lines) || empty($lines)) return [];

        $out = [];
        foreach ($lines as $line) {
            if (!is_array($line)) continue;

            $upc = isset($line['upc']) ? trim((string) $line['upc']) : '';
            if ($upc === '') continue;

            $qty = isset($line['qty']) ? (int) $line['qty'] : 0;

            $out[] = new DistributorOrderLine($upc, $qty, $ffl_required);
        }

        return $out;
    }

    /**
     * Select jobs eligible for shipping polling.
     *
     * @return OrderPlacementJobRow[]
     */
    public static function find_jobs_for_shipping_poll(
        string $status,
        string $poll_cutoff_mysql_utc,
        string $ship_cutoff_mysql_utc,
        int $limit
    ): array {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();

        $sql = $wpdb->prepare(
            "
            SELECT
                id, order_id, job_key, dist_id, bucket, status,
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
            $status,
            $poll_cutoff_mysql_utc,
            $ship_cutoff_mysql_utc,
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

    /* ===================== Internal helpers ===================== */

    /**
     * Fetch a job row as associative array (subset of columns).
     *
     * @param int $order_id
     * @param string $job_key
     * @param string[] $cols
     * @return array<string,mixed>
     */
    private static function get_job_row(int $order_id, string $job_key, array $cols): array
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();
        $job_key = self::normalize_job_key($job_key);

        $cols = array_values(array_filter(array_map('trim', $cols), function ($c) {
            return is_string($c) && $c !== '';
        }));

        if (empty($cols)) {
            $cols = ['id'];
        }

        $allowed = [
            'id',
            'order_id',
            'job_key',
            'dist_id',
            'bucket',
            'status',
            'attempts',
            'created_at',
            'updated_at',
            'action_id',
            'next_run_at',
            'last_step',
            'last_error',
            'last_codes_json',
            'done_at',
            'payload_json',
            'validate_result_json',
            'place_result_json',
            'merchant_po',
            'external_order_ids_json',
            'external_order_id',

            // shipping fields
            'shipped_at',
            'tracking_numbers_json',
            'invoice_numbers_json',
            'shipping_service',
            'shipping_weight',
            'shipment_raw_json',
            'last_shipping_poll_at',
        ];

        $sel = [];
        foreach ($cols as $c) {
            if (in_array($c, $allowed, true)) $sel[] = $c;
        }
        if (empty($sel)) $sel = ['id'];

        $sql = "SELECT " . implode(',', $sel) . " FROM {$table} WHERE order_id = %d AND job_key = %s LIMIT 1";

        $row = $wpdb->get_row(
            $wpdb->prepare($sql, $order_id, $job_key),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    /**
     * Update fields on a job row (partial update). NULL-safe.
     *
     * @param int $order_id
     * @param string $job_key
     * @param array<string,mixed> $fields
     */
    private static function update_job_fields(int $order_id, string $job_key, array $fields): void
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();
        $job_key = self::normalize_job_key($job_key);

        $fields = is_array($fields) ? $fields : [];
        if (empty($fields)) return;

        $fields['updated_at'] = self::now_mysql_utc();

        $allowed = [
            'dist_id',
            'bucket',
            'status',
            'attempts',
            'action_id',
            'next_run_at',
            'last_step',
            'last_error',
            'last_codes_json',
            'done_at',
            'payload_json',
            'validate_result_json',
            'place_result_json',
            'merchant_po',
            'external_order_ids_json',
            'external_order_id',

            // shipping fields
            'shipped_at',
            'tracking_numbers_json',
            'invoice_numbers_json',
            'shipping_service',
            'shipping_weight',
            'shipment_raw_json',
            'last_shipping_poll_at',

            'updated_at',
        ];

        $data = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $data[$k] = $v;
        }
        if (empty($data)) return;

        $has_null = false;
        foreach ($data as $v) {
            if ($v === null) {
                $has_null = true;
                break;
            }
        }

        if (!$has_null) {
            // No nulls: safe to use wpdb->update()
            $format = [];
            foreach ($data as $v) {
                $format[] = is_int($v) ? '%d' : '%s';
            }

            $wpdb->update(
                $table,
                $data,
                ['order_id' => $order_id, 'job_key' => $job_key],
                $format,
                ['%d', '%s']
            );
            return;
        }

        // NULL-safe manual UPDATE
        $sets = [];
        $args = [];

        foreach ($data as $k => $v) {
            if ($v === null) {
                $sets[] = "{$k} = NULL";
            } elseif (is_int($v)) {
                $sets[] = "{$k} = %d";
                $args[] = $v;
            } else {
                $sets[] = "{$k} = %s";
                $args[] = (string) $v;
            }
        }

        $args[] = $order_id;
        $args[] = $job_key;

        $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE order_id = %d AND job_key = %s";
        $wpdb->query($wpdb->prepare($sql, $args));
    }

    private static function normalize_job_key(string $job_key): string
    {
        return strtolower(trim((string) $job_key));
    }

    /** @param string[] $codes @return string[] */
    private static function normalize_codes(array $codes): array
    {
        $out = [];
        foreach ($codes as $c) {
            $c = trim((string) $c);
            if ($c !== '') $out[] = $c;
        }
        $out = array_values(array_unique($out));
        if (count($out) > 25) $out = array_slice($out, 0, 25);
        return $out;
    }

    /** @param mixed[] $ids @return string[] */
    private static function normalize_external_ids(array $ids): array
    {
        $out = [];
        foreach ($ids as $v) {
            $s = trim((string) $v);
            if ($s !== '') $out[] = $s;
        }
        $out = array_values(array_unique($out));
        if (count($out) > 25) $out = array_slice($out, 0, 25);
        return $out;
    }

    private static function now_mysql_utc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function iso_to_mysql_utc(string $iso): string
    {
        $iso = trim((string) $iso);
        if ($iso === '') return '';

        $ts = strtotime($iso);
        if ($ts === false) return '';

        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function mysql_utc_to_iso(string $mysql): string
    {
        $mysql = trim((string) $mysql);
        if ($mysql === '') return '';

        $ts = strtotime($mysql . ' UTC');
        if ($ts === false) return '';

        return gmdate('c', $ts);
    }



    /**
     * Get action_id values for jobs that represent FUTURE work we can cancel.
     * We deliberately avoid cancelling "running" work.
     *
     * @return int[]
     */
    public static function get_future_action_ids_for_order(int $order_id): array
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();
        $order_id = (int) $order_id;
        if ($order_id <= 0) return [];

        // Define which statuses represent "future scheduled work"
        $future_statuses = [
            OrderPlacementKeys::JOB_STATUS_QUEUED,
            OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED,
        ];

        $placeholders = implode(',', array_fill(0, count($future_statuses), '%s'));

        $sql = $wpdb->prepare(
            "
            SELECT action_id
            FROM {$table}
            WHERE order_id = %d
              AND action_id IS NOT NULL
              AND action_id > 0
              AND status IN ({$placeholders})
            ",
            array_merge([$order_id], $future_statuses)
        );

        $rows = $wpdb->get_col($sql);

        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $v) {
                $i = (int) $v;
                if ($i > 0) $out[] = $i;
            }
        }

        $out = array_values(array_unique($out));
        return $out;
    }

    /**
     * Clear action bookkeeping for an order so your admin UI reflects
     * that nothing is scheduled anymore.
     */
    public static function clear_actions_for_order(int $order_id, string $reason = ''): void
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();
        $order_id = (int) $order_id;
        if ($order_id <= 0) return;

        $now = self::now_mysql_utc();

        // Only clear for statuses that represent future work
        $future_statuses = [
            OrderPlacementKeys::JOB_STATUS_QUEUED,
            OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED,
        ];

        $placeholders = implode(',', array_fill(0, count($future_statuses), '%s'));

        // Keep semantics clean: DON'T change status.
        // Just clear scheduling fields + optionally annotate last_error.
        $sql = "
            UPDATE {$table}
            SET action_id = NULL,
                next_run_at = NULL,
                updated_at = %s
            WHERE order_id = %d
              AND status IN ({$placeholders})
        ";

        $args = array_merge([$now, $order_id], $future_statuses);
        $wpdb->query($wpdb->prepare($sql, $args));

        if (is_string($reason) && trim($reason) !== '') {
            // Optional: stamp last_error for visibility (still no status changes)
            $sql2 = "
                UPDATE {$table}
                SET last_error = %s,
                    updated_at = %s
                WHERE order_id = %d
                  AND status IN ({$placeholders})
            ";
            $args2 = array_merge([trim($reason), $now, $order_id], $future_statuses);
            $wpdb->query($wpdb->prepare($sql2, $args2));
        }
    }

    /**
     * Permanent delete: remove ALL job rows for this order.
     */
    public static function delete_jobs_for_order(int $order_id): void
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();
        $order_id = (int) $order_id;
        if ($order_id <= 0) return;

        $wpdb->delete($table, ['order_id' => $order_id], ['%d']);
    }
}
