<?php

namespace FFLHub\Distributor\Integrations\CSSI;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Services\CSSI\API\CSSIClient;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * Chattanooga Shooting Supplies runtime distributor.
 */
final class DistributorCSSI extends DistributorBase
{
    private const DEBUG_FLAG = 'FFLHUB_CSSI_DEBUG';
    private const LOG_PREFIX = '[FFLHub][DistributorCSSI]';
    private const DEFAULT_FLAT_SHIPPING_COST = 13.0;
    private const SHIPPING_SERVICE_GROUND_ECONOMY = 'ground_economy';
    private const SHIPPING_SERVICE_HANDGUN_SECOND_DAY = 'handgun_second_day';
    private const SHIPPING_SERVICE_LONG_GUN_GROUND_PREMIUM = 'long_gun_ground_premium';
    private const SHIPPING_GROUND_ECONOMY_RATE = 8.95;
    private const SHIPPING_GROUND_ECONOMY_STEP_OZ = 128.0; // 8 lb
    private const SHIPPING_HANDGUN_SECOND_DAY_RATE = 14.95;
    private const SHIPPING_LONG_GUN_GROUND_PREMIUM_RATE = 13.95;
    private const SHIPPING_FIREARM_STEP_OZ = 480.0; // 30 lb
    private const SHIPPING_MIN_ORDER_FEE_THRESHOLD = 50.0;
    private const SHIPPING_MIN_ORDER_FEE = 7.50;
    private const SHIPPING_INSURANCE_PER_100 = 1.0;
    private const VALIDATE_MAX_UNIQUE_ITEMS = 75;
    private const CSSI_API_ORDERS_URL = 'https://api.chattanoogashooting.com/rest/v5/orders';

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

    protected function validation_max_unique_items(DistributorOrderRequest $request, bool $local_only): int
    {
        return self::VALIDATE_MAX_UNIQUE_ITEMS;
    }

    protected function validation_too_many_unique_code(): string
    {
        return 'CSSI_VALIDATE_TOO_MANY_UNIQUE';
    }

    protected function validation_services_missing_code(): string
    {
        return 'CSSI_SERVICES_MISSING';
    }

