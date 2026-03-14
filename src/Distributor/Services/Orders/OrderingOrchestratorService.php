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
     * Single authoritative entry point for starting the placement pipeline.
     *
     * @param WC_Order $order
     * @param string  $trigger
     */
    private function start_pipeline_if_needed(WC_Order $order, string $trigger): void
    {
        $oid = (int) $order->get_id();

        // Atomic “claim” to prevent concurrent double-start.
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
     *   bucket:string,
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

        $seen = [
            'items_iterated'     => 0,
            'items_not_product'  => 0,
            'product_missing'    => 0,
            'not_managed'        => 0,
            'missing_dist'       => 0,
            'missing_upc'        => 0,
            'accepted'           => 0,
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

            $dist_raw = (string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true);
            $dist_id  = OrderPlacementKeysUtil::normalize_dist_id($dist_raw);
            if ($dist_id === '') {
                $seen['missing_dist']++;
                $this->log_ctx('skip_line_missing_dist', [
                    'order_id'   => $oid,
                    'product_id' => (int) $product->get_id(),
                    'dist_raw'   => $dist_raw,
                ]);
                continue;
            }

            $upc = OrderPlacementProductUtil::extract_upc_from_product($product);
            if ($upc === '') {
                $seen['missing_upc']++;
                $this->log_ctx('skip_line_missing_upc', [
                    'order_id'   => $oid,
                    'product_id' => (int) $product->get_id(),
                    'dist_id'    => $dist_id,
                ]);
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

            $accepted_by_line_id[$line_id] = [
                'line_id'          => $line_id,
                'product_id'       => (int) $product->get_id(),
                'dist_id'          => (string) $dist_id,
                'upc'              => (string) $upc,
                'qty'              => $qty,
                'ffl_required'     => $ffl_required,
                'dropship_enabled' => $dropship_enabled,
            ];

            $routing_lines[] = [
                'line_id'          => $line_id,
                'dist_id'          => (string) $dist_id,
                'qty'              => $qty,
                'weight_oz'        => $weight_oz,
                'ffl_required'     => $ffl_required,
                'dropship_enabled' => $dropship_enabled,
                'dist_lane_fee'    => $dist_lane_fee,
            ];

            $seen['accepted']++;
        }

        if (empty($accepted_by_line_id) || empty($routing_lines)) {
            return [];
        }

        $plan = DealerFulfillmentRoutingPlanner::find_cheapest_plan($routing_lines);
        $assignments = (isset($plan['assignments']) && is_array($plan['assignments'])) ? $plan['assignments'] : [];

        $this->log_ctx('lane_plan_computed', [
            'order_id'            => $oid,
            'accepted_lines'      => count($accepted_by_line_id),
            'total_cost'          => (float) ($plan['total_cost'] ?? 0.0),
            'decision_lines'      => (int) ($plan['meta']['decision_lines'] ?? 0),
            'combinations'        => (int) ($plan['meta']['combinations_evaluated'] ?? 0),
            'assignment_count'    => count($assignments),
        ]);

        /** @var array<string,array<string,array<string,array<string,mixed>>>> $agg */
        $agg = [];

        foreach ($accepted_by_line_id as $line_id => $row) {
            $route = isset($assignments[$line_id])
                ? strtolower(trim((string) $assignments[$line_id]))
                : (!empty($row['dropship_enabled']) ? 'direct_ship' : 'dealer_fulfilled');

            $lane = $this->lane_for_route($route, !empty($row['ffl_required']));
            if (!OrderPlacementKeysUtil::is_valid_bucket($lane)) {
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

        $jobs = [];

        foreach ($agg as $dist_id => $lanes) {
            ksort($lanes, SORT_STRING);

            foreach ($lanes as $lane => $by_line_key) {
                if (!OrderPlacementKeysUtil::is_valid_bucket($lane)) {
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
                    'bucket'   => (string) $lane,
                    'lane'     => (string) $lane,
                    'lines'    => $lines,
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
            return OrderPlacementKeysUtil::BUCKET_DEALER_FULFILLED;
        }

        if ($route === 'direct_ship') {
            return $ffl_required
                ? OrderPlacementKeysUtil::BUCKET_DIRECT_SHIP_FFL
                : OrderPlacementKeysUtil::BUCKET_DIRECT_SHIP_NON_FFL;
        }

        // Defensive fallback for unknown route labels.
        return $ffl_required
            ? OrderPlacementKeysUtil::BUCKET_DIRECT_SHIP_FFL
            : OrderPlacementKeysUtil::BUCKET_DIRECT_SHIP_NON_FFL;
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
     * @param array<string, array{order_id:int,dist_id:string,bucket:string,lines:array<int,array{upc:string,qty:int,ffl_required?:int,dropship_enabled?:int}>}> $lane_jobs
     */
    private function mark_jobs_eligible_for_processing(WC_Order $order, array $lane_jobs): void
    {
        $oid = (int) $order->get_id();

        foreach ($lane_jobs as $job_key => $_job) {
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
