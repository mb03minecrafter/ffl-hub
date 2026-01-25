<?php
// File: src/Distributor/Lipseys/DistributorLipseys.php

namespace FFLHub\Distributor\Integrations\Lipseys;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Contracts\DistributorModuleInterface;

use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Distributor\Services\Lipseys\LipseysServices;

use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorShipTo;

/**
 * Lipsey's distributor implementation.
 *
 * Uses the official Lipsey's PHP client (lipseys/apiintegration) if it is available.
 */
class DistributorLipseys extends DistributorBase
{
    private const VALIDATEITEM_CACHE_TTL_SECONDS = 60;
    private const VALIDATEITEM_MAX_UNIQUE_ITEMS = 50;

    /**
     * Toggle Lipsey's validation debug logs.
     *
     * Enable by setting:
     *   define('FFLHUB_LIPSEYS_DEBUG', true);
     * in wp-config.php, OR env var:
     *   FFLHUB_LIPSEYS_DEBUG=1
     */
    private const DEBUG_CONST = 'FFLHUB_LIPSEYS_DEBUG';

    public function __construct(DistributorModuleInterface $module, ?LipseysServices $services = null)
    {
        parent::__construct($module, $services);
    }

    /**
     * Lipsey's image resolver override.
     */
    protected function get_image_url_from_row(array $row, $field): string
    {
        $image_name = $this->get_string_field($row, is_array($field) ? $field : [$field]);
        $image_name = is_string($image_name) ? trim($image_name) : '';

        if ($image_name === '') {
            return '';
        }

        return 'https://www.lipseyscloud.com/images/' . $image_name;
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

        return $this->build_payload_from_row(
            $row,
            [
                'sku'          => ['lipseys_item_number'],
                'upc'          => ['upc'],
                'name'         => ['manufacturer', 'model', 'caliber_gauge'],
                'description'  => ['product_description'],
                'price'        => ['distributor_price'],
                'map'          => ['retail_map'],
                'msrp'         => ['retail_msrp'],
                'quantity'     => ['inventory_quantity'],
                'category'     => ['item_group'],
                'image'        => ['image_name'],
                'ffl_required' => ['ffl_required'],
            ],
            [DistributorProductCategoryMapper::class, 'map_lipseys'],
            $normalized_upc,
            true
        );
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        return 10.0;
    }

