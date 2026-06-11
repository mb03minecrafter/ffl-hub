<?php

namespace FFLHub\Distributor\Services\Orion\API;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Util\DebugLogUtil;

/**
 * Thin WordPress HTTP client for the Orion Wholesale API.
 */
final class OrionApiClient
{
    public const DEFAULT_BASE_URL = 'https://orionfflsales.com/api.php';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][OrionHttpCron]';

    private string $connectionKey;
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct(string $connectionKey, string $baseUrl = self::DEFAULT_BASE_URL, int $timeoutSeconds = 120)
    {
        $this->connectionKey = trim($connectionKey);
        $this->baseUrl = trim($baseUrl) !== '' ? trim($baseUrl) : self::DEFAULT_BASE_URL;
        $this->timeoutSeconds = max(10, $timeoutSeconds);
    }

    public function has_credentials(): bool
    {
        return $this->connectionKey !== '';
    }

    /**
     * @return array{ok:bool,status:int,data:array<string,mixed>,error:string}
     */
    public function test_credentials(): array
    {
        return $this->get('test_credentials');
    }

    /**
     * @param int[]|string[] $productIds
     * @return array{ok:bool,status:int,data:array<string,mixed>,error:string}
     */
    public function get_catalog(array $productIds = []): array
    {
        $params = [];
        $ids = $this->normalize_product_ids($productIds);
        if ($ids !== '') {
            $params['product_ids'] = $ids;
        }

        return $this->get('get_catalog', $params);
    }

    /**
     * @param int[]|string[] $productIds
     * @return array{ok:bool,status:int,data:array<string,mixed>,error:string}
     */
    public function get_catalog_inventory(array $productIds = []): array
    {
        // Orion treats omitted product_ids as a full inventory request. The
        // optimized inventory cron passes explicit product IDs; the normal full
        // cron path passes an empty array on purpose.
        $params = [];
        $ids = $this->normalize_product_ids($productIds);
        if ($ids !== '') {
            $params['product_ids'] = $ids;
        }

        return $this->get('get_catalog_inventory', $params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array{ok:bool,status:int,data:array<string,mixed>,error:string}
     */
    public function place_order(array $params): array
    {
        return $this->post('place_order', $this->normalize_params($params));
    }

    /**
     * @return array{ok:bool,status:int,data:array<string,mixed>,error:string}
     */
    public function get_shipment_data(string $orderId = '', ?int $days = null): array
    {
        $params = [];

        $orderId = trim($orderId);
        if ($orderId !== '') {
            $params['order_id'] = $orderId;
        }

        if ($days !== null && $days > 0) {
            $params['days'] = (string) $days;
        }

        return $this->get('get_shipment_data', $params);
    }

    /**
     * @param array<string,string> $params
     * @return array{ok:bool,status:int,data:array<string,mixed>,error:string}
     */
    private function get(string $method, array $params = []): array
    {
        $request_context = $this->request_context('GET', $method, $params);

        if (!$this->has_credentials()) {
            $this->log('HTTP GET skipped: missing credentials', $request_context);

            return [
                'ok'             => false,
                'status'         => 0,
                'data'           => [],
                'error'          => 'Missing Orion connection key.',
                'body_excerpt'   => '',
                'response_bytes' => 0,
            ];
        }

        $url = add_query_arg(array_merge(['method' => $method], $params), $this->baseUrl);
        $t_request = microtime(true);

        $this->log('HTTP GET start', $request_context);

        $response = wp_remote_get(
            $url,
            [
                'timeout' => $this->timeoutSeconds,
                'headers' => [
                    'Accept'         => 'application/json',
                    'Connection-Key' => $this->connectionKey,
                ],
            ]
        );

        $parsed = $this->parse_response($response);
        $this->log('HTTP GET complete', $this->response_context($request_context, $parsed, $t_request));

        return $parsed;
    }

    /**
     * @param array<string,string> $params
     * @return array{ok:bool,status:int,data:array<string,mixed>,error:string}
     */
    private function post(string $method, array $params = []): array
    {
        $request_context = $this->request_context('POST', $method, $params);

        if (!$this->has_credentials()) {
            $this->log('HTTP POST skipped: missing credentials', $request_context);

            return [
                'ok'             => false,
                'status'         => 0,
                'data'           => [],
                'error'          => 'Missing Orion connection key.',
                'body_excerpt'   => '',
                'response_bytes' => 0,
            ];
        }

        $url = add_query_arg(array_merge(['method' => $method], $params), $this->baseUrl);
        $t_request = microtime(true);

        $this->log('HTTP POST start', $request_context);

        $response = wp_remote_post(
            $url,
            [
                'timeout' => $this->timeoutSeconds,
                'headers' => [
                    'Accept'         => 'application/json',
                    'Connection-Key' => $this->connectionKey,
                ],
                'body'    => [],
            ]
        );

        $parsed = $this->parse_response($response);
        $this->log('HTTP POST complete', $this->response_context($request_context, $parsed, $t_request));

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
                'ok'             => false,
                'status'         => 0,
                'data'           => [],
                'error'          => $response->get_error_message(),
                'body_excerpt'   => '',
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
                'ok'             => false,
                'status'         => $status,
                'data'           => [],
                'error'          => 'Orion returned an invalid JSON response.',
                'body_excerpt'   => $body_excerpt,
                'response_bytes' => $response_bytes,
            ];
        }

        $result = strtoupper(trim((string) ($decoded['result'] ?? 'OK')));
        $ok = ($status >= 200 && $status < 300 && $result === 'OK');

        return [
            'ok'             => $ok,
            'status'         => $status,
            'data'           => $decoded,
            'error'          => $ok ? '' : $this->extract_error_message($decoded, $status),
            'body_excerpt'   => $body_excerpt,
            'response_bytes' => $response_bytes,
        ];
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
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function request_context(string $verb, string $method, array $params): array
    {
        $endpoint = $this->endpoint_context();

        return [
            'verb' => $verb,
            'method' => $method,
            'timeout_sec' => $this->timeoutSeconds,
            'endpoint_host' => $endpoint['host'],
            'endpoint_path' => $endpoint['path'],
            'params' => $this->summarize_params($params),
        ];
    }

    /**
     * @return array{host:string,path:string}
     */
    private function endpoint_context(): array
    {
        $parts = parse_url($this->baseUrl);
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

        foreach ($params as $key => $value) {
            $key = (string) $key;
            $value = (string) $value;

            if ($key === 'product_ids') {
                $ids = array_values(array_filter(array_map('trim', explode(',', $value)), static function ($id): bool {
                    return $id !== '';
                }));
                $summary['product_ids_count'] = count($ids);
                $summary['product_ids_sample'] = array_slice($ids, 0, 5);
                continue;
            }

            $summary[$key . '_bytes'] = strlen($value);
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $requestContext
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    private function response_context(array $requestContext, array $parsed, float $t0): array
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

        return $ctx;
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
     * @param array<string,mixed> $decoded
     */
    private function extract_error_message(array $decoded, int $status): string
    {
        foreach (['error_message', 'message', 'error', 'result'] as $key) {
            if (isset($decoded[$key]) && trim((string) $decoded[$key]) !== '') {
                return trim((string) $decoded[$key]);
            }
        }

        return 'Orion request failed with HTTP status ' . $status . '.';
    }

    /**
     * @param int[]|string[] $productIds
     */
    private function normalize_product_ids(array $productIds): string
    {
        // Keep request IDs stable and unique before building Orion's comma
        // separated product_ids query value. This prevents duplicate normalized
        // offer rows from bloating an optimized inventory request.
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
     * @param array<string,mixed> $params
     * @return array<string,string>
     */
    private function normalize_params(array $params): array
    {
        $out = [];

        foreach ($params as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || $value === null) {
                continue;
            }

            if (is_bool($value)) {
                $out[$key] = $value ? '1' : '0';
                continue;
            }

            if (is_array($value)) {
                $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES);
                $out[$key] = is_string($encoded) ? $encoded : '';
                continue;
            }

            $out[$key] = trim((string) $value);
        }

        return $out;
    }
}
