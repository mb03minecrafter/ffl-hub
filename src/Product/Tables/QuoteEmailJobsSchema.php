<?php
declare(strict_types=1);

namespace FFLHub\Product\Tables;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schema definition for customer quote email jobs.
 *
 * This table stores queued quote-email jobs generated from the
 * "Email for Quote" product flow.
 */
final class QuoteEmailJobsSchema
{
    /**
     * Base table key without the WordPress table prefix.
     */
    private const BASE_TABLE_KEY = 'fflhub_customer_quote_email_jobs';

    public function get_base_table_key(): string
    {
        return self::BASE_TABLE_KEY;
    }

    /**
     * Columns safe for partial updates.
     *
     * @return string[]
     */
    public function get_writable_columns(): array
    {
        return [
            'request_first_name',
            'request_last_name',
            'request_email',
            'quote_upc',
            'quote_product_name',
            'random_delay_minutes',
            'email_sent',
        ];
    }

    /**
     * Column definitions for dbDelta CREATE TABLE.
     *
     * @return array<string,string>
     */
    public function get_column_definitions(): array
    {
        return [
            'id'                   => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'request_first_name'   => "VARCHAR(100) NOT NULL DEFAULT ''",
            'request_last_name'    => "VARCHAR(100) NOT NULL DEFAULT ''",
            'request_email'        => "VARCHAR(190) NOT NULL DEFAULT ''",
            'quote_upc'            => "VARCHAR(64) NOT NULL DEFAULT ''",
            'quote_product_name'   => "VARCHAR(255) NOT NULL DEFAULT ''",
            'submitted_at'         => 'DATETIME NOT NULL',
            'random_delay_minutes' => 'SMALLINT UNSIGNED NOT NULL',
            'email_sent'           => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
        ];
    }

    /**
     * Index definitions for dbDelta CREATE TABLE.
     *
     * @return string[]
     */
    public function get_index_definitions(): array
    {
        return [
            'PRIMARY KEY (id)',
            'KEY idx_quote_upc (quote_upc)',
            'KEY idx_request_email (request_email)',
            'KEY idx_email_sent_submitted_at (email_sent, submitted_at)',
        ];
    }

    /**
     * Insert-safe columns (excludes auto-increment primary key).
     *
     * @return string[]
     */
    public function get_insert_columns(): array
    {
        $columns = array_keys($this->get_column_definitions());

        return array_values(
            array_filter(
                $columns,
                static fn(string $column): bool => $column !== 'id'
            )
        );
    }
}
