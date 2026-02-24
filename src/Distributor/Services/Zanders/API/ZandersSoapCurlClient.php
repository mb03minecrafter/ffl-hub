<?php

declare(strict_types=1);

namespace FFLHub\Distributor\Services\Zanders\API;

use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Low-level SOAP-over-HTTP client using cURL.
 *
 * Supports:
 * - Zanders RPC/Encoded payloads (ns2:Map + enc:Array) as shown in their spec examples.
 * - Optional debug logging (request/response head + parse summary).
 * - WSDL URL fix: strip trailing ?wsdl for POST endpoint.
 */
final class ZandersSoapCurlClient
{
    /** Toggle client-level debug logs */
    public const DEBUG_FLAG = 'FFLHUB_ZANDERS_SOAP_DEBUG';

    /** @var string */
    private $endpoint;

    /** @var int */
    private $timeout_sec;

    /** @var bool */
    private $verify_tls;

    /** @var string|null */
    private $log_prefix;

    public function __construct(string $endpoint, int $timeout_sec = 60, bool $verify_tls = true, ?string $log_prefix = null)
    {
        $endpoint = trim($endpoint);

        // If someone passes a WSDL URL, derive the actual endpoint.
        $endpoint = (string) preg_replace('/\?wsdl$/i', '', $endpoint);

        $this->endpoint    = $endpoint;
        $this->timeout_sec = max(5, $timeout_sec);
        $this->verify_tls  = (bool) $verify_tls;
        $this->log_prefix  = $log_prefix;
    }

    /**
     * Call a SOAP operation.
     *
     * Options:
     * - mode: 'zanders_rpc_encoded' | 'soap11_literal' (default: soap11_literal)
     *
     * @param string               $soap_action
     * @param string               $operation
     * @param array<string,mixed>  $params
     * @param string               $ns
     * @param array<string,string> $auth
     * @param array<string,mixed>  $options
     *
     * @return array<string,mixed>
     */
    public function call(
        string $soap_action,
        string $operation,
        array $params,
        string $ns,
        array $auth = [],
        array $options = []
    ): array {
        if ($this->endpoint === '') {
            return $this->fail(0, 'Zanders SOAP endpoint is empty.');
        }

        $soap_action = trim($soap_action);
        $operation   = trim($operation);
        $ns          = trim($ns);

        if ($operation === '' || $ns === '') {
            return $this->fail(0, 'SOAP operation or namespace missing.');
        }

        $mode = (string) ($options['mode'] ?? 'soap11_literal');

        if ($mode === 'zanders_rpc_encoded') {
            $xml = $this->build_envelope_zanders_rpc_encoded($operation, $params, $ns);
            $headers = [
                // Zanders examples often use SOAP 1.2 envelope URI. Many servers still accept text/xml.
                'Content-Type: text/xml; charset=utf-8',
                'Accept: text/xml',
            ];
            if ($soap_action !== '') {
                $headers[] = 'SOAPAction: "' . $soap_action . '"';
            }
        } else {
            // Legacy SOAP 1.1 doc/literal envelope (kept as fallback)
            $xml = $this->build_envelope_soap11_literal($operation, $params, $ns, $auth);
            $headers = [
                'Content-Type: text/xml; charset=utf-8',
                'Accept: text/xml',
            ];
            if ($soap_action !== '') {
                $headers[] = 'SOAPAction: "' . $soap_action . '"';
            }
        }

        $this->dbg('SOAP request', [
            'endpoint'    => $this->endpoint,
            'operation'   => $operation,
            'soap_action' => $soap_action,
            'ns'          => $ns,
            'mode'        => $mode,
            'timeout_sec' => $this->timeout_sec,
            'verify_tls'  => $this->verify_tls ? 1 : 0,
            'req_xml'     => $this->maybe_truncate($this->redact_xml($xml), 6000),
        ]);

        $ch = curl_init($this->endpoint);
        if (!is_resource($ch) && !($ch instanceof \CurlHandle)) {
            return $this->fail(0, 'Failed to initialize cURL.');
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout_sec);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verify_tls ? 1 : 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verify_tls ? 2 : 0);

