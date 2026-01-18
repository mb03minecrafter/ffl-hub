<?php

namespace FFLHub\Distributor\Services\Lipseys;

use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Service for importing Lipsey's catalog items into the STAGING table
 * of a double-buffered fulfillment table.
 */
class LipseysFulfillmentImporterService
{
    private DoubleBufferedFulfillmentTable $table;

    public function __construct(DoubleBufferedFulfillmentTable $table)
    {
        $this->table = $table;
    }

    /**
     * Import an array of item arrays into the staging table.
     *
     * @param array<int,array<string,mixed>> $items
     * @return int Number of rows successfully inserted.
     */
    public function import_items_array(array $items): int
    {
        $t_start   = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $log_timing = function (string $label, float $t0): void {
            $elapsed_ms = (microtime(true) - $t0) * 1000;
            $this->log_debug(
                sprintf(
                    '[FFLHub][Lipseys Import] %s took %.2f ms',
                    $label,
                    $elapsed_ms
                )
            );
        };

        $this->log_debug('[FFLHub][Lipseys Import] ---- IMPORT START ----');
        if ($mem_start > 0) {
            $this->log_debug(
                sprintf(
                    '[FFLHub][Lipseys Import] PHP PID=%d, memory_start=%d KB',
                    function_exists('getmypid') ? getmypid() : 0,
                    (int) round($mem_start / 1024)
                )
            );
        }

        // Start with a clean staging table via the table helper.
        $t_trunc = microtime(true);
        try {
            $this->table->truncate_staging();
        } catch (\Throwable $e) {
            $this->log_debug('[FFLHub][Lipseys Import] ERROR: truncate_staging() threw: ' . $e->getMessage());
            $log_timing('truncate_staging (failed)', $t_trunc);
            $log_timing('Total import (truncate failed)', $t_start);
            $this->log_memory_summary($mem_start);
            $this->log_debug('[FFLHub][Lipseys Import] ---- IMPORT END (ERROR) ----');
            return 0;
        }
        $log_timing('truncate_staging', $t_trunc);

        $parser              = new LipseysFulfillmentParser();
        $batch_size          = 250;
        $batch_rows          = [];
        $total_import        = 0;
        $skipped_missing_upc = 0;
        $skipped_dupe_upc    = 0;
        $seen_upcs           = [];

        $batch_flushes       = 0;
        $batch_failures      = 0;

        $t_parse_total = 0.0;
        $t_insert_total = 0.0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $t_parse = microtime(true);

            $row = $parser->parse_item($item);

            $t_parse_total += (microtime(true) - $t_parse);

            // parse_item() already filters out non-drop-ship items.
            if ($row === null) {
                continue;
            }

            $upc = isset($row['upc']) ? trim((string) $row['upc']) : '';
            if ($upc === '' || strcasecmp($upc, 'null') === 0) {
                $skipped_missing_upc++;
                continue;
            }

            // De-dupe by UPC (first wins).
            if (isset($seen_upcs[$upc])) {
                $skipped_dupe_upc++;
                continue;
            }
            $seen_upcs[$upc] = true;

            $batch_rows[] = $row;

            if (count($batch_rows) >= $batch_size) {
                $batch_flushes++;

                $t_ins = microtime(true);
                try {
                    $inserted = $this->table->insert_rows_into_staging($batch_rows);
                } catch (\Throwable $e) {
                    $batch_failures++;
                    $this->log_debug('[FFLHub][Lipseys Import] ERROR: insert_rows_into_staging() threw: ' . $e->getMessage());
                    $inserted = 0;
                }
                $t_insert_total += (microtime(true) - $t_ins);

                $total_import += (int) $inserted;
                $batch_rows = [];
            }
        }

        // Flush any remaining rows.
        if (! empty($batch_rows)) {
            $batch_flushes++;

            $t_ins = microtime(true);
            try {
                $inserted = $this->table->insert_rows_into_staging($batch_rows);
            } catch (\Throwable $e) {
                $batch_failures++;
                $this->log_debug('[FFLHub][Lipseys Import] ERROR: final insert_rows_into_staging() threw: ' . $e->getMessage());
                $inserted = 0;
            }
            $t_insert_total += (microtime(true) - $t_ins);

            $total_import += (int) $inserted;
        }

        if ($total_import > 0) {
            update_option('fflhub_lipseys_fulfillment_last_import', current_time('mysql'));
            update_option('fflhub_lipseys_fulfillment_last_import_count', (int) $total_import);
        }

        $this->log_debug(
            sprintf(
                '[FFLHub][Lipseys Import] import_items_array(): items_in=%d, rows_inserted=%d, skipped_missing_upc=%d, skipped_dupe_upc=%d, batch_flushes=%d, batch_failures=%d',
                count($items),
                (int) $total_import,
                (int) $skipped_missing_upc,
                (int) $skipped_dupe_upc,
                (int) $batch_flushes,
                (int) $batch_failures
            )
        );

        $this->log_debug(
            sprintf(
                '[FFLHub][Lipseys Import] Timing: parse_total=%.2f ms, insert_total=%.2f ms',
                $t_parse_total * 1000,
                $t_insert_total * 1000
            )
        );

        $log_timing('Total import', $t_start);
        $this->log_memory_summary($mem_start);
        $this->log_debug('[FFLHub][Lipseys Import] ---- IMPORT END (SUCCESS) ----');

        return (int) $total_import;
    }


    private function log_debug(string $message): void
    {
        if (! defined('FFLHUB_CRON_DEBUG') || FFLHUB_CRON_DEBUG !== true) {
            return;
        }

        error_log($message);
    }

    private function log_memory_summary(int $mem_start): void
    {
        $mem_end = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
        if ($mem_start > 0 && $mem_end > 0) {
            $this->log_debug(
                sprintf(
                    '[FFLHub][Lipseys Import] Memory usage summary: start=%d KB, end=%d KB, delta=%+d KB',
                    (int) round($mem_start / 1024),
                    (int) round($mem_end / 1024),
                    (int) round(($mem_end - $mem_start) / 1024)
                )
            );
        }
    }
}
