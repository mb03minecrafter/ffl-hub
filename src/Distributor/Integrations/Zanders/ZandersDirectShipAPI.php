<?php

declare(strict_types=1);

namespace FFLHub\Distributor\Integrations\Zanders;

use FFLHub\Distributor\Services\Zanders\API\ZandersSoapCurlClient;

if (!defined('ABSPATH')) {
    exit;
}

final class ZandersDirectShipAPI
{
    // WSDLs documented in the PDF
    public const ORDERS_WSDL  = 'https://shop2.gzanders.com/webservice/orders?wsdl';            // :contentReference[oaicite:4]{index=4}
    public const SHIPTO_WSDL  = 'https://shop2.gzanders.com/webservice/shiptoaddresses?wsdl';  // :contentReference[oaicite:5]{index=5}

    // SOAP operation element namespaces shown in examples (note: docs show both http and https variants)
    public const ORDERS_NS_HTTP  = 'http://shop2.gzanders.com/webservice/orders';              // :contentReference[oaicite:6]{index=6}
    public const ORDERS_NS_HTTPS = 'https://shop2.gzanders.com/webservice/orders';             // :contentReference[oaicite:7]{index=7}
    public const SHIPTO_NS_HTTPS = 'https://shop2.gzanders.com/webservice/shiptoaddresses';    // :contentReference[oaicite:8]{index=8}

    // Operation names (as documented)
    public const OP_CREATE_ORDER      = 'createOrder';       // :contentReference[oaicite:9]{index=9}
    public const OP_EDIT_ORDER        = 'editOrder';         // :contentReference[oaicite:10]{index=10}
    public const OP_GET_TRACKING_INFO = 'getTrackingInfo';   // :contentReference[oaicite:11]{index=11}
    public const OP_USE_SHIP_TO       = 'useShipTo';         // :contentReference[oaicite:12]{index=12}

    /**
     * createOrder(username, password, order(Map), testing)
     *
     * $order is the associative array that represents the "orderarray" from the PDF.
     * Example keys include: purchaseOrderNumber, shipToNo, shipDate, shipViaCode, payCode, shipInstructions, items[], etc.
     * :contentReference[oaicite:13]{index=13} :contentReference[oaicite:14]{index=14}
     */
    public static function create_order(
        ZandersSoapCurlClient $client,
        array $auth,
        array $order,
        bool $testing
    ): array {
        $payload = [
            'username' => (string)($auth['username'] ?? ''),
            'password' => (string)($auth['password'] ?? ''),
            'order'    => $order,
            'testing'  => $testing ? 'true' : 'false',
        ];

        // SOAPAction handling:
        // The PDF doesn’t explicitly specify SOAPAction URIs; many servers accept just the op name.
        // If Zanders requires a full URI later, you can change this to "…/orders#createOrder" etc.
        return $client->call(self::OP_CREATE_ORDER, self::OP_CREATE_ORDER, $payload, self::ORDERS_NS_HTTPS, []);
    }

    /**
     * editOrder(username, password, order(Map), testing)
     *
     * Used to change shipToNo / shipViaCode / shipDate / payCode (hold/unhold). :contentReference[oaicite:15]{index=15}
     */
    public static function edit_order(
        ZandersSoapCurlClient $client,
        array $auth,
        array $order_patch,
        bool $testing
    ): array {
        $payload = [
            'username' => (string)($auth['username'] ?? ''),
            'password' => (string)($auth['password'] ?? ''),
            'order'    => $order_patch,
            'testing'  => $testing ? 'true' : 'false',
        ];

        return $client->call(self::OP_EDIT_ORDER, self::OP_EDIT_ORDER, $payload, self::ORDERS_NS_HTTPS, []);
    }

    /**
     * getTrackingInfo(username, password, ordernumber, testing)
     *
     * Returns a Map with numberOfShipments + trackingNumbers[]. :contentReference[oaicite:16]{index=16} :contentReference[oaicite:17]{index=17}
     */
    public static function get_tracking_info(
        ZandersSoapCurlClient $client,
        array $auth,
        string $order_number,
        bool $testing
    ): array {
        $payload = [
            'username'     => (string)($auth['username'] ?? ''),
            'password'     => (string)($auth['password'] ?? ''),
            'ordernumber'  => (string)$order_number,
            'testing'      => $testing ? 'true' : 'false',
        ];

        return $client->call(self::OP_GET_TRACKING_INFO, self::OP_GET_TRACKING_INFO, $payload, self::ORDERS_NS_HTTPS, []);
    }

    /**
     * useShipTo(username, password, addressinfo(array), testing)
     *
     * Creates/returns a ShipToNo for the gun DS account. :contentReference[oaicite:18]{index=18}
     */
    public static function use_ship_to(
        ZandersSoapCurlClient $client,
        array $auth,
        array $address_info,
        bool $testing
    ): array {
        $payload = [
            'username'     => (string)($auth['username'] ?? ''),
            'password'     => (string)($auth['password'] ?? ''),
            'addressinfo'  => $address_info,
            'testing'      => $testing ? 'true' : 'false',
        ];

        return $client->call(self::OP_USE_SHIP_TO, self::OP_USE_SHIP_TO, $payload, self::SHIPTO_NS_HTTPS, []);
    }

