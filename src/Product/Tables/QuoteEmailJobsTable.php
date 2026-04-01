<?php
declare(strict_types=1);

namespace FFLHub\Product\Tables;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Physical table manager for customer quote email jobs.
 */
final class QuoteEmailJobsTable
{
    private QuoteEmailJobsSchema $schema;

    public function __construct(QuoteEmailJobsSchema $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Fully-qualified table name (includes WordPress prefix).
     */
    public function get_table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . $this->schema->get_base_table_key());
    }

    /**
     * Create or migrate the table with dbDelta.
     */
    public function createTables(): void
    {
        global $wpdb;

        $table   = $this->get_table_name();
        $charset = (string) $wpdb->get_charset_collate();

        $columns = $this->schema->get_column_definitions();
        $indexes = $this->schema->get_index_definitions();

        $lines = [];

        foreach ($columns as $name => $definition) {
            $lines[] = "{$name} {$definition}";
        }

        foreach ($indexes as $index_definition) {
            $lines[] = (string) $index_definition;
        }

        $sql = "CREATE TABLE {$table} (\n" .
            implode(",\n", $lines) .
            "\n) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Columns safe for partial updates.
     *
     * @return string[]
     */
    public function get_writable_columns(): array
    {
        return $this->schema->get_writable_columns();
    }
}

