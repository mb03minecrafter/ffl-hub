<?php

namespace FFLHub\Distributor\Services\Davidsons\Tables;

use FFLHub\Distributor\Services\Tables\ProductSchemaInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Davidson's fulfillment schema.
 *
 * Source CSV headers currently mapped:
 * - Item #           -> davidsons_item_number
 * - Item Description -> product_description
 * - MSP              -> retail_map
 * - Retail Price     -> retail_msrp
 * - Dealer Price     -> distributor_price
 * - Sale Price       -> sale_price
 * - Sale Ends        -> sale_ends
 * - Quantity         -> inventory_quantity
 * - UPC Code         -> upc
 * - Manufacturer     -> manufacturer
 * - Gun Type         -> item_type
 * - Model Series     -> model
 * - Caliber          -> caliber_gauge
 * - Action           -> action
 * - Capacity         -> capacity
 * - Finish           -> finish
 * - Stock            -> stock_frame_grips
 * - Sights           -> sights
 * - Barrel Length    -> barrel_length
 * - Overall Length   -> overall_length
 * - Features         -> features
 *
 * Rule:
 * - If a CSV field maps to an existing normalized column, keep only the
 *   normalized column (no duplicated raw alias column).
 * - Keep source-specific fields only when there is no normalized equivalent.
 */
class DavidsonsProductTableSchema implements ProductSchemaInterface
{
    public const BASE_TABLE_KEY = 'fflhub_davidsons_product';
    public const LIVE_TABLE_OPTION = 'fflhub_davidsons_product_live_table';

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
            'davidsons_item_number' => 'VARCHAR(64) NOT NULL',

            // Inventory / pricing (normalized names used across distributors)
            'inventory_quantity' => 'VARCHAR(32) NULL',
            'allocation_status' => 'VARCHAR(64) NULL',
            'distributor_price' => 'VARCHAR(32) NULL',
            'shipping_cost' => 'VARCHAR(32) NULL',
            'retail_map' => 'VARCHAR(32) NULL',
            'retail_msrp' => 'VARCHAR(32) NULL',
            'sale_price' => 'VARCHAR(32) NULL',
            'sale_ends' => 'VARCHAR(64) NULL',

            // Catalog data
            'product_description' => 'TEXT NULL',
            'manufacturer' => 'VARCHAR(255) NULL',
            'model' => 'VARCHAR(255) NULL',
            'caliber_gauge' => 'VARCHAR(64) NULL',
            'item_type' => 'VARCHAR(128) NULL',
            'action' => 'VARCHAR(128) NULL',
            'capacity' => 'VARCHAR(64) NULL',
            'finish' => 'VARCHAR(255) NULL',
            'stock_frame_grips' => 'VARCHAR(255) NULL',
            'sights' => 'VARCHAR(255) NULL',
            'barrel_length' => 'VARCHAR(64) NULL',
            'overall_length' => 'VARCHAR(64) NULL',
            'features' => 'TEXT NULL',

            // Keep normalized shipping/regulatory fields for compatibility
            'shipping_weight' => 'DECIMAL(10,2) NULL',
            'ffl_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'sot_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'dropship_block_reason' => 'VARCHAR(255) NULL',
        ];
    }

    public function get_index_definitions(): array
    {
        return [
            'PRIMARY KEY (upc)',
            'KEY davidsons_item_number (davidsons_item_number)',
            'KEY manufacturer (manufacturer)',
            'KEY model (model)',
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
