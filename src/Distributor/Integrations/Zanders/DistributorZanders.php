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
use FFLHub\Distributor\Services\Zanders\API\ZandersSoapCurlClient;
use FFLHub\Distributor\Services\Zanders\ZandersServices;
use FFLHub\FFL\Data\FFLRepository;
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

    /**
     * Guardrail: prevent pathological carts from causing heavy DB lookups.
     */
    private const VALIDATE_MAX_UNIQUE_ITEMS = 75;

    public function __construct(DistributorModuleInterface $module, $services = null)
    {
        parent::__construct($module, $services);
    }



    //VALIDATION SECTION

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
        // Bucket + enforcement is shared in base helper you added.
        $b = $this->infer_bucket_and_ffl_enforcement($request);

        return [
            'label'                => 'Zanders validation (local)',
            'max_unique'           => self::VALIDATE_MAX_UNIQUE_ITEMS,
            'inventory_keys'       => ['inventory_quantity'],
            'unknown_qty_blocks'   => true,

            'bucket'               => $b['bucket'],
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
        // 1) wp-config constant wins
        if (defined('FFLHUB_ZANDERS_TESTING') && FFLHUB_ZANDERS_TESTING) {
            return true;
        }

        // 2) env var
        $env = getenv('FFLHUB_ZANDERS_TESTING');
        if ($env !== false && $env !== '' && $env !== '0') {
            return true;
        }

        // 3) option (if you add it later)
        $opt = (string) \FFLHub\Settings\Options::get_distributor_option('zanders', 'testing_mode', '0');
        if ($opt === '1' || strtoupper($opt) === 'Y' || strtoupper($opt) === 'TRUE') {
            return true;
        }

        // 4) filter fallback
        return (bool) apply_filters('fflhub_zanders_testing_mode', false);
    }


    /**
     * @return array{ok:bool,message:string,payload:array{username?:string,password?:string}}
     */
    private function get_zanders_auth_for_bucket(string $bucket): array
    {
        $bucket = ($bucket === 'ffl') ? 'ffl' : 'non';

        $u_key = ($bucket === 'ffl') ? 'gun_username' : 'accessory_username';
        $p_key = ($bucket === 'ffl') ? 'gun_password' : 'accessory_password';

        $u = trim((string) \FFLHub\Settings\Options::get_distributor_option('zanders', $u_key, ''));
        $p = trim((string) \FFLHub\Settings\Options::get_distributor_option('zanders', $p_key, ''));

        if ($u === '' || $p === '') {
            return [
                'ok' => false,
                'message' => "Missing Zanders SOAP creds for bucket={$bucket} (keys: {$u_key}/{$p_key}).",
                'payload' => [],
            ];
        }

        return [
            'ok' => true,
            'message' => 'OK',
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

        return null;
    }


    protected function place_order_bucket(
        DistributorOrderRequest $request,
        string $bucket,
        array $lines,
        array &$external_ids
    ): DistributorOrderResult {
        $auth = $this->get_zanders_auth_for_bucket($bucket);
        if (empty($auth['ok'])) {
            $r = DistributorOrderResult::block_fatal(
                'Zanders: missing credentials for bucket=' . $bucket,
                [DistributorOrderResult::REASON_FATAL_MISSING_CREDS],
                ['auth' => ['message' => (string)($auth['message'] ?? '')]]
            );
            $r->external_order_ids = $external_ids;
            return $r;
        }
        $testing = $this->is_testing_mode();

        $orders_client = $this->make_orders_client();
        $shipto_client = $this->make_shipto_client();


        $po = $this->sanitize_and_truncate_po((string) $request->merchant_order_id, 22);


        $ship_date = (string) apply_filters('fflhub_zanders_ship_date', gmdate('Y-m-d'), $request);

        $items = $this->build_zanders_items($lines);
        if ($items instanceof DistributorOrderResult) {
            $items->external_order_ids = $external_ids;
            return $items;
        }

        if ($bucket === 'non') {
            if (!($request->ship_to_customer instanceof DistributorShipTo)) {
                return DistributorOrderResult::block_fatal(
                    'Zanders NON: missing ship_to_customer (ship-to address required).',
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
                    'Zanders NON: ' . (string) ($ship_check['message'] ?? 'Invalid ship-to.'),
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

            $soap = ZandersDirectShipAPI::create_order($orders_client, $auth['payload'], $order_map, $testing);
            if (!($soap['ok'] ?? false)) {
                $r = $this->classify_zanders_transport_failure($soap, 'Zanders NON');
                $r->external_order_ids = $external_ids;
                return $r;
            }

            $norm = ZandersDirectShipAPI::normalize_order_response($soap, 'Zanders NON');
            if (!($norm['ok'] ?? false)) {
                $r = $this->classify_zanders_order_failure($norm, 'Zanders NON');
                $r->external_order_ids = $external_ids;
                return $r;
            }

            $external_ids[] = (string) ($norm['order_number'] ?? '');
            return DistributorOrderResult::ok('Zanders NON order submitted.', $external_ids);
        }

        // bucket === 'ffl'
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
            $r = $this->classify_zanders_transport_failure($soap, 'Zanders FFL');
            $r->external_order_ids = $external_ids;
            return $r;
        }

        $norm = ZandersDirectShipAPI::normalize_order_response($soap, 'Zanders FFL');
        if (!($norm['ok'] ?? false)) {
            $r = $this->classify_zanders_order_failure($norm, 'Zanders FFL');
            $r->external_order_ids = $external_ids;
            return $r;
        }

        $external_ids[] = (string) ($norm['order_number'] ?? '');
        return DistributorOrderResult::ok('Zanders FFL order submitted.', $external_ids);
    }


    //END OF ORDERING SECTION


    /**
     * Decide which Zanders credential bucket to use from our merchant PO encoding.
     *
     * Expected examples:
     *   FH-ZANDERS-6722-N1  => non
     *   FH-ZANDERS-6722-F1  => ffl
     *
     * Fallback: 'non' (safe default) unless we explicitly detect ffl.
     */
    private static function infer_bucket_from_po(string $po): string
    {

        $po = strtoupper(trim($po));
        if ($po === '') {
            return 'non';
        }

        // Split on '-' and look at the last token
        $parts = preg_split('/-+/', $po);
        $last  = is_array($parts) && !empty($parts) ? strtoupper((string) end($parts)) : '';

        // Your current encoding uses N1. We'll treat anything starting with 'N' as non.
        if ($last !== '' && preg_match('/^N\d*$/', $last)) {
            return 'non';
        }

        // Common encoding for firearms bucket
        if ($last !== '' && preg_match('/^F\d*$/', $last)) {
            return 'ffl';
        }

        // Extra safety: if PO contains obvious marker anywhere
        if (strpos($po, '-FFL-') !== false || strpos($po, '_FFL_') !== false) {
            return 'ffl';
        }

        return 'non';
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

        // Use PO encoding to select the correct credential bucket (non vs ffl).
        $bucket = self::infer_bucket_from_po($po_number);
        $this->log('Shipment poll: inferred bucket', ['po' => $po_number, 'bucket' => $bucket, 'external_ids' => $external_ids]);

        $auth = $this->get_zanders_auth_for_bucket($bucket);
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

            $raw[] = ['orderNumber' => $order_number, 'soap' => $soap];

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
                    'bucket'       => $bucket,
                ]
            );
        }

        return null;
    }







    // ---------------------------------------------------------------------
    // Item mapping (UPC -> itemNumber) + payload shaping
    // ---------------------------------------------------------------------


    /**
     * @param DistributorOrderLine[] $lines
     * @param bool $allow_empty
     * @return array<int,array{itemNumber:string,quantity:int,allowBackOrder:string}>|DistributorOrderResult
     */
    private function build_zanders_items(array $lines, bool $require_non_empty  = false)
    {
        return $this->map_order_lines_to_items(
            $lines,
            function (string $normalized_upc, string $raw_upc, DistributorOrderLine $line): ?string {
                $item_no = $this->lookup_zanders_item_number_by_upc($normalized_upc);
                return $item_no !== '' ? $item_no : null;
            },
            function (string $item_no, int $qty, string $normalized_upc, string $raw_upc, DistributorOrderLine $line): array {
                return [
                    'itemNumber'     => $item_no,
                    'quantity'       => $qty,
                    'allowBackOrder' => 'false',
                ];
            },
            'Zanders: cannot map UPC to itemNumber: %s',
            !$require_non_empty,
            'Zanders: no valid items after normalization.'
        );
    }


    private function lookup_zanders_item_number_by_upc(string $upc): string
    {
        // TODO: wire to your Zanders fulfillment table / repo.
        // Example idea:
        // return (string) $this->services->zanders()->fulfillment_repo()->get_itemnumber_by_upc($upc);
        if (!$this->services) {
            return '';
        }

        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return '';
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized);
        if (!$row) {
            return '';
        }

        $item_no = $this->get_string_field($row, ['zanders_item_number']);
        $item_no = trim((string) $item_no);

        return $item_no;
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

        // Zanders “returnCode=9” is commonly out-of-stock with removed items.
        // For now: treat as fatal (bad request / cannot fulfill) and include removed items.
        // If you later want partial-fill behavior, this is where you’d change it.
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
     * For Zanders, its $15 no matter what
     */
    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }
        return 15.0;
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
        if (!$this->services) {
            return null;
        }

        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
        if (!$row || !is_array($row)) {
            return null;
        }

        return $this->build_payload_from_row_zanders($row, $normalized_upc, true);
    }

    // same as above, but exclude image for our product syncing
    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
        if (!$this->services) {
            return null;
        }

        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
        if (!$row || !is_array($row)) {
            return null;
        }

        return $this->build_payload_from_row_zanders($row, $normalized_upc, false);
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

        $shipping  = (float) ($this->get_shipping_cost_by_upc($normalized_upc) ?? 0.0);
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
            $recommended_category,
            $row
        );
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

        // ✅ CACHE HIT
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

            // ✅ REMOTE MISSING
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

        // ✅ DOWNLOAD SUCCESS
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

    /**
     * Retrieve and validate FTP credentials from Zanders distributor settings.
     *
     * Note: Zanders is FTP (no TLS). We enforce use_ssl=false and port=21 defaults.
     *
     * @return array{host:string,username:string,password:string,use_ssl:bool,port:int}|null
     */
    private function get_ftp_credentials(): ?array
    {
        $host     = \FFLHub\Settings\Options::get_distributor_option('zanders', 'ftp_host', '');
        $username = \FFLHub\Settings\Options::get_distributor_option('zanders', 'ftp_username', '');
        $password = \FFLHub\Settings\Options::get_distributor_option('zanders', 'ftp_password', '');

        $host     = trim((string) $host);
        $username = trim((string) $username);
        $password = trim((string) $password);

        if ($host === '' || $username === '' || $password === '') {
            if (defined('FFLHUB_CRON_DEBUG') && FFLHUB_CRON_DEBUG === true) {
                error_log('[FFLHub][ZandersDistributor] Missing FTP credentials');
            }
            return null;
        }

        return [
            'host'     => $host,
            'username' => $username,
            'password' => $password,
            'use_ssl'  => false,
            'port'     => 21,
        ];
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
    /**
     * @return string[]
     */
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
