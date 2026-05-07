<?php

namespace FFLHub\Distributor\Services\ProductSync;

use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\UpcLookupResult;
use FFLHub\Distributor\Product\DistributorProductHelper;
use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Plugin;
use FFLHub\Product\ProductMeta;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

use WC_Product;
use WC_Product_Simple;

use function current_time;
use function get_post_meta;
use function get_post_status;
use function update_post_meta;
use function wc_get_product;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handles ongoing pricing and quantity synchronization for FFLHub-managed products.
 *
 * Optimization:
 * - Always bump LAST_SYNC (rotation key) each run.
 * - Only call $product->save() (heavy) when stock/price/meta actually changed.
 *
 * PROFIT FLOOR RULE:
 * If sell price would be below true_cost + transaction fee percent,
 * force product OUT OF STOCK (qty=0) and skip price/meta update.
 */
final class DistributorProductSyncCronService extends AbstractCronService
{
    private const CRON_HOOK = 'fflhub_sync_managed_products';

    /**
     * Debug constant + prefix for DebugLogUtil.
     * (Adjust constant name if yours differs; keeping existing global switch too.)
     */
    private const DEBUG_CONST = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX  = '[FFLHUB][ProductSync]';



    private DistributorHandler $handler;

    public function __construct(DistributorHandler $handler)
    {
        $this->handler = $handler;
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_interval_seconds(): int
    {
        return 5 * MINUTE_IN_SECONDS;
    }

    public function get_action_group(): string
    {
        return 'fflhub_product_sync';
    }

    protected function get_initial_delay_seconds(): int
    {
        return 300; // 5 minutes
    }

    public function run(): void
    {
        $this->sync_batch(1000);
    }

    public function sync_batch(int $limit = 50): void
    {
        $t_start   = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        $this->log_ctx('---- RUN START ----', array(
            'limit'        => (int) $limit,
            'memory_kb'    => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
            'hook'         => self::CRON_HOOK,
            'group'        => $this->get_action_group(),
            'interval_sec' => $this->get_interval_seconds(),
        ));

        $t_q   = microtime(true);
        $query = DistributorProductHelper::query_for_managed_products($limit);
        $posts = ($query && isset($query->posts) && is_array($query->posts)) ? count($query->posts) : 0;

        $this->profile('query_for_managed_products', $t_q, array(
            'limit' => (int) $limit,
            'posts' => (int) $posts,
        ));

        if (! $query || ! $query->have_posts()) {
            $this->log('sync_batch: no managed products found.');
            $this->log_ctx('---- RUN END (NOOP) ----', array('elapsed_ms' => $this->ms_since($t_start)));
            return;
        }

        $processed = 0;
        $errors    = 0;

        // Outcome counters
        $stats = array(
            'skipped_trash'             => 0,
            'missing_upc'               => 0,
            'lookup_invalid'            => 0,
            'no_offers_oos'             => 0,
            'no_selected_offer'         => 0,
            'bad_payload'               => 0,
            'bad_price_stock_only'      => 0,
            'forced_oos_profit_floor'   => 0,
            'updated_normal'            => 0,
            'noop_bump_only'            => 0,
        );

        // Timing aggregates
        $lookup_ms_sum = 0.0;
        $lookup_ms_max = 0.0;
        $write_ms_sum  = 0.0;
        $write_ms_max  = 0.0;

        foreach ($query->posts as $product_id) {
            $product_id = (int) $product_id;
            if ($product_id <= 0) {
                continue;
            }

            $t_one = microtime(true);

            try {
                $result = $this->sync_single_product($product_id);
                $processed++;

                if (is_array($result)) {
                    $outcome = isset($result['outcome']) ? (string) $result['outcome'] : '';

                    switch ($outcome) {
                        case 'SKIP_TRASH':
                            $stats['skipped_trash']++;
                            break;
                        case 'MISSING_UPC':
                            $stats['missing_upc']++;
                            break;
                        case 'LOOKUP_INVALID':
                            $stats['lookup_invalid']++;
                            break;
                        case 'NO_OFFERS_OOS':
                            $stats['no_offers_oos']++;
                            break;
                        case 'NO_SELECTED_OFFER':
                            $stats['no_selected_offer']++;
                            break;
                        case 'BAD_PAYLOAD':
                            $stats['bad_payload']++;
                            break;
                        case 'BAD_PRICE_STOCK_ONLY':
                            $stats['bad_price_stock_only']++;
                            break;
                        case 'FORCED_OOS_PROFIT_FLOOR':
                            $stats['forced_oos_profit_floor']++;
                            break;
                        case 'UPDATED_NORMAL':
                            $stats['updated_normal']++;
                            break;
                        case 'NOOP_BUMP_ONLY':
                            $stats['noop_bump_only']++;
                            break;
                    }

                    $lms = isset($result['lookup_ms']) ? (float) $result['lookup_ms'] : 0.0;
                    $wms = isset($result['write_ms']) ? (float) $result['write_ms'] : 0.0;

                    if ($lms > 0) {
                        $lookup_ms_sum += $lms;
                        $lookup_ms_max = max($lookup_ms_max, $lms);
                    }
                    if ($wms > 0) {
                        $write_ms_sum += $wms;
                        $write_ms_max = max($write_ms_max, $wms);
                    }
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->log_ctx('sync_batch: exception syncing product', array(
                    'product_id' => $product_id,
                    'error'      => $e->getMessage(),
                ));
            } finally {
                $this->profile('sync_single_product', $t_one, array('product_id' => $product_id));
            }
        }

        $lookup_avg = ($processed > 0) ? ($lookup_ms_sum / (float) $processed) : 0.0;
        $write_avg  = ($processed > 0) ? ($write_ms_sum / (float) $processed) : 0.0;

        $this->profile('Run summary', $t_start, array_merge(array(
            'limit'         => (int) $limit,
            'processed'     => (int) $processed,
            'errors'        => (int) $errors,
            'lookup_avg_ms' => (float) $lookup_avg,
            'lookup_max_ms' => (float) $lookup_ms_max,
            'write_avg_ms'  => (float) $write_avg,
            'write_max_ms'  => (float) $write_ms_max,
            'total_ms'      => (float) $this->ms_since($t_start),
        ), $stats));

        $this->profile('Total cron run', $t_start, array(
            'status'    => ($errors > 0 ? 'PARTIAL' : 'SUCCESS'),
            'processed' => (int) $processed,
            'errors'    => (int) $errors,
        ));

        $mem_end = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        if ($mem_start > 0 && $mem_end > 0) {
            $this->log_ctx('Memory usage summary', array(
                'start_kb' => (int) round($mem_start / 1024),
                'end_kb'   => (int) round($mem_end / 1024),
                'delta_kb' => (int) round(($mem_end - $mem_start) / 1024),
            ));
        }

        $this->log('---- RUN END ----');
    }

    /**
     * Sync pricing and quantity for a single managed WooCommerce product.
     *
     * @return array{outcome:string,lookup_ms:float,write_ms:float}
     */
    public function sync_single_product(int $product_id): array
    {
        $t_start  = microtime(true);
        $t_lookup = 0.0;
        $t_write  = 0.0;

        $now_mysql = current_time('mysql');

        if ($product_id <= 0 || ! function_exists('wc_get_product')) {
            return array('outcome' => 'LOOKUP_INVALID', 'lookup_ms' => 0.0, 'write_ms' => 0.0);
        }

        $t0 = microtime(true);
        $product = wc_get_product($product_id);
        $this->profile('wc_get_product', $t0, array('product_id' => $product_id));

        if (! $product instanceof WC_Product) {
            $this->log_ctx('wc_get_product returned non-product', array('product_id' => $product_id));
            return array('outcome' => 'LOOKUP_INVALID', 'lookup_ms' => 0.0, 'write_ms' => 0.0);
        }

        $t0 = microtime(true);
        $post_status = get_post_status($product_id);
        $this->profile('get_post_status', $t0, array('product_id' => $product_id, 'status' => (string) $post_status));

        if ('trash' === $post_status) {
            update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);

            $this->profile('TOTAL product', $t_start, array(
                'product_id' => $product_id,
                'outcome'    => 'SKIP_TRASH',
            ));

            return array('outcome' => 'SKIP_TRASH', 'lookup_ms' => 0.0, 'write_ms' => 0.0);
        }

        $this->log_ctx('Product START', array(
            'product_id'    => $product_id,
            'status'        => (string) $post_status,
            'stock'         => $product->get_stock_quantity(),
            'stock_status'  => (string) $product->get_stock_status(),
            'regular_price' => (string) $product->get_regular_price(),
        ));

        $stock_oos_override_enabled = $this->is_stock_oos_override_enabled($product);
        $local_stock_override_qty = $this->get_local_stock_override_qty_for_sync($product);
        $this->log_ctx('Stock override state', array(
            'product_id'                => $product_id,
            'stock_oos_override_enabled' => $stock_oos_override_enabled ? 1 : 0,
            'local_stock_override_qty'  => $local_stock_override_qty,
        ));

        $t0 = microtime(true);
        $upc = trim((string) get_post_meta($product_id, ProductMeta::FFLHUB_UPC_META, true));
        $this->profile('get_post_meta_upc', $t0, array('product_id' => $product_id, 'has_upc' => ($upc !== '')));

        if ($upc === '') {
            update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);

            $this->profile('TOTAL product', $t_start, array(
                'product_id' => $product_id,
                'outcome'    => 'MISSING_UPC',
            ));

            return array('outcome' => 'MISSING_UPC', 'lookup_ms' => 0.0, 'write_ms' => 0.0);
        }

        // Lookup
        $t0 = microtime(true);
        $lookup = null;

        try {
            $lookup = $this->handler->get_payloads_for_upc($upc, false); // returns UpcLookupResult, we dont need image to update pricing and quantity data so we exclude it by passing false into the optional argument
        } catch (\Throwable $e) {
            $lookup = null;
            $this->log_ctx('distributor_lookup exception', array(
                'product_id' => $product_id,
                'upc'        => $upc,
                'error'      => $e->getMessage(),
            ));
        }

        $t_lookup = $this->ms_since($t0);

        $this->profile('distributor_lookup', $t0, array(
            'product_id' => $product_id,
            'upc'        => $upc,
            'ok'         => ($lookup instanceof UpcLookupResult),
        ));

        if (! ($lookup instanceof UpcLookupResult)) {
            update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);

            $this->profile('TOTAL product', $t_start, array(
                'product_id' => $product_id,
                'outcome'    => 'LOOKUP_INVALID',
            ));

            return array('outcome' => 'LOOKUP_INVALID', 'lookup_ms' => $t_lookup, 'write_ms' => 0.0);
        }

