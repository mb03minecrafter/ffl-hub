<?php

declare(strict_types=1);

namespace FFLHub\FFL\Parsing;

use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure parser for the ATF FFL exports.
 *
 * Supports the header-based CSV export and ATF's 323-character fixed-width
 * TXT export. Both formats produce the same normalized database rows.
 */
final class FFLParser
{
    private const FIXED_WIDTH_REQUIRED_LENGTH = 307;

    /** @var array<string,array{0:int,1:int}> */
    private const FIXED_WIDTH_FIELDS = [
        'LIC_REGN'        => [0, 1],
        'LIC_DIST'        => [1, 2],
        'LIC_CNTY'        => [3, 3],
        'LIC_TYPE'        => [6, 2],
        'LIC_XPRDTE'      => [8, 2],
        'LIC_SEQN'        => [10, 5],
        'LICENSE_NAME'    => [15, 50],
        'BUSINESS_NAME'   => [65, 50],
        'PREMISE_STREET'  => [115, 50],
        'PREMISE_CITY'    => [165, 30],
        'PREMISE_STATE'   => [195, 2],
        'PREMISE_ZIP_CODE' => [197, 9],
        'MAIL_STREET'     => [206, 50],
        'MAIL_CITY'       => [256, 30],
        'MAIL_STATE'      => [286, 2],
        'MAIL_ZIP_CODE'   => [288, 9],
        'VOICE_PHONE'     => [297, 10],
    ];

    private const LOG_PREFIX = '[FFLHUB][FFLParser]';

    private static function log(string $msg, array $ctx = []): void
    {
        if (!empty($ctx)) {
            DebugLogUtil::log_ctx('FFLHUB_ADMIN_DEBUG', self::LOG_PREFIX, $msg, $ctx);
            return;
        }
        DebugLogUtil::log('FFLHUB_ADMIN_DEBUG', self::LOG_PREFIX, $msg);
    }

    /** @return array<int,array<string,string>> */
    public function parse(string $txt): array
    {
        $txt = $this->strip_utf8_bom($txt);

        $lines = preg_split("/\r\n|\n|\r/", $txt) ?: [];
        if (empty($lines)) {
            self::log('parse(): no lines');
            return [];
        }

        $first_line_index = null;
        foreach ($lines as $i => $line) {
            if (trim((string) $line) !== '') {
                $first_line_index = (int) $i;
                break;
            }
        }

        if ($first_line_index === null) {
            self::log('parse(): no non-empty records');
            return [];
        }

        $first_line = (string) $lines[$first_line_index];
        if ($this->looks_like_fixed_width_record($first_line)) {
            return $this->parse_fixed_width($lines);
        }

        return $this->parse_csv($lines, $first_line_index);
    }

    /**
     * @param string[] $lines
     * @return array<int,array<string,string>>
     */
    private function parse_csv(array $lines, int $header_index): array
    {
        $header = str_getcsv(trim((string) $lines[$header_index]), ',', '"', '\\');
        $header = array_map(static fn($h) => strtoupper(trim((string) $h)), $header);

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
            self::log('parse_csv(): missing required header columns', [
                'missing' => $missing,
                'header'  => $header,
            ]);
            return [];
        }

        $rows           = [];
        $accepted       = 0;
        $skipped_empty  = 0;
        $skipped_short  = 0;
        $skipped_sanity = 0;

        $line_count = count($lines);
        for ($i = $header_index + 1; $i < $line_count; $i++) {
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

            $source = [];
            foreach ($required as $field) {
                $source[$field] = $get($cols, $idx, $field);
            }

            $row = $this->normalize_row($source);
            if ($row === null) {
                $skipped_sanity++;
                continue;
            }

            $rows[] = $row;
            $accepted++;
        }

        self::log('parse_csv() summary', [
            'accepted'       => $accepted,
            'rows_returned'  => count($rows),
            'skipped_empty'  => $skipped_empty,
            'skipped_short'  => $skipped_short,
            'skipped_sanity' => $skipped_sanity,
        ]);

        return $rows;
    }

    /**
     * @param string[] $lines
     * @return array<int,array<string,string>>
     */
    private function parse_fixed_width(array $lines): array
    {
        $rows           = [];
        $skipped_empty  = 0;
        $skipped_short  = 0;
        $skipped_sanity = 0;

        foreach ($lines as $line) {
            $line = (string) $line;
            if (trim($line) === '') {
                $skipped_empty++;
                continue;
            }

            // The final 16 characters are optional ATF date fields that are
            // not stored by the current FFL schema.
            if (strlen($line) < self::FIXED_WIDTH_REQUIRED_LENGTH) {
                $skipped_short++;
                continue;
            }

            $source = [];
            foreach (self::FIXED_WIDTH_FIELDS as $field => [$offset, $length]) {
                $source[$field] = trim(substr($line, $offset, $length));
            }

            $row = $this->normalize_row($source);
            if ($row === null) {
                $skipped_sanity++;
                continue;
            }

            $rows[] = $row;
        }

        self::log('parse_fixed_width() summary', [
            'accepted'       => count($rows),
            'rows_returned'  => count($rows),
            'skipped_empty'  => $skipped_empty,
            'skipped_short'  => $skipped_short,
            'skipped_sanity' => $skipped_sanity,
        ]);

        return $rows;
    }

    private function looks_like_fixed_width_record(string $line): bool
    {
        if (strlen($line) < self::FIXED_WIDTH_REQUIRED_LENGTH) {
            return false;
        }

        return preg_match('/^\d{9}[A-HJ-M]\d{5}/', $line) === 1;
    }

    /**
     * @param array<string,string> $source
     * @return array<string,string>|null
     */
    private function normalize_row(array $source): ?array
    {
        $get = static fn(string $field): string => trim((string) ($source[$field] ?? ''));

        $license_name   = $get('LICENSE_NAME');
        $premise_street = $get('PREMISE_STREET');
        $premise_city   = $get('PREMISE_CITY');
        $premise_state  = strtoupper($get('PREMISE_STATE'));

        if ($license_name === '' || $premise_street === '' || $premise_city === '' || $premise_state === '') {
            return null;
        }

        $ffl_number_parts = [
            $get('LIC_REGN'),
            $get('LIC_DIST'),
            $get('LIC_CNTY'),
            $get('LIC_TYPE'),
            $get('LIC_XPRDTE'),
            $get('LIC_SEQN'),
        ];

        foreach ($ffl_number_parts as $part) {
            if ($part === '') {
                return null;
            }
        }

        $premise_zip = preg_replace('/\s+/', '', $get('PREMISE_ZIP_CODE')) ?? '';
        $mail_zip    = preg_replace('/\s+/', '', $get('MAIL_ZIP_CODE')) ?? '';

        return [
            'ffl_number'     => implode('-', $ffl_number_parts),
            'ffl_expiration' => $this->normalize_atf_expiration($get('LIC_XPRDTE')),
            'license_name'   => $license_name,
            'premise_street' => $premise_street,
            'premise_city'   => $premise_city,
            'premise_state'  => $premise_state,
            'premise_zip'    => $premise_zip,
            'mail_street'    => $get('MAIL_STREET'),
            'mail_city'      => $get('MAIL_CITY'),
            'mail_state'     => strtoupper($get('MAIL_STATE')),
            'mail_zip'       => $mail_zip,
            'voice_phone'    => $get('VOICE_PHONE'),
        ];
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
