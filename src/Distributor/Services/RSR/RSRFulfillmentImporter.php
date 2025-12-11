<?php

namespace FFLHub\Distributor\Services\RSR;

use FFLHub\Distributor\Services\RSR\Tables\RSRFulfillmentSchema;
use FFLHub\Distributor\Services\RSR\Tables\RSRFulfillmentTable;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Coordinator for importing the RSR fulfillment catalog into the STAGING table.
 *
 * It:
 *  - reads a local file (semicolons),
 *  - truncates the staging table,
 *  - bulk-inserts rows.
 *
 * You can call RSRFulfillmentImporter::import_from_downloaded_file()
 * (or legacy FFLHub_RSR_Fulfillment_Importer) after your FTP cron has fetched
 * the latest fulfillment-inv-new.txt.
 */
class RSRFulfillmentImporter
{
    /**
     * Convenience wrapper: import from the standard downloaded file location.
     *
     * @return int Number of rows inserted.
     */
    public static function import_from_downloaded_file(): int
    {
        $uploads   = wp_upload_dir();
        $base_dir  = trailingslashit( $uploads['basedir'] ) . 'fflhub-rsr';
        $file_path = trailingslashit( $base_dir ) . 'fulfillment-inv-new.txt';

        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            error_log( '[FFLHub] RSR fulfillment import: file missing or not readable at ' . $file_path );
            return 0;
        }

