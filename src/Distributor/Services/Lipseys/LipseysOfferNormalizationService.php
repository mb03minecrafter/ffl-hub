<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Lipseys;

use FFLHub\Distributor\Services\AbstractDistributorTableSyncService;
use FFLHub\Distributor\Services\OfferSync\DistributorOfferSyncSqlRunner;
use FFLHub\Distributor\Services\OfferSync\OfferInventorySyncMap;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lipsey's-specific normalized offer sync.
 *
 * Product/catalog sync is inherited from AbstractDistributorTableSyncService:
 * after the Lipsey's product cron swaps in a fresh live table, this class maps
 * Lipsey's live-table columns into fflhub_distributor_offers for UPCs we carry.
 *
 * Inventory sync remains local to this class because Lipsey's pricing/quantity
 * stage rows include volatile inventory, dealer price, MAP, and can-dropship
 * data that need a distributor-specific UPDATE.
 */
final class LipseysOfferNormalizationService extends AbstractDistributorTableSyncService
{
    private const DIST_ID = 'lipseys';

    protected static function distributor_id(): string
    {
        return self::DIST_ID;
    }

    protected static function label(): string
    {
        return 'Lipsey\'s';
    }

    protected static function source_alias(): string
    {
        return 'l';
    }

    protected static function matched_count_key(): string
    {
        return 'matched_active_lipseys_upcs';
    }

