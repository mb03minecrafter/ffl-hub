<?php

namespace FFLHub\Distributor;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Product\DistributorProductPayload;
use FFLHub\Distributor\Product\DistributorOffer;
use FFLHub\Distributor\Services\DistributorServicesInterface;
use FFLHub\Distributor\Services\Tables\DistributorTableInterface;

/**
 * Base class for distributors with common functionality.
 */
abstract class DistributorBase implements DistributorInterface
{

    protected DistributorModuleInterface $module;

    /**
     * Optional services bundle for this distributor (tables, cron, etc.).
     */
    protected ?DistributorServicesInterface $services = null;

    public function __construct(DistributorModuleInterface $module, ?DistributorServicesInterface $services = null)
    {
        $this->module = $module;

        if ($services !== null) {
            $this->services = $services;
        }
    }

    /**
     * Access the module definition for this distributor.
     */
    public function get_module(): DistributorModuleInterface
    {
        return $this->module;
    }

    /* ---- Metadata + schema delegate to module ---- */

    public function get_id(): string
    {
        return $this->module->id();
    }

    public function get_label(): string
    {
        return $this->module->label();
    }

    public function get_name(): string
    {
        return $this->module->name();
    }

    public function get_description(): string
    {
        return $this->module->description();
    }

    public function get_section_description(): string
    {
        return $this->module->section_description();
    }

    public function get_icon_url(): string
    {
        return $this->module->icon_url();
    }

    public function get_field_definitions(): array
    {
        $schema = $this->module->settings_schema();
        return is_array($schema) ? $schema : [];
    }

    /* ---- Services ---- */

    public function set_services(DistributorServicesInterface $services): void
    {
        $this->services = $services;
    }

    public function get_services(): ?DistributorServicesInterface
    {
        return $this->services;
    }

    public function has_services(): bool
    {
        return $this->services instanceof DistributorServicesInterface;
    }

    protected function get_fulfillment_table(): ?DistributorTableInterface
    {
        if (! $this->services instanceof DistributorServicesInterface) {
            return null;
        }

        return $this->services->get_fulfillment_table();
    }

    /* ---- Helpers for WordPress option naming ---- */

    protected function get_option_group(): string
    {
        return 'fflhub_' . $this->get_id() . '_settings_group';
    }

    protected function get_settings_page(): string
    {
        return 'ffl-hub-settings-' . $this->get_id();
    }

    protected function get_section_id(): string
    {
        return 'fflhub_' . $this->get_id() . '_section';
    }

    public function get_option_name(string $field_key): string
    {
        return 'fflhub_' . $this->get_id() . '_' . $field_key;
    }

    protected function get_field_id(string $field_key): string
    {
        return 'fflhub_' . $this->get_id() . '_' . $field_key . '_field';
    }


    /* ---- Default product / pricing implementations ---- */

    public function get_offer_by_upc(string $upc): ?DistributorOffer
    {
        $product = $this->get_product_by_upc($upc);


        return new DistributorOffer(
            $this->get_id(),
            $this->get_label(),
            $product
        );
    }


    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        // Default: not implemented.
        return null;
    }

    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
        return $this->get_product_by_upc($upc);
    }

    public function get_stock_quantity_by_upc(string $upc): ?int
    {
        $product = $this->get_pricing_payload_by_upc($upc);

        if (! $product instanceof DistributorProductPayload) {
            return null;
        }

        if ($product->quantity === null || $product->quantity === '') {
            return null;
        }

        return (int) $product->quantity;
    }

    public function get_distributor_price_by_upc(string $upc): ?float
    {
        $product = $this->get_pricing_payload_by_upc($upc);

        if (! $product instanceof DistributorProductPayload) {
            return null;
        }

        if ($product->price === null || $product->price === '') {
            return null;
        }

        return (float) $product->price;
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        return 0.0;
    }

    /**
     * Normalize a UPC by stripping non-digits.
     */
    protected function normalize_upc(string $upc): ?string
    {
        $normalized = preg_replace('/\D+/', '', $upc);

        if ($normalized === '') {
            error_log(
                'FFLHub Distributor: UPC empty after normalization. Original: ' . $upc
            );
            return null;
        }

        return $normalized;
    }

    /* ---- Field helpers ---- */

    protected function get_string_field(array $item, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                return (string) $item[$key];
            }
        }
        return null;
    }

    protected function get_int_field(array $item, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                return (int) $item[$key];
            }
        }
        return null;
    }

    protected function get_float_field(array $item, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                return (float) $item[$key];
            }
        }
        return null;
    }

    protected function get_bool_field(array $item, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                return (bool) $item[$key];
            }
        }
        return null;
    }


    


    protected function build_payload_from_row(
        array $row,
        array $map,
        callable $category_mapper,
        string $normalized_upc,
        bool $include_images = true
    ): DistributorProductPayload {
        $sku         = $this->get_string_field($row, $map['sku']);
        $upc         = $this->get_string_field($row, $map['upc']);
        $name        = $this->get_string_field($row, $map['name']);
        $description = $this->get_string_field($row, $map['description']);

        $price    = $this->get_float_field($row, $map['price']);
        $mapPrice = $this->get_float_field($row, $map['map']);
        $msrp     = $this->get_float_field($row, $map['msrp']);
        $quantity = $this->get_int_field($row, $map['quantity']);

        $shipping  = $this->get_shipping_cost_by_upc($normalized_upc);
        $true_cost = $this->get_true_cost_by_distributor_cost_shipping_cost($price, $shipping);

        $category = $category_mapper(
            $this->get_string_field($row, $map['category'])
        );

        $image = $include_images && isset($map['image'])
            ? $this->get_image_url_from_row($row, $map['image'])
            : '';

        $ffl_required = $this->get_int_field($row, $map['ffl_required']); // default; distributor can override later

        return new DistributorProductPayload(
            $upc ?: $normalized_upc,
            $sku,
            $name,
            $description,
            $price,
            $mapPrice,
            $msrp,
            $quantity,
            $shipping,
            $true_cost,
            $image,
            $ffl_required,
            $category,
            $row
        );
    }


    /**
     * Resolve an image URL from a fulfillment row.
     *
     * Default behavior: no images.
     * Distributors may override.
     *
     * @param array $row
     * @param array|string $field
     */
    protected function get_image_url_from_row(array $row, $field): string
    {
        return '';
    }

    /* ---- True cost helpers ---- */

    protected function get_true_cost_by_upc(string $upc): ?float
    {
        $distributor_cost = $this->get_distributor_price_by_upc($upc);
        if ($distributor_cost === null) {
            return null;
        }

        $shipping_cost = $this->get_shipping_cost_by_upc($upc);
        if ($shipping_cost === null) {
            $shipping_cost = 0.0;
        }

        $base_cost = $distributor_cost + $shipping_cost;

        $fee_percent = (float) get_option('fflhub_payment_processor_fee_percent', '2.9');
        $fee_decimal = $fee_percent / 100.0;

        if ($fee_decimal >= 1.0) {
            return $base_cost;
        }

        return $base_cost / (1.0 - $fee_decimal);
    }

    protected function get_true_cost_by_distributor_cost_shipping_cost(
        float $distributor_cost,
        float $shipping_cost
    ): ?float {
        $base_cost = $distributor_cost + $shipping_cost;

        $fee_percent = (float) get_option('fflhub_payment_processor_fee_percent', '2.9');
        $fee_decimal = $fee_percent / 100.0;

        if ($fee_decimal >= 1.0) {
            return $base_cost;
        }

        return $base_cost / (1.0 - $fee_decimal);
    }
}
