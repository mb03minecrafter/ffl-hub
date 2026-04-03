<?php

namespace FFLHub\Distributor\Services\Orders;

use WC_Order;
use WC_Product;
use WC_Order_Item_Product;

use FFLHub\Product\ProductMeta;
use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Orders\Jobs\Lifecycle\OrderPlacementJobLifeCycle;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementPipelineMetaStore;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementProductUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Routing\DealerFulfillmentRoutingPlanner;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderingOrchestratorService
 *
 * Responsibility:
 * - Orchestrate the *start* of the Order Placement pipeline when payment is complete.
 *
 * Trigger model:
 * - Uses WooCommerce `woocommerce_payment_complete` (order is paid).
 *
 * What this class does:
 * - On payment complete: fetch WC_Order, verify paid.
 * - Build per-(dist_id x lane) job definitions from the order's FFLHub-managed line items.
 * - Persist durable job rows to the Order Placement Jobs table (upsert/init).
 * - Mark those rows eligible for processing by setting:
 *   - status = scheduled
 *   - next_run_at = now (UTC)
 *   - action_id = NULL (no per-job Action Scheduler action)
 *
 * Idempotency / safety:
 * - Atomic postmeta lock prevents concurrent double-start.
 * - Pipeline meta store prevents re-start on later events.
 */
final class OrderingOrchestratorService
{
    private const LOG_PREFIX  = '[FFLHUB][OrderPlacementOrchestrator]';
    private const DEBUG_CONST = 'FFLHUB_PLACE_ORCH_DEBUG';
    private const LOCAL_STOCK_DIST_ID = 'local_stock';
    private const LOCAL_STOCK_RESULT_MESSAGE = 'Pulled from local stock. Distributor placement skipped.';

    private OrderPlacementJobsTable $jobs_table;

    public function __construct(OrderPlacementJobsTable $jobs_table)
    {
        $this->jobs_table = $jobs_table;
    }

    /**
     * Register WooCommerce hooks.
     */
    public function register(): void
    {
        // Canonical trigger: ONLY start ordering after payment is complete.
        add_action('woocommerce_payment_complete', [$this, 'handle_payment_complete'], 10, 1);

        // Manual trigger for testing/debugging via wp eval:
        // do_action('fflhub_ordering_force_start', <order_id>);
        add_action('fflhub_ordering_force_start', [$this, 'handle_manual_force_start'], 10, 1);
    }

    /**
     * WooCommerce hook handler: payment is complete.
     *
     * @param int|string $order_id
     */
    public function handle_payment_complete($order_id): void
    {
        $order_id_i = (int) $order_id;

        $order = wc_get_order($order_id_i);
        if (!($order instanceof WC_Order)) {
            $this->log_ctx('payment_complete_order_not_found', [
                'order_id' => $order_id_i,
            ]);
            return;
        }

        // Safety: should be true for this hook, but keep it.
        if (!$order->is_paid()) {
            $this->log_ctx('payment_complete_not_paid', [
                'order_id' => $order_id_i,
                'status'   => $order->get_status(),
            ]);
            return;
        }

        $this->start_pipeline_if_needed($order, 'payment_complete');
    }

    /**
     * Manual trigger entrypoint (intended for local/VPS testing).
     *
     * @param int|string $order_id
     */
    public function handle_manual_force_start($order_id): void
    {
        $order_id_i = (int) $order_id;
        $order = wc_get_order($order_id_i);
        if (!($order instanceof WC_Order)) {
            $this->log_ctx('manual_force_order_not_found', [
                'order_id' => $order_id_i,
            ]);
            return;
        }

        $this->start_pipeline_if_needed($order, 'manual_force_start');
    }

