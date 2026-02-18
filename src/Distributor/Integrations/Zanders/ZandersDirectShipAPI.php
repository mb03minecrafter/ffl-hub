<?php

declare(strict_types=1);

namespace FFLHub\Distributor\Integrations\Zanders;

use FFLHub\Distributor\Services\Zanders\API\ZandersSoapCurlClient;

if (!defined('ABSPATH')) {
    exit;
}

final class ZandersDirectShipAPI
{
    /** Toggle API-level debug logs */
    public const DEBUG_FLAG = 'FFLHUB_ZANDERS_SOAP_DEBUG';

    public const ORDERS_WSDL = 'https://shop2.gzanders.com/webservice/orders?wsdl';
    public const SHIPTO_WSDL = 'https://shop2.gzanders.com/webservice/shiptoaddresses?wsdl';

    public const ORDERS_NS_HTTP  = 'http://shop2.gzanders.com/webservice/orders';
    public const ORDERS_NS_HTTPS = 'https://shop2.gzanders.com/webservice/orders';
    public const SHIPTO_NS_HTTPS = 'https://shop2.gzanders.com/webservice/shiptoaddresses';

    public const OP_CREATE_ORDER      = 'createOrder';
    public const OP_EDIT_ORDER        = 'editOrder';
    public const OP_GET_TRACKING_INFO = 'getTrackingInfo';
    public const OP_USE_SHIP_TO       = 'useShipTo';

    public static function create_order(
        ZandersSoapCurlClient $client,
        array $auth,
        array $order,
        bool $testing
    ): array {
        // NOTE: Zanders expects order as ns2:Map and items as enc:Array of ns2:Map. :contentReference[oaicite:3]{index=3}
        $payload = [
            'username' => (string) ($auth['username'] ?? ''),
            'password' => (string) ($auth['password'] ?? ''),
            'order'    => $order,
            'testing'  => (bool) $testing,
        ];

        self::dbg('create_order request', [
            'testing' => $testing ? 1 : 0,
            'keys'    => array_keys($order),
            'items_n' => is_array($order['items'] ?? null) ? count($order['items']) : 0,
        ]);

        $res = $client->call(
            self::OP_CREATE_ORDER,
            self::OP_CREATE_ORDER,
            $payload,
            self::ORDERS_NS_HTTPS,
            [],
            ['mode' => 'zanders_rpc_encoded']
        );

        self::dbg('create_order response', self::summarize_client_result($res));

        return $res;
    }

    public static function edit_order(
        ZandersSoapCurlClient $client,
        array $auth,
        array $order_patch,
        bool $testing
    ): array {
        $payload = [
            'username' => (string) ($auth['username'] ?? ''),
            'password' => (string) ($auth['password'] ?? ''),
            'order'    => $order_patch,
            'testing'  => (bool) $testing,
        ];

        self::dbg('edit_order request', [
            'testing' => $testing ? 1 : 0,
            'keys'    => array_keys($order_patch),
        ]);

        $res = $client->call(
            self::OP_EDIT_ORDER,
            self::OP_EDIT_ORDER,
            $payload,
            self::ORDERS_NS_HTTPS,
            [],
            ['mode' => 'zanders_rpc_encoded']
        );

        self::dbg('edit_order response', self::summarize_client_result($res));

        return $res;
    }

    public static function get_tracking_info(
        ZandersSoapCurlClient $client,
        array $auth,
        string $order_number,
        bool $testing
    ): array {
        $payload = [
            'username'    => (string) ($auth['username'] ?? ''),
            'password'    => (string) ($auth['password'] ?? ''),
            'ordernumber' => (string) $order_number,
            'testing'     => (bool) $testing,
        ];

        self::dbg('get_tracking_info request', [
            'testing'      => $testing ? 1 : 0,
            'order_number' => $order_number,
        ]);

        $res = $client->call(
            self::OP_GET_TRACKING_INFO,
            self::OP_GET_TRACKING_INFO,
            $payload,
            self::ORDERS_NS_HTTPS,
            [],
            ['mode' => 'zanders_rpc_encoded']
        );

        self::dbg('get_tracking_info response', self::summarize_client_result($res));

        return $res;
    }

