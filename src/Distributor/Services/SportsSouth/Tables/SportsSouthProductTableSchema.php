<?php

namespace FFLHub\Distributor\Services\SportsSouth\Tables;

use FFLHub\Distributor\Services\Tables\ProductSchemaInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sports South fulfillment schema.
 *
 * DailyItemUpdate is the catalog source. IncrementalOnhandUpdate updates
 * quantity and customer-specific pricing against this table by ITEMNO/UPC.
 */
final class SportsSouthProductTableSchema implements ProductSchemaInterface
{
    public const BASE_TABLE_KEY = 'fflhub_sports_south_product';
    public const LIVE_TABLE_OPTION = 'fflhub_sports_south_product_live_table';

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
            'sports_south_item_number' => 'VARCHAR(64) NOT NULL',
            'remote_identifier' => 'VARCHAR(128) NULL',

            // Inventory / pricing
            'inventory_quantity' => 'VARCHAR(32) NULL',
            'allocation_status' => 'VARCHAR(64) NULL',
            'distributor_price' => 'VARCHAR(32) NULL',
            'catalog_price' => 'VARCHAR(32) NULL',
            'retail_map' => 'VARCHAR(32) NULL',
            'retail_msrp' => 'VARCHAR(32) NULL',

            // Catalog data
            'product_name' => 'VARCHAR(255) NULL',
            'product_description' => 'LONGTEXT NULL',
            'manufacturer' => 'VARCHAR(255) NULL',
            'brand_number' => 'VARCHAR(64) NULL',
            'model' => 'VARCHAR(255) NULL',
            'manufacturer_part_number' => 'VARCHAR(128) NULL',
            'category_id' => 'VARCHAR(64) NULL',
            'item_type' => 'VARCHAR(128) NULL',
            'caliber_gauge' => 'VARCHAR(128) NULL',
            'attributes_json' => 'LONGTEXT NULL',

            // Regulatory / fulfillment
            'ffl_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'sot_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'dropship_block_reason' => 'VARCHAR(255) NULL',
            'restricted_states' => 'VARCHAR(255) NULL',

            // Shipping / dimensions / media
            'shipping_weight' => 'DECIMAL(10,2) NULL',
            'shipping_length_in' => 'VARCHAR(32) NULL',
            'shipping_width_in' => 'VARCHAR(32) NULL',
            'shipping_height_in' => 'VARCHAR(32) NULL',
            'image_ref' => 'VARCHAR(128) NULL',
            'image_url' => 'VARCHAR(1024) NULL',
            'image_urls_json' => 'LONGTEXT NULL',
            'text_ref' => 'VARCHAR(128) NULL',

            // Feed metadata
            'last_seen_utc' => 'VARCHAR(64) NULL',
            'last_onhand_utc' => 'VARCHAR(64) NULL',
            'raw_item_json' => 'LONGTEXT NULL',
        ];
    }

    public function get_index_definitions(): array
    {
        return [
            'PRIMARY KEY (upc)',
            'KEY sports_south_item_number (sports_south_item_number)',
            'KEY manufacturer (manufacturer)',
            'KEY brand_number (brand_number)',
            'KEY category_id (category_id)',
            'KEY item_type (item_type)',
            'KEY ffl_required (ffl_required)',
            'KEY sot_required (sot_required)',
            'KEY dropship_enabled (dropship_enabled)',
        ];
    }

    public function get_insert_columns(): array
    {
        return array_values(array_keys($this->get_column_definitions()));
    }
}
