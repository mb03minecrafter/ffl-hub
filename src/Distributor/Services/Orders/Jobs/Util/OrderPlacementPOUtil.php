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


    //build merchant PO from job row, this PO MUST BE UNIQUE within a 6 month period, this ensures we build a unique PO by using order number, dist, etc
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

        $bucket = $job->bucket_norm();
        $bucket_code = OrderPlacementKeysUtil::bucket_code($bucket);

        $i = (int) $split_index;
        if ($i < 1) {
            $i = 1;
        }

        $po = sprintf('FH-%s-%d-%s%d', $dist, $order_id, $bucket_code, $i);
        return self::sanitize_po($po, 22);
    }

    private static function sanitize_po(string $po, int $max_len = 22): string
    {
        $po = trim($po);
        if ($po === '') return '';

        $po = preg_replace('/[^A-Za-z0-9 \-]+/', '-', $po);
        $po = is_string($po) ? $po : '';

        $po = preg_replace('/\s+/', ' ', $po);
        $po = is_string($po) ? trim($po) : '';

        $po = preg_replace('/\-{2,}/', '-', $po);
        $po = is_string($po) ? trim($po, '-') : '';

        if ($max_len > 0 && strlen($po) > $max_len) {
            $po = substr($po, 0, $max_len);
        }

        return $po;
    }
}
