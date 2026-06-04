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

    public static function sql_expression(string $sql_expression): string
    {
        $expr = "UPPER(TRIM({$sql_expression}))";

        foreach (["' '", "'&'", "'/'", "'-'", "'.'", "','", "'('", "')'", 'CHAR(39)'] as $needle) {
            $expr = "REPLACE({$expr}, {$needle}, '')";
        }

        return $expr;
    }
}
