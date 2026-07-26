<?php
declare(strict_types=1);

namespace FFLHub\Shipping\EasyPost;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin WordPress HTTP API wrapper for EasyPost API v2.
 *
 * EasyPost uses HTTP Basic Auth where the API key is the username and the
 * password is blank. This class owns auth, JSON decoding, and consistent
 * WP_Error shaping; callers own order/business policy.
 */
final class EasyPostClient
{
    private const BASE_URL = 'https://api.easypost.com/v2';
    private const TIMEOUT_SEC = 30;

    private string $api_key;

    public function __construct(?string $api_key = null)
    {
        $this->api_key = trim((string) ($api_key ?? EasyPostOptions::api_key()));
    }

    public function has_api_key(): bool
    {
        return $this->api_key !== '';
    }

    /**
     * @param string[] $carriers
     * @param string[] $types
     * @return array<string,mixed>|WP_Error
     */
    public function carrier_metadata(array $carriers = [], array $types = [])
    {
        $query = [];
        if (!empty($carriers)) {
            $query['carriers'] = implode(',', array_values(array_filter(array_map('sanitize_key', $carriers))));
        }
        if (!empty($types)) {
            $query['types'] = implode(',', array_values(array_filter(array_map('sanitize_key', $types))));
        }

        return $this->request('GET', '/metadata/carriers', null, $query);
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>|WP_Error
     */
    public function create_address(array $address)
    {
        return $this->request('POST', '/addresses', ['address' => $address]);
    }

    /**
     * @param array<string,mixed> $shipment
     * @return array<string,mixed>|WP_Error
     */
    public function create_shipment(array $shipment)
    {
        return $this->request('POST', '/shipments', ['shipment' => $shipment]);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function retrieve_shipment(string $shipment_id)
    {
        $shipment_id = sanitize_text_field($shipment_id);
        if ($shipment_id === '') {
            return new WP_Error('fflhub_easypost_missing_shipment_id', 'Missing EasyPost shipment ID.');
        }

        return $this->request('GET', '/shipments/' . rawurlencode($shipment_id));
    }

    /**
     * @param array<int,array<string,mixed>> $shipments
     * @return array<string,mixed>|WP_Error
     */
    public function create_batch(array $shipments, string $reference = '')
    {
        if (empty($shipments)) {
            return new WP_Error('fflhub_easypost_empty_batch', 'EasyPost batch needs at least one shipment.');
        }

        $batch = [
            'shipments' => array_values($shipments),
        ];

        $reference = sanitize_text_field($reference);
        if ($reference !== '') {
            $batch['reference'] = $reference;
        }

        return $this->request('POST', '/batches', ['batch' => $batch]);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function retrieve_batch(string $batch_id)
    {
        $batch_id = sanitize_text_field($batch_id);
        if ($batch_id === '') {
            return new WP_Error('fflhub_easypost_missing_batch_id', 'Missing EasyPost batch ID.');
        }

        return $this->request('GET', '/batches/' . rawurlencode($batch_id));
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function buy_batch(string $batch_id)
    {
        $batch_id = sanitize_text_field($batch_id);
        if ($batch_id === '') {
            return new WP_Error('fflhub_easypost_missing_batch_id', 'Missing EasyPost batch ID.');
        }

        return $this->request('POST', '/batches/' . rawurlencode($batch_id) . '/buy');
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function create_batch_label(string $batch_id, string $file_format = 'PDF')
    {
        $batch_id = sanitize_text_field($batch_id);
        if ($batch_id === '') {
            return new WP_Error('fflhub_easypost_missing_batch_id', 'Missing EasyPost batch ID.');
        }

        $format = strtoupper(sanitize_text_field($file_format));
        if (!in_array($format, ['PDF', 'ZPL', 'EPL2'], true)) {
            $format = 'PDF';
        }

        return $this->request('POST', '/batches/' . rawurlencode($batch_id) . '/label', [
            'file_format' => $format,
        ]);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>|WP_Error
     */
    public function buy_shipment(string $shipment_id, string $rate_id, array $options = [], string $insurance = '')
    {
        $shipment_id = sanitize_text_field($shipment_id);
        $rate_id = sanitize_text_field($rate_id);
        if ($shipment_id === '' || $rate_id === '') {
            return new WP_Error('fflhub_easypost_missing_buy_fields', 'Missing EasyPost shipment or rate ID.');
        }

        $payload = [
            'rate' => [
                'id' => $rate_id,
            ],
        ];

        if (!empty($options)) {
            $payload['options'] = $options;
        }

        $insurance = trim($insurance);
        if ($insurance !== '') {
            $payload['insurance'] = $insurance;
        }

        return $this->request('POST', '/shipments/' . rawurlencode($shipment_id) . '/buy', $payload);
    }

    /**
     * @param string[] $tracking_codes
     * @return array<string,mixed>|WP_Error
     */
    public function refund_tracking_codes(string $carrier, array $tracking_codes)
    {
        $carrier = sanitize_text_field($carrier);
        $codes = [];
        foreach ($tracking_codes as $tracking_code) {
            $tracking_code = sanitize_text_field((string) $tracking_code);
            if ($tracking_code !== '') {
                $codes[] = $tracking_code;
            }
        }

        if ($carrier === '' || empty($codes)) {
            return new WP_Error('fflhub_easypost_missing_refund_fields', 'Missing EasyPost carrier or tracking code.');
        }

        return $this->request('POST', '/refunds', [
            'refund' => [
                'carrier' => $carrier,
                'tracking_codes' => $codes,
            ],
        ]);
    }

    /**
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    public function download_label(string $url)
    {
        $url = esc_url_raw($url);
        if ($url === '') {
            return new WP_Error('fflhub_easypost_missing_label_url', 'Missing EasyPost label URL.');
        }

        $response = wp_remote_get($url, [
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
                'fflhub_easypost_download_failed',
                'EasyPost label download failed.',
                ['status' => $code]
            );
        }

        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        if ($content_type === '') {
            $content_type = 'application/octet-stream';
        }

        $filename = basename((string) wp_parse_url($url, PHP_URL_PATH));
        if ($filename === '' || $filename === '/') {
            $filename = 'easypost-label';
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
            return new WP_Error('fflhub_easypost_missing_api_key', 'EasyPost API key is not configured.');
        }

        $url = rtrim(self::BASE_URL, '/') . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $args = [
            'method' => strtoupper($method),
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->api_key . ':'),
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
        $request_id = (string) wp_remote_retrieve_header($response, 'x-request-id');
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
            'fflhub_easypost_http_error',
            $error->get_error_message(),
            ['status' => 0]
        );
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function api_error(array $decoded, int $status, string $request_id, string $raw_body): WP_Error
    {
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $messages = [];
        if (trim((string) ($error['message'] ?? '')) !== '') {
            $messages[] = trim((string) $error['message']);
        }
        if (trim((string) ($decoded['message'] ?? '')) !== '') {
            $messages[] = trim((string) $decoded['message']);
        }

        $details = [];
        foreach ((array) ($error['errors'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $message = trim((string) ($entry['message'] ?? $entry['reason'] ?? $entry['field'] ?? ''));
            if ($message !== '') {
                $messages[] = $message;
            }
            $details[] = $entry;
        }

        $message = $messages[0] ?? ('EasyPost API request failed with HTTP ' . $status . '.');
        if ($request_id !== '') {
            $message .= ' Request ID: ' . $request_id . '.';
        }

        $raw_body = trim($raw_body);
        if ($raw_body !== '' && empty($details)) {
            $message .= ' Response: ' . substr($raw_body, 0, 500);
        }

        return new WP_Error(
            'fflhub_easypost_api_error',
            $message,
            [
                'status' => $status,
                'request_id' => $request_id,
                'errors' => $details,
                'raw_response' => substr($raw_body, 0, 1000),
            ]
        );
    }
}
