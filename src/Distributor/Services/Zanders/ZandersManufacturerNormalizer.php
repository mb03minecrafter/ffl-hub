<?php

namespace FFLHub\Distributor\Services\Zanders;

if (!defined('ABSPATH')) {
    exit;
}

final class ZandersManufacturerNormalizer
{
    private function __construct()
    {
    }

    /**
     * Normalize Zanders manufacturer names for restricted dropship matching.
     *
     * @param mixed $value
     */
    public static function normalize($value): string
    {
        $value = strtoupper(trim((string) $value));
        $value = (string) preg_replace('/^\xEF\xBB\xBF/', '', $value);

        return str_replace(
            [' ', '&', '/', '-', '.', ',', '(', ')', "'"],
            '',
            $value
        );
    }

    /**
     * Canonical customer/debug-facing manufacturer label for Zanders rows.
     *
     * @param mixed $value
     */
    public static function canonical_display($value): string
    {
        $label = trim((string) $value);
        $label = (string) preg_replace('/^\xEF\xBB\xBF/', '', $label);

        return self::is_sig_normalized(self::normalize($label)) ? 'SIG SAUER' : $label;
    }

    /**
     * Canonical stored manufacturer norm for Zanders rows.
     *
     * This intentionally keeps SIG as "SIG SAUER" because downstream policy
     * checks and admin/reporting should use the same human-readable canonical
     * value, while generic restricted-manufacturer matching can still strip it
     * with sql_expression()/normalize() when needed.
     *
     * @param mixed $value
     */
    public static function canonical_norm($value): string
    {
        $normalized = self::normalize($value);

        return self::is_sig_normalized($normalized) ? 'SIG SAUER' : $normalized;
    }

    public static function sql_expression(string $sql_expression): string
    {
        $expr = "UPPER(TRIM({$sql_expression}))";

        foreach (["' '", "'&'", "'/'", "'-'", "'.'", "','", "'('", "')'", 'CHAR(39)'] as $needle) {
            $expr = "REPLACE({$expr}, {$needle}, '')";
        }

        return $expr;
    }

    public static function canonical_display_sql_expression(string $sql_expression): string
    {
        $trimmed = "TRIM(BOTH '\\r' FROM TRIM({$sql_expression}))";
        $norm = self::sql_expression($trimmed);

        return "
            CASE
                WHEN " . self::sig_normalized_sql_where($norm) . " THEN 'SIG SAUER'
                ELSE {$trimmed}
            END
        ";
    }

    public static function canonical_norm_sql_expression(string $sql_expression): string
    {
        $trimmed = "TRIM(BOTH '\\r' FROM TRIM({$sql_expression}))";
        $norm = self::sql_expression($trimmed);

        return "
            CASE
                WHEN " . self::sig_normalized_sql_where($norm) . " THEN 'SIG SAUER'
                ELSE {$norm}
            END
        ";
    }

    private static function is_sig_normalized(string $normalized): bool
    {
        return $normalized === 'SIG'
            || $normalized === 'SIGSAUER'
            || $normalized === 'SIGARMS'
            || strpos($normalized, 'SIGSAUER') === 0
            || strpos($normalized, 'SIGARMS') === 0;
    }

    private static function sig_normalized_sql_where(string $expr): string
    {
        return "{$expr} = 'SIG'"
            . " OR {$expr} = 'SIGSAUER'"
            . " OR {$expr} = 'SIGARMS'"
            . " OR {$expr} LIKE 'SIGSAUER%'"
            . " OR {$expr} LIKE 'SIGARMS%'";
    }
}
