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
                'data_head' => $this->truncate((string) wp_json_encode($data), 1200),
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

        if ($status < 200 || $status >= 300) {
            @unlink($tmpPath);
            $out = [
                'ok' => false,
                'status' => $status,
                'error' => 'Unexpected HTTP status while downloading CSSI file.',
                'content_type' => $contentType,
                'headers' => (array) ($exec['headers'] ?? []),
            ];
            $this->profile('File download failed (status)', $t0, $out);
            return $out;
        }

        if (!@rename($tmpPath, $outputPath)) {
            @unlink($tmpPath);
            $out = [
                'ok' => false,
                'status' => $status,
                'error' => 'Failed to finalize CSSI download file.',
                'tmp_path' => $tmpPath,
                'output_path' => $outputPath,
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

        $exec = $this->execute_curl($method, $url, $headers, $encodedBody, null, 90);

        if (!(bool) ($exec['transport_ok'] ?? false)) {
            $out = [
                'ok' => false,
                'status' => (int) ($exec['http_code'] ?? 0),
                'error' => 'cURL transport error: ' . (string) ($exec['error'] ?? 'unknown'),
                'curl_errno' => (int) ($exec['errno'] ?? 0),
                'curl_error' => (string) ($exec['error'] ?? ''),
                'curl_info' => (array) ($exec['info'] ?? []),
                'headers' => (array) ($exec['headers'] ?? []),
                'raw_excerpt' => $this->truncate((string) ($exec['body'] ?? ''), 1200),
            ];

            $this->profile('HTTP response transport failure', $t0, [
                'call_id' => $callId,
                'path' => $path,
                'status' => (int) ($out['status'] ?? 0),
                'error' => (string) ($out['error'] ?? ''),
                'curl_errno' => (int) ($out['curl_errno'] ?? 0),
                'headers' => (array) ($out['headers'] ?? []),
                'raw_excerpt' => (string) ($out['raw_excerpt'] ?? ''),
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
                'headers' => $headersOut,
                'raw_excerpt' => $this->truncate($rawBody, 1200),
            ];

            $this->profile('HTTP response non-success', $t0, [
                'call_id' => $callId,
                'path' => $path,
                'status' => $status,
                'content_type' => $contentType,
                'headers' => $headersOut,
                'body_bytes' => $bodyBytes,
                'error' => $error,
                'raw_excerpt' => $this->truncate($rawBody, 400),
            ]);

            return $out;
        }

        if (!is_array($decoded)) {
            $out = [
                'ok' => false,
                'status' => $status,
                'error' => 'Invalid JSON response from CSSI API.',
                'content_type' => $contentType,
                'headers' => $headersOut,
                'json_error' => $jsonError,
                'raw_excerpt' => $this->truncate($rawBody, 1200),
            ];

            $this->profile('HTTP response invalid JSON', $t0, [
                'call_id' => $callId,
                'path' => $path,
                'status' => $status,
                'content_type' => $contentType,
                'headers' => $headersOut,
                'body_bytes' => $bodyBytes,
                'json_error' => $jsonError,
                'raw_excerpt' => $this->truncate($rawBody, 400),
            ]);

            return $out;
        }

        $this->profile('HTTP response success', $t0, [
            'call_id' => $callId,
            'path' => $path,
            'status' => $status,
            'content_type' => $contentType,
            'headers' => $headersOut,
            'body_bytes' => $bodyBytes,
            'top_keys' => array_values(array_map('strval', array_slice(array_keys($decoded), 0, 12))),
            'decoded_head' => $this->truncate((string) wp_json_encode($decoded), 1200),
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
            'redirect_count' => (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT),
            'ssl_verify_result' => (int) curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT),
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
