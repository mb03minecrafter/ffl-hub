<?php

namespace FFLHub\Distributor\Services\Orders\Jobs;

use WC_Order;
use FFLHub\Distributor\Services\Orders\OrderPlacementKeys;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementPipelineMetaStore
 *
 * Responsibility:
 * - Owns pipeline-wide metadata that lives directly on the WooCommerce order.
 * - This is NOT job-level state — it represents the lifecycle of the entire
 *   order placement pipeline (across all distributor jobs).
 *
 * Storage:
 * - Uses WC_Order meta fields defined in OrderPlacementKeys.
 *
 * Design notes:
 * - This class is intentionally narrow and stable.
 * - No DB access, no job table access, no business logic.
 * - Safe to call from UI, workers, cron, etc.
 *
 */
final class OrderPlacementPipelineMetaStore
{

    /**
     * Determine whether the placement pipeline has been started for this order.
     *
     * Semantics:
     * - Returns TRUE once the pipeline has been marked started.
     * - Returns FALSE if the pipeline has never been started or was reset.
     *
     * Storage:
     * - Reads WC_Order meta: META_PIPELINE_STARTED ("1" / "0").
     *
     * Idempotency:
     * - Safe to call repeatedly.
     */
    public static function get_pipeline_started(WC_Order $order): bool
    {
        return ((string) $order->get_meta(OrderPlacementKeys::META_PIPELINE_STARTED, true) === '1');
    }

    /**
     * Mark the placement pipeline as started or not started.
     *
     * Semantics:
     * - When $started === true:
     *     - Sets META_PIPELINE_STARTED = "1".
     *     - Optionally stamps who started the pipeline and when.
     * - When $started === false:
     *     - Sets META_PIPELINE_STARTED = "0".
     *     - Does NOT clear the timestamp fields (historical visibility).
     *
     * Storage:
     * - Writes WC_Order meta fields.
     *
     * Side effects:
     * - Does NOT call $order->save(); caller controls persistence.
     *
     * Idempotency:
     * - Safe to call multiple times with the same values.
     */
    public static function set_pipeline_started(
        WC_Order $order,
        bool $started,
        string $started_at = '',
        string $started_by = ''
    ): void {
        $order->update_meta_data(OrderPlacementKeys::META_PIPELINE_STARTED, $started ? '1' : '0');

        if ($started) {
            if ($started_at !== '') {
                $order->update_meta_data(OrderPlacementKeys::META_PIPELINE_STARTED_AT, $started_at);
            }
            if ($started_by !== '') {
                $order->update_meta_data(OrderPlacementKeys::META_PIPELINE_STARTED_BY, $started_by);
            }
        }
    }

    /**
     * Retrieve the timestamp when the pipeline was first started.
     *
     * Semantics:
     * - Returns a raw string as stored in order meta.
     * - Returns empty string if not set.
     *
     * Storage:
     * - Reads WC_Order meta: META_PIPELINE_STARTED_AT.
     *
     * Format:
     * - Caller controls timestamp format (ISO, MySQL, etc).
     */
    public static function get_pipeline_started_at(WC_Order $order): string
    {
        return (string) $order->get_meta(OrderPlacementKeys::META_PIPELINE_STARTED_AT, true);
    }

    /**
     * Retrieve the identifier of who started the pipeline.
     *
     * Semantics:
     * - Typically a username, system label, or service name.
     * - Returns empty string if not set.
     *
     * Storage:
     * - Reads WC_Order meta: META_PIPELINE_STARTED_BY.
     */
    public static function get_pipeline_started_by(WC_Order $order): string
    {
        return (string) $order->get_meta(OrderPlacementKeys::META_PIPELINE_STARTED_BY, true);
    }
}
