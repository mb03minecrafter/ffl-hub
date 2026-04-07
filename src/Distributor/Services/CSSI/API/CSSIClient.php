<?php

namespace FFLHub\Distributor\Services\CSSI\API;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Util\DebugLogUtil;

/**
 * Thin REST client for Chattanooga Shooting Supplies (CSSI) API.
 */
final class CSSIClient
{
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][CSSIClient]';
    private const DEFAULT_BASE_URL = 'https://api.chattanoogashooting.com/rest/v5/';
    private const AUTH_MODE_LEGACY_RAW = 'legacy_raw';

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
     * Fetch one page from GET /items.
     *
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
            'page' => (int) ($res['pagination']['page'] ?? $page),
            'page_count' => (int) ($res['pagination']['page_count'] ?? 1),
            'item_count' => count($items),
            'status' => (int) ($res['status'] ?? 0),
        ]);

        return $res;
    }

    /**
     * Resolve product feed CSV URL from GET /items/product-feed.
     *
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
            'url' => $url,
            'data' => $data,
        ];

        $this->profile('Product-feed URL request complete', $t0, [
            'status' => (int) ($out['status'] ?? 0),
            'url_head' => $this->truncate($url, 220),
        ]);

        return $out;
    }

    /**
     * Download a CSV file URL to local disk.
     *
     * @return array<string,mixed>
     */
    public function download_file(string $url, string $outputPath): array
    {
        $t0 = microtime(true);

        $url = trim($url);
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

            $this->profile('File download failed (invalid args)', $t0, [
                'error' => (string) ($out['error'] ?? ''),
            ]);

            return $out;
        }

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            $made = function_exists('wp_mkdir_p')
                ? (bool) wp_mkdir_p($outputDir)
                : @mkdir($outputDir, 0775, true);
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

        $args = [
            'timeout' => 180,
            'redirection' => 5,
            'httpversion' => '1.1',
            'headers' => $this->build_headers('*/*', self::AUTH_MODE_LEGACY_RAW),
            'stream' => true,
            'filename' => $outputPath,
        ];
        $this->log('File download HTTP attempt', [
            'attempt' => 1,
            'max_attempts' => 1,
            'auth_mode' => self::AUTH_MODE_LEGACY_RAW,
            'url_head' => $this->truncate($url, 220),
        ]);

        $resp = wp_remote_get($url, $args);
        if (is_wp_error($resp)) {
            $errorMessage = (string) $resp->get_error_message();
            $errorCtx = $this->collect_wp_error_context($resp);
            $probe = $this->curl_probe('GET', $url, $args['headers'], null);

            $this->profile('File download failed (wp_error)', $t0, [
                'attempt' => 1,
                'max_attempts' => 1,
                'error' => $errorMessage,
                'wp_error_code' => (string) ($errorCtx['code'] ?? ''),
                'wp_error_data' => $errorCtx['data'] ?? null,
                'env' => $this->request_environment($url),
                'probe' => $probe,
            ]);

            return [
                'ok' => false,
                'status' => 0,
                'error' => $errorMessage,
                'wp_error_code' => (string) ($errorCtx['code'] ?? ''),
                'wp_error_data' => $errorCtx['data'] ?? null,
                'probe' => $probe,
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($resp);
        $contentType = (string) wp_remote_retrieve_header($resp, 'content-type');

        if ($status < 200 || $status >= 300) {
            $out = [
                'ok' => false,
                'status' => $status,
                'error' => 'Unexpected HTTP status while downloading CSSI file.',
                'content_type' => $contentType,
            ];

            $this->profile('File download failed (status)', $t0, [
                'status' => $status,
                'content_type' => $contentType,
            ]);

            return $out;
        }

        $bytes = (is_file($outputPath)) ? (int) filesize($outputPath) : 0;
        if ($bytes <= 0) {
            $out = [
                'ok' => false,
                'status' => $status,
                'error' => 'Downloaded CSSI file was empty or missing.',
                'content_type' => $contentType,
            ];

            $this->profile('File download failed (empty)', $t0, [
                'status' => $status,
                'content_type' => $contentType,
                'bytes' => $bytes,
            ]);

            return $out;
        }

        $out = [
            'ok' => true,
            'status' => $status,
            'bytes' => $bytes,
            'path' => $outputPath,
            'content_type' => $contentType,
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
    private function request_json(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $t0 = microtime(true);

        if (!$this->has_credentials()) {
            $out = [
                'ok' => false,
                'status' => 0,
                'error' => 'Missing CSSI SID/token credentials.',
            ];

            $this->profile('JSON request blocked (missing creds)', $t0, [
                'path' => $path,
            ]);

            return $out;
        }

        $method = strtoupper(trim($method));
        $path = ltrim(trim($path), '/');
        $url = $this->baseUrl . $path;

        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $args = [
            'method' => $method,
            'timeout' => 90,
            'redirection' => 5,
            'httpversion' => '1.1',
        ];
        $encodedBody = null;
        if ($body !== null) {
            $encodedBody = wp_json_encode($body);
        }

        $callId = substr(sha1($method . '|' . $path . '|' . microtime(true) . '|' . mt_rand()), 0, 10);

        $authMode = self::AUTH_MODE_LEGACY_RAW;
        $attemptArgs = $args;
        $attemptArgs['headers'] = $this->build_headers('application/json', $authMode);
        if ($body !== null) {
            $attemptArgs['headers']['Content-Type'] = 'application/json';
            $attemptArgs['body'] = is_string($encodedBody) ? $encodedBody : '{}';
        }

        $this->log('HTTP request', [
            'call_id' => $callId,
            'attempt' => 1,
            'max_attempts' => 1,
            'auth_mode' => $authMode,
            'method' => $method,
            'path' => $path,
            'url' => $url,
            'query_keys' => array_values(array_map('strval', array_keys($query))),
            'body_keys' => is_array($body) ? array_values(array_map('strval', array_keys($body))) : [],
            'sid_prefix' => $this->mask_sid($this->sid),
        ]);

        $resp = wp_remote_request($url, $attemptArgs);
        if (is_wp_error($resp)) {
            $errorMessage = (string) $resp->get_error_message();
            $errorCtx = $this->collect_wp_error_context($resp);
            $probe = $this->curl_probe(
                $method,
                $url,
                is_array($attemptArgs['headers'] ?? null) ? (array) $attemptArgs['headers'] : [],
                is_string($attemptArgs['body'] ?? null) ? (string) $attemptArgs['body'] : null
            );

            $this->profile('HTTP response wp_error', $t0, [
                'call_id' => $callId,
                'path' => $path,
                'attempt' => 1,
                'max_attempts' => 1,
                'auth_mode' => $authMode,
                'error' => $errorMessage,
                'wp_error_code' => (string) ($errorCtx['code'] ?? ''),
                'wp_error_data' => $errorCtx['data'] ?? null,
                'env' => $this->request_environment($url),
                'probe' => $probe,
            ]);

            return [
                'ok' => false,
                'status' => 0,
                'error' => $errorMessage,
                'wp_error_code' => (string) ($errorCtx['code'] ?? ''),
                'wp_error_data' => $errorCtx['data'] ?? null,
                'probe' => $probe,
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($resp);
        $rawBody = (string) wp_remote_retrieve_body($resp);
        $contentType = (string) wp_remote_retrieve_header($resp, 'content-type');
        $headersArray = $this->response_headers_to_array(wp_remote_retrieve_headers($resp));
        $bodyBytes = strlen($rawBody);

        $decoded = json_decode($rawBody, true);
        $jsonError = json_last_error() === JSON_ERROR_NONE ? '' : json_last_error_msg();

        if (!is_array($decoded)) {
            $out = [
                'ok' => false,
                'status' => $status,
                'error' => 'Invalid JSON response from CSSI API.',
                'raw_excerpt' => $this->truncate($rawBody, 700),
                'content_type' => $contentType,
                'json_error' => $jsonError,
            ];

            $this->profile('HTTP response invalid JSON', $t0, [
                'call_id' => $callId,
                'path' => $path,
                'status' => $status,
                'content_type' => $contentType,
                'headers' => $headersArray,
                'body_bytes' => $bodyBytes,
                'json_error' => $jsonError,
                'body_head' => $this->truncate($rawBody, 300),
            ]);

            return $out;
        }

        if ($status < 200 || $status >= 300) {
            $topKeys = array_slice(array_keys($decoded), 0, 12);

            $out = [
                'ok' => false,
                'status' => $status,
                'error' => 'CSSI API returned a non-success status.',
                'data' => $decoded,
                'content_type' => $contentType,
            ];

            $this->profile('HTTP response non-success', $t0, [
                'call_id' => $callId,
                'path' => $path,
                'status' => $status,
                'content_type' => $contentType,
                'headers' => $headersArray,
                'body_bytes' => $bodyBytes,
                'top_keys' => array_values(array_map('strval', $topKeys)),
                'body_head' => $this->truncate($rawBody, 300),
                'decoded_head' => $this->truncate((string) wp_json_encode($decoded), 1600),
            ]);

            return $out;
        }

        $topKeys = array_slice(array_keys($decoded), 0, 12);

        $this->profile('HTTP response success', $t0, [
            'call_id' => $callId,
            'path' => $path,
            'status' => $status,
            'content_type' => $contentType,
            'headers' => $headersArray,
            'body_bytes' => $bodyBytes,
            'top_keys' => array_values(array_map('strval', $topKeys)),
            'decoded_head' => $this->truncate((string) wp_json_encode($decoded), 1600),
        ]);

        return [
            'ok' => true,
            'status' => $status,
            'data' => $decoded,
            'content_type' => $contentType,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function build_headers(string $accept = 'application/json', string $authMode = self::AUTH_MODE_LEGACY_RAW): array
    {
        $sidToken = $this->sid . ':' . md5($this->token);
        $authorization = 'Basic ' . $sidToken;

        return [
            // CSSI docs specify this exact format: "Basic SID:md5(token)"
            'Authorization' => $authorization,
            'Accept' => $accept,
            'User-Agent' => 'FFLHub-CSSI/1.0',
        ];
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
     * @param mixed $headers
     * @return array<string,mixed>
     */
    private function response_headers_to_array($headers): array
    {
        if (is_array($headers)) {
            return $headers;
        }

        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $all = $headers->getAll();
            return is_array($all) ? $all : [];
        }

        return [];
    }

    /**
     * @param mixed $error
     * @return array{code:string,message:string,data:mixed}
     */
    private function collect_wp_error_context($error): array
    {
        if (!is_wp_error($error)) {
            return [
                'code' => '',
                'message' => '',
                'data' => null,
            ];
        }

        return [
            'code' => (string) $error->get_error_code(),
            'message' => (string) $error->get_error_message(),
            'data' => $error->get_error_data(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function request_environment(string $url): array
    {
        $parts = wp_parse_url($url);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $resolved = '';
        if ($host !== '' && function_exists('gethostbyname')) {
            $resolved = (string) gethostbyname($host);
        }

        $curlVersion = [];
        if (function_exists('curl_version')) {
            $cv = curl_version();
            if (is_array($cv)) {
                $curlVersion = [
                    'version' => (string) ($cv['version'] ?? ''),
                    'ssl_version' => (string) ($cv['ssl_version'] ?? ''),
                    'libz_version' => (string) ($cv['libz_version'] ?? ''),
                ];
            }
        }

        return [
            'host' => $host,
            'resolved_host' => $resolved,
            'php_version' => PHP_VERSION,
            'openssl' => defined('OPENSSL_VERSION_TEXT') ? (string) OPENSSL_VERSION_TEXT : '',
            'curl' => $curlVersion,
        ];
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private function curl_probe(string $method, string $url, array $headers, ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            return ['supported' => 0, 'reason' => 'curl_init unavailable'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['supported' => 0, 'reason' => 'curl_init failed'];
        }

        $stderr = fopen('php://temp', 'w+');
        $headerLines = [];
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === 'authorization') {
                $headerLines[] = (string) $k . ': [redacted]';
                continue;
            }
            $headerLines[] = (string) $k . ': ' . (string) $v;
        }

        $method = strtoupper(trim($method));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        if (is_resource($stderr)) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            curl_setopt($ch, CURLOPT_STDERR, $stderr);
        }
        if ($body !== null && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $errno = (int) curl_errno($ch);
        $error = (string) curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $localIp = (string) curl_getinfo($ch, CURLINFO_LOCAL_IP);
        $totalTime = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);

        $verbose = '';
        if (is_resource($stderr)) {
            rewind($stderr);
            $verbose = (string) stream_get_contents($stderr);
            fclose($stderr);
        }

        $rawString = is_string($raw) ? $raw : '';
        $rawHeaders = $headerSize > 0 ? substr($rawString, 0, $headerSize) : '';
        $rawBody = $headerSize > 0 ? substr($rawString, $headerSize) : $rawString;

        return [
            'supported' => 1,
            'method' => $method,
            'http_code' => $httpCode,
            'errno' => $errno,
            'error' => $error,
            'primary_ip' => $primaryIp,
            'local_ip' => $localIp,
            'total_time_ms' => number_format($totalTime * 1000, 2, '.', ''),
            'response_headers_head' => $this->truncate($rawHeaders, 1800),
            'response_body_head' => $this->truncate($rawBody, 1800),
            'verbose_head' => $this->truncate($verbose, 2200),
        ];
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
