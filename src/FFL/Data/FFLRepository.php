<?php

declare(strict_types=1);

namespace FFLHub\FFL\Data;

use FFLHub\FFL\Tables\FFLTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository/gateway for all FFL table reads and writes.
 *
 * IMPORTANT:
 * - ONLY this class should touch $wpdb for the FFL table.
 * - Callers must pass an FFLTable instance (explicit dependency; no hidden globals).
 * - Shape/normalization is delegated to FFLRowMapper.
 *
 * Notes:
 * - Methods return "public-shaped" arrays (via FFLRowMapper::to_public()).
 * - Bulk writes favor throughput over per-row reporting.
 */
final class FFLRepository
{
    /**
     * Columns used for reads (kept centralized to avoid drift).
     *
     * Stored as a SQL fragment string to keep $wpdb->prepare usage simple.
     */
    private const SELECT_COLUMNS = "
    ffl_number,
    ffl_expiration,
    license_name,
    business_name,
    premise_street,
    premise_city,
    premise_state,
    premise_zip,
    mail_street,
    mail_city,
    mail_state,
    mail_zip,
    voice_phone
";

    /**
     * Find a single FFL by exact number.
     *
     * @return array<string,mixed>|null Public-shaped record or null if not found.
     */
    public static function find_by_number(FFLTable $table, string $ffl_number): ?array
    {
        $ffl_number = FFLRowMapper::normalize_ffl_number($ffl_number);
        if ($ffl_number === '') {
            return null;
        }

        global $wpdb;

        $table_name = $table->get_table_name();

        $sql = $wpdb->prepare(
            "SELECT " . self::SELECT_COLUMNS . " FROM {$table_name} WHERE ffl_number = %s LIMIT 1",
            $ffl_number
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!$row || !is_array($row)) {
            return null;
        }

        return FFLRowMapper::to_public($row);
    }

    /**
     * Search by ZIP or ZIP prefix across premise_zip and mail_zip.
     *
     * @return array<int, array<string,mixed>> List of public-shaped records.
     */
    public static function search_by_zip_prefix(FFLTable $table, string $zip, int $limit = 50): array
    {
        $zip = FFLRowMapper::normalize_zip_prefix($zip);

        $limit = max(1, min($limit, 200));
        if ($zip === '') {
            return [];
        }

        global $wpdb;

        $table_name = $table->get_table_name();
        $like       = $zip . '%';

        $sql = $wpdb->prepare(
            "SELECT " . self::SELECT_COLUMNS . "
             FROM {$table_name}
             WHERE premise_zip LIKE %s OR mail_zip LIKE %s
             ORDER BY premise_state, premise_city, COALESCE(NULLIF(business_name, ''), license_name), license_name
             LIMIT %d",
            $like,
            $like,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = FFLRowMapper::to_public($row);
            }
        }

