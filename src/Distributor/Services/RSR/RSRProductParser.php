<?php

namespace FFLHub\Distributor\Services\RSR;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parser for the RSR product catalog file (rsrinventory-new.txt).
 *
 * The file is semicolon-delimited, e.g.:
 * 17912WH-1-SBL-R;816161020234;1791 2 WAY IWB ...;...;20210420;48.99;;7.50;6.50;2.00;Y;;
 *
 * Column layout (simplified):
 *  0  RSR Stock Number
 *  1  UPC
 *  2  Product Description
 *  3  Dept #
 *  4  Manufacturer Id.
 *  5  Retail Price      (MSRP)
 *  6  RSR Regular Price (Distributor cost)
 *  7  Product Weight (oz)
 *  8  Inventory Quantity
 *  9  Model
 * 10  Full Manufacturer Name
 * 11  Manufacturer Part Number
 * 12  Allocated / Closeout / Deleted
 * 13  Expanded Product Description
 * 14  Image Name
 * 15..(15 + 51 - 1)  State flags: AK, AL, AR, ..., PH, RI, ..., WY (51 columns)
 * next 3 columns:
 *   Ground Shipments Only
 *   Adult Sig Required
 *   Blocked from Dropship
 * then:
 *   Date Entered
 *   Retail MAP
 *   Image Disclaimer
 *   Shipping Length (inches)
 *   Shipping Width (inches)
 *   Shipping Height (inches)
 *   Reserved for Future Use
 *
 * Some files have a couple of extra trailing semicolons; we simply ignore columns
 * beyond the ones we care about.
 */
