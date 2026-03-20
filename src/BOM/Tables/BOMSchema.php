<?php
declare(strict_types=1);

namespace FFLHub\BOM\Tables;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for BOM item storage.
 */
final class BOMSchema
{
    private const BASE_TABLE_KEY = 'fflhub_bom_items';

    public const SOURCE_DISTRIBUTOR_UPC = 'distributor_upc';
    public const SOURCE_PRODUCT_LINK    = 'product_link';
    public const SOURCE_INTERNAL_STOCK  = 'internal_stock';

    public function get_base_table_key(): string
    {
        return self::BASE_TABLE_KEY;
    }

    /**
     * @return string[]
     */
    public function get_writable_columns(): array
    {
        return [
            'sort_order',
            'component_name',
            'component_notes',
            'quantity_required',
            'source_type',
            'source_ref',
            'manual_unit_price',
            'manual_qty_on_hand',
            'updated_at',
        ];
    }

    /**
     * @return array<string,string>
     */
    public function get_column_definitions(): array
    {
        return [
            'id'                => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'parent_product_id' => 'BIGINT UNSIGNED NOT NULL',
            'sort_order'        => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'component_name'    => 'VARCHAR(255) NOT NULL',
            'component_notes'   => 'TEXT NULL',
            'quantity_required' => 'DECIMAL(12,4) NOT NULL DEFAULT 1.0000',
            'source_type'       => 'VARCHAR(32) NOT NULL',
            'source_ref'        => 'VARCHAR(128) NULL',
            'manual_unit_price' => 'DECIMAL(12,4) NULL',
            'manual_qty_on_hand' => 'INT NULL',
            'created_at'        => 'DATETIME NOT NULL',
            'updated_at'        => 'DATETIME NOT NULL',
        ];
    }

    /**
     * @return string[]
     */
    public function get_index_definitions(): array
    {
        return [
            'PRIMARY KEY (id)',
            'KEY idx_parent_sort (parent_product_id, sort_order, id)',
            'KEY idx_parent_product (parent_product_id)',
            'KEY idx_source (source_type, source_ref)',
        ];
    }

    /**
     * @return string[]
     */
    public function get_insert_columns(): array
    {
        $columns = array_keys($this->get_column_definitions());

        return array_values(
            array_filter(
                $columns,
                static fn(string $col): bool => $col !== 'id'
            )
        );
    }
}
