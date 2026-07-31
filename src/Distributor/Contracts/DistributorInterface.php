<?php

namespace FFLHub\Distributor\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Services\DistributorServicesInterface;

/**
 * Core contract for all distributor implementations.
 *
 * This interface defines the minimum surface area required for:
 * - Admin UI (metadata + settings schema)
 * - Product lookup / pricing
 * - Ordering and shipment tracking
 *
 * Notes:
 * - Most concrete distributors extend DistributorBase, which provides
 *   default implementations and helpers for many of these methods.
 * - This interface intentionally stays relatively small and stable.
 */
interface DistributorInterface
{
    /* ---------------------------------------------------------------------
     * Metadata / identity
     * ------------------------------------------------------------------ */

    /** Machine-friendly ID/slug (e.g. "rsr"). */
    public function get_id(): string;

    /** Short label shown in UI cards (e.g. "RSR"). */
    public function get_label(): string;

    /** Full human-readable name (e.g. "RSR Group"). */
    public function get_name(): string;

    /** Short description shown on distributor cards. */
    public function get_description(): string;

    /** Section description used in the settings UI. */
    public function get_section_description(): string;

    /** URL to an icon image representing this distributor. */
    public function get_icon_url(): string;

    /* ---------------------------------------------------------------------
     * Settings schema
     * ------------------------------------------------------------------ */

    /**
     * Static field definitions for this distributor's settings.
     *
     * Expected shape:
     * [
     *   'field_key' => [
     *     'label'       => 'Field Label',
     *     'type'        => 'text|password|checkbox|select|number',
     *     'placeholder' => '...',
     *     'description' => 'Help text',
     *     'default'     => '',
     *   ],
     * ]
     *
     * These definitions are consumed by SettingsRegistrar and admin UI.
     *
     * @return array<string,mixed>
     */
    public function get_field_definitions(): array;

    /* ---------------------------------------------------------------------
     * Services bundle
     * ------------------------------------------------------------------ */

    /**
     * Instance-level access to this distributor's services bundle.
     *
     * Services may include:
     * - Fulfillment tables
     * - Cron services
     * - Importers / background jobs
     *
     * Returns null if the distributor has no services bundle.
     */
    public function get_services(): ?DistributorServicesInterface;

    /* ---------------------------------------------------------------------
     * Product / pricing API
     * ------------------------------------------------------------------ */

    /**
     * Fetch a full product payload by UPC.
     *
     * Implementations may call remote APIs or local tables.
     */
    public function get_product_by_upc(string $upc): ?DistributorProductPayload;

    /**
     * Fetch a pricing-only payload by UPC.
     *
     * This may be cheaper than a full product lookup.
     */
    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload;

    /**
     * Fetch pricing-only payloads for many UPCs.
     *
     * @param array<int,string> $upcs
     * @return array<string,DistributorProductPayload> Payloads keyed by normalized UPC.
     */
    public function get_pricing_payloads_by_upcs(array $upcs): array;

    /**
     * Return available stock quantity for a UPC, if known.
     */
    public function get_stock_quantity_by_upc(string $upc): ?int;

    /**
     * Return distributor base price for a UPC, if known.
     */
    public function get_distributor_price_by_upc(string $upc): ?float;

    /**
     * Return estimated shipping cost for a UPC, if supported.
     */
    public function get_shipping_cost_by_upc(string $upc): ?float;

    /* ---------------------------------------------------------------------
     * Ordering / fulfillment
     * ------------------------------------------------------------------ */

    /**
     * Place an order with the distributor.
     *
     * Implementations should return a structured DistributorOrderResult
     * indicating success, retryable failure, or fatal failure.
     */
    public function place_order(DistributorOrderRequest $request): DistributorOrderResult;

    /**
     * Fetch shipment info for a purchase order number.
     *
     * Returns null if the shipment is not yet known or not supported.
     */
    public function get_shipment_by_po(string $po_number): ?DistributorShipment;

    /**
     * Whether this distributor can poll shipment info for a specific order lane.
     *
     * Most distributors use one account for every lane. Distributors with lane
     * limitations can opt out without the polling cron hardcoding distributor ids.
     */
    public function supports_shipment_polling_for_lane(string $lane): bool;

    /**
     * Fetch shipment info when the persisted order lane is already known.
     *
     * Most distributors ignore the lane and delegate to get_shipment_by_po().
     */
    public function get_shipment_by_po_for_lane(string $po_number, string $lane): ?DistributorShipment;
}
