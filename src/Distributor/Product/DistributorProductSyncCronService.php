<?php

namespace FFLHub\Distributor\Product;

use WC_Product;
use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Product\ProductMeta;
use FFLHub\Settings\Options;

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
 * CHANGE: If computed sell price violates profit-floor rule:
 *   sell_price < true_cost * (1 + transaction_fee_percent)
 * then force product OUT OF STOCK (qty=0) instead of updating price/stock normally.
 */
class DistributorProductSyncCronService extends AbstractCronService
{
    /**
     * Cron hook name for syncing managed products.
     */
    private const CRON_HOOK = 'fflhub_sync_managed_products';

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_schedule_key(): string
    {
        return 'fflhub_every_five_minutes';
    }

    protected function get_interval_seconds(): int
    {
        return 5 * MINUTE_IN_SECONDS;
    }

    protected function get_interval_display(): string
    {
        return __('Every 5 minutes (FFLHub Product Sync)', 'ffl-hub');
    }

    protected function get_initial_delay_seconds(): int
    {
        return 300; // 5 minutes
    }

    public function run(): void
    {
        $this->sync_batch(50);
    }

    /**
     * Sync a batch of FFLHub-managed products.
     *
     * @param int $limit
     */
    public function sync_batch(int $limit = 50): void
    {
        $t_start = microtime(true);

        $this->log_debug('[FFLHub][Product Sync] ---- RUN START ----');

        $t_q = microtime(true);
        $query = DistributorProductHelper::query_for_managed_products($limit);
        $this->log_timing('query_for_managed_products', $t_q);

        if (! $query->have_posts()) {
            $this->log_debug('[FFLHub][Product Sync] sync_batch: no managed products found.');
            $this->log_debug('[FFLHub][Product Sync] ---- RUN END (NOOP) ----');
            return;
        }

        $processed = 0;
        $errors    = 0;

        foreach ($query->posts as $product_id) {
            $product_id = (int) $product_id;
            if ($product_id <= 0) {
                continue;
            }

            $t_one = microtime(true);

            try {
                $this->sync_single_product($product_id);
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $this->log_debug(
                    sprintf(
                        '[FFLHub][Product Sync] sync_batch: exception syncing product %d: %s',
                        $product_id,
                        $e->getMessage()
                    )
                );
                continue;
            } finally {
                $this->log_timing('sync_single_product product_id=' . $product_id, $t_one);
            }
        }

        $elapsed_ms = (microtime(true) - $t_start) * 1000.0;

        $this->log_debug(
            sprintf(
                '[FFLHub][Product Sync] ---- RUN END (processed=%d, errors=%d, total_ms=%.2f) ----',
                $processed,
                $errors,
                $elapsed_ms
            )
        );
    }

