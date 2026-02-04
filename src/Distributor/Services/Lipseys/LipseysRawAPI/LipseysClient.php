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


    //we are now rolling our own catalog function that streams directly to a tsv that we can later local in file into our sql table 
    public function CatalogToTsv(string $tsv_path, array $columns, callable $item_to_row): array
    {
        // $item_to_row: function(array $item): ?array
        // returns: row array keyed by column => value, or null to skip (e.g., canDropship filter)

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

            $result = $this->catalog_stream_to_tsv_once($tsv_path, $columns, $item_to_row);

            // If streaming detected unauthorized, we mimic old behavior: re-login and retry once.
            if (is_array($result) && isset($result['authorized']) && $result['authorized'] === false) {
                $loginAttemptResult = $this->login();
                if ($loginAttemptResult != 1) {
                    return $this->InvalidLoginResponse($loginAttemptResult);
                }
                $last_err = $result;
                continue;
            }

            // Success or a real error
            return $result;
        }

        // If we got here, we failed twice with unauthorized or something weird
        return is_array($last_err) ? $last_err : $this->RequestError('CatalogToTsv failed after retry.');
    }

    /**
     * One attempt: stream CatalogFeed and write TSV without loading full JSON.
     */
    private function catalog_stream_to_tsv_once(string $tsv_path, array $columns, callable $item_to_row): array
    {
        $fh = @fopen($tsv_path, 'wb');
        if (!$fh) {
            return $this->RequestError('Failed to open TSV for writing: ' . $tsv_path);
        }

        // Optional: write header row (comment out if you don't want it)
        // $this->tsv_write_row($fh, $columns);

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

        $curl = $this->GetRequestBuilder("integration/items/CatalogFeed");

        // Streaming state
        $buffer = '';
        $found_array_start = false;
        $array_key_found = null; // 'data' or 'items'
        $preamble_checked = false;

        // Object extraction state (brace counter, string/escape tracking)
        $in_string = false;
        $escape = false;
        $depth = 0;
        $collecting_obj = false;
        $obj = '';

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, false); // IMPORTANT: stream via WRITEFUNCTION

        curl_setopt($curl, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (
            &$stats,
            &$buffer,
            &$found_array_start,
            &$array_key_found,
            &$preamble_checked,
            &$in_string,
            &$escape,
            &$depth,
            &$collecting_obj,
            &$obj,
            $fh,
            $columns,
            $item_to_row
        ) {
            $len = strlen($chunk);
            $stats['bytes_received'] += $len;

            // Append to buffer for scanning / parsing
            $buffer .= $chunk;

            // 1) Before we start parsing items, find the array start: "data":[ or "items":[
            if (!$found_array_start) {
                // Check preamble for authorized:false once we have "authorized" field
                if (!$preamble_checked) {
                    // Cheap check: only evaluate once we see the word authorized
                    if (strpos($buffer, '"authorized"') !== false) {
                        // If authorized is false, stop early
                        if (preg_match('/"authorized"\s*:\s*false/i', $buffer)) {
                            $stats['authorized'] = false;
                            $stats['success'] = false;
                            $stats['errors'][] = 'Not authorized (token invalid/expired).';
                            return 0; // abort transfer
                        }
                        $preamble_checked = true;
                    }
                }

                // Find the beginning of the array
                $pos = strpos($buffer, '"data"');
                $key = 'data';
                if ($pos === false) {
                    $pos = strpos($buffer, '"items"');
                    $key = 'items';
                }

                if ($pos !== false) {
                    // Find the '[' after the key
                    $bracket_pos = strpos($buffer, '[', $pos);
                    if ($bracket_pos !== false) {
                        $found_array_start = true;
                        $array_key_found = $key;

                        // Discard everything up to and including the '[' so buffer begins right after array start
                        $buffer = substr($buffer, $bracket_pos + 1);
                    }
                }

                // Not ready to parse objects yet
                return $len;
            }

            // 2) We are inside the items array region. Incrementally extract JSON objects { ... }.
            $i = 0;
            $buf_len = strlen($buffer);

            while ($i < $buf_len) {
                $c = $buffer[$i];

                if (!$collecting_obj) {
                    // Skip until first '{' or ']' (end of array)
                    if ($c === '{') {
                        $collecting_obj = true;
                        $obj = '{';
                        $depth = 1;
                        $in_string = false;
                        $escape = false;
                    } elseif ($c === ']') {
                        // End of array - we're done
                        // Keep buffer empty to avoid reprocessing
                        $buffer = '';
                        return $len;
                    }
                    $i++;
                    continue;
                }

                // Collecting an object: brace-depth parse with string/escape handling
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
                            // End of object
                            $collecting_obj = false;

                            $decoded = json_decode($obj, true);
                            if (is_array($decoded)) {
                                $stats['items_seen']++;

                                $row = null;
                                try {
                                    $row = $item_to_row($decoded);
                                } catch (\Throwable $e) {
                                    $stats['items_skipped']++;
                                    // silently skip (or add error if you want)
                                    $row = null;
                                }

                                if (is_array($row)) {
                                    $line = array();
                                    foreach ($columns as $col) {
                                        $line[] = isset($row[$col]) ? (string) $row[$col] : '';
                                    }
                                    $this->tsv_write_row($fh, $line);
                                    $stats['rows_written']++;
                                } else {
                                    $stats['items_skipped']++;
                                }
                            } else {
                                $stats['json_decode_fails']++;
                            }

                            // Reset object accumulator
                            $obj = '';
                        }
                    }
                }

                $i++;
            }

            // We consumed entire buffer contents into parsing state; clear buffer.
            // BUT if we are mid-object, keep only the object accumulator in $obj (already stored).
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

        // If we aborted due to unauthorized in WRITEFUNCTION, stats['authorized'] is false.
        if ($stats['authorized'] === false) {
            return array(
                'authorized' => false,
                'success'    => false,
                'errors'     => $stats['errors'],
            );
        }

        // Basic HTTP sanity
        if ((int)$http !== 200) {
            return $this->RequestError('Unexpected HTTP status: ' . (string) $http);
        }

        $stats['success'] = true;
        return $stats;
    }

    /**
     * Write one TSV row, escaping tabs/newlines safely.
     *
     * @param resource $fh
     * @param string[] $fields
     */
    private function tsv_write_row($fh, array $fields): void
    {
        // Replace tabs/newlines to keep file well-formed for LOAD DATA
        foreach ($fields as &$v) {
            $v = (string) $v;
            $v = str_replace(array("\t", "\r", "\n"), array(' ', ' ', ' '), $v);
        }
        unset($v);

        fwrite($fh, implode("\t", $fields) . "\n");
    }




    /**
     * Download-only benchmark: hit CatalogFeed and just count bytes (no parsing, no TSV).
     * Lets us isolate network/server time from CPU parsing time.
     */
    public function CatalogDownloadOnlyStats(): array
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

            $result = $this->catalog_download_only_once();

            // Mirror the same unauthorized retry behavior.
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

        return is_array($last_err) ? $last_err : $this->RequestError('CatalogDownloadOnlyStats failed after retry.');
    }

    private function catalog_download_only_once(): array
    {
        $stats = array(
            'authorized'     => true,
            'success'        => false,
            'errors'         => array(),
            'bytes_received' => 0,
            'http_code'      => 0,
            'curl_total_s'   => 0.0,
            'curl_speed_bps' => 0.0,
        );

        $curl = $this->GetRequestBuilder("integration/items/CatalogFeed");

        // Important: stream (no giant string in memory)
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);

        // Optional micro-opts (won’t hurt; may help a tiny bit)
        if (defined('CURL_HTTP_VERSION_2TLS')) {
            @curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
        }
        @curl_setopt($curl, CURLOPT_BUFFERSIZE, 262144); // 256KB

        // We still need to detect unauthorized early like your TSV stream does.
        $buffer = '';
        $preamble_checked = false;

        curl_setopt($curl, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$stats, &$buffer, &$preamble_checked) {
            $len = strlen($chunk);
            $stats['bytes_received'] += $len;

            if (!$preamble_checked) {
                $buffer .= $chunk;

                // only once we see "authorized"
                if (strpos($buffer, '"authorized"') !== false) {
                    if (preg_match('/"authorized"\s*:\s*false/i', $buffer)) {
                        $stats['authorized'] = false;
                        $stats['success'] = false;
                        $stats['errors'][] = 'Not authorized (token invalid/expired).';
                        return 0; // abort transfer
                    }
                    $preamble_checked = true;
                    $buffer = ''; // free memory
                } elseif (strlen($buffer) > 65536) {
                    // prevent unbounded buffer growth if response is weird
                    $buffer = substr($buffer, -32768);
                }
            }

            return $len;
        });

        $ok  = curl_exec($curl);
        $err = curl_error($curl);
        $info = curl_getinfo($curl);

        $stats['http_code'] = (int) ($info['http_code'] ?? 0);
        $stats['curl_total_s'] = (float) ($info['total_time'] ?? 0.0);
        $stats['curl_speed_bps'] = (float) ($info['speed_download'] ?? 0.0);

        curl_close($curl);

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

        if ($stats['http_code'] !== 200) {
            return $this->RequestError('Unexpected HTTP status: ' . (string) $stats['http_code']);
        }

        $stats['success'] = true;
        return $stats;
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

    public function Order($order)
    {
        if (!$this->Token) {
            $loginAttemptResult = $this->login();
            if ($loginAttemptResult != 1) {
                return $this->InvalidLoginResponse($loginAttemptResult);
            }
        }

        if (!$order || !array_key_exists("Items", $order) || count($order["Items"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"Items\""
                )
            );
        }
        foreach ($order["Items"] as &$value) {
            if (!array_key_exists("ItemNo", $value) || strlen($value["ItemNo"]) < 1 || !$value["Quantity"] || $value["Quantity"] < 1) {
                print_r($value["Quantity"]);

                return array(
                    "authorized" => true,
                    "success" => false,
                    "errors" => array(
                        "One or more line item was missing item number or had less than 1 quantity"
                    )
                );
            }
        }

        $curl = $this->PostRequestBuilder("integration/order/apiorder", $order);
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

                $curl = $this->PostRequestBuilder("integration/order/apiorder", $order);
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
    public function AllocationOrder($order)
    {
        if (!$this->Token) {
            $loginAttemptResult = $this->login();
            if ($loginAttemptResult != 1) {
                return $this->InvalidLoginResponse($loginAttemptResult);
            }
        }

        if (!$order || !array_key_exists("Items", $order) || count($order["Items"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"Items\""
                )
            );
        }
        foreach ($order["Items"] as &$value) {
            if (!array_key_exists("ItemNo", $value) || strlen($value["ItemNo"]) < 1 || !$value["Quantity"] || $value["Quantity"] < 1) {
                print_r($value["Quantity"]);

                return array(
                    "authorized" => true,
                    "success" => false,
                    "errors" => array(
                        "One or more line item was missing item number or had less than 1 quantity"
                    )
                );
            }
        }

        $curl = $this->PostRequestBuilder("integration/order/AllocationOrder", $order);
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

                $curl = $this->PostRequestBuilder("integration/order/AllocationOrder", $order);
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
    public function DropShipAccessories($order)
    {
        if (!$this->Token) {
            $loginAttemptResult = $this->login();
            if ($loginAttemptResult != 1) {
                return $this->InvalidLoginResponse($loginAttemptResult);
            }
        }


        if (!$order || !array_key_exists("BillingName", $order) || count($order["BillingName"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"BillingName\""
                )
            );
        }
        if (!$order || !array_key_exists("BillingAddressLine1", $order) || count($order["BillingAddressLine1"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"BillingAddressLine1\""
                )
            );
        }
        if (!$order || !array_key_exists("BillingAddressCity", $order) || count($order["BillingAddressCity"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"BillingAddressCity\""
                )
            );
        }
        if (!$order || !array_key_exists("BillingAddressState", $order) || count($order["BillingAddressState"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"BillingAddressState\""
                )
            );
        }

        if (strlen($order["BillingAddressState"]) != 2) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "BillingAddressState Should be 2 Letters"
                )
            );
        }
        if (!$order || !array_key_exists("BillingAddressZip", $order) || count($order["BillingAddressZip"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"BillingAddressZip\""
                )
            );
        }
        if (strlen($order["BillingAddressZip"]) > 5) {
            $order["BillingAddressZip"] = trim($order["BillingAddressZip"]);
            if (strlen($order["BillingAddressZip"]) > 5) {
                $order["BillingAddressZip"] = substr($order["BillingAddressZip"], 0, 5);
            }
        }
        if (strlen($order["BillingAddressZip"]) < 5) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "BillingAddressZip Should be 5 Numbers"
                )
            );
        }
        if (!$order || !array_key_exists("ShippingName", $order) || count($order["ShippingName"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"ShippingName\""
                )
            );
        }
        if (!$order || !array_key_exists("ShippingAddressLine1", $order) || count($order["ShippingAddressLine1"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"ShippingAddressLine1\""
                )
            );
        }
        if (!$order || !array_key_exists("ShippingAddressCity", $order) || count($order["ShippingAddressCity"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"ShippingAddressCity\""
                )
            );
        }
        if (!$order || !array_key_exists("ShippingAddressState", $order) || count($order["ShippingAddressState"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"ShippingAddressState\""
                )
            );
        }
        if (strlen($order["ShippingAddressState"]) != 2) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "ShippingAddressState Should be 2 Letters"
                )
            );
        }
        if (!$order || !array_key_exists("ShippingAddressZip", $order) || count($order["ShippingAddressZip"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"ShippingAddressZip\""
                )
            );
        }

        if (strlen($order["ShippingAddressZip"]) > 5) {
            $order["ShippingAddressZip"] = trim($order["ShippingAddressZip"]);
            if (strlen($order["ShippingAddressZip"]) > 5) {
                $order["ShippingAddressZip"] = substr($order["ShippingAddressZip"], 0, 5);
            }
        }
        if (strlen($order["ShippingAddressZip"]) < 5) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "ShippingAddressZip Should be 5 Numbers"
                )
            );
        }

        if (!$order || !array_key_exists("PoNumber", $order) || count($order["PoNumber"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"PoNumber\""
                )
            );
        }



        if (!$order || !array_key_exists("Items", $order) || count($order["Items"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"Items\""
                )
            );
        }
        foreach ($order["Items"] as &$value) {
            if (!array_key_exists("ItemNo", $value) || strlen($value["ItemNo"]) < 1 || !array_key_exists("Quantity", $value) || $value["Quantity"] < 1) {
                return array(
                    "authorized" => true,
                    "success" => false,
                    "errors" => array(
                        "One or more line item was missing item number or had less than 1 quantity"
                    )
                );
            }
        }


        $curl = $this->PostRequestBuilder("integration/order/dropship", $order);
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

                $curl = $this->PostRequestBuilder("integration/order/dropship", $order);
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
    public function DropShipFirearms($order)
    {
        if (!$this->Token) {
            $loginAttemptResult = $this->login();
            if ($loginAttemptResult != 1) {
                return $this->InvalidLoginResponse($loginAttemptResult);
            }
        }

        if (!$order || !array_key_exists("Ffl", $order) || count($order["Ffl"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"Ffl\""
                )
            );
        }
        if (!$order || !array_key_exists("Name", $order) || count($order["Name"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"Name\""
                )
            );
        }
        if (!$order || !array_key_exists("Phone", $order) || count($order["Phone"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"Phone\""
                )
            );
        }

        if (!$order || !array_key_exists("Items", $order) || count($order["Items"]) < 1) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "Field Missing: \"Items\""
                )
            );
        }
        foreach ($order["Items"] as &$value) {
            if (!array_key_exists("ItemNo", $value) || strlen($value["ItemNo"]) < 1 || !array_key_exists("Quantity", $value) || $value["Quantity"] < 1) {
                return array(
                    "authorized" => true,
                    "success" => false,
                    "errors" => array(
                        "One or more line item was missing item number or had less than 1 quantity"
                    )
                );
            }
        }


        $curl = $this->PostRequestBuilder("integration/order/DropShipFirearm", $order);
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

                $curl = $this->PostRequestBuilder("integration/order/DropShipFirearm", $order);
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

    public function OneDaysShipping($date)
    {
        if (!$this->Token) {
            $loginAttemptResult = $this->login();
            if ($loginAttemptResult != 1) {
                return $this->InvalidLoginResponse($loginAttemptResult);
            }
        }

        if (!$date) {
            return array(
                "authorized" => true,
                "success" => false,
                "errors" => array(
                    "date not provided"
                )
            );
        }

        $curl = $this->PostRequestBuilder("integration/shipping/oneday", $date);
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

                $curl = $this->PostRequestBuilder("integration/shipping/oneday", $date);
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
