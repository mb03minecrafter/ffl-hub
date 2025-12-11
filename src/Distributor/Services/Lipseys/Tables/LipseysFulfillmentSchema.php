<?php

namespace FFLHub\Distributor\Services\Lipseys\Tables;

use FFLHub\Distributor\Services\Tables\FulfillmentSchemaInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Single source of truth for the Lipsey's fulfillment table schema.
 */
class LipseysFulfillmentSchema implements FulfillmentSchemaInterface
{
    public const BASE_TABLE_KEY    = 'fflhub_lipseys_fulfillment';
    public const LIVE_TABLE_OPTION = 'fflhub_lipseys_fulfillment_live_table';

    public static function get_base_table_key(): string
    {
        return self::BASE_TABLE_KEY;
    }

    public static function get_live_table_option_name(): string
    {
        return self::LIVE_TABLE_OPTION;
    }

    public static function get_column_definitions(): array
    {
        return array(
            // Core identifiers
            'upc'                 => 'VARCHAR(32)   NOT NULL',
            'lipseys_item_number' => 'VARCHAR(64)   NOT NULL',

            // Quantity and Pricing
            'inventory_quantity'  => 'VARCHAR(32)   NULL',
            'allocation_status'   => 'VARCHAR(64)   NULL',
            'distributor_price'   => 'VARCHAR(32)   NULL',
            'retail_map'          => 'VARCHAR(32)   NULL',
            'retail_msrp'         => 'VARCHAR(32)   NULL',

            // Catalog data
            'product_description'     => 'TEXT          NOT NULL',
            'model'                   => 'VARCHAR(128)  NULL',
            'manufacturer'            => 'VARCHAR(255)  NULL',
            'mfg_model_number'        => 'VARCHAR(128)  NULL',
            'caliber_gauge'           => 'VARCHAR(64)   NULL',
            'item_type'               => 'VARCHAR(128)  NULL',
            'action'                  => 'VARCHAR(128)  NULL',
            'barrel_length'           => 'VARCHAR(64)   NULL',
            'capacity'                => 'VARCHAR(64)   NULL',
            'finish'                  => 'VARCHAR(128)  NULL',
            'overall_length'          => 'VARCHAR(64)   NULL',
            'receiver'                => 'VARCHAR(255)  NULL',
            'safety'                  => 'VARCHAR(255)  NULL',
            'sights'                  => 'VARCHAR(255)  NULL',
            'stock_frame_grips'       => 'VARCHAR(255)  NULL',
            'magazine'                => 'VARCHAR(255)  NULL',
            'product_weight_oz'       => 'VARCHAR(32)   NULL',
            'image_name'              => 'VARCHAR(255)  NULL',

            // Bound book & regulatory-ish stuff
            'bound_book_manufacturer' => 'VARCHAR(255)  NULL',
            'bound_book_model'        => 'VARCHAR(255)  NULL',
            'bound_book_type'         => 'VARCHAR(64)   NULL',
            'ffl_required'            => 'TINYINT(1) NOT NULL DEFAULT 0',
            'sot_required'            => 'TINYINT(1) NOT NULL DEFAULT 0',

            // Grouping / marketing-ish
            'item_group'        => 'VARCHAR(128)  NULL',
            'family'            => 'VARCHAR(128)  NULL',
            'finish_type'       => 'VARCHAR(128)  NULL',
            'frame'             => 'VARCHAR(128)  NULL',
            'country_of_origin' => 'VARCHAR(64)   NULL',

            // Shipping / dimensions
            'shipping_weight'    => 'DECIMAL(10,2) NULL',
            'shipping_length_in' => 'VARCHAR(32)   NULL',
            'shipping_width_in'  => 'VARCHAR(32)   NULL',
            'shipping_height_in' => 'VARCHAR(32)   NULL',
        );
    }

    public static function get_index_definitions(): array
    {
        return array(
            'PRIMARY KEY  (upc)',
            'KEY lipseys_item_number (lipseys_item_number)',
        );
    }

    public static function get_insert_columns(): array
    {
        $columns = array_keys( self::get_column_definitions() );

        return array_values(
            array_filter(
                $columns,
                static function (string $col): bool {
                    return $col !== 'id';
                }
            )
        );
    }

    public static function get_pricing_quantity_update_columns(): array
    {
        return array(
            'lipseys_item_number',
            'upc',
            'mfg_model_number',
            'inventory_quantity',
            'allocation_status',
            'distributor_price',
            'retail_map',
        );
    }
}
