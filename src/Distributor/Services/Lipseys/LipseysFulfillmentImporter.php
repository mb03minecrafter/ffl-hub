<?php

namespace FFLHub\Distributor\Services\Lipseys;

use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Service for importing Lipsey's catalog items into the STAGING table
 * of a double-buffered fulfillment table.
 */
class LipseysFulfillmentImporterService
{
    private DoubleBufferedFulfillmentTable $table;

    public function __construct( DoubleBufferedFulfillmentTable $table ) {
        $this->table = $table;
    }

    /**
     * Import an array of item arrays into the staging table.
     *
     * @param array<int,array<string,mixed>> $items
     * @return int Number of rows successfully inserted.
     */
    public function import_items_array( array $items ): int
    {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        // Start with a clean staging table via the table helper.
        $this->table->truncate_staging();

        $parser             = new LipseysFulfillmentParser();
        $batch_size         = 250;
        $batch_rows         = [];
        $total_import       = 0;
        $skipped_missing_upc = 0;

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $row = $parser->parse_item( $item );

            // parse_item() already filters out non-drop-ship items.
            if ( $row === null ) {
                continue;
            }

            // Enforce UPC requirement.
            $upc = isset( $row['upc'] ) ? trim( (string) $row['upc'] ) : '';
            if ( $upc === '' || strcasecmp( $upc, 'null' ) === 0 ) {
                $skipped_missing_upc++;
                continue;
            }

            $batch_rows[] = $row;

            if ( count( $batch_rows ) >= $batch_size ) {
                // Let the table handle the actual batch INSERT.
                $inserted     = $this->table->insert_rows_into_staging( $batch_rows );
                $total_import += $inserted;
                $batch_rows    = [];
            }
        }

        // Flush any remaining rows.
        if ( ! empty( $batch_rows ) ) {
            $inserted     = $this->table->insert_rows_into_staging( $batch_rows );
            $total_import += $inserted;
        }

        if ( $total_import > 0 ) {
            update_option( 'fflhub_lipseys_fulfillment_last_import', current_time( 'mysql' ) );
            update_option( 'fflhub_lipseys_fulfillment_last_import_count', $total_import );
        }

        error_log(
            sprintf(
                '[FFLHub][Lipseys Import] import_items_array(): items_in=%d, rows_inserted=%d, skipped_missing_upc=%d',
                count( $items ),
                $total_import,
                $skipped_missing_upc
            )
        );

        return $total_import;
    }
}
