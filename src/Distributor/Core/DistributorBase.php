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

    /**
     * Parse mixed bool-ish values used across distributor tables.
     *
     * Accepts: 1/0, y/n, yes/no, true/false, on/off.
     */
    protected function to_boolish($value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) > 0;
        }

        if ($value === null) {
            return $default;
        }

        $s = strtoupper(trim((string) $value));
        if ($s === '') {
            return $default;
        }

        if (in_array($s, ['1', 'Y', 'YES', 'T', 'TRUE', 'ON'], true)) {
            return true;
        }

        if (in_array($s, ['0', 'N', 'NO', 'F', 'FALSE', 'OFF'], true)) {
            return false;
        }

        if (is_numeric($s)) {
            return ((int) $s) > 0;
        }

        return $default;
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
     * Shared order payload formatting helpers
     * ------------------------------------------------------------------ */

    protected static function truncate_string(string $s, int $max): string
    {
        $s = trim($s);
        if ($s === '' || $max <= 0) {
            return '';
        }
        return (strlen($s) <= $max) ? $s : substr($s, 0, $max);
    }

    /**
     * "Best effort" state formatter for APIs that REQUIRE 2 chars.
     * Your existing normalize_us_state_code_for_payload() does not clamp.
     */
    protected static function format_us_state2_best_effort($state): string
    {
        $s = strtoupper(trim((string) $state));
        if ($s === '') {
            return '';
        }
        return (strlen($s) === 2) ? $s : substr($s, 0, 2);
    }

    /**
     * ZIP5 best-effort:
     * - Accepts "12345-6789" -> "12345"
     * - Otherwise extracts digits and uses first 5.
     */
    protected static function format_us_zip5_best_effort($zip): string
    {
        $zip = trim((string) $zip);
        if ($zip === '') {
            return '';
        }

        if (preg_match('/^(\d{5})/', $zip, $m)) {
            return (string) $m[1];
        }

        $digits = self::extract_digits($zip);
        if ($digits === '') {
            return '';
        }

        return substr($digits, 0, 5);
    }

    protected static function looks_like_yyyy_mm_dd(string $s): bool
    {
        return (bool) preg_match('/^\d{4}\-\d{2}\-\d{2}$/', trim($s));
    }

    /**
     * Zanders-style ship instructions: 40 chars name + 40 chars phone.
     */
    protected static function build_fixed_80_ship_instructions(string $customer_name, string $customer_phone): string
    {
        $name  = self::truncate_string($customer_name !== '' ? $customer_name : 'Customer', 40);
        $phone = self::truncate_string($customer_phone !== '' ? $customer_phone : 'NA', 40);

        $name  = str_pad($name, 40, ' ');
        $phone = str_pad($phone, 40, ' ');

        return substr($name . $phone, 0, 80);
    }

    /**
     * First 3 digits + last 5 digits from an FFL number (digits-only).
     * Zanders requires this for addressinfo.fflno.
     */
    protected static function format_fflno_first3_last5(string $ffl): string
    {
        $digits = self::extract_digits($ffl);
        if (strlen($digits) < 8) {
            return '';
        }
        return substr($digits, 0, 3) . substr($digits, -5);
    }

    /**
     * Minimal ship-to validation shared across distributors.
     *
     * @return array{ok:bool,message:string}
     */
    protected static function validate_shipto_minimum(\FFLHub\Distributor\Models\DistributorShipTo $s): array
    {
        if (
            trim((string) $s->name) === '' ||
            trim((string) $s->address1) === '' ||
            trim((string) $s->city) === '' ||
            trim((string) $s->state) === '' ||
            trim((string) $s->zip) === ''
        ) {
            return ['ok' => false, 'message' => 'ship-to missing required fields (name/address/city/state/zip).'];
        }

        return ['ok' => true, 'message' => 'OK'];
    }

    /**
     * Convenience: sanitize then truncate a merchant PO.
     */
    protected function sanitize_and_truncate_po(string $po, int $max, string $allowed_regex = '/[^A-Z0-9\-]/'): string
    {
        $po = $this->sanitize_po($po, $allowed_regex, true);
        return self::truncate_string($po, $max);
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

        // Prefer "Name - Description" unless description is already inside the name.
        if ($raw_name !== '' && $raw_description !== '') {
            if (stripos($raw_name, $raw_description) !== false) {
                $name = $raw_name;
            } else {
                $name = $raw_name . ' - ' . $raw_description;
            }
        } else {
            $name = $raw_name !== '' ? $raw_name : $raw_description;
        }

        $description = $raw_description;

        $price    = (float) ($this->get_float_field($row, $map['price'] ?? []) ?? 0.0);
        $mapPrice = (float) ($this->get_float_field($row, $map['map'] ?? []) ?? 0.0);
        $msrp     = (float) ($this->get_float_field($row, $map['msrp'] ?? []) ?? 0.0);
        $quantity = (int)   ($this->get_int_field($row, $map['quantity'] ?? []) ?? 0);
        $shipping_weight = $this->get_string_field($row, $map['shipping_weight'] ?? ['shipping_weight']);
        $shipping_length_in = $this->get_string_field($row, $map['shipping_length_in'] ?? ['shipping_length_in']);
        $shipping_width_in  = $this->get_string_field($row, $map['shipping_width_in'] ?? ['shipping_width_in']);
        $shipping_height_in = $this->get_string_field($row, $map['shipping_height_in'] ?? ['shipping_height_in']);

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

        $sot_required = false;
        $sot_raw = $this->get_string_field($row, $map['sot_required'] ?? ['sot_required']);
        if ($sot_raw !== null) {
            $sot_required = $this->to_boolish($sot_raw, false);
        }

        // Default true when source does not provide this yet.
        $dropship_enabled = true;
        $dropship_raw = $this->get_string_field($row, $map['dropship_enabled'] ?? ['dropship_enabled']);
        if ($dropship_raw !== null) {
            $dropship_enabled = $this->to_boolish($dropship_raw, true);
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
            $dropship_enabled,
            $recommended_category,
            $row,
            $shipping_weight,
            $sot_required,
            $shipping_length_in,
            $shipping_width_in,
            $shipping_height_in
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

            $raw_upc = $this->read_line_upc($l);
            $raw_upc = trim((string) $raw_upc);
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

            $qty = max(1, (int) $this->read_line_qty($l));

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



    /* -------------------------------------------------------------------------
     * Internal helpers: line aggregation (validation)
     * ---------------------------------------------------------------------- */

    /**
     * Aggregate required quantities by normalized UPC.
     *
     * Why:
     * - Checkouts can contain the same UPC multiple times (qty spread across lines).
     * - Lipsey's ValidateItem is per-item; we need total required per UPC.
     *
     * @param DistributorOrderLine[] $lines
     * @return array<string,int> map of UPC => requiredQty
     */
    protected function build_required_qty_by_upc(array $lines): array
    {
        $required = [];

        foreach ($lines as $idx => $l) {
            if (!($l instanceof DistributorOrderLine)) {

                continue;
            }

            $raw_upc = $this->read_line_upc($l);
            $qty     = $this->read_line_qty($l);

            $upc = $this->normalize_upc($raw_upc);
            if ($upc === null) {
                continue;
            }

            if ($qty < 1) {
                continue;
            }

            if (!isset($required[$upc])) {
                $required[$upc] = 0;
            }

            $required[$upc] += $qty;
        }

        return $required;
    }

    /**
     * Defensive UPC accessor.
     */
    protected function read_line_upc(DistributorOrderLine $l): string
    {
        foreach (['get_upc', 'getUpc', 'upc'] as $m) {
            if (method_exists($l, $m)) {
                try {
                    $v = $l->{$m}();
                    $v = trim((string) $v);
                    if ($v !== '') {
                        return $v;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        if (isset($l->upc)) {
            return trim((string) $l->upc);
        }

        return '';
    }

    /**
     * Defensive quantity accessor (clamps to >= 0).
     */
    protected function read_line_qty(DistributorOrderLine $l): int
    {
        foreach (['get_qty', 'getQty', 'qty', 'get_quantity', 'getQuantity', 'quantity'] as $m) {
            if (method_exists($l, $m)) {
                try {
                    return max(0, (int) $l->{$m}());
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        if (isset($l->quantity)) {
            return max(0, (int) $l->quantity);
        }


        return 0;
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


    /**
     * Normalize shipment carrier/service string to a standard carrier name.
     *
     * Examples:
     *   "usps USPS Ground Advantage" -> "USPS"
     *   "UPS Next Day Air"           -> "UPS"
     *   "Federal Express"            -> "FEDEX"
     *
     * @param string|null $raw
     * @return string|null  Canonical carrier (USPS|UPS|FEDEX) or null if unknown.
     */
    protected function normalize_carrier(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $v = strtoupper($raw);

        // USPS
        if (
            strpos($v, 'USPS') !== false
            || strpos($v, 'POSTAL') !== false
            || strpos($v, 'UNITED STATES POSTAL') !== false
        ) {
            return 'USPS';
        }

        // UPS
        if (
            strpos($v, 'UPS') !== false
            || strpos($v, 'UNITED PARCEL') !== false
        ) {
            return 'UPS';
        }

        // FedEx
        if (
            strpos($v, 'FEDEX') !== false
            || strpos($v, 'FEDERAL EXPRESS') !== false
        ) {
            return 'FedEx';
        }

        return null;
    }


    /**
     * Infer carrier from a tracking number (best-effort).
     *
     * @return string|null  USPS|UPS|FEDEX or null if unknown
     */
    protected function infer_carrier_from_tracking(string $t): ?string
    {
        $t = strtoupper(trim($t));
        if ($t === '') {
            return null;
        }

        // UPS: typically starts with 1Z
        if (strpos($t, '1Z') === 0) {
            return 'UPS';
        }

        // USPS: many are 22 digits starting with 9 (e.g., 9400...)
        if (preg_match('/^9\d{21}$/', $t)) {
            return 'USPS';
        }

        // FedEx: common forms are 12, 15, 20, 22 digits (not definitive)
        if (preg_match('/^\d{12}$|^\d{15}$|^\d{20}$|^\d{22}$/', $t)) {
            return 'FEDEX';
        }

        return null;
    }




    /**
     * Validate required quantities using the local fulfillment table.
     *
     * Centralizes the common "local_only" / "local fallback" behavior:
     * - aggregate qty per UPC
     * - lookup row by UPC in local fulfillment table
     * - parse inventory quantity safely
     * - unknown qty policy (default: block)
     * - optional lane enforcement against row flag (e.g., ffl_required)
     *
     * IMPORTANT (fix):
     * - Missing row is NOT the same as qty=0.
     *   We now record missing rows as:
     *     found=0, inventory_raw=null, local_qty=null, reason=not_carried
     *   and emit a specific code so CartCompliance can ignore non-source voters.
     *
     * @param DistributorOrderLine[] $lines
     * @param array{
     *   label?:string,
     *   max_unique?:int,
     *   inventory_keys?:string[],
     *   unknown_qty_blocks?:bool,
     *   lane?:string,
     *   enforce_ffl_required?:null|int,
     *   ffl_required_row_keys?:string[],
     *   extra_row_checks?:null|callable(array $row,string $normalized_upc,int $requiredQty):array{ok:bool,message?:string,details?:array},
     *   code_prefix?:string, // optional stable prefix for codes (ex: ZANDERS, RSR, LIPSEYS)
     * } $opts
     */
    protected function validate_local_fulfillment_required_qty_by_upc(array $lines, array $opts = []): DistributorOrderValidationResult
    {
        $label = isset($opts['label']) ? trim((string) $opts['label']) : 'Local fulfillment';
        if ($label === '') {
            $label = 'Local fulfillment';
        }

        // Prefer an explicit code_prefix; otherwise use distributor id if available; otherwise derive from label.
        $code_prefix = isset($opts['code_prefix']) ? strtoupper(trim((string) $opts['code_prefix'])) : '';
        if ($code_prefix === '' && method_exists($this, 'get_id')) {
            $code_prefix = strtoupper(trim((string) $this->get_id()));
        }
        if ($code_prefix === '') {
            $code_prefix = strtoupper(preg_replace('/\s+/', '_', $label));
        }

        $required_by_upc = $this->build_required_qty_by_upc($lines);
        if (empty($required_by_upc)) {
            return DistributorOrderValidationResult::allow($label . ': no valid UPC line items to validate.', [
                'required_by_upc' => [],
                'items' => [],
                'label' => $label,
            ]);
        }

        $max_unique = isset($opts['max_unique']) ? (int) $opts['max_unique'] : 75;
        if ($max_unique > 0 && count($required_by_upc) > $max_unique) {
            return DistributorOrderValidationResult::block(
                $label . ': too many unique items to validate (' . count($required_by_upc) . ').',
                [$code_prefix . '_TOO_MANY_UNIQUE'],
                [
                    'label' => $label,
                    'unique_count' => count($required_by_upc),
                    'max_unique' => $max_unique,
                    'required_by_upc' => $required_by_upc,
                ]
            );
        }

        if (!$this->services) {
            return DistributorOrderValidationResult::block(
                $label . ': services not available; cannot access fulfillment table.',
                [$code_prefix . '_SERVICES_MISSING'],
                [
                    'label' => $label,
                    'required_by_upc' => $required_by_upc,
                ]
            );
        }

        $table = $this->services->get_fulfillment_table();
        if (!$table) {
            return DistributorOrderValidationResult::block(
                $label . ': fulfillment table not available.',
                [$code_prefix . '_TABLE_MISSING'],
                [
                    'label' => $label,
                    'required_by_upc' => $required_by_upc,
                ]
            );
        }

        $inventory_keys = isset($opts['inventory_keys']) && is_array($opts['inventory_keys'])
            ? array_values($opts['inventory_keys'])
            : ['inventory_quantity'];

        $unknown_qty_blocks = array_key_exists('unknown_qty_blocks', $opts) ? (bool) $opts['unknown_qty_blocks'] : true;

        $lane = isset($opts['lane']) ? trim((string) $opts['lane']) : '';
        $enforce_ffl_required = array_key_exists('enforce_ffl_required', $opts) ? $opts['enforce_ffl_required'] : null;
        if ($enforce_ffl_required !== null) {
            $enforce_ffl_required = (int) $enforce_ffl_required;
            if ($enforce_ffl_required !== 0 && $enforce_ffl_required !== 1) {
                $enforce_ffl_required = null;
            }
        }

        $ffl_required_row_keys = isset($opts['ffl_required_row_keys']) && is_array($opts['ffl_required_row_keys'])
            ? array_values($opts['ffl_required_row_keys'])
            : ['ffl_required'];

        $extra_row_checks = isset($opts['extra_row_checks']) && is_callable($opts['extra_row_checks'])
            ? $opts['extra_row_checks']
            : null;

        $details = [
            'label' => $label,
            'lane' => $lane,
            'required_by_upc' => $required_by_upc,
            'items' => [],
            'policy' => [
                'unknown_qty_blocks' => $unknown_qty_blocks ? 1 : 0,
                'inventory_keys' => $inventory_keys,
                'enforce_ffl_required' => $enforce_ffl_required,
                'ffl_required_row_keys' => $ffl_required_row_keys,
                'code_prefix' => $code_prefix,
            ],
        ];

        $fail_msgs = [];

        // Track failure kinds so we can emit specific codes (in addition to the legacy *_BLOCKED).
        $has_invalid_upc      = false;
        $has_not_carried      = false;
        $has_unknown_qty      = false;
        $has_insufficient     = false;
        $has_lane_mismatch    = false;
        $has_extra_row_fail   = false;

        foreach ($required_by_upc as $upc => $requiredQty) {
            $requiredQty = (int) $requiredQty;

            $normalized = $this->normalize_upc((string) $upc);
            if ($normalized === null) {
                $has_invalid_upc = true;
                $fail_msgs[] = "UPC={$upc} invalid (normalize_upc null)";
                $details['items'][(string) $upc] = [
                    'requiredQty' => $requiredQty,
                    'found' => 0,
                    'inventory_raw' => null,
                    'local_qty' => null,
                    'reason' => 'invalid_upc',
                ];
                continue;
            }

            $row = $table->get_row_by_upc($normalized);
            if (!$row || !is_array($row)) {
                // KEY FIX: missing row is "not carried", not qty=0.
                $has_not_carried = true;
                $fail_msgs[] = "UPC={$normalized} not found in local fulfillment table";
                $details['items'][$normalized] = [
                    'requiredQty' => $requiredQty,
                    'found' => 0,
                    'inventory_raw' => null,
                    'local_qty' => null,
                    'reason' => 'not_carried',
                ];
                continue;
            }

            $qty_raw = $this->get_string_field($row, $inventory_keys);
            $qty_raw_s = trim((string) $qty_raw);

            $local_qty = null;
            if ($qty_raw_s !== '' && is_numeric($qty_raw_s)) {
                $local_qty = (int) $qty_raw_s;
            }

            $item_details = [
                'requiredQty' => $requiredQty,
                'found' => 1,
                'inventory_raw' => ($qty_raw_s !== '' ? $qty_raw_s : null),
                'local_qty' => $local_qty,
            ];

            if ($enforce_ffl_required !== null) {
                $ffl_raw  = $this->get_string_field($row, $ffl_required_row_keys);
                $ffl_flag = (int) (is_numeric((string) $ffl_raw) ? (int) $ffl_raw : ((string) $ffl_raw === 'Y' ? 1 : 0));
                $item_details['ffl_required'] = $ffl_flag;

                if ($ffl_flag !== $enforce_ffl_required) {
                    $has_lane_mismatch = true;
                    $fail_msgs[] = "UPC={$normalized} lane_mismatch ffl_required={$ffl_flag} expected={$enforce_ffl_required}";
                    $item_details['reason'] = 'lane_mismatch';
                    $details['items'][$normalized] = $item_details;
                    continue;
                }
            }

            if ($extra_row_checks) {
                $chk = $extra_row_checks($row, $normalized, $requiredQty);
                $ok  = (bool) ($chk['ok'] ?? false);

                if (isset($chk['details']) && is_array($chk['details'])) {
                    $item_details = array_merge($item_details, $chk['details']);
                }

                if (!$ok) {
                    $has_extra_row_fail = true;
                    $m = trim((string) ($chk['message'] ?? 'extra_row_checks failed'));
                    $fail_msgs[] = "UPC={$normalized} " . $m;
                    $item_details['reason'] = 'extra_row_checks';
                    $details['items'][$normalized] = $item_details;
                    continue;
                }
            }

            if ($local_qty === null) {
                $has_unknown_qty = true;
                $item_details['reason'] = 'unknown_qty';
                $details['items'][$normalized] = $item_details;

                if ($unknown_qty_blocks) {
                    $fail_msgs[] = "UPC={$normalized} local_qty=UNKNOWN required={$requiredQty}";
                }
                continue;
            }

            if ($local_qty < $requiredQty) {
                $has_insufficient = true;
                $fail_msgs[] = "UPC={$normalized} local_available={$local_qty} required={$requiredQty}";
                $item_details['reason'] = 'insufficient';
            } else {
                $item_details['reason'] = 'ok';
            }

            $details['items'][$normalized] = $item_details;
        }

        if (!empty($fail_msgs)) {
            $msg = $label . ' failed: ' . implode(' | ', array_slice($fail_msgs, 0, 8));
            if (count($fail_msgs) > 8) {
                $msg .= ' | ...';
            }

            // Preserve the legacy code (some callers might key off it),
            // but ALSO emit specific codes so CartCompliance can safely ignore non-source voters.
            $codes = [$code_prefix . '_VALIDATION_LOCAL_BLOCKED'];

            if ($has_invalid_upc)     $codes[] = $code_prefix . '_INVALID_UPC';
            if ($has_not_carried)     $codes[] = $code_prefix . '_NOT_CARRIED';
            if ($has_unknown_qty)     $codes[] = $code_prefix . '_UNKNOWN_QTY';
            if ($has_insufficient)    $codes[] = $code_prefix . '_OUT_OF_STOCK';
            if ($has_lane_mismatch)   $codes[] = $code_prefix . '_LANE_MISMATCH';
            if ($has_extra_row_fail)  $codes[] = $code_prefix . '_EXTRA_ROW_CHECKS_FAILED';

            $details['failure_flags'] = [
                'invalid_upc'     => $has_invalid_upc ? 1 : 0,
                'not_carried'     => $has_not_carried ? 1 : 0,
                'unknown_qty'     => $has_unknown_qty ? 1 : 0,
                'insufficient'    => $has_insufficient ? 1 : 0,
                'lane_mismatch' => $has_lane_mismatch ? 1 : 0,
                'extra_row_checks' => $has_extra_row_fail ? 1 : 0,
            ];

            return DistributorOrderValidationResult::block(
                $msg,
                array_values(array_unique($codes)),
                $details
            );
        }

        return DistributorOrderValidationResult::allow($label . ' OK.', $details);
    }




        /* -------------------------------------------------------------------------
     * Order validation template (base orchestration + distributor hooks)
     * ---------------------------------------------------------------------- */

    /**
     * Validate an order request before placement (optional).
     *
     * Base behavior:
     * - empty lines => allow
     * - build_required_qty_by_upc => allow if empty
     * - max unique guard
     * - optional invariants (ex: FFL ship-to requirements)
     * - local-only path OR "remote not supported" => local validation
     * - remote path => distributor-specific remote validation
     *
     * Distributors should override the hook methods below rather than
     * overriding validate_order_request() directly.
     *
     * @param bool $local_only If true, perform only local checks (no remote API).
     */
    public function validate_order_request(
        DistributorOrderRequest $request,
        bool $local_only = false
    ): DistributorOrderValidationResult {
        if (empty($request->lines)) {
            return DistributorOrderValidationResult::allow('No order lines to validate.');
        }

        $required_by_upc = $this->build_required_qty_by_upc($request->lines);
        if (empty($required_by_upc)) {
            return DistributorOrderValidationResult::allow('No valid UPC line items to validate.');
        }

        $max_unique = $this->validation_max_unique_items($request, $local_only);
        if ($max_unique > 0 && count($required_by_upc) > $max_unique) {
            return DistributorOrderValidationResult::block(
                $this->validation_too_many_unique_message(count($required_by_upc), $max_unique),
                [$this->validation_too_many_unique_code()],
                [
                    'unique_count'     => count($required_by_upc),
                    'max_unique'       => $max_unique,
                    'local_only'       => $local_only ? 1 : 0,
                    'required_by_upc'  => $required_by_upc,
                ]
            );
        }

        // Cheap invariants (can block early).
        $inv = $this->validation_precheck_invariants($request, $local_only);
        if ($inv instanceof DistributorOrderValidationResult) {
            return $inv;
        }

        // Local-only OR remote not supported => local path.
        if ($local_only || !$this->supports_remote_validation()) {
            return $this->validate_order_request_local($request, $required_by_upc, $local_only);
        }

        // Remote path.
        return $this->validate_order_request_remote($request, $required_by_upc);
    }

    /**
     * Whether this distributor supports remote validation.
     */
    protected function supports_remote_validation(): bool
    {
        return false;
    }

    /**
     * Max unique UPCs allowed in validation.
     * Return 0 to disable the guard.
     */
    protected function validation_max_unique_items(DistributorOrderRequest $request, bool $local_only): int
    {
        return 75;
    }

    protected function validation_too_many_unique_code(): string
    {
        return strtoupper($this->get_id()) . '_VALIDATE_TOO_MANY_UNIQUE';
    }

    protected function validation_too_many_unique_message(int $count, int $max): string
    {
        return $this->get_label() . ' validation failed: too many unique items to validate in one checkout (' . $count . ' > ' . $max . ').';
    }

    protected function validation_services_missing_code(): string
    {
        return strtoupper($this->get_id()) . '_SERVICES_MISSING';
    }

    protected function validation_services_missing_message(): string
    {
        return $this->get_label() . ' services not available; cannot access fulfillment table.';
    }

    /**
     * Optional invariant checks before local/remote validation runs.
     *
     * Return:
     * - DistributorOrderValidationResult to block/allow early
     * - null to continue
     */
    protected function validation_precheck_invariants(DistributorOrderRequest $request, bool $local_only): ?DistributorOrderValidationResult
    {
        return null;
    }

    /**
     * Local validation options for validate_local_fulfillment_required_qty_by_upc().
     *
     * @param array<string,int> $required_by_upc
     * @return array<string,mixed>
     */
    protected function validation_local_options(
        DistributorOrderRequest $request,
        array $required_by_upc,
        bool $local_only
    ): array {
        return [
            'label'              => $this->get_label() . ' validation (local)',
            'max_unique'          => $this->validation_max_unique_items($request, $local_only),
            'inventory_keys'      => ['inventory_quantity'],
            'unknown_qty_blocks'  => true,
        ];
    }

    /**
     * Local validation wrapper (centralized services/table guard + call).
     *
     * @param array<string,int> $required_by_upc
     */
    protected function validate_order_request_local(
        DistributorOrderRequest $request,
        array $required_by_upc,
        bool $local_only
    ): DistributorOrderValidationResult {
        if (!$this->services) {
            return DistributorOrderValidationResult::block(
                $this->validation_services_missing_message(),
                [$this->validation_services_missing_code()],
                [
                    'local_only'      => $local_only ? 1 : 0,
                    'required_by_upc' => $required_by_upc,
                ]
            );
        }

        $opts = $this->validation_local_options($request, $required_by_upc, $local_only);

        // Note: validate_local_* will rebuild required_by_upc internally.
        // That's fine; if you want to avoid that later, we can add an optional
        // 'required_by_upc' override to validate_local_*.
        return $this->validate_local_fulfillment_required_qty_by_upc($request->lines, $opts);
    }

    /**
     * Remote validation hook. Only called if supports_remote_validation() is true.
     *
     * @param array<string,int> $required_by_upc
     */
    protected function validate_order_request_remote(
        DistributorOrderRequest $request,
        array $required_by_upc
    ): DistributorOrderValidationResult {
        return DistributorOrderValidationResult::allow($this->get_label() . ' validation: remote not implemented.');
    }

    /**
     * Shared invariant helper: if the request has any FFL lines, require ship_to_ffl + receiving_ffl_number.
     *
     * $code_prefix should be something like "RSR" or strtoupper($this->get_id()).
     */
    protected function require_ffl_shipto_if_ffl_lines(DistributorOrderRequest $request, string $code_prefix): ?DistributorOrderValidationResult
    {
        $ffl_lines = method_exists($request, 'ffl_lines') ? (array) $request->ffl_lines() : [];

        if (empty($ffl_lines)) {
            return null;
        }

        if (!($request->ship_to_ffl instanceof \FFLHub\Distributor\Models\DistributorShipTo)) {
            return DistributorOrderValidationResult::block(
                'Validation failed (FFL items): missing ship_to_ffl (transfer dealer address required).',
                [$code_prefix . '_FFL_ADDRESS_MISSING']
            );
        }

        if (trim((string) $request->receiving_ffl_number) === '') {
            return DistributorOrderValidationResult::block(
                'Validation failed (FFL items): missing receiving FFL number (ShipFFL required).',
                [$code_prefix . '_SHIPFFL_MISSING']
            );
        }

        return null;
    }

    /**
     * Shared helper: infer lane/enforcement from request->lane or line ffl_required flags.
     *
     * Returns: ['lane' => 'direct_ship_ffl'|'direct_ship_non_ffl'|'dealer_fulfilled'|'', 'enforce_ffl_required' => 1|0|null]
     *
     * @return array{lane:string,enforce_ffl_required:null|int}
     */
    protected function infer_lane_and_ffl_enforcement(DistributorOrderRequest $request): array
    {
        $lane = '';
        $enforce = null;

        if (property_exists($request, 'lane')) {
            $l = strtolower(trim((string) ($request->lane ?? '')));
            if ($l === 'direct_ship_ffl') {
                return ['lane' => 'direct_ship_ffl', 'enforce_ffl_required' => 1];
            }
            if ($l === 'direct_ship_non_ffl') {
                return ['lane' => 'direct_ship_non_ffl', 'enforce_ffl_required' => 0];
            }
            if ($l === 'dealer_fulfilled') {
                return ['lane' => 'dealer_fulfilled', 'enforce_ffl_required' => null];
            }
        }

        $flags = [];
        foreach ((array) $request->lines as $ln) {
            if (is_object($ln) && property_exists($ln, 'ffl_required')) {
                $flags[] = ((int) ($ln->ffl_required ?? 0)) ? 1 : 0;
            } elseif (is_array($ln) && array_key_exists('ffl_required', $ln)) {
                $flags[] = ((int) ($ln['ffl_required'] ?? 0)) ? 1 : 0;
            }
        }

        $flags = array_values(array_unique($flags));
        if (count($flags) === 1) {
            $enforce = (int) $flags[0];
            $lane = ($enforce === 1) ? 'direct_ship_ffl' : 'direct_ship_non_ffl';
        }

        return ['lane' => $lane, 'enforce_ffl_required' => $enforce];
    }


    /* -------------------------------------------------------------------------
 * Order placement template (base orchestration + distributor hooks)
 * ---------------------------------------------------------------------- */

    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        if (!$this->supports_ordering()) {
            return DistributorOrderResult::block_fatal(
                'Ordering is not implemented for this distributor.',
                [DistributorOrderResult::REASON_FATAL_NOT_IMPLEMENTED]
            );
        }

        $guard = $this->place_order_precheck($request);
        if ($guard instanceof DistributorOrderResult) {
            return $guard;
        }

        $lines_non = method_exists($request, 'non_ffl_lines') ? (array) $request->non_ffl_lines() : [];
        $lines_ffl = method_exists($request, 'ffl_lines') ? (array) $request->ffl_lines() : [];
        $all_lines = method_exists($request, 'valid_lines') ? (array) $request->valid_lines() : [];

        if (empty($all_lines)) {
            return DistributorOrderResult::block_fatal(
                'No valid order lines after normalization.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        $external_ids = [];
        $errors = [];

        $stop_on_first_failure = $this->place_order_stop_on_first_failure();

        $explicit_lane = strtolower(trim((string) ($request->lane ?? '')));
        $plan = [];

        if ($explicit_lane === 'dealer_fulfilled') {
            $plan[] = ['lane' => 'dealer_fulfilled', 'lines' => $all_lines];
        } elseif ($explicit_lane === 'direct_ship_non_ffl') {
            if (!empty($lines_non)) {
                $plan[] = ['lane' => 'direct_ship_non_ffl', 'lines' => $lines_non];
            }
        } elseif ($explicit_lane === 'direct_ship_ffl') {
            if (!empty($lines_ffl)) {
                $plan[] = ['lane' => 'direct_ship_ffl', 'lines' => $lines_ffl];
            }
        } else {
            // Legacy/unscoped fallback: execute direct-ship non-FFL + direct-ship FFL passes.
            if (!empty($lines_non)) {
                $plan[] = ['lane' => 'direct_ship_non_ffl', 'lines' => $lines_non];
            }
            if (!empty($lines_ffl)) {
                $plan[] = ['lane' => 'direct_ship_ffl', 'lines' => $lines_ffl];
            }
        }

        foreach ($plan as $step) {
            $lane = (string) ($step['lane'] ?? '');
            $lines = isset($step['lines']) && is_array($step['lines']) ? $step['lines'] : [];
            if ($lane === '' || empty($lines)) {
                continue;
            }

            $res = $this->place_order_lane($request, $lane, $lines, $external_ids);

            if (!$this->order_result_code_ok($res)) {
                $res->external_order_ids = $external_ids;

                if ($stop_on_first_failure || $this->place_order_should_short_circuit_on_failure($res)) {
                    return $res;
                }

                $errors[] = (string) $res->message;
            } else {
                // In case lane handler returned OK with ids in result.
                $external_ids = $this->merge_external_ids($external_ids, (array) $res->external_order_ids);
            }
        }

        if (!empty($errors)) {
            return DistributorOrderResult::block_fatal(
                $this->get_label() . ' order failed: ' . $this->join_msgs($errors, 8),
                [DistributorOrderResult::REASON_FATAL_UNKNOWN],
                ['errors' => $errors],
                0,
                '',
                $external_ids
            );
        }

        return DistributorOrderResult::ok($this->get_label() . ' order submitted.', $external_ids);
    }

    protected function supports_ordering(): bool
    {
        return false;
    }

    protected function place_order_stop_on_first_failure(): bool
    {
        // RSR/Zanders want true. Lipsey's wants false (it can attempt both).
        return true;
    }

    protected function place_order_should_short_circuit_on_failure(DistributorOrderResult $res): bool
    {
        // Default: always short-circuit retryables (keeps idempotency simple).
        return $res->is_retryable();
    }

    protected function place_order_precheck(DistributorOrderRequest $request): ?DistributorOrderResult
    {
        if (!$this->services) {
            return DistributorOrderResult::block_fatal(
                $this->get_label() . ' services not available; cannot access fulfillment table.',
                [DistributorOrderResult::REASON_FATAL_SERVICES_MISSING]
            );
        }

        if (empty($request->lines)) {
            return DistributorOrderResult::block_fatal(
                'No order lines provided.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        return null;
    }

    /**
     * @param 'direct_ship_non_ffl'|'direct_ship_ffl'|'dealer_fulfilled' $lane
     * @param array<int,mixed> $lines
     * @param array<int,string> $external_ids accumulator (pass-by-ref)
     */
    protected function place_order_lane(
        DistributorOrderRequest $request,
        string $lane,
        array $lines,
        array &$external_ids
    ): DistributorOrderResult {
        return DistributorOrderResult::block_fatal(
            'place_order_lane not implemented for this distributor.',
            [DistributorOrderResult::REASON_FATAL_NOT_IMPLEMENTED]
        );
    }

    /* -----------------------
 * Small internal helpers
 * -------------------- */

    protected function order_result_code_ok(DistributorOrderResult $res): bool
    {
        // Use code, not $res->ok (dry_run has code=OK but ok=false).
        return ($res->code === DistributorOrderResult::CODE_OK);
    }

    /** @param string[] $a @param string[] $b @return string[] */
    protected function merge_external_ids(array $a, array $b): array
    {
        $out = $a;
        foreach ($b as $id) {
            $id = trim((string) $id);
            if ($id !== '' && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** @param string[] $msgs */
    protected function join_msgs(array $msgs, int $max = 8): string
    {
        $msgs = array_values(array_filter(array_map('strval', $msgs), function ($m) {
            return trim($m) !== '';
        }));

        $head = array_slice($msgs, 0, max(1, $max));
        $s = implode(' | ', $head);

        if (count($msgs) > $max) {
            $s .= ' | ...';
        }

        return $s;
    }




    //SANITIZE HELPERS SECTION
    /**
     * Sanitize a merchant PO / reference string into a conservative "safe" format.
     *
     * Philosophy:
     * - Be transparent (minimal mutation).
     * - Keep only characters that are broadly accepted across distributor APIs.
     * - Do NOT re-shape the string (no dash insertion, no truncation).
     *
     * Default allowed chars: A-Z, 0-9, dash.
     * Override allowed pattern per distributor if needed.
     */
    protected function sanitize_po(
        string $s,
        string $allowed_regex = '/[^A-Z0-9\-]/',
        bool $uppercase = true
    ): string {
        $s = trim($s);
        if ($s === '') {
            return '';
        }

        if ($uppercase) {
            $s = strtoupper($s);
        }

        $s = preg_replace($allowed_regex, '', $s);
        return is_string($s) ? $s : '';
    }
}