    protected function validation_services_missing_message(): string
    {
        return 'CSSI services not available; cannot access fulfillment table.';
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
            'max_unique'         => self::VALIDATE_MAX_UNIQUE_ITEMS,
            'inventory_keys'     => ['inventory_quantity'],
            'unknown_qty_blocks' => true,
            'code_prefix'        => 'CSSI',
        ];
    }

    protected function validation_precheck_invariants(DistributorOrderRequest $request, bool $local_only): ?DistributorOrderValidationResult
    {
        return $this->require_ffl_shipto_if_ffl_lines($request, 'CSSI');
    }

    /**
     * @param array<string,int> $required_by_upc
     */
    protected function validate_order_request_local(
        DistributorOrderRequest $request,
        array $required_by_upc,
        bool $local_only
    ): DistributorOrderValidationResult {
        $t0 = microtime(true);
        $traceId = $this->new_trace_id();
        $fflLineCount = count((array) $request->ffl_required_lines());

        $this->log('Validation local start', [
            'trace_id' => $traceId,
            'local_only' => $local_only ? 1 : 0,
            'line_count' => count((array) $request->lines),
            'ffl_line_count' => $fflLineCount,
            'unique_upc_count' => count($required_by_upc),
        ]);

        $local = parent::validate_order_request_local($request, $required_by_upc, $local_only);
        if (!$local->ok) {
            $this->profile('Validation local blocked by table checks', $t0, [
                'trace_id' => $traceId,
                'code' => (string) ($local->code ?? ''),
                'codes' => array_values(array_slice((array) ($local->codes ?? []), 0, 8)),
                'message' => (string) ($local->message ?? ''),
            ]);
            return $local;
        }

        // Respect caller intent for strict local-only validation.
        if ($local_only) {
            $this->profile('Validation local complete (strict local_only)', $t0, [
                'trace_id' => $traceId,
                'ffl_probe_skipped' => 1,
            ]);
            return $local;
        }

        if ($fflLineCount <= 0) {
            $this->profile('Validation local complete (no FFL lines)', $t0, [
                'trace_id' => $traceId,
                'ffl_probe_skipped' => 1,
            ]);
            return $local;
        }

        $fflNumber = $this->sanitize_cssi_ffl_number((string) $request->receiving_ffl_number);
        if ($fflNumber === '') {
            $this->profile('Validation local failed (missing receiving FFL)', $t0, [
                'trace_id' => $traceId,
            ]);
            return DistributorOrderValidationResult::block(
                'CSSI validation failed (FFL items): missing receiving FFL number.',
                ['CSSI_VALIDATE_SHIPFFL_MISSING']
            );
        }

        $client = $this->make_cssi_client();
        if (!($client instanceof CSSIClient) || !$client->has_credentials()) {
            $this->profile('Validation local failed (missing CSSI credentials)', $t0, [
                'trace_id' => $traceId,
                'ffl_tail4' => $this->tail4($fflNumber),
            ]);
            return DistributorOrderValidationResult::block(
                'CSSI validation failed (FFL items): missing SID/token credentials.',
                ['CSSI_VALIDATE_CREDS_MISSING']
            );
        }

        $fflProbe = $client->get_federal_firearms_license($fflNumber);
        if (!(bool) ($fflProbe['ok'] ?? false)) {
            $mapped = $this->classify_cssi_api_failure($fflProbe, 'CSSI validation FFL lookup');
            if ($mapped->is_retryable()) {
                $this->profile('Validation local retryable (FFL lookup)', $t0, [
                    'trace_id' => $traceId,
                    'ffl_tail4' => $this->tail4($fflNumber),
                    'status' => (int) ($fflProbe['status'] ?? 0),
                    'message' => (string) $mapped->message,
                ]);
                return DistributorOrderValidationResult::block_retryable(
                    $mapped->message,
                    !empty($mapped->codes) ? $mapped->codes : ['CSSI_VALIDATE_FFL_LOOKUP_RETRYABLE'],
                    $mapped->details
                );
            }

            $this->profile('Validation local fatal (FFL lookup)', $t0, [
                'trace_id' => $traceId,
                'ffl_tail4' => $this->tail4($fflNumber),
                'status' => (int) ($fflProbe['status'] ?? 0),
                'message' => (string) $mapped->message,
            ]);
            return DistributorOrderValidationResult::block(
                $mapped->message,
                !empty($mapped->codes) ? $mapped->codes : ['CSSI_VALIDATE_FFL_LOOKUP_FAILED'],
                $mapped->details
            );
        }

        $records = isset($fflProbe['federal_firearms_licenses']) && is_array($fflProbe['federal_firearms_licenses'])
            ? (array) $fflProbe['federal_firearms_licenses']
            : [];

        if (empty($records) || !is_array($records[0])) {
            $this->profile('Validation local failed (FFL not on file)', $t0, [
                'trace_id' => $traceId,
                'ffl_tail4' => $this->tail4($fflNumber),
                'record_count' => count($records),
            ]);
            return DistributorOrderValidationResult::block(
                'CSSI validation failed (FFL items): receiving FFL is not on file at CSSI.',
                ['CSSI_VALIDATE_FFL_NOT_ON_FILE'],
                [
                    'ffl_tail4' => $this->tail4($fflNumber),
                    'record_count' => count($records),
                ]
            );
        }

        $record = (array) $records[0];
        $onFile = $this->to_boolish($record['on_file_flag'] ?? 0);
        $optedOut = $this->to_boolish($record['drop_ship_opted_out_flag'] ?? 0);

        if (!$onFile) {
            $this->profile('Validation local failed (FFL on_file_flag=0)', $t0, [
                'trace_id' => $traceId,
                'ffl_tail4' => $this->tail4($fflNumber),
                'on_file_flag' => $record['on_file_flag'] ?? null,
                'drop_ship_opted_out_flag' => $record['drop_ship_opted_out_flag'] ?? null,
            ]);
            return DistributorOrderValidationResult::block(
                'CSSI validation failed (FFL items): receiving FFL is not on file at CSSI.',
                ['CSSI_VALIDATE_FFL_NOT_ON_FILE'],
                [
                    'ffl_tail4' => $this->tail4($fflNumber),
                    'on_file_flag' => $record['on_file_flag'] ?? null,
                    'drop_ship_opted_out_flag' => $record['drop_ship_opted_out_flag'] ?? null,
                ]
            );
        }

        if ($optedOut) {
            $this->profile('Validation local failed (FFL dropship opted out)', $t0, [
                'trace_id' => $traceId,
                'ffl_tail4' => $this->tail4($fflNumber),
                'on_file_flag' => $record['on_file_flag'] ?? null,
                'drop_ship_opted_out_flag' => $record['drop_ship_opted_out_flag'] ?? null,
            ]);
            return DistributorOrderValidationResult::block(
                'CSSI validation failed (FFL items): receiving FFL has opted out of CSSI drop-ship.',
                ['CSSI_VALIDATE_FFL_DROP_SHIP_OPTED_OUT'],
                [
                    'ffl_tail4' => $this->tail4($fflNumber),
                    'on_file_flag' => $record['on_file_flag'] ?? null,
                    'drop_ship_opted_out_flag' => $record['drop_ship_opted_out_flag'] ?? null,
                ]
            );
        }

        $this->profile('Validation local complete (FFL probe passed)', $t0, [
            'trace_id' => $traceId,
            'ffl_tail4' => $this->tail4($fflNumber),
            'record_count' => count($records),
        ]);
        return $local->with_detail('cssi_ffl_lookup', [
            'checked' => 1,
            'ffl_tail4' => $this->tail4($fflNumber),
            'record_count' => count($records),
        ]);
    }

    protected function supports_ordering(): bool
    {
        return true;
    }

    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        return parent::place_order($request);
    }

    protected function place_order_precheck(DistributorOrderRequest $request): ?DistributorOrderResult
    {
        $t0 = microtime(true);
        $traceId = $this->new_trace_id();
        $this->log('Order precheck start', [
            'trace_id' => $traceId,
            'lane' => strtolower(trim((string) ($request->lane ?? ''))),
            'line_count' => count((array) ($request->lines ?? [])),
        ]);

        $base = parent::place_order_precheck($request);
        if ($base instanceof DistributorOrderResult) {
            $this->profile('Order precheck blocked by base', $t0, [
                'trace_id' => $traceId,
                'code' => (string) $base->code,
                'message' => (string) $base->message,
            ]);
            return $base;
        }

        $client = $this->make_cssi_client();
        if (!($client instanceof CSSIClient) || !$client->has_credentials()) {
            $this->profile('Order precheck failed (missing creds)', $t0, [
                'trace_id' => $traceId,
            ]);
            return DistributorOrderResult::block_fatal(
                'Missing CSSI SID/token credentials.',
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS]
            );
        }

        $this->profile('Order precheck complete', $t0, [
            'trace_id' => $traceId,
            'has_client' => 1,
        ]);
        return null;
    }

    protected function place_order_lane(
        DistributorOrderRequest $request,
        string $lane,
        array $lines,
        array &$external_ids
    ): DistributorOrderResult {
        $t0 = microtime(true);
        $traceId = $this->new_trace_id();
        $lane = strtolower(trim((string) $lane));
        $this->log('Place-order lane start', [
            'trace_id' => $traceId,
            'lane' => $lane,
            'line_count' => count($lines),
            'existing_external_ids' => count($external_ids),
        ]);

        $client = $this->make_cssi_client();
        if (!($client instanceof CSSIClient) || !$client->has_credentials()) {
            $this->profile('Place-order lane failed (missing creds)', $t0, [
                'trace_id' => $traceId,
                'lane' => $lane,
            ]);
            return DistributorOrderResult::block_fatal(
                'Missing CSSI SID/token credentials.',
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS],
                [],
                0,
                '',
                $external_ids
            );
        }

        $items = $this->build_cssi_order_items($lines, false);
        if ($items instanceof DistributorOrderResult) {
            $items->external_order_ids = $external_ids;
            $this->profile('Place-order lane failed (item mapping)', $t0, [
                'trace_id' => $traceId,
                'lane' => $lane,
                'code' => (string) $items->code,
                'message' => (string) $items->message,
            ]);
            return $items;
        }

        $po = $this->build_cssi_po((string) $request->merchant_order_id);
        $this->log('Place-order lane mapped items', [
            'trace_id' => $traceId,
            'lane' => $lane,
            'po' => $po,
            'item_count' => count($items),
        ]);

        $payload = [
            'purchase_order_number' => $po,
            'order_items' => $items,
        ];

        if ($lane === 'direct_ship_non_ffl') {
            $this->log('Place-order lane branch selected', [
                'trace_id' => $traceId,
                'lane' => $lane,
                'drop_ship_flag' => 1,
                'ffl_required' => 0,
            ]);
            if (!($request->ship_to_customer instanceof DistributorShipTo)) {
                $this->profile('Place-order lane failed (missing ship_to_customer)', $t0, [
                    'trace_id' => $traceId,
                    'lane' => $lane,
                ]);
                return DistributorOrderResult::block_fatal(
                    'CSSI direct-ship non-FFL: missing ship_to_customer (ship-to address required).',
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    [],
                    0,
                    '',
                    $external_ids
                );
            }

            $shipCheck = self::validate_shipto_minimum($request->ship_to_customer);
            if (!($shipCheck['ok'] ?? false)) {
                $this->profile('Place-order lane failed (invalid ship_to_customer)', $t0, [
                    'trace_id' => $traceId,
                    'lane' => $lane,
                    'ship_check' => $shipCheck,
                ]);
                return DistributorOrderResult::block_fatal(
                    'CSSI direct-ship non-FFL: ' . (string) ($shipCheck['message'] ?? 'Invalid ship-to.'),
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    ['ship_check' => $shipCheck],
                    0,
                    '',
                    $external_ids
                );
            }

            $payload['drop_ship_flag'] = 1;
            $payload['customer'] = $this->build_cssi_customer_name($request->ship_to_customer);
            $payload['delivery_option'] = $this->cssi_delivery_option_for_lane($lane, $request);
            $payload['ship_to_address'] = $this->build_cssi_ship_to_address($request->ship_to_customer);

            if ($this->to_boolish(apply_filters('fflhub_cssi_insurance_flag', true, $lane, $request, $this))) {
                $payload['insurance_flag'] = 1;
            }

            if ($this->to_boolish(apply_filters('fflhub_cssi_adult_signature_flag', false, $lane, $request, $this))) {
                $payload['adult_signature_flag'] = 1;
            }
        } elseif ($lane === 'direct_ship_ffl') {
            $this->log('Place-order lane branch selected', [
                'trace_id' => $traceId,
                'lane' => $lane,
                'drop_ship_flag' => 1,
                'ffl_required' => 1,
            ]);
            if (!($request->ship_to_ffl instanceof DistributorShipTo)) {
                $this->profile('Place-order lane failed (missing ship_to_ffl)', $t0, [
                    'trace_id' => $traceId,
                    'lane' => $lane,
                ]);
                return DistributorOrderResult::block_fatal(
                    'CSSI direct-ship FFL: missing ship_to_ffl (transfer dealer address required).',
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    [],
                    0,
                    '',
                    $external_ids
                );
            }

            $shipCheck = self::validate_shipto_minimum($request->ship_to_ffl);
            if (!($shipCheck['ok'] ?? false)) {
                $this->profile('Place-order lane failed (invalid ship_to_ffl)', $t0, [
                    'trace_id' => $traceId,
                    'lane' => $lane,
                    'ship_check' => $shipCheck,
                ]);
                return DistributorOrderResult::block_fatal(
                    'CSSI direct-ship FFL: ' . (string) ($shipCheck['message'] ?? 'Invalid FFL ship-to.'),
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    ['ship_check' => $shipCheck],
                    0,
                    '',
                    $external_ids
                );
            }

            $fflNumber = $this->sanitize_cssi_ffl_number((string) $request->receiving_ffl_number);
            if ($fflNumber === '') {
                $this->profile('Place-order lane failed (missing receiving FFL)', $t0, [
                    'trace_id' => $traceId,
                    'lane' => $lane,
                ]);
                return DistributorOrderResult::block_fatal(
                    'CSSI direct-ship FFL: missing receiving FFL number.',
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    [],
                    0,
                    '',
                    $external_ids
                );
            }

            $fflCheck = $this->validate_cssi_ffl_for_dropship($client, $fflNumber, $external_ids);
            if ($fflCheck instanceof DistributorOrderResult) {
                $this->profile('Place-order lane failed (FFL probe)', $t0, [
                    'trace_id' => $traceId,
                    'lane' => $lane,
                    'ffl_tail4' => $this->tail4($fflNumber),
                    'code' => (string) $fflCheck->code,
                    'message' => (string) $fflCheck->message,
                ]);
                return $fflCheck;
            }

            $payload['drop_ship_flag'] = 1;
            $payload['customer'] = $this->build_cssi_customer_name($request->ship_to_customer);
            $payload['delivery_option'] = $this->cssi_delivery_option_for_lane($lane, $request);
            $payload['adult_signature_flag'] = 1;
            $payload['federal_firearms_license_number'] = $fflNumber;
            $payload['ship_to_address'] = $this->build_cssi_ship_to_address($request->ship_to_ffl);

            if ($this->to_boolish(apply_filters('fflhub_cssi_insurance_flag', true, $lane, $request, $this))) {
                $payload['insurance_flag'] = 1;
            }
        } elseif ($lane === 'dealer_fulfilled') {
            $this->log('Place-order lane branch selected', [
                'trace_id' => $traceId,
                'lane' => $lane,
                'drop_ship_flag' => 0,
            ]);
            $ship = $this->resolve_cssi_dealer_ship_to();
            if (!($ship instanceof DistributorShipTo)) {
                $this->profile('Place-order lane failed (missing dealer ship-to)', $t0, [
                    'trace_id' => $traceId,
                    'lane' => $lane,
                ]);
                return DistributorOrderResult::block_fatal(
                    'CSSI dealer-fulfilled: missing dealer ship-to settings.',
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    [],
                    0,
                    '',
                    $external_ids
                );
            }

            $shipCheck = self::validate_shipto_minimum($ship);
            if (!($shipCheck['ok'] ?? false)) {
                $this->profile('Place-order lane failed (invalid dealer ship-to)', $t0, [
                    'trace_id' => $traceId,
                    'lane' => $lane,
                    'ship_check' => $shipCheck,
                ]);
                return DistributorOrderResult::block_fatal(
                    'CSSI dealer-fulfilled: ' . (string) ($shipCheck['message'] ?? 'Invalid ship-to.'),
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    ['ship_check' => $shipCheck],
                    0,
                    '',
                    $external_ids
                );
            }

            $payload['drop_ship_flag'] = 0;
            $payload['ship_to_address'] = $this->build_cssi_ship_to_address($ship);
        } else {
            $this->profile('Place-order lane failed (unsupported lane)', $t0, [
                'trace_id' => $traceId,
                'lane' => $lane,
            ]);
            return DistributorOrderResult::block_fatal(
                'CSSI: unsupported lane "' . $lane . '".',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        if ($lane === 'dealer_fulfilled') {
            $payload = $this->normalize_cssi_dealer_fulfilled_payload($payload);
        }

        $this->log('Place-order lane payload built', [
            'trace_id' => $traceId,
            'lane' => $lane,
            'po' => $po,
            'drop_ship_flag' => (int) ($payload['drop_ship_flag'] ?? 0),
            'item_count' => isset($payload['order_items']) && is_array($payload['order_items']) ? count($payload['order_items']) : 0,
            'has_ffl_number' => trim((string) ($payload['federal_firearms_license_number'] ?? '')) !== '' ? 1 : 0,
            'delivery_option' => (string) ($payload['delivery_option'] ?? ''),
        ]);

        if ($this->is_test_order_debug_enabled()) {
            $this->profile('Place-order lane blocked by test-order debug mode', $t0, [
                'trace_id' => $traceId,
                'lane' => $lane,
                'po' => $po,
                'item_count' => count($items),
            ]);
            return $this->build_test_order_debug_block(
                $lane,
                self::CSSI_API_ORDERS_URL,
                'POST',
                'json',
                $this->encode_debug_json_payload($payload),
                [
                    'po' => $po,
                    'item_count' => count($items),
                    'drop_ship_flag' => (int) ($payload['drop_ship_flag'] ?? 0),
                ],
                $external_ids
            );
        }

        $res = $client->create_order($payload);
        if (!(bool) ($res['ok'] ?? false)) {
            $failed = $this->classify_cssi_api_failure($res, 'CSSI ' . $lane . ' place order');
            $failed->external_order_ids = $external_ids;
            $this->profile('Place-order lane API call failed', $t0, [
                'trace_id' => $traceId,
                'lane' => $lane,
                'po' => $po,
                'status' => (int) ($res['status'] ?? 0),
                'classified_code' => (string) $failed->code,
                'classified_message' => (string) $failed->message,
            ]);
            return $failed;
        }

        $orders = isset($res['orders']) && is_array($res['orders']) ? (array) $res['orders'] : [];
        if (empty($orders)) {
            $this->profile('Place-order lane failed (empty orders response)', $t0, [
                'trace_id' => $traceId,
                'lane' => $lane,
                'po' => $po,
                'status' => (int) ($res['status'] ?? 0),
            ]);
            return DistributorOrderResult::block_fatal(
                'CSSI order API returned success but no orders were returned.',
                [DistributorOrderResult::REASON_FATAL_UNKNOWN],
                [
                    'status' => (int) ($res['status'] ?? 0),
                    'po' => $po,
                    'lane' => $lane,
                ],
                (int) ($res['status'] ?? 0),
                '',
                $external_ids
            );
        }

        $newIds = $this->extract_cssi_order_numbers($orders);
        $external_ids = $this->merge_external_ids($external_ids, $newIds);

        $this->profile('Place-order lane complete', $t0, [
            'trace_id' => $traceId,
            'lane' => $lane,
            'po' => $po,
            'status' => (int) ($res['status'] ?? 0),
            'returned_orders' => count($orders),
            'new_external_ids' => count($newIds),
            'total_external_ids' => count($external_ids),
        ]);

        return DistributorOrderResult::ok(
            'CSSI ' . $lane . ' order submitted.',
            $external_ids,
            [
                'po' => $po,
                'lane' => $lane,
                'order_count' => count($orders),
                'order_numbers' => $newIds,
            ]
        );
    }

    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        $t0 = microtime(true);
        $traceId = $this->new_trace_id();
        $po = $this->build_cssi_po($po_number);
        if ($po === '') {
            $this->profile('Shipment poll skipped (empty PO)', $t0, [
                'trace_id' => $traceId,
            ]);
            return null;
        }

        $this->log('Shipment poll start', [
            'trace_id' => $traceId,
            'po' => $po,
        ]);

        $client = $this->make_cssi_client();
        if (!($client instanceof CSSIClient) || !$client->has_credentials()) {
            $this->profile('Shipment poll aborted (missing creds)', $t0, [
                'trace_id' => $traceId,
                'po' => $po,
            ]);
            return null;
        }

        $page = 1;
        $pageCount = 1;
        $orderNumbers = [];
        $trackingNumbers = [];
        $shippingService = null;

        do {
            $shipmentsRes = $client->get_shipments_by_purchase_order([
                'purchase_order_numbers' => $po,
                'only_return_unreceived_shipments' => 0,
                'page' => $page,
                'per_page' => 25,
            ]);
            if (!(bool) ($shipmentsRes['ok'] ?? false)) {
                $status = (int) ($shipmentsRes['status'] ?? 0);
                $this->profile('Shipment poll page failed', $t0, [
                    'trace_id' => $traceId,
                    'po' => $po,
                    'page' => $page,
                    'status' => $status,
                    'error' => (string) ($shipmentsRes['error'] ?? ''),
                ]);
                if ($status === 404) {
                    return null;
                }
                return null;
            }

            $shipments = isset($shipmentsRes['shipments']) && is_array($shipmentsRes['shipments'])
                ? (array) $shipmentsRes['shipments']
                : [];

            $pageTrackingCount = 0;
            $pageOrderCount = 0;
            foreach ($shipments as $shipmentGroup) {
                if (!is_array($shipmentGroup)) {
                    continue;
                }

                $groupPo = strtoupper(trim((string) ($shipmentGroup['purchase_order_number'] ?? '')));
                if ($groupPo !== '' && $groupPo !== strtoupper($po)) {
                    continue;
                }

                $orders = isset($shipmentGroup['orders']) && is_array($shipmentGroup['orders'])
                    ? (array) $shipmentGroup['orders']
                    : [];

                foreach ($orders as $order) {
                    if (!is_array($order)) {
                        continue;
                    }
                    $pageOrderCount++;

                    $orderNumber = trim((string) ($order['order_number'] ?? ''));
                    if ($orderNumber !== '') {
                        $orderNumbers[] = $orderNumber;
                    }

                    // CSSI returns tracking containers under `shipments` on this endpoint.
                    // Keep `packages` support as a fallback for historical/variant payloads.
                    $shipmentEntries = [];
                    if (isset($order['shipments']) && is_array($order['shipments'])) {
                        $shipmentEntries = array_merge($shipmentEntries, (array) $order['shipments']);
                    }
                    if (isset($order['packages']) && is_array($order['packages'])) {
                        $shipmentEntries = array_merge($shipmentEntries, (array) $order['packages']);
                    }

                    foreach ($shipmentEntries as $pkg) {
                        if (!is_array($pkg)) {
                            continue;
                        }

                        $tracking = trim((string) ($pkg['tracking_number'] ?? ''));
                        if ($tracking !== '') {
                            $trackingNumbers[] = $tracking;
                            $pageTrackingCount++;
                        }

                        if ($shippingService === null) {
                            $carrier = trim((string) ($pkg['carrier_name'] ?? $pkg['carrier'] ?? ''));
                            if ($carrier !== '') {
                                $shippingService = $this->normalize_carrier($carrier) ?? $carrier;
                            }
                        }
                    }
                }
            }

            $pagination = isset($shipmentsRes['pagination']) && is_array($shipmentsRes['pagination'])
                ? (array) $shipmentsRes['pagination']
                : [];

            $pageCount = max(1, (int) ($pagination['page_count'] ?? 1));
            $this->log('Shipment poll page parsed', [
                'trace_id' => $traceId,
                'po' => $po,
                'page' => $page,
                'page_count' => $pageCount,
                'shipment_groups' => count($shipments),
                'page_orders' => $pageOrderCount,
                'page_tracking' => $pageTrackingCount,
                'accum_order_ids' => count($orderNumbers),
                'accum_tracking' => count($trackingNumbers),
            ]);
            $page++;
        } while ($page <= $pageCount && $page <= 200);

        $orderNumbers = array_values(array_unique(array_filter($orderNumbers)));
        $trackingNumbers = array_values(array_unique(array_filter($trackingNumbers)));
        sort($trackingNumbers, SORT_STRING);

        if (empty($trackingNumbers)) {
            $this->profile('Shipment poll complete (no tracking yet)', $t0, [
                'trace_id' => $traceId,
                'po' => $po,
                'order_count' => count($orderNumbers),
            ]);
            return null;
        }

        $this->profile('Shipment poll complete', $t0, [
            'trace_id' => $traceId,
            'po' => $po,
            'order_count' => count($orderNumbers),
            'tracking_count' => count($trackingNumbers),
            'shipping_service' => $shippingService,
        ]);
        return new DistributorShipment(
            $trackingNumbers,
            [],
            $shippingService,
            null,
            [
                'po_number' => $po,
                'order_numbers' => $orderNumbers,
                'order_count' => count($orderNumbers),
            ]
        );
    }

    private function make_cssi_client(): ?CSSIClient
    {
        $sid = trim((string) Options::get_distributor_option('cssi', 'sid', ''));
        $token = trim((string) Options::get_distributor_option('cssi', 'token', ''));
        if ($sid === '' || $token === '') {
            $this->log('CSSI client unavailable (missing credentials)', [
                'has_sid' => $sid !== '' ? 1 : 0,
                'has_token' => $token !== '' ? 1 : 0,
            ]);
            return null;
        }

        $this->log('CSSI client initialized', [
            'sid_tail2' => substr($sid, -2),
        ]);
        return new CSSIClient($sid, $token);
    }

    /**
     * @param array<int,mixed> $lines
     * @return array<int,array<string,mixed>>|DistributorOrderResult
     */
    private function build_cssi_order_items(array $lines, bool $allow_empty = false)
    {
        $this->log('Build CSSI order items start', [
            'line_count' => count($lines),
            'allow_empty' => $allow_empty ? 1 : 0,
        ]);

        $mapped = $this->map_order_lines_to_items(
            $lines,
            function (string $normalized_upc, string $raw_upc, DistributorOrderLine $line): ?string {
                return $this->lookup_cssi_item_number_by_upc($normalized_upc);
            },
            function (string $itemNumber, int $qty, string $normalized_upc): array {
                return [
                    'item_number' => $itemNumber,
                    'order_quantity' => $qty,
                    'customer_reference' => self::truncate_string($normalized_upc, 24),
                ];
            },
            'Cannot map UPC to CSSI item_number: %s',
            !$allow_empty,
            'No valid CSSI line items after normalization.'
        );

        if ($mapped instanceof DistributorOrderResult) {
            $this->log('Build CSSI order items failed', [
                'code' => (string) $mapped->code,
                'message' => (string) $mapped->message,
            ]);
            return $mapped;
        }

        $mappedCount = count($mapped);
        $aggregated = [];
        foreach ($mapped as $row) {
            if (!is_array($row)) {
                continue;
            }

            $itemNumber = trim((string) ($row['item_number'] ?? ''));
            if ($itemNumber === '') {
                continue;
            }

            $qty = max(1, (int) ($row['order_quantity'] ?? 0));
            if (!isset($aggregated[$itemNumber])) {
                $aggregated[$itemNumber] = [
                    'item_number' => $itemNumber,
                    'order_quantity' => 0,
                    'customer_reference' => trim((string) ($row['customer_reference'] ?? '')),
                ];
            }

            $aggregated[$itemNumber]['order_quantity'] += $qty;
        }

        $out = array_values($aggregated);
        $this->log('Build CSSI order items complete', [
            'mapped_rows' => $mappedCount,
            'aggregated_rows' => count($out),
        ]);

        return $out;
    }

    private function lookup_cssi_item_number_by_upc(string $upc): ?string
    {
        $lookup = $this->get_fulfillment_row_for_upc($upc);
        if ($lookup === null) {
            return null;
        }

        $row = isset($lookup['row']) && is_array($lookup['row']) ? (array) $lookup['row'] : [];
        $itemNumber = trim((string) ($this->get_string_field($row, ['cssi_item_number', 'sku']) ?? ''));
        return $itemNumber !== '' ? $itemNumber : null;
    }

    private function build_cssi_po(string $po): string
    {
        $sanitized = $this->sanitize_and_truncate_po($po, 24, '/[^A-Z0-9\-_]/');
        if ($sanitized === '') {
            $sanitized = 'WC' . gmdate('YmdHis');
        }

        return self::truncate_string($sanitized, 24);
    }

    private function sanitize_cssi_ffl_number(string $fflNumber): string
    {
        $fflNumber = strtoupper(trim($fflNumber));
        $fflNumber = preg_replace('/[^A-Z0-9]/', '', $fflNumber);
        if (!is_string($fflNumber)) {
            return '';
        }

        return self::truncate_string($fflNumber, 15);
    }

    /**
     * @param array<int,string> $external_ids
     */
    private function validate_cssi_ffl_for_dropship(CSSIClient $client, string $fflNumber, array $external_ids): ?DistributorOrderResult
    {
        $t0 = microtime(true);
        $traceId = $this->new_trace_id();
        $this->log('FFL dropship probe start', [
            'trace_id' => $traceId,
            'ffl_tail4' => $this->tail4($fflNumber),
        ]);

        $res = $client->get_federal_firearms_license($fflNumber);
        if (!(bool) ($res['ok'] ?? false)) {
            $failed = $this->classify_cssi_api_failure($res, 'CSSI FFL lookup');
            $failed->external_order_ids = $external_ids;
            $this->profile('FFL dropship probe failed (API)', $t0, [
                'trace_id' => $traceId,
                'ffl_tail4' => $this->tail4($fflNumber),
                'status' => (int) ($res['status'] ?? 0),
                'code' => (string) $failed->code,
                'message' => (string) $failed->message,
            ]);
            return $failed;
        }

        $records = isset($res['federal_firearms_licenses']) && is_array($res['federal_firearms_licenses'])
            ? (array) $res['federal_firearms_licenses']
            : [];

        if (empty($records) || !is_array($records[0])) {
            $this->profile('FFL dropship probe failed (missing record)', $t0, [
                'trace_id' => $traceId,
                'ffl_tail4' => $this->tail4($fflNumber),
                'record_count' => count($records),
            ]);
            return DistributorOrderResult::block_fatal(
                'CSSI FFL lookup failed: receiving FFL is not on file.',
                [DistributorOrderResult::REASON_FATAL_RESTRICTED],
                [
                    'ffl_tail4' => $this->tail4($fflNumber),
                    'record_count' => count($records),
                ],
                (int) ($res['status'] ?? 0),
                '',
                $external_ids
            );
        }

        $record = (array) $records[0];
        $onFile = $this->to_boolish($record['on_file_flag'] ?? 0);
        $optedOut = $this->to_boolish($record['drop_ship_opted_out_flag'] ?? 0);
        if (!$onFile) {
            $this->profile('FFL dropship probe failed (on_file_flag=0)', $t0, [
                'trace_id' => $traceId,
                'ffl_tail4' => $this->tail4($fflNumber),
                'on_file_flag' => $record['on_file_flag'] ?? null,
                'drop_ship_opted_out_flag' => $record['drop_ship_opted_out_flag'] ?? null,
            ]);
            return DistributorOrderResult::block_fatal(
                'CSSI FFL lookup failed: receiving FFL is not on file.',
                [DistributorOrderResult::REASON_FATAL_RESTRICTED],
                [
                    'ffl_tail4' => $this->tail4($fflNumber),
                    'on_file_flag' => $record['on_file_flag'] ?? null,
                    'drop_ship_opted_out_flag' => $record['drop_ship_opted_out_flag'] ?? null,
                ],
                (int) ($res['status'] ?? 0),
                '',
                $external_ids
            );
        }

        if ($optedOut) {
            $this->profile('FFL dropship probe failed (opted out)', $t0, [
                'trace_id' => $traceId,
                'ffl_tail4' => $this->tail4($fflNumber),
                'on_file_flag' => $record['on_file_flag'] ?? null,
                'drop_ship_opted_out_flag' => $record['drop_ship_opted_out_flag'] ?? null,
            ]);
            return DistributorOrderResult::block_fatal(
                'CSSI FFL lookup failed: receiving FFL has opted out of drop-ship.',
                [DistributorOrderResult::REASON_FATAL_RESTRICTED],
                [
                    'ffl_tail4' => $this->tail4($fflNumber),
                    'on_file_flag' => $record['on_file_flag'] ?? null,
                    'drop_ship_opted_out_flag' => $record['drop_ship_opted_out_flag'] ?? null,
                ],
                (int) ($res['status'] ?? 0),
                '',
                $external_ids
            );
        }

        $this->profile('FFL dropship probe passed', $t0, [
            'trace_id' => $traceId,
            'ffl_tail4' => $this->tail4($fflNumber),
            'record_count' => count($records),
        ]);
        return null;
    }

    /**
     * CSSI marks drop-ship-only fields invalid when drop_ship_flag=0.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalize_cssi_dealer_fulfilled_payload(array $payload): array
    {
        $payload['drop_ship_flag'] = 0;

        unset(
            $payload['customer'],
            $payload['delivery_option'],
            $payload['insurance_flag'],
            $payload['adult_signature_flag'],
            $payload['federal_firearms_license_number']
        );

        return $payload;
    }

    private function resolve_cssi_dealer_ship_to(): ?DistributorShipTo
    {
        $cfg = Options::get_dealer_ship_to_address();

        $name = trim((string) ($cfg['name'] ?? ''));
        $company = trim((string) ($cfg['company'] ?? ''));
        if ($name === '') {
            $name = $company;
        }
        if ($company === '') {
            $company = $name;
        }

        $address1 = trim((string) ($cfg['address1'] ?? ''));
        $address2 = trim((string) ($cfg['address2'] ?? ''));
        $city = trim((string) ($cfg['city'] ?? ''));
        $state = strtoupper(trim((string) ($cfg['state'] ?? '')));
        $zip = trim((string) ($cfg['zip'] ?? ''));

        if ($name === '' || $address1 === '' || $city === '' || $state === '' || $zip === '') {
            return null;
        }

        return new DistributorShipTo(
            $name,
            $company,
            $address1,
            $address2,
            $city,
            $state,
            $zip,
            trim((string) ($cfg['phone'] ?? '')),
            trim((string) ($cfg['email'] ?? ''))
        );
    }

    /**
     * @return array<string,string>
     */
    private function build_cssi_ship_to_address(DistributorShipTo $ship): array
    {
        $name = trim((string) $ship->name);
        if ($name === '') {
            $name = trim((string) $ship->company);
        }
        if ($name === '') {
            $name = 'Customer';
        }

        return [
            'name' => self::truncate_string($name, 60),
            'line_1' => self::truncate_string(trim((string) $ship->address1), 60),
            'line_2' => self::truncate_string(trim((string) $ship->address2), 60),
            'city' => self::truncate_string(trim((string) $ship->city), 60),
            'state_code' => self::format_us_state2_best_effort($ship->state),
            'zip' => self::format_us_zip5_or_zip9_with_dash_for_payload((string) $ship->zip),
        ];
    }

    private function build_cssi_customer_name(DistributorShipTo $ship): string
    {
        $name = trim((string) $ship->name);
        if ($name === '') {
            $name = trim((string) $ship->company);
        }
        if ($name === '') {
            $name = 'Customer';
        }

        return self::truncate_string($name, 60);
    }

    private function cssi_delivery_option_for_lane(string $lane, DistributorOrderRequest $request): string
    {
        $lane = strtolower(trim($lane));
        $default = 'best';
        $value = (string) apply_filters('fflhub_cssi_delivery_option', $default, $lane, $request, $this);
        $value = strtolower(trim($value));

        $allowed = ['best', 'fastest', 'economy', 'ground', 'next_day_air', 'second_day_air'];
        if (!in_array($value, $allowed, true)) {
            return $default;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $resp
     */
    private function classify_cssi_api_failure(array $resp, string $prefix): DistributorOrderResult
    {
        $http = isset($resp['status']) ? (int) $resp['status'] : 0;
        $msg = trim((string) ($resp['error'] ?? 'CSSI request failed.'));
        $data = isset($resp['data']) && is_array($resp['data']) ? (array) $resp['data'] : [];

        $provider = trim((string) ($data['error_code'] ?? ''));
        $apiMessage = trim((string) ($data['message'] ?? ''));
        if ($msg === '' && $apiMessage !== '') {
            $msg = $apiMessage;
        }
        if ($msg === '') {
            $msg = 'CSSI request failed.';
        }

        $this->log('Classify CSSI API failure', [
            'prefix' => $prefix,
            'status' => $http,
            'error_code' => trim((string) ($data['error_code'] ?? '')),
            'message' => $msg,
        ]);

        $details = [
            'status' => $http,
        ];
        if ($provider !== '') {
            $details['error_code'] = $provider;
        }
        if ($apiMessage !== '') {
            $details['api_message'] = $apiMessage;
        }
        if (isset($data['errors']) && is_array($data['errors'])) {
            $errs = [];
            foreach ($data['errors'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $m = trim((string) ($entry['message'] ?? ''));
                if ($m !== '') {
                    $errs[] = $m;
                }
                if (count($errs) >= 3) {
                    break;
                }
            }
            if (!empty($errs)) {
                $details['api_errors'] = $errs;
            }
        }

        $lc = strtolower($msg . ' ' . $apiMessage . ' ' . $provider);

        if ($http === 429 || strpos($lc, 'too many request') !== false || strpos($lc, 'too-many-requests') !== false) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': rate limit: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_RATE_LIMIT],
                $details,
                $http,
                $provider
            );
        }

        if ($http === 401 || $provider === '4001') {
            return DistributorOrderResult::block_fatal(
                $prefix . ': invalid credentials: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS],
                $details,
                $http,
                $provider
            );
        }

        if ($http === 403 || $provider === '4003') {
            return DistributorOrderResult::block_fatal(
                $prefix . ': permission denied: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_RESTRICTED],
                $details,
                $http,
                $provider
            );
        }

        if ($http === 408 || $http === 504) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': timeout (HTTP ' . $http . '): ' . $msg,
                [DistributorOrderResult::REASON_RETRY_TIMEOUT],
                $details,
                $http,
                $provider
            );
        }

        if ($http === 500 || $http === 502 || $http === 503) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': upstream error (HTTP ' . $http . '): ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UPSTREAM],
                $details,
                $http,
                $provider
            );
        }

        if (
            strpos($lc, 'insufficient inventory') !== false ||
            strpos($lc, 'out of stock') !== false ||
            $provider === '60202'
        ) {
            return DistributorOrderResult::block_fatal(
                $prefix . ': out of stock: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_OUT_OF_STOCK],
                $details,
                $http,
                $provider
            );
        }

        if (
            strpos($lc, 'cannot be shipped') !== false ||
            strpos($lc, 'drop ship authorization failed') !== false ||
            strpos($lc, 'opted out') !== false ||
            in_array($provider, ['60101', '60106', '60107', '60108', '60109', '60203', '60204'], true)
        ) {
            return DistributorOrderResult::block_fatal(
                $prefix . ': restricted: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_RESTRICTED],
                $details,
                $http,
                $provider
            );
        }

        if ($http === 400 || $http === 404 || $http === 409 || $http === 410 || $http === 422 || $provider === '9000') {
            return DistributorOrderResult::block_fatal(
                $prefix . ': bad request: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                $details,
                $http,
                $provider
            );
        }

        if (
            $http === 0 &&
            (
                strpos($lc, 'timeout') !== false ||
                strpos($lc, 'timed out') !== false ||
                strpos($lc, 'could not resolve') !== false ||
                strpos($lc, 'connection') !== false ||
                strpos($lc, 'ssl') !== false ||
                strpos($lc, 'curl') !== false
            )
        ) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': timeout/network: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_TIMEOUT],
                $details,
                $http,
                $provider
            );
        }

        if ($http === 0) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': transport failure: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UNKNOWN],
                $details,
                $http,
                $provider
            );
        }

        return DistributorOrderResult::block_fatal(
            $prefix . ': request failed: ' . $msg,
            [DistributorOrderResult::REASON_FATAL_UNKNOWN],
            $details,
            $http,
            $provider
        );
    }

    /**
     * @param array<int,mixed> $orders
     * @return array<int,string>
     */
    private function extract_cssi_order_numbers(array $orders): array
    {
        $out = [];
        foreach ($orders as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = trim((string) ($row['order_number'] ?? ''));
            if ($id !== '') {
                $out[] = $id;
            }
        }

        $out = array_values(array_unique($out));
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $message);
            return;
        }

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $message, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $ctx['elapsed_ms'] = number_format((microtime(true) - $t0) * 1000, 2, '.', '');
        $this->log('PROFILE: ' . $label, $ctx);
    }

    private function new_trace_id(): string
    {
        return substr(sha1((string) microtime(true) . '|' . (string) mt_rand()), 0, 10);
    }

    private function tail4(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return (strlen($value) >= 4) ? substr($value, -4) : $value;
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $computed = self::DEFAULT_FLAT_SHIPPING_COST;
        $lookup = $this->get_fulfillment_row_for_upc($normalized);
        if ($lookup !== null && isset($lookup['row']) && is_array($lookup['row'])) {
            $row = (array) $lookup['row'];

            $ffl_required_raw = $this->get_string_field($row, ['ffl_required']);
            $ffl_required = $this->to_boolish($ffl_required_raw ?? '0', false);

            $item_type = strtoupper(trim((string) ($this->get_string_field($row, ['item_type']) ?? '')));
            $product_name = strtoupper(trim((string) ($this->get_string_field($row, ['product_name']) ?? '')));

            $shipping_weight_oz = $this->parse_non_negative_float(
                $this->get_string_field($row, ['shipping_weight']) ?? ''
            );
            if ($shipping_weight_oz <= 0.0) {
                // Ensure we still bill a single increment when weight is missing.
                $shipping_weight_oz = 1.0;
            }

            $distributor_price = $this->parse_non_negative_money(
                $this->get_string_field($row, ['distributor_price']) ?? ''
            );

            $service = $this->resolve_shipping_service_for_row(
                $ffl_required,
                $item_type,
                $product_name
            );

            if ($service === self::SHIPPING_SERVICE_HANDGUN_SECOND_DAY) {
                $increments = max(1, (int) ceil($shipping_weight_oz / self::SHIPPING_FIREARM_STEP_OZ));
                $base_shipping = $increments * self::SHIPPING_HANDGUN_SECOND_DAY_RATE;
            } elseif ($service === self::SHIPPING_SERVICE_LONG_GUN_GROUND_PREMIUM) {
                $increments = max(1, (int) ceil($shipping_weight_oz / self::SHIPPING_FIREARM_STEP_OZ));
                $base_shipping = $increments * self::SHIPPING_LONG_GUN_GROUND_PREMIUM_RATE;
            } else {
                $increments = max(1, (int) ceil($shipping_weight_oz / self::SHIPPING_GROUND_ECONOMY_STEP_OZ));
                $base_shipping = $increments * self::SHIPPING_GROUND_ECONOMY_RATE;
            }

            $minimum_order_fee = ($distributor_price > 0.0 && $distributor_price < self::SHIPPING_MIN_ORDER_FEE_THRESHOLD)
                ? self::SHIPPING_MIN_ORDER_FEE
                : 0.0;
            $insurance_fee = ($distributor_price > 0.0)
                ? (float) ceil($distributor_price / 100.0) * self::SHIPPING_INSURANCE_PER_100
                : 0.0;

            $computed = max(0.0, round($base_shipping + $minimum_order_fee + $insurance_fee, 2));
        }

        $cost = apply_filters(
            'fflhub_cssi_flat_shipping_cost',
            $computed,
            $normalized,
            $this
        );

        return is_numeric($cost) ? (float) $cost : $computed;
    }

    private function resolve_shipping_service_for_row(bool $ffl_required, string $item_type, string $product_name): string
    {
        // Final business rule:
        // - accessories + ammo: ground economy
        // - handguns: second day air
        // - long guns: ground premium
        if (!$ffl_required) {
            return self::SHIPPING_SERVICE_GROUND_ECONOMY;
        }

        if ($this->is_handgun_row($item_type, $product_name)) {
            return self::SHIPPING_SERVICE_HANDGUN_SECOND_DAY;
        }

        return self::SHIPPING_SERVICE_LONG_GUN_GROUND_PREMIUM;
    }

    private function is_handgun_row(string $item_type, string $product_name): bool
    {
        $haystack = strtoupper(trim($item_type . ' ' . $product_name));
        if ($haystack === '') {
            return false;
        }

        foreach (['HANDGUN', 'PISTOL', 'REVOLVER', 'DERRINGER'] as $keyword) {
            if (strpos($haystack, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function parse_non_negative_money(string $value): float
    {
        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }

        $value = str_replace([',', '$'], '', $value);
        $value = preg_replace('/[^0-9\.\-]/', '', $value);
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || $value === '-' || $value === '.') {
            return 0.0;
        }

        $amount = (float) $value;
        if (!is_finite($amount) || $amount < 0.0) {
            return 0.0;
        }

        return $amount;
    }

    private function parse_non_negative_float(string $value): float
    {
        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }

        $value = preg_replace('/[^0-9\.\-]/', '', $value);
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || $value === '-' || $value === '.') {
            return 0.0;
        }

        $amount = (float) $value;
        if (!is_finite($amount) || $amount < 0.0) {
            return 0.0;
        }

        return $amount;
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

        // Product-creation stage requirement:
        // keep description blank and never compose the product name from it.
        $payload->description = '';

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
