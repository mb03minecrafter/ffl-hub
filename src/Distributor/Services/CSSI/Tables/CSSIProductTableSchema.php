<?php

namespace FFLHub\Distributor\Services\CSSI\Tables;

use FFLHub\Distributor\Services\Tables\ProductSchemaInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CSSI fulfillment table schema.
 */
class CSSIProductTableSchema implements ProductSchemaInterface
{
    public const BASE_TABLE_KEY = 'fflhub_cssi_product';
    public const LIVE_TABLE_OPTION = 'fflhub_cssi_product_live_table';

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
            'cssi_item_number' => 'VARCHAR(64) NOT NULL',

            // Inventory / pricing
            'inventory_quantity' => 'VARCHAR(32) NULL',
            'in_stock_flag' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'allocation_status' => 'VARCHAR(64) NULL',
            'distributor_price' => 'VARCHAR(32) NULL',
            'shipping_cost' => 'VARCHAR(32) NULL',
            'retail_map' => 'VARCHAR(32) NULL',
            'retail_msrp' => 'VARCHAR(32) NULL',
            'drop_ship_price' => 'VARCHAR(32) NULL',

            // Catalog fields
            'product_name' => 'VARCHAR(255) NULL',
            'product_description' => 'TEXT NULL',
            'manufacturer' => 'VARCHAR(255) NULL',
            'model' => 'VARCHAR(255) NULL',
            'mfg_model_number' => 'VARCHAR(128) NULL',
            'caliber_gauge' => 'VARCHAR(64) NULL',
            'item_type' => 'VARCHAR(128) NULL',
            'serialized_flag' => 'TINYINT(1) NOT NULL DEFAULT 0',

            // Regulatory / fulfillment
            'ffl_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'sot_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_block_reason' => 'VARCHAR(255) NULL',
            'drop_ship_delivery_options' => 'VARCHAR(255) NULL',

            // Shipping
            'shipping_weight' => 'DECIMAL(10,2) NULL',
            'shipping_length_in' => 'VARCHAR(32) NULL',
            'shipping_width_in' => 'VARCHAR(32) NULL',
            'shipping_height_in' => 'VARCHAR(32) NULL',
            'image_location' => 'VARCHAR(1024) NULL',

            // Feed metadata
            'last_seen_utc' => 'VARCHAR(64) NULL',
        ];
    }

    public function get_index_definitions(): array
    {
        return [
            'PRIMARY KEY (upc)',
            'KEY cssi_item_number (cssi_item_number)',
            'KEY manufacturer (manufacturer)',
            'KEY model (model)',
            'KEY item_type (item_type)',
            'KEY ffl_required (ffl_required)',
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
