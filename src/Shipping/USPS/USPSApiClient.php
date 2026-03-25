<?php

namespace FFLHub\Shipping\USPS;

use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared USPS API client:
 * - Reads shared USPS API config (base URL, OAuth credentials, timeout)
 * - Obtains/caches OAuth access token
 * - Performs authenticated JSON requests
 */
final class USPSApiClient
{
    private const TOKEN_TRANSIENT_PREFIX = 'fflhub_usps_token_';
    private const DEFAULT_TIMEOUT_SEC    = 8;

    private const DEFAULT_TEST_BASE_URL = 'https://apis-tem.usps.com';
    private const DEFAULT_PROD_BASE_URL = 'https://apis.usps.com';

    private const TOKEN_PATH = '/oauth2/v3/token';

    /**
     * @return array{
     *   base_url:string,
     *   client_id:string,
     *   client_secret:string,
     *   timeout_sec:int
     * }
     */
    public function get_shared_config(): array
    {
        $use_test_env = $this->read_bool('FFLHUB_USPS_USE_TEST_ENV', 'fflhub_usps_use_test_env', true);

        $base_url = $this->read_string('FFLHUB_USPS_BASE_URL', 'fflhub_usps_base_url', '');
        if ($base_url === '') {
            $base_url = $use_test_env ? self::DEFAULT_TEST_BASE_URL : self::DEFAULT_PROD_BASE_URL;
        }

        $timeout_sec = (int) $this->read_string(
            'FFLHUB_USPS_TIMEOUT_SEC',
            'fflhub_usps_timeout_sec',
            (string) self::DEFAULT_TIMEOUT_SEC
        );
        if ($timeout_sec < 3) {
            $timeout_sec = 3;
        }
        if ($timeout_sec > 30) {
            $timeout_sec = 30;
        }

        return [
            'base_url'      => rtrim($base_url, '/'),
            'client_id'     => $this->read_string('FFLHUB_USPS_CLIENT_ID', 'fflhub_usps_client_id', ''),
            'client_secret' => $this->read_string('FFLHUB_USPS_CLIENT_SECRET', 'fflhub_usps_client_secret', ''),
            'timeout_sec'   => $timeout_sec,
        ];
    }

    public function is_configured(): bool
    {
        $cfg = $this->get_shared_config();

        return $cfg['base_url'] !== ''
            && $cfg['client_id'] !== ''
            && $cfg['client_secret'] !== '';
    }

    /**
     * @return array<string,mixed>
     */
    public function get_access_token(): array
    {
        $cfg = $this->get_shared_config();
        if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') {
            return $this->error_result('missing_credentials', 'USPS credentials are missing.');
        }

        $transient_key = self::TOKEN_TRANSIENT_PREFIX . md5($cfg['base_url'] . '|' . $cfg['client_id']);
        $cached = get_transient($transient_key);
        if (is_array($cached) && !empty($cached['token'])) {
            return [
                'ok'    => true,
                'token' => (string) $cached['token'],
            ];
        }

        $payload = [
            'grant_type'    => 'client_credentials',
            'client_id'     => (string) $cfg['client_id'],
            'client_secret' => (string) $cfg['client_secret'],
        ];

        $response = $this->request_json('POST', self::TOKEN_PATH, [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ], $payload, true);

        if (empty($response['ok'])) {
            $error_code = (string) ($response['error_code'] ?? 'auth_failed');
            if ($error_code === 'http_error') {
                $error_code = 'auth_http_error';
            } elseif (strpos($error_code, 'http_') === 0) {
                $error_code = 'auth_' . $error_code;
            }

            return $this->error_result(
                $error_code,
                (string) ($response['error'] ?? 'USPS auth failed.')
            );
        }

        $json = (isset($response['json']) && is_array($response['json'])) ? $response['json'] : [];
        $token = isset($json['access_token']) ? trim((string) $json['access_token']) : '';
        if ($token === '') {
            return $this->error_result('auth_missing_token', 'USPS auth response missing access_token.');
        }

        $expires_in = (int) ($json['expires_in'] ?? 0);
        if ($expires_in <= 0) {
            $expires_in = 3300;
        }
        $ttl = max(60, $expires_in - 60);

        set_transient($transient_key, ['token' => $token], $ttl);