    public function validate_order_request(DistributorOrderRequest $request): DistributorOrderValidationResult
    {


        if (empty($request->lines)) {
            return DistributorOrderValidationResult::allow('No order lines to validate.');
        }

        $this->dbg('validate_order_request: start', [
            'lines_count' => is_array($request->lines) ? count($request->lines) : 0,
            'has_ship_to_customer' => ($request->ship_to_customer instanceof DistributorShipTo) ? 1 : 0,
            'has_ship_to_ffl' => ($request->ship_to_ffl instanceof DistributorShipTo) ? 1 : 0,
            'receiving_ffl_len' => strlen((string) $request->receiving_ffl_number),
        ]);

        $email = $this->get_dealer_email();
        $password = $this->get_dealer_password();

        if ($email === '' || $password === '') {
            return DistributorOrderValidationResult::block(
                'Missing Lipsey’s credentials (dealer_email / dealer_password).',
                ['LIPSEYS_CREDS_MISSING']
            );
        }

        $required_by_upc = $this->build_required_qty_by_upc($request->lines);

        $this->dbg('validate_order_request: required_by_upc built', [
            'unique' => count($required_by_upc),
        ]);

        if (empty($required_by_upc)) {
            return DistributorOrderValidationResult::allow('No valid UPC line items to validate.');
        }

        if (count($required_by_upc) > self::VALIDATEITEM_MAX_UNIQUE_ITEMS) {
            return DistributorOrderValidationResult::block(
                'Lipseys validation failed: too many unique items to validate in one checkout (' . count($required_by_upc) . ').',
                ['LIPSEYS_VALIDATEITEM_TOO_MANY_UNIQUE'],
                [
                    'unique_count' => count($required_by_upc),
                    'max_unique' => self::VALIDATEITEM_MAX_UNIQUE_ITEMS,
                ]
            );
        }

        if (! $this->services) {
            return DistributorOrderValidationResult::block(
                'Lipseys services not available; cannot access fulfillment table.',
                ['LIPSEYS_SERVICES_MISSING']
            );
        }

        $client_res = LipseysIntegrationAPI::create_client($email, $password);
        if (! $client_res['ok']) {
            // (1) retryable: transient auth/client init/remote hiccups
            return DistributorOrderValidationResult::block_retryable(
                $client_res['message'],
                ['LIPSEYS_CLIENT_INIT_FAILED']
            );
        }

        /** @var \lipseys\ApiIntegration\LipseysClient $client */
        $client = $client_res['client'];

        $details = [
            'required_by_upc' => $required_by_upc,
            'items' => [],
            'cache_ttl_seconds' => self::VALIDATEITEM_CACHE_TTL_SECONDS,
            'local_fallback' => [
                'used' => false,
                'items' => [],
                'reason' => '',
            ],
        ];

        $blocked_msgs = [];
        $insufficient_msgs = [];

        // (3) retryable failures from ValidateItem (non-quota)
        $retryable_msgs = [];

        $quota_triggered = false;
        $quota_msgs = [];

        foreach ($required_by_upc as $upc => $requiredQty) {
            $requiredQty = (int) $requiredQty;

            $norm = $this->get_cached_validateitem($upc);

            if (! is_array($norm)) {
                $call = LipseysIntegrationAPI::validate_item($client, $upc);
                $norm = $call['result'];
                if (is_array($norm)) {
                    $this->set_cached_validateitem($upc, $norm);
                } else {
                    // (2) retryable: ValidateItem returned invalid/unparseable response
                    $norm = [
                        'ok' => false,
                        'message' => 'ValidateItem returned invalid result',
                        'retryable' => true,
                    ];
                }
            }

            $details['items'][$upc] = array_merge($norm, [
                'requiredQty' => $requiredQty,
            ]);

            $ok = (bool) ($norm['ok'] ?? false);
            $msg_lc = strtolower((string) ($norm['message'] ?? ''));

            // Quota / rate-limit style failures -> local fallback for ALL items.
            if (
                $ok === false &&
                (
                    strpos($msg_lc, 'quota') !== false ||
                    strpos($msg_lc, 'rate') !== false ||
                    strpos($msg_lc, 'exceeded') !== false ||
                    strpos($msg_lc, 'maximum admitted') !== false ||
                    strpos($msg_lc, 'api calls') !== false ||
                    strpos($msg_lc, 'throttle') !== false
                )
            ) {
                $quota_triggered = true;
                $quota_msgs[] = "UPC={$upc}: " . (string) ($norm['message'] ?? 'quota exceeded');
                continue;
            }

            if (! $ok) {
                // (3) treat generic ValidateItem failures as retryable for now
                $retryable_msgs[] = "UPC={$upc}: " . (string) ($norm['message'] ?? 'ValidateItem failed');
                continue;
            }

            if (($norm['blocked'] ?? false) === true) {
                $blocked_msgs[] = "UPC={$upc} blocked=true";
                continue;
            }

            if (($norm['canDropship'] ?? null) === false) {
                $blocked_msgs[] = "UPC={$upc} canDropship=false";
                continue;
            }

            if (($norm['allocated'] ?? false) === true) {
                $blocked_msgs[] = "UPC={$upc} allocated=true";
                continue;
            }

            $available = (int) ($norm['qty'] ?? 0);
            if ($available < $requiredQty) {
                $insufficient_msgs[] = "UPC={$upc} available={$available} required={$requiredQty}";
            }
        }

        if ($quota_triggered) {
            $details['local_fallback']['used'] = true;
            $details['local_fallback']['reason'] = 'ValidateItem quota/rate-limit exceeded';
            $details['local_fallback']['quota_messages'] = $quota_msgs;

            $local_fail_msgs = [];

            foreach ($required_by_upc as $upc => $requiredQty) {
                $requiredQty = (int) $requiredQty;

                $normalized_upc = $this->normalize_upc($upc);
                if ($normalized_upc === null) {
                    $local_fail_msgs[] = "UPC={$upc} invalid (normalize_upc null)";
                    continue;
                }

                $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
                if (! $row || ! is_array($row)) {
                    $local_fail_msgs[] = "UPC={$normalized_upc} not found in local fulfillment table";
                    $details['local_fallback']['items'][$normalized_upc] = [
                        'requiredQty' => $requiredQty,
                        'local_qty' => null,
                        'found' => 0,
                    ];
                    continue;
                }

                $qty_raw = $this->get_string_field($row, ['inventory_quantity']);
                $local_qty = is_numeric($qty_raw) ? (int) $qty_raw : null;

                $details['local_fallback']['items'][$normalized_upc] = [
                    'requiredQty' => $requiredQty,
                    'local_qty' => $local_qty,
                    'found' => 1,
                ];

                if ($local_qty === null) {
                    $local_fail_msgs[] = "UPC={$normalized_upc} local_qty=UNKNOWN required={$requiredQty}";
                    continue;
                }

                if ($local_qty < $requiredQty) {
                    $local_fail_msgs[] = "UPC={$normalized_upc} local_available={$local_qty} required={$requiredQty}";
                }
            }

            if (! empty($local_fail_msgs)) {
                $msg = 'Lipseys validation failed (local fallback): ' . implode(' | ', array_slice($local_fail_msgs, 0, 8));
                if (count($local_fail_msgs) > 8) {
                    $msg .= ' | ...';
                }

                return DistributorOrderValidationResult::block(
                    $msg,
                    ['LIPSEYS_LOCAL_FALLBACK_BLOCKED'],
                    $details
                );
            }

            return DistributorOrderValidationResult::allow('Lipseys validation OK (local fallback).', $details);
        }

        // (3) retryable: any non-quota ValidateItem failure(s)
        if (! empty($retryable_msgs)) {
            $msg = 'Lipseys validation retryable failure: ' . implode(' | ', array_slice($retryable_msgs, 0, 8));
            if (count($retryable_msgs) > 8) {
                $msg .= ' | ...';
            }

            return DistributorOrderValidationResult::block_retryable(
                $msg,
                ['LIPSEYS_VALIDATEITEM_RETRYABLE'],
                $details
            );
        }

        if (! empty($blocked_msgs)) {
            $msg = 'Lipseys validation failed: ' . implode(' | ', array_slice($blocked_msgs, 0, 8));
            if (count($blocked_msgs) > 8) {
                $msg .= ' | ...';
            }

            return DistributorOrderValidationResult::block(
                $msg,
                ['LIPSEYS_VALIDATEITEM_BLOCKED'],
                $details
            );
        }

        if (! empty($insufficient_msgs)) {
            $msg = 'Lipseys validation failed (insufficient stock): ' . implode(' | ', array_slice($insufficient_msgs, 0, 8));
            if (count($insufficient_msgs) > 8) {
                $msg .= ' | ...';
            }

            return DistributorOrderValidationResult::block(
                $msg,
                ['LIPSEYS_INSUFFICIENT_STOCK'],
                $details
            );
        }

        return DistributorOrderValidationResult::allow('Lipseys validation OK.', $details);
    }


