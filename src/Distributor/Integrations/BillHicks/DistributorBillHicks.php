<?php

namespace FFLHub\Distributor\Integrations\BillHicks;

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
 * Bill Hicks runtime distributor.
 *
 * Product lookup is backed by the local double-buffered Bill Hicks catalog
 * table. Ordering and shipment polling stay inert until the Bill Hicks order
 * API/workflow is implemented.
 */
final class DistributorBillHicks extends DistributorBase
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
            static fn($raw_category): ?array => DistributorProductCategoryMapper::map_sports_south((string) $raw_category),
            true,
            static function (DistributorProductPayload $payload, array $row, string $normalized_upc): DistributorProductPayload {
                return self::prefer_bill_hicks_catalog_name($payload, $row);
            }
        );
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $rows = $this->get_fulfillment_rows_by_upcs([$normalized], true);
        $row = $rows[$normalized] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return $this->get_shipping_cost_from_row($row, $normalized);
    }

    protected function get_shipping_cost_from_row(array $row, string $normalized_upc): ?float
    {
        $raw = trim((string) ($row['shipping_cost'] ?? ''));
        if ($raw === '' || !is_numeric($raw)) {
            return 0.0;
        }

        return max(0.0, (float) $raw);
    }

    protected function get_true_cost_by_distributor_cost_shipping_cost(float $distributor_cost, float $shipping_cost): ?float
    {
        return max(0.0, $distributor_cost) + max(0.0, $shipping_cost);
    }

    /**
     * @param array<string,mixed> $row
     */
    protected function get_image_url_from_row(array $row, $field): string
    {
        $url = trim((string) ($row['image_url'] ?? ''));
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        return DistributorOrderResult::manual(
            'Bill Hicks ordering is not implemented yet.',
            [DistributorOrderResult::REASON_MANUAL_REQUIRED]
        );
    }

    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        return null;
    }

    private function build_payload_from_local_row(string $upc, bool $include_images): ?DistributorProductPayload
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $rows = $this->get_fulfillment_rows_by_upcs([$normalized], true);
        $row = $rows[$normalized] ?? null;
        if (!is_array($row)) {
            return null;
        }

        $payload = $this->build_payload_from_row(
            $row,
            self::payload_field_map(),
            static fn($raw_category): ?array => DistributorProductCategoryMapper::map_sports_south((string) $raw_category),
            $normalized,
            $include_images
        );

        return self::prefer_bill_hicks_catalog_name($payload, $row);
    }

    /**
     * Bill Hicks provides a short product name and a longer product
     * description. Keep them separate so lookup/product creation does not turn
     * the long description into the storefront title.
     *
     * @param array<string,mixed> $row
     */
    private static function prefer_bill_hicks_catalog_name(DistributorProductPayload $payload, array $row): DistributorProductPayload
    {
        $name = trim((string) ($row['product_name'] ?? ''));
        if ($name !== '') {
            $payload->name = $name;
        }

        $description = trim((string) ($row['product_description'] ?? ''));
        if ($description !== '') {
            $payload->description = $description;
        }

        return $payload;
    }

    /**
     * @return array<string,array<int,string>>
     */
    private static function payload_field_map(): array
    {
        return [
            'sku' => ['bill_hicks_item_number'],
            'upc' => ['upc'],
            'name' => ['product_name'],
            'description' => ['product_description'],
            'brand' => ['manufacturer'],
            'price' => ['distributor_price'],
            'map' => ['retail_map'],
            'msrp' => ['retail_msrp'],
            'quantity' => ['inventory_quantity'],
            'category' => ['item_type'],
            'image' => ['image_url'],
            'shipping_weight' => ['shipping_weight'],
            'shipping_length_in' => ['shipping_length'],
            'shipping_width_in' => ['shipping_width'],
            'shipping_height_in' => ['shipping_height'],
            'ffl_required' => ['ffl_required'],
            'sot_required' => ['sot_required'],
            'dropship_enabled' => ['dropship_enabled'],
        ];
    }
}
