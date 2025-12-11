<?php


namespace FFLHub\Distributor\Services\RSR\Cron;


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use FFLHub\Distributor\Services\RSR\RSRFTPClient;
use FFLHub\Distributor\RSR\DistributorRSR;
use FFLHub\Distributor\Services\RSR\Tables\RSRFulfillmentTable;


/**
 * Cron job for real-time inventory updates using RSR's IM-QTY-CSV.csv file.
 *
 * Runs every 5 minutes:
 *  - downloads IM-QTY-CSV.csv via FTP to uploads/fflhub-rsr/
 *  - parses it (RSR Stock Number, Quantity)
 *  - updates inventory_quantity in the *live* fulfillment table
 */
class RSRInventoryCron {



    const CRON_HOOK = 'fflhub_rsr_pricing_quantity_update';


    /**
     * Hook this up from your main plugin bootstrap.
     */
    public static function init(): void {
        // Add custom 5-minute interval.
        add_filter( 'cron_schedules', array( __CLASS__, 'register_intervals' ) );

        // Cron event callback.
        add_action(
            self::CRON_HOOK,
            array( __CLASS__, 'cron_update_inventory_from_qty_file' )
        );

        // Runtime safety: if event missing, schedule it.
        add_action( 'init', array( __CLASS__, 'maybe_schedule_event' ) );
    }

    /**
     * Register a custom interval of 5 minutes for inventory updates.
     *
     * @param array<string,array<string,int|string>> $schedules
     * @return array
     */
    public static function register_intervals( array $schedules ): array {
        if ( ! isset( $schedules['fflhub_five_minutes'] ) ) {
            $schedules['fflhub_five_minutes'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => 'Every 5 minutes (FFLHub RSR inventory)',
            );
        }

        return $schedules;
    }

