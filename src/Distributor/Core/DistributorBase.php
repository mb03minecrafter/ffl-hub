<?php
// File: src/Distributor/Core/DistributorBase.php

namespace FFLHub\Distributor\Core;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorInterface;
use FFLHub\Distributor\Contracts\DistributorModuleInterface;

use FFLHub\Distributor\Services\DistributorServicesInterface;
use FFLHub\Distributor\Services\Tables\DistributorTableInterface;

use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorShipment;

/**
 * Base class for distributors with common functionality.
 *
 * Responsibilities:
 * - Provide module metadata
 * - Provide services/table helpers
 * - Provide shared normalization and row-to-payload helpers
 * - Provide shared compliance helpers (can_ship_to_state_by_upc)
 * - Provide shared line->Items[] mapping helper for ordering APIs
 */
abstract class DistributorBase implements DistributorInterface
{
    protected DistributorModuleInterface $module;

    /**
     * Optional services bundle for this distributor (tables, cron, etc.)
     */
    protected ?DistributorServicesInterface $services = null;

    public function __construct(DistributorModuleInterface $module, ?DistributorServicesInterface $services = null)
    {
        $this->module = $module;
        $this->services = $services;
    }

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
        return $this->services ? $this->services->get_fulfillment_table() : null;
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

    /* ---- Product / pricing (default implementations) ---- */

    public function get_offer_by_upc(string $upc, bool $include_images = true): ?DistributorOffer
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        // Fast-path: no image probing for cron/sync workloads.
        $product = null;

        if (! $include_images && method_exists($this, 'get_pricing_payload_by_upc')) {
            $product = $this->get_pricing_payload_by_upc($normalized);
        } else {
            $product = $this->get_product_by_upc($normalized);
        }

        if (! $product instanceof DistributorProductPayload) {
            return null;
        }

