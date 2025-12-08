<?php

declare(strict_types=1);

namespace FFLHub\FFL;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pure parser for the ATF TXT file.
 * Turns the raw text into an array of associative arrays ready to insert.
 */
class FFLParser
{
    /**
     * Parse the ATF TXT file contents.
     *
     * @param string $txt
     * @return array<int, array<string,string>> Rows keyed to match DB columns.
     */
    public function parse(string $txt): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $txt);
        $rows  = array();

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $columns = explode("\t", $line);

            // Skip header row that starts with "LIC_REGN".
            if (isset($columns[0]) && strtoupper(trim($columns[0])) === 'LIC_REGN') {
                continue;
            }

            // Expect at least 17 columns based on the sample.
            if (count($columns) < 17) {
                continue;
            }

            // Raw columns (include LIC_REGN again).
            $lic_regn     = trim($columns[0] ?? '');
            $lic_dist     = trim($columns[1] ?? '');
            $lic_cnty     = trim($columns[2] ?? '');
            $lic_type     = trim($columns[3] ?? '');
            $lic_xprdte   = trim($columns[4] ?? '');
            $lic_seqn     = trim($columns[5] ?? '');
            $license_name = trim($columns[6] ?? '');

            // Column 7 is blank spacer.
            $premise_street = trim($columns[8]  ?? '');
            $premise_city   = trim($columns[9]  ?? '');
            $premise_state  = strtoupper(trim($columns[10] ?? ''));
            $premise_zip    = trim($columns[11] ?? '');

            $mail_street = trim($columns[12] ?? '');
            $mail_city   = trim($columns[13] ?? '');
            $mail_state  = strtoupper(trim($columns[14] ?? ''));
            $mail_zip    = trim($columns[15] ?? '');

            $voice_phone = trim($columns[16] ?? '');

            // Basic sanity check: must have name + premises address.
            if (
                $license_name === '' ||
                $premise_street === '' ||
                $premise_city === '' ||
                $premise_state === ''
            ) {
                continue;
            }

            // Normalize ZIPs (remove spaces).
            $premise_zip = preg_replace('/\s+/', '', $premise_zip);
            $mail_zip    = preg_replace('/\s+/', '', $mail_zip);

            // Build FFL number INCLUDING LIC_REGN:
            // Format: REGN-DIST-CNTY-TYPE-XPRDTE-SEQ
            $ffl_number_parts = array($lic_regn, $lic_dist, $lic_cnty, $lic_type, $lic_xprdte, $lic_seqn);
            $ffl_number_parts = array_map('trim', $ffl_number_parts);
            $ffl_number_parts = array_filter(
                $ffl_number_parts,
                static function ($v) {
                    return $v !== '';
                }
            );

            // Must have all 6 parts.
            if (count($ffl_number_parts) < 6) {
                continue;
            }

            $ffl_number = implode('-', $ffl_number_parts);

            $rows[] = array(
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
            );
        }

        return $rows;
    }
}
