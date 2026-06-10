<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\CSSI;

use FFLHub\Distributor\Services\AbstractDistributorTableSyncService;

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
 * dealer price, MAP/MSRP, dropship state, shipping cost, and dimensions. The
 * product-feed rows do not currently expose reliable FFL/SOT data in the CSV
 * shape we ingest, so those flags are copied exactly as stored in the CSSI live
 * table.
 */
final class CSSIOfferNormalizationService extends AbstractDistributorTableSyncService
{
    private const DIST_ID = 'cssi';

    protected static function distributor_id(): string
    {
        return self::DIST_ID;
    }

    protected static function label(): string
    {
        return 'CSSI';
    }

    protected static function source_alias(): string
    {
        return 'c';
    }

    protected static function matched_count_key(): string
    {
        return 'matched_active_cssi_upcs';
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

        // Build typed expressions for inherited product-table insert/update SQL.
        // CSSI stores feed values as strings in the live table, so the shared
        // sync casts prices, quantities, dimensions, and flags before writing
        // to distributor_offers.
        $qty_expr = self::unsigned_quantity_expr('c', 'inventory_quantity');
        $dealer_price_expr = self::decimal_expr('c', 'distributor_price');
        $shipping_cost_expr = self::decimal_expr('c', 'shipping_cost');

        // Product-cron offer fields:
        // - identity: distributor_product_id, distributor_sku, manufacturer_norm
        // - current snapshot: qty, stock_status, dealer_price, shipping_cost,
        //   landed_cost, map_price, msrp
        // - policy flags: ffl_required, sot_required, dropship_enabled, enabled
        // The inherited sync inserts these for missing rows and updates them
        // for existing rows when the expressions differ from distributor_offers.
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

        // Include optional dimensions only when present on the current live
        // table, avoiding schema assumptions across existing installs.
        foreach (self::dimension_source_columns() as $target_column => $source_column) {
            if ($live_table_exists && self::table_has_column($live_table, $source_column)) {
                $source_columns[$target_column] = self::decimal_expr('c', $source_column, 10, 3);
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