        return new DistributorOffer(
            $this->get_id(),
            $this->get_label(),
            $product
        );
    }

    /**
     * Distributors override this.
     *
     * NOTE: In DistributorBase we normalize incoming UPCs before calling this method.
     * Implementations may assume $upc is digits-only.
     */
    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        return null; // distributors override
    }

    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        return $this->get_product_by_upc($normalized);
    }

    public function get_stock_quantity_by_upc(string $upc): ?int
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $product = $this->get_pricing_payload_by_upc($normalized);
        if (! $product instanceof DistributorProductPayload) {
            return null;
        }

        $q = $product->quantity;
        if ($q === null || $q === '') {
            return null;
        }

        return (int) $q;
    }

    public function get_distributor_price_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $product = $this->get_pricing_payload_by_upc($normalized);
        if (! $product instanceof DistributorProductPayload) {
            return null;
        }

        $p = $product->price;
        if ($p === null || $p === '') {
            return null;
        }

        return (float) $p;
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        return 0.0;
    }

    /**
     * Normalize a UPC by stripping non-digits.
     * Returns null if empty after normalization.
     */
    protected function normalize_upc(string $upc): ?string
    {
        $normalized = preg_replace('/\D+/', '', (string) $upc);
        $normalized = is_string($normalized) ? $normalized : '';
        $normalized = trim($normalized);

        return $normalized !== '' ? $normalized : null;
    }

    /* ---- Field helpers ---- */

    /**
     * @param array<string,mixed> $item
     * @param array<int,string> $keys
     */
    protected function get_string_field(array $item, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== '' && $item[$key] !== null) {
                return (string) $item[$key];
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<int,string> $keys
     */
    protected function get_int_field(array $item, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== '' && $item[$key] !== null) {
                return (int) $item[$key];
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<int,string> $keys
     */
    protected function get_float_field(array $item, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== '' && $item[$key] !== null) {
                return (float) $item[$key];
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<int,string> $keys
     */
    protected function get_bool_field(array $item, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== '' && $item[$key] !== null) {
                return (bool) $item[$key];
            }
        }
        return null;
    }

    /**
     * Trim a scalar-ish value into a safe string for distributor payloads.
     */
    protected static function normalize_payload_string($value): string
    {
        return trim((string) $value);
    }

    /**
     * Normalize US state code for distributor payloads.
     * Returns uppercase trimmed input; if it is a valid 2-letter code, it stays that.
     */
    protected static function normalize_us_state_code_for_payload($state): string
    {
        $s = strtoupper(trim((string) $state));
        return $s;
    }

    /**
     * Extract only digits from a value (ZIP, phone, etc.).
     */
    protected static function extract_digits($value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        return is_string($digits) ? $digits : '';
    }

    /**
     * Format ZIP as 5 digits (ZIP5). Returns '' if no digits.
     */
    protected static function format_us_zip5_for_payload($zip): string
    {
        $digits = self::extract_digits($zip);
        if ($digits === '') {
            return '';
        }
        return substr($digits, 0, 5);
    }

    /**
     * Format ZIP as ZIP5 or ZIP+4 with dash when 9 digits are present.
     */
    protected static function format_us_zip5_or_zip9_with_dash_for_payload($zip): string
    {
        $zip = trim((string) $zip);
        if ($zip === '') {
            return '';
        }

        if (preg_match('/^\d{5}-\d{4}$/', $zip)) {
            return $zip;
        }

        $digits = self::extract_digits($zip);
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) >= 9) {
            return substr($digits, 0, 5) . '-' . substr($digits, 5, 4);
        }

        return substr($digits, 0, 5);
    }

    /**
     * Build a normalized payload from a fulfillment row + field map.
     *
     * @param array<string,mixed> $row
     * @param array<string,array<int,string>> $map
     * @param callable $category_mapper fn(?string $raw_category): ?array  (recommended category path)
     */
    protected function build_payload_from_row(
        array $row,
        array $map,
        callable $category_mapper,
        string $normalized_upc,
        bool $include_images = true
    ): DistributorProductPayload {
        $sku             = $this->get_string_field($row, $map['sku'] ?? []) ?? '';
        $upc_raw         = $this->get_string_field($row, $map['upc'] ?? []) ?? $normalized_upc;
        $upc             = $this->normalize_upc($upc_raw) ?? $normalized_upc;

        $raw_name        = $this->get_string_field($row, $map['name'] ?? []) ?? '';
        $raw_description = $this->get_string_field($row, $map['description'] ?? []) ?? '';

        $raw_name        = trim(preg_replace('/\s+/', ' ', $raw_name));
        $raw_description = trim(preg_replace('/\s+/', ' ', $raw_description));

        if ($raw_name !== '' && $raw_description !== '') {
            if (stripos($raw_name, $raw_description) !== false) {
                $name = $raw_name;
            } else {
                $name = $raw_name . ' – ' . $raw_description;
            }
        } else {
            $name = $raw_name !== '' ? $raw_name : $raw_description;
        }

        $description = $raw_description;

        $price    = (float) ($this->get_float_field($row, $map['price'] ?? []) ?? 0.0);
        $mapPrice = (float) ($this->get_float_field($row, $map['map'] ?? []) ?? 0.0);
        $msrp     = (float) ($this->get_float_field($row, $map['msrp'] ?? []) ?? 0.0);
        $quantity = (int)   ($this->get_int_field($row, $map['quantity'] ?? []) ?? 0);

        $shipping = (float) ($this->get_shipping_cost_by_upc($normalized_upc) ?? 0.0);

        $true_cost = $this->get_true_cost_by_distributor_cost_shipping_cost($price, $shipping);
        if ($true_cost === null) {
            $true_cost = $price + $shipping;
        }

        $category_raw = $this->get_string_field($row, $map['category'] ?? []);
        $recommended_category = $category_mapper($category_raw);
        if (! is_array($recommended_category)) {
            $recommended_category = null;
        }

        $image = '';
        if ($include_images && isset($map['image'])) {
            $image = $this->get_image_url_from_row($row, $map['image']);
            $image = is_string($image) ? trim($image) : '';
        }

        $ffl_required = false;
        if (isset($map['ffl_required'])) {
            $ffl_required = (bool) ((int) ($this->get_int_field($row, $map['ffl_required']) ?? 0));
        }

        return new DistributorProductPayload(
            $upc,
            $sku,
            $name,
            $description,
            $price,
            $mapPrice,
            $msrp,
            $quantity,
            $shipping,
            (float) $true_cost,
            $image,
            $ffl_required,
            $recommended_category,
            $row
        );
    }

    protected function get_image_url_from_row(array $row, $field): string
    {
        return '';
    }

    /* ---- True cost helpers ---- */

    protected function get_true_cost_by_distributor_cost_shipping_cost(float $distributor_cost, float $shipping_cost): ?float
    {
        return $distributor_cost;
    }

    /* ---- Ordering helpers ---- */

    /**
     * Map order lines into a distributor-specific Items[] payload.
     *
     * If mapping fails, returns a coded DistributorOrderResult using the NEW shape:
     *  - code: OK / BLOCK_RETRYABLE / BLOCK_FATAL
     *  - codes[]: secondary reason(s)
     *
     * @param array<int,mixed> $lines
     * @param callable $map   fn(string $normalized_upc, string $raw_upc, DistributorOrderLine $line): ?string
     * @param callable $build fn(string $mapped_key, int $qty, string $normalized_upc, string $raw_upc, DistributorOrderLine $line): array
     * @return array<int,array<string,mixed>>|DistributorOrderResult
     */
    protected function map_order_lines_to_items(
        array $lines,
        callable $map,
        callable $build,
        string $map_error_fmt,
        bool $require_non_empty = true,
        string $empty_error_msg = 'No valid line items after normalization.'
    ) {
        $items = [];
        $seen_any_line = false;

        foreach ($lines as $l) {
            if (!($l instanceof DistributorOrderLine)) {
                continue;
            }

            $seen_any_line = true;

            $raw_upc = trim((string) $l->upc);
            if ($raw_upc === '') {
                continue;
            }

            $normalized_upc = $this->normalize_upc($raw_upc);
            if ($normalized_upc === null) {
                return DistributorOrderResult::block_fatal(
                    'Invalid UPC (failed normalization).',
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    [
                        'raw_upc_tail4' => (strlen($raw_upc) >= 4 ? substr($raw_upc, -4) : $raw_upc),
                    ]
                );
            }

            $qty = max(1, (int) $l->quantity);

            $mapped_key = $map($normalized_upc, $raw_upc, $l);
            $mapped_key = is_string($mapped_key) ? trim($mapped_key) : '';

            if ($mapped_key === '') {
                return DistributorOrderResult::block_fatal(
                    sprintf($map_error_fmt, $raw_upc),
                    [DistributorOrderResult::REASON_FATAL_MAPPING],
                    [
                        'upc_tail4' => (strlen($normalized_upc) >= 4 ? substr($normalized_upc, -4) : $normalized_upc),
                    ]
                );
            }

            $item = $build($mapped_key, $qty, $normalized_upc, $raw_upc, $l);
            if (is_array($item) && !empty($item)) {
                $items[] = $item;
            }
        }

        if ($require_non_empty && empty($items)) {
            return DistributorOrderResult::block_fatal(
                $empty_error_msg,
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [
                    'seen_any_line' => $seen_any_line ? 1 : 0,
                    'lines_count'   => count($lines),
                ]
            );
        }

        return $items;
    }


    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        return DistributorOrderResult::block_fatal(
            'Ordering is not implemented for this distributor.',
            [DistributorOrderResult::REASON_FATAL_NOT_IMPLEMENTED]
        );
    }



    /**
     * Default behavior: allow (some distributors won’t support a preflight API).
     */
    public function validate_order_request(DistributorOrderRequest $request): DistributorOrderValidationResult
    {
        return DistributorOrderValidationResult::allow('No distributor-specific validation implemented.');
    }

    /**
     * Return subset of lines this distributor can validate.
     *
     * @param DistributorOrderLine[] $lines
     * @return DistributorOrderLine[]
     */
    public function filter_lines_for_validation(array $lines): array
    {
        $table = $this->get_fulfillment_table();
        if (! $table) {
            return [];
        }

        $out = [];
        foreach ($lines as $l) {
            if (!($l instanceof DistributorOrderLine)) {
                continue;
            }

            $normalized = $this->normalize_upc((string) $l->upc);
            if ($normalized === null) {
                continue;
            }

            $row = $table->get_row_by_upc($normalized);
            if ($row) {
                $out[] = $l;
            }
        }

        return $out;
    }



    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        return null;
    }
}
