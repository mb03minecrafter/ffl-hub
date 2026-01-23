<?php
// File: src/Distributor/Lipseys/LipseysIntegrationAPI.php

namespace FFLHub\Distributor\Integrations\Lipseys;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Lipsey's Integration API helper (static).
 *
 * Centralizes:
 *  - client creation
 *  - ValidateItem call + normalization
 *  - order response normalization (DropShipAccessories / DropShipFirearms)
 *
 * NOTE: This wraps the vendor client (lipseys/apiintegration) but does not replace it.
 *
 * DEBUG:
 *  - Gate verbose Lipsey's API logging behind:
 *      define('FFLHUB_LIPSEYS_DEBUG', true);
 *    (recommended in wp-config.php)
 */
final class LipseysIntegrationAPI
{
    /**
     * @return array{ok:bool,message:string,client:object|null}
     */
    public static function create_client(string $email, string $password): array
    {
        if (! class_exists('\\lipseys\\ApiIntegration\\LipseysClient')) {
            return [
                'ok' => false,
                'message' => 'Lipseys API client not available (lipseys/apiintegration).',
                'client' => null,
            ];
        }

        try {
            $client = new \lipseys\ApiIntegration\LipseysClient($email, $password);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Failed to initialize Lipsey’s client: ' . $e->getMessage(),
                'client' => null,
            ];
        }

