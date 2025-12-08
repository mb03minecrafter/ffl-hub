<?php

namespace FFLHub\Distributor\Product;

use FFLHub\Plugin;
use WP_Query;
use WC_Product;
use FFLHub\Distributor\Product\DistributorProductPayload;
use FFLHub\Product\ProductMeta;


use function apply_filters;
use function class_exists;
use function current_time;
use function get_option;
use function get_post_meta;
use function get_post_status;
use function time;
use function update_post_meta;
use function wc_get_product;
use function wc_format_decimal;
use function wp_json_encode;
use function wp_next_scheduled;
use function wp_schedule_event;
use function wp_unschedule_event;

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
class DistributorProductSync
{
    /**
     * Cron hook name for syncing managed products.
     */
    public const CRON_HOOK = 'fflhub_sync_managed_products';

    /**
     * Initialize hooks.
     *
     * Call this from your main plugin bootstrap, e.g. Plugin::instance()->...
     */
    public static function init(): void
    {
        self::log('init: registering cron handler for ' . self::CRON_HOOK);
        add_action(self::CRON_HOOK, array(__CLASS__, 'handle_cron'));

        // Optional runtime guard if you want it later:
        // add_action('init', array(__CLASS__, 'maybe_schedule_event'));
    }

    /**
     * Activation callback: schedule the recurring cron event.
     *
     * Call from register_activation_hook in your main plugin file (via alias FFLHub_Product_Sync if you want).
     */
    public static function activate(): void
    {
        self::log('activate: attempting to schedule cron event.');

        if (! function_exists('wp_next_scheduled')) {
            self::log('activate: wp_next_scheduled not available, aborting.');
            return;
        }

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            $timestamp = time() + 300; // 5 minutes from now
            $result    = wp_schedule_event($timestamp, 'fflhub_every_five_minutes', self::CRON_HOOK);
            self::log(
                'activate: scheduled cron event at ' .
                gmdate('c', $timestamp) .
                ' result=' . var_export($result, true)
            );
        } else {
            self::log('activate: cron event already scheduled.');
        }
    }

    /**
     * Deactivation callback: unschedule our cron event.
     *
     * Call from register_deactivation_hook in your main plugin file (via alias FFLHub_Product_Sync if you want).
     */
    public static function deactivate(): void
    {
        self::log('deactivate: attempting to unschedule cron event.');

        if (! function_exists('wp_next_scheduled')) {
            self::log('deactivate: wp_next_scheduled not available, aborting.');
            return;
        }

        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            self::log('deactivate: unscheduled cron event at ' . gmdate('c', $timestamp));
        } else {
            self::log('deactivate: no scheduled cron event found.');
        }
    }

    /**
     * (Optional) Runtime guard to ensure the event exists.
     * If you want this behavior, you can call maybe_schedule_event() on init.
     */
    public static function maybe_schedule_event(): void
    {
        if (! function_exists('wp_next_scheduled')) {
            return;
        }

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            $timestamp = time() + 300;
            $result    = wp_schedule_event($timestamp, 'fflhub_every_five_minutes', self::CRON_HOOK);
            self::log(
                'maybe_schedule_event: scheduled cron event at ' .
                gmdate('c', $timestamp) .
                ' result=' . var_export($result, true)
            );
        }
    }

    /**
     * Cron entry point.
     *
     * Processes a batch of FFLHub-managed products each run.
     */
    public static function handle_cron(): void
    {
        $start = microtime(true);
        self::log('handle_cron: START');

        // Adjust limit as needed; keep modest to avoid timeouts.
        self::sync_batch(50);

        $elapsed_ms = round((microtime(true) - $start) * 1000, 2);
        self::log('handle_cron: END elapsed=' . $elapsed_ms . ' ms');
    }

    /**
     * Sync a batch of FFLHub-managed products.
     *
     * @param int $limit Number of products to process in this run.
     */
    public static function sync_batch(int $limit = 50): void
    {
        $start = microtime(true);
        self::log('sync_batch: starting with limit=' . (int) $limit);

        if (! class_exists(WC_Product::class)) {
            self::log('sync_batch: WooCommerce not loaded; aborting.');
            return;
        }

        if (! class_exists(ProductMeta::class)) {
            self::log('sync_batch: ProductMeta missing; aborting.');
            return;
        }

        $args = array(
            'post_type'      => 'product',
            'post_status'    => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => max(1, (int) $limit),
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'   => ProductMeta::FFLHUB_MANAGED_META,
                    'value' => 1,
                ),
            ),
            'meta_key'       => ProductMeta::FFLHUB_LAST_SYNC_META,
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        );

        self::log('sync_batch: WP_Query args=' . wp_json_encode($args));

        $q_start = microtime(true);
        $query   = new WP_Query($args);
        $q_ms    = round((microtime(true) - $q_start) * 1000, 2);
        self::log('sync_batch: WP_Query finished in ' . $q_ms . ' ms');

        if (! $query->have_posts()) {
            self::log('sync_batch: no managed products found.');
            return;
        }

        self::log('sync_batch: found ' . (int) $query->post_count . ' products to process.');

        $processed = 0;

        foreach ($query->posts as $product_id) {
            $product_id = (int) $product_id;
            if ($product_id <= 0) {
                self::log('sync_batch: skipping invalid product ID: ' . $product_id);
                continue;
            }

            $p_start = microtime(true);
            self::log('sync_batch: syncing product ID ' . $product_id);

            try {
                self::sync_single_product($product_id);
                $processed++;
            } catch (\Throwable $e) {
                self::log(sprintf(
                    'sync_batch: exception syncing product %d: %s',
                    $product_id,
                    $e->getMessage()
                ));
                continue;
            }

            $p_ms = round((microtime(true) - $p_start) * 1000, 2);
            self::log('sync_batch: finished product ' . $product_id . ' in ' . $p_ms . ' ms');
        }

        $elapsed_ms = round((microtime(true) - $start) * 1000, 2);
        self::log(sprintf(
            'sync_batch: processed %d managed products in %s ms.',
            $processed,
            $elapsed_ms
        ));
    }

    /**
     * Sync pricing and quantity for a single FFLHub-managed WooCommerce product.
     *
     * @param int $product_id
     */
    public static function sync_single_product(int $product_id): void
    {
        $start = microtime(true);
        self::log('sync_single_product: START product_id=' . $product_id);

        if ($product_id <= 0) {
            self::log('sync_single_product: invalid product_id, aborting.');
            return;
        }

        if (! function_exists('wc_get_product')) {
            self::log('sync_single_product: wc_get_product not available, aborting.');
            return;
        }

        $t_load_product = microtime(true);
        $product        = wc_get_product($product_id);
        $t_load_ms      = round((microtime(true) - $t_load_product) * 1000, 2);
        self::log('sync_single_product: wc_get_product took ' . $t_load_ms . ' ms');

        if (! $product instanceof WC_Product) {
            self::log(sprintf('sync_single_product: product %d not found or not a WC_Product.', $product_id));
            return;
        }

        // Skip trashed products.
        $post_status = get_post_status($product_id);
        self::log('sync_single_product: product ' . $product_id . ' post_status=' . $post_status);

        if ('trash' === $post_status) {
            self::log(sprintf('sync_single_product: product %d is trashed; skipping.', $product_id));
            return;
        }

        // Ensure this product is managed by FFLHub.
        $managed_raw = get_post_meta($product_id, ProductMeta::FFLHUB_MANAGED_META, true);
        $managed     = (int) $managed_raw === 1 || $managed_raw === '1' || $managed_raw === true;

        self::log(sprintf(
            'sync_single_product: product %d managed_raw=%s managed=%s',
            $product_id,
            var_export($managed_raw, true),
            $managed ? 'true' : 'false'
        ));

        if (! $managed) {
            self::log(sprintf('sync_single_product: product %d is not FFLHub-managed; skipping.', $product_id));
            return;
        }

        // UPC is our key into distributor data.
        $upc = (string) get_post_meta($product_id, ProductMeta::FFLHUB_UPC_META, true);
        $upc = trim($upc);

        self::log(sprintf(
            'sync_single_product: product %d has UPC="%s"',
            $product_id,
            $upc
        ));

        if ($upc === '') {
            self::log(sprintf('sync_single_product: product %d has no UPC meta; skipping.', $product_id));
            return;
        }

        // Get all distributor payloads for this UPC.
        $t_payloads = microtime(true);
        $lookup     = self::get_distributor_payloads_for_upc($upc);
        $payload_ms = round((microtime(true) - $t_payloads) * 1000, 2);
        self::log('sync_single_product: get_distributor_payloads_for_upc took ' . $payload_ms . ' ms for UPC ' . $upc);

        $carrier_distributors = $lookup['carriers'];
        $cheapest_in_stock    = $lookup['cheapest_in_stock'];
        $cheapest_any         = $lookup['cheapest_any'];

        self::log(sprintf(
            'sync_single_product: UPC %s has %d carrier(s).',
            $upc,
            count($carrier_distributors)
        ));

        if (empty($carrier_distributors)) {
            // No distributors currently carry this UPC.
            // Mark stock as 0 but leave price unchanged.
            self::log(sprintf(
                'sync_single_product: UPC %s (product %d) not found in any distributors; marking out of stock.',
                $upc,
                $product_id
            ));

            $t_stock = microtime(true);
            $product->set_manage_stock(true);
            $product->set_stock_quantity(0);
            $product->set_stock_status('outofstock');
            $product->save();
            $stock_ms = round((microtime(true) - $t_stock) * 1000, 2);
            self::log('sync_single_product: saving out-of-stock product took ' . $stock_ms . ' ms');

            update_post_meta(
                $product_id,
                ProductMeta::FFLHUB_LAST_SYNC_META,
                current_time('mysql')
            );

            $elapsed_ms = round((microtime(true) - $start) * 1000, 2);
            self::log(sprintf(
                'sync_single_product: product %d saved as out of stock; END elapsed=%s ms.',
                $product_id,
                $elapsed_ms
            ));

            return;
        }

        // Choose selected distributor/product: cheapest in stock if possible, else cheapest overall.
        $selected = null;

        if ($cheapest_in_stock !== null) {
            self::log(sprintf(
                'sync_single_product: UPC %s has cheapest_in_stock from %s true_cost=%s qty=%s',
                $upc,
                $cheapest_in_stock['id'],
                var_export($cheapest_in_stock['true_cost'], true),
                var_export($cheapest_in_stock['quantity'], true)
            ));
            $selected = $cheapest_in_stock;
        } elseif ($cheapest_any !== null) {
            self::log(sprintf(
                'sync_single_product: UPC %s has no in-stock carriers, using cheapest_any from %s true_cost=%s qty=%s',
                $upc,
                $cheapest_any['id'],
                var_export($cheapest_any['true_cost'], true),
                var_export($cheapest_any['quantity'], true)
            ));
            $selected = $cheapest_any;
        } else {
            // Fallback: just pick the first carrier.
            $first_key = array_key_first($carrier_distributors);
            $first     = $carrier_distributors[$first_key] ?? null;

            if ($first && isset($first['payload']) && $first['payload'] instanceof DistributorProductPayload) {
                self::log(sprintf(
                    'sync_single_product: UPC %s has carriers but no valid true_cost, using first carrier %s.',
                    $upc,
                    $first_key
                ));

                $selected = array(
                    'product'   => $first['payload'],
                    'true_cost' => isset($first['true_cost']) ? (float) $first['true_cost'] : null,
                    'label'     => $first['label'],
                    'id'        => $first_key,
                    'quantity'  => $first['quantity'],
                );
            }
        }

        if ($selected === null) {
            self::log(sprintf(
                'sync_single_product: UPC %s (product %d) has carriers but no valid true_cost; skipping price update.',
                $upc,
                $product_id
            ));

            // Still update last_sync timestamp.
            update_post_meta(
                $product_id,
                ProductMeta::FFLHUB_LAST_SYNC_META,
                current_time('mysql')
            );

            $elapsed_ms = round((microtime(true) - $start) * 1000, 2);
            self::log('sync_single_product: END (no selected distributor) elapsed=' . $elapsed_ms . ' ms');
            return;
        }

        /** @var DistributorProductPayload $selected_payload */
        $selected_payload = $selected['product'];
        $selected_dist_id = (string) $selected['id'];

        self::log(sprintf(
            'sync_single_product: selected distributor=%s label=%s',
            $selected_dist_id,
            $selected['label']
        ));

        // Aggregate MAP/MSRP and total quantity across all carriers.
        $t_aggr     = microtime(true);
        $aggregates = self::aggregate_carrier_metrics($carrier_distributors);
        $aggr_ms    = round((microtime(true) - $t_aggr) * 1000, 2);

        $max_map  = $aggregates['max_map'];
        $max_msrp = $aggregates['max_msrp'];
        $qty_sum  = $aggregates['qty_sum'];

        self::log(sprintf(
            'sync_single_product: aggregates for UPC %s: max_map=%s max_msrp=%s qty_sum=%d (took %s ms)',
            $upc,
            var_export($max_map, true),
            var_export($max_msrp, true),
            (int) $qty_sum,
            $aggr_ms
        ));

        // Extract pricing from selected distributor.
        $selected_array     = get_object_vars($selected_payload);
        $selected_true_cost = isset($selected_array['true_cost']) && is_numeric($selected_array['true_cost'])
            ? (float) $selected_array['true_cost']
            : null;
        $selected_dealer    = isset($selected_array['price']) && is_numeric($selected_array['price'])
            ? (float) $selected_array['price']
            : null;

        self::log(sprintf(
            'sync_single_product: selected_true_cost=%s selected_dealer=%s',
            var_export($selected_true_cost, true),
            var_export($selected_dealer, true)
        ));

        // Compute markup settings for this product.
        $t_markup        = microtime(true);
        $markup_settings = self::get_markup_settings_for_product($product_id);
        $markup_ms       = round((microtime(true) - $t_markup) * 1000, 2);

        $markup_mode    = $markup_settings['mode'];        // 1 = auto, 0 = manual
        $markup_percent = $markup_settings['effective'];   // decimal, e.g. 0.25 for 25%

        self::log(sprintf(
            'sync_single_product: markup settings product=%d mode=%d raw=%s global=%s effective=%s (took %s ms)',
            $product_id,
            $markup_mode,
            var_export($markup_settings['raw'], true),
            var_export($markup_settings['global'], true),
            var_export($markup_percent, true),
            $markup_ms
        ));

        // Compute recommended price.
        $t_price           = microtime(true);
        $recommended_price = self::compute_recommended_price(
            $selected_true_cost,
            $selected_dealer,
            $max_map,
            $markup_percent
        );
        $price_ms = round((microtime(true) - $t_price) * 1000, 2);

        self::log(sprintf(
            'sync_single_product: computed recommended_price=%s in %s ms',
            var_export($recommended_price, true),
            $price_ms
        ));

        // Update stock from aggregated quantity.
        $product->set_manage_stock(true);
        $product->set_stock_quantity((int) $qty_sum);
        $product->set_stock_status($qty_sum > 0 ? 'instock' : 'outofstock');

        self::log(sprintf(
            'sync_single_product: setting stock_quantity=%d stock_status=%s',
            (int) $qty_sum,
            $qty_sum > 0 ? 'instock' : 'outofstock'
        ));

        // Auto-pricing mode: update Woo regular price if we have a valid recommended price.
        if ($markup_mode === 1 && $recommended_price !== null && $recommended_price > 0) {
            $current_price_raw = $product->get_regular_price();
            $current_price     = is_numeric($current_price_raw) ? (float) $current_price_raw : 0.0;

            self::log(sprintf(
                'sync_single_product: current_price=%s recommended_price=%s',
                var_export($current_price_raw, true),
                var_export($recommended_price, true)
            ));

            if (abs($recommended_price - $current_price) >= 0.01) {
                $product->set_regular_price(wc_format_decimal($recommended_price, 2));
                self::log('sync_single_product: updated regular_price on product ' . $product_id);
            } else {
                self::log('sync_single_product: price change < 0.01, not updating regular_price.');
            }
        } else {
            if ($markup_mode !== 1) {
                self::log('sync_single_product: markup_mode != 1 (manual), not updating regular_price.');
            } elseif ($recommended_price === null || $recommended_price <= 0) {
                self::log('sync_single_product: no valid recommended_price, not updating regular_price.');
            }
        }

        // Save core Woo product fields.
        $t_save = microtime(true);
        $product->save();
        $save_ms = round((microtime(true) - $t_save) * 1000, 2);
        self::log('sync_single_product: Woo product saved for product_id=' . $product_id . ' in ' . $save_ms . ' ms');

        // Update FFLHub meta fields.
        $t_meta = microtime(true);

        update_post_meta(
            $product_id,
            ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META,
            $selected_dist_id
        );
        update_post_meta(
            $product_id,
            ProductMeta::FFLHUB_LAST_TRUE_COST_META,
            $selected_true_cost !== null ? $selected_true_cost : ''
        );
        update_post_meta(
            $product_id,
            ProductMeta::FFLHUB_LAST_DEALER_PRICE_META,
            $selected_dealer !== null ? $selected_dealer : ''
        );
        update_post_meta(
            $product_id,
            ProductMeta::FFLHUB_LAST_MAP_META,
            $max_map !== null ? $max_map : ''
        );
        update_post_meta(
            $product_id,
            ProductMeta::FFLHUB_LAST_MSRP_META,
            $max_msrp !== null ? $max_msrp : ''
        );
        update_post_meta(
            $product_id,
            ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META,
            $recommended_price !== null ? $recommended_price : ''
        );
        update_post_meta(
            $product_id,
            ProductMeta::FFLHUB_LAST_SYNC_META,
            current_time('mysql')
        );

        $meta_ms = round((microtime(true) - $t_meta) * 1000, 2);
        self::log(sprintf(
            'sync_single_product: meta updated for product %d in %s ms.',
            $product_id,
            $meta_ms
        ));

        $elapsed_ms = round((microtime(true) - $start) * 1000, 2);
        self::log(sprintf(
            'sync_single_product: END product %d elapsed=%s ms.',
            $product_id,
            $elapsed_ms
        ));
    }

    private static function get_distributor_payloads_for_upc(string $upc): array
    {
        $start = microtime(true);
        self::log('get_distributor_payloads_for_upc: START upc=' . $upc);

        $carriers          = array();
        $cheapest_in_stock = null;
        $cheapest_any      = null;

        if (! class_exists(Plugin::class)) {
            self::log('get_distributor_payloads_for_upc: Plugin class missing.');
            return array(
                'carriers'          => $carriers,
                'cheapest_in_stock' => $cheapest_in_stock,
                'cheapest_any'      => $cheapest_any,
            );
        }

        $plugin = Plugin::instance();

        // Get all configured distributors (RSR, Lipsey’s, etc.).
        $distributors = method_exists($plugin, 'get_distributors')
            ? $plugin->get_distributors()
            : array(
                'rsr'     => $plugin->get_distributor_by_id('rsr'),
                'lipseys' => $plugin->get_distributor_by_id('lipseys'),
            );

        self::log('get_distributor_payloads_for_upc: checking distributors=' . implode(',', array_keys($distributors)));

        foreach ($distributors as $id => $distributor) {
            if (! $distributor) {
                self::log(sprintf(
                    'get_distributor_payloads_for_upc: distributor %s is null, skipping.',
                    (string) $id
                ));
                continue;
            }

            self::log(sprintf(
                'get_distributor_payloads_for_upc: querying distributor %s for UPC %s',
                (string) $id,
                $upc
            ));

            $d_start = microtime(true);

            try {
                // Prefer a lightweight pricing method if the distributor provides it.
                if (method_exists($distributor, 'get_pricing_payload_by_upc')) {
                    $product = $distributor->get_pricing_payload_by_upc($upc);
                } else {
                    $product = $distributor->get_product_by_upc($upc);
                }
            } catch (\Throwable $e) {
                $d_ms = round((microtime(true) - $d_start) * 1000, 2);
                self::log(sprintf(
                    'get_distributor_payloads_for_upc: exception from distributor %s for UPC %s after %s ms: %s',
                    (string) $id,
                    $upc,
                    $d_ms,
                    $e->getMessage()
                ));
                continue;
            }

            $d_ms = round((microtime(true) - $d_start) * 1000, 2);

            if (! ($product instanceof DistributorProductPayload)) {
                self::log(sprintf(
                    'get_distributor_payloads_for_upc: distributor %s returned no payload for UPC %s (took %s ms).',
                    (string) $id,
                    $upc,
                    $d_ms
                ));
                continue;
            }

            $label = method_exists($distributor, 'get_label')
                ? $distributor->get_label()
                : ucfirst((string) $id);

            $payload_array = get_object_vars($product);

            $true_cost = null;
            if (isset($payload_array['true_cost']) && is_numeric($payload_array['true_cost'])) {
                $true_cost = (float) $payload_array['true_cost'];
            }

            $quantity = null;
            if (isset($payload_array['quantity']) && is_numeric($payload_array['quantity'])) {
                $quantity = (int) $payload_array['quantity'];
            }

            self::log(sprintf(
                'get_distributor_payloads_for_upc: distributor %s label=%s true_cost=%s qty=%s (took %s ms)',
                (string) $id,
                $label,
                var_export($true_cost, true),
                var_export($quantity, true),
                $d_ms
            ));

            $carriers[(string) $id] = array(
                'label'     => $label,
                'payload'   => $product,
                'true_cost' => $true_cost,
                'quantity'  => $quantity,
            );

            // Cheapest overall (any quantity) if true_cost is valid.
            if ($true_cost !== null) {
                if ($cheapest_any === null || $true_cost < $cheapest_any['true_cost']) {
                    $cheapest_any = array(
                        'product'   => $product,
                        'true_cost' => $true_cost,
                        'label'     => $label,
                        'id'        => (string) $id,
                        'quantity'  => $quantity,
                    );
                    self::log(sprintf(
                        'get_distributor_payloads_for_upc: updated cheapest_any to %s with true_cost=%s',
                        (string) $id,
                        var_export($true_cost, true)
                    ));
                }
            }

            // Cheapest *in stock* (quantity > 0 and valid true_cost).
            if ($true_cost !== null && $quantity !== null && $quantity > 0) {
                if ($cheapest_in_stock === null || $true_cost < $cheapest_in_stock['true_cost']) {
                    $cheapest_in_stock = array(
                        'product'   => $product,
                        'true_cost' => $true_cost,
                        'label'     => $label,
                        'id'        => (string) $id,
                        'quantity'  => $quantity,
                    );
                    self::log(sprintf(
                        'get_distributor_payloads_for_upc: updated cheapest_in_stock to %s with true_cost=%s qty=%d',
                        (string) $id,
                        var_export($true_cost, true),
                        (int) $quantity
                    ));
                }
            }
        }

        $elapsed_ms = round((microtime(true) - $start) * 1000, 2);
        self::log(sprintf(
            'get_distributor_payloads_for_upc: END upc=%s carriers=%d elapsed=%s ms',
            $upc,
            count($carriers),
            $elapsed_ms
        ));

        return array(
            'carriers'          => $carriers,
            'cheapest_in_stock' => $cheapest_in_stock,
            'cheapest_any'      => $cheapest_any,
        );
    }

    /**
     * Aggregate MAP/MSRP and total quantity across all carriers.
     *
     * @param array<string,array{label:string,payload:DistributorProductPayload,true_cost:?float,quantity:?int}> $carriers
     *
     * @return array{max_map:?float,max_msrp:?float,qty_sum:int}
     */
    private static function aggregate_carrier_metrics(array $carriers): array
    {
        $start = microtime(true);
        self::log('aggregate_carrier_metrics: START carriers=' . count($carriers));

        $max_map  = null;
        $max_msrp = null;
        $qty_sum  = 0;

        foreach ($carriers as $id => $info) {
            $payload_array = get_object_vars($info['payload']);

            if (isset($payload_array['map']) && is_numeric($payload_array['map'])) {
                $val = (float) $payload_array['map'];
                if ($max_map === null || $val > $max_map) {
                    $max_map = $val;
                }
            }

            if (isset($payload_array['msrp']) && is_numeric($payload_array['msrp'])) {
                $val = (float) $payload_array['msrp'];
                if ($max_msrp === null || $val > $max_msrp) {
                    $max_msrp = $val;
                }
            }

            if (isset($info['quantity']) && is_numeric($info['quantity'])) {
                $qty_sum += (int) $info['quantity'];
            }
        }

        $elapsed_ms = round((microtime(true) - $start) * 1000, 2);
        self::log(sprintf(
            'aggregate_carrier_metrics: END max_map=%s max_msrp=%s qty_sum=%d elapsed=%s ms',
            var_export($max_map, true),
            var_export($max_msrp, true),
            (int) $qty_sum,
            $elapsed_ms
        ));

        return array(
            'max_map'  => $max_map,
            'max_msrp' => $max_msrp,
            'qty_sum'  => $qty_sum,
        );
    }

    /**
     * Get markup mode and effective markup percent for a product.
     *
     * Mode:
     *   1 = auto pricing (update Woo regular price)
     *   0 = manual pricing (do not touch Woo regular price)
     *
     * Effective:
     *   Decimal percentage, e.g. 0.25 = 25%.
     *
     * @param int $product_id
     *
     * @return array{mode:int, raw:?float, global:float, effective:float}
     */
    private static function get_markup_settings_for_product(int $product_id): array
    {
        // Mode meta: 1=auto,0=manual, default auto.
        $mode_raw = get_post_meta($product_id, ProductMeta::FFLHUB_MARKUP_MODE_META, true);
        $mode     = ($mode_raw === '') ? 1 : (int) $mode_raw;

        // Product-specific markup percent.
        $raw_percent_meta = get_post_meta($product_id, ProductMeta::FFLHUB_MARKUP_PERCENT_META, true);
        $raw_percent      = null;

        if (is_numeric($raw_percent_meta)) {
            $p = (float) $raw_percent_meta;
            if ($p > 0) {
                if ($p > 1) {
                    $p = $p / 100.0;
                }
                $raw_percent = max(0.0, $p);
            }
        }

        // Global markup option.
        $global = self::get_global_markup_percent();

        // Effective percent = product-specific if present, else global.
        $effective = ($raw_percent !== null) ? $raw_percent : $global;

        return array(
            'mode'      => $mode,
            'raw'       => $raw_percent,
            'global'    => $global,
            'effective' => $effective,
        );
    }

    /**
     * Global markup percent as a decimal.
     *
     * Reads the same option you used on the admin page: fflhub_global_markup.
     *
     * Example:
     *   '25' => 0.25
     *   '0.25' => 0.25
     *
     * @return float
     */
    private static function get_global_markup_percent(): float
    {
        $raw = get_option('fflhub_global_markup', '25'); // default to 25%

        if (is_numeric($raw)) {
            $percent = (float) $raw;
            if ($percent > 1) {
                $percent = $percent / 100.0;
            }
            $percent = max(0.0, $percent);

            self::log(sprintf(
                'get_global_markup_percent: raw=%s computed=%s',
                var_export($raw, true),
                var_export($percent, true)
            ));

            return $percent;
        }

        self::log(sprintf(
            'get_global_markup_percent: non-numeric raw=%s, using fallback 0.25',
            var_export($raw, true)
        ));

        return 0.25; // fallback
    }

    /**
     * Compute recommended retail price from distributor data and markup rules.
     *
     * @param float|null $true_cost      "True cost" (dealer + shipping) from selected distributor.
     * @param float|null $dealer_price   Dealer price from selected distributor.
     * @param float|null $max_map        Maximum MAP across all carriers.
     * @param float      $markup_percent Decimal percent, e.g. 0.25 for 25%.
     *
     * @return float|null Recommended price, or null if it can't be computed.
     */
    private static function compute_recommended_price(
        ?float $true_cost,
        ?float $dealer_price,
        ?float $max_map,
        float $markup_percent
    ): ?float {
        self::log(sprintf(
            'compute_recommended_price: true_cost=%s dealer_price=%s max_map=%s markup_percent=%s',
            var_export($true_cost, true),
            var_export($dealer_price, true),
            var_export($max_map, true),
            var_export($markup_percent, true)
        ));

        $base = null;

        if ($true_cost !== null && $true_cost > 0) {
            $base = $true_cost * (1 + $markup_percent);
            self::log(sprintf(
                'compute_recommended_price: using true_cost base=%s',
                var_export($base, true)
            ));
        } elseif ($dealer_price !== null && $dealer_price > 0) {
            $base = $dealer_price * (1 + $markup_percent);
            self::log(sprintf(
                'compute_recommended_price: using dealer_price base=%s',
                var_export($base, true)
            ));
        }

        if ($base === null || $base <= 0) {
            self::log('compute_recommended_price: no valid base, returning null.');
            return null;
        }

        $recommended = $base;

        // Enforce MAP floor.
        if ($max_map !== null && $max_map > $recommended) {
            self::log(sprintf(
                'compute_recommended_price: enforcing MAP floor, old recommended=%s new=%s',
                var_export($recommended, true),
                var_export($max_map, true)
            ));
            $recommended = $max_map;
        }

        // Round to 2 decimals.
        $recommended = round($recommended, 2);

        self::log(sprintf(
            'compute_recommended_price: final recommended=%s',
            var_export($recommended, true)
        ));

        return ($recommended > 0) ? $recommended : null;
    }

    /**
     * Simple internal logger.
     *
     * Flip the `false` to `true` to enable logging for this class.
     *
     * @param string $message
     */
    private static function log(string $message): void
    {
        if (false) {
            error_log('[FFLHub][Product Sync] ' . $message);
        }
    }
}
