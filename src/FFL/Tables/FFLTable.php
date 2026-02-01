<?php
declare(strict_types=1);

namespace FFLHub\FFL\Tables;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Physical table manager for the FFL database table.
 *
 * Responsibilities:
 * - Resolve the fully-qualified table name (with WP prefix).
 * - Create or migrate the table schema via dbDelta().
 * - Expose schema-defined writable columns as a single source of truth.
 *
 * Explicitly NOT responsible for:
 * - Import/parsing logic
 * - Query/read repositories
 * - Business or validation logic
 */
final class FFLTable
{
    /**
     * Schema definition (authoritative source for columns and indexes).
     */
    private FFLSchema $schema;

    public function __construct(FFLSchema $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Get the fully-qualified table name (including $wpdb->prefix).
     */
    public function get_table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . $this->schema->get_base_table_key());
    }

    /**
     * Create or migrate the table using dbDelta().
     *
     * dbDelta quirks to be aware of:
     * - No inline SQL comments (--) inside CREATE TABLE.
     * - No blank lines inside the CREATE TABLE parentheses.
     * - One column or index definition per line.
     *
     * This method is safe to call repeatedly.
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

        foreach ($indexes as $indexDefinition) {
            $lines[] = (string) $indexDefinition;
        }

        // IMPORTANT: dbDelta requires a tightly formatted CREATE TABLE body.
        $sql = "CREATE TABLE {$table} (\n" .
            implode(",\n", $lines) .
            "\n) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Columns that are safe to update via partial updates or patch operations.
     *
     * Delegated directly from the schema to avoid duplication.
     *
     * @return string[]
     */
    public function get_writable_columns(): array
    {
        return $this->schema->get_writable_columns();
    }
}
