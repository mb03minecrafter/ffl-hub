<?php

namespace FFLHub\Distributor\Services\Orders\Optimization;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Services\Orders\Cron\DealerBatchCronRegistry;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementProductUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Conservative cross-distributor optimizer for pending dealer-batch rows.
 */
final class DealerBatchShippingOptimizer
{
    private const DEBUG_CONST = 'FFLHUB_DEBUG_DEALER_BATCH_OPTIMIZER';
    private const LOG_PREFIX = '[DealerBatchShippingOptimizer]';
    private const LOCK_OPTION = 'fflhub_dealer_batch_optimizer_lock';
    private const LOCK_TTL_SECONDS = 300;
    private const QUERY_LIMIT = 1000;
    private const EPSILON = 0.0001;

    private OrderPlacementJobsTable $jobs_table;
    private DealerBatchOptimizerAuditTable $audit_table;
    private bool $offers_schema_ready = false;

    /** @var array<string,DistributorProductPayload|null> */
    private array $payload_cache = [];

    public function __construct(
        DistributorHandler $handler,
        OrderPlacementJobsTable $jobs_table,
        ?DealerBatchOptimizerAuditTable $audit_table = null
    ) {
        $this->jobs_table = $jobs_table;
        $this->audit_table = $audit_table ?: new DealerBatchOptimizerAuditTable();
    }

