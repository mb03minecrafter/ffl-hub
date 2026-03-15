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
 *   - job_key (dist|lane)
 *   - dist_id
 *   - lane
 */
final class OrderPlacementKeysUtil
{
    public const LANE_DIRECT_SHIP_NON_FFL = 'direct_ship_non_ffl';
    public const LANE_DIRECT_SHIP_FFL     = 'direct_ship_ffl';
    public const LANE_DEALER_FULFILLED    = 'dealer_fulfilled';

    /** @var string[] */
    private const VALID_LANES = [
        self::LANE_DIRECT_SHIP_NON_FFL,
        self::LANE_DIRECT_SHIP_FFL,
        self::LANE_DEALER_FULFILLED,
    ];

    // -----------------------------
    // Normalization
    // -----------------------------

    /**
     * Normalize a job key into canonical form: "dist|lane".
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
            $lane  = self::normalize_lane($parts[1] ?? '');

            if ($dist === '' || !self::is_valid_lane($lane)) {
                return '';
            }

            return $dist . '|' . $lane;
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
     * Normalize lane.
     * Rule: trim + lowercase.
     */
    public static function normalize_lane(string $lane): string
    {
        $lane = strtolower(trim((string) $lane));
        return $lane;
    }

    // -----------------------------
    // Validation / helpers
    // -----------------------------

    public static function is_valid_lane(string $lane): bool
    {
        $l = self::normalize_lane($lane);
        return in_array($l, self::VALID_LANES, true);
    }

    public static function is_direct_ship_ffl_lane(string $lane): bool
    {
        $l = self::normalize_lane($lane);
        return ($l === self::LANE_DIRECT_SHIP_FFL);
    }

    public static function is_direct_ship_non_ffl_lane(string $lane): bool
    {
        $l = self::normalize_lane($lane);
        return ($l === self::LANE_DIRECT_SHIP_NON_FFL);
    }

    public static function is_dealer_fulfilled_lane(string $lane): bool
    {
        return self::normalize_lane($lane) === self::LANE_DEALER_FULFILLED;
    }

    /**
     * Convert lane to the code used in correlation IDs / POs.
     * Returns:
     * - 'F' for direct_ship_ffl
     * - 'N' for direct_ship_non_ffl
     * - 'D' for dealer_fulfilled
     * - 'U' for unknown/invalid
     */
    public static function lane_code(string $lane): string
    {
        $l = self::normalize_lane($lane);
        if ($l === self::LANE_DIRECT_SHIP_FFL) return 'F';
        if ($l === self::LANE_DIRECT_SHIP_NON_FFL) return 'N';
        if ($l === self::LANE_DEALER_FULFILLED) return 'D';
        return 'U';
    }

    // -----------------------------
    // Construction / parsing
    // -----------------------------

    /**
     * Build a canonical job key "dist|lane".
     * Returns empty string if inputs are invalid.
     */
    public static function build_job_key(string $dist_id, string $lane): string
    {
        $dist = self::normalize_dist_id($dist_id);
        $lane = self::normalize_lane($lane);

        if ($dist === '' || !self::is_valid_lane($lane)) {
            return '';
        }

        return $dist . '|' . $lane;
    }

    /**
     * Split a job key into [dist_id, lane] (both normalized).
     * Returns ['dist_id' => '', 'lane' => ''] if invalid.
     *
     * @return array{dist_id:string,lane:string}
     */
    public static function split_job_key(string $job_key): array
    {
        $norm = self::normalize_job_key($job_key);
        if ($norm === '' || strpos($norm, '|') === false) {
            return ['dist_id' => '', 'lane' => ''];
        }

        $parts = explode('|', $norm, 2);
        return [
            'dist_id' => (string) ($parts[0] ?? ''),
            'lane'    => (string) ($parts[1] ?? ''),
        ];
    }


    //is this an order post?
    private static function is_shop_order_post(int $post_id): bool
    {
        $post_type = get_post_type($post_id);
        return ($post_type === 'shop_order' || $post_type === 'shop_order_placehold');
    }
}
