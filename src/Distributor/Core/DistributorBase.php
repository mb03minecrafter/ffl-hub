<?php

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
        // Fast-path: no image probing for cron/sync workloads.
        $product = null;

        if (! $include_images && method_exists($this, 'get_pricing_payload_by_upc')) {
            /** @var callable $call */
            $call = [$this, 'get_pricing_payload_by_upc'];
            $product = $call($upc);
        } else {
            $product = $this->get_product_by_upc($upc);
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

    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        return null; // distributors override
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

        $q = $product->quantity;
        if ($q === null || $q === '') {
            return null;
        }

        return (int) $q;
    }

    public function get_distributor_price_by_upc(string $upc): ?float
    {
        $product = $this->get_pricing_payload_by_upc($upc);
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
        // If invalid, still return the cleaned value (some APIs tolerate it / will validate)
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
     * Examples:
     *  - "70808" => "70808"
     *  - "708081234" => "70808-1234"
     *  - "70808-1234" => "70808-1234"
     */
    protected static function format_us_zip5_or_zip9_with_dash_for_payload($zip): string
    {
        $zip = trim((string) $zip);
        if ($zip === '') {
            return '';
        }

        // Preserve already-correct ZIP+4 form.
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
     * Policy defaults:
     * - price/map/msrp: 0.0 if missing
     * - quantity: 0 if missing
     * - shipping: 0.0 if missing
     * - true_cost: computed from distributor logic; falls back to base_cost (price + shipping) if needed
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
        // Strings (raw fields)
        $sku            = $this->get_string_field($row, $map['sku'] ?? []) ?? '';
        $upc            = $this->get_string_field($row, $map['upc'] ?? []) ?? $normalized_upc;
        $raw_name        = $this->get_string_field($row, $map['name'] ?? []) ?? '';
        $raw_description = $this->get_string_field($row, $map['description'] ?? []) ?? '';

        // Normalize whitespace
        $raw_name        = trim(preg_replace('/\s+/', ' ', $raw_name));
        $raw_description = trim(preg_replace('/\s+/', ' ', $raw_description));

        /**
         * Unified naming rule:
         *   - If both exist and description is not already contained in name:
         *         "Name – Description"
         *   - If only one exists: use that
         *   - Else: empty string
         */
        if ($raw_name !== '' && $raw_description !== '') {
            // Avoid duplicate text if distributor already embeds description in name
            if (stripos($raw_name, $raw_description) !== false) {
                $name = $raw_name;
            } else {
                $name = $raw_name . ' – ' . $raw_description;
            }
        } else {
            $name = $raw_name !== '' ? $raw_name : $raw_description;
        }

        // Preserve description separately (never merged)
        $description = $raw_description;

        // Numerics (strict defaults)
        $price    = (float) ($this->get_float_field($row, $map['price'] ?? []) ?? 0.0);
        $mapPrice = (float) ($this->get_float_field($row, $map['map'] ?? []) ?? 0.0);
        $msrp     = (float) ($this->get_float_field($row, $map['msrp'] ?? []) ?? 0.0);
        $quantity = (int)   ($this->get_int_field($row, $map['quantity'] ?? []) ?? 0);

        // Shipping and true cost
        $shipping = (float) ($this->get_shipping_cost_by_upc($normalized_upc) ?? 0.0);

        // Your current true-cost helper returns distributor_cost only (you said no gross-up anymore).
        // We'll still call it for flexibility, but ensure we never return null.
        $true_cost = $this->get_true_cost_by_distributor_cost_shipping_cost($price, $shipping);
        if ($true_cost === null) {
            $true_cost = $price + $shipping;
        }

        // Category
        $category_raw = $this->get_string_field($row, $map['category'] ?? []);
        $recommended_category = $category_mapper($category_raw);
        if (! is_array($recommended_category)) {
            $recommended_category = null;
        }

        // Image (optional)
        $image = '';
        if ($include_images && isset($map['image'])) {
            $image = $this->get_image_url_from_row($row, $map['image']);
            $image = is_string($image) ? trim($image) : '';
        }

        // ffl_required (optional mapping; default false)
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
     * Resolve an image URL from a fulfillment row.
     * Default behavior: no images. Distributors may override.
     *
     * @param array<string,mixed> $row
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

        $shipping_cost = $this->get_shipping_cost_by_upc($upc) ?? 0.0;

        // You said you don't do crazy price calcs anymore; keep actual base.
        return $distributor_cost + $shipping_cost;
    }

    protected function get_true_cost_by_distributor_cost_shipping_cost(float $distributor_cost, float $shipping_cost): ?float
    {
        // You said you don't do crazy price calcs anymore; keep actual base.
        // (Leave signature intact for future reintroduction.)
        return $distributor_cost;
    }

    /* ---- Compliance helpers (shipping restrictions) ---- */

    public function can_ship_to_state_by_upc(string $upc, string $state_code): ?bool
    {
        $state_code = $this->normalize_state_code($state_code);
        if ($state_code === null) {
            return null;
        }

        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $table = $this->get_fulfillment_table();
        if (! $table) {
            return null;
        }

        $row = $table->get_row_by_upc($normalized_upc);
        if (! is_array($row) || empty($row)) {
            return null;
        }

        return $this->can_ship_row_to_state($row, $state_code);
    }

    /**
     * Determine if the provided fulfillment-table row can ship to the given state.
     *
     * Default implementation assumes a per-state "block flag" key:
     *   ship_la, ship_tx, ship_ca, ...
     *
     * Convention (your current behavior):
     * - truthy => BLOCKED (return false)
     * - falsey => allowed (return true)
     *
     * Distributors with different schemas can override this.
     *
     * @param array<string,mixed> $row
     */
    protected function can_ship_row_to_state(array $row, string $state_code): ?bool
    {
        $key = 'ship_' . strtolower($state_code);
        if (! array_key_exists($key, $row)) {
            return null;
        }

        $val = $row[$key];

        // Truthy => blocked
        if ($val === true || $val === 1 || $val === '1' || $val === 'Y' || $val === 'y' || $val === 'YES' || $val === 'yes') {
            return false;
        }

        // Falsey => allowed
        if ($val === false || $val === 0 || $val === '0' || $val === 'N' || $val === 'n' || $val === 'NO' || $val === 'no') {
            return true;
        }

        return null;
    }

    protected function normalize_state_code(string $state_code): ?string
    {
        $state_code = strtoupper(trim($state_code));
        if ($state_code === '') {
            return null;
        }

        return preg_match('/^[A-Z]{2}$/', $state_code) ? $state_code : null;
    }

    /* ---- Ordering helpers ---- */

    /**
     * Map order lines into a distributor-specific Items[] payload.
     *
     * - Trims UPC
     * - Normalizes UPC using $this->normalize_upc()
     * - Normalizes quantity (min 1)
     * - Uses $map to convert UPC -> distributor key (ItemNo, PartNum, etc.)
     * - Uses $build to produce the final item array
     *
     * If mapping fails, returns DistributorOrderResult (failure) so caller can bubble up cleanly.
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

        foreach ($lines as $l) {
            if (! ($l instanceof DistributorOrderLine)) {
                continue;
            }

            $raw_upc = trim((string) $l->upc);
            if ($raw_upc === '') {
                continue;
            }

            $normalized_upc = $this->normalize_upc($raw_upc);
            if ($normalized_upc === null) {
                return new DistributorOrderResult(false, 'Invalid UPC (failed normalization): ' . $raw_upc, []);
            }

            $qty = max(1, (int) $l->quantity);

            $mapped_key = $map($normalized_upc, $raw_upc, $l);
            $mapped_key = is_string($mapped_key) ? trim($mapped_key) : '';

            if ($mapped_key === '') {
                return new DistributorOrderResult(false, sprintf($map_error_fmt, $raw_upc), []);
            }

            $item = $build($mapped_key, $qty, $normalized_upc, $raw_upc, $l);
            if (is_array($item) && ! empty($item)) {
                $items[] = $item;
            }
        }

        if ($require_non_empty && empty($items)) {
            return new DistributorOrderResult(false, $empty_error_msg, []);
        }

        return $items;
    }

    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        return new DistributorOrderResult(false, 'Ordering is not implemented for this distributor.', []);
    }



    /**
     * Validate whether this distributor can fulfill the request given destination, item restrictions,
     * and any drop-ship/FFL requirements.
     *
     * Default behavior: allow (some distributors won’t support a preflight API).
     */
    public function validate_order_request(DistributorOrderRequest $request): DistributorOrderValidationResult
    {
        return DistributorOrderValidationResult::allow('No distributor-specific validation implemented.');
    }


    /**
     * Return subset of lines this distributor can validate. AKA we dont wanna try and validate items that the distirbutor does not carry for cart compliance. 
     * Default: return lines as-is.
     *
     * @param DistributorOrderLine[] $lines
     * @return DistributorOrderLine[]
     */
    public function filter_lines_for_validation(array $lines): array
    {
        if (! $this->services) {
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

            $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized);
            if ($row) {
                $out[] = $l;
            }
        }

        return $out;
    }
}
