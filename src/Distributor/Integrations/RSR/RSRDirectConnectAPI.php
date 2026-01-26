<?php
// File: src/Distributor/Integrations/RSR/RSRDirectConnectAPI.php

namespace FFLHub\Distributor\Integrations\RSR;

use FFLHub\Distributor\Models\DistributorShipTo;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RSR DirectConnect API helper (static).
 *
 * Centralizes:
 *  - URL building
 *  - wp_remote_* request boilerplate
 *  - response decode + StatusCode/StatusMssg parsing
 *  - standard return shape
 *  - RSR payload rules / formatting helpers (PO rules, ship context)
 *  - RSR image URL generation + probing
 *
 * NOTE:
 * This file threads `http_status` through responses so job workers
 * can reliably classify retryable vs fatal conditions.
 */
final class RSRDirectConnectAPI
{
    public const DEFAULT_API_BASE_URL = 'https://www.rsrgroup.com';

    public const PLACE_ORDER_PATH    = '/api/rsrbridge/1.0/pos/place-order';
    public const CHECK_CATALOG_PATH  = '/api/rsrbridge/1.0/pos/check-catalog';

    /**
     * Place order wrapper.
     *
     * @return array{
     *   ok:bool,
     *   message:string,
     *   external_id:string,
     *   raw:array|null,
     *   http_status:int,
     *   rsr_status_code:string,
     *   rsr_status_msg:string
     * }
     */
    public static function place_order(array $payload, ?string $base_url = null, int $timeout = 60): array
    {
        $url = self::build_url($base_url, self::PLACE_ORDER_PATH);
        return self::post_json_and_parse_standard_response($url, $payload, $timeout);
    }

