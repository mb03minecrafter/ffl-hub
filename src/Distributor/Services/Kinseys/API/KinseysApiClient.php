<?php

namespace FFLHub\Distributor\Services\Kinseys\API;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Util\DebugLogUtil;

/**
 * Thin WordPress HTTP client for Kinsey's Customer API v2.
 */
final class KinseysApiClient
{
    public const DEFAULT_BASE_URL = 'https://api.kinseysinc.com/v2/';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][KinseysHttpCron]';

    private string $apiIdentifier;
    private string $apiKey;
    private string $source;
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct(
        string $apiIdentifier,
        string $apiKey,
        string $source = '',
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeoutSeconds = 120
    ) {
        $this->apiIdentifier = trim($apiIdentifier);
        $this->apiKey = trim($apiKey);
        $this->source = trim($source);
        $this->baseUrl = $this->normalize_base_url($baseUrl);
        $this->timeoutSeconds = max(10, $timeoutSeconds);
    }

    public function has_credentials(): bool
    {
        return $this->apiIdentifier !== '' && $this->apiKey !== '';
    }

    /**
     * @param string[]|int[] $productIds
     * @return array<string,mixed>
     */
    public function get_inventory(array $productIds = []): array
    {
        $params = [];
        $ids = $this->normalize_product_ids($productIds);
        if ($ids !== '') {
            $params['products'] = $ids;
        }

        return $this->get('Inventory', $params);
    }

    /**
     * @return array<string,mixed>
     */
    public function get_products(): array
    {
        return $this->get('Products');
    }

    /**
     * @param string[]|int[] $productIds
     * @return array<string,mixed>
     */
    public function get_allowed_products(array $productIds = []): array
    {
        $params = [];
        $ids = $this->normalize_product_ids($productIds);
        if ($ids !== '') {
            $params['products'] = $ids;
        }

        return $this->get('Products/Allowed', $params);
    }

    /**
     * @param string[]|int[] $productIds
     * @return array<string,mixed>
     */
    public function get_products_by_id(array $productIds): array
    {
        $ids = $this->normalize_product_ids($productIds);
        return $this->get('Products/GetById', $ids !== '' ? ['products' => $ids] : []);
    }

    /**
     * @return array<string,mixed>
     */
    public function test_credentials(string $probeProductId = '10113'): array
    {
        $probeProductId = trim($probeProductId);
        return $this->get_inventory($probeProductId !== '' ? [$probeProductId] : ['10113']);
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function get(string $path, array $params = []): array
    {
        $request_context = $this->request_context('GET', $path, $params);

        if (!$this->has_credentials()) {
            $this->log('HTTP GET skipped: missing credentials', $request_context);

            return [
                'ok' => false,
                'status' => 0,
                'data' => [],
                'error' => 'Missing Kinsey\'s API Identifier or API key.',
                'body_excerpt' => '',
                'response_bytes' => 0,
            ];
        }

        $params = $this->with_api_identifier($params);
        $url = add_query_arg($params, $this->endpoint_url($path));
        $t_request = microtime(true);

        $this->log('HTTP GET start', $request_context);

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-API-KEY' => $this->apiKey,
        ];

        if ($this->source !== '') {
            $headers['Kinsey-Source'] = $this->source;
        }

        $t_transport = microtime(true);
        $response = wp_remote_get(
            $url,
            [
                'timeout' => $this->timeoutSeconds,
                'headers' => $headers,
            ]
        );
        $transport_ms = $this->elapsed_ms($t_transport);

        $t_parse = microtime(true);
        $parsed = $this->parse_response($response);
        $parse_ms = $this->elapsed_ms($t_parse);
        $this->log('HTTP GET complete', $this->response_context($request_context, $parsed, $t_request, [
            'transport_ms' => $transport_ms,
            'parse_response_ms' => $parse_ms,
        ]));

        return $parsed;
    }

    /**
     * @param mixed $response
     * @return array<string,mixed>
     */
    private function parse_response($response): array
    {
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'status' => 0,
                'data' => [],
                'error' => $response->get_error_message(),
                'body_excerpt' => '',
                'response_bytes' => 0,
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $body_excerpt = self::excerpt_for_log($body, 2000);
        $response_bytes = strlen($body);
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'status' => $status,
                'data' => [],
                'error' => 'Kinsey\'s returned an invalid JSON response.',
                'body_excerpt' => $body_excerpt,
                'response_bytes' => $response_bytes,
            ];
        }

        $ok = ($status >= 200 && $status < 300);

        return [
            'ok' => $ok,
            'status' => $status,
            'data' => $decoded,
            'error' => $ok ? '' : $this->extract_error_message($decoded, $status),
            'body_excerpt' => $body_excerpt,
            'response_bytes' => $response_bytes,
        ];
    }

    /**
     * @param array<string,string> $params
     * @return array<string,string>
     */
    private function with_api_identifier(array $params): array
    {
        $query_key = (string) apply_filters(
            'fflhub_kinseys_api_identifier_query_key',
            'apiIdentifier'
        );
        $query_key = trim($query_key) !== '' ? trim($query_key) : 'apiIdentifier';

        if (!array_key_exists($query_key, $params)) {
            $params[$query_key] = $this->apiIdentifier;
        }

        return $params;
    }

    private function endpoint_url(string $path): string
    {
        return trailingslashit($this->baseUrl) . ltrim($path, '/');
    }

    private function normalize_base_url(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            $baseUrl = self::DEFAULT_BASE_URL;
        }

        return trailingslashit($baseUrl);
    }

    /**
     * @param string[]|int[] $productIds
     */
    private function normalize_product_ids(array $productIds): string
    {
        $ids = [];
        foreach ($productIds as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return implode(',', array_values($ids));
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function extract_error_message(array $decoded, int $status): string
    {
        foreach (['message', 'error', 'title', 'detail', 'referenceNo'] as $key) {
            if (isset($decoded[$key]) && trim((string) $decoded[$key]) !== '') {
                return trim((string) $decoded[$key]);
            }
        }

        return 'Kinsey\'s request failed with HTTP status ' . $status . '.';
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function request_context(string $verb, string $path, array $params): array
    {
        $endpoint = $this->endpoint_context($path);

        return [
            'verb' => $verb,
            'path' => $path,
            'timeout_sec' => $this->timeoutSeconds,
            'endpoint_host' => $endpoint['host'],
            'endpoint_path' => $endpoint['path'],
            'params' => $this->summarize_params($params),
        ];
    }

    /**
     * @return array{host:string,path:string}
     */
    private function endpoint_context(string $path): array
    {
        $parts = parse_url($this->endpoint_url($path));
        if (!is_array($parts)) {
            return [
                'host' => '',
                'path' => '',
            ];
        }

        return [
            'host' => (string) ($parts['host'] ?? ''),
            'path' => (string) ($parts['path'] ?? ''),
        ];
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function summarize_params(array $params): array
    {
        if (empty($params)) {
            return [
                'keys' => [],
            ];
        }

        $summary = [
            'keys' => array_values(array_keys($params)),
        ];

        if (isset($params['products'])) {
            $ids = array_values(array_filter(array_map('trim', explode(',', (string) $params['products']))));
            $summary['products_count'] = count($ids);
            $summary['products_sample'] = array_slice($ids, 0, 5);
            $summary['products_bytes'] = strlen((string) $params['products']);
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $requestContext
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    private function response_context(array $requestContext, array $parsed, float $t0, array $timings = []): array
    {
        $ctx = $requestContext;
        $ctx['ok'] = empty($parsed['ok']) ? 0 : 1;
        $ctx['status'] = (int) ($parsed['status'] ?? 0);
        $ctx['response_bytes'] = (int) ($parsed['response_bytes'] ?? 0);
        $ctx['elapsed_ms'] = number_format((microtime(true) - $t0) * 1000.0, 2, '.', '');

        $error = self::excerpt_for_log((string) ($parsed['error'] ?? ''), 500);
        if ($error !== '') {
            $ctx['error'] = $error;
        }

        if (empty($parsed['ok'])) {
            $body_excerpt = self::excerpt_for_log((string) ($parsed['body_excerpt'] ?? ''), 500);
            if ($body_excerpt !== '') {
                $ctx['body_excerpt'] = $body_excerpt;
            }
        }

        foreach ($timings as $key => $value) {
            $ctx[(string) $key] = $value;
        }

        return $ctx;
    }

    private function elapsed_ms(float $tStart): string
    {
        return number_format((microtime(true) - $tStart) * 1000.0, 2, '.', '');
    }

    private static function excerpt_for_log(string $text, int $max = 1200): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return '';
        }

        return strlen($text) <= $max ? $text : substr($text, 0, $max) . '...';
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
