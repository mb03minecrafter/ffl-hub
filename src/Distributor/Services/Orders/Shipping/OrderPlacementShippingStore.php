<?php

namespace FFLHub\Distributor\Services\Orders\Shipping;

use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Services\Tables\OrderPlacementJobsTable;

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
        $job_key = strtolower(trim((string) $job_key));

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
        $job_key = strtolower(trim((string) $job_key));

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




    /**
     * @return array{added_tracking:array<int,string>, added_invoices:array<int,string>, merged_tracking:array<int,string>, merged_invoices:array<int,string>}
     */
    public static function mark_job_shipped(int $order_id, string $job_key, DistributorShipment $shipment): array
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();
        $now   = gmdate('Y-m-d H:i:s');

        $order_id = (int) $order_id;
        $job_key  = strtolower(trim((string) $job_key));

        // -----------------------------
        // 1) Load existing row (for merge)
        // -----------------------------
        $sql = "
        SELECT
            shipped_at,
            tracking_numbers_json,
            invoice_numbers_json,
            shipping_service,
            shipping_weight,
            shipment_raw_json
        FROM {$table}
        WHERE order_id = %d AND job_key = %s
        LIMIT 1
    ";

        $existing = $wpdb->get_row(
            $wpdb->prepare($sql, $order_id, $job_key),
            ARRAY_A
        );

        if (!is_array($existing) || empty($existing)) {
            error_log(self::LOG_PREFIX . " mark_job_shipped missing row order={$order_id} job={$job_key}");
            return [
                'added_tracking'  => [],
                'added_invoices'  => [],
                'merged_tracking' => [],
                'merged_invoices' => [],
            ];
        }

        // -----------------------------
        // 2) Helpers
        // -----------------------------
        $decode_list = static function ($json): array {
            if (!is_string($json) || trim($json) === '') {
                return [];
            }
            $arr = json_decode($json, true);
            if (!is_array($arr)) {
                return [];
            }
            $out = [];
            foreach ($arr as $v) {
                $s = trim((string) $v);
                if ($s !== '') $out[] = $s;
            }
            return array_values(array_unique($out));
        };

        $normalize_list = static function (array $vals): array {
            $out = [];
            foreach ($vals as $v) {
                $s = trim((string) $v);
                if ($s !== '') $out[] = $s;
            }
            return array_values(array_unique($out));
        };

        $merge_lists = static function (array $a, array $b): array {
            // preserve order: existing first, then new items not already present
            $set = [];
            $out = [];

            foreach ($a as $v) {
                $k = (string) $v;
                if ($k === '' || isset($set[$k])) continue;
                $set[$k] = true;
                $out[] = $k;
            }
            foreach ($b as $v) {
                $k = (string) $v;
                if ($k === '' || isset($set[$k])) continue;
                $set[$k] = true;
                $out[] = $k;
            }

            return $out;
        };

        // -----------------------------
        // 3) Existing lists from DB
        // -----------------------------
        $existing_tracking = $decode_list($existing['tracking_numbers_json'] ?? '');
        $existing_invoices = $decode_list($existing['invoice_numbers_json'] ?? '');

        // -----------------------------
        // 4) New lists from DTO
        // -----------------------------
        $new_tracking = [];
        $new_invoices = [];

        if (property_exists($shipment, 'tracking_numbers') && is_array($shipment->tracking_numbers)) {
            $new_tracking = $normalize_list($shipment->tracking_numbers);
        }
        if (property_exists($shipment, 'invoice_numbers') && is_array($shipment->invoice_numbers)) {
            $new_invoices = $normalize_list($shipment->invoice_numbers);
        }

        // Merge
        $merged_tracking = $merge_lists($existing_tracking, $new_tracking);
        $merged_invoices = $merge_lists($existing_invoices, $new_invoices);

        // ✅ Deltas (for email trigger / idempotency)
        // Note: array_diff preserves values but not necessarily order; we re-index.
        $added_tracking = array_values(array_diff($merged_tracking, $existing_tracking));
        $added_invoices = array_values(array_diff($merged_invoices, $existing_invoices));

        // -----------------------------
        // 5) Service/weight: keep existing if already set, otherwise set from shipment
        // -----------------------------
        $shipping_service = isset($existing['shipping_service']) ? trim((string) $existing['shipping_service']) : '';
        if ($shipping_service === '' && property_exists($shipment, 'shipping_service') && $shipment->shipping_service !== null) {
            $s = trim((string) $shipment->shipping_service);
            if ($s !== '') $shipping_service = $s;
        }
        $shipping_service = ($shipping_service !== '') ? $shipping_service : null;

        $shipping_weight = isset($existing['shipping_weight']) ? trim((string) $existing['shipping_weight']) : '';
        if ($shipping_weight === '' && property_exists($shipment, 'shipping_weight') && $shipment->shipping_weight !== null) {
            $s = trim((string) $shipment->shipping_weight);
            if ($s !== '') $shipping_weight = $s;
        }
        $shipping_weight = ($shipping_weight !== '') ? $shipping_weight : null;

        // -----------------------------
        // 5b) shipment_raw_json: keep existing if set; otherwise set from DTO raw
        // -----------------------------
        $existing_raw = isset($existing['shipment_raw_json']) ? trim((string) $existing['shipment_raw_json']) : '';

        $raw_json_to_write = null;
        if ($existing_raw === '' && property_exists($shipment, 'raw') && is_array($shipment->raw) && !empty($shipment->raw)) {
            $j = wp_json_encode($shipment->raw);
            if (is_string($j) && $j !== '') {
                $raw_json_to_write = $j;
            }
        }

        // -----------------------------
        // 6) shipped_at: keep earliest
        // -----------------------------
        $existing_shipped_at = isset($existing['shipped_at']) ? (string) $existing['shipped_at'] : '';
        $has_shipped_at = ($existing_shipped_at !== '' && $existing_shipped_at !== '0000-00-00 00:00:00');
        $shipped_at_to_write = $has_shipped_at ? $existing_shipped_at : $now;

        // -----------------------------
        // 7) Build UPDATE dynamically so we never clobber with NULL/empty
        // -----------------------------
        $data    = [];
        $formats = [];

        // Always update poll timestamps
        $data['last_shipping_poll_at'] = $now;
        $formats[] = '%s';
        $data['updated_at']            = $now;
        $formats[] = '%s';

        // shipped_at: only set if it wasn't set before
        if (!$has_shipped_at) {
            $data['shipped_at'] = $shipped_at_to_write;
            $formats[] = '%s';
        }

        // tracking/invoice JSON: ONLY write if merged list is non-empty
        if (!empty($merged_tracking)) {
            $tracking_json = wp_json_encode($merged_tracking);
            if (is_string($tracking_json) && $tracking_json !== '') {
                $data['tracking_numbers_json'] = $tracking_json;
                $formats[] = '%s';
            }
        }

        if (!empty($merged_invoices)) {
            $invoice_json = wp_json_encode($merged_invoices);
            if (is_string($invoice_json) && $invoice_json !== '') {
                $data['invoice_numbers_json'] = $invoice_json;
                $formats[] = '%s';
            }
        }

        // service/weight: only write if we have a non-null value (and existing was empty)
        if ($shipping_service !== null && (trim((string) ($existing['shipping_service'] ?? '')) === '')) {
            $data['shipping_service'] = $shipping_service;
            $formats[] = '%s';
        }

        if ($shipping_weight !== null && (trim((string) ($existing['shipping_weight'] ?? '')) === '')) {
            $data['shipping_weight'] = $shipping_weight;
            $formats[] = '%s';
        }

        if ($raw_json_to_write !== null) {
            $data['shipment_raw_json'] = $raw_json_to_write;
            $formats[] = '%s';
        }

        $where = [
            'order_id' => $order_id,
            'job_key'  => $job_key,
        ];

        $updated = $wpdb->update(
            $table,
            $data,
            $where,
            $formats,
            ['%d', '%s']
        );

        if ($updated === false) {
            error_log(self::LOG_PREFIX . " failed mark_job_shipped order={$order_id} job={$job_key}");
        }




        return [
            'added_tracking'  => $added_tracking,
            'added_invoices'  => $added_invoices,
            'merged_tracking' => $merged_tracking,
            'merged_invoices' => $merged_invoices,
        ];
    }



    public static function are_all_success_jobs_shipped(int $order_id): bool
    {
        global $wpdb;
        $table = OrderPlacementJobsTable::get_table_name();

        $sql = $wpdb->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN tracking_numbers_json IS NOT NULL AND tracking_numbers_json <> '' THEN 1 ELSE 0 END) AS shipped
        FROM {$table}
        WHERE order_id = %d AND status = %s
    ", $order_id, \FFLHub\Distributor\Services\Orders\OrderPlacementKeys::JOB_STATUS_SUCCESS);

        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!is_array($row)) return false;

        $total   = (int) ($row['total'] ?? 0);
        $shipped = (int) ($row['shipped'] ?? 0);

        return ($total > 0 && $shipped >= $total);
    }
}