    /**
     * Place Lipsey's orders.
     *
     * Splits internally:
     *  - non-FFL lines => DropShip (accessories/optics)  [api/Integration/Order/DropShip]
     *  - FFL lines     => DropShipFirearm               [api/Integration/Order/DropShipFirearm]
     *
     * Returns DistributorOrderResult (NEW shape):
     *  - code: OK | BLOCK_RETRYABLE | BLOCK_FATAL
     *  - codes[]: reason(s)
     */
    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        if (! class_exists('\\lipseys\\ApiIntegration\\LipseysClient')) {
            return DistributorOrderResult::block_fatal(
                'Lipseys API client not available (lipseys/apiintegration).',
                [DistributorOrderResult::REASON_FATAL_CLIENT_MISSING]
            );
        }

        if (! $this->services) {
            return DistributorOrderResult::block_fatal(
                'Lipseys services not available; cannot access fulfillment table.',
                [DistributorOrderResult::REASON_FATAL_SERVICES_MISSING]
            );
        }

        if (empty($request->lines)) {
            return DistributorOrderResult::block_fatal(
                'No order lines provided.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        $email = $this->get_dealer_email();
        $password = $this->get_dealer_password();

        if ($email === '' || $password === '') {
            return DistributorOrderResult::block_fatal(
                'Missing Lipsey’s credentials (dealer_email / dealer_password).',
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS]
            );
        }

        if (! ($request->ship_to_customer instanceof DistributorShipTo)) {
            return DistributorOrderResult::block_fatal(
                'Missing ship_to_customer (required for Lipsey’s drop-ship).',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        // Build item payloads (mapping failures return DistributorOrderResult already).
        $items_non = $this->build_lipseys_items($request->non_ffl_lines(), true);
        if ($items_non instanceof DistributorOrderResult) {
            return $items_non;
        }

        $items_ffl = $this->build_lipseys_items($request->ffl_lines(), true);
        if ($items_ffl instanceof DistributorOrderResult) {
            return $items_ffl;
        }

        if (empty($items_non) && empty($items_ffl)) {
            return DistributorOrderResult::block_fatal(
                'No valid line items after normalization.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        $client_res = LipseysIntegrationAPI::create_client($email, $password);
        if (! $client_res['ok'] || ! is_object($client_res['client'])) {
            // NOTE: this is consistent with validate_order_request treating client init failures as retryable.
            return DistributorOrderResult::block_retryable(
                (string) ($client_res['message'] ?? 'Failed to initialize Lipsey’s client.'),
                [DistributorOrderResult::REASON_RETRY_UNKNOWN],
                ['client_init' => $client_res]
            );
        }

        /** @var \lipseys\ApiIntegration\LipseysClient $client */
        $client = $client_res['client'];

        $external_ids = [];
        $errors = [];

        $base_po = self::sanitize_po((string) $request->merchant_order_id);
        if ($base_po === '') {
            $base_po = 'FFLHUB';
        }

        $customer = $request->ship_to_customer;

        // 1) Non-FFL -> DropShip (accessories/optics)
        if (! empty($items_non)) {
            $po = $base_po . '-NON';

            $payload = [
                // Optional (depends on the Lipsey's account configuration)
                // 'Warehouse' => '',

                'PoNumber' => $po,

                // Billing prints on packing slip (consumer billing)
                'BillingName'         => self::normalize_payload_string($customer->name),
                'BillingAddressLine1' => self::normalize_payload_string($customer->address1),
                'BillingAddressLine2' => self::normalize_payload_string($customer->address2),
                'BillingAddressCity'  => self::normalize_payload_string($customer->city),
                'BillingAddressState' => self::normalize_us_state_code_for_payload($customer->state),
                'BillingAddressZip'   => self::format_us_zip5_for_payload($customer->zip),

                // Shipping is consumer shipping
                'ShippingName'         => self::normalize_payload_string($customer->name),
                'ShippingAddressLine1' => self::normalize_payload_string($customer->address1),
                'ShippingAddressLine2' => self::normalize_payload_string($customer->address2),
                'ShippingAddressCity'  => self::normalize_payload_string($customer->city),
                'ShippingAddressState' => self::normalize_us_state_code_for_payload($customer->state),
                'ShippingAddressZip'   => self::format_us_zip5_for_payload($customer->zip),

                // Optional fields
                // 'MessageForSalesExec' => '',
                'DisableEmail' => true,
                'Overnight'    => false,

                'Items' => $items_non,
            ];

            $resp = null;

            try {
                // IMPORTANT: This maps to api/Integration/Order/DropShip
                $resp = $client->DropShipAccessories($payload);
            } catch (\Throwable $e) {
                $classified = $this->classify_lipseys_exception_as_order_result($e, 'Lipseys NON DropShip', $po);
                if ($classified->is_retryable()) {
                    return $classified; // fail job retryable
                }
                $errors[] = $classified->message; // accumulate fatal message(s)
                $resp = null;
            }

            if ($resp !== null) {
                $norm = LipseysIntegrationAPI::normalize_order_response($resp, $po, 'DropShip');
                if (! $norm['ok']) {
                    $classified = $this->classify_lipseys_order_failure($norm, 'Lipseys NON');
                    if ($classified->is_retryable()) {
                        return $classified; // fail job retryable
                    }
                    $errors[] = $classified->message;
                } else {
                    $external_ids[] = (string) $norm['external_id'];
                }
            }
        }

        // 2) FFL -> DropShipFirearm (firearms only)
        if (! empty($items_ffl)) {
            $ffl_num = strtoupper(trim((string) $request->receiving_ffl_number));
            if ($ffl_num === '') {
                $errors[] = 'Lipseys FFL: missing receiving FFL number.';
            } else {
                $po = $base_po . '-FFL';

                $cust_name = trim((string) $customer->name);
                if ($cust_name === '') {
                    $cust_name = 'Customer';
                }

                $cust_phone = trim((string) $customer->phone);
                if ($cust_phone === '' && ($request->ship_to_ffl instanceof DistributorShipTo)) {
                    $cust_phone = trim((string) $request->ship_to_ffl->phone);
                }

                if ($cust_phone === '') {
                    $errors[] = 'Lipseys FFL: missing customer phone (required by DropShipFirearm).';
                } else {
                    $payload = [
                        'Ffl'           => $ffl_num,
                        'Po'            => $po, // NOTE: DropShipFirearm uses "Po" (not PoNumber)
                        'Name'          => self::normalize_payload_string($cust_name),
                        'Phone'         => self::normalize_payload_string($cust_phone),
                        'DelayShipping' => false,
                        'DisableEmail'  => true,
                        'Items'         => $items_ffl,
                    ];

                    $resp = null;

                    try {
                        // IMPORTANT: This maps to api/Integration/Order/DropShipFirearm
                        $resp = $client->DropShipFirearms($payload);
                    } catch (\Throwable $e) {
                        $classified = $this->classify_lipseys_exception_as_order_result($e, 'Lipseys FFL DropShipFirearm', $po);
                        if ($classified->is_retryable()) {
                            return $classified;
                        }
                        $errors[] = $classified->message;
                        $resp = null;
                    }

                    if ($resp !== null) {
                        $norm = LipseysIntegrationAPI::normalize_order_response($resp, $po, 'DropShipFirearm');
                        if (! $norm['ok']) {
                            $classified = $this->classify_lipseys_order_failure($norm, 'Lipseys FFL');
                            if ($classified->is_retryable()) {
                                return $classified;
                            }
                            $errors[] = $classified->message;
                        } else {
                            $external_ids[] = (string) $norm['external_id'];
                        }
                    }
                }
            }
        }

        if (! empty($errors)) {
            // No partial orders: if *any* branch fatals, whole job fatals (but keep external_ids for visibility).
            // Use FATAL_RESTRICTED if any error looks like restricted, else UNKNOWN.
            $all = strtolower(implode(' | ', $errors));
            $reason = (
                strpos($all, 'restricted') !== false ||
                strpos($all, 'prohibited') !== false ||
                strpos($all, 'not allowed') !== false ||
                strpos($all, 'cannot ship') !== false ||
                strpos($all, 'denied') !== false
            )
                ? DistributorOrderResult::REASON_FATAL_RESTRICTED
                : DistributorOrderResult::REASON_FATAL_UNKNOWN;

            return DistributorOrderResult::block_fatal(
                'Lipseys order failed: ' . implode(' | ', $errors),
                [$reason],
                ['errors' => $errors],
                0,
                '',
                $external_ids
            );
        }

        return DistributorOrderResult::ok('Lipseys order submitted.', $external_ids);
    }


    /**
     * Build Lipsey's Items[] payload from normalized order lines.
     *
     * @param DistributorOrderLine[] $lines
     * @param bool $allow_empty
     * @return array<int,array{ItemNo:string,Quantity:int}>|DistributorOrderResult
     */
    private function build_lipseys_items(array $lines, bool $allow_empty = false)
    {
        return $this->map_order_lines_to_items(
            $lines,
            function (string $normalized_upc, string $raw_upc, DistributorOrderLine $line): ?string {
                // normalized_upc already digits-only; lookup expects normalized ok.
                return $this->lookup_item_number_by_upc($normalized_upc);
            },
            function (string $item_no, int $qty, string $normalized_upc, string $raw_upc, DistributorOrderLine $line): array {
                return ['ItemNo' => $item_no, 'Quantity' => $qty];
            },
            'Cannot map UPC to Lipsey’s item number: %s',
            ! $allow_empty,
            'No valid Lipsey’s line items after normalization.'
        );
    }

    private function lookup_item_number_by_upc(string $upc): ?string
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

        $item_no = $this->get_string_field($row, ['lipseys_item_number']);
        $item_no = trim((string) $item_no);

        return $item_no !== '' ? $item_no : null;
    }

    /**
     * Classify a normalized Lipsey's order failure into retryable vs fatal.
     *
     * @param array<string,mixed> $norm
     */
    private function classify_lipseys_order_failure(array $norm, string $prefix): DistributorOrderResult
    {
        $msg = (string) ($norm['message'] ?? 'Unknown error');
        $http = isset($norm['http_status']) ? (int) $norm['http_status'] : 0;
        $provider = isset($norm['provider_error_code']) ? (string) $norm['provider_error_code'] : '';

        $lc = strtolower($msg);

        $details = [
            'raw' => isset($norm['raw']) ? $norm['raw'] : null,
        ];

        // Prefer explicit HTTP classification if present.
        if ($http === 429) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': rate limit (HTTP 429): ' . $msg,
                [DistributorOrderResult::REASON_RETRY_RATE_LIMIT],
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

        if ($http === 502 || $http === 503) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': upstream error (HTTP ' . $http . '): ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UPSTREAM],
                $details,
                $http,
                $provider
            );
        }

        // Auth failures -> fatal creds
        if (strpos($lc, 'not authorized') !== false || strpos($lc, 'unauthorized') !== false) {
            return DistributorOrderResult::block_fatal(
                $prefix . ': not authorized: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS],
                $details,
                $http,
                $provider
            );
        }

        // Quota / rate limit heuristics
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
                $http,
                $provider
            );
        }

