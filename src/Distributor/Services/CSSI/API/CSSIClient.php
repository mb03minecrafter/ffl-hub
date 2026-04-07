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
    private const TRANSPORT_MAX_ATTEMPTS = 3;
    private const TRANSPORT_RETRY_BASE_MS = 400;
    private const TRANSPORT_RETRY_MAX_MS = 2500;

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
            'headers' => $this->build_headers('*/*'),
            'stream' => true,
            'filename' => $outputPath,
        ];

        $maxAttempts = self::TRANSPORT_MAX_ATTEMPTS;
        $resp = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->log('File download HTTP attempt', [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'url_head' => $this->truncate($url, 220),
            ]);

            $resp = wp_remote_get($url, $args);
            if (!is_wp_error($resp)) {
                break;
            }

            $errorMessage = (string) $resp->get_error_message();
            $retryable = $this->is_retryable_wp_error($resp);
            $willRetry = $retryable && $attempt < $maxAttempts;

            $this->profile('File download failed (wp_error)', $t0, [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'retryable' => $retryable ? 1 : 0,
                'will_retry' => $willRetry ? 1 : 0,
                'error' => $errorMessage,
            ]);

            if (!$willRetry) {
                return [
                    'ok' => false,
                    'status' => 0,
                    'error' => $errorMessage,
                ];
            }

            $this->sleep_before_retry($attempt, 'download_file', [
                'url_head' => $this->truncate($url, 220),
            ]);
        }

        if (!is_array($resp)) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Download failed without a valid HTTP response.',
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
            'headers' => $this->build_headers(),
        ];

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $encodedBody = wp_json_encode($body);
            $args['body'] = is_string($encodedBody) ? $encodedBody : '{}';
        }

        $callId = substr(sha1($method . '|' . $path . '|' . microtime(true) . '|' . mt_rand()), 0, 10);

        $maxAttempts = self::TRANSPORT_MAX_ATTEMPTS;
        $resp = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->log('HTTP request', [
                'call_id' => $callId,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'method' => $method,
                'path' => $path,
                'url' => $url,
                'query_keys' => array_values(array_map('strval', array_keys($query))),
                'body_keys' => is_array($body) ? array_values(array_map('strval', array_keys($body))) : [],
                'sid_prefix' => $this->mask_sid($this->sid),
            ]);

            $resp = wp_remote_request($url, $args);
            if (!is_wp_error($resp)) {
                break;
            }

            $errorMessage = (string) $resp->get_error_message();
            $retryable = $this->is_retryable_wp_error($resp);
            $willRetry = $retryable
                && $attempt < $maxAttempts
                && $this->method_allows_transport_retry($method);

            $this->profile('HTTP response wp_error', $t0, [
                'call_id' => $callId,
                'path' => $path,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'retryable' => $retryable ? 1 : 0,
                'will_retry' => $willRetry ? 1 : 0,
                'error' => $errorMessage,
            ]);

            if (!$willRetry) {
                return [
                    'ok' => false,
                    'status' => 0,
                    'error' => $errorMessage,
                ];
            }

            $this->sleep_before_retry($attempt, 'request_json', [
                'call_id' => $callId,
                'path' => $path,
                'method' => $method,
            ]);
        }

        if (!is_array($resp)) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'HTTP request failed without a valid response payload.',
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($resp);
        $rawBody = (string) wp_remote_retrieve_body($resp);
        $contentType = (string) wp_remote_retrieve_header($resp, 'content-type');
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
                'body_bytes' => $bodyBytes,
                'top_keys' => array_values(array_map('strval', $topKeys)),
                'body_head' => $this->truncate($rawBody, 300),
            ]);

            return $out;
        }

        $topKeys = array_slice(array_keys($decoded), 0, 12);

        $this->profile('HTTP response success', $t0, [
            'call_id' => $callId,
            'path' => $path,
            'status' => $status,
            'content_type' => $contentType,
            'body_bytes' => $bodyBytes,
            'top_keys' => array_values(array_map('strval', $topKeys)),
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
    private function build_headers(string $accept = 'application/json'): array
    {
        return [
            // CSSI requires this exact auth shape: "Basic SID:md5(token)"
            'Authorization' => 'Basic ' . $this->sid . ':' . md5($this->token),
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

    private function method_allows_transport_retry(string $method): bool
    {
        return in_array(strtoupper(trim($method)), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * @param mixed $error
     */
    private function is_retryable_wp_error($error): bool
    {
        if (!is_wp_error($error)) {
            return false;
        }

        $code = strtolower(trim((string) $error->get_error_code()));
        $message = strtolower(trim((string) $error->get_error_message()));

        if (in_array($code, ['http_request_failed', 'http_request_timeout', 'requests_transport_internalerror'], true)) {
            return true;
        }

        $needles = [
            'timeout',
            'timed out',
            'operation timed out',
            'could not resolve host',
            'could not connect',
            'failed to connect',
            'connection refused',
            'connection reset',
            'network is unreachable',
            'temporary failure',
            'empty reply from server',
            'recv failure',
            'ssl_read',
            'unexpected eof',
            'http2 stream',
            'errno 104',
            'errno 110',
            'errno 111',
        ];

        foreach ($needles as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function sleep_before_retry(int $attempt, string $operation, array $ctx = []): void
    {
        $exp = (int) (self::TRANSPORT_RETRY_BASE_MS * (2 ** max(0, $attempt - 1)));
        $baseDelayMs = min(self::TRANSPORT_RETRY_MAX_MS, $exp);

        $jitterMs = 0;
        try {
            $jitterMs = random_int(0, 150);
        } catch (\Throwable $e) {
            $jitterMs = 0;
        }

        $delayMs = $baseDelayMs + $jitterMs;
        $ctx['attempt'] = $attempt;
        $ctx['delay_ms'] = $delayMs;

        $this->log('Retry backoff: ' . $operation, $ctx);

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
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
