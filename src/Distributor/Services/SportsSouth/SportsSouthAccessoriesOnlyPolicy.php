<?php

namespace FFLHub\Distributor\Services\SportsSouth;

use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optional Sports South catalog guard for stores that only want accessory rows.
 */
final class SportsSouthAccessoriesOnlyPolicy
{
    public const OPTION_KEY = 'accessories_only';

    public static function is_enabled(): bool
    {
        $raw = Options::get_distributor_option('sports_south', self::OPTION_KEY, '0');
        $raw = apply_filters('fflhub_sports_south_accessories_only', $raw);

        return self::to_boolish($raw, false);
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function should_skip_row(array $row): bool
    {
        return self::is_enabled() && self::row_is_ffl_or_sot($row);
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function row_is_ffl_or_sot(array $row): bool
    {
        return self::to_boolish($row['ffl_required'] ?? false, false)
            || self::to_boolish($row['sot_required'] ?? false, false);
    }

    /**
     * @param mixed $value
     */
    private static function to_boolish($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return $default;
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return $default;
        }

        if (in_array($raw, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($raw, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        return $default;
    }
}
