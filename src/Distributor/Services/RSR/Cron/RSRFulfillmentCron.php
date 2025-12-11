<?php

namespace FFLHub\Distributor\Services\RSR\Cron;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FFLHub\Distributor\Services\RSR\RSRFTPClient;
use FFLHub\Distributor\RSR\DistributorRSR;
use FFLHub\Distributor\Services\RSR\RSRFulfillmentImporter;
use FFLHub\Distributor\Services\RSR\Tables\RSRFulfillmentTable;

/**
 * Sets up a WP-Cron job to regularly download the RSR fulfillment catalog file
 * (fulfillment-inv-new.txt) from the RSR FTP server into uploads/fflhub-rsr/,
 * then import it into the staging table and swap staging ↔ live.
 */
class RSRFulfillmentCron {

    public const CRON_HOOK = 'fflhub_rsr_fulfillment_update';

    public static function init(): void {
        add_filter( 'cron_schedules', array( __CLASS__, 'register_intervals' ) );

        add_action(
            self::CRON_HOOK,
            array( __CLASS__, 'cron_download_fulfillment_file' )
        );

        // Runtime guard: ensure event is scheduled even if activation hook was missed.
        add_action( 'init', array( __CLASS__, 'maybe_schedule_event' ) );
    }

    public static function register_intervals( array $schedules ): array {
        if ( ! isset( $schedules['fflhub_two_hours'] ) ) {
            $schedules['fflhub_two_hours'] = array(
                'interval' => 2 * HOUR_IN_SECONDS,
                'display'  => 'Every 2 hours (FFLHub RSR)',
            );
        }

        return $schedules;
    }

    public static function maybe_schedule_event(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event(
                time() + 5 * MINUTE_IN_SECONDS,
                'fflhub_two_hours',
                self::CRON_HOOK
            );
        }
    }

    public static function on_activation(): void {
        self::maybe_schedule_event();
    }

    public static function on_deactivation(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Cron callback:
     *  1) Download fulfillment-inv-new.txt from RSR FTP to uploads.
     *  2) Import it into the STAGING table.
     *  3) Swap staging ↔ live if import succeeded.
     *
     * Now instrumented with timing benchmarks.
     */
    public static function cron_download_fulfillment_file(): void {
        $t_start = microtime( true );

        // Small helper for timing logs.
        $log_timing = function ( string $label, float $t0 ) {
            $elapsed = ( microtime( true ) - $t0 ) * 1000; // ms
            error_log(
                sprintf(
                    '[FFLHub][RSR Fulfillment Cron] %s took %.2f ms',
                    $label,
                    $elapsed
                )
            );
        };

        error_log( '[FFLHub][RSR Fulfillment Cron] ---- RUN START ----' );

        // 0) Load distributor and credentials.
        $t_creds_start = microtime( true );


        $rsr = new DistributorRSR();
        if ( ! method_exists( $rsr, 'get_ftp_credentials' ) ) {
            error_log( '[FFLHub][RSR Fulfillment Cron] ERROR: get_ftp_credentials() not available on distributor.' );
            return;
        }

        $creds = $rsr->get_ftp_credentials();
        if ( ! is_array( $creds ) ) {
            // get_ftp_credentials() already logged a detailed error.
            $log_timing( 'Credentials retrieval (failed)', $t_creds_start );
            return;
        }

        $log_timing( 'Credentials retrieval', $t_creds_start );

        $host     = $creds['host'] ?? '';
        $username = $creds['username'] ?? '';
        $password = $creds['password'] ?? '';
        $use_ssl  = ! empty( $creds['use_ssl'] );

        // Where to save the file locally.
        $uploads  = wp_upload_dir();
        $base_dir = trailingslashit( $uploads['basedir'] ) . 'fflhub-rsr';
        if ( ! wp_mkdir_p( $base_dir ) ) {
            error_log( '[FFLHub][RSR Fulfillment Cron] ERROR: failed to create base directory ' . $base_dir );
            return;
        }

        $file_name      = 'fulfillment-inv-new.txt';
        $local_path     = trailingslashit( $base_dir ) . $file_name; // extracted TXT path
        $zip_name       = 'fulfillment-inv-new.zip';
        $local_zip_path = trailingslashit( $base_dir ) . $zip_name;

        // Remote path on RSR FTP.
        $remote_path = '/ftpdownloads/fulfillment-inv-new.zip'; // adjust if needed

        // 1) Download the file.
        $t_download_start = microtime( true );

        $ok = RSRFTPClient::download_zip_file(
            $remote_path,
            $local_zip_path,
            $host,
            $username,
            $password,
            $base_dir, // extract_to_dir
            $use_ssl   // use_ssl (delete_zip_after defaults to true)
        );

        $log_timing( 'FTP download', $t_download_start );

        if ( $ok ) {
            update_option( 'fflhub_rsr_fulfillment_last_download', current_time( 'mysql' ) );
            delete_option( 'fflhub_rsr_fulfillment_last_download_error' );
        } else {
            update_option( 'fflhub_rsr_fulfillment_last_download_error', current_time( 'mysql' ) );
            error_log( '[FFLHub][RSR Fulfillment Cron] Download failed – aborting import and swap.' );
            $log_timing( 'Total (download failed early)', $t_start );
            error_log( '[FFLHub][RSR Fulfillment Cron] ---- RUN END (DOWNLOAD FAILED) ----' );
            return;
        }

        // 2) Import the downloaded file into the staging table.
        $t_import_start = microtime( true );

        
        // Importer reads from the known TXT path (produced by unzip step).
        $count = RSRFulfillmentImporter::import_from_downloaded_file();

        $log_timing( 'Import into staging', $t_import_start );

        if ( $count <= 0 ) {
            error_log( '[FFLHub][RSR Fulfillment Cron] Import completed but 0 rows processed, not swapping tables.' );
            $log_timing( 'Total (0 rows imported)', $t_start );
            error_log( '[FFLHub][RSR Fulfillment Cron] ---- RUN END (NO ROWS) ----' );
            return;
        }

        // 3) Swap staging ↔ live, so new data goes live atomically.
        $t_swap_start = microtime( true );

        

        $new_live = RSRFulfillmentTable::swap_live_and_staging();

        $log_timing( 'Swap staging ↔ live', $t_swap_start );

        error_log(
            sprintf(
                '[FFLHub][RSR Fulfillment Cron] Completed: imported %d rows, new live table: %s',
                (int) $count,
                (string) $new_live
            )
        );

        $log_timing( 'Total cron run', $t_start );
        error_log( '[FFLHub][RSR Fulfillment Cron] ---- RUN END (SUCCESS) ----' );
    }
}

