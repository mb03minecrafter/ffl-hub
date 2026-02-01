<?php
declare(strict_types=1);

namespace FFLHub\FFL\Data;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps raw database rows to the canonical "public" FFL structure.
 *
 * This is the single place where:
 * - DB column names are translated to API/UI-friendly keys.
 * - Nested address structure is enforced.
 *
 * IMPORTANT:
 * - Callers should never shape DB rows themselves.
 * - Any public-facing FFL structure changes should happen here.
 */
final class FFLRowMapper
{
    /**
     * Convert a DB row (ARRAY_A) into the normalized public shape.
     *
     * Public shape:
     *   [
     *     'ffl_number' => string,
     *     'name'       => string,
     *     'premise'    => ['street','city','state','zip'],
     *     'mailing'    => ['street','city','state','zip'],
     *     'phone'      => string,
     *   ]
     *
     * @param array<string,mixed> $row Raw DB row.
     * @return array<string,mixed> Canonical public representation.
     */
    public static function to_public(array $row): array
    {
        return [
            'ffl_number' => (string) ($row['ffl_number'] ?? ''),
            'name'       => (string) ($row['license_name'] ?? ''),
            'premise'    => [
                'street' => (string) ($row['premise_street'] ?? ''),
                'city'   => (string) ($row['premise_city'] ?? ''),
                'state'  => (string) ($row['premise_state'] ?? ''),
                'zip'    => (string) ($row['premise_zip'] ?? ''),
            ],
            'mailing'    => [
                'street' => (string) ($row['mail_street'] ?? ''),
                'city'   => (string) ($row['mail_city'] ?? ''),
                'state'  => (string) ($row['mail_state'] ?? ''),
                'zip'    => (string) ($row['mail_zip'] ?? ''),
            ],
            'phone'      => (string) ($row['voice_phone'] ?? ''),
        ];
    }

    /**
     * Normalize an FFL number consistently across the system.
     *
     * Current behavior:
     * - Trim whitespace
     * - Uppercase
     *
     * IMPORTANT:
     * - This must stay in sync with how FFL numbers are stored in the DB.
     */
    public static function normalize_ffl_number(string $ffl_number): string
    {
        return strtoupper(trim($ffl_number));
    }

    /**
     * Normalize a ZIP code or ZIP prefix for searching.
     *
     * Behavior:
     * - sanitize_text_field()
     * - remove all whitespace
     */
    public static function normalize_zip_prefix(string $zip): string
    {
        $zip = sanitize_text_field((string) $zip);
        $zip = preg_replace('/\s+/', '', $zip);
        return (string) $zip;
    }
}
