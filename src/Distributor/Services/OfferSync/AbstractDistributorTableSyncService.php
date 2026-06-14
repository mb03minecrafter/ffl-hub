<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared base for syncing distributor product tables into normalized offer rows.
 *
 * Product/catalog crons call normalize_from_product_table() after their live
 * table has been rebuilt/swapped. Concrete distributors provide only identity
 * and SQL expressions for their live table columns; this base turns those into
 * an OfferProductSyncMap for the shared SQL runner.
 *
 * This class does not update Woo products and does not update the raw
 * distributor product tables. Inventory-stage updates stay in the concrete
 * distributor services because their stage schemas and volatile fields differ.
 */
abstract class AbstractDistributorTableSyncService
{
    /**
     * Stable distributor id stored on fflhub_distributor_offers.distributor_id.
     */
    abstract protected static function distributor_id(): string;

    /**
     * Human-readable name used in profiling and error output.
     */
    abstract protected static function label(): string;

    /**
     * SQL alias used for the live distributor product table in generated SQL.
     */
    abstract protected static function source_alias(): string;

    /**
     * Result-array key used for the active product_state UPC match count.
     */
    abstract protected static function matched_count_key(): string;

    /**
     * Map distributor_offers columns to SQL expressions against the live table.
     *
     * The returned array must include upc. Every other column is inserted for
     * missing rows and updated only when the null-safe comparison detects a
     * real value change.
     *
     * @return array<string,string>
     */
    abstract protected static function product_source_columns(string $live_table): array;

    protected static function live_table_label(): string
    {
        return 'live ' . static::label();
    }

    /**
     * Normalize the distributor's current live product table into the shared
     * distributor offers table.
     *
     * This is the public entry point used by product crons and manual admin
     * backfill buttons. It intentionally limits rows to active product_state
     * UPCs, so a distributor's full catalog does not flood the normalized table.
     *
     * @return array<string,mixed>
     */
    public static function normalize_from_product_table(string $source_live_table): array
    {
        return static::normalize_product_offers(trim($source_live_table));
    }

    /**
     * Run the full product-table sync.
     *
     * The flow is:
     * 1. Validate the live table and concrete source-column map.
     * 2. Insert missing offers for active carried UPCs.
     * 3. Update existing offers where mapped values actually changed.
     * 4. Disable stale offers when an active carried UPC is no longer present
     *    in this distributor's newly live product table.
     *
     * The SQL runner keeps this bulk/set-based. These jobs can touch thousands
     * of products, so replacing this with row-by-row PHP would be much slower.
     *
     * @return array<string,mixed>
     */
    protected static function normalize_product_offers(string $live_table): array
    {
        $result = DistributorOfferSyncSqlRunner::sync_product_offers(new OfferProductSyncMap(
            static::distributor_id(),
            static::label(),
            static::live_table_label(),
            $live_table,
            static::source_alias(),
            static::matched_count_key(),
            static::product_source_columns($live_table)
        ));

        if (!empty($result['ok'])) {
            $result['best_offer_selection'] = ProductBestOfferSelectionService::refresh_changed_upcs();
            if (!empty($result['best_offer_selection']['ok'])) {
                $result['product_state_best_offer_apply'] = ProductStateBestOfferApplyService::apply_changed_best_offers();
                if (!empty($result['product_state_best_offer_apply']['ok'])) {
                    $result['product_state_woo_apply'] = ProductStateWooApplyService::apply_changed_product_state();
                }
            }
        }

        return $result;
    }

    /**
     * Run an inventory-stage update against existing normalized offer rows.
     *
     * Concrete distributors still own their stage-table mapping because each
     * inventory feed has different identifiers and volatile fields. This helper
     * keeps the actual set-based UPDATE execution in one shared place.
     *
     * The concrete class decides:
     * - which stage table is safe to read,
     * - which stage/live identifier should join to distributor_offers,
     * - which distributor_offers columns that inventory feed owns, and
     * - which null-safe changed-only predicate keeps no-op runs cheap.
     *
     * The shared runner then compiles the same optimized UPDATE shape for each
     * distributor. That keeps the heavy SQL behavior consistent while still
     * leaving distributor-specific field ownership in the child class where it
     * is easier to audit.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    protected static function update_existing_offers_from_inventory_stage_map(OfferInventorySyncMap $map): array
    {
        $result = DistributorOfferSyncSqlRunner::update_existing_offers_from_inventory_stage($map);
        $result['best_offer_selection'] = ProductBestOfferSelectionService::refresh_changed_upcs();
        if (!empty($result['best_offer_selection']['ok'])) {
            $result['product_state_best_offer_apply'] = ProductStateBestOfferApplyService::apply_changed_best_offers();
            if (!empty($result['product_state_best_offer_apply']['ok'])) {
                $result['product_state_woo_apply'] = ProductStateWooApplyService::apply_changed_product_state();
            }
        }

        return $result;
    }

    public static function table_exists_by_name(string $table): bool
    {
        global $wpdb;

        if ($table === '') {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($found) && $found === $table;
    }

    public static function table_has_column(string $table, string $column): bool
    {
        global $wpdb;

        if ($table === '' || $column === '') {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_string($found) && $found === $column;
    }

    public static function unsigned_quantity_expr(string $alias, string $column): string
    {
        return "CAST(COALESCE(NULLIF(TRIM({$alias}.{$column}), ''), '0') AS UNSIGNED)";
    }

    public static function decimal_expr(string $alias, string $column, int $precision = 12, int $scale = 4): string
    {
        return "CAST(NULLIF(TRIM({$alias}.{$column}), '') AS DECIMAL({$precision},{$scale}))";
    }

    public static function stock_status_expr(string $qty_expr): string
    {
        return "CASE WHEN {$qty_expr} > 0 THEN 'instock' ELSE 'outofstock' END";
    }

    public static function landed_cost_expr(string $dealer_price_expr, string $shipping_cost_expr): string
    {
        return "
            CASE
                WHEN {$dealer_price_expr} IS NULL THEN NULL
                ELSE {$dealer_price_expr} + COALESCE({$shipping_cost_expr}, 0.0000)
            END
        ";
    }

}
