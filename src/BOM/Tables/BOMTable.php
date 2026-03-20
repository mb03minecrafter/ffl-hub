<?php
declare(strict_types=1);

namespace FFLHub\BOM\Tables;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Physical table manager for BOM rows.
 */
final class BOMTable
{
    private BOMSchema $schema;

    public function __construct(BOMSchema $schema)
    {
        $this->schema = $schema;
    }

    public function get_table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . $this->schema->get_base_table_key());
    }

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

        $sql = "CREATE TABLE {$table} (\n" .
            implode(",\n", $lines) .
            "\n) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * @return string[]
     */
    public function get_writable_columns(): array
    {
        return $this->schema->get_writable_columns();
    }
}
