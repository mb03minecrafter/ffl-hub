<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\BillHicks;

use FFLHub\Distributor\Services\OfferSync\AbstractDistributorTableSyncService;
use FFLHub\Distributor\Services\OfferSync\OfferInventorySyncMap;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bill Hicks-specific normalized offer sync.
 *
 * The product cron owns Bill Hicks catalog rows. After it loads the HostedFTP
 * catalog CSV, applies the Bill Hicks fulfillment policy, and swaps the staging
 * table live, this class maps that newly live table into
 * fflhub_distributor_offers for active product_state UPCs.
 *
 * Bill Hicks' catalog does not include real inventory quantity, so the product
 * sync seeds qty as 0/outofstock. The future inventory cron can overwrite the
 * volatile quantity/stock fields later when we wire that feed in.
 */
final class BillHicksOfferNormalizationService extends AbstractDistributorTableSyncService
{
    private const DIST_ID = 'bill_hicks';

    protected static function distributor_id(): string
    {
        return self::DIST_ID;
    }

    protected static function label(): string
    {
        return 'Bill Hicks';
    }

    protected static function source_alias(): string
    {
        return 'bh';
    }

    protected static function matched_count_key(): string
    {
        return 'matched_active_bill_hicks_upcs';
    }

    /**
     * Apply loaded Bill Hicks inventory stage quantities to existing offers.
     *
     * This is the inventory-cron path. BHC_inventory.csv owns only current
     * quantity, so this method writes only:
     * qty, stock_status, normalized_at.
     *
     * It intentionally does not write price, shipping, MAP/MSRP, FFL/SOT,
     * manufacturer, or dropship fields. Those belong to the product/catalog
     * cron because they come from BHC_Catalog.csv.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    public static function update_existing_from_inventory_stage(string $stage_table): array
    {
        // Step 1: translate the staged BHC quantity into offer-table fields.
        // stock_status is derived entirely from qty, matching the rest of the
        // normalized offer pipeline.
        $stock_status_expr = "CASE WHEN S.qty > 0 THEN 'instock' ELSE 'outofstock' END";

        // Step 2: join stage rows to existing Bill Hicks offers by UPC.
        // distributor_id is added by the shared runner's WHERE clause, so the
        // primary offer key (upc, distributor_id) remains selective without
        // trusting product names as the only identifier.
        $map = new OfferInventorySyncMap(
            self::DIST_ID,
            static::label(),
            $stage_table,
            'S',
            "S.upc <> '' AND S.upc = o.upc",
            [
                'o.qty = S.qty',
                "o.stock_status = {$stock_status_expr}",
                'o.normalized_at = NOW()',
            ],
            "
                NOT (
                        o.qty <=> S.qty
                    AND NULLIF(o.stock_status, '') <=> NULLIF({$stock_status_expr}, '')
                )
            "
        );

        // Step 3: run the shared changed-only UPDATE. The shared runner also
        // marks changed offer rows and refreshes best-offer/product-state/Woo
        // rows when needed.
        return self::update_existing_offers_from_inventory_stage_map($map);
    }

    /**
     * Describe how the current live Bill Hicks product table maps into offers.
     *
     * Product-cron offer fields written here:
     * distributor_product_id, distributor_sku, manufacturer_norm, qty,
     * stock_status, dealer_price, shipping_cost, landed_cost, map_price, msrp,
     * ffl_required, sot_required, dropship_enabled, enabled, and optional
     * shipping dimensions.
     *
     * Bill Hicks-specific differences:
     * - bill_hicks_item_number is the distributor product id/SKU.
     * - inventory_quantity is seeded as 0 by the catalog cron until the
     *   inventory cron exists.
     * - shipping_cost is precomputed by the importer from Bill Hicks' flat
     *   rules: 0 over $500, otherwise 20 for pistols and 15 for everything else.
     * - ffl_required/sot_required are category-code derived because the catalog
     *   feed does not expose dedicated flags.
     *
     * @return array<string,string>
     */
    protected static function product_source_columns(string $live_table): array
    {
        $live_table_exists = self::table_exists_by_name($live_table);

        // Step 1: build typed expressions once so landed cost and changed-row
        // comparisons use the same interpretation as the INSERT/UPDATE writes.
        $qty_expr = self::unsigned_quantity_expr('bh', 'inventory_quantity');
        $dealer_price_expr = self::decimal_expr('bh', 'distributor_price');
        $shipping_cost_expr = self::decimal_expr('bh', 'shipping_cost');

        // Step 2: map Bill Hicks live-table columns to distributor_offers.
        // The shared parent inserts missing offer rows, updates changed rows,
        // disables stale rows, then triggers best-offer/product-state refresh.
        $source_columns = [
            'upc' => 'bh.upc',
            'distributor_product_id' => "NULLIF(TRIM(bh.bill_hicks_item_number), '')",
            'distributor_sku' => "NULLIF(TRIM(bh.bill_hicks_item_number), '')",
            'manufacturer_norm' => "NULLIF(TRIM(bh.manufacturer_norm), '')",
            'qty' => $qty_expr,
            'stock_status' => self::stock_status_expr($qty_expr),
            'dealer_price' => $dealer_price_expr,
            'shipping_cost' => $shipping_cost_expr,
            'landed_cost' => self::landed_cost_expr($dealer_price_expr, $shipping_cost_expr),
            'map_price' => self::decimal_expr('bh', 'retail_map'),
            'msrp' => self::decimal_expr('bh', 'retail_msrp'),
            'ffl_required' => 'CAST(COALESCE(bh.ffl_required, 0) AS UNSIGNED)',
            'sot_required' => 'CAST(COALESCE(bh.sot_required, 0) AS UNSIGNED)',
            'dropship_enabled' => 'CAST(COALESCE(bh.dropship_enabled, 0) AS UNSIGNED)',
            'enabled' => '1',
        ];

        // Step 3: include dimensions when present. These checks keep manual
        // admin backfills safe if an older install has one live buffer table
        // that predates the current schema.
        foreach (self::dimension_source_columns() as $target_column => $source_column) {
            if ($live_table_exists && self::table_has_column($live_table, $source_column)) {
                $source_columns[$target_column] = self::decimal_expr('bh', $source_column, 10, 3);
            }
        }

        return $source_columns;
    }

    /**
     * Bill Hicks live table dimension column -> distributor_offers column.
     *
     * shipping_weight is stored in ounces by the product importer.
     *
     * @return array<string,string>
     */
    private static function dimension_source_columns(): array
    {
        return [
            'shipping_weight_oz' => 'shipping_weight',
            'shipping_length_in' => 'shipping_length',
            'shipping_width_in' => 'shipping_width',
            'shipping_height_in' => 'shipping_height',
        ];
    }
}
