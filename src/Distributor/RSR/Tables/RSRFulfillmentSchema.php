<?php

namespace FFLHub\Distributor\RSR\Tables;


if (! defined('ABSPATH')) {
    exit;
}

class RSRFulfillmentSchema
{

    /**
     * Base DB table key.
     */
    const BASE_TABLE_KEY    = 'fflhub_rsr_fulfillment';
    const LIVE_TABLE_OPTION = 'fflhub_rsr_fulfillment_live_table';

    /**
     * Column definitions for CREATE TABLE.
     *
     * Key = column name, value = full SQL fragment after the name.
     */
    public static function get_column_definitions(): array
    {
        return array(
            // Primary key
            'upc'                        => 'VARCHAR(32)   NOT NULL',

            'rsr_stock_number'           => 'VARCHAR(64)   NOT NULL',



            //Quantity and Pricing
            'inventory_quantity'         => 'VARCHAR(32)   NULL',
            'allocation_status'          => 'VARCHAR(64)   NULL',
            'distributor_price'          => 'VARCHAR(32)   NULL',
            'retail_map'                 => 'VARCHAR(32)  NULL',
            'retail_msrp'               => 'VARCHAR(32)   NULL',


            //Catalog data
            'product_description'        => 'TEXT          NOT NULL',
            'dept_number'                => 'VARCHAR(16)   NULL',
            'manufacturer_id'            => 'VARCHAR(64)   NULL',
            'product_weight_oz'          => 'VARCHAR(32)   NULL',
            'model'                      => 'VARCHAR(128)  NULL',
            'full_manufacturer_name'     => 'VARCHAR(255)  NULL',
            'manufacturer_part_number'   => 'VARCHAR(128)  NULL',
            'expanded_product_description' => 'TEXT        NULL',
            'image_name'                 => 'VARCHAR(255)  NULL',

            //RSR PER STATE SHIPPING FLAGS 

            'ship_ak' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_al' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ar' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_az' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ca' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_co' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ct' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_dc' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_de' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_fl' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ga' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_hi' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ia' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_id' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_il' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_in' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ks' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ky' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_la' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ma' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_md' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_me' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_mi' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_mn' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_mo' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ms' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_mt' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_nc' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_nd' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ne' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_nh' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_nj' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_nm' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_nv' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ny' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_oh' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ok' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_or' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ph' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ri' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_sc' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_sd' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_tn' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_tx' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_ut' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_va' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_vt' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_wa' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_wi' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_wv' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ship_wy' => 'TINYINT(1) NOT NULL DEFAULT 0',

            'ground_shipments_only'      => 'TINYINT(1) NOT NULL DEFAULT 0',
            'adult_sig_required'         => 'TINYINT(1) NOT NULL DEFAULT 0',
            'blocked_from_dropship'      => 'TINYINT(1) NOT NULL DEFAULT 0',
            'date_entered'               => 'VARCHAR(16)  NULL',
            'image_disclaimer'           => 'TEXT         NULL',

            'shipping_length_in'         => 'VARCHAR(32)  NULL',
            'shipping_width_in'          => 'VARCHAR(32)  NULL',
            'shipping_height_in'         => 'VARCHAR(32)  NULL',



            'reserved_future'            => 'VARCHAR(255) NULL',
        );
    }


    /**
     * Index definitions (PRIMARY and KEYs).
     *
     * Each value is a full index line to drop directly into CREATE TABLE.
     */
    public static function get_index_definitions(): array
    {
        return array(
            'PRIMARY KEY  (upc)',
            'KEY rsr_stock_number (rsr_stock_number)',

        );
    }

    /**
     * Columns used when doing INSERTs (exclude id, etc).
     */
    public static function get_insert_columns(): array
    {
        $all = array_keys(self::get_column_definitions());
        // Remove columns you never insert directly (id, maybe reserved, etc).
        return array_values(
            array_filter(
                $all,
                static fn($col) => $col !== 'id'
            )
        );
    }

    /**
     * Columns touched by the qty cron (for building CASE / UPDATE if you want).
     */
    public static function get_quantity_update_columns(): array
    {
        return array(
            'inventory_quantity',
        );
    }

    // Helpers for table names (v1/v2) – you already have this logic, just move it here if you want,
    // or keep it in the Table class and have *that* use BASE_TABLE_KEY from here.
}