    /**
     * Sync pricing and quantity for a single FFLHub-managed WooCommerce product.
     *
     * @param int $product_id
     */
    public function sync_single_product(int $product_id): void
    {
        $t_start = microtime(true);

        if ($product_id <= 0) {
            return;
        }

        if (! function_exists('wc_get_product')) {
            return;
        }

        $t_load = microtime(true);
        $product = wc_get_product($product_id);
        $this->log_timing("wc_get_product product_id={$product_id}", $t_load);

        if (! $product instanceof WC_Product) {
            $this->log_debug("[FFLHub][Product Sync] product_id={$product_id}: wc_get_product returned non-product");
            return;
        }

        // Skip trashed products.
        $t_status = microtime(true);
        $post_status = get_post_status($product_id);
        $this->log_timing("get_post_status product_id={$product_id}", $t_status);

        if ('trash' === $post_status) {
            $this->log_debug("[FFLHub][Product Sync] product_id={$product_id}: skipped (trash)");
            return;
        }

        // Per-product header snapshot
        $current_stock        = $product->get_stock_quantity();
        $current_stock_status = $product->get_stock_status();
        $current_regular      = $product->get_regular_price();

        $this->log_debug(
            sprintf(
                '[FFLHub][Product Sync] product_id=%d START status=%s stock=%s stock_status=%s regular_price=%s',
                $product_id,
                (string) $post_status,
                ($current_stock === null ? 'null' : (string) $current_stock),
                (string) $current_stock_status,
                ($current_regular === '' ? '[empty]' : (string) $current_regular)
            )
        );

        $t_upc = microtime(true);
        $upc = (string) get_post_meta($product_id, ProductMeta::FFLHUB_UPC_META, true);
        $upc = trim($upc);
        $this->log_timing("get_post_meta upc product_id={$product_id}", $t_upc);

        if ($upc === '') {
            $this->log_debug("[FFLHub][Product Sync] product_id={$product_id}: missing UPC meta; stamping last_sync and exiting");
            update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));
            $this->log_timing("TOTAL product_id={$product_id}", $t_start);
            return;
        }

        // Lookup
        $t_lookup = microtime(true);
        $lookup = null;
        try {
            $lookup = DistributorProductHelper::get_upc_lookup_result_from_distributors($upc, false);
        } catch (\Throwable $e) {
            $lookup = null;
            $this->log_debug(
                sprintf(
                    '[FFLHub][Product Sync] product_id=%d upc=%s lookup EXCEPTION: %s',
                    $product_id,
                    $upc,
                    $e->getMessage()
                )
            );
        }
        $this->log_timing("distributor_lookup upc={$upc}", $t_lookup);

        if (! ($lookup instanceof UpcLookupResult)) {
            $this->log_debug("[FFLHub][Product Sync] product_id={$product_id} upc={$upc}: lookup returned null/invalid; stamping last_sync");
            update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));
            $this->log_timing("TOTAL product_id={$product_id}", $t_start);
            return;
        }

        /** @var array<string, DistributorOffer> $offers */
        $offers = $lookup->offers();
        $offers_count = is_array($offers) ? count($offers) : 0;

        $cis = $lookup->cheapest_in_stock();
        $ca  = $lookup->cheapest_any();

        $this->log_debug(
            sprintf(
                '[FFLHub][Product Sync] product_id=%d upc=%s offers=%d cheapest_in_stock=%s cheapest_any=%s',
                $product_id,
                $upc,
                (int) $offers_count,
                ($cis instanceof DistributorOffer) ? (string) $cis->distributor_id : '[none]',
                ($ca instanceof DistributorOffer) ? (string) $ca->distributor_id : '[none]'
            )
        );

        // No distributors currently carry this UPC => mark OOS but keep price unchanged.
        if (empty($offers)) {
            $t_write = microtime(true);

            $product->set_manage_stock(true);
            $product->set_stock_quantity(0);
            $product->set_stock_status('outofstock');

            $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));
            $product->save();

            $this->log_timing("write_oos_no_offers product_id={$product_id}", $t_write);
            $this->log_debug("[FFLHub][Product Sync] product_id={$product_id} upc={$upc}: set OOS (no offers)");
            $this->log_timing("TOTAL product_id={$product_id}", $t_start);
            return;
        }

        // Choose best offer: cheapest in stock, else cheapest any, else first.
        $t_select = microtime(true);
        $selected_offer = $cis ?: $ca;

        if (! ($selected_offer instanceof DistributorOffer)) {
            $first = reset($offers);
            $selected_offer = ($first instanceof DistributorOffer) ? $first : null;
        }
        $this->log_timing("select_offer upc={$upc}", $t_select);

        if (! ($selected_offer instanceof DistributorOffer)) {
            $t_write = microtime(true);
            $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));
            $product->save();
            $this->log_timing("write_last_sync_no_offer product_id={$product_id}", $t_write);
            $this->log_debug("[FFLHub][Product Sync] product_id={$product_id} upc={$upc}: no valid selected offer; exiting");
            $this->log_timing("TOTAL product_id={$product_id}", $t_start);
            return;
        }

        $selected_payload = $selected_offer->product;
        if (! ($selected_payload instanceof DistributorProductPayload)) {
            $t_write = microtime(true);
            $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));
            $product->save();
            $this->log_timing("write_last_sync_bad_payload product_id={$product_id}", $t_write);
            $this->log_debug(
                sprintf(
                    '[FFLHub][Product Sync] product_id=%d upc=%s selected_offer=%s: missing/invalid payload; exiting',
                    $product_id,
                    $upc,
                    (string) $selected_offer->distributor_id
                )
            );
            $this->log_timing("TOTAL product_id={$product_id}", $t_start);
            return;
        }

        $selected_dist_id = (string) $selected_offer->distributor_id;

        $qty        = (int) ($selected_payload->quantity ?? 0);
        $true_cost  = (is_numeric($selected_payload->true_cost) && (float) $selected_payload->true_cost > 0)
            ? (float) $selected_payload->true_cost
            : null;
        $dealer     = (is_numeric($selected_payload->price) && (float) $selected_payload->price > 0)
            ? (float) $selected_payload->price
            : null;
        $ship_cost  = (is_numeric($selected_payload->shipping_cost) && (float) $selected_payload->shipping_cost >= 0)
            ? (float) $selected_payload->shipping_cost
            : null;
        $map        = (is_numeric($selected_payload->map) && (float) $selected_payload->map > 0)
            ? (float) $selected_payload->map
            : null;

        $this->log_debug(
            sprintf(
                '[FFLHub][Product Sync] product_id=%d upc=%s selected=%s qty=%d true_cost=%s dealer=%s ship=%s map=%s',
                $product_id,
                $upc,
                $selected_dist_id,
                $qty,
                ($true_cost === null ? 'null' : sprintf('%.2f', $true_cost)),
                ($dealer === null ? 'null' : sprintf('%.2f', $dealer)),
                ($ship_cost === null ? 'null' : sprintf('%.2f', $ship_cost)),
                ($map === null ? 'null' : sprintf('%.2f', $map))
            )
        );

        // Compute sell price using your per-product pricing settings.
        $t_price = microtime(true);
        $recommended_price = DistributorProductHelper::compute_sell_price_for_product($product_id, $selected_payload);
        $this->log_timing("compute_sell_price product_id={$product_id}", $t_price);

        // If we can't compute a valid price, do NOT set the Woo price to 0.
        if (! is_numeric($recommended_price) || (float) $recommended_price <= 0) {
            $this->log_debug(
                sprintf(
                    '[FFLHub][Product Sync] product_id=%d upc=%s selected=%s: could not compute sell price (skipping price update)',
                    $product_id,
                    $upc,
                    $selected_dist_id
                )
            );

            $t_write = microtime(true);

            // Still update stock + last sync.
            $product->set_manage_stock(true);
            $product->set_stock_quantity($qty);
            $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');

            $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));
            $product->save();

            $this->log_timing("write_stock_only_bad_price product_id={$product_id}", $t_write);
            $this->log_timing("TOTAL product_id={$product_id}", $t_start);
            return;
        }

        $recommended_price = (float) $recommended_price;

        /**
         * PROFIT FLOOR RULE:
         * If sell price would be below true_cost + transaction fee percent,
         * force product OUT OF STOCK (qty=0) and skip price/meta update.
         */
        $t_floor = microtime(true);

        $fee_raw = Options::get_payment_processor_fee_percent();
        $fee_pct = is_numeric($fee_raw) ? (float) $fee_raw : 0.0;
        $fee_pct = ($fee_pct > 1.0) ? ($fee_pct / 100.0) : $fee_pct;

        $min_profitable_price = null;
        if ($true_cost !== null && $fee_pct >= 0) {
            $min_profitable_price = $true_cost * (1.0 + $fee_pct);
        }

        $this->log_timing("profit_floor_calc product_id={$product_id}", $t_floor);

        $this->log_debug(
            sprintf(
                '[FFLHub][Product Sync] product_id=%d upc=%s sell=%.2f floor=%s (true_cost=%s fee_pct=%.4f)',
                $product_id,
                $upc,
                $recommended_price,
                ($min_profitable_price === null ? 'null' : sprintf('%.2f', $min_profitable_price)),
                ($true_cost === null ? 'null' : sprintf('%.2f', $true_cost)),
                $fee_pct
            )
        );

        if ($min_profitable_price !== null && $recommended_price < $min_profitable_price) {
            $t_write = microtime(true);

            $product->set_manage_stock(true);
            $product->set_stock_quantity(0);
            $product->set_stock_status('outofstock');

            $this->log_debug(
                sprintf(
                    '[FFLHub][Product Sync] product_id=%d upc=%s selected=%s forced OOS: sell=%.2f < floor=%.2f (true_cost=%.2f fee_pct=%.4f)',
                    $product_id,
                    $upc,
                    $selected_dist_id,
                    $recommended_price,
                    $min_profitable_price,
                    (float) $true_cost,
                    $fee_pct
                )
            );

            $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));
            $product->save();

            $this->log_timing("write_forced_oos_floor product_id={$product_id}", $t_write);
            $this->log_timing("TOTAL product_id={$product_id}", $t_start);
            return;
        }

        // Normal update path (stock + price + meta)
        $t_write = microtime(true);

        $product->set_manage_stock(true);
        $product->set_stock_quantity($qty);
        $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');

        $product->set_regular_price(wc_format_decimal($recommended_price, 2));

        DistributorProductHelper::update_fflhub_meta_from_payload_for_sync(
            $product,
            $selected_dist_id,
            $selected_payload,
            $recommended_price
        );

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));
        $product->save();

        $this->log_timing("write_stock_price_meta product_id={$product_id}", $t_write);

        $this->log_debug(
            sprintf(
                '[FFLHub][Product Sync] product_id=%d END upc=%s selected=%s final_qty=%d final_status=%s final_regular=%.2f',
                $product_id,
                $upc,
                $selected_dist_id,
                $qty,
                ($qty > 0 ? 'instock' : 'outofstock'),
                $recommended_price
            )
        );

        $this->log_timing("TOTAL product_id={$product_id}", $t_start);
    }

    private function log_timing(string $label, float $t0): void
    {
        $elapsed_ms = (microtime(true) - $t0) * 1000.0;
        $this->log_debug(sprintf('[FFLHub][Product Sync][TIMING] %s took %.2f ms', $label, $elapsed_ms));
    }

    private function log_debug(string $message): void
    {
        if (! defined('FFLHUB_CRON_DEBUG') || FFLHUB_CRON_DEBUG !== true) {
            return;
        }

        error_log($message);
    }
}
