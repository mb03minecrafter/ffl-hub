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
        return ShippingProviderPolicy::package_assignments_require_ffl($package_item_assignments, $order_items);
    }

    /**
     * @param array<int,array<string,mixed>> $package_items
     * @param array<int,array<string,mixed>> $order_items
     */
    public static function package_requires_signature(array $package_items, array $order_items): bool
    {
        return ShippingProviderPolicy::package_assignment_requires_ffl($package_items, $order_items);
    }

    public static function confirmation_for_ffl_requirement(string $requested, bool $requires_signature): string
    {
        $confirmation = self::normalize_confirmation($requested);
        if (!$requires_signature || in_array($confirmation, ['signature', 'direct_signature', 'adult_signature'], true)) {
            return $confirmation;
        }

        return 'signature';
    }

    private static function normalize_confirmation(string $confirmation): string
    {
        $confirmation = strtolower(trim($confirmation));

        return in_array($confirmation, ['none', 'delivery', 'signature', 'adult_signature', 'direct_signature'], true)
            ? $confirmation
            : 'delivery';
    }
}
