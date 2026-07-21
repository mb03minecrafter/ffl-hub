<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\CSSI;

use FFLHub\Distributor\Services\OfferSync\AbstractDistributorTableSyncService;
use FFLHub\Distributor\Services\OfferSync\OfferInventorySyncMap;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CSSI-specific normalized offer sync.
 *
 * CSSI's full product cron downloads the product-feed CSV, loads it into the
 * double-buffered CSSI product table, then swaps the newly imported table live.
 * After that swap, this class maps the newly live CSSI rows into
 * fflhub_distributor_offers for active product_state UPCs.
 *
 * CSSI product feeds currently own the full catalog snapshot plus quantity,
 * dealer price, MAP/MSRP, dropship state, shipping cost, dimensions, and the
 * category-derived FFL/SOT flags stored in the CSSI live table.
 *
 * This class deliberately does not know how to download or import CSSI data.
 * It only describes how an already-live CSSI product table maps into the
 * normalized distributor offers table. The shared base class owns the bulk SQL
 * shape: insert missing carried UPCs, update changed carried UPCs, and disable
 * stale carried UPCs that no longer exist in the live CSSI table.
 */
final class CSSIOfferNormalizationService extends AbstractDistributorTableSyncService
{
    private const DIST_ID = 'cssi';
    private const SHIPPING_NON_FFL_RATE = 8.95;
    private const SHIPPING_NON_FFL_WEIGHT_LBS = 8.0;
    private const SHIPPING_FFL_RATE = 14.95;
    private const SHIPPING_FFL_WEIGHT_LBS = 30.0;
    private const SHIPPING_MINIMUM_ORDER_FEE = 7.50;
    private const SHIPPING_MINIMUM_ORDER_THRESHOLD = 50.0;
    private const SHIPPING_INSURANCE_PER_100 = 1.00;
    private const DEALER_SHIP_FREE_THRESHOLD = 750.0;
    private const DEALER_SHIP_NON_FFL_RATE = 11.95;
    private const DEALER_SHIP_FFL_RATE = 16.95;

    /**
     * Stable distributor id used in fflhub_distributor_offers.distributor_id.
     */
    protected static function distributor_id(): string
    {
        return self::DIST_ID;
    }

    /**
     * Human label used in profiling output and admin result messages.
     */
    protected static function label(): string
    {
        return 'CSSI';
    }

    /**
     * SQL alias for the live CSSI product table in generated normalization SQL.
     */
    protected static function source_alias(): string
    {
        return 'c';
    }

    /**
     * Result-array key for the count of active product_state UPCs found in CSSI.
     */
    protected static function matched_count_key(): string
    {
        return 'matched_active_cssi_upcs';
    }

