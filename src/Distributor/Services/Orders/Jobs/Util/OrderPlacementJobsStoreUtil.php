<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Util;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobsStoreUtil
 *
 * Responsibility:
 * - Small shared helper functions used by job stores and repositories.
 * - Contains NO database access and NO side effects.
 * - Safe for reuse across all job-related classes.
 *
 * Design goals:
 * - Centralize normalization logic so behavior stays consistent everywhere.
 * - Keep time conversions predictable and UTC-only.
 * - Avoid copy/paste helpers across stores.
 *
 * PHP 7 compatible.
 */
final class OrderPlacementJobsStoreUtil
{

    

    /**
     * Normalize a list of external order IDs.
     *
     * Rules:
     * - Cast everything to string
     * - Trim whitespace
     * - Drop empty values
     * - Remove duplicates (preserve first occurrence order)
     * - Hard limit to 25 entries (safety guard)
     *
     * Why:
     * - Prevents bloating JSON columns with garbage data.
     * - Keeps admin UI predictable.
     * - Avoids unbounded growth from buggy integrations.
     *
     * @param array $ids Raw external ids.
     * @return string[] Normalized external ids.
     */
    public static function normalize_external_ids(array $ids): array
    {
        $out = [];

        foreach ($ids as $v) {
            $s = trim((string) $v);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        // De-duplicate while preserving order
        $out = array_values(array_unique($out));

        // Safety cap
        if (count($out) > 25) {
            $out = array_slice($out, 0, 25);
        }

        return $out;
    }

    /**
     * Get the current UTC timestamp in MySQL DATETIME format.
     *
     * Format:
     *   YYYY-mm-dd HH:ii:ss (UTC)
     *
     * Example:
     *   2026-01-27 19:42:15
     *
     * Why:
     * - Keeps DB timestamps consistent and timezone-safe.
     * - Avoids PHP default timezone issues.
     *
     * @return string MySQL UTC datetime string.
     */
    public static function now_mysql_utc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Convert an ISO-8601 datetime string into MySQL UTC format.
     *
     * Accepted examples:
     *   2026-01-27T19:42:15Z
     *   2026-01-27T13:42:15-06:00
     *
     * Behavior:
     * - Invalid or empty input returns empty string.
     * - Always normalizes into UTC.
     *
     * @param string $iso ISO-8601 datetime string.
     * @return string MySQL UTC datetime string or empty string on failure.
     */
    public static function iso_to_mysql_utc(string $iso): string
    {
        $iso = trim((string) $iso);
        if ($iso === '') {
            return '';
        }

        $ts = strtotime($iso);
        if ($ts === false) {
            return '';
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * Convert a MySQL UTC datetime string into ISO-8601 format.
     *
     * Input example:
     *   2026-01-27 19:42:15
     *
     * Output example:
     *   2026-01-27T19:42:15+00:00
     *
     * Behavior:
     * - Invalid or empty input returns empty string.
     * - Assumes input is UTC.
     *
     * @param string $mysql MySQL UTC datetime string.
     * @return string ISO-8601 datetime string or empty string on failure.
     */
    public static function mysql_utc_to_iso(string $mysql): string
    {
        $mysql = trim((string) $mysql);
        if ($mysql === '') {
            return '';
        }

        $ts = strtotime($mysql . ' UTC');
        if ($ts === false) {
            return '';
        }

        return gmdate('c', $ts);
    }
}
