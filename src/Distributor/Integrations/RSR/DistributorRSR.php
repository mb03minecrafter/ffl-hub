<?php
// File: src/Distributor/Integrations/RSR/DistributorRSR.php

namespace FFLHub\Distributor\Integrations\RSR;

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Contracts\DistributorModuleInterface;

use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Distributor\Services\RSR\RSRServices;

use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Models\DistributorShipTo;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * RSR distributor implementation.
 *
 * Uses the local RSR fulfillment table for product/price/quantity lookups,
 * and the RSR DirectConnect endpoints for validation + ordering.
 */
class DistributorRSR extends DistributorBase
{
    private const BASE_SHIPPING_COST = 15.0;
    private const ADULT_SIGNATURE_SURCHARGE = 5.0;

    public function __construct(DistributorModuleInterface $module, ?RSRServices $services = null)
    {
        parent::__construct($module, $services);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function get_fulfillment_row_by_upc(string $upc, ?string &$normalized_upc = null): ?array
    {
        if (! $this->services) {
            return null;
        }

        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized);
        if (! $row) {
            return null;
        }
        if (! is_array($row)) {
            if (! is_object($row)) {
                return null;
            }
            $row = get_object_vars($row);
        }

        $normalized_upc = $normalized;

        return $row;
    }

    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        $normalized_upc = null;
        $row = $this->get_fulfillment_row_by_upc($upc, $normalized_upc);
        if ($row === null || $normalized_upc === null) {
            return null;
        }

        $payload = $this->build_payload_from_row(
            $row,
            [
                'sku'         => ['rsr_stock_number', 'sku'],
                'upc'         => ['upc'],
                'name'        => ['model'],
                'description' => ['product_description'],
                'price'       => ['distributor_price'],
                'map'         => ['retail_map'],
                'msrp'        => ['retail_msrp'],
                'quantity'    => ['inventory_quantity'],
                'category'    => ['dept_number'],
                'shipping_weight' => ['shipping_weight'],
                'shipping_length_in' => ['shipping_length_in'],
                'shipping_width_in'  => ['shipping_width_in'],
                'shipping_height_in' => ['shipping_height_in'],
                'sot_required' => ['sot_required'],
                'dropship_enabled' => ['dropship_enabled'],
            ],
            [DistributorProductCategoryMapper::class, 'map_rsr'],
            $normalized_upc,
            false
        );

        $payload->name = (string) $payload->description;
        $payload->ffl_required = false;
        if (! $payload->sot_required && $this->is_sot_required_from_dept_number($row['dept_number'] ?? null)) {
            $payload->sot_required = true;
        }

        $image_name = trim((string) $this->get_string_field($row, ['image_name']));
        if ($image_name !== '') {
            $rsr_image_urls = RSRDirectConnectAPI::build_image_urls_from_image_name($image_name);
            if (! empty($rsr_image_urls)) {
                $payload->add_image_url((string) $rsr_image_urls[0]);
                foreach (array_slice($rsr_image_urls, 1) as $extra_url) {
                    $payload->add_image_url($extra_url);
                }
            }
        }

