<?php

namespace FFLHub\Distributor\Services\Lipseys\Cron;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Settings\Options;

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
        $mem_start = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;

        $log_timing = function (string $label, float $t0): void {
            $elapsed_ms = (microtime(true) - $t0) * 1000;
            $this->log_debug(
                sprintf(
                    "[FFLHub][Lipsey's Inventory Cron] %s took %.2f ms",
                    $label,
                    $elapsed_ms
                )
            );
        };

        $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ---- RUN START ----");
        if ($mem_start > 0) {
            $this->log_debug(
                sprintf(
                    "[FFLHub][Lipsey's Inventory Cron] PHP PID=%d, memory_start=%d KB",
                    function_exists('getmypid') ? getmypid() : 0,
                    (int) round($mem_start / 1024)
                )
            );
        }

        // Credentials via centralized Options helper.
        $t_creds         = microtime(true);
        $dealer_email    = trim(Options::get_distributor_option('lipseys', 'dealer_email', ''));
        $dealer_password = trim(Options::get_distributor_option('lipseys', 'dealer_password', ''));

        if ($dealer_email === '' || $dealer_password === '') {
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ERROR: dealer_email or dealer_password not set.");
            $log_timing('Credentials retrieval (failed)', $t_creds);
            $log_timing('Total cron run (credentials failed)', $t_start);
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }
        $log_timing('Credentials retrieval', $t_creds);

        // Client creation.
        $t_client = microtime(true);
        try {
            $client = new \lipseys\ApiIntegration\LipseysClient(
                (string) $dealer_email,
                (string) $dealer_password
            );
        } catch (\Throwable $e) {
            $this->log_debug(
                "[FFLHub][Lipsey's Inventory Cron] ERROR: exception creating LipseysClient: " . $e->getMessage()
            );
            $log_timing('Client creation (failed)', $t_client);
            $log_timing('Total cron run (client failed)', $t_start);
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }
        $log_timing('Client creation', $t_client);

        // PricingAndQuantity().
        $t_paq = microtime(true);
        try {
            $result = $client->PricingAndQuantity();
        } catch (\Throwable $e) {
            $this->log_debug(
                "[FFLHub][Lipsey's Inventory Cron] ERROR: exception calling PricingAndQuantity(): " . $e->getMessage()
            );
            $log_timing('PricingAndQuantity() call (failed)', $t_paq);
            $log_timing('Total cron run (PricingAndQuantity failed)', $t_start);
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }
        $log_timing('PricingAndQuantity() call', $t_paq);

        if (! is_array($result)) {
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ERROR: PricingAndQuantity() did not return an array.");
            $log_timing('Total cron run (bad result)', $t_start);
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }

        // Validate.
        $t_validate = microtime(true);
        $success    = isset($result['success']) ? (bool) $result['success'] : false;
        $authorized = isset($result['authorized']) ? (bool) $result['authorized'] : false;

        if (! $success || ! $authorized) {
            $errors = isset($result['errors']) && is_array($result['errors'])
                ? implode('; ', array_map('strval', $result['errors']))
                : '';
            $this->log_debug(
                "[FFLHub][Lipsey's Inventory Cron] ERROR: API response not successful/authorized. "
                    . 'success=' . ($success ? '1' : '0')
                    . ' authorized=' . ($authorized ? '1' : '0')
                    . ' errors=' . $errors
            );
            $log_timing('Response validation (failed)', $t_validate);
            $log_timing('Total cron run (validation failed)', $t_start);
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }

        if (! isset($result['data']) || ! is_array($result['data'])) {
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ERROR: response missing data object.");
            $log_timing('Response validation (missing data)', $t_validate);
            $log_timing('Total cron run (missing data)', $t_start);
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }

        $data = $result['data'];

        if (isset($data['nextUpdate'])) {
            update_option(
                'fflhub_lipseys_pricing_quantity_next_update',
                (string) $data['nextUpdate']
            );
        }

        if (! isset($data['items']) || ! is_array($data['items'])) {
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ERROR: data.items missing or not array.");
            $log_timing('Response validation (items missing)', $t_validate);
            $log_timing('Total cron run (items missing)', $t_start);
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }

        $items       = $data['items'];
        $items_count = count($items);

        if (empty($items)) {
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ERROR: data.items is empty.");
            $log_timing('Response validation (empty items)', $t_validate);
            $log_timing('Total cron run (empty items)', $t_start);
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }

        $log_timing(
            "Response validation + items extraction (count={$items_count})",
            $t_validate
        );

        $table_name = $this->table->get_live_table_name();
        if (! is_string($table_name) || $table_name === '') {
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ERROR: could not resolve live table name.");
            $log_timing('Total cron run (no table)', $t_start);
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $t_loop = microtime(true);

        // Normalize only the delta fields we actually want to update.
        // Keyed by lipseys_item_number.
        $rows = array();

        foreach ($items as $item) {
            if (! is_array($item)) {
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

        if (empty($rows)) {
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ERROR: no usable items after normalization.");
            $log_timing('Update loop (empty normalized rows)', $t_loop);
            $log_timing('Total cron run (no rows)', $t_start);
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }

        $this->log_debug(
            sprintf(
                "[FFLHub][Lipsey's Inventory Cron] Normalized items: input_items=%d, normalized_rows=%d",
                (int) $items_count,
                (int) count($rows)
            )
        );

        // Transaction via table helper.
        $this->table->begin_transaction();

        $rows_changed_total = 0;
        $rows_matched_total = 0;

        try {
            $batch_size = 200;
            $batches    = array_chunk($rows, $batch_size, true);

            foreach ($batches as $batch_index => $batch) {
                if (empty($batch)) {
                    continue;
                }

                // Matched rows (truth), vs changed rows (wpdb/query result).
                $item_numbers = array_keys($batch);
                $matched_rows = $this->count_lipseys_matched_rows($table_name, $item_numbers);
                $rows_matched_total += $matched_rows;

                $sql = $this->build_lipseys_case_update_sql($table_name, $batch);
                if ($sql === '') {
                    $this->log_debug(
                        sprintf(
                            "[FFLHub][Lipsey's Inventory Cron] Batch %d skipped (empty SQL) (batch_items=%d, matched_rows=%d)",
                            (int) ($batch_index + 1),
                            (int) count($batch),
                            (int) $matched_rows
                        )
                    );
                    continue;
                }

                $q = $wpdb->query($sql);

                if ($q === false) {
                    $this->log_debug(
                        sprintf(
                            "[FFLHub][Lipsey's Inventory Cron] SQL ERROR (batch %d): %s",
                            (int) ($batch_index + 1),
                            (string) $wpdb->last_error
                        )
                    );
                    continue;
                }

                // Note: wpdb->query() returns "changed rows" (not "matched rows").
                $changed_rows = (int) $q;
                $rows_changed_total += $changed_rows;

                $this->log_debug(
                    sprintf(
                        "[FFLHub][Lipsey's Inventory Cron] Batch %d updated (batch_items=%d, matched_rows=%d, changed_rows=%d)",
                        (int) ($batch_index + 1),
                        (int) count($batch),
                        (int) $matched_rows,
                        (int) $changed_rows
                    )
                );
            }

            $this->table->commit_transaction();
        } catch (\Throwable $e) {
            $this->table->rollback_transaction();

            $this->log_debug(
                "[FFLHub][Lipsey's Inventory Cron] ERROR: exception during batched updates, rolled back transaction: "
                    . $e->getMessage()
            );

            $log_timing('Update loop (failed)', $t_loop);
            $log_timing('Total cron run (update loop exception)', $t_start);
            $this->log_debug("[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }

        $t_loop_ms     = (microtime(true) - $t_loop) * 1000;
        $items_per_sec = $t_loop_ms > 0 ? ($items_count / ($t_loop_ms / 1000)) : 0;

        $this->log_debug(
            sprintf(
                "[FFLHub][Lipsey's Inventory Cron] Batched DB updates took %.2f ms (items=%d, matched_rows_total=%d, changed_rows_total=%d, ~%.0f items/sec)",
                $t_loop_ms,
                (int) $items_count,
                (int) $rows_matched_total,
                (int) $rows_changed_total,
                (float) $items_per_sec
            )
        );

        update_option(
            'fflhub_lipseys_pricing_quantity_last_sync',
            current_time('mysql')
        );

        // Keep existing meaning for the stored count: "changed rows" total.
        update_option(
            'fflhub_lipseys_pricing_quantity_last_sync_count',
            (int) $rows_changed_total
        );

        // Optional extra visibility: matched rows total (not required, but helpful).
        update_option(
            'fflhub_lipseys_pricing_quantity_last_sync_matched_count',
            (int) $rows_matched_total
        );

        $log_timing('Total cron run', $t_start);

        $mem_end = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
        if ($mem_start > 0 && $mem_end > 0) {
            $this->log_debug(
                sprintf(
                    "[FFLHub][Lipsey's Inventory Cron] Memory usage summary: start=%d KB, end=%d KB, delta=%+d KB",
                    (int) round($mem_start / 1024),
                    (int) round($mem_end / 1024),
                    (int) round(($mem_end - $mem_start) / 1024)
                )
            );
        }

        $this->log_debug(
            sprintf(
                "[FFLHub][Lipsey's Inventory Cron] ---- RUN END (SUCCESS, changed_rows_total %d (matched %d) across %d items) ----",
                (int) $rows_changed_total,
                (int) $rows_matched_total,
                (int) $items_count
            )
        );
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
     * @param string        $table_name
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
        if (! isset($item[$key])) {
            return null;
        }
        $val = $item[$key];
        return is_numeric($val) ? (int) $val : null;
    }

    private function to_decimal_or_null(array $item, string $key): ?float
    {
        if (! isset($item[$key])) {
            return null;
        }
        $val = $item[$key];
        return is_numeric($val) ? (float) $val : null;
    }

    private function to_bool_flag(array $item, string $key): int
    {
        if (! isset($item[$key])) {
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

    /**
     * All cron logging (including "ERROR: ...") is gated behind this constant.
     */
    private function log_debug(string $message): void
    {
        if (! defined('FFLHUB_CRON_DEBUG') || FFLHUB_CRON_DEBUG !== true) {
            return;
        }

        error_log($message);
    }
}
