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
     * @return array{ok:bool,status:int,xml_path:string,raw_path:string,xml_bytes:int,body_bytes:int,error:string}
     */
    public function daily_item_update_to_file(string $xmlPath, string $lastUpdate = '1/1/1990', int $lastItem = -1): array
    {
        return $this->post_operation_to_file('DailyItemUpdate', [
            'LastUpdate' => trim($lastUpdate) !== '' ? trim($lastUpdate) : '1/1/1990',
            'LastItem' => (string) $lastItem,
        ], $xmlPath);
    }

    /**
     * @return array{ok:bool,status:int,xml_path:string,raw_path:string,xml_bytes:int,body_bytes:int,error:string}
     */
    public function brand_update_to_file(string $xmlPath): array
    {
        return $this->post_operation_to_file('BrandUpdate', [], $xmlPath);
    }

    /**
     * @return array{ok:bool,status:int,xml_path:string,raw_path:string,xml_bytes:int,body_bytes:int,error:string}
     */
    public function category_update_to_file(string $xmlPath): array
    {
        return $this->post_operation_to_file('CategoryUpdate', [], $xmlPath);
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
     * @param array<string,string> $operationParams
     * @return array{ok:bool,status:int,xml_path:string,raw_path:string,xml_bytes:int,body_bytes:int,error:string}
     */
    private function post_operation_to_file(string $operation, array $operationParams, string $xmlPath): array
    {
        if (!$this->has_credentials()) {
            return [
                'ok' => false,
                'status' => 0,
                'xml_path' => $xmlPath,
                'raw_path' => '',
                'xml_bytes' => 0,
                'body_bytes' => 0,
                'error' => 'Missing Sports South credentials.',
            ];
        }

        if (!function_exists('curl_init')) {
            $response = $this->post_operation($operation, $operationParams);
            $xml = (string) ($response['xml'] ?? '');
            $bytes = $xml !== '' ? file_put_contents($xmlPath, $xml) : false;

            return [
                'ok' => !empty($response['ok']) && $bytes !== false && (int) $bytes > 0,
                'status' => (int) ($response['status'] ?? 0),
                'xml_path' => $xmlPath,
                'raw_path' => '',
                'xml_bytes' => $bytes !== false ? (int) $bytes : 0,
                'body_bytes' => strlen((string) ($response['body'] ?? '')),
                'error' => $bytes === false ? 'Failed to write Sports South XML file.' : (string) ($response['error'] ?? ''),
            ];
        }

        $url = $this->baseUrl . '/' . rawurlencode($operation);
        $body = array_merge($this->credential_body(), $operationParams);
        $rawPath = $xmlPath . '.raw-response.xml';

        $rawHandle = fopen($rawPath, 'wb');
        if (!$rawHandle) {
            return [
                'ok' => false,
                'status' => 0,
                'xml_path' => $xmlPath,
                'raw_path' => $rawPath,
                'xml_bytes' => 0,
                'body_bytes' => 0,
                'error' => 'Failed to open Sports South raw response file.',
            ];
        }

        $t_start = microtime(true);
        DebugLogUtil::log_if_ctx(true, self::LOG_PREFIX, 'POST stream start', [
            'operation' => $operation,
            'url' => $url,
            'timeout_seconds' => $this->timeoutSeconds,
            'params' => $operationParams,
            'xml_path' => $xmlPath,
            'raw_path' => $rawPath,
            'customer_present' => $this->customerNumber !== '' ? 1 : 0,
            'username_present' => $this->username !== '' ? 1 : 0,
            'source_present' => $this->source !== '' ? 1 : 0,
        ], self::DEBUG_FLAG);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($body, '', '&'),
            CURLOPT_HTTPHEADER => [
                'Accept: text/xml, application/xml, */*',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_FILE => $rawHandle,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FAILONERROR => false,
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = $ok === false ? (string) curl_error($ch) : '';
        curl_close($ch);
        fclose($rawHandle);
        clearstatcache(true, $rawPath);

        $bodyBytes = file_exists($rawPath) ? (int) filesize($rawPath) : 0;
        $xmlBytes = 0;
        $error = '';

        if ($ok === false) {
            $error = $curlError !== '' ? $curlError : 'Sports South curl request failed.';
        } elseif ($status < 200 || $status >= 300) {
            $error = 'Sports South ' . $operation . ' failed with HTTP status ' . $status . '.';
        } elseif ($bodyBytes <= 0) {
            $error = 'Sports South ' . $operation . ' returned an empty response.';
        } else {
            $xmlBytes = $this->write_decoded_payload_file($rawPath, $xmlPath);
            if ($xmlBytes <= 0) {
                $error = 'Failed to decode Sports South ASMX response.';
            }
        }

        DebugLogUtil::log_if_ctx(true, self::LOG_PREFIX, 'POST stream complete', [
            'operation' => $operation,
            'ok' => $error === '' ? 1 : 0,
            'status' => $status,
            'error' => $error,
            'xml_bytes' => $xmlBytes,
            'body_bytes' => $bodyBytes,
            'elapsed_ms' => number_format((microtime(true) - $t_start) * 1000.0, 2, '.', ''),
        ], self::DEBUG_FLAG);

        return [
            'ok' => $error === '',
            'status' => $status,
            'xml_path' => $xmlPath,
            'raw_path' => $rawPath,
            'xml_bytes' => $xmlBytes,
            'body_bytes' => $bodyBytes,
            'error' => $error,
        ];
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
        if (trim($xml) === '') {
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

    private function write_decoded_payload_file(string $rawPath, string $xmlPath): int
    {
        $in = fopen($rawPath, 'rb');
        $out = fopen($xmlPath, 'wb');
        if (!$in || !$out) {
            if ($in) {
                fclose($in);
            }
            if ($out) {
                fclose($out);
            }
            return 0;
        }

        $state = 'before_payload';
        $buffer = '';
        $written = 0;
        $tailLength = 512;

        while (!feof($in)) {
            $chunk = fread($in, 1048576);
            if (!is_string($chunk) || $chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            if ($state === 'before_payload') {
                $stringPos = stripos($buffer, '<string');
                if ($stringPos === false) {
                    $buffer = substr($buffer, -$tailLength);
                    continue;
                }

                $openEnd = strpos($buffer, '>', $stringPos);
                if ($openEnd === false) {
                    $buffer = substr($buffer, $stringPos);
                    continue;
                }

                $buffer = substr($buffer, $openEnd + 1);
                $state = 'in_payload';
            }

            if ($state !== 'in_payload') {
                continue;
            }

            $closePos = stripos($buffer, '</string>');
            if ($closePos !== false) {
                $written += $this->write_decoded_payload_chunk($out, substr($buffer, 0, $closePos));
                $buffer = '';
                $state = 'done';
                break;
            }

            if (strlen($buffer) > $tailLength) {
                $limit = strlen($buffer) - $tailLength;
                $cut = strrpos(substr($buffer, 0, $limit), "\n");
                if ($cut === false || $cut <= 0) {
                    $cut = $limit;
                } else {
                    $cut++;
                }

                $flush = substr($buffer, 0, $cut);
                $buffer = substr($buffer, $cut);
                $written += $this->write_decoded_payload_chunk($out, $flush);
            }
        }

        if ($state === 'in_payload' && $buffer !== '') {
            $closePos = stripos($buffer, '</string>');
            $payload = $closePos !== false ? substr($buffer, 0, $closePos) : $buffer;
            $written += $this->write_decoded_payload_chunk($out, $payload);
        }

        fclose($in);
        fclose($out);
        clearstatcache(true, $xmlPath);

        return file_exists($xmlPath) ? (int) filesize($xmlPath) : (int) $written;
    }

    /**
     * @param resource $handle
     */
    private function write_decoded_payload_chunk($handle, string $chunk): int
    {
        if ($chunk === '') {
            return 0;
        }

        $chunk = $this->decode_wrapped_dataset_xml($chunk);
        $bytes = fwrite($handle, $chunk);

        return $bytes !== false ? (int) $bytes : 0;
    }

    private function escape_bare_text_less_than(string $xml): string
    {
        // Vendor text can contain values like "<5mW". After unwrapping the
        // escaped ASMX string, those become invalid XML unless we re-escape them.
        $fixed = preg_replace('/<(?!(?:\/?(?:NewDataSet|Table|Onhand|ServerDateTime|[A-Z][A-Z0-9_]*)(?:\s[^<>]*)?\/?>|[?!]))/', '&lt;', $xml);

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