        // Network/timeout heuristics
        if (
            strpos($lc, 'timeout') !== false ||
            strpos($lc, 'timed out') !== false ||
            strpos($lc, 'could not resolve') !== false ||
            strpos($lc, 'connection') !== false ||
            strpos($lc, 'ssl') !== false ||
            strpos($lc, 'curl error 28') !== false
        ) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': timeout/network: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_TIMEOUT],
                $details,
                $http,
                $provider
            );
        }

        // Upstream heuristics
        if (
            strpos($lc, '502') !== false ||
            strpos($lc, '503') !== false ||
            strpos($lc, 'bad gateway') !== false ||
            strpos($lc, 'service unavailable') !== false ||
            strpos($lc, 'temporar') !== false
        ) {
            return DistributorOrderResult::block_retryable(
                $prefix . ': upstream: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UPSTREAM],
                $details,
                $http,
                $provider
            );
        }

        // Restricted (your requested fatal)
        if (
            strpos($lc, 'restricted') !== false ||
            strpos($lc, 'restriction') !== false ||
            strpos($lc, 'cannot ship') !== false ||
            strpos($lc, 'not allowed') !== false ||
            strpos($lc, 'prohibited') !== false ||
            strpos($lc, 'denied') !== false ||
            strpos($lc, 'blocked') !== false
        ) {
            return DistributorOrderResult::block_fatal(
                $prefix . ': restricted: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_RESTRICTED],
                $details,
                $http,
                $provider
            );
        }

        // Stock
        if (strpos($lc, 'out of stock') !== false || strpos($lc, 'insufficient') !== false) {
            return DistributorOrderResult::block_fatal(
                $prefix . ': out of stock: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_OUT_OF_STOCK],
                $details,
                $http,
                $provider
            );
        }

        return DistributorOrderResult::block_fatal(
            $prefix . ': order failed: ' . $msg,
            [DistributorOrderResult::REASON_FATAL_UNKNOWN],
            $details,
            $http,
            $provider
        );
    }


    private function classify_lipseys_exception_as_order_result(\Throwable $e, string $prefix, string $po): DistributorOrderResult
    {
        $msg = (string) $e->getMessage();
        $lc = strtolower($msg);

        $details = ['po' => $po];

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
            return DistributorOrderResult::block_retryable(
                $prefix . ' exception: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_TIMEOUT],
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
            return DistributorOrderResult::block_retryable(
                $prefix . ' exception: ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UPSTREAM],
                $details
            );
        }

        return DistributorOrderResult::block_fatal(
            $prefix . ' exception: ' . $msg,
            [DistributorOrderResult::REASON_FATAL_UNKNOWN],
            $details
        );
    }


    private function get_dealer_email(): string
    {
        return trim((string) get_option($this->get_option_name('dealer_email'), ''));
    }

    private function get_dealer_password(): string
    {
        return trim((string) get_option($this->get_option_name('dealer_password'), ''));
    }

    private static function sanitize_po(string $po): string
    {
        $po = trim($po);
        if ($po === '') {
            return '';
        }

        $po = preg_replace('/[^A-Za-z0-9\-]+/', '-', $po);
        $po = is_string($po) ? $po : '';
        $po = trim($po, '-');

        if (strlen($po) > 24) {
            $po = substr($po, 0, 24);
        }

        return $po;
    }



    /**
     * Aggregate required quantities by normalized UPC.
     *
     * @param DistributorOrderLine[] $lines
     * @return array<string,int> map of UPC => requiredQty
     */
    private function build_required_qty_by_upc(array $lines): array
    {
        $required = [];

        foreach ($lines as $idx => $l) {
            if (! ($l instanceof DistributorOrderLine)) {
                $this->dbg('build_required_qty_by_upc: skipping non-DistributorOrderLine', [
                    'idx' => (int) $idx,
                    'type' => is_object($l) ? get_class($l) : gettype($l),
                ]);
                continue;
            }

            $raw_upc = $this->read_line_upc($l);
            $qty = $this->read_line_qty($l);

            $upc = $this->normalize_upc($raw_upc);
            if ($upc === null) {
                continue;
            }

            if ($qty < 1) {
                continue;
            }

            if (! isset($required[$upc])) {
                $required[$upc] = 0;
            }

            $required[$upc] += $qty;
        }

        return $required;
    }

    private function read_line_upc(DistributorOrderLine $l): string
    {
        foreach (['upc', 'get_upc', 'getUpc'] as $m) {
            if (method_exists($l, $m)) {
                try {
                    $v = $l->{$m}();
                    $v = is_string($v) ? $v : (string) $v;
                    $v = trim($v);
                    if ($v !== '') {
                        return $v;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        $raw = '';
        if (isset($l->upc)) {
            $raw = (string) $l->upc;
        }
        return trim($raw);
    }

    private function read_line_qty(DistributorOrderLine $l): int
    {
        foreach (['qty', 'get_qty', 'getQty'] as $m) {
            if (method_exists($l, $m)) {
                try {
                    $v = $l->{$m}();
                    return max(0, (int) $v);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        if (isset($l->quantity)) {
            return max(0, (int) $l->quantity);
        }

        return 0;
    }

    private function cache_key_validateitem(string $upc): string
    {
        return 'fflhub_lipseys_validateitem_' . md5($upc);
    }

    private function get_cached_validateitem(string $upc): ?array
    {
        $v = get_transient($this->cache_key_validateitem($upc));
        return is_array($v) ? $v : null;
    }

    private function set_cached_validateitem(string $upc, array $value): void
    {
        set_transient($this->cache_key_validateitem($upc), $value, self::VALIDATEITEM_CACHE_TTL_SECONDS);
    }

    /* ---------------- Debug helpers ---------------- */

    private function dbg_enabled(): bool
    {
        if (defined(self::DEBUG_CONST)) {
            return (bool) constant(self::DEBUG_CONST);
        }

        $env = getenv(self::DEBUG_CONST);
        if ($env !== false) {
            $env = strtolower(trim((string) $env));
            return in_array($env, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @param string $msg
     * @param array<string,mixed> $ctx
     */
    private function dbg(string $msg, array $ctx = []): void
    {
        if (! $this->dbg_enabled()) {
            return;
        }
        $prefix = '[FFLHub Lipseys] ';
        if (! empty($ctx)) {
            error_log($prefix . $msg . ' ' . wp_json_encode($ctx));
        } else {
            error_log($prefix . $msg);
        }
    }
}
