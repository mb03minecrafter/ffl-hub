<?php

namespace FFLHub\Distributor\Services\BillHicks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parses Bill Hicks 855 acknowledgement and 856 shipment notice files.
 *
 * The samples repeat a header row before each data row:
 * - ACK|... followed by AckData|...
 * - ASN|... followed by ASNData|...
 */
final class BillHicksEdiInboundParser
{
    /**
     * @return array{acks:array<int,array<string,string>>,shipments:array<int,array<string,string>>,errors:string[],file_type:string}
     */
    public function parse_file(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [
                'acks' => [],
                'shipments' => [],
                'errors' => ['Inbound Bill Hicks EDI file is missing or unreadable.'],
                'file_type' => 'unknown',
            ];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [
                'acks' => [],
                'shipments' => [],
                'errors' => ['Unable to read inbound Bill Hicks EDI file.'],
                'file_type' => 'unknown',
            ];
        }

        $acks = [];
        $shipments = [];
        $errors = [];
        $headers = [];
        $mode = '';

        foreach ($lines as $line_no => $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $delimiter = strpos($line, '|') !== false ? '|' : "\t";
            $fields = array_map('trim', explode($delimiter, $line));
            $record_type = strtoupper(trim((string) ($fields[0] ?? '')));

            if ($record_type === 'ACK' || $record_type === 'ASN') {
                $mode = $record_type;
                $headers = $fields;
                continue;
            }

            if ($record_type === 'ACKDATA') {
                if ($mode !== 'ACK' || empty($headers)) {
                    $errors[] = 'AckData row without ACK header at line ' . ((int) $line_no + 1) . '.';
                    continue;
                }
                $acks[] = $this->map_row($headers, $fields);
                continue;
            }

            if ($record_type === 'ASNDATA') {
                if ($mode !== 'ASN' || empty($headers)) {
                    $errors[] = 'ASNData row without ASN header at line ' . ((int) $line_no + 1) . '.';
                    continue;
                }
                $shipments[] = $this->map_row($headers, $fields);
                continue;
            }

            $errors[] = 'Unknown Bill Hicks EDI record type "' . (string) ($fields[0] ?? '') . '" at line ' . ((int) $line_no + 1) . '.';
        }

        $file_type = 'unknown';
        if (!empty($acks) && empty($shipments)) {
            $file_type = '855';
        } elseif (empty($acks) && !empty($shipments)) {
            $file_type = '856';
        } elseif (!empty($acks) && !empty($shipments)) {
            $file_type = 'mixed';
        }

        return [
            'acks' => $acks,
            'shipments' => $shipments,
            'errors' => $errors,
            'file_type' => $file_type,
        ];
    }

    /**
     * @param string[] $headers
     * @param string[] $fields
     * @return array<string,string>
     */
    private function map_row(array $headers, array $fields): array
    {
        $out = [];
        $max = max(count($headers), count($fields));

        for ($i = 0; $i < $max; $i++) {
            $key = trim((string) ($headers[$i] ?? ('field_' . $i)));
            if ($key === '') {
                $key = 'field_' . $i;
            }
            $out[$key] = trim((string) ($fields[$i] ?? ''));
        }

        return $out;
    }
}
