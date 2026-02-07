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
    public function __construct(DistributorModuleInterface $module, ?RSRServices $services = null)
    {
        parent::__construct($module, $services);
    }

    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        if (! $this->services) {
            return null;
        }

        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
        if (! $row) {
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
            ],
            [DistributorProductCategoryMapper::class, 'map_rsr'],
            $normalized_upc,
            false
        );

        $payload->name = (string) $payload->description;
        $payload->ffl_required = false;

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
        if (! $this->services) {
            return null;
        }

        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
        if (! $row) {
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
            ],
            [DistributorProductCategoryMapper::class, 'map_rsr'],
            $normalized_upc,
            false
        );

        $payload->name = (string) $payload->description;
        $payload->ffl_required = false;

        return $payload;
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        if (! $this->services) {
            return null;
        }

        $cost = 15.0;

        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $product = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
        if (! $product) {
            return null;
        }

        $requires_signature = $this->get_bool_field($product, ['adult_sig_required']);
        if ($requires_signature === true) {
            $cost += 5.0;
        }

        return $cost;
    }

    /**
     * Submit RSR orders using DirectConnect place-order.
     *
     * NOTE on partial orders:
     * We do NOT continue submitting the next bucket if one bucket fails.
     * This avoids creating partial orders in the “same job” scenario.
     */
    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        if (! $this->services) {
            return DistributorOrderResult::block_fatal(
                'RSR services not available; cannot access fulfillment table.',
                [DistributorOrderResult::REASON_FATAL_SERVICES_MISSING]
            );
        }

        $auth = $this->get_rsr_auth_payload();
        if (! $auth['ok']) {
            return DistributorOrderResult::block_fatal(
                (string) $auth['message'],
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS],
                ['auth' => $this->safe_raw_summary($auth)]
            );
        }

        if (empty($request->lines)) {
            return DistributorOrderResult::block_fatal(
                'No order lines provided.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        $lines_non = $request->non_ffl_lines();
        $lines_ffl = $request->ffl_lines();

        if (empty($lines_non) && empty($lines_ffl)) {
            return DistributorOrderResult::block_fatal(
                'No valid order lines after normalization.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        $api_base_url = $this->get_api_base_url();
        $external_ids = [];

        $base_po = RSRDirectConnectAPI::sanitize_rsr_po((string) $request->merchant_order_id);
        if ($base_po === '') {
            $base_po = 'WCORDER';
        }

        // -----------------------------
        // NON-FFL bucket
        // -----------------------------
        if (! empty($lines_non)) {
            $po = RSRDirectConnectAPI::truncate_po($base_po);

            $items = $this->build_rsr_items_from_lines($lines_non);
            if ($items instanceof DistributorOrderResult) {
                // Preserve whatever we may have already created (future-proofing).
                $items->external_order_ids = $external_ids;
                return $items;
            }

            $ship = $request->ship_to_customer;

            $ship_check = RSRDirectConnectAPI::validate_ship_to_required_fields($ship);
            if (! $ship_check['ok']) {
                return DistributorOrderResult::block_fatal(
                    'RSR NON: ' . (string) $ship_check['message'],
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

            $resp = RSRDirectConnectAPI::place_order($payload, $api_base_url, 60);
            if (! $resp['ok']) {
                $failure = $this->classify_rsr_place_order_failure($resp, 'RSR NON');
                $failure->external_order_ids = $external_ids;
                return $failure;
            }

            $external_ids[] = (string) $resp['external_id'];
        }

        // -----------------------------
        // FFL bucket
        // -----------------------------
        if (! empty($lines_ffl)) {
            $po = RSRDirectConnectAPI::truncate_po($base_po);

            $items = $this->build_rsr_items_from_lines($lines_ffl);
            if ($items instanceof DistributorOrderResult) {
                $items->external_order_ids = $external_ids;
                return $items;
            }

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

            if (! ($request->ship_to_ffl instanceof DistributorShipTo)) {
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
            if (! $ship_check['ok']) {
                return DistributorOrderResult::block_fatal(
                    'RSR FFL: ' . (string) $ship_check['message'],
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    ['ship_check' => $ship_check],
                    0,
                    '',
                    $external_ids
                );
            }

            $id_check = RSRDirectConnectAPI::validate_customer_identity_for_firearm_dropship($request->ship_to_customer);
            if (! $id_check['ok']) {
                // You explicitly asked for fatal restricted.
                return DistributorOrderResult::block_fatal(
                    'RSR FFL: ' . (string) $id_check['message'],
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

            $resp = RSRDirectConnectAPI::place_order($payload, $api_base_url, 60);
            if (! $resp['ok']) {
                $failure = $this->classify_rsr_place_order_failure($resp, 'RSR FFL');
                $failure->external_order_ids = $external_ids;
                return $failure;
            }

            $external_ids[] = (string) $resp['external_id'];
        }

        return DistributorOrderResult::ok('RSR order submitted.', $external_ids);
    }

    /**
     * Validate an order request against RSR shipping/restriction rules using check-catalog.
     *
     * If $local_only=true, we ONLY do a lightweight local fulfillment-table stock check
     * and skip ALL remote check-catalog calls.
     */
    public function validate_order_request(DistributorOrderRequest $request, bool $local_only = false): DistributorOrderValidationResult
    {
        if (! $this->services) {
            return DistributorOrderValidationResult::block(
                'RSR services not available; cannot access fulfillment table.',
                ['RSR_SERVICES_MISSING']
            );
        }

        $lines_non = $request->non_ffl_lines();
        $lines_ffl = $request->ffl_lines();

        if (empty($lines_non) && empty($lines_ffl)) {
            return DistributorOrderValidationResult::allow('No valid order lines to validate.');
        }

        // -----------------------------------------
        // LOCAL-ONLY MODE: stock-only local check
        // -----------------------------------------
        if ($local_only) {
            $details = [
                'local_only' => true,
                'non' => [
                    'ok' => true,
                    'kind' => 'local_only',
                    'message' => 'local_only=true (skipping RSR check-catalog)',
                    'items' => [],
                ],
                'ffl' => [
                    'ok' => true,
                    'kind' => 'local_only',
                    'message' => 'local_only=true (skipping RSR check-catalog)',
                    'items' => [],
                ],
            ];

            $local_fail_msgs = [];
            $table = $this->services->get_fulfillment_table();

            $check_lines = function (array $lines, string $bucket_key) use (&$details, &$local_fail_msgs, $table): void {
                foreach ($lines as $line) {
                    // Try to extract a UPC and required quantity without assuming too much.
                    $upc_raw = '';
                    if (is_array($line)) {
                        $upc_raw = (string) ($line['upc'] ?? $line['UPC'] ?? $line['Upc'] ?? $line['product_upc'] ?? '');
                    } elseif (is_object($line)) {
                        // common DTO patterns
                        if (isset($line->upc)) {
                            $upc_raw = (string) $line->upc;
                        } elseif (method_exists($line, 'upc')) {
                            $upc_raw = (string) $line->upc();
                        }
                    }

                    $qty_req = 1;
                    if (is_array($line)) {
                        $qty_req = (int) ($line['qty'] ?? $line['quantity'] ?? 1);
                    } elseif (is_object($line)) {
                        if (isset($line->qty)) {
                            $qty_req = (int) $line->qty;
                        } elseif (isset($line->quantity)) {
                            $qty_req = (int) $line->quantity;
                        } elseif (method_exists($line, 'qty')) {
                            $qty_req = (int) $line->qty();
                        } elseif (method_exists($line, 'quantity')) {
                            $qty_req = (int) $line->quantity();
                        }
                    }
                    if ($qty_req <= 0) {
                        $qty_req = 1;
                    }

                    $normalized_upc = $this->normalize_upc($upc_raw);
                    if ($normalized_upc === null || $normalized_upc === '') {
                        $local_fail_msgs[] = "UPC={$upc_raw} invalid (normalize_upc null/empty)";
                        $details[$bucket_key]['items'][$upc_raw] = [
                            'requiredQty' => $qty_req,
                            'local_qty' => null,
                            'found' => 0,
                            'reason' => 'invalid_upc',
                        ];
                        continue;
                    }

                    $row = $table->get_row_by_upc($normalized_upc);
                    if (! $row || ! is_array($row)) {
                        $local_fail_msgs[] = "UPC={$normalized_upc} not found in local fulfillment table";
                        $details[$bucket_key]['items'][$normalized_upc] = [
                            'requiredQty' => $qty_req,
                            'local_qty' => null,
                            'found' => 0,
                            'reason' => 'not_found',
                        ];
                        continue;
                    }

                    $qty_raw = $this->get_string_field($row, ['inventory_quantity', 'qty', 'quantity', 'available', 'on_hand']);
                    $local_qty = is_numeric($qty_raw) ? (int) $qty_raw : null;

                    $details[$bucket_key]['items'][$normalized_upc] = [
                        'requiredQty' => $qty_req,
                        'local_qty' => $local_qty,
                        'found' => 1,
                    ];

                    // Keeping parity with Lipseys local fallback:
                    // UNKNOWN => block (safer, avoids placing into unknown inventory)
                    if ($local_qty === null) {
                        $local_fail_msgs[] = "UPC={$normalized_upc} local_qty=UNKNOWN required={$qty_req}";
                        continue;
                    }

                    if ($local_qty < $qty_req) {
                        $local_fail_msgs[] = "UPC={$normalized_upc} local_available={$local_qty} required={$qty_req}";
                    }
                }
            };

            if (! empty($lines_non)) {
                $check_lines($lines_non, 'non');
            }
            if (! empty($lines_ffl)) {
                // Still enforce required FFL info even in local_only mode (cheap + prevents nonsense)
                if (! ($request->ship_to_ffl instanceof DistributorShipTo)) {
                    return DistributorOrderValidationResult::block(
                        'RSR validation failed (FFL items): missing ship_to_ffl (transfer dealer address required).',
                        ['RSR_FFL_ADDRESS_MISSING'],
                        $details
                    );
                }
                if (trim((string) $request->receiving_ffl_number) === '') {
                    return DistributorOrderValidationResult::block(
                        'RSR validation failed (FFL items): missing receiving FFL number (ShipFFL required).',
                        ['RSR_SHIPFFL_MISSING'],
                        $details
                    );
                }

                $check_lines($lines_ffl, 'ffl');
            }

            if (! empty($local_fail_msgs)) {
                $msg = 'RSR validation failed (local_only): ' . implode(' | ', array_slice($local_fail_msgs, 0, 8));
                if (count($local_fail_msgs) > 8) {
                    $msg .= ' | ...';
                }

                return DistributorOrderValidationResult::block(
                    $msg,
                    ['RSR_LOCAL_ONLY_BLOCKED'],
                    $details
                );
            }

            return DistributorOrderValidationResult::allow('RSR validation OK (local_only).', $details);
        }

        // -----------------------------------------
        // REMOTE MODE (existing behavior)
        // -----------------------------------------

        $auth = $this->get_rsr_auth_payload();
        if (! $auth['ok']) {
            return DistributorOrderValidationResult::block(
                (string) $auth['message'],
                ['RSR_AUTH_MISSING']
            );
        }

        $details = [];

        if (! empty($lines_non)) {
            $res_non = $this->rsr_check_catalog_for_bucket($auth['payload'], $request, false, $lines_non);
            $details['non'] = $res_non;

            if (! $res_non['ok']) {
                $kind = isset($res_non['kind']) ? (string) $res_non['kind'] : '';

                if ($kind === 'out_of_stock') {
                    return DistributorOrderValidationResult::block(
                        'RSR out of stock (non-FFL items): ' . (string) ($res_non['message'] ?? 'Out of stock'),
                        ['RSR_OUT_OF_STOCK'],
                        $details
                    );
                }

                $http = isset($res_non['http_status']) ? (int) $res_non['http_status'] : 0;
                $msg  = (string) ($res_non['message'] ?? 'Unknown');

                if ($this->is_rsr_validation_failure_retryable($msg, $http)) {
                    return DistributorOrderValidationResult::block_retryable(
                        'RSR validation (non-FFL) retryable: ' . $msg,
                        ['RSR_NON_CHECK_CATALOG_RETRYABLE'],
                        $details
                    );
                }

                return DistributorOrderValidationResult::block(
                    'RSR validation failed (non-FFL items): ' . $msg,
                    ['RSR_NON_CHECK_CATALOG_RESTRICTED'],
                    $details
                );
            }
        }

        if (! empty($lines_ffl)) {
            if (! ($request->ship_to_ffl instanceof DistributorShipTo)) {
                return DistributorOrderValidationResult::block(
                    'RSR validation failed (FFL items): missing ship_to_ffl (transfer dealer address required).',
                    ['RSR_FFL_ADDRESS_MISSING'],
                    $details
                );
            }

            if (trim((string) $request->receiving_ffl_number) === '') {
                return DistributorOrderValidationResult::block(
                    'RSR validation failed (FFL items): missing receiving FFL number (ShipFFL required).',
                    ['RSR_SHIPFFL_MISSING'],
                    $details
                );
            }

            $res_ffl = $this->rsr_check_catalog_for_bucket($auth['payload'], $request, true, $lines_ffl);
            $details['ffl'] = $res_ffl;

            if (! $res_ffl['ok']) {
                $kind = isset($res_ffl['kind']) ? (string) $res_ffl['kind'] : '';

                if ($kind === 'out_of_stock') {
                    return DistributorOrderValidationResult::block(
                        'RSR out of stock (FFL items): ' . (string) ($res_ffl['message'] ?? 'Out of stock'),
                        ['RSR_OUT_OF_STOCK'],
                        $details
                    );
                }

                $http = isset($res_ffl['http_status']) ? (int) $res_ffl['http_status'] : 0;
                $msg  = (string) ($res_ffl['message'] ?? 'Unknown');

                if ($this->is_rsr_validation_failure_retryable($msg, $http)) {
                    return DistributorOrderValidationResult::block_retryable(
                        'RSR validation (FFL) retryable: ' . $msg,
                        ['RSR_FFL_CHECK_CATALOG_RETRYABLE'],
                        $details
                    );
                }

                return DistributorOrderValidationResult::block(
                    'RSR validation failed (FFL items): ' . $msg,
                    ['RSR_FFL_CHECK_CATALOG_RESTRICTED'],
                    $details
                );
            }
        }

        return DistributorOrderValidationResult::allow('RSR validation OK.', $details);
    }



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

        // Tracking sentinels we should NOT treat as real tracking numbers, this is because RSR is dumb and shows we shipped even if the tracking number is only pending... dumb
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
                        error_log($t_lc);
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
            null,
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
     */
    private function classify_rsr_place_order_failure(array $resp, string $prefix = 'RSR'): DistributorOrderResult
    {
        $http = isset($resp['http_status']) ? (int) $resp['http_status'] : 0;
        $msg  = (string) ($resp['message'] ?? 'Unknown error');

        $details = [
            'raw' => $this->safe_raw_summary($resp['raw'] ?? null),
        ];

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
    private function rsr_check_catalog_for_bucket(array $auth_payload, DistributorOrderRequest $request, bool $ffl_bucket, array $lines): array
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

        $ship = $request->ship_to_for_bucket($ffl_bucket);

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

        if ($ffl_bucket) {
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

        if ($ffl_bucket) {
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
    private function get_rsr_auth_payload(): array
    {
        $username = $this->get_dropship_username();
        $password = $this->get_dropship_password();
        $pos      = $this->get_pos_indicator();

        if ($username === '' || $password === '') {
            return [
                'ok' => false,
                'message' => 'Missing RSR dropship credentials (dropship_account_number/password).',
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

    private function get_pos_indicator(): string
    {
        return trim((string) get_option($this->get_option_name('pos_indicator'), ''));
    }

    private function resolve_dealer_email(): string
    {
        $email = trim((string) get_option('admin_email', ''));
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
                return substr($json, 0, 2000) . '…';
            }
            return $raw;
        }
        return $raw;
    }
}