    public static function use_ship_to(
        ZandersSoapCurlClient $client,
        array $auth,
        array $address_info,
        bool $testing
    ): array {
        $payload = [
            'username'    => (string) ($auth['username'] ?? ''),
            'password'    => (string) ($auth['password'] ?? ''),
            'addressinfo' => $address_info,
            'testing'     => (bool) $testing,
        ];

        self::dbg('use_ship_to request', [
            'testing' => $testing ? 1 : 0,
            'keys'    => array_keys($address_info),
        ]);

        $res = $client->call(
            self::OP_USE_SHIP_TO,
            self::OP_USE_SHIP_TO,
            $payload,
            self::SHIPTO_NS_HTTPS,
            [],
            ['mode' => 'zanders_rpc_encoded']
        );

        self::dbg('use_ship_to response', self::summarize_client_result($res));

        return $res;
    }

    // ---------------------------------------------------------------------
    // Normalizers (unchanged)
    // ---------------------------------------------------------------------

    public static function normalize_order_response(array $soap_res, string $context = ''): array
    {
        $base = self::normalize_map_style_response($soap_res, $context);

        $returnCode = (int) ($base['map']['returnCode'] ?? -1);
        $orderNum   = (string) ($base['map']['orderNumber'] ?? '');

        $reason  = (string) ($base['map']['reason'] ?? '');
        $removed = $base['map']['removedItems'] ?? [];

        $ok = ($base['ok'] === true && $returnCode === 0);

        return [
            'ok'            => $ok,
            'return_code'   => $returnCode,
            'order_number'  => $orderNum,
            'reason'        => $reason,
            'removed_items' => is_array($removed) ? $removed : [],
            'message'       => $ok ? self::ctx($context, 'OK') : self::ctx($context, $base['message']),
            'raw'           => $base['raw'],
        ];
    }

    public static function normalize_use_ship_to_response(array $soap_res, string $context = ''): array
    {
        $base = self::normalize_map_style_response($soap_res, $context);

        $returnCode = (int) ($base['map']['returnCode'] ?? -1);

        $shipTo = '';
        if ($returnCode === 0) {
            $shipTo = (string) self::dig($base['map'], ['shipToAddress', 'ShipToNo'], '');
        } else {
            $shipTo = (string) self::dig($base['map'], ['searchResults', 'ShipToNo'], '');
        }

        $reason = (string) ($base['map']['reason'] ?? '');

        $ok = ($base['ok'] === true && $shipTo !== '');

        return [
            'ok'          => $ok,
            'return_code' => $returnCode,
            'ship_to_no'  => $shipTo,
            'reason'      => $reason,
            'message'     => $ok ? self::ctx($context, 'OK') : self::ctx($context, $base['message']),
            'raw'         => $base['raw'],
        ];
    }

    public static function normalize_tracking_response(array $soap_res, string $context = ''): array
    {
        $base = self::normalize_map_style_response($soap_res, $context);

        $returnCode = (int) ($base['map']['returnCode'] ?? -1);
        $numShip    = (int) ($base['map']['numberOfShipments'] ?? 0);

        $rowsRaw = $base['map']['trackingNumbers'] ?? [];
        $rows    = [];

        if (is_array($rowsRaw)) {
            if (isset($rowsRaw['item'])) {
                $items = $rowsRaw['item'];
                $items_list = self::is_list($items) ? $items : [$items];

                foreach ($items_list as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    $rows[] = self::maybe_map_to_assoc($it);
                }
            } else {
                if (self::is_list($rowsRaw)) {
                    foreach ($rowsRaw as $row) {
                        if (is_array($row)) {
                            $rows[] = self::maybe_map_to_assoc($row);
                        }
                    }
                } else {
                    $rows[] = self::maybe_map_to_assoc($rowsRaw);
                }
            }
        }

        $tracking_numbers = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $t = trim((string) ($r['trackingNumber'] ?? ''));
            if ($t !== '') {
                $tracking_numbers[] = $t;
            }
        }
        $tracking_numbers = array_values(array_unique($tracking_numbers));
        sort($tracking_numbers, SORT_STRING);