    /**
     * Single authoritative entry point for starting the placement pipeline.
     *
     * @param WC_Order $order
     * @param string  $trigger
     */
    private function start_pipeline_if_needed(WC_Order $order, string $trigger): void
    {
        $oid = (int) $order->get_id();

        // Atomic "claim" to prevent concurrent double-start.
        $lock_key = '_fflhub_order_place_pipeline_lock';

        // add_post_meta returns false if meta already exists when $unique=true.
        $locked = add_post_meta($oid, $lock_key, (string) time(), true);
        if (!$locked) {
            $this->log_ctx('pipeline_lock_already_claimed', [
                'order_id' => $oid,
                'trigger'  => $trigger,
            ]);
            return;
        }

        // If pipeline already started (durable meta), don't start again.
        if (OrderPlacementPipelineMetaStore::get_pipeline_started($order)) {
            $this->log_ctx('pipeline_already_started', [
                'order_id' => $oid,
                'trigger'  => $trigger,
            ]);
            return;
        }

        // Build jobs from FFLHub-managed products only.
        // If none exist, lane jobs are empty and we early-exit.
        $lane_jobs = $this->build_lane_jobs_from_order($order);
        if (empty($lane_jobs)) {
            $this->log_ctx('no_lane_jobs_found', [
                'order_id' => $oid,
                'trigger'  => $trigger,
            ]);
            return;
        }

        $started_at = gmdate('c');

        // Mark started + persist jobs + mark eligible (DB queue)
        OrderPlacementPipelineMetaStore::set_pipeline_started($order, true, $started_at, $trigger);

        $this->persist_lane_jobs_table($order, $lane_jobs);
        $this->finalize_local_stock_jobs($order, $lane_jobs);
        $this->mark_jobs_eligible_for_processing($order, $lane_jobs);

        try {
            $order->save();
        } catch (\Throwable $e) {
            $this->log_ctx('order_save_exception', [
                'order_id' => $oid,
                'err'      => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ]);
        }
    }

