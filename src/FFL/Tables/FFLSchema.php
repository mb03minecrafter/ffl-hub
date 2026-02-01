<?php
declare(strict_types=1);

namespace FFLHub\FFL\Tables;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for the FFL table schema.
 *
 * Responsibilities:
 * - Define table name key (prefix applied elsewhere).
 * - Declare columns, indexes, and writable fields.
 * - Provide safe lists for inserts and partial updates.
 *
 * IMPORTANT (dbDelta quirks):
 * - Do NOT place SQL comments (--) inside CREATE TABLE bodies.
 * - Avoid blank lines inside CREATE TABLE parentheses.
 * - Keep exactly one column or index definition per line.
 */
final class FFLSchema
{
    /**
     * Base table key (without $wpdb->prefix).
     *
     * Final table name is built by the table class.
     */
    private const BASE_TABLE_KEY = 'fflhub_ffls';

    /**
     * Get the base table key.
     */
    public function get_base_table_key(): string
    {
        return self::BASE_TABLE_KEY;
    }

    /**
     * Columns that may be safely updated via partial UPDATE statements.
     *
     * Explicitly excludes:
     * - id (auto-increment primary key)
     * - ffl_number (natural unique identifier)
     *
     * @return string[]
     */
    public function get_writable_columns(): array
    {
        return [
            'license_name',
            'premise_street',
            'premise_city',
            'premise_state',
            'premise_zip',
            'mail_street',
            'mail_city',
            'mail_state',
            'mail_zip',
            'voice_phone',
        ];
    }

    /**
     * Column definitions.
     *
     * Format:
     *   column_name => SQL fragment (no trailing commas)
     *
     * @return array<string,string>
     */
    public function get_column_definitions(): array
    {
        return [
            'id'             => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'ffl_number'     => 'VARCHAR(64) NOT NULL',
            'license_name'   => 'VARCHAR(255) NOT NULL',
            'premise_street' => 'VARCHAR(255) NOT NULL',
            'premise_city'   => 'VARCHAR(128) NOT NULL',
            'premise_state'  => 'CHAR(2) NOT NULL',
            'premise_zip'    => 'VARCHAR(10) NOT NULL',
            'mail_street'    => 'VARCHAR(255) NULL',
            'mail_city'      => 'VARCHAR(128) NULL',
            'mail_state'     => 'CHAR(2) NULL',
            'mail_zip'       => 'VARCHAR(10) NULL',
            'voice_phone'    => 'VARCHAR(32) NULL',
        ];
    }

    /**
     * Index definitions.
     *
     * dbDelta requires these to be provided as raw SQL lines
     * inside the CREATE TABLE statement.
     *
     * @return string[]
     */
    public function get_index_definitions(): array
    {
        return [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uq_ffl_number (ffl_number)',
            'KEY idx_premise_zip (premise_zip)',
            'KEY idx_premise_state (premise_state)',
        ];
    }

    /**
     * Columns used for INSERT statements.
     *
     * Excludes auto-increment primary key.
     *
     * @return string[]
     */
    public function get_insert_columns(): array
    {
        $columns = array_keys($this->get_column_definitions());

        return array_values(
            array_filter(
                $columns,
                static fn (string $col): bool => $col !== 'id'
            )
        );
    }
}
