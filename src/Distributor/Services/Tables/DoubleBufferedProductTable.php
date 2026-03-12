<?php

namespace FFLHub\Distributor\Services\Tables;

use FFLHub\Util\DebugLogUtil;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Generic double-buffered product table:
 *  - <prefix><base>_v1
 *  - <prefix><base>_v2
 *
 * "Live" vs "staging" is controlled by an option on the schema.
 */
class DoubleBufferedProductTable implements DistributorTableInterface
{
    /**
     * Optional option name used to record last swap time.
     * e.g. "fflhub_rsr_fulfillment_last_swap"
     *
     * @var string
     */
    protected $SWAP_TIMESTAMP_OPTION;

    /**
     * Schema used to create our tables (columns, indexes, etc).
     *
     * @var ProductSchemaInterface
     */
    protected $productSchema;

    /**
     * @param ProductSchemaInterface $productSchema
     * @param string                     $SWAP_TIMESTAMP_OPTION
     */
    public function __construct(ProductSchemaInterface $productSchema, string $SWAP_TIMESTAMP_OPTION)
    {
        $this->productSchema         = $productSchema;
        $this->SWAP_TIMESTAMP_OPTION = $SWAP_TIMESTAMP_OPTION;
    }

    public function get_schema(): ProductSchemaInterface
    {
        return $this->productSchema;
    }

    /**
     * Fully-qualified table name for a given suffix (v1 or v2).
     */
    public function get_table_name_with_suffix(string $suffix): string
    {
        global $wpdb;

        return $wpdb->prefix . $this->productSchema->get_base_table_key() . '_' . $suffix;
    }

    /**
     * Returns the name of the live table (full name with prefix).
     */
    public function get_live_table_name(): string
    {
        $default     = $this->get_table_name_with_suffix('v1');
        $option_name = $this->productSchema->get_live_table_option_name();
        $stored      = get_option($option_name);

        if (is_string($stored) && $stored !== '') {
            $v1 = $this->get_table_name_with_suffix('v1');
            $v2 = $this->get_table_name_with_suffix('v2');

            // Already a full table name?
            if ($stored === $v1 || $stored === $v2) {
                return $stored;
            }

            // Legacy simple 'v1' / 'v2' case: normalize.
            if ($stored === 'v1' || $stored === 'v2') {
                $normalized = $this->get_table_name_with_suffix($stored);
                update_option($option_name, $normalized);
                return $normalized;
            }
        }

        // Fallback: default to v1 and store that.
        update_option($option_name, $default);
        return $default;
    }

    /**
     * Returns the staging table name (the “other” one).
     */
    public function get_staging_table_name(): string
    {
        $live = $this->get_live_table_name();
        $v1   = $this->get_table_name_with_suffix('v1');
        $v2   = $this->get_table_name_with_suffix('v2');

        return ($live === $v1) ? $v2 : $v1;
    }

    /**
     * Swap live and staging tables by flipping the live-table option.
     *
     * @return string New live table name after swap.
     */
    public function swap_live_and_staging(): string
    {
        $option_name = $this->productSchema->get_live_table_option_name();

        $current_live  = $this->get_live_table_name();
        $current_stage = $this->get_staging_table_name();

        update_option($option_name, $current_stage);

        if ($this->SWAP_TIMESTAMP_OPTION !== '') {
            update_option(
                $this->SWAP_TIMESTAMP_OPTION,
                current_time('mysql')
            );
        }

        return $current_stage;
    }

