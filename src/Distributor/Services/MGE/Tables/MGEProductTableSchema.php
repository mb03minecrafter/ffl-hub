<?php

namespace FFLHub\Distributor\Services\MGE\Tables;

use FFLHub\Distributor\Services\Tables\ProductSchemaInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MGE fulfillment schema.
 *
 * Full feed source columns:
 * description,subCategory,name,qty,MAP,barcod,image,manufacturer_id,VendorID,
 * category,price,STAT,id,DropShip,vendorItemNo,rowIndex
 */
class MGEProductTableSchema implements ProductSchemaInterface
{
    public const BASE_TABLE_KEY = 'fflhub_mge_product';
    public const LIVE_TABLE_OPTION = 'fflhub_mge_product_live_table';

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
        return [
            // Core identifiers
            'upc' => 'VARCHAR(32) NOT NULL',
            'mge_item_number' => 'VARCHAR(64) NOT NULL',
            'vendor_item_number' => 'VARCHAR(64) NULL',

            // Inventory / pricing
            'inventory_quantity' => 'VARCHAR(32) NULL',
            'allocation_status' => 'VARCHAR(64) NULL',
            'distributor_price' => 'VARCHAR(32) NULL',
            'shipping_cost' => 'VARCHAR(32) NULL',
            'retail_map' => 'VARCHAR(32) NULL',
            'retail_msrp' => 'VARCHAR(32) NULL',

            // Catalog
            'product_description' => 'TEXT NULL',
            'model' => 'VARCHAR(255) NULL',
            'manufacturer' => 'VARCHAR(255) NULL',
            'manufacturer_id' => 'VARCHAR(64) NULL',
            'vendor_id' => 'VARCHAR(64) NULL',
            'item_type' => 'VARCHAR(128) NULL',
            'sub_category' => 'VARCHAR(128) NULL',
            'image_url' => 'VARCHAR(1024) NULL',

            // Regulatory / fulfillment
            'ffl_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'sot_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_block_reason' => 'VARCHAR(255) NULL',

            // Feed metadata
            'status_code' => 'VARCHAR(16) NULL',
            'drop_ship_source' => 'VARCHAR(32) NULL',
            'row_index' => 'VARCHAR(32) NULL',
            'barcod_raw' => 'VARCHAR(64) NULL',
        ];
    }

    public function get_index_definitions(): array
    {
        return [
            'PRIMARY KEY (upc)',
            'KEY mge_item_number (mge_item_number)',
            'KEY vendor_item_number (vendor_item_number)',
            'KEY manufacturer (manufacturer)',
            'KEY item_type (item_type)',
            'KEY ffl_required (ffl_required)',
            'KEY sot_required (sot_required)',
        ];
    }

    public function get_insert_columns(): array
    {
        $all = array_keys($this->get_column_definitions());

        return array_values(
            array_filter(
                $all,
                static fn(string $col): bool => $col !== 'id'
            )
        );
    }
}
