<?php

namespace FFLHub\Distributor\RSR;

use FFLHub\Distributor\DistributorBase;
use FFLHub\Distributor\Product\DistributorProductPayload;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Distributor\Services\RSR\RSRServices;
use FFLHub\Distributor\DistributorModuleInterface;

use FFLHub\Distributor\Product\DistributorOrderRequest;
use FFLHub\Distributor\Product\DistributorOrderValidationResult;

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
 * and the RSR DirectConnect endpoints for validation + ordering.
 */
class DistributorRSR extends DistributorBase
{
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

        $image_name = trim((string) $this->get_string_field($row, ['image_name']));
        if ($image_name !== '') {
            $rsr_image_urls = RSRDirectConnectAPI::build_image_urls_from_image_name($image_name);
            if (!empty($rsr_image_urls)) {
                $payload->add_image_url((string) $rsr_image_urls[0]);
                foreach (array_slice($rsr_image_urls, 1) as $extra_url) {
                    $payload->add_image_url($extra_url);
                }
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
     */
    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        if (! $this->services) {
            return new DistributorOrderResult(false, 'RSR services not available; cannot access fulfillment table.', []);
        }

        $auth = $this->get_rsr_auth_payload();
        if (! $auth['ok']) {
            return new DistributorOrderResult(false, $auth['message'], []);
        }

        if (empty($request->lines)) {
            return new DistributorOrderResult(false, 'No order lines provided.', []);
        }

        $lines_non = $request->non_ffl_lines();
        $lines_ffl = $request->ffl_lines();

        if (empty($lines_non) && empty($lines_ffl)) {
            return new DistributorOrderResult(false, 'No valid order lines after normalization.', []);
        }

        $api_base_url = $this->get_api_base_url();
        $external_ids = [];
        $errors = [];

        $base_po = RSRDirectConnectAPI::sanitize_rsr_po((string) $request->merchant_order_id);
        if ($base_po === '') {
            $base_po = 'WCORDER';
        }

        // NON-FFL order
        if (! empty($lines_non)) {
            $po = RSRDirectConnectAPI::truncate_po($base_po . '-NON');

            $items = $this->build_rsr_items_from_lines($lines_non);
            if ($items instanceof DistributorOrderResult) {
                return $items;
            }

            $ship = $request->ship_to_customer;

            $ship_check = RSRDirectConnectAPI::validate_ship_to_required_fields($ship);
            if (! $ship_check['ok']) {
                return new DistributorOrderResult(false, 'RSR NON: ' . $ship_check['message'], []);
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
                $errors[] = 'RSR NON: ' . $resp['message'];
            } else {
                $external_ids[] = $resp['external_id'];
            }
        }

        // FFL order
        if (! empty($lines_ffl)) {
            $po = RSRDirectConnectAPI::truncate_po($base_po . '-FFL');

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

                $ship_check = RSRDirectConnectAPI::validate_ship_to_required_fields($ship);
                if (! $ship_check['ok']) {
                    $errors[] = 'RSR FFL: ' . $ship_check['message'];
                } else {
                    $id_check = RSRDirectConnectAPI::validate_customer_identity_for_firearm_dropship($request->ship_to_customer);
                    if (! $id_check['ok']) {
                        $errors[] = 'RSR FFL: ' . $id_check['message'];
                    } else {
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
                            $errors[] = 'RSR FFL: ' . $resp['message'];
                        } else {
                            $external_ids[] = $resp['external_id'];
                        }
                    }
                }
            }
        }

        if (! empty($errors)) {
            return new DistributorOrderResult(false, 'RSR order failed: ' . implode(' | ', $errors), $external_ids);
        }

