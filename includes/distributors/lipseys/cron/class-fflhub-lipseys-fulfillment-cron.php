<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sets up a WP-Cron job to regularly pull the Lipsey's Catalog feed
 * via the Lipseys PHP integration, import it into the STAGING table,
 * then swap staging ↔ live.
 */
class FFLHub_Lipseys_Fulfillment_Cron {

    /**
     * Cron hook name for Lipsey's catalog refresh.
     */
    const CRON_HOOK = 'fflhub_lipseys_fulfillment_update';

    /**
     * Initialize cron schedules and hooks.
     */
    public static function init(): void {
        add_filter( 'cron_schedules', array( __CLASS__, 'register_intervals' ) );

        add_action(
            self::CRON_HOOK,
            array( __CLASS__, 'cron_refresh_catalog' )
        );

        // Runtime guard: ensure event is scheduled even if activation hook was missed.
        add_action( 'init', array( __CLASS__, 'maybe_schedule_event' ) );
    }

    /**
     * Register a custom 4-hour interval for Lipsey's.
     */
    public static function register_intervals( array $schedules ): array {
        if ( ! isset( $schedules['fflhub_four_hours_lipseys'] ) ) {
            $schedules['fflhub_four_hours_lipseys'] = array(
                'interval' => 4 * HOUR_IN_SECONDS,
                'display'  => "Every 4 hours (FFLHub Lipsey's)",
            );
        }

        return $schedules;
    }

