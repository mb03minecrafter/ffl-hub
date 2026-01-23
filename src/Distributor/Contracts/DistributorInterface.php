<?php

namespace FFLHub\Distributor\Contracts;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\DistributorServicesInterface;

use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;

interface DistributorInterface
{
    /** Machine-friendly ID/slug, e.g. "rsr". */
    public function get_id(): string;

    /** Short label shown on the card, e.g. "RSR". */
    public function get_label(): string;

    /** Full name, e.g. "RSR Group". */
    public function get_name(): string;

    /** Short description shown on the card. */
    public function get_description(): string;

    /** Section description used in settings UI. */
    public function get_section_description(): string;

    /** URL to an icon image for this distributor. */
    public function get_icon_url(): string;

    /**
     * Field definitions (static schema).
     *
     * [
     *   'field_key' => [
     *     'label' => 'Field Label',
     *     'type'  => 'text|password|checkbox',
     *     'placeholder' => '...',
     *     'description' => 'Help text',
     *     'default'     => '',
     *   ],
     * ]
     */
    public function get_field_definitions(): array;

    /**
     * Instance-level access to this distributor's services bundle
     * (fulfillment table + cron services, etc.).
     */
    public function get_services(): ?DistributorServicesInterface;

    // --- Product / pricing API ---

    public function get_product_by_upc(string $upc): ?DistributorProductPayload;

    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload;

    public function get_stock_quantity_by_upc(string $upc): ?int;

    public function get_distributor_price_by_upc(string $upc): ?float;

    public function get_shipping_cost_by_upc(string $upc): ?float;



    /**
     * Determine if this distributor can ship a UPC to a given US state.
     *
     * @param string $upc
     * @param string $state_code Two-letter state code (e.g. "LA", "TX")
     * @return bool|null
     *   true  = explicitly allowed
     *   false = explicitly blocked
     *   null  = distributor does not provide restriction data
     */
    public function can_ship_to_state_by_upc(string $upc, string $state_code): ?bool;



    public function place_order(DistributorOrderRequest $request): DistributorOrderResult;
}
