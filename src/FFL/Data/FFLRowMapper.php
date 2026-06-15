<?php

declare(strict_types=1);

namespace FFLHub\FFL\Data;

use FFLHub\FFL\Tables\FFLTable;

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
     * Checkout can receive either the raw license number from the picker or a
     * browser/session-restored display label such as "FFL Number 1-23-...".
     * Store and validate only the canonical license number used by the FFL DB.
     */
    public static function normalize_ffl_number(string $ffl_number): string
    {
        $value = strtoupper(trim($ffl_number));
        if ($value === '') {
            return '';
        }

        if (preg_match('/\b(\d-\d{2}-\d{3}-\d{2}-[A-Z0-9]{2}-\d{5})\b/', $value, $matches)) {
            return $matches[1];
        }

        $compact = preg_replace('/[^A-Z0-9]/', '', $value);
        $compact = is_string($compact) ? $compact : '';

        if (preg_match('/^(\d)(\d{2})(\d{3})(\d{2})([A-Z0-9]{2})(\d{5})$/', $compact, $matches)) {
            return implode('-', array_slice($matches, 1));
        }

        return '';
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
