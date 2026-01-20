<?php

namespace FFLHub\Distributor\RSR;

use FFLHub\Distributor\DistributorBase;
use FFLHub\Distributor\Product\DistributorProductPayload;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Distributor\Services\RSR\RSRServices;
use FFLHub\Distributor\DistributorModuleInterface;

use FFLHub\Distributor\Product\DistributorOrderRequest;
use FFLHub\Distributor\Product\DistributorOrderResult;
use FFLHub\Distributor\Product\DistributorOrderLine;
use FFLHub\Distributor\Product\DistributorShipTo;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * RSR distributor implementation.
 *
 * Uses the local RSR fulfillment table for product/price/quantity lookups,
 * and the RSR DirectConnect "place-order" endpoint for ordering.
 */
class DistributorRSR extends DistributorBase
{
    /**
     * Default base URL for RSR DirectConnect.
     * Spec examples show the URI as "/api/rsrbridge/1.0/pos/place-order".
     *
     * If RSR changes hostnames, you can override via filter:
     *   add_filter('fflhub_rsr_api_base_url', fn() => 'https://...'); // returns base like https://api.rsrgroup.com
     */
    private const DEFAULT_API_BASE_URL = 'https://api.rsrgroup.com';

    private const PLACE_ORDER_PATH = '/api/rsrbridge/1.0/pos/place-order';

    public function __construct(DistributorModuleInterface $module, ?RSRServices $services = null)
    {
        parent::__construct($module, $services);
    }

    /**
     * Full product payload by UPC (includes image probing).
     */
    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
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

        $image_name = $this->get_string_field($row, ['image_name']);
        $image_name = trim((string) $image_name);

        $rsr_image_urls = [];
        if ($image_name !== '') {
            $rsr_image_urls = $this->build_rsr_image_urls_from_image_name($image_name);
        }

        if (! empty($rsr_image_urls)) {
            $payload->add_image_url((string) $rsr_image_urls[0]);
            foreach (array_slice($rsr_image_urls, 1) as $extra_url) {
                $payload->add_image_url($extra_url);
            }
        }

