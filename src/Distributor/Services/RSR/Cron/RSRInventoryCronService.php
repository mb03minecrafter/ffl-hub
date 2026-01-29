<?php

namespace FFLHub\Distributor\Services\RSR\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Distributor\Services\RSR\RSRFTPService;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

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
     * Debug gate constant (define('FFLHUB_CRON_DEBUG', true);).
     */
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';

    /**
     * Log prefix.
     */
    private const LOG_PREFIX = '[FFLHUB][RSRInventoryCron]';

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

        // 0) Get FTP credentials.
        $t_creds = microtime(true);
        $creds   = $this->get_ftp_credentials();

        $this->profile('Credentials retrieval', $t_creds, [
            'ok'      => is_array($creds),
            'has_host'=> is_array($creds) ? (bool) ($creds['host'] ?? '') : false,
            'has_user'=> is_array($creds) ? (bool) ($creds['username'] ?? '') : false,
            'has_ssl' => is_array($creds) ? (bool) ($creds['use_ssl'] ?? false) : false,
        ]);

        if (!is_array($creds)) {
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing credentials)');
            return;
        }

        $host     = (string) $creds['host'];
        $username = (string) $creds['username'];
        $password = (string) $creds['password'];
        $use_ssl  = (bool) $creds['use_ssl'];

        // Local path for the quantity file.
        $uploads  = wp_upload_dir();
        $base_dir = trailingslashit($uploads['basedir']) . 'fflhub-rsr';

        $t_paths = microtime(true);

        if (!wp_mkdir_p($base_dir)) {
            $this->log('ERROR: failed to create base directory', [
                'base_dir' => (string) $base_dir,
            ]);
            $this->profile('Prepare local paths (mkdir failed)', $t_paths);
            $this->finalize_run($t_start, $mem_start, 'ERROR (mkdir failed)');
            return;
        }

        $file_name  = 'IM-QTY-CSV.csv';
        $local_path = trailingslashit($base_dir) . $file_name;

        // Remote path on RSR FTP.
        $remote_path = '/ftpdownloads/IM-QTY-CSV.csv';

        $this->profile('Prepare local paths', $t_paths, [
            'base_dir'     => (string) $base_dir,
            'remote_csv'   => (string) $remote_path,
            'local_csv'    => (string) $local_path,
            'live_table'   => (string) $this->table->get_live_table_name(),
        ]);

        // 1) Download the file via FTP.
        $t_ftp = microtime(true);

        $ftp = new RSRFTPService($host, $username, $password, $use_ssl);
        if (!$ftp->is_connected()) {
            update_option('fflhub_rsr_inventory_last_download_error', current_time('mysql'));
            $this->log('ERROR: FTP connection not available.', [
                'host'    => $host,
                'use_ssl' => $use_ssl ? 1 : 0,
            ]);
            $this->profile('FTP connection (failed)', $t_ftp);
            $this->finalize_run($t_start, $mem_start, 'ERROR (FTP connection failed)');
            return;
        }

        $csv_kb_before = file_exists($local_path) ? (int) round(((int) filesize($local_path)) / 1024) : 0;

        $ok = $ftp->download_file($remote_path, $local_path);

        $csv_kb_after = file_exists($local_path) ? (int) round(((int) filesize($local_path)) / 1024) : 0;

        $this->profile('FTP download', $t_ftp, [
            'ok'            => $ok ? 1 : 0,
            'csv_kb_before' => (int) $csv_kb_before,
            'csv_kb_after'  => (int) $csv_kb_after,
        ]);

        if (!$ok) {
            update_option('fflhub_rsr_inventory_last_download_error', current_time('mysql'));
            $this->log('ERROR: FTP download failed', [
                'remote_csv' => (string) $remote_path,
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (download failed)');
            return;
        }

        update_option('fflhub_rsr_inventory_last_download', current_time('mysql'));
        delete_option('fflhub_rsr_inventory_last_download_error');

        // 2) Apply inventory updates to live table.
        $t_apply = microtime(true);

        $processed_rows = 0;
        $apply_stats    = [];

        try {
            // returns ['processed_rows'=>int,'input_rows'=>int,'batches'=>int,'parse_ms'=>float,'db_flush_ms'=>float,'total_ms'=>float]
            $apply_stats = $this->apply_inventory_updates_from_file_profiled($local_path);
            $processed_rows = (int) ($apply_stats['processed_rows'] ?? 0);
        } catch (\Throwable $e) {
            $this->log('ERROR: exception applying inventory updates', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Apply inventory updates (failed)', $t_apply);
            $this->finalize_run($t_start, $mem_start, 'ERROR (apply failed)');
            return;
        }

        $this->profile('Apply inventory updates', $t_apply, $apply_stats);

        update_option('fflhub_rsr_inventory_last_update', current_time('mysql'));
        update_option('fflhub_rsr_inventory_last_update_count', (int) $processed_rows);

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'processed_rows' => (int) $processed_rows,
        ]);
    }

    /**
     * Profiled wrapper around the existing apply logic.
     *
     * @return array<string,mixed>
     */
    private function apply_inventory_updates_from_file_profiled(string $file_path): array
    {
        global $wpdb;

        $t_start = microtime(true);

        $t_parse_total = 0.0;
        $t_flush_total = 0.0;

        if (!file_exists($file_path) || !is_readable($file_path)) {
            $this->log('ERROR: IM-QTY-CSV file missing or unreadable', [
                'file_path' => (string) $file_path,
            ]);
            return [
                'processed_rows' => 0,
                'input_rows'     => 0,
                'batches'        => 0,
                'parse_ms'       => '0.00',
                'db_flush_ms'    => '0.00',
                'total_ms'       => '0.00',
            ];
        }

        $live_table = (string) $this->table->get_live_table_name();
        if ($live_table === '') {
            $this->log('ERROR: could not resolve live table name');
            return [
                'processed_rows' => 0,
                'input_rows'     => 0,
                'batches'        => 0,
                'parse_ms'       => '0.00',
                'db_flush_ms'    => '0.00',
                'total_ms'       => '0.00',
            ];
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $this->log('ERROR: could not fopen file', [
                'file_path' => (string) $file_path,
            ]);
            return [
                'processed_rows' => 0,
                'input_rows'     => 0,
                'batches'        => 0,
                'parse_ms'       => '0.00',
                'db_flush_ms'    => '0.00',
                'total_ms'       => '0.00',
            ];
        }

        $input_rows     = 0; // raw lines that parse into an update row
        $batch_size     = 200;
        $batch_updates  = [];
        $flushes        = 0;

        $wpdb->query('START TRANSACTION');

        try {
            $flush_batch = function () use (&$batch_updates, $live_table, $wpdb, &$t_flush_total, &$input_rows, &$flushes): void {
                if (empty($batch_updates)) {
                    return;
                }

                $t0 = microtime(true);

                $when_sql        = [];
                $case_values     = [];
                $in_placeholders = [];
                $in_values       = [];

                foreach ($batch_updates as $row) {
                    $rsr_stock_number = (string) $row['rsr_stock_number'];
                    $qty_str          = (string) $row['qty'];

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
                    throw new \RuntimeException('batch UPDATE failed: ' . (string) $wpdb->last_error);
                }

                $input_rows += count($batch_updates);
                $batch_updates = [];

                $flushes++;
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

                $batch_updates[] = [
                    'rsr_stock_number' => $rsr_stock_number_raw,
                    'qty'              => (string) $qty_int,
                ];

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
            $this->log('ERROR: rolled back transaction', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $t_total_ms = (microtime(true) - $t_start) * 1000.0;

        $stats = [
            // keep prior semantics: "processed_rows" = input rows processed (not DB changed)
            'processed_rows' => (int) $input_rows,
            'input_rows'     => (int) $input_rows,
            'batch_size'     => (int) $batch_size,
            'batches'        => (int) $flushes,
            'parse_ms'       => number_format($t_parse_total * 1000.0, 2, '.', ''),
            'db_flush_ms'    => number_format($t_flush_total * 1000.0, 2, '.', ''),
            'total_ms'       => number_format($t_total_ms, 2, '.', ''),
        ];

        // Keep existing detailed log too (useful if you grep legacy logs)
        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, 'PROFILE: apply_inventory_updates_from_file() breakdown', $stats);

        return $stats;
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

        $host     = trim((string) $host);
        $username = trim((string) $username);
        $password = trim((string) $password);
        $use_ssl  = ($use_ssl_s !== '');

        if ($host === '' || $username === '' || $password === '') {
            $this->log('Missing FTP credentials', [
                'host' => $host !== '' ? 'set' : 'empty',
                'user' => $username !== '' ? 'set' : 'empty',
            ]);
            return null;
        }

        return [
            'host'     => $host,
            'username' => $username,
            'password' => $password,
            'use_ssl'  => $use_ssl,
        ];
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
