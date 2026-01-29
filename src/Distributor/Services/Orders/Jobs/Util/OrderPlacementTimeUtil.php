<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Util;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementTimeUtil
 *
 * Responsibility:
 * - Centralize all UTC-only time formatting and conversions used by order placement.
 * - NO side effects and NO DB access.
 *
 * PHP 7 compatible.
 */
final class OrderPlacementTimeUtil
{
    private function __construct() {}

    /**
     * Current UTC timestamp in MySQL DATETIME format.
     *
     * Example: 2026-01-27 19:42:15
     */
    public static function now_mysql_utc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Convert a unix timestamp (seconds) to MySQL UTC DATETIME string.
     *
     * Returns '' if invalid.
     */
    public static function unix_to_mysql_utc(int $ts): string
    {
        $ts = (int) $ts;
        if ($ts <= 0) {
            return '';
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * Convert ISO-8601 datetime string to MySQL UTC DATETIME string.
     *
     * Returns '' on failure.
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
     * Convert a MySQL UTC DATETIME string to ISO-8601 string.
     *
     * Returns '' on failure.
     */
    public static function mysql_utc_to_iso(string $mysql): string
    {
        $mysql = trim((string) $mysql);
        if ($mysql === '') {
            return '';
        }

        // Treat input as UTC
        $ts = strtotime($mysql . ' UTC');
        if ($ts === false) {
            return '';
        }

        return gmdate('c', $ts);
    }


    /**
     * Convert unix timestamp → ISO-8601 UTC string (e.g. 2026-01-28T03:41:22+00:00).
     */
    public static function unix_to_iso_utc(int $unix): string
    {
        if ($unix <= 0) {
            return '';
        }

        // gmdate('c') always returns ISO-8601 in UTC.
        return gmdate('c', $unix);
    }
}
