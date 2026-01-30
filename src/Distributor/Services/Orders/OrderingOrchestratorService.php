<?php

namespace FFLHub\Distributor\Services\Orders;

use WC_Order;
use WC_Product;
use WC_Order_Item_Product;

use FFLHub\Product\ProductMeta;
use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Orders\Jobs\Lifecycle\OrderPlacementJobLifecycle;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementPipelineMetaStore;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementProductUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;

use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orchestrates the Order Placement pipeline:
 *  - detect trigger (order status)
 *  - build per-(dist×bucket) jobs
 *  - persist durable job state (table)
 *  - mark jobs eligible for processing (DB queue)
 *
 * NOTE:
 * We no longer schedule 1 Action Scheduler action per job.
 * A separate recurring dispatcher will batch-pull eligible rows (e.g., 50) and execute.
 *
 * Does NOT contain per-job execution logic. That lives in OrderPlacementJobRunner (invoked by dispatcher).
 */
final class OrderingOrchestratorService
{
    private const LOG_PREFIX  = '[FFLHUB][OrderPlacementOrchestrator]';
    private const DEBUG_CONST = 'FFLHUB_PLACE_ORCH_DEBUG';


    public function register(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'handle_status_changed'], 10, 4);      
    }

    public function handle_status_changed($order_id, $old_status, $new_status, $order): void
    {
        $started = microtime(true);

        $order_id_i = (int) $order_id;
        $old_s = (string) $old_status;
        $new_s = (string) $new_status;

        $this->log_ctx('status_changed', [
            'order_id'    => $order_id_i,
            'old_status'  => $old_s,
            'new_status'  => $new_s,
            'order_is_wc' => ($order instanceof WC_Order) ? '1' : '0',
        ]);

        if ($new_s !== 'processing') {
            $this->log_ctx('status_ignored', [
                'order_id'    => $order_id_i,
                'reason'      => 'new_status_not_processing',
                'new_status'  => $new_s,
            ]);
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

        $oid = (int) $order->get_id();

        if (OrderPlacementPipelineMetaStore::get_pipeline_started($order)) {
            $this->log_ctx('pipeline_already_started_skip', [
                'order_id' => $oid,
                'status'   => (string) $order->get_status(),
            ]);
            return;
        }

        $bucket_jobs = $this->build_bucket_jobs_from_order($order);
        if (empty($bucket_jobs)) {
            $this->log_ctx('no_bucket_jobs', [
                'order_id' => $oid,
                'status'   => (string) $order->get_status(),
                'total'    => (string) $order->get_total(),
                'reason'   => 'no_fflhub_managed_products_or_missing_meta',
            ]);
            return;
        }

        $started_at = gmdate('c');

        $this->log_ctx('pipeline_starting', [
            'order_id'    => $oid,
            'started_at'  => $started_at,
            'job_keys'    => array_keys($bucket_jobs),
            'job_count'   => count($bucket_jobs),
        ]);

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

        $this->log_ctx('pipeline_started', [
            'order_id'   => $oid,
            'started_at' => $started_at,
            'status'     => (string) $order->get_status(),
            'total'      => (string) $order->get_total(),
            'job_count'  => count($bucket_jobs),
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    /**
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

        $this->log_ctx('build_jobs_start', [
            'order_id'     => $oid,
            'items_total'  => method_exists($order, 'get_item_count') ? (int) $order->get_item_count() : null,
        ]);

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

        $this->log_ctx('build_jobs_finish', [
            'order_id'  => $oid,
            'job_count' => count($jobs),
            'job_keys'  => array_keys($jobs),
            'stats'     => $seen,
        ]);

        return $jobs;
    }

    /** @param array<string,mixed> $bucket_jobs */
    private function persist_bucket_jobs_table(WC_Order $order, array $bucket_jobs): void
    {
        $oid = (int) $order->get_id();
        $count = 0;
        $skipped = 0;

        $this->log_ctx('persist_jobs_start', [
            'order_id'  => $oid,
            'job_count' => is_array($bucket_jobs) ? count($bucket_jobs) : 0,
        ]);

        foreach ($bucket_jobs as $job_key => $job) {
            $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
            if ($job_key === '' || !is_array($job)) {
                $skipped++;
                continue;
            }

            try {
                OrderPlacementJobLifecycle::init_job_meta($order, $job_key, $job);
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

        $this->log_ctx('persist_jobs_finish', [
            'order_id'  => $oid,
            'persisted' => $count,
            'skipped'   => $skipped,
        ]);
    }

    /**
     * Mark DB-backed job rows as eligible for the dispatcher to process.
     *
     * Legacy behavior scheduled an Action Scheduler action per job.
     * New behavior:
     *  - Writes status + next_run_at so the recurring dispatcher can batch-pull.
     *  - Does NOT create/lookup Action Scheduler actions.
     *
     * @param array<string, array{order_id:int,dist_id:string,bucket:string,lines:array<int,array{upc:string,qty:int}>}> $bucket_jobs
     */
    private function mark_jobs_eligible_for_processing(WC_Order $order, array $bucket_jobs): void
    {
        $oid = (int) $order->get_id();

        $this->log_ctx('mark_eligible_start', [
            'order_id'  => $oid,
            'job_count' => is_array($bucket_jobs) ? count($bucket_jobs) : 0,
        ]);

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

            $status = (string) OrderPlacementJobLifecycle::get_job_status($order, $job_key_norm);

            // If already succeeded, don't re-queue.
            if ($status === OrderPlacementKeys::JOB_STATUS_SUCCESS) {
                $skipped++;
                $this->log_ctx('mark_eligible_skip_already_success', [
                    'order_id' => $oid,
                    'job_key'  => $job_key_norm,
                ]);
                continue;
            }

            // "scheduled" now means: eligible in DB for dispatcher (not "AS action exists").
            $patch = OrderPlacementJobPatch::empty()
                ->with_action_id(null) // no per-job Action Scheduler action
                ->with_status(OrderPlacementKeys::JOB_STATUS_SCHEDULED)
                ->with_next_run_at_mysql(OrderPlacementTimeUtil::now_mysql_utc());

            OrderPlacementJobWriter::apply_patch_for_order($order, $job_key_norm, $patch);
            $marked++;

            $this->log_ctx('mark_eligible_marked', [
                'order_id' => $oid,
                'job_key'  => $job_key_norm,
                'status'   => OrderPlacementKeys::JOB_STATUS_SCHEDULED,
                'run_at'   => gmdate('c'),
            ]);
        }

        $this->log_ctx('mark_eligible_finish', [
            'order_id' => $oid,
            'marked'   => $marked,
            'skipped'  => $skipped,
        ]);
    }

    

    /** @param array<string,mixed> $ctx */
    private function log_ctx(string $msg, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $msg, $ctx);
    }
}
