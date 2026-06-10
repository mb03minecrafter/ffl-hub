<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\RSR;

use FFLHub\Distributor\Services\AbstractDistributorTableSyncService;
use FFLHub\Distributor\Services\OfferSync\DistributorOfferSyncSqlRunner;
use FFLHub\Distributor\Services\OfferSync\OfferInventorySyncMap;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RSR-specific normalized offer sync.
 *
 * The product/catalog path inherits the shared product-table sync and maps the
 * current live RSR product table into fflhub_distributor_offers for carried
 * UPCs. That path seeds the static/catalog snapshot plus current price/qty.
 *
 * The RSR inventory feed is narrower than the product feed. It only carries
 * stock number + quantity, so the inventory-stage update below intentionally
 * updates only volatile quantity/stock fields on existing offer rows.
 */
final class RSROfferNormalizationService extends AbstractDistributorTableSyncService
{
    private const DIST_ID = 'rsr';

    protected static function distributor_id(): string
    {
        return self::DIST_ID;
    }

    protected static function label(): string
    {
        return 'RSR';
    }

    protected static function source_alias(): string
    {
        return 'r';
    }

    protected static function matched_count_key(): string
    {
        return 'matched_active_rsr_upcs';
    }

    /**
     * Apply loaded RSR inventory stage quantities to existing normalized offer rows.
     *
     * This is the inventory-cron path. It intentionally updates only inventory
     * fields and never inserts rows.
     * It writes only these distributor_offers columns:
     * qty, stock_status, normalized_at.
     * It does not write price, shipping, MAP, FFL/SOT, manufacturer, or
     * dropship fields because the RSR inventory CSV does not own those values.
     * Product crons/backfills are responsible for creating offer rows because
     * the inventory CSV does not contain enough catalog fields to seed them.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    public static function update_existing_from_inventory_stage(string $stage_table): array
    {
        // Target distributor_offers fields in this UPDATE:
        // qty, stock_status, normalized_at.
        // 1. RSR inventory stage owns only quantity. Stock status is derived
        // from that quantity, and product/catalog fields are left untouched.
        $set = [
            'o.qty = S.qty',
            "o.stock_status = CASE WHEN S.qty > 0 THEN 'instock' ELSE 'outofstock' END",
            'o.normalized_at = NOW()',
        ];

        // 2. Join stage to existing RSR offers by stock number. The stage table
        // stores rsr_stock_number, which product sync also stores as
        // distributor_product_id in fflhub_distributor_offers.
        $map = new OfferInventorySyncMap(
            self::DIST_ID,
            static::label(),
            $stage_table,
            'S',
            'S.rsr_stock_number = o.distributor_product_id',
            $set,
            'NOT (o.qty <=> S.qty)'
        );

        // 3. Only write rows whose quantity changed. Stock status is implied by
        // qty, so comparing qty alone is enough for this inventory path.
        return DistributorOfferSyncSqlRunner::update_existing_offers_from_inventory_stage($map);
    }

    /**
     * Describe how the current live RSR product table maps into offers.
     *
     * RSR uses rsr_stock_number as both distributor_product_id and
     * distributor_sku. Shipping cost is read from the live table when available,
     * with the current RSR fallback kept here so older tables still normalize.
     *
     * When the RSR product cron finishes a full catalog import and swaps the
     * live table, the inherited product sync uses this map to insert/update
     * these distributor_offers fields:
     * distributor_product_id, distributor_sku, manufacturer_norm, qty,
     * stock_status, dealer_price, shipping_cost, landed_cost, map_price, msrp,
     * ffl_required, sot_required, dropship_enabled, enabled, and optional
     * shipping dimensions.
     *
     * RSR-specific differences:
     * ffl_required is hard-coded to 0 here, manufacturer_norm comes from
     * manufacturer_id, and shipping_cost falls back to 10.0000 if the live
     * table does not have a shipping_cost column yet.
     *
     * @return array<string,string>
     */
    protected static function product_source_columns(string $live_table): array
    {
        $live_table_exists = self::table_exists_by_name($live_table);

        // Build typed expressions for inherited product-table insert/update SQL.
        // RSR stores many product values as strings in the live table.
        $qty_expr = self::unsigned_quantity_expr('r', 'inventory_quantity');
        $dealer_price_expr = self::decimal_expr('r', 'distributor_price');

        // RSR shipping became a live-table column later. Keep the fallback so
        // product sync still works if an older table is normalized.
        $shipping_cost_expr = ($live_table_exists && self::table_has_column($live_table, 'shipping_cost'))
            ? 'COALESCE(' . self::decimal_expr('r', 'shipping_cost') . ', 10.0000)'
            : '10.0000';

        // Product-cron offer fields:
        // - identity: distributor_product_id, distributor_sku, manufacturer_norm
        // - current snapshot: qty, stock_status, dealer_price, shipping_cost,
        //   landed_cost, map_price, msrp
        // - policy flags: ffl_required, sot_required, dropship_enabled, enabled
        // The inherited sync inserts these for missing rows and updates them
        // for existing rows when the expressions differ from distributor_offers.
        $source_columns = [
            'upc' => 'r.upc',
            'distributor_product_id' => "NULLIF(TRIM(r.rsr_stock_number), '')",
            'distributor_sku' => "NULLIF(TRIM(r.rsr_stock_number), '')",
            'manufacturer_norm' => "NULLIF(TRIM(r.manufacturer_id), '')",
            'qty' => $qty_expr,
            'stock_status' => self::stock_status_expr($qty_expr),
            'dealer_price' => $dealer_price_expr,
            'shipping_cost' => $shipping_cost_expr,
            'landed_cost' => self::landed_cost_expr($dealer_price_expr, $shipping_cost_expr),
            'map_price' => self::decimal_expr('r', 'retail_map'),
            'msrp' => self::decimal_expr('r', 'retail_msrp'),
            'ffl_required' => '0',
            'sot_required' => 'CAST(COALESCE(r.sot_required, 0) AS UNSIGNED)',
            'dropship_enabled' => 'CAST(COALESCE(r.dropship_enabled, 1) AS UNSIGNED)',
            'enabled' => '1',
        ];

        // Include optional dimensions only when present on the current live
        // table, avoiding schema assumptions across existing installs.
        foreach (self::dimension_source_columns() as $target_column => $source_column) {
            if ($live_table_exists && self::table_has_column($live_table, $source_column)) {
                $source_columns[$target_column] = self::decimal_expr('r', $source_column, 10, 3);
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
