<?php

namespace FFLHub\Distributor\Services\SportsSouth\API;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin client for Sports South's ASMX invoices service.
 */
final class SportsSouthInvoicesClient
{
    public const DEFAULT_BASE_URL = 'https://webservices.theshootingwarehouse.com/smart/invoices.asmx';

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
     * @return array{ok:bool,status:int,scalar:string,rows:array<int,array<string,string>>,body:string,error:string}
     */
    public function get_tracking_by_po(string $poNumber): array
    {
        $resp = $this->post_operation('GetTrackingByPo', [
            'PONumber' => trim($poNumber),
        ]);

        $scalar = (string) ($resp['scalar'] ?? '');
        $rows = !empty($resp['ok']) ? $this->parse_dataset_rows($scalar) : [];

        return [
            'ok' => !empty($resp['ok']),
            'status' => (int) ($resp['status'] ?? 0),
            'scalar' => $scalar,
            'rows' => $rows,
            'body' => (string) ($resp['body'] ?? ''),
            'error' => (string) ($resp['error'] ?? ''),
        ];
    }

    /**
     * Read-only credential probe using a fake PO tracking lookup.
     *
     * @return array<string,mixed>
     */
    public function test_credentials(string $fakePo): array
    {
        $fakePo = trim($fakePo) !== '' ? trim($fakePo) : 'SSTEST' . gmdate('YmdHis');
        $resp = $this->post_operation('GetTrackingByPo', [
            'PONumber' => $fakePo,
        ]);

        $body = (string) ($resp['body'] ?? '');
        $scalar = (string) ($resp['scalar'] ?? '');
        $rows = !empty($resp['ok']) ? $this->parse_dataset_rows($scalar) : [];

        return array_merge($resp, [
            'operation' => 'GetTrackingByPo',
            'fake_po' => $fakePo,
            'rows' => $rows,
            'rows_count' => count($rows),
            'body_excerpt' => $this->excerpt_for_log($body, 2000),
            'response_bytes' => strlen($body),
            'credentials_confirmed' => !empty($resp['ok'])
                && $this->looks_like_tracking_probe_response($body, $scalar),
        ]);
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
                'error' => 'Missing Sports South invoice credentials.',
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
            if (in_array($name, ['string'], true)) {
                return trim((string) $xml);
            }

            $resultName = $operation . 'Result';
            $nodes = $xml->xpath('//*[local-name()="' . $resultName . '"]');
            if (is_array($nodes) && isset($nodes[0])) {
                return trim((string) $nodes[0]);
            }
        }

        if (preg_match('/<string\b[^>]*>(.*?)<\/string>/is', $trimmed, $m)) {
            return trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }

        if (preg_match('/<' . preg_quote($operation, '/') . 'Result\b[^>]*>(.*?)<\/' . preg_quote($operation, '/') . 'Result>/is', $trimmed, $m)) {
            return trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }

        return '';
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function parse_dataset_rows(string $dataset): array
    {
        $dataset = trim(html_entity_decode($dataset, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        if ($dataset === '' || strpos($dataset, '<') === false) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($dataset);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$xml instanceof \SimpleXMLElement) {
            return [];
        }

        $rows = [];
        $nodes = $xml->xpath('//*[local-name()="Table"]');
        if (!is_array($nodes)) {
            return [];
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \SimpleXMLElement) {
                continue;
            }

            $row = [];
            $children = $node->xpath('./*');
            if (!is_array($children)) {
                continue;
            }

            foreach ($children as $child) {
                if (!$child instanceof \SimpleXMLElement) {
                    continue;
                }

                $row[strtoupper(trim((string) $child->getName()))] = trim((string) $child);
            }

            if (!empty($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function looks_like_auth_failure(string $text): bool
    {
        $needle = strtolower($text);

        return strpos($needle, 'not authenticated') !== false
            || strpos($needle, 'not authorized') !== false
            || strpos($needle, 'invalid password') !== false
            || strpos($needle, 'invalid username') !== false;
    }

    private function looks_like_tracking_probe_response(string $body, string $scalar): bool
    {
        $haystack = strtolower($body . "\n" . $scalar);

        return strpos($haystack, 'gettrackingbyporesult') !== false
            || strpos($haystack, '<string') !== false
            || strpos($haystack, '<newdataset') !== false
            || strpos($haystack, '<table') !== false;
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
}
