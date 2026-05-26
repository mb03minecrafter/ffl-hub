<?php

namespace FFLHub\Distributor\Services\Orion\API;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin WordPress HTTP client for the Orion Wholesale API.
 */
final class OrionApiClient
{
    public const DEFAULT_BASE_URL = 'https://orionfflsales.com/api.php';

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
        if (!$this->has_credentials()) {
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

        return $this->parse_response($response);
    }

    /**
     * @param array<string,string> $params
     * @return array{ok:bool,status:int,data:array<string,mixed>,error:string}
     */
    private function post(string $method, array $params = []): array
    {
        if (!$this->has_credentials()) {
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

        return $this->parse_response($response);
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
