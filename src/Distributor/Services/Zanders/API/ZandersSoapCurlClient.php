<?php

declare(strict_types=1);

namespace FFLHub\Distributor\Services\Zanders\API;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Low-level SOAP-over-HTTP client using cURL.
 *
 * Responsibilities:
 * - Build SOAP 1.1 envelope
 * - POST to endpoint with SOAPAction
 * - Return normalized array:
 *   [
 *     'ok' => bool,
 *     'http_status' => int,
 *     'message' => string,
 *     'raw' => string,          // raw XML response (optional; keep small upstream)
 *     'parsed' => array|null,   // parsed summary (optional)
 *     'fault' => array|null,    // soap fault details
 *   ]
 *
 * Notes:
 * - SOAP 1.1: Content-Type text/xml; charset=utf-8 + SOAPAction header.
 * - Uses simple XML parsing (no ext/soap dependency).
 */
final class ZandersSoapCurlClient
{
    /** @var string */
    private $endpoint;

    /** @var int */
    private $timeout_sec;

    /** @var bool */
    private $verify_tls;

    /** @var string|null */
    private $log_prefix;

    /**
     * @param string $endpoint
     * @param int    $timeout_sec
     * @param bool   $verify_tls
     * @param string|null $log_prefix
     */
    public function __construct(string $endpoint, int $timeout_sec = 60, bool $verify_tls = true, ?string $log_prefix = null)
    {
        $this->endpoint    = trim($endpoint);
        $this->timeout_sec = max(5, $timeout_sec);
        $this->verify_tls  = (bool) $verify_tls;
        $this->log_prefix  = $log_prefix;
    }

    /**
     * Call a SOAP operation.
     *
     * @param string               $soap_action Full SOAPAction value or operation name (depends on server)
     * @param string               $operation   SOAP body operation element name (e.g. "DropShipAccessories")
     * @param array<string,mixed>  $params      Operation params (scalar/arrays)
     * @param string               $ns          XML namespace for operation element
     * @param array<string,string> $auth        Optional: ['username' => '...', 'password' => '...'] for header auth
     *
     * @return array<string,mixed>
     */
    public function call(string $soap_action, string $operation, array $params, string $ns, array $auth = []): array
    {
        if ($this->endpoint === '') {
            return $this->fail(0, 'Zanders SOAP endpoint is empty.');
        }

        $soap_action = trim($soap_action);
        $operation   = trim($operation);
        $ns          = trim($ns);

        if ($operation === '' || $ns === '') {
            return $this->fail(0, 'SOAP operation or namespace missing.');
        }

        $xml = $this->build_envelope_soap11($operation, $params, $ns, $auth);

        $headers = [
            'Content-Type: text/xml; charset=utf-8',
            'Accept: text/xml',
        ];

        // Some SOAP servers require SOAPAction quoted; some don’t. Quote is safest for SOAP 1.1.
        if ($soap_action !== '') {
            $headers[] = 'SOAPAction: "' . $soap_action . '"';
        }

        $ch = curl_init($this->endpoint);
        if (!is_resource($ch) && !($ch instanceof \CurlHandle)) {
            return $this->fail(0, 'Failed to initialize cURL.');
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout_sec);

        // TLS verification toggles (keep true for production).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verify_tls ? 1 : 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verify_tls ? 2 : 0);

        // We want headers only for debugging sometimes; keep off by default.
        curl_setopt($ch, CURLOPT_HEADER, false);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $raw === null) {
            return $this->fail($http, 'SOAP request failed: ' . (string) $err, [
                'http_status' => $http,
            ]);
        }

        $raw_s = (string) $raw;

        // If HTTP is non-200, still attempt to parse SOAP Fault (often returned with 500).
        $parsed = $this->parse_soap_response($raw_s);

        if (!empty($parsed['fault'])) {
            $fault = $parsed['fault'];
            $msg = 'SOAP Fault: ' . ($fault['faultstring'] ?? 'Unknown fault');
            return [
                'ok'          => false,
                'http_status' => $http,
                'message'     => $msg,
                'raw'         => $this->maybe_truncate($raw_s),
                'parsed'      => null,
                'fault'       => $fault,
            ];
        }

        // If HTTP bad but no SOAP Fault, treat as failure.
        if ($http < 200 || $http >= 300) {
            return $this->fail($http, 'SOAP HTTP error: ' . $http, [
                'raw'    => $this->maybe_truncate($raw_s),
                'parsed' => $parsed['body'] ?? null,
            ]);
        }

