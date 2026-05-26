<?php

namespace FFLHub\Distributor\Services\Kinseys\Tables;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Tables\ProductSchemaInterface;

/**
 * Kinsey's fulfillment table schema.
 */
final class KinseysProductTableSchema implements ProductSchemaInterface
{
    public const BASE_TABLE_KEY = 'fflhub_kinseys_product';
    public const LIVE_TABLE_OPTION = 'fflhub_kinseys_product_live_table';

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
            'kinseys_product_id' => 'VARCHAR(64) NOT NULL',
            'north_item_number' => 'VARCHAR(64) NULL',
            'south_item_number' => 'VARCHAR(64) NULL',
            'vendor_item_number' => 'VARCHAR(128) NULL',

            // Inventory / pricing
            'inventory_quantity' => 'VARCHAR(32) NULL',
            'allocation_status' => 'VARCHAR(64) NULL',
            'distributor_price' => 'VARCHAR(32) NULL',
            'retail_map' => 'VARCHAR(32) NULL',
            'retail_msrp' => 'VARCHAR(32) NULL',
            'unit_price' => 'VARCHAR(32) NULL',
            'restock_eta' => 'VARCHAR(255) NULL',
            'warehouses_json' => 'LONGTEXT NULL',

            // Catalog fields
            'product_name' => 'VARCHAR(255) NULL',
            'product_description' => 'LONGTEXT NULL',
            'manufacturer' => 'VARCHAR(255) NULL',
            'model' => 'VARCHAR(255) NULL',
            'description_1' => 'VARCHAR(255) NULL',
            'description_2' => 'VARCHAR(255) NULL',
            'bullet_features' => 'LONGTEXT NULL',
            'country_of_origin' => 'VARCHAR(128) NULL',
            'item_category_code' => 'VARCHAR(64) NULL',
            'product_group_code' => 'VARCHAR(64) NULL',
            'product_sub_group_1' => 'VARCHAR(64) NULL',
            'product_sub_group_2' => 'VARCHAR(64) NULL',
            'pack_size' => 'VARCHAR(64) NULL',
            'include_exclude_group' => 'VARCHAR(128) NULL',
            'prohibited_states' => 'VARCHAR(255) NULL',
            'nav_inventory_posting_group' => 'VARCHAR(128) NULL',

            // Regulatory / fulfillment
            'ffl_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'sot_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_block_reason' => 'VARCHAR(255) NULL',
            'can_be_dropshipped' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'blocked_flag' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'inactive_flag' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'hazardous_flag' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'flammable_flag' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'prop65_applies' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'prop65_cancer_harm' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'prop65_reproductive_harm' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'prop65_chemical' => 'VARCHAR(255) NULL',

            // Shipping / variants
            'shipping_weight' => 'DECIMAL(10,2) NULL',
            'shipping_length_in' => 'VARCHAR(32) NULL',
            'shipping_width_in' => 'VARCHAR(32) NULL',
            'shipping_height_in' => 'VARCHAR(32) NULL',
            'color_1' => 'VARCHAR(128) NULL',
            'color_2' => 'VARCHAR(128) NULL',
            'size' => 'VARCHAR(128) NULL',
            'rh_lh' => 'VARCHAR(64) NULL',
            'parent_child_sku' => 'VARCHAR(128) NULL',
            'parent_child_option_1' => 'VARCHAR(128) NULL',
            'parent_child_option_2' => 'VARCHAR(128) NULL',
            'parent_child_option_3' => 'VARCHAR(128) NULL',
            'parent_child_option_4' => 'VARCHAR(128) NULL',

            // Feed metadata
            'date_created' => 'VARCHAR(64) NULL',
            'last_seen_utc' => 'VARCHAR(64) NULL',
        ];
    }

    public function get_index_definitions(): array
    {
        return [
            'PRIMARY KEY (upc)',
            'KEY kinseys_product_id (kinseys_product_id)',
            'KEY north_item_number (north_item_number)',
            'KEY south_item_number (south_item_number)',
            'KEY vendor_item_number (vendor_item_number)',
            'KEY manufacturer (manufacturer)',
            'KEY product_group_code (product_group_code)',
            'KEY item_category_code (item_category_code)',
            'KEY ffl_required (ffl_required)',
            'KEY dropship_enabled (dropship_enabled)',
        ];
    }

    public function get_insert_columns(): array
    {
        return array_values(array_keys($this->get_column_definitions()));
    }
}
