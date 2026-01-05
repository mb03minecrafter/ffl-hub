<?php

namespace FFLHub\Distributor\Product;

use WC_Product;
use FFLHub\Distributor\Product\UpcLookupResult;
use FFLHub\Distributor\Product\DistributorOffer;

use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Product\ProductMeta;

use function current_time;
use function get_post_meta;
use function get_post_status;
use function update_post_meta;
use function wc_get_product;
use function wc_format_decimal;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handles ongoing pricing and quantity synchronization for FFLHub-managed products.
 *
 * High-level:
 * - On a schedule (WP-Cron hook), find Woo products with _fflhub_managed = 1.
 * - For each product:
 *   - Look up the UPC in all configured distributors.
 *   - Aggregate pricing/quantity (true_cost, MAP, MSRP, qty).
 *   - Pick the best distributor (cheapest in stock, else cheapest overall).
 *   - Recompute recommended price using markup rules.
 *   - Update Woo product price/stock + FFLHub meta.
 */
class DistributorProductSyncCronService extends AbstractCronService
{
    /**
     * Cron hook name for syncing managed products.
     */
    private const CRON_HOOK = 'fflhub_sync_managed_products';

    /**
     * Unique cron hook name.
     */
    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    /**
     * Schedule key added to cron_schedules.
     *
     * Matches the old 'fflhub_every_five_minutes' interval.
     */
    protected function get_schedule_key(): string
    {
        return 'fflhub_every_five_minutes';
    }

    /**
     * Interval length in seconds (5 minutes).
     */
    protected function get_interval_seconds(): int
    {
        return 5 * MINUTE_IN_SECONDS;
    }

    /**
     * Human-readable schedule label.
     */
    protected function get_interval_display(): string
    {
        return __('Every 5 minutes (FFLHub Product Sync)', 'ffl-hub');
    }

    /**
     * Optional: start 5 minutes after activation / registration instead of immediately.
     */
    protected function get_initial_delay_seconds(): int
    {
        return 300; // 5 minutes
    }

    /**
     * Cron entry point.
     *
     * This is called by WP-Cron via the hook returned by get_cron_hook_name().
     */
    public function run(): void
    {
       

        // Adjust limit as needed; keep modest to avoid timeouts.
        $this->sync_batch(50);

      
    }

    /**
     * Sync a batch of FFLHub-managed products.
     *
     * @param int $limit Number of products to process in this run.
     */
    public function sync_batch(int $limit = 50): void
    {
       
        $query   = DistributorProductHelper::query_for_managed_products($limit);


        if (! $query->have_posts()) {
            $this->log('sync_batch: no managed products found.');
            return;
        }


        $processed = 0;

        foreach ($query->posts as $product_id) {
            $product_id = (int) $product_id;
            if ($product_id <= 0) {
                continue;
            }


            try {
                $this->sync_single_product($product_id);
                $processed++;
            } catch (\Throwable $e) {
                $this->log(
                    sprintf(
                        'sync_batch: exception syncing product %d: %s',
                        $product_id,
                        $e->getMessage()
                    )
                );
                continue;
            }
        }
    }

    /**
     * Sync pricing and quantity for a single FFLHub-managed WooCommerce product.
     *
     * @param int $product_id
     */

    public function sync_single_product(int $product_id): void
    {
       

        if ($product_id <= 0) {
            return;
        }

        if (! function_exists('wc_get_product')) {
            return;
        }

        $product = wc_get_product($product_id);

        if (! $product instanceof WC_Product) {
            return;
        }

        // Skip trashed products.
        $post_status = get_post_status($product_id);

        if ('trash' === $post_status) {
            return;
        }

        $upc = (string) get_post_meta($product_id, ProductMeta::FFLHUB_UPC_META, true);
        $upc = trim($upc);


        if ($upc === '') {
            return;
        }

        // ✅ CHANGED: handler now returns UpcLookupResult (not array)
        $lookup     = DistributorProductHelper::get_upc_lookup_result_from_distributors($upc); // ✅ CHANGED (new method)


        if (! ($lookup instanceof UpcLookupResult)) { // ✅ CHANGED
            update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));
            return;
        }

        /** @var array<string, DistributorOffer> $offers */
        $offers = $lookup->offers(); // ✅ CHANGED (offers map)


        // No distributors currently carry this UPC => mark OOS but keep price unchanged.
        if (empty($offers)) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity(0);
            $product->set_stock_status('outofstock');
            $product->save();

            update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));
            return;
        }

        // ✅ CHANGED: selected is now DistributorOffer (not array)
        $selected_offer = $lookup->cheapest_in_stock() ?: $lookup->cheapest_any(); // ✅ CHANGED

        if (! ($selected_offer instanceof DistributorOffer)) {
            // Fallback: first offer (mirrors your old “first carrier” fallback)
            $first = reset($offers);
            $selected_offer = ($first instanceof DistributorOffer) ? $first : null;
        }

        if (! ($selected_offer instanceof DistributorOffer)) {
            update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));
            return;
        }

        // ✅ CHANGED: pull payload and distributor info from offer
        $selected_payload = $selected_offer->product;                 // ✅ CHANGED
        $selected_dist_id = (string) $selected_offer->distributor_id; // ✅ CHANGED
        $selected_label   = (string) $selected_offer->label;          // ✅ CHANGED

        // ✅ CHANGED: you previously logged “aggregate MAP/MSRP and total qty”
        // but actually used selected payload only. Keeping behavior consistent:
        $map  = $selected_payload->map;
        $msrp = $selected_payload->msrp;
        $qty  = (int) $selected_payload->quantity;


        $true_cost    = $selected_payload->true_cost;
        $dealer_price = $selected_payload->price;

 

        // Markup percent: you log it, but recommended_price is currently computed
        // by get_reccomended_price_from_payload() which uses global markup.
        // Keeping your current behavior for now (no behavior change).
        $recommended_price = DistributorProductHelper::compute_sell_price_for_product($product_id,$selected_payload);

 

        // Update stock from selected quantity (same as your current behavior).
        $product->set_manage_stock(true);
        $product->set_stock_quantity($qty);
        $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');

        $product->set_regular_price(wc_format_decimal((float) $recommended_price, 2));
        

        // Save Woo product fields.
        $product->save();

        // Update FFLHub meta fields from selected payload.

        DistributorProductHelper::update_fflhub_meta_from_payload_for_sync(
            $product,
            $selected_dist_id,
            $selected_payload,
            (float) $recommended_price
        );

    }





    /**
     * Simple internal logger.
     *
     * Flip the `false` to `true` to enable logging for this class.
     *
     * @param string $message
     */
    private function log(string $message): void
    {
        if (true) {
            error_log('[FFLHub][Product Sync] ' . $message);
        }
    }
}
