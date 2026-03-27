<?php
// File: src/Distributor/Lipseys/DistributorLipseys.php

namespace FFLHub\Distributor\Integrations\Lipseys;

if (!defined('ABSPATH')) {
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
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Util\DebugLogUtil;

/**
 * Lipsey's distributor implementation (runtime behavior).
 *
 * Key responsibilities:
 * - Read Lipsey's fulfillment table to build normalized product payloads.
 * - Validate checkout lines against Lipsey's rules/availability:
 *     - Prefer remote ValidateItem when allowed
 *     - Fall back to local fulfillment stock when rate-limited/quota-limited
 *     - Support local-only mode to avoid remote API calls (cron workloads / safety)
 * - Place orders through Lipsey's Integration API:
 *     - Non-FFL lines -> DropShip
 *     - FFL lines     -> DropShipFirearm
 * - Look up shipment/tracking by PO via Lipsey's shipment table (if enabled).
 *
 * Dependencies:
 * - LipseysServices: provides access to fulfillment table (and shipment table).
 * - LipseysIntegrationAPI: wraps client creation + response normalization.
 *
 * IMPORTANT invariants:
 * - UPC normalization is digits-only (handled by DistributorBase::normalize_upc()).
 * - This class never writes to fulfillment tables; reads only.
 * - Ordering returns DistributorOrderResult (NEW shape) with retryable vs fatal classification.
 */
class DistributorLipseys extends DistributorBase
{
    private const FLAT_SHIPPING_COST = 10.0;

    /**
     * ValidateItem caching:
     * - Avoid spamming Lipsey's API during a single burst of checkouts.
     * - Keep TTL short so stock/flags stay reasonably fresh.
     */
    private const VALIDATEITEM_CACHE_TTL_SECONDS = 60;

    /**
     * Guardrail: ValidateItem is per-item; prevent pathological carts from
     * triggering dozens/hundreds of remote calls in a single request.
     */
    private const VALIDATEITEM_MAX_UNIQUE_ITEMS = 50;

    public function __construct(DistributorModuleInterface $module, ?LipseysServices $services = null)
    {
        parent::__construct($module, $services);
    }

    /* -------------------------------------------------------------------------
     * Product lookup
     * ---------------------------------------------------------------------- */

    /**
     * Lipsey's image resolver.
     *
     * Lipsey's fulfillment table stores an image file name; the public image URL
     * is hosted on lipseyscloud.com/images/{image_name}.
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

    /**
     * Build a normalized DistributorProductPayload for a UPC using the local fulfillment table.
     *
     * NOTE:
     * - This does NOT call Lipsey's API; it relies on your synced local table.
     * - Category mapping is delegated to DistributorProductCategoryMapper::map_lipseys().
     */
    public function get_product_by_upc(string $upc): ?DistributorProductPayload
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

        return $this->build_payload_from_row(
            $row,
            [
                'sku'          => ['lipseys_item_number'],
                'upc'          => ['upc'],
                'name'         => ['manufacturer', 'model', 'caliber_gauge'],
                'description'  => ['product_description'],
                'brand'        => ['manufacturer'],
                'price'        => ['distributor_price'],
                'map'          => ['retail_map'],
                'msrp'         => ['retail_msrp'],
                'quantity'     => ['inventory_quantity'],
                'category'     => ['item_group'],
                'image'        => ['image_name'],
                'shipping_weight' => ['shipping_weight'],
                'shipping_length_in' => ['shipping_length_in'],
                'shipping_width_in'  => ['shipping_width_in'],
                'shipping_height_in' => ['shipping_height_in'],
                'ffl_required' => ['ffl_required'],
                'sot_required' => ['sot_required'],
                'dropship_enabled' => ['dropship_enabled'],
            ],
            [DistributorProductCategoryMapper::class, 'map_lipseys'],
            $normalized_upc,
            true
        );
    }

    /**
     * Placeholder shipping estimator for Lipsey's.
     *
     * Right now this is a fixed heuristic. If/when Lipsey's provides
     * reliable per-item shipping or you derive a model from historical orders,
     * this is the choke point.
     */
    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        return self::FLAT_SHIPPING_COST;
    }







    //LIPSEYS VALIDATION SECTION

    protected function supports_remote_validation(): bool
    {
        return true;
    }

    protected function validation_max_unique_items(DistributorOrderRequest $request, bool $local_only): int
    {
        return self::VALIDATEITEM_MAX_UNIQUE_ITEMS;
    }

    protected function validation_too_many_unique_code(): string
    {
        return 'LIPSEYS_VALIDATEITEM_TOO_MANY_UNIQUE';
    }

    protected function validation_services_missing_code(): string
    {
        return 'LIPSEYS_SERVICES_MISSING';
    }

    protected function validation_services_missing_message(): string
    {
        return 'Lipseys services not available; cannot access fulfillment table.';
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
        // Used for local_only path only; remote quota fallback builds its own opts.
        return [
            'label'              => 'Lipseys validation (local_only)',
            'max_unique'         => self::VALIDATEITEM_MAX_UNIQUE_ITEMS,
            'inventory_keys'     => ['inventory_quantity'],
            'unknown_qty_blocks' => true,
            'lane'               => '',
            'enforce_ffl_required' => null,
        ];
    }

    /**
     * @param array<string,int> $required_by_upc
     */
    protected function validate_order_request_remote(
        DistributorOrderRequest $request,
        array $required_by_upc
    ): DistributorOrderValidationResult {
        DebugLogUtil::log_ctx(
            'FFLHUB_LIPSEYS_DEBUG',
            '[FFLHub][LipseysValidation]',
            'validation.path.remote.start',
            [
                'upc_count' => count($required_by_upc),
                'cache_ttl_seconds' => self::VALIDATEITEM_CACHE_TTL_SECONDS,
                'credential_source' => 'main_account',
            ]
        );

        $email    = $this->get_main_account_email();
        $password = $this->get_main_account_password();

        if ($email === '' || $password === '') {
            return DistributorOrderValidationResult::block(
                'Missing Lipsey\'s validation credentials (main_account_email / main_account_password).',
                ['LIPSEYS_CREDS_MISSING']
            );
        }

        $client_res = LipseysIntegrationAPI::create_raw_client($email, $password);
        if (!($client_res['ok'] ?? false)) {
            return DistributorOrderValidationResult::block_retryable(
                (string) ($client_res['message'] ?? 'Lipseys raw API client init failed.'),
                ['LIPSEYS_CLIENT_INIT_FAILED']
            );
        }

        /** @var \FFLHub\Distributor\Services\Lipseys\LipseysRawAPI\LipseysClient $client */
        $client = $client_res['client'];

        $details = [
            'required_by_upc'   => $required_by_upc,
            'items'             => [],
            'cache_ttl_seconds' => self::VALIDATEITEM_CACHE_TTL_SECONDS,
            'local_only'        => false,
        ];

        $blocked_msgs      = [];
        $insufficient_msgs = [];
        $retryable_msgs    = [];

        $quota_triggered = false;
        $quota_msgs      = [];

        foreach ($required_by_upc as $upc => $requiredQty) {
            $requiredQty = (int) $requiredQty;

            $norm = $this->get_cached_validateitem($upc);
            if (!is_array($norm)) {
                $call = LipseysIntegrationAPI::validate_item($client, $upc);
                $norm = $call['result'] ?? null;

                if (is_array($norm)) {
                    $this->set_cached_validateitem($upc, $norm);
                } else {
                    $norm = [
                        'ok'        => false,
                        'message'   => 'ValidateItem returned invalid result',
                        'retryable' => true,
                    ];
                }
            }

            $details['items'][$upc] = array_merge($norm, [
                'requiredQty' => $requiredQty,
            ]);

            $ok     = (bool) ($norm['ok'] ?? false);
            $msg    = (string) ($norm['message'] ?? '');
            $msg_lc = strtolower($msg);

            if ($ok === false && $this->lipseys_msg_indicates_quota_or_rate_limit($msg_lc)) {
                $quota_triggered = true;
                $quota_msgs[] = "UPC={$upc}: " . ($msg !== '' ? $msg : 'quota exceeded');
                continue;
            }

            if (!$ok) {
                $retryable_msgs[] = "UPC={$upc}: " . ($msg !== '' ? $msg : 'ValidateItem failed');
                continue;
            }

            if (($norm['blocked'] ?? false) === true) {
                $blocked_msgs[] = "UPC={$upc} blocked=true";
                continue;
            }

            // Non-dropship items are valid in the new fulfillment model (dealer fulfilled),
            // so canDropship=false must not block checkout validation.

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
            DebugLogUtil::log_ctx(
                'FFLHUB_LIPSEYS_DEBUG',
                '[FFLHub][LipseysValidation]',
                'validation.path.remote.quota_fallback_local',
                [
                    'upc_count' => count($required_by_upc),
                    'quota_messages' => $quota_msgs,
                ]
            );

            $res = $this->validate_local_fulfillment_required_qty_by_upc(
                $request->lines,
                [
                    'label'              => 'Lipseys validation (local fallback)',
                    'max_unique'         => self::VALIDATEITEM_MAX_UNIQUE_ITEMS,
                    'inventory_keys'     => ['inventory_quantity'],
                    'unknown_qty_blocks' => true,
                ]
            );

            $res->details = is_array($res->details) ? $res->details : [];
            $res->details['quota_triggered'] = 1;
            $res->details['quota_messages']  = $quota_msgs;

            DebugLogUtil::log_ctx(
                'FFLHUB_LIPSEYS_DEBUG',
                '[FFLHub][LipseysValidation]',
                'validation.path.remote.quota_fallback_local.result',
                [
                    'code' => (string) ($res->code ?? ''),
                    'ok' => !empty($res->ok) ? 1 : 0,
                    'codes' => is_array($res->codes) ? $res->codes : [],
                    'message' => (string) ($res->message ?? ''),
                ]
            );

            return $res;
        }

        if (!empty($retryable_msgs)) {
            DebugLogUtil::log_ctx(
                'FFLHUB_LIPSEYS_DEBUG',
                '[FFLHub][LipseysValidation]',
                'validation.path.remote.result',
                [
                    'status' => 'retryable',
                    'retryable_count' => count($retryable_msgs),
                ]
            );

            return DistributorOrderValidationResult::block_retryable(
                'Lipseys validation retryable failure: ' . $this->join_msgs($retryable_msgs),
                ['LIPSEYS_VALIDATEITEM_RETRYABLE'],
                $details
            );
        }

        if (!empty($blocked_msgs)) {
            DebugLogUtil::log_ctx(
                'FFLHUB_LIPSEYS_DEBUG',
                '[FFLHub][LipseysValidation]',
                'validation.path.remote.result',
                [
                    'status' => 'blocked',
                    'blocked_count' => count($blocked_msgs),
                ]
            );

            return DistributorOrderValidationResult::block(
                'Lipseys validation failed: ' . $this->join_msgs($blocked_msgs),
                ['LIPSEYS_VALIDATEITEM_BLOCKED'],
                $details
            );
        }

        if (!empty($insufficient_msgs)) {
            DebugLogUtil::log_ctx(
                'FFLHUB_LIPSEYS_DEBUG',
                '[FFLHub][LipseysValidation]',
                'validation.path.remote.result',
                [
                    'status' => 'insufficient',
                    'insufficient_count' => count($insufficient_msgs),
                ]
            );

            return DistributorOrderValidationResult::block(
                'Lipseys validation failed (insufficient stock): ' . $this->join_msgs($insufficient_msgs),
                ['LIPSEYS_INSUFFICIENT_STOCK'],
                $details
            );
        }

        DebugLogUtil::log_ctx(
            'FFLHUB_LIPSEYS_DEBUG',
            '[FFLHub][LipseysValidation]',
            'validation.path.remote.result',
            [
                'status' => 'ok',
                'upc_count' => count($required_by_upc),
            ]
        );

        return DistributorOrderValidationResult::allow('Lipseys validation OK.', $details);
    }

    /**
     * Log when the base class local validation path is used first.
     *
     * @param array<string,int> $required_by_upc
     */
    protected function validate_order_request_local(
        DistributorOrderRequest $request,
        array $required_by_upc,
        bool $local_only
    ): DistributorOrderValidationResult {
        DebugLogUtil::log_ctx(
            'FFLHUB_LIPSEYS_DEBUG',
            '[FFLHub][LipseysValidation]',
            'validation.path.local.start',
            [
                'upc_count' => count($required_by_upc),
                'local_only' => $local_only ? 1 : 0,
            ]
        );

        $res = parent::validate_order_request_local($request, $required_by_upc, $local_only);

        DebugLogUtil::log_ctx(
            'FFLHUB_LIPSEYS_DEBUG',
            '[FFLHub][LipseysValidation]',
            'validation.path.local.result',
            [
                'code' => (string) ($res->code ?? ''),
                'ok' => !empty($res->ok) ? 1 : 0,
                'codes' => is_array($res->codes) ? $res->codes : [],
                'message' => (string) ($res->message ?? ''),
            ]
        );

        return $res;
    }

    private function lipseys_msg_indicates_quota_or_rate_limit(string $msg_lc): bool
    {
        // keep your exact matching behavior
        return (
            strpos($msg_lc, 'quota') !== false ||
            strpos($msg_lc, 'rate') !== false ||
            strpos($msg_lc, 'exceeded') !== false ||
            strpos($msg_lc, 'maximum admitted') !== false ||
            strpos($msg_lc, 'api calls') !== false ||
            strpos($msg_lc, 'throttle') !== false
        );
    }




    //END VALIDATION SECTION






    //ORDERING SECTION
    protected function supports_ordering(): bool
    {
        return true;
    }

    protected function place_order_stop_on_first_failure(): bool
    {
        return false; // Lipsey's can attempt both direct-ship lanes
    }

    /**
     * Default already does this, but making it explicit:
     * short-circuit retryables (keep idempotency simple).
     */
    protected function place_order_should_short_circuit_on_failure(DistributorOrderResult $res): bool
    {
        if ($res->is_retryable()) {
            return true;
        }

        $codes = is_array($res->codes) ? array_map('strval', $res->codes) : [];
        if (in_array('TEST_ORDER_DEBUG_BLOCK', $codes, true)) {
            return true;
        }

        return false;
    }

    protected function place_order_precheck(DistributorOrderRequest $request): ?DistributorOrderResult
    {
        // Hard dependency: internal raw Lipsey's client must be available.
        if (!class_exists('\\FFLHub\\Distributor\\Services\\Lipseys\\LipseysRawAPI\\LipseysClient')) {
            return DistributorOrderResult::block_fatal(
                'Lipseys raw API client not available.',
                [DistributorOrderResult::REASON_FATAL_CLIENT_MISSING]
            );
        }

        $base = parent::place_order_precheck($request);
        if ($base instanceof DistributorOrderResult) {
            return $base;
        }

        $lane = strtolower(trim((string) ($request->lane ?? '')));
        $dealer_fulfilled = ($lane === 'dealer_fulfilled');

        if ($dealer_fulfilled) {
            $email    = $this->get_main_account_email();
            $password = $this->get_main_account_password();
        } else {
            $email    = $this->get_dealer_email();
            $password = $this->get_dealer_password();
        }

        if ($email === '' || $password === '') {
            $msg = $dealer_fulfilled
                ? 'Missing Lipsey\'s main account credentials (main_account_email / main_account_password).'
                : 'Missing Lipsey\'s dealer credentials (dealer_email / dealer_password).';

            return DistributorOrderResult::block_fatal(
                $msg,
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS]
            );
        }

        // Direct-ship lanes require a consumer ship-to address.
        if (!$dealer_fulfilled && !($request->ship_to_customer instanceof DistributorShipTo)) {
            return DistributorOrderResult::block_fatal(
                'Missing ship_to_customer (required for Lipsey\'s drop-ship).',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        // Client init can fail transiently; treat as retryable.
        $client_res = LipseysIntegrationAPI::create_client($email, $password);
        if (!($client_res['ok'] ?? false) || !is_object($client_res['client'])) {
            return DistributorOrderResult::block_retryable(
                (string) ($client_res['message'] ?? 'Failed to initialize Lipsey raw API client.'),
                [DistributorOrderResult::REASON_RETRY_UNKNOWN],
                ['client_init' => $client_res]
            );
        }

        // Stash client so lane handler does not re-init
        $this->lipseys_client = $client_res['client'];

        return null;
    }

    /** @var \FFLHub\Distributor\Services\Lipseys\LipseysRawAPI\LipseysClient|null */
    protected $lipseys_client = null;

    protected function place_order_lane(
        DistributorOrderRequest $request,
        string $lane,
        array $lines,
        array &$external_ids
    ): DistributorOrderResult {
        $lane = strtolower(trim((string) $lane));

        /** @var \FFLHub\Distributor\Services\Lipseys\LipseysRawAPI\LipseysClient $client */
        $client = $this->lipseys_client;

        if (!$client) {
            // Should not happen because precheck guards it, but keep it defensive.
            return DistributorOrderResult::block_retryable(
                'Lipseys client missing during lane placement.',
                [DistributorOrderResult::REASON_RETRY_UNKNOWN],
                [],
                0,
                '',
                $external_ids
            );
        }

        // Build Items[] arrays by mapping UPC -> lipseys_item_number using local table.
        $items = $this->build_lipseys_items($lines, true);
        if ($items instanceof DistributorOrderResult) {
            $items->external_order_ids = $external_ids;
            return $items;
        }

        if (empty($items)) {
            return DistributorOrderResult::block_fatal(
                'No valid line items after normalization.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $base_po = self::sanitize_po((string) $request->merchant_order_id);
        if ($base_po === '') {
            $base_po = 'FFLHUB';
        }

        /** @var DistributorShipTo $customer */
        $customer = $request->ship_to_customer;

        $item_note = trim((string) $request->notes);
        if ($item_note !== '') {
            foreach ($items as &$item_row) {
                if (!is_array($item_row)) {
                    continue;
                }
                $item_row['Note'] = $item_note;
            }
            unset($item_row);
        }

        if ($lane === 'dealer_fulfilled') {
            $po = $base_po . '-DF';

            $payload = [
                'PONumber'     => $po,
                'DisableEmail' => true,
                'Items'        => $items,
            ];

            if ($this->is_test_order_debug_enabled()) {
                return $this->build_test_order_debug_block(
                    $lane,
                    'https://api.lipseys.com/api/Integration/Order/APIOrder',
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

            try {
                $resp = $client->Order($payload); // APIOrder
            } catch (\Throwable $e) {
                $r = $this->classify_lipseys_exception_as_order_result($e, 'Lipseys dealer-fulfilled APIOrder', $po);
                $r->external_order_ids = $external_ids;
                return $r;
            }

            $norm = LipseysIntegrationAPI::normalize_order_response($resp, $po, 'APIOrder');

            $item_issues = $this->collect_lipseys_api_order_item_issues($resp);
            if (!empty($item_issues)) {
                $norm['ok'] = false;
                $norm['message'] = 'APIOrder line errors: ' . $this->join_msgs($item_issues, 10);
            }

            if (!($norm['ok'] ?? false)) {
                $r = $this->classify_lipseys_order_failure($norm, 'Lipseys dealer-fulfilled');
                $r->external_order_ids = $external_ids;
                return $r;
            }

            $external_ids[] = (string) ($norm['external_id'] ?? '');
            return DistributorOrderResult::ok('Lipseys dealer-fulfilled order submitted.', $external_ids);
        }

        if ($lane === 'direct_ship_non_ffl') {
            $po = $base_po . '-NON';

            $payload = [
                'PoNumber' => $po,

                'BillingName'         => self::normalize_payload_string($customer->name),
                'BillingAddressLine1' => self::normalize_payload_string($customer->address1),
                'BillingAddressLine2' => self::normalize_payload_string($customer->address2),
                'BillingAddressCity'  => self::normalize_payload_string($customer->city),
                'BillingAddressState' => self::normalize_us_state_code_for_payload($customer->state),
                'BillingAddressZip'   => self::format_us_zip5_for_payload($customer->zip),

                'ShippingName'         => self::normalize_payload_string($customer->name),
                'ShippingAddressLine1' => self::normalize_payload_string($customer->address1),
                'ShippingAddressLine2' => self::normalize_payload_string($customer->address2),
                'ShippingAddressCity'  => self::normalize_payload_string($customer->city),
                'ShippingAddressState' => self::normalize_us_state_code_for_payload($customer->state),
                'ShippingAddressZip'   => self::format_us_zip5_for_payload($customer->zip),

                'DisableEmail' => true,
                'Overnight'    => false,
                'Items'        => $items,
            ];

            if ($this->is_test_order_debug_enabled()) {
                return $this->build_test_order_debug_block(
                    $lane,
                    'https://api.lipseys.com/api/Integration/Order/DropShip',
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

            try {
                $resp = $client->DropShipAccessories($payload);
            } catch (\Throwable $e) {
                $r = $this->classify_lipseys_exception_as_order_result($e, 'Lipseys NON DropShip', $po);
                $r->external_order_ids = $external_ids;
                return $r;
            }

            $norm = LipseysIntegrationAPI::normalize_order_response($resp, $po, 'DropShip');
            if (!($norm['ok'] ?? false)) {
                $r = $this->classify_lipseys_order_failure($norm, 'Lipseys direct-ship non-FFL');
                $r->external_order_ids = $external_ids;
                return $r;
            }

            $external_ids[] = (string) ($norm['external_id'] ?? '');
            return DistributorOrderResult::ok('Lipseys direct-ship non-FFL order submitted.', $external_ids);
        }

        if ($lane !== 'direct_ship_ffl') {
            return DistributorOrderResult::block_fatal(
                'Lipseys: unsupported lane "' . $lane . '".',
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
                'Lipseys FFL: missing receiving FFL number.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

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
            return DistributorOrderResult::block_fatal(
                'Lipseys FFL: missing customer phone (required by DropShipFirearm).',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $payload = [
            'Ffl'           => $ffl_num,
            'Po'            => $po,
            'Name'          => self::normalize_payload_string($cust_name),
            'Phone'         => self::normalize_payload_string($cust_phone),
            'DelayShipping' => false,
            'DisableEmail'  => true,
            'Items'         => $items,
        ];

        if ($this->is_test_order_debug_enabled()) {
            return $this->build_test_order_debug_block(
                $lane,
                'https://api.lipseys.com/api/Integration/Order/DropShipFirearm',
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

        try {
            $resp = $client->DropShipFirearms($payload);
        } catch (\Throwable $e) {
            $r = $this->classify_lipseys_exception_as_order_result($e, 'Lipseys FFL DropShipFirearm', $po);
            $r->external_order_ids = $external_ids;
            return $r;
        }

        $norm = LipseysIntegrationAPI::normalize_order_response($resp, $po, 'DropShipFirearm');
        if (!($norm['ok'] ?? false)) {
            $r = $this->classify_lipseys_order_failure($norm, 'Lipseys direct-ship FFL');
            $r->external_order_ids = $external_ids;
            return $r;
        }

        $external_ids[] = (string) ($norm['external_id'] ?? '');
        return DistributorOrderResult::ok('Lipseys direct-ship FFL order submitted.', $external_ids);
    }


    //END OF ORDERING SECTION

    /* -------------------------------------------------------------------------
     * Shipment lookup (PO -> tracking)
     * ---------------------------------------------------------------------- */

    /**
     * Look up shipment rows by PO number and return a normalized DistributorShipment.
     *
     * Behavior:
     * - If there are no rows or no tracking numbers, returns null (treat as "not shipped yet").
     * - If multiple cartons exist, aggregates tracking/invoice numbers.
     * - Preserves raw rows in metadata for auditing/debugging.
     */
    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        $po_number = trim((string) $po_number);
        if ($po_number === '') {
            return null;
        }

        $services = $this->get_services();

        // Only Lipsey's currently supports shipment tables.
        if (!$services instanceof \FFLHub\Distributor\Services\Lipseys\LipseysServices) {
            return null;
        }

        $table = $services->get_shipment_table();
        if (!$table) {
            return null;
        }

        $rows = $table->get_rows_by_po($po_number);
        if (empty($rows) || !is_array($rows)) {
            return null;
        }

        // Deterministic order helps stable outputs and diffing logs.
        usort(
            $rows,
            static function ($a, $b): int {
                $ta = trim((string) ($a['tracking_number'] ?? ''));
                $tb = trim((string) ($b['tracking_number'] ?? ''));
                return strcmp($ta, $tb);
            }
        );

        $tracking_numbers = [];
        $invoice_numbers  = [];

        $shipping_service = null;
        $shipping_weight  = null;

        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }

            $t = trim((string) ($r['tracking_number'] ?? ''));
            if ($t !== '') {
                $tracking_numbers[] = $t;
            }

            $inv = trim((string) ($r['invoice_number'] ?? ''));
            if ($inv !== '') {
                $invoice_numbers[] = $inv;
            }

            // First non-empty value wins (good enough for now).
            if ($shipping_service === null) {
                $svc = trim((string) ($r['shipping_service'] ?? ''));
                if ($svc !== '') {
                    $carrier = $this->normalize_carrier($svc); // USPS|UPS|FEDEX|null
                    $shipping_service = $carrier !== null ? $carrier : $svc;
                }
            }

            if ($shipping_weight === null) {
                $w = trim((string) ($r['weight'] ?? ''));
                if ($w !== '') {
                    $shipping_weight = $w;
                }
            }
        }

        // Dedupe while preserving order.
        $tracking_numbers = array_values(array_unique($tracking_numbers));
        $invoice_numbers  = array_values(array_unique($invoice_numbers));

        // If no tracking exists, treat as not shipped.
        if (empty($tracking_numbers)) {
            return null;
        }

        return new DistributorShipment(
            $tracking_numbers,
            $invoice_numbers,
            $shipping_service,
            $shipping_weight,
            [
                'po_number' => $po_number,
                // Preserve all cartons / raw rows for auditing & debugging.
                'rows' => $rows,
            ]
        );
    }

    /* -------------------------------------------------------------------------
     * Internal helpers: line mapping & item lookup
     * ---------------------------------------------------------------------- */

    /**
     * Build Lipsey's Items[] payload from normalized order lines.
     *
     * Lipsey's ordering APIs require item numbers (ItemNo), not UPCs.
     * We map UPC -> lipseys_item_number via the local fulfillment table.
     *
     * @param DistributorOrderLine[] $lines
     * @param bool $allow_empty If true, empty lines returns [] instead of fatal.
     * @return array<int,array{ItemNo:string,Quantity:int}>|DistributorOrderResult
     */
    private function build_lipseys_items(array $lines, bool $allow_empty = false)
    {
        return $this->map_order_lines_to_items(
            $lines,
            function (string $normalized_upc, string $raw_upc, DistributorOrderLine $line): ?string {
                // normalized_upc is digits-only; table expects normalized UPC.
                return $this->lookup_item_number_by_upc($normalized_upc);
            },
            function (string $item_no, int $qty, string $normalized_upc, string $raw_upc, DistributorOrderLine $line): array {
                return ['ItemNo' => $item_no, 'Quantity' => $qty];
            },
            'Cannot map UPC to Lipsey\'s item number: %s',
            !$allow_empty,
            'No valid Lipsey\'s line items after normalization.'
        );
    }

    /**
     * Map UPC -> Lipsey's item number using the local fulfillment table.
     *
     * This is the critical idempotent mapping layer:
     * - All ordering uses item numbers, so this must remain stable.
     */
    private function lookup_item_number_by_upc(string $upc): ?string
    {
        if (!$this->services) {
            return null;
        }

        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized);
        if (!$row) {
            return null;
        }

        $item_no = $this->get_string_field($row, ['lipseys_item_number']);
        $item_no = trim((string) $item_no);

        return $item_no !== '' ? $item_no : null;
    }

    /**
     * Collect APIOrder per-line issues from Lipsey's response payload.
     *
     * @param mixed $resp
     * @return string[]
     */
    private function collect_lipseys_api_order_item_issues($resp): array
    {
        if (!is_array($resp)) {
            return [];
        }

        $rows = $resp['data'] ?? null;
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = trim((string) ($row['itemNumber'] ?? $row['lipseysItemNumber'] ?? 'unknown'));
            $parts = [];

            if ($this->to_boolish($row['blocked'] ?? false, false)) {
                $parts[] = 'blocked';
            }
            if ($this->to_boolish($row['allocated'] ?? false, false)) {
                $parts[] = 'allocated';
            }
            if (array_key_exists('validForCart', $row) && !$this->to_boolish($row['validForCart'], true)) {
                $parts[] = 'invalid_for_cart';
            }
            if (array_key_exists('validForShipTo', $row) && !$this->to_boolish($row['validForShipTo'], true)) {
                $parts[] = 'invalid_for_shipto';
            }
            if ($this->to_boolish($row['orderError'] ?? false, false)) {
                $parts[] = 'order_error';
            }

            $requested = isset($row['requestedQuantity']) ? max(0, (int) $row['requestedQuantity']) : 0;
            $fulfilled = isset($row['fulfilledQuantity']) ? max(0, (int) $row['fulfilledQuantity']) : 0;
            if ($requested > 0 && $fulfilled < $requested) {
                $parts[] = 'fulfilled=' . $fulfilled . '/' . $requested;
            }

            if (isset($row['errors']) && is_array($row['errors']) && !empty($row['errors'])) {
                $errs = [];
                foreach ($row['errors'] as $e) {
                    $s = trim((string) $e);
                    if ($s !== '') {
                        $errs[] = $s;
                    }
                    if (count($errs) >= 2) {
                        break;
                    }
                }
                if (!empty($errs)) {
                    $parts[] = implode('; ', $errs);
                }
            }

            if (!empty($parts)) {
                $out[] = $id . ': ' . implode(', ', $parts);
            }

            if (count($out) >= 12) {
                $out[] = '...';
                break;
            }
        }

        return $out;
    }

    /* -------------------------------------------------------------------------
     * Internal helpers: error classification
     * ---------------------------------------------------------------------- */

    /**
     * Classify a normalized Lipsey's order failure into retryable vs fatal.
     *
     * This is used after LipseysIntegrationAPI::normalize_order_response() to
     * translate provider failures into your pipeline semantics.
     *
     * Retryable: rate limits, upstream issues, transient timeouts.
     * Fatal: credentials, restricted/prohibited, out of stock, bad request-ish.
     *
     * @param array<string,mixed> $norm
     */
    private function classify_lipseys_order_failure(array $norm, string $prefix): DistributorOrderResult
    {
        $msg      = (string) ($norm['message'] ?? 'Unknown error');
        $http     = isset($norm['http_status']) ? (int) $norm['http_status'] : 0;
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

        // Auth failures -> fatal creds.
        if (strpos($lc, 'not authorized') !== false || strpos($lc, 'unauthorized') !== false) {
            return DistributorOrderResult::block_fatal(
                $prefix . ': not authorized: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS],
                $details,
                $http,
                $provider
            );
        }

        // Quota / rate limit heuristics.
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

        // Network/timeout heuristics.
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

        // Upstream heuristics.
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

        // Restricted/prohibited.
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

        // Stock.
        if (strpos($lc, 'out of stock') !== false || strpos($lc, 'insufficient') !== false) {
            return DistributorOrderResult::block_fatal(
                $prefix . ': out of stock: ' . $msg,
                [DistributorOrderResult::REASON_FATAL_OUT_OF_STOCK],
                $details,
                $http,
                $provider
            );
        }

        // Default: fatal unknown.
        return DistributorOrderResult::block_fatal(
            $prefix . ': order failed: ' . $msg,
            [DistributorOrderResult::REASON_FATAL_UNKNOWN],
            $details,
            $http,
            $provider
        );
    }

    /**
     * Classify thrown exceptions (transport/client issues) into retryable vs fatal.
     *
     * This is used when the API call throws before we get a structured response.
     */
    private function classify_lipseys_exception_as_order_result(\Throwable $e, string $prefix, string $po): DistributorOrderResult
    {
        $msg = (string) $e->getMessage();
        $lc  = strtolower($msg);

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

    /* -------------------------------------------------------------------------
     * Internal helpers: credentials & PO shaping
     * ---------------------------------------------------------------------- */

    /**
     * Dealer credentials are stored in WordPress options under this distributor's option namespace.
     */
    private function get_dealer_email(): string
    {
        return trim((string) get_option($this->get_option_name('dealer_email'), ''));
    }

    private function get_dealer_password(): string
    {
        return trim((string) get_option($this->get_option_name('dealer_password'), ''));
    }

    private function get_main_account_email(): string
    {
        return trim((string) get_option($this->get_option_name('main_account_email'), ''));
    }

    private function get_main_account_password(): string
    {
        return trim((string) get_option($this->get_option_name('main_account_password'), ''));
    }

    

    

    /* -------------------------------------------------------------------------
     * Internal helpers: ValidateItem caching (transients)
     * ---------------------------------------------------------------------- */

    /**
     * Transient cache key for ValidateItem results.
     *
     * md5() keeps keys short and safe for WP transient storage.
     */
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

}



