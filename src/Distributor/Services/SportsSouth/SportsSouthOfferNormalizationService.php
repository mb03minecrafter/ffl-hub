<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\SportsSouth;

use FFLHub\Distributor\Services\AbstractDistributorTableSyncService;

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
