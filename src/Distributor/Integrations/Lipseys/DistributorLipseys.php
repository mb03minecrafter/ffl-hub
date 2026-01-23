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
        $image_name = $this->get_string_field($row, $field);
        if ($image_name === '') {
            return '';
        }

        return 'https://www.lipseyscloud.com/images/' . $image_name;
    }

    /**
     * Look up a single product by UPC using the Lipsey's LIVE fulfillment table.
     */
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

    /**
     * Flat-rate Lipsey's shipping.
     */
    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        return 10.0;
    }

    /**
     * Validate order request using Lipsey's ValidateItem.
     *
     * Goal: prevent partial orders by verifying near-real-time stock quantity.
     *
     * Behavior:
     * - Aggregates required qty by UPC
     * - Calls ValidateItem(UPC) for each unique UPC (cached for 60s)
     * - Blocks if:
     *    - API says blocked=true
     *    - canDropship=false (recommended)
     *    - allocated=true (recommended)
     *    - qty < required
     *
     * Fallback (NEW):
     * - If ANY ValidateItem call returns a quota / rate-limit style failure,
     *   we defer to our local fulfillment table for stock verification.
     * - Local fallback checks only:
     *    - row exists
     *    - inventory_quantity >= required
     * - If local cannot prove availability -> BLOCK (fail safe).
     */
    public function validate_order_request(DistributorOrderRequest $request): DistributorOrderValidationResult
    {
        if (! class_exists('\\lipseys\\ApiIntegration\\LipseysClient')) {
            return DistributorOrderValidationResult::block(
                'Lipseys API client not available (lipseys/apiintegration).',
                ['LIPSEYS_CLIENT_MISSING']
            );
        }

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
            'keys_tail4' => array_map(
                fn($k) => (strlen((string)$k) >= 4 ? substr((string)$k, -4) : (string)$k),
                array_keys($required_by_upc)
            ),
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
            return DistributorOrderValidationResult::block(
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
                    $norm = ['ok' => false, 'message' => 'ValidateItem returned invalid result'];
                }
            }

            $details['items'][$upc] = array_merge($norm, [
                'requiredQty' => $requiredQty,
            ]);

            // Detect quota / rate-limit failures and trigger local fallback
            $msg_lc = strtolower((string) ($norm['message'] ?? ''));
            if (
                ($norm['ok'] ?? false) === false &&
                (
                    str_contains($msg_lc, 'quota') ||
                    str_contains($msg_lc, 'rate') ||
                    str_contains($msg_lc, 'exceeded') ||
                    str_contains($msg_lc, 'maximum admitted') ||
                    str_contains($msg_lc, 'api calls')
                )
            ) {
                $quota_triggered = true;
                $quota_msgs[] = "UPC={$upc}: " . (string) ($norm['message'] ?? 'quota exceeded');
                continue; // defer this UPC to local fallback pass
            }

            // Normal API enforcement
            if (! ($norm['ok'] ?? false)) {
                $blocked_msgs[] = "UPC={$upc}: " . (string) ($norm['message'] ?? 'ValidateItem failed');
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

        // If quota triggered anywhere, we switch to local table verification for ALL items (simple + deterministic)
        if ($quota_triggered) {
            $details['local_fallback']['used'] = true;
            $details['local_fallback']['reason'] = 'ValidateItem quota/rate-limit exceeded';
            $details['local_fallback']['quota_messages'] = $quota_msgs;

            $this->dbg('validate_order_request: quota exceeded — local fulfillment fallback', [
                'quota_msgs_count' => count($quota_msgs),
                'unique_items' => count($required_by_upc),
            ]);

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

                // Your table field is inventory_quantity (as used elsewhere)
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

        // Normal path decision (no quota fallback)
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
     * Your procurement layer can pass ONE request containing mixed lines (FFL + non-FFL).
     * Lipsey's requires two separate endpoints, so we split internally:
     *  - non-FFL lines => DropShipAccessories
     *  - FFL lines     => DropShipFirearms
     *
     * IMPORTANT:
     * Lipsey's ordering endpoints require ItemNo (Lipsey's item number), so we map:
     *   UPC -> lipseys_item_number via our Lipsey's fulfillment table.
     */
    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        if (! class_exists('\\lipseys\\ApiIntegration\\LipseysClient')) {
            return new DistributorOrderResult(false, 'Lipseys API client not available (lipseys/apiintegration).', []);
        }

        if (! $this->services) {
            return new DistributorOrderResult(false, 'Lipseys services not available; cannot access fulfillment table.', []);
        }

        if (empty($request->lines)) {
            return new DistributorOrderResult(false, 'No order lines provided.', []);
        }

        $email = $this->get_dealer_email();
        $password = $this->get_dealer_password();

        if ($email === '' || $password === '') {
            return new DistributorOrderResult(false, 'Missing Lipsey’s credentials (dealer_email / dealer_password).', []);
        }

        // Split into two Lipsey's orders based on ffl_required (via request accessors).
        $items_non = $this->build_lipseys_items($request->non_ffl_lines(), true);
        if ($items_non instanceof DistributorOrderResult) {
            return $items_non;
        }

        $items_ffl = $this->build_lipseys_items($request->ffl_lines(), true);
        if ($items_ffl instanceof DistributorOrderResult) {
            return $items_ffl;
        }

        if (empty($items_non) && empty($items_ffl)) {
            return new DistributorOrderResult(false, 'No valid line items after normalization.', []);
        }

        $client_res = LipseysIntegrationAPI::create_client($email, $password);
        if (! $client_res['ok']) {
            return new DistributorOrderResult(false, $client_res['message'], []);
        }

        /** @var \lipseys\ApiIntegration\LipseysClient $client */
        $client = $client_res['client'];

        $external_ids = [];
        $errors = [];

        $base_po = self::sanitize_po((string) $request->merchant_order_id);
        if ($base_po === '') {
            $base_po = 'FFLHUB';
        }

        // 1) Non-FFL -> DropShipAccessories
        if (! empty($items_non)) {
            $po = $base_po . '-NON';

            $ship = $request->ship_to_customer;

            $payload = [
                // Billing (Lipsey's requires these; we use customer info)
                'BillingName'          => self::normalize_payload_string($ship->name),
                'BillingAddressLine1'  => self::normalize_payload_string($ship->address1),
                'BillingAddressCity'   => self::normalize_payload_string($ship->city),
                'BillingAddressState'  => self::normalize_us_state_code_for_payload($ship->state),
                'BillingAddressZip'    => self::format_us_zip5_for_payload($ship->zip),

                // Shipping
                'ShippingName'         => self::normalize_payload_string($ship->name),
                'ShippingAddressLine1' => self::normalize_payload_string($ship->address1),
                'ShippingAddressCity'  => self::normalize_payload_string($ship->city),
                'ShippingAddressState' => self::normalize_us_state_code_for_payload($ship->state),
                'ShippingAddressZip'   => self::format_us_zip5_for_payload($ship->zip),

                'PoNumber'             => $po,
                'Items'                => $items_non,
            ];

            try {
                $resp = $client->DropShipAccessories($payload);
            } catch (\Throwable $e) {
                $errors[] = 'DropShipAccessories exception: ' . $e->getMessage();
                $resp = null;
            }

            $norm = LipseysIntegrationAPI::normalize_order_response($resp, $po, 'DropShipAccessories');
            if ($norm['ok']) {
                $external_ids[] = $norm['external_id'];
            } else {
                $errors[] = $norm['message'];
            }
        }

        // 2) FFL -> DropShipFirearms
        if (! empty($items_ffl)) {
            $ffl_num = strtoupper(trim((string) $request->receiving_ffl_number));
            if ($ffl_num === '') {
                $errors[] = 'Missing receiving FFL number for Lipsey’s firearm order.';
            } elseif (! ($request->ship_to_ffl instanceof DistributorShipTo)) {
                $errors[] = 'Missing receiving FFL ship-to address for Lipsey’s firearm order.';
            } else {
                $po = $base_po . '-FFL';

                $ffl_ship = $request->ship_to_ffl;
                $phone = trim((string) $ffl_ship->phone);
                if ($phone === '') {
                    $phone = trim((string) $request->ship_to_customer->phone);
                }

                $payload = [
                    'Ffl'   => $ffl_num,
                    'Name'  => self::normalize_payload_string($ffl_ship->name),
                    'Phone' => self::normalize_payload_string($phone),
                    'Items' => $items_ffl,
                ];

                try {
                    $resp = $client->DropShipFirearms($payload);
                } catch (\Throwable $e) {
                    $errors[] = 'DropShipFirearms exception: ' . $e->getMessage();
                    $resp = null;
                }

                $norm = LipseysIntegrationAPI::normalize_order_response($resp, $po, 'DropShipFirearms');
                if ($norm['ok']) {
                    $external_ids[] = $norm['external_id'];
                } else {
                    $errors[] = $norm['message'];
                }
            }
        }

        if (! empty($errors)) {
            return new DistributorOrderResult(false, 'Lipseys order failed: ' . implode(' | ', $errors), $external_ids);
        }

        return new DistributorOrderResult(true, 'Lipseys order submitted.', $external_ids);
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
            fn(string $normalized_upc, string $raw_upc, DistributorOrderLine $line): ?string
            => $this->lookup_item_number_by_upc($raw_upc),
            fn(string $item_no, int $qty): array
            => ['ItemNo' => $item_no, 'Quantity' => $qty],
            'Cannot map UPC to Lipsey’s item number: %s',
            ! $allow_empty,
            'No valid Lipsey’s line items after normalization.'
        );
    }

    /**
     * Map UPC -> lipseys_item_number using the LIVE fulfillment table.
     */
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
     * IMPORTANT:
     * DistributorOrderLine may NOT expose public props. We therefore support:
     *  - public properties: $line->upc, $line->qty
     *  - accessors: upc(), qty(), get_upc(), get_qty(), getUpc(), getQty()
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

            $this->dbg('build_required_qty_by_upc: line read', [
                'idx' => (int) $idx,
                'line_class' => get_class($l),
                'raw_upc_len' => strlen($raw_upc),
                'raw_upc_tail4' => (strlen($raw_upc) >= 4 ? substr($raw_upc, -4) : $raw_upc),
                'qty' => $qty,
            ]);

            $upc = $this->normalize_upc($raw_upc);
            if ($upc === null) {
                $this->dbg('build_required_qty_by_upc: line rejected (normalize_upc null)', [
                    'idx' => (int) $idx,
                    'raw_upc_tail4' => (strlen($raw_upc) >= 4 ? substr($raw_upc, -4) : $raw_upc),
                ]);
                continue;
            }

            if ($qty < 1) {
                $this->dbg('build_required_qty_by_upc: line rejected (qty < 1)', [
                    'idx' => (int) $idx,
                    'upc_tail4' => (strlen($upc) >= 4 ? substr($upc, -4) : $upc),
                    'qty' => $qty,
                ]);
                continue;
            }

            if (! isset($required[$upc])) {
                $required[$upc] = 0;
            }

            $required[$upc] += $qty;
        }

        return $required;
    }

    /**
     * Try all common ways to read UPC from DistributorOrderLine.
     */
    private function read_line_upc(DistributorOrderLine $l): string
    {
        // 1) Accessors (preferred)
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
                    // ignore and fall through
                }
            }
        }

        // 2) Public properties (fallback)
        $raw = '';
        if (isset($l->upc)) {
            $raw = (string) $l->upc;
        }
        return trim($raw);
    }

    /**
     * Try all common ways to read quantity from DistributorOrderLine.
     */
    private function read_line_qty(DistributorOrderLine $l): int
    {
        // 1) Accessors (preferred)
        foreach (['qty', 'get_qty', 'getQty'] as $m) {
            if (method_exists($l, $m)) {
                try {
                    $v = $l->{$m}();
                    return max(0, (int) $v);
                } catch (\Throwable $e) {
                    // ignore and fall through
                }
            }
        }

        // 2) Public properties (fallback)

        if (isset($l->quantity)) {
            return max(0, (int) $l->quantity);
        }

        return 0;
    }

    private function cache_key_validateitem(string $upc): string
    {
        return 'fflhub_lipseys_validateitem_' . md5($upc);
    }

    /**
     * @return array|null
     */
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
