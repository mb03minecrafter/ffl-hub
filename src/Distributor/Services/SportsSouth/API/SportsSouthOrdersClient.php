<?php

namespace FFLHub\Distributor\Services\SportsSouth\API;

use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin client for Sports South's ASMX order service.
 *
 * The order API supports SOAP and form POST. We use form POST to match the
 * existing inventory client and keep payloads easy to inspect in test mode.
 */
final class SportsSouthOrdersClient
{
    public const DEFAULT_BASE_URL = 'https://webservices.theshootingwarehouse.com/smart/orders.asmx';
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][SportsSouthOrdersAPI]';

    private string $customerNumber;
    private string $username;
    private string $password;
    private string $source;
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct(
        string $customerNumber,
        string $username,
        string $password,
        string $source = '',
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeoutSeconds = 60
    ) {
        $this->customerNumber = trim($customerNumber);
        $this->username = trim($username);
        $this->password = trim($password);
        $this->source = trim($source) !== '' ? trim($source) : $this->customerNumber;
        $this->baseUrl = rtrim(trim($baseUrl) !== '' ? trim($baseUrl) : self::DEFAULT_BASE_URL, '/');
        $this->timeoutSeconds = max(10, $timeoutSeconds);
    }

    public function has_credentials(): bool
    {
        return $this->customerNumber !== '' && $this->username !== '' && $this->password !== '';
    }

    public function get_base_url(): string
    {
        return $this->baseUrl;
    }

    /**
     * Credential probe for the orders endpoint. Uses a high fake order number
     * with Submit so the call should reach order-service validation without
     * creating a header/detail row.
     *
     * @return array<string,mixed>
     */
    public function test_credentials(string $fakeOrderNumber): array
    {
        $fakeOrderNumber = preg_replace('/\D+/', '', trim($fakeOrderNumber));
        if (!is_string($fakeOrderNumber) || $fakeOrderNumber === '') {
            $fakeOrderNumber = '2147483000';
        }

        $resp = $this->post_operation('Submit', [
            'OrderNumber' => $fakeOrderNumber,
        ]);

        $body = (string) ($resp['body'] ?? '');
        $scalar = strtolower(trim((string) ($resp['scalar'] ?? '')));
        $error = strtolower(trim((string) ($resp['error'] ?? '')));

        $credentialsConfirmed = (int) ($resp['status'] ?? 0) >= 200
            && (int) ($resp['status'] ?? 0) < 300
            && trim($body) !== ''
            && !$this->looks_like_auth_failure($body . "\n" . $error)
            && (
                in_array($scalar, ['false', '0', 'true', '1'], true)
                || stripos($body, 'SubmitResult') !== false
                || stripos($body, '<faultstring') !== false
            );

        return array_merge($resp, [
            'operation' => 'Submit',
            'fake_order_number' => $fakeOrderNumber,
            'credentials_confirmed' => $credentialsConfirmed,
        ]);
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    public function add_header(array $params): array
    {
        $resp = $this->post_operation('AddHeader', $params);
        $orderNumber = trim((string) ($resp['scalar'] ?? ''));
        $ok = !empty($resp['ok']) && $orderNumber !== '' && $orderNumber !== '0' && ctype_digit($orderNumber);

        $error = (string) ($resp['error'] ?? '');
        if (!$ok && $error === '') {
            $error = 'Sports South AddHeader returned no order number.';
        }

        return array_merge($resp, [
            'ok' => $ok,
            'status' => (int) ($resp['status'] ?? 0),
            'order_number' => $ok ? $orderNumber : '',
            'scalar' => $orderNumber,
            'body' => (string) ($resp['body'] ?? ''),
            'error' => $error,
        ]);
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    public function add_detail(string $orderNumber, array $params): array
    {
        return $this->boolean_operation('AddDetail', array_merge([
            'OrderNumber' => trim($orderNumber),
        ], $params));
    }

    /**
     * @return array<string,mixed>
     */
    public function submit(string $orderNumber): array
    {
        return $this->boolean_operation('Submit', [
            'OrderNumber' => trim($orderNumber),
        ]);
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function boolean_operation(string $operation, array $params): array
    {
        $resp = $this->post_operation($operation, $params);
        $scalar = strtolower(trim((string) ($resp['scalar'] ?? '')));
        $ok = !empty($resp['ok']) && in_array($scalar, ['true', '1'], true);

        $error = (string) ($resp['error'] ?? '');
        if (!$ok && $error === '') {
            $error = 'Sports South ' . $operation . ' returned false.';
        }

        return array_merge($resp, [
            'ok' => $ok,
            'status' => (int) ($resp['status'] ?? 0),
            'scalar' => (string) ($resp['scalar'] ?? ''),
            'body' => (string) ($resp['body'] ?? ''),
            'error' => $error,
        ]);
    }

    /**
     * @param array<string,string> $operationParams
     * @return array<string,mixed>
     */
    private function post_operation(string $operation, array $operationParams): array
    {
        $url = $this->baseUrl . '/' . rawurlencode($operation);

        if (!$this->has_credentials()) {
            $this->log('Sports South order API request blocked: missing credentials.', [
                'operation' => $operation,
                'base_url' => $this->baseUrl,
                'has_customer' => $this->customerNumber !== '' ? 1 : 0,
                'has_username' => $this->username !== '' ? 1 : 0,
                'has_password' => $this->password !== '' ? 1 : 0,
            ]);

            return [
                'ok' => false,
                'status' => 0,
                'scalar' => '',
                'body' => '',
                'body_excerpt' => '',
                'response_bytes' => 0,
                'error' => 'Missing Sports South order credentials.',
                'operation' => $operation,
                'url' => $url,
                'elapsed_ms' => '0.00',
                'request' => [],
            ];
        }

        $body = array_merge($this->credential_body(), $this->normalize_params($operationParams));
        $t0 = microtime(true);

        $this->log('Sports South order API request starting.', [
            'operation' => $operation,
            'url' => $url,
            'timeout_seconds' => $this->timeoutSeconds,
            'request' => $this->sanitize_params_for_log($body),
        ]);

        $response = wp_remote_post(
            $url,
            [
                'timeout' => $this->timeoutSeconds,
                'headers' => [
                    'Accept' => 'text/xml, application/xml, */*',
                ],
                'body' => $body,
            ]
        );

        $parsed = $this->parse_response($response, $operation);
        $parsed['operation'] = $operation;
        $parsed['url'] = $url;
        $parsed['elapsed_ms'] = number_format((microtime(true) - $t0) * 1000.0, 2, '.', '');
        $parsed['request'] = $this->sanitize_params_for_log($body);

        $this->log(empty($parsed['ok']) ? 'Sports South order API response failed.' : 'Sports South order API response OK.', [
            'operation' => $operation,
            'url' => $url,
            'status' => (int) ($parsed['status'] ?? 0),
            'ok' => !empty($parsed['ok']) ? 1 : 0,
            'scalar' => $this->safe_scalar_for_log((string) ($parsed['scalar'] ?? '')),
            'error' => (string) ($parsed['error'] ?? ''),
            'body_excerpt' => (string) ($parsed['body_excerpt'] ?? ''),
            'response_bytes' => (int) ($parsed['response_bytes'] ?? 0),
            'elapsed_ms' => (string) ($parsed['elapsed_ms'] ?? ''),
        ]);

        return $parsed;
    }

    /**
     * @return array<string,string>
     */
    private function credential_body(): array
    {
        return [
            'CustomerNumber' => $this->customerNumber,
            'UserName' => $this->username,
            'Password' => $this->password,
            'Source' => $this->source,
        ];
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
                $out[$key] = $value ? 'True' : 'False';
                continue;
            }

            $out[$key] = trim((string) $value);
        }

        return $out;
    }

    /**
     * @param mixed $response
     * @return array{ok:bool,status:int,scalar:string,body:string,error:string}
     */
    private function parse_response($response, string $operation): array
    {
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'status' => 0,
                'scalar' => '',
                'body' => '',
                'body_excerpt' => '',
                'response_bytes' => 0,
                'error' => $response->get_error_message(),
                'wp_error_code' => $response->get_error_code(),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $scalar = $this->extract_scalar($body, $operation);
        $fault = $this->extract_fault_string($body);

        $error = '';
        if ($status < 200 || $status >= 300) {
            $error = 'Sports South ' . $operation . ' failed with HTTP status ' . $status . '.';
        } elseif (trim($body) === '') {
            $error = 'Sports South ' . $operation . ' returned an empty response.';
        } elseif ($this->looks_like_auth_failure($body)) {
            $error = 'Sports South ' . $operation . ' authentication failed.';
        } elseif ($fault !== '') {
            $error = 'Sports South ' . $operation . ' SOAP fault: ' . $fault;
        }

        return [
            'ok' => $error === '',
            'status' => $status,
            'scalar' => $scalar,
            'body' => $body,
            'body_excerpt' => $this->excerpt_for_log($body),
            'response_bytes' => strlen($body),
            'error' => $error,
        ];
    }

    private function extract_scalar(string $body, string $operation): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($trimmed);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml instanceof \SimpleXMLElement) {
            $name = strtolower((string) $xml->getName());
            if (in_array($name, ['int', 'boolean', 'string'], true)) {
                return trim((string) $xml);
            }

            $resultName = $operation . 'Result';
            $nodes = $xml->xpath('//*[local-name()="' . $resultName . '"]');
            if (is_array($nodes) && isset($nodes[0])) {
                return trim((string) $nodes[0]);
            }
        }

        if (preg_match('/<(?:int|boolean|string)\b[^>]*>(.*?)<\/(?:int|boolean|string)>/is', $trimmed, $m)) {
            return trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }

        if (preg_match('/<' . preg_quote($operation, '/') . 'Result\b[^>]*>(.*?)<\/' . preg_quote($operation, '/') . 'Result>/is', $trimmed, $m)) {
            return trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }

        return '';
    }

    private function extract_fault_string(string $body): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($trimmed);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml instanceof \SimpleXMLElement) {
            $nodes = $xml->xpath('//*[local-name()="faultstring" or local-name()="FaultString"]');
            if (is_array($nodes) && isset($nodes[0])) {
                return $this->excerpt_for_log((string) $nodes[0], 500);
            }
        }

        if (preg_match('/<faultstring\b[^>]*>(.*?)<\/faultstring>/is', $trimmed, $m)) {
            return $this->excerpt_for_log(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'), 500);
        }

        return '';
    }

    private function looks_like_auth_failure(string $text): bool
    {
        $needle = strtolower($text);

        return strpos($needle, 'not authenticated') !== false
            || strpos($needle, 'not authorized') !== false
            || strpos($needle, 'invalid password') !== false
            || strpos($needle, 'invalid username') !== false;
    }

    /**
     * @param array<string,string> $params
     * @return array<string,string>
     */
    private function sanitize_params_for_log(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            if (in_array(strtolower($key), ['password'], true)) {
                $out[$key] = '[redacted]';
                continue;
            }

            if (in_array(strtolower($key), ['customernumber', 'username'], true)) {
                $out[$key] = $this->mask_value((string) $value);
                continue;
            }

            $out[$key] = $this->excerpt_for_log((string) $value, 300);
        }

        return $out;
    }

    private function mask_value(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $len = strlen($value);
        if ($len <= 2) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($value, -1);
    }

    private function safe_scalar_for_log(string $scalar): string
    {
        return $this->excerpt_for_log($scalar, 500);
    }

    private function excerpt_for_log(string $text, int $max = 1200): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return '';
        }

        $text = (string) preg_replace('/(<Password>).*?(<\/Password>)/i', '$1[redacted]$2', $text);
        $text = (string) preg_replace('/(Password=)[^&\s]+/i', '$1[redacted]', $text);

        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max) . '...';
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log_if(true, self::LOG_PREFIX, $message, self::DEBUG_FLAG);
            return;
        }

        DebugLogUtil::log_if_ctx(true, self::LOG_PREFIX, $message, $ctx, self::DEBUG_FLAG);
    }
}
