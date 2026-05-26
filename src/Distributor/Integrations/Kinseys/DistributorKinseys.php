<?php

namespace FFLHub\Distributor\Integrations\Kinseys;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorProductPayload;

/**
 * Kinsey's runtime distributor.
 */
final class DistributorKinseys extends DistributorBase
{
    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        return $this->build_payload_from_local_row($upc, true);
    }

    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
        return $this->build_payload_from_local_row($upc, false);
    }

    /**
     * @param array<int,string> $upcs
     * @return array<string,DistributorProductPayload>
     */
    public function get_pricing_payloads_by_upcs(array $upcs): array
    {
        return $this->get_local_pricing_payloads_by_upcs(
            $upcs,
            self::payload_field_map(),
            static fn($raw_item_type): ?array => null,
            true,
            static function (DistributorProductPayload $payload, array $row, string $normalized_upc): DistributorProductPayload {
                $payload->description = '';
                return $payload;
            }
        );
    }

    private function build_payload_from_local_row(string $upc, bool $include_images): ?DistributorProductPayload
    {
        if (!$this->services) {
            return null;
        }

        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $table = $this->services->get_fulfillment_table();
        $row = $table->get_row_by_upc($normalized_upc);
        if (!$row || !is_array($row)) {
            return null;
        }

        $payload = $this->build_payload_from_row(
            $row,
            self::payload_field_map(),
            static fn($raw_item_type): ?array => null,
            $normalized_upc,
            $include_images
        );

        $payload->description = '';

        return $payload;
    }

    /**
     * @return array<string,array<int,string>>
     */
    private static function payload_field_map(): array
    {
        return [
            'sku' => ['kinseys_product_id', 'north_item_number', 'south_item_number', 'vendor_item_number'],
            'upc' => ['upc'],
            'name' => ['product_name', 'description_1'],
            'brand' => ['manufacturer'],
            'price' => ['distributor_price', 'unit_price'],
            'map' => ['retail_map'],
            'msrp' => ['retail_msrp'],
            'quantity' => ['inventory_quantity'],
            'category' => ['product_group_code', 'item_category_code'],
            'shipping_weight' => ['shipping_weight'],
            'shipping_length_in' => ['shipping_length_in'],
            'shipping_width_in' => ['shipping_width_in'],
            'shipping_height_in' => ['shipping_height_in'],
            'ffl_required' => ['ffl_required'],
            'sot_required' => ['sot_required'],
            'dropship_enabled' => ['dropship_enabled'],
        ];
    }
}
