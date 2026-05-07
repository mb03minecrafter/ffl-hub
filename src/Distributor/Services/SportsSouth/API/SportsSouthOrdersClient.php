<?php

namespace FFLHub\Distributor\Services\SportsSouth\API;

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
     * @param array<string,string> $params
     * @return array{ok:bool,status:int,order_number:string,scalar:string,body:string,error:string}
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

        return [
            'ok' => $ok,
            'status' => (int) ($resp['status'] ?? 0),
            'order_number' => $ok ? $orderNumber : '',
            'scalar' => $orderNumber,
            'body' => (string) ($resp['body'] ?? ''),
            'error' => $error,
        ];
    }

    /**
     * @param array<string,string> $params
     * @return array{ok:bool,status:int,scalar:string,body:string,error:string}
     */
    public function add_detail(string $orderNumber, array $params): array
    {
        return $this->boolean_operation('AddDetail', array_merge([
            'OrderNumber' => trim($orderNumber),
        ], $params));
    }

    /**
     * @return array{ok:bool,status:int,scalar:string,body:string,error:string}
     */
    public function submit(string $orderNumber): array
    {
        return $this->boolean_operation('Submit', [
            'OrderNumber' => trim($orderNumber),
        ]);
    }

    /**
     * @param array<string,string> $params
     * @return array{ok:bool,status:int,scalar:string,body:string,error:string}
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

        return [
            'ok' => $ok,
            'status' => (int) ($resp['status'] ?? 0),
            'scalar' => (string) ($resp['scalar'] ?? ''),
            'body' => (string) ($resp['body'] ?? ''),
            'error' => $error,
        ];
    }

    /**
     * @param array<string,string> $operationParams
     * @return array{ok:bool,status:int,scalar:string,body:string,error:string}
     */
    private function post_operation(string $operation, array $operationParams): array
    {
        if (!$this->has_credentials()) {
            return [
                'ok' => false,
                'status' => 0,
                'scalar' => '',
                'body' => '',
                'error' => 'Missing Sports South order credentials.',
            ];
        }

        $url = $this->baseUrl . '/' . rawurlencode($operation);
        $body = array_merge($this->credential_body(), $this->normalize_params($operationParams));

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

        return $this->parse_response($response, $operation);
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
                'error' => $response->get_error_message(),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $scalar = $this->extract_scalar($body, $operation);

        $error = '';
        if ($status < 200 || $status >= 300) {
            $error = 'Sports South ' . $operation . ' failed with HTTP status ' . $status . '.';
        } elseif (trim($body) === '') {
            $error = 'Sports South ' . $operation . ' returned an empty response.';
        } elseif ($this->looks_like_auth_failure($body)) {
            $error = 'Sports South ' . $operation . ' authentication failed.';
        }

        return [
            'ok' => $error === '',
            'status' => $status,
            'scalar' => $scalar,
            'body' => $body,
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

    private function looks_like_auth_failure(string $text): bool
    {
        $needle = strtolower($text);

        return strpos($needle, 'not authenticated') !== false
            || strpos($needle, 'not authorized') !== false
            || strpos($needle, 'invalid password') !== false
            || strpos($needle, 'invalid username') !== false;
    }
}