class RSRProductParser
{
    /**
     * Map one data line into a DB row.
     *
     * @param string $line        Raw line from the file.
     * @param int    $line_number 1-based line number (for header detection).
     * @return array<string,string>|null Row keyed to match DB columns, or null to skip.
     */
    public function parse_line( string $line, int $line_number ): ?array
    {
        $line = trim( $line );
        if ( $line === '' ) {
            return null;
        }

        $columns = explode( ';', $line );

        // Skip header row if present.
        $first_col = isset( $columns[0] ) ? trim( $columns[0] ) : '';
        if (
            $line_number === 1 &&
            ( stripos( $first_col, 'RSR Stock' ) === 0 || stripos( $first_col, 'RSR#' ) === 0 )
        ) {
            return null;
        }

        // Require at least enough columns to reach date / map / dims.
        if ( count( $columns ) < 70 ) {
            return null;
        }

        $get = static function ( array $cols, int $idx ): string {
            return isset( $cols[ $idx ] ) ? trim( (string) $cols[ $idx ] ) : '';
        };

        // Base fixed columns.
        $rsr_stock_number = $get( $columns, 0 );
        if ( $rsr_stock_number === '' ) {
            // No stock number = unusable row.
            return null;
        }

        $upc                    = $get( $columns, 1 );
        $product_description    = $get( $columns, 2 );
        $dept_number            = $get( $columns, 3 );
        $manufacturer_id        = $get( $columns, 4 );
        $retail_msrp            = $get( $columns, 5 ); // maps to retail_msrp in schema
        $distributor_price      = $get( $columns, 6 ); // maps to distributor_price in schema
        $shipping_weight        = $get( $columns, 7 );
        $inventory_quantity     = $get( $columns, 8 );
        $model                  = $get( $columns, 9 );
        $manufacturer           = $get( $columns, 10 );
        $manufacturer_part_no   = $get( $columns, 11 );
        $allocation_status      = $get( $columns, 12 );
        $expanded_product_desc  = $get( $columns, 13 );
        $image_name             = $get( $columns, 14 );

        // State codes in order.
        $state_codes = array(
            'AK',
            'AL',
            'AR',
            'AZ',
            'CA',
            'CO',
            'CT',
            'DC',
            'DE',
            'FL',
            'GA',
            'HI',
            'IA',
            'ID',
            'IL',
            'IN',
            'KS',
            'KY',
            'LA',
            'MA',
            'MD',
            'ME',
            'MI',
            'MN',
            'MO',
            'MS',
            'MT',
            'NC',
            'ND',
            'NE',
            'NH',
            'NJ',
            'NM',
            'NV',
            'NY',
            'OH',
            'OK',
            'OR',
            'PH',
            'RI',
            'SC',
            'SD',
            'TN',
            'TX',
            'UT',
            'VA',
            'VT',
            'WA',
            'WI',
            'WV',
            'WY',
        );
        $state_base_index = 15; // Where AK starts.

        $state_flags = array();
        foreach ( $state_codes as $offset => $code ) {
            $raw = strtoupper( $get( $columns, $state_base_index + $offset ) );
            $state_flags[ 'ship_' . strtolower( $code ) ] = ( $raw === 'Y' ) ? '1' : '0';
        }

        // After the 51 state columns come 3 shipping flags + the rest.
        $ground_index  = $state_base_index + count( $state_codes ); // 15 + 51 = 66
        $adult_index   = $ground_index + 1;                         // 67
        $blocked_index = $ground_index + 2;                         // 68

        $date_index    = $ground_index + 3;                         // 69
        $map_index     = $ground_index + 4;                         // 70
        $imgdisc_index = $ground_index + 5;                         // 71
        $len_index     = $ground_index + 6;                         // 72
        $wid_index     = $ground_index + 7;                         // 73
        $ht_index      = $ground_index + 8;                         // 74
        $res_index     = $ground_index + 9;                         // 75

        $ground_shipments_raw = strtoupper( $get( $columns, $ground_index ) );
        $adult_sig_raw        = strtoupper( $get( $columns, $adult_index ) );
        $blocked_raw          = strtoupper( $get( $columns, $blocked_index ) );

        $ground_shipments_only = ( $ground_shipments_raw === 'Y' ) ? '1' : '0';
        $adult_sig_required    = ( $adult_sig_raw === 'Y' ) ? '1' : '0';
        $is_blocked_dropship   = ( $blocked_raw === 'Y' );
        $dropship_enabled      = $is_blocked_dropship ? '0' : '1';
        $dropship_block_reason = $is_blocked_dropship ? 'blocked_from_dropship' : '';
        $dept_number_numeric   = (int) preg_replace( '/\D+/', '', $dept_number );
        $sot_required          = ( $dept_number_numeric === 6 ) ? '1' : '0';

        $date_entered     = $get( $columns, $date_index );
        $retail_map       = $get( $columns, $map_index );
        $image_disclaimer = $get( $columns, $imgdisc_index );
        $shipping_length  = $get( $columns, $len_index );
        $shipping_width   = $get( $columns, $wid_index );
        $shipping_height  = $get( $columns, $ht_index );
        $reserved_future  = $get( $columns, $res_index );

        // Build row keyed to the CURRENT schema (minus id).
        $row = array(
            'upc'                          => $upc,
            'rsr_stock_number'             => $rsr_stock_number,

            'product_description'          => $product_description,
            'dept_number'                  => $dept_number,
            'manufacturer_id'              => $manufacturer_id,

            // Pricing (new names)
            'inventory_quantity'           => $inventory_quantity,
            'allocation_status'            => $allocation_status,
            'distributor_price'            => $distributor_price,
            'retail_map'                   => $retail_map,
            'retail_msrp'                  => $retail_msrp,

            // Catalog
            'shipping_weight'              => $shipping_weight,
            'sot_required'                 => $sot_required,
            'model'                        => $model,
            'manufacturer'                 => $manufacturer,
            'manufacturer_part_number'     => $manufacturer_part_no,
            'expanded_product_description' => $expanded_product_desc,
            'image_name'                   => $image_name,
        );

        // Merge state flags.
        $row = array_merge( $row, $state_flags );

        // Shipping / misc.
        $row['ground_shipments_only'] = $ground_shipments_only;
        $row['adult_sig_required']    = $adult_sig_required;
        $row['dropship_enabled']      = $dropship_enabled;
        $row['dropship_block_reason'] = $dropship_block_reason;
        $row['date_entered']          = $date_entered;
        $row['image_disclaimer']      = $image_disclaimer;
        $row['shipping_length_in']    = $shipping_length;
        $row['shipping_width_in']     = $shipping_width;
        $row['shipping_height_in']    = $shipping_height;
        $row['reserved_future']       = $reserved_future;

        return $row;
    }
}
