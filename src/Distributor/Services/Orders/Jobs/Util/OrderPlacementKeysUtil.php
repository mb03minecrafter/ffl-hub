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
        return ($b === 'ffl' || $b === 'non');
    }

    public static function is_ffl_bucket(string $bucket): bool
    {
        return self::normalize_bucket($bucket) === 'ffl';
    }

    public static function is_non_bucket(string $bucket): bool
    {
        return self::normalize_bucket($bucket) === 'non';
    }

    /**
     * Convert bucket to the code used in correlation IDs / POs.
     * Returns:
     * - 'F' for ffl
     * - 'N' for non
     * - 'U' for unknown/invalid
     */
    public static function bucket_code(string $bucket): string
    {
        $b = self::normalize_bucket($bucket);
        if ($b === 'ffl') return 'F';
        if ($b === 'non') return 'N';
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
