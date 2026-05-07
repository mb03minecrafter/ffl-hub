<?php

namespace FFLHub\Distributor\Services\SportsSouth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sports South DailyItemUpdate has CATID but no explicit FFL flag.
 * CategoryUpdate supplies CATDES/DEP, which is the least-bad source for
 * determining whether a row needs dealer transfer.
 */
final class SportsSouthCategoryPolicy
{
    /**
     * @param array<string,mixed> $row
     * @param array<string,array<string,mixed>> $categoryMap
     * @return array<string,mixed>
     */
    public static function apply_to_row(array $row, array $categoryMap): array
    {
        $category_id = trim((string) ($row['category_id'] ?? ''));
        $category = $category_id !== '' && isset($categoryMap[$category_id])
            ? $categoryMap[$category_id]
            : [];

        $category_description = trim((string) ($category['category_description'] ?? ''));
        $department_name = trim((string) ($category['department_name'] ?? ''));

        if ($category_description !== '') {
            $row['item_type'] = $category_description;
        }

        $row['ffl_required'] = self::category_requires_ffl($category_description, $department_name) ? '1' : '0';
        $row['sot_required'] = self::category_requires_sot($category_description, $department_name) ? '1' : '0';

        return $row;
    }

    private static function category_requires_ffl(string $categoryDescription, string $departmentName): bool
    {
        $category = self::normalize($categoryDescription);
        $department = self::normalize($departmentName);

        if ($category === '') {
            return false;
        }

        // These can live under firearm-ish departments but are not dealer
        // transfer firearms.
        foreach (['BLACKPOWDER', 'AIRGUN', 'AIRGUNS', 'UPPER', 'UPPERS'] as $non_ffl) {
            if (strpos($category, $non_ffl) !== false) {
                return false;
            }
        }

        foreach (['LOWER', 'LOWERS', 'FRAME', 'FRAMES', 'RECEIVER', 'RECEIVERS'] as $ffl) {
            if (strpos($category, $ffl) !== false) {
                return true;
            }
        }

        foreach (['PISTOL', 'PISTOLS', 'REVOLVER', 'REVOLVERS', 'RIFLE', 'RIFLES', 'SHOTGUN', 'SHOTGUNS', 'COMBO', 'SPECIALTY'] as $ffl) {
            if (strpos($category, $ffl) !== false) {
                return true;
            }
        }

        return $department === 'FIREARMS';
    }

    private static function category_requires_sot(string $categoryDescription, string $departmentName): bool
    {
        $haystack = self::normalize($categoryDescription . ' ' . $departmentName);
        if ($haystack === '') {
            return false;
        }

        foreach (['NFA', 'SUPPRESSOR', 'SUPPRESSORS', 'SILENCER', 'SILENCERS', 'CLASS3', 'CLASSIII'] as $needle) {
            if (strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $value): string
    {
        $normalized = preg_replace('/[^A-Z0-9]+/', '', strtoupper(trim($value)));

        return is_string($normalized) ? $normalized : '';
    }
}
