<?php

namespace FFLHub\Distributor\Services\SportsSouth\API;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Util\DebugLogUtil;

/**
 * Thin client for Sports South's ASMX inventory service.
 *
 * The service supports SOAP, GET, and form POST. We use form POST because it
 * gives the same XML payload without building SOAP envelopes.
 */
final class SportsSouthInventoryClient
{
    public const DEFAULT_BASE_URL = 'https://webservices.theshootingwarehouse.com/smart/inventory.asmx';
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][SportsSouthAPI]';

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
        int $timeoutSeconds = 180
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

    /**
     * @return array{ok:bool,status:int,xml:string,body:string,error:string}
     */
    public function daily_item_update(string $lastUpdate = '1/1/1990', int $lastItem = -1): array
    {
        return $this->post_operation('DailyItemUpdate', [
            'LastUpdate' => trim($lastUpdate) !== '' ? trim($lastUpdate) : '1/1/1990',
            'LastItem' => (string) $lastItem,
        ]);
    }

    /**
     * @return array{ok:bool,status:int,xml:string,body:string,error:string}
     */
    public function incremental_onhand_update(string $sinceDateTime): array
    {
        return $this->post_operation('IncrementalOnhandUpdate', [
            'SinceDateTime' => trim($sinceDateTime),
        ]);
    }

    /**
     * @param array<string,string> $operationParams
     * @return array{ok:bool,status:int,xml:string,body:string,error:string}
     */
    private function post_operation(string $operation, array $operationParams): array
    {
        if (!$this->has_credentials()) {
            return [
                'ok' => false,
                'status' => 0,
                'xml' => '',
                'body' => '',
                'error' => 'Missing Sports South credentials.',
            ];
        }

        $url = $this->baseUrl . '/' . rawurlencode($operation);
        $body = array_merge($this->credential_body(), $operationParams);

        $t_start = microtime(true);
        DebugLogUtil::log_if_ctx(true, self::LOG_PREFIX, 'POST start', [
            'operation' => $operation,
            'url' => $url,
            'timeout_seconds' => $this->timeoutSeconds,
            'params' => $operationParams,
            'customer_present' => $this->customerNumber !== '' ? 1 : 0,
            'username_present' => $this->username !== '' ? 1 : 0,
            'source_present' => $this->source !== '' ? 1 : 0,
        ], self::DEBUG_FLAG);

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
        DebugLogUtil::log_if_ctx(true, self::LOG_PREFIX, 'POST complete', [
            'operation' => $operation,
            'ok' => empty($parsed['ok']) ? 0 : 1,
            'status' => (int) ($parsed['status'] ?? 0),
            'error' => (string) ($parsed['error'] ?? ''),
            'xml_bytes' => strlen((string) ($parsed['xml'] ?? '')),
            'body_bytes' => strlen((string) ($parsed['body'] ?? '')),
            'elapsed_ms' => number_format((microtime(true) - $t_start) * 1000.0, 2, '.', ''),
        ], self::DEBUG_FLAG);

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
     * @param mixed $response
     * @return array{ok:bool,status:int,xml:string,body:string,error:string}
     */
    private function parse_response($response, string $operation): array
    {
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'status' => 0,
                'xml' => '',
                'body' => '',
                'error' => $response->get_error_message(),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $xml = $this->extract_inner_xml($body);

        $error = '';
        if ($status < 200 || $status >= 300) {
            $error = 'Sports South ' . $operation . ' failed with HTTP status ' . $status . '.';
        } elseif ($xml === '' && trim($body) === '') {
            $error = 'Sports South ' . $operation . ' returned an empty response.';
        } elseif ($this->looks_like_auth_failure($body . "\n" . $xml)) {
            $error = 'Sports South ' . $operation . ' authentication failed.';
        }

        return [
            'ok' => $error === '',
            'status' => $status,
            'xml' => $xml !== '' ? $xml : $body,
            'body' => $body,
            'error' => $error,
        ];
    }

    private function extract_inner_xml(string $body): string
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
            if ($name === 'string') {
                return $this->decode_wrapped_dataset_xml(trim((string) $xml));
            }

            $namespaces = $xml->getNamespaces(true);
            foreach ($namespaces as $prefix => $namespace) {
                $xml->registerXPathNamespace($prefix !== '' ? $prefix : 'x', $namespace);
            }

            $result_nodes = $xml->xpath('//*[local-name()="DailyItemUpdateResult" or local-name()="IncrementalOnhandUpdateResult"]');
            if (is_array($result_nodes) && isset($result_nodes[0])) {
                return $this->decode_wrapped_dataset_xml(trim((string) $result_nodes[0]));
            }
        }

        if (preg_match('/<string\b[^>]*>(.*?)<\/string>/is', $trimmed, $m)) {
            return $this->decode_wrapped_dataset_xml(trim((string) $m[1]));
        }

        return $trimmed;
    }

    private function decode_wrapped_dataset_xml(string $xml): string
    {
        $xml = trim($xml);
        if ($xml === '') {
            return '';
        }

        // Sports South returns the catalog as escaped XML inside an ASMX string.
        // Decode only markup entities. Product text ampersands arrive as
        // &amp;amp; and must remain valid XML as &amp; until the row parser reads them.
        $xml = strtr($xml, [
            '&lt;' => '<',
            '&LT;' => '<',
            '&#60;' => '<',
            '&#x3c;' => '<',
            '&#X3C;' => '<',
            '&gt;' => '>',
            '&GT;' => '>',
            '&#62;' => '>',
            '&#x3e;' => '>',
            '&#X3E;' => '>',
        ]);

        return $this->escape_bare_text_less_than($xml);
    }

    private function escape_bare_text_less_than(string $xml): string
    {
        // Vendor text can contain values like "<5mW". After unwrapping the
        // escaped ASMX string, those become invalid XML unless we re-escape them.
        $fixed = preg_replace('/<(?!(?:\/?(?:NewDataSet|Table|[A-Z][A-Z0-9_]*)(?:\s[^<>]*)?\/?>|[?!]))/', '&lt;', $xml);

        return is_string($fixed) ? $fixed : $xml;
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
