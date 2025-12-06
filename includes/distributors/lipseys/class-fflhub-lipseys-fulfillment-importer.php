<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parser for the Lipsey's catalog feed items.
 *
 * Each $item is a single assoc array from LipseysClient->Catalog(), e.g.:
 *
 *  [itemNo]               => GLPX4350201RMOS
 *  [description1]         => G43X 9MM BLK 3.39" MOS RAIL
 *  [description2]         => FRONT RAIL
 *  [upc]                  => 764503046629
 *  ...
 */
class FFLHub_Lipseys_Fulfillment_Parser {

    /**
     * Map one Lipsey item array into a DB row keyed to match
     * the Lipsey's fulfillment table schema (minus id).
     *
     * IMPORTANT: Only returns a row if the item is *drop ship enabled*
     * (`canDropship` truthy). Otherwise returns null.
     *
     * @param array<string,mixed> $item
     * @return array<string,string>|null Row keyed to match DB columns, or null to skip.
     */
    public function parse_item( array $item ): ?array {
        // Basic required fields.
        $item_no = $this->get_string( $item, 'itemNo' );
        $upc     = $this->get_string( $item, 'upc' );

        if ( $item_no === '' && $upc === '' ) {
            // No identifiers, skip this line entirely.
            return null;
        }

        // Drop-ship filter: we only keep items that are drop-ship enabled.
        $can_dropship_raw = $this->get_string( $item, 'canDropship' );
        $can_dropship     = $this->to_flag( $can_dropship_raw );


        
        if ( $can_dropship !== '1' ) {
            // Not drop ship enabled => do not import this item at all.
            return null;
        }

        // Descriptions and basic identifiers.
        $description1 = $this->get_string( $item, 'description1' );
        $description2 = $this->get_string( $item, 'description2' );

        // Combine description1 + description2 into single product_description.
        $product_description = trim(
            $description1 . ( $description2 !== '' ? ' ' . $description2 : '' )
        );

        if ( $product_description === '' ) {
            // Fallback to something minimal if needed.
            $product_description = $item_no;
        }

        $model        = $this->get_string( $item, 'model' );
        $manufacturer = $this->get_string( $item, 'manufacturer' );
        $mfg_part     = $this->get_string( $item, 'manufacturerModelNo' );
        $family       = $this->get_string( $item, 'family' );
        $item_group   = $this->get_string( $item, 'itemGroup' );

        // Specs.
        $caliber        = $this->get_string( $item, 'caliberGauge' );
        $type           = $this->get_string( $item, 'type' );
        $item_type      = $this->get_string( $item, 'itemType' );
        $action         = $this->get_string( $item, 'action' );
        $barrel_length  = $this->get_string( $item, 'barrelLength' );
        $capacity       = $this->get_string( $item, 'capacity' );
        $finish         = $this->get_string( $item, 'finish' );
        $finish_type    = $this->get_string( $item, 'finishType' );
        $overall_length = $this->get_string( $item, 'overallLength' );
        $receiver       = $this->get_string( $item, 'receiver' );
        $safety         = $this->get_string( $item, 'safety' );
        $sights         = $this->get_string( $item, 'sights' );
        $stock_grips    = $this->get_string( $item, 'stockFrameGrips' );
        $magazine       = $this->get_string( $item, 'magazine' );
        $weight         = $this->get_string( $item, 'weight' );          // e.g. "18.6 oz."
        $shipping_weight = $this->get_string( $item, 'shippingWeight' ); // e.g. "2.9"
        $frame          = $this->get_string( $item, 'frame' );
        $country        = $this->get_string( $item, 'countryOfOrigin' );

        // Bound-book fields.
        $bb_mfg   = $this->get_string( $item, 'boundBookManufacturer' );
        $bb_model = $this->get_string( $item, 'boundBookModel' );
        $bb_type  = $this->get_string( $item, 'boundBookType' );

        // Inventory / status.
        $qty       = $this->get_string( $item, 'quantity' );
        $allocated = $this->get_string( $item, 'allocated' );

        $ffl_required_raw = $this->get_string( $item, 'fflRequired' );
        $sot_required_raw = $this->get_string( $item, 'sotRequired' );

        // Pricing.
        $msrp         = $this->get_string( $item, 'msrp' );
        $price        = $this->get_string( $item, 'price' );
        $currentPrice = $this->get_string( $item, 'currentPrice' );
        $retailMap    = $this->get_string( $item, 'retailMap' );

        // Package dimensions.
        $packageLength = $this->get_string( $item, 'packageLength' );
        $packageWidth  = $this->get_string( $item, 'packageWidth' );
        $packageHeight = $this->get_string( $item, 'packageHeight' );

        // Interpret remaining booleans as '0'/'1' strings for TINYINT(1) columns.
        $ffl_required = $this->to_flag( $ffl_required_raw );
        $sot_required = $this->to_flag( $sot_required_raw );

        // item_type: prefer itemType, fall back to type.
        $final_item_type = $item_type !== '' ? $item_type : $type;

        // Build the row keyed to the Lipsey's fulfillment table schema.
        return array(
            
            'upc'                     => $upc,
// Core identifiers
            'lipseys_item_number'     => $item_no,
            // Quantity and Pricing (subset of schema)
            'inventory_quantity'      => $qty,
            'allocation_status'       => $allocated,
            'distributor_price'       => $currentPrice,
            'retail_map'              => $retailMap,
            'retail_msrp'             => $msrp,

            // Catalog data
            'product_description'     => $product_description,
            'model'                   => $model,
            'manufacturer'            => $manufacturer,
            'mfg_model_number'        => $mfg_part,
            'caliber_gauge'           => $caliber,
            'item_type'               => $final_item_type,
            'action'                  => $action,
            'barrel_length'           => $barrel_length,
            'capacity'                => $capacity,
            'finish'                  => $finish,
            'overall_length'          => $overall_length,
            'receiver'                => $receiver,
            'safety'                  => $safety,
            'sights'                  => $sights,
            'stock_frame_grips'       => $stock_grips,
            'magazine'                => $magazine,
            'product_weight_oz'       => $weight,
            'image_name'              => $this->get_string( $item, 'imageName' ),

            // Bound book & regulatory-ish stuff
            'bound_book_manufacturer' => $bb_mfg,
            'bound_book_model'        => $bb_model,
            'bound_book_type'         => $bb_type,
            'ffl_required'            => $ffl_required,
            'sot_required'            => $sot_required,

            // Grouping / marketing-ish
            'item_group'              => $item_group,
            'family'                  => $family,
            'finish_type'             => $finish_type,
            'frame'                   => $frame,
            'country_of_origin'       => $country,

            // Shipping / dimensions
            'shipping_weight'         => $shipping_weight,
            'shipping_length_in'      => $packageLength,
            'shipping_width_in'       => $packageWidth,
            'shipping_height_in'      => $packageHeight,
        );
    }

