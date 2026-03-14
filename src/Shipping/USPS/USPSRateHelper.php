<?php

namespace FFLHub\Shipping\USPS;

use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * USPS Domestic Prices v3 helper for outbound shipping estimates.
 *
 * Design goals:
 * - Keep USPS auth + HTTP details out of shipping method logic.
 * - Fail safely: caller can always fall back to formula pricing.
 * - Cache OAuth token (transient) and repeated same-request quotes.
 */
final class USPSRateHelper
{
    private const TOKEN_TRANSIENT_PREFIX = 'fflhub_usps_token_';
    private const DEFAULT_TIMEOUT_SEC    = 8;

    private const DEFAULT_TEST_BASE_URL = 'https://apis-tem.usps.com';
    private const DEFAULT_PROD_BASE_URL = 'https://apis.usps.com';

    private const TOKEN_PATH      = '/oauth2/v3/token';
    private const BASE_RATES_PATH = '/prices/v3/base-rates/search';

    /** @var array<string,array<string,mixed>> */
    private static array $requestCache = [];

    public function is_enabled(): bool
    {
        $cfg = $this->read_config();

        return $cfg['enabled']
            && $cfg['client_id'] !== ''
            && $cfg['client_secret'] !== ''
            && $cfg['origin_zip'] !== '';
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function estimate_rate(array $args): array
    {
        $cfg = $this->read_config();

        if (!$cfg['enabled']) {
            return $this->error_result('usps_disabled', 'USPS outbound estimate is disabled.');
        }

        if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') {
            return $this->error_result('missing_credentials', 'USPS credentials are missing.');
        }

        if ($cfg['origin_zip'] === '') {
            return $this->error_result('missing_origin_zip', 'USPS origin ZIP is missing.');
        }

        $destination_zip = $this->normalize_us_zip((string) ($args['destination_zip'] ?? ''));
        if ($destination_zip === '') {
            return $this->error_result('missing_destination_zip', 'USPS destination ZIP is missing.');
        }

        $weight_oz = $this->to_non_negative_float($args['weight_oz'] ?? 0.0, 0.0);
        if ($weight_oz <= 0.0) {
            return [
                'ok'          => true,
                'cost'        => 0.0,
                'source'      => 'no_weight',
                'mail_class'  => '',
                'description' => '',
                'http_code'   => 0,
                'error_code'  => '',
                'error'       => '',
            ];
        }

        $length_in = $this->positive_or_default($args['length_in'] ?? 0.0, 9.0);
        $width_in  = $this->positive_or_default($args['width_in'] ?? 0.0, 6.0);
        $height_in = $this->positive_or_default($args['height_in'] ?? 0.0, 2.0);
        [$length_in, $width_in, $height_in] = $this->normalize_dimensions_for_usps($length_in, $width_in, $height_in);

        $weight_lb = round(max(0.0625, $weight_oz / 16.0), 3);

        $payload = [
            'originZIPCode'               => $cfg['origin_zip'],
            'destinationZIPCode'          => $destination_zip,
            'weight'                      => $weight_lb,
            'length'                      => round($length_in, 2),
            'width'                       => round($width_in, 2),
            'height'                      => round($height_in, 2),
            'mailClass'                   => $cfg['mail_class'],
            'processingCategory'          => $cfg['processing_category'],
            'destinationEntryFacilityType'=> $cfg['destination_entry_facility_type'],
            'priceType'                   => $cfg['price_type'],
            'mailingDate'                 => gmdate('Y-m-d'),
        ];

        $rate_indicator = trim((string) $cfg['rate_indicator']);
        if ($rate_indicator === '') {
            $rate_indicator = $this->default_rate_indicator((string) $cfg['mail_class']);
        }
        $payload['rateIndicator'] = ($rate_indicator !== '') ? $rate_indicator : 'SP';

        if ($cfg['account_number'] !== '') {
            $payload['accountType']   = $cfg['account_type'];
            $payload['accountNumber'] = $cfg['account_number'];
        }

        $cache_key = md5(wp_json_encode([
            'base_url' => $cfg['base_url'],
            'payload'  => $payload,
        ]));

        if (isset(self::$requestCache[$cache_key])) {
            return self::$requestCache[$cache_key];
        }

        $token_result = $this->get_access_token($cfg);
        if (empty($token_result['ok'])) {
            $result = $this->error_result(
                (string) ($token_result['error_code'] ?? 'auth_failed'),
                (string) ($token_result['error'] ?? 'USPS auth failed.')
            );
            self::$requestCache[$cache_key] = $result;
            return $result;
        }

        $token = (string) ($token_result['token'] ?? '');
        if ($token === '') {
            $result = $this->error_result('auth_empty_token', 'USPS auth returned empty token.');
            self::$requestCache[$cache_key] = $result;
            return $result;
        }

        $url = rtrim($cfg['base_url'], '/') . self::BASE_RATES_PATH;

        $request_args = [
            'timeout' => (int) $cfg['timeout_sec'],
            'headers' => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body'    => wp_json_encode($payload),
        ];

        $this->log('rate.request', [
            'url'        => $url,
            'timeout'    => (int) $cfg['timeout_sec'],
            'payload'    => $payload,
            'cache_key'  => $cache_key,
        ]);

        $started = microtime(true);
        $res = wp_remote_post($url, $request_args);
        $elapsed_ms = (microtime(true) - $started) * 1000.0;

        if (is_wp_error($res)) {
            $result = $this->error_result(
                'http_error',
                'USPS rate request error: ' . $res->get_error_message()
            );
            $result['elapsed_ms'] = round($elapsed_ms, 2);
            $result['request_url'] = $url;
            $result['request_payload'] = $payload;
            self::$requestCache[$cache_key] = $result;

            $this->log('rate.error', [
                'error'      => (string) $result['error'],
                'elapsed_ms' => $result['elapsed_ms'],
                'url'        => $url,
                'payload'    => $payload,
            ]);

            return $result;
        }

        $http_code = (int) wp_remote_retrieve_response_code($res);
        $body      = (string) wp_remote_retrieve_body($res);
        $json      = json_decode($body, true);

        if ($http_code < 200 || $http_code >= 300) {
            $result = $this->error_result(
                'http_' . (string) $http_code,
                'USPS rate request failed with HTTP ' . (string) $http_code . '.'
            );
            $result['http_code'] = $http_code;
            $result['elapsed_ms'] = round($elapsed_ms, 2);
            $result['response_excerpt'] = $this->excerpt($body, 200);
            $result['request_url'] = $url;
            $result['request_payload'] = $payload;
            self::$requestCache[$cache_key] = $result;

            $this->log('rate.http_fail', [
                'http_code'  => $http_code,
                'elapsed_ms' => $result['elapsed_ms'],
                'excerpt'    => $result['response_excerpt'],
                'response_body' => $body,
                'url'        => $url,
                'payload'    => $payload,
            ]);

            return $result;
        }

        if (!is_array($json)) {
            $result = $this->error_result('invalid_json', 'USPS rate response was not valid JSON.');
            $result['http_code'] = $http_code;
            $result['elapsed_ms'] = round($elapsed_ms, 2);
            $result['response_excerpt'] = $this->excerpt($body, 200);
            $result['request_url'] = $url;
            $result['request_payload'] = $payload;
            self::$requestCache[$cache_key] = $result;
            return $result;
        }

        $cost = $this->extract_total_price($json);
        if ($cost === null) {
            $result = $this->error_result('missing_price', 'USPS rate response did not include a price.');
            $result['http_code'] = $http_code;
            $result['elapsed_ms'] = round($elapsed_ms, 2);
            $result['response_excerpt'] = $this->excerpt($body, 200);
            $result['request_url'] = $url;
            $result['request_payload'] = $payload;
            self::$requestCache[$cache_key] = $result;
            return $result;
        }

        $result = [
            'ok'          => true,
            'cost'        => max(0.0, (float) $cost),
            'source'      => 'usps_api',
            'mail_class'  => $this->extract_mail_class($json, $cfg['mail_class']),
            'description' => $this->extract_description($json),
            'http_code'   => $http_code,
            'error_code'  => '',
            'error'       => '',
            'elapsed_ms'  => round($elapsed_ms, 2),
            'request_url' => $url,
            'request_payload' => $payload,
        ];

        self::$requestCache[$cache_key] = $result;

        $this->log('rate.ok', [
            'destination_zip' => $destination_zip,
            'weight_lb'       => $weight_lb,
            'dims_in'         => sprintf('%.2fx%.2fx%.2f', $length_in, $width_in, $height_in),
            'mail_class'      => (string) $result['mail_class'],
            'cost'            => (float) $result['cost'],
            'elapsed_ms'      => (float) $result['elapsed_ms'],
            'http_code'       => $http_code,
        ]);

        return $result;
    }

