<?php

namespace FFLHub\Distributor\Integrations\SportsSouth;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOffer;
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
use FFLHub\Settings\Options;

/**
 * Sports South runtime distributor backed by the local catalog table.
 */
final class DistributorSportsSouth extends DistributorBase
{
    private const DEFAULT_SHIP_VIA = '';

    public function __construct(DistributorModuleInterface $module, $services = null)
    {
        parent::__construct($module, $services);
    }

    public function get_offer_by_upc(string $upc, bool $include_images = true): ?DistributorOffer
    {
        if (!self::lookup_offers_enabled()) {
            return null;
        }

        return parent::get_offer_by_upc($upc, $include_images);
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

        if (strtolower(trim((string) ($request->lane ?? ''))) === 'direct_ship_ffl') {
            return DistributorOrderValidationResult::block(
                'Sports South direct firearm fulfillment is not enabled; route FFL items through dealer-fulfilled batch ordering.',
                ['SPORTS_SOUTH_DIRECT_FFL_NOT_ENABLED']
            );
        }

        return $this->require_ffl_shipto_if_ffl_lines($request, 'SPORTS_SOUTH');
    }

    public function validate_order_request(
        DistributorOrderRequest $request,
        bool $local_only = false
    ): DistributorOrderValidationResult {
        return parent::validate_order_request($request, $local_only);
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

    private static function lookup_offers_enabled(): bool
    {
        return (bool) apply_filters('fflhub_sports_south_expose_lookup_offers', true);
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $cost = apply_filters('fflhub_sports_south_flat_shipping_cost', 0.0, $normalized, $this);

        return is_numeric($cost) ? max(0.0, (float) $cost) : 0.0;
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

        if ($this->get_customer_number() === '' || $this->get_username() === '' || $this->get_password() === '') {
            return DistributorOrderResult::block_fatal(
                'Sports South: missing order credentials.',
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS]
            );
        }

        $po = $this->sanitize_and_truncate_po((string) $request->merchant_order_id, 32);
        if ($po === '') {
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
        $lane = strtolower(trim((string) $lane));

        if ($lane === 'direct_ship_ffl') {
            return DistributorOrderResult::block_fatal(
                'Sports South direct firearm fulfillment is not enabled; use dealer-fulfilled Sports South batch ordering.',
                [DistributorOrderResult::REASON_FATAL_NOT_IMPLEMENTED],
                [],
                0,
                '',
                $external_ids
            );
        }

        if ($lane !== 'dealer_fulfilled' && $lane !== 'direct_ship_non_ffl') {
            return DistributorOrderResult::block_fatal(
                'Sports South: unsupported lane "' . $lane . '".',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $details = $this->build_sports_south_detail_rows($lines);
        if ($details instanceof DistributorOrderResult) {
            $details->external_order_ids = $external_ids;
            return $details;
        }

        $po = $this->sanitize_and_truncate_po((string) $request->merchant_order_id, 32);
        if ($po === '') {
            $po = 'SS-' . gmdate('YmdHis');
        }

        $header = $this->build_header_params($request, $lane, $po);
        if ($header instanceof DistributorOrderResult) {
            $header->external_order_ids = $external_ids;
            return $header;
        }

        $client = $this->make_orders_client(60);
        $endpoint = rtrim($this->get_orders_api_base_url(), '/');

        if ($this->is_test_order_debug_enabled()) {
            return $this->build_test_order_debug_block(
                $lane,
                $endpoint . '/AddHeader + /AddDetail + /Submit',
                'POST',
                'form',
                $this->encode_debug_json_payload([
                    'AddHeader' => $header,
                    'AddDetail' => $details,
                    'Submit' => ['OrderNumber' => '{AddHeaderResult}'],
                ]),
                [
                    'po' => $po,
                    'item_count' => count($details),
                    'ship_via' => (string) ($header['ShipVIA'] ?? ''),
                ],
                $external_ids
            );
        }

        $headerResp = $client->add_header($header);
        if (empty($headerResp['ok'])) {
            $failure = $this->classify_sports_south_failure($headerResp, 'Sports South AddHeader', [
                'lane' => $lane,
                'po' => $po,
                'item_count' => count($details),
            ]);
            $failure->external_order_ids = $external_ids;
            return $failure;
        }

        $ssOrderNumber = (string) ($headerResp['order_number'] ?? '');
        if ($ssOrderNumber !== '') {
            $external_ids[] = $ssOrderNumber;
        }

        foreach ($details as $detail) {
            $detailResp = $client->add_detail($ssOrderNumber, $detail);
            if (empty($detailResp['ok'])) {
                $failure = $this->classify_sports_south_failure($detailResp, 'Sports South AddDetail', [
                    'lane' => $lane,
                    'po' => $po,
                    'sports_south_order_number' => $ssOrderNumber,
                    'item' => $this->summarize_detail_row($detail),
                ], $external_ids);
                $failure->external_order_ids = $external_ids;
                return $failure;
            }
        }

        $submitResp = $client->submit($ssOrderNumber);
        if (empty($submitResp['ok'])) {
            $failure = $this->classify_sports_south_failure($submitResp, 'Sports South Submit', [
                'lane' => $lane,
                'po' => $po,
                'sports_south_order_number' => $ssOrderNumber,
                'item_count' => count($details),
            ], $external_ids);
            $failure->external_order_ids = $external_ids;
            return $failure;
        }

        return DistributorOrderResult::ok(
            'Sports South ' . $this->lane_label($lane) . ' order submitted.',
            $external_ids,
            [
                'po' => $po,
                'sports_south_order_number' => $ssOrderNumber,
                'item_count' => count($details),
            ]
        );
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

        if (!($request->ship_to_customer instanceof DistributorShipTo)) {
            return DistributorOrderResult::block_fatal(
                'Sports South fulfillment: missing ship_to_customer.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        $shipCheck = self::validate_shipto_minimum($request->ship_to_customer);
        if (empty($shipCheck['ok'])) {
            return DistributorOrderResult::block_fatal(
                'Sports South fulfillment: ' . (string) ($shipCheck['message'] ?? 'Invalid ship-to.'),
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                ['ship_check' => $shipCheck]
            );
        }

        if (self::extract_digits($request->ship_to_customer->phone) === '') {
            return DistributorOrderResult::block_fatal(
                'Sports South fulfillment: ship-to phone is required.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        return array_merge($header, $this->build_ship_to_header_params($request->ship_to_customer));
    }

    /**
     * @return array<string,string>
     */
    private function build_ship_to_header_params(DistributorShipTo $ship): array
    {
        $name = trim((string) ($ship->name !== '' ? $ship->name : $ship->company));
        $attn = trim((string) ($ship->company !== '' && $ship->company !== $name ? $ship->company : $ship->name));

        return [
            'ShipToName' => self::truncate_string(self::normalize_payload_string($name), 40),
            'ShipToAttn' => self::truncate_string(self::normalize_payload_string($attn), 40),
            'ShipToAddr1' => self::truncate_string(self::normalize_payload_string($ship->address1), 40),
            'ShipToAddr2' => self::truncate_string(self::normalize_payload_string($ship->address2), 40),
            'ShipToCity' => self::truncate_string(self::normalize_payload_string($ship->city), 30),
            'ShipToState' => self::format_us_state2_best_effort($ship->state),
            'ShipToZip' => self::format_us_zip5_best_effort($ship->zip),
            'ShipToPhone' => self::truncate_string(self::extract_digits($ship->phone), 10),
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

        $msgLc = strtolower($msg);
        $retryable = (
            $http === 0 ||
            $http === 408 ||
            $http === 429 ||
            $http >= 500 ||
            strpos($msgLc, 'timeout') !== false ||
            strpos($msgLc, 'timed out') !== false ||
            strpos($msgLc, 'could not resolve') !== false ||
            strpos($msgLc, 'connection') !== false
        );

        $safeDetails = array_merge($details, [
            'http_status' => $http,
            'error' => $msg,
            'scalar' => (string) ($resp['scalar'] ?? ''),
        ]);

        if ($retryable) {
            return DistributorOrderResult::block_retryable(
                $ctx . ': ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UNKNOWN],
                $safeDetails,
                $http,
                '',
                $external_ids
            );
        }

        return DistributorOrderResult::block_fatal(
            $ctx . ': ' . $msg,
            [DistributorOrderResult::REASON_FATAL_UNKNOWN],
            $safeDetails,
            $http,
            '',
            $external_ids
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

}
