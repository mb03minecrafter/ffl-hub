<?php

namespace FFLHub\Distributor\Services\Lipseys\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * WP-Cron job to refresh Lipsey's pricing/quantity feed hourly
 * and update relevant fields in the LIVE Lipsey's fulfillment table.
 */
final class LipseysInventoryCronService extends AbstractTableCronService
{
    /**
     * Cron hook name for Lipsey's pricing/quantity refresh.
     */
    public const CRON_HOOK = 'fflhub_lipseys_pricing_quantity_update';

    /**
     * Debug gate constant (define('FFLHUB_CRON_DEBUG', true);).
     */
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';

    /**
     * Log prefix.
     */
    private const LOG_PREFIX = '[FFLHUB][LipseysInventoryCron]';

    public function __construct(DoubleBufferedFulfillmentTable $table)
    {
        parent::__construct($table);
    }

    /**
     * Unique cron hook name.
     */
    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    /**
     * Interval length in seconds.
     */
    protected function get_interval_seconds(): int
    {
        return HOUR_IN_SECONDS;
    }

    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    /**
     * Delay before first run (keeps your old 5-minute initial delay).
     */
    protected function get_initial_delay_seconds(): int
    {
        return 5 * MINUTE_IN_SECONDS;
    }

    /**
     * Main cron callback:
     *  - Calls PricingAndQuantity() on Lipseys API
     *  - Validates response
     *  - Applies price/qty deltas to the LIVE table
     */
    public function run(): void
    {
        global $wpdb;

        $t_start   = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $this->log('---- RUN START ----', [
            'pid'          => function_exists('getmypid') ? (int) getmypid() : 0,
            'memory_kb'    => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
            'hook'         => self::CRON_HOOK,
            'group'        => $this->get_action_group(),
            'interval_sec' => $this->get_interval_seconds(),
        ]);

        // Credentials via centralized Options helper.
        $t_creds         = microtime(true);
        $dealer_email    = trim((string) Options::get_distributor_option('lipseys', 'dealer_email', ''));
        $dealer_password = trim((string) Options::get_distributor_option('lipseys', 'dealer_password', ''));

        $this->profile('Credentials retrieval', $t_creds, [
            'has_email'    => ($dealer_email !== ''),
            'has_password' => ($dealer_password !== ''),
        ]);

        if ($dealer_email === '' || $dealer_password === '') {
            $this->log('ERROR: dealer_email or dealer_password not set.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing credentials)');
            return;
        }

        // Client creation.
        $t_client = microtime(true);
        try {
            $client = new \lipseys\ApiIntegration\LipseysClient(
                (string) $dealer_email,
                (string) $dealer_password
            );
        } catch (\Throwable $e) {
            $this->log('ERROR: exception creating LipseysClient', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Client creation (failed)', $t_client);
            $this->finalize_run($t_start, $mem_start, 'ERROR (client creation)');
            return;
        }
        $this->profile('Client creation', $t_client);

        // PricingAndQuantity().
        $t_paq = microtime(true);
        try {
            $result = $client->PricingAndQuantity();
        } catch (\Throwable $e) {
            $this->log('ERROR: exception calling PricingAndQuantity()', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('PricingAndQuantity() call (failed)', $t_paq);
            $this->finalize_run($t_start, $mem_start, 'ERROR (PricingAndQuantity exception)');
            return;
        }
        $this->profile('PricingAndQuantity() call', $t_paq);

        if (!is_array($result)) {
            $this->log('ERROR: PricingAndQuantity() did not return an array.', [
                'type' => is_object($result) ? get_class($result) : gettype($result),
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (bad result type)');
            return;
        }

        // Validate response and extract items.
        $t_validate = microtime(true);

        $success    = isset($result['success']) ? (bool) $result['success'] : false;
        $authorized = isset($result['authorized']) ? (bool) $result['authorized'] : false;

        if (!$success || !$authorized) {
            $errors = (isset($result['errors']) && is_array($result['errors']))
                ? implode('; ', array_map('strval', $result['errors']))
                : '';

            $this->log('ERROR: API response not successful/authorized', [
                'success'    => $success ? 1 : 0,
                'authorized' => $authorized ? 1 : 0,
                'errors'     => $errors,
            ]);

            $this->profile('Response validation (failed)', $t_validate);
            $this->finalize_run($t_start, $mem_start, 'ERROR (validation failed)');
            return;
        }

        if (!isset($result['data']) || !is_array($result['data'])) {
            $this->log('ERROR: response missing data object.');
            $this->profile('Response validation (missing data)', $t_validate);
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing data)');
            return;
        }

        $data = $result['data'];

        if (isset($data['nextUpdate'])) {
            update_option('fflhub_lipseys_pricing_quantity_next_update', (string) $data['nextUpdate']);
        }

        if (!isset($data['items']) || !is_array($data['items'])) {
            $this->log('ERROR: data.items missing or not array.');
            $this->profile('Response validation (items missing)', $t_validate);
            $this->finalize_run($t_start, $mem_start, 'ERROR (items missing)');
            return;
        }

        $items       = $data['items'];
        $items_count = count($items);

        if (empty($items)) {
            $this->log('ERROR: data.items is empty.');
            $this->profile('Response validation (empty items)', $t_validate);
            $this->finalize_run($t_start, $mem_start, 'ERROR (empty items)');
            return;
        }

        $mem_after_extract = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        $this->profile('Response validation + items extraction', $t_validate, [
            'count'         => (int) $items_count,
            'memory_kb_now' => $mem_after_extract > 0 ? (int) round($mem_after_extract / 1024) : 0,
        ]);

        $table_name = (string) $this->table->get_live_table_name();
        if ($table_name === '') {
            $this->log('ERROR: could not resolve live table name.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (no table)');
            return;
        }

        // Normalize only the delta fields we actually want to update.
        // Keyed by lipseys_item_number.
        $t_normalize = microtime(true);
        $rows = array();

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $item_number = isset($item['itemNumber']) ? trim((string) $item['itemNumber']) : '';
            if ($item_number === '') {
                continue;
            }

            $quantity     = $this->to_int_or_null($item, 'quantity');
            $allocated    = (bool) $this->to_bool_flag($item, 'allocated');
            $currentPrice = $this->to_decimal_or_null($item, 'currentPrice');
            $retailMap    = $this->to_decimal_or_null($item, 'retailMap');

            $rows[$item_number] = array(
                // Keep your existing data typing conventions (strings in table)
                'inventory_quantity' => $quantity !== null ? (string) $quantity : '0',
                'allocation_status'  => $allocated ? 'Y' : '',
                'distributor_price'  => $currentPrice !== null ? (string) $currentPrice : '',
                'retail_map'         => $retailMap !== null ? (string) $retailMap : '',
            );
        }

        $this->profile('Normalize items → delta rows', $t_normalize, [
            'input_items'      => (int) $items_count,
            'normalized_rows'  => (int) count($rows),
        ]);

        if (empty($rows)) {
            $this->log('ERROR: no usable items after normalization.', [
                'input_items' => (int) $items_count,
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (no normalized rows)');
            return;
        }

        // Transaction via table helper.
        $t_updates = microtime(true);

        $this->table->begin_transaction();

        $rows_changed_total = 0;
        $rows_matched_total = 0;

        // Batch timing aggregation (keeps logs tight).
        $batch_count = 0;
        $batch_ms_total = 0.0;
        $batch_ms_max = 0.0;

        try {
            $batch_size = 200;
            $batches    = array_chunk($rows, $batch_size, true);

            foreach ($batches as $batch_index => $batch) {
                if (empty($batch)) {
                    continue;
                }

                $batch_count++;
                $t_batch = microtime(true);

                // Matched rows (truth), vs changed rows (wpdb/query result).
                $item_numbers = array_keys($batch);
                $matched_rows = $this->count_lipseys_matched_rows($table_name, $item_numbers);
                $rows_matched_total += $matched_rows;

                $sql = $this->build_lipseys_case_update_sql($table_name, $batch);
                if ($sql === '') {
                    $this->log('Batch skipped (empty SQL)', [
                        'batch'        => (int) ($batch_index + 1),
                        'batch_items'  => (int) count($batch),
                        'matched_rows' => (int) $matched_rows,
                    ]);
                    continue;
                }

                $q = $wpdb->query($sql);

                if ($q === false) {
                    $this->log('SQL ERROR during batch', [
                        'batch'       => (int) ($batch_index + 1),
                        'batch_items' => (int) count($batch),
                        'matched_rows'=> (int) $matched_rows,
                        'last_error'  => (string) $wpdb->last_error,
                    ]);
                    continue;
                }

                // Note: wpdb->query() returns "changed rows" (not "matched rows").
                $changed_rows = (int) $q;
                $rows_changed_total += $changed_rows;

                $batch_ms = (microtime(true) - $t_batch) * 1000.0;
                $batch_ms_total += $batch_ms;
                if ($batch_ms > $batch_ms_max) {
                    $batch_ms_max = $batch_ms;
                }

                // Keep per-batch logging concise (still useful when this job is the thing you're diagnosing).
                $this->log('Batch updated', [
                    'batch'        => (int) ($batch_index + 1),
                    'batch_items'  => (int) count($batch),
                    'matched_rows' => (int) $matched_rows,
                    'changed_rows' => (int) $changed_rows,
                    'elapsed_ms'   => number_format($batch_ms, 2, '.', ''),
                ]);
            }

            $this->table->commit_transaction();
        } catch (\Throwable $e) {
            $this->table->rollback_transaction();

            $this->log('ERROR: exception during batched updates (rolled back transaction)', [
                'error' => $e->getMessage(),
            ]);

            $this->profile('Batched DB updates (failed)', $t_updates, [
                'items'               => (int) $items_count,
                'normalized_rows'     => (int) count($rows),
                'matched_rows_total'  => (int) $rows_matched_total,
                'changed_rows_total'  => (int) $rows_changed_total,
                'batches_seen'        => (int) $batch_count,
            ]);

            $this->finalize_run($t_start, $mem_start, 'ERROR (update loop exception)');
            return;
        }

        $updates_ms     = (microtime(true) - $t_updates) * 1000.0;
        $items_per_sec  = $updates_ms > 0 ? ($items_count / ($updates_ms / 1000.0)) : 0.0;
        $avg_batch_ms   = $batch_count > 0 ? ($batch_ms_total / $batch_count) : 0.0;

        $this->profile('Batched DB updates', $t_updates, [
            'table'              => (string) $table_name,
            'items'              => (int) $items_count,
            'normalized_rows'    => (int) count($rows),
            'batch_size'         => 200,
            'batches'            => (int) $batch_count,
            'avg_batch_ms'       => number_format($avg_batch_ms, 2, '.', ''),
            'max_batch_ms'       => number_format($batch_ms_max, 2, '.', ''),
            'matched_rows_total' => (int) $rows_matched_total,
            'changed_rows_total' => (int) $rows_changed_total,
            'items_per_sec'      => number_format($items_per_sec, 0, '.', ''),
        ]);

        update_option('fflhub_lipseys_pricing_quantity_last_sync', current_time('mysql'));

        // Keep existing meaning for the stored count: "changed rows" total.
        update_option('fflhub_lipseys_pricing_quantity_last_sync_count', (int) $rows_changed_total);

        // Optional extra visibility: matched rows total.
        update_option('fflhub_lipseys_pricing_quantity_last_sync_matched_count', (int) $rows_matched_total);

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'items'              => (int) $items_count,
            'normalized_rows'    => (int) count($rows),
            'matched_rows_total' => (int) $rows_matched_total,
            'changed_rows_total' => (int) $rows_changed_total,
            'batches'            => (int) $batch_count,
        ]);
    }

    /**
     * Build a batched CASE-based UPDATE for Lipsey's live fulfillment table.
     *
     * @param string $table_name
     * @param array<string,array<string,string>> $batch Keyed by lipseys_item_number
     * @return string SQL (empty string if batch empty)
     */
    private function build_lipseys_case_update_sql(string $table_name, array $batch): string
    {
        if (empty($batch)) {
            return '';
        }

        $ids = array();

        $case_inventory_qty = '';
        $case_alloc_status  = '';
        $case_dist_price    = '';
        $case_retail_map    = '';

        foreach ($batch as $item_number => $vals) {
            $raw_id = trim((string) $item_number);
            if ($raw_id === '') {
                continue;
            }

            // Escape only at SQL build time.
            $id = esc_sql($raw_id);
            $ids[] = "'" . $id . "'";

            $case_inventory_qty .= " WHEN '{$id}' THEN '" . esc_sql((string) $vals['inventory_quantity']) . "'";
            $case_alloc_status  .= " WHEN '{$id}' THEN '" . esc_sql((string) $vals['allocation_status']) . "'";
            $case_dist_price    .= " WHEN '{$id}' THEN '" . esc_sql((string) $vals['distributor_price']) . "'";
            $case_retail_map    .= " WHEN '{$id}' THEN '" . esc_sql((string) $vals['retail_map']) . "'";
        }

        if (empty($ids)) {
            return '';
        }

        return "
        UPDATE {$table_name}
        SET
            inventory_quantity = CASE lipseys_item_number {$case_inventory_qty} ELSE inventory_quantity END,
            allocation_status  = CASE lipseys_item_number {$case_alloc_status}  ELSE allocation_status END,
            distributor_price  = CASE lipseys_item_number {$case_dist_price}    ELSE distributor_price END,
            retail_map         = CASE lipseys_item_number {$case_retail_map}    ELSE retail_map END
        WHERE lipseys_item_number IN (" . implode(',', $ids) . ")
    ";
    }

    /**
     * Count how many rows in the LIVE table match the given lipseys_item_number list.
     * This is "matched rows" (truth), separate from "changed rows" returned by UPDATE.
     *
     * @param string            $table_name
     * @param array<int,string> $ids Raw (unescaped) item numbers
     */
    private function count_lipseys_matched_rows(string $table_name, array $ids): int
    {
        global $wpdb;

        if (empty($ids)) {
            return 0;
        }

        // Trim + drop empties.
        $clean = array();
        foreach ($ids as $id) {
            $v = trim((string) $id);
            if ($v !== '') {
                $clean[] = $v;
            }
        }

        if (empty($clean)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($clean), '%s'));
        $sql          = "SELECT COUNT(*) FROM {$table_name} WHERE lipseys_item_number IN ($placeholders)";

        return (int) $wpdb->get_var($wpdb->prepare($sql, $clean));
    }

    private function to_int_or_null(array $item, string $key): ?int
    {
        if (!isset($item[$key])) {
            return null;
        }
        $val = $item[$key];
        return is_numeric($val) ? (int) $val : null;
    }

    private function to_decimal_or_null(array $item, string $key): ?float
    {
        if (!isset($item[$key])) {
            return null;
        }
        $val = $item[$key];
        return is_numeric($val) ? (float) $val : null;
    }

    private function to_bool_flag(array $item, string $key): int
    {
        if (!isset($item[$key])) {
            return 0;
        }

        $val = $item[$key];

        if (is_bool($val)) {
            return $val ? 1 : 0;
        }

        if (is_numeric($val)) {
            return ((int) $val) ? 1 : 0;
        }

        $str = strtolower(trim((string) $val));
        if (in_array($str, array('1', 'true', 'yes', 'y'), true)) {
            return 1;
        }

        return 0;
    }

    // --------------------------------------------------
    // Debug / profiling helpers (DebugLogUtil)
    // --------------------------------------------------

    /** @param array<string,mixed> $ctx */
    private function log(string $msg, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $msg);
            return;
        }

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $msg, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $elapsed_ms = (microtime(true) - $t0) * 1000.0;

        $ctx = array_merge($ctx, [
            // string avoids float-repr noise like 0.28999999998
            'elapsed_ms' => number_format($elapsed_ms, 2, '.', ''),
        ]);

        $this->log("PROFILE: {$label}", $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function finalize_run(float $t_start, int $mem_start, string $status, array $ctx = []): void
    {
        $this->profile('Total cron run', $t_start, [
            'status' => (string) $status,
        ]);

        $mem_end = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        if ($mem_start > 0 && $mem_end > 0) {
            $this->log('Memory usage summary', [
                'start_kb' => (int) round($mem_start / 1024),
                'end_kb'   => (int) round($mem_end / 1024),
                'delta_kb' => (int) round(($mem_end - $mem_start) / 1024),
            ]);
        }

        if (!empty($ctx)) {
            $this->log("---- RUN END ({$status}) ----", $ctx);
        } else {
            $this->log("---- RUN END ({$status}) ----");
        }
    }
}