        return $out;
    }

    /**
     * Atomically replace the complete FFL snapshot.
     *
     * The old rows remain visible to other connections until the replacement
     * commits. Any delete, insert, or row-count failure rolls the transaction
     * back so a failed import cannot leave the registry empty or partial.
     *
     * @param array<int,array<string,string>> $rows
     */
    public static function replace_all(FFLTable $table, array $rows, int $batch_size = 500): int
    {
        if (empty($rows)) {
            return 0;
        }

        global $wpdb;

        $table_name = $table->get_table_name();
        $expected    = count($rows);

        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Could not start the FFL import transaction.');
        }

        try {
            if ($wpdb->query("DELETE FROM {$table_name}") === false) {
                throw new \RuntimeException('Could not clear the existing FFL snapshot: ' . (string) $wpdb->last_error);
            }

            $processed = self::bulk_upsert($table, $rows, $batch_size);
            if ($processed !== $expected) {
                throw new \RuntimeException(
                    sprintf(
                        'FFL snapshot insert was incomplete: expected %d rows, processed %d. %s',
                        $expected,
                        $processed,
                        (string) $wpdb->last_error
                    )
                );
            }

            $stored = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            if ($stored !== $expected) {
                throw new \RuntimeException(
                    sprintf('FFL snapshot row count mismatch: expected %d rows, stored %d.', $expected, $stored)
                );
            }

            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Could not commit the FFL snapshot: ' . (string) $wpdb->last_error);
            }

            return $stored;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Bulk upsert normalized rows parsed from an ATF export.
     *
     * Expected keys per row (13 columns):
     * - ffl_number, ffl_expiration, license_name, business_name
     * - premise_street, premise_city, premise_state, premise_zip
     * - mail_street, mail_city, mail_state, mail_zip
     * - voice_phone
     *
     * Notes:
     * - Uses multi-row INSERT ... ON DUPLICATE KEY UPDATE for throughput.
     * - Returns number of processed rows (attempted upserts), NOT DB affected rows.
     *
     * @param array<int, array<string,string>> $rows
     */
    public static function bulk_upsert(FFLTable $table, array $rows, int $batch_size = 500): int
    {
        if (empty($rows)) {
            return 0;
        }

        // Keep batches reasonable to avoid giant prepared statements.
        $batch_size = max(50, min($batch_size, 2000));

        global $wpdb;

        $table_name = $table->get_table_name();

        $total = 0;
        $batch = [];

        $flush = static function () use (&$batch, &$total, $table_name, $wpdb): void {
            if (empty($batch)) {
                return;
            }

            $placeholders = [];
            $values       = [];

            foreach ($batch as $row) {
                // 13 columns per row.
                // For ffl_expiration (DATE NULL): we pass either a YYYY-MM-DD string or NULL.
                $placeholders[] = '( %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s )';

                $values[] = (string) ($row['ffl_number'] ?? '');

                $exp = trim((string) ($row['ffl_expiration'] ?? ''));
                // Let NULL land as NULL for DATE column (avoid '' -> 0000-00-00 coercion).
                $values[] = ($exp !== '' ? $exp : null);

                $values[] = (string) ($row['license_name'] ?? '');
                $values[] = (string) ($row['business_name'] ?? '');

                $values[] = (string) ($row['premise_street'] ?? '');
                $values[] = (string) ($row['premise_city'] ?? '');
                $values[] = (string) ($row['premise_state'] ?? '');
                $values[] = (string) ($row['premise_zip'] ?? '');

                $values[] = (string) ($row['mail_street'] ?? '');
                $values[] = (string) ($row['mail_city'] ?? '');
                $values[] = (string) ($row['mail_state'] ?? '');
                $values[] = (string) ($row['mail_zip'] ?? '');

                $values[] = (string) ($row['voice_phone'] ?? '');
            }

            // IMPORTANT:
            // - For ffl_expiration we want NULL to stay NULL.
            // - $wpdb->prepare does not have a %d/%f/%s form that cleanly preserves NULL for %s,
            //   so we do a post-pass replacement for the "NULL" sentinel below.
            //
            // Strategy:
            // - We insert a unique sentinel string for null dates, then replace it with literal NULL.
            $NULL_SENTINEL = '__FFLHUB_NULL_DATE__';

            for ($i = 0; $i < count($values); $i++) {
                if ($values[$i] === null) {
                    $values[$i] = $NULL_SENTINEL;
                }
            }

            $sql = "
            INSERT INTO {$table_name}
                ( ffl_number, ffl_expiration, license_name, business_name,
                  premise_street, premise_city, premise_state, premise_zip,
                  mail_street, mail_city, mail_state, mail_zip,
                  voice_phone )
            VALUES " . implode(', ', $placeholders) . "
            ON DUPLICATE KEY UPDATE
                ffl_expiration = VALUES(ffl_expiration),
                license_name   = VALUES(license_name),
                business_name  = VALUES(business_name),
                premise_street = VALUES(premise_street),
                premise_city   = VALUES(premise_city),
                premise_state  = VALUES(premise_state),
                premise_zip    = VALUES(premise_zip),
                mail_street    = VALUES(mail_street),
                mail_city      = VALUES(mail_city),
                mail_state     = VALUES(mail_state),
                mail_zip       = VALUES(mail_zip),
                voice_phone    = VALUES(voice_phone)
        ";

            $prepared = $wpdb->prepare($sql, $values);

            // Replace the quoted sentinel with literal NULL.
            // We must cover both single-quote and double-quote cases defensively.
            $prepared = str_replace("'" . $NULL_SENTINEL . "'", 'NULL', (string) $prepared);
            $prepared = str_replace('"' . $NULL_SENTINEL . '"', 'NULL', (string) $prepared);

            $result = $wpdb->query($prepared);

            if ($result !== false) {
                $total += count($batch);
            }

            $batch = [];
        };

        foreach ($rows as $row) {
            $batch[] = $row;

            if (count($batch) >= $batch_size) {
                $flush();
            }
        }

        $flush();

        return $total;
    }


    public static function get_expiration_by_number(FFLTable $table, string $ffl_number): string
    {
        $normalized = FFLRowMapper::normalize_ffl_number($ffl_number);
        if ($normalized === '') {
            return '';
        }

        global $wpdb;

        $table_name = $table->get_table_name();

        $sql = $wpdb->prepare(
            "SELECT ffl_expiration FROM {$table_name} WHERE ffl_number = %s LIMIT 1",
            $normalized
        );

        $expiration = self::normalize_expiration_value($wpdb->get_var($sql));
        if ($expiration !== '') {
            return $expiration;
        }

        $compact = self::normalize_ffl_number_without_punctuation($normalized);
        if ($compact === '') {
            return '';
        }

        $sql = $wpdb->prepare(
            "SELECT ffl_expiration
             FROM {$table_name}
             WHERE REPLACE(REPLACE(REPLACE(UPPER(ffl_number), '-', ''), ' ', ''), '.', '') = %s
             LIMIT 1",
            $compact
        );

        return self::normalize_expiration_value($wpdb->get_var($sql));
    }

    private static function normalize_ffl_number_without_punctuation(string $ffl_number): string
    {
        $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($ffl_number)));

        return is_string($normalized) ? $normalized : '';
    }

    /**
     * DB DATE should already be YYYY-MM-DD, but normalize defensively.
     */
    private static function normalize_expiration_value($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }
}
