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

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementShippingJobStore
 *
 * Responsibility:
 * - Shipping-specific persistence for an Order Placement job row:
 *     - last_shipping_poll_at (poll pacing)
 *     - external_order_id (optional convenience field used by some distributors)
 *     - shipped_at / tracking / invoices / shipping_service / shipping_weight / shipment_raw_json
 *
 * Implementation:
 * - Reads the existing job row via OrderPlacementJobsRepository (DTO read).
 * - Computes a safe patch via OrderPlacementShippingService::compute_patch()
 *   (merge tracking/invoices, preserve earliest shipped_at, do not clobber service/weight/raw).
 * - Applies the patch via OrderPlacementJobWriter (single write primitive).
 *
 * Notes:
 * - ShippingUpdateResult is returned so the poller can decide whether to trigger emails.
 * - This store does NOT decide eligibility for polling; that’s a query responsibility.
 */
final class ShippingJobStore
{
    /**
     * Stamp last_shipping_poll_at to “now” (UTC MySQL) for a specific job.
     *
     * @param int $order_id WooCommerce order id.
     * @param string $job_key Job key (dist|bucket).
     */
    public static function touch_last_shipping_poll_at(int $order_id, string $job_key): void
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

        OrderPlacementJobWriter::apply_patch($order_id, $job_key, $patch);
    }

    /**
     * Persist an external order id (single string) on the job row.
     *
     * @param int $order_id WooCommerce order id.
     * @param string $job_key Job key.
     * @param string $external_order_id External id; empty string clears the field to NULL.
     */
    public static function set_external_order_id(int $order_id, string $job_key, string $external_order_id): void
    {
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

        OrderPlacementJobWriter::apply_patch($order_id, $job_key, $patch);
    }

    /**
     * Mark a job as shipped (shipping fields on jobs table).
     *
     * @param int $order_id WooCommerce order id.
     * @param string $job_key Job key.
     * @param DistributorShipment $shipment Shipment DTO from distributor lookup.
     * @return ShippingUpdateResult Result describing changes (added/merged lists).
     */
    public static function mark_job_shipped(int $order_id, string $job_key, DistributorShipment $shipment): ShippingUpdateResult
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return ShippingUpdateResult::empty();
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return ShippingUpdateResult::empty();
        }

        $job = OrderPlacementJobsRepository::get_job($order_id, $job_key);
        if (!$job) {
            error_log('[FFLHUB][ShippingJobStore] mark_job_shipped missing row order=' . $order_id . ' job=' . $job_key);
            return ShippingUpdateResult::empty();
        }

        $now = OrderPlacementTimeUtil::now_mysql_utc();

        // Compute a safe patch (shipping merge rules live here)
        $patch = ShippingService::compute_patch($job, $shipment, $now);

        if (!($patch instanceof OrderPlacementJobPatch)) {
            // Defensive: compute_patch should always return a patch
            error_log('[FFLHUB][ShippingJobStore] compute_patch did not return OrderPlacementJobPatch order=' . $order_id . ' job=' . $job_key);
            return ShippingUpdateResult::empty();
        }

        // Always touch polling + set last_step
        $patch = $patch
            ->touch_last_shipping_poll_at($now)
            ->with_last_step('shipped');

        // Apply write
        OrderPlacementJobWriter::apply_patch($order_id, $job_key, $patch);

        // Return computed result so caller can decide whether to trigger emails
        return $patch->shipping_result();
    }
}