    // ---------------------------------------------------------------------
    // Normalizers
    // ---------------------------------------------------------------------

    /**
     * Normalize createOrder/editOrder response into:
     * [
     *   'ok' => bool,
     *   'return_code' => int,
     *   'order_number' => string,
     *   'reason' => string,
     *   'removed_items' => array,
     *   'message' => string,
     *   'raw' => array|null
     * ]
     *
     * Example success Map includes returnCode=0 and orderNumber. :contentReference[oaicite:19]{index=19}
     * Example OOS Map includes returnCode=9 + reason + removedItems. :contentReference[oaicite:20]{index=20}
     */
    public static function normalize_order_response(array $soap_res, string $context = ''): array
    {
        $base = self::normalize_map_style_response($soap_res, $context);

        $returnCode = (int)($base['map']['returnCode'] ?? -1);
        $orderNum   = (string)($base['map']['orderNumber'] ?? '');

        $reason = (string)($base['map']['reason'] ?? '');
        $removed = $base['map']['removedItems'] ?? [];

        $ok = ($base['ok'] === true && $returnCode === 0);

        return [
            'ok'           => $ok,
            'return_code'  => $returnCode,
            'order_number' => $orderNum,
            'reason'       => $reason,
            'removed_items' => is_array($removed) ? $removed : [],
            'message'      => $ok ? self::ctx($context, 'OK') : self::ctx($context, $base['message']),
            'raw'          => $base['raw'],
        ];
    }

    /**
     * Normalize useShipTo response into:
     * [
     *   'ok' => bool,
     *   'return_code' => int,
     *   'ship_to_no' => string,
     *   'reason' => string,
     *   'message' => string,
     *   'raw' => array|null
     * ]
     *
     * PDF example logic:
     * - if returnCode == 0 => shipToAddress[ShipToNo]
     * - else => searchResults[ShipToNo] + reason :contentReference[oaicite:21]{index=21}
     */
    public static function normalize_use_ship_to_response(array $soap_res, string $context = ''): array
    {
        $base = self::normalize_map_style_response($soap_res, $context);

        $returnCode = (int)($base['map']['returnCode'] ?? -1);

        $shipTo = '';
        if ($returnCode === 0) {
            $shipTo = (string)self::dig($base['map'], ['shipToAddress', 'ShipToNo'], '');
        } else {
            $shipTo = (string)self::dig($base['map'], ['searchResults', 'ShipToNo'], '');
        }

        $reason = (string)($base['map']['reason'] ?? '');

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

    /**
     * Normalize getTrackingInfo response into:
     * [
     *   'ok' => bool,
     *   'return_code' => int,
     *   'number_of_shipments' => int,
     *   'tracking_numbers' => array,
     *   'message' => string,
     *   'raw' => array|null
     * ]
     *
     * trackingNumbers is an array of Maps with shipCompany/shipVia/trackingNumber/weight/url. :contentReference[oaicite:22]{index=22}
     */
    public static function normalize_tracking_response(array $soap_res, string $context = ''): array
    {
        $base = self::normalize_map_style_response($soap_res, $context);

        $returnCode = (int)($base['map']['returnCode'] ?? -1);
        $numShip    = (int)($base['map']['numberOfShipments'] ?? 0);

        $rowsRaw = $base['map']['trackingNumbers'] ?? [];
        $rows = [];

        // Normalize rowsRaw into a list of associative arrays
        if (is_array($rowsRaw)) {
            // If it's a SOAP Map container, convert once.
            if (isset($rowsRaw['item'])) {
                $items = $rowsRaw['item'];

                // item can be a single row or a list of rows
                $items_list = self::is_list($items) ? $items : [$items];

                foreach ($items_list as $it) {
                    if (!is_array($it)) {
                        continue;
                    }

                    // Each row may itself be a SOAP Map (with 'item' key/value pairs)
                    $rows[] = self::maybe_map_to_assoc($it);
                }
            } else {
                // If it's already a list -> normalize each row
                if (self::is_list($rowsRaw)) {
                    foreach ($rowsRaw as $row) {
                        if (is_array($row)) {
                            $rows[] = self::maybe_map_to_assoc($row);
                        }
                    }
                } else {
                    // Single row associative
                    $rows[] = self::maybe_map_to_assoc($rowsRaw);
                }
            }
        }

        // Flatten tracking numbers
        $tracking_numbers = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $t = trim((string)($r['trackingNumber'] ?? ''));
            if ($t !== '') {
                $tracking_numbers[] = $t;
            }
        }
        $tracking_numbers = array_values(array_unique($tracking_numbers));
        sort($tracking_numbers, SORT_STRING);

        $ok = ($base['ok'] === true && $returnCode === 0);

        return [
            'ok'                   => $ok,
            'return_code'          => $returnCode,
            'number_of_shipments'  => $numShip,
            'tracking_rows'        => $rows,
            'tracking_numbers_flat' => $tracking_numbers,
            'message'              => $ok ? self::ctx($context, 'OK') : self::ctx($context, $base['message']),
            'raw'                  => $base['raw'],
        ];
    }


