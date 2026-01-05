<?php

namespace FFLHub\Distributor\Services\Lipseys\Cron;

if ( ! defined( 'ABSPATH' ) ) {
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

    public function __construct( DoubleBufferedFulfillmentTable $table ) {
        parent::__construct( $table );
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
     * (Kept as 'fflhub_one_hour' to avoid breaking existing schedules.)
     */
    protected function get_schedule_key(): string
    {
        return 'fflhub_one_hour';
    }

    /**
     * Interval length in seconds.
     */
    protected function get_interval_seconds(): int
    {
        return HOUR_IN_SECONDS;
    }

    /**
     * Human-readable schedule label.
     */
    protected function get_interval_display(): string
    {
        return 'Every hour (FFLHub Lipsey\'s Pricing/Quantity)';
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

        $t_start   = microtime( true );
        $mem_start = function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0;

        $log_timing = function ( string $label, float $t0 ): void {
            $elapsed_ms = ( microtime( true ) - $t0 ) * 1000;
            error_log(
                sprintf(
                    "[FFLHub][Lipsey's Inventory Cron] %s took %.2f ms",
                    $label,
                    $elapsed_ms
                )
            );
        };

        error_log( "[FFLHub][Lipsey's Inventory Cron] ---- RUN START ----" );
        if ( $mem_start > 0 ) {
            error_log(
                sprintf(
                    "[FFLHub][Lipsey's Inventory Cron] PHP PID=%d, memory_start=%d KB",
                    function_exists( 'getmypid' ) ? getmypid() : 0,
                    (int) round( $mem_start / 1024 )
                )
            );
        }


         // Credentials via centralized Options helper.
        $t_creds         = microtime( true );
        $dealer_email    = trim( Options::get_distributor_option( 'lipseys', 'dealer_email', '' ) );
        $dealer_password = trim( Options::get_distributor_option( 'lipseys', 'dealer_password', '' ) );

        if ( $dealer_email === '' || $dealer_password === '' ) {
            error_log( "[FFLHub][Lipsey's Inventory Cron] ERROR: dealer_email or dealer_password not set." );
            $log_timing( 'Credentials retrieval (failed)', $t_creds );
            $log_timing( 'Total cron run (credentials failed)', $t_start );
            error_log( "[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----" );
            return;
        }
        $log_timing( 'Credentials retrieval', $t_creds );

        // Client creation.
        $t_client = microtime( true );
        try {
            $client = new \lipseys\ApiIntegration\LipseysClient(
                (string) $dealer_email,
                (string) $dealer_password
            );
        } catch ( \Throwable $e ) {
            error_log(
                "[FFLHub][Lipsey's Inventory Cron] ERROR: exception creating LipseysClient: " . $e->getMessage()
            );
            $log_timing( 'Client creation (failed)', $t_client );
            $log_timing( 'Total cron run (client failed)', $t_start );
            error_log( "[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----" );
            return;
        }
        $log_timing( 'Client creation', $t_client );

        // PricingAndQuantity().
        $t_paq = microtime( true );
        try {
            $result = $client->PricingAndQuantity();
        } catch ( \Throwable $e ) {
            error_log(
                "[FFLHub][Lipsey's Inventory Cron] ERROR: exception calling PricingAndQuantity(): " . $e->getMessage()
            );
            $log_timing( 'PricingAndQuantity() call (failed)', $t_paq );
            $log_timing( 'Total cron run (PricingAndQuantity failed)', $t_start );
            error_log( "[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----" );
            return;
        }
        $log_timing( 'PricingAndQuantity() call', $t_paq );

        if ( ! is_array( $result ) ) {
            error_log( "[FFLHub][Lipsey's Inventory Cron] ERROR: PricingAndQuantity() did not return an array." );
            $log_timing( 'Total cron run (bad result)', $t_start );
            error_log( "[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----" );
            return;
        }

        // Validate.
        $t_validate = microtime( true );
        $success    = isset( $result['success'] ) ? (bool) $result['success'] : false;
        $authorized = isset( $result['authorized'] ) ? (bool) $result['authorized'] : false;

        if ( ! $success || ! $authorized ) {
            $errors = isset( $result['errors'] ) && is_array( $result['errors'] )
                ? implode( '; ', array_map( 'strval', $result['errors'] ) )
                : '';
            error_log(
                "[FFLHub][Lipsey's Inventory Cron] ERROR: API response not successful/authorized. "
                . 'success=' . ( $success ? '1' : '0' )
                . ' authorized=' . ( $authorized ? '1' : '0' )
                . ' errors=' . $errors
            );
            $log_timing( 'Response validation (failed)', $t_validate );
            $log_timing( 'Total cron run (validation failed)', $t_start );
            error_log( "[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----" );
            return;
        }

        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            error_log( "[FFLHub][Lipsey's Inventory Cron] ERROR: response missing data object." );
            $log_timing( 'Response validation (missing data)', $t_validate );
            $log_timing( 'Total cron run (missing data)', $t_start );
            error_log( "[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----" );
            return;
        }

        $data = $result['data'];

        if ( isset( $data['nextUpdate'] ) ) {
            update_option(
                'fflhub_lipseys_pricing_quantity_next_update',
                (string) $data['nextUpdate']
            );
        }

        if ( ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
            error_log( "[FFLHub][Lipsey's Inventory Cron] ERROR: data.items missing or not array." );
            $log_timing( 'Response validation (items missing)', $t_validate );
            $log_timing( 'Total cron run (items missing)', $t_start );
            error_log( "[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----" );
            return;
        }

        $items       = $data['items'];
        $items_count = is_array( $items ) ? count( $items ) : 0;

        if ( empty( $items ) ) {
            error_log( "[FFLHub][Lipsey's Inventory Cron] ERROR: data.items is empty." );
            $log_timing( 'Response validation (empty items)', $t_validate );
            $log_timing( 'Total cron run (empty items)', $t_start );
            error_log( "[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----" );
            return;
        }

        $log_timing(
            "Response validation + items extraction (count={$items_count})",
            $t_validate
        );

        // Use the injected double-buffer table to locate the LIVE table.
        $table_name = $this->table->get_live_table_name();

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $total_updated = 0;
        $t_loop        = microtime( true );

        // Wrap all updates in a single transaction via the table helper.
        $this->table->begin_transaction();

        try {
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }

                $item_number = isset( $item['itemNumber'] ) ? trim( (string) $item['itemNumber'] ) : '';
                if ( $item_number === '' ) {
                    continue;
                }

                $upc              = isset( $item['upc'] ) ? trim( (string) $item['upc'] ) : '';
                $mfg_model_number = isset( $item['mfgModelNumber'] ) ? trim( (string) $item['mfgModelNumber'] ) : '';

                $quantity     = $this->to_int_or_null( $item, 'quantity' );
                $allocated    = $this->to_bool_flag( $item, 'allocated' );
                $price        = $this->to_decimal_or_null( $item, 'price' );
                $currentPrice = $this->to_decimal_or_null( $item, 'currentPrice' );
                $retailMap    = $this->to_decimal_or_null( $item, 'retailMap' );

                $effective_price = $currentPrice;

                $data_update = array(
                    'upc'                => $upc,
                    'mfg_model_number'   => $mfg_model_number,
                    'inventory_quantity' => $quantity !== null ? (string) $quantity : '0',
                    'allocation_status'  => $allocated ? 'Y' : '',
                    'distributor_price'  => $effective_price !== null ? (string) $effective_price : '',
                    'retail_map'         => $retailMap !== null ? (string) $retailMap : '',
                );

                $where = array(
                    'lipseys_item_number' => $item_number,
                );

                $format = array(
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                );

                $where_format = array( '%s' );

                $result_update = $wpdb->update(
                    $table_name,
                    $data_update,
                    $where,
                    $format,
                    $where_format
                );

                if ( $result_update === false ) {
                    // Optional: log $wpdb->last_error here if needed.
                    continue;
                }

                if ( $result_update > 0 ) {
                    $total_updated++;
                }
            }

            // If we got here without throwing, commit.
            $this->table->commit_transaction();
        } catch ( \Throwable $e ) {
            // Roll back on any unexpected failure to keep the table consistent.
            $this->table->rollback_transaction();

            error_log(
                "[FFLHub][Lipsey's Inventory Cron] ERROR: exception during update loop, rolled back transaction: "
                . $e->getMessage()
            );

            $log_timing( 'Update loop (failed)', $t_loop );
            $log_timing( 'Total cron run (update loop exception)', $t_start );
            error_log( "[FFLHub][Lipsey's Inventory Cron] ---- RUN END (ERROR) ----" );
            return;
        }

        $t_loop_ms    = ( microtime( true ) - $t_loop ) * 1000;
        $rows_per_sec = $t_loop_ms > 0 ? ( $items_count / ( $t_loop_ms / 1000 ) ) : 0;

        error_log(
            sprintf(
                "[FFLHub][Lipsey's Inventory Cron] Loop + DB updates took %.2f ms (items=%d, updated=%d, ~%.0f items/sec)",
                $t_loop_ms,
                $items_count,
                $total_updated,
                $rows_per_sec
            )
        );

        update_option(
            'fflhub_lipseys_pricing_quantity_last_sync',
            current_time( 'mysql' )
        );
        update_option(
            'fflhub_lipseys_pricing_quantity_last_sync_count',
            $total_updated
        );

        $log_timing( 'Total cron run', $t_start );

        $mem_end = function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0;
        if ( $mem_start > 0 && $mem_end > 0 ) {
            error_log(
                sprintf(
                    "[FFLHub][Lipsey's Inventory Cron] Memory usage summary: start=%d KB, end=%d KB, delta=%+d KB",
                    (int) round( $mem_start / 1024 ),
                    (int) round( $mem_end / 1024 ),
                    (int) round( ( $mem_end - $mem_start ) / 1024 )
                )
            );
        }

        error_log(
            sprintf(
                "[FFLHub][Lipsey's Inventory Cron] ---- RUN END (SUCCESS, updated %d rows out of %d items) ----",
                (int) $total_updated,
                (int) $items_count
            )
        );
    }

    private function to_int_or_null( array $item, string $key ): ?int
    {
        if ( ! isset( $item[ $key ] ) ) {
            return null;
        }
        $val = $item[ $key ];
        return is_numeric( $val ) ? (int) $val : null;
    }

    private function to_decimal_or_null( array $item, string $key ): ?float
    {
        if ( ! isset( $item[ $key ] ) ) {
            return null;
        }
        $val = $item[ $key ];
        return is_numeric( $val ) ? (float) $val : null;
    }

    private function to_bool_flag( array $item, string $key ): int
    {
        if ( ! isset( $item[ $key ] ) ) {
            return 0;
        }

        $val = $item[ $key ];

        if ( is_bool( $val ) ) {
            return $val ? 1 : 0;
        }

        if ( is_numeric( $val ) ) {
            return ( (int) $val ) ? 1 : 0;
        }

        $str = strtolower( trim( (string) $val ) );
        if ( in_array( $str, array( '1', 'true', 'yes', 'y' ), true ) ) {
            return 1;
        }

        return 0;
    }
}
