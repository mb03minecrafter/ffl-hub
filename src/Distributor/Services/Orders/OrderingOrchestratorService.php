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
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderingOrchestratorService
 *
 * Responsibility:
 * - Orchestrate the *start* of the Order Placement pipeline when an order transitions
 *   into the configured trigger status (currently: "processing").
 *
 * What this class does:
 * - Detect trigger (woocommerce_order_status_changed).
 * - Build per-(dist_id × bucket) job definitions from the order's line items.
 * - Persist durable job rows to the Order Placement Jobs table (upsert/init).
 * - Mark those rows eligible for processing by setting:
 *   - status = scheduled
 *   - next_run_at = now (UTC)
 *   - action_id = NULL (no per-job Action Scheduler action)
 *
 * Execution model (important):
 * - Legacy: schedule 1 Action Scheduler action per job.
 * - Current: a separate recurring dispatcher batch-pulls eligible rows (LIMIT N) and executes them.
 *
 * Non-responsibilities:
 * - Per-job execution logic (belongs in OrderPlacementJobRunner invoked by the dispatcher).
 * - Retry/backoff policy (lifecycle/state machine).
 * - Shipping polling/merges and email dispatch.
 *
 * Idempotency:
 * - OrderPlacementPipelineMetaStore is used to ensure we only start the pipeline once per order.
 * - Job row creation is an upsert keyed by (order_id, job_key).
 *
 * Notes:
 * - Order save failures are best-effort logged; pipeline meta is still treated as authoritative.
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
        add_action('woocommerce_payment_complete', [$this, 'handle_payment_complete'], 10, 1); //ONLY ORDER WHEN THE PAYMENT IS COMPLETE TO AVOID GETTING FUCKED
    }

    /**
     * WooCommerce hook handler: start pipeline when status transitions to "processing".
     *
     * @param int|string $order_id
     * @param string     $old_status
     * @param string     $new_status
     * @param mixed      $order
     */
    public function handle_status_changed($order_id, $old_status, $new_status, $order): void
    {
        $started = microtime(true);

        $order_id_i = (int) $order_id;
        $old_s = (string) $old_status;
        $new_s = (string) $new_status;



        if ($new_s !== 'processing') {
            return;
        }

        if (!($order instanceof WC_Order)) {
            $order = wc_get_order($order_id_i);
        }
        if (!($order instanceof WC_Order)) {
            $this->log_ctx('order_not_found', [
                'order_id' => $order_id_i,
                'reason'   => 'wc_get_order_failed',
            ]);
            return;
        }


        // If we aint paid, they aint getting the product.
        if (!$order->is_paid()) {
            $this->log_ctx('skip_not_paid', [
                'order_id' => $order_id_i,
                'status'   => $order->get_status(),
            ]);
            return;
        }



        $oid = (int) $order->get_id();


        // Atomic “claim” to prevent concurrent double-start.
        $lock_key = '_fflhub_order_place_pipeline_lock';


        //this is extra security to make sure we dont order twice. I want to be able to sleep at night
        // add_post_meta returns false if meta already exists when $unique=true.
        $locked = add_post_meta($oid, $lock_key, (string) time(), true);
        if (!$locked) {
            // Another request already claimed pipeline start.
            return;
        }


        if (OrderPlacementPipelineMetaStore::get_pipeline_started($order)) {
            return;
        }

        $bucket_jobs = $this->build_bucket_jobs_from_order($order);
        if (empty($bucket_jobs)) {
            return;
        }

        $started_at = gmdate('c');

        // Mark started + persist jobs + mark eligible (DB queue)
        OrderPlacementPipelineMetaStore::set_pipeline_started($order, true, $started_at, 'status_processing');

        $this->persist_bucket_jobs_table($order, $bucket_jobs);
        $this->mark_jobs_eligible_for_processing($order, $bucket_jobs);

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
     * Build per-(dist_id × bucket) placement jobs from an order's line items.
     *
     * Rules:
     * - Only includes products marked as "FFLHUB managed".
     * - Bucket is derived from the FFL-required meta (ffl vs non).
     * - Lines aggregate quantities by UPC within each (dist, bucket).
     *
     * @param WC_Order $order
     * @return array<string, array{
     *   order_id:int,
     *   dist_id:string,
     *   bucket:string,
     *   lines:array<int,array{upc:string,qty:int}>
     * }>
     */
    private function build_bucket_jobs_from_order(WC_Order $order): array
    {
        $oid = (int) $order->get_id();

        /** @var array<string, array<string, array<string,int>>> $agg dist => bucket => upc => qty */
        $agg = [];

        $seen = [
            'items_iterated'     => 0,
            'items_not_product'  => 0,
            'product_missing'    => 0,
            'not_managed'        => 0,
            'missing_dist'       => 0,
            'invalid_bucket'     => 0,
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

            $ffl_required = ((int) $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true) === 1);
            $bucket = OrderPlacementKeysUtil::normalize_bucket($ffl_required ? 'ffl' : 'non');

            if (!OrderPlacementKeysUtil::is_valid_bucket($bucket)) {
                $seen['invalid_bucket']++;
                $this->log_ctx('skip_line_invalid_bucket', [
                    'order_id'     => $oid,
                    'product_id'   => (int) $product->get_id(),
                    'dist_id'      => $dist_id,
                    'bucket'       => $bucket,
                    'ffl_required' => $ffl_required ? '1' : '0',
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
                    'bucket'     => $bucket,
                ]);
                continue;
            }

            $seen['accepted']++;

            if (!isset($agg[$dist_id])) {
                $agg[$dist_id] = ['non' => [], 'ffl' => []];
            }
            if (!isset($agg[$dist_id][$bucket][$upc])) {
                $agg[$dist_id][$bucket][$upc] = 0;
            }

            $agg[$dist_id][$bucket][$upc] += $qty;
        }

        $jobs = [];

        foreach ($agg as $dist_id => $buckets) {
            foreach (['non', 'ffl'] as $bucket) {
                $bucket = OrderPlacementKeysUtil::normalize_bucket($bucket);
                if (!OrderPlacementKeysUtil::is_valid_bucket($bucket)) {
                    continue;
                }

                $by_upc = $buckets[$bucket] ?? [];
                if (empty($by_upc)) {
                    continue;
                }

                $lines = [];
                foreach ($by_upc as $upc => $qty) {
                    $lines[] = ['upc' => (string) $upc, 'qty' => max(1, (int) $qty)];
                }

                $job_key = OrderPlacementKeysUtil::build_job_key($dist_id, $bucket);
                if ($job_key === '') {
                    $this->log_ctx('skip_job_key_build_failed', [
                        'order_id' => $oid,
                        'dist_id'  => $dist_id,
                        'bucket'   => $bucket,
                    ]);
                    continue;
                }

                $jobs[$job_key] = [
                    'order_id' => $oid,
                    'dist_id'  => (string) $dist_id,
                    'bucket'   => (string) $bucket,
                    'lines'    => $lines,
                ];
            }
        }

        ksort($jobs);

        return $jobs;
    }

    /**
     * Persist job rows to the Order Placement Jobs table (init/upsert).
     *
     * @param WC_Order              $order
     * @param array<string,mixed>   $bucket_jobs
     */
    private function persist_bucket_jobs_table(WC_Order $order, array $bucket_jobs): void
    {
        $oid = (int) $order->get_id();
        $count = 0;
        $skipped = 0;

        foreach ($bucket_jobs as $job_key => $job) {
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
     * Legacy:
     * - Scheduled an Action Scheduler action per job.
     *
     * Current:
     * - Writes status=scheduled and next_run_at=now for the dispatcher to batch-pull.
     * - Ensures action_id is NULL (no per-job Action Scheduler action).
     *
     * @param WC_Order $order
     * @param array<string, array{order_id:int,dist_id:string,bucket:string,lines:array<int,array{upc:string,qty:int}>}> $bucket_jobs
     */
    private function mark_jobs_eligible_for_processing(WC_Order $order, array $bucket_jobs): void
    {
        $oid = (int) $order->get_id();

        $marked = 0;
        $skipped = 0;

        foreach ($bucket_jobs as $job_key => $_job) {
            $job_key_norm = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
            if ($job_key_norm === '') {
                $skipped++;
                $this->log_ctx('mark_eligible_skip_invalid_job_key', [
                    'order_id'    => $oid,
                    'job_key_raw' => (string) $job_key,
                ]);
                continue;
            }

            $status = (string) OrderPlacementJobLifeCycle::get_job_status($this->jobs_table, $order, $job_key_norm);

            // If already succeeded, don't re-queue.
            if ($status === OrderPlacementKeys::JOB_STATUS_SUCCESS) {
                $skipped++;
                continue;
            }

            // "scheduled" now means: eligible in DB for dispatcher (not "AS action exists").
            $patch = OrderPlacementJobPatch::empty()
                ->with_action_id(null)
                ->with_status(OrderPlacementKeys::JOB_STATUS_SCHEDULED)
                ->with_next_run_at_mysql(OrderPlacementTimeUtil::now_mysql_utc());

            OrderPlacementJobWriter::apply_patch_for_order($this->jobs_table, $order, $job_key_norm, $patch);
            $marked++;
        }
    }

    /**
     * Logging helper.
     *
     * @param string               $msg
     * @param array<string,mixed>  $ctx
     */
    private function log_ctx(string $msg, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $msg, $ctx);
    }
}