    /**
     * Runtime guard: make sure the inventory event is scheduled.
     */
    public static function maybe_schedule_event(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event(
                time() + 2 * MINUTE_IN_SECONDS,
                'fflhub_five_minutes',
                self::CRON_HOOK
            );
        }
    }

    /**
     * Optional: called on plugin activation.
     */
    public static function on_activation(): void {
        self::maybe_schedule_event();
    }

    /**
     * Called on plugin deactivation to clear the scheduled event.
     */
    public static function on_deactivation(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Cron callback:
     *  1) Download IM-QTY-CSV.csv from RSR FTP to uploads.
     *  2) Parse it and update inventory_quantity in the live table.
     */
    public static function cron_update_inventory_from_qty_file(): void {
        $t_start = microtime( true );

        $log_timing = function ( string $label, float $t0 ) {
            $elapsed_ms = ( microtime( true ) - $t0 ) * 1000;
            error_log(
                sprintf(
                    '[FFLHub][RSR Inventory Cron] %s took %.2f ms',
                    $label,
                    $elapsed_ms
                )
            );
        };

        error_log( '[FFLHub][RSR Inventory Cron] ---- RUN START ----' );

        // 0) Get FTP credentials.
        $t_creds_start = microtime( true );

        

        $rsr = new DistributorRSR();
        if ( ! method_exists( $rsr, 'get_ftp_credentials' ) ) {
            error_log( '[FFLHub][RSR Inventory Cron] ERROR: get_ftp_credentials() not available on distributor.' );
            return;
        }

        $creds = $rsr->get_ftp_credentials();
        if ( ! is_array( $creds ) ) {
            // get_ftp_credentials() already logged a detailed error.
            $log_timing( 'Credentials retrieval (failed)', $t_creds_start );
            error_log( '[FFLHub][RSR Inventory Cron] ---- RUN END (CREDS FAILED) ----' );
            return;
        }

        $log_timing( 'Credentials retrieval', $t_creds_start );

        $host     = $creds['host'] ?? '';
        $username = $creds['username'] ?? '';
        $password = $creds['password'] ?? '';
        $use_ssl  = ! empty( $creds['use_ssl'] );

        // Local path for the quantity file.
        $uploads    = wp_upload_dir();
        $base_dir   = trailingslashit( $uploads['basedir'] ) . 'fflhub-rsr';
        $file_name  = 'IM-QTY-CSV.csv';
        $local_path = trailingslashit( $base_dir ) . $file_name;

        // Remote path on RSR FTP (leading slash to match fulfillment path style).
        $remote_path = '/ftpdownloads/IM-QTY-CSV.csv';

        // 1) Download the file.
        $t_ftp_start = microtime( true );

        $ok = RSRFTPClient::download_file(
            $remote_path,
            $local_path,
            $host,
            $username,
            $password,
            $use_ssl
        );

        $log_timing( 'FTP download', $t_ftp_start );

        if ( ! $ok ) {
            update_option( 'fflhub_rsr_inventory_last_download_error', current_time( 'mysql' ) );
            error_log( '[FFLHub][RSR Inventory Cron] FTP download failed for ' . $remote_path );
            $log_timing( 'Total (download failed)', $t_start );
            error_log( '[FFLHub][RSR Inventory Cron] ---- RUN END (DOWNLOAD FAILED) ----' );
            return;
        }

        update_option( 'fflhub_rsr_inventory_last_download', current_time( 'mysql' ) );
        delete_option( 'fflhub_rsr_inventory_last_download_error' );

        // 2) Apply inventory updates to live table.
        $t_apply_start = microtime( true );

        $updated_rows = self::apply_inventory_updates_from_file( $local_path );

        $log_timing( 'Apply inventory updates', $t_apply_start );

        update_option( 'fflhub_rsr_inventory_last_update', current_time( 'mysql' ) );
        update_option( 'fflhub_rsr_inventory_last_update_count', $updated_rows );

        $log_timing( 'Total cron run', $t_start );
        error_log(
            sprintf(
                '[FFLHub][RSR Inventory Cron] ---- RUN END (SUCCESS, processed %d input rows) ----',
                (int) $updated_rows
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
    protected static function apply_inventory_updates_from_file( string $file_path ): int {
        global $wpdb;

        $t_start       = microtime( true );
        $t_parse_total = 0.0;
        $t_flush_total = 0.0;

        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            error_log( '[FFLHub][RSR Inventory Cron] IM-QTY-CSV file missing or unreadable at ' . $file_path );
            return 0;
        }


        $live_table = RSRFulfillmentTable::get_live_table_name();

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            error_log( '[FFLHub][RSR Inventory Cron] could not fopen ' . $file_path );
            return 0;
        }

        $total_rows    = 0;
        $batch_size    = 200; // rows per UPDATE batch
        $batch_updates = array();


        $wpdb->query( 'START TRANSACTION' );


        $flush_batch = function () use ( &$batch_updates, &$total_rows, $live_table, $wpdb, &$t_flush_total ) {
            if ( empty( $batch_updates ) ) {
                return;
            }

            $t0 = microtime( true );

            $when_sql        = array();
            $case_values     = array();
            $in_placeholders = array();
            $in_values       = array();

            foreach ( $batch_updates as $row ) {
                $rsr_stock_number = $row['rsr_stock_number'];
                $qty_str          = $row['qty']; // already string

                // CASE rsr_stock_number WHEN %s THEN %s ...
                $when_sql[]    = 'WHEN %s THEN %s';
                $case_values[] = $rsr_stock_number;
                $case_values[] = $qty_str;

                $in_placeholders[] = '%s';
                $in_values[]       = $rsr_stock_number;
            }

            $all_values = array_merge( $case_values, $in_values );

            $sql = "
                UPDATE {$live_table}
                SET inventory_quantity = CASE rsr_stock_number
                    " . implode( "\n                    ", $when_sql ) . "
                END
                WHERE rsr_stock_number IN (" . implode( ', ', $in_placeholders ) . ')
            ';

            $prepared = $wpdb->prepare( $sql, $all_values );
            $result   = $wpdb->query( $prepared );

            if ( $result === false ) {
                error_log( '[FFLHub][RSR Inventory Cron] batch UPDATE failed.' );
            }

            $total_rows    += count( $batch_updates );
            $batch_updates  = array();

            $t_flush_total += ( microtime( true ) - $t0 );
        };

        while ( ( $line = fgets( $handle ) ) !== false ) {
            $t0 = microtime( true );

            $line = trim( $line );
            if ( $line === '' ) {
                $t_parse_total += ( microtime( true ) - $t0 );
                continue;
            }

            // CSV is simple: RSR_STOCK_NUMBER,QUANTITY
            $cols = explode( ',', $line );
            if ( count( $cols ) < 2 ) {
                $t_parse_total += ( microtime( true ) - $t0 );
                continue;
            }

            $rsr_stock_number_raw = trim( (string) $cols[0] );
            $qty_raw              = trim( (string) $cols[1] );

            if ( $rsr_stock_number_raw === '' ) {
                $t_parse_total += ( microtime( true ) - $t0 );
                continue;
            }

            // Quantity is zero-padded integer, e.g., '0000012'.
            // Convert to plain int to normalize, then back to string (since column is VARCHAR).
            if ( $qty_raw === '' ) {
                $qty_int = 0;
            } else {
                $digits  = preg_replace( '/[^0-9]/', '', $qty_raw );
                $qty_int = ( $digits === '' ) ? 0 : (int) $digits;
            }

            $qty_str = (string) $qty_int;

            $batch_updates[] = array(
                'rsr_stock_number' => $rsr_stock_number_raw,
                'qty'              => $qty_str,
            );

            $t_parse_total += ( microtime( true ) - $t0 );

            if ( count( $batch_updates ) >= $batch_size ) {
                $flush_batch();
            }
        }

        fclose( $handle );

        // Flush any remaining rows.
        $flush_batch();

        $wpdb->query( 'COMMIT' );




        $t_total_ms = ( microtime( true ) - $t_start ) * 1000;
        $t_parse_ms = $t_parse_total * 1000;
        $t_flush_ms = $t_flush_total * 1000;

        error_log(
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
}
