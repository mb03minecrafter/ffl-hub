<?php

namespace FFLHub\Distributor\Services\Lipseys\Cron;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Lipseys\LipseysFulfillmentImporterService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;

/**
 * WP-Cron job to pull the Lipsey's Catalog feed into a double-buffered table
 * and atomically swap staging ↔ live.
 */
final class LipseysFulfillmentCronService extends AbstractTableCronService
{
    /**
     * Cron hook name for Lipsey's catalog refresh.
     */
    public const CRON_HOOK = 'fflhub_lipseys_fulfillment_update';

    /**
     * @var LipseysFulfillmentImporterService|null
     */
    private ?LipseysFulfillmentImporterService $importer = null;

    /**
     * @param DoubleBufferedFulfillmentTable $table
     */
    public function __construct( DoubleBufferedFulfillmentTable $table ) {
        parent::__construct( $table );
    }

    /**
     * Lazily create / return the importer bound to our table.
     */
    private function get_importer(): LipseysFulfillmentImporterService {
        if ( $this->importer === null ) {
            $this->importer = new LipseysFulfillmentImporterService( $this->table );
        }
        return $this->importer;
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
     */
    protected function get_schedule_key(): string
    {
        return 'fflhub_four_hours_lipseys';
    }

    /**
     * Interval length in seconds.
     */
    protected function get_interval_seconds(): int
    {
        return 4 * HOUR_IN_SECONDS;
    }

    /**
     * Human-readable schedule label.
     */
    protected function get_interval_display(): string
    {
        return "Every 4 hours (FFLHub Lipsey's)";
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
     *  1) Create LipseysClient with dealer credentials.
     *  2) Call Catalog() to get the full feed.
     *  3) Extract the items array.
     *  4) Import items into the STAGING table via importer.
     *  5) Swap staging ↔ live if import succeeded.
     */
    public function run(): void
    {
        $t_start   = microtime( true );
        $mem_start = function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0;

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $log_timing = function ( string $label, float $t0 ): void {
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
            sprintf(
                "[FFLHub][Lipsey's Fulfillment Cron] PHP PID=%d, memory_start=%d KB",
                function_exists( 'getmypid' ) ? getmypid() : 0,
                $mem_start > 0 ? (int) round( $mem_start / 1024 ) : 0
            )
        );

        // 1) Pull credentials.
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

        // 2) Create the client.
        $t_client = microtime( true );
        try {
            $client = new \lipseys\ApiIntegration\LipseysClient(
                (string) $dealer_email,
                (string) $dealer_password
            );
        } catch ( \Throwable $e ) {
            error_log(
                "[FFLHub][Lipsey's Fulfillment Cron] ERROR: exception creating LipseysClient: " . $e->getMessage()
            );
            $log_timing( 'Client creation (failed)', $t_client );
            $log_timing( 'Total cron run (client failed)', $t_start );
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ---- RUN END (ERROR) ----" );
            return;
        }
        $log_timing( 'Client creation', $t_client );

        // 3) Call Catalog() to get the feed.
        $t_catalog = microtime( true );
        try {
            $result = $client->Catalog();
        } catch ( \Throwable $e ) {
            error_log(
                "[FFLHub][Lipsey's Fulfillment Cron] ERROR: exception calling Catalog(): " . $e->getMessage()
            );
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

        // 4) Extract items array from the response.
        $t_items = microtime( true );
        $items   = [];

        if ( isset( $result['items'] ) && is_array( $result['items'] ) ) {
            $items = $result['items'];
        } elseif ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
            $items = $result['data'];
        } elseif ( $this->is_list_of_items( $result ) ) {
            $items = $result;
        }

        $items_count = is_array( $items ) ? count( $items ) : 0;

        $mem_mid = function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0;
        error_log(
            sprintf(
                "[FFLHub][Lipsey's Fulfillment Cron] Items extraction: count=%d, memory_now=%d KB",
                $items_count,
                $mem_mid > 0 ? (int) round( $mem_mid / 1024 ) : 0
            )
        );

        $log_timing( "Items extraction (count={$items_count})", $t_items );

        if ( empty( $items ) ) {
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ERROR: no items found in Catalog() response." );
            $log_timing( 'Total cron run (no items)', $t_start );
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ---- RUN END (ERROR) ----" );
            return;
        }

        // 5) Import into the STAGING table via the importer service.
        $t_import = microtime( true );
        $count    = $this->get_importer()->import_items_array( $items );
        $log_timing( 'Import into staging', $t_import );

        error_log(
            sprintf(
                "[FFLHub][Lipsey's Fulfillment Cron] Import result: requested_items=%d, imported_rows=%d",
                $items_count,
                (int) $count
            )
        );

        if ( $count <= 0 ) {
            error_log(
                "[FFLHub][Lipsey's Fulfillment Cron] WARNING: import completed but 0 rows processed, not swapping tables."
            );
            $log_timing( 'Total cron run (0 rows imported)', $t_start );
            error_log( "[FFLHub][Lipsey's Fulfillment Cron] ---- RUN END (NO SWAP) ----" );
            return;
        }

        // 6) Swap staging ↔ live using the injected table.
        $t_swap   = microtime( true );
        $new_live = $this->table->swap_live_and_staging();
        $log_timing( 'Swap staging ↔ live', $t_swap );

        // Mark success.
        update_option( 'fflhub_lipseys_fulfillment_last_refresh', current_time( 'mysql' ) );
        update_option( 'fflhub_lipseys_fulfillment_last_refresh_count', (int) $count );

        // Total + memory delta.
        $mem_end = function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0;
        $log_timing( 'Total cron run', $t_start );

        if ( $mem_start > 0 && $mem_end > 0 ) {
            error_log(
                sprintf(
                    "[FFLHub][Lipsey's Fulfillment Cron] Memory usage summary: start=%d KB, end=%d KB, delta=%+d KB",
                    (int) round( $mem_start / 1024 ),
                    (int) round( $mem_end / 1024 ),
                    (int) round( ( $mem_end - $mem_start ) / 1024 )
                )
            );
        }

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
     */
    protected function is_list_of_items( array $arr ): bool
    {
        if ( empty( $arr ) ) {
            return false;
        }

        foreach ( $arr as $key => $value ) {
            if ( ! is_int( $key ) || ! is_array( $value ) ) {
                return false;
            }
        }

        return true;
    }
}
