<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Zanders;

use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Distributor\Services\DistributorTableSyncService;
use FFLHub\Distributor\Services\SigDropshipApproval;

if (!defined('ABSPATH')) {
    exit;
}

final class ZandersOfferNormalizationService
{
    private const DIST_ID = 'zanders';

    /**
     * Apply loaded Zanders inventory stage rows to existing normalized offer rows.
     *
     * This is the inventory-cron path. It intentionally updates only volatile
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
        $sig_approval_enabled = SigDropshipApproval::is_distributor_sig_approved(self::DIST_ID);
        $sig_offer_where_sql = $sig_approval_enabled
            ? self::offer_sig_approval_where_sql('o')
            : '0 = 1';

        $set = [
            'o.qty = IFNULL(S.available, 0)',
            "o.stock_status = CASE WHEN IFNULL(S.available, 0) > 0 THEN 'instock' ELSE 'outofstock' END",
            'o.dealer_price = S.price1',
            'o.shipping_cost = CASE WHEN S.price1 >= 500 THEN 0 ELSE 15 END',
            'o.landed_cost = CASE WHEN S.price1 IS NULL THEN NULL ELSE S.price1 + CASE WHEN S.price1 >= 500 THEN 0 ELSE 15 END END',
            "o.dropship_enabled = CASE WHEN {$sig_offer_where_sql} THEN 1 ELSE o.dropship_enabled END",
            'o.normalized_at = NOW()',
        ];

        $sig_offer_changed_sql = "(
            {$sig_offer_where_sql}
            AND NOT (o.dropship_enabled <=> 1)
        )";

        $sql = $wpdb->prepare(
            "
                UPDATE {$offers_table} o
                INNER JOIN {$stage_table} S
                    ON S.itemnumber = o.distributor_product_id
                SET
                    " . implode(",\n                    ", $set) . "
                WHERE o.distributor_id = %s
                    AND (
                        NOT (o.qty <=> IFNULL(S.available, 0))
                        OR NOT (o.dealer_price <=> S.price1)
                        OR {$sig_offer_changed_sql}
                    )
            ",
            self::DIST_ID
        );

        $updated = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($updated === false) {
            throw new \RuntimeException('Zanders distributor offers update failed: ' . (string) $wpdb->last_error);
        }

        return [
            'rows' => is_numeric($updated) ? (int) $updated : 0,
            'elapsed_ms' => (microtime(true) - $started) * 1000.0,
        ];
    }

    /**
     * Normalize the current live Zanders product table into distributor offers.
     *
     * This is intentionally set-based and only touches rows for UPCs that are
     * already present in active product_state.
     *
     * @return array<string,mixed>
     */
    public static function normalize_from_product_table(string $source_live_table): array
    {
        $live_table = trim($source_live_table);

        return DistributorTableSyncService::normalize_product_offers([
            'distributor_id' => self::DIST_ID,
            'label' => 'Zanders',
            'live_table_label' => 'live Zanders',
            'source_live_table' => $live_table,
            'source_alias' => 'z',
            'matched_count_key' => 'matched_active_zanders_upcs',
            'source_columns' => self::product_source_columns(),
        ]);
    }

    /**
     * @return array<string,string>
     */
    private static function product_source_columns(): array
    {
        $qty_expr = DistributorTableSyncService::unsigned_quantity_expr('z', 'inventory_quantity');
        $dealer_price_expr = DistributorTableSyncService::decimal_expr('z', 'distributor_price');
        $shipping_cost_expr = DistributorTableSyncService::decimal_expr('z', 'shipping_cost');

        return [
            'upc' => 'z.upc',
            'distributor_product_id' => "NULLIF(TRIM(z.zanders_item_number), '')",
            'distributor_sku' => "NULLIF(TRIM(z.zanders_item_number), '')",
            'manufacturer_norm' => "NULLIF(TRIM(z.manufacturer_norm), '')",
            'qty' => $qty_expr,
            'stock_status' => DistributorTableSyncService::stock_status_expr($qty_expr),
            'dealer_price' => $dealer_price_expr,
            'shipping_cost' => $shipping_cost_expr,
            'landed_cost' => DistributorTableSyncService::landed_cost_expr($dealer_price_expr, $shipping_cost_expr),
            'map_price' => DistributorTableSyncService::decimal_expr('z', 'retail_map'),
            'msrp' => DistributorTableSyncService::decimal_expr('z', 'retail_msrp'),
            'ffl_required' => 'CAST(COALESCE(z.ffl_required, 0) AS UNSIGNED)',
            'sot_required' => 'CAST(COALESCE(z.sot_required, 0) AS UNSIGNED)',
            'dropship_enabled' => 'CAST(COALESCE(z.dropship_enabled, 1) AS UNSIGNED)',
            'enabled' => '1',
            'shipping_weight_oz' => DistributorTableSyncService::decimal_expr('z', 'shipping_weight', 10, 3),
        ];
    }

    private static function offer_sig_approval_where_sql(string $alias): string
    {
        $alias = trim($alias);
        $prefix = $alias !== '' ? $alias . '.' : '';

        return "
            COALESCE({$prefix}sot_required, 0) = 0
            AND (
                   {$prefix}manufacturer_norm IN ('SIG', 'SIGSAUER', 'SIG SAUER', 'SIGARMS', 'SIG ARMS')
                OR {$prefix}manufacturer_norm LIKE 'SIGSAUER%'
                OR {$prefix}manufacturer_norm LIKE 'SIG SAUER%'
                OR {$prefix}manufacturer_norm LIKE 'SIGARMS%'
                OR {$prefix}manufacturer_norm LIKE 'SIG ARMS%'
            )
        ";
    }

}
