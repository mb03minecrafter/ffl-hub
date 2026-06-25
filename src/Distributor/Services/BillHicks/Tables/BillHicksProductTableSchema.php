<?php

namespace FFLHub\Distributor\Services\BillHicks\Tables;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Tables\ProductSchemaInterface;

/**
 * Bill Hicks product table scaffold.
 *
 * The initial schema intentionally mirrors the normalized distributor-table
 * columns we already use elsewhere, with a few Bill Hicks source identifiers.
 * Import logic can add source-specific fields once the feed mapping is final.
 */
final class BillHicksProductTableSchema implements ProductSchemaInterface
{
    public const BASE_TABLE_KEY = 'fflhub_bill_hicks_product';
    public const LIVE_TABLE_OPTION = 'fflhub_bill_hicks_product_live_table';

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
            'bill_hicks_item_number' => 'VARCHAR(64) NOT NULL',
            'manufacturer_number' => 'VARCHAR(64) NULL',

            // Inventory / pricing
            'inventory_quantity' => 'VARCHAR(32) NULL',
            'allocation_status' => 'VARCHAR(64) NULL',
            'distributor_price' => 'VARCHAR(32) NULL',
            'shipping_cost' => 'VARCHAR(32) NULL',
            'retail_map' => 'VARCHAR(32) NULL',
            'retail_msrp' => 'VARCHAR(32) NULL',

            // Catalog
            'product_name' => 'VARCHAR(255) NULL',
            'product_description' => 'TEXT NULL',
            'manufacturer' => 'VARCHAR(255) NULL',
            'manufacturer_norm' => 'VARCHAR(191) NOT NULL DEFAULT \'\'',
            'model' => 'VARCHAR(255) NULL',
            'caliber_gauge' => 'VARCHAR(64) NULL',
            'item_type' => 'VARCHAR(128) NULL',
            'category' => 'VARCHAR(255) NULL',
            'image_url' => 'VARCHAR(1024) NULL',

            // Shipping / regulatory / fulfillment
            'shipping_weight' => 'DECIMAL(10,2) NULL',
            'shipping_length' => 'DECIMAL(10,2) NULL',
            'shipping_width' => 'DECIMAL(10,2) NULL',
            'shipping_height' => 'DECIMAL(10,2) NULL',
            'ffl_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'sot_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'dropship_block_reason' => 'VARCHAR(255) NULL',

            // Feed metadata
            'status_code' => 'VARCHAR(64) NULL',
            'last_change_date' => 'VARCHAR(32) NULL',
            'last_change_time' => 'VARCHAR(32) NULL',
        ];
    }

    public function get_index_definitions(): array
    {
        return [
            'PRIMARY KEY (upc)',
            'KEY bill_hicks_item_number (bill_hicks_item_number)',
            'KEY manufacturer (manufacturer)',
            'KEY manufacturer_norm (manufacturer_norm)',
            'KEY manufacturer_number (manufacturer_number)',
            'KEY item_type (item_type)',
            'KEY category (category)',
            'KEY ffl_required (ffl_required)',
            'KEY sot_required (sot_required)',
            'KEY dropship_enabled (dropship_enabled)',
        ];
    }

    public function get_insert_columns(): array
    {
        return array_keys($this->get_column_definitions());
    }
}
