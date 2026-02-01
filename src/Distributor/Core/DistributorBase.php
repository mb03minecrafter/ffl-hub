<?php
// File: src/Distributor/Core/DistributorBase.php

namespace FFLHub\Distributor\Core;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorInterface;
use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Services\DistributorServicesInterface;
use FFLHub\Distributor\Services\Tables\DistributorTableInterface;

/**
 * Base class for distributor implementations.
 *
 * This class provides:
 * - Module metadata delegation (id/label/name/description/settings schema).
 * - Optional service bundle access (tables, cron wiring, etc.).
 * - Common normalization helpers (UPC, ZIP, digits-only extraction, etc.).
 * - Helpers to build a DistributorProductPayload from a fulfillment row + map.
 * - Helpers to map order lines into a distributor-specific Items[] payload.
 *
 * Subclasses typically override:
 * - get_product_by_upc()
 * - get_image_url_from_row()
 * - get_shipping_cost_by_upc() (if applicable)
 * - get_true_cost_by_distributor_cost_shipping_cost() (if applicable)
 * - place_order()
 * - validate_order_request() / get_shipment_by_po() (if supported)
 */
abstract class DistributorBase implements DistributorInterface
{
    /**
     * Module definition for this distributor (metadata + settings schema + builder).
     */
    protected DistributorModuleInterface $module;

    /**
     * Optional services bundle for this distributor (tables, cron, importers, etc).
     *
     * Many distributor methods can run without services, but validation and
     * local-only checks often rely on fulfillment tables.
     */
    protected ?DistributorServicesInterface $services = null;

    public function __construct(DistributorModuleInterface $module, ?DistributorServicesInterface $services = null)
    {
        $this->module   = $module;
        $this->services = $services;
    }

    public function get_module(): DistributorModuleInterface
    {
        return $this->module;
    }

    /* ---------------------------------------------------------------------
     * Metadata + schema (delegated to module)
     * ------------------------------------------------------------------ */

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

    /**
     * Distributor settings schema, used by SettingsRegistrar.
     *
     * @return array<string,mixed>
     */
    public function get_field_definitions(): array
    {
        $schema = $this->module->settings_schema();
        return is_array($schema) ? $schema : [];
    }

    /* ---------------------------------------------------------------------
     * Services bundle access
     * ------------------------------------------------------------------ */

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

    /**
     * Convenience access to the distributor's fulfillment table (if available).
     *
     * Some workflows (like local-only validation) rely on this table.
     */
    protected function get_fulfillment_table(): ?DistributorTableInterface
    {
        return $this->services ? $this->services->get_fulfillment_table() : null;
    }

    /* ---------------------------------------------------------------------
     * Legacy WordPress option naming helpers
     * ------------------------------------------------------------------ */

    /**
     * NOTE: These are older helpers for settings pages.
     * New settings access should generally use Settings\Options helpers.
     */
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

    /* ---------------------------------------------------------------------
     * Product / pricing (default implementations)
     * ------------------------------------------------------------------ */

    /**
     * Default offer lookup:
     * - Normalizes UPC (digits only).
     * - Uses pricing-only payload when include_images=false (cron fast-path).
     * - Wraps the payload as a DistributorOffer (id + label + product).
     */
    public function get_offer_by_upc(string $upc, bool $include_images = true): ?DistributorOffer
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        // Fast-path: no image probing for cron/sync workloads.
        if (!$include_images && method_exists($this, 'get_pricing_payload_by_upc')) {
            $product = $this->get_pricing_payload_by_upc($normalized);
        } else {
            $product = $this->get_product_by_upc($normalized);
        }

        if (!$product instanceof DistributorProductPayload) {
            return null;
        }

