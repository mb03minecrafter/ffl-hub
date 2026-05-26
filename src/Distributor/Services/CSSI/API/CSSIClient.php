<?php

namespace FFLHub\Distributor\Services\CSSI\API;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Util\DebugLogUtil;

/**
 * Chattanooga Shooting Supplies (CSSI) REST client.
 *
 * This intentionally uses the same auth/header shape as the known-good curl probe:
 *   Authorization: Basic <SID>:<md5(token)>
 */
final class CSSIClient
{
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][CSSIClient]';
    private const DEFAULT_BASE_URL = 'https://api.chattanoogashooting.com/rest/v5/';

    private string $sid;
    private string $token;
    private string $baseUrl;

    public function __construct(string $sid, string $token, string $baseUrl = self::DEFAULT_BASE_URL)
    {
        $this->sid = trim($sid);
        $this->token = trim($token);
        $this->baseUrl = rtrim(trim($baseUrl), '/') . '/';
    }

    public function has_credentials(): bool
    {
        return $this->sid !== '' && $this->token !== '';
    }

    /**
     * Read-only credential probe. A valid response must look like the expected
     * CSSI items endpoint shape; a generic 2xx JSON payload is not enough.
     *
     * @return array<string,mixed>
     */
    public function test_credentials(int $timeout = 30): array
    {
        $t0 = microtime(true);
        $timeout = max(10, min(60, (int) $timeout));

        $this->log('Credential test request start', [
            'endpoint' => 'items',
            'per_page' => 1,
        ]);

        $res = $this->request_json('GET', 'items', [
            'page' => 1,
            'per_page' => 1,
        ], null, $timeout);

        if (!(bool) ($res['ok'] ?? false)) {
            $this->profile('Credential test request failed', $t0, [
                'status' => (int) ($res['status'] ?? 0),
                'error' => (string) ($res['error'] ?? 'Unknown error'),
            ]);
            return $res;
        }

        $data = is_array($res['data'] ?? null) ? (array) $res['data'] : [];
        $items = isset($data['items']) && is_array($data['items']) ? (array) $data['items'] : [];
        $pagination = isset($data['pagination']) && is_array($data['pagination']) ? (array) $data['pagination'] : [];

        $res['items'] = $items;
        $res['pagination'] = [
            'page' => (int) ($pagination['page'] ?? 1),
            'per_page' => (int) ($pagination['per_page'] ?? 1),
            'page_count' => (int) ($pagination['page_count'] ?? 1),
        ];
        $res['credentials_confirmed'] = array_key_exists('items', $data) && is_array($data['items']);

        $this->profile('Credential test request complete', $t0, [
            'status' => (int) ($res['status'] ?? 0),
            'confirmed' => !empty($res['credentials_confirmed']) ? 1 : 0,
            'item_count' => count($items),
            'data_keys' => array_values(array_map('strval', array_slice(array_keys($data), 0, 12))),
        ]);

        return $res;
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function get_items_page(int $page = 1, int $perPage = 50, array $query = []): array
    {
        $t0 = microtime(true);

        $page = max(1, (int) $page);
        $perPage = max(1, min(50, (int) $perPage));
        $query = array_merge($query, [
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $this->log('Items page request start', [
            'page' => $page,
            'per_page' => $perPage,
            'query_keys' => array_values(array_map('strval', array_keys($query))),
        ]);

        $res = $this->request_json('GET', 'items', $query);
        if (!(bool) ($res['ok'] ?? false)) {
            $this->profile('Items page request failed', $t0, [
                'page' => $page,
                'status' => (int) ($res['status'] ?? 0),
                'error' => (string) ($res['error'] ?? 'Unknown error'),
            ]);
            return $res;
        }

        $data = is_array($res['data'] ?? null) ? (array) $res['data'] : [];
        $items = isset($data['items']) && is_array($data['items']) ? (array) $data['items'] : [];
        $pagination = isset($data['pagination']) && is_array($data['pagination']) ? (array) $data['pagination'] : [];

        $res['items'] = $items;
        $res['pagination'] = [
            'page' => (int) ($pagination['page'] ?? $page),
            'per_page' => (int) ($pagination['per_page'] ?? $perPage),
            'page_count' => (int) ($pagination['page_count'] ?? 1),
        ];

        $this->profile('Items page request complete', $t0, [
            'status' => (int) ($res['status'] ?? 0),
            'page' => (int) ($res['pagination']['page'] ?? $page),
            'page_count' => (int) ($res['pagination']['page_count'] ?? 1),
            'item_count' => count($items),
        ]);

        return $res;
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function get_product_feed_url(array $query = []): array
    {
        $t0 = microtime(true);

        $this->log('Product-feed URL request start', [
            'query_keys' => array_values(array_map('strval', array_keys($query))),
        ]);

        $res = $this->request_json('GET', 'items/product-feed', $query);
        if (!(bool) ($res['ok'] ?? false)) {
            $this->profile('Product-feed URL request failed', $t0, [
                'status' => (int) ($res['status'] ?? 0),
                'error' => (string) ($res['error'] ?? 'Unknown error'),
            ]);
            return $res;
        }

        $data = is_array($res['data'] ?? null) ? (array) $res['data'] : [];
        $url = '';
        if (isset($data['product_feed']) && is_array($data['product_feed'])) {
            $url = trim((string) ($data['product_feed']['url'] ?? ''));
        }
        $normalizedUrl = $this->normalize_feed_file_url($url);

        if ($url === '') {
            $out = [
                'ok' => false,
                'status' => (int) ($res['status'] ?? 0),
                'error' => 'CSSI product-feed response did not contain product_feed.url.',
                'data' => $data,
            ];

            $this->profile('Product-feed URL parse failed', $t0, [
                'status' => (int) ($out['status'] ?? 0),
                'error' => (string) ($out['error'] ?? ''),
            ]);

            return $out;
        }

        $out = [
            'ok' => true,
            'status' => (int) ($res['status'] ?? 200),
            'url' => $normalizedUrl,
            'data' => $data,
        ];

        $this->profile('Product-feed URL request complete', $t0, [
            'status' => (int) ($out['status'] ?? 0),
            'url_head' => $this->truncate($normalizedUrl, 220),
            'raw_url_head' => $this->truncate($url, 220),
        ]);

        return $out;
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function get_orders(array $query = []): array
    {
        $t0 = microtime(true);

        if (isset($query['page'])) {
            $query['page'] = max(1, (int) $query['page']);
        }
        if (isset($query['per_page'])) {
            $query['per_page'] = max(1, min(20, (int) $query['per_page']));
        }

        $this->log('Orders request start', [
            'query_keys' => array_values(array_map('strval', array_keys($query))),
        ]);

        $res = $this->request_json('GET', 'orders', $query);
        if (!(bool) ($res['ok'] ?? false)) {
            $this->profile('Orders request failed', $t0, [
                'status' => (int) ($res['status'] ?? 0),
                'error' => (string) ($res['error'] ?? 'Unknown error'),
            ]);
            return $res;
        }

        $data = is_array($res['data'] ?? null) ? (array) $res['data'] : [];
        $orders = isset($data['orders']) && is_array($data['orders']) ? (array) $data['orders'] : [];
        $pagination = isset($data['pagination']) && is_array($data['pagination']) ? (array) $data['pagination'] : [];

        $res['orders'] = $orders;
        $res['pagination'] = [
            'page' => (int) ($pagination['page'] ?? (int) ($query['page'] ?? 1)),
            'per_page' => (int) ($pagination['per_page'] ?? (int) ($query['per_page'] ?? 10)),
            'page_count' => (int) ($pagination['page_count'] ?? 1),
        ];

        $this->profile('Orders request complete', $t0, [
            'status' => (int) ($res['status'] ?? 0),
            'page' => (int) ($res['pagination']['page'] ?? 1),
            'page_count' => (int) ($res['pagination']['page_count'] ?? 1),
            'order_count' => count($orders),
        ]);

        return $res;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function create_order(array $payload): array
    {
        $t0 = microtime(true);

        $this->log('Create order request start', [
            'body_keys' => array_values(array_map('strval', array_keys($payload))),
            'item_count' => isset($payload['order_items']) && is_array($payload['order_items']) ? count($payload['order_items']) : 0,
            'drop_ship_flag' => (int) ((bool) ($payload['drop_ship_flag'] ?? false)),
            'has_po' => trim((string) ($payload['purchase_order_number'] ?? '')) !== '' ? 1 : 0,
        ]);

        $res = $this->request_json('POST', 'orders', [], $payload);
        if (!(bool) ($res['ok'] ?? false)) {
            $this->profile('Create order request failed', $t0, [
                'status' => (int) ($res['status'] ?? 0),
                'error' => (string) ($res['error'] ?? 'Unknown error'),
            ]);
            return $res;
        }

        $data = is_array($res['data'] ?? null) ? (array) $res['data'] : [];
        $orders = isset($data['orders']) && is_array($data['orders']) ? (array) $data['orders'] : [];
        $res['orders'] = $orders;

        $this->profile('Create order request complete', $t0, [
            'status' => (int) ($res['status'] ?? 0),
            'order_count' => count($orders),
        ]);

        return $res;
    }

    /**
     * @return array<string,mixed>
     */
    public function get_order_shipments(int $orderNumber): array
    {
        $t0 = microtime(true);
        $orderNumber = max(1, (int) $orderNumber);

        $this->log('Order shipments request start', [
            'order_number' => $orderNumber,
        ]);

        $res = $this->request_json('GET', 'orders/' . $orderNumber . '/shipments');
        if (!(bool) ($res['ok'] ?? false)) {
            $this->profile('Order shipments request failed', $t0, [
                'order_number' => $orderNumber,
                'status' => (int) ($res['status'] ?? 0),
                'error' => (string) ($res['error'] ?? 'Unknown error'),
            ]);
            return $res;
        }

        $data = is_array($res['data'] ?? null) ? (array) $res['data'] : [];
        $orderShipments = isset($data['order_shipments']) && is_array($data['order_shipments']) ? (array) $data['order_shipments'] : [];
        $res['order_shipments'] = $orderShipments;

        $shipmentCount = 0;
        foreach ($orderShipments as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (!isset($entry['shipments']) || !is_array($entry['shipments'])) {
                continue;
            }
            $shipmentCount += count($entry['shipments']);
        }

        $this->profile('Order shipments request complete', $t0, [
            'order_number' => $orderNumber,
            'status' => (int) ($res['status'] ?? 0),
            'order_groups' => count($orderShipments),
            'shipment_count' => $shipmentCount,
        ]);

        return $res;
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function get_shipments_by_purchase_order(array $query = []): array
    {
        $t0 = microtime(true);

        if (isset($query['page'])) {
            $query['page'] = max(1, (int) $query['page']);
        }
        if (isset($query['per_page'])) {
            $query['per_page'] = max(1, min(25, (int) $query['per_page']));
        }
        if (isset($query['drop_ship_flag'])) {
            $query['drop_ship_flag'] = ((int) ((bool) $query['drop_ship_flag'])) ? 1 : 0;
        }
        if (isset($query['only_return_unreceived_shipments'])) {
            $query['only_return_unreceived_shipments'] = ((int) ((bool) $query['only_return_unreceived_shipments'])) ? 1 : 0;
        }

        $this->log('Shipments by PO request start', [
            'query_keys' => array_values(array_map('strval', array_keys($query))),
            'has_po_numbers' => trim((string) ($query['purchase_order_numbers'] ?? '')) !== '' ? 1 : 0,
        ]);

        $res = $this->request_json('GET', 'shipments/by-purchase-order', $query);
        if (!(bool) ($res['ok'] ?? false)) {
            $this->profile('Shipments by PO request failed', $t0, [
                'status' => (int) ($res['status'] ?? 0),
                'error' => (string) ($res['error'] ?? 'Unknown error'),
            ]);
            return $res;
        }

        $data = is_array($res['data'] ?? null) ? (array) $res['data'] : [];
        $shipments = isset($data['shipments']) && is_array($data['shipments']) ? (array) $data['shipments'] : [];
        $pagination = isset($data['pagination']) && is_array($data['pagination']) ? (array) $data['pagination'] : [];

        $res['shipments'] = $shipments;
        $res['pagination'] = [
            'page' => (int) ($pagination['page'] ?? (int) ($query['page'] ?? 1)),
            'per_page' => (int) ($pagination['per_page'] ?? (int) ($query['per_page'] ?? 10)),
            'page_count' => (int) ($pagination['page_count'] ?? 1),
        ];

        $orderCount = 0;
        $packageCount = 0;
        foreach ($shipments as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $orders = isset($entry['orders']) && is_array($entry['orders']) ? (array) $entry['orders'] : [];
            $orderCount += count($orders);

            foreach ($orders as $order) {
                if (!is_array($order)) {
                    continue;
                }

                $packages = isset($order['packages']) && is_array($order['packages']) ? (array) $order['packages'] : [];
                $packageCount += count($packages);
            }
        }

        $this->profile('Shipments by PO request complete', $t0, [
            'status' => (int) ($res['status'] ?? 0),
            'page' => (int) ($res['pagination']['page'] ?? 1),
            'page_count' => (int) ($res['pagination']['page_count'] ?? 1),
            'shipment_groups' => count($shipments),
            'order_count' => $orderCount,
            'package_count' => $packageCount,
        ]);

        return $res;
    }

    /**
     * @return array<string,mixed>
     */
    public function get_federal_firearms_license(string $fflNumber): array
    {
        $t0 = microtime(true);

        $fflNumber = strtoupper(trim($fflNumber));
        $fflNumber = preg_replace('/[^A-Z0-9]/', '', $fflNumber);
        if (!is_string($fflNumber) || $fflNumber === '') {
            $out = [
                'ok' => false,
                'status' => 0,
                'error' => 'Missing FFL number for CSSI federal-firearms-licenses lookup.',
            ];
            $this->profile('FFL lookup blocked (missing ffl_number)', $t0, $out);
            return $out;
        }

        $this->log('FFL lookup request start', [
            'ffl_tail4' => (strlen($fflNumber) >= 4) ? substr($fflNumber, -4) : $fflNumber,
        ]);

        $path = 'federal-firearms-licenses/' . rawurlencode($fflNumber);
        $res = $this->request_json('GET', $path);
        if (!(bool) ($res['ok'] ?? false)) {
            $this->profile('FFL lookup request failed', $t0, [
                'status' => (int) ($res['status'] ?? 0),
                'error' => (string) ($res['error'] ?? 'Unknown error'),
            ]);
            return $res;
        }

        $data = is_array($res['data'] ?? null) ? (array) $res['data'] : [];
        $records = isset($data['federal_firearms_licenses']) && is_array($data['federal_firearms_licenses'])
            ? (array) $data['federal_firearms_licenses']
            : [];

        $res['federal_firearms_licenses'] = $records;

        $this->profile('FFL lookup request complete', $t0, [
            'status' => (int) ($res['status'] ?? 0),
            'record_count' => count($records),
            'ffl_tail4' => (strlen($fflNumber) >= 4) ? substr($fflNumber, -4) : $fflNumber,
        ]);

        return $res;
    }

    /**
     * @return array<string,mixed>
     */
    public function download_file(string $url, string $outputPath): array
    {
        $t0 = microtime(true);

        $url = trim($url);
        $url = $this->normalize_feed_file_url($url);
        $outputPath = trim($outputPath);

        $this->log('File download start', [
            'url_head' => $this->truncate($url, 220),
            'output_path' => $outputPath,
        ]);

        if ($url === '' || $outputPath === '') {
            $out = [
                'ok' => false,
                'status' => 0,
                'error' => 'download_file requires a URL and output path.',
            ];
            $this->profile('File download failed (invalid args)', $t0, ['error' => (string) ($out['error'] ?? '')]);
            return $out;
        }

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            $made = function_exists('wp_mkdir_p') ? (bool) wp_mkdir_p($outputDir) : @mkdir($outputDir, 0775, true);
            if (!$made) {
                $out = [
                    'ok' => false,
                    'status' => 0,
                    'error' => 'Unable to create CSSI output directory.',
                    'output_dir' => $outputDir,
                ];
                $this->profile('File download failed (mkdir)', $t0, [
                    'output_dir' => $outputDir,
                    'error' => (string) ($out['error'] ?? ''),
                ]);
                return $out;
            }
        }

        $tmpPath = $outputPath . '.part';
        $fh = @fopen($tmpPath, 'wb');
        if (!is_resource($fh)) {
            $out = [
                'ok' => false,
                'status' => 0,
                'error' => 'Unable to open temp output file for CSSI download.',
                'tmp_path' => $tmpPath,
            ];
            $this->profile('File download failed (open temp)', $t0, $out);
            return $out;
        }

        $headers = $this->build_headers('*/*');

        $this->log('File download HTTP attempt', [
            'attempt' => 1,
            'max_attempts' => 1,
            'auth_mode' => 'legacy_raw',
            'url_head' => $this->truncate($url, 220),
            'sid_prefix' => $this->mask_sid($this->sid),
        ]);

        $exec = $this->execute_curl('GET', $url, $headers, null, $fh, 180);
        fclose($fh);

        if (!(bool) ($exec['transport_ok'] ?? false)) {
            @unlink($tmpPath);
            $out = [
                'ok' => false,
                'status' => (int) ($exec['http_code'] ?? 0),
                'error' => (string) ($exec['error'] ?? 'cURL transport failure.'),
                'curl_errno' => (int) ($exec['errno'] ?? 0),
                'curl_error' => (string) ($exec['error'] ?? ''),
                'curl_info' => (array) ($exec['info'] ?? []),
            ];
            $this->profile('File download failed (transport)', $t0, $out);
            return $out;
        }

        $status = (int) ($exec['http_code'] ?? 0);
        $contentType = (string) ($exec['content_type'] ?? '');
        $curlInfo = (array) ($exec['info'] ?? []);

        if ($status < 200 || $status >= 300) {
            @unlink($tmpPath);
            $out = [
                'ok' => false,
                'status' => $status,
                'error' => 'Unexpected HTTP status while downloading CSSI file.',
                'content_type' => $contentType,
                'curl_info' => $curlInfo,
            ];
            $this->profile('File download failed (status)', $t0, $out);
            return $out;
        }

        $tmpBytes = (is_file($tmpPath)) ? (int) filesize($tmpPath) : 0;
        if ($tmpBytes <= 0) {
            $this->log('File download stream returned empty payload; retrying buffered mode.', [
                'status' => $status,
                'content_type' => $contentType,
                'tmp_path' => $tmpPath,
                'curl_info' => $curlInfo,
            ]);

            $bufferExec = $this->execute_curl('GET', $url, $headers, null, null, 180);
            if (!(bool) ($bufferExec['transport_ok'] ?? false)) {
                @unlink($tmpPath);
                $out = [
                    'ok' => false,
                    'status' => (int) ($bufferExec['http_code'] ?? 0),
                    'error' => (string) ($bufferExec['error'] ?? 'Buffered cURL transport failure.'),
                    'curl_errno' => (int) ($bufferExec['errno'] ?? 0),
                    'curl_error' => (string) ($bufferExec['error'] ?? ''),
                    'curl_info' => (array) ($bufferExec['info'] ?? []),
                ];
                $this->profile('File download failed (buffered transport)', $t0, $out);
                return $out;
            }

            $status = (int) ($bufferExec['http_code'] ?? 0);
            $contentType = (string) ($bufferExec['content_type'] ?? '');
            $curlInfo = (array) ($bufferExec['info'] ?? []);
            if ($status < 200 || $status >= 300) {
                @unlink($tmpPath);
                $out = [
                    'ok' => false,
                    'status' => $status,
                    'error' => 'Unexpected HTTP status while downloading CSSI file (buffered).',
                    'content_type' => $contentType,
                    'curl_info' => $curlInfo,
                ];
                $this->profile('File download failed (buffered status)', $t0, $out);
                return $out;
            }

            $body = (string) ($bufferExec['body'] ?? '');
            $bodyBytes = strlen($body);
            if ($bodyBytes <= 0) {
                @unlink($tmpPath);
                $out = [
                    'ok' => false,
                    'status' => $status,
                    'error' => 'Buffered download returned empty body.',
                    'content_type' => $contentType,
                    'curl_info' => $curlInfo,
                ];
                $this->profile('File download failed (buffered empty)', $t0, $out);
                return $out;
            }

            $written = @file_put_contents($tmpPath, $body);
            if (!is_int($written) || $written <= 0) {
                @unlink($tmpPath);
                $out = [
                    'ok' => false,
                    'status' => $status,
                    'error' => 'Unable to write buffered download output.',
                    'content_type' => $contentType,
                    'bytes' => $bodyBytes,
                ];
                $this->profile('File download failed (buffered write)', $t0, $out);
                return $out;
            }

            $tmpBytes = (is_file($tmpPath)) ? (int) filesize($tmpPath) : 0;
            $this->log('File download buffered fallback succeeded.', [
                'status' => $status,
                'content_type' => $contentType,
                'bytes' => $tmpBytes,
                'curl_info' => $curlInfo,
            ]);
        }

        if (!@rename($tmpPath, $outputPath)) {
            @unlink($tmpPath);
            $out = [
                'ok' => false,
                'status' => $status,
                'error' => 'Failed to finalize CSSI download file.',
                'tmp_path' => $tmpPath,
                'output_path' => $outputPath,
                'curl_info' => $curlInfo,
            ];
            $this->profile('File download failed (rename)', $t0, $out);
            return $out;
        }

        $bytes = (is_file($outputPath)) ? (int) filesize($outputPath) : 0;
        if ($bytes <= 0) {
            $out = [
                'ok' => false,
                'status' => $status,
                'error' => 'Downloaded CSSI file was empty or missing.',
                'content_type' => $contentType,
                'curl_info' => $curlInfo,
            ];
            $this->profile('File download failed (empty)', $t0, [
                'status' => $status,
                'content_type' => $contentType,
                'bytes' => $bytes,
                'curl_info' => $curlInfo,
            ]);
            return $out;
        }

        $out = [
            'ok' => true,
            'status' => $status,
            'bytes' => $bytes,
            'path' => $outputPath,
            'content_type' => $contentType,
            'curl_info' => $curlInfo,
        ];

        $this->profile('File download complete', $t0, [
            'status' => $status,
            'bytes' => $bytes,
            'content_type' => $contentType,
        ]);

        return $out;
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request_json(string $method, string $path, array $query = [], ?array $body = null, int $timeout = 90): array
    {
        $t0 = microtime(true);

        if (!$this->has_credentials()) {
            $out = [
                'ok' => false,
                'status' => 0,
                'error' => 'Missing CSSI SID/token credentials.',
            ];
            $this->profile('JSON request blocked (missing creds)', $t0, ['path' => $path]);
            return $out;
        }

        $method = strtoupper(trim($method));
        $path = ltrim(trim($path), '/');
        $url = $this->baseUrl . $path;

        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $headers = $this->build_headers('application/json');
        $encodedBody = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $encodedBody = wp_json_encode($body);
            if (!is_string($encodedBody)) {
                $encodedBody = '{}';
            }
        }

        $callId = substr(sha1($method . '|' . $path . '|' . microtime(true) . '|' . mt_rand()), 0, 10);

        $this->log('HTTP request', [
            'call_id' => $callId,
            'attempt' => 1,
            'max_attempts' => 1,
            'auth_mode' => 'legacy_raw',
            'method' => $method,
            'path' => $path,
            'url' => $url,
            'query_keys' => array_values(array_map('strval', array_keys($query))),
            'body_keys' => is_array($body) ? array_values(array_map('strval', array_keys($body))) : [],
            'sid_prefix' => $this->mask_sid($this->sid),
        ]);

        $exec = $this->execute_curl($method, $url, $headers, $encodedBody, null, max(1, $timeout));

        if (!(bool) ($exec['transport_ok'] ?? false)) {
            $out = [
                'ok' => false,
                'status' => (int) ($exec['http_code'] ?? 0),
                'error' => 'cURL transport error: ' . (string) ($exec['error'] ?? 'unknown'),
                'curl_errno' => (int) ($exec['errno'] ?? 0),
                'curl_error' => (string) ($exec['error'] ?? ''),
                'curl_info' => (array) ($exec['info'] ?? []),
            ];

            $this->profile('HTTP response transport failure', $t0, [
                'call_id' => $callId,
                'path' => $path,
                'status' => (int) ($out['status'] ?? 0),
                'error' => (string) ($out['error'] ?? ''),
                'curl_errno' => (int) ($out['curl_errno'] ?? 0),
                'curl_info' => (array) ($out['curl_info'] ?? []),
            ]);

            return $out;
        }

        $status = (int) ($exec['http_code'] ?? 0);
        $contentType = (string) ($exec['content_type'] ?? '');
        $rawBody = (string) ($exec['body'] ?? '');
        $bodyBytes = strlen($rawBody);
        $headersOut = (array) ($exec['headers'] ?? []);

        $decoded = json_decode($rawBody, true);
        $jsonError = json_last_error() === JSON_ERROR_NONE ? '' : json_last_error_msg();

        if ($status < 200 || $status >= 300) {
            $apiMessage = '';
            $apiCode = '';
            if (is_array($decoded)) {
                $apiMessage = trim((string) ($decoded['message'] ?? ''));
                $apiCode = trim((string) ($decoded['error_code'] ?? ''));
            }

            $error = 'CSSI API returned a non-success status.';
            if ($apiMessage !== '' && $apiCode !== '') {
                $error = 'CSSI API ' . $status . ': ' . $apiMessage . ' (error_code=' . $apiCode . ')';
            } elseif ($apiMessage !== '') {
                $error = 'CSSI API ' . $status . ': ' . $apiMessage;
            } elseif ($apiCode !== '') {
                $error = 'CSSI API ' . $status . ': error_code=' . $apiCode;
            }

            $out = [
                'ok' => false,
                'status' => $status,
                'error' => $error,
                'data' => is_array($decoded) ? $decoded : [],
                'content_type' => $contentType,
                'raw_body_excerpt' => $this->truncate($rawBody, 2000),
            ];

            $this->profile('HTTP response non-success', $t0, [
                'call_id' => $callId,
                'path' => $path,
                'status' => $status,
                'content_type' => $contentType,
                'body_bytes' => $bodyBytes,
                'error' => $error,
            ]);

            return $out;
        }

        if (!is_array($decoded)) {
            $out = [
                'ok' => false,
                'status' => $status,
                'error' => 'Invalid JSON response from CSSI API.',
                'content_type' => $contentType,
                'json_error' => $jsonError,
                'raw_body_excerpt' => $this->truncate($rawBody, 2000),
            ];

            $this->profile('HTTP response invalid JSON', $t0, [
                'call_id' => $callId,
                'path' => $path,
                'status' => $status,
                'content_type' => $contentType,
                'body_bytes' => $bodyBytes,
                'json_error' => $jsonError,
            ]);

            return $out;
        }

        $this->profile('HTTP response success', $t0, [
            'call_id' => $callId,
            'path' => $path,
            'status' => $status,
            'content_type' => $contentType,
            'body_bytes' => $bodyBytes,
            'top_keys' => array_values(array_map('strval', array_slice(array_keys($decoded), 0, 12))),
        ]);

        return [
            'ok' => true,
            'status' => $status,
            'data' => $decoded,
            'content_type' => $contentType,
            'headers' => $headersOut,
        ];
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private function execute_curl(string $method, string $url, array $headers, ?string $body = null, $streamHandle = null, int $timeout = 90): array
    {
        if (!function_exists('curl_init')) {
            return [
                'transport_ok' => false,
                'errno' => -1,
                'error' => 'cURL extension is not available.',
                'http_code' => 0,
                'content_type' => '',
                'headers' => [],
                'body' => '',
                'info' => [],
            ];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'transport_ok' => false,
                'errno' => -2,
                'error' => 'curl_init failed.',
                'http_code' => 0,
                'content_type' => '',
                'headers' => [],
                'body' => '',
                'info' => [],
            ];
        }

        $method = strtoupper(trim($method));
        $isStream = is_resource($streamHandle);

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => $this->header_lines($headers),
            CURLOPT_USERAGENT => 'FFLHub-CSSI/1.0',
            CURLOPT_ENCODING => '',
        ];

        if ($isStream) {
            $options[CURLOPT_FILE] = $streamHandle;
            $options[CURLOPT_HEADER] = false;
            $options[CURLOPT_RETURNTRANSFER] = false;
        } else {
            $options[CURLOPT_HEADER] = true;
            $options[CURLOPT_RETURNTRANSFER] = true;
        }

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $errno = (int) curl_errno($ch);
        $error = (string) curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $info = [
            'http_code' => $httpCode,
            'total_time_ms' => number_format(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000, 2, '.', ''),
            'primary_ip' => (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP),
            'local_ip' => (string) curl_getinfo($ch, CURLINFO_LOCAL_IP),
            'effective_url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            'redirect_count' => (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT),
            'ssl_verify_result' => (int) curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT),
            'size_download' => (float) $this->curl_get_download_size($ch),
            'content_length_download' => (float) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD),
            'speed_download' => (float) curl_getinfo($ch, CURLINFO_SPEED_DOWNLOAD),
        ];

        curl_close($ch);

        $headersRaw = '';
        $bodyRaw = '';
        if (!$isStream) {
            $rawString = is_string($raw) ? $raw : '';
            if ($headerSize > 0) {
                $headersRaw = (string) substr($rawString, 0, $headerSize);
                $bodyRaw = (string) substr($rawString, $headerSize);
            } else {
                $bodyRaw = $rawString;
            }
        }

        $transportOk = ($raw !== false);
        if ($isStream && $errno === 0) {
            $transportOk = true;
        }

        return [
            'transport_ok' => $transportOk,
            'errno' => $errno,
            'error' => $error,
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'headers' => $this->parse_headers($headersRaw),
            'body' => $bodyRaw,
            'info' => $info,
        ];
    }

    /**
     * @param array<string,string> $headers
     * @return array<int,string>
     */
    private function header_lines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = (string) $k . ': ' . (string) $v;
        }

        return $lines;
    }

    /**
     * @return array<string,string>
     */
    private function build_headers(string $accept = 'application/json'): array
    {
        $auth = 'Basic ' . $this->sid . ':' . md5($this->token);

        return [
            'Authorization' => $auth,
            'Accept' => $accept,
            'User-Agent' => 'FFLHub-CSSI/1.0',
        ];
    }

    private function curl_get_download_size($ch): float
    {
        if (defined('CURLINFO_SIZE_DOWNLOAD_T')) {
            return (float) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD_T);
        }

        return (float) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    }

    private function normalize_feed_file_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $normalized = preg_replace('#^http://api\.chattanoogashooting\.com/#i', 'https://api.chattanoogashooting.com/', $url);
        if (!is_string($normalized) || trim($normalized) === '') {
            return $url;
        }

        return trim($normalized);
    }

    /**
     * @return array<string,string>
     */
    private function parse_headers(string $headersRaw): array
    {
        $headersRaw = trim($headersRaw);
        if ($headersRaw === '') {
            return [];
        }

        $parts = preg_split('/\r\n\r\n|\n\n|\r\r/', $headersRaw);
        $last = (is_array($parts) && !empty($parts)) ? (string) end($parts) : $headersRaw;

        $out = [];
        $lines = preg_split('/\r\n|\n|\r/', $last);
        if (!is_array($lines)) {
            return $out;
        }

        foreach ($lines as $line) {
            if (!is_string($line) || strpos($line, ':') === false) {
                continue;
            }

            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $name = strtolower(trim($name));
            $value = trim($value);
            if ($name === '') {
                continue;
            }

            $out[$name] = $value;
        }

        return $out;
    }

    private function mask_sid(string $sid): string
    {
        $sid = trim($sid);
        if ($sid === '') {
            return '[empty]';
        }

        if (strlen($sid) <= 4) {
            return str_repeat('*', strlen($sid));
        }

        return substr($sid, 0, 2) . str_repeat('*', strlen($sid) - 4) . substr($sid, -2);
    }

    private function truncate(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '' || $max <= 0) {
            return '';
        }

        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max) . '...';
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $message);
            return;
        }

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $message, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $ctx['elapsed_ms'] = number_format((microtime(true) - $t0) * 1000, 2, '.', '');
        $this->log('PROFILE: ' . $label, $ctx);
    }
}