    /**
     * Apply loaded Lipsey's pricing/quantity stage rows to existing offer rows.
     *
     * This is the inventory-worker path. It intentionally updates only
     * inventory/pricing-owned fields and never inserts rows.
     * It writes these distributor_offers columns:
     * qty, stock_status, dealer_price, map_price, landed_cost,
     * dropship_enabled, normalized_at.
     * It does not write shipping_cost because Lipsey's shipping is owned by
     * the product/catalog snapshot, not the pricing/quantity feed.
     * Missing offer rows are created by the product-table sync path, where we
     * have the full catalog fields needed to seed a complete offer snapshot.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    public static function update_existing_from_inventory_stage(string $stage_table): array
    {
        // 1. Normalize the raw stage strings into typed SQL expressions.
        // Lipsey's inventory stage stores API values as strings, so every
        // comparison/update trims blanks and casts to the target offer type.
        $qty_expr = "CAST(COALESCE(NULLIF(TRIM(S.inventory_quantity), ''), '0') AS UNSIGNED)";
        $stock_status_expr = "CASE WHEN {$qty_expr} > 0 THEN 'instock' ELSE 'outofstock' END";
        $dealer_price_expr = "CAST(NULLIF(TRIM(S.distributor_price), '') AS DECIMAL(12,4))";
        $map_price_expr = "CAST(NULLIF(TRIM(S.retail_map), '') AS DECIMAL(12,4))";
        $landed_cost_expr = "
            CASE
                WHEN {$dealer_price_expr} IS NULL THEN NULL
                ELSE {$dealer_price_expr} + COALESCE(o.shipping_cost, 0.0000)
            END
        ";
        $dropship_enabled_expr = "
            CASE
                WHEN S.can_dropship = 0 THEN 0
                WHEN COALESCE(o.sot_required, 0) = 1 THEN o.dropship_enabled
                ELSE 1
            END
        ";

        // Target distributor_offers fields in this UPDATE:
        // qty, stock_status, dealer_price, map_price, landed_cost,
        // dropship_enabled, normalized_at.
        // 2. Join stage rows to existing Lipsey's offer rows by item number.
        // The inventory worker only updates rows created by product sync; it
        // refreshes qty, stock, dealer price, MAP, landed cost, and dropship
        // eligibility when any of those calculated values actually changes.
        $map = new OfferInventorySyncMap(
            self::DIST_ID,
            static::label(),
            $stage_table,
            'S',
            'S.lipseys_item_number = o.distributor_product_id',
            [
                "o.qty = {$qty_expr}",
                "o.stock_status = {$stock_status_expr}",
                "o.dealer_price = {$dealer_price_expr}",
                "o.map_price = {$map_price_expr}",
                "o.landed_cost = {$landed_cost_expr}",
                "o.dropship_enabled = {$dropship_enabled_expr}",
                'o.normalized_at = NOW()',
            ],
            "
                NOT (
                        o.qty <=> {$qty_expr}
                    AND NULLIF(o.stock_status, '') <=> NULLIF({$stock_status_expr}, '')
                    AND o.dealer_price <=> {$dealer_price_expr}
                    AND o.map_price <=> {$map_price_expr}
                    AND o.landed_cost <=> {$landed_cost_expr}
                    AND o.dropship_enabled <=> {$dropship_enabled_expr}
                )
            "
        );

        // 3. Let the shared runner compile/execute the changed-only UPDATE.
        // A zero row count is valid: it means the latest Lipsey's stage matched
        // the normalized offer snapshot.
        return DistributorOfferSyncSqlRunner::update_existing_offers_from_inventory_stage($map);
    }

    /**
     * Describe how the current live Lipsey's product table maps into offers.
     *
     * These expressions are used by the inherited product-table sync for both
     * missing-row inserts and changed-row updates. Optional shipping dimensions
     * are included only when the live table has those columns.
     *
     * When the Lipsey's product cron finishes a full catalog import and swaps
     * the live table, the inherited product sync uses this map to insert/update
     * these distributor_offers fields:
     * distributor_product_id, distributor_sku, manufacturer_norm, qty,
     * stock_status, dealer_price, shipping_cost, landed_cost, map_price, msrp,
     * ffl_required, sot_required, dropship_enabled, enabled, and optional
     * shipping dimensions.
     *
     * @return array<string,string>
     */
    protected static function product_source_columns(string $live_table): array
    {
        $live_table_exists = self::table_exists_by_name($live_table);

        // Build reusable expressions so inherited insert/update SQL treats
        // Lipsey's blank numeric strings consistently as NULL or zero.
        $qty_expr = self::unsigned_quantity_expr('l', 'inventory_quantity');
        $dealer_price_expr = self::decimal_expr('l', 'distributor_price');
        $shipping_cost_expr = self::decimal_expr('l', 'shipping_cost');

        // Product-cron offer fields:
        // - identity: distributor_product_id, distributor_sku, manufacturer_norm
        // - current snapshot: qty, stock_status, dealer_price, shipping_cost,
        //   landed_cost, map_price, msrp
        // - policy flags: ffl_required, sot_required, dropship_enabled, enabled
        // The inherited sync inserts these for missing rows and updates them
        // for existing rows when the expressions differ from distributor_offers.
        $source_columns = [
            'upc' => 'l.upc',
            'distributor_product_id' => "NULLIF(TRIM(l.lipseys_item_number), '')",
            'distributor_sku' => "NULLIF(TRIM(l.lipseys_item_number), '')",
            'manufacturer_norm' => "NULLIF(UPPER(TRIM(l.manufacturer)), '')",
            'qty' => $qty_expr,
            'stock_status' => self::stock_status_expr($qty_expr),
            'dealer_price' => $dealer_price_expr,
            'shipping_cost' => $shipping_cost_expr,
            'landed_cost' => self::landed_cost_expr($dealer_price_expr, $shipping_cost_expr),
            'map_price' => self::decimal_expr('l', 'retail_map'),
            'msrp' => self::decimal_expr('l', 'retail_msrp'),
            'ffl_required' => 'CAST(COALESCE(l.ffl_required, 0) AS UNSIGNED)',
            'sot_required' => 'CAST(COALESCE(l.sot_required, 0) AS UNSIGNED)',
            'dropship_enabled' => 'CAST(COALESCE(l.dropship_enabled, 1) AS UNSIGNED)',
            'enabled' => '1',
        ];

        // Newer Lipsey's tables may include dimensions. Add them only when the
        // live table actually has those columns so older installs still sync.
        foreach (self::dimension_source_columns() as $target_column => $source_column) {
            if ($live_table_exists && self::table_has_column($live_table, $source_column)) {
                $source_columns[$target_column] = self::decimal_expr('l', $source_column, 10, 3);
            }
        }

        return $source_columns;
    }

    /**
     * @return array<string,string>
     */
    private static function dimension_source_columns(): array
    {
        return [
            'shipping_weight_oz' => 'shipping_weight',
            'shipping_length_in' => 'shipping_length_in',
            'shipping_width_in' => 'shipping_width_in',
            'shipping_height_in' => 'shipping_height_in',
        ];
    }
}
