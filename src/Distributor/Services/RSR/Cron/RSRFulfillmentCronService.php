<?php

namespace FFLHub\Distributor\Services\RSR\Cron;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Distributor\Services\RSR\RSRFTPService;
use FFLHub\Distributor\Services\RSR\RSRFulfillmentImporterService;

/**
 * WP-Cron job to regularly download the RSR fulfillment catalog file
 * (fulfillment-inv-new.txt) from the RSR FTP server into uploads/fflhub-rsr/,
 * then import it into the staging table and swap staging ↔ live.
 */
final class RSRFulfillmentCronService extends AbstractTableCronService
{
    /**
     * Cron hook name for RSR fulfillment refresh.
     */
    public const CRON_HOOK = 'fflhub_rsr_fulfillment_update';

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
     * Schedule key used in cron_schedules.
     * (Kept as 'fflhub_two_hours' to avoid breaking existing schedules.)
     */
    protected function get_schedule_key(): string
    {
        return 'fflhub_two_hours';
    }

    /**
     * Interval length in seconds.
     */
    protected function get_interval_seconds(): int
    {
        return 2 * HOUR_IN_SECONDS;
    }

    /**
     * Human-readable schedule label.
     */
    protected function get_interval_display(): string
    {
        return 'Every 2 hours (FFLHub RSR)';
    }

    /**
     * Delay before first run (keeps your old 5-minute initial delay).
     */
    protected function get_initial_delay_seconds(): int
    {
        return 5 * MINUTE_IN_SECONDS;
    }

    /**
     * Cron callback:
     *  1) Download fulfillment-inv-new.txt from RSR FTP to uploads.
     *  2) Import it into the STAGING table.
     *  3) Swap staging ↔ live if import succeeded.
     */
    public function run(): void
    {
        $t_start   = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;

        // Allow long-running download/import if needed.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $log_timing = function (string $label, float $t0): void {
            $elapsed_ms = (microtime(true) - $t0) * 1000;
            error_log(
                sprintf(
                    '[FFLHub][RSR Fulfillment Cron] %s took %.2f ms',
                    $label,
                    $elapsed_ms
                )
            );
        };

        error_log('[FFLHub][RSR Fulfillment Cron] ---- RUN START ----');
        if ($mem_start > 0) {
            error_log(
                sprintf(
                    '[FFLHub][RSR Fulfillment Cron] PHP PID=%d, memory_start=%d KB',
                    function_exists('getmypid') ? getmypid() : 0,
                    (int) round($mem_start / 1024)
                )
            );
        }

        // 0) Load FTP credentials.
        $t_creds_start = microtime(true);

        $creds = $this->get_ftp_credentials();

        if (! is_array($creds)) {
            $log_timing('Credentials retrieval (failed)', $t_creds_start);
            $log_timing('Total cron run (credentials failed)', $t_start);
            error_log('[FFLHub][RSR Fulfillment Cron] ---- RUN END (ERROR) ----');
            return;
        }

        $log_timing('Credentials retrieval', $t_creds_start);

        $host     = $creds['host'];
        $username = $creds['username'];
        $password = $creds['password'];
        $use_ssl  = $creds['use_ssl'];

        // Where to save the file locally.
        $uploads  = wp_upload_dir();
        $base_dir = trailingslashit($uploads['basedir']) . 'fflhub-rsr';

        if (! wp_mkdir_p($base_dir)) {
            error_log(
                '[FFLHub][RSR Fulfillment Cron] ERROR: failed to create base directory ' . $base_dir
            );
            $log_timing('Total cron run (mkdir failed)', $t_start);
            error_log('[FFLHub][RSR Fulfillment Cron] ---- RUN END (ERROR) ----');
            return;
        }

        $file_name      = 'fulfillment-inv-new.txt';
        $local_path     = trailingslashit($base_dir) . $file_name; // extracted TXT path
        $zip_name       = 'fulfillment-inv-new.zip';
        $local_zip_path = trailingslashit($base_dir) . $zip_name;

        // Remote path on RSR FTP.
        $remote_path = '/ftpdownloads/fulfillment-inv-new.zip'; // adjust if needed

        // 1) Download the file.
        $t_download_start = microtime(true);


        $ftp = new RSRFTPService($host, $username, $password, $use_ssl);

        if (! $ftp->is_connected()) {
            error_log('[FFLHub][RSR Fulfillment Cron] FTP connection not available.');
            // maybe inspect $ftp->get_last_error()
            return;
        }

