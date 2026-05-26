<?php
// File: src/Distributor/Zanders/DistributorZanders.php

namespace FFLHub\Distributor\Integrations\Zanders;

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Distributor\Services\FTP\FTPClientService;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Distributor\Services\Zanders\API\ZandersSoapCurlClient;
use FFLHub\Distributor\Services\Zanders\ZandersFtpCredentials;
use FFLHub\Distributor\Services\Zanders\ZandersServices;
use FFLHub\FFL\Data\FFLRepository;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

class DistributorZanders extends DistributorBase
{
    /**
     * Toggle Zanders debug logs.
     *
     * Enable by setting:
     *   define('FFLHUB_ZANDERS_DEBUG', true);
     * in wp-config.php, OR env var:
     *   FFLHUB_ZANDERS_DEBUG=1
     */
    private const DEBUG_FLAG = 'FFLHUB_ZANDERS_DEBUG';
    private const LOG_PREFIX = '[FFLHub][ZandersDistributor]';
    private const FLAT_SHIPPING_COST = 15.0;
    private const FREE_SHIPPING_DISTRIBUTOR_COST_THRESHOLD = 500.0;

    /**
     * Guardrail: prevent pathological carts from causing heavy DB lookups.
     */
    private const VALIDATE_MAX_UNIQUE_ITEMS = 75;

    public function __construct(DistributorModuleInterface $module, $services = null)
    {
        parent::__construct($module, $services);
    }



    //ZANDERS VALIDATION SECTION

    protected function supports_remote_validation(): bool
    {
        return false; // Zanders never does remote validation
    }

    protected function validation_max_unique_items(DistributorOrderRequest $request, bool $local_only): int
    {
        return self::VALIDATE_MAX_UNIQUE_ITEMS;
    }

    protected function validation_too_many_unique_code(): string
    {
        return 'ZANDERS_VALIDATE_TOO_MANY_UNIQUE';
    }

    protected function validation_services_missing_code(): string
    {
        return 'ZANDERS_SERVICES_MISSING';
    }

    protected function validation_services_missing_message(): string
    {
        return 'Zanders services not available; cannot access fulfillment table.';
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
        // lane + enforcement is shared in the base helper.
        $b = $this->infer_lane_and_ffl_enforcement($request);

        return [
            'label'                => 'Zanders validation (local)',
            'max_unique'           => self::VALIDATE_MAX_UNIQUE_ITEMS,
            'inventory_keys'       => ['inventory_quantity'],
            'unknown_qty_blocks'   => true,

            'lane'                 => $b['lane'],
            'enforce_ffl_required' => $b['enforce_ffl_required'],
            'ffl_required_row_keys' => ['ffl_required'],

            'extra_row_checks' => function (array $row, string $normalized_upc, int $requiredQty): array {
                $sot_raw  = $row['sot_required'] ?? null;
                $sot_flag = (int) (is_numeric((string) $sot_raw) ? (int) $sot_raw : ((string) $sot_raw === 'Y' ? 1 : 0));

                return [
                    'ok' => true,
                    'details' => [
                        'sot_required'        => $sot_flag,
                        'zanders_item_number' => (string) ($row['zanders_item_number'] ?? ''),
                    ],
                ];
            },
        ];
    }


    //END VALIDATION SECTION



    private function is_testing_mode(): bool
    {
        // Source of truth: global Admin setting ("Test order debug mode").
        $testing = Options::get_test_order_debug_enabled();

        // Keep filter hook for emergency overrides/custom deployments.
        return (bool) apply_filters('fflhub_zanders_testing_mode', $testing);
    }

    private function is_dealer_fulfilled_manual_mode(): bool
    {
        $mode = strtolower(trim((string) Options::get_distributor_option('zanders', 'dealer_fulfilled_mode', 'manual')));
        return $mode !== 'auto';
    }


    /**
     * @return array{
     *   ok:bool,
     *   message:string,
     *   lane:string,
     *   username_key:string,
     *   password_key:string,
     *   payload:array{username?:string,password?:string}
     * }
     */
    private function get_zanders_auth_for_lane(string $lane): array
    {
        $lane = strtolower(trim((string) $lane));
        if ($lane === 'dealer_fulfilled') {
            $auth_lane = 'dealer_fulfilled';
            $u_key = 'main_username';
            $p_key = 'main_password';
        } elseif ($lane === 'direct_ship_ffl') {
            $auth_lane = 'direct_ship_ffl';
            $u_key = 'gun_username';
            $p_key = 'gun_password';
        } else {
            $auth_lane = 'direct_ship_non_ffl';
            $u_key = 'accessory_username';
            $p_key = 'accessory_password';
        }

        $u = trim((string) Options::get_distributor_option('zanders', $u_key, ''));
        $p = trim((string) Options::get_distributor_option('zanders', $p_key, ''));

        if ($u === '' || $p === '') {
            return [
                'ok' => false,
                'message' => "Missing Zanders SOAP creds for lane={$auth_lane} (keys: {$u_key}/{$p_key}).",
                'lane' => $auth_lane,
                'username_key' => $u_key,
                'password_key' => $p_key,
                'payload' => [],
            ];
        }

        return [
            'ok' => true,
            'message' => 'OK',
            'lane' => $auth_lane,
            'username_key' => $u_key,
            'password_key' => $p_key,
            'payload' => ['username' => $u, 'password' => $p],
        ];
    }


    private function make_orders_client(): ZandersSoapCurlClient
    {
        $verify_tls = (bool) apply_filters('fflhub_zanders_verify_tls', true);
        $timeout    = (int) apply_filters('fflhub_zanders_timeout_sec', 60);

        return new ZandersSoapCurlClient(
            ZandersDirectShipAPI::ORDERS_WSDL,
            max(10, $timeout),
            $verify_tls,
            'FFLHUB-Zanders-Orders'
        );
    }

    private function make_shipto_client(): ZandersSoapCurlClient
    {
        $verify_tls = (bool) apply_filters('fflhub_zanders_verify_tls', true);
        $timeout    = (int) apply_filters('fflhub_zanders_timeout_sec', 60);

        return new ZandersSoapCurlClient(
            ZandersDirectShipAPI::SHIPTO_WSDL,
            max(10, $timeout),
            $verify_tls,
            'FFLHUB-Zanders-ShipTo'
        );
    }

    //ORDERING SECIONT


    protected function supports_ordering(): bool
    {
        return true;
    }

