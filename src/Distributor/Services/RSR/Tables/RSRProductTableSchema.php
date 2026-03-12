<?php

namespace FFLHub\Distributor\Services\RSR\Tables;

use FFLHub\Distributor\Services\Tables\ProductSchemaInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSRProductTableSchema implements ProductSchemaInterface
{
    public const BASE_TABLE_KEY    = 'fflhub_rsr_product';
    public const LIVE_TABLE_OPTION = 'fflhub_rsr_product_live_table';

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
            'upc'                      => 'VARCHAR(32)   NOT NULL',
            'rsr_stock_number'         => 'VARCHAR(64)   NOT NULL',

            // Quantity and Pricing
            'inventory_quantity'       => 'VARCHAR(32)   NULL',
            'allocation_status'        => 'VARCHAR(64)   NULL',
            'distributor_price'        => 'VARCHAR(32)   NULL',
            'retail_map'               => 'VARCHAR(32)   NULL',
            'retail_msrp'              => 'VARCHAR(32)   NULL',

            // Catalog data
            'product_description'      => 'TEXT          NOT NULL',
            'dept_number'              => 'VARCHAR(16)   NULL',
            'manufacturer_id'          => 'VARCHAR(64)   NULL',
            'product_weight_oz'        => 'VARCHAR(32)   NULL',
            'model'                    => 'VARCHAR(128)  NULL',
            'full_manufacturer_name'   => 'VARCHAR(255)  NULL',
            'manufacturer_part_number' => 'VARCHAR(128)  NULL',
            'expanded_product_description' => 'TEXT       NULL',
            'image_name'               => 'VARCHAR(255)  NULL',

            // Per-state shipping flags
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

            'ground_shipments_only' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'adult_sig_required'    => 'TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_enabled'      => 'TINYINT(1) NOT NULL DEFAULT 1',
            'dropship_block_reason' => 'VARCHAR(255) NULL',
            'date_entered'          => 'VARCHAR(16)  NULL',
            'image_disclaimer'      => 'TEXT        NULL',

            'shipping_length_in'    => 'VARCHAR(32)  NULL',
            'shipping_width_in'     => 'VARCHAR(32)  NULL',
            'shipping_height_in'    => 'VARCHAR(32)  NULL',

            'reserved_future'       => 'VARCHAR(255) NULL',
        );
    }

    public function get_index_definitions(): array
    {
        return array(
            'PRIMARY KEY  (upc)',
            'KEY rsr_stock_number (rsr_stock_number)',
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

    public function get_quantity_update_columns(): array
    {
        return array(
            'inventory_quantity',
        );
    }
}
