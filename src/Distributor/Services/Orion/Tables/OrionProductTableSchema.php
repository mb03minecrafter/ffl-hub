<?php

namespace FFLHub\Distributor\Services\Orion\Tables;

use FFLHub\Distributor\Services\Tables\ProductSchemaInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orion fulfillment schema.
 *
 * Keeps the same normalized columns used by the other distributor tables, plus
 * Orion identifiers and raw catalog descriptors that are needed for import,
 * product creation, drop-ship checks, and future order submission.
 */
final class OrionProductTableSchema implements ProductSchemaInterface
{
    public const BASE_TABLE_KEY = 'fflhub_orion_product';
    public const LIVE_TABLE_OPTION = 'fflhub_orion_product_live_table';

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
            'orion_product_id' => 'VARCHAR(64) NOT NULL',
            'orion_product_code' => 'VARCHAR(128) NOT NULL',
            'remote_identifier' => 'VARCHAR(128) NULL',

            // Inventory / pricing
            'inventory_quantity' => 'VARCHAR(32) NULL',
            'allocation_status' => 'VARCHAR(64) NULL',
            'distributor_price' => 'VARCHAR(32) NULL',
            'shipping_cost' => 'VARCHAR(32) NULL',
            'retail_map' => 'VARCHAR(32) NULL',
            'retail_msrp' => 'VARCHAR(32) NULL',
            'base_cost' => 'VARCHAR(32) NULL',
            'sale_price' => 'VARCHAR(32) NULL',

            // Catalog data
            'product_name' => 'VARCHAR(255) NULL',
            'product_description' => 'LONGTEXT NULL',
            'manufacturer' => 'VARCHAR(255) NULL',
            'model' => 'VARCHAR(255) NULL',
            'mfg_model_number' => 'VARCHAR(128) NULL',
            'item_type' => 'VARCHAR(128) NULL',
            'product_format' => 'VARCHAR(128) NULL',
            'unit' => 'VARCHAR(64) NULL',
            'product_categories' => 'VARCHAR(512) NULL',
            'product_tags' => 'VARCHAR(512) NULL',
            'restricted_states' => 'VARCHAR(255) NULL',
            'facets_json' => 'LONGTEXT NULL',

            // Regulatory / fulfillment
            'ffl_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'sot_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_block_reason' => 'VARCHAR(255) NULL',
            'serializable' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'cannot_dropship' => 'TINYINT(1) NOT NULL DEFAULT 0',

            // Shipping / dimensions / media
            'shipping_weight' => 'DECIMAL(10,2) NULL',
            'shipping_length_in' => 'VARCHAR(32) NULL',
            'shipping_width_in' => 'VARCHAR(32) NULL',
            'shipping_height_in' => 'VARCHAR(32) NULL',
            'image_url' => 'VARCHAR(1024) NULL',
            'image_urls_json' => 'LONGTEXT NULL',
            'last_seen_utc' => 'VARCHAR(64) NULL',
        ];
    }

    public function get_index_definitions(): array
    {
        return [
            'PRIMARY KEY (upc)',
            'KEY orion_product_id (orion_product_id)',
            'KEY orion_product_code (orion_product_code)',
            'KEY manufacturer (manufacturer)',
            'KEY mfg_model_number (mfg_model_number)',
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
