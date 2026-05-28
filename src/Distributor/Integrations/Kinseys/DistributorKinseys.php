<?php

namespace FFLHub\Distributor\Integrations\Kinseys;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Distributor\Services\Kinseys\API\KinseysApiClient;
use FFLHub\Settings\Options;

/**
 * Kinsey's runtime distributor.
 */
final class DistributorKinseys extends DistributorBase
{
    private const ORDER_TIMEOUT_SECONDS = 120;
    private const PURCHASE_ORDER_MAX_LEN = 20;
    private const VALIDATE_MAX_UNIQUE_ITEMS = 75;
    private const SUCCESS_LINE_STATUS = '7200';

    /** @var KinseysApiClient|null */
    private $order_client = null;

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
            [DistributorProductCategoryMapper::class, 'map_kinseys'],
            true,
            static function (DistributorProductPayload $payload, array $row, string $normalized_upc): DistributorProductPayload {
                $payload->description = '';
                return $payload;
            }
        );
    }

    protected function validation_max_unique_items(DistributorOrderRequest $request, bool $local_only): int
    {
        return self::VALIDATE_MAX_UNIQUE_ITEMS;
    }

    protected function validation_services_missing_code(): string
    {
        return 'KINSEYS_SERVICES_MISSING';
    }

    protected function validation_services_missing_message(): string
    {
        return 'Kinsey\'s services not available; cannot access fulfillment table.';
    }

    protected function validation_precheck_invariants(DistributorOrderRequest $request, bool $local_only): ?DistributorOrderValidationResult
    {
        $ffl_check = $this->require_ffl_shipto_if_ffl_lines($request, 'KINSEYS');
        if ($ffl_check instanceof DistributorOrderValidationResult) {
            return $ffl_check;
        }

        $lane = strtolower(trim((string) $request->lane));
        if ($lane === 'dealer_fulfilled') {
            return DistributorOrderValidationResult::block(
                'Kinsey\'s validation failed: dealer-fulfilled ordering is not supported for Kinsey\'s API orders.',
                ['KINSEYS_DEALER_FULFILLED_NOT_SUPPORTED']
            );
        }

        $ffl_lines = (array) $request->ffl_required_lines();
        $non_ffl_lines = (array) $request->non_ffl_required_lines();

        if ($lane === '' && !empty($ffl_lines) && !empty($non_ffl_lines)) {
            return DistributorOrderValidationResult::block(
                'Kinsey\'s validation failed: mixed FFL and non-FFL lines require separate scoped jobs; full order or no split order.',
                ['KINSEYS_MIXED_LANES_NOT_ALLOWED']
            );
        }

        if ($lane === 'direct_ship_ffl' && !empty($non_ffl_lines)) {
            return DistributorOrderValidationResult::block(
                'Kinsey\'s validation failed: direct_ship_ffl contains non-FFL lines.',
                ['KINSEYS_LANE_MISMATCH']
            );
        }

        if ($lane === 'direct_ship_non_ffl' && !empty($ffl_lines)) {
            return DistributorOrderValidationResult::block(
                'Kinsey\'s validation failed: direct_ship_non_ffl contains FFL lines.',
                ['KINSEYS_LANE_MISMATCH']
            );
        }

        return null;
    }

    protected function validation_local_options(
        DistributorOrderRequest $request,
        array $required_by_upc,
        bool $local_only
    ): array {
        $lane = $this->infer_lane_and_ffl_enforcement($request);

        return [
            'label' => 'Kinsey\'s validation (local)',
            'max_unique' => self::VALIDATE_MAX_UNIQUE_ITEMS,
            'inventory_keys' => ['inventory_quantity'],
            'unknown_qty_blocks' => true,
            'lane' => $lane['lane'],
            'enforce_ffl_required' => $lane['enforce_ffl_required'],
            'ffl_required_row_keys' => ['ffl_required'],
            'code_prefix' => 'KINSEYS',
            'extra_row_checks' => function (array $row, string $normalized_upc, int $requiredQty): array {
                $product_id = trim((string) ($this->get_string_field($row, [
                    'kinseys_product_id',
                    'north_item_number',
                    'south_item_number',
                ]) ?? ''));

                if ($product_id === '') {
                    return [
                        'ok' => false,
                        'message' => 'missing Kinsey\'s product id',
                        'details' => ['kinseys_product_id' => null],
                    ];
                }

                if ($this->to_boolish($row['blocked_flag'] ?? false, false)) {
                    return [
                        'ok' => false,
                        'message' => 'blocked by Kinsey\'s',
                        'details' => ['blocked_flag' => 1],
                    ];
                }

                if ($this->to_boolish($row['inactive_flag'] ?? false, false)) {
                    return [
                        'ok' => false,
                        'message' => 'inactive at Kinsey\'s',
                        'details' => ['inactive_flag' => 1],
                    ];
                }

                if (
                    !$this->to_boolish($row['dropship_enabled'] ?? false, false) ||
                    $this->to_boolish($row['cannot_dropship'] ?? false, false)
                ) {
                    return [
                        'ok' => false,
                        'message' => 'not drop-ship eligible',
                        'details' => [
                            'dropship_enabled' => $this->to_boolish($row['dropship_enabled'] ?? false, false) ? 1 : 0,
                            'cannot_dropship' => $this->to_boolish($row['cannot_dropship'] ?? false, false) ? 1 : 0,
                        ],
                    ];
                }

                return [
                    'ok' => true,
                    'message' => 'OK',
                    'details' => ['kinseys_product_id' => $product_id],
                ];
            },
        ];
    }

    protected function supports_ordering(): bool
    {
        return true;
    }

    protected function place_order_stop_on_first_failure(): bool
    {
        return true;
    }

    protected function place_order_precheck(DistributorOrderRequest $request): ?DistributorOrderResult
    {
        $base = parent::place_order_precheck($request);
        if ($base instanceof DistributorOrderResult) {
            return $base;
        }

        $client = $this->make_order_client();
        if (!$client->has_credentials()) {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s: missing API Identifier or API key.',
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS]
            );
        }
        $this->order_client = $client;

        $po = $this->build_purchase_order_no((string) $request->merchant_order_id);
        if ($po === '') {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s: missing merchant purchase order number.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        $lane = strtolower(trim((string) $request->lane));
        if ($lane === 'dealer_fulfilled') {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s: dealer-fulfilled ordering is not supported by the Kinsey\'s API integration.',
                [DistributorOrderResult::REASON_FATAL_NOT_IMPLEMENTED]
            );
        }

        $ffl_lines = (array) $request->ffl_required_lines();
        $non_ffl_lines = (array) $request->non_ffl_required_lines();

        if ($lane === '' && !empty($ffl_lines) && !empty($non_ffl_lines)) {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s: mixed FFL and non-FFL lines are not allowed in one unscoped order; full order or no order.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        if ($lane === 'direct_ship_ffl' && !empty($non_ffl_lines)) {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s: direct_ship_ffl request contains non-FFL lines; full order or no split order.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        if ($lane === 'direct_ship_non_ffl' && !empty($ffl_lines)) {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s: direct_ship_non_ffl request contains FFL lines; full order or no split order.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        if (($lane === 'direct_ship_ffl' || ($lane === '' && !empty($ffl_lines)))) {
            $ffl_check = $this->require_kinseys_ffl_ship_to($request, []);
            if ($ffl_check instanceof DistributorOrderResult) {
                return $ffl_check;
            }
        }

        if ($lane === 'direct_ship_non_ffl' || ($lane === '' && !empty($non_ffl_lines))) {
            $ship_check = self::validate_shipto_minimum($request->ship_to_customer);
            if (empty($ship_check['ok'])) {
                return DistributorOrderResult::block_fatal(
                    'Kinsey\'s non-FFL ship-to invalid: ' . (string) ($ship_check['message'] ?? 'missing required fields'),
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
                );
            }
        }

        return null;
    }

    protected function place_order_lane(
        DistributorOrderRequest $request,
        string $lane,
        array $lines,
        array &$external_ids
    ): DistributorOrderResult {
        $lane = strtolower(trim((string) $lane));
        if ($lane !== 'direct_ship_non_ffl' && $lane !== 'direct_ship_ffl') {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s: unsupported order lane "' . $lane . '".',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $required_by_upc = $this->build_required_qty_by_upc($lines);
        $local_validation = $this->validate_local_fulfillment_required_qty_by_upc(
            $lines,
            $this->validation_local_options($request, $required_by_upc, true)
        );
        if (empty($local_validation->ok)) {
            return $this->order_result_from_validation_failure($local_validation, $external_ids);
        }

        $items = $this->build_kinseys_sales_lines($lines, true);
        if ($items instanceof DistributorOrderResult) {
            $items->external_order_ids = $external_ids;
            return $items;
        }

        if (empty($items)) {
            return DistributorOrderResult::block_fatal(
                'No valid Kinsey\'s line items after normalization.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $po = $this->build_purchase_order_no((string) $request->merchant_order_id);
        if ($po === '') {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s: missing merchant purchase order number.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $ship_to = ($lane === 'direct_ship_ffl') ? $request->ship_to_ffl : $request->ship_to_customer;
        if (!$ship_to instanceof DistributorShipTo) {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s: missing ship-to for lane=' . $lane . '.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $ship_check = self::validate_shipto_minimum($ship_to);
        if (empty($ship_check['ok'])) {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s ship-to invalid: ' . (string) ($ship_check['message'] ?? 'missing required fields'),
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $payload = [
            'purchaseOrderNo' => $po,
            'options' => [
                'backOrdersAllowed' => false,
                'splitOrdersAllowed' => false,
            ],
            'shipTo' => $this->build_kinseys_ship_to_payload($ship_to),
            'salesLines' => $items,
        ];

        $comment = self::truncate_string((string) $request->notes, 250);
        if ($comment !== '') {
            $payload['comment'] = $comment;
        }

        if ($lane === 'direct_ship_ffl') {
            $ffl_check = $this->require_kinseys_ffl_ship_to($request, $external_ids);
            if ($ffl_check instanceof DistributorOrderResult) {
                return $ffl_check;
            }

            /** @var DistributorShipTo $ffl */
            $ffl = $request->ship_to_ffl;
            $payload['fflInfo'] = $this->build_kinseys_ffl_payload($ffl, (string) $request->receiving_ffl_number);
        }

        if ($this->is_test_order_debug_enabled()) {
            return $this->build_test_order_debug_block(
                $lane,
                $this->kinseys_endpoint_url('SalesOrder'),
                'POST',
                'json',
                $this->encode_debug_json_payload($payload),
                [
                    'po' => $po,
                    'item_count' => count($items),
                    'backOrdersAllowed' => 0,
                    'splitOrdersAllowed' => 0,
                ],
                $external_ids
            );
        }

        $client = $this->order_client instanceof KinseysApiClient ? $this->order_client : $this->make_order_client();
        $response = $client->create_sales_order($payload);

        if (empty($response['ok'])) {
            $result = $this->classify_kinseys_order_failure($response, 'Kinsey\'s SalesOrder', $po, $external_ids);
            $result->external_order_ids = $external_ids;
            return $result;
        }

        return $this->normalize_kinseys_sales_order_success($response, $po, $lane, count($items), $external_ids);
    }

    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        $po = $this->build_purchase_order_no($po_number);
        if ($po === '') {
            return null;
        }

        $client = $this->make_order_client();
        if (!$client->has_credentials()) {
            return null;
        }

        $response = $client->get_shipments_by_purchase_order($po);
        if (empty($response['ok'])) {
            return null;
        }

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $packages = $this->normalize_array_rows($data['packages'] ?? $data['Packages'] ?? []);
        if (empty($packages)) {
            return null;
        }

        $tracking_numbers = [];
        $package_numbers = [];
        $sales_order_numbers = [];
        $shipping_service = null;
        $normalized_packages = [];

        foreach ($packages as $package) {
            if (is_object($package)) {
                $package = get_object_vars($package);
            }
            if (!is_array($package)) {
                continue;
            }

            $normalized_packages[] = $package;

            $tracking = $this->array_string($package, ['trackingNo', 'TrackingNo', 'tracking_number']);
            if ($tracking !== '') {
                $tracking_numbers[] = $tracking;
            }

            $package_no = $this->array_string($package, ['packageNo', 'PackageNo', 'package_number']);
            if ($package_no !== '') {
                $package_numbers[] = $package_no;
            }

            $sales_order_no = $this->array_string($package, ['salesOrderNo', 'SalesOrderNo', 'sales_order_no']);
            if ($sales_order_no !== '') {
                $sales_order_numbers[] = $sales_order_no;
            }

            if ($shipping_service === null) {
                $carrier = $this->array_string($package, ['carrierCode', 'CarrierCode', 'carrier_code']);
                $service = $this->array_string($package, ['serviceCode', 'ServiceCode', 'service_code']);
                $service_raw = trim($carrier . ($carrier !== '' && $service !== '' ? ' ' : '') . $service);
                if ($service_raw !== '') {
                    $carrier_norm = $this->normalize_carrier($service_raw);
                    $shipping_service = $carrier_norm !== null ? $carrier_norm : $service_raw;
                }
            }
        }

        $tracking_numbers = array_values(array_unique($tracking_numbers));
        if (empty($tracking_numbers)) {
            return null;
        }

        return new DistributorShipment(
            $tracking_numbers,
            array_values(array_unique($package_numbers)),
            $shipping_service,
            null,
            [
                'po_number' => $po,
                'sales_order_numbers' => array_values(array_unique($sales_order_numbers)),
                'packages' => $normalized_packages,
            ]
        );
    }

    /**
     * @param DistributorOrderLine[] $lines
     * @return array<int,array{productId:string,quantity:int}>|DistributorOrderResult
     */
    private function build_kinseys_sales_lines(array $lines, bool $allow_empty = false)
    {
        return $this->map_order_lines_to_items(
            $lines,
            function (string $normalized_upc, string $raw_upc, DistributorOrderLine $line): ?string {
                return $this->lookup_kinseys_product_id_by_upc($normalized_upc);
            },
            function (string $product_id, int $qty, string $normalized_upc, string $raw_upc, DistributorOrderLine $line): array {
                return [
                    'productId' => $product_id,
                    'quantity' => $qty,
                ];
            },
            'Cannot map UPC to Kinsey\'s product id: %s',
            !$allow_empty,
            'No valid Kinsey\'s line items after normalization.'
        );
    }

    private function lookup_kinseys_product_id_by_upc(string $upc): ?string
    {
        if (!$this->services) {
            return null;
        }

        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $table = $this->services->get_fulfillment_table();
        $row = $table->get_row_by_upc($normalized);
        if (!$row) {
            foreach ($this->build_common_upc_lookup_candidates($normalized) as $candidate_upc) {
                $row = $table->get_row_by_upc($candidate_upc);
                if ($row) {
                    break;
                }
            }
        }

        if (!$row || !is_array($row)) {
            return null;
        }

        $product_id = trim((string) ($this->get_string_field($row, [
            'kinseys_product_id',
            'north_item_number',
            'south_item_number',
        ]) ?? ''));

        if ($product_id === '' || strtoupper($product_id) === 'NORTH ONLY' || strtoupper($product_id) === 'SOUTH ONLY') {
            return null;
        }

        return $product_id;
    }

    /**
     * @return array<string,mixed>
     */
    private function build_kinseys_ship_to_payload(DistributorShipTo $ship_to): array
    {
        $name = trim((string) $ship_to->name);
        if ($name === '') {
            $name = trim((string) $ship_to->company);
        }

        $payload = [
            'name' => self::truncate_string($name !== '' ? $name : 'Customer', 100),
            'address' => self::truncate_string((string) $ship_to->address1, 100),
            'address2' => self::truncate_string((string) $ship_to->address2, 100),
            'city' => self::truncate_string((string) $ship_to->city, 60),
            'state' => self::format_us_state2_best_effort((string) $ship_to->state),
            'zipCode' => self::format_us_zip5_or_zip9_with_dash_for_payload((string) $ship_to->zip),
            'country' => 'US',
            'phone' => self::truncate_string((string) $ship_to->phone, 30),
        ];

        return $this->drop_empty_strings($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function build_kinseys_ffl_payload(DistributorShipTo $ffl, string $license_number): array
    {
        $name = trim((string) $ffl->name);
        $company = trim((string) $ffl->company);
        if ($name === '') {
            $name = $company !== '' ? $company : 'FFL';
        }

        $payload = [
            'licenseNumber' => self::truncate_string(strtoupper(trim($license_number)), 32),
            'name' => self::truncate_string($name, 100),
            'company' => self::truncate_string($company, 100),
            'address1' => self::truncate_string((string) $ffl->address1, 100),
            'address2' => self::truncate_string((string) $ffl->address2, 100),
            'city' => self::truncate_string((string) $ffl->city, 60),
            'state' => self::format_us_state2_best_effort((string) $ffl->state),
            'zip' => self::format_us_zip5_or_zip9_with_dash_for_payload((string) $ffl->zip),
            'phone' => self::truncate_string((string) $ffl->phone, 30),
        ];

        return $this->drop_empty_strings($payload);
    }

    /**
     * @param array<int,string> $external_ids
     */
    private function require_kinseys_ffl_ship_to(DistributorOrderRequest $request, array $external_ids): ?DistributorOrderResult
    {
        if (!($request->ship_to_ffl instanceof DistributorShipTo)) {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s FFL order missing ship_to_ffl.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $license = strtoupper(trim((string) $request->receiving_ffl_number));
        if ($license === '') {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s FFL order missing receiving FFL license number.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $ship_check = self::validate_shipto_minimum($request->ship_to_ffl);
        if (empty($ship_check['ok'])) {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s FFL ship-to invalid: ' . (string) ($ship_check['message'] ?? 'missing required fields'),
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        return null;
    }

    /**
     * @param array<string,mixed> $response
     * @param array<int,string> $external_ids
     */
    private function normalize_kinseys_sales_order_success(
        array $response,
        string $po,
        string $lane,
        int $line_count,
        array $external_ids
    ): DistributorOrderResult {
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $orders = $data['salesOrders'] ?? $data['SalesOrders'] ?? [];
        if (!is_array($orders) || empty($orders)) {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s SalesOrder returned success without any salesOrders.',
                [DistributorOrderResult::REASON_FATAL_UNKNOWN],
                [
                    'po' => $po,
                    'referenceNo' => (string) ($data['referenceNo'] ?? ''),
                    'message' => (string) ($data['message'] ?? ''),
                ],
                (int) ($response['status'] ?? 0),
                '',
                $external_ids
            );
        }

        $orders = $this->normalize_array_rows($orders);
        $sales_order_ids = [];
        $line_issues = [];
        $order_summaries = [];

        foreach ($orders as $order) {
            $sales_order_no = $this->array_string($order, ['salesOrderNo', 'SalesOrderNo', 'sales_order_no']);
            if ($sales_order_no !== '') {
                $sales_order_ids[] = $sales_order_no;
            }

            $order_lines = $order['orderLines'] ?? $order['OrderLines'] ?? [];
            $order_lines = is_array($order_lines) ? $this->normalize_array_rows($order_lines) : [];

            $order_summaries[] = [
                'salesOrderNo' => $sales_order_no,
                'purchaseOrderNo' => $this->array_string($order, ['purchaseOrderNo', 'PurchaseOrderNo']),
                'warehouse' => $this->array_string($order, ['warehouse', 'Warehouse']),
                'status' => $this->array_string($order, ['status', 'Status']),
                'line_count' => count($order_lines),
            ];

            foreach ($order_lines as $line) {
                $product_id = $this->array_string($line, ['productID', 'ProductID', 'productId', 'ProductId']);
                $status = $this->array_string($line, ['status', 'Status']);
                $message = $this->array_string($line, ['message', 'Message']);
                $qty_ordered = $this->array_int($line, ['qtyOrd', 'QtyOrd', 'quantity', 'Quantity']);
                $qty_reserved = $this->array_int($line, ['qtyRes', 'QtyRes']);
                $qty_backordered = $this->array_int($line, ['qtyBO', 'QtyBO']);
                $has_qty_reserved = $this->array_has_any_key($line, ['qtyRes', 'QtyRes']);

                if ($status !== '' && $status !== self::SUCCESS_LINE_STATUS) {
                    $line_issues[] = $product_id . ': status=' . $status . ($message !== '' ? ' ' . $message : '');
                }

                if ($qty_backordered > 0) {
                    $line_issues[] = $product_id . ': backordered=' . $qty_backordered;
                }

                if ($has_qty_reserved && $qty_ordered > 0 && $qty_reserved < $qty_ordered) {
                    $line_issues[] = $product_id . ': reserved=' . $qty_reserved . '/' . $qty_ordered;
                }
            }
        }

        $external_ids = $this->merge_external_ids($external_ids, $sales_order_ids);

        if (count($orders) > 1) {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s SalesOrder returned multiple sales orders even though splitOrdersAllowed=false.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [
                    'po' => $po,
                    'sales_orders' => $order_summaries,
                    'policy' => ['splitOrdersAllowed' => 0],
                ],
                (int) ($response['status'] ?? 0),
                '',
                $external_ids
            );
        }

        if (!empty($line_issues)) {
            return DistributorOrderResult::block_fatal(
                'Kinsey\'s SalesOrder did not fully reserve all lines: ' . $this->join_msgs($line_issues, 8),
                [DistributorOrderResult::REASON_FATAL_OUT_OF_STOCK],
                [
                    'po' => $po,
                    'sales_orders' => $order_summaries,
                    'line_issues' => $line_issues,
                    'policy' => [
                        'backOrdersAllowed' => 0,
                        'splitOrdersAllowed' => 0,
                    ],
                ],
                (int) ($response['status'] ?? 0),
                '',
                $external_ids
            );
        }

        return DistributorOrderResult::ok(
            'Kinsey\'s ' . $lane . ' order submitted.',
            $external_ids,
            [
                'po' => $po,
                'line_count' => $line_count,
                'sales_orders' => $order_summaries,
                'referenceNo' => (string) ($data['referenceNo'] ?? ''),
                'message' => (string) ($data['message'] ?? ''),
                'policy' => [
                    'backOrdersAllowed' => 0,
                    'splitOrdersAllowed' => 0,
                ],
            ]
        );
    }

    private function order_result_from_validation_failure(
        DistributorOrderValidationResult $validation,
        array $external_ids
    ): DistributorOrderResult {
        $details = [
            'validation_code' => (string) $validation->code,
            'validation_codes' => is_array($validation->codes) ? $validation->codes : [],
            'validation_details' => is_array($validation->details) ? $validation->details : [],
            'policy' => [
                'backOrdersAllowed' => 0,
                'splitOrdersAllowed' => 0,
            ],
        ];

        if (method_exists($validation, 'is_retryable') && $validation->is_retryable()) {
            return DistributorOrderResult::block_retryable(
                'Kinsey\'s local full-order validation retryable failure: ' . (string) $validation->message,
                [DistributorOrderResult::REASON_RETRY_UNKNOWN],
                $details,
                0,
                '',
                $external_ids
            );
        }

        $codes_lc = strtolower(implode(' ', is_array($validation->codes) ? $validation->codes : []));
        $reason = (strpos($codes_lc, 'out_of_stock') !== false || strpos($codes_lc, 'unknown_qty') !== false)
            ? DistributorOrderResult::REASON_FATAL_OUT_OF_STOCK
            : DistributorOrderResult::REASON_FATAL_BAD_REQUEST;

        return DistributorOrderResult::block_fatal(
            'Kinsey\'s local full-order validation failed before API submit: ' . (string) $validation->message,
            [$reason],
            $details,
            0,
            '',
            $external_ids
        );
    }

    /**
     * @param array<string,mixed> $response
     * @param array<int,string> $external_ids
     */
    private function classify_kinseys_order_failure(
        array $response,
        string $prefix,
        string $po,
        array $external_ids = []
    ): DistributorOrderResult {
        $status = (int) ($response['status'] ?? 0);
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $msg = trim((string) ($response['error'] ?? ''));
        if ($msg === '') {
            $msg = $this->first_non_empty_string($data, ['message', 'Message', 'error', 'Error', 'detail', 'Detail']);
        }
        if ($msg === '') {
            $msg = 'Unknown Kinsey\'s order error.';
        }

        $provider = $this->first_non_empty_string($data, ['referenceNo', 'ReferenceNo', 'code', 'Code']);
        $lc = strtolower($msg);
        $details = [
            'po' => $po,
            'status' => $status,
            'referenceNo' => $provider,
        ];

        $body_excerpt = trim((string) ($response['body_excerpt'] ?? ''));
        if ($body_excerpt !== '') {
            $details['body_excerpt'] = self::truncate_string($body_excerpt, 500);
        }

        if ($status === 429 || strpos($lc, 'rate') !== false || strpos($lc, 'throttle') !== false || strpos($lc, 'quota') !== false) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': rate limit: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_RATE_LIMIT],
                $details,
                $status,
                $provider,
                $external_ids
            );
        }

        if ($status === 408 || $status === 504 || strpos($lc, 'timeout') !== false || strpos($lc, 'timed out') !== false) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': timeout: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_TIMEOUT],
                $details,
                $status,
                $provider,
                $external_ids
            );
        }

        if ($status === 0 || $status >= 500 || strpos($lc, 'invalid json') !== false || strpos($lc, 'temporar') !== false) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': upstream/API error: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UPSTREAM],
                $details,
                $status,
                $provider,
                $external_ids
            );
        }

        if ($status === 401 || $status === 403 || strpos($lc, 'unauthorized') !== false || strpos($lc, 'not authorized') !== false) {
            return DistributorOrderResult::block_fatal(
                $prefix . ': authorization failed: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS],
                $details,
                $status,
                $provider,
                $external_ids
            );
        }

        if (
            strpos($lc, 'out of stock') !== false ||
            strpos($lc, 'insufficient') !== false ||
            strpos($lc, 'unavailable') !== false ||
            strpos($lc, 'backorder') !== false ||
            strpos($lc, 'back order') !== false
        ) {
            return DistributorOrderResult::block_fatal(
                $prefix . ': not fully available: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_OUT_OF_STOCK],
                $details,
                $status,
                $provider,
                $external_ids
            );
        }

        if (
            strpos($lc, 'restricted') !== false ||
            strpos($lc, 'prohibited') !== false ||
            strpos($lc, 'blocked') !== false ||
            strpos($lc, 'not allowed') !== false
        ) {
            return DistributorOrderResult::block_fatal(
                $prefix . ': restricted: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_RESTRICTED],
                $details,
                $status,
                $provider,
                $external_ids
            );
        }

        return DistributorOrderResult::block_fatal(
            $prefix . ': failed: ' . $msg,
            [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
            $details,
            $status,
            $provider,
            $external_ids
        );
    }

    private function make_order_client(): KinseysApiClient
    {
        $api_identifier = Options::get_distributor_option('kinseys', 'api_identifier', '');
        $api_key = Options::get_distributor_option('kinseys', 'api_key', '');
        $source = Options::get_distributor_option('kinseys', 'source', 'FFLHub');
        $base_url = (string) apply_filters('fflhub_kinseys_api_base_url', KinseysApiClient::DEFAULT_BASE_URL);
        $timeout = (int) apply_filters('fflhub_kinseys_order_timeout_seconds', self::ORDER_TIMEOUT_SECONDS);
        $timeout = max(30, min(300, $timeout));

        return new KinseysApiClient($api_identifier, $api_key, $source, $base_url, $timeout);
    }

    private function kinseys_endpoint_url(string $path): string
    {
        $base_url = (string) apply_filters('fflhub_kinseys_api_base_url', KinseysApiClient::DEFAULT_BASE_URL);
        return trailingslashit($base_url) . ltrim($path, '/');
    }

    private function build_purchase_order_no(string $po): string
    {
        $po = $this->sanitize_po($po);
        if ($po === '') {
            return '';
        }

        return self::truncate_string($po, self::PURCHASE_ORDER_MAX_LEN);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function drop_empty_strings(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    /**
     * @param mixed $rows
     * @return array<int,array<string,mixed>>
     */
    private function normalize_array_rows($rows): array
    {
        if (is_object($rows)) {
            $rows = get_object_vars($rows);
        }

        if (!is_array($rows)) {
            return [];
        }

        if (!$this->is_list_array($rows)) {
            return [$rows];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $row = get_object_vars($row);
            }
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * PHP 7-compatible array_is_list.
     *
     * @param array<mixed> $items
     */
    private function is_list_array(array $items): bool
    {
        $expected = 0;
        foreach (array_keys($items) as $key) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     */
    private function array_string(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     */
    private function array_int(array $row, array $keys): int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_numeric((string) $row[$key])) {
                return (int) $row[$key];
            }
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     */
    private function first_non_empty_string(array $row, array $keys): string
    {
        return $this->array_string($row, $keys);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     */
    private function array_has_any_key(array $row, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return true;
            }
        }

        return false;
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
        if (!$row) {
            foreach ($this->build_common_upc_lookup_candidates($normalized_upc) as $candidate_upc) {
                $row = $table->get_row_by_upc($candidate_upc);
                if ($row) {
                    break;
                }
            }
        }
        if (!$row || !is_array($row)) {
            return null;
        }

        $payload = $this->build_payload_from_row(
            $row,
            self::payload_field_map(),
            [DistributorProductCategoryMapper::class, 'map_kinseys'],
            $normalized_upc,
            $include_images
        );

        $payload->description = $include_images ? $this->build_product_description($row) : '';

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

        if ($url === '' && !empty($row['image_urls_json'])) {
            $urls = json_decode((string) $row['image_urls_json'], true);
            if (is_array($urls)) {
                foreach ($urls as $candidate) {
                    $candidate = trim((string) $candidate);
                    if ($candidate !== '') {
                        $url = $candidate;
                        break;
                    }
                }
            }
        }

        if ($url === '') {
            return '';
        }

        if (stripos($url, 'http://') === 0) {
            $url = 'https://' . substr($url, 7);
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function build_product_description(array $row): string
    {
        $description = trim((string) ($this->get_string_field($row, ['product_description']) ?? ''));
        $features = trim((string) ($this->get_string_field($row, ['bullet_features']) ?? ''));

        if ($description === '') {
            return $features;
        }

        if ($features === '' || stripos($description, $features) !== false) {
            return $description;
        }

        return $description . "\n\n" . $features;
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
            'description' => ['description_2', 'description_1'],
            'brand' => ['manufacturer'],
            'price' => ['distributor_price', 'unit_price'],
            'map' => ['retail_map'],
            'msrp' => ['retail_msrp'],
            'quantity' => ['inventory_quantity'],
            'category' => ['product_categories', 'item_type', 'product_group_code', 'item_category_code'],
            'shipping_weight' => ['shipping_weight'],
            'shipping_length_in' => ['shipping_length_in'],
            'shipping_width_in' => ['shipping_width_in'],
            'shipping_height_in' => ['shipping_height_in'],
            'image' => ['image_url'],
            'ffl_required' => ['ffl_required'],
            'sot_required' => ['sot_required'],
            'dropship_enabled' => ['dropship_enabled'],
        ];
    }
}