    /**
     * Apply loaded CSSI /items stage rows to existing normalized offer rows.
     *
     * This is the inventory-cron path. CSSI's /items response is broader than
     * most inventory feeds: it can carry quantity, dealer price, MAP/MSRP,
     * dropship state, serialized/FFL hints, manufacturer, and shipping data. Because
     * the stage table already contains the parsed current API rows, this method
     * updates distributor_offers directly from wp_fflhub_cssi_pq_stage after
     * the live CSSI table has been refreshed.
     *
     * It writes these distributor_offers columns when changed:
     * manufacturer_norm, qty, stock_status, dealer_price, shipping_cost,
     * landed_cost, map_price, msrp, ffl_required, sot_required,
     * dropship_enabled, shipping_weight_oz, shipping_length_in,
     * shipping_width_in, shipping_height_in, normalized_at.
     * The inventory API does not reliably expose category/SOT data, so the
     * SOT expression preserves the product-cron category-derived value unless
     * the stage explicitly turns SOT on.
     *
     * It intentionally does not insert missing rows. Missing CSSI offer rows
     * are seeded by the product-table sync path, where we have the full catalog
     * snapshot and the active product_state UPC filter.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    public static function update_existing_from_inventory_stage(string $stage_table): array
    {
        // 1. Build typed target expressions from the CSSI inventory stage.
        // The /items parser stores values as strings in the stage table. The
        // normalized offers table uses numeric columns, so each assignment and
        // comparison casts the stage value into the offer-table type first.
        $qty_expr = self::unsigned_quantity_expr('S', 'inventory_quantity');
        $stock_status_expr = self::stock_status_expr($qty_expr);

        // CSSI sometimes sends partial item detail in /items. For fields that
        // may be omitted from a page, keep the current offer value when stage
        // is blank instead of erasing product-cron catalog data.
        $manufacturer_norm_expr = "
            CASE
                WHEN NULLIF(TRIM(S.manufacturer), '') IS NULL THEN o.manufacturer_norm
                ELSE NULLIF(UPPER(TRIM(S.manufacturer)), '')
            END
        ";

        $dealer_price_raw_expr = self::decimal_expr('S', 'distributor_price');
        $dealer_price_expr = "
            CASE
                WHEN NULLIF(TRIM(S.distributor_price), '') IS NULL THEN o.dealer_price
                ELSE {$dealer_price_raw_expr}
            END
        ";

        $map_price_raw_expr = self::decimal_expr('S', 'retail_map');
        $map_price_expr = "
            CASE
                WHEN NULLIF(TRIM(S.retail_map), '') IS NULL THEN o.map_price
                ELSE {$map_price_raw_expr}
            END
        ";

        $msrp_raw_expr = self::decimal_expr('S', 'retail_msrp');
        $msrp_expr = "
            CASE
                WHEN NULLIF(TRIM(S.retail_msrp), '') IS NULL THEN o.msrp
                ELSE {$msrp_raw_expr}
            END
        ";

        $stage_ffl_required_expr = 'CAST(COALESCE(S.ffl_required, 0) AS UNSIGNED)';
        $stage_sot_required_expr = 'CAST(COALESCE(S.sot_required, 0) AS UNSIGNED)';
        $sot_required_expr = "
            CASE
                WHEN {$stage_sot_required_expr} = 1 THEN 1
                ELSE o.sot_required
            END
        ";
        $ffl_required_expr = "
            CASE
                WHEN {$stage_ffl_required_expr} = 1 OR {$sot_required_expr} = 1 THEN 1
                ELSE o.ffl_required
            END
        ";
        $dropship_enabled_expr = 'CAST(COALESCE(S.dropship_enabled, 0) AS UNSIGNED)';

        $weight_expr = self::preserve_blank_decimal_expr('S', 'shipping_weight', 'o.shipping_weight_oz');
        $length_expr = self::preserve_blank_decimal_expr('S', 'shipping_length_in', 'o.shipping_length_in');
        $width_expr = self::preserve_blank_decimal_expr('S', 'shipping_width_in', 'o.shipping_width_in');
        $height_expr = self::preserve_blank_decimal_expr('S', 'shipping_height_in', 'o.shipping_height_in');

        // Do not copy S.shipping_cost directly. The inventory stage can be
        // partial, so recalculate from the same effective price/FFL/dropship
        // and weight values this UPDATE is about to persist. If CSSI omits
        // price entirely and the offer has no existing dealer price fallback,
        // preserve the old shipping value instead of inventing a rate.
        $has_price_expr = "
            (
                NULLIF(TRIM(S.distributor_price), '') IS NOT NULL
                OR o.dealer_price IS NOT NULL
            )
        ";
        $shipping_cost_expr = "
            CASE
                WHEN NOT ({$has_price_expr}) THEN o.shipping_cost
                ELSE " . self::shipping_cost_expr(
                    $dealer_price_expr,
                    $weight_expr,
                    $ffl_required_expr,
                    $dropship_enabled_expr
                ) . '
            END
        ';
        $landed_cost_expr = self::landed_cost_expr($dealer_price_expr, $shipping_cost_expr);

        // Target distributor_offers fields in this UPDATE:
        // manufacturer_norm, qty, stock_status, dealer_price, shipping_cost,
        // landed_cost, map_price, msrp, ffl_required, sot_required,
        // dropship_enabled, shipping dimensions, normalized_at.
        // 2. Join stage rows to existing CSSI offer rows by CSSI item id.
        // Product sync stores cssi_item_number as distributor_product_id, which
        // makes this a small indexed join without reading the live CSSI table.
        $map = new OfferInventorySyncMap(
            self::DIST_ID,
            static::label(),
            $stage_table,
            'S',
            "S.cssi_item_number <> '' AND S.cssi_item_number = o.distributor_product_id",
            [
                "o.manufacturer_norm = {$manufacturer_norm_expr}",
                "o.qty = {$qty_expr}",
                "o.stock_status = {$stock_status_expr}",
                "o.dealer_price = {$dealer_price_expr}",
                "o.shipping_cost = {$shipping_cost_expr}",
                "o.landed_cost = {$landed_cost_expr}",
                "o.map_price = {$map_price_expr}",
                "o.msrp = {$msrp_expr}",
                "o.ffl_required = {$ffl_required_expr}",
                "o.sot_required = {$sot_required_expr}",
                "o.dropship_enabled = {$dropship_enabled_expr}",
                "o.shipping_weight_oz = {$weight_expr}",
                "o.shipping_length_in = {$length_expr}",
                "o.shipping_width_in = {$width_expr}",
                "o.shipping_height_in = {$height_expr}",
                'o.normalized_at = NOW()',
            ],
            "
                NOT (
                        o.manufacturer_norm <=> {$manufacturer_norm_expr}
                    AND o.qty <=> {$qty_expr}
                    AND NULLIF(o.stock_status, '') <=> NULLIF({$stock_status_expr}, '')
                    AND o.dealer_price <=> {$dealer_price_expr}
                    AND o.shipping_cost <=> {$shipping_cost_expr}
                    AND o.landed_cost <=> {$landed_cost_expr}
                    AND o.map_price <=> {$map_price_expr}
                    AND o.msrp <=> {$msrp_expr}
                    AND o.ffl_required <=> {$ffl_required_expr}
                    AND o.sot_required <=> {$sot_required_expr}
                    AND o.dropship_enabled <=> {$dropship_enabled_expr}
                    AND o.shipping_weight_oz <=> {$weight_expr}
                    AND o.shipping_length_in <=> {$length_expr}
                    AND o.shipping_width_in <=> {$width_expr}
                    AND o.shipping_height_in <=> {$height_expr}
                )
            "
        );

        // 3. Let the shared runner compile/execute the changed-only UPDATE.
        // A zero row count means the latest CSSI /items stage already matches
        // the normalized offer snapshot.
        return self::update_existing_offers_from_inventory_stage_map($map);
    }

    /**
     * Describe how the current live CSSI product table maps into offers.
     *
     * When the CSSI product cron finishes a full catalog import and swaps the
     * live table, the inherited product sync uses this map to insert/update
     * these distributor_offers fields:
     * distributor_product_id, distributor_sku, manufacturer_norm, qty,
     * stock_status, dealer_price, shipping_cost, landed_cost, map_price, msrp,
     * ffl_required, sot_required, dropship_enabled, enabled, and shipping
     * dimensions.
     *
     * CSSI-specific differences:
     * cssi_item_number is the distributor product id/SKU, manufacturer_norm is
     * derived from the CSSI manufacturer string, and CSSI shipping_weight is
     * already normalized to ounces in the live table.
     *
     * @return array<string,string>
     */
    protected static function product_source_columns(string $live_table): array
    {
        $live_table_exists = self::table_exists_by_name($live_table);

        // Step 1: build reusable typed expressions for the inherited SQL.
        //
        // The CSSI live table mirrors the feed shape and stores most values as
        // strings. distributor_offers is typed more strictly, so the mapping
        // casts before writing. These expressions are reused in multiple target
        // fields so landed_cost, stock_status, and changed-row comparisons all
        // use the exact same interpretation of CSSI quantity/price/shipping.
        $qty_expr = self::unsigned_quantity_expr('c', 'inventory_quantity');
        $dealer_price_expr = self::decimal_expr('c', 'distributor_price');
        $shipping_cost_expr = self::decimal_expr('c', 'shipping_cost');

        // Step 2: describe the CSSI product snapshot in distributor_offers terms.
        //
        // The shared base class reads this map and generates two set-based SQL
        // statements:
        // - INSERT IGNORE missing offer rows for active product_state UPCs.
        // - UPDATE existing offer rows only when one of these expressions differs.
        //
        // Identity fields:
        // cssi_item_number is CSSI's distributor-side item id. We store it in
        // both distributor_product_id and distributor_sku so inventory or future
        // CSSI-specific updates can join by item number without touching the
        // larger CSSI product table.
        //
        // Catalog/manufacturer fields:
        // manufacturer_norm is a simple uppercase trim of CSSI manufacturer.
        // This is intentionally not brand taxonomy logic; it is distributor
        // source normalization for filtering/debugging/rules.
        //
        // Volatile fields:
        // CSSI's full product feed includes quantity and price, so the product
        // sync can seed/update qty, stock_status, dealer_price, shipping_cost,
        // and landed_cost. If CSSI later runs an inventory-stage offer update,
        // that faster path can overwrite the volatile fields afterward.
        //
        // Policy fields:
        // ffl_required/sot_required are copied from the live table. The current
        // CSV import stores these as 0 because the product-feed CSV we ingest
        // does not currently expose trustworthy flags. dropship_enabled is also
        // copied from the live table after CSSI SIG-policy handling.
        $source_columns = [
            'upc' => 'c.upc',
            'distributor_product_id' => "NULLIF(TRIM(c.cssi_item_number), '')",
            'distributor_sku' => "NULLIF(TRIM(c.cssi_item_number), '')",
            'manufacturer_norm' => "NULLIF(UPPER(TRIM(c.manufacturer)), '')",
            'qty' => $qty_expr,
            'stock_status' => self::stock_status_expr($qty_expr),
            'dealer_price' => $dealer_price_expr,
            'shipping_cost' => $shipping_cost_expr,
            'landed_cost' => self::landed_cost_expr($dealer_price_expr, $shipping_cost_expr),
            'map_price' => self::decimal_expr('c', 'retail_map'),
            'msrp' => self::decimal_expr('c', 'retail_msrp'),
            'ffl_required' => 'CAST(COALESCE(c.ffl_required, 0) AS UNSIGNED)',
            'sot_required' => 'CAST(COALESCE(c.sot_required, 0) AS UNSIGNED)',
            'dropship_enabled' => 'CAST(COALESCE(c.dropship_enabled, 0) AS UNSIGNED)',
            'enabled' => '1',
        ];

        // Step 3: append shipping dimensions when this install's live CSSI table
        // has those columns. This keeps the normalizer compatible with older
        // installs/tables while still populating dimensions where available.
        //
        // CSSI shipping_weight is already stored as ounces by the importer, so
        // it maps directly to distributor_offers.shipping_weight_oz.
        foreach (self::dimension_source_columns() as $target_column => $source_column) {
            if ($live_table_exists && self::table_has_column($live_table, $source_column)) {
                $source_columns[$target_column] = self::decimal_expr('c', $source_column, 10, 3);
            }
        }

        return $source_columns;
    }

