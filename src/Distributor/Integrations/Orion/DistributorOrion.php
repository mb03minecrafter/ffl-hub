<?php

namespace FFLHub\Distributor\Integrations\Orion;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;

/**
 * Runtime placeholder for the Orion integration.
 *
 * Product import, order placement, and shipment tracking will be added behind
 * this module once the settings scaffold is in place.
 */
final class DistributorOrion extends DistributorBase
{
    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        return $this->build_payload_from_local_row($upc, true);
    }

    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
        return $this->build_payload_from_local_row($upc, false);
    }

    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        return DistributorOrderResult::manual(
            'Orion ordering is not implemented yet.',
            [DistributorOrderResult::REASON_MANUAL_REQUIRED]
        );
    }

    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        return null;
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
            'label' => 'Orion validation (local)',
            'max_unique' => 100,
            'inventory_keys' => ['inventory_quantity'],
            'unknown_qty_blocks' => true,
            'code_prefix' => 'ORION',
        ];
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

        $payload = $this->build_payload_from_row(
            $row,
            [
                'sku' => ['orion_product_code', 'orion_product_id'],
                'upc' => ['upc'],
                'name' => ['product_name', 'model'],
                'description' => ['product_description', 'product_name'],
                'brand' => ['manufacturer'],
                'price' => ['distributor_price', 'sale_price', 'base_cost'],
                'map' => ['retail_map'],
                'msrp' => ['retail_msrp'],
                'quantity' => ['inventory_quantity'],
                'category' => ['product_categories', 'item_type'],
                'shipping_weight' => ['shipping_weight'],
                'shipping_length_in' => ['shipping_length_in'],
                'shipping_width_in' => ['shipping_width_in'],
                'shipping_height_in' => ['shipping_height_in'],
                'image' => ['image_url'],
                'ffl_required' => ['ffl_required'],
                'sot_required' => ['sot_required'],
                'dropship_enabled' => ['dropship_enabled'],
            ],
            static fn($raw_category): ?array => DistributorProductCategoryMapper::map_orion((string) $raw_category),
            $normalized_upc,
            $include_images
        );

        $short_name = trim((string) ($row['product_name'] ?? ''));
        if ($short_name !== '') {
            $payload->name = $short_name;
        }

        if ($include_images) {
            foreach ($this->image_urls_from_row($row) as $url) {
                $payload->add_image_url($url);
            }
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $row
     * @param mixed $field
     */
    protected function get_image_url_from_row(array $row, $field): string
    {
        foreach ($this->image_urls_from_row($row) as $url) {
            return $url;
        }

        return '';
    }

    /**
     * @param array<string,mixed> $row
     * @return string[]
     */
    private function image_urls_from_row(array $row): array
    {
        $urls = [];

        $primary = trim((string) ($row['image_url'] ?? ''));
        if ($primary !== '') {
            $urls[$primary] = $primary;
        }

        $json = trim((string) ($row['image_urls_json'] ?? ''));
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    $url = trim((string) $entry);
                    if ($url !== '') {
                        $urls[$url] = $url;
                    }
                }
            }
        }

        return array_values($urls);
    }
}
