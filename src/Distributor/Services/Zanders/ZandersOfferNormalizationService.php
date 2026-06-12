<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Zanders;

use FFLHub\Distributor\Services\OfferSync\AbstractDistributorTableSyncService;
use FFLHub\Distributor\Services\OfferSync\OfferInventorySyncMap;
use FFLHub\Distributor\Services\SigDropshipApproval;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Zanders-specific normalized offer sync.
 *
 * Product/catalog sync is inherited from AbstractDistributorTableSyncService
 * and maps the freshly swapped live Zanders table into distributor offers for
 * carried UPCs. That path owns the full product snapshot.
 *
 * Zanders inventory updates are handled here because liveinv.csv owns volatile
 * quantity and price, Zanders shipping is derived from price, and SIG approval
 * can change dropship state for non-SOT SIG rows.
 */
final class ZandersOfferNormalizationService extends AbstractDistributorTableSyncService
{
    private const DIST_ID = 'zanders';

    protected static function distributor_id(): string
    {
        return self::DIST_ID;
    }

    protected static function label(): string
    {
        return 'Zanders';
    }

    protected static function source_alias(): string
    {
        return 'z';
    }

    protected static function matched_count_key(): string
    {
        return 'matched_active_zanders_upcs';
    }

    /**
     * Apply loaded Zanders inventory stage rows to existing normalized offer rows.
     *
     * This is the inventory-cron path. It intentionally updates only volatile
     * fields and never inserts rows.
     * It writes these distributor_offers columns:
     * qty, stock_status, dealer_price, shipping_cost, landed_cost,
     * dropship_enabled, normalized_at.
     * It does not write MAP/MSRP, FFL/SOT, manufacturer_norm, enabled, or
     * dimensions because those are product/catalog-owned fields.
     * The stage table is joined by Zanders item number, which is stored as
     * distributor_product_id on normalized Zanders offer rows.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    public static function update_existing_from_inventory_stage(string $stage_table): array
    {
        // 1. Resolve the optional SIG approval condition once for this run.
        // If Zanders SIG approval is off, the generated CASE expressions use a
        // permanent false condition and leave existing dropship state untouched.
        $sig_approval_enabled = SigDropshipApproval::is_distributor_sig_approved(self::DIST_ID);
        $sig_offer_where_sql = $sig_approval_enabled
            ? self::offer_sig_approval_where_sql('o')
            : '0 = 1';

        // Target distributor_offers fields in this UPDATE:
        // qty, stock_status, dealer_price, shipping_cost, landed_cost,
        // dropship_enabled, normalized_at.
        // 2. Build volatile field assignments from liveinv.csv stage data.
        // Zanders inventory owns quantity and price. Shipping is derived from
        // price here, and landed_cost is recalculated from dealer + shipping.
        $set = [
            'o.qty = IFNULL(S.available, 0)',
            "o.stock_status = CASE WHEN IFNULL(S.available, 0) > 0 THEN 'instock' ELSE 'outofstock' END",
            'o.dealer_price = S.price1',
            'o.shipping_cost = CASE WHEN S.price1 >= 500 THEN 0 ELSE 15 END',
            'o.landed_cost = CASE WHEN S.price1 IS NULL THEN NULL ELSE S.price1 + CASE WHEN S.price1 >= 500 THEN 0 ELSE 15 END END',
            "o.dropship_enabled = CASE WHEN {$sig_offer_where_sql} THEN 1 ELSE o.dropship_enabled END",
            'o.normalized_at = NOW()',
        ];

        // 3. SIG approval can flip eligible non-SOT SIG rows back to dropship.
        // Keep this as a changed-row condition so the UPDATE does not rewrite
        // every SIG row on every inventory run.
        $sig_offer_changed_sql = "(
            {$sig_offer_where_sql}
            AND NOT (o.dropship_enabled <=> 1)
        )";

        // 4. Join stage rows by Zanders item number and update existing offers.
        // This inventory path does not insert rows; the product cron creates
        // complete offer snapshots after a full catalog import.
        $map = new OfferInventorySyncMap(
            self::DIST_ID,
            static::label(),
            $stage_table,
            'S',
            'S.itemnumber = o.distributor_product_id',
            $set,
            "
                NOT (o.qty <=> IFNULL(S.available, 0))
                OR NOT (o.dealer_price <=> S.price1)
                OR {$sig_offer_changed_sql}
            "
        );

        // 5. Let the shared runner compile/execute the changed-only UPDATE.
        // A zero count is expected when the stage quantity/price/SIG state
        // matches the current offer snapshot.
        return self::update_existing_offers_from_inventory_stage_map($map);
    }

    /**
     * Describe how the current live Zanders product table maps into offers.
     *
     * These expressions are consumed by the inherited product-table sync after
     * the Zanders catalog cron swaps tables. Inventory-stage updates may refresh
     * the volatile fields later, but this product path produces a complete
     * snapshot from the newly live catalog table.
     *
     * When the Zanders product cron finishes a full catalog import and swaps
     * the live table, the inherited product sync uses this map to insert/update
     * these distributor_offers fields:
     * distributor_product_id, distributor_sku, manufacturer_norm, qty,
     * stock_status, dealer_price, shipping_cost, landed_cost, map_price, msrp,
     * ffl_required, sot_required, dropship_enabled, enabled, shipping_weight_oz.
     *
     * Zanders-specific differences:
     * manufacturer_norm is already normalized in the Zanders live table, item
     * number is used as both distributor_product_id and distributor_sku, and
     * only shipping_weight_oz is mapped here rather than full dimensions.
     *
     * @return array<string,string>
     */
    protected static function product_source_columns(string $live_table): array
    {
        // Build typed expressions for inherited product-table insert/update SQL.
        // These expressions read from the newly live Zanders catalog table.
        $qty_expr = self::unsigned_quantity_expr('z', 'inventory_quantity');
        $dealer_price_expr = self::decimal_expr('z', 'distributor_price');
        $shipping_cost_expr = self::decimal_expr('z', 'shipping_cost');

        // Product-cron offer fields:
        // - identity: distributor_product_id, distributor_sku, manufacturer_norm
        // - current snapshot: qty, stock_status, dealer_price, shipping_cost,
        //   landed_cost, map_price, msrp
        // - policy flags: ffl_required, sot_required, dropship_enabled, enabled
        // - shipping data: shipping_weight_oz
        // The inherited sync inserts these for missing rows and updates them
        // for existing rows when the expressions differ from distributor_offers.
        return [
            'upc' => 'z.upc',
            'distributor_product_id' => "NULLIF(TRIM(z.zanders_item_number), '')",
            'distributor_sku' => "NULLIF(TRIM(z.zanders_item_number), '')",
            'manufacturer_norm' => "NULLIF(TRIM(z.manufacturer_norm), '')",
            'qty' => $qty_expr,
            'stock_status' => self::stock_status_expr($qty_expr),
            'dealer_price' => $dealer_price_expr,
            'shipping_cost' => $shipping_cost_expr,
            'landed_cost' => self::landed_cost_expr($dealer_price_expr, $shipping_cost_expr),
            'map_price' => self::decimal_expr('z', 'retail_map'),
            'msrp' => self::decimal_expr('z', 'retail_msrp'),
            'ffl_required' => 'CAST(COALESCE(z.ffl_required, 0) AS UNSIGNED)',
            'sot_required' => 'CAST(COALESCE(z.sot_required, 0) AS UNSIGNED)',
            'dropship_enabled' => 'CAST(COALESCE(z.dropship_enabled, 1) AS UNSIGNED)',
            'enabled' => '1',
            'shipping_weight_oz' => self::decimal_expr('z', 'shipping_weight', 10, 3),
        ];
    }

    private static function offer_sig_approval_where_sql(string $alias): string
    {
        $alias = trim($alias);
        $prefix = $alias !== '' ? $alias . '.' : '';

        // SIG approval never applies to SOT rows. Manufacturer normalization is
        // intentionally broad because old rows may contain pre-normalized SIG
        // variants while newer Zanders rows use manufacturer_norm = SIG SAUER.
        return "
            COALESCE({$prefix}sot_required, 0) = 0
            AND (
                   {$prefix}manufacturer_norm IN ('SIG', 'SIGSAUER', 'SIG SAUER', 'SIGARMS', 'SIG ARMS')
                OR {$prefix}manufacturer_norm LIKE 'SIGSAUER%'
                OR {$prefix}manufacturer_norm LIKE 'SIG SAUER%'
                OR {$prefix}manufacturer_norm LIKE 'SIGARMS%'
                OR {$prefix}manufacturer_norm LIKE 'SIG ARMS%'
            )
        ";
    }

}
