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
        $page = max(1, (int) $page);
        $perPage = max(1, min(50, (int) $perPage));

        $query = array_merge($query, [
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $res = $this->request_json('GET', 'items', $query);
        if (!(bool) ($res['ok'] ?? false)) {
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

        return $res;
    }

    /**
     * Resolve product feed CSV URL from GET /items/product-feed.
     *
     * @return array<string,mixed>
     */
    public function get_product_feed_url(array $query = []): array
    {
        $res = $this->request_json('GET', 'items/product-feed', $query);
        if (!(bool) ($res['ok'] ?? false)) {
            return $res;
        }

        $data = is_array($res['data'] ?? null) ? (array) $res['data'] : [];
        $url = '';
        if (isset($data['product_feed']) && is_array($data['product_feed'])) {
            $url = trim((string) ($data['product_feed']['url'] ?? ''));
        }

        if ($url === '') {
            return [
                'ok' => false,
                'status' => (int) ($res['status'] ?? 0),
                'error' => 'CSSI product-feed response did not contain product_feed.url.',
                'data' => $data,
            ];
        }

        return [
            'ok' => true,
            'status' => (int) ($res['status'] ?? 200),
            'url' => $url,
            'data' => $data,
        ];
    }

    /**
     * Download a CSV file URL to local disk.
     *
     * @return array<string,mixed>
     */
    public function download_file(string $url, string $outputPath): array
    {
        $url = trim($url);
        $outputPath = trim($outputPath);

        if ($url === '' || $outputPath === '') {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'download_file requires a URL and output path.',
            ];
        }

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            $made = function_exists('wp_mkdir_p')
                ? (bool) wp_mkdir_p($outputDir)
                : @mkdir($outputDir, 0775, true);
            if (!$made) {
                return [
                    'ok' => false,
                    'status' => 0,
                    'error' => 'Unable to create CSSI output directory.',
                    'output_dir' => $outputDir,
                ];
            }
        }

        $args = [
            'timeout' => 180,
            'redirection' => 5,
            'headers' => $this->build_headers('*/*'),
            'stream' => true,
            'filename' => $outputPath,
        ];

        $resp = wp_remote_get($url, $args);
        if (is_wp_error($resp)) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => $resp->get_error_message(),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($resp);
        if ($status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => 'Unexpected HTTP status while downloading CSSI file.',
            ];
        }

        $bytes = (is_file($outputPath)) ? (int) filesize($outputPath) : 0;
        if ($bytes <= 0) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => 'Downloaded CSSI file was empty or missing.',
            ];
        }

        return [
            'ok' => true,
            'status' => $status,
            'bytes' => $bytes,
            'path' => $outputPath,
        ];
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request_json(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if (!$this->has_credentials()) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Missing CSSI SID/token credentials.',
            ];
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
            'headers' => $this->build_headers(),
        ];

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $this->log('HTTP request', [
            'method' => $method,
            'path' => $path,
            'sid_prefix' => $this->mask_sid($this->sid),
        ]);

        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => $resp->get_error_message(),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($resp);
        $rawBody = (string) wp_remote_retrieve_body($resp);
        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => 'Invalid JSON response from CSSI API.',
                'raw_excerpt' => $this->truncate($rawBody, 700),
            ];
        }

        if ($status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => 'CSSI API returned a non-success status.',
                'data' => $decoded,
            ];
        }

        return [
            'ok' => true,
            'status' => $status,
            'data' => $decoded,
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
}