    /**
     * Build per-(dist_id x lane) placement jobs from an order's line items.
     *
     * Lanes:
     * - direct_ship_non_ffl
     * - direct_ship_ffl
     * - dealer_fulfilled (can contain mixed FFL + non-FFL lines)
     *
     * @param WC_Order $order
     * @return array<string, array{
     *   order_id:int,
     *   dist_id:string,
     *   lane:string,
     *   lines:array<int,array{upc:string,qty:int,ffl_required:int,dropship_enabled:int}>
     * }>
     */
    private function build_lane_jobs_from_order(WC_Order $order): array
    {
        $oid = (int) $order->get_id();

        /** @var array<string,array<string,mixed>> $accepted_by_line_id */
        $accepted_by_line_id = [];

        /** @var array<int,array<string,mixed>> $routing_lines */
        $routing_lines = [];

        /** @var array<string,array{upc:string,qty:int,ffl_required:int,dropship_enabled:int,product_id:int,product_name:string}> $local_stock_lines */
        $local_stock_lines = [];

        /** @var array<int,int> $local_available_by_product */
        $local_available_by_product = [];

        /** @var array<int,array{product_id:int,qty:int,product_name:string,upc:string}> $local_stock_decrements */
        $local_stock_decrements = [];

        $seen = [
            'items_iterated'     => 0,
            'items_not_product'  => 0,
            'product_missing'    => 0,
            'not_managed'        => 0,
            'missing_dist'       => 0,
            'missing_upc'        => 0,
            'accepted'           => 0,
            'local_fulfilled'    => 0,
            'local_partial'      => 0,
        ];

        foreach ($order->get_items('line_item') as $item) {
            $seen['items_iterated']++;

            if (!($item instanceof WC_Order_Item_Product)) {
                $seen['items_not_product']++;
                continue;
            }

            $qty = max(1, (int) $item->get_quantity());

            $product = $item->get_product();
            if (!($product instanceof WC_Product)) {
                $pid = (int) $item->get_product_id();
                $vid = (int) $item->get_variation_id();
                $product = $vid > 0 ? wc_get_product($vid) : wc_get_product($pid);
                if (!($product instanceof WC_Product)) {
                    $seen['product_missing']++;
                    continue;
                }
            }

            $managed = (int) $product->get_meta(ProductMeta::FFLHUB_MANAGED_META, true);
            if ($managed !== 1) {
                $seen['not_managed']++;
                continue;
            }

            $ffl_required = ((int) $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true) === 1);
            $dropship_enabled = $this->to_boolish(
                $product->get_meta(ProductMeta::FFLHUB_DROPSHIP_ENABLED_META, true),
                true
            );
            $weight_oz = $this->to_non_negative_float(
                $product->get_meta(ProductMeta::FFLHUB_SHIPPING_WEIGHT_META, true),
                0.0
            );
            $dist_lane_fee = $this->to_non_negative_float(
                $product->get_meta(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, true),
                0.0
            );

            $item_id = (int) $item->get_id();
            $line_id = ($item_id > 0) ? ('oi_' . (string) $item_id) : ('oi_idx_' . (string) $seen['items_iterated']);
            $product_id = (int) $product->get_id();
            $upc = OrderPlacementProductUtil::extract_upc_from_product($product);
            $line_upc = $upc !== '' ? $upc : $this->fallback_local_identifier($product);
            $line_name = trim((string) $product->get_name());

            $line_qty_for_routing = $qty;
            $local_enabled = $this->is_local_stock_override_enabled($product);
            if ($local_enabled) {
                if (!isset($local_available_by_product[$product_id])) {
                    $local_available_by_product[$product_id] = $this->get_local_stock_override_qty($product);
                }

                $available_local_qty = max(0, (int) ($local_available_by_product[$product_id] ?? 0));
                $local_take_qty = min($line_qty_for_routing, $available_local_qty);
                if ($local_take_qty > 0) {
                    $local_line_key = (string) $product_id . '|' . (string) (!empty($ffl_required) ? 1 : 0);
                    if (!isset($local_stock_lines[$local_line_key])) {
                        $local_stock_lines[$local_line_key] = [
                            'upc'              => $line_upc,
                            'qty'              => 0,
                            'ffl_required'     => !empty($ffl_required) ? 1 : 0,
                            'dropship_enabled' => !empty($dropship_enabled) ? 1 : 0,
                            'product_id'       => $product_id,
                            'product_name'     => $line_name,
                        ];
                    }
                    $local_stock_lines[$local_line_key]['qty'] += $local_take_qty;

                    if (!isset($local_stock_decrements[$product_id])) {
                        $local_stock_decrements[$product_id] = [
                            'product_id'   => $product_id,
                            'qty'          => 0,
                            'product_name' => $line_name,
                            'upc'          => $line_upc,
                        ];
                    }
                    $local_stock_decrements[$product_id]['qty'] += $local_take_qty;

                    $local_available_by_product[$product_id] = $available_local_qty - $local_take_qty;
                    $line_qty_for_routing -= $local_take_qty;
                    $seen['local_fulfilled']++;

                    if ($line_qty_for_routing > 0) {
                        $seen['local_partial']++;
                    }
                }
            }

            if ($line_qty_for_routing <= 0) {
                continue;
            }

            $dist_raw = (string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true);
            $dist_id  = OrderPlacementKeysUtil::normalize_dist_id($dist_raw);
            if ($dist_id === '') {
                $seen['missing_dist']++;
                $this->log_ctx('skip_line_missing_dist', [
                    'order_id'   => $oid,
                    'product_id' => $product_id,
                    'dist_raw'   => $dist_raw,
                ]);
                continue;
            }

            if ($upc === '') {
                $seen['missing_upc']++;
                $this->log_ctx('skip_line_missing_upc', [
                    'order_id'   => $oid,
                    'product_id' => $product_id,
                    'dist_id'    => $dist_id,
                ]);
                continue;
            }

            $accepted_by_line_id[$line_id] = [
                'line_id'          => $line_id,
                'product_id'       => $product_id,
                'dist_id'          => (string) $dist_id,
                'upc'              => (string) $upc,
                'qty'              => $line_qty_for_routing,
                'ffl_required'     => $ffl_required,
                'dropship_enabled' => $dropship_enabled,
            ];

            $routing_lines[] = [
                'line_id'          => $line_id,
                'dist_id'          => (string) $dist_id,
                'qty'              => $line_qty_for_routing,
                'weight_oz'        => $weight_oz,
                'ffl_required'     => $ffl_required,
                'dropship_enabled' => $dropship_enabled,
                'dist_lane_fee'    => $dist_lane_fee,
            ];

            $seen['accepted']++;
        }