        return [
            'ok'    => true,
            'token' => $token,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function post_authenticated_json(string $path, array $payload): array
    {
        $token_result = $this->get_access_token();
        if (empty($token_result['ok'])) {
            return $token_result;
        }

        $token = trim((string) ($token_result['token'] ?? ''));
        if ($token === '') {
            return $this->error_result('auth_empty_token', 'USPS auth returned empty token.');
        }

        return $this->request_json('POST', $path, [
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ], $payload, false);
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request_json(
        string $method,
        string $path,
        array $headers,
        array $payload,
        bool $redact_payload = false
    ): array {
        $cfg = $this->get_shared_config();
        $url = rtrim((string) $cfg['base_url'], '/') . '/' . ltrim($path, '/');
        $safe_payload = $redact_payload ? ['redacted' => true] : $payload;
        $encoded_body = wp_json_encode($payload);
        if (!is_string($encoded_body) || $encoded_body === '') {
            $encoded_body = json_encode($payload);
        }
        if (!is_string($encoded_body) || $encoded_body === '') {
            return $this->error_result(
                'encode_error',
                'USPS API request payload could not be encoded as JSON.',
                [
                    'request_url'     => $url,
                    'request_payload' => $safe_payload,
                ]
            );
        }

        $this->log('request', [
            'method'      => $method,
            'url'         => $url,
            'timeout_sec' => (int) $cfg['timeout_sec'],
            'payload'     => $safe_payload,
            'body_len'    => strlen($encoded_body),
        ]);

        $request_args = [
            'method'  => $method,
            'timeout' => (int) $cfg['timeout_sec'],
            'headers' => $headers,
            'body'    => $encoded_body,
        ];

        $started = microtime(true);
        $res = wp_remote_request($url, $request_args);
        $elapsed_ms = round((microtime(true) - $started) * 1000.0, 2);

        if (is_wp_error($res)) {
            return $this->error_result(
                'http_error',
                'USPS API request error: ' . $res->get_error_message(),
                [
                    'elapsed_ms'      => $elapsed_ms,
                    'request_url'     => $url,
                    'request_payload' => $safe_payload,
                ]
            );
        }

        $http_code = (int) wp_remote_retrieve_response_code($res);
        $body      = (string) wp_remote_retrieve_body($res);

        if ($http_code < 200 || $http_code >= 300) {
            return $this->error_result(
                'http_' . (string) $http_code,
                'USPS API request failed with HTTP ' . (string) $http_code . '.',
                [
                    'http_code'        => $http_code,
                    'elapsed_ms'       => $elapsed_ms,
                    'response_excerpt' => $this->excerpt($body, 200),
                    'request_url'      => $url,
                    'request_payload'  => $safe_payload,
                ]
            );
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return $this->error_result(
                'invalid_json',
                'USPS API response was not valid JSON.',
                [
                    'http_code'        => $http_code,
                    'elapsed_ms'       => $elapsed_ms,
                    'response_excerpt' => $this->excerpt($body, 200),
                    'request_url'      => $url,
                    'request_payload'  => $safe_payload,
                ]
            );
        }

        return [
            'ok'              => true,
            'http_code'       => $http_code,
            'json'            => $json,
            'response_body'   => $body,
            'elapsed_ms'      => $elapsed_ms,
            'request_url'     => $url,
            'request_payload' => $safe_payload,
            'error_code'      => '',
            'error'           => '',
        ];
    }

    private function read_string(string $const_name, string $option_name, string $default = ''): string
    {
        if (defined($const_name)) {
            $value = trim((string) constant($const_name));
            if ($value !== '') {
                return $value;
            }
        }

        $value = get_option($option_name, $default);
        return trim((string) $value);
    }

    private function read_bool(string $const_name, string $option_name, bool $default): bool
    {
        if (defined($const_name)) {
            return $this->to_boolish(constant($const_name), $default);
        }

        return $this->to_boolish(get_option($option_name, $default ? '1' : '0'), $default);
    }

    /**
     * @param mixed $value
     */
    private function to_boolish($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return $default;
        }

        if (in_array($raw, ['1', 'true', 'yes', 'on', 'y'], true)) {
            return true;
        }
        if (in_array($raw, ['0', 'false', 'no', 'off', 'n'], true)) {
            return false;
        }

        if (is_numeric($raw)) {
            return ((float) $raw) !== 0.0;
        }

        return $default;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function error_result(string $code, string $message, array $extra = []): array
    {
        $result = [
            'ok'              => false,
            'http_code'       => 0,
            'json'            => [],
            'response_body'   => '',
            'elapsed_ms'      => 0.0,
            'request_url'     => '',
            'request_payload' => [],
            'error_code'      => $code,
            'error'           => $message,
        ];

        foreach ($extra as $k => $v) {
            $result[(string) $k] = $v;
        }

        $this->log('error', [
            'error_code' => $code,
            'error'      => $message,
            'extra'      => $extra,
        ]);

        return $result;
    }

    private function excerpt(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max - 3) . '...';
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        DebugLogUtil::log_ctx('FFLHUB_DEBUG_SHIPPING', '[FFLHub][USPSApiClient]', $message, $ctx);
    }
}
