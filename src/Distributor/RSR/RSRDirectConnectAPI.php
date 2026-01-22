<?php

namespace FFLHub\Distributor\RSR;

use FFLHub\Distributor\Product\DistributorShipTo;

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
 *  - RSR image URL generation + probing (to keep DistributorRSR smaller)
 */
final class RSRDirectConnectAPI
{
    public const DEFAULT_API_BASE_URL = 'https://www.rsrgroup.com';

    // Endpoints (paths)
    public const PLACE_ORDER_PATH    = '/api/rsrbridge/1.0/pos/place-order';
    public const CHECK_CATALOG_PATH  = '/api/rsrbridge/1.0/pos/check-catalog';

    /* ============================================================
     * Endpoint wrappers
     * ============================================================
     */

    /**
     * Place order wrapper.
     *
     * @return array{ok:bool,message:string,external_id:string,raw:array|null}
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
     *   raw:array|null
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
            ];
        }

        $decoded = $res['raw'];
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'message' => 'Invalid JSON response from RSR.',
                'items' => [],
                'raw' => null,
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
                $msg_parts[] = trim("[$code] $m (PartNum=$p UPC=$u)");
            }

            $msg = 'RSR check-catalog restriction: ' . implode(' | ', $msg_parts);
            if (count($invalid) > 5) {
                $msg .= ' | ...';
            }

            return [
                'ok' => false,
                'message' => $msg,
                'items' => $normalized,
                'raw' => $decoded,
            ];
        }

        return [
            'ok' => true,
            'message' => 'OK',
            'items' => $normalized,
            'raw' => $decoded,
        ];
    }

    /* ============================================================
     * Response normalization helpers
     * ============================================================
     */

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

    /* ============================================================
     * HTTP helpers
     * ============================================================
     */

    /**
     * Generic JSON POST for RSR endpoints that return the standard StatusCode/StatusMssg format.
     *
     * @return array{ok:bool,message:string,external_id:string,raw:array|null}
     */
    public static function post_json_and_parse_standard_response(string $url, array $payload, int $timeout = 60): array
    {
        $res = self::post_json($url, $payload, $timeout);

        if (!$res['ok']) {
            return [
                'ok' => false,
                'message' => $res['message'],
                'external_id' => '',
                'raw' => $res['raw'],
            ];
        }

        $decoded = $res['raw'];
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'message' => 'Invalid JSON response from RSR.',
                'external_id' => '',
                'raw' => null,
            ];
        }

        $status_code = isset($decoded['StatusCode']) ? (string) $decoded['StatusCode'] : '';
        $status_msg  = isset($decoded['StatusMssg']) ? (string) $decoded['StatusMssg'] : '';

        // Some wrappers might nest.
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

        $external = self::extract_external_id($decoded);
        if ($external === '') {
            $external = 'RSR-' . gmdate('Ymd-His');
        }

        return [
            'ok' => true,
            'message' => 'OK',
            'external_id' => $external,
            'raw' => $decoded,
        ];
    }

    /**
     * Low-level JSON POST.
     *
     * @return array{ok:bool,message:string,raw:array|null}
     */
    public static function post_json(string $url, array $payload, int $timeout = 60): array
    {
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
            ];
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);

        if ($code < 200 || $code >= 300) {
            return [
                'ok' => false,
                'message' => 'HTTP status ' . (string) $code . ': ' . substr((string) $body, 0, 300),
                'raw' => null,
            ];
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'message' => 'Invalid JSON response from RSR.',
                'raw' => null,
            ];
        }

        return [
            'ok' => true,
            'message' => 'OK',
            'raw' => $decoded,
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

    /**
     * Pulls the best candidate confirmation/reference id from typical RSR responses.
     */
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
     * RSR payload rules & validation (moved from DistributorRSR)
     * ============================================================
     */

    /**
     * PONum max 22 chars. Only allow letters/numbers/space/dash.
     */
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

    /**
     * Build the shared “drop-ship / geolocation” ship context payload used by RSR endpoints.
     *
     * @return array<string,string>
     */
    public static function build_ship_context_payload(DistributorShipTo $dest_ship, DistributorShipTo $customer_identity): array
    {
        $customer_name  = trim((string) $customer_identity->name);
        $customer_phone = trim((string) $customer_identity->phone);

        if ($customer_name === '') {
            $customer_name = 'Customer';
        }

        return [
            'StoreName'  => $customer_name,
            'ContactNum' => $customer_phone,

            'ShipAddress'  => self::normalize_payload_string($dest_ship->address1),
            'ShipAddress2' => self::normalize_payload_string($dest_ship->address2),
            'ShipCity'     => self::normalize_payload_string($dest_ship->city),
            'ShipState'    => self::normalize_us_state_code_for_payload($dest_ship->state),
            'ShipZip'      => self::format_us_zip5_or_zip9_with_dash_for_payload($dest_ship->zip),
        ];
    }

    /**
     * Validate the minimum ship-to fields required for RSR geolocation / drop-ship checks.
     *
     * @return array{ok:bool,message:string}
     */
    public static function validate_ship_to_required_fields(DistributorShipTo $dest_ship): array
    {
        $missing = [];

        if (trim((string) $dest_ship->address1) === '') { $missing[] = 'ShipAddress'; }
        if (trim((string) $dest_ship->city) === '')     { $missing[] = 'ShipCity'; }
        if (trim((string) $dest_ship->state) === '')    { $missing[] = 'ShipState'; }
        if (trim((string) $dest_ship->zip) === '')      { $missing[] = 'ShipZip'; }

        if (!empty($missing)) {
            return ['ok' => false, 'message' => 'Missing required ship-to fields: ' . implode(', ', $missing)];
        }

        return ['ok' => true, 'message' => 'OK'];
    }

    /**
     * For firearm drop ship, RSR requires end-consumer phone (StatusCode 82 otherwise).
     *
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

    /* ============================================================
     * Simple payload normalization helpers (API-side)
     * ============================================================
     */

    public static function normalize_payload_string(?string $s): string
    {
        $s = is_string($s) ? trim($s) : '';
        // Keep it simple; RSR has field length limits but we won't hard-truncate here unless you want it.
        return $s;
    }

    public static function normalize_us_state_code_for_payload(?string $state): string
    {
        $state = is_string($state) ? strtoupper(trim($state)) : '';
        // If someone passed "Louisiana", the API will likely reject; we keep 2-char behavior.
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

        // Remove everything except digits
        $digits = preg_replace('/\D+/', '', $zip);
        $digits = is_string($digits) ? $digits : '';

        if (strlen($digits) === 9) {
            return substr($digits, 0, 5) . '-' . substr($digits, 5, 4);
        }

        if (strlen($digits) >= 5) {
            return substr($digits, 0, 5);
        }

        // If it’s weird, return original trimmed value (RSR may reject, but we preserve intent)
        return $zip;
    }

    /* ============================================================
     * RSR image URL generation + probing (moved from DistributorRSR)
     * ============================================================
     */

    /**
     * Given an RSR image_name like "LAS981-0054_1.jpg", generate all real
     * product image URLs for that item, stopping when we hit the generic
     * "image coming soon" placeholder (110x85).
     *
     * @return string[]
     */
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

    /**
     * Check whether the given RSR image URL is a real product image
     * and NOT the generic "image coming soon" placeholder.
     */
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
}