    protected function place_order_stop_on_first_failure(): bool
    {
        return true; // match RSR behavior: no partials
    }

    protected function place_order_precheck(DistributorOrderRequest $request): ?DistributorOrderResult
    {
        $base = parent::place_order_precheck($request);
        if ($base instanceof DistributorOrderResult) {
            return $base;
        }

        $po = $this->sanitize_and_truncate_po((string) $request->merchant_order_id, 22);
        if ($po === '') {
            return DistributorOrderResult::block_fatal(
                'Zanders: missing merchant PO (purchaseOrderNumber).',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST]
            );
        }

        $lane = strtolower(trim((string) ($request->lane ?? '')));
        if ($lane === 'dealer_fulfilled' && $this->is_dealer_fulfilled_manual_mode()) {
            return DistributorOrderResult::manual(
                'Zanders dealer-fulfilled ordering is set to manual mode. Enter the merchant PO on the Zanders Dealer Batch Queue page after placing the order manually.',
                [
                    DistributorOrderResult::REASON_MANUAL_REQUIRED,
                    'ZANDERS_DEALER_FULFILLED_MANUAL_MODE',
                ],
                [
                    'lane' => $lane,
                    'dealer_fulfilled_mode' => 'manual',
                ]
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

        $ca_drop_ship_manual = $this->manual_result_if_ca_drop_ship_to_california($request, $lane, $external_ids);
        if ($ca_drop_ship_manual instanceof DistributorOrderResult) {
            return $ca_drop_ship_manual;
        }

        $auth = $this->get_zanders_auth_for_lane($lane);
        if (empty($auth['ok'])) {
            $r = DistributorOrderResult::block_fatal(
                'Zanders: missing credentials for lane=' . $lane,
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS],
                ['auth' => ['message' => (string)($auth['message'] ?? '')]]
            );
            $r->external_order_ids = $external_ids;
            return $r;
        }
        $testing = $this->is_testing_mode();

        $orders_client = $this->make_orders_client();
        $shipto_client = $this->make_shipto_client();


        $po = $this->sanitize_and_truncate_po(
            (string) $request->merchant_order_id,
            ($lane === 'dealer_fulfilled') ? 25 : 22
        );


        $ship_date = (string) apply_filters('fflhub_zanders_ship_date', gmdate('Y-m-d'), $request);

        $items = $this->build_zanders_items(
            $lines,
            $lane === 'dealer_fulfilled',
            $lane === 'dealer_fulfilled'
        );
        if ($items instanceof DistributorOrderResult) {
            $items->external_order_ids = $external_ids;
            return $items;
        }

        if ($lane === 'dealer_fulfilled') {
            $customer_email = '';
            if (($request->ship_to_customer instanceof DistributorShipTo) && trim((string) $request->ship_to_customer->email) !== '') {
                $customer_email = trim((string) $request->ship_to_customer->email);
            }
            if ($customer_email === '') {
                $customer_email = trim((string) get_option('admin_email', ''));
            }
            if ($customer_email === '') {
                $customer_email = 'dealer@example.com';
            }

            $order_map = [
                'shipToNo'             => '0001',
                'shipDate'             => $ship_date,
                'shipViaCode'          => 'BW',
                'shipInstructions'     => self::truncate_string((string) $request->notes, 80),
                'orderCommentsEmail'   => self::truncate_string($customer_email, 40),
                'purchaseOrderNumber'  => $po,
                'items'                => $items,
            ];

            if ($this->is_test_order_debug_enabled()) {
                $call_payload = [
                    'username' => (string) ($auth['payload']['username'] ?? ''),
                    'password' => (string) ($auth['payload']['password'] ?? ''),
                    'order'    => $order_map,
                    'testing'  => (bool) $testing,
                ];
                $xml = $orders_client->build_request_xml_preview(
                    ZandersDirectShipAPI::OP_CREATE_ORDER,
                    $call_payload,
                    ZandersDirectShipAPI::ORDERS_NS_HTTPS,
                    ['mode' => 'zanders_rpc_encoded']
                );

                return $this->build_test_order_debug_block(
                    $lane,
                    $this->strip_wsdl_suffix(ZandersDirectShipAPI::ORDERS_WSDL),
                    'POST',
                    'xml',
                    $xml,
                    [
                        'po' => $po,
                        'operation' => ZandersDirectShipAPI::OP_CREATE_ORDER,
                        'item_count' => count($items),
                    ],
                    $external_ids
                );
            }

            $soap = ZandersDirectShipAPI::create_order($orders_client, $auth['payload'], $order_map, $testing);
            if (!($soap['ok'] ?? false)) {
                $r = $this->classify_zanders_transport_failure($soap, 'Zanders dealer-fulfilled');
                $r->external_order_ids = $external_ids;
                return $r;
            }

            $norm = ZandersDirectShipAPI::normalize_order_response($soap, 'Zanders dealer-fulfilled');
            if (!($norm['ok'] ?? false)) {
                $r = $this->classify_zanders_order_failure($norm, 'Zanders dealer-fulfilled');
                $r->external_order_ids = $external_ids;
                return $r;
            }

            $external_ids[] = (string) ($norm['order_number'] ?? '');
            return DistributorOrderResult::ok('Zanders dealer-fulfilled order submitted.', $external_ids);
        }

        if ($lane === 'direct_ship_non_ffl') {
            if (!($request->ship_to_customer instanceof DistributorShipTo)) {
                return DistributorOrderResult::block_fatal(
                    'Zanders direct-ship non-FFL: missing ship_to_customer (ship-to address required).',
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    [],
                    0,
                    '',
                    $external_ids
                );
            }

            $ship = $request->ship_to_customer;
            $ship_check = self::validate_shipto_minimum($ship);


            if (!($ship_check['ok'] ?? false)) {
                return DistributorOrderResult::block_fatal(
                    'Zanders direct-ship non-FFL: ' . (string) ($ship_check['message'] ?? 'Invalid ship-to.'),
                    [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                    ['ship_check' => $ship_check],
                    0,
                    '',
                    $external_ids
                );
            }

            $order_map = [
                'shipToName'         => self::truncate_string($ship->name, 40),
                'shipToAddress1'     => self::truncate_string($ship->address1, 40),
                'shipToAddress2'     => self::truncate_string($ship->address2, 40),
                'shipToCity'         => self::truncate_string($ship->city, 30),
                'shipToState'        => self::format_us_state2_best_effort($ship->state),
                'shipToZip'          => self::format_us_zip5_best_effort($ship->zip),

                'shipDate'           => $ship_date,
                'shipViaCode'        => 'UM',
                'shipInstructions'   => self::truncate_string((string) $request->notes, 60),

                'orderCommentsPhone' => self::truncate_string($ship->phone, 20),
                'orderCommentsEmail' => self::truncate_string($ship->email, 60),

                'purchaseOrderNumber' => $po,
                'items'               => $items,
            ];

            if ($this->is_test_order_debug_enabled()) {
                $call_payload = [
                    'username' => (string) ($auth['payload']['username'] ?? ''),
                    'password' => (string) ($auth['payload']['password'] ?? ''),
                    'order'    => $order_map,
                    'testing'  => (bool) $testing,
                ];
                $xml = $orders_client->build_request_xml_preview(
                    ZandersDirectShipAPI::OP_CREATE_ORDER,
                    $call_payload,
                    ZandersDirectShipAPI::ORDERS_NS_HTTPS,
                    ['mode' => 'zanders_rpc_encoded']
                );

                return $this->build_test_order_debug_block(
                    $lane,
                    $this->strip_wsdl_suffix(ZandersDirectShipAPI::ORDERS_WSDL),
                    'POST',
                    'xml',
                    $xml,
                    [
                        'po' => $po,
                        'operation' => ZandersDirectShipAPI::OP_CREATE_ORDER,
                        'item_count' => count($items),
                    ],
                    $external_ids
                );
            }

            $soap = ZandersDirectShipAPI::create_order($orders_client, $auth['payload'], $order_map, $testing);
            if (!($soap['ok'] ?? false)) {
                $r = $this->classify_zanders_transport_failure($soap, 'Zanders direct-ship non-FFL');
                $r->external_order_ids = $external_ids;
                return $r;
            }

            $norm = ZandersDirectShipAPI::normalize_order_response($soap, 'Zanders direct-ship non-FFL');
            if (!($norm['ok'] ?? false)) {
                $r = $this->classify_zanders_order_failure($norm, 'Zanders direct-ship non-FFL');
                $r->external_order_ids = $external_ids;
                return $r;
            }

            $external_ids[] = (string) ($norm['order_number'] ?? '');
            return DistributorOrderResult::ok('Zanders direct-ship non-FFL order submitted.', $external_ids);
        }

        if ($lane !== 'direct_ship_ffl') {
            return DistributorOrderResult::block_fatal(
                'Zanders: unsupported lane "' . $lane . '".',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        // direct_ship_ffl lane
        if (!($request->ship_to_ffl instanceof DistributorShipTo)) {
            return DistributorOrderResult::block_fatal(
                'Zanders FFL: missing ship_to_ffl (transfer dealer address required).',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $ffl_num = strtoupper(trim((string) $request->receiving_ffl_number));
        if ($ffl_num === '') {
            return DistributorOrderResult::block_fatal(
                'Zanders FFL: missing receiving FFL number.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $fflno = self::format_fflno_first3_last5($ffl_num); // now base version
        if ($fflno === '') {
            return DistributorOrderResult::block_fatal(
                'Zanders FFL: invalid receiving FFL number (cannot derive first3+last5).',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                ['receiving_ffl_number' => $ffl_num],
                0,
                '',
                $external_ids
            );
        }

        $fflexp = $this->resolve_fflexp_for_request($request);
        if (!self::looks_like_yyyy_mm_dd($fflexp)) {
            return DistributorOrderResult::block_fatal(
                'Zanders FFL: missing/invalid FFL expiration (fflexp required for useShipTo).',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                ['fflexp' => $fflexp],
                0,
                '',
                $external_ids
            );
        }

        $ffl_ship = $request->ship_to_ffl;

        $addrinfo = [
            'name'     => self::truncate_string($ffl_ship->company !== '' ? $ffl_ship->company : $ffl_ship->name, 40),
            'address1' => self::truncate_string($ffl_ship->address1, 40),
            'address2' => self::truncate_string($ffl_ship->address2, 40),
            'city'     => self::truncate_string($ffl_ship->city, 30),
            'state'    => self::format_us_state2_best_effort($ffl_ship->state),
            'zip'      => self::format_us_zip5_best_effort($ffl_ship->zip),
            'fflno'    => $fflno,
            'fflexp'   => $fflexp,
        ];

        if ($this->is_test_order_debug_enabled()) {
            $shipto_payload = [
                'username'    => (string) ($auth['payload']['username'] ?? ''),
                'password'    => (string) ($auth['payload']['password'] ?? ''),
                'addressinfo' => $addrinfo,
                'testing'     => (bool) $testing,
            ];

            $shipto_xml = $shipto_client->build_request_xml_preview(
                ZandersDirectShipAPI::OP_USE_SHIP_TO,
                $shipto_payload,
                ZandersDirectShipAPI::SHIPTO_NS_HTTPS,
                ['mode' => 'zanders_rpc_encoded']
            );

            $customer_name = '';
            $customer_phone = '';
            if ($request->ship_to_customer instanceof DistributorShipTo) {
                $customer_name = (string) $request->ship_to_customer->name;
                $customer_phone = (string) $request->ship_to_customer->phone;
            }

            // In test debug mode we do not call useShipTo(), so shipToNo is unknown here.
            $preview_ship_to_no = '__FROM_USESHIPTO__';
            $ship_instructions = self::build_fixed_80_ship_instructions($customer_name, $customer_phone);

            $order_map_preview = [
                'shipToNo'            => $preview_ship_to_no,
                'shipDate'            => $ship_date,
                'shipViaCode'         => 'UG',
                'shipInstructions'    => $ship_instructions,
                'purchaseOrderNumber' => $po,
                'items'               => $items,
            ];

            $create_payload = [
                'username' => (string) ($auth['payload']['username'] ?? ''),
                'password' => (string) ($auth['payload']['password'] ?? ''),
                'order'    => $order_map_preview,
                'testing'  => (bool) $testing,
            ];

            $create_xml = $orders_client->build_request_xml_preview(
                ZandersDirectShipAPI::OP_CREATE_ORDER,
                $create_payload,
                ZandersDirectShipAPI::ORDERS_NS_HTTPS,
                ['mode' => 'zanders_rpc_encoded']
            );

            return $this->build_test_order_debug_block(
                $lane,
                $this->strip_wsdl_suffix(ZandersDirectShipAPI::ORDERS_WSDL),
                'POST',
                'xml',
                $create_xml,
                [
                    'po' => $po,
                    'operation' => ZandersDirectShipAPI::OP_CREATE_ORDER,
                    'item_count' => count($items),
                    'debug_note' => 'createOrder preview uses placeholder shipToNo because useShipTo is not executed in test order debug mode.',
                    'debug_preflight_operation' => ZandersDirectShipAPI::OP_USE_SHIP_TO,
                    'debug_preflight_endpoint' => $this->strip_wsdl_suffix(ZandersDirectShipAPI::SHIPTO_WSDL),
                    'debug_request_body_preflight' => $shipto_xml,
                ],
                $external_ids
            );
        }


        $soap_shipto = ZandersDirectShipAPI::use_ship_to($shipto_client, $auth['payload'], $addrinfo, $testing);
        if (!($soap_shipto['ok'] ?? false)) {
            $r = $this->classify_zanders_transport_failure($soap_shipto, 'Zanders FFL useShipTo');
            $r->external_order_ids = $external_ids;
            return $r;
        }

        $shipto_norm = ZandersDirectShipAPI::normalize_use_ship_to_response($soap_shipto, 'Zanders FFL useShipTo');
        if (!($shipto_norm['ok'] ?? false)) {
            return DistributorOrderResult::block_fatal(
                'Zanders FFL useShipTo failed: ' . (string) ($shipto_norm['message'] ?? 'Unknown error'),
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                ['shipto' => $shipto_norm],
                0,
                '',
                $external_ids
            );
        }

        $shipToNo = (string) ($shipto_norm['ship_to_no'] ?? '');
        if ($shipToNo === '') {
            return DistributorOrderResult::block_fatal(
                'Zanders FFL: useShipTo returned empty ShipToNo.',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                ['shipto' => $shipto_norm],
                0,
                '',
                $external_ids
            );
        }

        if (!($request->ship_to_customer instanceof DistributorShipTo)) {
            return DistributorOrderResult::block_fatal(
                'Zanders FFL: missing ship_to_customer (needed for shipInstructions).',
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST],
                [],
                0,
                '',
                $external_ids
            );
        }

        $customer = $request->ship_to_customer;
        $ship_instructions = self::build_fixed_80_ship_instructions($customer->name, $customer->phone);

        $order_map = [
            'shipToNo'            => $shipToNo,
            'shipDate'            => $ship_date,
            'shipViaCode'         => 'UG',
            'shipInstructions'    => $ship_instructions,

            'purchaseOrderNumber' => $po,
            'items'               => $items,
        ];

        $soap = ZandersDirectShipAPI::create_order($orders_client, $auth['payload'], $order_map, $testing);
        if (!($soap['ok'] ?? false)) {
            $r = $this->classify_zanders_transport_failure($soap, 'Zanders direct-ship FFL');
            $r->external_order_ids = $external_ids;
            return $r;
        }

        $norm = ZandersDirectShipAPI::normalize_order_response($soap, 'Zanders direct-ship FFL');
        if (!($norm['ok'] ?? false)) {
            $r = $this->classify_zanders_order_failure($norm, 'Zanders direct-ship FFL');
            $r->external_order_ids = $external_ids;
            return $r;
        }

        $external_ids[] = (string) ($norm['order_number'] ?? '');
        return DistributorOrderResult::ok('Zanders direct-ship FFL order submitted.', $external_ids);
    }


    //END OF ORDERING SECTION

    /**
     * Zanders CA drop-ship orders must be handled manually.
     *
     * Dealer-fulfilled orders are intentionally not blocked here because they
     * ship to the dealer first, not directly to the CA recipient/transfer FFL.
     *
     * @param array<int,string> $external_ids
     */
    private function manual_result_if_ca_drop_ship_to_california(
        DistributorOrderRequest $request,
        string $lane,
        array $external_ids
    ): ?DistributorOrderResult {
        $lane = strtolower(trim((string) $lane));
        if ($lane !== 'direct_ship_non_ffl' && $lane !== 'direct_ship_ffl') {
            return null;
        }

        $ship_to = ($lane === 'direct_ship_ffl') ? $request->ship_to_ffl : $request->ship_to_customer;
        $state = ($ship_to instanceof DistributorShipTo)
            ? strtoupper(trim((string) $ship_to->state))
            : strtoupper(trim((string) $request->dest_state));

        if ($state !== 'CA') {
            return null;
        }

        return DistributorOrderResult::manual(
            'Zanders CA drop-ship order blocked: a manual order must be placed for CA drop orders.',
            [
                DistributorOrderResult::REASON_MANUAL_REQUIRED,
                'ZANDERS_CA_DROP_SHIP_MANUAL_REQUIRED',
            ],
            [
                'lane' => $lane,
                'ship_to_state' => $state,
            ],
            0,
            '',
            $external_ids
        );
    }


    /**
     * Decide which Zanders credential lane to use from our merchant PO encoding.
     *
     * Expected examples:
     *   FH-ZANDERS-6722-N1  => direct_ship_non_ffl
     *   FH-ZANDERS-6722-F1  => direct_ship_ffl
     *   FH-ZANDERS-6722-D1  => dealer_fulfilled
     *
     * Fallback: direct_ship_non_ffl (safe default) unless we explicitly detect ffl.
     */
    private static function infer_lane_from_po(string $po): string
    {

        $po = strtoupper(trim($po));
        if ($po === '') {
            return 'direct_ship_non_ffl';
        }

        // Split on '-' and look at the last token
        $parts = preg_split('/-+/', $po);
        $last  = is_array($parts) && !empty($parts) ? strtoupper((string) end($parts)) : '';

        // Your current encoding uses N1. Treat anything starting with N as direct-ship non-FFL.
        if ($last !== '' && preg_match('/^N\d*$/', $last)) {
            return 'direct_ship_non_ffl';
        }

        // Common encoding for direct-ship FFL lane.
        if ($last !== '' && preg_match('/^F\d*$/', $last)) {
            return 'direct_ship_ffl';
        }
        if ($last !== '' && preg_match('/^D\d*$/', $last)) {
            return 'dealer_fulfilled';
        }

        // Lane-oriented codes
        if ($last !== '' && preg_match('/^DSF\d*$/', $last)) {
            return 'direct_ship_ffl';
        }
        if ($last !== '' && preg_match('/^DSN\d*$/', $last)) {
            return 'direct_ship_non_ffl';
        }
        if ($last !== '' && preg_match('/^DSD\d*$/', $last)) {
            return 'dealer_fulfilled';
        }

        // Extra safety: if PO contains obvious marker anywhere
        if (strpos($po, '-FFL-') !== false || strpos($po, '_FFL_') !== false) {
            return 'direct_ship_ffl';
        }
        if (strpos($po, '-DEALER-') !== false || strpos($po, '_DEALER_') !== false || strpos($po, '-DF-') !== false) {
            return 'dealer_fulfilled';
        }

        return 'direct_ship_non_ffl';
    }


    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        $po_number = trim((string) $po_number);
        if ($po_number === '') {
            return null;
        }

        $external_ids = $this->lookup_external_order_ids_by_po($po_number);
        if (empty($external_ids)) {
            return null;
        }

        $testing       = $this->is_testing_mode();
        $orders_client = $this->make_orders_client();

        // Use PO encoding to select the correct credential lane.
        $lane = self::infer_lane_from_po($po_number);
        $this->log('Shipment poll: inferred lane', ['po' => $po_number, 'lane' => $lane, 'external_ids' => $external_ids]);

        $auth = $this->get_zanders_auth_for_lane($lane);
        $this->log('Shipment poll: auth selection', [
            'po'              => $po_number,
            'inferred_lane'   => $lane,
            'auth_lane'       => (string) ($auth['lane'] ?? ''),
            'username_key'    => (string) ($auth['username_key'] ?? ''),
            'password_key'    => (string) ($auth['password_key'] ?? ''),
            'username_masked' => self::mask_value_for_log((string) ($auth['payload']['username'] ?? '')),
            'auth_ok'         => (!empty($auth['ok']) ? 1 : 0),
        ]);
        if (!is_array($auth) || empty($auth['ok']) || empty($auth['payload']) || !is_array($auth['payload'])) {
            return null;
        }

        $tracking_numbers = [];
        $shipping_service = null;
        $shipping_weight  = null;
        $raw              = [];

        foreach ($external_ids as $order_number) {
            $order_number = trim((string) $order_number);
            if ($order_number === '') {
                continue;
            }

            $soap = ZandersDirectShipAPI::get_tracking_info(
                $orders_client,
                $auth['payload'],
                $order_number,
                $testing
            );

            $raw[] = [
                'orderNumber'  => $order_number,
                'inferredLane' => $lane,
                'authLane'     => (string) ($auth['lane'] ?? ''),
                'usernameKey'  => (string) ($auth['username_key'] ?? ''),
                'soap'         => $soap,
            ];

            if (empty($soap['ok'])) {
                continue;
            }

            $norm = ZandersDirectShipAPI::normalize_tracking_response($soap, 'Zanders getTrackingInfo');
            $raw[] = ['orderNumber' => $order_number, 'norm' => $norm];

            // If returnCode != 0, skip. (Prevents your returnCode=21 spam when creds are wrong.)
            if (empty($norm['ok'])) {
                continue;
            }

            foreach ((array) ($norm['tracking_numbers_flat'] ?? []) as $t) {
                $t = trim((string) $t);
                if ($t !== '') {
                    $tracking_numbers[] = $t;
                }
            }

            foreach ((array) ($norm['tracking_rows'] ?? []) as $r) {
                if (!is_array($r)) {
                    continue;
                }

                if ($shipping_service === null) {
                    $co  = trim((string) ($r['shipCompany'] ?? ''));
                    $sv  = trim((string) ($r['shipVia'] ?? ''));
                    $svc = trim($co . ($sv !== '' ? (' ' . $sv) : ''));

                    if ($svc !== '') {
                        // NEW: normalize to USPS/UPS/FEDEX if possible, otherwise keep raw
                        $carrier = $this->normalize_carrier($svc);
                        $shipping_service = $carrier !== null ? $carrier : $svc;
                    }
                }

                if ($shipping_weight === null) {
                    $w = trim((string) ($r['weight'] ?? ''));
                    if ($w !== '') {
                        $shipping_weight = $w;
                    }
                }

                if ($shipping_service !== null && $shipping_weight !== null) {
                    break;
                }
            }
        }

        $tracking_numbers = array_values(array_unique(array_filter($tracking_numbers)));
        sort($tracking_numbers, SORT_STRING);

        if (!empty($tracking_numbers)) {
            return new DistributorShipment(
                $tracking_numbers,
                [],
                $shipping_service,
                $shipping_weight,
                [
                    'po_number'    => $po_number,
                    'external_ids' => $external_ids,
                    'raw'          => $raw,
                    'lane'         => $lane,
                ]
            );
        }

        return null;
    }

    private static function mask_value_for_log(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (strpos($value, '@') !== false) {
            [$local, $domain] = array_pad(explode('@', $value, 2), 2, '');
            return self::mask_local_part($local) . ($domain !== '' ? ('@' . $domain) : '');
        }

        return self::mask_local_part($value);
    }

    private static function mask_local_part(string $part): string
    {
        $part = trim($part);
        $len  = strlen($part);
        if ($len <= 2) {
            return str_repeat('*', max(1, $len));
        }
        if ($len <= 4) {
            return substr($part, 0, 1) . str_repeat('*', $len - 2) . substr($part, -1);
        }

        return substr($part, 0, 2) . str_repeat('*', $len - 4) . substr($part, -2);
    }







    // ---------------------------------------------------------------------
    // Item mapping (UPC -> itemNumber) + payload shaping
    // ---------------------------------------------------------------------


    /**
     * @param DistributorOrderLine[] $lines
     * @param bool $require_non_empty When true, returns fatal result if no valid items map to item numbers.
     * @param bool $allow_backorder Whether items should be submitted with allowBackOrder=true.
     * @return array<int,array{itemNumber:string,quantity:int,allowBackOrder:bool}>|DistributorOrderResult
     */
    private function build_zanders_items(array $lines, bool $require_non_empty = false, bool $allow_backorder = false)
    {
        return $this->map_order_lines_to_items(
            $lines,
            function (string $normalized_upc, string $raw_upc, DistributorOrderLine $line): ?string {
                $item_no = $this->lookup_zanders_item_number_by_upc($normalized_upc);
                return $item_no !== '' ? $item_no : null;
            },
            function (string $item_no, int $qty, string $normalized_upc, string $raw_upc, DistributorOrderLine $line) use ($allow_backorder): array {
                return [
                    'itemNumber'     => $item_no,
                    'quantity'       => $qty,
                    'allowBackOrder' => $allow_backorder ? true : false,
                ];
            },
            'Zanders: cannot map UPC to itemNumber: %s',
            !$require_non_empty,
            'Zanders: no valid items after normalization.'
        );
    }


    private function lookup_zanders_item_number_by_upc(string $upc): string
    {
        $lookup = $this->get_fulfillment_row_for_upc($upc);
        if ($lookup === null) {
            return '';
        }

        $row = $lookup['row'];
        $item_no = $this->get_string_field($row, ['zanders_item_number']);
        $item_no = trim((string) $item_no);

        return $item_no;
    }

    /**
     * @return array{row:array<string,mixed>,normalized_upc:string}|null
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
        if (!is_array($row)) {
            if (!is_object($row)) {
                return null;
            }
            $row = get_object_vars($row);
        }

        return [
            'row' => $row,
            'normalized_upc' => $normalized_upc,
        ];
    }

    // ---------------------------------------------------------------------
    // Failure classification
    // ---------------------------------------------------------------------

    private function classify_zanders_transport_failure(array $soap, string $ctx): DistributorOrderResult
    {
        $http = (int) ($soap['http_status'] ?? 0);
        $msg  = (string) ($soap['message'] ?? 'SOAP call failed');

        // Heuristics: timeouts, DNS, TLS, 5xx, etc => retryable
        $m = strtolower($msg);
        $retryable =
            $http === 0 ||
            $http >= 500 ||
            strpos($m, 'timeout') !== false ||
            strpos($m, 'timed out') !== false ||
            strpos($m, 'could not resolve') !== false ||
            strpos($m, 'connection') !== false ||
            strpos($m, 'soap fault') !== false; // treat as retryable unless you learn otherwise

        if ($retryable) {
            return DistributorOrderResult::block_retryable(
                $ctx . ': ' . $msg,
                [DistributorOrderResult::REASON_RETRY_UNKNOWN],
                ['soap' => ['http' => $http, 'message' => $msg]]
            );
        }

        return DistributorOrderResult::block_fatal(
            $ctx . ': ' . $msg,
            [DistributorOrderResult::REASON_FATAL_UNKNOWN],
            ['soap' => ['http' => $http, 'message' => $msg]]
        );
    }

    private function classify_zanders_order_failure(array $norm, string $ctx): DistributorOrderResult
    {
        $code   = (int) ($norm['return_code'] ?? -1);
        $reason = (string) ($norm['reason'] ?? '');
        $removed = $norm['removed_items'] ?? [];

        // Zanders "returnCode=9" is commonly out-of-stock with removed items.
        // For now: treat as fatal (bad request / cannot fulfill) and include removed items.
        // If you later want partial-fill behavior, this is where you would change it.
        $msg = $ctx . ': Zanders order rejected (returnCode=' . $code . ')';
        if ($reason !== '') {
            $msg .= ' reason=' . $reason;
        }

        return DistributorOrderResult::block_fatal(
            $msg,
            [DistributorOrderResult::REASON_FATAL_UNKNOWN],
            [
                'return_code'   => $code,
                'reason'        => $reason,
                'removed_items' => is_array($removed) ? $removed : [],
                'raw'           => $norm['raw'] ?? null,
            ]
        );
    }


    /*
     * Zanders shipping rule:
     * - Free shipping when distributor cost is at or over $500
     * - Otherwise flat shipping
     */
    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $lookup = $this->get_fulfillment_row_for_upc($upc);
        if ($lookup === null) {
            return null;
        }

        $row = $lookup['row'];
        $distributor_cost = $this->money_to_float($this->get_string_field($row, ['distributor_price']));

        return $this->compute_shipping_cost_from_distributor_cost($distributor_cost);
    }

    /**
     * Build a normalized DistributorProductPayload for a UPC using the local Zanders fulfillment table.
     *
     * NOTE:
     * - No SOAP calls.
     * - Pricing: uses distributor_price (normalized from price1).
     */
    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        $lookup = $this->get_fulfillment_row_for_upc($upc);
        if ($lookup === null) {
            return null;
        }

        return $this->build_payload_from_row_zanders($lookup['row'], $lookup['normalized_upc'], true);
    }

    // Pricing payload variant excludes images for product sync workflows.
    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
        $lookup = $this->get_fulfillment_row_for_upc($upc);
        if ($lookup === null) {
            return null;
        }

        return $this->build_payload_from_row_zanders($lookup['row'], $lookup['normalized_upc'], false);
    }

    /**
     * @param array<int,string> $upcs
     * @return array<string,DistributorProductPayload>
     */
    public function get_pricing_payloads_by_upcs(array $upcs): array
    {
        $rows = $this->get_fulfillment_rows_by_upcs($upcs, false);
        if (empty($rows)) {
            return [];
        }

        $payloads = [];
        foreach ($rows as $normalized_upc => $row) {
            $payload = $this->build_payload_from_row_zanders($row, (string) $normalized_upc, false);
            if ($payload instanceof DistributorProductPayload) {
                $payloads[(string) $normalized_upc] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * Zanders-specific payload builder.
     *
     * Updates for new schema:
     * - price_1 -> distributor_price
     * - map_price -> retail_map
     * - msrp -> retail_msrp
     * - available -> inventory_quantity
     * - category -> item_type
     * - desc1/desc2 -> product_description
     * - ffl_required/sot_required now exist in-row
     *
     * @param array<string,mixed> $row
     */
    protected function build_payload_from_row_zanders(
        array $row,
        string $normalized_upc,
        bool $include_images = true
    ): DistributorProductPayload {
        // Helpers: parse money + parse int-ish strings safely.
        $money = static function ($v): float {
            $s = trim((string) $v);
            if ($s === '') {
                return 0.0;
            }
            $s = preg_replace('/[^0-9\.\-]/', '', $s);
            $s = is_string($s) ? $s : '';
            return $s === '' ? 0.0 : (float) $s;
        };

        $intish = static function ($v): int {
            $s = trim((string) $v);
            if ($s === '') {
                return 0;
            }
            // supports "10+", "Qty: 5", etc.
            $digits = preg_replace('/\D+/', '', $s);
            $digits = is_string($digits) ? $digits : '';
            return $digits === '' ? 0 : (int) $digits;
        };

        $to_boolish = static function ($v): bool {
            $s = strtoupper(trim((string) $v));
            return in_array($s, ['1', 'Y', 'YES', 'T', 'TRUE'], true);
        };

        $sku     = trim((string) ($this->get_string_field($row, ['zanders_item_number']) ?? ''));
        $upc_raw = $this->get_string_field($row, ['upc']) ?? $normalized_upc;
        $upc     = $this->normalize_upc((string) $upc_raw) ?? $normalized_upc;

        $manufacturer = trim((string) ($this->get_string_field($row, ['manufacturer']) ?? ''));
        $brand = $manufacturer;

        // New schema: description is already combined.
        $raw_desc = trim((string) ($this->get_string_field($row, ['product_description']) ?? ''));
        $raw_desc = trim((string) preg_replace('/\s+/', ' ', $raw_desc));

        // Build a stable "name" (avoid old desc1/desc2 fields).
        $name = trim((string) preg_replace('/\s+/', ' ', trim($manufacturer . ' ' . $raw_desc)));
        if ($name === '') {
            $name = $raw_desc;
        }

        $description = $raw_desc;

        // New schema pricing fields
        $price    = $money($this->get_string_field($row, ['distributor_price']));
        $mapPrice = $money($this->get_string_field($row, ['retail_map']));
        $msrp     = $money($this->get_string_field($row, ['retail_msrp']));

        // New schema inventory field
        $quantity = $intish($this->get_string_field($row, ['inventory_quantity']));
        $shipping_weight = $this->get_string_field($row, ['shipping_weight']);

        $shipping = $this->compute_shipping_cost_from_distributor_cost($price);
        $true_cost = $this->get_true_cost_by_distributor_cost_shipping_cost($price, $shipping);
        if ($true_cost === null) {
            $true_cost = $price + $shipping;
        }

        // New schema: category is item_type
        $item_type_raw = $this->get_string_field($row, ['item_type']);
        $recommended_category = null;
        if (is_string($item_type_raw) && trim($item_type_raw) !== '') {
            $recommended_category = \FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper::map_zanders($item_type_raw);
            if (!is_array($recommended_category)) {
                $recommended_category = null;
            }
        }

        $image = '';
        if ($include_images) {
            // field param unused for Zanders; pass null for clarity
            $image = $this->get_image_url_from_row($row, null);
            $image = is_string($image) ? trim($image) : '';
        }

        // New schema: this is now provided/derived at import time.
        $ffl_required = $to_boolish($this->get_string_field($row, ['ffl_required']) ?? '0');
        $sot_required = $to_boolish($this->get_string_field($row, ['sot_required']) ?? '0');
        $dropship_enabled = $to_boolish($this->get_string_field($row, ['dropship_enabled']) ?? '1');
        $sig_check_row = $row;
        if ($brand !== '') {
            $sig_check_row['brand'] = $brand;
        }
        if (SigDropshipApproval::should_force_row($this->get_id(), $sig_check_row)) {
            $dropship_enabled = true;
        }

        return new DistributorProductPayload(
            $upc,
            $sku,
            $name,
            $description,
            $price,
            $mapPrice,
            $msrp,
            $quantity,
            $shipping,
            (float) $true_cost,
            $image,
            $ffl_required,
            $dropship_enabled,
            $recommended_category,
            $row,
            $shipping_weight,
            $sot_required,
            null,
            null,
            null,
            $brand
        );
    }

    private function compute_shipping_cost_from_distributor_cost(float $distributor_cost): float
    {
        if ($distributor_cost >= self::FREE_SHIPPING_DISTRIBUTOR_COST_THRESHOLD) {
            return 0.0;
        }

        return self::FLAT_SHIPPING_COST;
    }

    /**
     * Parses mixed money-like values into a float.
     *
     * Supports values like "$1,234.56" as well as plain numeric strings.
     *
     * @param mixed $value
     */
    private function money_to_float($value): float
    {
        $s = trim((string) $value);
        if ($s === '') {
            return 0.0;
        }

        $s = preg_replace('/[^0-9\.\-]/', '', $s);
        $s = is_string($s) ? $s : '';

        return ($s === '') ? 0.0 : (float) $s;
    }

    /**
     * Zanders image resolver (FTP-cached).
     *
     * Remote:
     *   /Inventory/Images_2/{zanders_item_number}.jpg
     *
     * Local cache:
     *   wp-content/uploads/fflhub-zanders/images/{zanders_item_number}.jpg
     *
     * @param array<string,mixed> $row
     * @param mixed              $field Unused for Zanders
     */
    protected function get_image_url_from_row(array $row, $field): string
    {
        $item_no = trim((string) ($this->get_string_field($row, ['zanders_item_number']) ?? ''));
        if ($item_no === '') {
            $this->log('Image: missing zanders_item_number');
            return '';
        }

        $remote_path = $this->build_zanders_remote_image_path($item_no);
        if ($remote_path === '') {
            $this->log('Image: failed to build remote path', ['item_no' => $item_no]);
            return '';
        }

        $missing_key = 'fflhub_zanders_img_missing_' . md5($remote_path);
        if (get_transient($missing_key)) {
            $this->log('Image: skip (known missing)', ['remote' => $remote_path, 'item_no' => $item_no]);
            return '';
        }

        $uploads = wp_upload_dir();
        $subdir  = 'fflhub-zanders/images';
        $dir     = rtrim((string) ($uploads['basedir'] ?? ''), '/\\') . '/' . $subdir;

        if ($dir === '' || empty($uploads['baseurl'])) {
            $this->log('Image: uploads dir/baseurl missing', ['basedir' => $uploads['basedir'] ?? '', 'baseurl' => $uploads['baseurl'] ?? '']);
            return '';
        }

        $safe_item = preg_replace('/[^A-Za-z0-9_\-\.]+/', '', $item_no);
        $safe_item = is_string($safe_item) ? $safe_item : '';
        if ($safe_item === '') {
            $safe_item = 'zanders';
        }

        $filename   = $safe_item . '.jpg';
        $local_path = $dir . '/' . $filename;
        $public_url = rtrim((string) $uploads['baseurl'], '/\\') . '/' . $subdir . '/' . rawurlencode($filename);

        // CACHE HIT
        if (is_file($local_path) && filesize($local_path) > 1024) {
            $this->log('Image: cache hit', [
                'item_no' => $item_no,
                'local'   => $local_path,
                'bytes'   => (int) filesize($local_path),
            ]);
            return $public_url;
        }

        $lock_key = 'fflhub_zanders_img_dl_' . md5($remote_path);
        if (get_transient($lock_key)) {
            $this->log('Image: skip (download lock active)', ['remote' => $remote_path, 'item_no' => $item_no]);
            return '';
        }
        set_transient($lock_key, 1, 10 * MINUTE_IN_SECONDS);

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            $this->log('Image: failed to create cache dir', ['dir' => $dir]);
            return '';
        }

        $creds = $this->get_ftp_credentials();
        if (!$creds) {
            $this->log('Image: missing FTP creds');
            return '';
        }

        $this->log('Image: cache miss, attempting download', [
            'remote' => $remote_path,
            'local'  => $local_path,
            'item_no' => $item_no,
        ]);

        try {
            $ftp = new FTPClientService(
                $creds['host'],
                $creds['username'],
                $creds['password'],
                (bool) $creds['use_ssl'],
                (int) $creds['port'],
                30,
                true,
                '[FFLHub][ZandersFTP]'
            );

            if (!$ftp->is_connected()) {
                $this->log('Image: FTP not connected', ['host' => $creds['host']]);
                return '';
            }

            $remote_size = $ftp->get_remote_size($remote_path);

            // REMOTE MISSING
            if ($remote_size <= 0) {
                set_transient($missing_key, 1, DAY_IN_SECONDS);
                $this->log('Image: remote missing (cached)', [
                    'remote' => $remote_path,
                    'size'   => $remote_size,
                ]);
                return '';
            }

            $this->log('Image: remote exists, downloading', ['remote' => $remote_path, 'size' => $remote_size]);

            $ok = $ftp->download_file($remote_path, $local_path);
            if (!$ok) {
                $this->log('Image: download failed', [
                    'remote' => $remote_path,
                    'local'  => $local_path,
                    'err'    => $ftp->get_last_error(),
                ]);
                return '';
            }
        } catch (\Throwable $e) {
            $this->log('Image: exception during download', ['remote' => $remote_path, 'err' => $e->getMessage()]);
            return '';
        }

        // DOWNLOAD SUCCESS
        if (is_file($local_path) && filesize($local_path) > 1024) {
            $this->log('Image: download success', [
                'local' => $local_path,
                'bytes' => (int) filesize($local_path),
            ]);
            return $public_url;
        }

        $this->log('Image: downloaded file invalid/tiny', [
            'local' => $local_path,
            'bytes' => is_file($local_path) ? (int) filesize($local_path) : 0,
        ]);

        return '';
    }

    private function build_zanders_remote_image_path(string $item_no): string
    {
        $item_no = trim($item_no);
        if ($item_no === '') {
            return '';
        }

        // Example: /Inventory/Images_2/00061.jpg
        return '/Inventory/Images_2/' . $item_no . '.jpg';
    }

    private function strip_wsdl_suffix(string $url): string
    {
        return (string) preg_replace('/\?wsdl$/i', '', trim($url));
    }

    /**
     * Retrieve and validate FTP credentials from Zanders distributor settings.
     *
     * Note: Zanders is FTP (no TLS). We enforce use_ssl=false and port=21 defaults.
     *
     * @return array{host:string,username:string,password:string,use_ssl:bool,port:int}|null
     */
    private function get_ftp_credentials(): ?array
    {
        $creds = ZandersFtpCredentials::get();
        if (!is_array($creds)) {
            DebugLogUtil::log('FFLHUB_CRON_DEBUG', '[FFLHub][ZandersDistributor]', 'Missing FTP credentials');
            return null;
        }

        return $creds;
    }

    /** @param array<string,mixed> $ctx */
    private function log(string $msg, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $msg);
            return;
        }

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $msg, $ctx);
    }






    /**
     * Resolve FFL expiration date for Zanders useShipTo (fflexp).
     *
     * Returns '' when missing/unparseable so caller can block_fatal.
     */
    private function resolve_fflexp_for_request(DistributorOrderRequest $request): string
    {
        $ffl_full = strtoupper(trim((string) $request->receiving_ffl_number));
        if ($ffl_full === '') {
            return '';
        }

        if (!($this->services instanceof \FFLHub\Distributor\Services\Zanders\ZandersServices)) {
            $this->log('resolve_fflexp_for_request: services not ZandersServices');
            return '';
        }

        $fflTable = $this->services->get_ffl_table(); // must return \FFLHub\FFL\Tables\FFLTable

        $exp = FFLRepository::get_expiration_by_number($fflTable, $ffl_full);

        if ($exp === '') {
            $this->log('resolve_fflexp_for_request: missing expiration for FFL', ['ffl' => $ffl_full]);
        }

        return $exp; // YYYY-MM-DD or ''
    }


    /**
     * @return string[]
     */
    private function lookup_external_order_ids_by_po(string $po_number): array
    {
        global $wpdb;

        $po_number = trim((string) $po_number);
        if ($po_number === '') {
            return [];
        }

        if (!($this->services instanceof \FFLHub\Distributor\Services\Zanders\ZandersServices)) {
            return [];
        }

        $order_table = $this->services->get_order_table();
        $table = $order_table->get_table_name();

        $sql = $wpdb->prepare(
            "SELECT external_order_ids_json, external_order_id, place_result_json
         FROM {$table}
         WHERE merchant_po = %s AND dist_id = %s
         ORDER BY id DESC
         LIMIT 10",
            $po_number,
            'zanders'
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }

            // 1) Highest priority: external_order_id
            $single = trim((string) ($r['external_order_id'] ?? ''));
            if ($single !== '') {
                return [$single];
            }

            // 2) Next: external_order_ids_json
            $ids_json = trim((string) ($r['external_order_ids_json'] ?? ''));
            if ($ids_json !== '') {
                $decoded = json_decode($ids_json, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $id) {
                        $id = trim((string) $id);
                        if ($id !== '') {
                            return [$id];
                        }
                    }
                }
            }

            // 3) Lowest: place_result_json.ext_ids
            $place_json = trim((string) ($r['place_result_json'] ?? ''));
            if ($place_json !== '') {
                $p = json_decode($place_json, true);
                if (is_array($p) && isset($p['ext_ids']) && is_array($p['ext_ids'])) {
                    foreach ($p['ext_ids'] as $id) {
                        $id = trim((string) $id);
                        if ($id !== '') {
                            return [$id];
                        }
                    }
                }
            }
        }

        return [];
    }
}

