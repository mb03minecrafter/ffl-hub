<?php

namespace FFLHub\Distributor\Services\Orders\Shipping;

use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Models\ShippingUpdateResult;
use FFLHub\Distributor\Models\OrderPlacementJobPatch;

if (!defined('ABSPATH')) {
    exit;
}

final class ShippingService
{
    /**
     * Compute a safe DB patch for shipping fields + a ShippingUpdateResult.
     *
     * Rules:
     * - Merge tracking/invoice lists (preserve order, de-dupe)
     * - "added_*" are new values present in shipment but not previously stored
     * - shipped_at: write only if missing (keep earliest)
     * - service/weight/raw: do NOT clobber; only write if existing is empty and shipment provides it
     */
    public static function compute_patch(
        OrderPlacementJobRow $existing,
        DistributorShipment $shipment,
        string $now_mysql_utc
    ): OrderPlacementJobPatch {

        // Existing lists from DB (DTO helpers)
        $existing_tracking = self::normalize_list($existing->tracking_numbers());
        $existing_invoices = self::normalize_list($existing->invoice_numbers());

        // New lists from shipment DTO
        $new_tracking = self::normalize_list(
            is_array($shipment->tracking_numbers ?? null) ? $shipment->tracking_numbers : []
        );
        $new_invoices = self::normalize_list(
            is_array($shipment->invoice_numbers ?? null) ? $shipment->invoice_numbers : []
        );

        // Merge (existing first, then new)
        $merged_tracking = self::merge_lists($existing_tracking, $new_tracking);
        $merged_invoices = self::merge_lists($existing_invoices, $new_invoices);

        // Deltas (idempotent “new tracking => email trigger”)
        $added_tracking = self::diff_new($new_tracking, $existing_tracking);
        $added_invoices = self::diff_new($new_invoices, $existing_invoices);

        // shipped_at: keep earliest
        $write = [];
        if (self::is_zero_date($existing->shipped_at ?? null)) {
            $write['shipped_at'] = $now_mysql_utc;
        }

        // tracking / invoices: only write if merged is non-empty AND differs from existing
        if (!empty($merged_tracking) && $merged_tracking !== $existing_tracking) {
            $j = wp_json_encode($merged_tracking);
            if (is_string($j) && $j !== '') {
                $write['tracking_numbers_json'] = $j;
            }
        }

        if (!empty($merged_invoices) && $merged_invoices !== $existing_invoices) {
            $j = wp_json_encode($merged_invoices);
            if (is_string($j) && $j !== '') {
                $write['invoice_numbers_json'] = $j;
            }
        }

        // service/weight/raw: do NOT clobber; only write if existing empty
        $existing_service = trim((string) ($existing->shipping_service ?? ''));
        if ($existing_service === '') {
            $s = ($shipment->shipping_service ?? null) !== null ? trim((string) $shipment->shipping_service) : '';
            if ($s !== '') {
                $write['shipping_service'] = $s;
            }
        }

        $existing_weight = trim((string) ($existing->shipping_weight ?? ''));
        if ($existing_weight === '') {
            $s = ($shipment->shipping_weight ?? null) !== null ? trim((string) $shipment->shipping_weight) : '';
            if ($s !== '') {
                $write['shipping_weight'] = $s;
            }
        }

        $existing_raw = trim((string) ($existing->shipment_raw_json ?? ''));
        if ($existing_raw === '' && is_array($shipment->raw ?? null) && !empty($shipment->raw)) {
            $j = wp_json_encode($shipment->raw);
            if (is_string($j) && $j !== '') {
                $write['shipment_raw_json'] = $j;
            }
        }

        $result = new ShippingUpdateResult(
            $added_tracking,
            $added_invoices,
            $merged_tracking,
            $merged_invoices
        );

        return OrderPlacementJobPatch::for_shipping($result, $write);
    }

    /* ===================== helpers ===================== */

    private static function is_zero_date($mysql): bool
    {
        $s = trim((string) $mysql);
        return ($s === '' || $s === '0000-00-00 00:00:00');
    }

    /**
     * @param array $vals
     * @return string[]
     */
    private static function normalize_list(array $vals): array
    {
        $out = [];
        foreach ($vals as $v) {
            $s = trim((string) $v);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Preserve order: existing first, then new values not already present.
     *
     * @param string[] $existing
     * @param string[] $incoming
     * @return string[]
     */
    private static function merge_lists(array $existing, array $incoming): array
    {
        $set = [];
        $out = [];

        foreach ($existing as $v) {
            $k = (string) $v;
            if ($k === '' || isset($set[$k])) continue;
            $set[$k] = true;
            $out[] = $k;
        }

        foreach ($incoming as $v) {
            $k = (string) $v;
            if ($k === '' || isset($set[$k])) continue;
            $set[$k] = true;
            $out[] = $k;
        }

        return $out;
    }

    /**
     * Values in $new that are not in $existing (keeps order from $new).
     *
     * @param string[] $new
     * @param string[] $existing
     * @return string[]
     */
    private static function diff_new(array $new, array $existing): array
    {
        if (empty($new)) return [];

        $set = [];
        foreach ($existing as $v) {
            $k = (string) $v;
            if ($k !== '') $set[$k] = true;
        }

        $out = [];
        foreach ($new as $v) {
            $k = (string) $v;
            if ($k === '' || isset($set[$k])) continue;
            $out[] = $k;
        }

        return array_values($out);
    }
}
