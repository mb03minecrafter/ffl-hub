<?php

namespace FFLHub\Distributor\Product;

use FFLHub\Plugin;
use WP_Query;
use WC_Product;
use FFLHub\Distributor\Product\DistributorProductPayload;
use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Product\ProductMeta;

use function class_exists;
use function current_time;
use function get_option;
use function get_post_meta;
use function get_post_status;
use function update_post_meta;
use function wc_get_product;
use function wc_format_decimal;
use function wp_json_encode;

if ( ! defined( 'ABSPATH' ) ) {
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
        return __( 'Every 5 minutes (FFLHub Product Sync)', 'ffl-hub' );
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
        $start = microtime( true );
        $this->log( 'run: START' );

        // Adjust limit as needed; keep modest to avoid timeouts.
        $this->sync_batch( 50 );

        $elapsed_ms = round( ( microtime( true ) - $start ) * 1000, 2 );
        $this->log( 'run: END elapsed=' . $elapsed_ms . ' ms' );
    }

    /**
     * Sync a batch of FFLHub-managed products.
     *
     * @param int $limit Number of products to process in this run.
     */
    public function sync_batch( int $limit = 50 ): void
    {
        $start = microtime( true );
        $this->log( 'sync_batch: starting with limit=' . (int) $limit );

        if ( ! class_exists( WC_Product::class ) ) {
            $this->log( 'sync_batch: WooCommerce not loaded; aborting.' );
            return;
        }

        if ( ! class_exists( ProductMeta::class ) ) {
            $this->log( 'sync_batch: ProductMeta missing; aborting.' );
            return;
        }

        $args = array(
            'post_type'      => 'product',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => max( 1, (int) $limit ),
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

        $this->log( 'sync_batch: WP_Query args=' . wp_json_encode( $args ) );

        $q_start = microtime( true );
        $query   = new WP_Query( $args );
        $q_ms    = round( ( microtime( true ) - $q_start ) * 1000, 2 );
        $this->log( 'sync_batch: WP_Query finished in ' . $q_ms . ' ms' );

        if ( ! $query->have_posts() ) {
            $this->log( 'sync_batch: no managed products found.' );
            return;
        }

        $this->log( 'sync_batch: found ' . (int) $query->post_count . ' products to process.' );

        $processed = 0;

        foreach ( $query->posts as $product_id ) {
            $product_id = (int) $product_id;
            if ( $product_id <= 0 ) {
                $this->log( 'sync_batch: skipping invalid product ID: ' . $product_id );
                continue;
            }

            $p_start = microtime( true );
            $this->log( 'sync_batch: syncing product ID ' . $product_id );

            try {
                $this->sync_single_product( $product_id );
                $processed++;
            } catch ( \Throwable $e ) {
                $this->log(
                    sprintf(
                        'sync_batch: exception syncing product %d: %s',
                        $product_id,
                        $e->getMessage()
                    )
                );
                continue;
            }

            $p_ms = round( ( microtime( true ) - $p_start ) * 1000, 2 );
            $this->log( 'sync_batch: finished product ' . $product_id . ' in ' . $p_ms . ' ms' );
        }

        $elapsed_ms = round( ( microtime( true ) - $start ) * 1000, 2 );
        $this->log(
            sprintf(
                'sync_batch: processed %d managed products in %s ms.',
                $processed,
                $elapsed_ms
            )
        );
    }

    /**
     * Sync pricing and quantity for a single FFLHub-managed WooCommerce product.
     *
     * @param int $product_id
     */
    public function sync_single_product( int $product_id ): void
    {
        $start = microtime( true );
        $this->log( 'sync_single_product: START product_id=' . $product_id );

        if ( $product_id <= 0 ) {
            $this->log( 'sync_single_product: invalid product_id, aborting.' );
            return;
        }

        if ( ! function_exists( 'wc_get_product' ) ) {
            $this->log( 'sync_single_product: wc_get_product not available, aborting.' );
            return;
        }

        $t_load_product = microtime( true );
        $product        = wc_get_product( $product_id );
        $t_load_ms      = round( ( microtime( true ) - $t_load_product ) * 1000, 2 );
        $this->log( 'sync_single_product: wc_get_product took ' . $t_load_ms . ' ms' );

        if ( ! $product instanceof WC_Product ) {
            $this->log(
                sprintf(
                    'sync_single_product: product %d not found or not a WC_Product.',
                    $product_id
                )
            );
            return;
        }

        // Skip trashed products.
        $post_status = get_post_status( $product_id );
        $this->log( 'sync_single_product: product ' . $product_id . ' post_status=' . $post_status );

        if ( 'trash' === $post_status ) {
            $this->log(
                sprintf(
                    'sync_single_product: product %d is trashed; skipping.',
                    $product_id
                )
            );
            return;
        }

        // Ensure this product is managed by FFLHub.
        $managed_raw = get_post_meta( $product_id, ProductMeta::FFLHUB_MANAGED_META, true );
        $managed     = (int) $managed_raw === 1 || $managed_raw === '1' || $managed_raw === true;

        $this->log(
            sprintf(
                'sync_single_product: product %d managed_raw=%s managed=%s',
                $product_id,
                var_export( $managed_raw, true ),
                $managed ? 'true' : 'false'
            )
        );

        if ( ! $managed ) {
            $this->log(
                sprintf(
                    'sync_single_product: product %d is not FFLHub-managed; skipping.',
                    $product_id
                )
            );
            return;
        }

        // UPC is our key into distributor data.
        $upc = (string) get_post_meta( $product_id, ProductMeta::FFLHUB_UPC_META, true );
        $upc = trim( $upc );

        $this->log(
            sprintf(
                'sync_single_product: product %d has UPC="%s"',
                $product_id,
                $upc
            )
        );

        if ( $upc === '' ) {
            $this->log(
                sprintf(
                    'sync_single_product: product %d has no UPC meta; skipping.',
                    $product_id
                )
            );
            return;
        }

        // Get all distributor payloads for this UPC.
        $t_payloads = microtime( true );
        $lookup     = $this->get_distributor_payloads_for_upc( $upc );
        $payload_ms = round( ( microtime( true ) - $t_payloads ) * 1000, 2 );
        $this->log(
            'sync_single_product: get_distributor_payloads_for_upc took ' .
            $payload_ms .
            ' ms for UPC ' .
            $upc
        );

        $carrier_distributors = $lookup['carriers'];
        $cheapest_in_stock    = $lookup['cheapest_in_stock'];
        $cheapest_any         = $lookup['cheapest_any'];

        $this->log(
            sprintf(
                'sync_single_product: UPC %s has %d carrier(s).',
                $upc,
                count( $carrier_distributors )
            )
        );

        if ( empty( $carrier_distributors ) ) {
            // No distributors currently carry this UPC.
            // Mark stock as 0 but leave price unchanged.
            $this->log(
                sprintf(
                    'sync_single_product: UPC %s (product %d) not found in any distributors; marking out of stock.',
                    $upc,
                    $product_id
                )
            );

            $t_stock = microtime( true );
            $product->set_manage_stock( true );
            $product->set_stock_quantity( 0 );
            $product->set_stock_status( 'outofstock' );
            $product->save();
            $stock_ms = round( ( microtime( true ) - $t_stock ) * 1000, 2 );
            $this->log(
                'sync_single_product: saving out-of-stock product took ' .
                $stock_ms .
                ' ms'
            );

            update_post_meta(
                $product_id,
                ProductMeta::FFLHUB_LAST_SYNC_META,
                current_time( 'mysql' )
            );

            $elapsed_ms = round( ( microtime( true ) - $start ) * 1000, 2 );
            $this->log(
                sprintf(
                    'sync_single_product: product %d saved as out of stock; END elapsed=%s ms.',
                    $product_id,
                    $elapsed_ms
                )
            );

            return;
        }

        // Choose selected distributor/product: cheapest in stock if possible, else cheapest overall.
        $selected = null;

        if ( $cheapest_in_stock !== null ) {
            $this->log(
                sprintf(
                    'sync_single_product: UPC %s has cheapest_in_stock from %s true_cost=%s qty=%s',
                    $upc,
                    $cheapest_in_stock['id'],
                    var_export( $cheapest_in_stock['true_cost'], true ),
                    var_export( $cheapest_in_stock['quantity'], true )
                )
            );
            $selected = $cheapest_in_stock;
        } elseif ( $cheapest_any !== null ) {
            $this->log(
                sprintf(
                    'sync_single_product: UPC %s has no in-stock carriers, using cheapest_any from %s true_cost=%s qty=%s',
                    $upc,
                    $cheapest_any['id'],
                    var_export( $cheapest_any['true_cost'], true ),
                    var_export( $cheapest_any['quantity'], true )
                )
            );
            $selected = $cheapest_any;
        } else {
            // Fallback: just pick the first carrier.
            $first_key = array_key_first( $carrier_distributors );
            $first     = $carrier_distributors[ $first_key ] ?? null;

            if (
                $first &&
                isset( $first['payload'] ) &&
                $first['payload'] instanceof DistributorProductPayload
            ) {
                $this->log(
                    sprintf(
                        'sync_single_product: UPC %s has carriers but no valid true_cost, using first carrier %s.',
                        $upc,
                        $first_key
                    )
                );

                $selected = array(
                    'product'   => $first['payload'],
                    'true_cost' => isset( $first['true_cost'] ) ? (float) $first['true_cost'] : null,
                    'label'     => $first['label'],
                    'id'        => $first_key,
                    'quantity'  => $first['quantity'],
                );
            }
        }

        if ( $selected === null ) {
            $this->log(
                sprintf(
                    'sync_single_product: UPC %s (product %d) has carriers but no valid true_cost; skipping price update.',
                    $upc,
                    $product_id
                )
            );

            // Still update last_sync timestamp.
            update_post_meta(
                $product_id,
                ProductMeta::FFLHUB_LAST_SYNC_META,
                current_time( 'mysql' )
            );

            $elapsed_ms = round( ( microtime( true ) - $start ) * 1000, 2 );
            $this->log(
                'sync_single_product: END (no selected distributor) elapsed=' .
                $elapsed_ms .
                ' ms'
            );
            return;
        }

        /** @var DistributorProductPayload $selected_payload */
        $selected_payload = $selected['product'];
        $selected_dist_id = (string) $selected['id'];

        $this->log(
            sprintf(
                'sync_single_product: selected distributor=%s label=%s',
                $selected_dist_id,
                $selected['label']
            )
        );

        // Aggregate MAP/MSRP and total quantity across all carriers.
        $t_aggr     = microtime( true );
        $aggregates = $this->aggregate_carrier_metrics( $carrier_distributors );
        $aggr_ms    = round( ( microtime( true ) - $t_aggr ) * 1000, 2 );

        $max_map  = $aggregates['max_map'];
        $max_msrp = $aggregates['max_msrp'];
        $qty_sum  = $aggregates['qty_sum'];

        $this->log(
            sprintf(
                'sync_single_product: aggregates for UPC %s: max_map=%s max_msrp=%s qty_sum=%d (took %s ms)',
                $upc,
                var_export( $max_map, true ),
                var_export( $max_msrp, true ),
                (int) $qty_sum,
                $aggr_ms
            )
        );

        // Extract pricing from selected distributor.
        $selected_array     = get_object_vars( $selected_payload );
        $selected_true_cost = isset( $selected_array['true_cost'] ) && is_numeric( $selected_array['true_cost'] )
            ? (float) $selected_array['true_cost']
            : null;
        $selected_dealer    = isset( $selected_array['price'] ) && is_numeric( $selected_array['price'] )
            ? (float) $selected_array['price']
            : null;

        $this->log(
            sprintf(
                'sync_single_product: selected_true_cost=%s selected_dealer=%s',
                var_export( $selected_true_cost, true ),
                var_export( $selected_dealer, true )
            )
        );

        // Compute markup settings for this product.
        $t_markup        = microtime( true );
        $markup_settings = $this->get_markup_settings_for_product( $product_id );
        $markup_ms       = round( ( microtime( true ) - $t_markup ) * 1000, 2 );

        $markup_mode    = $markup_settings['mode'];      // 1 = auto, 0 = manual
        $markup_percent = $markup_settings['effective']; // decimal, e.g. 0.25 for 25%

        $this->log(
            sprintf(
                'sync_single_product: markup settings product=%d mode=%d raw=%s global=%s effective=%s (took %s ms)',
                $product_id,
                $markup_mode,
                var_export( $markup_settings['raw'], true ),
                var_export( $markup_settings['global'], true ),
                var_export( $markup_percent, true ),
                $markup_ms
            )
        );

        // Compute recommended price.
        $t_price           = microtime( true );
        $recommended_price = $this->compute_recommended_price(
            $selected_true_cost,
            $selected_dealer,
            $max_map,
            $markup_percent
        );
        $price_ms = round( ( microtime( true ) - $t_price ) * 1000, 2 );

        $this->log(
            sprintf(
                'sync_single_product: computed recommended_price=%s in %s ms',
                var_export( $recommended_price, true ),
                $price_ms
            )
        );

        // Update stock from aggregated quantity.
        $product->set_manage_stock( true );
        $product->set_stock_quantity( (int) $qty_sum );
        $product->set_stock_status( $qty_sum > 0 ? 'instock' : 'outofstock' );

        $this->log(
            sprintf(
                'sync_single_product: setting stock_quantity=%d stock_status=%s',
                (int) $qty_sum,
                $qty_sum > 0 ? 'instock' : 'outofstock'
            )
        );

        // Auto-pricing mode: update Woo regular price if we have a valid recommended price.
        if ( $markup_mode === 1 && $recommended_price !== null && $recommended_price > 0 ) {
            $current_price_raw = $product->get_regular_price();
            $current_price     = is_numeric( $current_price_raw ) ? (float) $current_price_raw : 0.0;

            $this->log(
                sprintf(
                    'sync_single_product: current_price=%s recommended_price=%s',
                    var_export( $current_price_raw, true ),
                    var_export( $recommended_price, true )
                )
            );

            if ( abs( $recommended_price - $current_price ) >= 0.01 ) {
                $product->set_regular_price( wc_format_decimal( $recommended_price, 2 ) );
                $this->log(
                    'sync_single_product: updated regular_price on product ' .
                    $product_id
                );
            } else {
                $this->log(
                    'sync_single_product: price change < 0.01, not updating regular_price.'
                );
            }
        } else {
            if ( $markup_mode !== 1 ) {
                $this->log(
                    'sync_single_product: markup_mode != 1 (manual), not updating regular_price.'
                );
            } elseif ( $recommended_price === null || $recommended_price <= 0 ) {
                $this->log(
                    'sync_single_product: no valid recommended_price, not updating regular_price.'
                );
            }
        }

        // Save core Woo product fields.
        $t_save = microtime( true );
        $product->save();
        $save_ms = round( ( microtime( true ) - $t_save ) * 1000, 2 );
        $this->log(
            'sync_single_product: Woo product saved for product_id=' .
            $product_id .
            ' in ' .
            $save_ms .
            ' ms'
        );

        // Update FFLHub meta fields.
        $t_meta = microtime( true );

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
            current_time( 'mysql' )
        );

        $meta_ms = round( ( microtime( true ) - $t_meta ) * 1000, 2 );
        $this->log(
            sprintf(
                'sync_single_product: meta updated for product %d in %s ms.',
                $product_id,
                $meta_ms
            )
        );

        $elapsed_ms = round( ( microtime( true ) - $start ) * 1000, 2 );
        $this->log(
            sprintf(
                'sync_single_product: END product %d elapsed=%s ms.',
                $product_id,
                $elapsed_ms
            )
        );
    }

    /**
     * Delegate UPC lookup to the DistributorHandler helper.
     *
     * @param string $upc
     * @return array{
     *   carriers: array<string,array{label:string,payload:DistributorProductPayload,true_cost:?float,quantity:?int}>,
     *   cheapest_in_stock: ?array{product:DistributorProductPayload,true_cost:float,label:string,id:string,quantity:?int},
     *   cheapest_any: ?array{product:DistributorProductPayload,true_cost:float,label:string,id:string,quantity:?int}
     * }
     */
    private function get_distributor_payloads_for_upc( string $upc ): array
    {
        $start = microtime( true );
        $this->log( 'get_distributor_payloads_for_upc: START upc=' . $upc );

        $empty = array(
            'carriers'          => array(),
            'cheapest_in_stock' => null,
            'cheapest_any'      => null,
        );

        if ( ! class_exists( Plugin::class ) ) {
            $this->log( 'get_distributor_payloads_for_upc: Plugin class missing.' );
            return $empty;
        }

        $plugin  = Plugin::instance();
        $handler = $plugin->distributor_handler ?? null;

        if ( ! $handler || ! method_exists( $handler, 'get_payloads_for_upc' ) ) {
            $this->log(
                'get_distributor_payloads_for_upc: distributor_handler missing or does not implement get_payloads_for_upc.'
            );
            return $empty;
        }

        try {
            $lookup = $handler->get_payloads_for_upc( $upc );
        } catch ( \Throwable $e ) {
            $this->log(
                sprintf(
                    'get_distributor_payloads_for_upc: exception while delegating to handler for UPC %s: %s',
                    $upc,
                    $e->getMessage()
                )
            );
            return $empty;
        }

        if ( ! is_array( $lookup ) ) {
            $this->log(
                'get_distributor_payloads_for_upc: handler returned non-array; using empty result.'
            );
            return $empty;
        }

        $carriers = isset( $lookup['carriers'] ) && is_array( $lookup['carriers'] )
            ? $lookup['carriers']
            : array();

        $elapsed_ms = round( ( microtime( true ) - $start ) * 1000, 2 );
        $this->log(
            sprintf(
                'get_distributor_payloads_for_upc: END upc=%s carriers=%d elapsed=%s ms',
                $upc,
                count( $carriers ),
                $elapsed_ms
            )
        );

        return array(
            'carriers'          => $carriers,
            'cheapest_in_stock' => $lookup['cheapest_in_stock'] ?? null,
            'cheapest_any'      => $lookup['cheapest_any'] ?? null,
        );
    }

    /**
     * Aggregate MAP/MSRP and total quantity across all carriers.
     *
     * @param array<string,array{label:string,payload:DistributorProductPayload,true_cost:?float,quantity:?int}> $carriers
     *
     * @return array{max_map:?float,max_msrp:?float,qty_sum:int}
     */
    private function aggregate_carrier_metrics( array $carriers ): array
    {
        $start = microtime( true );
        $this->log(
            'aggregate_carrier_metrics: START carriers=' .
            count( $carriers )
        );

        $max_map  = null;
        $max_msrp = null;
        $qty_sum  = 0;

        foreach ( $carriers as $id => $info ) {
            $payload_array = get_object_vars( $info['payload'] );

            if ( isset( $payload_array['map'] ) && is_numeric( $payload_array['map'] ) ) {
                $val = (float) $payload_array['map'];
                if ( $max_map === null || $val > $max_map ) {
                    $max_map = $val;
                }
            }

            if ( isset( $payload_array['msrp'] ) && is_numeric( $payload_array['msrp'] ) ) {
                $val = (float) $payload_array['msrp'];
                if ( $max_msrp === null || $val > $max_msrp ) {
                    $max_msrp = $val;
                }
            }

            if ( isset( $info['quantity'] ) && is_numeric( $info['quantity'] ) ) {
                $qty_sum += (int) $info['quantity'];
            }
        }

        $elapsed_ms = round( ( microtime( true ) - $start ) * 1000, 2 );
        $this->log(
            sprintf(
                'aggregate_carrier_metrics: END max_map=%s max_msrp=%s qty_sum=%d elapsed=%s ms',
                var_export( $max_map, true ),
                var_export( $max_msrp, true ),
                (int) $qty_sum,
                $elapsed_ms
            )
        );

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
    private function get_markup_settings_for_product( int $product_id ): array
    {
        // Mode meta: 1=auto,0=manual, default auto.
        $mode_raw = get_post_meta( $product_id, ProductMeta::FFLHUB_MARKUP_MODE_META, true );
        $mode     = ( $mode_raw === '' ) ? 1 : (int) $mode_raw;

        // Product-specific markup percent.
        $raw_percent_meta = get_post_meta( $product_id, ProductMeta::FFLHUB_MARKUP_PERCENT_META, true );
        $raw_percent      = null;

        if ( is_numeric( $raw_percent_meta ) ) {
            $p = (float) $raw_percent_meta;
            if ( $p > 0 ) {
                if ( $p > 1 ) {
                    $p = $p / 100.0;
                }
                $raw_percent = max( 0.0, $p );
            }
        }

        // Global markup option.
        $global = $this->get_global_markup_percent();

        // Effective percent = product-specific if present, else global.
        $effective = ( $raw_percent !== null ) ? $raw_percent : $global;

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
    private function get_global_markup_percent(): float
    {
        $raw = get_option( 'fflhub_global_markup', '25' ); // default to 25%

        if ( is_numeric( $raw ) ) {
            $percent = (float) $raw;
            if ( $percent > 1 ) {
                $percent = $percent / 100.0;
            }
            $percent = max( 0.0, $percent );

            $this->log(
                sprintf(
                    'get_global_markup_percent: raw=%s computed=%s',
                    var_export( $raw, true ),
                    var_export( $percent, true )
                )
            );

            return $percent;
        }

        $this->log(
            sprintf(
                'get_global_markup_percent: non-numeric raw=%s, using fallback 0.25',
                var_export( $raw, true )
            )
        );

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
    private function compute_recommended_price(
        ?float $true_cost,
        ?float $dealer_price,
        ?float $max_map,
        float $markup_percent
    ): ?float {
        $this->log(
            sprintf(
                'compute_recommended_price: true_cost=%s dealer_price=%s max_map=%s markup_percent=%s',
                var_export( $true_cost, true ),
                var_export( $dealer_price, true ),
                var_export( $max_map, true ),
                var_export( $markup_percent, true )
            )
        );

        $base = null;

        if ( $true_cost !== null && $true_cost > 0 ) {
            $base = $true_cost * ( 1 + $markup_percent );
            $this->log(
                sprintf(
                    'compute_recommended_price: using true_cost base=%s',
                    var_export( $base, true )
                )
            );
        } elseif ( $dealer_price !== null && $dealer_price > 0 ) {
            $base = $dealer_price * ( 1 + $markup_percent );
            $this->log(
                sprintf(
                    'compute_recommended_price: using dealer_price base=%s',
                    var_export( $base, true )
                )
            );
        }

        if ( $base === null || $base <= 0 ) {
            $this->log( 'compute_recommended_price: no valid base, returning null.' );
            return null;
        }

        $recommended = $base;

        // Enforce MAP floor.
        if ( $max_map !== null && $max_map > $recommended ) {
            $this->log(
                sprintf(
                    'compute_recommended_price: enforcing MAP floor, old recommended=%s new=%s',
                    var_export( $recommended, true ),
                    var_export( $max_map, true )
                )
            );
            $recommended = $max_map;
        }

        // Round to 2 decimals.
        $recommended = round( $recommended, 2 );

        $this->log(
            sprintf(
                'compute_recommended_price: final recommended=%s',
                var_export( $recommended, true )
            )
        );

        return ( $recommended > 0 ) ? $recommended : null;
    }

    /**
     * Simple internal logger.
     *
     * Flip the `false` to `true` to enable logging for this class.
     *
     * @param string $message
     */
    private function log( string $message ): void
    {
        if ( false ) {
            error_log( '[FFLHub][Product Sync] ' . $message );
        }
    }
}