        $ok = $ftp->download_zip_file(
            $remote_path,
            $local_zip_path,
            $base_dir
        );

        $log_timing('FTP download', $t_download_start);

        if ($ok) {
            update_option('fflhub_rsr_fulfillment_last_download', current_time('mysql'));
            delete_option('fflhub_rsr_fulfillment_last_download_error');
        } else {
            update_option('fflhub_rsr_fulfillment_last_download_error', current_time('mysql'));
            error_log('[FFLHub][RSR Fulfillment Cron] Download failed – aborting import and swap.');
            $log_timing('Total cron run (download failed early)', $t_start);

            $mem_end = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
            if ($mem_start > 0 && $mem_end > 0) {
                error_log(
                    sprintf(
                        '[FFLHub][RSR Fulfillment Cron] Memory usage summary: start=%d KB, end=%d KB, delta=%+d KB',
                        (int) round($mem_start / 1024),
                        (int) round($mem_end / 1024),
                        (int) round(($mem_end - $mem_start) / 1024)
                    )
                );
            }

            error_log('[FFLHub][RSR Fulfillment Cron] ---- RUN END (DOWNLOAD FAILED) ----');
            return;
        }

        // 2) Import the downloaded file into the staging table.
        $t_import_start = microtime(true);

        // Use the new importer service (instance-based, uses DoubleBufferedFulfillmentTable).
        $importer = new RSRFulfillmentImporterService($this->table);
        $count    = $importer->import_from_downloaded_file();

        $log_timing('Import into staging', $t_import_start);

        if ($count <= 0) {
            error_log(
                '[FFLHub][RSR Fulfillment Cron] Import completed but 0 rows processed, not swapping tables.'
            );
            $log_timing('Total cron run (0 rows imported)', $t_start);

            $mem_end = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
            if ($mem_start > 0 && $mem_end > 0) {
                error_log(
                    sprintf(
                        '[FFLHub][RSR Fulfillment Cron] Memory usage summary: start=%d KB, end=%d KB, delta=%+d KB',
                        (int) round($mem_start / 1024),
                        (int) round($mem_end / 1024),
                        (int) round(($mem_end - $mem_start) / 1024)
                    )
                );
            }

            error_log('[FFLHub][RSR Fulfillment Cron] ---- RUN END (NO ROWS) ----');
            return;
        }

        // 3) Swap staging ↔ live, so new data goes live atomically.
        $t_swap_start = microtime(true);

        $new_live = $this->table->swap_live_and_staging();

        $log_timing('Swap staging ↔ live', $t_swap_start);

        error_log(
            sprintf(
                '[FFLHub][RSR Fulfillment Cron] Completed: imported %d rows, new live table: %s',
                (int) $count,
                (string) $new_live
            )
        );

        $log_timing('Total cron run', $t_start);

        $mem_end = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
        if ($mem_start > 0 && $mem_end > 0) {
            error_log(
                sprintf(
                    '[FFLHub][RSR Fulfillment Cron] Memory usage summary: start=%d KB, end=%d KB, delta=%+d KB',
                    (int) round($mem_start / 1024),
                    (int) round($mem_end / 1024),
                    (int) round(($mem_end - $mem_start) / 1024)
                )
            );
        }

        error_log('[FFLHub][RSR Fulfillment Cron] ---- RUN END (SUCCESS) ----');
    }

    /**
     * Get FTP credentials for downloading RSR catalog/quantity files.
     *
     * @return array|null {
     *   @type string $host
     *   @type string $username
     *   @type string $password
     *   @type bool   $use_ssl
     * }
     */
    public function get_ftp_credentials(): ?array
    {
        $host     = get_option('fflhub_rsr_ftp_host');
        $username = get_option('fflhub_rsr_ftp_username');
        $password = get_option('fflhub_rsr_ftp_password');
        $use_ssl  = get_option('fflhub_rsr_ftp_use_ssl');

        $host     = is_string($host)     ? trim($host)     : '';
        $username = is_string($username) ? trim($username) : '';
        $password = is_string($password) ? trim($password) : '';
        $use_ssl  = (is_string($use_ssl) ? trim($use_ssl) : '') !== '';

        if ($host === '' || $username === '' || $password === '') {
            error_log(
                sprintf(
                    '[FFLHub][RSR Fulfillment Cron] Missing FTP credentials (host: %s, user: %s).',
                    $host     !== '' ? 'set' : 'empty',
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
}