        /** @var array<string, DistributorOffer> $offers */
        $offers = $lookup->offers();
        $offers_before_enabled_filter = is_array($offers) ? count($offers) : 0;
        $offers = $this->filter_offers_by_enabled_distributors($offers);
        $offers_before_lock = is_array($offers) ? count($offers) : 0;

        $dist_lock = $this->get_distributor_lock_for_product($product);
        if (!empty($dist_lock['enabled'])) {
            $offers = $this->filter_offers_by_locked_distributors($offers, (array) ($dist_lock['ids'] ?? []));
        }

        $effective_lookup = new UpcLookupResult($offers);
        $cis    = $effective_lookup->cheapest_in_stock();
        $ca     = $effective_lookup->cheapest_any();

        $this->log_ctx('Lookup summary', array(
            'product_id'        => $product_id,
            'upc'               => $upc,
            'offers'            => is_array($offers) ? count($offers) : 0,
            'offers_before_enabled_filter' => $offers_before_enabled_filter,
            'offers_before_lock' => $offers_before_lock,
            'lock_enabled'      => !empty($dist_lock['enabled']) ? 1 : 0,
            'lock_ids'          => !empty($dist_lock['ids']) ? (array) $dist_lock['ids'] : array(),
            'cheapest_in_stock' => ($cis instanceof DistributorOffer) ? (string) $cis->distributor_id : null,
            'cheapest_any'      => ($ca instanceof DistributorOffer) ? (string) $ca->distributor_id : null,
        ));