        return $payload;
    }

    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
        $normalized_upc = null;
        $row = $this->get_fulfillment_row_by_upc($upc, $normalized_upc);
        if ($row === null || $normalized_upc === null) {
            return null;
        }

        $payload = $this->build_payload_from_row(
            $row,
            [
                'sku'         => ['rsr_stock_number', 'sku'],
                'upc'         => ['upc'],
                'name'        => ['model'],
                'description' => ['product_description'],
                'price'       => ['distributor_price'],
                'map'         => ['retail_map'],
                'msrp'        => ['retail_msrp'],
                'quantity'    => ['inventory_quantity'],
                'category'    => ['dept_number'],
                'shipping_weight' => ['shipping_weight'],
                'shipping_length_in' => ['shipping_length_in'],
                'shipping_width_in'  => ['shipping_width_in'],
                'shipping_height_in' => ['shipping_height_in'],
                'sot_required' => ['sot_required'],
                'dropship_enabled' => ['dropship_enabled'],
            ],
            [DistributorProductCategoryMapper::class, 'map_rsr'],
            $normalized_upc,
            false
        );

        $payload->name = (string) $payload->description;
        $payload->ffl_required = false;
        if (! $payload->sot_required && $this->is_sot_required_from_dept_number($row['dept_number'] ?? null)) {
            $payload->sot_required = true;
        }

        return $payload;
    }

    /**
     * RSR dept 6 indicates NFA/SOT-required products.
     *
     * @param mixed $dept_number
     */
    private function is_sot_required_from_dept_number($dept_number): bool
    {
        if ($dept_number === null) {
            return false;
        }

        $raw = trim((string) $dept_number);
        if ($raw === '') {
            return false;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        $digits = is_string($digits) ? $digits : '';
        if ($digits === '') {
            return false;
        }

        return ((int) $digits) === 6;
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $product = $this->get_fulfillment_row_by_upc($upc);
        if ($product === null) {
            return null;
        }

        $cost = self::BASE_SHIPPING_COST;

        $requires_signature = $this->get_bool_field($product, ['adult_sig_required']);
        if ($requires_signature === true) {
            $cost += self::ADULT_SIGNATURE_SURCHARGE;
        }

        return $cost;
    }


    //ORDERING SECTION

    protected function supports_ordering(): bool
    {
        return true;
    }

    protected function place_order_stop_on_first_failure(): bool
    {
        return true; // no partial same-job ordering
    }

    /**
     * Pre-checks shared by both LANES.
     */
    protected function place_order_precheck(DistributorOrderRequest $request): ?DistributorOrderResult
    {
        $base = parent::place_order_precheck($request);
        if ($base instanceof DistributorOrderResult) {
            return $base;
        }

        $lane = strtolower(trim((string) ($request->lane ?? '')));
        $auth_purpose = ($lane === 'dealer_fulfilled') ? 'dealer_fulfilled' : 'ordering';
        $auth = $this->get_rsr_auth_payload($auth_purpose);
        if (!($auth['ok'] ?? false)) {
            return DistributorOrderResult::block_fatal(
                (string) ($auth['message'] ?? 'Missing RSR credentials.'),
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS],
                ['auth' => $this->safe_raw_summary($auth)]
            );
        }

        return null;
    }

    /**
     * @param 'direct_ship_non_ffl'|'direct_ship_ffl'|'dealer_fulfilled' $lane
     * @param array<int,mixed> $lines
     */
    protected function place_order_lane(
        DistributorOrderRequest $request,
        string $lane,
        array $lines,
        array &$external_ids
    ): DistributorOrderResult {
        $lane = strtolower(trim((string) $lane));

        $auth_purpose = ($lane === 'dealer_fulfilled') ? 'dealer_fulfilled' : 'ordering';
        $auth = $this->get_rsr_auth_payload($auth_purpose); // already validated in precheck
        $api_base_url = $this->get_api_base_url();

        $base_po = RSRDirectConnectAPI::sanitize_rsr_po((string) $request->merchant_order_id);
        if ($base_po === '') {
            $fallback = (string) ($request->order_id ?? $request->wc_order_id ?? '');
            $base_po = $fallback !== '' ? ('WC' . $fallback) : ('WC' . gmdate('YmdHis'));
        }
        $po = RSRDirectConnectAPI::truncate_po($base_po);

        $items = $this->build_rsr_items_from_lines($lines);
        if ($items instanceof DistributorOrderResult) {
            $items->external_order_ids = $external_ids;
            return $items;
        }

        if ($lane === 'dealer_fulfilled') {
            $payload = array_merge(
                $auth['payload'],
                $this->build_rsr_dealer_email_payload(),
                [
                    'PONum' => $po,
                    'Items' => $items,
                ]
            );

            if ($this->is_test_order_debug_enabled()) {
                return $this->build_test_order_debug_block(
                    $lane,
                    rtrim($api_base_url, '/') . '/place-order',
                    'POST',
                    'json',
                    $this->encode_debug_json_payload($payload),
                    [
                        'po' => $po,
                        'item_count' => count($items),
                    ],
                    $external_ids
                );
            }

            $resp = RSRDirectConnectAPI::place_order($payload, $api_base_url, 60);
            if (!($resp['ok'] ?? false)) {
                $failure = $this->classify_rsr_place_order_failure(
                    $resp,
                    'RSR dealer-fulfilled',
                    $payload,
                    rtrim($api_base_url, '/') . '/place-order'
                );
                $failure->external_order_ids = $external_ids;
                return $failure;
            }

            $external_ids[] = (string) ($resp['external_id'] ?? '');
            return DistributorOrderResult::ok('RSR dealer-fulfilled order submitted.', $external_ids);
        }

        if ($lane === 'direct_ship_non_ffl') {
            if (!($request->ship_to_customer instanceof DistributorShipTo)) {
                return DistributorOrderResult::block_fatal(
                    'RSR direct-ship non-FFL: missing ship_to_customer (ship-to address required).',
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    [],
                    0,
                    '',
                    $external_ids
                );
            }

            $ship = $request->ship_to_customer;
            $ship_check = RSRDirectConnectAPI::validate_ship_to_required_fields($ship);


            if (!($ship_check['ok'] ?? false)) {
                return DistributorOrderResult::block_fatal(
                    'RSR direct-ship non-FFL: ' . (string) ($ship_check['message'] ?? 'Invalid ship-to.'),
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    ['ship_check' => $ship_check],
                    0,
                    '',
                    $external_ids
                );
            }

            $ship_ctx = RSRDirectConnectAPI::build_ship_context_payload($ship, $request->ship_to_customer);

            $payload = array_merge(
                $auth['payload'],
                $this->build_rsr_dealer_email_payload(),
                [
                    'PONum' => $po,
                    'Items' => $items,
                ],
                $ship_ctx
            );

            if ($this->is_test_order_debug_enabled()) {
                return $this->build_test_order_debug_block(
                    $lane,
                    rtrim($api_base_url, '/') . '/place-order',
                    'POST',
                    'json',
                    $this->encode_debug_json_payload($payload),
                    [
                        'po' => $po,
                        'item_count' => count($items),
                    ],
                    $external_ids
                );
            }

            $resp = RSRDirectConnectAPI::place_order($payload, $api_base_url, 60);
            if (!($resp['ok'] ?? false)) {
                $failure = $this->classify_rsr_place_order_failure(
                    $resp,
                    'RSR direct-ship non-FFL',
                    $payload,
                    rtrim($api_base_url, '/') . '/place-order'
                );
                $failure->external_order_ids = $external_ids;
                return $failure;
            }

            $external_ids[] = (string) ($resp['external_id'] ?? '');
            return DistributorOrderResult::ok('RSR direct-ship non-FFL order submitted.', $external_ids);
        }

        if ($lane !== 'direct_ship_ffl') {
            return DistributorOrderResult::block_fatal(
                'RSR: unsupported lane "' . $lane . '".',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        // direct_ship_ffl lane
        $ffl_num = strtoupper(trim((string) $request->receiving_ffl_number));
        if ($ffl_num === '') {
            return DistributorOrderResult::block_fatal(
                'RSR FFL: missing receiving FFL number (ShipFFL required).',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        if (!($request->ship_to_ffl instanceof DistributorShipTo)) {
            return DistributorOrderResult::block_fatal(
                'RSR FFL: missing ship_to_ffl address (transfer dealer address required).',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $ship = $request->ship_to_ffl;

        $ship_check = RSRDirectConnectAPI::validate_ship_to_required_fields($ship);
        if (!($ship_check['ok'] ?? false)) {
            return DistributorOrderResult::block_fatal(
                'RSR FFL: ' . (string) ($ship_check['message'] ?? 'Invalid ship-to.'),
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                ['ship_check' => $ship_check],
                0,
                '',
                $external_ids
            );
        }

        $id_check = RSRDirectConnectAPI::validate_customer_identity_for_firearm_dropship($request->ship_to_customer);
        if (!($id_check['ok'] ?? false)) {
            return DistributorOrderResult::block_fatal(
                'RSR FFL: ' . (string) ($id_check['message'] ?? 'Customer identity check failed.'),
                [DistributorOrderResult::REASON_FATAL_RESTRICTED],
                ['id_check' => $id_check],
                0,
                '',
                $external_ids
            );
        }

        $ship_ctx = RSRDirectConnectAPI::build_ship_context_payload($ship, $request->ship_to_customer);

        $payload = array_merge(
            $auth['payload'],
            $this->build_rsr_dealer_email_payload(),
            [
                'PONum'   => $po,
                'ShipFFL' => $ffl_num,
                'Items'   => $items,
            ],
            $ship_ctx
        );

        if ($this->is_test_order_debug_enabled()) {
            return $this->build_test_order_debug_block(
                $lane,
                rtrim($api_base_url, '/') . '/place-order',
                'POST',
                'json',
                $this->encode_debug_json_payload($payload),
                [
                    'po' => $po,
                    'item_count' => count($items),
                ],
                $external_ids
            );
        }

        $resp = RSRDirectConnectAPI::place_order($payload, $api_base_url, 60);
        if (!($resp['ok'] ?? false)) {
            $failure = $this->classify_rsr_place_order_failure(
                $resp,
                'RSR direct-ship FFL',
                $payload,
                rtrim($api_base_url, '/') . '/place-order'
            );
            $failure->external_order_ids = $external_ids;
            return $failure;
        }

        $external_ids[] = (string) ($resp['external_id'] ?? '');
        return DistributorOrderResult::ok('RSR direct-ship FFL order submitted.', $external_ids);
    }

    //END OF ORDERING SECTION






    //RSR VALIDATION SECTION


    protected function supports_remote_validation(): bool
    {
        return true;
    }

    protected function validation_max_unique_items(DistributorOrderRequest $request, bool $local_only): int
    {
        // Matches your local_only option max_unique=100.
        // Remote check-catalog probably also should cap; keep consistent.
        return 100;
    }

    protected function validation_services_missing_code(): string
    {
        return 'RSR_SERVICES_MISSING';
    }

    protected function validation_services_missing_message(): string
    {
        return 'RSR services not available; cannot access fulfillment table.';
    }

    protected function validation_precheck_invariants(DistributorOrderRequest $request, bool $local_only): ?DistributorOrderValidationResult
    {
        // Enforce FFL invariants both local_only and remote (matches old behavior).
        return $this->require_ffl_shipto_if_ffl_lines($request, 'RSR');
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
            'label'             => 'RSR validation (local_only)',
            'max_unique'        => 100,
            'inventory_keys'    => ['inventory_quantity', 'qty', 'quantity', 'available', 'on_hand'],
            'unknown_qty_blocks' => true,
        ];
    }

    /**
     * Remote validation via RSR check-catalog (lane-aware).
     *
     * @param array<string,int> $required_by_upc
     */
    protected function validate_order_request_remote(
        DistributorOrderRequest $request,
        array $required_by_upc
    ): DistributorOrderValidationResult {
        $lines_non = $request->non_ffl_required_lines();
        $lines_ffl = $request->ffl_required_lines();

        if (empty($lines_non) && empty($lines_ffl)) {
            return DistributorOrderValidationResult::allow('No valid order lines to validate.');
        }

        $auth = $this->get_rsr_auth_payload('validation');
        if (!($auth['ok'] ?? false)) {
            return DistributorOrderValidationResult::block(
                (string) ($auth['message'] ?? 'Missing RSR auth payload.'),
                ['RSR_AUTH_MISSING']
            );
        }

        $details = [];

        if (!empty($lines_non)) {
            $res = $this->rsr_check_catalog_for_lane($auth['payload'], $request, false, $lines_non);
            $details['direct_ship_non_ffl'] = $res;

            $early = $this->rsr_map_check_catalog_failure_to_validation_result(
                $res,
                false,
                $details
            );
            if ($early instanceof DistributorOrderValidationResult) {
                return $early;
            }
        }

        if (!empty($lines_ffl)) {
            // FFL invariants already enforced by base precheck hook.

            $res = $this->rsr_check_catalog_for_lane($auth['payload'], $request, true, $lines_ffl);
            $details['direct_ship_ffl'] = $res;

            $early = $this->rsr_map_check_catalog_failure_to_validation_result(
                $res,
                true,
                $details
            );
            if ($early instanceof DistributorOrderValidationResult) {
                return $early;
            }
        }

        return DistributorOrderValidationResult::allow('RSR validation OK.', $details);
    }

    /**
     * Convert a check-catalog LANE response to a validation result (or null if ok).
     *
     * @param array<string,mixed> $res
     * @param array<string,mixed> $details
     */
    private function rsr_map_check_catalog_failure_to_validation_result(
        array $res,
        bool $is_ffl,
        array $details
    ): ?DistributorOrderValidationResult {
        if (($res['ok'] ?? false) === true) {
            return null;
        }

        $kind = (string) ($res['kind'] ?? '');
        if ($kind === 'out_of_stock') {
            return DistributorOrderValidationResult::block(
                'RSR out of stock (' . ($is_ffl ? 'FFL' : 'non-FFL') . ' items): ' . (string) ($res['message'] ?? 'Out of stock'),
                ['RSR_OUT_OF_STOCK'],
                $details
            );
        }

        $http = (int) ($res['http_status'] ?? 0);
        $msg  = (string) ($res['message'] ?? 'Unknown');

        if ($this->is_rsr_validation_failure_retryable($msg, $http)) {
            return DistributorOrderValidationResult::block_retryable(
                'RSR validation (' . ($is_ffl ? 'FFL' : 'non-FFL') . ') retryable: ' . $msg,
                [$is_ffl ? 'RSR_FFL_CHECK_CATALOG_RETRYABLE' : 'RSR_NON_CHECK_CATALOG_RETRYABLE'],
                $details
            );
        }

        return DistributorOrderValidationResult::block(
            'RSR validation failed (' . ($is_ffl ? 'FFL' : 'non-FFL') . ' items): ' . $msg,
            [$is_ffl ? 'RSR_FFL_CHECK_CATALOG_RESTRICTED' : 'RSR_NON_CHECK_CATALOG_RESTRICTED'],
            $details
        );
    }



    //END VALIDATION SECTION






    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        $po_number = trim((string) $po_number);
        if ($po_number === '') {
            return null;
        }

        $auth = $this->get_rsr_auth_payload();
        if (!is_array($auth) || empty($auth['ok'])) {
            return null;
        }

        $api_base_url = $this->get_api_base_url();

        $resp = RSRDirectConnectAPI::check_order_report_all(
            $auth['payload'],
            $po_number,
            $api_base_url,
            60
        );

        if (!is_array($resp) || empty($resp['ok'])) {
            return null;
        }

        $items = $resp['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            return null;
        }

        $tracking_numbers = [];
        $invoice_numbers  = [];

        $date_shipped_values = [];
        $warehouses          = [];

        //we infer the shipping carrier from the tracking number the best we can since RSR doesnt provide the carrier by default
        $shipping_service = null;


        // Tracking sentinels we should NOT treat as real tracking numbers because RSR can mark shipped before final tracking is assigned.
        $bad_tracking = [
            'pending',
            'tbd',
            'n/a',
            'na',
            'none',
            'null',
            'unknown',
            '-', // sometimes vendors send "-"
        ];

        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Tracking numbers (CSV)
            $tracking_raw = trim((string) ($row['TrackingNum'] ?? ''));
            if ($tracking_raw !== '') {
                foreach (preg_split('/\s*,\s*/', $tracking_raw) as $t) {
                    $t = trim((string) $t);
                    if ($t === '') {
                        continue;
                    }

                    $t_lc = strtolower($t);

                    // Drop obvious non-tracking sentinel values like "Pending"
                    if (in_array($t_lc, $bad_tracking, true)) {
                        continue;
                    }

                    // Drop whitespace-containing tokens (usually not real tracking)
                    if (preg_match('/\s/', $t)) {
                        continue;
                    }

                    // Drop pure alphabetic words (e.g., "Pending")
                    if (preg_match('/^[a-z]+$/i', $t)) {
                        continue;
                    }

                    // Basic length sanity check; avoids short junk tokens
                    // (UPS/FedEx/USPS tracking are typically longer than this)
                    if (strlen($t) < 8) {
                        continue;
                    }

                    $tracking_numbers[] = $t;


                    if ($shipping_service === null) {
                        $shipping_service = $this->infer_carrier_from_tracking($t);
                    }
                }
            }

            // Invoice numbers (CSV)
            $invoice_raw = trim((string) ($row['Invoices'] ?? ''));
            if ($invoice_raw !== '') {
                foreach (preg_split('/\s*,\s*/', $invoice_raw) as $inv) {
                    $inv = trim((string) $inv);
                    if ($inv !== '') {
                        $invoice_numbers[] = $inv;
                    }
                }
            }

            // Date shipped (CSV-ish too)
            $date_raw = trim((string) ($row['DateShipped'] ?? ''));
            if ($date_raw !== '') {
                foreach (preg_split('/\s*,\s*/', $date_raw) as $d) {
                    $d = trim((string) $d);
                    if ($d !== '') {
                        $date_shipped_values[] = $d;
                    }
                }
            }

            $wh = trim((string) ($row['Warehouse'] ?? ''));
            if ($wh !== '') {
                $warehouses[] = $wh;
            }
        }

        // Dedupe while preserving order
        $tracking_numbers    = array_values(array_unique($tracking_numbers));
        $invoice_numbers     = array_values(array_unique($invoice_numbers));
        $date_shipped_values = array_values(array_unique($date_shipped_values));
        $warehouses          = array_values(array_unique($warehouses));

        // If no VALID tracking yet, treat as "not shipped"
        if (empty($tracking_numbers)) {
            return null;
        }

        // Deterministic output ordering
        sort($tracking_numbers, SORT_STRING);
        sort($invoice_numbers, SORT_STRING);
        sort($date_shipped_values, SORT_STRING);
        sort($warehouses, SORT_STRING);

        return new DistributorShipment(
            $tracking_numbers,
            $invoice_numbers,
            $shipping_service,
            null,
            [
                'po_number'          => $po_number,
                'raw_items'          => $items,
                'raw'                => $resp['raw'] ?? null,
                'http_status'        => isset($resp['http_status']) ? (int) $resp['http_status'] : 0,
                'date_shipped_values' => $date_shipped_values,
                'warehouses'         => $warehouses,
            ]
        );
    }


    /**
     * Classify an RSR place-order failure into retryable vs fatal (NEW shape).
     *
     * @param array<string,mixed> $resp
     * @param array<string,mixed> $request_payload
     */
    private function classify_rsr_place_order_failure(
        array $resp,
        string $prefix = 'RSR',
        array $request_payload = [],
        string $request_url = ''
    ): DistributorOrderResult
    {
        $http = isset($resp['http_status']) ? (int) $resp['http_status'] : 0;
        $msg  = (string) ($resp['message'] ?? 'Unknown error');

        $details = [
            'raw' => $this->safe_raw_summary($resp['raw'] ?? null),
        ];
        if (!empty($request_payload)) {
            $details['request_payload'] = $request_payload;
            $request_json = wp_json_encode($request_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($request_json) && $request_json !== '') {
                $details['request_body_json'] = $request_json;
            }
        }
        if ($request_url !== '') {
            $details['request_url'] = $request_url;
        }

        // HTTP-based classification first (best signal).
        if ($http === 429) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': rate limit (HTTP 429): ' . $msg,
                [DistributorOrderResult::REASON_RETRY_RATE_LIMIT],
                $details,
                $http
            );
        }

        if ($http === 408 || $http === 504) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': timeout (HTTP ' . $http . '): ' . $msg,
                [DistributorOrderResult::REASON_RETRY_TIMEOUT],
                $details,
                $http
            );
        }

        if ($http === 502 || $http === 503) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': upstream error (HTTP ' . $http . '): ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UPSTREAM],
                $details,
                $http
            );
        }

        // Explicit non-retryable request errors.
        if ($http === 400 || $http === 422) {
            return DistributorOrderResult::block_fatal(
                $prefix . ': bad request (HTTP ' . $http . '): ' . $msg,
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                $details,
                $http
            );
        }

        // Message heuristics if HTTP status is absent/0.
        $lc = strtolower($msg);

        if (
            strpos($lc, 'quota') !== false ||
            strpos($lc, 'rate') !== false ||
            strpos($lc, 'throttle') !== false ||
            strpos($lc, 'too many') !== false ||
            strpos($lc, 'exceeded') !== false
        ) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': rate/quota: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_RATE_LIMIT],
                $details,
                $http
            );
        }

        if (
            strpos($lc, 'timeout') !== false ||
            strpos($lc, 'timed out') !== false ||
            strpos($lc, 'could not resolve') !== false ||
            strpos($lc, 'connection') !== false ||
            strpos($lc, 'ssl') !== false ||
            strpos($lc, 'cURL error 28') !== false
        ) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': timeout/network: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_TIMEOUT],
                $details,
                $http
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
                $prefix . ': upstream: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UPSTREAM],
                $details,
                $http
            );
        }

        // If we have an RSR StatusCode, treat it as a business restriction (fatal).
        $rsr_code = isset($resp['rsr_status_code']) ? trim((string) $resp['rsr_status_code']) : '';
        if ($rsr_code !== '' && $rsr_code !== '00') {
            return DistributorOrderResult::block_fatal(
                $prefix . ': RSR rejected order: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_RESTRICTED],
                array_merge($details, [
                    'rsr_status_code' => $rsr_code,
                    'rsr_status_msg'  => isset($resp['rsr_status_msg']) ? (string) $resp['rsr_status_msg'] : '',
                ]),
                $http,
                $rsr_code
            );
        }

        // Out of stock heuristics (just in case place-order returns it explicitly)
        if (strpos($lc, 'out of stock') !== false || strpos($lc, 'insufficient') !== false) {
            return DistributorOrderResult::block_fatal(
                $prefix . ': out of stock: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_OUT_OF_STOCK],
                $details,
                $http
            );
        }

        // Transport-ish unknown (often wp_error path => http=0)
        if ($http === 0) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': network/unknown transport failure: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UNKNOWN],
                $details,
                0
            );
        }

        return DistributorOrderResult::block_fatal(
            $prefix . ': order failed: ' . $msg,
            [DistributorOrderResult::REASON_FATAL_UNKNOWN],
            $details,
            $http
        );
    }

    /**
     * @return array{
     *   ok:bool,
     *   message:string,
     *   items:array<int,array<string,mixed>>,
     *   raw:array|string|null,
     *   http_status:int,
     *   error?:array{code:string,provider_error_code:string}
     * }
     */
    private function rsr_check_catalog_for_lane(array $auth_payload, DistributorOrderRequest $request, bool $ffl_lane, array $lines): array
    {
        $items = $this->build_rsr_check_catalog_items_from_lines($lines);
        if ($items instanceof DistributorOrderResult) {
            // Mapping error => treat as non-retryable bad request for validation, but preserve machine code.
            return [
                'ok' => false,
                'message' => (string) $items->message,
                'items' => [],
                'raw' => null,
                'http_status' => (int) $items->http_status,
                'error' => [
                    'code' => (string) $items->code,
                    'provider_error_code' => (string) $items->provider_error_code,
                ],
            ];
        }

        $ship = $request->ship_to_for_ffl_requirement($ffl_lane);

        $ship_check = RSRDirectConnectAPI::validate_ship_to_required_fields($ship);
        if (! $ship_check['ok']) {
            return [
                'ok' => false,
                'message' => (string) $ship_check['message'],
                'items' => [],
                'raw' => null,
                'http_status' => 0,
            ];
        }

        if ($ffl_lane) {
            $id_check = RSRDirectConnectAPI::validate_customer_identity_for_firearm_dropship($request->ship_to_customer);
            if (! $id_check['ok']) {
                return [
                    'ok' => false,
                    'message' => (string) $id_check['message'],
                    'items' => [],
                    'raw' => null,
                    'http_status' => 0,
                ];
            }
        }

        $ship_ctx = RSRDirectConnectAPI::build_ship_context_payload($ship, $request->ship_to_customer);

        $payload = array_merge(
            $auth_payload,
            $this->build_rsr_dealer_email_payload(),
            $ship_ctx,
            [
                'LookupBy' => 'S',
                'Items'    => $items,
            ]
        );

        if ($ffl_lane) {
            $payload['ShipFFL'] = strtoupper(trim((string) $request->receiving_ffl_number));
        }

        $out = RSRDirectConnectAPI::check_catalog($payload, $this->get_api_base_url(), 60);

        if (! isset($out['http_status'])) {
            $out['http_status'] = 0;
        }

        // Make sure raw doesn't blow up logs.
        if (isset($out['raw'])) {
            $out['raw'] = $this->safe_raw_summary($out['raw']);
        }


        // ----------------------------
        // NEW: Treat StatusCode=01 as "out of stock" (soft restriction)
        // ----------------------------
        if (isset($out['ok']) && $out['ok'] === true && isset($out['items']) && is_array($out['items'])) {
            $oos = [];

            foreach ($out['items'] as $it) {
                if (!is_array($it)) {
                    continue;
                }

                $status = trim((string)($it['StatusCode'] ?? ''));
                if ($status !== '01') {
                    continue;
                }

                $upc  = (string)($it['UPC'] ?? '');
                $part = (string)($it['PartNum'] ?? '');

                $oos[] = [
                    'PartNum'    => $part,
                    'UPC'        => $upc,
                    'OnHand'     => $it['OnHand'] ?? '',
                    'StatusCode' => $status,
                    'StatusMssg' => (string)($it['StatusMssg'] ?? ''),
                ];
            }

            if (!empty($oos)) {
                // Keep message concise; include up to 5 items
                $msg_parts = [];
                foreach (array_slice($oos, 0, 5) as $bad) {
                    $p = (string)($bad['PartNum'] ?? '');
                    $u = (string)($bad['UPC'] ?? '');
                    $u_tail = substr(preg_replace('/\D+/', '', $u) ?: $u, -4);
                    $msg_parts[] = "OOS (PartNum={$p} UPC=**{$u_tail})";
                }

                $msg = 'RSR check-catalog out of stock: ' . implode(' | ', $msg_parts);
                if (count($oos) > 5) {
                    $msg .= ' | ...';
                }

                // Flip to "not ok" with a discriminator so validate_order_request can classify cleanly
                $out['ok'] = false;
                $out['kind'] = 'out_of_stock';
                $out['message'] = $msg;
                $out['oos'] = $oos;
            }
        }

        return $out;
    }

    /**
     * Build RSR Items[] from normalized lines (place-order).
     *
     * @param DistributorOrderLine[] $lines
     * @return array<int,array<string,mixed>>|DistributorOrderResult
     */
    private function build_rsr_items_from_lines(array $lines)
    {
        $res = $this->map_order_lines_to_items(
            $lines,
            function (string $normalized_upc, string $raw_upc, DistributorOrderLine $line): ?string {
                return $this->lookup_rsr_partnum_by_normalized_upc($normalized_upc);
            },
            function (string $partnum, int $qty, string $normalized_upc, string $raw_upc, DistributorOrderLine $line): array {
                return [
                    'UPCcode' => $normalized_upc,
                    'WishQty' => $qty,
                    'PartNum' => $partnum,
                ];
            },
            // Use tail4 in message (map_order_lines_to_items should pass tail; if it doesn't yet, message still ok).
            'RSR cannot map UPC to PartNum (rsr_stock_number) using fulfillment table: **%s',
            true,
            'RSR: no valid items after mapping.'
        );

        return $res;
    }

    /**
     * Build check-catalog Items[] entries from lines.
     *
     * @param DistributorOrderLine[] $lines
     * @return array<int,array{PartNum:string}>|DistributorOrderResult
     */
    private function build_rsr_check_catalog_items_from_lines(array $lines)
    {
        $items = $this->map_order_lines_to_items(
            $lines,
            function (string $normalized_upc, string $raw_upc, DistributorOrderLine $line): ?string {
                return $this->lookup_rsr_partnum_by_normalized_upc($normalized_upc);
            },
            function (string $partnum, int $qty, string $normalized_upc, string $raw_upc, DistributorOrderLine $line): array {
                return ['PartNum' => $partnum];
            },
            'RSR cannot map UPC to PartNum (rsr_stock_number) using fulfillment table: **%s',
            true,
            'RSR: no valid items after mapping.'
        );

        if (! is_array($items)) {
            return $items;
        }

        // Deduplicate PartNum for check-catalog
        $seen = [];
        $deduped = [];
        foreach ($items as $row) {
            $p = isset($row['PartNum']) ? trim((string) $row['PartNum']) : '';
            if ($p === '' || isset($seen[$p])) {
                continue;
            }
            $seen[$p] = true;
            $deduped[] = ['PartNum' => $p];
        }

        if (count($deduped) > 100) {
            $deduped = array_slice($deduped, 0, 100);
        }

        return $deduped;
    }

    private function lookup_rsr_partnum_by_normalized_upc(string $normalized_upc): ?string
    {
        if (! $this->services) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
        if (! $row) {
            return null;
        }

        $part = $this->get_string_field($row, ['rsr_stock_number', 'sku']);
        $part = trim((string) $part);

        return $part !== '' ? $part : null;
    }

    /**
     * @return array{
     *   ok:bool,
     *   message:string,
     *   payload:array{Username:string,Password:string,POS:string}
     * }
     */
    private function get_rsr_auth_payload(string $purpose = 'ordering'): array
    {
        $purpose = strtolower(trim((string) $purpose));
        $is_validation = ($purpose === 'validation');
        $is_dealer_fulfilled = ($purpose === 'dealer_fulfilled');

        if ($is_validation || $is_dealer_fulfilled) {
            $username = $this->get_main_username();
            $password = $this->get_main_password();
        } else {
            $username = $this->get_dropship_username();
            $password = $this->get_dropship_password();
        }

        $pos      = $this->get_pos_indicator();

        if ($username === '' || $password === '') {
            if ($is_validation) {
                $missing_message = 'Missing RSR main credentials (main_account_number/password).';
            } elseif ($is_dealer_fulfilled) {
                $missing_message = 'Missing RSR main credentials (main_account_number/password) required for dealer-fulfilled ordering.';
            } else {
                $missing_message = 'Missing RSR dropship credentials (dropship_account_number/password).';
            }

            return [
                'ok' => false,
                'message' => $missing_message,
                'payload' => ['Username' => '', 'Password' => '', 'POS' => ''],
            ];
        }
        if ($pos === '') {
            return [
                'ok' => false,
                'message' => 'Missing RSR POS indicator (pos_indicator).',
                'payload' => ['Username' => '', 'Password' => '', 'POS' => ''],
            ];
        }

        return [
            'ok' => true,
            'message' => 'OK',
            'payload' => [
                'Username' => $username,
                'Password' => $password,
                'POS'      => $pos,
            ],
        ];
    }

    /**
     * @return array{Email:string}
     */
    private function build_rsr_dealer_email_payload(): array
    {
        return [
            'Email' => $this->resolve_dealer_email(),
        ];
    }

    private function get_api_base_url(): string
    {
        $base = RSRDirectConnectAPI::DEFAULT_API_BASE_URL;
        $filtered = apply_filters('fflhub_rsr_api_base_url', $base);

        $filtered = is_string($filtered) ? trim($filtered) : $base;
        if ($filtered === '') {
            $filtered = $base;
        }

        return rtrim($filtered, '/');
    }

    private function get_dropship_username(): string
    {
        return trim((string) get_option($this->get_option_name('dropship_account_number'), ''));
    }

    private function get_dropship_password(): string
    {
        return trim((string) get_option($this->get_option_name('dropship_account_password'), ''));
    }

    private function get_main_username(): string
    {
        return trim((string) get_option($this->get_option_name('main_account_number'), ''));
    }

    private function get_main_password(): string
    {
        return trim((string) get_option($this->get_option_name('main_account_password'), ''));
    }

    private function get_pos_indicator(): string
    {
        return trim((string) get_option($this->get_option_name('pos_indicator'), ''));
    }

    private function resolve_dealer_email(): string
    {
        $email = trim((string) get_option($this->get_option_name('order_email'), ''));
        if ($email === '') {
            $email = trim((string) get_option('admin_email', ''));
        }
        if ($email === '') {
            $email = 'dealer@example.com';
        }
        return $email;
    }


    /**
     * For validation (check-catalog) failures: determine whether this is retryable.
     */
    private function is_rsr_validation_failure_retryable($message, $http_status)
    {
        $msg = strtolower(trim((string) $message));
        $hs  = (int) $http_status;

        if ($hs === 429 || $hs === 408 || $hs === 502 || $hs === 503 || $hs === 504) {
            return true;
        }

        if (
            strpos($msg, 'rate') !== false ||
            strpos($msg, 'quota') !== false ||
            strpos($msg, 'too many') !== false ||
            strpos($msg, 'exceeded') !== false ||
            strpos($msg, 'throttle') !== false ||
            strpos($msg, 'timeout') !== false ||
            strpos($msg, 'temporar') !== false
        ) {
            return true;
        }

        return false;
    }

    /**
     * Keep response blobs small/redacted to avoid log bloat or leaking secrets.
     *
     * @param mixed $raw
     * @return mixed
     */
    private function safe_raw_summary($raw)
    {
        if (is_string($raw)) {
            return substr($raw, 0, 2000);
        }
        if (is_array($raw)) {
            // Don't recursively shrink; just cap json size.
            $json = wp_json_encode($raw);
            if (is_string($json) && strlen($json) > 2000) {
                return substr($json, 0, 2000) . '...';
            }
            return $raw;
        }
        return $raw;
    }

}