        return new DistributorOrderResult(true, 'RSR order submitted.', $external_ids);
    }

    /**
     * Validate an order request against RSR shipping/restriction rules using check-catalog.
     */
    public function validate_order_request(DistributorOrderRequest $request): DistributorOrderValidationResult
    {
        if (! $this->services) {
            return DistributorOrderValidationResult::block(
                'RSR services not available; cannot access fulfillment table.',
                ['RSR_SERVICES_MISSING']
            );
        }

        $auth = $this->get_rsr_auth_payload();
        if (! $auth['ok']) {
            return DistributorOrderValidationResult::block(
                $auth['message'],
                ['RSR_AUTH_MISSING']
            );
        }

        $lines_non = $request->non_ffl_lines();
        $lines_ffl = $request->ffl_lines();

        if (empty($lines_non) && empty($lines_ffl)) {
            return DistributorOrderValidationResult::allow('No valid order lines to validate.');
        }

        $details = [];

        if (! empty($lines_non)) {
            $res_non = $this->rsr_check_catalog_for_bucket($auth['payload'], $request, false, $lines_non);
            $details['non'] = $res_non;

            if (! $res_non['ok']) {
                return DistributorOrderValidationResult::block(
                    'RSR validation failed (non-FFL items): ' . $res_non['message'],
                    ['RSR_CHECK_CATALOG_RESTRICTED'],
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
                return DistributorOrderValidationResult::block(
                    'RSR validation failed (FFL items): ' . $res_ffl['message'],
                    ['RSR_CHECK_CATALOG_RESTRICTED'],
                    $details
                );
            }
        }

        return DistributorOrderValidationResult::allow('RSR validation OK.', $details);
    }

    /**
     * Bucket-aware check-catalog call builder.
     *
     * @param array{Username:string,Password:string,POS:string} $auth_payload
     * @param DistributorOrderLine[] $lines
     * @return array{ok:bool,message:string,items:array<int,array<string,mixed>>,raw:array|null}
     */
    private function rsr_check_catalog_for_bucket(array $auth_payload, DistributorOrderRequest $request, bool $ffl_bucket, array $lines): array
    {
        $items = $this->build_rsr_check_catalog_items_from_lines($lines);
        if ($items instanceof DistributorOrderResult) {
            return [
                'ok' => false,
                'message' => $items->message,
                'items' => [],
                'raw' => null,
            ];
        }

        $ship = $request->ship_to_for_bucket($ffl_bucket);

        $ship_check = RSRDirectConnectAPI::validate_ship_to_required_fields($ship);
        if (! $ship_check['ok']) {
            return [
                'ok' => false,
                'message' => $ship_check['message'],
                'items' => [],
                'raw' => null,
            ];
        }

        if ($ffl_bucket) {
            $id_check = RSRDirectConnectAPI::validate_customer_identity_for_firearm_dropship($request->ship_to_customer);
            if (! $id_check['ok']) {
                return [
                    'ok' => false,
                    'message' => $id_check['message'],
                    'items' => [],
                    'raw' => null,
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

        return RSRDirectConnectAPI::check_catalog($payload, $this->get_api_base_url(), 60);
    }

    /**
     * Normalized UPC -> rsr_stock_number lookup (avoids re-normalizing).
     */
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
     * Build RSR Items[] from normalized lines (place-order).
     *
     * @param DistributorOrderLine[] $lines
     * @return array<int,array<string,mixed>>|DistributorOrderResult
     */
    private function build_rsr_items_from_lines(array $lines)
    {
        return $this->map_order_lines_to_items(
            $lines,
            function (string $normalized_upc, string $raw_upc, DistributorOrderLine $line): ?string {
                return $this->lookup_rsr_partnum_by_normalized_upc($normalized_upc);
            },
            function (string $partnum, int $qty, string $normalized_upc): array {
                return [
                    'UPCcode' => $normalized_upc,
                    'WishQty' => $qty,
                    'PartNum' => $partnum,
                ];
            },
            'RSR cannot map UPC to PartNum (rsr_stock_number) using fulfillment table: %s',
            true,
            'RSR: no valid items after mapping.'
        );
    }

    /**
     * Build check-catalog Items[] entries from lines.
     *
     * Uses LookupBy=S (RSR Stock #), so Items[] becomes:
     *   [ ['PartNum' => 'ABC123'], ['PartNum' => 'DEF456'] ]
     *
     * Also de-dupes PartNum and caps to 100.
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
            function (string $partnum, int $qty, string $normalized_upc): array {
                return ['PartNum' => $partnum];
            },
            'RSR cannot map UPC to PartNum (rsr_stock_number) using fulfillment table: %s',
            true,
            'RSR: no valid items after mapping.'
        );

        if (! is_array($items)) {
            return $items;
        }

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

    /**
     * Centralized auth payload builder for RSR API calls.
     *
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
     * RSR requires dealer email (not customer).
     *
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

    /**
     * RSR requires dealer email (not customer).
     */
    private function resolve_dealer_email(): string
    {
        $email = trim((string) get_option('admin_email', ''));
        if ($email === '') {
            $email = 'dealer@example.com';
        }
        return $email;
    }



    
}
