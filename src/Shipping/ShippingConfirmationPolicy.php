<?php
declare(strict_types=1);

namespace FFLHub\Shipping;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central confirmation policy for outbound dealer-fulfilled shipping labels.
 */
final class ShippingConfirmationPolicy
{
    /**
     * @param array<int,array<int,array{item_id:int,quantity:int}>> $package_item_assignments
     * @param array<int,array<string,mixed>> $order_items
     */
    public static function confirmation_for_package_assignments(string $requested, array $package_item_assignments, array $order_items): string
    {
        return self::confirmation_for_ffl_requirement(
            $requested,
            self::any_package_requires_signature($package_item_assignments, $order_items)
        );
    }

    /**
     * @param array<int,array<int,array{item_id:int,quantity:int}>> $package_item_assignments
     * @param array<int,array<string,mixed>> $order_items
     */
    public static function any_package_requires_signature(array $package_item_assignments, array $order_items): bool
    {
        foreach ($package_item_assignments as $package_items) {
            if (self::package_requires_signature(is_array($package_items) ? $package_items : [], $order_items)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $package_items
     * @param array<int,array<string,mixed>> $order_items
     */
    public static function package_requires_signature(array $package_items, array $order_items): bool
    {
        $ffl_item_ids = self::ffl_item_id_map($order_items);

        foreach ($package_items as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (self::boolish($row['ffl_required'] ?? null)) {
                return true;
            }

            $item_id = absint($row['item_id'] ?? $row['order_item_id'] ?? 0);
            $quantity = max(0, (int) ($row['quantity'] ?? 0));
            if ($item_id > 0 && $quantity > 0 && !empty($ffl_item_ids[$item_id])) {
                return true;
            }
        }

        return false;
    }

    public static function confirmation_for_ffl_requirement(string $requested, bool $requires_signature): string
    {
        $confirmation = self::normalize_confirmation($requested);
        if (!$requires_signature || in_array($confirmation, ['signature', 'direct_signature', 'adult_signature'], true)) {
            return $confirmation;
        }

        return 'signature';
    }

    /**
     * @param array<int,array<string,mixed>> $order_items
     * @return array<int,bool>
     */
    private static function ffl_item_id_map(array $order_items): array
    {
        $out = [];
        foreach ($order_items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $item_id = absint($item['item_id'] ?? $item['order_item_id'] ?? 0);
            if ($item_id > 0 && self::boolish($item['ffl_required'] ?? null)) {
                $out[$item_id] = true;
            }
        }

        return $out;
    }

    private static function normalize_confirmation(string $confirmation): string
    {
        $confirmation = strtolower(trim($confirmation));

        return in_array($confirmation, ['none', 'delivery', 'signature', 'adult_signature', 'direct_signature'], true)
            ? $confirmation
            : 'delivery';
    }

    /**
     * @param mixed $value
     */
    private static function boolish($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
    }
}
