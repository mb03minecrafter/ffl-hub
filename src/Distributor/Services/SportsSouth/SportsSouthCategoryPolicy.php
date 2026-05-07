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

        $row = self::apply_attribute_labels($row, $category);
        $row['ffl_required'] = self::category_requires_ffl($category_description, $department_name) ? '1' : '0';
        $row['sot_required'] = self::category_requires_sot($category_description, $department_name) ? '1' : '0';

        return $row;
    }

    /**
     * CategoryUpdate supplies the labels and DailyItemUpdate supplies the
     * values. Slot 10 is vendor-weird: ATTR0 pairs with ITATR0.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $category
     * @return array<string,mixed>
     */
    private static function apply_attribute_labels(array $row, array $category): array
    {
        $raw_product_attributes = self::decode_json_object((string) ($row['attributes_json'] ?? ''));
        $raw_category_attributes = isset($category['attributes']) && is_array($category['attributes'])
            ? $category['attributes']
            : [];

        if (empty($raw_product_attributes) || empty($raw_category_attributes)) {
            return $row;
        }

        $product_attributes = self::normalize_keyed_strings($raw_product_attributes);
        $category_attributes = self::normalize_keyed_strings($raw_category_attributes);
        $labeled = [];

        for ($slot = 1; $slot <= 20; $slot++) {
            $label = self::category_attribute_label($category_attributes, $slot);
            if ($label === '') {
                continue;
            }

            $value = self::product_attribute_value($product_attributes, $slot);
            if ($value === '') {
                continue;
            }

            $labeled[self::unique_attribute_label($label, $slot, $labeled)] = $value;
        }

        if (!empty($labeled)) {
            $row['attributes_json'] = self::encode_json($labeled);
        }

        return $row;
    }

    /**
     * @return array<string,string>
     */
    private static function decode_json_object(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<mixed,mixed> $values
     * @return array<string,string>
     */
    private static function normalize_keyed_strings(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            $normalized[strtoupper(trim((string) $key))] = trim((string) $value);
        }

        return $normalized;
    }

    /**
     * @param array<string,string> $categoryAttributes
     */
    private static function category_attribute_label(array $categoryAttributes, int $slot): string
    {
        $keys = ($slot === 10)
            ? ['ATTR0', 'ATTR10']
            : ['ATTR' . $slot];

        return self::first_non_empty($categoryAttributes, $keys);
    }

    /**
     * @param array<string,string> $productAttributes
     */
    private static function product_attribute_value(array $productAttributes, int $slot): string
    {
        $keys = ($slot === 10)
            ? ['ITATR0', 'ATR0', 'ITATR10', 'ATR10']
            : ['ITATR' . $slot, 'ATR' . $slot];

        return self::first_non_empty($productAttributes, $keys);
    }

    /**
     * @param array<string,string> $values
     * @param string[] $keys
     */
    private static function first_non_empty(array $values, array $keys): string
    {
        foreach ($keys as $key) {
            $key = strtoupper($key);
            $value = trim((string) ($values[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string,string> $existing
     */
    private static function unique_attribute_label(string $label, int $slot, array $existing): string
    {
        $label = trim($label);
        if ($label === '') {
            $label = 'Attribute ' . $slot;
        }

        if (!array_key_exists($label, $existing)) {
            return $label;
        }

        return $label . ' (Attribute ' . $slot . ')';
    }

    /**
     * @param mixed $value
     */
    private static function encode_json($value): string
    {
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '';
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
