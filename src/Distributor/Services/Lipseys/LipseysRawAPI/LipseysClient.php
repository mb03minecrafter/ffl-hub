<?php

namespace FFLHub\Distributor\Services\Lipseys\LipseysRawAPI;

use Exception;
use FFLHub\Util\DebugLogUtil;

class LipseysClient
{
    private $BaseUrl = "https://api.lipseys.com/api/";

    private $Email = "";
    private $Password = "";

    private $Account;
    private $Token;
    private $LastLoginHttpCode = 0;

    public function __construct($email, $password)
    {
        if (!extension_loaded('curl')) {
            throw new Exception("This method requires the php curl extension.");
        }
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        $this->Email = $email;
        $this->Password = $password;

        if (session_status() == PHP_SESSION_ACTIVE) {
            $sessionKey = $this->sessionTokenKey();
            if (is_array($_SESSION ?? null) && array_key_exists($sessionKey, $_SESSION)) {
                $this->Token = $_SESSION[$sessionKey];
            }
        }
    }

    /**
     * Authenticate against Lipsey's without making a catalog, validation, or order call.
     *
     * @return array<string,mixed>
     */
    public function Authenticate(): array
    {
        $loginAttemptResult = $this->login();
        if ($loginAttemptResult == 1) {
            return array(
                "authorized" => true,
                "success" => true,
                "errors" => array(),
                "http_code" => (int) $this->LastLoginHttpCode,
                "token_present" => (is_string($this->Token) && $this->Token !== '') ? 1 : 0,
            );
        }

        return $this->InvalidLoginResponse($loginAttemptResult);
    }

