<?php

namespace FFLHub\Distributor\Services\Orion\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Cron\CronRunLogger;
use FFLHub\Distributor\Services\Orion\API\OrionApiClient;
use FFLHub\Distributor\Services\Orion\OrionOfferNormalizationService;
use FFLHub\Distributor\Services\Orion\OrionProductImporterService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * Orion inventory/pricing Action Scheduler job.
 *
 * This job does not rebuild the Orion catalog table. It fetches the current
 * get_catalog_inventory response, stages only the volatile inventory payload,
 * applies that stage to the live Orion product table, then projects the same
 * stage into fflhub_distributor_offers for already-normalized Orion offers.
 *
 * The optional optimized mode changes only the API request list. Full mode asks
 * Orion for the full inventory feed. Optimized mode asks only for product IDs
 * that already exist as enabled Orion rows in distributor_offers.
 */
final class OrionInventoryCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_orion_pricing_quantity_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][OrionInventoryCron]';
    private const DEFAULT_TIMEOUT_SECONDS = 120;
    private const FAILURE_COOLDOWN_SECONDS = 900;
    private const FAILURE_COOLDOWN_TRANSIENT = 'fflhub_orion_inventory_failure_cooldown';

    public function __construct(DoubleBufferedProductTable $table)
    {
        parent::__construct($table);

        add_action('init', [$this, 'maybe_reschedule_hourly_inventory_action'], 9);
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_interval_seconds(): int
    {
        return HOUR_IN_SECONDS;
    }

    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    public function maybe_reschedule_hourly_inventory_action(): void
    {
        if (!function_exists('as_unschedule_all_actions') || !class_exists('\ActionScheduler_Store')) {
            return;
        }

        $expected_interval = (int) $this->get_interval_seconds();
        if ($expected_interval <= 0) {
            return;
        }

        $hook = $this->get_cron_hook_name();
        $args = $this->get_action_args();
        $group = $this->get_action_group();

        try {
            $store = \ActionScheduler_Store::instance();
            if (!method_exists($store, 'query_actions') || !method_exists($store, 'fetch_action')) {
                return;
            }

            $ids = $store->query_actions([
                'hook' => $hook,
                'group' => $group,
                'args' => $args,
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'claimed' => false,
                'per_page' => 20,
            ]);

            if (!is_array($ids) || empty($ids)) {
                return;
            }

            foreach ($ids as $id) {
                $current_interval = $this->scheduled_action_recurrence_seconds($store, (int) $id);
                if ($current_interval !== null && $current_interval !== $expected_interval) {
                    as_unschedule_all_actions($hook, $args, $group);
                    $this->log('Rescheduled Orion inventory cron interval.', [
                        'old_interval_sec' => $current_interval,
                        'new_interval_sec' => $expected_interval,
                        'pending_actions_found' => count($ids),
                    ]);
                    return;
                }
            }
        } catch (\Throwable $e) {
            $this->log('Unable to inspect Orion inventory cron schedule interval.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function scheduled_action_recurrence_seconds(object $store, int $actionId): ?int
    {
        $action = $store->fetch_action($actionId);
        if (!is_object($action) || !method_exists($action, 'get_schedule')) {
            return null;
        }

        $schedule = $action->get_schedule();
        if (!is_object($schedule)) {
            return null;
        }

        if (method_exists($schedule, 'get_recurrence')) {
            $recurrence = $schedule->get_recurrence();
            return is_numeric($recurrence) ? (int) $recurrence : null;
        }

        if (method_exists($schedule, 'interval_in_seconds')) {
            return (int) $schedule->interval_in_seconds();
        }

        return null;
    }

    protected function get_initial_delay_seconds(): int
    {
        return 2 * MINUTE_IN_SECONDS;
    }

    public function run(): void
    {
        // Stage 0: initialize profiling, timeout, and the run marker.
        //
        // This cron can spend most of its time waiting on Orion's API, so the
        // logger captures memory and phase timing from the start. The actual
        // table writes happen later inside OrionProductImporterService.
        $t_start = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        $timeout_seconds = $this->get_timeout_seconds();

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        update_option('fflhub_orion_inventory_last_run', current_time('mysql'), false);

        $this->log('---- RUN START ----', [
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'hook' => self::CRON_HOOK,
            'group' => $this->get_action_group(),
            'timeout_sec' => $timeout_seconds,
            'memory_kb' => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
            'memory_peak_kb' => $this->memory_peak_kb(),
        ]);

        // Stage 1: build the API client and stop early when credentials or a
        // recent network/API failure cooldown make the run unsafe.
        //
        // The cooldown avoids hammering Orion during temporary outages. It does
        // not mark inventory stale or touch distributor/offers tables.
        $client = $this->make_client($timeout_seconds);
        if (!$client->has_credentials()) {
            update_option('fflhub_orion_inventory_last_error', current_time('mysql'), false);
            $this->log('Missing Orion connection key; inventory update skipped.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing connection key)');
            return;
        }

        $cooldown = $this->active_failure_cooldown();
        if ($cooldown !== null) {
            $this->log('Skipping Orion inventory update due to recent API failure cooldown.', $cooldown);
            $this->finalize_run($t_start, $mem_start, 'SUCCESS (failure cooldown)', $cooldown);
            return;
        }

        $t_inventory = microtime(true);

        // Stage 2: choose the inventory request scope.
        //
        // Full mode passes an empty product-id list to the API client, which is
        // the legacy/current behavior and returns the full Orion inventory
        // snapshot.
        //
        // Optimized mode reads enabled Orion distributor_offers rows and sends
        // their distributor_product_id values to Orion. That keeps the request
        // limited to products we already carry without joining product_state or
        // the large Orion live table during the inventory cron.
        $optimized_inventory_run = $this->optimized_inventory_run_enabled();
        $optimized_product_ids = $optimized_inventory_run
            ? OrionOfferNormalizationService::enabled_offer_product_ids_for_inventory()
            : [];

        if ($optimized_inventory_run && empty($optimized_product_ids)) {
            // In optimized mode, an empty ID list should be a no-op. Passing an
            // empty list through would look like full-mode behavior to the API
            // client, which could accidentally trigger a full feed pull.
            $ctx = [
                'optimized_inventory_run' => 1,
                'requested_product_ids' => 0,
            ];
            $this->log('Skipping optimized Orion inventory update because no enabled Orion offer product IDs were found.', $ctx);
            $this->finalize_run($t_start, $mem_start, 'SUCCESS (optimized inventory no product ids)', $ctx);
            return;
        }

        $this->log('PHASE START: get_catalog_inventory', [
            'timeout_sec' => $timeout_seconds,
            'optimized_inventory_run' => $optimized_inventory_run ? 1 : 0,
            'requested_product_ids' => count($optimized_product_ids),
            'memory_kb' => $this->memory_kb(),
            'memory_peak_kb' => $this->memory_peak_kb(),
        ]);

        // Stage 3: fetch the raw Orion inventory payload.
        //
        // The API client owns request formatting. This cron records whether we
        // used optimized IDs, how many IDs were requested, response size, and
        // row count so we can compare full-vs-optimized runs cleanly.
        $inventory = $client->get_catalog_inventory($optimized_product_ids);
        $inventory_data = (array) ($inventory['data'] ?? []);
        $this->profile('get_catalog_inventory', $t_inventory, array_merge([
            'ok' => empty($inventory['ok']) ? 0 : 1,
            'status' => (int) ($inventory['status'] ?? 0),
            'timeout_sec' => $timeout_seconds,
            'optimized_inventory_run' => $optimized_inventory_run ? 1 : 0,
            'requested_product_ids' => count($optimized_product_ids),
            'response_bytes' => (int) ($inventory['response_bytes'] ?? 0),
        ], DebugLogUtil::summarize_array_keys($inventory_data), [
            'inventory_rows' => $this->count_inventory_rows($inventory_data),
        ]));

        if (empty($inventory['ok'])) {
            update_option('fflhub_orion_inventory_last_error', current_time('mysql'), false);
            $this->log('Orion get_catalog_inventory failed.', [
                'status' => (int) ($inventory['status'] ?? 0),
                'error' => (string) ($inventory['error'] ?? ''),
            ]);
            $this->set_failure_cooldown($inventory);
            $this->finalize_run($t_start, $mem_start, 'ERROR (inventory request failed)', [
                'status' => (int) ($inventory['status'] ?? 0),
                'error' => (string) ($inventory['error'] ?? ''),
            ]);
            return;
        }

        $t_apply = microtime(true);
        $importer = new OrionProductImporterService($this->table);

        // Stage 4: stage and apply the inventory payload.
        //
        // The importer owns all database work for this payload:
        // - normalize Orion inventory rows,
        // - recreate/truncate the inventory stage,
        // - update live Orion inventory/price fields,
        // - re-apply SIG dropship approval to the live table, and
        // - update existing normalized Orion offer rows from the same stage.
        $this->log('PHASE START: apply_inventory_array_to_live', [
            'inventory_rows' => $this->count_inventory_rows($inventory_data),
            'optimized_inventory_run' => $optimized_inventory_run ? 1 : 0,
            'requested_product_ids' => count($optimized_product_ids),
            'memory_kb' => $this->memory_kb(),
            'memory_peak_kb' => $this->memory_peak_kb(),
        ]);
        $stats = $importer->apply_inventory_array_to_live($inventory_data);
        $stats['optimized_inventory_run'] = $optimized_inventory_run ? 1 : 0;
        $stats['requested_product_ids'] = count($optimized_product_ids);
        $this->profile('apply_inventory_array_to_live', $t_apply, $stats);

        // Stage 5: persist the success markers only after the importer has
        // completed both live-table updates and distributor_offers projection.
        update_option('fflhub_orion_inventory_last_update', current_time('mysql'), false);
        update_option('fflhub_orion_inventory_last_update_count', (int) ($stats['rows_loaded'] ?? 0), false);
        delete_option('fflhub_orion_inventory_last_error');
        $this->clear_failure_cooldown();

        $this->log('Orion inventory update complete.', $stats);
        $this->finalize_run($t_start, $mem_start, 'SUCCESS', $stats);
    }

    private function make_client(int $timeoutSeconds): OrionApiClient
    {
        $connection_key = Options::get_distributor_option('orion', 'connection_key', '');
        $base_url = (string) apply_filters('fflhub_orion_api_base_url', OrionApiClient::DEFAULT_BASE_URL);

        return new OrionApiClient($connection_key, $base_url, $timeoutSeconds);
    }

    private function get_timeout_seconds(): int
    {
        $timeout_seconds = (int) apply_filters(
            'fflhub_orion_inventory_timeout_seconds',
            self::DEFAULT_TIMEOUT_SECONDS
        );

        return max(10, min(180, $timeout_seconds));
    }

    private function optimized_inventory_run_enabled(): bool
    {
        // Default is intentionally false. The full inventory run is the safest
        // source-of-truth path; optimized mode is a performance switch that can
        // be enabled once normalized Orion offer rows are known to be complete.
        return Options::get_distributor_option('orion', 'optimized_inventory_run', '0') === '1';
    }

    /**
     * @param array<string,mixed> $inventoryData
     */
    private function count_inventory_rows(array $inventoryData): int
    {
        if (isset($inventoryData['product_inventory']) && is_array($inventoryData['product_inventory'])) {
            return count($inventoryData['product_inventory']);
        }

        return count($inventoryData);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function active_failure_cooldown(): ?array
    {
        if (!function_exists('get_transient')) {
            return null;
        }

        $cooldown = get_transient(self::FAILURE_COOLDOWN_TRANSIENT);
        if (!is_array($cooldown)) {
            return null;
        }

        $until = (int) ($cooldown['until'] ?? 0);
        $now = time();
        if ($until <= $now) {
            $this->clear_failure_cooldown();
            return null;
        }

        return [
            'skip_for_sec' => max(0, $until - $now),
            'failed_at' => (string) ($cooldown['failed_at'] ?? ''),
            'status' => (int) ($cooldown['status'] ?? 0),
            'error' => (string) ($cooldown['error'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $inventory
     */
    private function set_failure_cooldown(array $inventory): void
    {
        if (!function_exists('set_transient') || !$this->is_cooldown_worthy_failure($inventory)) {
            return;
        }

        $cooldown_seconds = $this->get_failure_cooldown_seconds();
        if ($cooldown_seconds <= 0) {
            return;
        }

        $payload = [
            'until' => time() + $cooldown_seconds,
            'failed_at' => current_time('mysql'),
            'status' => (int) ($inventory['status'] ?? 0),
            'error' => (string) ($inventory['error'] ?? ''),
        ];

        set_transient(self::FAILURE_COOLDOWN_TRANSIENT, $payload, $cooldown_seconds);
        $this->log('Orion inventory API failure cooldown armed.', [
            'cooldown_sec' => $cooldown_seconds,
            'status' => $payload['status'],
            'error' => $payload['error'],
        ]);
    }

    private function clear_failure_cooldown(): void
    {
        if (function_exists('delete_transient')) {
            delete_transient(self::FAILURE_COOLDOWN_TRANSIENT);
        }
    }

    /**
     * @param array<string,mixed> $inventory
     */
    private function is_cooldown_worthy_failure(array $inventory): bool
    {
        $status = (int) ($inventory['status'] ?? 0);
        $error = strtolower((string) ($inventory['error'] ?? ''));

        return $status === 0
            || strpos($error, 'timed out') !== false
            || strpos($error, 'timeout') !== false
            || strpos($error, 'could not resolve') !== false
            || strpos($error, 'couldn\'t connect') !== false;
    }

    private function get_failure_cooldown_seconds(): int
    {
        $cooldown_seconds = (int) apply_filters(
            'fflhub_orion_inventory_failure_cooldown_seconds',
            self::FAILURE_COOLDOWN_SECONDS
        );

        return max(0, min(3600, $cooldown_seconds));
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function cron_logger(): CronRunLogger
    {
        return CronRunLogger::create(self::DEBUG_FLAG, self::LOG_PREFIX);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        $this->cron_logger()->log($message, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $this->cron_logger()->profile($label, $t0, $ctx, true, true);
    }

    private function memory_kb(): int
    {
        return $this->cron_logger()->memoryKb();
    }

    private function memory_peak_kb(): int
    {
        return $this->cron_logger()->memoryPeakKb();
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function finalize_run(float $t_start, int $mem_start, string $status, array $ctx = []): void
    {
        $this->cron_logger()->finishWithContextSummary($t_start, $mem_start, $status, $ctx, true);
    }
}
