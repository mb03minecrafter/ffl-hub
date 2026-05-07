<?php

namespace FFLHub\Distributor\Integrations\SportsSouth;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Product\CategorySchema;

/**
 * Sports South runtime distributor backed by the local catalog table.
 */
final class DistributorSportsSouth extends DistributorBase
{
    public function __construct(DistributorModuleInterface $module, $services = null)
    {
        parent::__construct($module, $services);
    }

    public function get_offer_by_upc(string $upc, bool $include_images = true): ?DistributorOffer
    {
        if (!self::lookup_offers_enabled()) {
            return null;
        }

        return parent::get_offer_by_upc($upc, $include_images);
    }

    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        return $this->build_payload_from_local_row($upc, true);
    }

    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
        return $this->build_payload_from_local_row($upc, false);
    }

    protected function supports_remote_validation(): bool
    {
        return false;
    }

    /**
     * @param array<string,int> $required_by_upc
     * @return array<string,mixed>
     */
    protected function validation_local_options(
        DistributorOrderRequest $request,
        array $required_by_upc,
        bool $local_only
    ): array {
        return [
            'label' => 'Sports South validation (local)',
            'max_unique' => 100,
            'inventory_keys' => ['inventory_quantity'],
            'unknown_qty_blocks' => true,
            'code_prefix' => 'SPORTS_SOUTH',
            'ffl_required_row_keys' => ['ffl_required'],
        ];
    }

    public function validate_order_request(
        DistributorOrderRequest $request,
        bool $local_only = false
    ): DistributorOrderValidationResult {
        return parent::validate_order_request($request, $local_only);
    }

    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        return DistributorOrderResult::manual(
            'Sports South ordering is not implemented yet. Use manual ordering for Sports South rows.',
            [DistributorOrderResult::REASON_MANUAL_REQUIRED]
        );
    }

    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        return null;
    }

    private static function lookup_offers_enabled(): bool
    {
        return (bool) apply_filters('fflhub_sports_south_expose_lookup_offers', false);
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $cost = apply_filters('fflhub_sports_south_flat_shipping_cost', 0.0, $normalized, $this);

        return is_numeric($cost) ? max(0.0, (float) $cost) : 0.0;
    }

    private function build_payload_from_local_row(string $upc, bool $includeImages): ?DistributorProductPayload
    {
        if (!$this->services) {
            return null;
        }

        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
        if (!$row) {
            return null;
        }
        if (!is_array($row)) {
            if (!is_object($row)) {
                return null;
            }
            $row = get_object_vars($row);
        }

        return $this->build_payload_from_row(
            $row,
            [
                'sku' => ['sports_south_item_number'],
                'upc' => ['upc'],
                'name' => ['product_name', 'model'],
                'description' => ['product_description', 'product_name'],
                'brand' => ['manufacturer'],
                'price' => ['distributor_price', 'catalog_price'],
                'map' => ['retail_map'],
                'msrp' => ['retail_msrp'],
                'quantity' => ['inventory_quantity'],
                'category' => ['item_type', 'category_id', 'product_name'],
                'shipping_weight' => ['shipping_weight'],
                'shipping_length_in' => ['shipping_length_in'],
                'shipping_width_in' => ['shipping_width_in'],
                'shipping_height_in' => ['shipping_height_in'],
                'image' => ['image_url', 'image_ref'],
                'ffl_required' => ['ffl_required'],
                'sot_required' => ['sot_required'],
                'dropship_enabled' => ['dropship_enabled'],
            ],
            static fn($raw): ?array => self::map_sports_south_category((string) $raw),
            $normalized_upc,
            $includeImages
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param mixed $field
     */
    protected function get_image_url_from_row(array $row, $field): string
    {
        $keys = is_array($field) ? $field : [$field];
        foreach ($keys as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array<int,string>|null
     */
    private static function map_sports_south_category(string $raw): ?array
    {
        $c = strtoupper(trim((string) preg_replace('/\s+/', ' ', $raw)));
        if ($c === '') {
            return null;
        }

        if (strpos($c, 'SUPPRESS') !== false || strpos($c, 'SILENC') !== false || strpos($c, 'NFA') !== false) {
            return [CategorySchema::CAT_NFA];
        }
        if (strpos($c, 'BLACK POWDER') !== false || strpos($c, 'MUZZLE') !== false) {
            return [CategorySchema::CAT_BLACK_POWDER];
        }
        if (strpos($c, 'MAGAZ') !== false) {
            return [CategorySchema::CAT_MAGAZINES];
        }
        if (strpos($c, 'AMMO') !== false || strpos($c, 'AMMUNITION') !== false) {
            return [CategorySchema::CAT_AMMO];
        }
        if (strpos($c, 'OPTIC') !== false || strpos($c, 'SCOPE') !== false || strpos($c, 'SIGHT') !== false || strpos($c, 'BINOC') !== false || strpos($c, 'RANGE') !== false) {
            return [CategorySchema::CAT_OPTICS];
        }
        if (strpos($c, 'LIGHT') !== false || strpos($c, 'LASER') !== false) {
            return [CategorySchema::CAT_LIGHTS];
        }
        if (strpos($c, 'LESS LETHAL') !== false || strpos($c, 'TASER') !== false || strpos($c, 'PEPPER') !== false) {
            return [CategorySchema::CAT_LESS_LETHAL];
        }
        if (strpos($c, 'REVOLVER') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'];
        }
        if (strpos($c, 'PISTOL') !== false || strpos($c, 'HANDGUN') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'];
        }
        if (strpos($c, 'SHOTGUN') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Shotguns'];
        }
        if (strpos($c, 'RIFLE') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Rifles'];
        }
        if (strpos($c, 'FIREARM') !== false || strpos($c, 'RECEIVER') !== false || strpos($c, 'FRAME') !== false || strpos($c, 'LOWER') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Other / Specialty'];
        }

        return null;
    }
}
