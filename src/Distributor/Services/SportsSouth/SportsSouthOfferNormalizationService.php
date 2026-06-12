<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\SportsSouth;

use FFLHub\Distributor\Services\OfferSync\AbstractDistributorTableSyncService;
use FFLHub\Distributor\Services\OfferSync\OfferInventorySyncMap;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sports South-specific normalized offer sync.
 *
 * Sports South's product/catalog cron owns the full current product snapshot:
 * identifiers, manufacturer/category-derived policy flags, inventory, dealer
 * price, MAP/MSRP, dropship eligibility, shipping cost, dimensions, and media.
 *
 * This class deliberately does not download, parse, or import Sports South XML.
 * It only describes how the already-current live Sports South product table
 * maps into fflhub_distributor_offers for active product_state UPCs. The shared
 * base class owns the set-based SQL shape: insert missing carried UPC offers,
 * update changed carried UPC offers, and disable stale carried UPC offers that
 * no longer exist in the current live Sports South table.
 *
 * The inventory path is narrower. IncrementalOnhandUpdate owns current quantity
 * and dealer/customer price, but it does not own MAP/MSRP, FFL/SOT policy,
 * dimensions, or shipping. Those catalog-owned fields stay with the product
 * cron. Inventory-stage updates therefore refresh only volatile offer fields on
 * existing Sports South offers.
 */
final class SportsSouthOfferNormalizationService extends AbstractDistributorTableSyncService
{
    private const DIST_ID = 'sports_south';

    /**
     * Stable distributor id used in fflhub_distributor_offers.distributor_id.
     */
    protected static function distributor_id(): string
    {
        return self::DIST_ID;
    }

    /**
     * Human label used in profiling output and admin/result messages.
     */
    protected static function label(): string
    {
        return 'Sports South';
    }

    /**
     * SQL alias for the live Sports South product table in generated sync SQL.
     */
    protected static function source_alias(): string
    {
        return 'ss';
    }

    /**
     * Result-array key for active product_state UPCs found in Sports South.
     */
    protected static function matched_count_key(): string
    {
        return 'matched_active_sports_south_upcs';
    }

    /**
     * Apply loaded Sports South IncrementalOnhandUpdate rows to existing offers.
     *
     * This is the inventory-cron path. It intentionally updates existing offer
     * rows only and never inserts missing offers. Missing rows are created by
     * the product/catalog sync, where we have the full catalog snapshot and the
     * active product_state UPC filter.
     *
     * It writes these distributor_offers columns when changed:
     * qty, stock_status, dealer_price, landed_cost, normalized_at.
     *
     * It does not write shipping_cost because Sports South shipping is derived
     * from catalog/policy data, not the onhand feed. It does not write MAP/MSRP,
     * FFL/SOT, manufacturer_norm, dimensions, enabled, or dropship fields.
     *
     * @return array{rows:int,elapsed_ms:float,item_rows:int,item_elapsed_ms:float,upc_rows:int,upc_elapsed_ms:float}
     */
    public static function update_existing_from_inventory_stage(string $stage_table): array
    {
        // Step 1: update the normal path first. Sports South IncrementalOnhandUpdate
        // rows are keyed by I/item number in current payloads, and product sync
        // stores that same value as distributor_product_id. This is the fast,
        // expected join and mirrors the live-table item-number update path.
        $item_stats = self::update_existing_from_inventory_stage_by_item_number($stage_table);

        // Step 2: run the compatibility fallback. Older/parser-supported shapes
        // may contain UPC with no item number. Those rows can still refresh an
        // existing offer by UPC, but only when the same offer did not also have
        // an item-number row in this stage window.
        $upc_stats = self::update_existing_from_inventory_stage_by_upc_fallback($stage_table);

        // Step 3: return split metrics. The cron reports item and UPC fallback
        // timings separately because item-number matches are the path we expect
        // to see on real Sports South incremental payloads.
        return [
            'rows' => (int) ($item_stats['rows'] ?? 0) + (int) ($upc_stats['rows'] ?? 0),
            'elapsed_ms' => (float) ($item_stats['elapsed_ms'] ?? 0.0) + (float) ($upc_stats['elapsed_ms'] ?? 0.0),
            'item_rows' => (int) ($item_stats['rows'] ?? 0),
            'item_elapsed_ms' => (float) ($item_stats['elapsed_ms'] ?? 0.0),
            'upc_rows' => (int) ($upc_stats['rows'] ?? 0),
            'upc_elapsed_ms' => (float) ($upc_stats['elapsed_ms'] ?? 0.0),
        ];
    }

