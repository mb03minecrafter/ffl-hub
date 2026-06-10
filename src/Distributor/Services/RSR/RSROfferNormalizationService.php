<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\RSR;

use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Distributor\Services\DistributorTableSyncService;

if (!defined('ABSPATH')) {
    exit;
}

final class RSROfferNormalizationService
{
    private const DIST_ID = 'rsr';

    /**
     * Apply loaded RSR inventory stage quantities to existing normalized offer rows.
     *
     * This is the inventory-cron path. It intentionally updates only inventory
     * fields and never inserts rows.
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
        $set = [
            'o.qty = S.qty',
            "o.stock_status = CASE WHEN S.qty > 0 THEN 'instock' ELSE 'outofstock' END",
            'o.normalized_at = NOW()',
        ];

        $sql = $wpdb->prepare(
            "
                UPDATE {$offers_table} o
                INNER JOIN {$stage_table} S
                    ON S.rsr_stock_number = o.distributor_product_id
                SET
                    " . implode(",\n                    ", $set) . "
                WHERE o.distributor_id = %s
                    AND NOT (o.qty <=> S.qty)
            ",
            self::DIST_ID
        );

        $updated = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($updated === false) {
            throw new \RuntimeException('RSR distributor offers update failed: ' . (string) $wpdb->last_error);
        }

        return [
            'rows' => is_numeric($updated) ? (int) $updated : 0,
            'elapsed_ms' => (microtime(true) - $started) * 1000.0,
        ];
    }

    /**
     * Normalize the current live RSR product table into distributor offers.
     *
     * This is intentionally set-based and only touches rows for UPCs already
     * present in active product_state.
     *
     * @return array<string,mixed>
     */
    public static function normalize_from_product_table(string $source_live_table): array
    {
        $live_table = trim($source_live_table);

        return DistributorTableSyncService::normalize_product_offers([
            'distributor_id' => self::DIST_ID,
            'label' => 'RSR',
            'live_table_label' => 'live RSR',
            'source_live_table' => $live_table,
            'source_alias' => 'r',
            'matched_count_key' => 'matched_active_rsr_upcs',
            'source_columns' => self::product_source_columns($live_table),
        ]);
    }

    /**
     * @return array<string,string>
     */
    private static function product_source_columns(string $live_table): array
    {
        $live_table_exists = DistributorTableSyncService::table_exists_by_name($live_table);
        $qty_expr = DistributorTableSyncService::unsigned_quantity_expr('r', 'inventory_quantity');
        $dealer_price_expr = DistributorTableSyncService::decimal_expr('r', 'distributor_price');
        $shipping_cost_expr = ($live_table_exists && DistributorTableSyncService::table_has_column($live_table, 'shipping_cost'))
            ? 'COALESCE(' . DistributorTableSyncService::decimal_expr('r', 'shipping_cost') . ', 10.0000)'
            : '10.0000';
        $source_columns = [
            'upc' => 'r.upc',
            'distributor_product_id' => "NULLIF(TRIM(r.rsr_stock_number), '')",
            'distributor_sku' => "NULLIF(TRIM(r.rsr_stock_number), '')",
            'manufacturer_norm' => "NULLIF(TRIM(r.manufacturer_id), '')",
            'qty' => $qty_expr,
            'stock_status' => DistributorTableSyncService::stock_status_expr($qty_expr),
            'dealer_price' => $dealer_price_expr,
            'shipping_cost' => $shipping_cost_expr,
            'landed_cost' => DistributorTableSyncService::landed_cost_expr($dealer_price_expr, $shipping_cost_expr),
            'map_price' => DistributorTableSyncService::decimal_expr('r', 'retail_map'),
            'msrp' => DistributorTableSyncService::decimal_expr('r', 'retail_msrp'),
            'ffl_required' => '0',
            'sot_required' => 'CAST(COALESCE(r.sot_required, 0) AS UNSIGNED)',
            'dropship_enabled' => 'CAST(COALESCE(r.dropship_enabled, 1) AS UNSIGNED)',
            'enabled' => '1',
        ];

        foreach (self::dimension_source_columns() as $target_column => $source_column) {
            if ($live_table_exists && DistributorTableSyncService::table_has_column($live_table, $source_column)) {
                $source_columns[$target_column] = DistributorTableSyncService::decimal_expr('r', $source_column, 10, 3);
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
