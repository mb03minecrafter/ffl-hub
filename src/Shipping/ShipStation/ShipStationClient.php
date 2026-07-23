<?php
declare(strict_types=1);

namespace FFLHub\Shipping\ShipStation;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin WordPress HTTP API wrapper for ShipStation API v2.
 *
 * This class owns API authentication, response decoding, request IDs, and safe
 * error shaping. Financial operations are intentionally not retried here.
 */
final class ShipStationClient
{
    private const BASE_URL = 'https://api.shipstation.com';
    private const TIMEOUT_SEC = 30;

    private string $api_key;

    public function __construct(?string $api_key = null)
    {
        $this->api_key = trim((string) ($api_key ?? ShipStationOptions::api_key()));
    }

    public function has_api_key(): bool
    {
        return $this->api_key !== '';
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function list_carriers(int $page = 1, int $page_size = 100, bool $extended = true)
    {
        return $this->request('GET', '/v2/carriers', null, [
            'page' => max(1, $page),
            'page_size' => max(1, min(200, $page_size)),
            'include_extended_details' => $extended ? 'true' : 'false',
        ]);
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>|WP_Error
     */
    public function validate_address(array $address)
    {
        $result = $this->request('POST', '/v2/addresses/validate', [$address]);
        if (is_wp_error($result)) {
            return $result;
        }

        $status = (int) ($result['_fflhub_status'] ?? 0);
        $request_id = (string) ($result['_fflhub_request_id'] ?? '');
        unset($result['_fflhub_status'], $result['_fflhub_request_id']);

        $validated = [];
        foreach ($result as $entry) {
            if (is_array($entry)) {
                $validated[] = $entry;
            }
        }

        return [
            'validated_addresses' => $validated,
            'validation' => $validated[0] ?? [],
            '_fflhub_status' => $status,
            '_fflhub_request_id' => $request_id,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function get_rates(array $payload)
    {
        return $this->request('POST', '/v2/rates', $payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function purchase_label_from_rate(string $rate_id, array $payload)
    {
        $rate_id = sanitize_text_field($rate_id);
        if ($rate_id === '') {
            return new WP_Error('fflhub_shipstation_missing_rate_id', 'Missing ShipStation rate_id.');
        }

        return $this->request('POST', '/v2/labels/rates/' . rawurlencode($rate_id), $payload);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function void_label(string $label_id)
    {
        $label_id = sanitize_text_field($label_id);
        if ($label_id === '') {
            return new WP_Error('fflhub_shipstation_missing_label_id', 'Missing ShipStation label_id.');
        }

        return $this->request('PUT', '/v2/labels/' . rawurlencode($label_id) . '/void');
    }

    /**
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    public function download_label(string $url)
    {
        $url = esc_url_raw($url);
        if ($url === '') {
            return new WP_Error('fflhub_shipstation_missing_label_url', 'Missing ShipStation label URL.');
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        $headers = [];
        if (is_string($host) && preg_match('/(^|\.)(shipstation|shipengine)\.com$/i', $host)) {
            $headers['API-Key'] = $this->api_key;
        }

        $response = wp_remote_get($url, [
            'headers' => $headers,
            'timeout' => self::TIMEOUT_SEC,
            'redirection' => 3,
        ]);

        if (is_wp_error($response)) {
            return $this->http_error($response);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || $body === '') {
            return new WP_Error(
                'fflhub_shipstation_download_failed',
                'ShipStation label download failed.',
                ['status' => $code]
            );
        }

        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        if ($content_type === '') {
            $content_type = 'application/octet-stream';
        }

        $filename = basename((string) wp_parse_url($url, PHP_URL_PATH));
        if ($filename === '' || $filename === '/') {
            $filename = 'shipstation-label';
        }

        return [
            'body' => $body,
            'content_type' => $content_type,
            'filename' => sanitize_file_name($filename),
        ];
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,mixed> $query
     * @return array<string,mixed>|WP_Error
     */
    private function request(string $method, string $path, ?array $body = null, array $query = [])
    {
        if ($this->api_key === '') {
            return new WP_Error('fflhub_shipstation_missing_api_key', 'ShipStation API key is not configured.');
        }

        $url = rtrim(self::BASE_URL, '/') . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $args = [
            'method' => strtoupper($method),
            'headers' => [
                'API-Key' => $this->api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => self::TIMEOUT_SEC,
            'redirection' => 3,
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response) && strtoupper($method) === 'GET') {
            $response = wp_remote_request($url, $args);
        }
        if (is_wp_error($response)) {
            return $this->http_error($response);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 500 && strtoupper($method) === 'GET') {
            $retry = wp_remote_request($url, $args);
            if (!is_wp_error($retry)) {
                $response = $retry;
                $status = (int) wp_remote_retrieve_response_code($response);
            }
        }

        $request_id = (string) wp_remote_retrieve_header($response, 'x-shipstation-requestid');
        $raw_body = (string) wp_remote_retrieve_body($response);
        $decoded = $raw_body !== '' ? json_decode($raw_body, true) : [];
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($status < 200 || $status >= 300) {
            return $this->api_error($decoded, $status, $request_id, $raw_body);
        }

        $decoded['_fflhub_status'] = $status;
        $decoded['_fflhub_request_id'] = $request_id;

        return $decoded;
    }

    private function http_error(WP_Error $error): WP_Error
    {
        return new WP_Error(
            'fflhub_shipstation_http_error',
            $error->get_error_message(),
            ['status' => 0]
        );
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function api_error(array $decoded, int $status, string $request_id, string $raw_body): WP_Error
    {
        $errors = isset($decoded['errors']) && is_array($decoded['errors'])
            ? $decoded['errors']
            : [];
        $normalized_errors = [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            $normalized_errors[] = [
                'source' => (string) ($error['error_source'] ?? $error['source'] ?? ''),
                'type' => (string) ($error['error_type'] ?? $error['type'] ?? ''),
                'code' => (string) ($error['error_code'] ?? $error['code'] ?? ''),
                'message' => (string) ($error['message'] ?? ''),
                'field_name' => (string) ($error['field_name'] ?? $error['field'] ?? ''),
                'raw' => $error,
            ];
        }
        $message = 'ShipStation API request failed.';

        $first = reset($normalized_errors);
        if (is_array($first) && trim((string) ($first['message'] ?? '')) !== '') {
            $message = (string) $first['message'];
        } elseif (trim((string) ($decoded['message'] ?? '')) !== '') {
            $message = (string) $decoded['message'];
        }

        $raw_body = trim($raw_body);
        if ($raw_body !== '') {
            $raw_body = substr($raw_body, 0, 500);
        }

        if ($message === 'ShipStation API request failed.') {
            $message = 'ShipStation API request failed with HTTP ' . $status . '.';
        }

        if ($request_id !== '') {
            $message .= ' Request ID: ' . $request_id . '.';
        }

        if ($raw_body !== '' && empty($normalized_errors)) {
            $message .= ' Response: ' . $raw_body;
        }

        return new WP_Error(
            'fflhub_shipstation_api_error',
            $message,
            [
                'status' => $status,
                'request_id' => $request_id !== '' ? $request_id : (string) ($decoded['request_id'] ?? ''),
                'errors' => $normalized_errors,
                'raw_response' => $raw_body,
            ]
        );
    }

    public static function redact(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return substr($value, 0, 2) . '...' . substr($value, -4);
    }
}
