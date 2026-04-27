<?php

namespace FFLHub\Distributor\Services\Orders\Util;

use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds normalized DistributorShipTo values from configured internal ship-to addresses.
 */
final class DealerShipToResolver
{
    public static function resolve(): ?DistributorShipTo
    {
        return self::resolve_from_address(Options::get_dealer_ship_to_address());
    }

    public static function resolve_relay(): ?DistributorShipTo
    {
        return self::resolve_from_address(Options::get_relay_ship_to_address());
    }

    /**
     * @param array<string,string> $addr
     */
    private static function resolve_from_address(array $addr): ?DistributorShipTo
    {
        $name = trim((string) ($addr['name'] ?? ''));
        $company = trim((string) ($addr['company'] ?? ''));
        $address1 = trim((string) ($addr['address1'] ?? ''));
        $address2 = trim((string) ($addr['address2'] ?? ''));
        $city = trim((string) ($addr['city'] ?? ''));
        $state = strtoupper(trim((string) ($addr['state'] ?? '')));
        $zip = trim((string) ($addr['zip'] ?? ''));
        $phone = trim((string) ($addr['phone'] ?? ''));
        $email = trim((string) ($addr['email'] ?? ''));

        if ($name === '' && $company !== '') {
            $name = $company;
        }
        if ($company === '' && $name !== '') {
            $company = $name;
        }

        if ($name === '' || $address1 === '' || $city === '' || !preg_match('/^[A-Z]{2}$/', $state) || $zip === '') {
            return null;
        }

        return new DistributorShipTo($name, $company, $address1, $address2, $city, $state, $zip, $phone, $email);
    }
}