    public function run(string $trigger_dist_id = '', string $horizon_mysql_utc = ''): void
    {
        if (!DealerBatchOptimizerConfig::optimizer_enabled()) {
            return;
        }

        $run_id = substr(md5((string) microtime(true) . '|' . (string) wp_rand()), 0, 12);
        if (!$this->acquire_lock($run_id)) {
            $this->log_ctx('skip_locked', ['run_id' => $run_id, 'trigger_dist_id' => $trigger_dist_id]);
            return;
        }

        try {
            $jobs = $this->load_plannable_dealer_batch_jobs($horizon_mysql_utc);
            if (empty($jobs)) {
                $this->log_ctx('no_pending_jobs', [
                    'run_id' => $run_id,
                    'trigger_dist_id' => $trigger_dist_id,
                    'horizon_mysql_utc' => $this->normalize_mysql_datetime_for_query($horizon_mysql_utc),
                ]);
                return;
            }

            $registry_ids = DealerBatchCronRegistry::distributor_ids();
            $config = DealerBatchOptimizerConfig::optimizer_distributor_config();
            $state = $this->build_state($jobs, $registry_ids);
            $before = [
                'subtotals' => $state['subtotals'],
                'estimated_paid_shipping' => $this->estimated_paid_shipping_total($state['subtotals'], $config),
                'jobs_seen' => count($jobs),
            ];

            $this->audit_table->start_run($run_id, strtolower(trim($trigger_dist_id)), $before, $config);

            $moves = $this->choose_moves($state, $config);
            if (empty($moves)) {
                $this->audit_table->finish_run($run_id, 'no_moves', 0, $before, 'No positive equal-cost paid-shipping improvement found.');
                $this->log_ctx('no_moves', ['run_id' => $run_id, 'before' => $before]);
                return;
            }

            $persisted = $this->persist_moves($run_id, $moves);
            $after_state = $this->build_state($this->load_plannable_dealer_batch_jobs($horizon_mysql_utc), $registry_ids);
            $after = [
                'subtotals' => $after_state['subtotals'],
                'estimated_paid_shipping' => $this->estimated_paid_shipping_total($after_state['subtotals'], $config),
                'jobs_seen' => count($after_state['jobs']),
            ];

            $status = $persisted > 0 ? 'optimized' : 'no_persisted_moves';
            $this->audit_table->finish_run($run_id, $status, $persisted, $after, '');
            $this->log_ctx('run_complete', [
                'run_id' => $run_id,
                'persisted_moves' => $persisted,
                'before' => $before,
                'after' => $after,
            ]);
        } catch (\Throwable $e) {
            $this->audit_table->finish_run($run_id, 'error', 0, [], $e->getMessage());
            $this->log_ctx('run_error', [
                'run_id' => $run_id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        } finally {
            $this->release_lock($run_id);
        }
    }

    /**
     * @return OrderPlacementJobRow[]
     */
    private function load_plannable_dealer_batch_jobs(string $horizon_mysql_utc = ''): array
    {
        global $wpdb;

        $table = $this->jobs_table->get_table_name();
        $dist_ids = array_values(array_filter(array_map(
            static fn(string $id): string => OrderPlacementKeysUtil::normalize_dist_id($id),
            DealerBatchCronRegistry::distributor_ids()
        )));
        if (empty($dist_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($dist_ids), '%s'));
        $lane = OrderPlacementKeysUtil::LANE_DEALER_FULFILLED;
        $status = OrderPlacementKeys::JOB_STATUS_BATCH_PENDING;
        $now = gmdate('Y-m-d H:i:s');
        $horizon_mysql_utc = $this->normalize_mysql_datetime_for_query($horizon_mysql_utc);
        $next_run_clause = "
                AND (
                    next_run_at IS NULL
                    OR next_run_at = '0000-00-00 00:00:00'
                    OR next_run_at <= %s
                    OR (
                        " . ($horizon_mysql_utc !== '' ? "next_run_at <= %s AND" : '') . "
                        (last_error IS NULL OR last_error = '')
                        AND (last_codes_json IS NULL OR last_codes_json = '' OR last_codes_json = '[]')
                        AND (validate_result_json IS NULL OR validate_result_json = '')
                        AND (place_result_json IS NULL OR place_result_json = '')
                    )
                )
        ";
        $time_args = $horizon_mysql_utc !== '' ? [$now, $horizon_mysql_utc] : [$now];

        $sql = $wpdb->prepare(
            "
            SELECT
                id, order_id, job_key, dist_id, lane, status,
                attempts, created_at, updated_at,
                action_id, next_run_at,
                last_step, last_error, last_codes_json,
                done_at,
                payload_json, validate_result_json, place_result_json,
                merchant_po, external_order_ids_json, external_order_id,
                shipped_at, tracking_numbers_json, invoice_numbers_json,
                last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json
            FROM {$table}
            WHERE
                dist_id IN ({$placeholders})
                AND lane = %s
                AND status = %s
                AND (merchant_po IS NULL OR merchant_po = '')
                AND (external_order_id IS NULL OR external_order_id = '')
                AND (external_order_ids_json IS NULL OR external_order_ids_json = '' OR external_order_ids_json = '[]')
                {$next_run_clause}
            ORDER BY created_at ASC, id ASC
            LIMIT %d
            ",
            array_merge($dist_ids, [$lane, $status], $time_args, [self::QUERY_LIMIT])
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $jobs = [];
        foreach ($rows as $row) {
            if (is_array($row) && $this->order_allows_batch_optimization((int) ($row['order_id'] ?? 0))) {
                $jobs[] = new OrderPlacementJobRow($row);
            }
        }

        return $jobs;
    }

    private function order_allows_batch_optimization(int $order_id): bool
    {
        if ($order_id <= 0) {
            return false;
        }

        if (\FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementPipelineMetaStore::is_order_suspended($order_id)) {
            return false;
        }

        if (!function_exists('wc_get_order')) {
            return true;
        }

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            return false;
        }

        return $order->has_status(['processing', 'completed']);
    }

    /**
     * @param OrderPlacementJobRow[] $jobs
     * @param string[] $registry_ids
     * @return array{
     *   jobs:OrderPlacementJobRow[],
     *   subtotals:array<string,float>,
     *   demand:array<string,array<string,int>>,
     *   stock:array<string,array<string,int>>,
     *   movable:array<int,array<string,mixed>>
     * }
     */
    private function build_state(array $jobs, array $registry_ids): array
    {
        $subtotals = [];
        $demand = [];
        $stock = [];
        $movable = [];

        foreach ($registry_ids as $dist_id) {
            $dist_id = OrderPlacementKeysUtil::normalize_dist_id((string) $dist_id);
            if ($dist_id === '') {
                continue;
            }
            $subtotals[$dist_id] = 0.0;
            $demand[$dist_id] = [];
            $stock[$dist_id] = [];
        }

        foreach ($jobs as $job) {
            if (!($job instanceof OrderPlacementJobRow)) {
                continue;
            }
            $source = OrderPlacementKeysUtil::normalize_dist_id((string) $job->dist_id_norm());
            if ($source === '' || !isset($subtotals[$source])) {
                continue;
            }

            $lines = $job->payload_lines();
            if (empty($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                if (!($line instanceof DistributorOrderLine)) {
                    continue;
                }
                $upc = OrderPlacementProductUtil::normalize_upc((string) $line->upc);
                if ($upc === '') {
                    continue;
                }
                $qty = max(1, (int) $line->quantity);
                $demand[$source][$upc] = (int) ($demand[$source][$upc] ?? 0) + $qty;
                $source_payload = $this->pricing_payload($source, $upc);
                if ($source_payload instanceof DistributorProductPayload) {
                    $stock[$source][$upc] = max(0, (int) $source_payload->quantity);
                    $subtotals[$source] += $this->money($source_payload->price) * (float) $qty;
                }
            }
        }

        foreach ($jobs as $job) {
            $candidate = $this->build_movable_candidate($job, $registry_ids, $demand, $stock);
            if (is_array($candidate)) {
                $movable[(int) $job->id] = $candidate;
            }
        }

        ksort($subtotals, SORT_STRING);

        return [
            'jobs' => $jobs,
            'subtotals' => $subtotals,
            'demand' => $demand,
            'stock' => $stock,
            'movable' => $movable,
        ];
    }

    /**
     * @param string[] $registry_ids
     * @param array<string,array<string,int>> $demand
     * @param array<string,array<string,int>> $stock
     * @return array<string,mixed>|null
     */
    private function build_movable_candidate(
        OrderPlacementJobRow $job,
        array $registry_ids,
        array $demand,
        array &$stock
    ): ?array {
        $payload = $job->payload();
        if (isset($payload['dealer_batch_optimizer']) && is_array($payload['dealer_batch_optimizer'])) {
            return null;
        }

        $source = OrderPlacementKeysUtil::normalize_dist_id((string) $job->dist_id_norm());
        if (!DealerBatchCronRegistry::supports_distributor($source)) {
            return null;
        }

        if (!Options::is_distributor_enabled($source)) {
            return null;
        }

        $lines = $job->payload_lines();
        if (empty($lines)) {
            return null;
        }

        $low_stock_threshold = DealerBatchOptimizerConfig::low_stock_threshold();
        $target_sets = null;
        $line_rows = [];
        $subtotal = 0.0;

        foreach ($lines as $line) {
            if (!($line instanceof DistributorOrderLine)) {
                return null;
            }

            $upc = OrderPlacementProductUtil::normalize_upc((string) $line->upc);
            $qty = max(1, (int) $line->quantity);
            if ($upc === '') {
                return null;
            }

            $source_payload = $this->pricing_payload($source, $upc);
            if (!($source_payload instanceof DistributorProductPayload)) {
                return null;
            }

            $stock[$source][$upc] = max(0, (int) $source_payload->quantity);
            if ((int) $source_payload->quantity <= $low_stock_threshold || (int) $source_payload->quantity < $qty) {
                return null;
            }

            $source_price = $this->money($source_payload->price);
            if ($source_price <= 0.0) {
                return null;
            }

            $line_targets = $this->eligible_targets_for_line($source, $upc, $qty, (bool) $line->ffl_required, $registry_ids, $stock);
            if (empty($line_targets)) {
                return null;
            }

            $target_keys = array_fill_keys(array_keys($line_targets), true);
            $target_sets = ($target_sets === null)
                ? $target_keys
                : array_intersect_key($target_sets, $target_keys);

            if (empty($target_sets)) {
                return null;
            }

            $subtotal += $source_price * (float) $qty;
            $line_rows[] = [
                'upc' => $upc,
                'quantity' => $qty,
                'unit_cost' => $source_price,
                'targets' => $line_targets,
                'ffl_required' => (bool) $line->ffl_required,
            ];
        }

        $order_context = $this->resolve_order_context($job, $line_rows);
        $targets = [];
        foreach (array_keys((array) $target_sets) as $target) {
            $target = OrderPlacementKeysUtil::normalize_dist_id((string) $target);
            if ($target !== '' && $target !== $source && $this->product_locks_allow_target($line_rows, $order_context, $target)) {
                $targets[$target] = $target;
            }
        }

        if (empty($targets)) {
            return null;
        }

        return [
            'job' => $job,
            'source' => $source,
            'targets' => array_values($targets),
            'subtotal' => $this->money($subtotal),
            'lines' => $line_rows,
            'order_context' => $order_context,
        ];
    }

    /**
     * @param string[] $registry_ids
     * @param array<string,array<string,int>> $stock
     * @return array<string,DistributorProductPayload>
     */
    private function eligible_targets_for_line(
        string $source,
        string $upc,
        int $qty,
        bool $ffl_required,
        array $registry_ids,
        array &$stock
    ): array {
        $offers = [];
        $lowest = null;

        foreach ($registry_ids as $dist_id) {
            $dist_id = OrderPlacementKeysUtil::normalize_dist_id((string) $dist_id);
            if ($dist_id === '' || !DealerBatchCronRegistry::supports_distributor($dist_id)) {
                continue;
            }
            if (!Options::is_distributor_enabled($dist_id)) {
                continue;
            }

            $payload = $this->pricing_payload($dist_id, $upc);
            if (!($payload instanceof DistributorProductPayload)) {
                continue;
            }

            $price = $this->money($payload->price);
            if ($price <= 0.0) {
                continue;
            }

            $stock[$dist_id][$upc] = max(0, (int) $payload->quantity);
            if ((int) $payload->quantity < $qty) {
                continue;
            }

            $offers[$dist_id] = $payload;
            $lowest = ($lowest === null) ? $price : min((float) $lowest, $price);
        }

        if (!isset($offers[$source]) || $lowest === null) {
            return [];
        }

        $source_price = $this->money($offers[$source]->price);
        if (abs($source_price - (float) $lowest) > self::EPSILON) {
            return [];
        }

        $targets = [];
        foreach ($offers as $dist_id => $payload) {
            if ($dist_id === $source) {
                continue;
            }
            if ($dist_id === 'rsr' && $source !== 'rsr' && $this->rsr_weekend_hold_active()) {
                continue;
            }
            if (abs($this->money($payload->price) - $source_price) > self::EPSILON) {
                continue;
            }
            if ((bool) $payload->ffl_required !== $ffl_required) {
                continue;
            }
            if (trim((string) $payload->sku) === '') {
                continue;
            }

            $targets[$dist_id] = $payload;
        }

        return $targets;
    }

    /**
     * @param array{
     *   subtotals:array<string,float>,
     *   demand:array<string,array<string,int>>,
     *   stock:array<string,array<string,int>>,
     *   movable:array<int,array<string,mixed>>
     * } $state
     * @param array<string,array{threshold:float,penalty:float}> $config
     * @return array<int,array<string,mixed>>
     */
    private function choose_moves(array $state, array $config): array
    {
        $subtotals = $state['subtotals'];
        $demand = $state['demand'];
        $stock = $state['stock'];
        $movable = $state['movable'];
        $chosen = [];
        $used_job_ids = [];

        while (true) {
            $best = $this->best_single_move($movable, $used_job_ids, $subtotals, $demand, $stock, $config);
            if (!is_array($best)) {
                $best = $this->best_threshold_bundle($movable, $used_job_ids, $subtotals, $demand, $stock, $config);
            }
            if (!is_array($best)) {
                break;
            }

            $moves = isset($best['moves']) && is_array($best['moves'])
                ? $best['moves']
                : [$best];

            foreach ($moves as $move) {
                if (!is_array($move)) {
                    continue;
                }
                $job_id = (int) ($move['job_id'] ?? 0);
                if ($job_id <= 0 || isset($used_job_ids[$job_id])) {
                    continue;
                }

                $this->apply_simulated_move($move, $subtotals, $demand);
                $used_job_ids[$job_id] = true;
                $chosen[] = $move;
            }
        }

        return $chosen;
    }

    /**
     * @param array<int,array<string,mixed>> $movable
     * @param array<int,bool> $used_job_ids
     * @param array<string,float> $subtotals
     * @param array<string,array<string,int>> $demand
     * @param array<string,array<string,int>> $stock
     * @param array<string,array{threshold:float,penalty:float}> $config
     * @return array<string,mixed>|null
     */
    private function best_single_move(
        array $movable,
        array $used_job_ids,
        array $subtotals,
        array $demand,
        array $stock,
        array $config
    ): ?array {
        $before_shipping = $this->estimated_paid_shipping_total($subtotals, $config);
        $best = null;
        $best_improvement = 0.0;

        foreach ($movable as $candidate) {
            $job = $candidate['job'] ?? null;
            if (!($job instanceof OrderPlacementJobRow) || isset($used_job_ids[(int) $job->id])) {
                continue;
            }

            foreach ((array) ($candidate['targets'] ?? []) as $target) {
                $move = $this->move_from_candidate($candidate, (string) $target, 'single_positive_improvement');
                if (!$this->target_has_capacity($move, $demand, $stock)) {
                    continue;
                }

                $after = $subtotals;
                $this->apply_simulated_move($move, $after, $demand, false);
                $improvement = $before_shipping - $this->estimated_paid_shipping_total($after, $config);
                if ($improvement <= self::EPSILON) {
                    continue;
                }

                $move['score'] = $improvement;
                if ($best === null || $this->move_is_better($move, $best, $improvement, $best_improvement)) {
                    $best = $move;
                    $best_improvement = $improvement;
                }
            }
        }

        return $best;
    }

    /**
     * @param array<int,array<string,mixed>> $movable
     * @param array<int,bool> $used_job_ids
     * @param array<string,float> $subtotals
     * @param array<string,array<string,int>> $demand
     * @param array<string,array<string,int>> $stock
     * @param array<string,array{threshold:float,penalty:float}> $config
     * @return array<string,mixed>|null
     */
    private function best_threshold_bundle(
        array $movable,
        array $used_job_ids,
        array $subtotals,
        array $demand,
        array $stock,
        array $config
    ): ?array {
        $before_shipping = $this->estimated_paid_shipping_total($subtotals, $config);
        $best_bundle = null;
        $best_improvement = 0.0;

        foreach ($config as $target => $dist_config) {
            $target = OrderPlacementKeysUtil::normalize_dist_id((string) $target);
            $threshold = (float) ($dist_config['threshold'] ?? 0.0);
            if ($target === '' || $threshold <= 0.0) {
                continue;
            }
            $current = (float) ($subtotals[$target] ?? 0.0);
            if ($current >= $threshold) {
                continue;
            }

            $pool = [];
            foreach ($movable as $candidate) {
                $job = $candidate['job'] ?? null;
                if (!($job instanceof OrderPlacementJobRow) || isset($used_job_ids[(int) $job->id])) {
                    continue;
                }
                if (!in_array($target, (array) ($candidate['targets'] ?? []), true)) {
                    continue;
                }

                $move = $this->move_from_candidate($candidate, $target, 'threshold_bundle');
                $pool[] = $move;
            }

            usort($pool, static function (array $a, array $b): int {
                $as = (float) ($a['subtotal'] ?? 0.0);
                $bs = (float) ($b['subtotal'] ?? 0.0);
                if (abs($as - $bs) > 0.0001) {
                    return $as < $bs ? -1 : 1;
                }

                return ((int) ($a['job_id'] ?? 0)) <=> ((int) ($b['job_id'] ?? 0));
            });

            $trial_subtotals = $subtotals;
            $trial_demand = $demand;
            $bundle = [];
            foreach ($pool as $move) {
                if (!$this->target_has_capacity($move, $trial_demand, $stock)) {
                    continue;
                }

                $bundle[] = $move;
                $this->apply_simulated_move($move, $trial_subtotals, $trial_demand);
                if ((float) ($trial_subtotals[$target] ?? 0.0) >= $threshold) {
                    break;
                }
            }

            if (empty($bundle) || (float) ($trial_subtotals[$target] ?? 0.0) < $threshold) {
                continue;
            }

            $improvement = $before_shipping - $this->estimated_paid_shipping_total($trial_subtotals, $config);
            if ($improvement <= self::EPSILON) {
                continue;
            }

            $overfill = max(0.0, (float) ($trial_subtotals[$target] ?? 0.0) - $threshold);
            if (
                $best_bundle === null
                || $improvement > ($best_improvement + self::EPSILON)
                || (
                    abs($improvement - $best_improvement) <= self::EPSILON
                    && count($bundle) < count((array) ($best_bundle['moves'] ?? []))
                )
                || (
                    abs($improvement - $best_improvement) <= self::EPSILON
                    && count($bundle) === count((array) ($best_bundle['moves'] ?? []))
                    && $overfill < (float) ($best_bundle['overfill'] ?? PHP_FLOAT_MAX)
                )
            ) {
                $best_bundle = [
                    'moves' => $bundle,
                    'score' => $improvement,
                    'overfill' => $overfill,
                ];
                $best_improvement = $improvement;
            }
        }

        return $best_bundle;
    }

    /**
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    private function move_from_candidate(array $candidate, string $target, string $reason): array
    {
        $job = $candidate['job'];
        $source = (string) ($candidate['source'] ?? '');
        $target = OrderPlacementKeysUtil::normalize_dist_id($target);

        return [
            'job_id' => (int) $job->id,
            'order_id' => (int) $job->order_id,
            'job' => $job,
            'source' => $source,
            'target' => $target,
            'subtotal' => $this->money((float) ($candidate['subtotal'] ?? 0.0)),
            'lines' => (array) ($candidate['lines'] ?? []),
            'order_context' => is_array($candidate['order_context'] ?? null) ? $candidate['order_context'] : [],
            'reason' => $reason,
        ];
    }

    /**
     * @param array<string,mixed> $move
     * @param array<string,array<string,int>> $demand
     * @param array<string,array<string,int>> $stock
     */
    private function target_has_capacity(array $move, array $demand, array $stock): bool
    {
        $target = (string) ($move['target'] ?? '');
        if ($target === '') {
            return false;
        }

        foreach ((array) ($move['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                return false;
            }
            $upc = (string) ($line['upc'] ?? '');
            $qty = max(1, (int) ($line['quantity'] ?? 0));
            $available = (int) ($stock[$target][$upc] ?? 0);
            $allocated = (int) ($demand[$target][$upc] ?? 0);
            if (($allocated + $qty) > $available) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $move
     * @param array<string,float> $subtotals
     * @param array<string,array<string,int>> $demand
     */
    private function apply_simulated_move(array $move, array &$subtotals, array &$demand, bool $apply_demand = true): void
    {
        $source = (string) ($move['source'] ?? '');
        $target = (string) ($move['target'] ?? '');
        $subtotal = (float) ($move['subtotal'] ?? 0.0);

        if ($source === '' || $target === '' || $source === $target || $subtotal <= 0.0) {
            return;
        }

        $subtotals[$source] = $this->money(max(0.0, (float) ($subtotals[$source] ?? 0.0) - $subtotal));
        $subtotals[$target] = $this->money((float) ($subtotals[$target] ?? 0.0) + $subtotal);

        if (!$apply_demand) {
            return;
        }

        foreach ((array) ($move['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $upc = (string) ($line['upc'] ?? '');
            $qty = max(1, (int) ($line['quantity'] ?? 0));
            if ($upc === '') {
                continue;
            }
            $demand[$source][$upc] = max(0, (int) ($demand[$source][$upc] ?? 0) - $qty);
            $demand[$target][$upc] = (int) ($demand[$target][$upc] ?? 0) + $qty;
        }
    }

    /**
     * @param array<string,float> $subtotals
     * @param array<string,array{threshold:float,penalty:float}> $config
     */
    private function estimated_paid_shipping_total(array $subtotals, array $config): float
    {
        $total = 0.0;
        foreach ($config as $dist_id => $row) {
            $threshold = (float) ($row['threshold'] ?? 0.0);
            $subtotal = (float) ($subtotals[$dist_id] ?? 0.0);
            if ($subtotal <= self::EPSILON) {
                continue;
            }

            if ($threshold > 0.0 && $subtotal >= $threshold) {
                continue;
            }

            $paid_shipping = (float) ($row['penalty'] ?? 0.0);
            if ($paid_shipping <= 0.0) {
                continue;
            }

            $total += $paid_shipping;
        }

        return $this->money($total);
    }

    private function move_is_better(array $candidate, array $current, float $candidate_score, float $current_score): bool
    {
        if ($candidate_score > ($current_score + self::EPSILON)) {
            return true;
        }
        if (abs($candidate_score - $current_score) > self::EPSILON) {
            return false;
        }

        $candidate_subtotal = (float) ($candidate['subtotal'] ?? 0.0);
        $current_subtotal = (float) ($current['subtotal'] ?? 0.0);
        if (abs($candidate_subtotal - $current_subtotal) > self::EPSILON) {
            return $candidate_subtotal < $current_subtotal;
        }

        return (int) ($candidate['job_id'] ?? 0) < (int) ($current['job_id'] ?? 0);
    }

    /**
     * @param array<int,array<string,mixed>> $moves
     */
    private function persist_moves(string $run_id, array $moves): int
    {
        global $wpdb;

        if (empty($moves)) {
            return 0;
        }

        $table = $this->jobs_table->get_table_name();
        $persisted = 0;

        $wpdb->query('START TRANSACTION');
        try {
            foreach ($moves as $move) {
                $job = $move['job'] ?? null;
                if (!($job instanceof OrderPlacementJobRow)) {
                    continue;
                }

                $target = OrderPlacementKeysUtil::normalize_dist_id((string) ($move['target'] ?? ''));
                $source = OrderPlacementKeysUtil::normalize_dist_id((string) ($move['source'] ?? ''));
                $new_job_key = OrderPlacementKeysUtil::build_job_key($target, OrderPlacementKeysUtil::LANE_DEALER_FULFILLED);
                $old_job_key = $job->job_key_norm();
                if ($target === '' || $source === '' || $new_job_key === '' || $old_job_key === '') {
                    continue;
                }

                $conflict_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE order_id = %d AND job_key = %s AND id <> %d LIMIT 1",
                    (int) $job->order_id,
                    $new_job_key,
                    (int) $job->id
                ));
                if ($conflict_id > 0) {
                    $this->log_ctx('skip_conflicting_target_job', [
                        'run_id' => $run_id,
                        'job_id' => (int) $job->id,
                        'order_id' => (int) $job->order_id,
                        'target_job_key' => $new_job_key,
                        'conflict_id' => $conflict_id,
                    ]);
                    continue;
                }

                $payload = $this->optimized_payload($job, $move, $run_id, $old_job_key, $new_job_key);
                $updated = $wpdb->query($wpdb->prepare(
                    "
                    UPDATE {$table}
                    SET
                        job_key = %s,
                        dist_id = %s,
                        lane = %s,
                        payload_json = %s,
                        updated_at = %s
                    WHERE
                        id = %d
                        AND dist_id = %s
                        AND job_key = %s
                        AND lane = %s
                        AND status = %s
                        AND (merchant_po IS NULL OR merchant_po = '')
                        AND (external_order_id IS NULL OR external_order_id = '')
                        AND (external_order_ids_json IS NULL OR external_order_ids_json = '' OR external_order_ids_json = '[]')
                    LIMIT 1
                    ",
                    $new_job_key,
                    $target,
                    OrderPlacementKeysUtil::LANE_DEALER_FULFILLED,
                    (string) wp_json_encode($payload),
                    gmdate('Y-m-d H:i:s'),
                    (int) $job->id,
                    $source,
                    $old_job_key,
                    OrderPlacementKeysUtil::LANE_DEALER_FULFILLED,
                    OrderPlacementKeys::JOB_STATUS_BATCH_PENDING
                ));

                if ($updated !== 1) {
                    continue;
                }

                $this->record_move_rows($run_id, $move, $old_job_key, $new_job_key);
                $this->add_order_note($move, $old_job_key, $new_job_key);
                $persisted++;
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        return $persisted;
    }

    /**
     * @param array<string,mixed> $move
     * @return array<string,mixed>
     */
    private function optimized_payload(
        OrderPlacementJobRow $job,
        array $move,
        string $run_id,
        string $old_job_key,
        string $new_job_key
    ): array {
        $payload = $job->payload();
        $source = (string) ($move['source'] ?? '');
        $target = (string) ($move['target'] ?? '');

        $payload['dist_id'] = $target;
        $payload['lane'] = OrderPlacementKeysUtil::LANE_DEALER_FULFILLED;
        $payload['dealer_batch_optimizer'] = [
            'run_id' => $run_id,
            'original_distributor_id' => $source,
            'optimized_from_distributor_id' => $source,
            'optimized_to_distributor_id' => $target,
            'optimized_at_utc' => gmdate('Y-m-d H:i:s'),
            'optimization_reason' => (string) ($move['reason'] ?? ''),
            'job_key_before' => $old_job_key,
            'job_key_after' => $new_job_key,
        ];

        if (isset($payload['lines']) && is_array($payload['lines'])) {
            foreach ($payload['lines'] as &$line) {
                if (!is_array($line)) {
                    continue;
                }
                $upc = OrderPlacementProductUtil::normalize_upc((string) ($line['upc'] ?? ''));
                if ($upc === '') {
                    continue;
                }
                $target_payload = $this->pricing_payload($target, $upc);
                if ($target_payload instanceof DistributorProductPayload) {
                    $line['sku'] = (string) $target_payload->sku;
                    $line['target_sku'] = (string) $target_payload->sku;
                    $line['target_unit_cost'] = $this->money($target_payload->price);
                }
                $line['optimized_from_dist_id'] = $source;
                $line['optimized_to_dist_id'] = $target;
            }
            unset($line);
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $move
     */
    private function record_move_rows(string $run_id, array $move, string $old_job_key, string $new_job_key): void
    {
        $context = is_array($move['order_context'] ?? null) ? $move['order_context'] : [];
        foreach ((array) ($move['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $upc = (string) ($line['upc'] ?? '');
            $line_context = is_array($context[$upc] ?? null) ? $context[$upc] : [];

            $this->audit_table->record_move($run_id, [
                'job_id' => (int) ($move['job_id'] ?? 0),
                'order_id' => (int) ($move['order_id'] ?? 0),
                'job_key_before' => $old_job_key,
                'job_key_after' => $new_job_key,
                'product_id' => $line_context['product_id'] ?? null,
                'variation_id' => $line_context['variation_id'] ?? null,
                'upc' => $upc,
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unit_cost' => (float) ($line['unit_cost'] ?? 0.0),
                'source_dist_id' => (string) ($move['source'] ?? ''),
                'target_dist_id' => (string) ($move['target'] ?? ''),
                'reason' => (string) ($move['reason'] ?? ''),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $move
     */
    private function add_order_note(array $move, string $old_job_key, string $new_job_key): void
    {
        $order = wc_get_order((int) ($move['order_id'] ?? 0));
        if (!($order instanceof WC_Order)) {
            return;
        }

        $order->add_order_note(sprintf(
            'FFLHub dealer-batch optimizer moved job %d from %s to %s for equal-cost paid-shipping optimization.',
            (int) ($move['job_id'] ?? 0),
            $old_job_key,
            $new_job_key
        ));
    }

    private function pricing_payload(string $dist_id, string $upc): ?DistributorProductPayload
    {
        global $wpdb;

        $dist_id = OrderPlacementKeysUtil::normalize_dist_id($dist_id);
        $upc = OrderPlacementProductUtil::normalize_upc($upc);
        if ($dist_id === '' || $upc === '' || !$wpdb) {
            return null;
        }

        $key = $dist_id . '|' . $upc;
        if (array_key_exists($key, $this->payload_cache)) {
            return $this->payload_cache[$key];
        }

        if (!$this->offers_schema_ready) {
            DistributorOffersStore::ensure_schema();
            $this->offers_schema_ready = true;
        }

        $table = DistributorOffersStore::table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT
                    upc,
                    distributor_id,
                    distributor_product_id,
                    distributor_sku,
                    manufacturer_norm,
                    qty,
                    stock_status,
                    dealer_price,
                    shipping_cost,
                    landed_cost,
                    map_price,
                    msrp,
                    ffl_required,
                    sot_required,
                    dropship_enabled,
                    shipping_weight_oz,
                    shipping_length_in,
                    shipping_width_in,
                    shipping_height_in
                FROM {$table}
                WHERE upc = %s
                  AND distributor_id = %s
                  AND enabled = 1
                LIMIT 1
                ",
                $upc,
                $dist_id
            ),
            ARRAY_A
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if (!is_array($row)) {
            $this->payload_cache[$key] = null;
            return null;
        }

        $this->payload_cache[$key] = $this->offer_row_to_payload($row);
        return $this->payload_cache[$key];
    }

    /**
     * Adapt the normalized offers row into the payload shape the existing
     * optimizer already scores. Dealer batch moves compare dealer unit cost;
     * batch freight savings are calculated separately from distributor
     * thresholds, so landed_cost is kept as diagnostic/raw data rather than the
     * candidate price.
     *
     * @param array<string,mixed> $row
     */
    private function offer_row_to_payload(array $row): DistributorProductPayload
    {
        $upc = OrderPlacementProductUtil::normalize_upc((string) ($row['upc'] ?? ''));
        $sku = trim((string) ($row['distributor_sku'] ?? ''));
        if ($sku === '') {
            $sku = trim((string) ($row['distributor_product_id'] ?? ''));
        }

        $stock_status = strtolower(trim((string) ($row['stock_status'] ?? '')));
        $qty = max(0, (int) ($row['qty'] ?? 0));
        if ($stock_status !== 'instock') {
            $qty = 0;
        }

        $dealer_price = $this->money((float) ($row['dealer_price'] ?? 0));
        $shipping_cost = $this->money((float) ($row['shipping_cost'] ?? 0));
        $landed_cost = $this->money((float) ($row['landed_cost'] ?? 0));
        if ($landed_cost <= 0.0 && $dealer_price > 0.0) {
            $landed_cost = $this->money($dealer_price + $shipping_cost);
        }

        return new DistributorProductPayload(
            $upc,
            $sku,
            '',
            '',
            $dealer_price,
            $this->money((float) ($row['map_price'] ?? 0)),
            $this->money((float) ($row['msrp'] ?? 0)),
            $qty,
            $shipping_cost,
            $landed_cost,
            '',
            (int) ($row['ffl_required'] ?? 0) === 1,
            (int) ($row['dropship_enabled'] ?? 0) === 1,
            null,
            $row,
            $this->nullable_string($row['shipping_weight_oz'] ?? null),
            (int) ($row['sot_required'] ?? 0) === 1,
            $this->nullable_string($row['shipping_length_in'] ?? null),
            $this->nullable_string($row['shipping_width_in'] ?? null),
            $this->nullable_string($row['shipping_height_in'] ?? null),
            $this->nullable_string($row['manufacturer_norm'] ?? null)
        );
    }

    /**
     * @param array<int,array<string,mixed>> $line_rows
     * @return array<string,array{product_id:int,variation_id:int,lock_enabled:bool,lock_ids:array<int,string>}>
     */
    private function resolve_order_context(OrderPlacementJobRow $job, array $line_rows): array
    {
        $order = wc_get_order((int) $job->order_id);
        if (!($order instanceof WC_Order)) {
            return [];
        }

        $wanted = [];
        foreach ($line_rows as $line) {
            $upc = (string) ($line['upc'] ?? '');
            if ($upc !== '') {
                $wanted[$upc] = true;
            }
        }

        if (empty($wanted)) {
            return [];
        }

        $context = [];
        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            if (!($product instanceof WC_Product)) {
                continue;
            }

            $upc = $this->order_item_upc($item, $product);
            if ($upc === '' || !isset($wanted[$upc]) || isset($context[$upc])) {
                continue;
            }

            $lock = $this->product_distributor_lock($product);
            $context[$upc] = [
                'product_id' => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
                'lock_enabled' => (bool) ($lock['enabled'] ?? false),
                'lock_ids' => is_array($lock['ids'] ?? null) ? $lock['ids'] : [],
            ];
        }

        return $context;
    }

    /**
     * @param array<int,array<string,mixed>> $line_rows
     * @param array<string,array<string,mixed>> $order_context
     */
    private function product_locks_allow_target(array $line_rows, array $order_context, string $target): bool
    {
        $target = OrderPlacementKeysUtil::normalize_dist_id($target);
        if ($target === '') {
            return false;
        }

        foreach ($line_rows as $line) {
            if (!is_array($line)) {
                return false;
            }

            $upc = (string) ($line['upc'] ?? '');
            if ($upc === '') {
                return false;
            }

            $context = is_array($order_context[$upc] ?? null) ? $order_context[$upc] : [];
            if (empty($context['lock_enabled'])) {
                continue;
            }

            $lock_ids = is_array($context['lock_ids'] ?? null) ? $context['lock_ids'] : [];
            if (empty($lock_ids)) {
                return false;
            }

            if (!in_array($target, $lock_ids, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{enabled:bool,ids:array<int,string>}
     */
    private function product_distributor_lock(WC_Product $product): array
    {
        $ids = ProductStateStore::get_allowed_distributor_ids_for_product($product);

        return [
            'enabled' => !empty($ids),
            'ids' => $ids,
        ];
    }

    private function order_item_upc(WC_Order_Item_Product $item, WC_Product $product): string
    {
        $upc = OrderPlacementProductUtil::extract_upc_from_product($product);
        if ($upc !== '') {
            return $upc;
        }

        $state_row = ProductStateStore::get_row_for_product($product);
        if (is_array($state_row)) {
            $upc = OrderPlacementProductUtil::normalize_upc((string) ($state_row['upc'] ?? ''));
            if ($upc !== '') {
                return $upc;
            }
        }

        foreach (['_upc', 'upc', 'UPC'] as $meta_key) {
            $upc = OrderPlacementProductUtil::normalize_upc((string) $item->get_meta($meta_key, true));
            if ($upc !== '') {
                return $upc;
            }
        }

        return OrderPlacementProductUtil::normalize_upc((string) $item->get_meta('_sku', true));
    }

    private function normalize_mysql_datetime_for_query(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return '';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) ? $value : '';
    }

    private function money(float $value): float
    {
        if (!is_finite($value)) {
            return 0.0;
        }

        return round(max(0.0, $value), 2);
    }

    private function nullable_string($value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function rsr_weekend_hold_active(): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Chicago'));
        $day = (int) $now->format('N');

        return $day >= 6;
    }

    private function acquire_lock(string $run_id): bool
    {
        $now = time();
        $current = get_option(self::LOCK_OPTION, '');
        if (is_string($current) && $current !== '') {
            $data = json_decode($current, true);
            $locked_at = is_array($data) ? (int) ($data['t'] ?? 0) : 0;
            if ($locked_at > 0 && ($now - $locked_at) < self::LOCK_TTL_SECONDS) {
                return false;
            }
        }

        update_option(self::LOCK_OPTION, (string) wp_json_encode(['t' => $now, 'run' => $run_id]), false);
        return true;
    }

    private function release_lock(string $run_id): void
    {
        $current = get_option(self::LOCK_OPTION, '');
        if (!is_string($current) || $current === '') {
            return;
        }

        $data = json_decode($current, true);
        $owner = is_array($data) ? (string) ($data['run'] ?? '') : '';
        if ($owner !== '' && $owner !== $run_id) {
            return;
        }

        delete_option(self::LOCK_OPTION);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log_ctx(string $message, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $message, $ctx);
    }
}