        // Route through main importer (which may use LOAD DATA).
        return self::import_fulfillment_file( $file_path );
    }

    /**
     * Check whether LOAD DATA LOCAL INFILE appears to be usable.
     *
     * @return bool
     */
    public static function can_use_load_data_local_infile(): bool
    {
        global $wpdb;

        // Check MySQL server variable.
        $row      = $wpdb->get_row( "SHOW VARIABLES LIKE 'local_infile'" );
        $mysql_ok = false;

        if ( $row && isset( $row->Value ) ) {
            $val      = strtolower( (string) $row->Value );
            $mysql_ok = ( $val === 'on' || $val === '1' );
        }

        // Check PHP ini for mysqli.
        $ini_val = ini_get( 'mysqli.allow_local_infile' );
        $php_ok  = ( $ini_val === '1' || strtolower( (string) $ini_val ) === 'on' );

        $result = ( $mysql_ok && $php_ok );

        error_log(
            sprintf(
                '[FFLHub][RSR Import][DEBUG] can_use_load_data_local_infile: mysql_ok=%s, php_ok=%s, result=%s',
                $mysql_ok ? 'true' : 'false',
                $php_ok ? 'true' : 'false',
                $result ? 'true' : 'false'
            )
        );

        return $result;
    }

    /**
     * Import from a specific file path into the staging table.
     * Uses LOAD DATA LOCAL INFILE when available; falls back to PHP batch importer.
     *
     * @param string $file_path
     * @return int Number of rows inserted.
     */
    public static function import_fulfillment_file( string $file_path ): int
    {
        // Try fast path first.
        if ( self::can_use_load_data_local_infile() ) {
            $rows = self::import_fulfillment_file_via_load_data( $file_path );
            if ( $rows >= 0 ) {
                return $rows;
            }

            // If LOAD DATA failed, log and fall back to PHP path.
            error_log(
                '[FFLHub][RSR Import] LOAD DATA path failed, falling back to PHP batch importer.'
            );
        }

        // Fallback (or if LOAD DATA disabled): PHP batch importer.
        return self::import_fulfillment_file_via_php( $file_path );
    }

    /**
     * FAST PATH: Import via MySQL's LOAD DATA LOCAL INFILE directly into the staging table.
     *
     * Returns:
     *  - >= 0 : number of rows inserted
     *  - -1   : failure (caller should fall back to PHP importer)
     *
     * @param string $file_path
     * @return int
     */
    private static function import_fulfillment_file_via_load_data( string $file_path ): int
    {
        global $wpdb;

        $t_start = microtime( true );

        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            error_log( '[FFLHub][RSR Import][LOAD DATA] file missing or not readable at ' . $file_path );
            return -1;
        }

        

        $table_name = RSRFulfillmentTable::get_staging_table_name();

        // Allow long-running import if needed.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        // Detect if first line is a header (same logic as parser).
        $ignore_lines = 0;
        $fh           = fopen(( $file_path ), 'r' );
        if ( $fh ) {
            $first_line = fgets( $fh );
            fclose( $fh );

            if ( $first_line !== false ) {
                $first_line = trim( $first_line );
                if ( $first_line !== '' ) {
                    $cols      = explode( ';', $first_line );
                    $first_col = isset( $cols[0] ) ? trim( $cols[0] ) : '';
                    if (
                        stripos( $first_col, 'RSR Stock' ) === 0 ||
                        stripos( $first_col, 'RSR#' ) === 0
                    ) {
                        $ignore_lines = 1;
                    }
                }
            }
        }

        // Clean staging table first.
        $wpdb->query( "TRUNCATE TABLE {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        /**
         * Map each semicolon-separated column into @c0..@c75, then SET real columns.
         */
        $sql = "
            LOAD DATA LOCAL INFILE %s
            INTO TABLE {$table_name}
            FIELDS TERMINATED BY ';'
            LINES TERMINATED BY '\\n'
            IGNORE {$ignore_lines} LINES
            (
                @c0,  @c1,  @c2,  @c3,  @c4,  @c5,  @c6,  @c7,  @c8,  @c9,
                @c10, @c11, @c12, @c13, @c14,
                @c15, @c16, @c17, @c18, @c19, @c20, @c21, @c22, @c23, @c24,
                @c25, @c26, @c27, @c28, @c29, @c30, @c31, @c32, @c33, @c34,
                @c35, @c36, @c37, @c38, @c39, @c40, @c41, @c42, @c43, @c44,
                @c45, @c46, @c47, @c48, @c49, @c50, @c51, @c52, @c53, @c54,
                @c55, @c56, @c57, @c58, @c59, @c60, @c61, @c62, @c63, @c64,
                @c65,
                @c66, @c67, @c68,
                @c69, @c70, @c71, @c72, @c73, @c74, @c75
            )
            SET
                upc                          = TRIM(TRIM(BOTH '\\r' FROM @c1)),
                rsr_stock_number             = TRIM(TRIM(BOTH '\\r' FROM @c0)),
                product_description          = TRIM(TRIM(BOTH '\\r' FROM @c2)),
                dept_number                  = TRIM(TRIM(BOTH '\\r' FROM @c3)),
                manufacturer_id              = TRIM(TRIM(BOTH '\\r' FROM @c4)),
                retail_msrp                  = TRIM(TRIM(BOTH '\\r' FROM @c5)),
                distributor_price            = TRIM(TRIM(BOTH '\\r' FROM @c6)),
                product_weight_oz            = TRIM(TRIM(BOTH '\\r' FROM @c7)),
                inventory_quantity           = TRIM(TRIM(BOTH '\\r' FROM @c8)),
                model                        = TRIM(TRIM(BOTH '\\r' FROM @c9)),
                full_manufacturer_name       = TRIM(TRIM(BOTH '\\r' FROM @c10)),
                manufacturer_part_number     = TRIM(TRIM(BOTH '\\r' FROM @c11)),
                allocation_status            = TRIM(TRIM(BOTH '\\r' FROM @c12)),
                expanded_product_description = TRIM(TRIM(BOTH '\\r' FROM @c13)),
                image_name                   = TRIM(TRIM(BOTH '\\r' FROM @c14)),

                ship_ak  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c15))) = 'Y' THEN '1' ELSE '0' END,
                ship_al  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c16))) = 'Y' THEN '1' ELSE '0' END,
                ship_ar  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c17))) = 'Y' THEN '1' ELSE '0' END,
                ship_az  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c18))) = 'Y' THEN '1' ELSE '0' END,
                ship_ca  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c19))) = 'Y' THEN '1' ELSE '0' END,
                ship_co  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c20))) = 'Y' THEN '1' ELSE '0' END,
                ship_ct  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c21))) = 'Y' THEN '1' ELSE '0' END,
                ship_dc  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c22))) = 'Y' THEN '1' ELSE '0' END,
                ship_de  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c23))) = 'Y' THEN '1' ELSE '0' END,
                ship_fl  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c24))) = 'Y' THEN '1' ELSE '0' END,
                ship_ga  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c25))) = 'Y' THEN '1' ELSE '0' END,
                ship_hi  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c26))) = 'Y' THEN '1' ELSE '0' END,
                ship_ia  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c27))) = 'Y' THEN '1' ELSE '0' END,
                ship_id  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c28))) = 'Y' THEN '1' ELSE '0' END,
                ship_il  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c29))) = 'Y' THEN '1' ELSE '0' END,
                ship_in  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c30))) = 'Y' THEN '1' ELSE '0' END,
                ship_ks  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c31))) = 'Y' THEN '1' ELSE '0' END,
                ship_ky  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c32))) = 'Y' THEN '1' ELSE '0' END,
                ship_la  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c33))) = 'Y' THEN '1' ELSE '0' END,
                ship_ma  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c34))) = 'Y' THEN '1' ELSE '0' END,
                ship_md  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c35))) = 'Y' THEN '1' ELSE '0' END,
                ship_me  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c36))) = 'Y' THEN '1' ELSE '0' END,
                ship_mi  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c37))) = 'Y' THEN '1' ELSE '0' END,
                ship_mn  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c38))) = 'Y' THEN '1' ELSE '0' END,
                ship_mo  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c39))) = 'Y' THEN '1' ELSE '0' END,
                ship_ms  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c40))) = 'Y' THEN '1' ELSE '0' END,
                ship_mt  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c41))) = 'Y' THEN '1' ELSE '0' END,
                ship_nc  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c42))) = 'Y' THEN '1' ELSE '0' END,
                ship_nd  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c43))) = 'Y' THEN '1' ELSE '0' END,
                ship_ne  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c44))) = 'Y' THEN '1' ELSE '0' END,
                ship_nh  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c45))) = 'Y' THEN '1' ELSE '0' END,
                ship_nj  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c46))) = 'Y' THEN '1' ELSE '0' END,
                ship_nm  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c47))) = 'Y' THEN '1' ELSE '0' END,
                ship_nv  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c48))) = 'Y' THEN '1' ELSE '0' END,
                ship_ny  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c49))) = 'Y' THEN '1' ELSE '0' END,
                ship_oh  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c50))) = 'Y' THEN '1' ELSE '0' END,
                ship_ok  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c51))) = 'Y' THEN '1' ELSE '0' END,
                ship_or  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c52))) = 'Y' THEN '1' ELSE '0' END,
                ship_ph  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c53))) = 'Y' THEN '1' ELSE '0' END,
                ship_ri  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c54))) = 'Y' THEN '1' ELSE '0' END,
                ship_sc  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c55))) = 'Y' THEN '1' ELSE '0' END,
                ship_sd  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c56))) = 'Y' THEN '1' ELSE '0' END,
                ship_tn  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c57))) = 'Y' THEN '1' ELSE '0' END,
                ship_tx  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c58))) = 'Y' THEN '1' ELSE '0' END,
                ship_ut  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c59))) = 'Y' THEN '1' ELSE '0' END,
                ship_va  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c60))) = 'Y' THEN '1' ELSE '0' END,
                ship_vt  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c61))) = 'Y' THEN '1' ELSE '0' END,
                ship_wa  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c62))) = 'Y' THEN '1' ELSE '0' END,
                ship_wi  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c63))) = 'Y' THEN '1' ELSE '0' END,
                ship_wv  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c64))) = 'Y' THEN '1' ELSE '0' END,
                ship_wy  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c65))) = 'Y' THEN '1' ELSE '0' END,

                ground_shipments_only = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c66))) = 'Y' THEN '1' ELSE '0' END,
                adult_sig_required    = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c67))) = 'Y' THEN '1' ELSE '0' END,
                blocked_from_dropship = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c68))) = 'Y' THEN '1' ELSE '0' END,

                date_entered          = TRIM(TRIM(BOTH '\\r' FROM @c69)),
                retail_map            = TRIM(TRIM(BOTH '\\r' FROM @c70)),
                image_disclaimer      = TRIM(TRIM(BOTH '\\r' FROM @c71)),
                shipping_length_in    = TRIM(TRIM(BOTH '\\r' FROM @c72)),
                shipping_width_in     = TRIM(TRIM(BOTH '\\r' FROM @c73)),
                shipping_height_in    = TRIM(TRIM(BOTH '\\r' FROM @c74)),
                reserved_future       = TRIM(TRIM(BOTH '\\r' FROM @c75))
        ";

        $prepared    = $wpdb->prepare( $sql, $file_path );
        $t_sql_start = microtime( true );
        $result      = $wpdb->query( $prepared );
        $t_sql_ms    = ( microtime( true ) - $t_sql_start ) * 1000;

        if ( $result === false ) {
            error_log(
                '[FFLHub][RSR Import][LOAD DATA] query failed: ' . $wpdb->last_error
            );
            return -1;
        }

        $wpdb->query( "DELETE FROM {$table_name} WHERE upc IS NULL OR upc = ''" );

        // Count rows actually loaded.
        $rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

        if ( $rows > 0 ) {
            update_option( 'fflhub_rsr_fulfillment_last_import', current_time( 'mysql' ), false );
            update_option( 'fflhub_rsr_fulfillment_last_import_count', $rows, false );
        }

        $t_total_ms = ( microtime( true ) - $t_start ) * 1000;

        error_log(
            sprintf(
                '[FFLHub][RSR Import] import_fulfillment_file_via_load_data(): total=%.2f ms (sql=%.2f ms), rows=%d, ignore_lines=%d',
                $t_total_ms,
                $t_sql_ms,
                $rows,
                $ignore_lines
            )
        );

        return $rows;
    }

    /**
     * ORIGINAL PHP BATCH IMPORTER (fallback if LOAD DATA is unavailable or fails).
     *
     * @param string $file_path
     * @return int
     */
    private static function import_fulfillment_file_via_php( string $file_path ): int
    {
        global $wpdb;

        $t_import_start = microtime( true );
        $t_parse_total  = 0.0;
        $t_flush_total  = 0.0;

        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return 0;
        }

        

        $table_name = RSRFulfillmentTable::get_staging_table_name();

        // Allow long-running import if needed.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            error_log( '[FFLHub] RSR fulfillment import: could not fopen ' . $file_path );
            return 0;
        }

        // Start with a clean staging table.
        $wpdb->query( "TRUNCATE TABLE {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $parser = new RSRFulfillmentParser();

        // Bigger batch = fewer INSERT statements.
        $batch_size          = 1000;
        $batch_rows          = array();
        $total_import        = 0;
        $line_number         = 0;
        $skipped_missing_upc = 0;

        // Single source of truth for column order from the schema helper.
        $columns     = RSRFulfillmentSchema::get_insert_columns();
        $num_cols    = count( $columns );
        $column_list = implode( ', ', $columns );

        // Precompute placeholders and insert prefix.
        $row_placeholder = '(' . implode( ', ', array_fill( 0, $num_cols, '%s' ) ) . ')';
        $insert_prefix   = 'INSERT INTO ' . $table_name . ' (' . $column_list . ') VALUES ';

        // Wrap all inserts in a single transaction to avoid per-batch commit overhead.
        $wpdb->query( 'START TRANSACTION' );

        $flush_batch = function () use (
            &$batch_rows,
            &$total_import,
            $wpdb,
            $columns,
            $num_cols,
            &$t_flush_total,
            $row_placeholder,
            $insert_prefix
        ) {
            if ( empty( $batch_rows ) ) {
                return;
            }

            $t0 = microtime( true );

            $placeholders = array();
            $values       = array();

            foreach ( $batch_rows as $row ) {
                // Reuse the precomputed row placeholder.
                $placeholders[] = $row_placeholder;

                foreach ( $columns as $col ) {
                    $values[] = isset( $row[ $col ] ) ? $row[ $col ] : '';
                }
            }

            $sql = $insert_prefix . implode( ', ', $placeholders );

            // One big prepared statement per batch.
            $prepared = $wpdb->prepare( $sql, $values );
            $result   = $wpdb->query( $prepared );

            if ( $result !== false ) {
                $total_import += count( $batch_rows );
            } else {
                error_log( '[FFLHub][RSR Import] Batch INSERT failed: ' . $wpdb->last_error );
            }

            $batch_rows = array();

            $t_flush_total += ( microtime( true ) - $t0 );
        };

        // Read + parse loop.
        while ( ( $line = fgets( $handle ) ) !== false ) {
            $line_number++;

            $t0  = microtime( true );
            $row = $parser->parse_line( $line, $line_number );
            $t1  = microtime( true );

            // Accumulate parsing (includes fgets + parse_line).
            $t_parse_total += ( $t1 - $t0 );

            if ( $row === null ) {
                continue;
            }

            // Skip rows where UPC is missing/empty/'null'.
            $upc = isset( $row['upc'] ) ? trim( (string) $row['upc'] ) : '';
            if ( $upc === '' || strcasecmp( $upc, 'null' ) === 0 ) {
                $skipped_missing_upc++;
                continue;
            }

            $batch_rows[] = $row;

            if ( count( $batch_rows ) >= $batch_size ) {
                $flush_batch();
            }
        }

        fclose( $handle );

        // Flush any remaining rows.
        $flush_batch();

        // Commit all inserts as a single transaction.
        $wpdb->query( 'COMMIT' );

        // Optional: store last-import info.
        if ( $total_import > 0 ) {
            update_option( 'fflhub_rsr_fulfillment_last_import', current_time( 'mysql' ), false );
            update_option( 'fflhub_rsr_fulfillment_last_import_count', $total_import, false );
        }

        // Timing logs for deeper insight.
        $t_import_total_ms = ( microtime( true ) - $t_import_start ) * 1000;
        $t_parse_ms        = $t_parse_total * 1000;
        $t_flush_ms        = $t_flush_total * 1000;

        error_log(
            sprintf(
                '[FFLHub][RSR Import] import_fulfillment_file_via_php(): total=%.2f ms, parse+loop=%.2f ms, db_flush=%.2f ms, rows=%d, skipped_missing_upc=%d',
                $t_import_total_ms,
                $t_parse_ms,
                $t_flush_ms,
                $total_import,
                $skipped_missing_upc
            )
        );

        return $total_import;
    }
}