    // ---------------------------------------------------------------------
    // Internal helpers for Map-style SOAP responses
    // ---------------------------------------------------------------------

    /**
     * Zanders services return SOAP Map structures (ns2:Map) with <item><key>..</key><value>..</value></item>.
     * We want to end up with a PHP associative array keyed by those <key> values. :contentReference[oaicite:23]{index=23}
     */
    private static function normalize_map_style_response(array $soap_res, string $context): array
    {
        if (!($soap_res['ok'] ?? false)) {
            $fault = $soap_res['fault'] ?? null;
            $msg = (string)($soap_res['message'] ?? 'SOAP call failed');

            if (is_array($fault) && !empty($fault['faultstring'])) {
                $msg = 'Zanders SOAP fault: ' . (string)$fault['faultstring'];
            }

            return [
                'ok'      => false,
                'message' => self::ctx($context, $msg),
                'map'     => [],
                'raw'     => is_array($soap_res['parsed'] ?? null) ? $soap_res['parsed'] : null,
            ];
        }

        $body = $soap_res['parsed'] ?? null;

        // The curl client returns the first element under SOAP Body as an array.
        // Typical shape: [ 'createOrderResponse' => [ 'return' => [ 'item' => ... ] ] ]
        // but it may also return directly: [ 'return' => ... ] depending on parsing.
        $returnNode = null;

        if (is_array($body)) {
            // Find 'return' anywhere in the first response node
            $returnNode = self::find_first_key_recursive($body, 'return');

            // If not found, sometimes it’s nested under <rpc:result> etc; try first child.
            if ($returnNode === null) {
                $returnNode = $body;
            }
        }

        $map = [];
        if (is_array($returnNode)) {
            $map = self::maybe_map_to_assoc($returnNode);
        }

        $returnCode = (int)($map['returnCode'] ?? -1);
        $reason = (string)($map['reason'] ?? '');

        // Message: if nonzero returnCode, include reason when present.
        $msg = 'OK';
        if ($returnCode !== 0) {
            $msg = 'Zanders returnCode=' . $returnCode;
            if ($reason !== '') {
                $msg .= ' reason=' . $reason;
            }
        }

        return [
            'ok'      => true,
            'message' => self::ctx($context, $msg),
            'map'     => $map,
            'raw'     => is_array($body) ? $body : null,
        ];
    }

    /**
     * If array looks like SOAP Map (<item> list), convert it. Otherwise return as-is.
     */
    private static function maybe_map_to_assoc(array $node): array
    {
        // Sometimes the node is already associative (e.g., shipToAddress => [ShipToNo => ...]).
        // But "ns2:Map" usually shows up as ['item' => [ ... ]].
        if (isset($node['item'])) {
            return self::soap_map_to_assoc($node);
        }

        // Sometimes return itself is the list of items (parsed as 'item' at same level but renamed)
        // If it looks like a list of key/value pairs, try to interpret.
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

        $out = [];
        $list = self::is_list($items) ? $items : [$items];

        foreach ($list as $it) {
            if (!is_array($it)) {
                continue;
            }

            $k = isset($it['key']) ? (string)$it['key'] : '';
            if ($k === '') {
                continue;
            }

            $v = $it['value'] ?? '';

            if (is_array($v)) {
                // Value might itself be a Map or an Array of Maps.
                if (isset($v['item'])) {
                    $out[$k] = self::soap_map_to_assoc($v);
                } elseif (isset($v['item']) || isset($v['@xsi:type'])) {
                    $out[$k] = self::maybe_map_to_assoc($v);
                } elseif (self::is_list($v)) {
                    $norm = [];
                    foreach ($v as $row) {
                        $norm[] = is_array($row) ? self::maybe_map_to_assoc($row) : (string)$row;
                    }
                    $out[$k] = $norm;
                } else {
                    // SOAP arrays often come through like ['item' => [...]] at some depth.
                    // Try to normalize children if present.
                    if (isset($v['item'])) {
                        $inner = $v['item'];
                        $innerList = self::is_list($inner) ? $inner : [$inner];
                        $norm = [];
                        foreach ($innerList as $row) {
                            $norm[] = is_array($row) ? self::maybe_map_to_assoc($row) : (string)$row;
                        }
                        $out[$k] = $norm;
                    } else {
                        $out[$k] = $v;
                    }
                }
            } else {
                $out[$k] = (string)$v;
            }
        }

        return $out;
    }

    /**
     * Some parsers produce a list like:
     * [
     *   0 => ['key' => 'returnCode', 'value' => '0'],
     *   1 => ['key' => 'orderNumber', 'value' => '123'],
     * ]
     */
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
            $k = isset($it['key']) ? (string)$it['key'] : '';
            if ($k === '') {
                continue;
            }
            $v = $it['value'] ?? '';
            $out[$k] = is_array($v) ? self::maybe_map_to_assoc($v) : (string)$v;
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
}
