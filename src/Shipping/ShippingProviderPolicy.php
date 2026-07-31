<?php
declare(strict_types=1);

namespace FFLHub\Shipping;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared provider policy that should apply no matter which label screen is
 * shopping rates.
 */
final class ShippingProviderPolicy
{
    /**
     * EasyPost may return ordinary UPS/FedEx rates for FFL packages. Those are
     * not firearm-program labels, so FFL Hub hides them and leaves firearm UPS
     * to ShipOutdoors.
     *
     * @param array<string,mixed> $rate
     */
    public static function rate_is_blocked_for_ffl_package(array $rate): bool
    {
        $provider = sanitize_key((string) ($rate['provider_id'] ?? ''));
        if ($provider !== 'easypost') {
            return false;
        }

        return in_array(self::carrier_family($rate), ['ups', 'fedex'], true);
    }

    /**
     * @param array<int,array<string,mixed>> $package_items
     */
    public static function package_items_require_ffl(array $package_items): bool
    {
        foreach ($package_items as $row) {
            if (is_array($row) && self::truthy($row['ffl_required'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $order_items
     */
    public static function order_items_require_ffl(array $order_items): bool
    {
        return self::package_items_require_ffl($order_items);
    }

    /**
     * @param array<int,array<int,array<string,mixed>>> $package_item_assignments
     * @param array<int,array<string,mixed>>            $order_items
     */
    public static function package_assignments_require_ffl(array $package_item_assignments, array $order_items): bool
    {
        foreach ($package_item_assignments as $package_items) {
            if (self::package_assignment_requires_ffl(is_array($package_items) ? $package_items : [], $order_items)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Package assignment rows may either include ffl_required directly or point
     * back to order item rows that include ffl_required. No product names,
     * categories, shipment metadata, or FFL address metadata are considered.
     *
     * @param array<int,array<string,mixed>> $package_items
     * @param array<int,array<string,mixed>> $order_items
     */
    public static function package_assignment_requires_ffl(array $package_items, array $order_items): bool
    {
        $ffl_item_ids = self::ffl_item_id_map($order_items);

        foreach ($package_items as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (self::truthy($row['ffl_required'] ?? null)) {
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

    /**
     * @param array<string,mixed> $rate
     * @return array<string,mixed>
     */
    public static function blocked_ffl_rate_summary(array $rate): array
    {
        return [
            'carrier_id' => (string) ($rate['carrier_id'] ?? ''),
            'carrier_code' => (string) ($rate['carrier_code'] ?? ''),
            'carrier_nickname' => (string) ($rate['carrier_nickname'] ?? ''),
            'service_code' => (string) ($rate['service_code'] ?? ''),
            'service_type' => (string) ($rate['service_type'] ?? ''),
            'provider_id' => (string) ($rate['provider_id'] ?? 'easypost'),
            'provider_label' => (string) ($rate['provider_label'] ?? 'EasyPost'),
            'error_messages' => ['FFL-required packages cannot use ordinary EasyPost UPS/FedEx rates. Use ShipOutdoors for firearm UPS labels.'],
        ];
    }

    /**
     * @param array<string,mixed> $rate
     */
    private static function carrier_family(array $rate): string
    {
        $text = strtolower(implode(' ', [
            (string) ($rate['carrier_code'] ?? ''),
            (string) ($rate['carrier_nickname'] ?? ''),
            (string) ($rate['carrier_friendly_name'] ?? ''),
            (string) ($rate['service_code'] ?? ''),
            (string) ($rate['service_type'] ?? ''),
        ]));

        if (strpos($text, 'fedex') !== false || strpos($text, 'federal express') !== false) {
            return 'fedex';
        }

        if (strpos($text, 'ups') !== false || strpos($text, 'united parcel') !== false) {
            return 'ups';
        }

        return '';
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
            if ($item_id > 0 && self::truthy($item['ffl_required'] ?? null)) {
                $out[$item_id] = true;
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private static function truthy($value): bool
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