    /**
     * Schedule the event if it doesn't already exist.
     */
    public static function maybe_schedule_event(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event(
                time() + 5 * MINUTE_IN_SECONDS,
                'fflhub_four_hours_lipseys',
                self::CRON_HOOK
            );
        }
    }

    /**
     * Plugin activation handler – make sure the cron is scheduled.
     */
    public static function on_activation(): void {
        self::maybe_schedule_event();
    }

    /**
     * Plugin deactivation handler – unschedule the cron event.
     */
    public static function on_deactivation(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Cron callback:
     *  1) Create LipseysClient with dealer credentials.
     *  2) Call Catalog() to get the full feed.
     *  3) Extract the items array.
     *  4) Import items into the STAGING table.
     *  5) Swap staging ↔ live if import succeeded.
     */
    public static function cron_refresh_catalog(): void {
        $t_start    = microtime( true );
        $mem_start  = function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0; // NEW

        // Allow long-running import if needed (in case Catalog() is slow). // NEW
        if ( function_exists( 'set_time_limit' ) ) {                                     // NEW
            @set_time_limit( 0 );                                                       // NEW
        }                                                                               // NEW

        $log_timing = function( string $label, float $t0 ) {
            $elapsed_ms = ( microtime( true ) - $t0 ) * 1000;
            error_log(
                sprintf(
                    "[FFLHub][Lipsey's Fulfillment Cron] %s took %.2f ms",
                    $label,
                    $elapsed_ms
                )
            );
        };

        error_log( "[FFLHub][Lipsey's Fulfillment Cron] ---- RUN START ----" );
        error_log(
            sprintf(                                                // NEW
                "[FFLHub][Lipsey's Fulfillment Cron] PHP PID=%d, memory_start=%d KB", // NEW
                function_exists( 'getmypid' ) ? getmypid() : 0,      // NEW
                $mem_start > 0 ? (int) round( $mem_start / 1024 ) : 0 // NEW
            )
        );                                                          // NEW

        // 1) Ensure Lipseys client class exists.
        $t_step = microtime( true );
        if ( ! class_exists( '\lipseys\ApiIntegration\LipseysClient' ) ) {
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ERROR: LipseysClient class not found (autoload issue)." );
            $log_timing( 'Early exit (missing client class)', $t_start );
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ---- RUN END (ERROR) ----" );
            return;
        }
        $log_timing( 'Class_exists(LipseysClient) check', $t_step );

        // 2) Pull credentials from the same options used by the distributor class.
        $t_creds = microtime( true );
        $dealer_email    = get_option( 'fflhub_lipseys_dealer_email' );
        $dealer_password = get_option( 'fflhub_lipseys_dealer_password' );

        $dealer_email    = is_string( $dealer_email )    ? trim( $dealer_email )    : '';
        $dealer_password = is_string( $dealer_password ) ? trim( $dealer_password ) : '';

        if ( $dealer_email === '' || $dealer_password === '' ) {
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ERROR: dealer_email or dealer_password not set." );
            $log_timing( 'Credentials retrieval (failed)', $t_creds );
            $log_timing( 'Total cron run (credentials failed)', $t_start );
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ---- RUN END (ERROR) ----" );
            return;
        }
        $log_timing( 'Credentials retrieval', $t_creds );

        // 3) Create the client.
        $t_client = microtime( true );
        try {
            $client = new \lipseys\ApiIntegration\LipseysClient(
                (string) $dealer_email,
                (string) $dealer_password
            );
        } catch ( \Throwable $e ) {
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ERROR: exception creating LipseysClient: " . $e->getMessage() );
            $log_timing( 'Client creation (failed)', $t_client );
            $log_timing( 'Total cron run (client failed)', $t_start );
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ---- RUN END (ERROR) ----" );
            return;
        }
        $log_timing( 'Client creation', $t_client );

        // 4) Call Catalog() to get the feed.
        $t_catalog = microtime( true );
        try {
            $result = $client->Catalog();
        } catch ( \Throwable $e ) {
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ERROR: exception calling Catalog(): " . $e->getMessage() );
            $log_timing( 'Catalog() call (failed)', $t_catalog );
            $log_timing( 'Total cron run (Catalog failed)', $t_start );
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ---- RUN END (ERROR) ----" );
            return;
        }
        $log_timing( 'Catalog() call', $t_catalog );

        if ( ! is_array( $result ) ) {
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ERROR: Catalog() did not return an array." );
            $log_timing( 'Total cron run (bad Catalog result)', $t_start );
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ---- RUN END (ERROR) ----" );
            return;
        }

        // 5) Extract items array from the response.
        $t_items = microtime( true );
        $items   = array();

        if ( isset( $result['items'] ) && is_array( $result['items'] ) ) {
            $items = $result['items'];
        } elseif ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
            $items = $result['data'];
        } else {
            if ( self::is_list_of_items( $result ) ) {
                $items = $result;
            }
        }

        $items_count = is_array( $items ) ? count( $items ) : 0;

        // Extra profiling: log a rough idea of items count and current memory. // NEW
        $mem_mid = function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0; // NEW
        error_log(                                                            // NEW
            sprintf(
                "[FFLHub][Lipsey's Fulfillment Cron] Items extraction: count=%d, memory_now=%d KB", // NEW
                $items_count,                                                // NEW
                $mem_mid > 0 ? (int) round( $mem_mid / 1024 ) : 0           // NEW
            )
        );                                                                   // NEW

        $log_timing( "Items extraction (count={$items_count})", $t_items );

        if ( empty( $items ) ) {
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ERROR: no items found in Catalog() response." );
            $log_timing( 'Total cron run (no items)', $t_start );
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ---- RUN END (ERROR) ----" );
            return;
        }

        // 6) Import into the STAGING table using the importer.
        if ( ! class_exists( 'FFLHub_Lipseys_Fulfillment_Importer' ) ) {
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ERROR: importer class not found, skipping DB import." );
            $log_timing( 'Total cron run (importer missing)', $t_start );
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ---- RUN END (ERROR) ----" );
            return;
        }

        $t_import = microtime( true );
        $count    = FFLHub_Lipseys_Fulfillment_Importer::import_items_array( $items );
        $log_timing( 'Import into staging', $t_import );

        error_log(                                                  // NEW
            sprintf(
                "[FFLHub][Lipsey's Fulfillment Cron] Import result: requested_items=%d, imported_rows=%d", // NEW
                $items_count,                                       // NEW
                (int) $count                                        // NEW
            )
        );                                                          // NEW

        if ( $count <= 0 ) {
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] WARNING: import completed but 0 rows processed, not swapping tables." );
            $log_timing( 'Total cron run (0 rows imported)', $t_start );
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ---- RUN END (NO SWAP) ----" );
            return;
        }

        // 7) Swap staging ↔ live.
        if ( ! class_exists( 'FFLHub_Lipseys_Fulfillment_Table' ) ) {
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ERROR: table helper class not found, cannot swap live/staging." );
            $log_timing( 'Total cron run (no table helper)', $t_start );
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ---- RUN END (ERROR) ----" );
            return;
        }

        $t_swap   = microtime( true );
        $new_live = FFLHub_Lipseys_Fulfillment_Table::swap_live_and_staging();
        $log_timing( 'Swap staging ↔ live', $t_swap );

        // Mark success.
        update_option( 'fflhub_lipseys_fulfillment_last_refresh', current_time( 'mysql' ) );
        update_option( 'fflhub_lipseys_fulfillment_last_refresh_count', $count );

        // Total + memory delta.
        $mem_end = function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0; // NEW
        $log_timing( 'Total cron run', $t_start );

        if ( $mem_start > 0 && $mem_end > 0 ) {                               // NEW
            error_log(                                                        // NEW
                sprintf(
                    "[FFLHub][Lipsey's Fulfillment Cron] Memory usage summary: start=%d KB, end=%d KB, delta=%+d KB",
                    (int) round( $mem_start / 1024 ),
                    (int) round( $mem_end / 1024 ),
                    (int) round( ( $mem_end - $mem_start ) / 1024 )
                )
            );
        }                                                                     // NEW

        error_log(
            sprintf(
                "[FFLHub][Lipsey's Fulfillment Cron] ---- RUN END (SUCCESS, imported %d rows, new live=%s) ----",
                (int) $count,
                (string) $new_live
            )
        );
    }

    /**
     * Heuristic: is this array basically a list of item arrays?
     *
     * @param array<string|int,mixed> $arr
     * @return bool
     */
    protected static function is_list_of_items( array $arr ): bool {
        if ( empty( $arr ) ) {
            return false;
        }

        foreach ( $arr as $key => $value ) {
            if ( ! is_int( $key ) ) {
                return false;
            }
            if ( ! is_array( $value ) ) {
                return false;
            }
        }

        return true;
    }
}