    private function RequestBuilder($options)
    {
        $curl = curl_init();
        $verifyTls = $this->shouldVerifyTls();
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $verifyTls ? 2 : 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $verifyTls);
        if ($this->shouldForceIpv4()) {
            curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }
        curl_setopt_array($curl, $options);
        return $curl;
    }

    private function PostRequestBuilder($url, $model, bool $includeToken = true)
    {
        $headers = array(
            "Content-Type: application/json",
            "Accept: application/json",
            "Accept-Encoding: gzip",
        );

        if ($includeToken && is_string($this->Token) && $this->Token !== '') {
            $headers[] = "Token: {$this->Token}";
        }

        $curl = $this->RequestBuilder(array(
            CURLOPT_URL => "{$this->BaseUrl}{$url}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($model),
            CURLOPT_HTTPHEADER => $headers,
        ));
        return $curl;
    }

    private function GetRequestBuilder($url)
    {
        $curl = $this->RequestBuilder(array(
            CURLOPT_URL => "{$this->BaseUrl}{$url}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_POSTFIELDS => "",
            CURLOPT_HTTPHEADER => array(
                "Accept: application/json",
                "Accept-Encoding: gzip",
                "Token: {$this->Token}",
                "cache-control: no-cache"
            ),
        ));
        return $curl;
    }

    private function InvalidLoginResponse($loginResponse)
    {
        $loginDiagnostics = is_array($loginResponse) ? $loginResponse : null;
        $errorsArray = array(
            "Not Authorized Response",
            "Account: " . $this->maskEmail($this->Email),
            date("Y-m-d h:i:s A T"),
            $this->sanitizeErrorValue($loginResponse)
        );
        if (is_array($loginDiagnostics)) {
            if (array_key_exists('http_code', $loginDiagnostics)) {
                $errorsArray[] = 'Login HTTP code: ' . (string) $loginDiagnostics['http_code'];
            }
            if (!empty($loginDiagnostics['curl_errno'])) {
                $errorsArray[] = 'Login curl errno: ' . (string) $loginDiagnostics['curl_errno'];
            }
            if (!empty($loginDiagnostics['curl_error'])) {
                $errorsArray[] = 'Login curl error: ' . $this->sanitizeErrorValue((string) $loginDiagnostics['curl_error']);
            }
            if (!empty($loginDiagnostics['json_error'])) {
                $errorsArray[] = 'Login JSON parse: ' . $this->sanitizeErrorValue((string) $loginDiagnostics['json_error']);
            }
        }
        if ($this->Token) {
            array_push($errorsArray, "Token present in memory.");
        }
        return array(
            "authorized" => false,
            "success" => false,
            "errors" => $errorsArray,
            "login_diagnostics" => $loginDiagnostics,
        );
    }

    private function shouldVerifyTls(): bool
    {
        $verifyTls = true;
        if (function_exists('apply_filters')) {
            $verifyTls = (bool) apply_filters('fflhub_lipseys_verify_tls', true);
        }
        return $verifyTls;
    }

    private function shouldForceIpv4(): bool
    {
        $forceIpv4 = true;
        if (function_exists('apply_filters')) {
            $forceIpv4 = (bool) apply_filters('fflhub_lipseys_force_ipv4', true);
        }
        return $forceIpv4;
    }

    private function sessionTokenKey(): string
    {
        $identity = strtolower(trim((string) $this->Email));
        return 'LipseysSessionToken_' . hash('sha256', $identity);
    }

    private function sanitizeErrorValue($value, int $maxLen = 800): string
    {
        if (is_array($value) || is_object($value)) {
            $json = json_encode($value);
            $value = $json === false ? 'Unable to encode error payload.' : $json;
        }

        $value = (string) $value;
        $value = preg_replace('/("?(?:password|token|authorization)"?\s*[:=]\s*")([^"]*)(")/i', '$1[redacted]$3', $value);
        if ($maxLen > 0 && strlen($value) > $maxLen) {
            $value = substr($value, 0, $maxLen) . '... [truncated]';
        }

        return $value;
    }

    private function maskEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '') {
            return '[empty]';
        }

        $atPos = strpos($email, '@');
        if ($atPos === false) {
            return substr($email, 0, 1) . str_repeat('*', max(strlen($email) - 1, 1));
        }

        $name = substr($email, 0, $atPos);
        $domain = substr($email, $atPos + 1);
        if ($name === '') {
            return '*@' . $domain;
        }

        $prefix = substr($name, 0, min(2, strlen($name)));
        $maskedName = $prefix . str_repeat('*', max(strlen($name) - strlen($prefix), 1));
        return $maskedName . '@' . $domain;
    }

    private function RequestError($error)
    {
        $this->debug_log('request.error', [
            'error' => $this->sanitizeErrorValue((string) $error),
        ]);

        return array(
            "authorized" => false,
            "success" => false,
            "errors" => array(
                "Error making http request",
                $error
            )
        );
    }

    public function CatalogToTsv(string $tsv_path, array $columns, callable $item_to_row): array
    {
        $inputErr = $this->validate_tsv_stream_inputs($tsv_path, $columns, $item_to_row);
        if ($inputErr !== null) {
            return $inputErr;
        }

        $attempts = 0;
        $last_err = null;

        while ($attempts < 2) {
            $attempts++;

            if (!$this->Token) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }
            }

            $result = $this->stream_endpoint_to_tsv_once(
                "integration/items/CatalogFeed",
                'data',
                $tsv_path,
                $columns,
                $item_to_row
            );

            if (is_array($result) && isset($result['authorized']) && $result['authorized'] === false) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }
                $last_err = $result;
                continue;
            }

            return $result;
        }

        return is_array($last_err) ? $last_err : $this->RequestError('CatalogToTsv failed after retry.');
    }

    /**
     * PricingQuantityFeed response format:
     *   { success, authorized, errors, data: { nextUpdate, items: [ ... ] } }
     *
     * We stream the array at "data.items", AND we also extract "data.nextUpdate" while streaming.
     */
    public function PricingAndQuantityToTsv(string $tsv_path, array $columns, callable $item_to_row): array
    {
        $inputErr = $this->validate_tsv_stream_inputs($tsv_path, $columns, $item_to_row);
        if ($inputErr !== null) {
            return $inputErr;
        }

        $attempts = 0;
        $last_err = null;

        while ($attempts < 2) {
            $attempts++;

            if (!$this->Token) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }
            }

            $result = $this->stream_endpoint_to_tsv_once(
                "integration/items/PricingQuantityFeed",
                'data.items',
                $tsv_path,
                $columns,
                $item_to_row
            );

            if (is_array($result) && isset($result['authorized']) && $result['authorized'] === false) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }
                $last_err = $result;
                continue;
            }

            return $result;
        }

        return is_array($last_err) ? $last_err : $this->RequestError('PricingAndQuantityToTsv failed after retry.');
    }

    /**
     * Full catalog feed (JSON, non-streaming).
     *
     * @return array<string,mixed>
     */
    public function Catalog(): array
    {
        return $this->get_with_auth_retry("integration/items/CatalogFeed", 'catalog');
    }

    /**
     * Single catalog item by item number/UPC payload accepted by Lipsey's API.
     *
     * @param mixed $itemNumber
     * @return array<string,mixed>
     */
    public function CatalogItem($itemNumber): array
    {
        $itemNumberStr = trim((string) $itemNumber);
        if ($itemNumberStr === '') {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Item number not provided"
                )
            );
        }

        return $this->post_with_auth_retry("integration/items/CatalogFeed/Item", $itemNumberStr, 'catalog_item');
    }

    /**
     * Full pricing + quantity feed (JSON, non-streaming).
     *
     * @return array<string,mixed>
     */
    public function PricingAndQuantity(): array
    {
        return $this->get_with_auth_retry("integration/items/PricingQuantityFeed", 'pricing_quantity');
    }

    /**
     * Allocation feed (JSON).
     *
     * @return array<string,mixed>
     */
    public function AllocationPricingAndQuantity(): array
    {
        return $this->get_with_auth_retry("integration/items/Allocations", 'allocation_pricing_quantity');
    }

    /**
     * Submit API order.
     *
     * @param mixed $order
     * @return array<string,mixed>
     */
    public function Order($order): array
    {
        $itemsCheck = $this->validate_order_items($order);
        if ($itemsCheck !== null) {
            return $itemsCheck;
        }

        return $this->post_with_auth_retry("integration/order/apiorder", $order, 'order');
    }

    /**
     * Submit allocation order.
     *
     * @param mixed $order
     * @return array<string,mixed>
     */
    public function AllocationOrder($order): array
    {
        $itemsCheck = $this->validate_order_items($order);
        if ($itemsCheck !== null) {
            return $itemsCheck;
        }

        return $this->post_with_auth_retry("integration/order/AllocationOrder", $order, 'allocation_order');
    }

    /**
     * Validate a single item using Lipsey's ValidateItem endpoint.
     *
     * Keeps retry/auth behavior aligned with vendor client while preserving
     * raw HTTP response logging inside our own raw API class.
     */
    public function ValidateItem($itemNumber): array
    {
        $itemNumberStr = trim((string) $itemNumber);
        $callId = substr(sha1($itemNumberStr . '|' . microtime(true) . '|' . mt_rand()), 0, 10);
        $endpoint = "integration/items/validateitem";
        $url = $this->buildUrl($endpoint);

        if ($itemNumberStr === '') {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Item number not provided"
                )
            );
        }

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            if (!$this->Token) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }
            }

            $this->debug_log('validateitem.request', [
                'call_id' => $callId,
                'attempt' => $attempt,
                'method' => 'POST',
                'endpoint' => $url,
                'request_body' => $this->sanitizeErrorValue($itemNumberStr, 4000),
                'token_present' => (is_string($this->Token) && $this->Token !== '') ? 1 : 0,
            ]);

            $curl = $this->PostRequestBuilder($endpoint, $itemNumberStr, true);
            $response = curl_exec($curl);
            $err = curl_error($curl);
            $errno = curl_errno($curl);
            $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            $this->debug_log('validateitem.raw_response', [
                'call_id' => $callId,
                'attempt' => $attempt,
                'http_code' => $http,
                'curl_errno' => (int) $errno,
                'curl_error' => $this->sanitizeErrorValue((string) $err),
                'raw_response' => $this->sanitizeErrorValue(is_string($response) ? $response : '', 4000),
            ]);

            if ($err) {
                return $this->RequestError($err);
            }

            $decode = json_decode((string) $response, true);
            $jsonError = json_last_error() === JSON_ERROR_NONE ? '' : json_last_error_msg();

            $this->debug_log('validateitem.decoded_response', [
                'call_id' => $callId,
                'attempt' => $attempt,
                'json_error' => $jsonError,
                'decoded' => is_array($decode) ? $decode : null,
            ]);

            if (!is_array($decode)) {
                return $this->RequestError('ValidateItem JSON decode failed: ' . ($jsonError !== '' ? $jsonError : 'unknown'));
            }

            if (array_key_exists('authorized', $decode) && $decode['authorized'] == false) {
                if ($attempt === 1) {
                    $loginAttemptResult = $this->login();
                    if ($loginAttemptResult != 1) {
                        return $this->InvalidLoginResponse($loginAttemptResult);
                    }
                    continue;
                }
                return $this->InvalidLoginResponse($response);
            }

            return $decode;
        }

        return $this->RequestError('ValidateItem failed after retry.');
    }

    /**
     * Pull one-day shipment data from Lipsey's for the given date string.
     *
     * @param mixed $date Example format: n/j/Y (UTC), e.g. 3/14/2026
     * @return array<string,mixed>
     */
    public function OneDaysShipping($date): array
    {
        $dateStr = trim((string) $date);
        if ($dateStr === '') {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "date not provided"
                )
            );
        }

        return $this->post_with_auth_retry("integration/shipping/oneday", $dateStr, 'one_day_shipping');
    }

    /**
     * Submit a non-FFL dropship order.
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    public function DropShipAccessories($order): array
    {
        if (!is_array($order)) {
            return $this->validation_error('Order payload must be an array');
        }

        $required = array(
            "BillingName",
            "BillingAddressLine1",
            "BillingAddressCity",
            "BillingAddressState",
            "BillingAddressZip",
            "ShippingName",
            "ShippingAddressLine1",
            "ShippingAddressCity",
            "ShippingAddressState",
            "ShippingAddressZip",
            "PoNumber",
        );
        foreach ($required as $field) {
            $fieldErr = $this->require_non_empty_field($order, $field);
            if ($fieldErr !== null) {
                return $fieldErr;
            }
        }

        $billingStateErr = $this->require_state_code($order, "BillingAddressState", "BillingAddressState Should be 2 Letters");
        if ($billingStateErr !== null) {
            return $billingStateErr;
        }

        $shippingStateErr = $this->require_state_code($order, "ShippingAddressState", "ShippingAddressState Should be 2 Letters");
        if ($shippingStateErr !== null) {
            return $shippingStateErr;
        }

        $billingZipErr = $this->normalize_and_require_zip5($order, "BillingAddressZip", "BillingAddressZip Should be 5 Numbers");
        if ($billingZipErr !== null) {
            return $billingZipErr;
        }

        $shippingZipErr = $this->normalize_and_require_zip5($order, "ShippingAddressZip", "ShippingAddressZip Should be 5 Numbers");
        if ($shippingZipErr !== null) {
            return $shippingZipErr;
        }

        $itemsCheck = $this->validate_order_items($order);
        if ($itemsCheck !== null) {
            return $itemsCheck;
        }

        return $this->post_with_auth_retry("integration/order/dropship", $order, 'dropship_accessories');
    }

    /**
     * Submit an FFL dropship order.
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    public function DropShipFirearms($order): array
    {
        if (!is_array($order)) {
            return $this->validation_error('Order payload must be an array');
        }

        foreach (array("Ffl", "Name", "Phone") as $field) {
            $fieldErr = $this->require_non_empty_field($order, $field);
            if ($fieldErr !== null) {
                return $fieldErr;
            }
        }

        $itemsCheck = $this->validate_order_items($order);
        if ($itemsCheck !== null) {
            return $itemsCheck;
        }

        return $this->post_with_auth_retry("integration/order/DropShipFirearm", $order, 'dropship_firearms');
    }

    /**
     * @param mixed $payload
     * @return array<string,mixed>
     */
    private function post_with_auth_retry(string $endpoint, $payload, string $op): array
    {
        $callId = substr(sha1($endpoint . '|' . microtime(true) . '|' . mt_rand()), 0, 10);
        $url = $this->buildUrl($endpoint);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            if (!$this->Token) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }
            }

            $this->debug_log($op . '.request', [
                'call_id' => $callId,
                'attempt' => $attempt,
                'method' => 'POST',
                'endpoint' => $url,
                'token_present' => (is_string($this->Token) && $this->Token !== '') ? 1 : 0,
                'payload' => $payload,
            ]);

            $curl = $this->PostRequestBuilder($endpoint, $payload, true);
            $response = curl_exec($curl);
            $err = curl_error($curl);
            $errno = curl_errno($curl);
            $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            $this->debug_log($op . '.raw_response', [
                'call_id' => $callId,
                'attempt' => $attempt,
                'http_code' => $http,
                'curl_errno' => (int) $errno,
                'curl_error' => $this->sanitizeErrorValue((string) $err),
                'raw_response' => $this->sanitizeErrorValue(is_string($response) ? $response : '', 4000),
            ]);

            if ($err) {
                return $this->RequestError($err);
            }

            $decoded = json_decode((string) $response, true);
            $jsonError = json_last_error() === JSON_ERROR_NONE ? '' : json_last_error_msg();

            $this->debug_log($op . '.decoded_response', [
                'call_id' => $callId,
                'attempt' => $attempt,
                'json_error' => $jsonError,
                'decoded' => is_array($decoded) ? $decoded : null,
            ]);

            if (!is_array($decoded)) {
                return $this->RequestError($op . ' JSON decode failed: ' . ($jsonError !== '' ? $jsonError : 'unknown'));
            }

            if (array_key_exists('authorized', $decoded) && $decoded['authorized'] == false) {
                if ($attempt === 1) {
                    $loginAttemptResult = $this->login();
                    if ($loginAttemptResult != 1) {
                        return $this->InvalidLoginResponse($loginAttemptResult);
                    }
                    continue;
                }

                return $this->InvalidLoginResponse($response);
            }

            return $decoded;
        }

        return $this->RequestError($op . ' failed after retry.');
    }

    /**
     * @return array<string,mixed>
     */
    private function get_with_auth_retry(string $endpoint, string $op): array
    {
        $callId = substr(sha1('GET|' . $endpoint . '|' . microtime(true) . '|' . mt_rand()), 0, 10);
        $url = $this->buildUrl($endpoint);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            if (!$this->Token) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }
            }

            $this->debug_log($op . '.request', [
                'call_id' => $callId,
                'attempt' => $attempt,
                'method' => 'GET',
                'endpoint' => $url,
                'token_present' => (is_string($this->Token) && $this->Token !== '') ? 1 : 0,
            ]);

            $curl = $this->GetRequestBuilder($endpoint);
            $response = curl_exec($curl);
            $err = curl_error($curl);
            $errno = curl_errno($curl);
            $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            $this->debug_log($op . '.raw_response', [
                'call_id' => $callId,
                'attempt' => $attempt,
                'http_code' => $http,
                'curl_errno' => (int) $errno,
                'curl_error' => $this->sanitizeErrorValue((string) $err),
                'raw_response' => $this->sanitizeErrorValue(is_string($response) ? $response : '', 4000),
            ]);

            if ($err) {
                return $this->RequestError($err);
            }

            $decoded = json_decode((string) $response, true);
            $jsonError = json_last_error() === JSON_ERROR_NONE ? '' : json_last_error_msg();

            $this->debug_log($op . '.decoded_response', [
                'call_id' => $callId,
                'attempt' => $attempt,
                'json_error' => $jsonError,
                'decoded' => is_array($decoded) ? $decoded : null,
            ]);

            if (!is_array($decoded)) {
                return $this->RequestError($op . ' JSON decode failed: ' . ($jsonError !== '' ? $jsonError : 'unknown'));
            }

            if (array_key_exists('authorized', $decoded) && $decoded['authorized'] == false) {
                if ($attempt === 1) {
                    $loginAttemptResult = $this->login();
                    if ($loginAttemptResult != 1) {
                        return $this->InvalidLoginResponse($loginAttemptResult);
                    }
                    continue;
                }

                return $this->InvalidLoginResponse($response);
            }

            return $decoded;
        }

        return $this->RequestError($op . ' failed after retry.');
    }

    /**
     * @param array<int,mixed> $columns
     * @param mixed $item_to_row
     * @return array<string,mixed>|null
     */
    private function validate_tsv_stream_inputs(string $tsv_path, array $columns, $item_to_row): ?array
    {
        if (trim($tsv_path) === '') {
            return $this->validation_error('TSV path not provided');
        }

        if (empty($columns)) {
            return $this->validation_error('TSV columns not provided');
        }

        foreach ($columns as $col) {
            if (!is_string($col) || trim($col) === '') {
                return $this->validation_error('TSV columns must be non-empty strings');
            }
        }

        if (!is_callable($item_to_row)) {
            return $this->validation_error('TSV row mapper must be callable');
        }

        return null;
    }

    /**
     * Validates shared order payload shape for order endpoints that require Items.
     *
     * @param mixed $order
     * @return array<string,mixed>|null
     */
    private function validate_order_items($order): ?array
    {
        if (!is_array($order) || !array_key_exists("Items", $order) || !is_array($order["Items"]) || count($order["Items"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"Items\""
                )
            );
        }

        foreach ($order["Items"] as $value) {
            if (
                !is_array($value)
                || !array_key_exists("ItemNo", $value)
                || strlen(trim((string) $value["ItemNo"])) < 1
                || !array_key_exists("Quantity", $value)
                || (int) $value["Quantity"] < 1
            ) {
                return array(
                    "authorized" => true,
                    "success" => false,
                    "errors" => array(
                        "One or more line item was missing item number or had less than 1 quantity"
                    )
                );
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>|null
     */
    private function require_non_empty_field(array $order, string $field): ?array
    {
        if (!array_key_exists($field, $order)) {
            return $this->validation_error('Field Missing: "' . $field . '"');
        }

        $value = $order[$field];
        if (is_array($value)) {
            return count($value) < 1 ? $this->validation_error('Field Missing: "' . $field . '"') : null;
        }

        if (trim((string) $value) === '') {
            return $this->validation_error('Field Missing: "' . $field . '"');
        }

        return null;
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>|null
     */
    private function require_state_code(array $order, string $field, string $errorMessage): ?array
    {
        if (!array_key_exists($field, $order)) {
            return $this->validation_error('Field Missing: "' . $field . '"');
        }

        $state = strtoupper(trim((string) $order[$field]));
        if (strlen($state) !== 2) {
            return $this->validation_error($errorMessage);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>|null
     */
    private function normalize_and_require_zip5(array &$order, string $field, string $errorMessage): ?array
    {
        if (!array_key_exists($field, $order)) {
            return $this->validation_error('Field Missing: "' . $field . '"');
        }

        $zip = trim((string) $order[$field]);
        if (strlen($zip) > 5) {
            $zip = substr($zip, 0, 5);
        }
        $order[$field] = $zip;

        if (strlen($zip) < 5) {
            return $this->validation_error($errorMessage);
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function validation_error(string $message): array
    {
        return array(
            "authorized" => true,
            "success" => false,
            "errors" => array($message),
        );
    }

    private function stream_endpoint_to_tsv_once(
        string $endpoint,
        string $array_path,
        string $tsv_path,
        array $columns,
        callable $item_to_row
    ): array {
        $this->debug_log('stream.request', [
            'method'        => 'GET',
            'endpoint'      => $this->buildUrl($endpoint),
            'array_path'    => $array_path,
            'tsv_file'      => basename($tsv_path),
            'token_present' => (is_string($this->Token) && $this->Token !== '') ? 1 : 0,
        ]);

        $fh = @fopen($tsv_path, 'wb');
        if (!$fh) {
            return $this->RequestError('Failed to open TSV for writing: ' . $tsv_path);
        }

        $stats = array(
            'authorized'        => true,
            'success'           => false,
            'errors'            => array(),
            'tsv_path'          => $tsv_path,
            'items_seen'        => 0,
            'rows_written'      => 0,
            'items_skipped'     => 0,
            'json_decode_fails' => 0,
            'bytes_received'    => 0,

            // ✅ NEW: capture nextUpdate while streaming (best-effort)
            'next_update_raw'   => null,
            'next_update_unix'  => null,
            'chunk_count'        => 0,
            'max_chunk_bytes'    => 0,
            'first_byte_ms'      => 0.0,
            'curl_exec_ms'       => 0.0,
            'callback_total_ms'  => 0.0,
            'network_wait_ms'    => 0.0,
            'json_decode_ms'     => 0.0,
            'item_to_row_ms'     => 0.0,
            'tsv_write_ms'       => 0.0,
            'curl_total_time_ms' => 0.0,
            'curl_starttransfer_ms' => 0.0,
            'curl_namelookup_ms' => 0.0,
            'curl_connect_ms'    => 0.0,
            'curl_appconnect_ms' => 0.0,
            'curl_pretransfer_ms'=> 0.0,
            'download_speed_bytes_sec' => 0.0,
        );

        $curl = $this->GetRequestBuilder($endpoint);

        $buffer = '';
        $found_array_start = false;
        $preamble_checked = false;

        // NEW: nextUpdate scan state
        $nextupdate_found = false;
        $re_nextupdate = '/"nextUpdate"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/';

        $in_string = false;
        $escape = false;
        $depth = 0;
        $collecting_obj = false;
        $obj = '';
        $curl_start = 0.0;
        $first_byte_at = 0.0;

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);

        curl_setopt($curl, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (
            &$stats,
            &$buffer,
            &$found_array_start,
            &$preamble_checked,
            &$in_string,
            &$escape,
            &$depth,
            &$collecting_obj,
            &$obj,
            $fh,
            $columns,
            $item_to_row,
            $array_path,
            &$nextupdate_found,
            $re_nextupdate,
            &$curl_start,
            &$first_byte_at
        ) {
            $callback_start = microtime(true);

            try {
                if ($first_byte_at <= 0.0) {
                    $first_byte_at = $callback_start;
                    if ($curl_start > 0.0) {
                        $stats['first_byte_ms'] = ($first_byte_at - $curl_start) * 1000.0;
                    }
                }

            $len = strlen($chunk);
            $stats['bytes_received'] += $len;
            $stats['chunk_count']++;
            $stats['max_chunk_bytes'] = max((int) $stats['max_chunk_bytes'], $len);
            $buffer .= $chunk;

            // 1) Preamble check for authorized:false
            if (!$preamble_checked && strpos($buffer, '"authorized"') !== false) {
                if (preg_match('/"authorized"\s*:\s*false/i', $buffer)) {
                    $stats['authorized'] = false;
                    $stats['success'] = false;
                    $stats['errors'][] = 'Not authorized (token invalid/expired).';
                    return 0;
                }
                $preamble_checked = true;
            }

            // ✅ NEW: Extract nextUpdate as soon as it appears (best-effort; before/while array start)
            if (!$nextupdate_found && strpos($buffer, '"nextUpdate"') !== false) {
                if (preg_match($re_nextupdate, $buffer, $m)) {
                    $raw = stripcslashes($m[1]);
                    $stats['next_update_raw'] = $raw;

                    $ts = strtotime($raw);
                    if ($ts !== false) {
                        $stats['next_update_unix'] = (int) $ts;
                    }
                    $nextupdate_found = true;
                }
            }

            // 2) Find the target array start
            if (!$found_array_start) {
                $bracket_pos = $this->find_json_array_start_for_path($buffer, $array_path);
                if ($bracket_pos === null) {
                    if (strlen($buffer) > 1024 * 1024) {
                        $buffer = substr($buffer, -256 * 1024);
                    }
                    return $len;
                }

                $found_array_start = true;
                $buffer = substr($buffer, $bracket_pos + 1);
            }

            // 3) Extract objects inside the array
            $i = 0;
            $buf_len = strlen($buffer);

            while ($i < $buf_len) {
                $c = $buffer[$i];

                if (!$collecting_obj) {
                    if ($c === '{') {
                        $collecting_obj = true;
                        $obj = '{';
                        $depth = 1;
                        $in_string = false;
                        $escape = false;
                    } elseif ($c === ']') {
                        $buffer = '';
                        return $len;
                    }
                    $i++;
                    continue;
                }

                $obj .= $c;

                if ($escape) {
                    $escape = false;
                    $i++;
                    continue;
                }

                if ($c === '\\') {
                    $escape = true;
                    $i++;
                    continue;
                }

                if ($c === '"') {
                    $in_string = !$in_string;
                    $i++;
                    continue;
                }

                if (!$in_string) {
                    if ($c === '{') {
                        $depth++;
                    } elseif ($c === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $collecting_obj = false;

                            $t_decode = microtime(true);
                            $decoded = json_decode($obj, true);
                            $stats['json_decode_ms'] += (microtime(true) - $t_decode) * 1000.0;
                            if (is_array($decoded)) {
                                $stats['items_seen']++;

                                $row = null;
                                $t_map = microtime(true);
                                try {
                                    $row = $item_to_row($decoded);
                                } catch (\Throwable $e) {
                                    $stats['items_skipped']++;
                                    $row = null;
                                }
                                $stats['item_to_row_ms'] += (microtime(true) - $t_map) * 1000.0;

                                if (is_array($row)) {
                                    $line = array();
                                    foreach ($columns as $col) {
                                        $line[] = isset($row[$col]) ? (string)$row[$col] : '';
                                    }
                                    $t_write = microtime(true);
                                    $this->tsv_write_row($fh, $line);
                                    $stats['tsv_write_ms'] += (microtime(true) - $t_write) * 1000.0;
                                    $stats['rows_written']++;
                                } else {
                                    $stats['items_skipped']++;
                                }
                            } else {
                                $stats['json_decode_fails']++;
                            }

                            $obj = '';
                        }
                    }
                }

                $i++;
            }

            $buffer = '';
            return $len;
            } finally {
                $stats['callback_total_ms'] += (microtime(true) - $callback_start) * 1000.0;
            }
        });

        $curl_start = microtime(true);
        $ok = curl_exec($curl);
        $curl_exec_ms = (microtime(true) - $curl_start) * 1000.0;
        $err = curl_error($curl);
        $errno = curl_errno($curl);
        $http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_info = curl_getinfo($curl);
        curl_close($curl);
        fclose($fh);

        $stats['curl_exec_ms'] = $curl_exec_ms;
        $stats['network_wait_ms'] = max(0.0, $curl_exec_ms - (float) ($stats['callback_total_ms'] ?? 0.0));
        $stats['curl_total_time_ms'] = isset($curl_info['total_time']) ? ((float) $curl_info['total_time'] * 1000.0) : 0.0;
        $stats['curl_starttransfer_ms'] = isset($curl_info['starttransfer_time']) ? ((float) $curl_info['starttransfer_time'] * 1000.0) : 0.0;
        $stats['curl_namelookup_ms'] = isset($curl_info['namelookup_time']) ? ((float) $curl_info['namelookup_time'] * 1000.0) : 0.0;
        $stats['curl_connect_ms'] = isset($curl_info['connect_time']) ? ((float) $curl_info['connect_time'] * 1000.0) : 0.0;
        $stats['curl_appconnect_ms'] = isset($curl_info['appconnect_time']) ? ((float) $curl_info['appconnect_time'] * 1000.0) : 0.0;
        $stats['curl_pretransfer_ms'] = isset($curl_info['pretransfer_time']) ? ((float) $curl_info['pretransfer_time'] * 1000.0) : 0.0;
        $stats['download_speed_bytes_sec'] = isset($curl_info['speed_download']) ? (float) $curl_info['speed_download'] : 0.0;

        $this->debug_log('stream.response', [
            'method'           => 'GET',
            'endpoint'         => $this->buildUrl($endpoint),
            'http_code'        => (int) $http,
            'curl_errno'       => (int) $errno,
            'curl_error'       => $this->sanitizeErrorValue((string) $err),
            'curl_exec_ok'     => $ok === false ? 0 : 1,
            'authorized'       => !empty($stats['authorized']) ? 1 : 0,
            'bytes_received'   => (int) ($stats['bytes_received'] ?? 0),
            'items_seen'       => (int) ($stats['items_seen'] ?? 0),
            'rows_written'     => (int) ($stats['rows_written'] ?? 0),
            'items_skipped'    => (int) ($stats['items_skipped'] ?? 0),
            'json_decode_fails'=> (int) ($stats['json_decode_fails'] ?? 0),
            'curl_exec_ms'     => number_format((float) ($stats['curl_exec_ms'] ?? 0), 2, '.', ''),
            'network_wait_ms'  => number_format((float) ($stats['network_wait_ms'] ?? 0), 2, '.', ''),
            'callback_total_ms'=> number_format((float) ($stats['callback_total_ms'] ?? 0), 2, '.', ''),
            'json_decode_ms'   => number_format((float) ($stats['json_decode_ms'] ?? 0), 2, '.', ''),
            'item_to_row_ms'   => number_format((float) ($stats['item_to_row_ms'] ?? 0), 2, '.', ''),
            'tsv_write_ms'     => number_format((float) ($stats['tsv_write_ms'] ?? 0), 2, '.', ''),
            'next_update_raw'  => isset($stats['next_update_raw']) ? $this->sanitizeErrorValue((string) $stats['next_update_raw']) : null,
        ]);

        if ($err) {
            return $this->RequestError($err);
        }

        if ($stats['authorized'] === false) {
            return array(
                'authorized' => false,
                'success'    => false,
                'errors'     => $stats['errors'],
            );
        }

        if ((int)$http !== 200) {
            return $this->RequestError('Unexpected HTTP status: ' . (string)$http);
        }

        $stats['success'] = true;
        return $stats;
    }

    private function find_json_array_start_for_path(string $buf, string $path): ?int
    {
        if ($path === 'data') {
            $data_pos = strpos($buf, '"data"');
            if ($data_pos === false) return null;
            $colon = strpos($buf, ':', $data_pos);
            if ($colon === false) return null;
            $bracket = strpos($buf, '[', $colon);
            if ($bracket === false) return null;
            return $bracket;
        }

        if ($path === 'data.items') {
            $data_pos = strpos($buf, '"data"');
            if ($data_pos === false) return null;
            $items_pos = strpos($buf, '"items"', $data_pos);
            if ($items_pos === false) return null;
            $bracket = strpos($buf, '[', $items_pos);
            if ($bracket === false) return null;
            return $bracket;
        }

        if ($path === 'items') {
            $items_pos = strpos($buf, '"items"');
            if ($items_pos === false) return null;
            $bracket = strpos($buf, '[', $items_pos);
            if ($bracket === false) return null;
            return $bracket;
        }

        return null;
    }

    private function tsv_write_row($fh, array $fields): void
    {
        foreach ($fields as &$v) {
            $v = (string)$v;
            $v = str_replace(array("\t", "\r", "\n"), array(' ', ' ', ' '), $v);
        }
        unset($v);

        fwrite($fh, implode("\t", $fields) . "\n");
    }

    private function login()
    {
        $model = array(
            "Email" => $this->Email,
            "Password" => $this->Password
        );

        $this->debug_log('login.request', [
            'method'    => 'POST',
            'endpoint'  => $this->buildUrl('integration/authentication/login'),
            'account'   => $this->maskEmail((string) $this->Email),
            'has_email' => trim((string) $this->Email) !== '' ? 1 : 0,
            'has_pass'  => trim((string) $this->Password) !== '' ? 1 : 0,
        ]);

        // Always do auth login without an existing token header.
        // Some API gateways reject login attempts with stale bearer/token headers.
        $this->Token = null;
        if (session_status() == PHP_SESSION_ACTIVE) {
            unset($_SESSION[$this->sessionTokenKey()]);
        }

        $curl = $this->PostRequestBuilder("integration/authentication/login", $model, false);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $errno = curl_errno($curl);
        $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $this->LastLoginHttpCode = $http;

        $responseExcerpt = $this->sanitizeErrorValue(is_string($response) ? $response : '');

        if ($err) {
            $this->debug_log('login.response.error', [
                'method'      => 'POST',
                'endpoint'    => $this->buildUrl('integration/authentication/login'),
                'http_code'   => $http,
                'curl_errno'  => (int) $errno,
                'curl_error'  => $this->sanitizeErrorValue((string) $err),
                'resp_excerpt'=> $responseExcerpt,
            ]);

            return array(
                'stage'            => 'login_request',
                'http_code'        => $http,
                'curl_errno'       => (int) $errno,
                'curl_error'       => $this->sanitizeErrorValue((string) $err),
                'response_excerpt' => $responseExcerpt,
            );
        } else {
            $decode = json_decode($response, true);
            $jsonError = json_last_error() === JSON_ERROR_NONE ? '' : json_last_error_msg();
            if (
                is_array($decode)
                && array_key_exists("token", $decode)
                && array_key_exists("econtact", $decode)
                && is_array($decode["econtact"])
                && array_key_exists("success", $decode["econtact"])
                && (int) $decode["econtact"]["success"] === 1
            ) {
                $this->Account = $decode;
                $this->Token = $decode["token"];
                if (session_status() == PHP_SESSION_ACTIVE) {
                    $_SESSION[$this->sessionTokenKey()] = $decode["token"];
                }

                $this->debug_log('login.response.ok', [
                    'method'           => 'POST',
                    'endpoint'         => $this->buildUrl('integration/authentication/login'),
                    'http_code'        => $http,
                    'has_token'        => 1,
                    'econtact_success' => 1,
                ]);

                return 1;
            }

            $diag = array(
                'stage'            => 'login_response_invalid',
                'http_code'        => $http,
                'curl_errno'       => (int) $errno,
                'curl_error'       => $this->sanitizeErrorValue((string) $err),
                'response_excerpt' => $responseExcerpt,
                'json_error'       => $jsonError !== '' ? $jsonError : null,
                'has_token_key'    => is_array($decode) && array_key_exists("token", $decode) ? 1 : 0,
                'has_econtact_key' => is_array($decode) && array_key_exists("econtact", $decode) ? 1 : 0,
                'econtact_success' => (is_array($decode) && isset($decode['econtact']) && is_array($decode['econtact']) && isset($decode['econtact']['success']))
                    ? (int) $decode['econtact']['success']
                    : null,
            );

            // If we can identify a likely network policy / allowlist denial, surface it explicitly.
            $responseLower = strtolower($responseExcerpt);
            if (
                $http === 401
                || $http === 403
                || strpos($responseLower, 'not authorized') !== false
                || strpos($responseLower, 'forbidden') !== false
                || strpos($responseLower, 'ip') !== false
            ) {
                $diag['likely_cause'] = 'auth_or_ip_allowlist';
            }

            $this->debug_log('login.response.invalid', [
                'method'      => 'POST',
                'endpoint'    => $this->buildUrl('integration/authentication/login'),
                'http_code'   => $http,
                'curl_errno'  => (int) $errno,
                'curl_error'  => $this->sanitizeErrorValue((string) $err),
                'diag'        => $diag,
            ]);

            return $diag;
        }
    }

    // Your existing NextUpdateFast stays as-is (cron bootstrap uses it rarely).
    public function PricingAndQuantityNextUpdateFast(): array
    {
        $attempts = 0;
        $last_err = null;

        while ($attempts < 2) {
            $attempts++;

            if (!$this->Token) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }
            }

            $result = $this->pricing_quantity_next_update_fast_once();

            if (is_array($result) && isset($result['authorized']) && $result['authorized'] === false) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }
                $last_err = $result;
                continue;
            }

            return $result;
        }

        return is_array($last_err) ? $last_err : $this->RequestError('PricingAndQuantityNextUpdateFast failed after retry.');
    }

    private function pricing_quantity_next_update_fast_once(): array
    {
        $stats = [
            'authorized'       => true,
            'success'          => false,
            'errors'           => [],
            'next_update_raw'  => null,
            'next_update_unix' => null,
            'bytes_received'   => 0,
            'http_code'        => 0,
        ];

        $curl = $this->GetRequestBuilder("integration/items/PricingQuantityFeed");

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);

        $rolling = '';
        $preamble_checked = false;

        $re_nextupdate = '/"nextUpdate"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/';

        curl_setopt($curl, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (
            &$stats,
            &$rolling,
            &$preamble_checked,
            $re_nextupdate
        ) {
            $len = strlen($chunk);
            $stats['bytes_received'] += $len;

            $rolling .= $chunk;
            if (strlen($rolling) > 64 * 1024) {
                $rolling = substr($rolling, -16 * 1024);
            }

            if (!$preamble_checked && strpos($rolling, '"authorized"') !== false) {
                if (preg_match('/"authorized"\s*:\s*false/i', $rolling)) {
                    $stats['authorized'] = false;
                    $stats['success'] = false;
                    $stats['errors'][] = 'Not authorized (token invalid/expired).';
                    return 0;
                }
                $preamble_checked = true;
            }

            if (strpos($rolling, '"nextUpdate"') !== false) {
                if (preg_match($re_nextupdate, $rolling, $m)) {
                    $raw = stripcslashes($m[1]);
                    $stats['next_update_raw'] = $raw;

                    $ts = strtotime($raw);
                    if ($ts !== false) {
                        $stats['next_update_unix'] = (int) $ts;
                    }

                    $stats['success'] = true;
                    return 0;
                }
            }

            return $len;
        });

        curl_exec($curl);
        $err   = curl_error($curl);
        $errno = curl_errno($curl);
        $http  = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $stats['http_code'] = $http;

        if ($stats['authorized'] === false) {
            return [
                'authorized'     => false,
                'success'        => false,
                'errors'         => $stats['errors'],
                'bytes_received' => (int) $stats['bytes_received'],
                'http_code'      => (int) $http,
            ];
        }

        if ($stats['success'] === true) {
            $stats['curl_errno'] = (int) $errno;
            $stats['curl_error'] = (string) $err;
            return $stats;
        }

        if ($err) {
            return [
                'authorized'     => true,
                'success'        => false,
                'errors'         => ["Error making http request", $err],
                'bytes_received' => (int) $stats['bytes_received'],
                'http_code'      => (int) $http,
                'curl_errno'     => (int) $errno,
            ];
        }

        if ($http !== 200) {
            return $this->RequestError('Unexpected HTTP status: ' . (string) $http);
        }

        return [
            'authorized'     => true,
            'success'        => false,
            'errors'         => ['nextUpdate not found in response (within rolling window).'],
            'bytes_received' => (int) $stats['bytes_received'],
            'http_code'      => (int) $http,
            'curl_errno'     => (int) $errno,
        ];
    }

    private function buildUrl(string $path): string
    {
        return rtrim((string) $this->BaseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function debug_log(string $message, array $context = []): void
    {
        if (!empty($context)) {
            DebugLogUtil::log_ctx('FFLHUB_LIPSEYS_DEBUG', '[FFLHub][LipseysRawAPI]', $message, $context);
            return;
        }

        DebugLogUtil::log('FFLHUB_LIPSEYS_DEBUG', '[FFLHub][LipseysRawAPI]', $message);
    }
}