        curl_setopt($ch, CURLOPT_HEADER, false);

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $raw === null) {
            $this->dbg('SOAP transport fail', [
                'http_status' => $http,
                'curl_err'    => (string) $err,
            ]);

            return $this->fail($http, 'SOAP request failed: ' . (string) $err, [
                'http_status' => $http,
            ]);
        }

        $raw_s = (string) $raw;



        // FULL RAW SOAP RESPONSE (only when debug enabled)
        $dom = new \DOMDocument();
        $pretty = $raw_s;
        if (@$dom->loadXML($raw_s)) {
            $dom->formatOutput = true;
            $pretty = $dom->saveXML();
        }
        $this->dbg('SOAP response (FULL XML)', [
            'http_status' => $http,
            'raw_xml'     => $this->maybe_truncate($pretty, 20000),
        ]);




        $this->dbg('SOAP response (raw head)', [
            'http_status' => $http,
            'raw_head'    => substr($raw_s, 0, 400),
        ]);

        $parsed = $this->parse_soap_response($raw_s);

        $this->dbg('SOAP response (parsed summary)', [
            'http_status' => $http,
            'has_fault'   => !empty($parsed['fault']) ? 1 : 0,
            'body_type'   => is_array($parsed['body'] ?? null) ? 'array' : (is_string($parsed['body'] ?? null) ? 'string' : 'null'),
            'fault'       => $parsed['fault'] ?? null,
        ]);

        if (!empty($parsed['fault'])) {
            $fault = $parsed['fault'];
            $msg   = 'SOAP Fault: ' . ($fault['faultstring'] ?? 'Unknown fault');

            return [
                'ok'          => false,
                'http_status' => $http,
                'message'     => $msg,
                'raw'         => $this->maybe_truncate($raw_s),
                'parsed'      => null,
                'fault'       => $fault,
            ];
        }

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
     * SOAP 1.1 doc/literal (fallback)
     */
    private function build_envelope_soap11_literal(string $operation, array $params, string $ns, array $auth): string
    {
        $op_xml = $this->xml_element_literal($operation, $params, $ns);

        $header_xml = '';
        $user = isset($auth['username']) ? (string) $auth['username'] : '';
        $pass = isset($auth['password']) ? (string) $auth['password'] : '';
        if ($user !== '' || $pass !== '') {
            $header_xml = '<Auth><Username>' . $this->xml_escape($user) . '</Username><Password>' . $this->xml_escape($pass) . '</Password></Auth>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
            . 'xmlns:xsd="http://www.w3.org/2001/XMLSchema" '
            . 'xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . ($header_xml !== '' ? '<soap:Header>' . $header_xml . '</soap:Header>' : '<soap:Header/>')
            . '<soap:Body>' . $op_xml . '</soap:Body>'
            . '</soap:Envelope>';
    }

    /**
     * Zanders RPC/Encoded envelope.
     *
     * Matches their XML examples:
     * - env namespace = http://www.w3.org/2003/05/soap-envelope
     * - order/addressinfo params encoded as ns2:Map
     * - items encoded as enc:Array of ns2:Map
     */
    private function build_envelope_zanders_rpc_encoded(string $operation, array $params, string $ns): string
    {
        $envNs = 'http://www.w3.org/2003/05/soap-envelope';
        $xsdNs = 'http://www.w3.org/2001/XMLSchema';
        $xsiNs = 'http://www.w3.org/2001/XMLSchema-instance';
        $ns2Ns = 'http://xml.apache.org/xml-soap';
        $encNs = 'http://www.w3.org/2003/05/soap-encoding';
        $rpcNs = 'http://www.w3.org/2003/05/soap-rpc';

        $opXml = $this->xml_element_zanders_rpc($operation, $params, $ns, $xsdNs, $xsiNs, $ns2Ns, $encNs);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<env:Envelope xmlns:env="' . $envNs . '" '
            . 'xmlns:ns1="' . $this->xml_escape($ns) . '" '
            . 'xmlns:xsd="' . $xsdNs . '" '
            . 'xmlns:xsi="' . $xsiNs . '" '
            . 'xmlns:ns2="' . $ns2Ns . '" '
            . 'xmlns:enc="' . $encNs . '">'
            . '<env:Body xmlns:rpc="' . $rpcNs . '">'
            . $opXml
            . '</env:Body>'
            . '</env:Envelope>';
    }

    private function xml_element_zanders_rpc(
        string $operation,
        array $params,
        string $ns,
        string $xsdNs,
        string $xsiNs,
        string $ns2Ns,
        string $encNs
    ): string {
        $inner = '';

        foreach ($params as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9_\-:.]/', '', (string) $k);
            if ($k === '') {
                continue;
            }

            // Zanders expects "order" and "addressinfo" as ns2:Map, not nested xml.
            if ($k === 'order' && is_array($v)) {
                $inner .= $this->zanders_param_map('order', $v, $xsdNs, $xsiNs, $ns2Ns, $encNs);
                continue;
            }
            if ($k === 'addressinfo' && is_array($v)) {
                $inner .= $this->zanders_param_map('addressinfo', $v, $xsdNs, $xsiNs, $ns2Ns, $encNs);
                continue;
            }

            // Scalars
            $inner .= $this->zanders_scalar($k, $v, $xsdNs, $xsiNs);
        }

        // NOTE: Zanders examples place encodingStyle on the operation element.
        return '<ns1:' . $operation . ' env:encodingStyle="http://www.w3.org/2003/05/soap-encoding" xmlns:env="http://www.w3.org/2003/05/soap-envelope">'
            . $inner
            . '</ns1:' . $operation . '>';
    }

    private function zanders_scalar(string $name, $v, string $xsdNs, string $xsiNs): string
    {
        $type = 'xsd:string';
        $val  = '';

        if (is_bool($v)) {
            $type = 'xsd:boolean';
            $val  = $v ? 'true' : 'false';
        } elseif (is_int($v)) {
            $type = 'xsd:int';
            $val  = (string) $v;
        } else {
            $type = 'xsd:string';
            $val  = (string) $v;
        }

        return '<' . $name . ' xsi:type="' . $type . '">' . $this->xml_escape($val) . '</' . $name . '>';
    }

    /**
     * Encode associative array as ns2:Map.
     * Special-case: items => enc:Array of ns2:Map (line items)
     */
    private function zanders_param_map(
        string $paramName,
        array $assoc,
        string $xsdNs,
        string $xsiNs,
        string $ns2Ns,
        string $encNs
    ): string {
        $out = '<' . $paramName . ' xsi:type="ns2:Map">';

        foreach ($assoc as $k => $v) {
            $key = (string) $k;
            if ($key === '') {
                continue;
            }

            if ($key === 'items' && is_array($v)) {
                // items is enc:Array of ns2:Map with arraySize=N
                $items = $this->is_list($v) ? $v : array_values($v);
                $n = count($items);

                $valueXml = '<value enc:itemType="ns2:Map" enc:arraySize="' . $n . '" xsi:type="enc:Array">';
                foreach ($items as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $valueXml .= '<item xsi:type="ns2:Map">' . $this->zanders_map_items($row) . '</item>';
                }
                $valueXml .= '</value>';

                $out .= '<item>'
                    . '<key xsi:type="xsd:string">' . $this->xml_escape($key) . '</key>'
                    . $valueXml
                    . '</item>';

                continue;
            }

            $out .= '<item>'
                . '<key xsi:type="xsd:string">' . $this->xml_escape($key) . '</key>'
                . $this->zanders_map_value($v)
                . '</item>';
        }

        $out .= '</' . $paramName . '>';
        return $out;
    }

    private function zanders_map_items(array $assoc): string
    {
        $out = '';
        foreach ($assoc as $k => $v) {
            $key = (string) $k;
            if ($key === '') {
                continue;
            }
            $out .= '<item>'
                . '<key xsi:type="xsd:string">' . $this->xml_escape($key) . '</key>'
                . $this->zanders_map_value($v)
                . '</item>';
        }
        return $out;
    }

    private function zanders_map_value($v): string
    {
        if (is_bool($v)) {
            return '<value xsi:type="xsd:boolean">' . ($v ? 'true' : 'false') . '</value>';
        }
        if (is_int($v)) {
            return '<value xsi:type="xsd:int">' . (string) $v . '</value>';
        }
        // quantities sometimes shown as string in examples; string is safest.
        return '<value xsi:type="xsd:string">' . $this->xml_escape((string) $v) . '</value>';
    }

    private function xml_element_literal(string $name, array $params, string $ns): string
    {
        $inner = $this->xml_from_params_literal($params);
        return '<' . $name . ' xmlns="' . $this->xml_escape($ns) . '">' . $inner . '</' . $name . '>';
    }

    private function xml_from_params_literal($v, string $default_list_item = 'item'): string
    {
        if (is_array($v)) {
            $is_list = $this->is_list($v);
            $out = '';

            if ($is_list) {
                foreach ($v as $item) {
                    $out .= '<' . $default_list_item . '>' . $this->xml_from_params_literal($item, $default_list_item) . '</' . $default_list_item . '>';
                }
                return $out;
            }

            foreach ($v as $k => $val) {
                $k = preg_replace('/[^a-zA-Z0-9_\-:.]/', '', (string) $k);
                if ($k === '') {
                    continue;
                }
                $out .= '<' . $k . '>' . $this->xml_from_params_literal($val, $default_list_item) . '</' . $k . '>';
            }
            return $out;
        }

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
            return ['fault' => ['faultstring' => 'Invalid XML in SOAP response'], 'body' => null];
        }

        $soap11 = 'http://schemas.xmlsoap.org/soap/envelope/';
        $soap12 = 'http://www.w3.org/2003/05/soap-envelope';

        $body = $doc->children($soap11)->Body ?? null;
        if (!$body) {
            $body = $doc->children($soap12)->Body ?? null;
        }
        if (!$body) {
            // prefix-agnostic fallback
            $namespaces = $doc->getNamespaces(true);
            if (is_array($namespaces)) {
                foreach ($namespaces as $uri) {
                    $try = $doc->children((string) $uri)->Body ?? null;
                    if ($try) {
                        $body = $try;
                        break;
                    }
                }
            }
        }

        if (!$body) {
            return ['fault' => ['faultstring' => 'SOAP Body missing'], 'body' => null];
        }

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

        // First child element under Body (namespaced or not)
        foreach ($body->children() as $child) {
            return ['fault' => null, 'body' => $this->simplexml_to_array($child)];
        }

        $bodyNs = $body->getNamespaces(true);
        if (is_array($bodyNs)) {
            foreach ($bodyNs as $uri) {
                foreach ($body->children((string) $uri) as $child) {
                    return ['fault' => null, 'body' => $this->simplexml_to_array($child)];
                }
            }
        }

        return ['fault' => null, 'body' => null];
    }

    private function simplexml_to_array(\SimpleXMLElement $x)
    {
        $out = [];

        foreach ($x->attributes() as $k => $v) {
            $out['@' . $k] = (string) $v;
        }

        $kids = $x->children();
        if ($kids->count() === 0) {
            return trim((string) $x);
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
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '...<truncated>';
    }

    private function fail(int $http, string $msg, array $extra = []): array
    {
        $this->dbg('SOAP fail', [
            'http_status' => (int) $http,
            'message'     => $msg,
            'extra'       => $extra,
        ]);

        return array_merge([
            'ok'          => false,
            'http_status' => (int) $http,
            'message'     => $msg,
            'raw'         => '',
            'parsed'      => null,
            'fault'       => null,
        ], $extra);
    }

    private function dbg(string $msg, array $ctx = []): void
    {
        $enabled = (defined(self::DEBUG_FLAG) && constant(self::DEBUG_FLAG));
        if (!$enabled) {
            $env = getenv(self::DEBUG_FLAG);
            if ($env === false || $env === '' || $env === '0') {
                return;
            }
            $enabled = true;
        }

        $prefix = $this->log_prefix ?: 'FFLHUB-Zanders-SOAP';
        if (!empty($ctx)) {
            DebugLogUtil::log_if_ctx($enabled, '[FFLHub][' . $prefix . ']', $msg, $ctx, self::DEBUG_FLAG);
            return;
        }

        DebugLogUtil::log_if($enabled, '[FFLHub][' . $prefix . ']', $msg, self::DEBUG_FLAG);
    }

    private function redact_xml(string $xml): string
    {
        $tags = [
            'password',
            'Username',
            'Password',
            'orderCommentsEmail',
            'orderCommentsPhone',
        ];

        foreach ($tags as $tag) {
            $xml = (string) preg_replace(
                '#(<' . preg_quote($tag, '#') . '\b[^>]*>)(.*?)(</' . preg_quote($tag, '#') . '>)#is',
                '$1***REDACTED***$3',
                $xml
            );
        }

        return $xml;
    }
}
