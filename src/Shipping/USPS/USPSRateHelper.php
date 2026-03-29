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
    private const BASE_RATES_PATH = '/prices/v3/base-rates/search';

    /** @var array<string,array<string,mixed>> */
    private static array $requestCache = [];

    private USPSApiClient $api_client;

    public function __construct(?USPSApiClient $api_client = null)
    {
        $this->api_client = $api_client ?? new USPSApiClient();
    }

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

        $base_weight_oz = $this->to_non_negative_float($args['weight_oz'] ?? 0.0, 0.0);
        $tare_weight_oz = $this->to_non_negative_float($cfg['tare_weight_oz'] ?? 0.0, 0.0);
        $weight_oz = $base_weight_oz;
        if ($weight_oz > 0.0 && $tare_weight_oz > 0.0) {
            $weight_oz += $tare_weight_oz;
        }
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

        $this->log('rate.request', [
            'endpoint'  => self::BASE_RATES_PATH,
            'payload'   => $payload,
            'cache_key' => $cache_key,
        ]);

        $api_result = $this->api_client->post_authenticated_json(self::BASE_RATES_PATH, $payload);
        if (empty($api_result['ok'])) {
            $result = $this->error_result(
                (string) ($api_result['error_code'] ?? 'http_error'),
                (string) ($api_result['error'] ?? 'USPS rate request failed.')
            );
            $result['http_code']        = (int) ($api_result['http_code'] ?? 0);
            $result['elapsed_ms']       = (float) ($api_result['elapsed_ms'] ?? 0.0);
            $result['response_excerpt'] = $this->excerpt((string) ($api_result['response_body'] ?? ''), 200);
            $result['request_url']      = (string) ($api_result['request_url'] ?? '');
            $result['request_payload']  = $payload;
            self::$requestCache[$cache_key] = $result;

            $this->log('rate.error', [
                'error_code' => (string) $result['error_code'],
                'error'      => (string) $result['error'],
                'http_code'  => (int) $result['http_code'],
                'elapsed_ms' => (float) $result['elapsed_ms'],
            ]);

            return $result;
        }

        $http_code = (int) ($api_result['http_code'] ?? 0);
        $elapsed_ms = (float) ($api_result['elapsed_ms'] ?? 0.0);
        $url = (string) ($api_result['request_url'] ?? '');
        $body = (string) ($api_result['response_body'] ?? '');
        $json = (isset($api_result['json']) && is_array($api_result['json'])) ? $api_result['json'] : null;

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
            'base_weight_oz'  => $base_weight_oz,
            'tare_weight_oz'  => $tare_weight_oz,
            'quoted_weight_oz'=> $weight_oz,
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
        $enabled = $this->read_bool('FFLHUB_USPS_ESTIMATE_ENABLED', 'fflhub_usps_estimate_enabled', false);
        $shared_cfg = $this->api_client->get_shared_config();

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
            'base_url'                        => (string) ($shared_cfg['base_url'] ?? ''),
            'client_id'                       => (string) ($shared_cfg['client_id'] ?? ''),
            'client_secret'                   => (string) ($shared_cfg['client_secret'] ?? ''),
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
            'timeout_sec'                     => (int) ($shared_cfg['timeout_sec'] ?? 8),
            'tare_weight_oz'                  => $this->to_non_negative_float(
                $this->read_string('FFLHUB_USPS_TARE_WEIGHT_OZ', 'fflhub_usps_tare_weight_oz', '0'),
                0.0
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
