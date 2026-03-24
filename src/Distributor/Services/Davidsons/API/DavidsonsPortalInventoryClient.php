<?php

namespace FFLHub\Distributor\Services\Davidsons\API;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Util\DebugLogUtil;

/**
 * Handles authenticated Davidson's portal CSV downloads.
 *
 * Flow:
 * - GET login page (extract form_key + optional referer)
 * - POST login
 * - GET account page (confirm logged-in state)
 * - GET inventory download page (extract form/action/hidden fields)
 * - POST download form for a given feed name (e.g. davidsons_inventory, davidsons_quantity)
 */
final class DavidsonsPortalInventoryClient
{
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHUB][DavidsonsCurl]';
    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36';

    private string $base_url;
    private int $timeout_sec;

    public function __construct(string $base_url = 'https://www.davidsonsinc.com', int $timeout_sec = 45)
    {
        $normalized_base = rtrim(trim($base_url), '/');
        $this->base_url  = $normalized_base !== '' ? $normalized_base : 'https://www.davidsonsinc.com';
        $this->timeout_sec = max(10, $timeout_sec);
    }

    /**
     * @return array<string,mixed>
     */
    public function download_csv_to(
        string $username,
        string $password,
        string $request_name,
        string $output_path,
        string $request_type = 'csv'
    ): array {
        $username = trim($username);
        $password = trim($password);
        $request_name = trim($request_name);
        $request_type = trim($request_type);
        $output_path = trim($output_path);

        if ($username === '' || $password === '') {
            return $this->fail('Missing Davidson portal credentials.');
        }
        if ($request_name === '') {
            return $this->fail('Missing Davidson request name.');
        }
        if ($request_type === '') {
            $request_type = 'csv';
        }
        if ($output_path === '') {
            return $this->fail('Missing output path for Davidson CSV download.');
        }
        if (!function_exists('curl_init')) {
            return $this->fail('cURL extension is not available in this PHP runtime.');
        }

        $output_dir = dirname($output_path);
        if (!is_dir($output_dir)) {
            $made = function_exists('wp_mkdir_p')
                ? (bool) wp_mkdir_p($output_dir)
                : @mkdir($output_dir, 0775, true);
            if (!$made) {
                return $this->fail('Unable to create Davidson output directory.', [
                    'output_dir' => $output_dir,
                ]);
            }
        }

        $cookie_jar = tempnam(sys_get_temp_dir(), 'fflhub_davidsons_cookie_');
        if (!is_string($cookie_jar) || $cookie_jar === '') {
            return $this->fail('Unable to create temporary cookie jar.');
        }

        $this->log('Starting Davidson CSV download flow', [
            'request_name' => $request_name,
            'request_type' => $request_type,
            'output_path'  => $output_path,
        ]);

        try {
            // 1) Login page
            $login_url = $this->to_absolute_url('/customer/account/login/');
            $login_page = $this->request('GET', $login_url, [
                'cookie_jar' => $cookie_jar,
                'headers' => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
            ]);
            if (!(bool) ($login_page['ok'] ?? false)) {
                return $this->fail('Login page request failed.', $this->error_context_from_response($login_page));
            }

            $login_html   = (string) ($login_page['body'] ?? '');
            $login_form   = $this->extract_form_block($login_html, 'loginPost');
            $login_action = $this->extract_form_action($login_form, 'loginPost');
            if ($login_action === '') {
                $login_action = $this->to_absolute_url('/customer/account/loginPost/');
            }

            $login_form_key = $this->extract_form_key($login_html);
            $referer        = $this->extract_hidden_input($login_form, 'referer');

            if ($login_form_key === '') {
                return $this->fail('Could not extract login form_key from Davidson login page.');
            }

            // 2) Login POST
            $login_post_fields = [
                'form_key'             => $login_form_key,
                'login[username]'      => $username,
                'login[password]'      => $password,
                'persistent_remember_me' => 'on',
            ];
            if ($referer !== '') {
                $login_post_fields['referer'] = $referer;
            }

            $login_post = $this->request('POST', $login_action, [
                'cookie_jar'  => $cookie_jar,
                'referer'     => $login_url,
                'post_fields' => $login_post_fields,
                'headers' => [
                    'Origin: ' . $this->base_url,
                    'Content-Type: application/x-www-form-urlencoded',
                ],
            ]);
            if (!(bool) ($login_post['ok'] ?? false)) {
                return $this->fail('Login POST failed.', $this->error_context_from_response($login_post));
            }

            // 3) Account check
            $account_url = $this->to_absolute_url('/customer/account/');
            $account_page = $this->request('GET', $account_url, [
                'cookie_jar' => $cookie_jar,
            ]);
            if (!(bool) ($account_page['ok'] ?? false)) {
                return $this->fail('Account page request failed after login.', $this->error_context_from_response($account_page));
            }

            $account_html = (string) ($account_page['body'] ?? '');
            if (!$this->is_logged_in_html($account_html)) {
                return $this->fail('Davidsons login verification failed (session not authenticated).');
            }
            $account_form_key = $this->extract_form_key($account_html);

            // 4) Inventory page (contains download form)
            $inventory_url = $this->to_absolute_url('/inventory-download/');
            $inventory_page = $this->request('GET', $inventory_url, [
                'cookie_jar' => $cookie_jar,
            ]);
            if (!(bool) ($inventory_page['ok'] ?? false)) {
                return $this->fail('Inventory page request failed.', $this->error_context_from_response($inventory_page));
            }

            $inventory_html = (string) ($inventory_page['body'] ?? '');
            if ($this->looks_like_login_page($inventory_html)) {
                return $this->fail('Inventory page returned login content; session appears unauthenticated.');
            }

            $download_form = $this->extract_form_block($inventory_html, 'downloadinventory');
            if ($download_form === '') {
                return $this->fail('Could not find Davidson inventory download form.');
            }

            $download_action = $this->extract_form_action($download_form, 'downloadinventory');
            if ($download_action === '') {
                $download_action = $this->to_absolute_url('/inventory-download/request/downloadinventory/');
            }

            $hidden_fields = $this->extract_hidden_inputs($download_form);

            $download_form_key = $account_form_key;
            if ($download_form_key === '') {
                $download_form_key = trim((string) ($hidden_fields['form_key'] ?? ''));
            }
            if ($download_form_key === '') {
                $download_form_key = $this->extract_form_key($inventory_html);
            }
            if ($download_form_key === '') {
                return $this->fail('Could not resolve download form_key from authenticated session.');
            }

            $post_fields = $hidden_fields;
            $post_fields['name'] = $request_name;
            $post_fields['type'] = $request_type;
            $post_fields['form_key'] = $download_form_key;

            // 5) Download POST
            $download_response = $this->request('POST', $download_action, [
                'cookie_jar'    => $cookie_jar,
                'cookie_inline' => 'form_key=' . $download_form_key,
                'referer'       => $inventory_url,
                'post_fields'   => $post_fields,
                'headers' => [
                    'Origin: ' . $this->base_url,
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
            ]);
            if (!(bool) ($download_response['ok'] ?? false)) {
                return $this->fail('CSV download request failed.', $this->error_context_from_response($download_response));
            }

            $body         = (string) ($download_response['body'] ?? '');
            $content_type = strtolower((string) ($download_response['content_type'] ?? ''));

            if ($body === '') {
                return $this->fail('CSV download response body was empty.', [
                    'request_name' => $request_name,
                    'content_type' => $content_type,
                ]);
            }

            if ($this->looks_like_html_response($body)) {
                return $this->fail('CSV download returned HTML content (likely session or form_key issue).', [
                    'request_name' => $request_name,
                    'content_type' => $content_type,
                    'body_head'    => $this->truncate(ltrim($body), 400),
                ]);
            }

            $written = @file_put_contents($output_path, $body);
            if ($written === false) {
                return $this->fail('Failed to write Davidson CSV output file.', [
                    'output_path' => $output_path,
                ]);
            }

            $first_line = strtok($body, "\r\n");
            $is_expected_csv = is_string($first_line) && stripos($first_line, 'Item #') !== false;
            if (!$is_expected_csv) {
                $this->log('Downloaded Davidson CSV but header did not match expected pattern.', [
                    'request_name' => $request_name,
                    'first_line'   => $this->truncate((string) $first_line, 300),
                ]);
            }

            $success = [
                'ok'           => true,
                'message'      => 'OK',
                'output_path'  => $output_path,
                'bytes'        => (int) $written,
                'request_name' => $request_name,
                'request_type' => $request_type,
                'http_status'  => (int) ($download_response['http_status'] ?? 0),
                'content_type' => (string) ($download_response['content_type'] ?? ''),
                'effective_url' => (string) ($download_response['effective_url'] ?? ''),
            ];

            $this->log('Davidsons CSV download complete', [
                'request_name'  => $request_name,
                'output_path'   => $output_path,
                'bytes'         => (int) $written,
                'content_type'  => (string) ($download_response['content_type'] ?? ''),
                'http_status'   => (int) ($download_response['http_status'] ?? 0),
            ]);

            return $success;
        } finally {
            @unlink($cookie_jar);
        }
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function request(string $method, string $url, array $options = []): array
    {
        $method      = strtoupper(trim($method));
        $url         = trim($url);
        $cookie_jar  = trim((string) ($options['cookie_jar'] ?? ''));
        $cookie_inline = trim((string) ($options['cookie_inline'] ?? ''));
        $referer     = trim((string) ($options['referer'] ?? ''));
        $headers     = isset($options['headers']) && is_array($options['headers']) ? $options['headers'] : [];
        $post_fields = isset($options['post_fields']) && is_array($options['post_fields']) ? $options['post_fields'] : [];

        $this->log('HTTP request', [
            'method'            => $method,
            'url'               => $url,
            'has_cookie_jar'    => $cookie_jar !== '' ? 1 : 0,
            'cookie_inline_set' => $cookie_inline !== '' ? 1 : 0,
            'referer'           => $referer,
            'post_keys'         => array_values(array_map('strval', array_keys($post_fields))),
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok'      => false,
                'message' => 'curl_init failed.',
            ];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout_sec);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout_sec);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_USERAGENT, self::DEFAULT_USER_AGENT);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if ($cookie_jar !== '') {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_jar);
        }
        if ($cookie_inline !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie_inline);
        }
        if ($referer !== '') {
            curl_setopt($ch, CURLOPT_REFERER, $referer);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($post_fields)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields, '', '&'));
            }
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $raw = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effective_url = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $total_ms = ((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000.0;
        curl_close($ch);

        if ($raw === false || $raw === null) {
            $out = [
                'ok'           => false,
                'message'      => 'cURL transport error: ' . (string) $curl_error,
                'http_status'  => $http_status,
                'content_type' => $content_type,
                'effective_url' => $effective_url,
            ];

            $this->log('HTTP response (transport error)', [
                'method'       => $method,
                'url'          => $url,
                'http_status'  => $http_status,
                'content_type' => $content_type,
                'effective_url' => $effective_url,
                'curl_error'   => (string) $curl_error,
                'elapsed_ms'   => number_format($total_ms, 2, '.', ''),
            ]);

            return $out;
        }

        $body = (string) $raw;
        $ok = ($http_status >= 200 && $http_status < 400);

        $out = [
            'ok'           => $ok,
            'message'      => $ok ? 'OK' : ('HTTP error: ' . $http_status),
            'http_status'  => $http_status,
            'content_type' => $content_type,
            'effective_url' => $effective_url,
            'body'         => $body,
        ];

        $this->log('HTTP response', [
            'method'       => $method,
            'url'          => $url,
            'http_status'  => $http_status,
            'content_type' => $content_type,
            'effective_url' => $effective_url,
            'body_bytes'   => strlen($body),
            'body_head'    => $this->truncate(ltrim($body), 350),
            'elapsed_ms'   => number_format($total_ms, 2, '.', ''),
        ]);

        return $out;
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function error_context_from_response(array $response): array
    {
        $body = isset($response['body']) ? (string) $response['body'] : '';
        return [
            'message'       => (string) ($response['message'] ?? ''),
            'http_status'   => (int) ($response['http_status'] ?? 0),
            'content_type'  => (string) ($response['content_type'] ?? ''),
            'effective_url' => (string) ($response['effective_url'] ?? ''),
            'body_head'     => $this->truncate(ltrim($body), 350),
        ];
    }

    private function to_absolute_url(string $path_or_url): string
    {
        $v = trim($path_or_url);
        if ($v === '') {
            return $this->base_url . '/';
        }
        if (preg_match('#^https?://#i', $v)) {
            return $v;
        }
        return $this->base_url . '/' . ltrim($v, '/');
    }

    private function extract_form_block(string $html, string $action_needle): string
    {
        if ($html === '') {
            return '';
        }
        $needle = preg_quote($action_needle, '/');
        if (preg_match('/<form\b[^>]*action=(["\'])([^"\']*' . $needle . '[^"\']*)\1[^>]*>.*?<\/form>/is', $html, $m)) {
            return (string) $m[0];
        }
        return '';
    }

    private function extract_form_action(string $form_html, string $action_needle): string
    {
        if ($form_html === '') {
            return '';
        }
        $needle = preg_quote($action_needle, '/');
        if (preg_match('/<form\b[^>]*action=(["\'])([^"\']*' . $needle . '[^"\']*)\1[^>]*>/is', $form_html, $m)) {
            return $this->to_absolute_url((string) $m[2]);
        }
        return '';
    }

    private function extract_hidden_input(string $form_html, string $name): string
    {
        if ($form_html === '' || $name === '') {
            return '';
        }
        $name_rx = preg_quote($name, '/');
        if (preg_match('/<input\b[^>]*name=(["\'])' . $name_rx . '\1[^>]*>/is', $form_html, $m)) {
            return $this->extract_attribute((string) $m[0], 'value');
        }
        return '';
    }

    /**
     * @return array<string,string>
     */
    private function extract_hidden_inputs(string $form_html): array
    {
        $out = [];
        if ($form_html === '') {
            return $out;
        }

        if (!preg_match_all('/<input\b[^>]*>/is', $form_html, $matches)) {
            return $out;
        }

        foreach ((array) ($matches[0] ?? []) as $tag) {
            $type = strtolower($this->extract_attribute((string) $tag, 'type'));
            if ($type !== 'hidden') {
                continue;
            }

            $name = trim($this->extract_attribute((string) $tag, 'name'));
            if ($name === '') {
                continue;
            }

            $out[$name] = $this->extract_attribute((string) $tag, 'value');
        }

        return $out;
    }

    private function extract_attribute(string $tag_html, string $attr): string
    {
        if ($tag_html === '' || $attr === '') {
            return '';
        }
        $attr_rx = preg_quote($attr, '/');

        if (preg_match('/\b' . $attr_rx . '\s*=\s*"([^"]*)"/i', $tag_html, $m)) {
            return html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match("/\b{$attr_rx}\s*=\s*'([^']*)'/i", $tag_html, $m)) {
            return html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    private function extract_form_key(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $patterns = [
            '/name=["\']form_key["\'][^>]*value=["\']([^"\']+)["\']/i',
            '/"form_key"\s*:\s*"([^"]+)"/i',
            '/FORM_KEY\s*=\s*["\']([^"\']+)["\']/i',
            '/"formKey"\s*:\s*"([^"]+)"/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $value = trim(html_entity_decode((string) ($m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function is_logged_in_html(string $html): bool
    {
        $lc = strtolower($html);
        return strpos($lc, 'customer/account/logout') !== false;
    }

    private function looks_like_login_page(string $html): bool
    {
        $lc = strtolower($html);
        if (strpos($lc, 'customer-account-login') !== false) {
            return true;
        }
        if (strpos($lc, 'name="login[username]"') !== false) {
            return true;
        }
        return false;
    }

    private function looks_like_html_response(string $body): bool
    {
        $head = strtolower(ltrim(substr($body, 0, 120)));
        return strpos($head, '<!doctype html') === 0 || strpos($head, '<html') === 0;
    }

    private function truncate(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '' || $max <= 0) {
            return '';
        }
        if (strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max) . '...';
    }

    /**
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private function fail(string $message, array $ctx = []): array
    {
        $this->log('ERROR: ' . $message, $ctx);
        return [
            'ok'      => false,
            'message' => $message,
            'context' => $ctx,
        ];
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $message);
            return;
        }
        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $message, $ctx);
    }
}

