<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Kinseys;

use FFLHub\Distributor\Services\OfferSync\AbstractDistributorTableSyncService;
use FFLHub\Distributor\Services\OfferSync\OfferInventorySyncMap;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kinsey's-specific normalized offer sync.
 *
 * Kinsey's product cron owns the full catalog snapshot after it rebuilds and
 * swaps the double-buffered live table. The cron also carries forward the most
 * recent inventory/pricing snapshot from the previous live table, then schedules
 * the inventory cron immediately afterward for fresher volatile fields.
 *
 * This class maps the newly live Kinsey's product table into
 * fflhub_distributor_offers for active product_state UPCs. It does not download
 * or import Kinsey's API data; it only describes how current Kinsey's table
 * columns become normalized distributor offer columns.
 */
final class KinseysOfferNormalizationService extends AbstractDistributorTableSyncService
{
    private const DIST_ID = 'kinseys';

    protected static function distributor_id(): string
    {
        return self::DIST_ID;
    }

    protected static function label(): string
    {
        return 'Kinsey\'s';
    }

    protected static function source_alias(): string
    {
        return 'k';
    }

    protected static function matched_count_key(): string
    {
        return 'matched_active_kinseys_upcs';
    }

    /**
     * Apply loaded Kinsey's inventory stage rows to existing normalized offer rows.
     *
     * This is the inventory-cron path. It intentionally updates only volatile
     * inventory/pricing fields and never inserts rows.
     * It writes these distributor_offers columns:
     * qty, stock_status, dealer_price, map_price, landed_cost, normalized_at.
     * It does not write shipping_cost because Kinsey's shipping is based on
     * static product data (weight/length/FFL), which belongs to the product cron.
     * It does not write dropship_enabled because Kinsey's inventory data does
     * not own dropship eligibility.
     * The inventory stage is keyed by UPC, matching the live-table update path.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    public static function update_existing_from_inventory_stage(string $stage_table): array
    {
        // Inventory-stage source fields used here:
        // - S.upc: joins the Kinsey's inventory row to an existing normalized
        //   offer row for the same UPC.
        // - S.quantity_on_hand: becomes distributor_offers.qty and drives the
        //   derived stock_status value.
        // - S.price: becomes distributor_offers.dealer_price when non-blank.
        // - S.map_price: becomes distributor_offers.map_price when non-blank.
        //
        // Inventory-stage source fields intentionally not used here:
        // - S.restock_eta has no normalized offer target column right now.
        // - Any product identity, dimensions, FFL/SOT, dropship, and shipping
        //   fields are catalog-owned and remain the product cron's job.

        // 1. Normalize stage values into the typed expressions expected by
        // distributor_offers. The Kinsey's stage stores price/MAP as strings.
        $qty_expr = 'CAST(COALESCE(S.quantity_on_hand, 0) AS UNSIGNED)';
        $stock_status_expr = self::stock_status_expr($qty_expr);

        // 2. Preserve the existing offer price/MAP when Kinsey's sends blanks.
        // This mirrors the live-table inventory update, which only overwrites
        // distributor_price and retail_map when the stage value is non-blank.
        $stage_dealer_price_expr = self::decimal_expr('S', 'price');
        $dealer_price_expr = "
            CASE
                WHEN NULLIF(TRIM(S.price), '') IS NULL THEN o.dealer_price
                ELSE {$stage_dealer_price_expr}
            END
        ";

        $stage_map_price_expr = self::decimal_expr('S', 'map_price');
        $map_price_expr = "
            CASE
                WHEN NULLIF(TRIM(S.map_price), '') IS NULL THEN o.map_price
                ELSE {$stage_map_price_expr}
            END
        ";

        // 3. Landed cost is volatile because dealer_price can change here, but
        // shipping_cost stays product-owned. Use the offer's current shipping
        // estimate rather than recalculating from the inventory stage.
        $landed_cost_expr = self::landed_cost_expr($dealer_price_expr, 'o.shipping_cost');

        // Target distributor_offers fields in this UPDATE:
        // qty, stock_status, dealer_price, map_price, landed_cost, normalized_at.
        //
        // Field-by-field behavior:
        // - qty mirrors the API inventory quantity after casting to an integer.
        // - stock_status is derived from qty, so no separate API flag is needed.
        // - dealer_price and map_price keep the old normalized value when
        //   Kinsey's sends an empty string, matching the live-table updater.
        // - landed_cost uses the resulting dealer_price plus the existing
        //   offer shipping_cost because Kinsey's shipping is based on product
        //   weight/length/FFL and is calculated by the product cron.
        // - normalized_at changes only when at least one target value changes.
        //
        // Fields deliberately not written:
        // shipping_cost, ffl_required, sot_required, manufacturer_norm,
        // dropship_enabled, enabled, distributor_product_id, distributor_sku,
        // and dimensions all belong to the catalog/product sync path.
        // 4. Join by UPC because Kinsey's inventory is keyed by UPC in the live
        // updater and we intentionally do not trust alternate IDs here.
        $map = new OfferInventorySyncMap(
            self::DIST_ID,
            static::label(),
            $stage_table,
            'S',
            "S.upc <> '' AND S.upc = o.upc",
            [
                "o.qty = {$qty_expr}",
                "o.stock_status = {$stock_status_expr}",
                "o.dealer_price = {$dealer_price_expr}",
                "o.map_price = {$map_price_expr}",
                "o.landed_cost = {$landed_cost_expr}",
                'o.normalized_at = NOW()',
            ],
            "
                /* Changed-only guard:
                 * The UPDATE should be cheap on no-op runs. Null-safe <=>
                 * comparisons let MariaDB treat NULLs consistently while still
                 * writing rows where any normalized target value is actually
                 * stale. This avoids unnecessary writes and keeps the affected
                 * row count meaningful for profiling.
                 */
                NOT (
                        o.qty <=> {$qty_expr}
                    AND NULLIF(o.stock_status, '') <=> NULLIF({$stock_status_expr}, '')
                    AND o.dealer_price <=> {$dealer_price_expr}
                    AND o.map_price <=> {$map_price_expr}
                    AND o.landed_cost <=> {$landed_cost_expr}
                )
            "
        );

        // 5. Let the shared runner execute the changed-only UPDATE. Missing
        // Kinsey's offer rows are created by the product-table sync, where the
        // full catalog snapshot exists.
        return self::update_existing_offers_from_inventory_stage_map($map);
    }

    /**
     * Describe how the current live Kinsey's product table maps into offers.
     *
     * When the Kinsey's product cron finishes a full catalog import and swaps
     * the live table, the inherited product sync uses this map to insert/update
     * these distributor_offers fields:
     * distributor_product_id, distributor_sku, manufacturer_norm, qty,
     * stock_status, dealer_price, shipping_cost, landed_cost, map_price, msrp,
     * ffl_required, sot_required, dropship_enabled, enabled, source_updated_at,
     * and shipping dimensions.
     *
     * Kinsey's-specific notes:
     * - kinseys_product_id is the API/orderable product identifier and is used
     *   for both distributor_product_id and distributor_sku.
     * - manufacturer_norm is a simple uppercase normalization of Brand as stored
     *   in the live table. Brand taxonomy cleanup stays outside this table.
     * - shipping_weight is already converted to ounces by KinseysProductParser.
     * - qty/price/MAP are seeded from the product cron's carried-forward live
     *   snapshot; the inventory cron can refresh those volatile fields later.
     *
     * @return array<string,string>
     */
    protected static function product_source_columns(string $live_table): array
    {
        $live_table_exists = self::table_exists_by_name($live_table);

        // Step 1: build typed expressions for the shared INSERT/UPDATE SQL.
        // Kinsey's live table stores most numeric values as strings, while
        // distributor_offers uses typed DECIMAL/INT columns.
        $qty_expr = self::unsigned_quantity_expr('k', 'inventory_quantity');
        $dealer_price_expr = self::decimal_expr('k', 'distributor_price');
        $shipping_cost_expr = self::decimal_expr('k', 'shipping_cost');

        // Step 2: describe the product snapshot in distributor_offers terms.
        // The shared base starts from active product_state UPCs, joins the
        // current Kinsey's live table by UPC, inserts missing offers, updates
        // changed offers, and disables stale carried UPC offers no longer
        // present in the new live table.
        $source_columns = [
            'upc' => 'k.upc',
            'distributor_product_id' => "NULLIF(TRIM(k.kinseys_product_id), '')",
            'distributor_sku' => "NULLIF(TRIM(k.kinseys_product_id), '')",
            'manufacturer_norm' => "NULLIF(UPPER(TRIM(k.manufacturer)), '')",
            'qty' => $qty_expr,
            'stock_status' => self::stock_status_expr($qty_expr),
            'dealer_price' => $dealer_price_expr,
            'shipping_cost' => $shipping_cost_expr,
            'landed_cost' => self::landed_cost_expr($dealer_price_expr, $shipping_cost_expr),
            'map_price' => self::decimal_expr('k', 'retail_map'),
            'msrp' => self::decimal_expr('k', 'retail_msrp'),
            'ffl_required' => 'CAST(COALESCE(k.ffl_required, 0) AS UNSIGNED)',
            'sot_required' => 'CAST(COALESCE(k.sot_required, 0) AS UNSIGNED)',
            'dropship_enabled' => 'CAST(COALESCE(k.dropship_enabled, 0) AS UNSIGNED)',
            'enabled' => '1',
            'source_updated_at' => "NULLIF(TRIM(k.last_seen_utc), '')",
        ];

        // Step 3: append shipping dimensions only when the live table has the
        // expected columns, matching the safe pattern used by other distributors.
        foreach (self::dimension_source_columns() as $target_column => $source_column) {
            if ($live_table_exists && self::table_has_column($live_table, $source_column)) {
                $source_columns[$target_column] = self::decimal_expr('k', $source_column, 10, 3);
            }
        }

        return $source_columns;
    }

    /**
     * Kinsey's live table dimension column -> distributor_offers column.
     *
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
