<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Util;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementKeysUtil
 *
 * Responsibility:
 * - Canonical normalization + construction for order-placement keys:
 *   - job_key (dist|bucket)
 *   - dist_id
 *   - bucket
 */
final class OrderPlacementKeysUtil
{
    // Lane-style values only.
    public const BUCKET_DIRECT_SHIP_NON_FFL = 'direct_ship_non_ffl';
    public const BUCKET_DIRECT_SHIP_FFL     = 'direct_ship_ffl';
    public const BUCKET_DEALER_FULFILLED    = 'dealer_fulfilled';

    /** @var string[] */
    private const VALID_BUCKETS = [
        self::BUCKET_DIRECT_SHIP_NON_FFL,
        self::BUCKET_DIRECT_SHIP_FFL,
        self::BUCKET_DEALER_FULFILLED,
    ];

    // -----------------------------
    // Normalization
    // -----------------------------

    /**
     * Normalize a job key into canonical form: "dist|bucket".
     */
    public static function normalize_job_key(string $job_key): string
    {
        $job_key = strtolower(trim((string) $job_key));
        if ($job_key === '') {
            return '';
        }

        // If it already contains a pipe, normalize both sides.
        if (strpos($job_key, '|') !== false) {
            $parts = explode('|', $job_key, 2);
            $dist  = self::normalize_dist_id($parts[0] ?? '');
            $buck  = self::normalize_bucket($parts[1] ?? '');

            if ($dist === '' || !self::is_valid_bucket($buck)) {
                return '';
            }

            return $dist . '|' . $buck;
        }

        // If no pipe, treat as invalid (caller should build via build_job_key()).
        return '';
    }

    /**
     * Normalize dist_id.
     * Rule: trim + lowercase.
     */
    public static function normalize_dist_id(string $dist_id): string
    {
        $dist_id = strtolower(trim((string) $dist_id));
        return $dist_id;
    }

    /**
     * Normalize bucket.
     * Rule: trim + lowercase.
     */
    public static function normalize_bucket(string $bucket): string
    {
        $bucket = strtolower(trim((string) $bucket));
        return $bucket;
    }

    // -----------------------------
    // Validation / helpers
    // -----------------------------

    public static function is_valid_bucket(string $bucket): bool
    {
        $b = self::normalize_bucket($bucket);
        return in_array($b, self::VALID_BUCKETS, true);
    }

    public static function is_ffl_bucket(string $bucket): bool
    {
        $b = self::normalize_bucket($bucket);
        return ($b === self::BUCKET_DIRECT_SHIP_FFL);
    }

    public static function is_non_bucket(string $bucket): bool
    {
        $b = self::normalize_bucket($bucket);
        return ($b === self::BUCKET_DIRECT_SHIP_NON_FFL);
    }

    public static function is_dealer_fulfilled_bucket(string $bucket): bool
    {
        return self::normalize_bucket($bucket) === self::BUCKET_DEALER_FULFILLED;
    }

    /**
     * Convert bucket to the code used in correlation IDs / POs.
     * Returns:
     * - 'F' for direct_ship_ffl
     * - 'N' for direct_ship_non_ffl
     * - 'D' for dealer_fulfilled
     * - 'U' for unknown/invalid
     */
    public static function bucket_code(string $bucket): string
    {
        $b = self::normalize_bucket($bucket);
        if ($b === self::BUCKET_DIRECT_SHIP_FFL) return 'F';
        if ($b === self::BUCKET_DIRECT_SHIP_NON_FFL) return 'N';
        if ($b === self::BUCKET_DEALER_FULFILLED) return 'D';
        return 'U';
    }

    // -----------------------------
    // Construction / parsing
    // -----------------------------

    /**
     * Build a canonical job key "dist|bucket".
     * Returns empty string if inputs are invalid.
     */
    public static function build_job_key(string $dist_id, string $bucket): string
    {
        $dist = self::normalize_dist_id($dist_id);
        $buck = self::normalize_bucket($bucket);

        if ($dist === '' || !self::is_valid_bucket($buck)) {
            return '';
        }

        return $dist . '|' . $buck;
    }

    /**
     * Split a job key into [dist_id, bucket] (both normalized).
     * Returns ['dist_id' => '', 'bucket' => ''] if invalid.
     *
     * @return array{dist_id:string,bucket:string}
     */
    public static function split_job_key(string $job_key): array
    {
        $norm = self::normalize_job_key($job_key);
        if ($norm === '' || strpos($norm, '|') === false) {
            return ['dist_id' => '', 'bucket' => ''];
        }

        $parts = explode('|', $norm, 2);
        return [
            'dist_id' => (string) ($parts[0] ?? ''),
            'bucket'  => (string) ($parts[1] ?? ''),
        ];
    }


    //is this an order post?
    private static function is_shop_order_post(int $post_id): bool
    {
        $post_type = get_post_type($post_id);
        return ($post_type === 'shop_order' || $post_type === 'shop_order_placehold');
    }
}
