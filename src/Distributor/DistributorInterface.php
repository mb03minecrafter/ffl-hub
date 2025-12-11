<?php

namespace FFLHub\Distributor;

use FFLHub\Distributor\Product\DistributorProductPayload;

interface DistributorInterface
{
    /** Machine-friendly ID/slug, e.g. "rsr". */
    public static function get_id(): string;

    /** Short label shown on the card, e.g. "RSR". */
    public static function get_label(): string;

    /** Full name, e.g. "RSR Group". */
    public static function get_name(): string;

    /** Short description shown on the card. */
    public static function get_description(): string;

    /** Section description used in settings UI. */
    public static function get_section_description(): string;

    /** URL to an icon image for this distributor. */
    public static function get_icon_url(): string;




    /**
     * Field definitions (static schema).
     *
     * Same shape you use now:
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
    public static function get_field_definitions(): array;


    //services class so we can append services to our distributors... WIP
    public static function get_services_class(): string;


    /** Register settings, sections, and fields. */
    //public function register_settings(): void;

    /** Render the full settings panel (heading + form + fields). */
    //public function render_settings_panel(): void;

    // --- Product / pricing API ---

    public function get_product_by_upc(string $upc): ?DistributorProductPayload;
    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload;
    public function get_stock_quantity_by_upc(string $upc): ?int;
    public function get_distributor_price_by_upc(string $upc): ?float;
    public function get_shipping_cost_by_upc(string $upc): ?float;




}