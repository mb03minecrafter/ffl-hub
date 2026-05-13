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
 * - Use primed postmeta for no-op rows and hydrate WC_Product only for writes.
 * - Only call $product->save() (heavy) when stock/price/meta actually changed.
 *
 * PROFIT FLOOR RULE:
 * If sell price would be below true_cost + transaction fee percent,
 * force product OUT OF STOCK (qty=0) and skip price/meta update.
 */
final class DistributorProductSyncCronService extends AbstractCronService
{
    private const CRON_HOOK = 'fflhub_sync_managed_products';
    private const DEFAULT_RUN_LIMIT = 0; // 0 means no cap.
    private const RUN_LOCK_TRANSIENT = 'fflhub_product_sync_cron_running';
    private const RUN_LOCK_TTL_SECONDS = 15 * MINUTE_IN_SECONDS;
    private const CHANGED_PRODUCTS_LOG_CHUNK_SIZE = 50;

    /**
     * Debug constant + prefix for DebugLogUtil.
     * (Adjust constant name if yours differs; keeping existing global switch too.)
     */
    private const DEBUG_CONST = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX  = '[FFLHUB][ProductSync]';



    private DistributorHandler $handler;

    /**
     * @var array<string,UpcLookupResult>|null
     */
    private ?array $preloadedLookupsByUpc = null;

    /**
     * Product IDs that only need LAST_SYNC bumped after the batch.
     *
     * @var array<int,int>
     */
    private array $pendingLastSyncBumpIds = [];

    private ?string $batchSyncTimestamp = null;
    private string $runLockToken = '';

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
        if (!$this->acquire_run_lock()) {
            $this->log_ctx('run skipped: product sync already running', array(
                'lock' => self::RUN_LOCK_TRANSIENT,
                'ttl_seconds' => self::RUN_LOCK_TTL_SECONDS,
            ));
            return;
        }