    /**
     * Update offers by Sports South item number.
     *
     * The onhand stage table may theoretically contain repeated item numbers in
     * one API window. The join keeps only the latest loaded row for each item by
     * requiring S.id to match MAX(id) for that item_number.
     *
     * Matching fields:
     * - stage S.item_number comes from Sports South I/item number;
     * - offer distributor_product_id comes from sports_south_item_number;
     * - distributor_id is constrained by the shared runner to sports_south.
     *
     * Fields updated by the common map:
     * qty, stock_status, dealer_price, landed_cost, normalized_at.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    private static function update_existing_from_inventory_stage_by_item_number(string $stage_table): array
    {
        return self::run_inventory_stage_update(
            $stage_table,
            "
                S.item_number <> ''
                AND S.item_number = o.distributor_product_id
                AND S.id = (
                    SELECT MAX(S2.id)
                    FROM {$stage_table} S2
                    WHERE S2.item_number = S.item_number
                )
            "
        );
    }

    /**
     * Update offers from rare UPC-only onhand rows.
     *
     * Recent Sports South incremental payloads have item numbers and no UPCs,
     * but the parser and live-table updater both support a UPC fallback. Keep
     * the normalized offers path equivalent: if Sports South ever sends an
     * onhand row with UPC but no item number, update the matching offer by UPC,
     * unless that offer also had an item-number row in the same stage window.
     *
     * This fallback is intentionally narrower than the item-number update:
     * - it requires S.item_number = '' so it cannot compete with the primary
     *   identifier path;
     * - it chooses the latest loaded UPC-only stage row with MAX(id);
     * - it refuses to update an offer if this same stage window contained an
     *   item-number row for that offer, because item number is the stronger
     *   distributor identifier.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    private static function update_existing_from_inventory_stage_by_upc_fallback(string $stage_table): array
    {
        return self::run_inventory_stage_update(
            $stage_table,
            "
                S.item_number = ''
                AND S.upc <> ''
                AND S.upc = o.upc
                AND S.id = (
                    SELECT MAX(S2.id)
                    FROM {$stage_table} S2
                    WHERE S2.item_number = ''
                      AND S2.upc = S.upc
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM {$stage_table} SI
                    WHERE SI.item_number <> ''
                      AND SI.item_number = o.distributor_product_id
                    LIMIT 1
                )
            "
        );
    }

    /**
     * Compile the common Sports South inventory-stage UPDATE.
     *
     * IncrementalOnhandUpdate fields:
     * - Q/current_quantity owns qty and derived stock_status.
     * - C/customer_price owns dealer_price when present.
     * - landed_cost is recalculated from dealer_price plus the existing
     *   product-owned offer shipping_cost.
     * - P/catalog_price is stored on the live Sports South table but does not
     *   map to a distributor_offers column.
     *
     * The changed-only WHERE uses null-safe comparison so unchanged offers are
     * not rewritten. That keeps write volume low and makes the affected-row
     * count meaningful in cron profiling.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    private static function run_inventory_stage_update(string $stage_table, string $join_condition_sql): array
    {
        // Step 1: build typed target expressions from the onhand stage row.
        // current_quantity is numeric in stage, with NULL treated as zero.
        // customer_price is optional; if Sports South omits it, keep the offer's
        // existing dealer_price rather than blanking a product-cron value.
        $qty_expr = 'COALESCE(S.current_quantity, 0)';
        $stock_status_expr = self::stock_status_expr($qty_expr);
        $dealer_price_expr = "
            CASE
                WHEN S.customer_price IS NULL THEN o.dealer_price
                ELSE S.customer_price
            END
        ";
        $landed_cost_expr = self::landed_cost_expr($dealer_price_expr, 'o.shipping_cost');

        // Step 2: describe the UPDATE to the shared runner.
        // The caller supplies only the join condition because Sports South has
        // two valid matching modes. The assignments and changed-row predicate
        // stay identical for item-number and UPC fallback updates.
        $map = new OfferInventorySyncMap(
            self::DIST_ID,
            static::label(),
            $stage_table,
            'S',
            $join_condition_sql,
            [
                "o.qty = {$qty_expr}",
                "o.stock_status = {$stock_status_expr}",
                "o.dealer_price = {$dealer_price_expr}",
                "o.landed_cost = {$landed_cost_expr}",
                'o.normalized_at = NOW()',
            ],
            "
                NOT (
                        o.qty <=> {$qty_expr}
                    AND NULLIF(o.stock_status, '') <=> NULLIF({$stock_status_expr}, '')
                    AND o.dealer_price <=> {$dealer_price_expr}
                    AND o.landed_cost <=> {$landed_cost_expr}
                )
            "
        );

        // Step 3: execute one changed-only set-based UPDATE. This updates only
        // existing Sports South offers; product sync/backfill remains the owner
        // of inserting missing offer rows from complete catalog data.
        return self::update_existing_offers_from_inventory_stage_map($map);
    }

    /**
     * Describe how the current live Sports South product table maps into offers.
     *
     * When the Sports South product cron finishes either a full rebuild/swap or
     * an incremental catalog delta, the inherited product sync uses this map to
     * insert/update these distributor_offers fields:
     * distributor_product_id, distributor_sku, manufacturer_norm, qty,
     * stock_status, dealer_price, shipping_cost, landed_cost, map_price, msrp,
     * ffl_required, sot_required, dropship_enabled, enabled, and shipping
     * dimensions. source_updated_at is populated from Sports South last_seen_utc.
     *
     * Sports South-specific differences:
     * sports_south_item_number is both distributor_product_id and
     * distributor_sku, manufacturer_norm is derived from the mapped brand name,
     * and shipping_weight is already stored as ounces by the parser.
     *
     * @return array<string,string>
     */
    protected static function product_source_columns(string $live_table): array
    {
        $live_table_exists = self::table_exists_by_name($live_table);

        // Step 1: build reusable typed expressions for the inherited SQL.
        //
        // Sports South stores product-table values as strings so the sync casts
        // before writing to the typed distributor_offers columns. Reusing these
        // expressions keeps stock_status, landed_cost, and changed-row checks
        // consistent within the generated INSERT/UPDATE statements.
        $qty_expr = self::unsigned_quantity_expr('ss', 'inventory_quantity');
        $dealer_price_expr = self::decimal_expr('ss', 'distributor_price');
        $shipping_cost_expr = self::decimal_expr('ss', 'shipping_cost');

        // Step 2: describe the Sports South product snapshot in normalized
        // offer terms. The shared base class starts from active product_state
        // UPCs, joins this current live table by UPC, inserts missing offer
        // rows, and updates existing offer rows only when these expressions
        // differ from the current distributor_offers values.
        $source_columns = [
            'upc' => 'ss.upc',
            'distributor_product_id' => "NULLIF(TRIM(ss.sports_south_item_number), '')",
            'distributor_sku' => "NULLIF(TRIM(ss.sports_south_item_number), '')",
            'manufacturer_norm' => "NULLIF(UPPER(TRIM(ss.manufacturer)), '')",
            'qty' => $qty_expr,
            'stock_status' => self::stock_status_expr($qty_expr),
            'dealer_price' => $dealer_price_expr,
            'shipping_cost' => $shipping_cost_expr,
            'landed_cost' => self::landed_cost_expr($dealer_price_expr, $shipping_cost_expr),
            'map_price' => self::decimal_expr('ss', 'retail_map'),
            'msrp' => self::decimal_expr('ss', 'retail_msrp'),
            'ffl_required' => 'CAST(COALESCE(ss.ffl_required, 0) AS UNSIGNED)',
            'sot_required' => 'CAST(COALESCE(ss.sot_required, 0) AS UNSIGNED)',
            'dropship_enabled' => 'CAST(COALESCE(ss.dropship_enabled, 1) AS UNSIGNED)',
            'enabled' => '1',
            'source_updated_at' => "NULLIF(TRIM(ss.last_seen_utc), '')",
        ];

        // Step 3: append dimensions only when this install's live Sports South
        // table has those columns. The table currently has them, but keeping
        // this conditional matches the other distributor implementations and
        // makes the normalizer safe against older double-buffered tables.
        foreach (self::dimension_source_columns() as $target_column => $source_column) {
            if ($live_table_exists && self::table_has_column($live_table, $source_column)) {
                $source_columns[$target_column] = self::decimal_expr('ss', $source_column, 10, 3);
            }
        }

        return $source_columns;
    }

    /**
     * Sports South live table dimension column -> distributor_offers column.
     *
     * shipping_weight is already converted to ounces by SportsSouthProductParser.
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
