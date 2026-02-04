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
            CURLOPT_ENCODING => "", // allow gzip/deflate
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
            CURLOPT_ENCODING => "", // allow gzip/deflate
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

    public function Catalog()
    {
        if (!$this->Token) {
            $loginAttemptResult = $this->login();
            if ($loginAttemptResult != 1) {
                return $this->InvalidLoginResponse($loginAttemptResult);
            }
        }

        $curl = $this->GetRequestBuilder("integration/items/CatalogFeed");
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return $this->RequestError($err);
        } else {
            $decode = json_decode($response, true);
            if ($decode["authorized"] == false) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }

                $curl = $this->GetRequestBuilder("integration/items/CatalogFeed");
                $response = curl_exec($curl);
                $err = curl_error($curl);

                curl_close($curl);

                if ($err) {
                    return $this->RequestError($err);
                } else {
                    $decode2 = json_decode($response, true);
                    if ($decode2["authorized"] == false) {
                        return $this->InvalidLoginResponse($response);
                    }
                    return $decode2;
                }
            }
            return $decode;
        }
    }

    /**
     * CatalogFeed response format is typically:
     *   { success, authorized, errors, data: [ {item...}, ... ] }
     * So we stream the ARRAY at "data".
     */
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
                'data', // <-- KEY FIX (Catalog uses data: [ ... ])
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
     * PricingQuantityFeed response format is:
     *   { success, authorized, errors, data: { nextUpdate, items: [ ... ] } }
     * So we stream the ARRAY at "data.items".
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
                'data.items', // <-- PricingQuantity uses data.items: [ ... ]
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
     * Generic streaming JSON->TSV.
     *
     * @param string $endpoint e.g. "integration/items/CatalogFeed"
     * @param string $array_path "data" OR "data.items" OR "items" (fallback)
     */
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
        );

        $curl = $this->GetRequestBuilder($endpoint);

        // Streaming state
        $buffer = '';
        $found_array_start = false;
        $preamble_checked = false;

        // Object extraction state
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
            $array_path
        ) {
            $len = strlen($chunk);
            $stats['bytes_received'] += $len;
            $buffer .= $chunk;

            // 1) Preamble check for authorized:false (stop early)
            if (!$preamble_checked && strpos($buffer, '"authorized"') !== false) {
                if (preg_match('/"authorized"\s*:\s*false/i', $buffer)) {
                    $stats['authorized'] = false;
                    $stats['success'] = false;
                    $stats['errors'][] = 'Not authorized (token invalid/expired).';
                    return 0; // abort transfer
                }
                $preamble_checked = true;
            }

            // 2) Find the target array start
            if (!$found_array_start) {
                $bracket_pos = $this->find_json_array_start_for_path($buffer, $array_path);
                if ($bracket_pos === null) {
                    // Not enough buffer yet
                    // Keep buffer bounded a bit to avoid unbounded growth before match
                    if (strlen($buffer) > 1024 * 1024) {
                        $buffer = substr($buffer, -256 * 1024);
                    }
                    return $len;
                }

                $found_array_start = true;
                // Discard up to and including '['
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

    /**
     * Returns position of '[' that begins the target array for a given path.
     * Supported:
     *  - "data"       => ..."data": [ ... ]
     *  - "data.items" => ..."data": { ... "items": [ ... ] }
     *  - "items"      => ..."items": [ ... ] (fallback)
     */
    private function find_json_array_start_for_path(string $buf, string $path): ?int
    {
        if ($path === 'data') {
            $data_pos = strpos($buf, '"data"');
            if ($data_pos === false) {
                return null;
            }
            $colon = strpos($buf, ':', $data_pos);
            if ($colon === false) {
                return null;
            }
            $bracket = strpos($buf, '[', $colon);
            if ($bracket === false) {
                return null;
            }
            return $bracket;
        }

        if ($path === 'data.items') {
            $data_pos = strpos($buf, '"data"');
            if ($data_pos === false) {
                return null;
            }
            $items_pos = strpos($buf, '"items"', $data_pos);
            if ($items_pos === false) {
                return null;
            }
            $bracket = strpos($buf, '[', $items_pos);
            if ($bracket === false) {
                return null;
            }
            return $bracket;
        }

        // fallback: top-level "items"
        if ($path === 'items') {
            $items_pos = strpos($buf, '"items"');
            if ($items_pos === false) {
                return null;
            }
            $bracket = strpos($buf, '[', $items_pos);
            if ($bracket === false) {
                return null;
            }
            return $bracket;
        }

        // Unknown path
        return null;
    }

    /**
     * Write one TSV row, escaping tabs/newlines safely.
     *
     * @param resource $fh
     * @param string[] $fields
     */
    private function tsv_write_row($fh, array $fields): void
    {
        foreach ($fields as &$v) {
            $v = (string)$v;
            $v = str_replace(array("\t", "\r", "\n"), array(' ', ' ', ' '), $v);
        }
        unset($v);

        fwrite($fh, implode("\t", $fields) . "\n");
    }

    public function CatalogItem($itemNumber)
    {
        if (!$this->Token) {
            $loginAttemptResult = $this->login();
            if ($loginAttemptResult != 1) {
                return $this->InvalidLoginResponse($loginAttemptResult);
            }
        }

        if (!$itemNumber || strlen($itemNumber) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Item number not provided"
                )
            );
        }

        $curl = $this->PostRequestBuilder("integration/items/CatalogFeed/Item", $itemNumber);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return $this->RequestError($err);
        } else {
            $decode = json_decode($response, true);
            if ($decode["authorized"] == false) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }

                $curl = $this->PostRequestBuilder("integration/items/CatalogFeed/Item", $itemNumber);
                $response = curl_exec($curl);
                $err = curl_error($curl);
                curl_close($curl);

                if ($err) {
                    return $this->RequestError($err);
                } else {
                    $decode2 = json_decode($response, true);
                    if ($decode2["authorized"] == false) {
                        return $this->InvalidLoginResponse($response);
                    }
                    return $decode2;
                }
            }
            return $decode;
        }
    }

    public function PricingAndQuantity()
    {
        if (!$this->Token) {
            $loginAttemptResult = $this->login();
            if ($loginAttemptResult != 1) {
                return $this->InvalidLoginResponse($loginAttemptResult);
            }
        }

        $curl = $this->GetRequestBuilder("integration/items/PricingQuantityFeed");
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return $this->RequestError($err);
        } else {
            $decode = json_decode($response, true);
            if ($decode["authorized"] == false) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }
                $curl = $this->GetRequestBuilder("integration/items/PricingQuantityFeed");
                $response = curl_exec($curl);
                $err = curl_error($curl);
                curl_close($curl);

                if ($err) {
                    return $this->RequestError($err);
                } else {
                    $decode2 = json_decode($response, true);
                    if ($decode2["authorized"] == false) {
                        return $this->InvalidLoginResponse($response);
                    }
                    return $decode2;
                }
            }
            return $decode;
        }
    }

    public function AllocationPricingAndQuantity()
    {
        if (!$this->Token) {
            $loginAttemptResult = $this->login();
            if ($loginAttemptResult != 1) {
                return $this->InvalidLoginResponse($loginAttemptResult);
            }
        }

        $curl = $this->GetRequestBuilder("integration/items/Allocations");
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return $this->RequestError($err);
        } else {
            $decode = json_decode($response, true);
            if ($decode["authorized"] == false) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }
                $curl = $this->GetRequestBuilder("integration/items/Allocations");
                $response = curl_exec($curl);
                $err = curl_error($curl);
                curl_close($curl);

                if ($err) {
                    return $this->RequestError($err);
                } else {
                    $decode2 = json_decode($response, true);
                    if ($decode2["authorized"] == false) {
                        return $this->InvalidLoginResponse($response);
                    }
                    return $decode2;
                }
            }
            return $decode;
        }
    }

    public function ValidateItem($itemNumber)
    {
        if (!$this->Token) {
            $loginAttemptResult = $this->login();
            if ($loginAttemptResult != 1) {
                return $this->InvalidLoginResponse($loginAttemptResult);
            }
        }

        if (!$itemNumber || strlen($itemNumber) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Item number not provided"
                )
            );
        }

        $curl = $this->PostRequestBuilder("integration/items/validateitem", $itemNumber);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return $this->RequestError($err);
        } else {
            $decode = json_decode($response, true);
            if ($decode["authorized"] == false) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }

                $curl = $this->PostRequestBuilder("integration/items/validateitem", $itemNumber);
                $response = curl_exec($curl);
                $err = curl_error($curl);
                curl_close($curl);

                if ($err) {
                    return $this->RequestError($err);
                } else {
                    $decode2 = json_decode($response, true);
                    if ($decode2["authorized"] == false) {
                        return $this->InvalidLoginResponse($response);
                    }
                    return $decode2;
                }
            }
            return $decode;
        }
    }

    // (rest of your methods unchanged...)

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
}
