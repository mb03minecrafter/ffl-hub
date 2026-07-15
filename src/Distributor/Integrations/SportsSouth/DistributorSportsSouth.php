<?php

namespace FFLHub\Distributor\Integrations\SportsSouth;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Distributor\Services\SportsSouth\API\SportsSouthInvoicesClient;
use FFLHub\Distributor\Services\SportsSouth\API\SportsSouthOrdersClient;
use FFLHub\Distributor\Services\SportsSouth\SportsSouthAccessoriesOnlyPolicy;
use FFLHub\FFL\Data\FFLRowMapper;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * Sports South runtime distributor backed by the local catalog table.
 */
final class DistributorSportsSouth extends DistributorBase
{
    private const DEFAULT_SHIP_VIA = '';
    private const FLAT_SHIPPING_COST = 7.95;
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][SportsSouthDistributor]';
    private const FFL_DOCUMENT_EMAIL = 'fulfillment@sportssouth.biz';
    private const SHIP_INSTRUCTION_MAX_ATTEMPTS = 3;

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

    /**
     * @param array<int,string> $upcs
     * @return array<string,DistributorProductPayload>
     */
    public function get_pricing_payloads_by_upcs(array $upcs): array
    {
        return $this->get_local_pricing_payloads_by_upcs(
            $upcs,
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
            [DistributorProductCategoryMapper::class, 'map_sports_south'],
            false,
            null,
            static function (array $row, string $normalized_upc): bool {
                return !SportsSouthAccessoriesOnlyPolicy::should_skip_row($row);
            }
        );
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
        $lane = $this->infer_lane_and_ffl_enforcement($request);

        return [
            'label' => 'Sports South validation (local)',
            'max_unique' => 100,
            'inventory_keys' => ['inventory_quantity'],
            'unknown_qty_blocks' => true,
            'code_prefix' => 'SPORTS_SOUTH',
            'lane' => $lane['lane'],
            'enforce_ffl_required' => $lane['enforce_ffl_required'],
            'ffl_required_row_keys' => ['ffl_required'],
        ];
    }

    protected function validation_precheck_invariants(DistributorOrderRequest $request, bool $local_only): ?DistributorOrderValidationResult
    {
        if (strtolower(trim((string) ($request->lane ?? ''))) === 'dealer_fulfilled') {
            return null;
        }

        return $this->require_ffl_shipto_if_ffl_lines($request, 'SPORTS_SOUTH');
    }

    public function validate_order_request(
        DistributorOrderRequest $request,
        bool $local_only = false
    ): DistributorOrderValidationResult {
        $t0 = microtime(true);
        $trace_id = self::new_trace_id();
        $this->log('Sports South validation start.', [
            'trace_id' => $trace_id,
            'request' => $this->summarize_order_request($request),
            'local_only' => $local_only ? 1 : 0,
        ]);

        try {
            $result = parent::validate_order_request($request, $local_only);
        } catch (\Throwable $e) {
            $this->profile('Sports South validation exception', $t0, [
                'trace_id' => $trace_id,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => (int) $e->getLine(),
            ]);

            $result = $this->classify_sports_south_validation_exception($e, [
                'trace_id' => $trace_id,
                'request' => $this->summarize_order_request($request),
                'local_only' => $local_only ? 1 : 0,
            ]);

            $this->log('Sports South validation exception classified.', [
                'trace_id' => $trace_id,
                'result' => self::summarize_validation_result($result),
            ]);

            return $result;
        }

        $this->profile('Sports South validation complete', $t0, [
            'trace_id' => $trace_id,
            'result' => self::summarize_validation_result($result),
        ]);

        return $result;
    }

    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        $t0 = microtime(true);
        $trace_id = self::new_trace_id();
        $this->log('Sports South place_order start.', [
            'trace_id' => $trace_id,
            'request' => $this->summarize_order_request($request),
            'orders_endpoint' => $this->get_orders_api_base_url(),
        ]);

        try {
            $result = parent::place_order($request);
        } catch (\Throwable $e) {
            $this->profile('Sports South place_order exception', $t0, [
                'trace_id' => $trace_id,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => (int) $e->getLine(),
            ]);

            $result = $this->classify_sports_south_exception_as_order_result(
                $e,
                'Sports South place_order',
                [
                    'trace_id' => $trace_id,
                    'request' => $this->summarize_order_request($request),
                ]
            );

            $this->log('Sports South place_order exception classified.', [
                'trace_id' => $trace_id,
                'result' => self::summarize_order_result($result),
            ]);

            return $result;
        }

        $this->profile('Sports South place_order complete', $t0, [
            'trace_id' => $trace_id,
            'result' => self::summarize_order_result($result),
        ]);

        return $result;
    }

    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        $po_number = trim((string) $po_number);
        if ($po_number === '') {
            return null;
        }

        $client = $this->make_invoices_client(60);
        if (!$client->has_credentials()) {
            return null;
        }

        $resp = $client->get_tracking_by_po($po_number);
        if (empty($resp['ok'])) {
            return null;
        }

        $rows = isset($resp['rows']) && is_array($resp['rows'])
            ? (array) $resp['rows']
            : [];

        $tracking_numbers = [];
        $invoice_numbers = [];
        $ship_dates = [];
        $weights = [];
        $shipping_service = null;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach (self::split_tracking_numbers((string) ($row['TRACKNO'] ?? '')) as $tracking) {
                $tracking_numbers[] = $tracking;
            }

            $invoice = trim((string) ($row['INVNO'] ?? ''));
            if ($invoice !== '') {
                $invoice_numbers[] = $invoice;
            }

            $ship_date = trim((string) ($row['SHPDTE'] ?? ''));
            if ($ship_date !== '') {
                $ship_dates[] = $ship_date;
            }

            $weight = trim((string) ($row['PKGWT'] ?? ''));
            if ($weight !== '') {
                $weights[] = $weight;
            }

            if ($shipping_service === null) {
                $service = trim((string) ($row['SERVICE'] ?? ''));
                if ($service !== '') {
                    $shipping_service = $this->normalize_carrier($service) ?? $service;
                }
            }
        }

        $scalar = (string) ($resp['scalar'] ?? '');
        if (empty($tracking_numbers) && strpos($scalar, '<') === false) {
            foreach (self::split_tracking_numbers($scalar) as $tracking) {
                $tracking_numbers[] = $tracking;
            }
        }

        $tracking_numbers = array_values(array_unique(array_filter($tracking_numbers)));
        $invoice_numbers = array_values(array_unique(array_filter($invoice_numbers)));
        $ship_dates = array_values(array_unique(array_filter($ship_dates)));
        $weights = array_values(array_unique(array_filter($weights)));
        sort($tracking_numbers, SORT_STRING);
        sort($invoice_numbers, SORT_STRING);
        sort($ship_dates, SORT_STRING);
        sort($weights, SORT_STRING);

        if (empty($tracking_numbers)) {
            return null;
        }

        if ($shipping_service === null) {
            $shipping_service = $this->infer_carrier_from_tracking((string) ($tracking_numbers[0] ?? ''));
        }

        return new DistributorShipment(
            $tracking_numbers,
            $invoice_numbers,
            $shipping_service,
            !empty($weights) ? implode(', ', $weights) : null,
            [
                'po_number' => $po_number,
                'rows' => $rows,
                'row_count' => count($rows),
                'ship_dates' => $ship_dates,
                'http_status' => (int) ($resp['status'] ?? 0),
            ]
        );
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $cost = apply_filters('fflhub_sports_south_flat_shipping_cost', self::FLAT_SHIPPING_COST, $normalized, $this);

        return is_numeric($cost) ? max(0.0, (float) $cost) : self::FLAT_SHIPPING_COST;
    }

    protected function get_shipping_cost_from_row(array $row, string $normalized_upc): ?float
    {
        if (array_key_exists('shipping_cost', $row)) {
            $raw = trim((string) ($row['shipping_cost'] ?? ''));
            if ($raw !== '' && is_numeric($raw)) {
                return max(0.0, (float) $raw);
            }
        }

        return parent::get_shipping_cost_from_row($row, $normalized_upc);
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
            $this->log('Sports South order precheck blocked by base precheck.', [
                'result' => self::summarize_order_result($base),
            ]);
            return $base;
        }

        if ($this->get_customer_number() === '' || $this->get_username() === '' || $this->get_password() === '') {
            $this->log('Sports South order precheck failed: missing credentials.', [
                'has_customer_number' => $this->get_customer_number() !== '' ? 1 : 0,
                'has_username' => $this->get_username() !== '' ? 1 : 0,
                'has_password' => $this->get_password() !== '' ? 1 : 0,
            ]);

            return DistributorOrderResult::block_fatal(
                'Sports South: missing order credentials.',
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS]
            );
        }

        $po = $this->sanitize_and_truncate_po((string) $request->merchant_order_id, 32);
        if ($po === '') {
            $this->log('Sports South order precheck failed: missing merchant PO.', [
                'raw_po_present' => trim((string) $request->merchant_order_id) !== '' ? 1 : 0,
            ]);

            return DistributorOrderResult::block_fatal(
                'Sports South: missing merchant PO.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        return null;
    }

    protected function place_order_lane(
        DistributorOrderRequest $request,
        string $lane,
        array $lines,
        array &$external_ids
    ): DistributorOrderResult {
        $t0 = microtime(true);
        $trace_id = self::new_trace_id();
        $lane = strtolower(trim((string) $lane));
        $this->log('Sports South lane placement start.', [
            'trace_id' => $trace_id,
            'lane' => $lane,
            'line_count' => count($lines),
            'lines' => $this->summarize_lines($lines),
            'external_ids_before' => $external_ids,
        ]);

        if ($lane !== 'dealer_fulfilled' && $lane !== 'direct_ship_non_ffl' && $lane !== 'direct_ship_ffl') {
            $result = DistributorOrderResult::block_fatal(
                'Sports South: unsupported lane "' . $lane . '".',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
            $this->profile('Sports South lane placement blocked', $t0, [
                'trace_id' => $trace_id,
                'lane' => $lane,
                'result' => self::summarize_order_result($result),
            ]);

            return $result;
        }

        $details = $this->build_sports_south_detail_rows($lines);
        if ($details instanceof DistributorOrderResult) {
            $details->external_order_ids = $external_ids;
            $this->profile('Sports South detail mapping failed', $t0, [
                'trace_id' => $trace_id,
                'lane' => $lane,
                'result' => self::summarize_order_result($details),
            ]);
            return $details;
        }

        $this->log('Sports South detail rows prepared.', [
            'trace_id' => $trace_id,
            'lane' => $lane,
            'detail_count' => count($details),
            'details' => array_map([$this, 'summarize_detail_row'], $details),
        ]);

        $po = $this->sanitize_and_truncate_po((string) $request->merchant_order_id, 32);
        if ($po === '') {
            $po = 'SS' . gmdate('YmdHis');
        }

        $header = $this->build_header_params($request, $lane, $po);
        if ($header instanceof DistributorOrderResult) {
            $header->external_order_ids = $external_ids;
            $this->profile('Sports South header build failed', $t0, [
                'trace_id' => $trace_id,
                'lane' => $lane,
                'po' => $po,
                'result' => self::summarize_order_result($header),
            ]);
            return $header;
        }

        $firearm_fulfillment = null;
        if ($lane === 'direct_ship_ffl') {
            $firearm_fulfillment = $this->build_firearm_fulfillment_context($request);
            if ($firearm_fulfillment instanceof DistributorOrderResult) {
                $firearm_fulfillment->external_order_ids = $external_ids;
                $this->profile('Sports South firearm fulfillment context failed', $t0, [
                    'trace_id' => $trace_id,
                    'lane' => $lane,
                    'po' => $po,
                    'result' => self::summarize_order_result($firearm_fulfillment),
                ]);
                return $firearm_fulfillment;
            }
        }

        $client = $this->make_orders_client(60);
        $endpoint = rtrim($this->get_orders_api_base_url(), '/');
        $this->log('Sports South header row prepared.', [
            'trace_id' => $trace_id,
            'lane' => $lane,
            'po' => $po,
            'endpoint' => $endpoint,
            'header' => self::summarize_header_for_log($header),
        ]);

        if ($this->is_test_order_debug_enabled()) {
            $debug_operations = [
                'AddHeader' => $header,
                'AddDetail' => $details,
                'Submit' => ['OrderNumber' => '{AddHeaderResult}'],
            ];
            $debug_endpoint = $endpoint . '/AddHeader + /AddDetail + /Submit';

            if (is_array($firearm_fulfillment)) {
                $debug_operations = [
                    'TransferDocumentsRequired' => [
                        'FFL' => (string) $firearm_fulfillment['ffl'],
                    ],
                    'AddHeader' => $header,
                    'AddShipInstructions' => [
                        'SystemOrderNumber' => '{AddHeaderResult}',
                        'ShipInst1' => (string) $firearm_fulfillment['ship_inst_1'],
                        'ShipInst2' => (string) $firearm_fulfillment['ship_inst_2'],
                    ],
                    'AddDetail' => $details,
                    'Submit' => ['OrderNumber' => '{AddHeaderResult}'],
                ];
                $debug_endpoint = $endpoint
                    . '/TransferDocumentsRequired + /AddHeader + /AddShipInstructions + /AddDetail + /Submit';
            }

            $result = $this->build_test_order_debug_block(
                $lane,
                $debug_endpoint,
                'POST',
                'form',
                $this->encode_debug_json_payload($debug_operations),
                [
                    'po' => $po,
                    'item_count' => count($details),
                    'ship_via' => (string) ($header['ShipVIA'] ?? ''),
                ],
                $external_ids
            );
            $this->profile('Sports South test order debug block', $t0, [
                'trace_id' => $trace_id,
                'lane' => $lane,
                'po' => $po,
                'result' => self::summarize_order_result($result),
            ]);

            return $result;
        }

        if (is_array($firearm_fulfillment)) {
            $transferResp = $client->transfer_documents_required((string) $firearm_fulfillment['ffl']);
            $this->log('Sports South TransferDocumentsRequired completed.', [
                'trace_id' => $trace_id,
                'lane' => $lane,
                'po' => $po,
                'ffl_tail4' => self::tail4((string) $firearm_fulfillment['ffl']),
                'response' => self::summarize_api_response($transferResp),
            ]);

            if (empty($transferResp['ok'])) {
                $failure = $this->classify_sports_south_failure(
                    $transferResp,
                    'Sports South TransferDocumentsRequired',
                    [
                        'lane' => $lane,
                        'po' => $po,
                        'ffl_tail4' => self::tail4((string) $firearm_fulfillment['ffl']),
                    ],
                    $external_ids
                );
                $this->profile('Sports South TransferDocumentsRequired failed', $t0, [
                    'trace_id' => $trace_id,
                    'lane' => $lane,
                    'po' => $po,
                    'result' => self::summarize_order_result($failure),
                ]);
                return $failure;
            }

            $decision = strtoupper(trim((string) ($transferResp['decision'] ?? '')));
            if ($decision === 'N') {
                return DistributorOrderResult::block_fatal(
                    'Sports South firearm fulfillment: receiving dealer does not accept transfers.',
                    [DistributorOrderResult::REASON_FATAL_RESTRICTED],
                    [
                        'lane' => $lane,
                        'po' => $po,
                        'ffl_tail4' => self::tail4((string) $firearm_fulfillment['ffl']),
                        'transfer_decision' => $decision,
                    ],
                    (int) ($transferResp['status'] ?? 0),
                    'TransferDocumentsRequired:N',
                    $external_ids
                );
            }

            if ($decision === 'E') {
                return DistributorOrderResult::block_retryable(
                    'Sports South firearm fulfillment preflight returned an error; no order header was created.',
                    [DistributorOrderResult::REASON_RETRY_UPSTREAM],
                    [
                        'lane' => $lane,
                        'po' => $po,
                        'ffl_tail4' => self::tail4((string) $firearm_fulfillment['ffl']),
                        'transfer_decision' => $decision,
                    ],
                    (int) ($transferResp['status'] ?? 0),
                    'TransferDocumentsRequired:E',
                    $external_ids
                );
            }
        }

        $headerResp = $client->add_header($header);
        $this->log('Sports South AddHeader completed.', [
            'trace_id' => $trace_id,
            'lane' => $lane,
            'po' => $po,
            'response' => self::summarize_api_response($headerResp),
        ]);
        if (empty($headerResp['ok'])) {
            $failure = $this->classify_sports_south_failure($headerResp, 'Sports South AddHeader', [
                'lane' => $lane,
                'po' => $po,
                'item_count' => count($details),
            ]);
            $failure->external_order_ids = $external_ids;
            $this->profile('Sports South AddHeader failed', $t0, [
                'trace_id' => $trace_id,
                'lane' => $lane,
                'po' => $po,
                'result' => self::summarize_order_result($failure),
            ]);
            return $failure;
        }

        $ssOrderNumber = (string) ($headerResp['order_number'] ?? '');
        if ($ssOrderNumber !== '') {
            $external_ids[] = $ssOrderNumber;
        }

        if (is_array($firearm_fulfillment)) {
            $instructionsResp = $this->add_firearm_ship_instructions_with_retry(
                $client,
                $ssOrderNumber,
                (string) $firearm_fulfillment['ship_inst_1'],
                (string) $firearm_fulfillment['ship_inst_2'],
                $trace_id,
                $po
            );

            if (empty($instructionsResp['ok'])) {
                $failure = $this->recover_open_order_failure(
                    $client,
                    $instructionsResp,
                    'Sports South AddShipInstructions',
                    $ssOrderNumber,
                    [
                        'lane' => $lane,
                        'po' => $po,
                        'ffl_tail4' => self::tail4((string) $firearm_fulfillment['ffl']),
                        'instruction_attempts' => (int) ($instructionsResp['attempts'] ?? 0),
                    ],
                    $external_ids,
                    true
                );
                $this->profile('Sports South AddShipInstructions failed', $t0, [
                    'trace_id' => $trace_id,
                    'lane' => $lane,
                    'po' => $po,
                    'sports_south_order_number' => $ssOrderNumber,
                    'result' => self::summarize_order_result($failure),
                ]);
                return $failure;
            }
        }

        foreach ($details as $detail_index => $detail) {
            $detailResp = $client->add_detail($ssOrderNumber, $detail);
            $this->log('Sports South AddDetail completed.', [
                'trace_id' => $trace_id,
                'lane' => $lane,
                'po' => $po,
                'sports_south_order_number' => $ssOrderNumber,
                'detail_index' => (int) $detail_index,
                'item' => $this->summarize_detail_row($detail),
                'response' => self::summarize_api_response($detailResp),
            ]);
            if (empty($detailResp['ok'])) {
                $failure = $this->recover_open_order_failure(
                    $client,
                    $detailResp,
                    'Sports South AddDetail',
                    $ssOrderNumber,
                    [
                        'lane' => $lane,
                        'po' => $po,
                        'sports_south_order_number' => $ssOrderNumber,
                        'item' => $this->summarize_detail_row($detail),
                    ],
                    $external_ids
                );
                $this->profile('Sports South AddDetail failed', $t0, [
                    'trace_id' => $trace_id,
                    'lane' => $lane,
                    'po' => $po,
                    'sports_south_order_number' => $ssOrderNumber,
                    'detail_index' => (int) $detail_index,
                    'result' => self::summarize_order_result($failure),
                ]);
                return $failure;
            }
        }

        $submitResp = $client->submit($ssOrderNumber);
        $this->log('Sports South Submit completed.', [
            'trace_id' => $trace_id,
            'lane' => $lane,
            'po' => $po,
            'sports_south_order_number' => $ssOrderNumber,
            'response' => self::summarize_api_response($submitResp),
        ]);
        if (empty($submitResp['ok'])) {
            $failure = $this->classify_sports_south_failure($submitResp, 'Sports South Submit', [
                'lane' => $lane,
                'po' => $po,
                'sports_south_order_number' => $ssOrderNumber,
                'item_count' => count($details),
            ], $external_ids);
            $failure->external_order_ids = $external_ids;
            $this->profile('Sports South Submit failed', $t0, [
                'trace_id' => $trace_id,
                'lane' => $lane,
                'po' => $po,
                'sports_south_order_number' => $ssOrderNumber,
                'result' => self::summarize_order_result($failure),
            ]);
            return $failure;
        }

        $ffl_notice_sent = false;
        if ($lane === 'direct_ship_ffl') {
            $ffl_notice_sent = $this->send_ffl_email_notice($request, $po, $ssOrderNumber, $details);
        }

        $result = DistributorOrderResult::ok(
            'Sports South ' . $this->lane_label($lane) . ' order submitted.',
            $external_ids,
            [
                'po' => $po,
                'sports_south_order_number' => $ssOrderNumber,
                'item_count' => count($details),
                'ffl_notice_sent' => $ffl_notice_sent ? 1 : 0,
            ]
        );
        $this->profile('Sports South lane placement complete', $t0, [
            'trace_id' => $trace_id,
            'lane' => $lane,
            'po' => $po,
            'result' => self::summarize_order_result($result),
        ]);

        return $result;
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
        if (SportsSouthAccessoriesOnlyPolicy::should_skip_row($row)) {
            return null;
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
            [DistributorProductCategoryMapper::class, 'map_sports_south'],
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
     * @param array<int,mixed> $lines
     * @return array<int,array<string,string>>|DistributorOrderResult
     */
    private function build_sports_south_detail_rows(array $lines)
    {
        return $this->map_order_lines_to_items(
            $lines,
            function (string $normalized_upc, string $raw_upc, DistributorOrderLine $line): ?string {
                $row = $this->get_fulfillment_row_for_upc($normalized_upc);
                if ($row === null) {
                    return null;
                }

                $itemNumber = trim((string) $this->get_string_field($row, ['sports_south_item_number']));
                return $itemNumber !== '' ? $itemNumber : null;
            },
            function (string $itemNumber, int $qty, string $normalized_upc, string $raw_upc, DistributorOrderLine $line): array {
                $row = $this->get_fulfillment_row_for_upc($normalized_upc) ?: [];
                $price = $this->get_float_field($row, ['distributor_price', 'catalog_price']);
                $name = trim((string) $this->get_string_field($row, ['product_name']));

                return [
                    'SSItemNumber' => $itemNumber,
                    'Quantity' => (string) $qty,
                    'OrderPrice' => number_format(max(0.0, (float) ($price ?? 0.0)), 2, '.', ''),
                    'CustomerItemNumber' => self::truncate_string($normalized_upc, 32),
                    'CustomerItemDescription' => self::truncate_string($name !== '' ? $name : $raw_upc, 80),
                ];
            },
            'Sports South: cannot map UPC to ITEMNO: %s',
            true,
            'Sports South: no valid items after normalization.'
        );
    }

    /**
     * @return array<string,string>|DistributorOrderResult
     */
    private function build_header_params(DistributorOrderRequest $request, string $lane, string $po)
    {
        $notes = self::truncate_string(self::normalize_payload_string((string) $request->notes), 255);

        $header = [
            'PO' => $po,
            'CustomerOrderNumber' => self::truncate_string($po, 32),
            'SalesMessage' => $notes,
            'ShipVIA' => $this->normalize_ship_via($this->get_order_ship_via()),
            'AdultSignature' => $this->bool_string($this->get_order_bool_option('order_adult_signature', false)),
            'Signature' => $this->bool_string($this->get_order_bool_option('order_signature', false)),
            'Insurance' => $this->bool_string($this->get_order_bool_option('order_insurance', false)),
        ];

        if ($lane === 'dealer_fulfilled') {
            return array_merge($header, [
                'ShipToName' => '',
                'ShipToAttn' => '',
                'ShipToAddr1' => '',
                'ShipToAddr2' => '',
                'ShipToCity' => '',
                'ShipToState' => '',
                'ShipToZip' => '0',
                'ShipToPhone' => '',
            ]);
        }

        $ship_to = $request->ship_to_customer;
        if ($lane === 'direct_ship_ffl') {
            $ship_to = $request->ship_to_ffl;
        }

        if (!($ship_to instanceof DistributorShipTo)) {
            return DistributorOrderResult::block_fatal(
                $lane === 'direct_ship_ffl'
                    ? 'Sports South FFL fulfillment: missing ship_to_ffl.'
                    : 'Sports South fulfillment: missing ship_to_customer.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        $shipCheck = self::validate_shipto_minimum($ship_to);
        if (empty($shipCheck['ok'])) {
            return DistributorOrderResult::block_fatal(
                'Sports South fulfillment: ' . (string) ($shipCheck['message'] ?? 'Invalid ship-to.'),
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                ['ship_check' => $shipCheck]
            );
        }

        if ($this->format_sports_south_phone($ship_to->phone) === '') {
            return DistributorOrderResult::block_fatal(
                'Sports South fulfillment: ship-to phone must contain a valid 10-digit US phone number.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        $attention = '';
        if ($lane === 'direct_ship_ffl') {
            $attention = trim((string) $request->ship_to_customer->name);
            if ($attention === '') {
                return DistributorOrderResult::block_fatal(
                    'Sports South FFL fulfillment: customer name is required for ShipToAttn.',
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
                );
            }
        }

        return array_merge($header, $this->build_ship_to_header_params($ship_to, $attention));
    }

    /**
     * Build the exact FFL and customer-phone instructions required by Sports
     * South before any external order is created.
     *
     * @return array{ffl:string,customer_phone:string,ship_inst_1:string,ship_inst_2:string}|DistributorOrderResult
     */
    private function build_firearm_fulfillment_context(DistributorOrderRequest $request)
    {
        $ffl = FFLRowMapper::normalize_ffl_number((string) $request->receiving_ffl_number);
        if ($ffl === '') {
            return DistributorOrderResult::block_fatal(
                'Sports South firearm fulfillment: receiving FFL number is missing or invalid.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        $customer_phone = $this->format_sports_south_phone($request->ship_to_customer->phone);
        if ($customer_phone === '') {
            return DistributorOrderResult::block_fatal(
                'Sports South firearm fulfillment: customer phone must contain a valid 10-digit US phone number.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        return [
            'ffl' => $ffl,
            'customer_phone' => $customer_phone,
            'ship_inst_1' => 'FFL # ' . $ffl,
            'ship_inst_2' => 'PHONE # ' . $customer_phone,
        ];
    }

    /**
     * Sports South requires exactly ten phone digits. Accept a leading US
     * country code, but reject incomplete or ambiguous values.
     */
    private function format_sports_south_phone(string $phone): string
    {
        $digits = self::extract_digits($phone);
        if (strlen($digits) === 11 && substr($digits, 0, 1) === '1') {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? $digits : '';
    }

    /**
     * The fulfillment guide explicitly says to retry AddShipInstructions when
     * it returns false. Keep retries inside this placement attempt so we do not
     * create another AddHeader merely because the instruction call was flaky.
     *
     * @return array<string,mixed>
     */
    private function add_firearm_ship_instructions_with_retry(
        SportsSouthOrdersClient $client,
        string $systemOrderNumber,
        string $shipInst1,
        string $shipInst2,
        string $traceId,
        string $po
    ): array {
        $last_response = [];

        for ($attempt = 1; $attempt <= self::SHIP_INSTRUCTION_MAX_ATTEMPTS; $attempt++) {
            $last_response = $client->add_ship_instructions(
                $systemOrderNumber,
                $shipInst1,
                $shipInst2
            );
            $last_response['attempts'] = $attempt;

            $this->log('Sports South AddShipInstructions completed.', [
                'trace_id' => $traceId,
                'po' => $po,
                'sports_south_order_number' => $systemOrderNumber,
                'attempt' => $attempt,
                'response' => self::summarize_api_response($last_response),
            ]);

            if (!empty($last_response['ok'])) {
                return $last_response;
            }

            if ($attempt < self::SHIP_INSTRUCTION_MAX_ATTEMPTS) {
                usleep(250000 * $attempt);
            }
        }

        return $last_response;
    }

    /**
     * Remove an unsubmitted header before returning a placement failure. A
     * confirmed delete makes later retries safe; an unconfirmed delete stops
     * automation and leaves the external order number for manual inspection.
     *
     * @param array<string,mixed> $failureResponse
     * @param array<string,mixed> $details
     * @param string[] $externalIds
     */
    private function recover_open_order_failure(
        SportsSouthOrdersClient $client,
        array $failureResponse,
        string $context,
        string $sportsSouthOrderNumber,
        array $details,
        array $externalIds,
        bool $forceRetryable = false
    ): DistributorOrderResult {
        $delete_response = $client->delete_open_order($sportsSouthOrderNumber);
        $this->log('Sports South DeleteOpenOrder completed after placement failure.', [
            'context' => $context,
            'sports_south_order_number' => $sportsSouthOrderNumber,
            'response' => self::summarize_api_response($delete_response),
        ]);

        $details['open_order_cleanup'] = [
            'attempted' => 1,
            'deleted' => !empty($delete_response['ok']) ? 1 : 0,
            'response' => self::summarize_api_response($delete_response),
        ];

        if (!empty($delete_response['ok'])) {
            if ($forceRetryable) {
                return DistributorOrderResult::block_retryable(
                    $context . ' failed after retries; the unsubmitted Sports South order was deleted safely.',
                    [DistributorOrderResult::REASON_RETRY_UPSTREAM],
                    $details,
                    (int) ($failureResponse['status'] ?? 0),
                    trim((string) ($failureResponse['operation'] ?? $context)),
                    $externalIds
                );
            }

            return $this->classify_sports_south_failure(
                $failureResponse,
                $context,
                $details,
                $externalIds
            );
        }

        return DistributorOrderResult::manual(
            $context . ' failed and cleanup of the unsubmitted Sports South order could not be confirmed; automatic retry stopped to prevent a duplicate.',
            [DistributorOrderResult::REASON_MANUAL_REQUIRED],
            $details,
            (int) ($failureResponse['status'] ?? 0),
            'DeleteOpenOrder',
            $externalIds
        );
    }

    /**
     * Sports South receives the FFL number electronically, but the license PDF
     * still must be emailed separately. This internal notice supplies the exact
     * recipient and message format without pretending we possess an attachment.
     *
     * @param array<int,array<string,string>> $details
     */
    private function send_ffl_email_notice(
        DistributorOrderRequest $request,
        string $po,
        string $sportsSouthOrderNumber,
        array $details
    ): bool {
        if (!function_exists('wp_mail')) {
            $this->log('Sports South FFL notice email skipped: wp_mail unavailable.', [
                'po' => $po,
                'sports_south_order_number' => $sportsSouthOrderNumber,
            ]);
            return false;
        }

        $recipient_candidates = preg_split('/[,;\s]+/', Options::get_batch_order_notification_email()) ?: [];

        /** @var mixed $filtered */
        $filtered = apply_filters(
            'fflhub_sports_south_ffl_notice_recipients',
            $recipient_candidates,
            $request,
            $po,
            $sportsSouthOrderNumber
        );

        $recipient_candidates = [];
        if (is_string($filtered)) {
            $recipient_candidates = preg_split('/[,;\s]+/', $filtered) ?: [];
        } elseif (is_array($filtered)) {
            $recipient_candidates = $filtered;
        }

        $recipients = [];
        foreach ($recipient_candidates as $candidate) {
            $email = sanitize_email((string) $candidate);
            if ($email !== '' && is_email($email)) {
                $recipients[$email] = true;
            }
        }

        if (empty($recipients)) {
            $this->log('Sports South FFL notice email skipped: no recipients.', [
                'po' => $po,
                'sports_south_order_number' => $sportsSouthOrderNumber,
            ]);
            return false;
        }

        $subject = sprintf(
            '[FFL Hub] Sports South FFL info required - PO %s',
            $po !== '' ? $po : $sportsSouthOrderNumber
        );

        $body = $this->build_ffl_email_notice_body($request, $po, $sportsSouthOrderNumber, $details);

        /** @var mixed $subject_filtered */
        $subject_filtered = apply_filters(
            'fflhub_sports_south_ffl_notice_subject',
            $subject,
            $request,
            $po,
            $sportsSouthOrderNumber
        );
        if (is_string($subject_filtered) && trim($subject_filtered) !== '') {
            $subject = trim($subject_filtered);
        }

        /** @var mixed $body_filtered */
        $body_filtered = apply_filters(
            'fflhub_sports_south_ffl_notice_body',
            $body,
            $request,
            $po,
            $sportsSouthOrderNumber,
            $details
        );
        if (is_string($body_filtered) && trim($body_filtered) !== '') {
            $body = $body_filtered;
        }

        $sent = wp_mail(array_keys($recipients), $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
        $this->log($sent ? 'Sports South FFL notice email sent.' : 'Sports South FFL notice email failed.', [
            'po' => $po,
            'sports_south_order_number' => $sportsSouthOrderNumber,
            'recipient_count' => count($recipients),
        ]);

        return (bool) $sent;
    }

    /**
     * @param array<int,array<string,string>> $details
     */
    private function build_ffl_email_notice_body(
        DistributorOrderRequest $request,
        string $po,
        string $sportsSouthOrderNumber,
        array $details
    ): string {
        $ffl = $request->ship_to_ffl;
        $customer = $request->ship_to_customer;
        $formatted_ffl = FFLRowMapper::normalize_ffl_number((string) $request->receiving_ffl_number);
        $ffl_contact = $ffl instanceof DistributorShipTo ? trim((string) $ffl->name) : '';
        $ffl_phone = $ffl instanceof DistributorShipTo ? trim((string) $ffl->phone) : '';
        $sports_south_subject = $this->get_customer_number() . ' - ' . $po;
        $sports_south_body = ($ffl_contact !== '' ? $ffl_contact : 'FFL contact person')
            . ' - '
            . ($ffl_phone !== '' ? $ffl_phone : 'FFL phone number');

        $lines = [
            'Sports South FFL dropship order submitted.',
            '',
            'Action required: email a copy of the matching FFL within three business days.',
            'To: ' . self::FFL_DOCUMENT_EMAIL,
            'Subject: ' . $sports_south_subject,
            'Body: ' . $sports_south_body,
            'Attachment: Copy of the receiving FFL, preferably PDF.',
            '',
            'Merchant PO: ' . ($po !== '' ? $po : '-'),
            'Sports South Order Number: ' . ($sportsSouthOrderNumber !== '' ? $sportsSouthOrderNumber : '-'),
            'Receiving FFL Number: ' . ($formatted_ffl !== '' ? $formatted_ffl : '-'),
            '',
            'Receiving FFL:',
        ];

        if ($ffl instanceof DistributorShipTo) {
            $lines = array_merge($lines, $this->format_ship_to_notice_lines($ffl));
        } else {
            $lines[] = '  -';
        }

        $lines[] = '';
        $lines[] = 'Customer:';
        $lines = array_merge($lines, $this->format_ship_to_notice_lines($customer));

        $lines[] = '';
        $lines[] = 'Items:';
        foreach ($details as $detail) {
            $item = trim((string) ($detail['SSItemNumber'] ?? ''));
            $qty = trim((string) ($detail['Quantity'] ?? ''));
            $upc = trim((string) ($detail['CustomerItemNumber'] ?? ''));
            $desc = trim((string) ($detail['CustomerItemDescription'] ?? ''));

            $lines[] = sprintf(
                '  - SSItemNumber: %s | Qty: %s | UPC: %s | %s',
                $item !== '' ? $item : '-',
                $qty !== '' ? $qty : '-',
                $upc !== '' ? $upc : '-',
                $desc !== '' ? $desc : '-'
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int,string>
     */
    private function format_ship_to_notice_lines(DistributorShipTo $ship): array
    {
        $name = trim((string) $ship->name);
        $company = trim((string) $ship->company);
        $address2 = trim((string) $ship->address2);

        $lines = [
            '  Name: ' . ($name !== '' ? $name : '-'),
            '  Company: ' . ($company !== '' ? $company : '-'),
            '  Address 1: ' . (trim((string) $ship->address1) !== '' ? trim((string) $ship->address1) : '-'),
        ];

        if ($address2 !== '') {
            $lines[] = '  Address 2: ' . $address2;
        }

        $lines[] = '  City/State/ZIP: ' . trim(sprintf(
            '%s, %s %s',
            trim((string) $ship->city) !== '' ? trim((string) $ship->city) : '-',
            trim((string) $ship->state) !== '' ? trim((string) $ship->state) : '-',
            trim((string) $ship->zip) !== '' ? trim((string) $ship->zip) : '-'
        ));
        $lines[] = '  Phone: ' . (trim((string) $ship->phone) !== '' ? trim((string) $ship->phone) : '-');
        $lines[] = '  Email: ' . (trim((string) $ship->email) !== '' ? trim((string) $ship->email) : '-');

        return $lines;
    }

    /**
     * @return array<string,string>
     */
    private function build_ship_to_header_params(DistributorShipTo $ship, string $attention = ''): array
    {
        $name = trim((string) ($ship->name !== '' ? $ship->name : $ship->company));
        $attn = trim($attention);
        if ($attn === '') {
            $attn = trim((string) ($ship->company !== '' && $ship->company !== $name ? $ship->company : $ship->name));
        }

        return [
            'ShipToName' => self::truncate_string(self::normalize_payload_string($name), 40),
            'ShipToAttn' => self::truncate_string(self::normalize_payload_string($attn), 40),
            'ShipToAddr1' => self::truncate_string(self::normalize_payload_string($ship->address1), 40),
            'ShipToAddr2' => self::truncate_string(self::normalize_payload_string($ship->address2), 40),
            'ShipToCity' => self::truncate_string(self::normalize_payload_string($ship->city), 30),
            'ShipToState' => self::format_us_state2_best_effort($ship->state),
            'ShipToZip' => self::format_us_zip5_best_effort($ship->zip),
            'ShipToPhone' => $this->format_sports_south_phone($ship->phone),
        ];
    }

    /**
     * @return array<string,mixed>|null
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

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
        if (!$row) {
            return null;
        }

        if (is_array($row)) {
            return $row;
        }

        return is_object($row) ? get_object_vars($row) : null;
    }

    private function make_orders_client(int $timeoutSeconds = 60): SportsSouthOrdersClient
    {
        return new SportsSouthOrdersClient(
            $this->get_customer_number(),
            $this->get_username(),
            $this->get_password(),
            $this->get_source(),
            $this->get_orders_api_base_url(),
            $timeoutSeconds
        );
    }

    private function make_invoices_client(int $timeoutSeconds = 60): SportsSouthInvoicesClient
    {
        return new SportsSouthInvoicesClient(
            $this->get_customer_number(),
            $this->get_username(),
            $this->get_password(),
            $this->get_source(),
            $this->get_invoices_api_base_url(),
            $timeoutSeconds
        );
    }

    private function get_customer_number(): string
    {
        return trim((string) Options::get_distributor_option('sports_south', 'customer_number', ''));
    }

    private function get_username(): string
    {
        return trim((string) Options::get_distributor_option('sports_south', 'username', ''));
    }

    private function get_password(): string
    {
        return trim((string) Options::get_distributor_option('sports_south', 'password', ''));
    }

    private function get_source(): string
    {
        $source = trim((string) Options::get_distributor_option('sports_south', 'source', ''));
        return $source !== '' ? $source : $this->get_customer_number();
    }

    private function get_orders_api_base_url(): string
    {
        $url = trim((string) Options::get_distributor_option(
            'sports_south',
            'orders_api_base_url',
            SportsSouthOrdersClient::DEFAULT_BASE_URL
        ));
        $url = trim((string) apply_filters('fflhub_sports_south_orders_api_base_url', $url, $this));

        return $url !== '' ? $url : SportsSouthOrdersClient::DEFAULT_BASE_URL;
    }

    private function get_invoices_api_base_url(): string
    {
        $url = trim((string) Options::get_distributor_option(
            'sports_south',
            'invoices_api_base_url',
            SportsSouthInvoicesClient::DEFAULT_BASE_URL
        ));
        $url = trim((string) apply_filters('fflhub_sports_south_invoices_api_base_url', $url, $this));

        return $url !== '' ? $url : SportsSouthInvoicesClient::DEFAULT_BASE_URL;
    }

    private function get_order_ship_via(): string
    {
        $shipVia = trim((string) Options::get_distributor_option('sports_south', 'order_ship_via', self::DEFAULT_SHIP_VIA));
        return trim((string) apply_filters('fflhub_sports_south_order_ship_via', $shipVia, $this));
    }

    private function get_order_bool_option(string $key, bool $default): bool
    {
        $raw = Options::get_distributor_option('sports_south', $key, $default ? '1' : '0');
        $raw = apply_filters('fflhub_sports_south_' . $key, $raw, $this);

        return $this->to_boolish($raw, $default);
    }

    private function normalize_ship_via(string $shipVia): string
    {
        $shipVia = strtoupper(trim($shipVia));
        return in_array($shipVia, ['', 'G', '2', 'N'], true) ? $shipVia : self::DEFAULT_SHIP_VIA;
    }

    private function bool_string(bool $value): string
    {
        return $value ? 'True' : 'False';
    }

    private function lane_label(string $lane): string
    {
        $lane = strtolower(trim($lane));
        if ($lane === 'dealer_fulfilled') {
            return 'dealer-fulfilled';
        }
        if ($lane === 'direct_ship_non_ffl') {
            return 'customer fulfillment';
        }
        if ($lane === 'direct_ship_ffl') {
            return 'FFL fulfillment';
        }

        return 'order';
    }

    /**
     * @return string[]
     */
    private static function split_tracking_numbers(string $raw): array
    {
        $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        if ($raw === '') {
            return [];
        }

        $tokens = preg_split('/[\s,;|]+/', $raw);
        if (!is_array($tokens)) {
            return [];
        }

        $bad = [
            'pending' => true,
            'tbd' => true,
            'n/a' => true,
            'na' => true,
            'none' => true,
            'null' => true,
            'unknown' => true,
            '-' => true,
        ];

        $out = [];
        foreach ($tokens as $token) {
            $tracking = trim((string) $token);
            if ($tracking === '') {
                continue;
            }

            $lower = strtolower($tracking);
            if (isset($bad[$lower])) {
                continue;
            }

            if (strlen($tracking) < 8 || preg_match('/^[a-z]+$/i', $tracking)) {
                continue;
            }

            $out[] = $tracking;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string,mixed> $resp
     * @param array<string,mixed> $details
     * @param string[] $external_ids
     */
    private function classify_sports_south_failure(array $resp, string $ctx, array $details, array $external_ids = []): DistributorOrderResult
    {
        $http = (int) ($resp['status'] ?? 0);
        $msg = trim((string) ($resp['error'] ?? ''));
        if ($msg === '') {
            $msg = $ctx . ' failed.';
        }

        $operation = trim((string) ($resp['operation'] ?? $ctx));
        $provider = trim((string) ($resp['provider_error_code'] ?? ''));
        if ($provider === '') {
            $provider = $operation;
        }

        $safeDetails = array_merge($details, [
            'operation' => $operation,
            'http_status' => $http,
            'error' => $msg,
            'scalar' => self::excerpt_for_log((string) ($resp['scalar'] ?? ''), 500),
            'body_excerpt' => self::excerpt_for_log((string) ($resp['body_excerpt'] ?? ''), 1000),
            'response_bytes' => (int) ($resp['response_bytes'] ?? 0),
            'elapsed_ms' => (string) ($resp['elapsed_ms'] ?? ''),
            'url' => (string) ($resp['url'] ?? ''),
            'request' => isset($resp['request']) && is_array($resp['request']) ? $resp['request'] : [],
        ]);

        $scalarLc = strtolower(trim((string) ($resp['scalar'] ?? '')));
        $lc = strtolower($msg . ' ' . (string) ($resp['scalar'] ?? '') . ' ' . (string) ($resp['body_excerpt'] ?? ''));

        if ($http === 429) {
            return $this->sports_south_failure_result(
                true,
                $ctx,
                'rate limit (HTTP 429): ' . $msg,
                [DistributorOrderResult::REASON_RETRY_RATE_LIMIT],
                $safeDetails,
                $http,
                $provider,
                $external_ids
            );
        }

        if ($http === 408 || $http === 504) {
            return $this->sports_south_failure_result(
                true,
                $ctx,
                'timeout (HTTP ' . $http . '): ' . $msg,
                [DistributorOrderResult::REASON_RETRY_TIMEOUT],
                $safeDetails,
                $http,
                $provider,
                $external_ids
            );
        }

        if ($http === 502 || $http === 503 || $http >= 500) {
            return $this->sports_south_failure_result(
                true,
                $ctx,
                'upstream error (HTTP ' . $http . '): ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UPSTREAM],
                $safeDetails,
                $http,
                $provider,
                $external_ids
            );
        }

        if (
            $http === 401 ||
            $http === 403 ||
            strpos($lc, 'auth') !== false ||
            strpos($lc, 'not authorized') !== false ||
            strpos($lc, 'unauthorized') !== false ||
            strpos($lc, 'invalid password') !== false ||
            strpos($lc, 'invalid username') !== false
        ) {
            return $this->sports_south_failure_result(
                false,
                $ctx,
                'not authorized: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS],
                $safeDetails,
                $http,
                $provider,
                $external_ids
            );
        }

        if ($http === 400 || $http === 422) {
            return $this->sports_south_failure_result(
                false,
                $ctx,
                'bad request (HTTP ' . $http . '): ' . $msg,
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                $safeDetails,
                $http,
                $provider,
                $external_ids
            );
        }

        if (
            strpos($lc, 'quota') !== false ||
            strpos($lc, 'rate') !== false ||
            strpos($lc, 'throttle') !== false ||
            strpos($lc, 'too many') !== false ||
            strpos($lc, 'exceeded') !== false
        ) {
            return $this->sports_south_failure_result(
                true,
                $ctx,
                'rate/quota: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_RATE_LIMIT],
                $safeDetails,
                $http,
                $provider,
                $external_ids
            );
        }

        if (
            strpos($lc, 'timeout') !== false ||
            strpos($lc, 'timed out') !== false ||
            strpos($lc, 'could not resolve') !== false ||
            strpos($lc, 'connection') !== false ||
            strpos($lc, 'ssl') !== false ||
            strpos($lc, 'curl error 28') !== false
        ) {
            return $this->sports_south_failure_result(
                true,
                $ctx,
                'timeout/network: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_TIMEOUT],
                $safeDetails,
                $http,
                $provider,
                $external_ids
            );
        }

        if (
            strpos($lc, 'bad gateway') !== false ||
            strpos($lc, 'service unavailable') !== false ||
            strpos($lc, 'temporar') !== false ||
            strpos($lc, '502') !== false ||
            strpos($lc, '503') !== false
        ) {
            return $this->sports_south_failure_result(
                true,
                $ctx,
                'upstream: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UPSTREAM],
                $safeDetails,
                $http,
                $provider,
                $external_ids
            );
        }

        if (
            strpos($lc, 'restricted') !== false ||
            strpos($lc, 'restriction') !== false ||
            strpos($lc, 'cannot ship') !== false ||
            strpos($lc, 'not allowed') !== false ||
            strpos($lc, 'prohibited') !== false ||
            strpos($lc, 'denied') !== false ||
            strpos($lc, 'blocked') !== false
        ) {
            return $this->sports_south_failure_result(
                false,
                $ctx,
                'restricted: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_RESTRICTED],
                $safeDetails,
                $http,
                $provider,
                $external_ids
            );
        }

        if (
            strpos($lc, 'out of stock') !== false ||
            strpos($lc, 'insufficient') !== false ||
            strpos($lc, 'not enough') !== false ||
            strpos($lc, 'quantity available') !== false
        ) {
            return $this->sports_south_failure_result(
                false,
                $ctx,
                'out of stock: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_OUT_OF_STOCK],
                $safeDetails,
                $http,
                $provider,
                $external_ids
            );
        }

        if (in_array($scalarLc, ['false', '0'], true)) {
            return $this->sports_south_failure_result(
                false,
                $ctx,
                'provider rejected request: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                $safeDetails,
                $http,
                $provider,
                $external_ids
            );
        }

        if ($http === 0) {
            return $this->sports_south_failure_result(
                true,
                $ctx,
                'network/unknown transport failure: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UNKNOWN],
                $safeDetails,
                0,
                $provider,
                $external_ids
            );
        }

        if ($http >= 400 && $http < 500) {
            return $this->sports_south_failure_result(
                false,
                $ctx,
                'bad request (HTTP ' . $http . '): ' . $msg,
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                $safeDetails,
                $http,
                $provider,
                $external_ids
            );
        }

        return $this->sports_south_failure_result(
            false,
            $ctx,
            'order failed: ' . $msg,
            [DistributorOrderResult::REASON_FATAL_UNKNOWN],
            $safeDetails,
            $http,
            $provider,
            $external_ids
        );
    }

    /**
     * @param string[] $codes
     * @param array<string,mixed> $details
     * @param string[] $external_ids
     */
    private function sports_south_failure_result(
        bool $retryable,
        string $ctx,
        string $message,
        array $codes,
        array $details,
        int $http,
        string $provider,
        array $external_ids
    ): DistributorOrderResult {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));

        $this->log($retryable ? 'Sports South order API failure classified retryable.' : 'Sports South order API failure classified terminal.', [
            'context' => $ctx,
            'codes' => $codes,
            'http_status' => $http,
            'provider_error_code' => $provider,
            'details' => $details,
            'external_ids' => $external_ids,
        ]);

        if ($retryable) {
            return DistributorOrderResult::block_retryable(
                $ctx . ': ' . $message,
                $codes,
                $details,
                $http,
                $provider,
                $external_ids
            );
        }

        return DistributorOrderResult::block_fatal(
            $ctx . ': ' . $message,
            $codes,
            $details,
            $http,
            $provider,
            $external_ids
        );
    }

    /**
     * @param array<string,mixed> $details
     * @param string[] $external_ids
     */
    private function classify_sports_south_exception_as_order_result(
        \Throwable $e,
        string $prefix,
        array $details = [],
        array $external_ids = []
    ): DistributorOrderResult {
        $msg = (string) $e->getMessage();
        $lc = strtolower($msg);
        $details = array_merge($details, [
            'exception' => get_class($e),
            'error' => self::excerpt_for_log($msg, 1000),
            'file' => $e->getFile(),
            'line' => (int) $e->getLine(),
        ]);

        if (
            strpos($lc, 'quota') !== false ||
            strpos($lc, 'rate') !== false ||
            strpos($lc, 'throttle') !== false ||
            strpos($lc, 'too many') !== false ||
            strpos($lc, 'exceeded') !== false
        ) {
            return DistributorOrderResult::block_retryable(
                $prefix . ' exception: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_RATE_LIMIT],
                $details,
                0,
                'EXCEPTION',
                $external_ids
            );
        }

        if (
            strpos($lc, 'timeout') !== false ||
            strpos($lc, 'timed out') !== false ||
            strpos($lc, 'could not resolve') !== false ||
            strpos($lc, 'connection') !== false ||
            strpos($lc, 'ssl') !== false ||
            strpos($lc, 'curl error 28') !== false
        ) {
            return DistributorOrderResult::block_retryable(
                $prefix . ' exception: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_TIMEOUT],
                $details,
                0,
                'EXCEPTION',
                $external_ids
            );
        }

        if (
            strpos($lc, 'bad gateway') !== false ||
            strpos($lc, 'service unavailable') !== false ||
            strpos($lc, 'temporar') !== false ||
            strpos($lc, '502') !== false ||
            strpos($lc, '503') !== false
        ) {
            return DistributorOrderResult::block_retryable(
                $prefix . ' exception: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UPSTREAM],
                $details,
                0,
                'EXCEPTION',
                $external_ids
            );
        }

        if (
            strpos($lc, 'auth') !== false ||
            strpos($lc, 'not authorized') !== false ||
            strpos($lc, 'unauthorized') !== false ||
            strpos($lc, 'invalid password') !== false ||
            strpos($lc, 'invalid username') !== false
        ) {
            return DistributorOrderResult::block_fatal(
                $prefix . ' exception: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS],
                $details,
                0,
                'EXCEPTION',
                $external_ids
            );
        }

        return DistributorOrderResult::block_fatal(
            $prefix . ' exception: ' . $msg,
            [DistributorOrderResult::REASON_FATAL_UNKNOWN],
            $details,
            0,
            'EXCEPTION',
            $external_ids
        );
    }

    /**
     * @param array<string,mixed> $details
     */
    private function classify_sports_south_validation_exception(\Throwable $e, array $details = []): DistributorOrderValidationResult
    {
        $msg = (string) $e->getMessage();
        $lc = strtolower($msg);
        $details = array_merge($details, [
            'exception' => get_class($e),
            'error' => self::excerpt_for_log($msg, 1000),
            'file' => $e->getFile(),
            'line' => (int) $e->getLine(),
        ]);

        if (
            strpos($lc, 'quota') !== false ||
            strpos($lc, 'rate') !== false ||
            strpos($lc, 'throttle') !== false ||
            strpos($lc, 'too many') !== false ||
            strpos($lc, 'exceeded') !== false
        ) {
            return DistributorOrderValidationResult::block_retryable(
                'Sports South validation exception: ' . $msg,
                ['SPORTS_SOUTH_VALIDATION_RETRY_RATE_LIMIT'],
                $details
            );
        }

        if (
            strpos($lc, 'timeout') !== false ||
            strpos($lc, 'timed out') !== false ||
            strpos($lc, 'could not resolve') !== false ||
            strpos($lc, 'connection') !== false ||
            strpos($lc, 'ssl') !== false ||
            strpos($lc, 'curl error 28') !== false
        ) {
            return DistributorOrderValidationResult::block_retryable(
                'Sports South validation exception: ' . $msg,
                ['SPORTS_SOUTH_VALIDATION_RETRY_TIMEOUT'],
                $details
            );
        }

        if (
            strpos($lc, 'bad gateway') !== false ||
            strpos($lc, 'service unavailable') !== false ||
            strpos($lc, 'temporar') !== false ||
            strpos($lc, '502') !== false ||
            strpos($lc, '503') !== false
        ) {
            return DistributorOrderValidationResult::block_retryable(
                'Sports South validation exception: ' . $msg,
                ['SPORTS_SOUTH_VALIDATION_RETRY_UPSTREAM'],
                $details
            );
        }

        return DistributorOrderValidationResult::block(
            'Sports South validation exception: ' . $msg,
            ['SPORTS_SOUTH_VALIDATION_EXCEPTION'],
            $details
        );
    }

    /**
     * @param array<string,string> $detail
     * @return array<string,string>
     */
    private function summarize_detail_row(array $detail): array
    {
        return [
            'SSItemNumber' => (string) ($detail['SSItemNumber'] ?? ''),
            'Quantity' => (string) ($detail['Quantity'] ?? ''),
            'OrderPrice' => (string) ($detail['OrderPrice'] ?? ''),
            'CustomerItemNumber' => (string) ($detail['CustomerItemNumber'] ?? ''),
        ];
    }

    /**
     * @param array<string,string> $header
     * @return array<string,string>
     */
    private static function summarize_header_for_log(array $header): array
    {
        return [
            'PO' => (string) ($header['PO'] ?? ''),
            'CustomerOrderNumber' => (string) ($header['CustomerOrderNumber'] ?? ''),
            'SalesMessage' => self::excerpt_for_log((string) ($header['SalesMessage'] ?? ''), 160),
            'ShipVIA' => (string) ($header['ShipVIA'] ?? ''),
            'ShipToName' => (string) ($header['ShipToName'] ?? ''),
            'ShipToCity' => (string) ($header['ShipToCity'] ?? ''),
            'ShipToState' => (string) ($header['ShipToState'] ?? ''),
            'ShipToZip' => (string) ($header['ShipToZip'] ?? ''),
            'ShipToPhoneTail4' => self::tail4((string) ($header['ShipToPhone'] ?? '')),
            'AdultSignature' => (string) ($header['AdultSignature'] ?? ''),
            'Signature' => (string) ($header['Signature'] ?? ''),
            'Insurance' => (string) ($header['Insurance'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $resp
     * @return array<string,mixed>
     */
    private static function summarize_api_response(array $resp): array
    {
        return [
            'ok' => !empty($resp['ok']) ? 1 : 0,
            'status' => (int) ($resp['status'] ?? 0),
            'operation' => (string) ($resp['operation'] ?? ''),
            'scalar' => self::excerpt_for_log((string) ($resp['scalar'] ?? ''), 500),
            'order_number' => (string) ($resp['order_number'] ?? ''),
            'error' => self::excerpt_for_log((string) ($resp['error'] ?? ''), 500),
            'body_excerpt' => self::excerpt_for_log((string) ($resp['body_excerpt'] ?? ''), 1000),
            'response_bytes' => (int) ($resp['response_bytes'] ?? 0),
            'elapsed_ms' => (string) ($resp['elapsed_ms'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function summarize_order_request(DistributorOrderRequest $request): array
    {
        $required = $this->build_required_qty_by_upc((array) $request->lines);

        return [
            'merchant_order_id' => $this->sanitize_and_truncate_po((string) $request->merchant_order_id, 32),
            'lane' => strtolower(trim((string) ($request->lane ?? ''))),
            'destination_state' => (string) ($request->dest_state ?? ''),
            'line_count' => count((array) $request->lines),
            'valid_line_count' => method_exists($request, 'valid_lines') ? count((array) $request->valid_lines()) : 0,
            'required_by_upc' => self::summarize_required_by_upc($required),
            'has_ship_to_customer' => $request->ship_to_customer instanceof DistributorShipTo ? 1 : 0,
            'has_ship_to_ffl' => $request->ship_to_ffl instanceof DistributorShipTo ? 1 : 0,
            'receiving_ffl_tail4' => self::tail4((string) $request->receiving_ffl_number),
        ];
    }

    /**
     * @param array<int,mixed> $lines
     * @return array<int,array<string,mixed>>
     */
    private function summarize_lines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (!$line instanceof DistributorOrderLine) {
                continue;
            }

            $upc = $this->normalize_upc($this->read_line_upc($line));
            $out[] = [
                'upc_tail4' => self::tail4((string) ($upc ?? '')),
                'qty' => $this->read_line_qty($line),
                'ffl_required' => property_exists($line, 'ffl_required') ? (int) ($line->ffl_required ?? 0) : null,
            ];
        }

        return array_slice($out, 0, 25);
    }

    /**
     * @param array<string,int> $required
     * @return array<int,array<string,mixed>>
     */
    private static function summarize_required_by_upc(array $required): array
    {
        $out = [];
        foreach ($required as $upc => $qty) {
            $out[] = [
                'upc_tail4' => self::tail4((string) $upc),
                'qty' => (int) $qty,
            ];
        }

        return array_slice($out, 0, 25);
    }

    private static function summarize_validation_result(DistributorOrderValidationResult $result): array
    {
        return [
            'ok' => !empty($result->ok) ? 1 : 0,
            'code' => (string) $result->code,
            'codes' => (array) $result->codes,
            'message' => self::excerpt_for_log((string) $result->message, 500),
            'detail_keys' => array_keys((array) $result->details),
            'failure_flags' => isset($result->details['failure_flags']) && is_array($result->details['failure_flags']) ? $result->details['failure_flags'] : [],
        ];
    }

    private static function summarize_order_result(DistributorOrderResult $result): array
    {
        return [
            'ok' => !empty($result->ok) ? 1 : 0,
            'code' => (string) $result->code,
            'codes' => (array) $result->codes,
            'message' => self::excerpt_for_log((string) $result->message, 500),
            'external_order_ids' => (array) $result->external_order_ids,
            'http_status' => (int) $result->http_status,
            'provider_error_code' => (string) $result->provider_error_code,
            'detail_keys' => array_keys((array) $result->details),
        ];
    }

    private static function new_trace_id(): string
    {
        return substr(md5(uniqid('sports_south_order_', true)), 0, 12);
    }

    private static function tail4(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return strlen($value) > 4 ? substr($value, -4) : $value;
    }

    private static function excerpt_for_log(string $text, int $max = 1200): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return '';
        }

        $text = (string) preg_replace('/(<Password>).*?(<\/Password>)/i', '$1[redacted]$2', $text);
        $text = (string) preg_replace('/(Password=)[^&\s]+/i', '$1[redacted]', $text);

        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max) . '...';
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log_if(true, self::LOG_PREFIX, $message, self::DEBUG_FLAG);
            return;
        }

        DebugLogUtil::log_if_ctx(true, self::LOG_PREFIX, $message, $ctx, self::DEBUG_FLAG);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $ctx['elapsed_ms'] = number_format((microtime(true) - $t0) * 1000.0, 2, '.', '');
        $this->log('PROFILE: ' . $label, $ctx);
    }

}
