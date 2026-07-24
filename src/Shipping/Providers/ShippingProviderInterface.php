<?php
declare(strict_types=1);

namespace FFLHub\Shipping\Providers;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Provider boundary for buying labels through a shipping API.
 *
 * Order-facing services should build FFL Hub shipment payloads and call this
 * contract. Provider adapters own the final API call details, auth, and raw
 * response handling for ShipStation, EasyPost, or any future label provider.
 */
interface ShippingProviderInterface
{
    public function id(): string;

    public function label(): string;

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>|WP_Error
     */
    public function validate_address(array $address);

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function get_rates(array $payload);

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function purchase_label_from_rate(string $rate_id, array $payload);

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function void_label(string $label_id);

    /**
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    public function download_label(string $url);
}
