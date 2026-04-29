<?php

namespace FFLHub\Distributor\Integrations\MGE;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Product\CategorySchema;

/**
 * MGE runtime distributor scaffold.
 *
 * Behavior is intentionally conservative until catalog/order/shipment flows
 * are implemented.
 */
final class DistributorMGE extends DistributorBase
{
    private const DEFAULT_FLAT_SHIPPING_COST = 15.0;

    public function __construct(DistributorModuleInterface $module, $services = null)
    {
        parent::__construct($module, $services);
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
            'label'              => 'MGE validation (local)',
            'max_unique'         => 100,
            'inventory_keys'     => ['inventory_quantity', 'qty', 'quantity', 'available', 'on_hand'],
            'unknown_qty_blocks' => true,
            'code_prefix'        => 'MGE',
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
            'MGE Wholesale ordering is not implemented yet. Use dealer-fulfilled/manual ordering for MGE.',
            [DistributorOrderResult::REASON_MANUAL_REQUIRED]
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
            'fflhub_mge_flat_shipping_cost',
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
                'sku'              => ['mge_item_number', 'vendor_item_number', 'sku'],
                'upc'              => ['upc'],
                'name'             => ['model'],
                'description'      => ['product_description'],
                'brand'            => ['manufacturer'],
                'price'            => ['distributor_price'],
                'map'              => ['retail_map'],
                'msrp'             => ['retail_msrp'],
                'quantity'         => ['inventory_quantity'],
                'category'         => ['item_type', 'sub_category'],
                'image'            => ['image_url'],
                'ffl_required'     => ['ffl_required'],
                'sot_required'     => ['sot_required'],
                'dropship_enabled' => ['dropship_enabled'],
            ],
            static fn($raw): ?array => self::map_mge_category((string) $raw),
            $lookup['normalized_upc'],
            $include_images
        );

        // Per MGE feed policy, keep this false unless SIG approval explicitly opts it into dropship treatment.
        $payload->dropship_enabled = SigDropshipApproval::should_force_row($this->get_id(), $lookup['row']);

        return $payload;
    }

    /**
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
            'row' => $row,
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

    /**
     * @return array<int,string>|null
     */
    private static function map_mge_category(string $raw): ?array
    {
        $c = strtoupper(trim($raw));
        if ($c === '') {
            return null;
        }

        if (strpos($c, 'SUPPRESS') !== false || strpos($c, 'SILENCER') !== false || strpos($c, 'NFA') !== false) {
            return [CategorySchema::CAT_NFA];
        }

        if (strpos($c, 'MAGAZ') !== false) {
            return [CategorySchema::CAT_MAGAZINES];
        }

        if (strpos($c, 'AMMO') !== false) {
            return [CategorySchema::CAT_AMMO];
        }

        if (strpos($c, 'OPTIC') !== false || strpos($c, 'SCOPE') !== false || strpos($c, 'RED DOT') !== false || strpos($c, 'BINOC') !== false || strpos($c, 'RANGE') !== false) {
            return [CategorySchema::CAT_OPTICS];
        }

        if (strpos($c, 'LIGHT') !== false || strpos($c, 'LASER') !== false) {
            return [CategorySchema::CAT_LIGHTS];
        }

        if (strpos($c, 'LESS LETHAL') !== false || strpos($c, 'TASER') !== false || strpos($c, 'PEPPER') !== false) {
            return [CategorySchema::CAT_LESS_LETHAL];
        }

        if (strpos($c, 'BLACK POWDER') !== false || strpos($c, 'MUZZLE') !== false) {
            return [CategorySchema::CAT_BLACK_POWDER];
        }

        if (strpos($c, 'REVOLVER') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'];
        }

        if (strpos($c, 'PISTOL') !== false || strpos($c, 'HANDGUN') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'];
        }

        if (strpos($c, 'RIFLE') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Rifles'];
        }

        if (strpos($c, 'SHOTGUN') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Shotguns'];
        }

        if (strpos($c, 'FIREARM') !== false || strpos($c, 'RECEIVER') !== false || strpos($c, 'FRAME') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Other / Specialty'];
        }

        return null;
    }
}