        $ok = ($base['ok'] === true && $returnCode === 0);

        return [
            'ok'                    => $ok,
            'return_code'           => $returnCode,
            'number_of_shipments'   => $numShip,
            'tracking_rows'         => $rows,
            'tracking_numbers_flat' => $tracking_numbers,
            'message'               => $ok ? self::ctx($context, 'OK') : self::ctx($context, $base['message']),
            'raw'                   => $base['raw'],
        ];
    }

    // ---------------------------------------------------------------------
    // Map-style response helpers
    // ---------------------------------------------------------------------

    private static function normalize_map_style_response(array $soap_res, string $context): array
    {
        if (!($soap_res['ok'] ?? false)) {
            $fault = $soap_res['fault'] ?? null;
            $msg   = (string) ($soap_res['message'] ?? 'SOAP call failed');

            if (is_array($fault) && !empty($fault['faultstring'])) {
                $msg = 'Zanders SOAP fault: ' . (string) $fault['faultstring'];
            }

            self::dbg('normalize_map_style_response (fail)', [
                'context' => $context,
                'message' => $msg,
                'http'    => (int) ($soap_res['http_status'] ?? 0),
                'fault'   => $fault,
                'raw_head' => isset($soap_res['raw']) ? substr((string) $soap_res['raw'], 0, 300) : '',
            ]);

            return [
                'ok'      => false,
                'message' => self::ctx($context, $msg),
                'map'     => [],
                'raw'     => is_array($soap_res['parsed'] ?? null) ? $soap_res['parsed'] : null,
            ];
        }

        $body = $soap_res['parsed'] ?? null;


        self::dbg('normalize_map_style_response parsed body', [
            'context' => $context,
            'parsed_type' => is_array($body) ? 'array' : (is_string($body) ? 'string' : 'null'),
            'top_keys' => is_array($body) ? array_keys($body) : [],
        ]);


        $returnNode = null;
        if (is_array($body)) {
            $returnNode = self::find_first_key_recursive($body, 'return');
            if ($returnNode === null) {
                $returnNode = $body;
            }
        }

        $map = [];
        if (is_array($returnNode)) {
            $map = self::maybe_map_to_assoc($returnNode);
        }

        $returnCode = (int) ($map['returnCode'] ?? -1);
        $reason     = (string) ($map['reason'] ?? '');

        $msg = 'OK';
        if ($returnCode !== 0) {
            $msg = 'Zanders returnCode=' . $returnCode;
            if ($reason !== '') {
                $msg .= ' reason=' . $reason;
            }
        }

        self::dbg('normalize_map_style_response (ok)', [
            'context'    => $context,
            'returnCode' => $returnCode,
            'has_order'  => isset($map['orderNumber']) ? 1 : 0,
            'has_shipto' => isset($map['shipToAddress']) || isset($map['searchResults']) ? 1 : 0,
            'msg'        => $msg,
        ]);

        return [
            'ok'      => true,
            'message' => self::ctx($context, $msg),
            'map'     => $map,
            'raw'     => is_array($body) ? $body : null,
        ];
    }

    private static function maybe_map_to_assoc(array $node): array
    {
        if (isset($node['item'])) {
            return self::soap_map_to_assoc($node);
        }
        if (self::looks_like_kv_items_list($node)) {
            return self::soap_kv_items_list_to_assoc($node);
        }
        return $node;
    }

    private static function soap_map_to_assoc(array $mapNode): array
    {
        $items = $mapNode['item'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $out  = [];
        $list = self::is_list($items) ? $items : [$items];

        foreach ($list as $it) {
            if (!is_array($it)) {
                continue;
            }

            $k = isset($it['key']) ? (string) $it['key'] : '';
            if ($k === '') {
                continue;
            }

            $v = $it['value'] ?? '';

            if (is_array($v)) {
                if (isset($v['item'])) {
                    $out[$k] = self::soap_map_to_assoc($v);
                } elseif (isset($v['item']) || isset($v['@xsi:type'])) {
                    $out[$k] = self::maybe_map_to_assoc($v);
                } elseif (self::is_list($v)) {
                    $norm = [];
                    foreach ($v as $row) {
                        $norm[] = is_array($row) ? self::maybe_map_to_assoc($row) : (string) $row;
                    }
                    $out[$k] = $norm;
                } else {
                    if (isset($v['item'])) {
                        $inner     = $v['item'];
                        $innerList = self::is_list($inner) ? $inner : [$inner];
                        $norm      = [];
                        foreach ($innerList as $row) {
                            $norm[] = is_array($row) ? self::maybe_map_to_assoc($row) : (string) $row;
                        }
                        $out[$k] = $norm;
                    } else {
                        $out[$k] = $v;
                    }
                }
            } else {
                $out[$k] = (string) $v;
            }
        }

        return $out;
    }

    private static function looks_like_kv_items_list(array $node): bool
    {
        if (!self::is_list($node) || empty($node)) {
            return false;
        }
        $first = $node[0] ?? null;
        return is_array($first) && array_key_exists('key', $first) && array_key_exists('value', $first);
    }

    private static function soap_kv_items_list_to_assoc(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $k = isset($it['key']) ? (string) $it['key'] : '';
            if ($k === '') {
                continue;
            }
            $v = $it['value'] ?? '';
            $out[$k] = is_array($v) ? self::maybe_map_to_assoc($v) : (string) $v;
        }
        return $out;
    }

    private static function find_first_key_recursive($node, string $needle)
    {
        if (!is_array($node)) {
            return null;
        }

        if (array_key_exists($needle, $node)) {
            return $node[$needle];
        }

        foreach ($node as $v) {
            if (is_array($v)) {
                $found = self::find_first_key_recursive($v, $needle);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private static function dig($arr, array $path, $default = '')
    {
        $cur = $arr;
        foreach ($path as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) {
                return $default;
            }
            $cur = $cur[$k];
        }
        return $cur;
    }

    private static function ctx(string $context, string $msg): string
    {
        $context = trim($context);
        return $context !== '' ? ($context . ': ' . $msg) : $msg;
    }

    private static function is_list(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_v) {
            if ($k !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }

    /** @return array<string,mixed> */
    private static function summarize_client_result(array $res): array
    {
        $raw = (string) ($res['raw'] ?? '');
        $fault = $res['fault'] ?? null;

        $parsed = $res['parsed'] ?? null;
        $topKeys = is_array($parsed) ? array_keys($parsed) : [];

        return [
            'ok'          => !empty($res['ok']) ? 1 : 0,
            'http_status' => (int) ($res['http_status'] ?? 0),
            'message'     => (string) ($res['message'] ?? ''),
            'has_fault'   => is_array($fault) ? 1 : 0,
            'fault'       => is_array($fault) ? $fault : null,
            'raw_head'    => $raw !== '' ? substr($raw, 0, 300) : '',
            'parsed_type' => is_array($parsed) ? 'array' : (is_string($parsed) ? 'string' : 'null'),
            'parsed_top_keys' => $topKeys,
        ];
    }

    private static function dbg(string $msg, array $ctx = []): void
    {
        if (!(defined(self::DEBUG_FLAG) && constant(self::DEBUG_FLAG))) {
            $env = getenv(self::DEBUG_FLAG);
            if ($env === false || $env === '' || $env === '0') {
                return;
            }
        }

        $line = '[FFLHub][ZandersDirectShipAPI] ' . $msg;
        if (!empty($ctx)) {
            $line .= ' ' . wp_json_encode($ctx);
        }
        error_log($line);
    }
}
