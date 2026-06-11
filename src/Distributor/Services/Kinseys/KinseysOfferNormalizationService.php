<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Kinseys;

use FFLHub\Distributor\Services\AbstractDistributorTableSyncService;

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
