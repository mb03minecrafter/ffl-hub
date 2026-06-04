<?php

namespace FFLHub\Distributor\Services\Zanders\Tables;

use FFLHub\Distributor\Services\Tables\ProductSchemaInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Zanders fulfillment schema (available inventory + tier pricing).
 *
 * Source CSV columns (as provided):
 * available,category,desc1,desc2,itemnumber,manufacturer,mfgpnumber,msrp,
 * price1,price2,price3,qty1,qty2,qty3,upc,weight,serialized,mapprice
 *
 * Normalized internal columns:
 * - zanders_item_number (instead of "itemnumber")
 * - inventory_quantity (instead of "available")
 * - distributor_price / retail_map / retail_msrp
 * - product_description
 * - mfg_model_number
 * - shipping_weight
 * - ffl_required / sot_required (derived)
 */
class ZandersProductTableSchema implements ProductSchemaInterface
{
    public const BASE_TABLE_KEY    = 'fflhub_zanders_product';
    public const LIVE_TABLE_OPTION = 'fflhub_zanders_product_live_table';

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

            // Distributor identifier (normalized pattern: <dist>_item_number)
            'zanders_item_number' => 'VARCHAR(64) NOT NULL',

            // Inventory / status (normalized)
            'inventory_quantity' => 'VARCHAR(32) NULL',
            'allocation_status'  => 'VARCHAR(64) NULL', // not provided by Zanders; reserved for future

            // Pricing (normalized)
            'distributor_price' => 'VARCHAR(32) NULL', // map from price1 (recommended)
            'shipping_cost'     => 'VARCHAR(32) NULL',
            'retail_map'        => 'VARCHAR(32) NULL', // mapprice
            'retail_msrp'       => 'VARCHAR(32) NULL', // msrp

            // Descriptive fields (normalized)
            'product_description' => 'TEXT NULL',        // desc1 + desc2 combined
            'item_type'           => 'VARCHAR(128) NULL',// map from category (or keep separate)
            'manufacturer'        => 'VARCHAR(255) NULL',
            'manufacturer_norm'   => 'VARCHAR(191) NOT NULL DEFAULT \'\'',
            'mfg_model_number'    => 'VARCHAR(128) NULL',

            // Logistics (normalized)
            'shipping_weight'     => 'DECIMAL(10,2) NULL', // map from weight (ensure units!)

            // Keep the raw fields that Zanders provides but we don't normalize yet
            'price_2'    => 'VARCHAR(32) NULL',
            'price_3'    => 'VARCHAR(32) NULL',
            'bulk_qty_1' => 'VARCHAR(32) NULL',
            'bulk_qty_2' => 'VARCHAR(32) NULL',
            'bulk_qty_3' => 'VARCHAR(32) NULL',

            // Flags (normalized/derived)
            'ffl_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'sot_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'dropship_block_reason' => 'VARCHAR(255) NULL',

            // Provided by Zanders (optional to retain)
            'serialized' => 'TINYINT(1) NOT NULL DEFAULT 0',
        );
    }

    public function get_index_definitions(): array
    {
        return array(
            'PRIMARY KEY (upc)',
            'KEY zanders_item_number (zanders_item_number)',
            'KEY manufacturer (manufacturer)',
            'KEY manufacturer_norm (manufacturer_norm)',
            'KEY mfg_model_number (mfg_model_number)',
            'KEY ffl_required (ffl_required)',
            'KEY sot_required (sot_required)',
        );
    }

    public function get_insert_columns(): array
    {
        $all = array_keys( $this->get_column_definitions() );

        return array_values(
            array_filter(
                $all,
                static fn( string $col ) => $col !== 'id'
            )
        );
    }

    /**
     * “Quantity update” should only touch the fast-moving inventory number.
     * For Zanders, that is the "available" value mapped to inventory_quantity.
     */
    public function get_quantity_update_columns(): array
    {
        return array(
            'inventory_quantity',
        );
    }
}
