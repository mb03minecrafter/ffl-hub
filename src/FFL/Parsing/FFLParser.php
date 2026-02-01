<?php

declare(strict_types=1);

namespace FFLHub\FFL\Parsing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure parser for the ATF TXT export.
 *
 * Input:
 * - Raw text contents of the ATF tab-delimited file.
 *
 * Output:
 * - An array of row arrays keyed to match the FFL DB columns (FFLSchema),
 *   suitable for bulk insert/upsert by an importer layer.
 *
 * Non-responsibilities:
 * - Downloading the file
 * - Validation beyond basic sanity checks
 * - Database writes or batching
 */
final class FFLParser
{
    /**
     * Parse the ATF TXT file contents.
     *
     * Notes:
     * - File is expected to be tab-delimited.
     * - A header row may be present (starts with "LIC_REGN").
     * - Some lines may be malformed or incomplete; these are skipped.
     *
     * @return array<int, array<string,string>> Rows keyed to match DB columns.
     */
    public function parse(string $txt): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $txt);
        $rows  = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $columns = explode("\t", $line);

            // Skip header row that starts with "LIC_REGN".
            if (isset($columns[0]) && strtoupper(trim((string) $columns[0])) === 'LIC_REGN') {
                continue;
            }

            // Expect at least 17 columns based on the ATF layout we ingest.
            if (count($columns) < 17) {
                continue;
            }

            // Raw columns used to construct the FFL number (all 6 parts required).
            $lic_regn   = trim((string) ($columns[0] ?? ''));
            $lic_dist   = trim((string) ($columns[1] ?? ''));
            $lic_cnty   = trim((string) ($columns[2] ?? ''));
            $lic_type   = trim((string) ($columns[3] ?? ''));
            $lic_xprdte = trim((string) ($columns[4] ?? ''));
            $lic_seqn   = trim((string) ($columns[5] ?? ''));

            $license_name = trim((string) ($columns[6] ?? ''));

            // Column 7 (index 7) is a blank spacer in the source file.
            $premise_street = trim((string) ($columns[8] ?? ''));
            $premise_city   = trim((string) ($columns[9] ?? ''));
            $premise_state  = strtoupper(trim((string) ($columns[10] ?? '')));
            $premise_zip    = trim((string) ($columns[11] ?? ''));

            $mail_street = trim((string) ($columns[12] ?? ''));
            $mail_city   = trim((string) ($columns[13] ?? ''));
            $mail_state  = strtoupper(trim((string) ($columns[14] ?? '')));
            $mail_zip    = trim((string) ($columns[15] ?? ''));

            $voice_phone = trim((string) ($columns[16] ?? ''));

            // Basic sanity check: must have a name and premise address fields.
            if (
                $license_name === '' ||
                $premise_street === '' ||
                $premise_city === '' ||
                $premise_state === ''
            ) {
                continue;
            }

            // Normalize ZIPs by removing spaces (sometimes appear in source).
            $premise_zip = preg_replace('/\s+/', '', $premise_zip) ?? '';
            $mail_zip    = preg_replace('/\s+/', '', $mail_zip) ?? '';

            /**
             * Build FFL number INCLUDING LIC_REGN:
             * Format: REGN-DIST-CNTY-TYPE-XPRDTE-SEQ
             *
             * We require all 6 parts; otherwise row is skipped.
             */
            $ffl_number_parts = [
                $lic_regn,
                $lic_dist,
                $lic_cnty,
                $lic_type,
                $lic_xprdte,
                $lic_seqn,
            ];

            $ffl_number_parts = array_map('trim', $ffl_number_parts);
            $ffl_number_parts = array_values(array_filter(
                $ffl_number_parts,
                static fn(string $v): bool => $v !== ''
            ));

            if (count($ffl_number_parts) < 6) {
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
        }

        return $rows;
    }
}