        return [
            'ok'          => true,
            'http_status' => $http,
            'message'     => 'OK',
            'raw'         => $this->maybe_truncate($raw_s),
            'parsed'      => $parsed['body'] ?? null,
            'fault'       => null,
        ];
    }

    /**
     * Build SOAP 1.1 envelope with optional auth header.
     *
     * This supports two common auth patterns:
     * 1) SOAP Header with <Auth><Username>..</Username><Password>..</Password></Auth>
     * 2) No header auth (credentials are in body params) — just pass $auth=[]
     *
     * If Zanders requires a specific header element name/namespace, change build_auth_header().
     *
     * @param string              $operation
     * @param array<string,mixed> $params
     * @param string              $ns
     * @param array<string,string> $auth
     */
    private function build_envelope_soap11(string $operation, array $params, string $ns, array $auth): string
    {
        $op_xml = $this->xml_element($operation, $params, $ns);

        $header_xml = '';
        $user = isset($auth['username']) ? (string) $auth['username'] : '';
        $pass = isset($auth['password']) ? (string) $auth['password'] : '';
        if ($user !== '' || $pass !== '') {
            $header_xml = $this->build_auth_header($user, $pass);
        }

        // SOAP 1.1 envelope
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
            . 'xmlns:xsd="http://www.w3.org/2001/XMLSchema" '
            . 'xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . ($header_xml !== '' ? '<soap:Header>' . $header_xml . '</soap:Header>' : '<soap:Header/>')
            . '<soap:Body>' . $op_xml . '</soap:Body>'
            . '</soap:Envelope>';
    }

    /**
     * Default auth header element. Adjust to match Zanders spec if needed.
     */
    private function build_auth_header(string $username, string $password): string
    {
        // NOTE: no namespace on Auth by default.
        return '<Auth>'
            . '<Username>' . $this->xml_escape($username) . '</Username>'
            . '<Password>' . $this->xml_escape($password) . '</Password>'
            . '</Auth>';
    }

    /**
     * Build an operation element with namespace and children.
     *
     * Scalars become <k>v</k>
     * Arrays become nested elements; numeric arrays become repeated <Item>..</Item> by default.
     *
     * @param string              $name
     * @param array<string,mixed> $params
     * @param string              $ns
     */
    private function xml_element(string $name, array $params, string $ns): string
    {
        $inner = $this->xml_from_params($params);
        return '<' . $name . ' xmlns="' . $this->xml_escape($ns) . '">' . $inner . '</' . $name . '>';
    }

    /**
     * @param mixed $v
     */
    private function xml_from_params($v, string $default_list_item = 'Item'): string
    {
        if (is_array($v)) {
            $is_list = $this->is_list($v);
            $out = '';

            if ($is_list) {
                foreach ($v as $item) {
                    if (is_array($item)) {
                        $out .= '<' . $default_list_item . '>' . $this->xml_from_params($item, $default_list_item) . '</' . $default_list_item . '>';
                    } else {
                        $out .= '<' . $default_list_item . '>' . $this->xml_escape((string) $item) . '</' . $default_list_item . '>';
                    }
                }
                return $out;
            }

            foreach ($v as $k => $val) {
                $k = preg_replace('/[^a-zA-Z0-9_\-:.]/', '', (string) $k);
                if ($k === '') {
                    continue;
                }
                if (is_array($val)) {
                    $out .= '<' . $k . '>' . $this->xml_from_params($val, $default_list_item) . '</' . $k . '>';
                } else {
                    $out .= '<' . $k . '>' . $this->xml_escape((string) $val) . '</' . $k . '>';
                }
            }
            return $out;
        }

        // scalar
        return $this->xml_escape((string) $v);
    }

    private function parse_soap_response(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '') {
            return ['fault' => ['faultstring' => 'Empty response'], 'body' => null];
        }

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            return [
                'fault' => ['faultstring' => 'Invalid XML in SOAP response'],
                'body'  => null,
            ];
        }

        // Register SOAP namespace if present
        $namespaces = $doc->getNamespaces(true);
        $soapNs = $namespaces['soap'] ?? $namespaces['SOAP-ENV'] ?? 'http://schemas.xmlsoap.org/soap/envelope/';

        $body = $doc->children($soapNs)->Body ?? null;
        if (!$body) {
            return ['fault' => ['faultstring' => 'SOAP Body missing'], 'body' => null];
        }

        // Fault?
        $fault = $body->Fault ?? null;
        if ($fault) {
            return [
                'fault' => [
                    'faultcode'   => (string) ($fault->faultcode ?? ''),
                    'faultstring' => (string) ($fault->faultstring ?? ''),
                    'detail'      => isset($fault->detail) ? $this->simplexml_to_array($fault->detail) : null,
                ],
                'body' => null,
            ];
        }

        // Otherwise: first child element under Body is the response payload
        $children = $body->children();
        foreach ($children as $child) {
            return [
                'fault' => null,
                'body'  => $this->simplexml_to_array($child),
            ];
        }

        return ['fault' => null, 'body' => null];
    }

    private function simplexml_to_array(\SimpleXMLElement $x)
    {
        $out = [];

        // attributes
        foreach ($x->attributes() as $k => $v) {
            $out['@' . $k] = (string) $v;
        }

        // children
        $kids = $x->children();
        if ($kids->count() === 0) {
            $s = trim((string) $x);
            return $s;
        }

        foreach ($kids as $k => $child) {
            $val = $this->simplexml_to_array($child);

            if (isset($out[$k])) {
                if (!is_array($out[$k]) || (is_array($out[$k]) && !$this->is_list($out[$k]))) {
                    $out[$k] = [$out[$k]];
                }
                $out[$k][] = $val;
            } else {
                $out[$k] = $val;
            }
        }

        return $out;
    }

    private function is_list(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_v) {
            if ($k !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }

    private function xml_escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function maybe_truncate(string $s, int $max = 6000): string
    {
        $s = (string) $s;
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '...<truncated>';
    }

    private function fail(int $http, string $msg, array $extra = []): array
    {
        return array_merge([
            'ok'          => false,
            'http_status' => (int) $http,
            'message'     => $msg,
            'raw'         => '',
            'parsed'      => null,
            'fault'       => null,
        ], $extra);
    }
}
