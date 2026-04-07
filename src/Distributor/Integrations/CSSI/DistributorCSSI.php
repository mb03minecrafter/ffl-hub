<?php

namespace FFLHub\Distributor\Integrations\CSSI;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Util\DebugLogUtil;

/**
 * Chattanooga Shooting Supplies runtime distributor.
 *
 * Ordering is manual-only until CSSI order endpoints are integrated.
 */
final class DistributorCSSI extends DistributorBase
{
    private const DEFAULT_FLAT_SHIPPING_COST = 13.0;

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
            'label'              => 'CSSI validation (local)',
            'max_unique'         => 100,
            'inventory_keys'     => ['inventory_quantity', 'inventory', 'qty', 'quantity', 'available'],
            'unknown_qty_blocks' => true,
            'code_prefix'        => 'CSSI',
        ];
    }

    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        return DistributorOrderResult::block_fatal(
            'CSSI only supports manual ordering right now.',
            [DistributorOrderResult::REASON_FATAL_NOT_IMPLEMENTED]
        );
    }

    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        return null;
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $cost = apply_filters(
            'fflhub_cssi_flat_shipping_cost',
            self::DEFAULT_FLAT_SHIPPING_COST,
            $normalized,
            $this
        );

        return is_numeric($cost) ? (float) $cost : self::DEFAULT_FLAT_SHIPPING_COST;
    }

    private function build_payload_from_local_row(string $upc, bool $include_images): ?DistributorProductPayload
    {
        $lookup = $this->get_fulfillment_row_for_upc($upc);
        if ($lookup === null) {
            return null;
        }

        $payload = $this->build_payload_from_row(
            $lookup['row'],
            [
                'sku'              => ['cssi_item_number', 'sku'],
                'upc'              => ['upc'],
                'name'             => ['product_name', 'model', 'mfg_model_number'],
                'description'      => ['product_description'],
                'brand'            => ['manufacturer'],
                'price'            => ['distributor_price'],
                'map'              => ['retail_map'],
                'msrp'             => ['retail_msrp'],
                'quantity'         => ['inventory_quantity'],
                'category'         => ['item_type'],
                'image'            => ['image_location'],
                'shipping_weight'  => ['shipping_weight'],
                'shipping_length_in' => ['shipping_length_in'],
                'shipping_width_in'  => ['shipping_width_in'],
                'shipping_height_in' => ['shipping_height_in'],
                'ffl_required'     => ['ffl_required'],
                'sot_required'     => ['sot_required'],
                'dropship_enabled' => ['dropship_enabled'],
            ],
            static fn($raw_item_type): ?array => null,
            $lookup['normalized_upc'],
            $include_images
        );

        return $payload;
    }

    /**
     * CSSI feed already provides absolute image URLs.
     *
     * @param array<string,mixed> $row
     * @param mixed $field
     */
    protected function get_image_url_from_row(array $row, $field): string
    {
        $keys = is_array($field) ? $field : [$field];
        $url = $this->get_string_field($row, $keys);
        $url = is_string($url) ? trim($url) : '';
        if ($url === '') {
            return '';
        }

        if (stripos($url, 'http://') === 0) {
            $url = 'https://' . substr($url, 7);
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        return $url;
    }

    /**
     * @return array{row:array<string,mixed>,normalized_upc:string}|null
     */
    private function get_fulfillment_row_for_upc(string $upc): ?array
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
        if (!$row) {
            $candidates = $this->build_upc_lookup_candidates($normalized_upc);
            foreach ($candidates as $candidate_upc) {
                $row = $table->get_row_by_upc($candidate_upc);
                if ($row) {
                    DebugLogUtil::log_ctx(
                        'FFLHUB_ADMIN_DEBUG',
                        '[FFLHub][DistributorCSSI]',
                        'UPC lookup matched via fallback candidate',
                        [
                            'requested_upc' => $normalized_upc,
                            'matched_upc' => $candidate_upc,
                        ]
                    );
                    break;
                }
            }
        }

        if (!$row) {
            return null;
        }
        if (!is_array($row)) {
            if (!is_object($row)) {
                return null;
            }
            $row = get_object_vars($row);
        }

        return [
            'row'            => $row,
            'normalized_upc' => $normalized_upc,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function build_upc_lookup_candidates(string $normalized_upc): array
    {
        $candidates = [];
        $len = strlen($normalized_upc);

        if ($len === 11) {
            $candidates[] = '0' . $normalized_upc;
        } elseif ($len === 12 && strpos($normalized_upc, '0') === 0) {
            $candidates[] = substr($normalized_upc, 1);
        }

        return $candidates;
    }
}
