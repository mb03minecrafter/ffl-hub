<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Orders\Tables;

use FFLHub\Distributor\Services\Tables\DistributorTableInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Physical table manager for the Order Placement Jobs table.
 *
 * Responsibilities:
 *  - Create/migrate the jobs table using dbDelta().
 *  - Resolve fully-qualified table name with WP prefix.
 *  - Expose schema-defined writable columns (single source of truth for partial updates).
 *
 * Non-responsibilities:
 *  - Reads/queries (repositories)
 *  - Writes/patch semantics (writer)
 *  - Business logic (lifecycle/state machine/job runner)
 */
final class OrderPlacementJobsTable implements DistributorTableInterface
{
    /**
     * Schema definition provider (single source of truth for columns/indexes/writable list).
     */
    private OrderPlacementJobsSchema $schema;

    public function __construct(OrderPlacementJobsSchema $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Fully-qualified table name (including WP $wpdb->prefix).
     */
    public function get_table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . $this->schema->get_base_table_key());
    }

    /**
     * Create or migrate the table using dbDelta().
     *
     * Notes:
     * - dbDelta is sensitive to formatting; avoid blank lines and inline SQL comments
     *   inside the CREATE TABLE body.
     */
    public function createTables(): void
    {
        global $wpdb;

        $table   = $this->get_table_name();
        $charset = (string) $wpdb->get_charset_collate();

        $cols    = $this->schema->get_column_definitions();
        $indexes = $this->schema->get_index_definitions();

        $lines = [];

        foreach ($cols as $name => $def) {
            $lines[] = "{$name} {$def}";
        }

        foreach ($indexes as $idx_def) {
            $lines[] = (string) $idx_def;
        }

        // IMPORTANT: no blank lines in dbDelta CREATE TABLE body.
        $sql = "CREATE TABLE {$table} (\n" .
            implode(",\n", $lines) .
            "\n) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Columns that are safe to update via partial updates/patches.
     *
     * Writer/patch logic should use this to avoid schema drift.
     *
     * @return string[]
     */
    public function get_writable_columns(): array
    {
        return $this->schema->get_writable_columns();
    }

    /**
     * Interface requirement (if DistributorTableInterface includes UPC lookup).
     *
     * Not applicable for this table; jobs are keyed by (order_id, job_key).
     *
     * @param mixed $upc
     * @return array<string,mixed>|null
     */
    public function get_row_by_upc($upc): ?array
    {
        return null;
    }

    /**
     * @param array<int,string> $upcs
     * @return array<string,array<string,mixed>>
     */
    public function get_rows_by_upcs(array $upcs): array
    {
        return [];
    }
}
