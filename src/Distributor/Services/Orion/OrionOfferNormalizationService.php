<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Orion;

use FFLHub\Distributor\Services\AbstractDistributorTableSyncService;

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
