<?php

declare(strict_types=1);

namespace FFLHub\FFL\Parsing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure parser for the ATF FFL export.
 *
 * We now ingest the CSV layout (comma-delimited, header row).
 *
 * Output:
 * - array<int, array<string,string>> keyed to match DB columns.
 */
final class FFLParser
{
    private const LOG_PREFIX = '[FFLHUB][FFLParser]';

    private static function log(string $msg, array $ctx = []): void
    {
        if (!empty($ctx)) {
            error_log(self::LOG_PREFIX . ' ' . $msg . ' ' . wp_json_encode($ctx));
            return;
        }
        error_log(self::LOG_PREFIX . ' ' . $msg);
    }

    /**
     * Parse ATF CSV file contents.
     *
     * Expected header (case-insensitive):
     * LIC_REGN,LIC_DIST,LIC_CNTY,LIC_TYPE,LIC_XPRDTE,LIC_SEQN,LICENSE_NAME,BUSINESS_NAME,
     * PREMISE_STREET,PREMISE_CITY,PREMISE_STATE,PREMISE_ZIP_CODE,
     * MAIL_STREET,MAIL_CITY,MAIL_STATE,MAIL_ZIP_CODE,VOICE_PHONE
     *
     * @return array<int, array<string,string>> Rows keyed to match DB columns.
     */
    public function parse(string $txt): array
    {
        // Handle BOM (Excel/export often includes it).
        $txt = $this->strip_utf8_bom($txt);

        $lines = preg_split("/\r\n|\n|\r/", $txt) ?: [];
        $rows  = [];

        if (empty($lines)) {
            self::log('parse(): no lines');
            return [];
        }

        // Find first non-empty line as header.
        $headerLine = '';
        $startIndex = 0;
        foreach ($lines as $i => $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $headerLine = $line;
            $startIndex = $i + 1;
            break;
        }

        if ($headerLine === '') {
            self::log('parse(): header missing (all empty)');
            return [];
        }

        $header = str_getcsv($headerLine, ',', '"', '\\');
        $header = array_map(
            static fn($h) => strtoupper(trim((string) $h)),
            $header
        );

        // Build name->index map.
        $idx = [];
        foreach ($header as $i => $name) {
            if ($name !== '') {
                $idx[$name] = $i;
            }
        }

        // Minimal required columns for our importer.
        $required = [
            'LIC_REGN',
            'LIC_DIST',
            'LIC_CNTY',
            'LIC_TYPE',
            'LIC_XPRDTE',
            'LIC_SEQN',
            'LICENSE_NAME',
            'PREMISE_STREET',
            'PREMISE_CITY',
            'PREMISE_STATE',
            'PREMISE_ZIP_CODE',
            'MAIL_STREET',
            'MAIL_CITY',
            'MAIL_STATE',
            'MAIL_ZIP_CODE',
            'VOICE_PHONE',
        ];

        $missing = array_values(array_filter($required, static fn($k) => !isset($idx[$k])));
        if (!empty($missing)) {
            self::log('parse(): missing required header columns', [
                'missing' => $missing,
                'header'  => $header,
            ]);
            return [];
        }

        $accepted = 0;
        $skipped_empty = 0;
        $skipped_short = 0;
        $skipped_sanity = 0;

        for ($i = $startIndex; $i < count($lines); $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                $skipped_empty++;
                continue;
            }

            // Proper CSV parsing (handles quoted commas).
            $cols = str_getcsv($line, ',', '"', '\\');
            if (!is_array($cols) || count($cols) < 10) {
                $skipped_short++;
                continue;
            }

            $get = static function (array $cols, array $idx, string $key): string {
                $pos = $idx[$key] ?? null;
                if ($pos === null) {
                    return '';
                }
                return trim((string) ($cols[$pos] ?? ''));
            };

            $lic_regn   = $get($cols, $idx, 'LIC_REGN');
            $lic_dist   = $get($cols, $idx, 'LIC_DIST');
            $lic_cnty   = $get($cols, $idx, 'LIC_CNTY');
            $lic_type   = $get($cols, $idx, 'LIC_TYPE');
            $lic_xprdte = $get($cols, $idx, 'LIC_XPRDTE');
            $lic_seqn   = $get($cols, $idx, 'LIC_SEQN');

            $license_name   = $get($cols, $idx, 'LICENSE_NAME');

            $premise_street = $get($cols, $idx, 'PREMISE_STREET');
            $premise_city   = $get($cols, $idx, 'PREMISE_CITY');
            $premise_state  = strtoupper($get($cols, $idx, 'PREMISE_STATE'));
            $premise_zip    = $get($cols, $idx, 'PREMISE_ZIP_CODE');

            $mail_street    = $get($cols, $idx, 'MAIL_STREET');
            $mail_city      = $get($cols, $idx, 'MAIL_CITY');
            $mail_state     = strtoupper($get($cols, $idx, 'MAIL_STATE'));
            $mail_zip       = $get($cols, $idx, 'MAIL_ZIP_CODE');

            $voice_phone    = $get($cols, $idx, 'VOICE_PHONE');

            // Basic sanity check: must have a name and premise address fields.
            if (
                $license_name === '' ||
                $premise_street === '' ||
                $premise_city === '' ||
                $premise_state === ''
            ) {
                $skipped_sanity++;
                continue;
            }

            // Normalize ZIPs by removing spaces.
            $premise_zip = preg_replace('/\s+/', '', $premise_zip) ?? '';
            $mail_zip    = preg_replace('/\s+/', '', $mail_zip) ?? '';

            // Require all 6 parts for FFL number.
            $ffl_number_parts = array_map('trim', [
                $lic_regn,
                $lic_dist,
                $lic_cnty,
                $lic_type,
                $lic_xprdte,
                $lic_seqn,
            ]);

            $ffl_number_parts = array_values(array_filter(
                $ffl_number_parts,
                static fn(string $v): bool => $v !== ''
            ));

            if (count($ffl_number_parts) < 6) {
                $skipped_sanity++;
                continue;
            }

            $ffl_number = implode('-', $ffl_number_parts);

            $rows[] = [
                'ffl_number'     => $ffl_number,
                'license_name'   => $license_name,
                'premise_street' => $premise_street,
                'premise_city'   => $premise_city,
                'premise_state'  => $premise_state,
                'premise_zip'    => $premise_zip,
                'mail_street'    => $mail_street,
                'mail_city'      => $mail_city,
                'mail_state'     => $mail_state,
                'mail_zip'       => $mail_zip,
                'voice_phone'    => $voice_phone,
            ];

            $accepted++;
        }

        self::log('parse() summary', [
            'accepted'       => $accepted,
            'rows_returned'  => count($rows),
            'skipped_empty'  => $skipped_empty,
            'skipped_short'  => $skipped_short,
            'skipped_sanity' => $skipped_sanity,
        ]);

        return $rows;
    }

    private function strip_utf8_bom(string $s): string
    {
        // UTF-8 BOM: EF BB BF
        if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
            return substr($s, 3);
        }
        return $s;
    }
}
