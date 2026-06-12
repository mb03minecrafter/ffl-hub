<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Orion;

use FFLHub\Distributor\Services\OfferSync\AbstractDistributorTableSyncService;
use FFLHub\Distributor\Services\OfferSync\OfferInventorySyncMap;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orion-specific normalized offer sync.
 *
 * Orion's product cron pulls the full get_catalog response, rebuilds the
 * double-buffered Orion product table, then swaps the new table live. This
 * class maps that newly live Orion table into fflhub_distributor_offers for
 * active product_state UPCs.
 *
 * Important Orion product-cron distinction:
 * when fflhub_orion_product_refresh_inventory_during_import is false, the
 * product cron carries forward inventory_quantity/sale_price from the previous
 * live Orion table. Because that snapshot is not fresh API inventory, this
 * product normalizer deliberately does not write qty or stock_status. A future
 * Orion inventory-stage normalizer should own those fields.
 *
 * Orion inventory-cron distinction:
 * the inventory cron already stages Orion product_id, product_code, quantity,
 * and sale_price. This class uses that stage to update existing normalized
 * offer rows without joining the large live Orion product table.
 */
final class OrionOfferNormalizationService extends AbstractDistributorTableSyncService
{
    private const DIST_ID = 'orion';

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
        return 'Orion';
    }

    /**
     * SQL alias for the live Orion product table in generated sync SQL.
     *
     * Avoid "or" because it is a SQL keyword, and avoid "o" because the shared
     * runner uses "o" for the distributor_offers table.
     */
    protected static function source_alias(): string
    {
        return 'op';
    }

    /**
     * Result-array key for active product_state UPCs found in Orion.
     */
    protected static function matched_count_key(): string
    {
        return 'matched_active_orion_upcs';
    }

    /**
     * Apply loaded Orion inventory stage rows to existing normalized offer rows.
     *
     * This is the inventory-cron path. It intentionally updates only volatile
     * inventory/pricing fields and never inserts rows.
     * It writes these distributor_offers columns:
     * qty, stock_status, dealer_price, landed_cost, normalized_at.
     * It does not write shipping_cost because Orion shipping is product-owned
     * and currently flat from the product/catalog table. It also does not write
     * MAP/MSRP, FFL/SOT, manufacturer_norm, dropship, dimensions, enabled, or
     * identity columns because the inventory endpoint does not own them.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    public static function update_existing_from_inventory_stage(string $stage_table): array
    {
        // This method mirrors the other distributor inventory normalizers:
        // it translates the already-loaded inventory stage into normalized
        // offer columns and lets the shared SQL runner execute one changed-only
        // UPDATE. It never inserts rows because the product cron/backfill path
        // is responsible for creating Orion offer identities.

        // Inventory-stage source fields used here:
        // - S.product_id joins to distributor_offers.distributor_product_id.
        // - S.quantity becomes qty and drives derived stock_status.
        // - S.sale_price becomes dealer_price when nonblank.
        //
        // Inventory-stage source fields intentionally not used here:
        // - S.product_code is a live-table compatibility fallback only. It is
        //   not the normalized offer identity for Orion.
        //
        // Stage product_code is intentionally not used for normalized offers.
        // Orion product sync stores product_id as distributor_product_id, which
        // is the stable API/order identifier used for optimized inventory runs.

        // 1. Normalize stage values into the typed expressions expected by
        // distributor_offers. quantity is already an unsigned int in stage, but
        // COALESCE keeps the expression safe if an old table permits NULL.
        $qty_expr = 'CAST(COALESCE(S.quantity, 0) AS UNSIGNED)';
        $stock_status_expr = self::stock_status_expr($qty_expr);

        // 2. Preserve the existing normalized dealer price when Orion sends a
        // blank sale_price. This mirrors the live-table inventory update, which
        // only overwrites distributor_price when S.sale_price is nonblank.
        $stage_dealer_price_expr = self::decimal_expr('S', 'sale_price');
        $dealer_price_expr = "
            CASE
                WHEN NULLIF(TRIM(S.sale_price), '') IS NULL THEN o.dealer_price
                ELSE {$stage_dealer_price_expr}
            END
        ";

        // 3. Landed cost is volatile because dealer_price can change here, but
        // shipping_cost stays product-owned. Use the offer's current shipping
        // estimate rather than recalculating from the inventory stage.
        $landed_cost_expr = self::landed_cost_expr($dealer_price_expr, 'o.shipping_cost');

        // Target distributor_offers fields in this UPDATE:
        // qty, stock_status, dealer_price, landed_cost, normalized_at.
        //
        // Fields deliberately not written:
        // shipping_cost, map_price, msrp, ffl_required, sot_required,
        // manufacturer_norm, dropship_enabled, enabled, distributor_product_id,
        // distributor_sku, source_updated_at, and dimensions all belong to the
        // product/catalog sync path.
        // 4. Join by Orion product ID because product sync stores that value as
        // distributor_product_id, and optimized inventory requests are built
        // from that same offer identity column.
        $map = new OfferInventorySyncMap(
            self::DIST_ID,
            static::label(),
            $stage_table,
            'S',
            "S.product_id <> '' AND S.product_id = o.distributor_product_id",
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

        // 5. Let the shared base/runner execute the changed-only UPDATE.
        // Missing Orion offer rows are created by the product-table sync, where
        // the full catalog snapshot and active product_state filter exist.
        // On the first run after product sync creates offer rows, this can
        // legitimately update many rows because qty/stock_status were not
        // seeded by the product cron. Later no-op runs should update zero rows.
        return self::update_existing_offers_from_inventory_stage_map($map);
    }

    /**
     * Describe how the current live Orion product table maps into offers.
     *
     * When the Orion product cron finishes a full catalog import and swaps the
     * live table, the inherited product sync uses this map to insert/update
     * these distributor_offers fields:
     * distributor_product_id, distributor_sku, manufacturer_norm, dealer_price,
     * shipping_cost, landed_cost, map_price, msrp, ffl_required, sot_required,
     * dropship_enabled, enabled, source_updated_at, and shipping dimensions.
     *
     * Orion-specific differences:
     * - orion_product_id is the order/API product identifier, so it becomes
     *   distributor_product_id.
     * - orion_product_code is the distributor SKU/code, so it becomes
     *   distributor_sku.
     * - shipping_cost is currently Orion's flat stored table value.
     * - qty and stock_status are intentionally omitted from this product-cron
     *   map because the product cron may carry them forward from the previous
     *   live table instead of fetching fresh inventory.
     *
     * @return array<string,string>
     */
    protected static function product_source_columns(string $live_table): array
    {
        $live_table_exists = self::table_exists_by_name($live_table);

        // Step 1: build reusable typed expressions for the inherited SQL.
        //
        // Orion stores price and shipping values as strings in the live product
        // table. distributor_offers stores DECIMAL columns, so we cast once and
        // reuse those expressions for dealer_price, shipping_cost, landed_cost,
        // and the generated changed-row comparisons.
        $dealer_price_expr = self::decimal_expr('op', 'distributor_price');
        $shipping_cost_expr = self::decimal_expr('op', 'shipping_cost');

        // Step 2: describe the Orion catalog snapshot in normalized offer terms.
        //
        // The shared base starts from active product_state UPCs, joins the new
        // live Orion table by UPC, inserts missing Orion offer rows, updates
        // existing rows when mapped values changed, and disables stale carried
        // UPC offers that disappeared from the current Orion table.
        //
        // Volatile inventory fields intentionally not present:
        // qty and stock_status are not listed here, so the product-cron upsert
        // will not overwrite any existing normalized Orion quantity snapshot.
        $source_columns = [
            'upc' => 'op.upc',
            'distributor_product_id' => "NULLIF(TRIM(op.orion_product_id), '')",
            'distributor_sku' => "NULLIF(TRIM(op.orion_product_code), '')",
            'manufacturer_norm' => "NULLIF(UPPER(TRIM(op.manufacturer)), '')",
            'dealer_price' => $dealer_price_expr,
            'shipping_cost' => $shipping_cost_expr,
            'landed_cost' => self::landed_cost_expr($dealer_price_expr, $shipping_cost_expr),
            'map_price' => self::decimal_expr('op', 'retail_map'),
            'msrp' => self::decimal_expr('op', 'retail_msrp'),
            'ffl_required' => 'CAST(COALESCE(op.ffl_required, 0) AS UNSIGNED)',
            'sot_required' => 'CAST(COALESCE(op.sot_required, 0) AS UNSIGNED)',
            'dropship_enabled' => 'CAST(COALESCE(op.dropship_enabled, 0) AS UNSIGNED)',
            'enabled' => '1',
            'source_updated_at' => "NULLIF(TRIM(op.last_seen_utc), '')",
        ];

        // Step 3: append dimensions only when the current live Orion table has
        // the columns. The current schema includes them, but the guard keeps the
        // normalizer safe against older double-buffered tables.
        //
        // OrionProductParser converts catalog weight from pounds to ounces, so
        // shipping_weight maps directly to shipping_weight_oz.
        foreach (self::dimension_source_columns() as $target_column => $source_column) {
            if ($live_table_exists && self::table_has_column($live_table, $source_column)) {
                $source_columns[$target_column] = self::decimal_expr('op', $source_column, 10, 3);
            }
        }

        return $source_columns;
    }

    /**
     * Return Orion product IDs that are already represented in normalized offers.
     *
     * The optimized Orion inventory cron uses this as its request list for
     * get_catalog_inventory(product_ids=...). Do not filter by stock status:
     * out-of-stock carried items still need to be requested so they can come
     * back in stock without waiting for a full inventory pull.
     *
     * @return string[]
     */
    public static function enabled_offer_product_ids_for_inventory(): array
    {
        global $wpdb;

        // Optimized inventory runs are intentionally driven by
        // distributor_offers, not product_state and not the live Orion table.
        // distributor_offers is the carried, normalized offer set and already
        // stores Orion's API product id in distributor_product_id.
        $offers_table = $wpdb->prefix . 'fflhub_distributor_offers';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $offers_table));
        if (!is_string($found) || $found !== $offers_table) {
            return [];
        }

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "
                SELECT DISTINCT distributor_product_id
                FROM {$offers_table}
                WHERE distributor_id = %s
                  AND enabled = 1
                  AND distributor_product_id IS NOT NULL
                  AND distributor_product_id <> ''
                ORDER BY distributor_product_id
                ",
                self::DIST_ID
            )
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if (!is_array($rows)) {
            return [];
        }

        // Dedupe in PHP after the DISTINCT query as a cheap extra guard. The
        // API request should contain clean, stable product IDs only; no stock
        // filter is applied because out-of-stock items need to be requested so
        // they can come back in stock during optimized runs.
        $ids = [];
        foreach ($rows as $row) {
            $id = trim((string) $row);
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Orion live table dimension column -> distributor_offers dimension column.
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
