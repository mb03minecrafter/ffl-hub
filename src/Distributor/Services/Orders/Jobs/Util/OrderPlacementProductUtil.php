<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Util;

use WC_Product;
use FFLHub\Product\ProductMeta;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementProductUtil
 *
 * Responsibility:
 * - Normalize and extract product identifiers needed by order placement pipeline.
 * - NO side effects and NO DB access.
 *
 * PHP 7 compatible.
 */
final class OrderPlacementProductUtil
{

    /**
     * Normalize a UPC-like value to digits-only.
     *
     * Rules:
     * - Trim
     * - Keep digits only
     * - Return '' if empty after normalization
     */
    public static function normalize_upc(string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        // Fast path: already digits
        if (ctype_digit($raw)) {
            return $raw;
        }

        $v = preg_replace('/\D+/', '', $raw);
        $v = is_string($v) ? $v : '';
        $v = trim($v);

        return ($v !== '' && ctype_digit($v)) ? $v : '';
    }

    /**
     * Extract a UPC for order placement from a WC_Product.
     *
     * Preference order:
     *  1) get_global_unique_id() (if available)
     *  2) ProductMeta::FFLHUB_UPC_META
     *
     * Returns digits-only UPC or '' if not found/invalid.
     */
    public static function extract_upc_from_product(WC_Product $product): string
    {
        $upc_raw = '';

        // Some WC versions / product types expose this.
        if (method_exists($product, 'get_global_unique_id')) {
            $upc_raw = trim((string) $product->get_global_unique_id());
        }

        if ($upc_raw === '') {
            $upc_raw = trim((string) $product->get_meta(ProductMeta::FFLHUB_UPC_META, true));
        }

        return self::normalize_upc($upc_raw);
    }


    /**
     * Normalize external order IDs coming back from distributors.
     *
     * Rules:
     * - Cast everything to string
     * - Trim whitespace
     * - Drop empty values
     * - De-duplicate
     * - Preserve original order (first occurrence wins)
     * - Hard cap length for safety
     *
     * @param array<int,mixed> $ids
     * @return string[]
     */
    public static function normalize_external_ids(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $out  = [];
        $seen = [];

        foreach ($ids as $v) {
            $s = trim((string) $v);
            if ($s === '') {
                continue;
            }

            // Preserve first occurrence order while de-duping
            if (isset($seen[$s])) {
                continue;
            }

            $seen[$s] = true;
            $out[]    = $s;

            // Safety cap (protect DB + logs)
            if (count($out) >= 25) {
                break;
            }
        }

        return $out;
    }
}
