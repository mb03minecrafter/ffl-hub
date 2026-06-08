<?php

namespace FFLHub\Distributor\Integrations\Orion;

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
use FFLHub\Distributor\Services\Orion\API\OrionApiClient;
use FFLHub\Distributor\Services\Orion\OrionServices;
use FFLHub\Settings\Options;

/**
 * Orion runtime distributor backed by the local Orion catalog tables.
 */
final class DistributorOrion extends DistributorBase
{
    private const DEFAULT_FLAT_SHIPPING_COST = 13.0;
    private const DEFAULT_SHIPPING_METHOD_CODE = 'STANDARD';
    private const DEFAULT_SHIPMENT_LOOKBACK_DAYS = 14;

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
                'sku' => ['orion_product_code', 'orion_product_id'],
                'upc' => ['upc'],
                'name' => ['product_name', 'model'],
                'description' => ['product_description', 'product_name'],
                'brand' => ['manufacturer'],
                'price' => ['distributor_price', 'sale_price', 'base_cost'],
                'map' => ['retail_map'],
                'msrp' => ['retail_msrp'],
                'quantity' => ['inventory_quantity'],
                'category' => ['product_categories', 'item_type'],
                'shipping_weight' => ['shipping_weight'],
                'shipping_length_in' => ['shipping_length_in'],
                'shipping_width_in' => ['shipping_width_in'],
                'shipping_height_in' => ['shipping_height_in'],
                'image' => ['image_url'],
                'ffl_required' => ['ffl_required'],
                'sot_required' => ['sot_required'],
                'dropship_enabled' => ['dropship_enabled'],
            ],
            static fn($raw_category): ?array => DistributorProductCategoryMapper::map_orion((string) $raw_category),
            false,
            static function (DistributorProductPayload $payload, array $row, string $normalized_upc): DistributorProductPayload {
                $short_name = trim((string) ($row['product_name'] ?? ''));
                if ($short_name !== '') {
                    $payload->name = $short_name;
                }

                return $payload;
            }
        );
    }

    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        return parent::place_order($request);
    }

    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        $po_number = trim((string) $po_number);
        if ($po_number === '') {
            return null;
        }

        $contexts = $this->lookup_order_contexts_by_po($po_number);
        if (!empty($contexts) && !$this->contexts_include_orion_dropship($contexts)) {
            return null;
        }

        $external_ids = $this->collect_external_ids_from_contexts($contexts);
        $client = $this->make_api_client(60);

        $tracking_numbers = [];
        $invoice_numbers = [];
        $shipping_service = null;
        $shipping_weight = null;
        $raw = [];

        if (!empty($external_ids)) {
            foreach ($external_ids as $external_id) {
                $resp = $client->get_shipment_data($external_id);
                $raw[] = [
                    'order_id' => $external_id,
                    'ok' => !empty($resp['ok']) ? 1 : 0,
                    'status' => (int) ($resp['status'] ?? 0),
                    'error' => (string) ($resp['error'] ?? ''),
                ];

                if (empty($resp['ok'])) {
                    continue;
                }

                $this->collect_shipment_values_from_response(
                    (array) ($resp['data'] ?? []),
                    false,
                    $po_number,
                    $tracking_numbers,
                    $invoice_numbers,
                    $shipping_service,
                    $shipping_weight
                );
            }
        } else {
            $resp = $client->get_shipment_data('', self::DEFAULT_SHIPMENT_LOOKBACK_DAYS);
            $raw[] = [
                'days' => self::DEFAULT_SHIPMENT_LOOKBACK_DAYS,
                'ok' => !empty($resp['ok']) ? 1 : 0,
                'status' => (int) ($resp['status'] ?? 0),
                'error' => (string) ($resp['error'] ?? ''),
            ];

            if (!empty($resp['ok'])) {
                $this->collect_shipment_values_from_response(
                    (array) ($resp['data'] ?? []),
                    true,
                    $po_number,
                    $tracking_numbers,
                    $invoice_numbers,
                    $shipping_service,
                    $shipping_weight
                );
            }
        }

        $tracking_numbers = array_values(array_unique(array_filter(array_map('strval', $tracking_numbers))));
        $invoice_numbers = array_values(array_unique(array_filter(array_map('strval', $invoice_numbers))));

        if (empty($tracking_numbers)) {
            return null;
        }

        sort($tracking_numbers, SORT_STRING);
        sort($invoice_numbers, SORT_STRING);

        return new DistributorShipment(
            $tracking_numbers,
            $invoice_numbers,
            $shipping_service,
            $shipping_weight,
            [
                'po_number' => $po_number,
                'external_ids' => $external_ids,
                'responses' => $raw,
                'contexts' => $contexts,
            ]
        );
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $cost = apply_filters(
            'fflhub_orion_flat_shipping_cost',
            self::DEFAULT_FLAT_SHIPPING_COST,
            $normalized,
            $this
        );

        return is_numeric($cost) ? max(0.0, (float) $cost) : self::DEFAULT_FLAT_SHIPPING_COST;
    }

    protected function get_shipping_cost_from_row(array $row, string $normalized_upc): ?float
    {
        if (array_key_exists('shipping_cost', $row)) {
            $raw = trim((string) ($row['shipping_cost'] ?? ''));
            if ($raw !== '' && is_numeric($raw)) {
                return max(0.0, (float) $raw);
            }
        }

        return $this->get_shipping_cost_by_upc($normalized_upc);
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
            'label' => 'Orion validation (local)',
            'max_unique' => 100,
            'inventory_keys' => ['inventory_quantity'],
            'unknown_qty_blocks' => true,
            'code_prefix' => 'ORION',
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

        return $this->require_ffl_shipto_if_ffl_lines($request, 'ORION');
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

        if ($this->get_connection_key() === '') {
            return DistributorOrderResult::block_fatal(
                'Orion: missing connection key.',
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS]
            );
        }

        $po = $this->sanitize_and_truncate_po((string) $request->merchant_order_id, 32);
        if ($po === '') {
            return DistributorOrderResult::block_fatal(
                'Orion: missing merchant PO (purchase_order_number).',
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
        $lane = strtolower(trim((string) $lane));

        $items = $this->build_orion_items($lines);
        if ($items instanceof DistributorOrderResult) {
            $items->external_order_ids = $external_ids;
            return $items;
        }

        $order_type = $this->order_type_for_lane($lane);
        if ($order_type === '') {
            return DistributorOrderResult::block_fatal(
                'Orion: unsupported lane "' . $lane . '".',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $po = $this->sanitize_and_truncate_po((string) $request->merchant_order_id, 32);
        if ($po === '') {
            $po = 'ORION-' . gmdate('YmdHis');
        }

        $order_items_json = wp_json_encode($items, JSON_UNESCAPED_SLASHES);
        if (!is_string($order_items_json) || $order_items_json === '') {
            return DistributorOrderResult::block_fatal(
                'Orion: failed to encode order_items payload.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $params = [
            'order_type' => $order_type,
            'order_items' => $order_items_json,
            'shipping_method_code' => (string) apply_filters(
                'fflhub_orion_shipping_method_code',
                self::DEFAULT_SHIPPING_METHOD_CODE,
                $request,
                $lane
            ),
            'purchase_order_number' => $po,
        ];

        if ($lane === 'direct_ship_non_ffl') {
            if (!($request->ship_to_customer instanceof DistributorShipTo)) {
                return DistributorOrderResult::block_fatal(
                    'Orion customer drop ship: missing ship_to_customer.',
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    [],
                    0,
                    '',
                    $external_ids
                );
            }

            $ship_check = self::validate_shipto_minimum($request->ship_to_customer);
            if (empty($ship_check['ok'])) {
                return DistributorOrderResult::block_fatal(
                    'Orion customer drop ship: ' . (string) ($ship_check['message'] ?? 'Invalid ship-to.'),
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    ['ship_check' => $ship_check],
                    0,
                    '',
                    $external_ids
                );
            }

            $params = array_merge($params, $this->build_ship_to_params($request->ship_to_customer, false));
        } elseif ($lane === 'direct_ship_ffl') {
            if (!($request->ship_to_ffl instanceof DistributorShipTo)) {
                return DistributorOrderResult::block_fatal(
                    'Orion FFL drop ship: missing ship_to_ffl.',
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    [],
                    0,
                    '',
                    $external_ids
                );
            }

            $ffl_number = strtoupper(trim((string) $request->receiving_ffl_number));
            if ($ffl_number === '') {
                return DistributorOrderResult::block_fatal(
                    'Orion FFL drop ship: missing receiving FFL number.',
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
                    'Orion FFL drop ship: ' . (string) ($ship_check['message'] ?? 'Invalid FFL ship-to.'),
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    ['ship_check' => $ship_check],
                    0,
                    '',
                    $external_ids
                );
            }

            $params = array_merge($params, $this->build_ship_to_params($request->ship_to_ffl, true));
            $params['license_number'] = $ffl_number;
        }

        $notes = $this->build_order_notes($request, $lane);
        if ($notes !== '') {
            $params['order_notes_content'] = $notes;
        }

        $promotion = trim((string) apply_filters('fflhub_orion_promotion_code', '', $request, $lane));
        if ($promotion !== '') {
            $params['promotion_code'] = $promotion;
        }

        $source = trim((string) apply_filters('fflhub_orion_source_code', '', $request, $lane));
        if ($source !== '') {
            $params['source_code'] = $source;
        }

        $params = (array) apply_filters('fflhub_orion_place_order_params', $params, $request, $lane, $items, $this);

        if ($this->is_test_order_debug_enabled()) {
            return $this->build_test_order_debug_block(
                $lane,
                add_query_arg(['method' => 'place_order'], $this->get_api_base_url()),
                'POST',
                'query',
                $this->encode_debug_json_payload($params),
                [
                    'po' => $po,
                    'order_type' => $order_type,
                    'item_count' => count($items),
                ],
                $external_ids
            );
        }

        $resp = $this->make_api_client(60)->place_order($params);
        if (empty($resp['ok'])) {
            $failure = $this->classify_orion_api_failure($resp, 'Orion ' . $this->lane_label($lane), $params);
            $failure->external_order_ids = $external_ids;
            return $failure;
        }

        $external_id = trim((string) ($resp['data']['order_id'] ?? ''));
        if ($external_id !== '') {
            $external_ids[] = $external_id;
        }

        return DistributorOrderResult::ok(
            'Orion ' . $this->lane_label($lane) . ' order submitted.',
            $external_ids,
            [
                'po' => $po,
                'order_type' => $order_type,
                'http_status' => (int) ($resp['status'] ?? 0),
            ]
        );
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

        $payload = $this->build_payload_from_row(
            $row,
            [
                'sku' => ['orion_product_code', 'orion_product_id'],
                'upc' => ['upc'],
                'name' => ['product_name', 'model'],
                'description' => ['product_description', 'product_name'],
                'brand' => ['manufacturer'],
                'price' => ['distributor_price', 'sale_price', 'base_cost'],
                'map' => ['retail_map'],
                'msrp' => ['retail_msrp'],
                'quantity' => ['inventory_quantity'],
                'category' => ['product_categories', 'item_type'],
                'shipping_weight' => ['shipping_weight'],
                'shipping_length_in' => ['shipping_length_in'],
                'shipping_width_in' => ['shipping_width_in'],
                'shipping_height_in' => ['shipping_height_in'],
                'image' => ['image_url'],
                'ffl_required' => ['ffl_required'],
                'sot_required' => ['sot_required'],
                'dropship_enabled' => ['dropship_enabled'],
            ],
            static fn($raw_category): ?array => DistributorProductCategoryMapper::map_orion((string) $raw_category),
            $normalized_upc,
            $include_images
        );

        $short_name = trim((string) ($row['product_name'] ?? ''));
        if ($short_name !== '') {
            $payload->name = $short_name;
        }

        if ($include_images) {
            foreach ($this->image_urls_from_row($row) as $url) {
                $payload->add_image_url($url);
            }
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $row
     * @param mixed $field
     */
    protected function get_image_url_from_row(array $row, $field): string
    {
        foreach ($this->image_urls_from_row($row) as $url) {
            return $url;
        }

        return '';
    }

    /**
     * @param array<string,mixed> $row
     * @return string[]
     */
    private function image_urls_from_row(array $row): array
    {
        $urls = [];

        $primary = trim((string) ($row['image_url'] ?? ''));
        if ($primary !== '') {
            $urls[$primary] = $primary;
        }

        $json = trim((string) ($row['image_urls_json'] ?? ''));
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    $url = trim((string) $entry);
                    if ($url !== '') {
                        $urls[$url] = $url;
                    }
                }
            }
        }

        return array_values($urls);
    }

    /**
     * @param array<int,mixed> $lines
     * @return array<int,array<string,mixed>>|DistributorOrderResult
     */
    private function build_orion_items(array $lines)
    {
        return $this->map_order_lines_to_items(
            $lines,
            function (string $normalized_upc, string $raw_upc, DistributorOrderLine $line): ?string {
                $identifier = $this->lookup_orion_order_identifier_by_upc($normalized_upc);
                return $identifier !== '' ? $identifier : null;
            },
            function (string $identifier, int $qty, string $normalized_upc, string $raw_upc, DistributorOrderLine $line): array {
                if (strpos($identifier, 'id:') === 0) {
                    return [
                        'product_id' => (int) substr($identifier, 3),
                        'quantity' => $qty,
                    ];
                }

                if (strpos($identifier, 'code:') === 0) {
                    return [
                        'product_code' => substr($identifier, 5),
                        'quantity' => $qty,
                    ];
                }

                return [
                    'product_code' => $identifier,
                    'quantity' => $qty,
                ];
            },
            'Orion: cannot map UPC to product_code/product_id: %s',
            true,
            'Orion: no valid items after normalization.'
        );
    }

    private function lookup_orion_order_identifier_by_upc(string $upc): string
    {
        $row = $this->get_fulfillment_row_for_upc($upc);
        if ($row === null) {
            return '';
        }

        $code = trim((string) $this->get_string_field($row, ['orion_product_code']));
        if ($code !== '') {
            return 'code:' . $code;
        }

        $id = trim((string) $this->get_string_field($row, ['orion_product_id']));
        if ($id !== '' && is_numeric($id)) {
            return 'id:' . $id;
        }

        return '';
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

    private function order_type_for_lane(string $lane): string
    {
        $lane = strtolower(trim((string) $lane));
        if ($lane === 'dealer_fulfilled') {
            return 'dealer';
        }
        if ($lane === 'direct_ship_non_ffl') {
            return 'customer';
        }
        if ($lane === 'direct_ship_ffl') {
            return 'ffl';
        }

        return '';
    }

    private function lane_label(string $lane): string
    {
        $lane = strtolower(trim((string) $lane));
        if ($lane === 'dealer_fulfilled') {
            return 'dealer-fulfilled';
        }
        if ($lane === 'direct_ship_non_ffl') {
            return 'customer drop-ship';
        }
        if ($lane === 'direct_ship_ffl') {
            return 'FFL drop-ship';
        }

        return 'order';
    }

    /**
     * @return array<string,string>
     */
    private function build_ship_to_params(DistributorShipTo $ship, bool $preferCompany): array
    {
        $name = $preferCompany
            ? trim((string) ($ship->company !== '' ? $ship->company : $ship->name))
            : trim((string) ($ship->name !== '' ? $ship->name : $ship->company));

        return [
            'full_name' => self::truncate_string(self::normalize_payload_string($name), 80),
            'address_1' => self::truncate_string(self::normalize_payload_string($ship->address1), 80),
            'address_2' => self::truncate_string(self::normalize_payload_string($ship->address2), 80),
            'city' => self::truncate_string(self::normalize_payload_string($ship->city), 60),
            'state' => self::format_us_state2_best_effort($ship->state),
            'postal_code' => self::format_us_zip5_best_effort($ship->zip),
            'country_code' => 'US',
            'phone_number' => self::truncate_string(self::normalize_payload_string($ship->phone), 30),
            'email_address' => self::truncate_string(self::normalize_payload_string($ship->email), 120),
        ];
    }

    private function build_order_notes(DistributorOrderRequest $request, string $lane): string
    {
        $chunks = [];
        $notes = trim((string) $request->notes);
        if ($notes !== '') {
            $chunks[] = $notes;
        }

        if ($lane === 'direct_ship_ffl' && $request->ship_to_customer instanceof DistributorShipTo) {
            $customer_bits = array_filter([
                trim((string) $request->ship_to_customer->name),
                trim((string) $request->ship_to_customer->phone),
                trim((string) $request->ship_to_customer->email),
            ], static function ($value): bool {
                return trim((string) $value) !== '';
            });

            if (!empty($customer_bits)) {
                $chunks[] = 'Customer: ' . implode(' / ', array_map('strval', $customer_bits));
            }
        }

        $notes = trim(implode(' | ', $chunks));
        $notes = (string) apply_filters('fflhub_orion_order_notes_content', $notes, $request, $lane);

        return self::truncate_string(self::normalize_payload_string($notes), 500);
    }

    private function get_connection_key(): string
    {
        return trim((string) Options::get_distributor_option('orion', 'connection_key', ''));
    }

    private function get_api_base_url(): string
    {
        $url = trim((string) apply_filters('fflhub_orion_api_base_url', OrionApiClient::DEFAULT_BASE_URL, $this));
        return $url !== '' ? $url : OrionApiClient::DEFAULT_BASE_URL;
    }

    private function make_api_client(int $timeoutSeconds = 60): OrionApiClient
    {
        return new OrionApiClient(
            $this->get_connection_key(),
            $this->get_api_base_url(),
            $timeoutSeconds
        );
    }

    /**
     * @param array{ok:bool,status:int,data:array<string,mixed>,error:string} $resp
     * @param array<string,mixed> $params
     */
    private function classify_orion_api_failure(array $resp, string $ctx, array $params): DistributorOrderResult
    {
        $http = (int) ($resp['status'] ?? 0);
        $msg = trim((string) ($resp['error'] ?? ''));
        if ($msg === '') {
            $msg = 'Orion request failed.';
        }

        $msg_lc = strtolower($msg);
        $retryable = (
            $http === 0 ||
            $http === 408 ||
            $http === 429 ||
            $http >= 500 ||
            strpos($msg_lc, 'timeout') !== false ||
            strpos($msg_lc, 'timed out') !== false ||
            strpos($msg_lc, 'could not resolve') !== false ||
            strpos($msg_lc, 'connection') !== false
        );

        $details = [
            'http_status' => $http,
            'error' => $msg,
            'api_result' => (string) (($resp['data']['result'] ?? '') ?: ''),
            'request' => $this->summarize_order_params($params),
        ];

        if ($retryable) {
            return DistributorOrderResult::block_retryable(
                $ctx . ': ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UNKNOWN],
                $details,
                $http
            );
        }

        return DistributorOrderResult::block_fatal(
            $ctx . ': ' . $msg,
            [DistributorOrderResult::REASON_FATAL_UNKNOWN],
            $details,
            $http
        );
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function summarize_order_params(array $params): array
    {
        $items = [];
        $decoded = json_decode((string) ($params['order_items'] ?? ''), true);
        if (is_array($decoded)) {
            $items = $decoded;
        }

        return [
            'order_type' => (string) ($params['order_type'] ?? ''),
            'purchase_order_number' => (string) ($params['purchase_order_number'] ?? ''),
            'shipping_method_code' => (string) ($params['shipping_method_code'] ?? ''),
            'item_count' => count($items),
        ];
    }

    /**
     * @return array<int,array{lane:string,external_ids:array<int,string>}>
     */
    private function lookup_order_contexts_by_po(string $po_number): array
    {
        global $wpdb;

        $po_number = trim((string) $po_number);
        if ($po_number === '' || !($this->services instanceof OrionServices)) {
            return [];
        }

        $order_table = $this->services->get_order_table();
        if ($order_table === null) {
            return [];
        }

        $table = $order_table->get_table_name();
        $sql = $wpdb->prepare(
            "SELECT lane, external_order_ids_json, external_order_id, place_result_json
             FROM {$table}
             WHERE merchant_po = %s AND dist_id = %s
             ORDER BY id DESC
             LIMIT 20",
            $po_number,
            'orion'
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $contexts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $contexts[] = [
                'lane' => strtolower(trim((string) ($row['lane'] ?? ''))),
                'external_ids' => $this->extract_external_ids_from_job_row($row),
            ];
        }

        return $contexts;
    }

    /**
     * @param array<int,array{lane:string,external_ids:array<int,string>}> $contexts
     */
    private function contexts_include_orion_dropship(array $contexts): bool
    {
        $saw_known_lane = false;

        foreach ($contexts as $context) {
            $lane = strtolower(trim((string) ($context['lane'] ?? '')));
            if ($lane === 'direct_ship_non_ffl' || $lane === 'direct_ship_ffl') {
                return true;
            }
            if ($lane !== '') {
                $saw_known_lane = true;
            }
        }

        return !$saw_known_lane;
    }

    /**
     * @param array<int,array{lane:string,external_ids:array<int,string>}> $contexts
     * @return string[]
     */
    private function collect_external_ids_from_contexts(array $contexts): array
    {
        $ids = [];

        foreach ($contexts as $context) {
            foreach ((array) ($context['external_ids'] ?? []) as $id) {
                $id = trim((string) $id);
                if ($id !== '') {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<string,mixed> $row
     * @return string[]
     */
    private function extract_external_ids_from_job_row(array $row): array
    {
        $ids = [];

        $this->append_string_values($row['external_order_id'] ?? '', $ids);

        $ids_json = trim((string) ($row['external_order_ids_json'] ?? ''));
        if ($ids_json !== '') {
            $decoded = json_decode($ids_json, true);
            $this->append_string_values($decoded, $ids);
        }

        $place_json = trim((string) ($row['place_result_json'] ?? ''));
        if ($place_json !== '') {
            $decoded = json_decode($place_json, true);
            if (is_array($decoded)) {
                $this->append_string_values($decoded['ext_ids'] ?? [], $ids);
                $this->append_string_values($decoded['external_order_ids'] ?? [], $ids);
            }
        }

        return array_values(array_unique(array_filter(array_map('strval', $ids))));
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $tracking_numbers
     * @param string[] $invoice_numbers
     */
    private function collect_shipment_values_from_response(
        array $data,
        bool $mustMatchPo,
        string $po_number,
        array &$tracking_numbers,
        array &$invoice_numbers,
        ?string &$shipping_service,
        ?string &$shipping_weight
    ): void {
        foreach ($this->normalize_shipment_rows($data) as $row) {
            if ($mustMatchPo && !$this->shipment_row_matches_po($row, $po_number)) {
                continue;
            }

            $this->append_values_for_keys_recursive($row, [
                'tracking_number',
                'tracking_numbers',
                'tracking',
                'trackingNumber',
                'tracking_no',
                'trackingNumberList',
            ], $tracking_numbers);

            $this->append_values_for_keys_recursive($row, [
                'invoice_number',
                'invoice_numbers',
                'invoice',
                'invoiceNumber',
            ], $invoice_numbers);

            if ($shipping_service === null) {
                $shipping_service = $this->first_non_empty_row_value($row, [
                    'shipping_service',
                    'shipping_method',
                    'shippingMethod',
                    'carrier',
                    'carrier_name',
                    'shipper',
                    'service',
                ]);
            }

            if ($shipping_weight === null) {
                $shipping_weight = $this->first_non_empty_row_value($row, ['shipping_weight', 'weight']);
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    private function normalize_shipment_rows(array $data): array
    {
        $source = null;
        foreach (['shipment_data', 'shipments', 'data', 'results'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $source = $data[$key];
                break;
            }
        }

        if (!is_array($source)) {
            $source = $data;
        }

        if (empty($source)) {
            return [];
        }

        if (!$this->is_list_array($source) && $this->array_has_any_keys($source, [
            'tracking_number',
            'tracking_numbers',
            'tracking',
            'trackingNumber',
            'purchase_order_number',
            'purchaseOrderNumber',
            'po_number',
            'order_id',
        ])) {
            return [$source];
        }

        $rows = [];
        foreach ($source as $entry) {
            if (is_array($entry)) {
                $rows[] = $entry;
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function shipment_row_matches_po(array $row, string $po_number): bool
    {
        $target = strtoupper(trim((string) $po_number));
        if ($target === '') {
            return false;
        }

        foreach (['purchase_order_number', 'purchaseOrderNumber', 'purchase_order', 'po_number', 'po', 'customer_po', 'merchant_po'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $candidate = strtoupper(trim((string) $row[$key]));
            if ($candidate !== '' && $candidate === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @param string[] $out
     */
    private function append_string_values($value, array &$out): void
    {
        if (is_array($value)) {
            foreach ($value as $entry) {
                $this->append_string_values($entry, $out);
            }
            return;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return;
        }

        $parts = preg_split('/[\r\n,;|]+/', $value);
        if (!is_array($parts) || count($parts) <= 1) {
            $out[] = $value;
            return;
        }

        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
    }

    /**
     * @param mixed $value
     * @param string[] $keys
     * @param string[] $out
     */
    private function append_values_for_keys_recursive($value, array $keys, array &$out): void
    {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $entry) {
            if (is_string($key) && in_array($key, $keys, true)) {
                $this->append_string_values($entry, $out);
                continue;
            }

            if (is_array($entry)) {
                $this->append_values_for_keys_recursive($entry, $keys, $out);
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param string[] $keys
     */
    private function first_non_empty_row_value(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $array
     */
    private function is_list_array(array $array): bool
    {
        $expected = 0;
        foreach (array_keys($array) as $key) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $array
     * @param string[] $keys
     */
    private function array_has_any_keys(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                return true;
            }
        }

        return false;
    }
}
