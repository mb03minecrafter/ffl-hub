<?php

namespace FFLHub\Distributor\Services\CSSI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CSSI category rules for regulatory flags that CSSI does not expose reliably.
 */
final class CSSIRegulatoryCategoryRules
{
    private const FFL_REQUIRED_CATEGORIES = [
        'Handguns',
        'Handguns|CA Compliant',
        'Handguns|CA Compliant|CSSI Exclusive Shop',
        'Handguns|CA Compliant|Firearms',
        'Handguns|CA Compliant|MA Compliant',
        'Handguns|CSSI Exclusive Shop',
        'Handguns|CSSI Exclusive Shop|Firearms',
        'Handguns|Firearms',
        'Handguns|MA Compliant',
        'Handguns|Used Guns',
        'Handguns|Used Guns|CA Compliant',
        'Handguns|Used Guns|CSSI Exclusive Shop',
        'Rifles',
        'Rifles|CA Compliant',
        'Rifles|Chassis & Stocks',
        'Rifles|CSSI Exclusive Shop',
        'Rifles|Firearms',
        'Rifles|Uppers',
        'Rifles|Used Guns',
        'Rifles|Used Guns|CA Compliant',
        'Shotguns',
        'Shotguns|CA Compliant',
        'Shotguns|Firearms',
        'Shotguns|Used Guns',
        'Used Guns',
    ];

    private const SOT_REQUIRED_CATEGORIES = [
        'Short Barreled Rifles',
        'Short Barreled Rifles|Firearms',
        'Short Barreled Rifles|Rifles',
        'Suppressors',
        'Suppressors and Parts',
        'Suppressors and Parts|Suppressors',
        'Suppressors|Handguns',
        'Suppressors|Suppressors',
        'Suppressors|Used Guns',
    ];

    public static function is_ffl_required_category(string $category): bool
    {
        $category = self::normalize_category($category);

        return in_array($category, self::FFL_REQUIRED_CATEGORIES, true)
            || in_array($category, self::SOT_REQUIRED_CATEGORIES, true);
    }

    public static function is_sot_required_category(string $category): bool
    {
        return in_array(self::normalize_category($category), self::SOT_REQUIRED_CATEGORIES, true);
    }

    public static function ffl_required_category_sql(string $categoryExpression): string
    {
        return '(' . self::normalized_category_sql($categoryExpression) . ' IN (' . self::quoted_category_list(self::FFL_REQUIRED_CATEGORIES) . ')
            OR ' . self::sot_required_category_sql($categoryExpression) . ')';
    }

    public static function sot_required_category_sql(string $categoryExpression): string
    {
        return '(' . self::normalized_category_sql($categoryExpression) . ' IN (' . self::quoted_category_list(self::SOT_REQUIRED_CATEGORIES) . '))';
    }

    private static function normalize_category(string $category): string
    {
        $category = html_entity_decode($category, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $category = trim($category);
        $category = (string) preg_replace('/\s+/', ' ', $category);

        return $category;
    }

    private static function normalized_category_sql(string $categoryExpression): string
    {
        return "TRIM(REPLACE({$categoryExpression}, '&amp;', '&'))";
    }

    /**
     * @param array<int,string> $categories
     */
    private static function quoted_category_list(array $categories): string
    {
        return implode(', ', array_map(static function (string $category): string {
            return "'" . str_replace("'", "''", $category) . "'";
        }, $categories));
    }
}