    /**
     * Safe string helper.
     *
     * @param array<string,mixed> $item
     * @param string              $key
     * @return string
     */
    protected function get_string( array $item, string $key ): string {
        if ( ! isset( $item[ $key ] ) ) {
            return '';
        }
        $val = $item[ $key ];
        if ( is_scalar( $val ) ) {
            return trim( (string) $val );
        }
        return '';
    }

    /**
     * Convert a raw value into '0' or '1'.
     *
     * Accepts '1', 1, true, 'Y', 'YES', 'TRUE' as true.
     *
     * @param string $raw
     * @return string
     */
    protected function to_flag( string $raw ): string {
        $raw_upper = strtoupper( trim( $raw ) );
        if (
            $raw_upper === '1' ||
            $raw_upper === 'Y' ||
            $raw_upper === 'YES' ||
            $raw_upper === 'TRUE'
        ) {
            return '1';
        }
        return '0';
    }
}

/**
 * Coordinator for importing Lipsey's catalog items into the STAGING table.
 */
class FFLHub_Lipseys_Fulfillment_Importer {

    /**
     * Import an array of item arrays into the staging table.
     *
     * @param array<int,array<string,mixed>> $items
     * @return int Number of rows successfully inserted.
     */
    public static function import_items_array( array $items ): int {
        global $wpdb;

        if ( ! class_exists( 'FFLHub_Lipseys_Fulfillment_Table' ) ) {
            error_log( '[FFLHub] Lipseys fulfillment import: FFLHub_Lipseys_Fulfillment_Table class not found.' );
            return 0;
        }

        if ( ! class_exists( 'FFLHub_Lipseys_Fulfillment_Schema' ) ) {
            error_log( '[FFLHub] Lipseys fulfillment import: FFLHub_Lipseys_Fulfillment_Schema class not found.' );
            return 0;
        }

        $table_name = FFLHub_Lipseys_Fulfillment_Table::get_staging_table_name();

        // Allow long-running import if needed.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        // Start with a clean staging table.
        $wpdb->query( "TRUNCATE TABLE {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $parser       = new FFLHub_Lipseys_Fulfillment_Parser();
        $batch_size   = 250;
        $batch_rows   = array();
        $total_import = 0;

        // NEW: track rows skipped due to missing/invalid UPC.
        $skipped_missing_upc = 0; // NEW

        // Single source of truth for column order from the schema helper.
        $columns  = FFLHub_Lipseys_Fulfillment_Schema::get_insert_columns();
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