    /**
     * Create both v1 and v2 tables (if missing).
     *
     * We only run dbDelta for v1; v2 is cloned via CREATE TABLE ... LIKE ...
     */
    public function createTables(): void
    {

        global $wpdb;

        $table_v1 = $this->get_table_name_with_suffix('v1');
        $table_v2 = $this->get_table_name_with_suffix('v2');
        $charset  = $wpdb->get_charset_collate();

        $existing_v1 = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table_v1)
        );
        $existing_v2 = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table_v2)
        );

        // If both exist, just normalize the live option and bail.
        if ($existing_v1 === $table_v1 && $existing_v2 === $table_v2) {
            $this->get_live_table_name();
            return;
        }

        $cols    = $this->productSchema->get_column_definitions();
        $indexes = $this->productSchema->get_index_definitions();

        $lines = array();

        foreach ($cols as $name => $def) {
            $lines[] = "{$name} {$def}";
        }

        foreach ($indexes as $idx_def) {
            $lines[] = $idx_def;
        }

        $create_v1 = "CREATE TABLE {$table_v1} (\n" . implode(",\n", $lines) . "\n) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Use dbDelta for v1 so WP can manage future schema changes.
        dbDelta($create_v1);

        // v2: clone structure + indexes from v1 if v2 does not exist yet.
        if ($existing_v2 !== $table_v2) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("CREATE TABLE {$table_v2} LIKE {$table_v1}");
        }

        // Normalize the live option.
        $this->get_live_table_name();
    }

    /**
     * Truncate the staging table.
     *
     * Used by importers before bulk-inserting into staging.
     */
    public function truncate_staging(): void
    {
        global $wpdb;

        $table = $this->get_staging_table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("TRUNCATE TABLE {$table}");
    }

    /**
     * Bulk insert rows into the staging table in batches.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string>|null         $columns If null, uses schema->get_insert_columns().
     * @param int                            $batch_size Number of rows per INSERT batch.
     *
     * @return int Number of rows successfully inserted.
     */
    public function insert_rows_into_staging(array $rows, ?array $columns = null, int $batch_size = 250): int
    {
        global $wpdb;

        $t_start   = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;

        if (empty($rows)) {
            return 0;
        }

        $table = $this->get_staging_table_name();

        // Resolve columns from schema if not explicitly provided.
        if ($columns === null) {
            $columns = $this->productSchema->get_insert_columns();
        }

        if (empty($columns)) {
            return 0;
        }

        $num_cols        = count($columns);
        $column_list     = implode(', ', $columns);
        $row_placeholder = '(' . implode(', ', array_fill(0, $num_cols, '%s')) . ')';

        // IMPORTANT: using INSERT IGNORE means duplicates are silently ignored.
        $insert_prefix = 'INSERT IGNORE INTO ' . $table . ' (' . $column_list . ') VALUES ';

        $total_inserted     = 0; // actual affected rows from MySQL
        $batch_placeholders = [];
        $batch_values       = [];
        $batch_count        = 0;

        $batch_flushes  = 0;
        $batch_failures = 0;

        $flush_batch = function () use (
            &$batch_placeholders,
            &$batch_values,
            &$total_inserted,
            &$batch_flushes,
            &$batch_failures,
            $insert_prefix,
            $wpdb,
            $table
        ) {
            if (empty($batch_placeholders) || empty($batch_values)) {
                return;
            }

            $batch_flushes++;

            $sql      = $insert_prefix . implode(', ', $batch_placeholders);
            $prepared = $wpdb->prepare($sql, $batch_values);
            $result   = $wpdb->query($prepared);

            if ($result !== false) {
                // With INSERT IGNORE, $result is the number of rows actually inserted.
                $total_inserted += (int) $result;

                $this->log_debug(
                    sprintf(
                        '[FFLHub][DoubleBufferedProductTable] Batch INSERT OK: table=%s, batch_rows=%d, inserted=%d',
                        $table,
                        count($batch_placeholders),
                        (int) $result
                    )
                );
            } else {
                $batch_failures++;
                $this->log_debug(
                    '[FFLHub][DoubleBufferedProductTable] Batch INSERT FAILED: table=' . $table . ' error=' . (string) $wpdb->last_error
                );
            }

            // Reset batch.
            $batch_placeholders = [];
            $batch_values       = [];
        };

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $batch_placeholders[] = $row_placeholder;

            // Push values in column order, defaulting to empty string if missing.
            foreach ($columns as $col) {
                $batch_values[] = isset($row[$col]) ? $row[$col] : '';
            }

            $batch_count++;

            if ($batch_count >= $batch_size) {
                $flush_batch();
                $batch_count = 0;
            }
        }

        // Flush any remaining rows.
        if (! empty($batch_placeholders)) {
            $flush_batch();
        }

        // High-level summary (gated)
        $elapsed_ms = (microtime(true) - $t_start) * 1000;
        $this->log_debug(
            sprintf(
                '[FFLHub][DoubleBufferedProductTable] insert_rows_into_staging(): input_rows=%d, inserted_rows=%d, batch_size=%d, batches=%d, failures=%d, elapsed=%.2f ms',
                count($rows),
                (int) $total_inserted,
                (int) $batch_size,
                (int) $batch_flushes,
                (int) $batch_failures,
                $elapsed_ms
            )
        );

        if ($mem_start > 0) {
            $this->log_memory_summary($mem_start);
        }

        return (int) $total_inserted;
    }


    /**
     * Thin wrappers for DB transactions.
     * Useful for cron jobs or importers doing multi-step updates.
     */
    public function begin_transaction(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('START TRANSACTION');
    }

    public function commit_transaction(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('COMMIT');
    }

    public function rollback_transaction(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('ROLLBACK');
    }

    /**
     * Interface-satisfying method name (camelCase).
     * Delegates to the snake_case helper below.
     *
     * @param string $upc
     *
     * @return array<string,mixed>|null
     */
    public function getRowByUPC($upc): ?array
    {
        return $this->get_row_by_upc($upc);
    }

    /**
     * Convenience helper for fetching a row from the LIVE table by UPC.
     *
     * @param string $upc
     *
     * @return array<string,mixed>|null
     */
    public function get_row_by_upc($upc): ?array
    {
        $table = $this->get_live_table_name();
        if (! $table) {
            return null;
        }

        global $wpdb;

        $sql = "SELECT * FROM {$table} WHERE upc = %s LIMIT 1";
        $row = $wpdb->get_row(
            $wpdb->prepare($sql, $upc),
            ARRAY_A
        );

        if (! is_array($row) || empty($row)) {
            return null;
        }

        return $row;
    }




    private function log_debug(string $message): void
    {
        DebugLogUtil::log('FFLHUB_CRON_DEBUG', '[FFLHub][DoubleBufferedProductTable]', $message);
    }

    private function log_memory_summary(int $mem_start, string $prefix = '[FFLHub][DoubleBufferedProductTable]'): void
    {
        $mem_end = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
        if ($mem_start > 0 && $mem_end > 0) {
            $this->log_debug(
                sprintf(
                    '%s Memory usage summary: start=%d KB, end=%d KB, delta=%+d KB',
                    $prefix,
                    (int) round($mem_start / 1024),
                    (int) round($mem_end / 1024),
                    (int) round(($mem_end - $mem_start) / 1024)
                )
            );
        }
    }
}
