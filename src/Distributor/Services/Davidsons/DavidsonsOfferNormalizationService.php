<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Davidsons;

use FFLHub\Distributor\Services\AbstractDistributorTableSyncService;

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
 * There is no Davidson's inventory-stage implementation yet. The product cron
 * snapshot is currently the authoritative source for Davidson's offer rows.
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