    /**
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    private function get_access_token(array $cfg): array
    {
        $transient_key = self::TOKEN_TRANSIENT_PREFIX . md5($cfg['base_url'] . '|' . $cfg['client_id']);
        $cached = get_transient($transient_key);
        if (is_array($cached) && !empty($cached['token'])) {
            return [
                'ok'    => true,
                'token' => (string) $cached['token'],
            ];
        }

        $url = rtrim((string) $cfg['base_url'], '/') . self::TOKEN_PATH;
        $payload = [
            'client_id'     => (string) $cfg['client_id'],
            'client_secret' => (string) $cfg['client_secret'],
            'grant_type'    => 'client_credentials',
        ];

        $request_args = [
            'timeout' => (int) $cfg['timeout_sec'],
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
        ];

        $res = wp_remote_post($url, $request_args);
        if (is_wp_error($res)) {
            return [
                'ok'         => false,
                'error_code' => 'auth_http_error',
                'error'      => 'USPS auth request error: ' . $res->get_error_message(),
            ];
        }

        $http_code = (int) wp_remote_retrieve_response_code($res);
        $body      = (string) wp_remote_retrieve_body($res);
        $json      = json_decode($body, true);

        if ($http_code < 200 || $http_code >= 300 || !is_array($json)) {
            return [
                'ok'         => false,
                'error_code' => 'auth_http_' . (string) $http_code,
                'error'      => 'USPS auth failed (HTTP ' . (string) $http_code . '): ' . $this->excerpt($body, 180),
            ];
        }

        $token = isset($json['access_token']) ? trim((string) $json['access_token']) : '';
        if ($token === '') {
            return [
                'ok'         => false,
                'error_code' => 'auth_missing_token',
                'error'      => 'USPS auth response missing access_token.',
            ];
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
     * @param array<string,mixed> $json
     */
    private function extract_total_price(array $json): ?float
    {
        if (isset($json['totalBasePrice']) && is_numeric($json['totalBasePrice'])) {
            return (float) $json['totalBasePrice'];
        }

        if (
            isset($json['rateOptions'])
            && is_array($json['rateOptions'])
            && isset($json['rateOptions'][0])
            && is_array($json['rateOptions'][0])
            && isset($json['rateOptions'][0]['totalBasePrice'])
            && is_numeric($json['rateOptions'][0]['totalBasePrice'])
        ) {
            return (float) $json['rateOptions'][0]['totalBasePrice'];
        }

        if (
            isset($json['rates'])
            && is_array($json['rates'])
            && isset($json['rates'][0])
            && is_array($json['rates'][0])
            && isset($json['rates'][0]['price'])
            && is_numeric($json['rates'][0]['price'])
        ) {
            return (float) $json['rates'][0]['price'];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $json
     */
    private function extract_mail_class(array $json, string $default): string
    {
        if (
            isset($json['rates'])
            && is_array($json['rates'])
            && isset($json['rates'][0])
            && is_array($json['rates'][0])
            && !empty($json['rates'][0]['mailClass'])
        ) {
            return (string) $json['rates'][0]['mailClass'];
        }

        if (
            isset($json['rateOptions'])
            && is_array($json['rateOptions'])
            && isset($json['rateOptions'][0]['rates'][0]['mailClass'])
        ) {
            return (string) $json['rateOptions'][0]['rates'][0]['mailClass'];
        }

        return $default;
    }

    /**
     * @param array<string,mixed> $json
     */
    private function extract_description(array $json): string
    {
        if (
            isset($json['rates'])
            && is_array($json['rates'])
            && isset($json['rates'][0])
            && is_array($json['rates'][0])
            && !empty($json['rates'][0]['description'])
        ) {
            return (string) $json['rates'][0]['description'];
        }

        if (
            isset($json['rateOptions'])
            && is_array($json['rateOptions'])
            && isset($json['rateOptions'][0]['rates'][0]['description'])
        ) {
            return (string) $json['rateOptions'][0]['rates'][0]['description'];
        }

        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function read_config(): array
    {
        $enabled      = $this->read_bool('FFLHUB_USPS_ESTIMATE_ENABLED', 'fflhub_usps_estimate_enabled', false);
        $use_test_env = $this->read_bool('FFLHUB_USPS_USE_TEST_ENV', 'fflhub_usps_use_test_env', true);

        $base_url = $this->read_string('FFLHUB_USPS_BASE_URL', 'fflhub_usps_base_url', '');
        if ($base_url === '') {
            $base_url = $use_test_env ? self::DEFAULT_TEST_BASE_URL : self::DEFAULT_PROD_BASE_URL;
        }

        // USPS v3 requires valid enum values for these fields.
        $mail_class = strtoupper(trim($this->read_string(
            'FFLHUB_USPS_MAIL_CLASS',
            'fflhub_usps_mail_class',
            'USPS_GROUND_ADVANTAGE'
        )));
        if ($mail_class === '') {
            $mail_class = 'USPS_GROUND_ADVANTAGE';
        }

        $processing_category = strtoupper(trim($this->read_string(
            'FFLHUB_USPS_PROCESSING_CATEGORY',
            'fflhub_usps_processing_category',
            'MACHINABLE'
        )));
        if ($processing_category === '') {
            $processing_category = 'MACHINABLE';
        }

        $destination_entry_facility_type = strtoupper(trim($this->read_string(
            'FFLHUB_USPS_DEST_ENTRY_FACILITY_TYPE',
            'fflhub_usps_destination_entry_facility_type',
            'NONE'
        )));
        if ($destination_entry_facility_type === '') {
            $destination_entry_facility_type = 'NONE';
        }

        $price_type = strtoupper(trim($this->read_string(
            'FFLHUB_USPS_PRICE_TYPE',
            'fflhub_usps_price_type',
            'COMMERCIAL'
        )));
        if ($price_type === '') {
            $price_type = 'COMMERCIAL';
        }

        return [
            'enabled'                         => $enabled,
            'base_url'                        => rtrim($base_url, '/'),
            'client_id'                       => $this->read_string('FFLHUB_USPS_CLIENT_ID', 'fflhub_usps_client_id', ''),
            'client_secret'                   => $this->read_string('FFLHUB_USPS_CLIENT_SECRET', 'fflhub_usps_client_secret', ''),
            'origin_zip'                      => $this->normalize_us_zip(
                $this->read_string('FFLHUB_USPS_ORIGIN_ZIP', 'fflhub_usps_origin_zip', '')
            ),
            'account_type'                    => $this->read_string('FFLHUB_USPS_ACCOUNT_TYPE', 'fflhub_usps_account_type', 'EPS'),
            'account_number'                  => $this->read_string('FFLHUB_USPS_ACCOUNT_NUMBER', 'fflhub_usps_account_number', ''),
            'mail_class'                      => $mail_class,
            'processing_category'             => $processing_category,
            'destination_entry_facility_type' => $destination_entry_facility_type,
            'rate_indicator'                  => $this->read_string('FFLHUB_USPS_RATE_INDICATOR', 'fflhub_usps_rate_indicator', ''),
            'price_type'                      => $price_type,
            'timeout_sec'                     => max(
                3,
                min(
                    30,
                    (int) $this->read_string('FFLHUB_USPS_TIMEOUT_SEC', 'fflhub_usps_timeout_sec', (string) self::DEFAULT_TIMEOUT_SEC)
                )
            ),
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

    private function normalize_us_zip(string $zip): string
    {
        $digits = preg_replace('/\D+/', '', trim($zip));
        if (!is_string($digits) || strlen($digits) < 5) {
            return '';
        }
        return substr($digits, 0, 5);
    }

    /**
     * @param mixed $value
     */
    private function to_non_negative_float($value, float $default = 0.0): float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return max(0.0, $default);
        }

        if (!is_numeric($raw)) {
            $raw = trim((string) preg_replace('/[^0-9.\-]/', '', $raw));
        }

        if ($raw === '' || !is_numeric($raw)) {
            return max(0.0, $default);
        }

        $v = (float) $raw;
        if (!is_finite($v) || $v < 0.0) {
            return max(0.0, $default);
        }

        return $v;
    }

    /**
     * @param mixed $value
     */
    private function positive_or_default($value, float $default): float
    {
        $v = $this->to_non_negative_float($value, $default);
        if ($v <= 0.0) {
            return $default;
        }
        return $v;
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
     * USPS v3 frequently requires a rateIndicator for specific mail classes.
     * We apply a conservative default only when admin did not set one.
     */
    private function default_rate_indicator(string $mail_class): string
    {
        $m = strtoupper(trim($mail_class));
        if ($m === 'USPS_GROUND_ADVANTAGE') {
            return 'SP';
        }
        return 'SP';
    }

    /**
     * USPS expects length to be the longest side, width second, height shortest.
     *
     * @return array{0:float,1:float,2:float}
     */
    private function normalize_dimensions_for_usps(float $length_in, float $width_in, float $height_in): array
    {
        $dims = [
            max(0.25, $length_in),
            max(0.25, $width_in),
            max(0.25, $height_in),
        ];

        rsort($dims, SORT_NUMERIC);

        return [
            (float) $dims[0],
            (float) $dims[1],
            (float) $dims[2],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function error_result(string $code, string $message): array
    {
        return [
            'ok'          => false,
            'cost'        => 0.0,
            'source'      => 'error',
            'mail_class'  => '',
            'description' => '',
            'http_code'   => 0,
            'error_code'  => $code,
            'error'       => $message,
        ];
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $msg, array $ctx = []): void
    {
        DebugLogUtil::log_ctx('FFLHUB_DEBUG_SHIPPING', '[FFLHub][USPSRateHelper]', $msg, $ctx);
    }
}
