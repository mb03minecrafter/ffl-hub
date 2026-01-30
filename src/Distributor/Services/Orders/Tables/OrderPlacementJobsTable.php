<?php

namespace FFLHub\Distributor\Services\Orders\Tables;

use FFLHub\Distributor\Services\Tables\DistributorTableInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Physical table manager for Order Placement Jobs table.
 *
 * Responsibilities:
 *  - Create / migrate the jobs table using dbDelta().
 *  - Provide table name resolution helpers.
 *
 * Intentionally simple; business logic stays in repositories/writers.
 */
final class OrderPlacementJobsTable implements DistributorTableInterface
{
    /**
     * @var OrderPlacementJobsSchema
     */
    private $schema;

    public function __construct(OrderPlacementJobsSchema $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Fully-qualified table name (with WP prefix).
     */
    public function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . $this->schema->get_base_table_key();
    }

    /**
     * Create or migrate the table using dbDelta().
     */
    public function createTables(): void
    {
        global $wpdb;

        $table   = $this->get_table_name();
        $charset = $wpdb->get_charset_collate();

        $cols    = $this->schema->get_column_definitions();
        $indexes = $this->schema->get_index_definitions();

        $lines = [];

        foreach ($cols as $name => $def) {
            $lines[] = "{$name} {$def}";
        }

        foreach ($indexes as $idx_def) {
            $lines[] = $idx_def;
        }

        // IMPORTANT: no blank lines in dbDelta CREATE TABLE body
        $sql = "CREATE TABLE {$table} (\n" .
            implode(",\n", $lines) .
            "\n) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Convenience: expose writable columns via the table manager.
     *
     * @return string[]
     */
    public function get_writable_columns(): array
    {
        return $this->schema->get_writable_columns();
    }

    /**
     * Interface requirement (if you have this on DistributorTableInterface).
     *
     * Not applicable for order placement jobs, so always returns null.
     */
    public function get_row_by_upc($upc): ?array
    {
        return null;
    }
}