        /** @var array<string,array<string,array<string,array<string,mixed>>>> $agg */
        $agg = [];
        if (!empty($accepted_by_line_id) && !empty($routing_lines)) {
            $plan = DealerFulfillmentRoutingPlanner::find_cheapest_plan($routing_lines);
            $assignments = (isset($plan['assignments']) && is_array($plan['assignments'])) ? $plan['assignments'] : [];

            $this->log_ctx('lane_plan_computed', [
                'order_id'            => $oid,
                'accepted_lines'      => count($accepted_by_line_id),
                'total_cost'          => (float) ($plan['total_cost'] ?? 0.0),
                'decision_lines'      => (int) ($plan['meta']['decision_lines'] ?? 0),
                'combinations'        => (int) ($plan['meta']['combinations_evaluated'] ?? 0),
                'assignment_count'    => count($assignments),
                'local_fulfilled'     => $seen['local_fulfilled'],
                'local_partial'       => $seen['local_partial'],
            ]);

            foreach ($accepted_by_line_id as $line_id => $row) {
                $route = isset($assignments[$line_id])
                    ? strtolower(trim((string) $assignments[$line_id]))
                    : (!empty($row['dropship_enabled']) ? 'direct_ship' : 'dealer_fulfilled');

                $lane = $this->lane_for_route($route, !empty($row['ffl_required']));
                if (!OrderPlacementKeysUtil::is_valid_lane($lane)) {
                    $this->log_ctx('skip_line_invalid_lane', [
                        'order_id' => $oid,
                        'line_id'  => $line_id,
                        'dist_id'  => (string) ($row['dist_id'] ?? ''),
                        'route'    => $route,
                        'lane'     => $lane,
                    ]);
                    continue;
                }

                $dist_id = (string) ($row['dist_id'] ?? '');
                if ($dist_id === '') {
                    continue;
                }

                if (!isset($agg[$dist_id])) {
                    $agg[$dist_id] = [];
                }
                if (!isset($agg[$dist_id][$lane])) {
                    $agg[$dist_id][$lane] = [];
                }

                $ffl_int = !empty($row['ffl_required']) ? 1 : 0;
                $line_key = (string) ($row['upc'] ?? '') . '|' . (string) $ffl_int;

                if (!isset($agg[$dist_id][$lane][$line_key])) {
                    $agg[$dist_id][$lane][$line_key] = [
                        'upc'              => (string) ($row['upc'] ?? ''),
                        'qty'              => 0,
                        'ffl_required'     => $ffl_int,
                        'dropship_enabled' => !empty($row['dropship_enabled']) ? 1 : 0,
                    ];
                }

                $agg[$dist_id][$lane][$line_key]['qty'] += max(1, (int) ($row['qty'] ?? 1));
            }
        }

        $jobs = [];

