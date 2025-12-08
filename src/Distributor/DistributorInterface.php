<?php

namespace FFLHub\Distributor;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Product\DistributorProductPayload;

/**
 * Interface that every distributor must implement.
 */
interface DistributorInterface
{
    /** Machine-friendly ID, e.g. "rsr". */
    public function get_id(): string;

    /** Short label shown on the card, e.g. "RSR". */
    public function get_label(): string;

    /** Full name, e.g. "RSR Group". */
    public function get_name(): string;

    /** Short description shown on the card. */
    public function get_description(): string;

    /**
     * URL to an icon image for this distributor.
     * Can be empty string if you want text-only instead.
     */
    public function get_icon_url(): string;

    /** Register settings, sections, and fields. */
    public function register_settings(): void;

    /** Render the full settings panel (heading + form + fields). */
    public function render_settings_panel(): void;

    /**
     * Look up a single product by UPC code via this distributor's API.
     *
     * @param string $upc
     * @return DistributorProductPayload|null Normalized product data or null if not found.
     *
     * Expected keys for the normalized payload object:
     * - upc        (string)      The UPC that was searched.
     * - sku        (string|null) Distributor SKU, if available.
     * - name       (string|null) Product name/title.
     * - description(string|null) Short description.
     * - price      (float|null)  Distributor price/cost.
     * - quantity   (int|null)    Available quantity.
     * - raw        (mixed)       Raw response from the distributor API.
     */
    public function get_product_by_upc(string $upc): ?DistributorProductPayload;

    /**
     * Lightweight version of get_product_by_upc:
     * same normalized payload, but image URLs are not populated
     * (useful when image fetch is expensive).
     */
    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload;

    /**
     * Get current stock quantity for a product by UPC.
     *
     * @param string $upc
     * @return int|null Number of units on hand, or null if unknown / not found.
     */
    public function get_stock_quantity_by_upc(string $upc): ?int;

    /**
     * Get distributor/dealer price by UPC.
     *
     * @param string $upc
     * @return float|null
     */
    public function get_distributor_price_by_upc(string $upc): ?float;

    /**
     * Get shipping cost by UPC (if supported by the distributor).
     *
     * @param string $upc
     * @return float|null
     */
    public function get_shipping_cost_by_upc(string $upc): ?float;
}
