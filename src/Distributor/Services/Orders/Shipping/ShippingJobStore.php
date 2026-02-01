<?php

namespace FFLHub\Distributor\Services\Orders\Shipping;

use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Models\ShippingUpdateResult;
use FFLHub\Distributor\Models\OrderPlacementJobPatch;

use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;

use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementProductUtil;

use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
// If ShippingService is not in this namespace, import the correct class:
// use FFLHub\Distributor\Services\Orders\Shipping\ShippingService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ShippingJobStore
 *
 * Responsibility:
 * - Shipping-specific persistence helpers for an Order Placement job row:
 *     - last_shipping_poll_at (poll pacing / observability)
 *     - external_order_id (optional convenience field used by some distributors)
 *     - shipped_at / tracking / invoices / shipping_service / shipping_weight / shipment_raw_json
 *
 * Implementation:
 * - Reads the existing job row via OrderPlacementJobsRepository (DTO read).
 * - Computes a safe patch via ShippingService::compute_patch():
 *     - merge tracking/invoices (append + dedupe)
 *     - preserve earliest shipped_at
 *     - avoid clobbering existing service/weight/raw unless new data is meaningful
 * - Applies the patch via OrderPlacementJobWriter (single write primitive).
 *
 * Notes:
 * - Returns ShippingUpdateResult so the poller can decide whether to trigger emails.
 * - This store does NOT decide eligibility for polling; that belongs in repository queries.
 * - Poll pacing:
 *   Typically the poller should touch last_shipping_poll_at *only when it actually calls the distributor*.
 *   This class can also touch as part of persistence, but that’s optional/redundant.
 */
final class ShippingJobStore
{
    /**
     * Stamp last_shipping_poll_at to "now" (UTC MySQL) for a specific job.
     *
     * Intended usage: call this right before making a distributor API request,
     * so poll pacing reflects actual lookups (not mere eligibility checks).
     *
     * @param OrderPlacementJobsTable $jobs_table Jobs table manager (table name resolution).
     * @param int $order_id WooCommerce order id.
     * @param string $job_key Job key (dist|bucket).
     */
    public static function touch_last_shipping_poll_at(OrderPlacementJobsTable $jobs_table, int $order_id, string $job_key): void
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return;
        }

        $patch = OrderPlacementJobPatch::empty()
            ->touch_last_shipping_poll_at(OrderPlacementTimeUtil::now_mysql_utc());

        OrderPlacementJobWriter::apply_patch($jobs_table, $order_id, $job_key, $patch);
    }

    /**
     * Persist an external order id (single string) on the job row.
     *
     * Some distributors expose/require a separate external id in addition to merchant_po.
     * This is purely a convenience column; the canonical ids list is external_order_ids_json.
     *
     * @param OrderPlacementJobsTable $jobs_table Jobs table manager.
     * @param int $order_id WooCommerce order id.
     * @param string $job_key Job key.
     * @param string $external_order_id External id; empty string clears the field to NULL.
     */
    public static function set_external_order_id(
        OrderPlacementJobsTable $jobs_table,
        int $order_id,
        string $job_key,
        string $external_order_id
    ): void {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return;
        }

        // Normalize via the same rules as external ids list, then take the first (or clear).
        $normalized = OrderPlacementProductUtil::normalize_external_ids([(string) $external_order_id]);
        $ext = $normalized[0] ?? '';

        $patch = OrderPlacementJobPatch::empty()
            ->with_field('external_order_id', ($ext !== '') ? $ext : null);

        OrderPlacementJobWriter::apply_patch($jobs_table, $order_id, $job_key, $patch);
    }

    /**
     * Persist shipment details onto a job row (shipping fields).
     *
     * This method assumes the caller already performed the distributor lookup and has a shipment DTO.
     * It will:
     * - load the current job row
     * - compute a safe merge patch
     * - apply the patch
     * - return a ShippingUpdateResult describing what materially changed
     *
     * @param OrderPlacementJobsTable $jobs_table Jobs table manager.
     * @param int $order_id WooCommerce order id.
     * @param string $job_key Job key.
     * @param DistributorShipment $shipment Shipment DTO from distributor lookup.
     * @return ShippingUpdateResult Result describing changes (e.g., added tracking numbers).
     */
    public static function mark_job_shipped(
        OrderPlacementJobsTable $jobs_table,
        int $order_id,
        string $job_key,
        DistributorShipment $shipment
    ): ShippingUpdateResult {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return ShippingUpdateResult::empty();
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return ShippingUpdateResult::empty();
        }

        $job = OrderPlacementJobsRepository::get_job($jobs_table, $order_id, $job_key);
        if (!$job) {
            error_log('[FFLHUB][ShippingJobStore] mark_job_shipped missing row order=' . $order_id . ' job=' . $job_key);
            return ShippingUpdateResult::empty();
        }

        $now = OrderPlacementTimeUtil::now_mysql_utc();

        // Compute a safe patch (shipping merge rules live in ShippingService).
        $patch = ShippingService::compute_patch($job, $shipment, $now);

        if (!($patch instanceof OrderPlacementJobPatch)) {
            error_log('[FFLHUB][ShippingJobStore] compute_patch did not return OrderPlacementJobPatch order=' . $order_id . ' job=' . $job_key);
            return ShippingUpdateResult::empty();
        }

        // Optional: touching last_shipping_poll_at here is redundant if the poller touched it
        // right before lookup, but harmless if you want persistence to always stamp it.
        $patch = $patch
            ->touch_last_shipping_poll_at($now)
            ->with_last_step('shipped');

        OrderPlacementJobWriter::apply_patch($jobs_table, $order_id, $job_key, $patch);

        $result = $patch->shipping_result();
        return ($result instanceof ShippingUpdateResult) ? $result : ShippingUpdateResult::empty();
    }
}