        return $payload;
    }

    /**
     * Lightweight pricing/stock payload for cron sync.
     */
    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
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

    /**
     * Shipping cost estimate by UPC.
     */
    public function get_shipping_cost_by_upc(string $upc): ?float
    {
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
        if ($requires_signature == 1) {
            $cost += 5.0;
        }

        return $cost;
    }

    /**
     * Submit RSR orders using DirectConnect place-order.
     *
     * IMPORTANT:
     * - RSR requires PartNum (RSR Stock #). We map UPC -> rsr_stock_number from our fulfillment table.
     * - RSR cannot combine firearms and accessories on a drop ship order (StatusCode 85),
     *   so we submit TWO orders if needed:
     *     - NON-FFL lines -> ship_to_customer
     *     - FFL lines     -> ship_to_ffl + ShipFFL
     *
     * Uses DROP-SHIP account creds (per your requirement).
     */
    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        if (! $this->services) {
            return new DistributorOrderResult(false, 'RSR services not available; cannot access fulfillment table.', []);
        }

        $username = $this->get_dropship_username();
        $password = $this->get_dropship_password();
        $pos      = $this->get_pos_indicator();

        if ($username === '' || $password === '') {
            return new DistributorOrderResult(false, 'Missing RSR dropship credentials (dropship_account_number/password).', []);
        }
        if ($pos === '') {
            return new DistributorOrderResult(false, 'Missing RSR POS indicator (pos_indicator).', []);
        }

        if (empty($request->lines)) {
            return new DistributorOrderResult(false, 'No order lines provided.', []);
        }

        // Split lines into NON vs FFL (RSR drop ship cannot mix)
        $lines_non = [];
        $lines_ffl = [];

        foreach ($request->lines as $l) {
            if (! ($l instanceof DistributorOrderLine)) {
                continue;
            }
            if ($l->ffl_required) {
                $lines_ffl[] = $l;
            } else {
                $lines_non[] = $l;
            }
        }

        if (empty($lines_non) && empty($lines_ffl)) {
            return new DistributorOrderResult(false, 'No valid order lines after normalization.', []);
        }

        $dealer_email = $this->resolve_dealer_email();

        $external_ids = [];
        $errors = [];

        // Base PO from merchant_order_id (must be <=22 chars and only allow letters/numbers/space/dash)
        $base_po = self::sanitize_rsr_po((string) $request->merchant_order_id);
        if ($base_po === '') {
            $base_po = 'WCORDER';
        }

        // Helper: customer identity required for dropship context
        $customer_name  = trim((string) $request->ship_to_customer->name);
        $customer_phone = trim((string) $request->ship_to_customer->phone);

        if ($customer_name === '') {
            $customer_name = 'Customer';
        }

        // RSR can throw StatusCode 82 if phone missing for FDS consumer.
        // We still submit if missing (RSR will error clearly), but we surface it.
        if ($customer_phone === '') {
            // keep empty; RSR will likely reject on firearm dropship
        }

        // 1) NON-FFL dropship to customer
        if (! empty($lines_non)) {
            $po = self::truncate_po($base_po . '-NON');

            $items = $this->build_rsr_items_from_lines($lines_non);
            if ($items instanceof DistributorOrderResult) {
                return $items; // early failure (e.g., missing PartNum mapping)
            }

            $ship = $request->ship_to_customer;

            $payload = [
                'Username' => $username,
                'Password' => $password,
                'POS'      => $pos,
                'PONum'    => $po,
                'Email'    => $dealer_email,

                // Drop-ship context
                'StoreName'   => $customer_name,
                'ContactNum'  => $customer_phone,

                // Ship-to customer address
                'ShipAddress'  => self::safe($ship->address1),
                'ShipAddress2' => self::safe($ship->address2),
                'ShipCity'     => self::safe($ship->city),
                'ShipState'    => self::state2($ship->state),
                'ShipZip'      => self::zip10($ship->zip),

                'Items' => $items,
            ];

            $resp = $this->rsr_api_place_order($payload);
            if (! $resp['ok']) {
                $errors[] = 'RSR NON: ' . $resp['message'];
            } else {
                $external_ids[] = $resp['external_id'];
            }
        }

        // 2) FFL dropship to transfer dealer + ShipFFL
        if (! empty($lines_ffl)) {
            $po = self::truncate_po($base_po . '-FFL');

            $items = $this->build_rsr_items_from_lines($lines_ffl);
            if ($items instanceof DistributorOrderResult) {
                return $items;
            }

            $ffl_num = strtoupper(trim((string) $request->receiving_ffl_number));
            if ($ffl_num === '') {
                $errors[] = 'RSR FFL: missing receiving FFL number (ShipFFL required).';
            } elseif (! ($request->ship_to_ffl instanceof DistributorShipTo)) {
                $errors[] = 'RSR FFL: missing ship_to_ffl address (transfer dealer address required).';
            } else {
                $ship = $request->ship_to_ffl;

                $payload = [
                    'Username' => $username,
                    'Password' => $password,
                    'POS'      => $pos,
                    'PONum'    => $po,
                    'Email'    => $dealer_email,

                    // Drop-ship context
                    'StoreName'   => $customer_name,
                    'ContactNum'  => $customer_phone,

                    // Ship-to TRANSFER DEALER address
                    'ShipAddress'  => self::safe($ship->address1),
                    'ShipAddress2' => self::safe($ship->address2),
                    'ShipCity'     => self::safe($ship->city),
                    'ShipState'    => self::state2($ship->state),
                    'ShipZip'      => self::zip10($ship->zip),

                    // REQUIRED for firearm drop ship
                    'ShipFFL' => $ffl_num,

                    'Items' => $items,
                ];

                $resp = $this->rsr_api_place_order($payload);
                if (! $resp['ok']) {
                    $errors[] = 'RSR FFL: ' . $resp['message'];
                } else {
                    $external_ids[] = $resp['external_id'];
                }
            }
        }

        if (! empty($errors)) {
            return new DistributorOrderResult(false, 'RSR order failed: ' . implode(' | ', $errors), $external_ids);
        }

        return new DistributorOrderResult(true, 'RSR order submitted.', $external_ids);
    }

    /**
     * Build RSR Items[] from normalized lines.
     *
     * RSR requires PartNum (RSR stock number).
     * We map UPC -> rsr_stock_number via fulfillment table.
     *
     * @param DistributorOrderLine[] $lines
     * @return array<int,array<string,mixed>>|DistributorOrderResult
     */
    private function build_rsr_items_from_lines(array $lines)
    {
        $items = [];

        foreach ($lines as $l) {
            if (! ($l instanceof DistributorOrderLine)) {
                continue;
            }

            $upc = trim((string) $l->upc);
            if ($upc === '') {
                continue;
            }

            $part = $this->lookup_rsr_partnum_by_upc($upc);
            if ($part === null || $part === '') {
                return new DistributorOrderResult(
                    false,
                    'RSR cannot map UPC to PartNum (rsr_stock_number) using fulfillment table: ' . $upc,
                    []
                );
            }

            $items[] = [
                // Spec: UPCcode optional but helpful
                'UPCcode' => $this->normalize_upc($upc) ?: $upc,
                'WishQty' => max(1, (int) $l->quantity),
                'PartNum' => $part,
            ];
        }

        if (empty($items)) {
            return new DistributorOrderResult(false, 'RSR: no valid items after mapping.', []);
        }

        return $items;
    }

    /**
     * UPC -> rsr_stock_number lookup.
     */
    private function lookup_rsr_partnum_by_upc(string $upc): ?string
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized);
        if (! $row) {
            return null;
        }

        $part = $this->get_string_field($row, ['rsr_stock_number', 'sku']);
        $part = trim((string) $part);

        return $part !== '' ? $part : null;
    }

    /**
     * Perform the API call to /pos/place-order.
     *
     * @return array{ok:bool,message:string,external_id:string,raw:array|null}
     */
    private function rsr_api_place_order(array $payload): array
    {
        $url = $this->get_api_base_url() . self::PLACE_ORDER_PATH;

        $args = [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ];

        $res = wp_remote_post($url, $args);

        if (is_wp_error($res)) {
            return [
                'ok' => false,
                'message' => 'HTTP error: ' . $res->get_error_message(),
                'external_id' => '',
                'raw' => null,
            ];
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);

        if ($code < 200 || $code >= 300) {
            return [
                'ok' => false,
                'message' => 'HTTP status ' . (string) $code . ': ' . substr((string) $body, 0, 300),
                'external_id' => '',
                'raw' => null,
            ];
        }

        $decoded = json_decode((string) $body, true);
        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'message' => 'Invalid JSON response from RSR.',
                'external_id' => '',
                'raw' => null,
            ];
        }

        // RSR response fields typically at top-level.
        $status_code = isset($decoded['StatusCode']) ? (string) $decoded['StatusCode'] : '';
        $status_msg  = isset($decoded['StatusMssg']) ? (string) $decoded['StatusMssg'] : '';

        // Some wrappers might nest, so try a fallback if needed.
        if ($status_code === '' && isset($decoded['Response']) && is_array($decoded['Response'])) {
            $maybe = $decoded['Response'];
            if (isset($maybe['StatusCode'])) {
                $status_code = (string) $maybe['StatusCode'];
            }
            if (isset($maybe['StatusMssg'])) {
                $status_msg = (string) $maybe['StatusMssg'];
            }
        }

        if ($status_code !== '00') {
            $item_status = '';
            if (isset($decoded['ItemStatus']) && is_string($decoded['ItemStatus'])) {
                $item_status = trim($decoded['ItemStatus']);
            }

            $msg = 'StatusCode=' . ($status_code !== '' ? $status_code : '(missing)');
            if ($status_msg !== '') {
                $msg .= ' ' . $status_msg;
            }
            if ($item_status !== '') {
                $msg .= ' | ItemStatus: ' . $item_status;
            }

            return [
                'ok' => false,
                'message' => $msg,
                'external_id' => '',
                'raw' => $decoded,
            ];
        }

        $external = '';
        foreach (['ConfirmResp', 'WebRef'] as $k) {
            if (isset($decoded[$k]) && is_string($decoded[$k]) && trim($decoded[$k]) !== '') {
                $external = trim($decoded[$k]);
                break;
            }
        }

        if ($external === '' && isset($decoded['ConfirmResp']) && is_numeric($decoded['ConfirmResp'])) {
            $external = (string) $decoded['ConfirmResp'];
        }

        if ($external === '') {
            // Fallback: keep a short identifiable token
            $external = 'RSR-' . gmdate('Ymd-His');
        }

        return [
            'ok' => true,
            'message' => 'OK',
            'external_id' => $external,
            'raw' => $decoded,
        ];
    }

    private function get_api_base_url(): string
    {
        $base = self::DEFAULT_API_BASE_URL;
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

    /**
     * RSR requires dealer email (not customer).
     * We use admin_email by default; you can later add a dedicated dealer_email setting if desired.
     */
    private function resolve_dealer_email(): string
    {
        $email = trim((string) get_option('admin_email', ''));
        if ($email === '') {
            $email = 'dealer@example.com';
        }
        return $email;
    }

    private static function safe(string $s): string
    {
        return trim((string) $s);
    }

    private static function state2(string $state): string
    {
        $s = strtoupper(trim((string) $state));
        return preg_match('/^[A-Z]{2}$/', $s) ? $s : $s;
    }

    /**
     * RSR accepts 5 or 9-digit zip with dash required for 9-digit.
     * We'll preserve a dash if the user has it, otherwise normalize digits.
     */
    private static function zip10(string $zip): string
    {
        $zip = trim((string) $zip);
        if ($zip === '') {
            return '';
        }

        // If already formatted 12345-6789 and valid, keep it.
        if (preg_match('/^\d{5}-\d{4}$/', $zip)) {
            return $zip;
        }

        $digits = preg_replace('/\D+/', '', $zip);
        $digits = is_string($digits) ? $digits : '';

        if (strlen($digits) >= 9) {
            return substr($digits, 0, 5) . '-' . substr($digits, 5, 4);
        }

        return substr($digits, 0, 5);
    }

    /**
     * PONum max 22 chars. Only allow letters/numbers/space/dash.
     */
    private static function sanitize_rsr_po(string $po): string
    {
        $po = trim((string) $po);
        if ($po === '') {
            return '';
        }

        // Replace disallowed chars with dash
        $po = preg_replace('/[^A-Za-z0-9 \-]+/', '-', $po);
        $po = is_string($po) ? $po : '';

        // Collapse whitespace
        $po = preg_replace('/\s+/', ' ', $po);
        $po = is_string($po) ? trim($po) : '';

        return self::truncate_po($po);
    }

    private static function truncate_po(string $po): string
    {
        $po = trim((string) $po);
        if ($po === '') {
            return '';
        }
        if (strlen($po) > 22) {
            $po = substr($po, 0, 22);
        }
        return $po;
    }

    /**
     * Given an RSR image_name like "LAS981-0054_1.jpg", generate all real
     * product image URLs for that item, stopping when we hit the generic
     * "image coming soon" placeholder (110x85).
     *
     * @param string $image_name
     * @return string[]
     */
    private function build_rsr_image_urls_from_image_name(string $image_name): array
    {
        $image_name = trim($image_name);
        if ($image_name === '') {
            return [];
        }

        $base_prefix = 'https://img.rsrgroup.com/pimages/';
        $urls        = [];

        if (preg_match('/^(.*)_([0-9]+)(\.[^.]+)$/', $image_name, $matches)) {
            $base        = $matches[1];
            $start_index = (int) $matches[2];
            $ext         = $matches[3];

            $first_file = $base . '_' . $start_index . $ext;
            $first_url  = $base_prefix . $first_file;
            $urls[]     = $first_url;

            $max_extra_attempts = 15;

            for ($i = $start_index + 1; $i <= $start_index + $max_extra_attempts; $i++) {
                $file = $base . '_' . $i . $ext;
                $url  = $base_prefix . $file;

                if (! $this->rsr_is_real_image_url($url)) {
                    break;
                }

                $urls[] = $url;
            }
        } else {
            $urls[] = $base_prefix . ltrim($image_name, '/');
        }

        return array_values(array_unique($urls));
    }

    /**
     * Check whether the given RSR image URL is a real product image
     * and NOT the generic "image coming soon" placeholder.
     */
    private function rsr_is_real_image_url(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $response = wp_remote_get(
            $url,
            [
                'timeout'     => 5,
                'redirection' => 3,
            ]
        );

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '' || $body === null) {
            return false;
        }

        $image_info = @getimagesizefromstring($body);
        if (false === $image_info) {
            return false;
        }

        $width  = isset($image_info[0]) ? (int) $image_info[0] : 0;
        $height = isset($image_info[1]) ? (int) $image_info[1] : 0;

        if ($width === 110 && $height === 85) {
            return false;
        }

        return true;
    }
}