        return new DistributorOffer(
            $this->get_id(),
            $this->get_label(),
            $product
        );
    }

    /**
     * Fetch a full product payload by UPC.
     *
     * Subclasses should override this.
     *
     * IMPORTANT:
     * - DistributorBase normalizes incoming UPCs before calling this.
     * - Implementations may assume $upc is digits-only.
     */
    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        return null;
    }

    /**
     * Pricing-only payload.
     *
     * Default: call get_product_by_upc() with normalized UPC.
     * Distributors may override to fetch a cheaper/smaller response.
     */
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
        if (!$product instanceof DistributorProductPayload) {
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
        if (!$product instanceof DistributorProductPayload) {
            return null;
        }

        $p = $product->price;
        if ($p === null || $p === '') {
            return null;
        }

        return (float) $p;
    }

    /**
     * Default shipping cost for a UPC.
     *
     * Many distributors don't provide this; default is 0.0.
     * Subclasses may override to implement real shipping heuristics.
     */
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
     *
     * Returns null if the UPC is empty after normalization.
     */
    protected function normalize_upc(string $upc): ?string
    {
        $normalized = preg_replace('/\D+/', '', (string) $upc);
        $normalized = is_string($normalized) ? $normalized : '';
        $normalized = trim($normalized);

        return $normalized !== '' ? $normalized : null;
    }

    /* ---------------------------------------------------------------------
     * Generic "row field" extraction helpers (for fulfillment row mapping)
     * ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed> $item
     * @param array<int,string>   $keys
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
     * @param array<int,string>   $keys
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
     * @param array<int,string>   $keys
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
     * @param array<int,string>   $keys
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

    /* ---------------------------------------------------------------------
     * Payload normalization helpers (shared formatting)
     * ------------------------------------------------------------------ */

    protected static function normalize_payload_string($value): string
    {
        return trim((string) $value);
    }

    protected static function normalize_us_state_code_for_payload($state): string
    {
        return strtoupper(trim((string) $state));
    }

    protected static function extract_digits($value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        return is_string($digits) ? $digits : '';
    }

    protected static function format_us_zip5_for_payload($zip): string
    {
        $digits = self::extract_digits($zip);
        if ($digits === '') {
            return '';
        }
        return substr($digits, 0, 5);
    }

    protected static function format_us_zip5_or_zip9_with_dash_for_payload($zip): string
    {
        $zip = trim((string) $zip);
        if ($zip === '') {
            return '';
        }

        // Already in ZIP+4 format.
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

    /* ---------------------------------------------------------------------
     * Payload building
     * ------------------------------------------------------------------ */

    /**
     * Build a normalized DistributorProductPayload from a fulfillment row + mapping.
     *
     * @param array<string,mixed>                  $row
     * @param array<string,array<int,string>>      $map
     * @param callable                             $category_mapper fn(?string $raw_category): ?array
     */
    protected function build_payload_from_row(
        array $row,
        array $map,
        callable $category_mapper,
        string $normalized_upc,
        bool $include_images = true
    ): DistributorProductPayload {
        $sku     = $this->get_string_field($row, $map['sku'] ?? []) ?? '';
        $upc_raw = $this->get_string_field($row, $map['upc'] ?? []) ?? $normalized_upc;
        $upc     = $this->normalize_upc($upc_raw) ?? $normalized_upc;

        $raw_name        = $this->get_string_field($row, $map['name'] ?? []) ?? '';
        $raw_description = $this->get_string_field($row, $map['description'] ?? []) ?? '';

        $raw_name        = trim((string) preg_replace('/\s+/', ' ', $raw_name));
        $raw_description = trim((string) preg_replace('/\s+/', ' ', $raw_description));

        // Prefer "Name – Description" unless description is already inside the name.
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

        // True cost is distributor-defined; default returns distributor_cost.
        $true_cost = $this->get_true_cost_by_distributor_cost_shipping_cost($price, $shipping);
        if ($true_cost === null) {
            $true_cost = $price + $shipping;
        }

        $category_raw = $this->get_string_field($row, $map['category'] ?? []);
        $recommended_category = $category_mapper($category_raw);
        if (!is_array($recommended_category)) {
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

    /**
     * Extract an image URL from a fulfillment row.
     *
     * Default implementation returns empty string.
     * Subclasses typically override to implement distributor-specific behavior.
     *
     * @param array<string,mixed> $row
     * @param mixed              $field Field key or schema entry.
     */
    protected function get_image_url_from_row(array $row, $field): string
    {
        return '';
    }

    /* ---------------------------------------------------------------------
     * True cost helpers
     * ------------------------------------------------------------------ */

    /**
     * Compute "true cost" for pricing decisions.
     *
     * Default behavior returns distributor cost unchanged (ignores shipping).
     * Subclasses may override to incorporate shipping or other heuristics.
     */
    protected function get_true_cost_by_distributor_cost_shipping_cost(float $distributor_cost, float $shipping_cost): ?float
    {
        return $distributor_cost;
    }

    /* ---------------------------------------------------------------------
     * Ordering helpers
     * ------------------------------------------------------------------ */

    /**
     * Map order lines into a distributor-specific Items[] payload.
     *
     * This is a shared helper for different distributor ordering APIs where
     * each line must be mapped into a required item key (SKU/ItemNo/etc).
     *
     * Behavior:
     * - Iterates provided lines, normalizes UPCs, clamps qty >= 1.
     * - Uses $map() to map UPC -> required key.
     * - Uses $build() to produce the array item payload.
     * - Returns DistributorOrderResult::block_fatal(...) on mapping errors.
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

    /**
     * Place an order with the distributor.
     *
     * Default: fatal "not implemented".
     * Subclasses that support ordering must override.
     */
    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        return DistributorOrderResult::block_fatal(
            'Ordering is not implemented for this distributor.',
            [DistributorOrderResult::REASON_FATAL_NOT_IMPLEMENTED]
        );
    }

    /**
     * Validate an order request before placement (optional).
     *
     * Default behavior: allow.
     * Some distributors support preflight validation via API; others do not.
     *
     * @param bool $local_only If true, perform only local checks (no remote API).
     */
    public function validate_order_request(
        DistributorOrderRequest $request,
        bool $local_only = false
    ): DistributorOrderValidationResult {
        return DistributorOrderValidationResult::allow('No distributor-specific validation implemented.');
    }

    /**
     * Return subset of lines this distributor can validate using local fulfillment table.
     *
     * This is useful for cart/order compliance: only validate lines that this
     * distributor actually carries (based on fulfillment table presence).
     *
     * @param DistributorOrderLine[] $lines
     * @return DistributorOrderLine[]
     */
    public function filter_lines_for_validation(array $lines): array
    {
        $table = $this->get_fulfillment_table();
        if (!$table) {
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

    /**
     * Fetch shipment information by purchase order number (if supported).
     *
     * Default: not implemented (returns null).
     * Subclasses may override.
     */
    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        return null;
    }
}