        foreach ($agg as $dist_id => $lanes) {
            ksort($lanes, SORT_STRING);

            foreach ($lanes as $lane => $by_line_key) {
                if (!OrderPlacementKeysUtil::is_valid_lane($lane)) {
                    continue;
                }

                if (empty($by_line_key)) {
                    continue;
                }

                $lines = [];
                foreach ($by_line_key as $line_row) {
                    if (!is_array($line_row)) {
                        continue;
                    }

                    $upc = trim((string) ($line_row['upc'] ?? ''));
                    if ($upc === '') {
                        continue;
                    }

                    $lines[] = [
                        'upc'              => $upc,
                        'qty'              => max(1, (int) ($line_row['qty'] ?? 1)),
                        'ffl_required'     => !empty($line_row['ffl_required']) ? 1 : 0,
                        'dropship_enabled' => !empty($line_row['dropship_enabled']) ? 1 : 0,
                    ];
                }

                if (empty($lines)) {
                    continue;
                }

                $job_key = OrderPlacementKeysUtil::build_job_key((string) $dist_id, (string) $lane);
                if ($job_key === '') {
                    $this->log_ctx('skip_job_key_build_failed', [
                        'order_id' => $oid,
                        'dist_id'  => $dist_id,
                        'lane'     => $lane,
                    ]);
                    continue;
                }

                $jobs[$job_key] = [
                    'order_id' => $oid,
                    'dist_id'  => (string) $dist_id,
                    'lane'     => (string) $lane,
                    'lines'    => $lines,
                ];
            }
        }

        if (!empty($local_stock_lines)) {
            $local_lines = array_values($local_stock_lines);
            $local_job_key = OrderPlacementKeysUtil::build_job_key(self::LOCAL_STOCK_DIST_ID, OrderPlacementKeysUtil::LANE_DEALER_FULFILLED);
            if ($local_job_key !== '') {
                $jobs[$local_job_key] = [
                    'order_id' => $oid,
                    'dist_id'  => self::LOCAL_STOCK_DIST_ID,
                    'lane'     => OrderPlacementKeysUtil::LANE_DEALER_FULFILLED,
                    'lines'    => $local_lines,
                    'local_stock' => [
                        'message'    => self::LOCAL_STOCK_RESULT_MESSAGE,
                        'decrements' => array_values($local_stock_decrements),
                    ],
                ];
            }
        }

        ksort($jobs, SORT_STRING);

