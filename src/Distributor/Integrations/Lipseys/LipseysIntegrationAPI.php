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
 *  - order response normalization (DropShip / DropShipFirearm)
 *
 * Performance / stability notes:
 *  - Debug logging is gated and compact (no massive JSON dumps).
 *  - Normalizers avoid expensive serialization unless debug is enabled.
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
     * @return array{
     *   ok:bool,
     *   message:string,
     *   result:array<string,mixed>,
     *   http_status:int,
     *   provider_error_code:string
     * }
     */
    public static function validate_item($client, string $query): array
    {
        try {
            $resp = $client->ValidateItem($query);
        } catch (\Throwable $e) {
            $norm = self::normalize_validateitem_response(null, $query);

            $msg = 'ValidateItem exception: ' . $e->getMessage();
            $norm['ok'] = false;
            $norm['message'] = $msg;

            self::debug_log('ValidateItem exception', [
                'query_tail4' => self::tail4($query),
                'error_tail120' => self::tail120($e->getMessage()),
            ]);

            return [
                'ok' => false,
                'message' => $msg,
                'result' => $norm,
                'http_status' => 0,
                'provider_error_code' => self::infer_provider_error_code_from_message($e->getMessage()),
            ];
        }

        $norm = self::normalize_validateitem_response($resp, $query);

        return [
            'ok' => (bool) ($norm['ok'] ?? false),
            'message' => (string) ($norm['message'] ?? 'OK'),
            'result' => $norm,
            'http_status' => 0,
            'provider_error_code' => isset($norm['provider_error_code']) ? (string) $norm['provider_error_code'] : '',
        ];
    }

    /**
     * Normalize Lipsey’s ValidateItem response per observed/expected schema.
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
     *   http_status:int,
     *   provider_error_code:string,
     *   raw:array|null
     * }
     */
    public static function normalize_validateitem_response($resp, string $queryValue): array
    {
        if (is_object($resp)) {
            // Avoid expensive deep conversions unless needed; wp_json_encode handles stdClass ok.
            $resp = json_decode(wp_json_encode($resp), true);
        }

        self::debug_log('ValidateItem raw (compact)', [
            'query_tail4' => self::tail4($queryValue),
            'resp' => self::compact_value($resp),
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
                'http_status' => 0,
                'provider_error_code' => '',
                'raw' => null,
            ];
        }

        $authorized = array_key_exists('authorized', $resp) ? (bool) $resp['authorized'] : true;
        $success    = array_key_exists('success', $resp) ? (bool) $resp['success'] : false;

        if (! $authorized) {
            $errors = self::implode_errors($resp);

            self::debug_log('ValidateItem not authorized', [
                'query_tail4' => self::tail4($queryValue),
                'errors_tail200' => self::tail200($errors),
            ]);

            return [
                'ok' => false,
                'message' => "ValidateItem not authorized: " . ($errors !== '' ? $errors : 'Not authorized'),
                'qty' => 0,
                'price' => null,
                'blocked' => false,
                'allocated' => false,
                'itemNumber' => '',
                'canDropship' => null,
                'http_status' => 0,
                'provider_error_code' => 'NOT_AUTHORIZED',
                'raw' => self::compact_raw_array($resp),
            ];
        }

        if (! $success) {
            $errors = self::implode_errors($resp);
            if ($errors === '') {
                $errors = 'Unknown error';
            }

            self::debug_log('ValidateItem failed', [
                'query_tail4' => self::tail4($queryValue),
                'errors_tail200' => self::tail200($errors),
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
                'http_status' => 0,
                'provider_error_code' => self::infer_provider_error_code_from_message($errors),
                'raw' => self::compact_raw_array($resp),
            ];
        }

        if (! isset($resp['data'])) {
            self::debug_log('ValidateItem success but missing data', [
                'query_tail4' => self::tail4($queryValue),
                'resp_keys' => array_slice(array_keys($resp), 0, 20),
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
                'http_status' => 0,
                'provider_error_code' => 'MISSING_DATA',
                'raw' => self::compact_raw_array($resp),
            ];
        }

        $data = $resp['data'];
        if (is_object($data)) {
            $data = json_decode(wp_json_encode($data), true);
        }

        $row = null;

        // data as associative object
        if (is_array($data) && ! self::is_list_array($data)) {
            $row = $data;
        }
        // data as list
        elseif (is_array($data) && self::is_list_array($data)) {
            $row = isset($data[0]) && is_array($data[0]) ? $data[0] : null;
        }

        if (! is_array($row) || empty($row)) {
            self::debug_log('ValidateItem invalid data row', [
                'query_tail4' => self::tail4($queryValue),
                'data_type' => gettype($resp['data']),
                'resp_keys' => array_slice(array_keys($resp), 0, 20),
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
                'http_status' => 0,
                'provider_error_code' => 'INVALID_DATA_ROW',
                'raw' => self::compact_raw_array($resp),
            ];
        }

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
            'http_status' => 0,
            'provider_error_code' => '',
            'raw' => self::compact_raw_array($resp),
        ];
    }

    /**
     * Normalize Lipsey’s order response shape (their client returns arrays with authorized/success/errors).
     *
     * Docs show orderNumber living under data.orderNumber (not top-level).
     *
     * @param mixed $resp
     * @return array{
     *   ok:bool,
     *   message:string,
     *   external_id:string,
     *   http_status:int,
     *   provider_error_code:string,
     *   raw:array|null
     * }
     */
    public static function normalize_order_response($resp, string $po, string $op): array
    {
        if (is_object($resp)) {
            $resp = json_decode(wp_json_encode($resp), true);
        }

        self::debug_log($op . ' raw (compact)', [
            'po_tail6' => self::tail6($po),
            'resp' => self::compact_value($resp),
        ]);

        if (! is_array($resp)) {
            return [
                'ok' => false,
                'message' => "{$op} returned non-array response.",
                'external_id' => '',
                'http_status' => 0,
                'provider_error_code' => '',
                'raw' => null,
            ];
        }

        $authorized = isset($resp['authorized']) ? (bool) $resp['authorized'] : true;
        $success = isset($resp['success']) ? (bool) $resp['success'] : false;

        if (! $authorized) {
            $errors = self::implode_errors($resp);
            if ($errors === '') {
                $errors = 'Not authorized';
            }

            return [
                'ok' => false,
                'message' => "{$op} not authorized: {$errors}",
                'external_id' => '',
                'http_status' => 0,
                'provider_error_code' => 'NOT_AUTHORIZED',
                'raw' => self::compact_raw_array($resp),
            ];
        }

        if (! $success) {
            $errors = self::implode_errors($resp);
            if ($errors === '') {
                $errors = 'Unknown error';
            }

            return [
                'ok' => false,
                'message' => "{$op} failed: {$errors}",
                'external_id' => '',
                'http_status' => 0,
                'provider_error_code' => self::infer_provider_error_code_from_message($errors),
                'raw' => self::compact_raw_array($resp),
            ];
        }

        // Extract order number: prefer data.orderNumber (per docs), then fall back.
        $external = '';

        $data = $resp['data'] ?? null;
        if (is_object($data)) {
            $data = json_decode(wp_json_encode($data), true);
        }

        if (is_array($data)) {
            // Some shapes are { orderNumber, ... } and some are { lineItems:[], orderNumber, ... }
            foreach (['orderNumber', 'OrderNumber', 'order_no', 'OrderNo'] as $k) {
                if (isset($data[$k]) && (is_string($data[$k]) || is_int($data[$k]) || is_numeric($data[$k]))) {
                    $external = (string) $data[$k];
                    $external = trim($external);
                    if ($external !== '') {
                        break;
                    }
                }
            }
        }

        // Very last-resort fallbacks (older/odd variants)
        if ($external === '') {
            foreach (['orderNumber', 'OrderNumber', 'order_no', 'OrderNo'] as $k) {
                if (isset($resp[$k]) && (is_string($resp[$k]) || is_int($resp[$k]) || is_numeric($resp[$k]))) {
                    $external = (string) $resp[$k];
                    $external = trim($external);
                    if ($external !== '') {
                        break;
                    }
                }
            }
        }

        if ($external === '') {
            $external = $po;
        }

        return [
            'ok' => true,
            'message' => "{$op} OK",
            'external_id' => $external,
            'http_status' => 0,
            'provider_error_code' => '',
            'raw' => self::compact_raw_array($resp),
        ];
    }

    /* ---------------------------------------------
     * Small helpers
     * ------------------------------------------- */

    private static function implode_errors(array $resp): string
    {
        if (isset($resp['errors']) && is_array($resp['errors'])) {
            $parts = [];
            foreach ($resp['errors'] as $e) {
                $s = trim((string) $e);
                if ($s !== '') {
                    $parts[] = $s;
                }
                if (count($parts) >= 8) {
                    break;
                }
            }
            return implode(' | ', $parts);
        }
        return '';
    }

    private static function infer_provider_error_code_from_message(string $message): string
    {
        $m = strtolower(trim($message));

        if ($m === '') {
            return '';
        }
        if (strpos($m, 'not authorized') !== false || strpos($m, 'unauthorized') !== false) {
            return 'NOT_AUTHORIZED';
        }
        if (strpos($m, 'quota') !== false || strpos($m, 'rate') !== false || strpos($m, 'throttle') !== false) {
            return 'RATE_LIMIT';
        }
        if (strpos($m, 'timeout') !== false || strpos($m, 'timed out') !== false) {
            return 'TIMEOUT';
        }
        if (strpos($m, 'out of stock') !== false || strpos($m, 'insufficient') !== false) {
            return 'OUT_OF_STOCK';
        }
        return '';
    }

    /**
     * Compact an arbitrary value for debug logs (prevents huge serialization).
     *
     * @param mixed $v
     * @return array<string,mixed>
     */
    private static function compact_value($v): array
    {
        if (is_null($v)) {
            return ['type' => 'null'];
        }
        if (is_bool($v)) {
            return ['type' => 'bool', 'value' => ($v ? 1 : 0)];
        }
        if (is_int($v) || is_float($v)) {
            return ['type' => 'number', 'value' => $v];
        }
        if (is_string($v)) {
            return ['type' => 'string', 'len' => strlen($v), 'tail80' => self::tail80($v)];
        }
        if (is_object($v)) {
            return ['type' => 'object', 'class' => get_class($v)];
        }
        if (is_array($v)) {
            return [
                'type' => 'array',
                'keys' => array_slice(array_keys($v), 0, 20),
                'count' => count($v),
            ];
        }
        return ['type' => gettype($v)];
    }

    /**
     * Keep raw payload small (keys + some known top-level fields).
     *
     * @param array<string,mixed> $resp
     * @return array<string,mixed>
     */
    private static function compact_raw_array(array $resp): array
    {
        $out = [
            '_keys' => array_slice(array_keys($resp), 0, 30),
        ];

        foreach (['authorized', 'success', 'errors', 'data'] as $k) {
            if (array_key_exists($k, $resp)) {
                $out[$k] = $resp[$k];
            }
        }

        // If errors is huge, trim it.
        if (isset($out['errors']) && is_array($out['errors'])) {
            $out['errors'] = array_slice($out['errors'], 0, 10);
        }

        // If data is an array with huge lineItems, trim.
        if (isset($out['data']) && is_array($out['data'])) {
            if (isset($out['data']['lineItems']) && is_array($out['data']['lineItems'])) {
                $out['data']['lineItems'] = array_slice($out['data']['lineItems'], 0, 5);
            }
        }

        return $out;
    }

    private static function debug_log(string $message, array $context = []): void
    {
        if (! defined('FFLHUB_LIPSEYS_DEBUG') || constant('FFLHUB_LIPSEYS_DEBUG') !== true) {
            return;
        }

        $prefix = '[FFLHub Lipseys] ';

        if (! empty($context)) {
            error_log($prefix . $message . ' ' . wp_json_encode($context));
            return;
        }

        error_log($prefix . $message);
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

    private static function tail6(string $s): string
    {
        $s = (string) $s;
        $n = strlen($s);
        if ($n <= 6) {
            return $s;
        }
        return substr($s, -6);
    }

    private static function tail80(string $s): string
    {
        $s = (string) $s;
        $n = strlen($s);
        if ($n <= 80) {
            return $s;
        }
        return substr($s, -80);
    }

    private static function tail120(string $s): string
    {
        $s = (string) $s;
        $n = strlen($s);
        if ($n <= 120) {
            return $s;
        }
        return substr($s, -120);
    }

    private static function tail200(string $s): string
    {
        $s = (string) $s;
        $n = strlen($s);
        if ($n <= 200) {
            return $s;
        }
        return substr($s, -200);
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
