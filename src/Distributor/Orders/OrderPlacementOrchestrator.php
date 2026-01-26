<?php

namespace FFLHub\Distributor\Orders;

use FFLHub\Product\ProductMeta;
use WC_Order;
use WC_Product;
use WC_Order_Item_Product;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orchestrates the Order Placement pipeline:
 *  - detect trigger (order status)
 *  - build per-(dist×bucket) jobs
 *  - persist durable job state (table)
 *  - schedule Action Scheduler actions
 *
 * 🚨 Does NOT contain per-job execution logic. That lives in OrderPlacementJobRunner.
 */
final class OrderPlacementOrchestrator
{
    private const LOG_PREFIX  = '[FFLHUB][OrderPlacementOrchestrator]';
    private const DEBUG_CONST = 'FFLHUB_PLACE_ORCH_DEBUG';

    // PHP 7.0+ compatible (no typed properties)
    private static $hooks_registered = false;

    public function register(): void
    {
        // Guard across ALL instances created in this PHP request.
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;

        add_action('woocommerce_order_status_changed', [$this, 'handle_status_changed'], 10, 4);

        // Action Scheduler worker hook: delegate to runner (this file orchestrates only)
        add_action(OrderPlacementKeys::AS_HOOK, [$this, 'handle_bucket_job'], 10, 2);
    }

    public function handle_status_changed($order_id, $old_status, $new_status, $order): void
    {
        $order_id_i = (int) $order_id;

        // Only when entering processing (your current trigger while testing)
        if ((string) $new_status !== 'processing') {
            return;
        }

        if (!($order instanceof WC_Order)) {
            $order = wc_get_order($order_id_i);
        }
        if (!($order instanceof WC_Order)) {
            error_log(self::LOG_PREFIX . " status_changed->processing but order not found: {$order_id_i}");
            return;
        }

        // Idempotency on the pipeline start (pipeline meta is still on the order)
        if (OrderPlacementJobsStore::get_pipeline_started($order)) {
            $this->debug('skip: pipeline already started', ['order_id' => $order_id_i]);
            return;
        }

        // Build per-(dist×bucket) jobs first (so we don't "start" a pipeline that has no jobs)
        $bucket_jobs = $this->build_bucket_jobs_from_order($order);

        if (empty($bucket_jobs)) {
            error_log(self::LOG_PREFIX . ' No bucket jobs produced for order ' . (int) $order->get_id());
            return;
        }

        $started_at = gmdate('c');

        // Mark started + persist jobs + schedule (durable)
        OrderPlacementJobsStore::set_pipeline_started($order, true, $started_at, 'status_processing');
        $this->persist_bucket_jobs_table($order, $bucket_jobs);
        $this->schedule_bucket_jobs($order, $bucket_jobs);

        // Save only needed for pipeline meta (jobs are table-backed and persist immediately)
        $order->save();

        $this->debug('pipeline started + jobs scheduled', [
            'order_id' => (int) $order->get_id(),
            'jobs'     => array_keys($bucket_jobs),
        ]);

        error_log(self::LOG_PREFIX . ' Pipeline started: ' . wp_json_encode([
            'order_id'   => (int) $order->get_id(),
            'started_at' => $started_at,
            'status'     => (string) $order->get_status(),
            'total'      => (string) $order->get_total(),
        ]));
    }

    /* ===================== Job build ===================== */

    /**
     * Build minimal per-(distributor×bucket) job payloads from a WooCommerce order.
     *
     * @return array<string, array{
     *   order_id:int,
     *   dist_id:string,
     *   bucket:string,
     *   lines:array<int,array{upc:string,qty:int}>
     * }> keyed by "dist|bucket"
     */
    private function build_bucket_jobs_from_order(WC_Order $order): array
    {
        $oid = (int) $order->get_id();

        /** @var array<string, array<string, array<string,int>>> $agg dist => bucket => upc => qty */
        $agg = [];

        $line_items = $order->get_items('line_item');

        foreach ($line_items as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $qty = max(1, (int) $item->get_quantity());

            $product = $item->get_product();
            if (!($product instanceof WC_Product)) {
                $pid = (int) $item->get_product_id();
                $vid = (int) $item->get_variation_id();
                $product = $vid > 0 ? wc_get_product($vid) : wc_get_product($pid);
                if (!($product instanceof WC_Product)) {
                    continue;
                }
            }

            // Only FFLHub-managed products.
            if ((int) $product->get_meta(ProductMeta::FFLHUB_MANAGED_META, true) !== 1) {
                continue;
            }

            $dist_id = strtolower(trim((string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true)));
            if ($dist_id === '') {
                continue;
            }

            $ffl_required = ((int) $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true) === 1);
            $bucket = $ffl_required ? 'ffl' : 'non';

            // UPC: prefer global_unique_id, then fallback meta.
            $upc_raw = '';
            if (method_exists($product, 'get_global_unique_id')) {
                $upc_raw = trim((string) $product->get_global_unique_id());
            }
            if ($upc_raw === '') {
                $upc_raw = trim((string) $product->get_meta(ProductMeta::FFLHUB_UPC_META, true));
            }

            $upc = self::digits_only($upc_raw);
            if ($upc === '') {
                continue;
            }

            if (!isset($agg[$dist_id])) {
                $agg[$dist_id] = ['non' => [], 'ffl' => []];
            }
            if (!isset($agg[$dist_id][$bucket][$upc])) {
                $agg[$dist_id][$bucket][$upc] = 0;
            }
            $agg[$dist_id][$bucket][$upc] += $qty;
        }

