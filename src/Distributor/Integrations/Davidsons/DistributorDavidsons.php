<?php

namespace FFLHub\Distributor\Integrations\Davidsons;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Util\DebugLogUtil;

/**
 * Davidson's runtime distributor.
 *
 * Rules:
 * - Ordering is manual-only (API placement intentionally blocked).
 * - Shipment lookup by PO is not supported (manual flow).
 * - Validation is local-table only (no remote validation API).
 * - Product lookup is fulfilled from local Davidson's table by UPC.
 */
final class DistributorDavidsons extends DistributorBase
{
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
            'label'              => "Davidson's validation (local)",
            'max_unique'         => 100,
            'inventory_keys'     => ['inventory_quantity', 'qty', 'quantity', 'available', 'on_hand'],
            'unknown_qty_blocks' => true,
            'code_prefix'        => 'DAVIDSONS',
        ];
    }

    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        return DistributorOrderResult::block_fatal(
            'Davidsons only supports manual ordering.',
            [DistributorOrderResult::REASON_FATAL_NOT_IMPLEMENTED]
        );
    }

    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        return null;
    }

    private function build_payload_from_local_row(string $upc, bool $include_images): ?DistributorProductPayload
    {
        $lookup = $this->get_fulfillment_row_for_upc($upc);
        if ($lookup === null) {
            return null;
        }

        return $this->build_payload_from_row(
            $lookup['row'],
            [
                'sku'              => ['davidsons_item_number', 'sku'],
                'upc'              => ['upc'],
                'name'             => ['model'],
                'description'      => ['product_description'],
                'brand'            => ['manufacturer'],
                'price'            => ['distributor_price'],
                'map'              => ['retail_map'],
                'msrp'             => ['retail_msrp'],
                'quantity'         => ['inventory_quantity'],
                'category'         => ['item_type'],
                'shipping_weight'  => ['shipping_weight'],
                'ffl_required'     => ['ffl_required'],
                'sot_required'     => ['sot_required'],
                'dropship_enabled' => ['dropship_enabled'],
            ],
            static fn($raw_item_type): ?array => self::map_davidsons_category($raw_item_type),
            $lookup['normalized_upc'],
            $include_images
        );
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

        // Primary exact lookup.
        $row = $table->get_row_by_upc($normalized_upc);
        if (!$row) {
            // Fallbacks for common UPC formatting differences:
            // - leading zero omitted by operator entry (11-digit input)
            // - leading zero present in DB but not in search (or vice versa)
            $candidates = $this->build_upc_lookup_candidates($normalized_upc);
            foreach ($candidates as $candidate_upc) {
                $row = $table->get_row_by_upc($candidate_upc);
                if ($row) {
                    DebugLogUtil::log_ctx(
                        'FFLHUB_ADMIN_DEBUG',
                        '[FFLHub][DistributorDavidsons]',
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
     * Build non-primary UPC candidates for local-table lookups.
     *
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

    /**
     * @param mixed $raw_item_type
     * @return array<int,string>|null
     */
    private static function map_davidsons_category($raw_item_type): ?array
    {
        $item_type = trim((string) $raw_item_type);
        if ($item_type === '') {
            return null;
        }

        return DistributorProductCategoryMapper::map_davidsons($item_type);
    }
}
