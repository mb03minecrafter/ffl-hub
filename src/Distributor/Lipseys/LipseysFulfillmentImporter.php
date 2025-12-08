<?php
namespace FFLHub\Distributor\Lipseys;

use FFLHub\Distributor\Lipseys\Tables\LipseysFulfillmentSchema;
use FFLHub\Distributor\Lipseys\Tables\LipseysFulfillmentTable;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Coordinator for importing Lipsey's catalog items into the STAGING table.
 */
class LipseysFulfillmentImporter {

    /**
     * Import an array of item arrays into the staging table.
     *
     * @param array<int,array<string,mixed>> $items
     * @return int Number of rows successfully inserted.
     */
    public static function import_items_array( array $items ): int {
        global $wpdb;

        

        $table_name = LipseysFulfillmentTable::get_staging_table_name();

        // Allow long-running import if needed.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        // Start with a clean staging table.
        $wpdb->query( "TRUNCATE TABLE {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $parser       = new LipseysFulfillmentParser();
        $batch_size   = 250;
        $batch_rows   = array();
        $total_import = 0;

        // NEW: track rows skipped due to missing/invalid UPC.
        $skipped_missing_upc = 0; // NEW

        // Single source of truth for column order from the schema helper.
        $columns  = LipseysFulfillmentSchema::get_insert_columns();
        $num_cols = count( $columns );

        $flush_batch = function () use ( &$batch_rows, &$total_import, $table_name, $wpdb, $columns, $num_cols ) {
            if ( empty( $batch_rows ) ) {
                return;
            }

            $placeholders = array();
            $values       = array();

            foreach ( $batch_rows as $row ) {
                $placeholders[] = '(' . implode( ', ', array_fill( 0, $num_cols, '%s' ) ) . ')';

                foreach ( $columns as $col ) {
                    $values[] = isset( $row[ $col ] ) ? $row[ $col ] : '';
                }
            }

            $sql = 'INSERT INTO ' . $table_name .
                ' (' . implode( ', ', $columns ) . ') VALUES ' .
                implode( ', ', $placeholders );

            $prepared = $wpdb->prepare( $sql, $values );
            $result   = $wpdb->query( $prepared );

            if ( $result !== false ) {
                $total_import += count( $batch_rows );
            }

            $batch_rows = array();
        };

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $row = $parser->parse_item( $item );

            // parse_item() already filters out non-drop-ship items.
            if ( $row === null ) {
                continue;
            }

            // NEW: enforce UPC requirement here too.
            $upc = isset( $row['upc'] ) ? trim( (string) $row['upc'] ) : '';
            if ( $upc === '' || strcasecmp( $upc, 'null' ) === 0 ) { // NEW
                $skipped_missing_upc++;                               // NEW
                continue;                                             // NEW
            }

            $batch_rows[] = $row;

            if ( count( $batch_rows ) >= $batch_size ) {
                $flush_batch();
            }
        }

        // Flush any remaining rows.
        $flush_batch();

        // Optional: store last-import info.
        if ( $total_import > 0 ) {
            update_option( 'fflhub_lipseys_fulfillment_last_import', current_time( 'mysql' ) );
            update_option( 'fflhub_lipseys_fulfillment_last_import_count', $total_import );
        }

        // NEW: log summary including skipped_missing_upc.
        error_log(
            sprintf(
                '[FFLHub][Lipseys Import] import_items_array(): items_in=%d, rows_inserted=%d, skipped_missing_upc=%d',
                count( $items ),
                $total_import,
                $skipped_missing_upc
            )
        ); // NEW

        return $total_import;
    }
}
