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
        license_name,
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
             ORDER BY premise_state, premise_city, license_name
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
     * Bulk upsert rows parsed from ATF TXT.
     *
     * Expected keys per row (11 columns):
     * - ffl_number, license_name
     * - premise_street, premise_city, premise_state, premise_zip
     * - mail_street, mail_city, mail_state, mail_zip
     * - voice_phone
     *
     * Notes:
     * - Uses multi-row INSERT ... ON DUPLICATE KEY UPDATE for throughput.
     * - Returns number of processed rows (attempted upserts), NOT DB affected rows.
     *   This makes reporting stable across inserts vs updates.
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

        /**
         * Flush current batch into the database.
         *
         * Counts processed rows (batch size) on success.
         */
        $flush = static function () use (&$batch, &$total, $table_name, $wpdb): void {
            if (empty($batch)) {
                return;
            }

            $placeholders = [];
            $values       = [];

            foreach ($batch as $row) {
                // 11 columns per row.
                $placeholders[] = '( %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s )';

                $values[] = (string) ($row['ffl_number'] ?? '');
                $values[] = (string) ($row['license_name'] ?? '');
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

            $sql = "
                INSERT INTO {$table_name}
                    ( ffl_number, license_name, premise_street, premise_city, premise_state,
                      premise_zip, mail_street, mail_city, mail_state, mail_zip, voice_phone )
                VALUES " . implode(', ', $placeholders) . "
                ON DUPLICATE KEY UPDATE
                    license_name   = VALUES(license_name),
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
            $result   = $wpdb->query($prepared);

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
}
