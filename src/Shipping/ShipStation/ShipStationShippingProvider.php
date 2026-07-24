<?php
declare(strict_types=1);

namespace FFLHub\Shipping\ShipStation;

use FFLHub\Shipping\Providers\ShippingProviderInterface;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ShipStation API v2 implementation of the provider contract.
 *
 * This adapter is intentionally thin: the existing client still owns HTTP,
 * authentication, retries for safe GETs, response decoding, and error shaping.
 * The adapter gives higher-level shipping services a provider-neutral seam.
 */
final class ShipStationShippingProvider implements ShippingProviderInterface
{
    private ShipStationClient $client;

    public function __construct(?ShipStationClient $client = null)
    {
        $this->client = $client ?? new ShipStationClient();
    }

    public function id(): string
    {
        return 'shipstation';
    }

    public function label(): string
    {
        return 'ShipStation API';
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>|WP_Error
     */
    public function validate_address(array $address)
    {
        return $this->client->validate_address($address);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function get_rates(array $payload)
    {
        return $this->client->get_rates($payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function purchase_label_from_rate(string $rate_id, array $payload)
    {
        return $this->client->purchase_label_from_rate($rate_id, $payload);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function void_label(string $label_id)
    {
        return $this->client->void_label($label_id);
    }

    /**
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    public function download_label(string $url)
    {
        return $this->client->download_label($url);
    }
}