        try {
            $this->sync_batch(self::DEFAULT_RUN_LIMIT);
        } finally {
            $this->release_run_lock();
        }
    }

    public function sync_batch(int $limit = self::DEFAULT_RUN_LIMIT): void
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
        $product_ids = ($query && isset($query->posts) && is_array($query->posts))
            ? array_values(array_filter(array_map('intval', $query->posts)))
            : [];

        $this->profile('query_for_managed_products', $t_q, array(
            'limit' => (int) $limit,
            'posts' => (int) $posts,
        ));

        if (! $query || ! $query->have_posts()) {
            $this->log('sync_batch: no managed products found.');
            $this->log_ctx('---- RUN END (NOOP) ----', array('elapsed_ms' => $this->ms_since($t_start)));
            return;
        }

        $this->batchSyncTimestamp = current_time('mysql');
        $this->pendingLastSyncBumpIds = [];

        $t_meta = microtime(true);
        if (!empty($product_ids) && function_exists('update_meta_cache')) {
            update_meta_cache('post', $product_ids);
        }

        $upcs_for_lookup = [];
        foreach ($product_ids as $product_id) {
            $upc = trim((string) get_post_meta($product_id, ProductMeta::FFLHUB_UPC_META, true));
            if ($upc !== '') {
                $upcs_for_lookup[$upc] = $upc;
            }
        }

        $this->profile('bulk_prime_product_meta', $t_meta, array(
            'products' => count($product_ids),
            'upcs'     => count($upcs_for_lookup),
        ));

        $t_bulk_lookup = microtime(true);
        $this->preloadedLookupsByUpc = $this->handler->get_payloads_for_upcs(array_values($upcs_for_lookup), false);
        $this->profile('bulk_distributor_lookup', $t_bulk_lookup, array(
            'upcs'    => count($upcs_for_lookup),
            'results' => is_array($this->preloadedLookupsByUpc) ? count($this->preloadedLookupsByUpc) : 0,
        ));

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
        $changed_products = [];

        foreach ($product_ids as $product_id) {
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

                    if (!empty($result['changed'])) {
                        $changed_products[] = $this->normalize_changed_product_result($result);
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

        $t_bump = microtime(true);
        $bulk_bumped = $this->flush_pending_last_sync_bumps();
        $this->profile('bulk_last_sync_bumps', $t_bump, array(
            'products' => (int) $bulk_bumped,
        ));

        $this->preloadedLookupsByUpc = null;
        $this->batchSyncTimestamp = null;

        $lookup_avg = ($processed > 0) ? ($lookup_ms_sum / (float) $processed) : 0.0;
        $write_avg  = ($processed > 0) ? ($write_ms_sum / (float) $processed) : 0.0;
        $changed_count = count($changed_products);

        $this->log_changed_products_summary($changed_products);

        $this->profile('Run summary', $t_start, array_merge(array(
            'limit'         => (int) $limit,
            'queried'       => (int) count($product_ids),
            'processed'     => (int) $processed,
            'changed'       => (int) $changed_count,
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

        $now_mysql = $this->batchSyncTimestamp ?: current_time('mysql');

        if ($product_id <= 0) {
            return array('outcome' => 'LOOKUP_INVALID', 'lookup_ms' => 0.0, 'write_ms' => 0.0);
        }

        $t0 = microtime(true);
        $post_status = get_post_status($product_id);
        $this->profile('get_post_status', $t0, array('product_id' => $product_id, 'status' => (string) $post_status));

        if ('trash' === $post_status) {
            $this->bump_last_sync_meta($product_id, $now_mysql);

            $this->profile('TOTAL product', $t_start, array(
                'product_id' => $product_id,
                'outcome'    => 'SKIP_TRASH',
            ));

            return array('outcome' => 'SKIP_TRASH', 'lookup_ms' => 0.0, 'write_ms' => 0.0);
        }

        $t0 = microtime(true);
        $product_state = $this->get_product_meta_state($product_id);
        $this->profile('load_product_meta_state', $t0, array('product_id' => $product_id));

        $this->log_ctx('Product START', array(
            'product_id'    => $product_id,
            'status'        => (string) $post_status,
            'stock'         => (int) ($product_state['stock_qty'] ?? 0),
            'stock_status'  => (string) ($product_state['stock_status'] ?? ''),
            'regular_price' => (string) ($product_state['regular_price'] ?? ''),
        ));

        $stock_oos_override_enabled = !empty($product_state['stock_oos_override_enabled']);
        $local_stock_override_qty = (int) ($product_state['local_stock_override_qty'] ?? 0);
        $this->log_ctx('Stock override state', array(
            'product_id'                => $product_id,
            'stock_oos_override_enabled' => $stock_oos_override_enabled ? 1 : 0,
            'local_stock_override_qty'  => $local_stock_override_qty,
        ));

        $t0 = microtime(true);
        $upc = trim((string) ($product_state['upc'] ?? ''));
        $this->profile('get_post_meta_upc', $t0, array('product_id' => $product_id, 'has_upc' => ($upc !== '')));

        if ($upc === '') {
            $this->bump_last_sync_meta($product_id, $now_mysql);

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
            if ($this->preloadedLookupsByUpc !== null) {
                $lookup_key = $this->normalize_upc_for_lookup($upc);
                $lookup = ($lookup_key !== '' && isset($this->preloadedLookupsByUpc[$lookup_key]))
                    ? $this->preloadedLookupsByUpc[$lookup_key]
                    : new UpcLookupResult([]);
            } else {
                $lookup = $this->handler->get_payloads_for_upc($upc, false); // returns UpcLookupResult, we dont need image to update pricing and quantity data so we exclude it by passing false into the optional argument
            }
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
            $this->bump_last_sync_meta($product_id, $now_mysql);

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

        $dist_lock = $this->get_distributor_lock_from_meta_state($product_state);
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

            $cur_qty    = (int) ($product_state['stock_qty'] ?? 0);
            $cur_status = (string) ($product_state['stock_status'] ?? '');
            $needs_save = (($cur_qty !== $desired_qty) || ($cur_status !== $desired_status));

            if ($needs_save) {
                $product = $this->load_product_for_save($product_id);
                if (!($product instanceof WC_Product)) {
                    $this->bump_last_sync_meta($product_id, $now_mysql);
                    return $this->product_load_failed_result($product_id, $upc, $t_lookup, $t_start);
                }

                $product->set_manage_stock(true);
                $product->set_stock_quantity($desired_qty);
                $product->set_stock_status($desired_status);
                $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);
                $product->save();
            } else {
                $this->bump_last_sync_meta($product_id, $now_mysql);
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

            return array(
                'outcome' => 'NO_OFFERS_OOS',
                'lookup_ms' => $t_lookup,
                'write_ms' => $t_write,
                'product_id' => $product_id,
                'upc' => $upc,
                'changed' => $needs_save,
                'saved' => $needs_save,
                'changes' => $needs_save ? array('stock') : array(),
                'final_qty' => $desired_qty,
                'final_status' => $desired_status,
                'selected' => '',
            );
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
            $this->bump_last_sync_meta($product_id, $now_mysql);

            $this->profile('TOTAL product', $t_start, array(
                'product_id' => $product_id,
                'outcome'    => 'NO_SELECTED_OFFER',
            ));

            return array('outcome' => 'NO_SELECTED_OFFER', 'lookup_ms' => $t_lookup, 'write_ms' => 0.0);
        }

        $selected_payload = $selected_offer->product;
        if (! ($selected_payload instanceof DistributorProductPayload)) {
            $this->bump_last_sync_meta($product_id, $now_mysql);

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

            $cur_qty    = (int) ($product_state['stock_qty'] ?? 0);
            $cur_status = (string) ($product_state['stock_status'] ?? '');

            $needs_save = (($cur_qty !== (int) $desired_qty) || ($cur_status !== (string) $desired_status));

            if ($needs_save) {
                $product = $this->load_product_for_save($product_id);
                if (!($product instanceof WC_Product)) {
                    $this->bump_last_sync_meta($product_id, $now_mysql);
                    return $this->product_load_failed_result($product_id, $upc, $t_lookup, $t_start);
                }

                $product->set_manage_stock(true);
                $product->set_stock_quantity($desired_qty);
                $product->set_stock_status($desired_status);
                $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);
                $product->save();
            } else {
                $this->bump_last_sync_meta($product_id, $now_mysql);
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

            $changes = [];
            if ($needs_save) {
                $changes[] = 'stock';
            }
            if ($brand_changed) {
                $changes[] = 'brand';
            }

            return array(
                'outcome' => 'BAD_PRICE_STOCK_ONLY',
                'lookup_ms' => $t_lookup,
                'write_ms' => $t_write,
                'product_id' => $product_id,
                'upc' => $upc,
                'selected' => $selected_dist_id,
                'changed' => ($needs_save || $brand_changed),
                'saved' => $needs_save,
                'changes' => $changes,
                'final_qty' => $desired_qty,
                'final_status' => $desired_status,
                'final_regular' => (string) ($product_state['regular_price'] ?? ''),
                'final_sale' => (string) ($product_state['sale_price'] ?? ''),
            );
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

            $cur_qty    = (int) ($product_state['stock_qty'] ?? 0);
            $cur_status = (string) ($product_state['stock_status'] ?? '');
            $needs_save = (($cur_qty !== $desired_qty) || ($cur_status !== $desired_status));

            if ($needs_save) {
                $product = $this->load_product_for_save($product_id);
                if (!($product instanceof WC_Product)) {
                    $this->bump_last_sync_meta($product_id, $now_mysql);
                    return $this->product_load_failed_result($product_id, $upc, $t_lookup, $t_start);
                }

                $product->set_manage_stock(true);
                $product->set_stock_quantity($desired_qty);
                $product->set_stock_status($desired_status);
                $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);
                $product->save();
            } else {
                $this->bump_last_sync_meta($product_id, $now_mysql);
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

            $changes = [];
            if ($needs_save) {
                $changes[] = 'stock';
            }
            if ($brand_changed) {
                $changes[] = 'brand';
            }

            return array(
                'outcome' => 'FORCED_OOS_PROFIT_FLOOR',
                'lookup_ms' => $t_lookup,
                'write_ms' => $t_write,
                'product_id' => $product_id,
                'upc' => $upc,
                'selected' => $selected_dist_id,
                'changed' => ($needs_save || $brand_changed),
                'saved' => $needs_save,
                'changes' => $changes,
                'final_qty' => $desired_qty,
                'final_status' => $desired_status,
                'final_regular' => (string) ($product_state['regular_price'] ?? ''),
                'final_sale' => (string) ($product_state['sale_price'] ?? ''),
                'sell' => $sell_price,
                'floor' => $min_profitable_price,
            );
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

        $cur_qty           = (int) ($product_state['stock_qty'] ?? 0);
        $cur_status        = (string) ($product_state['stock_status'] ?? '');
        $cur_regular_price = $this->normalize_price_for_compare((string) ($product_state['regular_price'] ?? ''));
        $cur_sale_price    = $this->normalize_price_for_compare((string) ($product_state['sale_price'] ?? ''));

        $stock_changed = (($cur_qty !== $desired_qty) || ($cur_status !== $desired_status));
        $price_changed = ($cur_regular_price !== $this->normalize_price_for_compare($desired_regular_price))
            || ($cur_sale_price !== $this->normalize_price_for_compare($desired_sale_price));

        $meta_changes = DistributorProductHelper::get_sync_payload_meta_changes_from_raw_meta(
            (array) ($product_state['meta'] ?? []),
            $selected_dist_id,
            $selected_payload,
            (float) $computed_price_for_meta,
            $offers
        );
        $meta_changed = !empty($meta_changes['changed']);

        $needs_save = ($stock_changed || $price_changed || $meta_changed);

        if ($needs_save) {
            $product = $this->load_product_for_save($product_id);
            if (!($product instanceof WC_Product)) {
                $this->bump_last_sync_meta($product_id, $now_mysql);
                return $this->product_load_failed_result($product_id, $upc, $t_lookup, $t_start);
            }

            if ($stock_changed) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($desired_qty);
                $product->set_stock_status($desired_status);
            }

            if ($price_changed) {
                $product->set_regular_price($desired_regular_price);
                $product->set_sale_price($desired_sale_price);
            }

            $meta_changed = $meta_changed || (bool) DistributorProductHelper::update_fflhub_meta_from_payload_for_sync(
                $product,
                $selected_dist_id,
                $selected_payload,
                (float) $computed_price_for_meta,
                $offers
            );

            // Helper already sets LAST_SYNC meta, but we also set it here to guarantee rotation.
            $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);
            $product->save();
        } else {
            // Keep rotation without heavy save().
            $this->bump_last_sync_meta($product_id, $now_mysql);
        }

        $changes = [];
        if ($stock_changed) {
            $changes[] = 'stock';
        }
        if ($price_changed) {
            $changes[] = 'price';
        }
        if ($meta_changed) {
            $changes[] = 'meta';
        }
        if ($brand_changed) {
            $changes[] = 'brand';
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
            'product_id' => $product_id,
            'upc' => $upc,
            'selected' => $selected_dist_id,
            'changed' => ($needs_save || $brand_changed),
            'saved' => $needs_save,
            'changes' => $changes,
            'final_qty' => $desired_qty,
            'final_status' => $desired_status,
            'final_regular' => $desired_regular_price,
            'final_sale' => $desired_sale_price,
            'sell' => $sell_price,
            'computed_for_meta' => (float) $computed_price_for_meta,
        );
    }

    /**
     * Load the product's primed meta into a cheap sync state.
     *
     * @return array<string,mixed>
     */
    private function get_product_meta_state(int $product_id): array
    {
        $raw_meta = get_post_meta($product_id);
        $raw_meta = is_array($raw_meta) ? $raw_meta : [];

        $local_stock_override_qty = 0;
        if ($this->is_truthy_meta_value($this->raw_meta_value($raw_meta, ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_ENABLED_META))) {
            $qty_raw = $this->raw_meta_value($raw_meta, ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_QTY_META);
            $local_stock_override_qty = is_numeric($qty_raw) ? max(0, (int) $qty_raw) : 0;
        }

        return [
            'meta' => $raw_meta,
            'upc' => trim((string) $this->raw_meta_value($raw_meta, ProductMeta::FFLHUB_UPC_META)),
            'stock_qty' => $this->normalize_stock_qty($this->raw_meta_value($raw_meta, '_stock')),
            'stock_status' => trim((string) $this->raw_meta_value($raw_meta, '_stock_status')),
            'regular_price' => trim((string) $this->raw_meta_value($raw_meta, '_regular_price')),
            'sale_price' => trim((string) $this->raw_meta_value($raw_meta, '_sale_price')),
            'stock_oos_override_enabled' => $this->is_truthy_meta_value(
                $this->raw_meta_value($raw_meta, ProductMeta::FFLHUB_STOCK_OOS_OVERRIDE_META)
            ),
            'local_stock_override_qty' => $local_stock_override_qty,
        ];
    }

    private function load_product_for_save(int $product_id): ?WC_Product
    {
        if (!function_exists('wc_get_product')) {
            return null;
        }

        $t0 = microtime(true);
        $product = wc_get_product($product_id);
        $this->profile('wc_get_product_for_save', $t0, array('product_id' => $product_id));

        if (!($product instanceof WC_Product)) {
            $this->log_ctx('wc_get_product returned non-product', array('product_id' => $product_id));
            return null;
        }

        return $product;
    }

    /**
     * @param array<string,array<int,mixed>> $raw_meta
     * @return mixed
     */
    private function raw_meta_value(array $raw_meta, string $key)
    {
        if (!isset($raw_meta[$key]) || !is_array($raw_meta[$key]) || $raw_meta[$key] === []) {
            return '';
        }

        $value = $raw_meta[$key][0] ?? '';
        return function_exists('maybe_unserialize') ? maybe_unserialize($value) : $value;
    }

    /**
     * @param mixed $value
     */
    private function is_truthy_meta_value($value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'on'], true);
    }

    /**
     * @param mixed $value
     */
    private function normalize_stock_qty($value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function normalize_price_for_compare(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return $value;
        }

        return function_exists('wc_format_decimal')
            ? (string) wc_format_decimal((float) $value, 2)
            : number_format((float) $value, 2, '.', '');
    }

    /**
     * @return array<string,mixed>
     */
    private function product_load_failed_result(int $product_id, string $upc, float $lookup_ms, float $t_start): array
    {
        $this->profile('TOTAL product', $t_start, array(
            'product_id' => $product_id,
            'outcome'    => 'LOOKUP_INVALID',
        ));

        return array(
            'outcome' => 'LOOKUP_INVALID',
            'lookup_ms' => $lookup_ms,
            'write_ms' => 0.0,
            'product_id' => $product_id,
            'upc' => $upc,
            'changed' => false,
            'saved' => false,
            'changes' => array(),
        );
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
    private function get_distributor_lock_from_meta_state(array $product_state): array
    {
        $raw_meta = isset($product_state['meta']) && is_array($product_state['meta'])
            ? $product_state['meta']
            : [];

        $enabled = $this->is_truthy_meta_value(
            $this->raw_meta_value($raw_meta, ProductMeta::FFLHUB_DISTRIBUTOR_LOCK_ENABLED_META)
        );
        $ids = $this->normalize_distributor_lock_ids(
            $this->raw_meta_value($raw_meta, ProductMeta::FFLHUB_DISTRIBUTOR_LOCK_IDS_META)
        );

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

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function normalize_changed_product_result(array $result): array
    {
        $changes = isset($result['changes']) && is_array($result['changes'])
            ? array_values(array_unique(array_filter(array_map('strval', $result['changes']))))
            : [];

        return array(
            'product_id' => (int) ($result['product_id'] ?? 0),
            'upc' => (string) ($result['upc'] ?? ''),
            'outcome' => (string) ($result['outcome'] ?? ''),
            'selected' => (string) ($result['selected'] ?? ''),
            'changes' => $changes,
            'saved' => !empty($result['saved']) ? 1 : 0,
            'final_qty' => isset($result['final_qty']) ? (int) $result['final_qty'] : null,
            'final_status' => (string) ($result['final_status'] ?? ''),
            'final_regular' => (string) ($result['final_regular'] ?? ''),
            'final_sale' => (string) ($result['final_sale'] ?? ''),
            'sell' => isset($result['sell']) && is_numeric($result['sell']) ? (float) $result['sell'] : null,
            'computed_for_meta' => isset($result['computed_for_meta']) && is_numeric($result['computed_for_meta'])
                ? (float) $result['computed_for_meta']
                : null,
        );
    }

    /**
     * @param array<int,array<string,mixed>> $changed_products
     */
    private function log_changed_products_summary(array $changed_products): void
    {
        $count = count($changed_products);
        $ids = [];
        foreach ($changed_products as $product) {
            $product_id = (int) ($product['product_id'] ?? 0);
            if ($product_id > 0) {
                $ids[] = $product_id;
            }
        }

        $this->log_ctx('Changed products summary', array(
            'count' => $count,
            'product_ids' => $ids,
        ));

        if ($count <= 0) {
            return;
        }

        $chunk_index = 0;
        foreach (array_chunk($changed_products, self::CHANGED_PRODUCTS_LOG_CHUNK_SIZE) as $chunk) {
            $chunk_index++;
            $this->log_ctx('Changed products detail', array(
                'chunk' => $chunk_index,
                'chunk_size' => count($chunk),
                'products' => $chunk,
            ));
        }
    }

    private function acquire_run_lock(): bool
    {
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return true;
        }

        $existing = get_transient(self::RUN_LOCK_TRANSIENT);
        if (!empty($existing)) {
            return false;
        }

        $token_entropy = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('', true);
        $this->runLockToken = sprintf('%d:%s', getmypid() ?: 0, $token_entropy);
        set_transient(self::RUN_LOCK_TRANSIENT, $this->runLockToken, self::RUN_LOCK_TTL_SECONDS);

        return true;
    }

    private function release_run_lock(): void
    {
        if ($this->runLockToken === '' || !function_exists('get_transient') || !function_exists('delete_transient')) {
            return;
        }

        if ((string) get_transient(self::RUN_LOCK_TRANSIENT) === $this->runLockToken) {
            delete_transient(self::RUN_LOCK_TRANSIENT);
        }

        $this->runLockToken = '';
    }

    private function bump_last_sync_meta(int $product_id, string $now_mysql): void
    {
        if ($product_id <= 0) {
            return;
        }

        if ($this->preloadedLookupsByUpc !== null) {
            $this->pendingLastSyncBumpIds[$product_id] = $product_id;
            return;
        }

        update_post_meta($product_id, ProductMeta::FFLHUB_LAST_SYNC_META, $now_mysql);
    }

    private function flush_pending_last_sync_bumps(): int
    {
        if (empty($this->pendingLastSyncBumpIds)) {
            return 0;
        }

        $product_ids = array_values(array_filter(array_map('intval', $this->pendingLastSyncBumpIds)));
        $this->pendingLastSyncBumpIds = [];

        if (empty($product_ids)) {
            return 0;
        }

        $now_mysql = $this->batchSyncTimestamp ?: current_time('mysql');

        return $this->bulk_update_post_meta_for_products(
            $product_ids,
            ProductMeta::FFLHUB_LAST_SYNC_META,
            $now_mysql
        );
    }

    /**
     * Bulk update a single post meta key for many products.
     *
     * WordPress postmeta has no unique key on (post_id, meta_key), so this does
     * an UPDATE for existing rows and an INSERT only for missing products.
     *
     * @param array<int,int> $product_ids
     */
    private function bulk_update_post_meta_for_products(array $product_ids, string $meta_key, string $meta_value): int
    {
        global $wpdb;

        $product_ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));
        if (empty($product_ids)) {
            return 0;
        }

        $updated_products = 0;

        foreach (array_chunk($product_ids, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));

            $update_sql = "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = %s AND post_id IN ({$placeholders})";
            $wpdb->query(
                $wpdb->prepare(
                    $update_sql,
                    array_merge([$meta_value, $meta_key], $chunk)
                )
            );

            $existing_sql = "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$placeholders})";
            $existing = $wpdb->get_col(
                $wpdb->prepare(
                    $existing_sql,
                    array_merge([$meta_key], $chunk)
                )
            );

            $existing_lookup = [];
            if (is_array($existing)) {
                foreach ($existing as $existing_id) {
                    $existing_lookup[(int) $existing_id] = true;
                }
            }

            $missing = [];
            foreach ($chunk as $product_id) {
                if (!isset($existing_lookup[(int) $product_id])) {
                    $missing[] = (int) $product_id;
                }
            }

            if (!empty($missing)) {
                $insert_placeholders = [];
                $insert_values = [];
                foreach ($missing as $product_id) {
                    $insert_placeholders[] = '(%d, %s, %s)';
                    $insert_values[] = $product_id;
                    $insert_values[] = $meta_key;
                    $insert_values[] = $meta_value;
                }

                $insert_sql = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode(', ', $insert_placeholders);
                $wpdb->query($wpdb->prepare($insert_sql, $insert_values));
            }

            foreach ($chunk as $product_id) {
                wp_cache_delete((int) $product_id, 'post_meta');
            }

            $updated_products += count($chunk);
        }

        return $updated_products;
    }

    private function normalize_upc_for_lookup(string $upc): string
    {
        $normalized = preg_replace('/\D+/', '', $upc);
        return is_string($normalized) ? trim($normalized) : '';
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
