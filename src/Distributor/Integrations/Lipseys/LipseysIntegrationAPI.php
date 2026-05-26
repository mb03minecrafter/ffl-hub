<?php
// File: src/Distributor/Lipseys/LipseysIntegrationAPI.php

namespace FFLHub\Distributor\Integrations\Lipseys;

use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lipsey's Integration API helper (static).
 *
 * Purpose:
 * - Centralize all direct interactions with the Lipsey's PHP client:
 *     - Client creation (auth/session init)
 *     - ValidateItem call + normalization
 *     - Order call response normalization (DropShip / DropShipFirearm)
 *
 * Design goals:
 * - Keep distributor implementation (DistributorLipseys) focused on business logic
 *   and treat this as the protocol / shape adapter layer.
 * - Normalize all provider responses into small predictable arrays so higher layers
 *   can do deterministic classification (retryable vs fatal).
 * - Debug logging must never explode logs:
 *     - all logs are gated behind a constant
 *     - payloads are compacted (keys + small tails)
 *
 * IMPORTANT:
 * - We intentionally do NOT attempt to infer HTTP status from the vendor client here,
 *   because the library does not reliably provide it. Callers may still classify by message.
 */
final class LipseysIntegrationAPI
{
    private const API_BASE_URL = 'https://api.lipseys.com/api/';
    private const VALIDATEITEM_ENDPOINT = 'integration/items/validateitem';

    /**
     * Create an authenticated Lipsey's client instance.
     *
     * @return array{ok:bool,message:string,client:object|null}
     */
    public static function create_client(string $email, string $password): array
    {
        if (!class_exists('\\FFLHub\\Distributor\\Services\\Lipseys\\LipseysRawAPI\\LipseysClient')) {
            return [
                'ok'      => false,
                'message' => 'Lipseys raw API client not available.',
                'client'  => null,
            ];
        }

        try {
            $client = new \FFLHub\Distributor\Services\Lipseys\LipseysRawAPI\LipseysClient($email, $password);
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'message' => 'Failed to initialize Lipsey raw API client: ' . $e->getMessage(),
                'client'  => null,
            ];
        }

