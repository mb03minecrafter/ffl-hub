<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Lipseys;

use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Distributor\Services\DistributorTableSyncService;

if (!defined('ABSPATH')) {
    exit;
}

final class LipseysOfferNormalizationService
{
    private const DIST_ID = 'lipseys';

    /**
     * Apply loaded Lipsey's pricing/quantity stage rows to existing offer rows.
     *
     * This is the inventory-worker path. It intentionally updates only
     * inventory/pricing-owned fields and never inserts rows.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    public static function update_existing_from_inventory_stage(string $stage_table): array
    {
        global $wpdb;

        if (!$wpdb) {
            throw new \RuntimeException('WordPress database connection is unavailable.');
        }

        $started = microtime(true);

        DistributorOffersStore::ensure_schema();

        $offers_table = DistributorOffersStore::table_name();
        $stage_table = trim($stage_table);
        if ($stage_table === '' || !DistributorTableSyncService::table_exists_by_name($stage_table)) {
            throw new \RuntimeException('Lipsey\'s inventory stage table is unavailable: ' . $stage_table);
        }

        $qty_expr = "CAST(COALESCE(NULLIF(TRIM(S.inventory_quantity), ''), '0') AS UNSIGNED)";
        $stock_status_expr = "CASE WHEN {$qty_expr} > 0 THEN 'instock' ELSE 'outofstock' END";
        $dealer_price_expr = "CAST(NULLIF(TRIM(S.distributor_price), '') AS DECIMAL(12,4))";
        $map_price_expr = "CAST(NULLIF(TRIM(S.retail_map), '') AS DECIMAL(12,4))";
        $landed_cost_expr = "
            CASE
                WHEN {$dealer_price_expr} IS NULL THEN NULL
                ELSE {$dealer_price_expr} + COALESCE(o.shipping_cost, 0.0000)
            END
        ";
        $dropship_enabled_expr = "
            CASE
                WHEN S.can_dropship = 0 THEN 0
                WHEN COALESCE(o.sot_required, 0) = 1 THEN o.dropship_enabled
                ELSE 1
            END
        ";

        $sql = $wpdb->prepare(
            "
                UPDATE {$offers_table} o
                INNER JOIN {$stage_table} S
                    ON S.lipseys_item_number = o.distributor_product_id
                SET
                    o.qty = {$qty_expr},
                    o.stock_status = {$stock_status_expr},
                    o.dealer_price = {$dealer_price_expr},
                    o.map_price = {$map_price_expr},
                    o.landed_cost = {$landed_cost_expr},
                    o.dropship_enabled = {$dropship_enabled_expr},
                    o.normalized_at = NOW()
                WHERE o.distributor_id = %s
                  AND NOT (
                        o.qty <=> {$qty_expr}
                    AND NULLIF(o.stock_status, '') <=> NULLIF({$stock_status_expr}, '')
                    AND o.dealer_price <=> {$dealer_price_expr}
                    AND o.map_price <=> {$map_price_expr}
                    AND o.landed_cost <=> {$landed_cost_expr}
                    AND o.dropship_enabled <=> {$dropship_enabled_expr}
                  )
            ",
            self::DIST_ID
        );

        $updated = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($updated === false) {
            throw new \RuntimeException('Lipsey\'s distributor offers inventory update failed: ' . (string) $wpdb->last_error);
        }

        return [
            'rows' => is_numeric($updated) ? (int) $updated : 0,
            'elapsed_ms' => (microtime(true) - $started) * 1000.0,
        ];
    }

    /**
     * Normalize the current live Lipsey's product table into distributor offers.
     *
     * This is the manual backfill/create path. It intentionally limits inserts
     * to active product_state UPCs and does not modify Woo products or crons.
     *
     * @return array<string,mixed>
     */
    public static function normalize_from_product_table(string $source_live_table): array
    {
        $live_table = trim($source_live_table);

        return DistributorTableSyncService::normalize_product_offers([
            'distributor_id' => self::DIST_ID,
            'label' => 'Lipsey\'s',
            'live_table_label' => 'live Lipsey\'s',
            'source_live_table' => $live_table,
            'source_alias' => 'l',
            'matched_count_key' => 'matched_active_lipseys_upcs',
            'source_columns' => self::product_source_columns($live_table),
        ]);
    }

    /**
     * @return array<string,string>
     */
    private static function product_source_columns(string $live_table): array
    {
        $live_table_exists = DistributorTableSyncService::table_exists_by_name($live_table);
        $qty_expr = DistributorTableSyncService::unsigned_quantity_expr('l', 'inventory_quantity');
        $dealer_price_expr = DistributorTableSyncService::decimal_expr('l', 'distributor_price');
        $shipping_cost_expr = DistributorTableSyncService::decimal_expr('l', 'shipping_cost');
        $source_columns = [
            'upc' => 'l.upc',
            'distributor_product_id' => "NULLIF(TRIM(l.lipseys_item_number), '')",
            'distributor_sku' => "NULLIF(TRIM(l.lipseys_item_number), '')",
            'manufacturer_norm' => "NULLIF(UPPER(TRIM(l.manufacturer)), '')",
            'qty' => $qty_expr,
            'stock_status' => DistributorTableSyncService::stock_status_expr($qty_expr),
            'dealer_price' => $dealer_price_expr,
            'shipping_cost' => $shipping_cost_expr,
            'landed_cost' => DistributorTableSyncService::landed_cost_expr($dealer_price_expr, $shipping_cost_expr),
            'map_price' => DistributorTableSyncService::decimal_expr('l', 'retail_map'),
            'msrp' => DistributorTableSyncService::decimal_expr('l', 'retail_msrp'),
            'ffl_required' => 'CAST(COALESCE(l.ffl_required, 0) AS UNSIGNED)',
            'sot_required' => 'CAST(COALESCE(l.sot_required, 0) AS UNSIGNED)',
            'dropship_enabled' => 'CAST(COALESCE(l.dropship_enabled, 1) AS UNSIGNED)',
            'enabled' => '1',
        ];

        foreach (self::dimension_source_columns() as $target_column => $source_column) {
            if ($live_table_exists && DistributorTableSyncService::table_has_column($live_table, $source_column)) {
                $source_columns[$target_column] = DistributorTableSyncService::decimal_expr('l', $source_column, 10, 3);
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
