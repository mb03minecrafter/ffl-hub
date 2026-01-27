<?php

namespace FFLHub\Distributor\Services\Orders\Shipping;

use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Models\ShippingUpdateResult;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure shipping business logic (NO DB).
 *
 * Computes:
 * - merged tracking/invoice lists
 * - deltas (added_tracking / added_invoices)
 * - a partial "write patch" that the Store can persist safely
 */
final class OrderPlacementShippingService
{
    private function __construct() {}

    /**
     * Compute a DB patch and ShippingUpdateResult.
     *
     * @param array<string,mixed> $existing  Existing DB fields (subset).
     * @return array{write: array<string,mixed>, result: ShippingUpdateResult}
     */
    public static function compute_patch(array $existing, DistributorShipment $shipment, string $now_mysql_utc): array
    {
        // -----------------------------
        // Helpers
        // -----------------------------
        $decode_list = static function ($json): array {
            if (!is_string($json) || trim($json) === '') return [];
            $arr = json_decode($json, true);
            if (!is_array($arr)) return [];
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
        // Existing lists from DB
        // -----------------------------
        $existing_tracking = $decode_list($existing['tracking_numbers_json'] ?? '');
        $existing_invoices = $decode_list($existing['invoice_numbers_json'] ?? '');

        // -----------------------------
        // New lists from DTO
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

        // Deltas (for idempotent “new tracking => email trigger”)
        $added_tracking = array_values(array_diff($new_tracking, $existing_tracking));
        $added_invoices = array_values(array_diff($new_invoices, $existing_invoices));

        // -----------------------------
        // Keep existing service/weight/raw if already set; otherwise set from shipment
        // -----------------------------
        $existing_service = isset($existing['shipping_service']) ? trim((string) $existing['shipping_service']) : '';
        $existing_weight  = isset($existing['shipping_weight']) ? trim((string) $existing['shipping_weight']) : '';
        $existing_raw     = isset($existing['shipment_raw_json']) ? trim((string) $existing['shipment_raw_json']) : '';

        $service_to_write = null;
        if ($existing_service === '' && property_exists($shipment, 'shipping_service') && $shipment->shipping_service !== null) {
            $s = trim((string) $shipment->shipping_service);
            if ($s !== '') $service_to_write = $s;
        }

        $weight_to_write = null;
        if ($existing_weight === '' && property_exists($shipment, 'shipping_weight') && $shipment->shipping_weight !== null) {
            $s = trim((string) $shipment->shipping_weight);
            if ($s !== '') $weight_to_write = $s;
        }

        $raw_to_write = null;
        if ($existing_raw === '' && property_exists($shipment, 'raw') && is_array($shipment->raw) && !empty($shipment->raw)) {
            $j = wp_json_encode($shipment->raw);
            if (is_string($j) && $j !== '') $raw_to_write = $j;
        }

        // -----------------------------
        // shipped_at: keep earliest
        // -----------------------------
        $existing_shipped_at = isset($existing['shipped_at']) ? (string) $existing['shipped_at'] : '';
        $has_shipped_at = ($existing_shipped_at !== '' && $existing_shipped_at !== '0000-00-00 00:00:00');
        $shipped_at_to_write = $has_shipped_at ? null : $now_mysql_utc;

        // -----------------------------
        // Build write patch (only write what’s safe)
        // -----------------------------
        $write = [];

        if ($shipped_at_to_write !== null) {
            $write['shipped_at'] = $shipped_at_to_write;
        }

        if (!empty($merged_tracking)) {
            $j = wp_json_encode($merged_tracking);
            if (is_string($j) && $j !== '') $write['tracking_numbers_json'] = $j;
        }

        if (!empty($merged_invoices)) {
            $j = wp_json_encode($merged_invoices);
            if (is_string($j) && $j !== '') $write['invoice_numbers_json'] = $j;
        }

        if ($service_to_write !== null) {
            $write['shipping_service'] = $service_to_write;
        }

        if ($weight_to_write !== null) {
            $write['shipping_weight'] = $weight_to_write;
        }

        if ($raw_to_write !== null) {
            $write['shipment_raw_json'] = $raw_to_write;
        }

        return [
            'write'  => $write,
            'result' => new ShippingUpdateResult(
                $added_tracking,
                $added_invoices,
                $merged_tracking,
                $merged_invoices
            ),
        ];
    }
}