        return [
            'ok'      => true,
            'message' => 'OK',
            'client'  => $client,
        ];
    }

    /**
     * Backward-compatible alias.
     *
     * @return array{ok:bool,message:string,client:object|null}
     */
    public static function create_raw_client(string $email, string $password): array
    {
        return self::create_client($email, $password);
    }

    /**
     * Authenticate credentials using Lipsey's dedicated login endpoint only.
     *
     * @return array{
     *   ok:bool,
     *   message:string,
     *   raw:array|null,
     *   http_status:int,
     *   provider_error_code:string,
     *   likely_cause:string
     * }
     */
    public static function authenticate_credentials(string $email, string $password): array
    {
        $client_res = self::create_client($email, $password);
        if (empty($client_res['ok']) || !is_object($client_res['client'] ?? null)) {
            return [
                'ok' => false,
                'message' => (string) ($client_res['message'] ?? 'Failed to initialize Lipsey raw API client.'),
                'raw' => null,
                'http_status' => 0,
                'provider_error_code' => '',
                'likely_cause' => '',
            ];
        }

        $client = $client_res['client'];
        if (!method_exists($client, 'Authenticate')) {
            return [
                'ok' => false,
                'message' => 'Lipseys raw API client does not expose an auth-only credential test.',
                'raw' => null,
                'http_status' => 0,
                'provider_error_code' => '',
                'likely_cause' => '',
            ];
        }

        try {
            $raw = $client->Authenticate();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Lipsey authentication exception: ' . $e->getMessage(),
                'raw' => null,
                'http_status' => 0,
                'provider_error_code' => self::infer_provider_error_code_from_message($e->getMessage()),
                'likely_cause' => '',
            ];
        }

        return self::normalize_authenticate_response($raw);
    }

    /**
     * @param mixed $resp
     * @return array{
     *   ok:bool,
     *   message:string,
     *   raw:array|null,
     *   http_status:int,
     *   provider_error_code:string,
     *   likely_cause:string
     * }
     */
    private static function normalize_authenticate_response($resp): array
    {
        if (is_object($resp)) {
            $resp = json_decode(wp_json_encode($resp), true);
        }

        if (!is_array($resp)) {
            return [
                'ok' => false,
                'message' => 'Lipsey authentication returned an invalid response.',
                'raw' => null,
                'http_status' => 0,
                'provider_error_code' => '',
                'likely_cause' => '',
            ];
        }

        $authorized = array_key_exists('authorized', $resp)
            ? self::to_bool_default($resp['authorized'], false)
            : false;
        $success = array_key_exists('success', $resp)
            ? self::to_bool_default($resp['success'], false)
            : false;

        $diagnostics = (isset($resp['login_diagnostics']) && is_array($resp['login_diagnostics']))
            ? $resp['login_diagnostics']
            : [];
        $http_status = isset($resp['http_code'])
            ? (int) $resp['http_code']
            : (isset($diagnostics['http_code']) ? (int) $diagnostics['http_code'] : 0);
        $likely_cause = isset($diagnostics['likely_cause']) ? (string) $diagnostics['likely_cause'] : '';

        if ($authorized && $success) {
            return [
                'ok' => true,
                'message' => 'OK',
                'raw' => self::compact_raw_array($resp),
                'http_status' => $http_status,
                'provider_error_code' => '',
                'likely_cause' => $likely_cause,
            ];
        }

        $errors = self::implode_errors($resp);
        if ($errors === '') {
            $errors = 'Authentication was not accepted.';
        }

        return [
            'ok' => false,
            'message' => 'Lipsey authentication failed: ' . $errors,
            'raw' => self::compact_raw_array($resp),
            'http_status' => $http_status,
            'provider_error_code' => self::infer_provider_error_code_from_message($errors),
            'likely_cause' => $likely_cause,
        ];
    }

    /**
     * ValidateItem wrapper.
     *
     * Lipsey's ValidateItem accepts multiple query types:
     * - Lipsey's item number
     * - Manufacturer model number
     * - UPC
     *
     * We treat it as an availability + policy probe returning:
     * - qty
     * - blocked / allocated flags
     * - canDropship flag (critical)
     *
     * Return shape is intentionally redundant:
     * - ok/message repeat the normalized "result" top-level for convenience.
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
        $call_id  = substr(sha1($query . '|' . microtime(true) . '|' . mt_rand()), 0, 10);
        $endpoint = self::build_endpoint_url(self::VALIDATEITEM_ENDPOINT);
        $started  = microtime(true);

        self::debug_log('ValidateItem request', [
            'call_id'      => $call_id,
            'method'       => 'POST',
            'endpoint'     => $endpoint,
            'request_body' => self::sanitize_for_log($query),
            'request_meta' => self::classify_validateitem_query($query),
        ]);

        try {
            // Vendor call. May throw for transport/auth errors.
            $resp = $client->ValidateItem($query);
        } catch (\Throwable $e) {
            // Normalize as if response were missing/invalid, then annotate error.
            $norm = self::normalize_validateitem_response(null, $query);

            $msg = 'ValidateItem exception: ' . $e->getMessage();
            $norm['ok'] = false;
            $norm['message'] = $msg;

            self::debug_log('ValidateItem exception', [
                'call_id'       => $call_id,
                'method'        => 'POST',
                'endpoint'      => $endpoint,
                'query_tail4'   => self::tail4($query),
                'error_tail120' => self::tail120($e->getMessage()),
                'elapsed_ms'    => round((microtime(true) - $started) * 1000.0, 2),
            ]);

            return [
                'ok'                 => false,
                'message'            => $msg,
                'result'             => $norm,
                // Library does not consistently expose HTTP status; keep 0.
                'http_status'        => 0,
                // Best-effort code to help higher layers classify.
                'provider_error_code'=> self::infer_provider_error_code_from_message($e->getMessage()),
            ];
        }

        self::debug_log('ValidateItem response', [
            'call_id'      => $call_id,
            'method'       => 'POST',
            'endpoint'     => $endpoint,
            'elapsed_ms'   => round((microtime(true) - $started) * 1000.0, 2),
            'response'     => self::sanitize_for_log($resp),
            'responseType' => is_object($resp) ? ('object:' . get_class($resp)) : gettype($resp),
        ]);

        // Successful call (meaning: it returned a payload, not necessarily success=true).
        $norm = self::normalize_validateitem_response($resp, $query);

        return [
            'ok'                  => (bool) ($norm['ok'] ?? false),
            'message'             => (string) ($norm['message'] ?? 'OK'),
            'result'              => $norm,
            'http_status'         => 0,
            'provider_error_code' => isset($norm['provider_error_code']) ? (string) $norm['provider_error_code'] : '',
        ];
    }

    /**
     * Normalize Lipsey’s ValidateItem response into a predictable shape.
     *
     * Observed vendor patterns:
     * - Response may be object or array.
     * - Top-level keys often include:
     *     authorized: bool
     *     success: bool
     *     errors: string[] (sometimes)
     *     data: object|array (either associative "row" or list of rows)
     *
     * Normalized keys (guaranteed to exist):
     * - ok: bool
     * - message: string
     * - qty: int
     * - price: float|null
     * - blocked: bool
     * - allocated: bool
     * - itemNumber: string
     * - canDropship: bool|null
     * - http_status: int (0 here)
     * - provider_error_code: string (best-effort)
     * - raw: array|null (compact)
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
        // Vendor client often returns stdClass; convert shallowly to array.
        if (is_object($resp)) {
            // wp_json_encode handles stdClass well; decode to associative array.
            $resp = json_decode(wp_json_encode($resp), true);
        }

        // Compact debug log (gated).
        self::debug_log('ValidateItem raw (compact)', [
            'query_tail4' => self::tail4($queryValue),
            'resp'        => self::compact_value($resp),
        ]);

        // If response isn't a map, it’s invalid/unexpected.
        if (!is_array($resp)) {
            return [
                'ok'                 => false,
                'message'            => "ValidateItem returned non-array response for {$queryValue}.",
                'qty'                => 0,
                'price'              => null,
                'blocked'            => false,
                'allocated'          => false,
                'itemNumber'         => '',
                'canDropship'        => null,
                'http_status'        => 0,
                'provider_error_code'=> '',
                'raw'                => null,
            ];
        }

        // Vendor conventions:
        // - If "authorized" exists and is false => auth failure (fatal credentials).
        // - If "success" false => error (quota/rate-limit/validation/etc).
        $authorized = array_key_exists('authorized', $resp)
            ? self::to_bool_default($resp['authorized'], true)
            : true;
        $success    = array_key_exists('success', $resp)
            ? self::to_bool_default($resp['success'], false)
            : false;

        if (!$authorized) {
            $errors = self::implode_errors($resp);

            self::debug_log('ValidateItem not authorized', [
                'query_tail4'     => self::tail4($queryValue),
                'errors_tail200'  => self::tail200($errors),
            ]);

            return [
                'ok'                 => false,
                'message'            => "ValidateItem not authorized: " . ($errors !== '' ? $errors : 'Not authorized'),
                'qty'                => 0,
                'price'              => null,
                'blocked'            => false,
                'allocated'          => false,
                'itemNumber'         => '',
                'canDropship'        => null,
                'http_status'        => 0,
                'provider_error_code'=> 'NOT_AUTHORIZED',
                'raw'                => self::compact_raw_array($resp),
            ];
        }

        if (!$success) {
            $errors = self::implode_errors($resp);
            if ($errors === '') {
                $errors = 'Unknown error';
            }

            self::debug_log('ValidateItem failed', [
                'query_tail4'     => self::tail4($queryValue),
                'errors_tail200'  => self::tail200($errors),
            ]);

            return [
                'ok'                 => false,
                'message'            => "ValidateItem failed: {$errors}",
                'qty'                => 0,
                'price'              => null,
                'blocked'            => false,
                'allocated'          => false,
                'itemNumber'         => '',
                'canDropship'        => null,
                'http_status'        => 0,
                'provider_error_code'=> self::infer_provider_error_code_from_message($errors),
                'raw'                => self::compact_raw_array($resp),
            ];
        }

        // success=true but no data => schema drift or vendor bug.
        if (!isset($resp['data'])) {
            self::debug_log('ValidateItem success but missing data', [
                'query_tail4' => self::tail4($queryValue),
                'resp_keys'   => array_slice(array_keys($resp), 0, 20),
            ]);

            return [
                'ok'                 => false,
                'message'            => 'ValidateItem returned success but no data payload.',
                'qty'                => 0,
                'price'              => null,
                'blocked'            => false,
                'allocated'          => false,
                'itemNumber'         => '',
                'canDropship'        => null,
                'http_status'        => 0,
                'provider_error_code'=> 'MISSING_DATA',
                'raw'                => self::compact_raw_array($resp),
            ];
        }

        // Normalize the "data" container.
        $data = $resp['data'];
        if (is_object($data)) {
            $data = json_decode(wp_json_encode($data), true);
        }

        $row = null;

        // data as associative object (single row)
        if (is_array($data) && !self::is_list_array($data)) {
            $row = $data;
        }
        // data as list (take first row)
        elseif (is_array($data) && self::is_list_array($data)) {
            $row = (isset($data[0]) && is_array($data[0])) ? $data[0] : null;
        }

        if (!is_array($row) || empty($row)) {
            self::debug_log('ValidateItem invalid data row', [
                'query_tail4' => self::tail4($queryValue),
                'data_type'   => gettype($resp['data']),
                'resp_keys'   => array_slice(array_keys($resp), 0, 20),
            ]);

            return [
                'ok'                 => false,
                'message'            => 'ValidateItem returned invalid data row.',
                'qty'                => 0,
                'price'              => null,
                'blocked'            => false,
                'allocated'          => false,
                'itemNumber'         => '',
                'canDropship'        => null,
                'http_status'        => 0,
                'provider_error_code'=> 'INVALID_DATA_ROW',
                'raw'                => self::compact_raw_array($resp),
            ];
        }

        // Extract fields with conservative defaults.
        $qty_source = '';
        $qty = self::extract_first_int_field(
            $row,
            ['qty', 'quantity', 'availableQuantity', 'availableQty', 'onHand', 'onhand', 'inStock', 'stockQty', 'stock', 'qtyOnHand'],
            0,
            $qty_source
        );

        $price_source = '';
        $price = self::extract_first_float_field(
            $row,
            ['price', 'unitPrice', 'dealerPrice', 'cost', 'itemPrice'],
            null,
            $price_source
        );

        $blocked = self::extract_first_bool_field($row, ['blocked', 'isBlocked'], false);
        $allocated = self::extract_first_bool_field($row, ['allocated', 'isAllocated'], false);
        $itemNumber = self::extract_first_string_field($row, ['itemNumber', 'itemNo', 'item_number', 'sku'], '');

        // canDropship is critical, but may be absent in some schemas.
        $canDropship = self::extract_first_bool_field($row, ['canDropship', 'canDropShip', 'dropshipAllowed', 'dropShipAllowed'], null);

        self::debug_log('ValidateItem normalized', [
            'query_tail4'        => self::tail4($queryValue),
            'qty'               => $qty,
            'qty_source'        => $qty_source !== '' ? $qty_source : '-',
            'blocked'           => $blocked ? 1 : 0,
            'allocated'         => $allocated ? 1 : 0,
            'itemNumber_tail4'  => self::tail4($itemNumber),
            'canDropship'       => ($canDropship === null ? 'null' : ($canDropship ? 'true' : 'false')),
            'row_keys'          => array_slice(array_keys($row), 0, 20),
        ]);

        return [
            'ok'                 => true,
            'message'            => 'OK',
            'qty'                => $qty,
            'price'              => $price,
            'blocked'            => $blocked,
            'allocated'          => $allocated,
            'itemNumber'         => $itemNumber,
            'canDropship'        => $canDropship,
            'http_status'        => 0,
            'provider_error_code'=> '',
            'raw'                => self::compact_raw_array($resp),
        ];
    }

    /**
     * Normalize Lipsey’s order response shape.
     *
     * Vendor pattern (observed):
     * - Top-level:
     *     authorized: bool
     *     success: bool
     *     errors: string[] (optional)
     *     data: object|array (often contains orderNumber)
     *
     * We return:
     * - ok/message
     * - external_id: Lipsey’s order number when available; else fall back to our PO
     * - provider_error_code: best-effort based on error text
     *
     * NOTE:
     * - We do not treat "missing order number" as failure; Lipsey sometimes
     *   returns success but omits it depending on endpoint/shape drift.
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
            'resp'     => self::compact_value($resp),
        ]);

        if (!is_array($resp)) {
            return [
                'ok'                 => false,
                'message'            => "{$op} returned non-array response.",
                'external_id'        => '',
                'http_status'        => 0,
                'provider_error_code'=> '',
                'raw'                => null,
            ];
        }

        $authorized = isset($resp['authorized']) ? (bool) $resp['authorized'] : true;
        $success    = isset($resp['success']) ? (bool) $resp['success'] : false;

        if (!$authorized) {
            $errors = self::implode_errors($resp);
            if ($errors === '') {
                $errors = 'Not authorized';
            }

            return [
                'ok'                 => false,
                'message'            => "{$op} not authorized: {$errors}",
                'external_id'        => '',
                'http_status'        => 0,
                'provider_error_code'=> 'NOT_AUTHORIZED',
                'raw'                => self::compact_raw_array($resp),
            ];
        }

        if (!$success) {
            $errors = self::implode_errors($resp);
            if ($errors === '') {
                $errors = 'Unknown error';
            }

            return [
                'ok'                 => false,
                'message'            => "{$op} failed: {$errors}",
                'external_id'        => '',
                'http_status'        => 0,
                'provider_error_code'=> self::infer_provider_error_code_from_message($errors),
                'raw'                => self::compact_raw_array($resp),
            ];
        }

        // Extract order number:
        // Docs show data.orderNumber (not top-level), but we tolerate variants.
        $external = '';

        $data = $resp['data'] ?? null;
        if (is_object($data)) {
            $data = json_decode(wp_json_encode($data), true);
        }

        if (is_array($data)) {
            foreach (['orderNumber', 'OrderNumber', 'order_no', 'OrderNo'] as $k) {
                if (isset($data[$k]) && (is_string($data[$k]) || is_int($data[$k]) || is_numeric($data[$k]))) {
                    $external = trim((string) $data[$k]);
                    if ($external !== '') {
                        break;
                    }
                }
            }
        }

        // Last-resort: some responses might surface the order number at top-level.
        if ($external === '') {
            foreach (['orderNumber', 'OrderNumber', 'order_no', 'OrderNo'] as $k) {
                if (isset($resp[$k]) && (is_string($resp[$k]) || is_int($resp[$k]) || is_numeric($resp[$k]))) {
                    $external = trim((string) $resp[$k]);
                    if ($external !== '') {
                        break;
                    }
                }
            }
        }

        // If we still don't have it, keep something deterministic.
        if ($external === '') {
            $external = $po;
        }

        return [
            'ok'                 => true,
            'message'            => "{$op} OK",
            'external_id'        => $external,
            'http_status'        => 0,
            'provider_error_code'=> '',
            'raw'                => self::compact_raw_array($resp),
        ];
    }

    /* -------------------------------------------------------------------------
     * Small helpers (errors, codes, logging, safe trimming)
     * ---------------------------------------------------------------------- */

    /**
     * Flatten vendor error array into a compact string.
     *
     * We cap to 8 parts to prevent huge strings.
     */
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

    /**
     * Best-effort provider error coding based on message content.
     *
     * This is intentionally shallow:
     * - Higher layers should still do "message heuristics" for classification.
     * - Codes here are used mostly for metrics / logs / quicker branching.
     */
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
     * Parse mixed truthy/falsy value safely.
     *
     * IMPORTANT:
     * - Avoid native (bool) cast on strings: (bool) "false" === true.
     */
    private static function to_bool_default($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((float) $value) != 0.0;
        }

        if (is_string($value)) {
            $v = strtolower(trim($value));
            if ($v === '') {
                return $default;
            }
            if (in_array($v, ['1', 'true', 'yes', 'y', 'on'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'no', 'n', 'off', 'null', 'none'], true)) {
                return false;
            }
            if (is_numeric($v)) {
                return ((float) $v) != 0.0;
            }
        }

        return $default;
    }

    /**
     * Parse qty-like mixed values into int.
     * Accepts values like: 12, "12", "12.0", "12+", "Qty: 12".
     */
    private static function parse_int_mixed($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) floor($value);
        }

        if (!is_string($value)) {
            return null;
        }

        $v = trim($value);
        if ($v === '') {
            return null;
        }

        $v = str_replace(',', '', $v);

        if (is_numeric($v)) {
            return (int) floor((float) $v);
        }

        if (preg_match('/-?\d+(?:\.\d+)?/', $v, $m) === 1 && isset($m[0])) {
            return (int) floor((float) $m[0]);
        }

        return null;
    }

    private static function parse_float_mixed($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $v = trim($value);
        if ($v === '') {
            return null;
        }

        $v = str_replace(',', '', $v);

        if (is_numeric($v)) {
            return (float) $v;
        }

        if (preg_match('/-?\d+(?:\.\d+)?/', $v, $m) === 1 && isset($m[0])) {
            return (float) $m[0];
        }

        return null;
    }

    /**
     * Case-insensitive field extraction helpers.
     *
     * @param array<string,mixed> $row
     * @param string[] $keys
     */
    private static function extract_first_int_field(array $row, array $keys, int $default, string &$source = ''): int
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            $parsed = self::parse_int_mixed($row[$k]);
            if ($parsed !== null) {
                $source = $k;
                return $parsed;
            }
        }

        $lower = array_change_key_case($row, CASE_LOWER);
        foreach ($keys as $k) {
            $lk = strtolower($k);
            if (!array_key_exists($lk, $lower)) {
                continue;
            }
            $parsed = self::parse_int_mixed($lower[$lk]);
            if ($parsed !== null) {
                $source = $lk;
                return $parsed;
            }
        }

        return $default;
    }

    /**
     * @param array<string,mixed> $row
     * @param string[] $keys
     */
    private static function extract_first_float_field(array $row, array $keys, ?float $default, string &$source = ''): ?float
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            $parsed = self::parse_float_mixed($row[$k]);
            if ($parsed !== null) {
                $source = $k;
                return $parsed;
            }
        }

        $lower = array_change_key_case($row, CASE_LOWER);
        foreach ($keys as $k) {
            $lk = strtolower($k);
            if (!array_key_exists($lk, $lower)) {
                continue;
            }
            $parsed = self::parse_float_mixed($lower[$lk]);
            if ($parsed !== null) {
                $source = $lk;
                return $parsed;
            }
        }

        return $default;
    }

    /**
     * @param array<string,mixed> $row
     * @param string[] $keys
     */
    private static function extract_first_bool_field(array $row, array $keys, ?bool $default): ?bool
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            return self::to_bool_default($row[$k], (bool) $default);
        }

        $lower = array_change_key_case($row, CASE_LOWER);
        foreach ($keys as $k) {
            $lk = strtolower($k);
            if (!array_key_exists($lk, $lower)) {
                continue;
            }
            return self::to_bool_default($lower[$lk], (bool) $default);
        }

        return $default;
    }

    /**
     * @param array<string,mixed> $row
     * @param string[] $keys
     */
    private static function extract_first_string_field(array $row, array $keys, string $default): string
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            $v = trim((string) $row[$k]);
            if ($v !== '') {
                return $v;
            }
        }

        $lower = array_change_key_case($row, CASE_LOWER);
        foreach ($keys as $k) {
            $lk = strtolower($k);
            if (!array_key_exists($lk, $lower)) {
                continue;
            }
            $v = trim((string) $lower[$lk]);
            if ($v !== '') {
                return $v;
            }
        }

        return $default;
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
                'type'  => 'array',
                'keys'  => array_slice(array_keys($v), 0, 20),
                'count' => count($v),
            ];
        }
        return ['type' => gettype($v)];
    }

    /**
     * Build a known endpoint URL for debug logging.
     */
    private static function build_endpoint_url(string $path): string
    {
        return rtrim(self::API_BASE_URL, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Classify ValidateItem query shape for easier debugging.
     *
     * @return array<string,mixed>
     */
    private static function classify_validateitem_query(string $query): array
    {
        $trimmed = trim($query);
        $digits  = preg_replace('/\D+/', '', $trimmed);
        if (!is_string($digits)) {
            $digits = '';
        }

        return [
            'len'            => strlen($trimmed),
            'digits_len'     => strlen($digits),
            'is_all_digits'  => (preg_match('/^\d+$/', $trimmed) === 1) ? 1 : 0,
            'looks_like_upc' => (preg_match('/^\d{10,14}$/', $trimmed) === 1) ? 1 : 0,
            'tail4'          => self::tail4($trimmed),
        ];
    }

    /**
     * Recursively sanitize values before logging.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function sanitize_for_log($value, int $depth = 0)
    {
        if ($depth >= 5) {
            return '[max_depth]';
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return self::sanitize_log_string($value, 1200);
        }

        if (is_object($value)) {
            $arr = json_decode(wp_json_encode($value), true);
            if (!is_array($arr)) {
                return '[object:' . get_class($value) . ']';
            }
            return self::sanitize_for_log($arr, $depth + 1);
        }

        if (is_array($value)) {
            $is_list = self::is_list_array($value);
            $limit   = $is_list ? 20 : 40;
            $items   = [];
            $count   = 0;

            foreach ($value as $k => $v) {
                $count++;
                if ($count > $limit) {
                    break;
                }
                $items[$k] = self::sanitize_for_log($v, $depth + 1);
            }

            if (count($value) > $limit) {
                $items['_truncated_count'] = count($value) - $limit;
            }

            return $items;
        }

        return '[type:' . gettype($value) . ']';
    }

    private static function sanitize_log_string(string $value, int $maxLen = 1200): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Generic key/value secret masking.
        $value = preg_replace('/("?(?:password|token|authorization|api[_-]?key|secret)"?\s*[:=]\s*")([^"]*)(")/i', '$1[redacted]$3', $value);
        if (!is_string($value)) {
            $value = '';
        }

        // Vendor-specific auth error shape includes plaintext credentials.
        $value = preg_replace('/(Credentials Provided:\s*[^,]*,\s*)([^\|\r\n]+)/i', '$1[redacted]', $value);
        if (!is_string($value)) {
            $value = '';
        }

        if ($maxLen > 0 && strlen($value) > $maxLen) {
            return substr($value, 0, $maxLen) . '... [truncated]';
        }

        return $value;
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

        // If errors is huge, trim.
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

    /**
     * Debug logging for Lipsey's integration.
     *
     * IMPORTANT:
     * - This is intentionally independent from DistributorLipseys::dbg_enabled().
     * - If you want unified gating, you can later route both to a shared DebugLogUtil.
     */
    private static function debug_log(string $message, array $context = []): void
    {
        if (!empty($context)) {
            DebugLogUtil::log_ctx('FFLHUB_LIPSEYS_DEBUG', '[FFLHub][LipseysAPI]', $message, $context);
            return;
        }

        DebugLogUtil::log('FFLHUB_LIPSEYS_DEBUG', '[FFLHub][LipseysAPI]', $message);
    }

    /* --- tail helpers: keep logs safe --- */

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
     *
     * Note: PHP 8.1+ has array_is_list(). If you ever bump minimum PHP,
     * you can replace this with array_is_list($arr).
     */
    private static function is_list_array(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}