        /** @var array<string, array{order_id:int,dist_id:string,bucket:string,lines:array<int,array{upc:string,qty:int}>}> $jobs */
        $jobs = [];

        foreach ($agg as $dist_id => $buckets) {
            foreach (['non', 'ffl'] as $bucket) {
                $by_upc = $buckets[$bucket] ?? [];
                if (empty($by_upc)) {
                    continue;
                }

                $lines = [];
                foreach ($by_upc as $upc => $qty) {
                    $lines[] = ['upc' => (string) $upc, 'qty' => max(1, (int) $qty)];
                }

                $job_key = $dist_id . '|' . $bucket;

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

    /* ===================== Persist jobs (TABLE) ===================== */

    /**
     * Persist per-job rows in the table (durable).
     *
     * @param array<string, array{
     *   order_id:int,
     *   dist_id:string,
     *   bucket:string,
     *   lines:array<int,array{upc:string,qty:int}>
     * }> $bucket_jobs
     */
    private function persist_bucket_jobs_table(WC_Order $order, array $bucket_jobs): void
    {
        $count = 0;

        foreach ($bucket_jobs as $job_key => $job) {
            if (!is_string($job_key) || $job_key === '' || !is_array($job)) {
                continue;
            }

            // Initialize job row if missing (idempotent) + always store payload_json
            OrderPlacementJobsStore::init_job_meta($order, $job_key, $job);
            $count++;
        }

        error_log(self::LOG_PREFIX . ' Persisted job rows: jobs=' . (int) $count);
    }

    /* ===================== Scheduling ===================== */

    /**
     * Schedule 1 Action Scheduler action per job.
     *
     * @param array<string, array{order_id:int,dist_id:string,bucket:string,lines:array<int,array{upc:string,qty:int}>}> $bucket_jobs
     */
    private function schedule_bucket_jobs(WC_Order $order, array $bucket_jobs): void
    {
        if (!function_exists('as_schedule_single_action')) {
            error_log(self::LOG_PREFIX . ' Action Scheduler not available: as_schedule_single_action() missing');
            return;
        }

        $oid = (int) $order->get_id();

        foreach ($bucket_jobs as $job_key => $job) {
            if (!is_string($job_key) || $job_key === '') {
                continue;
            }

            $status = OrderPlacementJobsStore::get_job_status($order, $job_key);

            // Never schedule successful jobs.
            if ($status === OrderPlacementKeys::JOB_STATUS_SUCCESS) {
                continue;
            }

            $args = [
                'order_id' => $oid,
                'job_key'  => (string) $job_key,
            ];

            // Strong idempotency: if there's already a pending scheduled action for this job, skip.
            if (function_exists('as_next_scheduled_action')) {
                $next = as_next_scheduled_action(OrderPlacementKeys::AS_HOOK, $args, OrderPlacementKeys::AS_GROUP);
                if (is_numeric($next) && (int) $next > 0) {
                    // Optional: keep DB in sync if action_id missing
                    if (OrderPlacementJobsStore::get_job_action_id($order, $job_key) === '') {
                        OrderPlacementJobsStore::set_job_action_id($order, $job_key, (string) $next);
                    }
                    // Mark scheduled if not already
                    if ($status === '' || $status === OrderPlacementKeys::JOB_STATUS_QUEUED) {
                        OrderPlacementJobsStore::set_job_status($order, $job_key, OrderPlacementKeys::JOB_STATUS_SCHEDULED);
                    }
                    continue;
                }
            }

            // Schedule immediately
            $action_id = as_schedule_single_action(time(), OrderPlacementKeys::AS_HOOK, $args, OrderPlacementKeys::AS_GROUP);

            // Persist action id + status (table-backed)
            OrderPlacementJobsStore::set_job_action_id($order, $job_key, (string) $action_id);
            OrderPlacementJobsStore::set_job_status($order, $job_key, OrderPlacementKeys::JOB_STATUS_SCHEDULED);

            error_log(self::LOG_PREFIX . " Scheduled job key={$job_key} action_id={$action_id}");
        }
    }

    /* ===================== Worker entrypoint (delegate) ===================== */

    /**
     * Action Scheduler entrypoint for a single job.
     *
     * Orchestrator delegates actual per-job execution to OrderPlacementJobRunner.
     *
     * @param int|string $order_id
     * @param string $job_key
     */
    public function handle_bucket_job($order_id, string $job_key): void
    {
        $order_id_i = (int) $order_id;
        $job_key_s  = (string) $job_key;

        $order = wc_get_order($order_id_i);
        if (!($order instanceof WC_Order)) {
            error_log(self::LOG_PREFIX . " Worker: order not found order_id={$order_id_i}");
            return;
        }

        try {
            $runner = new OrderPlacementJobRunner();
            $runner->run($order, $job_key_s);
        } catch (\Throwable $e) {
            error_log('[FFLHUB][AS] job failed: ' . $e->getMessage());
            error_log('[FFLHUB][AS] ' . $e->getTraceAsString());
            throw $e; // keep AS marking it failed
        }
    }

    /* ===================== Manual reschedule (ADMIN) ===================== */

    /**
     * Manual reschedule hook used by admin UI.
     *
     * Single source of truth for:
     * - AS action scheduling / idempotency
     * - table-backed job fields (status, next_run_at, action_id, clearing errors)
     *
     * Returns action_id string, or '' if Action Scheduler unavailable.
     */
    public static function manual_reschedule_job(WC_Order $order, string $job_key, int $delay_seconds = 5, string $reason = 'admin_retry'): string
    {
        $job_key = trim((string) $job_key);
        if ($job_key === '') {
            return '';
        }

        $delay_seconds = max(0, (int) $delay_seconds);
        $desired_run_at = time() + $delay_seconds;

        if (!function_exists('as_schedule_single_action')) {
            // Can't schedule. Leave job as failed; UI will show as_missing.
            error_log(self::LOG_PREFIX . " Manual reschedule failed: Action Scheduler missing order=" . (int) $order->get_id() . " job={$job_key}");
            return '';
        }

        $args = [
            'order_id' => (int) $order->get_id(),
            'job_key'  => (string) $job_key,
        ];

        // Strong idempotency: reuse pending action if one already exists
        $action_id = '';
        if (function_exists('as_next_scheduled_action')) {
            $existing = as_next_scheduled_action(
                OrderPlacementKeys::AS_HOOK,
                $args,
                OrderPlacementKeys::AS_GROUP
            );
            if (is_numeric($existing) && (int) $existing > 0) {
                $action_id = (string) (int) $existing;
            }
        }

        if ($action_id === '') {
            $aid = as_schedule_single_action(
                $desired_run_at,
                OrderPlacementKeys::AS_HOOK,
                $args,
                OrderPlacementKeys::AS_GROUP
            );
            $action_id = is_numeric($aid) ? (string) (int) $aid : '';
        }

        // Update table-backed job state to reflect "scheduled now"
        OrderPlacementJobsStore::set_job_status($order, $job_key, OrderPlacementKeys::JOB_STATUS_SCHEDULED);
        OrderPlacementJobsStore::set_job_next_run_at($order, $job_key, gmdate('c', $desired_run_at));
        OrderPlacementJobsStore::set_job_last_error($order, $job_key, '');
        OrderPlacementJobsStore::set_job_last_error_codes($order, $job_key, []);

        if ($action_id !== '') {
            OrderPlacementJobsStore::set_job_action_id($order, $job_key, $action_id);
        } else {
            // If schedule failed for some reason, don't show a stale action id
            if (method_exists(OrderPlacementJobsStore::class, 'clear_job_action_id')) {
                OrderPlacementJobsStore::clear_job_action_id($order, $job_key);
            } else {
                OrderPlacementJobsStore::set_job_action_id($order, $job_key, '');
            }
        }

        error_log(self::LOG_PREFIX . " Manual reschedule scheduled order=" . (int) $order->get_id()
            . " job={$job_key} run_at=" . gmdate('c', $desired_run_at) . " action_id={$action_id} reason={$reason}");

        return $action_id;
    }

    /* ===================== Helpers ===================== */

    private static function digits_only(string $value): string
    {
        $value = trim((string) $value);
        if ($value !== '' && ctype_digit($value)) {
            return $value;
        }
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function debug_enabled(): bool
    {
        if (defined(self::DEBUG_CONST)) {
            return (bool) constant(self::DEBUG_CONST);
        }
        $env = getenv('FFLHUB_PLACE_ORCH_DEBUG');
        if (is_string($env) && $env !== '') {
            return ($env === '1' || strtolower($env) === 'true' || strtolower($env) === 'yes');
        }
        return false; // default OFF now
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function debug(string $msg, array $ctx = []): void
    {
        if (!$this->debug_enabled()) {
            return;
        }
        $line = self::LOG_PREFIX . ' ' . $msg;
        if (!empty($ctx)) {
            $line .= ' ' . wp_json_encode($this->sanitize_ctx($ctx));
        }
        error_log($line);
    }

    /**
     * Keep debug contexts small (prevents log spam).
     *
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private function sanitize_ctx(array $ctx): array
    {
        $out = [];
        foreach ($ctx as $k => $v) {
            if (is_object($v)) {
                $out[$k] = 'object:' . get_class($v);
                continue;
            }
            if (is_resource($v)) {
                $out[$k] = 'resource';
                continue;
            }
            if (is_array($v)) {
                $out[$k] = (count($v) <= 15) ? $v : array_slice($v, 0, 15);
                continue;
            }
            if (is_string($v)) {
                $out[$k] = (strlen($v) <= 250) ? $v : substr($v, 0, 250) . '…';
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }
}