    /**
     * CSSI live table dimension column -> distributor_offers dimension column.
     *
     * The values are added conditionally in product_source_columns() because the
     * normalizer may be run against existing double-buffered tables created
     * before these columns were introduced.
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

    private static function preserve_blank_decimal_expr(string $stage_alias, string $stage_column, string $fallback_expression): string
    {
        return "
            CASE
                WHEN NULLIF(TRIM({$stage_alias}.{$stage_column}), '') IS NULL THEN {$fallback_expression}
                ELSE " . self::decimal_expr($stage_alias, $stage_column, 10, 3) . "
            END
        ";
    }

    private static function shipping_cost_expr(
        string $dealer_price_expr,
        string $weight_oz_expr,
        string $ffl_required_expr,
        string $dropship_enabled_expr
    ): string {
        $price_expr = "COALESCE({$dealer_price_expr}, 0)";
        $weight_expr = "COALESCE({$weight_oz_expr}, 0)";
        $weight_lb_expr = "CASE WHEN {$weight_expr} > 0 THEN {$weight_expr} / 16 ELSE 1 END";

        $dropship_shipping_expr = sprintf(
            "
            (
                CASE
                    WHEN {$ffl_required_expr} = 1 THEN GREATEST(1, CEIL((%s) / %F)) * %F
                    ELSE GREATEST(1, CEIL((%s) / %F)) * %F
                END
                + CASE WHEN %s > 0 THEN CEIL(%s / 100) * %F ELSE 0 END
                + CASE WHEN %s > 0 AND %s < %F THEN %F ELSE 0 END
            )
        ",
            $weight_lb_expr,
            self::SHIPPING_FFL_WEIGHT_LBS,
            self::SHIPPING_FFL_RATE,
            $weight_lb_expr,
            self::SHIPPING_NON_FFL_WEIGHT_LBS,
            self::SHIPPING_NON_FFL_RATE,
            $price_expr,
            $price_expr,
            self::SHIPPING_INSURANCE_PER_100,
            $price_expr,
            $price_expr,
            self::SHIPPING_MINIMUM_ORDER_THRESHOLD,
            self::SHIPPING_MINIMUM_ORDER_FEE
        );

        $dealer_shipping_expr = sprintf(
            "
            CASE
                WHEN {$price_expr} >= %F THEN 0
                WHEN {$ffl_required_expr} = 1 THEN %F
                ELSE %F
            END
        ",
            self::DEALER_SHIP_FREE_THRESHOLD,
            self::DEALER_SHIP_FFL_RATE,
            self::DEALER_SHIP_NON_FFL_RATE
        );

        return "
            CAST(ROUND(
                CASE
                    WHEN {$dropship_enabled_expr} = 1 THEN ({$dropship_shipping_expr})
                    ELSE ({$dealer_shipping_expr})
                END
            , 2) AS DECIMAL(12,4))
        ";
    }
}
