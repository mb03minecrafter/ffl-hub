<?php

namespace FFLHub\Distributor\Services\Lipseys\LipseysRawAPI;

use Exception;

class LipseysClient
{
    private $BaseUrl = "https://api.lipseys.com/api/";

    private $Email = "";
    private $Password = "";

    private $Account;
    private $Token;

    public function __construct($email, $password)
    {
        if (!extension_loaded('curl')) {
            throw new Exception("This method requires the php curl extension.");
        }
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        if (session_status() == PHP_SESSION_ACTIVE) {
            if (array_key_exists("LipseysSessionToken{$email}{$password}", $_SESSION)) {
                $this->Token = $_SESSION["LipseysSessionToken{$email}{$password}"];
            }
        }

        $this->Email = $email;
        $this->Password = $password;
    }

    private function RequestBuilder($options)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt_array($curl, $options);
        return $curl;
    }

    private function PostRequestBuilder($url, $model)
    {
        $curl = $this->RequestBuilder(array(
            CURLOPT_URL => "{$this->BaseUrl}{$url}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($model),
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json",
                "Accept: application/json",
                "Accept-Encoding: gzip",
                "Token: {$this->Token}",
            ),
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
        $errorsArray = array(
            "Not Authorized Response",
            "Credentials Provided: {$this->Email}, {$this->Password}",
            date("Y-m-d h:i:s A T"),
            $loginResponse
        );
        if ($this->Token) {
            array_push($errorsArray, "Token: {$this->Token}");
        }
        return array(
            "authorized" => false,
            "success" => false,
            "errors" => $errorsArray
        );
    }

    private function RequestError($error)
    {
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

    private function stream_endpoint_to_tsv_once(
        string $endpoint,
        string $array_path,
        string $tsv_path,
        array $columns,
        callable $item_to_row
    ): array {
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
            $re_nextupdate
        ) {
            $len = strlen($chunk);
            $stats['bytes_received'] += $len;
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

                            $decoded = json_decode($obj, true);
                            if (is_array($decoded)) {
                                $stats['items_seen']++;

                                $row = null;
                                try {
                                    $row = $item_to_row($decoded);
                                } catch (\Throwable $e) {
                                    $stats['items_skipped']++;
                                    $row = null;
                                }

                                if (is_array($row)) {
                                    $line = array();
                                    foreach ($columns as $col) {
                                        $line[] = isset($row[$col]) ? (string)$row[$col] : '';
                                    }
                                    $this->tsv_write_row($fh, $line);
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
        });

        $ok = curl_exec($curl);
        $err = curl_error($curl);
        $http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        fclose($fh);

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
        $curl = $this->PostRequestBuilder("integration/authentication/login", $model);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return $err;
        } else {
            $decode = json_decode($response, true);
            if (array_key_exists("token", $decode) && array_key_exists("econtact", $decode) && $decode["econtact"]["success"] == 1) {
                $this->Account = $decode;
                $this->Token = $decode["token"];
                if (session_status() == PHP_SESSION_ACTIVE) {
                    $_SESSION["LipseysSessionToken{$this->Email}{$this->Password}"] = $decode["token"];
                }
                return 1;
            }
        }
        return $response;
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
}