    /**
     * check-catalog wrapper.
     *
     * @return array{
     *   ok:bool,
     *   message:string,
     *   items:array<int,array<string,mixed>>,
     *   raw:array|null,
     *   http_status:int
     * }
     */
    public static function check_catalog(array $payload, ?string $base_url = null, int $timeout = 60): array
    {
        $url = self::build_url($base_url, self::CHECK_CATALOG_PATH);

        $res = self::post_json($url, $payload, $timeout);
        if (!$res['ok']) {
            return [
                'ok' => false,
                'message' => $res['message'],
                'items' => [],
                'raw' => $res['raw'],
                'http_status' => isset($res['http_status']) ? (int) $res['http_status'] : 0,
            ];
        }

        $decoded = $res['raw'];
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'message' => 'Invalid JSON response from RSR.',
                'items' => [],
                'raw' => null,
                'http_status' => isset($res['http_status']) ? (int) $res['http_status'] : 0,
            ];
        }

        $items = [];
        if (isset($decoded['Items']) && is_array($decoded['Items'])) {
            $items = $decoded['Items'];
        } elseif (isset($decoded['Response']) && is_array($decoded['Response']) && isset($decoded['Response']['Items']) && is_array($decoded['Response']['Items'])) {
            $items = $decoded['Response']['Items'];
        }

        $normalized = self::normalize_check_catalog_items($items);

        $invalid = [];
        foreach ($normalized as $it) {
            $code = (string)($it['StatusCode'] ?? '');
            if (!self::is_check_catalog_status_valid_for_customer_checkout($code)) {
                $invalid[] = $it;
            }
        }

        if (!empty($invalid)) {
            $msg_parts = [];
            foreach (array_slice($invalid, 0, 5) as $bad) {
                $code = (string)($bad['StatusCode'] ?? '');
                $m    = (string)($bad['StatusMssg'] ?? '');
                $p    = (string)($bad['PartNum'] ?? '');
                $u    = (string)($bad['UPC'] ?? '');

                $u_tail = self::tail4($u);
                $msg_parts[] = trim("[$code] $m (PartNum=$p UPC=**$u_tail)");
            }

            $msg = 'RSR check-catalog restriction: ' . implode(' | ', $msg_parts);
            if (count($invalid) > 5) {
                $msg .= ' | ...';
            }

            // Hint retryability for maintenance/fatal ("87") scenarios (spec: try again later)
            // so higher layers that use string heuristics will treat this as retryable.
            foreach ($invalid as $bad) {
                $code = (string)($bad['StatusCode'] ?? '');
                if (trim($code) === '87') {
                    $msg = 'RSR check-catalog temporary maintenance/fatal error (try again later): ' . $msg;
                    break;
                }
            }

            return [
                'ok' => false,
                'message' => $msg,
                'items' => $normalized,
                'raw' => $decoded,
                'http_status' => isset($res['http_status']) ? (int) $res['http_status'] : 0,
            ];
        }

        return [
            'ok' => true,
            'message' => 'OK',
            'items' => $normalized,
            'raw' => $decoded,
            'http_status' => isset($res['http_status']) ? (int) $res['http_status'] : 0,
        ];
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,array<string,mixed>>
     */
    public static function normalize_check_catalog_items(array $items): array
    {
        $out = [];

        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = [
                'PartNum'       => $row['PartNum'] ?? '',
                'UPC'           => $row['UPC'] ?? ($row['UPCcode'] ?? ''),
                'PartDesc'      => $row['PartDesc'] ?? '',
                'PartTitle'     => $row['PartTitle'] ?? '',
                'Category'      => $row['Category'] ?? '',
                'CCost'         => $row['CCost'] ?? '',
                'OnHand'        => $row['OnHand'] ?? '',
                'StatusCode'    => $row['StatusCode'] ?? '',
                'StatusMssg'    => $row['StatusMssg'] ?? '',
                'FFLErrCode'    => $row['FFLErrCode'] ?? '',
                'FFLErrMsg'     => $row['FFLErrMsg'] ?? '',
                'MAP'           => $row['MAP'] ?? '',
                'MSRP'          => $row['MSRP'] ?? '',
                'Manufacturer'  => $row['Manufacturer'] ?? '',
                'ManPartNum'    => $row['ManPartNum'] ?? '',
                'NFA'           => $row['NFA'] ?? '',
                'UOM'           => $row['UOM'] ?? '',
                'DS'            => $row['DS'] ?? '',
                '_raw'          => $row,
            ];
        }

        return $out;
    }

    public static function is_check_catalog_status_valid_for_customer_checkout(string $status_code): bool
    {
        $status_code = trim($status_code);
        return ($status_code === '00' || $status_code === '01');
    }

    /**
     * Generic JSON POST for RSR endpoints that return standard StatusCode/StatusMssg.
     *
     * @return array{
     *   ok:bool,
     *   message:string,
     *   external_id:string,
     *   raw:array|null,
     *   http_status:int,
     *   rsr_status_code:string,
     *   rsr_status_msg:string
     * }
     */
    public static function post_json_and_parse_standard_response(string $url, array $payload, int $timeout = 60): array
    {
        $res = self::post_json($url, $payload, $timeout);

        $http_status = isset($res['http_status']) ? (int) $res['http_status'] : 0;

        if (!$res['ok']) {
            return [
                'ok' => false,
                'message' => $res['message'],
                'external_id' => '',
                'raw' => $res['raw'],
                'http_status' => $http_status,
                'rsr_status_code' => '',
                'rsr_status_msg' => '',
            ];
        }

        $decoded = $res['raw'];
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'message' => 'Invalid JSON response from RSR.',
                'external_id' => '',
                'raw' => null,
                'http_status' => $http_status,
                'rsr_status_code' => '',
                'rsr_status_msg' => '',
            ];
        }

        $status_code = isset($decoded['StatusCode']) ? (string) $decoded['StatusCode'] : '';
        $status_msg  = isset($decoded['StatusMssg']) ? (string) $decoded['StatusMssg'] : '';

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
                'http_status' => $http_status,
                'rsr_status_code' => (string) $status_code,
                'rsr_status_msg' => (string) $status_msg,
            ];
        }

        $external = self::extract_external_id($decoded);
        if ($external === '') {
            $external = 'RSR-' . gmdate('Ymd-His');
        }

        return [
            'ok' => true,
            'message' => 'OK',
            'external_id' => $external,
            'raw' => $decoded,
            'http_status' => $http_status,
            'rsr_status_code' => (string) $status_code,
            'rsr_status_msg' => (string) $status_msg,
        ];
    }

    /**
     * Low-level JSON POST.
     *
     * NOTE: Even on non-2xx, attempt to decode JSON so callers can inspect structured errors.
     *
     * @return array{
     *   ok:bool,
     *   message:string,
     *   raw:array|null,
     *   http_status:int,
     *   body_snippet:string
     * }
     */
    public static function post_json(string $url, array $payload, int $timeout = 60): array
    {


        // Log the exact structure being sent (as PHP array -> pretty JSON)
        self::log_request_payload($url, $payload);

        $json_body = wp_json_encode($payload);

        // Optional: log the exact final JSON string being sent (RAW only)
        if (is_string($json_body)) {
            self::log_request_body_json($json_body);
        }
        $args = [
            'timeout' => $timeout,
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
                'raw' => null,
                'http_status' => 0,
                'body_snippet' => '',
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $body = (string) wp_remote_retrieve_body($res);
        $snippet = $body !== '' ? substr($body, 0, 300) : '';

        // Try decode regardless of status code (helps diagnostics on 4xx/5xx).
        $decoded = null;
        if ($body !== '') {
            $tmp = json_decode((string) $body, true);
            if (is_array($tmp)) {
                $decoded = $tmp;
            }
        }

        if ($code < 200 || $code >= 300) {
            return [
                'ok' => false,
                'message' => 'HTTP status ' . (string) $code . ': ' . $snippet,
                'raw' => $decoded, // may be null
                'http_status' => $code,
                'body_snippet' => $snippet,
            ];
        }

        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'message' => 'Invalid JSON response from RSR.',
                'raw' => null,
                'http_status' => $code,
                'body_snippet' => $snippet,
            ];
        }

        return [
            'ok' => true,
            'message' => 'OK',
            'raw' => $decoded,
            'http_status' => $code,
            'body_snippet' => $snippet,
        ];
    }

    public static function build_url(?string $base_url, string $path): string
    {
        $base = is_string($base_url) ? trim($base_url) : '';
        if ($base === '') {
            $base = self::DEFAULT_API_BASE_URL;
        }
        $base = rtrim($base, '/');

        $path = '/' . ltrim($path, '/');

        return $base . $path;
    }

    public static function extract_external_id(array $decoded): string
    {
        foreach (['ConfirmResp', 'WebRef'] as $k) {
            if (isset($decoded[$k]) && is_string($decoded[$k]) && trim($decoded[$k]) !== '') {
                return trim($decoded[$k]);
            }
        }

        if (isset($decoded['ConfirmResp']) && is_numeric($decoded['ConfirmResp'])) {
            return (string) $decoded['ConfirmResp'];
        }

        return '';
    }

    /* ============================================================
     * RSR payload helpers
     * ============================================================ */

    public static function sanitize_rsr_po(string $po): string
    {
        $po = trim($po);
        if ($po === '') {
            return '';
        }

        $po = preg_replace('/[^A-Za-z0-9 \-]+/', '-', $po);
        $po = is_string($po) ? $po : '';

        $po = preg_replace('/\s+/', ' ', $po);
        $po = is_string($po) ? trim($po) : '';

        return self::truncate_po($po);
    }

    public static function truncate_po(string $po): string
    {
        $po = trim($po);
        if ($po === '') {
            return '';
        }
        if (strlen($po) > 22) {
            $po = substr($po, 0, 22);
        }
        return $po;
    }

    public static function build_ship_context_payload(DistributorShipTo $dest_ship, DistributorShipTo $customer_identity): array
    {
        // Spec limits:
        // - Storename/StoreName: 25
        // - ContactNum: 20
        // - ShipAddress/ShipAddress2: 35
        // - ShipCity: 25
        // - ShipState: 2
        // - ShipZip: 10 (formatted 12345 or 12345-6789)
        $customer_name  = self::normalize_payload_string($customer_identity->name, 25);
        $customer_phone = self::normalize_payload_string($customer_identity->phone, 20);

        if ($customer_name === '') {
            $customer_name = 'Customer';
        }

        return [
            // RSR docs are inconsistent on casing: include both.
            'StoreName'  => $customer_name,
            'Storename'  => $customer_name,

            'ContactNum' => $customer_phone,

            'ShipAddress'  => self::normalize_payload_string($dest_ship->address1, 35),
            'ShipAddress2' => self::normalize_payload_string($dest_ship->address2, 35),
            'ShipCity'     => self::normalize_payload_string($dest_ship->city, 25),
            'ShipState'    => self::normalize_us_state_code_for_payload($dest_ship->state),
            'ShipZip'      => self::format_us_zip5_or_zip9_with_dash_for_payload($dest_ship->zip),
        ];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public static function validate_ship_to_required_fields(DistributorShipTo $dest_ship): array
    {
        $missing = [];

        if (trim((string) $dest_ship->address1) === '') {
            $missing[] = 'ShipAddress';
        }
        if (trim((string) $dest_ship->city) === '') {
            $missing[] = 'ShipCity';
        }
        if (trim((string) $dest_ship->state) === '') {
            $missing[] = 'ShipState';
        }
        if (trim((string) $dest_ship->zip) === '') {
            $missing[] = 'ShipZip';
        }

        if (!empty($missing)) {
            return ['ok' => false, 'message' => 'Missing required ship-to fields: ' . implode(', ', $missing)];
        }

        return ['ok' => true, 'message' => 'OK'];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public static function validate_customer_identity_for_firearm_dropship(DistributorShipTo $customer): array
    {
        $phone = trim((string) $customer->phone);
        if ($phone === '') {
            return ['ok' => false, 'message' => 'Missing customer phone number (ContactNum required for firearm dropship).'];
        }
        return ['ok' => true, 'message' => 'OK'];
    }

    public static function normalize_payload_string(?string $s, int $max_len = 0): string
    {
        $s = is_string($s) ? trim($s) : '';
        if ($max_len > 0 && $s !== '' && strlen($s) > $max_len) {
            $s = substr($s, 0, $max_len);
        }
        return $s;
    }

    public static function normalize_us_state_code_for_payload(?string $state): string
    {
        $state = is_string($state) ? strtoupper(trim($state)) : '';
        if (strlen($state) > 2) {
            $state = substr($state, 0, 2);
        }
        return $state;
    }

    public static function format_us_zip5_or_zip9_with_dash_for_payload(?string $zip): string
    {
        $zip = is_string($zip) ? trim($zip) : '';
        if ($zip === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $zip);
        $digits = is_string($digits) ? $digits : '';

        if (strlen($digits) === 9) {
            return substr($digits, 0, 5) . '-' . substr($digits, 5, 4);
        }

        if (strlen($digits) >= 5) {
            return substr($digits, 0, 5);
        }

        // Worst-case fallback (but cap to 10 chars per spec)
        return (strlen($zip) > 10) ? substr($zip, 0, 10) : $zip;
    }

    /**
     * Return last 4 digits/characters (for log-safe identifiers).
     */
    private static function tail4(string $s): string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return '';
        }
        return (strlen($s) >= 4) ? substr($s, -4) : $s;
    }

    /* ============================================================
     * Image helpers
     * ============================================================ */

    public static function build_image_urls_from_image_name(string $image_name): array
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

                if (! self::is_real_image_url($url)) {
                    break;
                }

                $urls[] = $url;
            }
        } else {
            $urls[] = $base_prefix . ltrim($image_name, '/');
        }

        return array_values(array_unique($urls));
    }

    public static function is_real_image_url(string $url): bool
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

        // RSR placeholder
        if ($width === 110 && $height === 85) {
            return false;
        }

        return true;
    }




    /**
     * Enable RSR request payload logging.
     *
     * In wp-config.php (preferred):
     *   define('FFLHUB_RSR_API_DEBUG', true);
     *
     * Optional (dangerous): log raw payload (no redaction).
     *   define('FFLHUB_RSR_API_DEBUG_RAW', true);
     *
     * Or env vars:
     *   FFLHUB_RSR_API_DEBUG=1
     *   FFLHUB_RSR_API_DEBUG_RAW=1
     */
    private const DEBUG_CONST     = 'FFLHUB_RSR_API_DEBUG';
    private const DEBUG_RAW_CONST = 'FFLHUB_RSR_API_DEBUG_RAW';

    private static function debug_enabled(): bool
    {
        if (defined(self::DEBUG_CONST) && constant(self::DEBUG_CONST)) {
            return true;
        }

        $env = getenv('FFLHUB_RSR_API_DEBUG');
        return ($env !== false && $env !== '' && $env !== '0');
    }

    private static function debug_raw_enabled(): bool
    {
        if (defined(self::DEBUG_RAW_CONST) && constant(self::DEBUG_RAW_CONST)) {
            return true;
        }

        $env = getenv('FFLHUB_RSR_API_DEBUG_RAW');
        return ($env !== false && $env !== '' && $env !== '0');
    }

    /**
     * Pretty JSON for logs (stable + readable).
     */
    private static function json_for_log($value): string
    {
        $json = wp_json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return is_string($json) ? $json : '(json_encode_failed)';
    }

    /**
     * Redact sensitive keys recursively (creds + obvious PII).
     * Default behavior: redact. You can opt into RAW logging for local debugging.
     */
    private static function redact_payload_for_log(array $payload): array
    {
        // Keys to redact wherever they appear (case-insensitive).
        $redact_keys = [
            'Username',
            'Password',

            // Customer identity / address-ish fields
            'ContactNum',
            'ShipAddress',
            'ShipAddress2',
            'ShipCity',
            'ShipState',
            'ShipZip',
            'StoreName',
            'Storename',

            // If you ever pass these:
            'Email',
            'Phone',
        ];

        $out = $payload;

        $walk = function (&$node) use (&$walk, $redact_keys) {
            if (!is_array($node)) {
                return;
            }

            foreach ($node as $k => &$v) {
                if (is_string($k)) {
                    foreach ($redact_keys as $rk) {
                        if (strcasecmp($k, $rk) === 0) {
                            if (is_string($v) && $v !== '') {
                                $v = '[REDACTED len=' . strlen($v) . ']';
                            } elseif (!empty($v)) {
                                $v = '[REDACTED]';
                            } else {
                                $v = '[REDACTED]';
                            }
                            continue 2;
                        }
                    }
                }

                if (is_array($v)) {
                    $walk($v);
                }
            }
        };

        $walk($out);

        return $out;
    }

    private static function log_request_payload(string $url, array $payload): void
    {
        if (!self::debug_enabled()) {
            return;
        }

        $raw = self::debug_raw_enabled();

        $to_log = $raw ? $payload : self::redact_payload_for_log($payload);

        error_log('[FFLHub RSR API] POST ' . $url);
        error_log('[FFLHub RSR API] Payload' . ($raw ? ' (RAW)' : ' (REDACTED)') . ":\n" . self::json_for_log($to_log));
    }

    private static function log_request_body_json(string $json_body): void
    {
        if (!self::debug_enabled()) {
            return;
        }

        // Only log the final JSON body if RAW logging is enabled (otherwise it may contain PII/creds).
        if (!self::debug_raw_enabled()) {
            return;
        }

        error_log("[FFLHub RSR API] Body JSON (RAW):\n" . $json_body);
    }
}
