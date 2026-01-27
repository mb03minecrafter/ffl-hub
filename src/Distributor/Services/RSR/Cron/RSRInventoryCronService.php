<?php

namespace FFLHub\Distributor\Services\RSR\Cron;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Distributor\Services\RSR\RSRFTPService;
use FFLHub\Settings\Options;

/**
 * Cron job for real-time inventory updates using RSR's IM-QTY-CSV.csv file.
 *
 * Runs every 5 minutes:
 *  - downloads IM-QTY-CSV.csv via FTP to uploads/fflhub-rsr/
 *  - parses it (RSR Stock Number, Quantity)
 *  - updates inventory_quantity in the LIVE fulfillment table.
 */
final class RSRInventoryCronService extends AbstractTableCronService
{

    /**
     * Cron hook name for RSR inventory refresh.
     */
    public const CRON_HOOK = 'fflhub_rsr_pricing_quantity_update';

    /**
     * Inject the double-buffered fulfillment table.
     */
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
        return 5 * MINUTE_IN_SECONDS;
    }


    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    /**
     * Delay before first run (keeps your old 2-minute initial delay).
     */
    protected function get_initial_delay_seconds(): int
    {
        return 2 * MINUTE_IN_SECONDS;
    }

    /**
     * Cron callback:
     *  1) Download IM-QTY-CSV.csv from RSR FTP to uploads.
     *  2) Parse it and update inventory_quantity in the live table.
     */
    public function run(): void
    {
        $t_start   = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $log_timing = function (string $label, float $t0) use ($t_start): void {
            $elapsed_ms = (microtime(true) - $t0) * 1000;
            $this->log_debug(
                sprintf(
                    "[FFLHub][RSR Inventory Cron] %s took %.2f ms",
                    $label,
                    $elapsed_ms
                )
            );
        };

        $this->log_debug("[FFLHub][RSR Inventory Cron] ---- RUN START ----");

        if ($mem_start > 0) {
            $this->log_debug(
                sprintf(
                    "[FFLHub][RSR Inventory Cron] PHP PID=%d, memory_start=%d KB",
                    function_exists('getmypid') ? getmypid() : 0,
                    (int) round($mem_start / 1024)
                )
            );
        }

        // 0) Get FTP credentials.
        $t_creds = microtime(true);
        $creds   = $this->get_ftp_credentials();

        if (! is_array($creds)) {
            $log_timing('Credentials retrieval (failed)', $t_creds);
            $log_timing('Total cron run (credentials failed)', $t_start);
            $this->log_debug("[FFLHub][RSR Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }

        $log_timing('Credentials retrieval', $t_creds);

        $host     = $creds['host'];
        $username = $creds['username'];
        $password = $creds['password'];
        $use_ssl  = $creds['use_ssl'];

        // Local path for the quantity file.
        $uploads  = wp_upload_dir();
        $base_dir = trailingslashit($uploads['basedir']) . 'fflhub-rsr';

        if (! wp_mkdir_p($base_dir)) {
            $this->log_debug("[FFLHub][RSR Inventory Cron] ERROR: failed to create base directory " . $base_dir);
            $log_timing('Total cron run (mkdir failed)', $t_start);
            $this->log_debug("[FFLHub][RSR Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }

        $file_name  = 'IM-QTY-CSV.csv';
        $local_path = trailingslashit($base_dir) . $file_name;

        // Remote path on RSR FTP.
        $remote_path = '/ftpdownloads/IM-QTY-CSV.csv';

        // 1) Download the file via FTP.
        $t_ftp = microtime(true);

        $ftp = new RSRFTPService($host, $username, $password, $use_ssl);
        if (! $ftp->is_connected()) {
            update_option('fflhub_rsr_inventory_last_download_error', current_time('mysql'));
            $this->log_debug("[FFLHub][RSR Inventory Cron] ERROR: FTP connection not available.");
            $log_timing('FTP connection (failed)', $t_ftp);
            $log_timing('Total cron run (FTP connection failed)', $t_start);
            $this->log_debug("[FFLHub][RSR Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }

        $ok = $ftp->download_file($remote_path, $local_path);

        $log_timing('FTP download', $t_ftp);

        if (! $ok) {
            update_option('fflhub_rsr_inventory_last_download_error', current_time('mysql'));
            $this->log_debug("[FFLHub][RSR Inventory Cron] ERROR: FTP download failed for " . $remote_path);
            $log_timing('Total cron run (download failed)', $t_start);
            $this->log_debug("[FFLHub][RSR Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }

        update_option('fflhub_rsr_inventory_last_download', current_time('mysql'));
        delete_option('fflhub_rsr_inventory_last_download_error');

        // 2) Apply inventory updates to live table.
        $t_apply = microtime(true);
        $processed_rows = 0;

        try {
            $processed_rows = $this->apply_inventory_updates_from_file($local_path);
        } catch (\Throwable $e) {
            $this->log_debug("[FFLHub][RSR Inventory Cron] ERROR: exception applying inventory updates: " . $e->getMessage());
            $log_timing('Apply inventory updates (failed)', $t_apply);
            $log_timing('Total cron run (apply failed)', $t_start);
            $this->log_debug("[FFLHub][RSR Inventory Cron] ---- RUN END (ERROR) ----");
            return;
        }

        $log_timing('Apply inventory updates', $t_apply);

        update_option('fflhub_rsr_inventory_last_update', current_time('mysql'));
        update_option('fflhub_rsr_inventory_last_update_count', $processed_rows);

        $log_timing('Total cron run', $t_start);

        $mem_end = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
        if ($mem_start > 0 && $mem_end > 0) {
            $this->log_debug(
                sprintf(
                    "[FFLHub][RSR Inventory Cron] Memory usage summary: start=%d KB, end=%d KB, delta=%+d KB",
                    (int) round($mem_start / 1024),
                    (int) round($mem_end / 1024),
                    (int) round(($mem_end - $mem_start) / 1024)
                )
            );
        }

        $this->log_debug(
            sprintf(
                "[FFLHub][RSR Inventory Cron] ---- RUN END (SUCCESS, processed %d input rows) ----",
                (int) $processed_rows
            )
        );
    }


    /**
     * Parse IM-QTY-CSV.csv and update inventory_quantity for rows in the live table.
     *
     * Actual format (from sample):
     *   17912WH-1-SBL-R,0000012
     *   17913WH-1-SBL-A,0000011
     *   ...
     *
     * No header line, comma-separated, 2 columns:
     *   [0] RSR Stock Number (rsr_stock_number)
     *   [1] Quantity (zero-padded string)
     *
     * @param string $file_path
     * @return int Number of input rows processed (not exact DB rows changed).
     */
    protected function apply_inventory_updates_from_file(string $file_path): int
    {
        global $wpdb;

        $t_start       = microtime(true);
        $t_parse_total = 0.0;
        $t_flush_total = 0.0;

        if (! file_exists($file_path) || ! is_readable($file_path)) {
            $this->log_debug("[FFLHub][RSR Inventory Cron] IM-QTY-CSV file missing or unreadable at " . $file_path);
            return 0;
        }

        $live_table = $this->table->get_live_table_name();

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $handle = fopen($file_path, 'r');
        if (! $handle) {
            $this->log_debug("[FFLHub][RSR Inventory Cron] could not fopen " . $file_path);
            return 0;
        }

        $total_rows    = 0;      // input rows processed (matches your existing semantics)
        $batch_size    = 200;
        $batch_updates = array();

        $wpdb->query('START TRANSACTION');

        try {
            $flush_batch = function () use (&$batch_updates, &$total_rows, $live_table, $wpdb, &$t_flush_total) {
                if (empty($batch_updates)) {
                    return;
                }

                $t0 = microtime(true);

                $when_sql        = array();
                $case_values     = array();
                $in_placeholders = array();
                $in_values       = array();

                foreach ($batch_updates as $row) {
                    $rsr_stock_number = $row['rsr_stock_number'];
                    $qty_str          = $row['qty'];

                    $when_sql[]    = 'WHEN %s THEN %s';
                    $case_values[] = $rsr_stock_number;
                    $case_values[] = $qty_str;

                    $in_placeholders[] = '%s';
                    $in_values[]       = $rsr_stock_number;
                }

                $all_values = array_merge($case_values, $in_values);

                $sql = "
                UPDATE {$live_table}
                SET inventory_quantity = CASE rsr_stock_number
                    " . implode("\n                    ", $when_sql) . "
                END
                WHERE rsr_stock_number IN (" . implode(', ', $in_placeholders) . ')
            ';

                $prepared = $wpdb->prepare($sql, $all_values);
                $result   = $wpdb->query($prepared);

                if ($result === false) {
                    // Let caller decide whether to rollback; throwing here keeps the transaction safe.
                    throw new \RuntimeException('batch UPDATE failed: ' . (string) $wpdb->last_error);
                }

                $total_rows   += count($batch_updates);
                $batch_updates = array();

                $t_flush_total += (microtime(true) - $t0);
            };

            while (($line = fgets($handle)) !== false) {
                $t0 = microtime(true);

                $line = trim($line);
                if ($line === '') {
                    $t_parse_total += (microtime(true) - $t0);
                    continue;
                }

                $cols = explode(',', $line);
                if (count($cols) < 2) {
                    $t_parse_total += (microtime(true) - $t0);
                    continue;
                }

                $rsr_stock_number_raw = trim((string) $cols[0]);
                $qty_raw              = trim((string) $cols[1]);

                if ($rsr_stock_number_raw === '') {
                    $t_parse_total += (microtime(true) - $t0);
                    continue;
                }

                if ($qty_raw === '') {
                    $qty_int = 0;
                } else {
                    $digits  = preg_replace('/[^0-9]/', '', $qty_raw);
                    $qty_int = ($digits === '') ? 0 : (int) $digits;
                }

                $qty_str = (string) $qty_int;

                $batch_updates[] = array(
                    'rsr_stock_number' => $rsr_stock_number_raw,
                    'qty'              => $qty_str,
                );

                $t_parse_total += (microtime(true) - $t0);

                if (count($batch_updates) >= $batch_size) {
                    $flush_batch();
                }
            }

            fclose($handle);

            // Flush remaining
            $flush_batch();

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            fclose($handle);
            $wpdb->query('ROLLBACK');
            $this->log_debug("[FFLHub][RSR Inventory Cron] ERROR: rolled back transaction: " . $e->getMessage());
            throw $e;
        }

        $t_total_ms = (microtime(true) - $t_start) * 1000;
        $t_parse_ms = $t_parse_total * 1000;
        $t_flush_ms = $t_flush_total * 1000;

        $this->log_debug(
            sprintf(
                '[FFLHub][RSR Inventory Cron] apply_inventory_updates_from_file(): total=%.2f ms, parse=%.2f ms, db_flush=%.2f ms, input_rows=%d',
                $t_total_ms,
                $t_parse_ms,
                $t_flush_ms,
                $total_rows
            )
        );

        return $total_rows;
    }


    /**
     * Retrieve and validate FTP credentials from RSR distributor settings.
     *
     * Uses the centralized Options helper so we don't hard-code option names.
     *
     * @return array{host:string,username:string,password:string,use_ssl:bool}|null
     */
    public function get_ftp_credentials(): ?array
    {
        // Values come from RSRModule::settings_schema() via SettingsRegistrar.
        $host      = Options::get_distributor_option('rsr', 'ftp_host', '');
        $username  = Options::get_distributor_option('rsr', 'ftp_username', '');
        $password  = Options::get_distributor_option('rsr', 'ftp_password', '');
        $use_ssl_s = Options::get_distributor_option('rsr', 'ftp_use_ssl', '');

        $host     = trim($host);
        $username = trim($username);
        $password = trim($password);
        $use_ssl  = ($use_ssl_s !== '');

        if ($host === '' || $username === '' || $password === '') {
            $this->log_debug(
                sprintf(
                    '[FFLHub][RSR Inventory Cron] Missing FTP credentials (host: %s, user: %s).',
                    $host !== '' ? 'set' : 'empty',
                    $username !== '' ? 'set' : 'empty'
                )
            );
            return null;
        }

        return array(
            'host'     => $host,
            'username' => $username,
            'password' => $password,
            'use_ssl'  => $use_ssl,
        );
    }


    private function log_debug(string $message): void
    {
        if (! defined('FFLHUB_CRON_DEBUG') || FFLHUB_CRON_DEBUG !== true) {
            return;
        }

        error_log($message);
    }
}