        return $jobs;
    }

    private function lane_for_route(string $route, bool $ffl_required): string
    {
        $route = strtolower(trim($route));

        if ($route === 'dealer_fulfilled') {
            return OrderPlacementKeysUtil::LANE_DEALER_FULFILLED;
        }

        if ($route === 'direct_ship') {
            return $ffl_required
                ? OrderPlacementKeysUtil::LANE_DIRECT_SHIP_FFL
                : OrderPlacementKeysUtil::LANE_DIRECT_SHIP_NON_FFL;
        }

        // Defensive fallback for unknown route labels.
        return $ffl_required
            ? OrderPlacementKeysUtil::LANE_DIRECT_SHIP_FFL
            : OrderPlacementKeysUtil::LANE_DIRECT_SHIP_NON_FFL;
    }

    /**
     * Persist job rows to the Order Placement Jobs table (init/upsert).
     *
     * @param WC_Order            $order
     * @param array<string,mixed> $lane_jobs
     */
    private function persist_lane_jobs_table(WC_Order $order, array $lane_jobs): void
    {
        $oid = (int) $order->get_id();
        $count = 0;
        $skipped = 0;

        foreach ($lane_jobs as $job_key => $job) {
            $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
            if ($job_key === '' || !is_array($job)) {
                $skipped++;
                continue;
            }

            try {
                OrderPlacementJobLifeCycle::init_job_meta($this->jobs_table, $order, $job_key, $job);
                $count++;
            } catch (\Throwable $e) {
                $this->log_ctx('persist_job_exception', [
                    'order_id' => $oid,
                    'job_key'  => $job_key,
                    'err'      => $e->getMessage(),
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine(),
                ]);
                $skipped++;
            }
        }
    }

    /**
     * Mark DB-backed job rows as eligible for the dispatcher to process.
     *
     * @param WC_Order $order
     * @param array<string, array{order_id:int,dist_id:string,lane:string,lines:array<int,array{upc:string,qty:int,ffl_required?:int,dropship_enabled?:int}>}> $lane_jobs
     */
    private function mark_jobs_eligible_for_processing(WC_Order $order, array $lane_jobs): void
    {
        $oid = (int) $order->get_id();

        foreach ($lane_jobs as $job_key => $_job) {
            if (
                is_array($_job)
                && OrderPlacementKeysUtil::normalize_dist_id((string) ($_job['dist_id'] ?? '')) === self::LOCAL_STOCK_DIST_ID
            ) {
                continue;
            }

            $job_key_norm = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
            if ($job_key_norm === '') {
                $this->log_ctx('mark_eligible_skip_invalid_job_key', [
                    'order_id'    => $oid,
                    'job_key_raw' => (string) $job_key,
                ]);
                continue;
            }

            $status = (string) OrderPlacementJobLifeCycle::get_job_status($this->jobs_table, $order, $job_key_norm);

            // If already succeeded, don't re-queue.
            if ($status === OrderPlacementKeys::JOB_STATUS_SUCCESS) {
                continue;
            }

            // "scheduled" means: eligible in DB for dispatcher (not "AS action exists").
            $patch = OrderPlacementJobPatch::empty()
                ->with_action_id(null)
                ->with_status(OrderPlacementKeys::JOB_STATUS_SCHEDULED)
                ->with_next_run_at_mysql(OrderPlacementTimeUtil::now_mysql_utc());

            OrderPlacementJobWriter::apply_patch_for_order($this->jobs_table, $order, $job_key_norm, $patch);
        }
    }

    /**
     * Mark local-stock jobs complete and decrement local/woo stock quantities.
     *
     * @param WC_Order $order
     * @param array<string, array<string,mixed>> $lane_jobs
     */
    private function finalize_local_stock_jobs(WC_Order $order, array $lane_jobs): void
    {
        $oid = (int) $order->get_id();

        foreach ($lane_jobs as $job_key => $job) {
            if (!is_array($job)) {
                continue;
            }

            $dist_id = OrderPlacementKeysUtil::normalize_dist_id((string) ($job['dist_id'] ?? ''));
            if ($dist_id !== self::LOCAL_STOCK_DIST_ID) {
                continue;
            }

            $job_key_norm = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
            if ($job_key_norm === '') {
                continue;
            }

            try {
                $decrements = [];
                if (isset($job['local_stock']['decrements']) && is_array($job['local_stock']['decrements'])) {
                    $decrements = $job['local_stock']['decrements'];
                }

                $result_lines = [];
                foreach ($decrements as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $product_id = isset($row['product_id']) ? (int) $row['product_id'] : 0;
                    $qty = isset($row['qty']) ? (int) $row['qty'] : 0;
                    $result_lines[] = $this->decrement_local_stock_for_product($product_id, $qty);
                }

                $result = [
                    'type'    => 'local_stock_override',
                    'message' => self::LOCAL_STOCK_RESULT_MESSAGE,
                    'lines'   => $result_lines,
                ];

                $result_json = wp_json_encode($result);
                if (!is_string($result_json) || $result_json === '') {
                    $result_json = '{}';
                }

                $patch = OrderPlacementJobPatch::empty()
                    ->with_last_step('place')
                    ->with_field('place_result_json', $result_json);

                OrderPlacementJobWriter::apply_patch_for_order($this->jobs_table, $order, $job_key_norm, $patch);
                OrderPlacementJobLifeCycle::mark_job_success($this->jobs_table, $order, $job_key_norm);

                $this->log_ctx('local_stock_job_completed', [
                    'order_id'    => $oid,
                    'job_key'     => $job_key_norm,
                    'line_count'  => count($result_lines),
                ]);
            } catch (\Throwable $e) {
                $this->log_ctx('local_stock_job_complete_exception', [
                    'order_id' => $oid,
                    'job_key'  => $job_key_norm,
                    'err'      => $e->getMessage(),
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine(),
                ]);
            }
        }
    }

    /**
     * Apply local stock decrements for a specific product.
     *
     * @return array<string,mixed>
     */
    private function decrement_local_stock_for_product(int $product_id, int $qty): array
    {
        $out = [
            'product_id'            => $product_id,
            'qty'                   => max(0, $qty),
            'local_override_before' => null,
            'local_override_after'  => null,
            'woo_stock_before'      => null,
            'woo_stock_after'       => null,
            'woo_manage_stock'      => null,
            'status'                => 'skipped',
        ];

        if ($product_id <= 0 || $qty <= 0) {
            return $out;
        }

        $product = wc_get_product($product_id);
        if (!($product instanceof WC_Product)) {
            $out['status'] = 'product_missing';
            return $out;
        }

        $local_before = $this->get_local_stock_override_qty($product);
        $local_after = max(0, $local_before - $qty);
        $out['local_override_before'] = $local_before;
        $out['local_override_after'] = $local_after;

        $product->update_meta_data(ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_QTY_META, $local_after);

        $managing_stock = $product->managing_stock();
        $out['woo_manage_stock'] = $managing_stock ? 1 : 0;

        $raw_stock_qty = $product->get_stock_quantity();
        if ($managing_stock || is_numeric((string) $raw_stock_qty)) {
            $woo_before = is_numeric((string) $raw_stock_qty) ? (int) $raw_stock_qty : 0;
            $woo_after = max(0, $woo_before - $qty);
            $product->set_stock_quantity($woo_after);
            if ($managing_stock) {
                $product->set_stock_status($woo_after > 0 ? 'instock' : 'outofstock');
            }
            $out['woo_stock_before'] = $woo_before;
            $out['woo_stock_after'] = $woo_after;
        }

        try {
            $product->save();
            $out['status'] = 'ok';
        } catch (\Throwable $e) {
            $out['status'] = 'save_failed';
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    private function is_local_stock_override_enabled(WC_Product $product): bool
    {
        return $this->to_boolish($product->get_meta(ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_ENABLED_META, true), false);
    }

    private function get_local_stock_override_qty(WC_Product $product): int
    {
        $raw = $product->get_meta(ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_QTY_META, true);
        return is_numeric((string) $raw) ? max(0, (int) $raw) : 0;
    }

    private function fallback_local_identifier(WC_Product $product): string
    {
        $sku = trim((string) $product->get_sku());
        if ($sku !== '') {
            return $sku;
        }

        return 'product-' . (string) $product->get_id();
    }

    /**
     * Parse truthy/falsey values from meta with a default fallback.
     *
     * @param mixed $value
     */
    private function to_boolish($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return $default;
        }

        if (in_array($raw, ['1', 'true', 't', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($raw, ['0', 'false', 'f', 'no', 'n', 'off'], true)) {
            return false;
        }

        if (is_numeric($raw)) {
            return ((float) $raw) !== 0.0;
        }

        return $default;
    }

    /**
     * Parse a non-negative float from meta.
     *
     * @param mixed $value
     */
    private function to_non_negative_float($value, float $default = 0.0): float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return max(0.0, $default);
        }

        $num = $raw;
        if (!is_numeric($num)) {
            $num = trim((string) preg_replace('/[^0-9\.\-]/', '', $raw));
        }

        if ($num === '' || !is_numeric($num)) {
            return max(0.0, $default);
        }

        $v = (float) $num;
        if (!is_finite($v) || $v < 0.0) {
            return max(0.0, $default);
        }

        return $v;
    }

    /**
     * Logging helper.
     *
     * @param string              $msg
     * @param array<string,mixed> $ctx
     */
    private function log_ctx(string $msg, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $msg, $ctx);
    }
}