        return [
            'ok' => true,
            'message' => 'OK',
            'client' => $client,
        ];
    }

    /**
     * ValidateItem wrapper (query can be Lipsey's item#, MFG model#, or UPC).
     *
     * @return array{ok:bool,message:string,result:array}
     */
    public static function validate_item($client, string $query): array
    {
        try {
            $resp = $client->ValidateItem($query);
        } catch (\Throwable $e) {
            // In exception cases, $resp may not exist; normalize with null.
            $norm = self::normalize_validateitem_response(null, $query);
            $norm['ok'] = false;
            $norm['message'] = 'ValidateItem exception: ' . $e->getMessage();

            // Debug: log exception and query tail
            self::debug_log('ValidateItem exception', [
                'query_tail4' => self::tail4($query),
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => $norm['message'],
                'result' => $norm,
            ];
        }

        $norm = self::normalize_validateitem_response($resp, $query);

        return [
            'ok' => (bool) ($norm['ok'] ?? false),
            'message' => (string) ($norm['message'] ?? 'OK'),
            'result' => $norm,
        ];
    }

    /**
     * Normalize Lipsey’s ValidateItem response per observed/expected schema.
     *
     * Observed working response shape (from your logs):
     * {
     *   "success": true,
     *   "authorized": true,
     *   "errors": [],
     *   "data": {
     *     "qty": 100,
     *     "price": 749.99,
     *     "blocked": false,
     *     "allocated": false,
     *     "itemNumber": "CAHG7854S-N",
     *     "canDropship": true
     *   }
     * }
     *
     * Some documentation/examples show:
     *   "data": [ { ... } ]
     * so we support both "data" as object and "data" as list.
     *
     * @param mixed $resp
     * @return array{
     *   ok:bool,
     *   message:string,
     *   qty:int,
     *   price:float|null,
     *   blocked:bool,
     *   allocated:bool,
     *   itemNumber:string,
     *   canDropship:bool|null,
     *   raw:array|null
     * }
     */
    public static function normalize_validateitem_response($resp, string $queryValue): array
    {
        // Convert object responses to arrays (vendor client sometimes returns stdClass)
        if (is_object($resp)) {
            $resp = json_decode(wp_json_encode($resp), true);
        }

        // Debug: raw response (truncated) behind flag
        self::debug_log('ValidateItem raw response', [
            'query_tail4' => self::tail4($queryValue),
            'resp' => self::describe_value($resp),
        ]);

        if (! is_array($resp)) {
            return [
                'ok' => false,
                'message' => "ValidateItem returned non-array response for {$queryValue}.",
                'qty' => 0,
                'price' => null,
                'blocked' => false,
                'allocated' => false,
                'itemNumber' => '',
                'canDropship' => null,
                'raw' => null,
            ];
        }

        $authorized = array_key_exists('authorized', $resp) ? (bool) $resp['authorized'] : true;
        $success    = array_key_exists('success', $resp) ? (bool) $resp['success'] : false;

        if (! $authorized) {
            $errors = '';
            if (isset($resp['errors']) && is_array($resp['errors'])) {
                $errors = implode(' | ', array_map('strval', $resp['errors']));
            }
            if ($errors === '') {
                $errors = 'Not authorized';
            }

            self::debug_log('ValidateItem not authorized', [
                'query_tail4' => self::tail4($queryValue),
                'errors' => $errors,
                'resp' => self::describe_value($resp),
            ]);

            return [
                'ok' => false,
                'message' => "ValidateItem not authorized: {$errors}",
                'qty' => 0,
                'price' => null,
                'blocked' => false,
                'allocated' => false,
                'itemNumber' => '',
                'canDropship' => null,
                'raw' => $resp,
            ];
        }

        if (! $success) {
            $errors = '';
            if (isset($resp['errors']) && is_array($resp['errors'])) {
                $errors = implode(' | ', array_map('strval', $resp['errors']));
            }
            if ($errors === '') {
                $errors = 'Unknown error';
            }

            self::debug_log('ValidateItem failed', [
                'query_tail4' => self::tail4($queryValue),
                'errors' => $errors,
                'resp' => self::describe_value($resp),
            ]);

            return [
                'ok' => false,
                'message' => "ValidateItem failed: {$errors}",
                'qty' => 0,
                'price' => null,
                'blocked' => false,
                'allocated' => false,
                'itemNumber' => '',
                'canDropship' => null,
                'raw' => $resp,
            ];
        }

        if (! isset($resp['data'])) {
            self::debug_log('ValidateItem success but missing data', [
                'query_tail4' => self::tail4($queryValue),
                'resp' => self::describe_value($resp),
            ]);

            return [
                'ok' => false,
                'message' => 'ValidateItem returned success but no data payload.',
                'qty' => 0,
                'price' => null,
                'blocked' => false,
                'allocated' => false,
                'itemNumber' => '',
                'canDropship' => null,
                'raw' => $resp,
            ];
        }

        /**
         * Support:
         *  - data as associative object: { qty, price, ... }
         *  - data as list: [ { qty, price, ... } ]
         */
        $data = $resp['data'];

        // Convert data object to array if needed
        if (is_object($data)) {
            $data = json_decode(wp_json_encode($data), true);
        }

        $row = null;

        // Case 1: data is an associative array row
        if (is_array($data) && ! self::is_list_array($data)) {
            $row = $data;
        }
        // Case 2: data is a list of rows
        elseif (is_array($data) && self::is_list_array($data)) {
            $row = isset($data[0]) && is_array($data[0]) ? $data[0] : null;
        }

        if (! is_array($row) || empty($row)) {
            self::debug_log('ValidateItem: invalid data row', [
                'query_tail4' => self::tail4($queryValue),
                'resp_keys' => array_slice(array_keys($resp), 0, 20),
                'data_type' => gettype($resp['data']),
                'data_keys' => (is_array($data) ? array_slice(array_keys($data), 0, 20) : null),
                'resp' => self::describe_value($resp),
            ]);

            return [
                'ok' => false,
                'message' => 'ValidateItem returned invalid data row.',
                'qty' => 0,
                'price' => null,
                'blocked' => false,
                'allocated' => false,
                'itemNumber' => '',
                'canDropship' => null,
                'raw' => $resp,
            ];
        }

        // Extract fields safely
        $qty = (isset($row['qty']) && (is_int($row['qty']) || is_numeric($row['qty']))) ? (int) $row['qty'] : 0;
        $price = (isset($row['price']) && (is_float($row['price']) || is_int($row['price']) || is_numeric($row['price'])))
            ? (float) $row['price']
            : null;

        $blocked = isset($row['blocked']) ? (bool) $row['blocked'] : false;
        $allocated = isset($row['allocated']) ? (bool) $row['allocated'] : false;
        $itemNumber = isset($row['itemNumber']) ? (string) $row['itemNumber'] : '';
        $canDropship = array_key_exists('canDropship', $row) ? (bool) $row['canDropship'] : null;

        self::debug_log('ValidateItem normalized', [
            'query_tail4' => self::tail4($queryValue),
            'qty' => $qty,
            'price' => $price,
            'blocked' => $blocked ? 1 : 0,
            'allocated' => $allocated ? 1 : 0,
            'itemNumber_tail4' => self::tail4($itemNumber),
            'canDropship' => ($canDropship === null ? 'null' : ($canDropship ? 'true' : 'false')),
        ]);

        return [
            'ok' => true,
            'message' => 'OK',
            'qty' => $qty,
            'price' => $price,
            'blocked' => $blocked,
            'allocated' => $allocated,
            'itemNumber' => $itemNumber,
            'canDropship' => $canDropship,
            'raw' => $resp,
        ];
    }

    /**
     * Normalize Lipsey’s order response shape (their client returns arrays with authorized/success/errors).
     *
     * @param mixed $resp
     * @return array{ok:bool,message:string,external_id:string}
     */
    public static function normalize_order_response($resp, string $po, string $op): array
    {
        if (is_object($resp)) {
            $resp = json_decode(wp_json_encode($resp), true);
        }

        if (! is_array($resp)) {
            return [
                'ok' => false,
                'message' => "{$op} returned non-array response.",
                'external_id' => '',
            ];
        }

        $authorized = isset($resp['authorized']) ? (bool) $resp['authorized'] : true;
        $success = isset($resp['success']) ? (bool) $resp['success'] : false;

        if (! $authorized) {
            $errors = '';
            if (isset($resp['errors']) && is_array($resp['errors'])) {
                $errors = implode(' | ', array_map('strval', $resp['errors']));
            }
            if ($errors === '') {
                $errors = 'Not authorized';
            }

            return [
                'ok' => false,
                'message' => "{$op} not authorized: {$errors}",
                'external_id' => '',
            ];
        }

        if (! $success) {
            $errors = '';
            if (isset($resp['errors']) && is_array($resp['errors'])) {
                $errors = implode(' | ', array_map('strval', $resp['errors']));
            }
            if ($errors === '') {
                $errors = 'Unknown error';
            }

            return [
                'ok' => false,
                'message' => "{$op} failed: {$errors}",
                'external_id' => '',
            ];
        }

        $external = '';
        foreach (['orderNumber', 'OrderNumber', 'order_no', 'OrderNo'] as $k) {
            if (isset($resp[$k]) && is_string($resp[$k]) && trim($resp[$k]) !== '') {
                $external = trim($resp[$k]);
                break;
            }
        }
        if ($external === '') {
            $external = $po;
        }

        return [
            'ok' => true,
            'message' => "{$op} OK",
            'external_id' => $external,
        ];
    }

    /* ---------------------------------------------
     * Debug helpers (gated)
     * ------------------------------------------- */

    private static function debug_log(string $message, array $context = []): void
    {
        if (! defined('FFLHUB_LIPSEYS_DEBUG') || FFLHUB_LIPSEYS_DEBUG !== true) {
            return;
        }

        $prefix = '[FFLHub Lipseys] ';

        if (! empty($context)) {
            error_log($prefix . $message . ' ' . wp_json_encode($context));
            return;
        }

        error_log($prefix . $message);
    }

    /**
     * Describe large values without dumping infinite logs.
     * Returns a compact description that includes keys and a JSON preview.
     *
     * @param mixed $v
     * @return array{type:string,len:int,truncated:int,json:string,keys:?array}
     */
    private static function describe_value($v): array
    {
        $type = is_object($v) ? get_class($v) : gettype($v);
        $json = wp_json_encode($v);

        if (! is_string($json)) {
            $json = '';
        }

        $max = 4000;
        $truncated = 0;

        if (strlen($json) > $max) {
            $json = substr($json, 0, $max) . '...';
            $truncated = 1;
        }

        $keys = null;
        if (is_array($v)) {
            $keys = array_slice(array_keys($v), 0, 20);
        }

        return [
            'type' => $type,
            'len' => strlen($json),
            'truncated' => $truncated,
            'json' => $json,
            'keys' => $keys,
        ];
    }

    private static function tail4(string $s): string
    {
        $s = (string) $s;
        $n = strlen($s);
        if ($n <= 4) {
            return $s;
        }
        return substr($s, -4);
    }

    /**
     * True if $arr is a numeric list (0..n-1).
     */
    private static function is_list_array(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
