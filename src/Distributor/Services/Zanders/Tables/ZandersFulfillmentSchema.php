<?php

namespace FFLHub\Distributor\Services\Zanders\Tables;

use FFLHub\Distributor\Services\Tables\FulfillmentSchemaInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Zanders fulfillment schema (available inventory + tier pricing).
 *
 * Source CSV columns (as provided):
 * available,category,desc1,desc2,itemnumber,manufacturer,mfgpnumber,msrp,
 * price1,price2,price3,qty1,qty2,qty3,upc,weight,serialized,mapprice
 */
class ZandersFulfillmentSchema implements FulfillmentSchemaInterface
{
    public const BASE_TABLE_KEY    = 'fflhub_zanders_fulfillment';
    public const LIVE_TABLE_OPTION = 'fflhub_zanders_fulfillment_live_table';

    public function get_base_table_key(): string
    {
        return self::BASE_TABLE_KEY;
    }

    public function get_live_table_option_name(): string
    {
        return self::LIVE_TABLE_OPTION;
    }

    public function get_column_definitions(): array
    {
        return array(
            // Primary key
            'upc' => 'VARCHAR(32) NOT NULL',

            // Core identifiers
            'zanders_item_number'      => 'VARCHAR(64)  NOT NULL',
            'manufacturer'             => 'VARCHAR(255) NULL',
            'manufacturer_part_number' => 'VARCHAR(128) NULL',

            // Catalog / descriptions
            'category' => 'VARCHAR(255) NULL',
            'desc1'    => 'TEXT         NULL',
            'desc2'    => 'TEXT         NULL',

            // Quantity and Pricing
            // RSR inventory_quantity == Zanders available
            'available'     => 'VARCHAR(32) NULL',
            'msrp'          => 'VARCHAR(32) NULL',
            'map_price'     => 'VARCHAR(32) NULL',
            'price_1'       => 'VARCHAR(32) NULL',
            'price_2'       => 'VARCHAR(32) NULL',
            'price_3'       => 'VARCHAR(32) NULL',
            'bulk_qty_1'    => 'VARCHAR(32) NULL',
            'bulk_qty_2'    => 'VARCHAR(32) NULL',
            'bulk_qty_3'    => 'VARCHAR(32) NULL',

            // Logistics / misc
            'weight_lb'   => 'VARCHAR(32) NULL',
            'serialized'  => 'TINYINT(1) NOT NULL DEFAULT 0',

            // Reserved for future Zanders fields / transformations without schema changes
            'reserved_future' => 'VARCHAR(255) NULL',
        );
    }

    public function get_index_definitions(): array
    {
        return array(
            'PRIMARY KEY  (upc)',
            'KEY zanders_item_number (zanders_item_number)',
            'KEY manufacturer_part_number (manufacturer_part_number)',
        );
    }

    public function get_insert_columns(): array
    {
        $all = array_keys( self::get_column_definitions() );

        return array_values(
            array_filter(
                $all,
                static fn( string $col ) => $col !== 'id'
            )
        );
    }

    /**
     * “Quantity update” should only touch the fast-moving inventory number.
     * For Zanders, that is the "available" column.
     */
    public function get_quantity_update_columns(): array
    {
        return array(
            'available',
        );
    }
}
