<?php

namespace FFLHub\Distributor\Services\Lipseys\Tables;

use FFLHub\Distributor\Services\Tables\DistributorTableInterface;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Physical table manager for the Lipsey's shipment ingestion table.
 *
 * Responsibilities:
 *  - Create / migrate the shipments table using dbDelta().
 *  - Provide table name resolution helpers.
 *
 * This is intentionally SIMPLE:
 *  - Single table (no swapping, no staging).
 *  - Append-only usage model.
 */
final class LipseysShipmentTable implements DistributorTableInterface
{
    /**
     * @var LipseysShipmentSchema
     */
    private $schema;

    public function __construct(LipseysShipmentSchema $schema)
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
     * Create or migrate the shipments table.
     *
     * Uses dbDelta so schema changes are applied safely.
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

        $sql = "CREATE TABLE {$table} (\n" .
            implode(",\n", $lines) .
            "\n) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta($sql);
    }

    /**
     * Interface requirement.
     *
     * Not applicable for shipments (no UPC concept),
     * so always returns null.
     */
    public function get_row_by_upc($upc): ?array
    {
        return null;
    }



    /**
     * Bulk insert shipment rows.
     *
     * Uses INSERT IGNORE for idempotency (PK = po_number + tracking_number).
     *
     * @param array<int,array<string,mixed>> $rows
     * @param int                           $batch_size
     *
     * @return int Rows actually inserted.
     */
    public function insert_rows(array $rows, int $batch_size = 250): int
    {
        global $wpdb;

        if (empty($rows)) {
            return 0;
        }

        $table   = $this->get_table_name();
        $columns = $this->schema->get_insert_columns();

        if (empty($columns)) {
            return 0;
        }

        $num_cols        = count($columns);
        $column_list     = implode(', ', $columns);
        $row_placeholder = '(' . implode(', ', array_fill(0, $num_cols, '%s')) . ')';

        $insert_prefix = 'INSERT IGNORE INTO ' . $table . ' (' . $column_list . ') VALUES ';

        $total_inserted     = 0;
        $batch_placeholders = [];
        $batch_values       = [];
        $batch_count        = 0;

        $flush_batch = function () use (
            &$batch_placeholders,
            &$batch_values,
            &$total_inserted,
            $insert_prefix,
            $wpdb
        ) {
            if (empty($batch_placeholders)) {
                return;
            }

            $sql      = $insert_prefix . implode(', ', $batch_placeholders);
            $prepared = $wpdb->prepare($sql, $batch_values);
            $result   = $wpdb->query($prepared);

            if ($result !== false) {
                $total_inserted += (int) $result;
            }

            $batch_placeholders = [];
            $batch_values       = [];
        };

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $batch_placeholders[] = $row_placeholder;

            foreach ($columns as $col) {
                $batch_values[] = isset($row[$col]) ? (string) $row[$col] : '';
            }

            $batch_count++;

            if ($batch_count >= $batch_size) {
                $flush_batch();
                $batch_count = 0;
            }
        }

        if (! empty($batch_placeholders)) {
            $flush_batch();
        }

        return (int) $total_inserted;
    }


    /**
     * Fetch all shipment rows for a PO number.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_rows_by_po(string $po_number): array
    {
        global $wpdb;

        $table = $this->get_table_name();
        if (! $table) {
            return [];
        }

        $sql = "
        SELECT *
        FROM {$table}
        WHERE po_number = %s
        ORDER BY ship_date ASC, tracking_number ASC
    ";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $po_number),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }
}
