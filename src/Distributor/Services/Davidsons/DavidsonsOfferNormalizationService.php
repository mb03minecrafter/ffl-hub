<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Davidsons;

use FFLHub\Distributor\Services\AbstractDistributorTableSyncService;
use FFLHub\Distributor\Services\OfferSync\DistributorOfferSyncSqlRunner;
use FFLHub\Distributor\Services\OfferSync\OfferInventorySyncMap;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Davidson's-specific normalized offer sync.
 *
 * Davidson's product cron owns the full current catalog snapshot after it
 * imports the portal CSV and swaps the double-buffered live table. This class
 * only describes how that newly live Davidson's table maps into
 * fflhub_distributor_offers for active product_state UPCs.
 *
 * The inventory cron has a narrower quantity-only feed. Its stage-table path
 * updates only volatile stock fields on existing Davidson's offer rows.
 */
final class DavidsonsOfferNormalizationService extends AbstractDistributorTableSyncService
{
    private const DIST_ID = 'davidsons';

    protected static function distributor_id(): string
    {
        return self::DIST_ID;
    }

    protected static function label(): string
    {
        return 'Davidson\'s';
    }

    protected static function source_alias(): string
    {
        return 'd';
    }

    protected static function matched_count_key(): string
    {
        return 'matched_active_davidsons_upcs';
    }

    /**
     * Apply loaded Davidson's quantity stage rows to existing normalized offers.
     *
     * This is the inventory-cron path. Davidson's quantity CSV owns only
     * warehouse quantity, so this update intentionally writes only:
     * qty, stock_status, normalized_at.
     *
     * It does not write price, shipping, MAP/MSRP, FFL/SOT, manufacturer, or
     * dropship fields. Those fields belong to the full product/catalog cron.
     *
     * The join uses Davidson's item number because product sync stores
     * davidsons_item_number as distributor_product_id. That avoids ambiguous
     * UPC cases where Davidson's quantity feed can disagree with the live
     * catalog row's UPC.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    public static function update_existing_from_inventory_stage(string $stage_table): array
    {
        // 1. Normalize the stage total into the integer quantity used by
        // distributor_offers. The inventory cron has already loaded
        // total_qty = Quantity_NC + Quantity_AZ into the persistent stage table.
        $qty_expr = 'CAST(COALESCE(S.total_qty, 0) AS UNSIGNED)';
        $stock_status_expr = self::stock_status_expr($qty_expr);

        // Target distributor_offers fields in this UPDATE:
        // qty, stock_status, normalized_at.
        // 2. Join by item number, not UPC. Davidson's item_number is the stable
        // distributor identifier and is stored as distributor_product_id.
        $map = new OfferInventorySyncMap(
            self::DIST_ID,
            static::label(),
            $stage_table,
            'S',
            "S.item_number <> '' AND S.item_number = o.distributor_product_id",
            [
                "o.qty = {$qty_expr}",
                "o.stock_status = {$stock_status_expr}",
                'o.normalized_at = NOW()',
            ],
            "
                NOT (
                        o.qty <=> {$qty_expr}
                    AND NULLIF(o.stock_status, '') <=> NULLIF({$stock_status_expr}, '')
                )
            "
        );

        // 3. Let the shared runner compile/execute the changed-only UPDATE.
        // Missing Davidson's offers are created by product sync where the full
        // catalog snapshot exists.
        return DistributorOfferSyncSqlRunner::update_existing_offers_from_inventory_stage($map);
    }

    /**
     * Describe how the current live Davidson's product table maps into offers.
     *
     * When the Davidson's product cron finishes a full catalog import and swaps
     * the live table, the inherited product sync uses this map to insert/update
     * these distributor_offers fields:
     * distributor_product_id, distributor_sku, manufacturer_norm, qty,
     * stock_status, dealer_price, shipping_cost, landed_cost, map_price, msrp,
     * ffl_required, sot_required, dropship_enabled, and enabled.
     *
     * Davidson's-specific notes:
     * - davidsons_item_number is the stable Davidson's item identifier and is
     *   used for both distributor_product_id and distributor_sku.
     * - retail_map and retail_msrp are both populated from Davidson's Retail
     *   Price column in the importer. MSP is not used as MAP.
     * - shipping_cost is the flat Davidson's fulfillment estimate stored by the
     *   importer, currently 13.00 for every row.
     * - shipping_weight is not mapped because the current Davidson's CSV import
     *   does not populate a trustworthy weight value.
     *
     * @return array<string,string>
     */
    protected static function product_source_columns(string $live_table): array
    {
        // Step 1: build typed expressions for the shared INSERT/UPDATE SQL.
        // Davidson's live table stores price and quantity values as strings,
        // while distributor_offers stores them as numeric columns.
        $qty_expr = self::unsigned_quantity_expr('d', 'inventory_quantity');
        $dealer_price_expr = self::decimal_expr('d', 'distributor_price');
        $shipping_cost_expr = self::decimal_expr('d', 'shipping_cost');

        // Step 2: describe the product snapshot in distributor_offers terms.
        // The shared base starts from active product_state UPCs, joins the
        // current Davidson's live table by UPC, inserts missing offers, updates
        // changed offers, and disables stale Davidson's offers no longer found
        // in the new live table.
        return [
            'upc' => 'd.upc',
            'distributor_product_id' => "NULLIF(TRIM(d.davidsons_item_number), '')",
            'distributor_sku' => "NULLIF(TRIM(d.davidsons_item_number), '')",
            'manufacturer_norm' => "NULLIF(UPPER(TRIM(d.manufacturer)), '')",
            'qty' => $qty_expr,
            'stock_status' => self::stock_status_expr($qty_expr),
            'dealer_price' => $dealer_price_expr,
            'shipping_cost' => $shipping_cost_expr,
            'landed_cost' => self::landed_cost_expr($dealer_price_expr, $shipping_cost_expr),
            'map_price' => self::decimal_expr('d', 'retail_map'),
            'msrp' => self::decimal_expr('d', 'retail_msrp'),
            'ffl_required' => 'CAST(COALESCE(d.ffl_required, 0) AS UNSIGNED)',
            'sot_required' => 'CAST(COALESCE(d.sot_required, 0) AS UNSIGNED)',
            'dropship_enabled' => 'CAST(COALESCE(d.dropship_enabled, 0) AS UNSIGNED)',
            'enabled' => '1',
        ];
    }
}
