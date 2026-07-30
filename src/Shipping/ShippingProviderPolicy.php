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
