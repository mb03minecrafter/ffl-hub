<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Util;

use FFLHub\Distributor\Models\OrderPlacementJobRow;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementPOUtil
 *
 * Responsibility:
 * - Build and sanitzie PO numbers
 *
 * No side effects. No DB access.
 *
 * PHP 7 compatible.
 */
final class OrderPlacementPOUtil
{


    // Build merchant PO from job row. It must stay unique within a 6 month
    // window, so use distributor + order id + lane code + split index.
    public static function build_merchant_po(
        OrderPlacementJobRow $job,
        int $split_index = 1
    ): string {
        $order_id = (int) $job->order_id;
        if ($order_id <= 0) {
            $order_id = 0;
        }

        $dist = strtoupper($job->dist_id_norm());
        if ($dist === '') {
            $dist = 'DIST';
        }

        $lane = $job->lane_norm();
        $lane_code = OrderPlacementKeysUtil::lane_code($lane);

        $i = (int) $split_index;
        if ($i < 1) {
            $i = 1;
        }

        $po = sprintf('%s%d%s%d', $dist, $order_id, $lane_code, $i);
        return self::sanitize_po($po, 22);
    }

    private static function sanitize_po(string $po, int $max_len = 22): string
    {
        $po = trim($po);
        if ($po === '') return '';

        $po = preg_replace('/[^A-Za-z0-9]+/', '', $po);
        $po = is_string($po) ? $po : '';

        if ($max_len > 0 && strlen($po) > $max_len) {
            $po = substr($po, 0, $max_len);
        }

        return $po;
    }
}