        if (empty($offers)) {
            $t0 = microtime(true);

            $desired_qty = $this->resolve_desired_stock_qty(0, $stock_oos_override_enabled, $local_stock_override_qty);
            $desired_status = ($desired_qty > 0 ? 'instock' : 'outofstock');

            $cur_qty    = (int) ($product->get_stock_quantity() ?? 0);
            $cur_status = (string) $product->get_stock_status();
            $needs_save = (($cur_qty !== $desired_qty) || ($cur_status !== $desired_status));

            if ($needs_save) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($desired_qty);
                $product->set_stock_status($desired_status);
                $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);
                $product->save();
            } else {
                update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);
            }

            $t_write = $this->ms_since($t0);

            $this->profile('write_oos_no_offers', $t0, array(
                'product_id' => $product_id,
                'upc'        => $upc,
                'stock_override' => $stock_oos_override_enabled ? 1 : 0,
                'local_stock_override_qty' => $local_stock_override_qty,
                'final_qty' => $desired_qty,
                'final_status' => $desired_status,
                'saved'      => $needs_save ? 1 : 0,
            ));

            $this->profile('TOTAL product', $t_start, array(
                'product_id' => $product_id,
                'outcome'    => 'NO_OFFERS_OOS',
            ));

            return array('outcome' => 'NO_OFFERS_OOS', 'lookup_ms' => $t_lookup, 'write_ms' => $t_write);
        }

        // Select offer
        $t0 = microtime(true);
        $selected_offer = $cis ?: $ca;
        if (! ($selected_offer instanceof DistributorOffer)) {
            $first = reset($offers);
            $selected_offer = ($first instanceof DistributorOffer) ? $first : null;
        }
        $this->profile('select_offer', $t0, array(
            'product_id' => $product_id,
            'upc'        => $upc,
            'selected'   => ($selected_offer instanceof DistributorOffer) ? (string) $selected_offer->distributor_id : null,
        ));

        if (! ($selected_offer instanceof DistributorOffer)) {
            update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);

            $this->profile('TOTAL product', $t_start, array(
                'product_id' => $product_id,
                'outcome'    => 'NO_SELECTED_OFFER',
            ));

            return array('outcome' => 'NO_SELECTED_OFFER', 'lookup_ms' => $t_lookup, 'write_ms' => 0.0);
        }

        $selected_payload = $selected_offer->product;
        if (! ($selected_payload instanceof DistributorProductPayload)) {
            update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);

            $this->profile('TOTAL product', $t_start, array(
                'product_id' => $product_id,
                'outcome'    => 'BAD_PAYLOAD',
            ));

            return array('outcome' => 'BAD_PAYLOAD', 'lookup_ms' => $t_lookup, 'write_ms' => 0.0);
        }

        $selected_dist_id = (string) $selected_offer->distributor_id;
        $brand_changed = DistributorProductHelper::sync_product_brand_from_payload($product_id, $selected_payload);

        $qty = (int) ($selected_payload->quantity ?? 0);
        $true_cost = (is_numeric($selected_payload->true_cost) && (float) $selected_payload->true_cost > 0)
            ? (float) $selected_payload->true_cost
            : null;

        // Compute mode-aware sell price for storefront, plus global-markup
        // recommended price for LAST_COMPUTED meta.
        $t0 = microtime(true);
        $sell_price = DistributorProductHelper::compute_sell_price_for_product($product_id, $selected_payload);
        $computed_price_for_meta = DistributorProductHelper::resolve_recommended_price_for_sync(
            $selected_payload,
            is_numeric($sell_price) ? (float) $sell_price : null
        );
        $this->profile('compute_sell_price', $t0, array(
            'product_id' => $product_id,
            'selected'   => $selected_dist_id,
            'ok'         => (is_numeric($sell_price) && (float) $sell_price > 0),
            'computed_for_meta' => (is_numeric($computed_price_for_meta) && (float) $computed_price_for_meta > 0)
                ? (float) $computed_price_for_meta
                : null,
        ));

        if (! is_numeric($sell_price) || (float) $sell_price <= 0) {
            $t0 = microtime(true);

            $desired_qty    = $this->resolve_desired_stock_qty((int) $qty, $stock_oos_override_enabled, $local_stock_override_qty);
            $desired_status = ($desired_qty > 0 ? 'instock' : 'outofstock');

            $cur_qty    = (int) ($product->get_stock_quantity() ?? 0);
            $cur_status = (string) $product->get_stock_status();

            $needs_save = (($cur_qty !== (int) $desired_qty) || ($cur_status !== (string) $desired_status));

            if ($needs_save) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($desired_qty);
                $product->set_stock_status($desired_status);
                $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);
                $product->save();
            } else {
                update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);
            }

            $t_write = $this->ms_since($t0);

            $this->profile('write_stock_only_bad_price', $t0, array(
                'product_id' => $product_id,
                'upc'        => $upc,
                'selected'   => $selected_dist_id,
                'qty'        => $desired_qty,
                'stock_override' => $stock_oos_override_enabled ? 1 : 0,
                'local_stock_override_qty' => $local_stock_override_qty,
                'brand_changed' => $brand_changed ? 1 : 0,
                'saved'      => $needs_save ? 1 : 0,
            ));

            $this->profile('TOTAL product', $t_start, array(
                'product_id' => $product_id,
                'outcome'    => 'BAD_PRICE_STOCK_ONLY',
            ));

            return array('outcome' => 'BAD_PRICE_STOCK_ONLY', 'lookup_ms' => $t_lookup, 'write_ms' => $t_write);
        }

        $sell_price = (float) $sell_price;

        // Profit floor
        $t0 = microtime(true);

        $fee_raw = Options::get_payment_processor_fee_percent();
        $fee_pct = is_numeric($fee_raw) ? (float) $fee_raw : 0.0;
        $fee_pct = ($fee_pct > 1.0) ? ($fee_pct / 100.0) : $fee_pct;

        $min_profitable_price = null;
        if ($true_cost !== null && $fee_pct >= 0) {
            $min_profitable_price = $true_cost * (1.0 + $fee_pct);
        }

        $this->profile('profit_floor_calc', $t0, array(
            'product_id' => $product_id,
            'fee_pct'    => $fee_pct,
            'true_cost'  => $true_cost,
            'floor'      => $min_profitable_price,
        ));

        if ($min_profitable_price !== null && $sell_price < $min_profitable_price) {
            $t0 = microtime(true);

            $desired_qty = $this->resolve_desired_stock_qty(0, $stock_oos_override_enabled, $local_stock_override_qty);
            $desired_status = ($desired_qty > 0 ? 'instock' : 'outofstock');

            $cur_qty    = (int) ($product->get_stock_quantity() ?? 0);
            $cur_status = (string) $product->get_stock_status();
            $needs_save = (($cur_qty !== $desired_qty) || ($cur_status !== $desired_status));

            if ($needs_save) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($desired_qty);
                $product->set_stock_status($desired_status);
                $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);
                $product->save();
            } else {
                update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);
            }

            $t_write = $this->ms_since($t0);

            $this->profile('write_forced_oos_floor', $t0, array(
                'product_id' => $product_id,
                'upc'        => $upc,
                'selected'   => $selected_dist_id,
                'sell'       => $sell_price,
                'floor'      => $min_profitable_price,
                'stock_override' => $stock_oos_override_enabled ? 1 : 0,
                'local_stock_override_qty' => $local_stock_override_qty,
                'final_qty' => $desired_qty,
                'final_status' => $desired_status,
                'brand_changed' => $brand_changed ? 1 : 0,
                'saved'      => $needs_save ? 1 : 0,
            ));

            $this->profile('TOTAL product', $t_start, array(
                'product_id' => $product_id,
                'outcome'    => 'FORCED_OOS_PROFIT_FLOOR',
            ));

            return array('outcome' => 'FORCED_OOS_PROFIT_FLOOR', 'lookup_ms' => $t_lookup, 'write_ms' => $t_write);
        }

        // Normal diff-aware write
        $t0 = microtime(true);

        $desired_qty    = $this->resolve_desired_stock_qty((int) $qty, $stock_oos_override_enabled, $local_stock_override_qty);
        $desired_status = ($desired_qty > 0 ? 'instock' : 'outofstock');
        $price_pair = DistributorProductHelper::resolve_regular_and_sale_prices(
            (float) $sell_price,
            $selected_payload->msrp ?? null
        );
        $desired_regular_price = (string) ($price_pair['regular'] ?? '');
        $desired_sale_price    = (string) ($price_pair['sale'] ?? '');

        $cur_qty           = (int) ($product->get_stock_quantity() ?? 0);
        $cur_status        = (string) $product->get_stock_status();
        $cur_regular_price = (string) $product->get_regular_price();
        $cur_sale_price    = (string) $product->get_sale_price();

        $stock_changed = (($cur_qty !== $desired_qty) || ($cur_status !== $desired_status));
        $price_changed = ($cur_regular_price !== $desired_regular_price) || ($cur_sale_price !== $desired_sale_price);

        if ($stock_changed) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($desired_qty);
            $product->set_stock_status($desired_status);
        }

        if ($price_changed) {
            $product->set_regular_price($desired_regular_price);
            $product->set_sale_price($desired_sale_price);
        }

        // You said you updated this helper; it should now return bool $meta_changed.
        $meta_changed = (bool) DistributorProductHelper::update_fflhub_meta_from_payload_for_sync(
            ($product instanceof WC_Product_Simple) ? $product : $product,
            $selected_dist_id,
            $selected_payload,
            (float) $computed_price_for_meta,
            $offers
        );

        $needs_save = ($stock_changed || $price_changed || $meta_changed);

        if ($needs_save) {
            // Helper already sets LAST_SYNC meta, but we also set it here to guarantee rotation.
            $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);
            $product->save();
        } else {
            // Keep rotation without heavy save().
            update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);
        }

        $t_write = $this->ms_since($t0);

        $this->profile('write_decision', $t0, array(
            'product_id'    => $product_id,
            'upc'           => $upc,
            'selected'      => $selected_dist_id,
            'qty'           => $desired_qty,
            'sell'          => $sell_price,
            'computed_for_meta' => (float) $computed_price_for_meta,
            'stock_override' => $stock_oos_override_enabled ? 1 : 0,
            'local_stock_override_qty' => $local_stock_override_qty,
            'stock_changed' => $stock_changed ? 1 : 0,
            'price_changed' => $price_changed ? 1 : 0,
            'meta_changed'  => $meta_changed ? 1 : 0,
            'brand_changed' => $brand_changed ? 1 : 0,
            'saved'         => $needs_save ? 1 : 0,
        ));

        $this->log_ctx('Product END', array(
            'product_id'   => $product_id,
            'upc'          => $upc,
            'selected'     => $selected_dist_id,
            'final_qty'    => $desired_qty,
            'final_status' => $desired_status,
            'final_regular' => $desired_regular_price,
            'final_sale'   => $desired_sale_price,
            'saved'        => $needs_save ? 1 : 0,
        ));

        $this->profile('TOTAL product', $t_start, array(
            'product_id' => $product_id,
            'outcome'    => $needs_save ? 'UPDATED_NORMAL' : 'NOOP_BUMP_ONLY',
        ));

        return array(
            'outcome'   => $needs_save ? 'UPDATED_NORMAL' : 'NOOP_BUMP_ONLY',
            'lookup_ms' => (float) $t_lookup,
            'write_ms'  => (float) $t_write,
        );
    }

    /**
     * Check whether stock out-of-stock override is enabled on a product.
     */
    private function is_stock_oos_override_enabled(WC_Product $product): bool
    {
        $raw = $product->get_meta(ProductMeta::FFLHUB_STOCK_OOS_OVERRIDE_META, true);
        $normalized = strtolower(trim((string) $raw));
        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    private function get_local_stock_override_qty_for_sync(WC_Product $product): int
    {
        $enabled_raw = $product->get_meta(ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_ENABLED_META, true);
        $enabled = in_array(strtolower(trim((string) $enabled_raw)), ['1', 'true', 'yes', 'y', 'on'], true);
        if (!$enabled) {
            return 0;
        }

        $qty_raw = $product->get_meta(ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_QTY_META, true);
        if (!is_numeric($qty_raw)) {
            return 0;
        }

        return max(0, (int) $qty_raw);
    }

    private function resolve_desired_stock_qty(int $distributor_qty, bool $stock_oos_override_enabled, int $local_stock_override_qty): int
    {
        $distributor_qty = max(0, $distributor_qty);
        $local_stock_override_qty = max(0, $local_stock_override_qty);

        if ($stock_oos_override_enabled) {
            return $local_stock_override_qty;
        }

        if ($distributor_qty <= 0 && $local_stock_override_qty > 0) {
            return $local_stock_override_qty;
        }

        return $distributor_qty;
    }

    /**
     * @return array{enabled:bool,ids:array<int,string>}
     */
    private function get_distributor_lock_for_product(WC_Product $product): array
    {
        $enabled_raw = $product->get_meta(ProductMeta::FFLHUB_DISTRIBUTOR_LOCK_ENABLED_META, true);
        $enabled = in_array(
            strtolower(trim((string) $enabled_raw)),
            ['1', 'true', 'yes', 'y', 'on'],
            true
        );

        $ids_raw = $product->get_meta(ProductMeta::FFLHUB_DISTRIBUTOR_LOCK_IDS_META, true);
        $ids = $this->normalize_distributor_lock_ids($ids_raw);

        return array(
            'enabled' => $enabled,
            'ids' => $ids,
        );
    }

    /**
     * @param array<string,DistributorOffer> $offers
     * @return array<string,DistributorOffer>
     */
    private function filter_offers_by_enabled_distributors(array $offers): array
    {
        if (empty($offers)) {
            return [];
        }

        $filtered = [];

        foreach ($offers as $offer_key => $offer) {
            if (!$offer instanceof DistributorOffer) {
                continue;
            }

            $dist_id = strtolower(trim((string) ($offer->distributor_id ?: $offer_key)));
            if ($dist_id === '') {
                continue;
            }

            if (!Options::is_distributor_enabled($dist_id)) {
                continue;
            }

            $filtered[$dist_id] = $offer;
        }

        return $filtered;
    }

    /**
     * @param array<string,DistributorOffer> $offers
     * @param string[] $locked_dist_ids
     * @return array<string,DistributorOffer>
     */
    private function filter_offers_by_locked_distributors(array $offers, array $locked_dist_ids): array
    {
        if (empty($offers) || empty($locked_dist_ids)) {
            return [];
        }

        $allowed = [];
        foreach ($locked_dist_ids as $dist_id) {
            $normalized = strtolower(trim((string) $dist_id));
            if ($normalized === '') {
                continue;
            }

            $allowed[$normalized] = true;
        }

        if (empty($allowed)) {
            return [];
        }

        $filtered = [];
        foreach ($offers as $offer_key => $offer) {
            if (!$offer instanceof DistributorOffer) {
                continue;
            }

            $candidate_ids = array(
                strtolower(trim((string) $offer_key)),
                strtolower(trim((string) $offer->distributor_id)),
            );

            foreach ($candidate_ids as $candidate_id) {
                if ($candidate_id !== '' && isset($allowed[$candidate_id])) {
                    $filtered[$candidate_id] = $offer;
                    break;
                }
            }
        }

        return $filtered;
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    private function normalize_distributor_lock_ids($raw): array
    {
        if (is_array($raw)) {
            $values = $raw;
        } else {
            $raw_string = trim((string) $raw);
            if ($raw_string === '') {
                return [];
            }

            $values = [];
            $decoded = json_decode($raw_string, true);
            if (is_array($decoded)) {
                $values = $decoded;
            } else {
                $split = preg_split('/\s*,\s*/', $raw_string);
                if (is_array($split)) {
                    $values = $split;
                }
            }
        }

        $normalized = [];
        foreach ($values as $value) {
            $dist_id = strtolower(trim((string) $value));
            if ($dist_id === '') {
                continue;
            }

            // Respect only currently-enabled distributors.
            if (!Options::is_distributor_enabled($dist_id)) {
                continue;
            }

            $normalized[] = $dist_id;
        }

        return array_values(array_unique($normalized));
    }

    // ---------------------------------------------------------------------
    // DebugLogUtil wrappers (your real API)
    // ---------------------------------------------------------------------

    private function log(string $msg): void
    {
        DebugLogUtil::log(self::DEBUG_CONST, self::LOG_PREFIX, $msg);
    }

    /** @param array<string,mixed> $ctx */
    private function log_ctx(string $msg, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $msg, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function profile(string $label, float $t0, array $ctx = array()): void
    {
        $ctx['elapsed_ms'] = number_format($this->ms_since($t0), 2, '.', '');
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, 'PROFILE: ' . $label, $ctx);
    }

    private function ms_since(float $t0): float
    {
        return (microtime(true) - $t0) * 1000.0;
    }
}
