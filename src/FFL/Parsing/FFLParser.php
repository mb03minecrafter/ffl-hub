<?php

declare(strict_types=1);

namespace FFLHub\FFL\Parsing;

use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure parser for the ATF FFL export (CSV).
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
            DebugLogUtil::log_ctx('FFLHUB_ADMIN_DEBUG', self::LOG_PREFIX, $msg, $ctx);
            return;
        }
        DebugLogUtil::log('FFLHUB_ADMIN_DEBUG', self::LOG_PREFIX, $msg);
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
        $header = array_map(static fn($h) => strtoupper(trim((string) $h)), $header);

        // Build name->index map.
        $idx = [];
        foreach ($header as $i => $name) {
            if ($name !== '') {
                $idx[$name] = $i;
            }
        }

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

        $accepted       = 0;
        $skipped_empty  = 0;
        $skipped_short  = 0;
        $skipped_sanity = 0;

        for ($i = $startIndex; $i < count($lines); $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                $skipped_empty++;
                continue;
            }

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
            $lic_xprdte = $get($cols, $idx, 'LIC_XPRDTE'); // <-- 2-char code like "7F"
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

            if (
                $license_name === '' ||
                $premise_street === '' ||
                $premise_city === '' ||
                $premise_state === ''
            ) {
                $skipped_sanity++;
                continue;
            }

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

            // Convert ATF expiration CODE -> real date string (YYYY-MM-DD) for DB DATE column.
            $ffl_expiration = $this->normalize_atf_expiration($lic_xprdte);

            $rows[] = [
                'ffl_number'     => $ffl_number,
                'ffl_expiration' => $ffl_expiration, // '' allowed (DATE NULL)
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
        if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
            return substr($s, 3);
        }
        return $s;
    }

    /**
     * Normalize ATF expiration value into YYYY-MM-DD.
     *
     * Important:
     * - In the ATF export, LIC_XPRDTE is usually a 2-character expiration code like "7F".
     *   - first char: last digit of expiration year
     *   - second char: month letter A–M (skipping I)
     *   Example: "8K" => Oct 2028 -> "2028-10-31"
     *
     * If something else is provided, we still support a few common literal date formats.
     */
    private function normalize_atf_expiration(string $raw): string
    {
        $s = strtoupper(trim($raw));
        if ($s === '') {
            return '';
        }

        // YYYY-MM-DD -> YYYY-MM-01
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return "{$m[1]}-{$m[2]}-01";
        }

        // MM/DD/YYYY -> YYYY-MM-01
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
            $mm = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            return "{$m[3]}-{$mm}-01";
        }

        // YYYYMMDD -> YYYY-MM-01
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
            return "{$m[1]}-{$m[2]}-01";
        }

        // ATF expiration CODE like "7F" -> YYYY-MM-01
        if (preg_match('/^([0-9])([A-Z])$/', $s, $m)) {
            $year_last_digit = (int) $m[1];
            $month_letter    = $m[2];

            $month = $this->month_from_atf_letter($month_letter);
            if ($month === 0) {
                return '';
            }

            $year = $this->resolve_expiration_year_from_last_digit($year_last_digit, $month);
            if ($year === 0) {
                return '';
            }

            return sprintf('%04d-%02d-01', $year, $month);
        }

        return '';
    }


    /**
     * Month letter mapping A–M skipping I:
     * A Jan, B Feb, C Mar, D Apr, E May, F Jun, G Jul, H Aug,
     * J Sep, K Oct, L Nov, M Dec.
     */
    private function month_from_atf_letter(string $letter): int
    {
        return match ($letter) {
            'A' => 1,
            'B' => 2,
            'C' => 3,
            'D' => 4,
            'E' => 5,
            'F' => 6,
            'G' => 7,
            'H' => 8,
            'J' => 9,
            'K' => 10,
            'L' => 11,
            'M' => 12,
            default => 0,
        };
    }

    /**
     * Given last digit of year + month, pick the nearest expiration year that is not in the past.
     *
     * Example (today 2026-02):
     * - code 7F (Jun) -> 2027
     * - code 6H (Aug) -> 2026 (since Aug 2026 is still upcoming)
     */
    private function resolve_expiration_year_from_last_digit(int $last_digit, int $month): int
    {
        // Use UTC "now" since imports usually run on server time; month-level granularity is fine.
        $nowY = (int) gmdate('Y');
        $nowM = (int) gmdate('n');

        // Search forward up to 20 years to find the first non-past match.
        for ($y = $nowY; $y <= $nowY + 20; $y++) {
            if (($y % 10) !== $last_digit) {
                continue;
            }

            // If it's this year, ensure the expiration month hasn't already passed.
            if ($y === $nowY && $month < $nowM) {
                continue;
            }

            return $y;
        }

        return 0;
    }
}
