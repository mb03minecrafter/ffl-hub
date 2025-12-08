<?php

namespace FFLHub\Distributor\RSR\Tables;


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSRFulfillmentTable {

    /**
     * Fully-qualified table name for a given suffix (v1 or v2).
     */
    public static function get_table_name_with_suffix( string $suffix ): string {
        global $wpdb;
        return $wpdb->prefix . RSRFulfillmentSchema::BASE_TABLE_KEY . '_' . $suffix;
    }

    /**
     * Returns the name of the live table (full name with prefix).
     */
    public static function get_live_table_name(): string {
        $default = self::get_table_name_with_suffix( 'v1' );

        $stored = get_option( RSRFulfillmentSchema::LIVE_TABLE_OPTION );
        if ( is_string( $stored ) && $stored !== '' ) {
            $v1 = self::get_table_name_with_suffix( 'v1' );
            $v2 = self::get_table_name_with_suffix( 'v2' );

            if ( $stored === $v1 || $stored === $v2 ) {
                return $stored;
            }

            if ( $stored === 'v1' || $stored === 'v2' ) {
                $normalized = self::get_table_name_with_suffix( $stored );
                update_option( RSRFulfillmentSchema::LIVE_TABLE_OPTION, $normalized );
                return $normalized;
            }
        }

        update_option( RSRFulfillmentSchema::LIVE_TABLE_OPTION, $default );
        return $default;
    }

    /**
     * Returns the staging table name (the “other” one).
     */
    public static function get_staging_table_name(): string {
        $live = self::get_live_table_name();
        $v1   = self::get_table_name_with_suffix( 'v1' );
        $v2   = self::get_table_name_with_suffix( 'v2' );

        return ( $live === $v1 ) ? $v2 : $v1;
    }

    /**
     * Swap live and staging.
     */
    public static function swap_live_and_staging(): string {
        $current_live  = self::get_live_table_name();
        $current_stage = self::get_staging_table_name();

        update_option( RSRFulfillmentSchema::LIVE_TABLE_OPTION, $current_stage );
        update_option( 'fflhub_rsr_fulfillment_last_swap', current_time( 'mysql' ) );

        return $current_stage;
    }

    /**
     * Create v1 and v2 tables (if missing), with columns + indexes
     * defined by FFLHub_RSR_Fulfillment_Schema.
     */
    public static function create_tables(): void {
        global $wpdb;

        $table_v1 = self::get_table_name_with_suffix( 'v1' );
        $table_v2 = self::get_table_name_with_suffix( 'v2' );
        $charset  = $wpdb->get_charset_collate();

        $existing_v1 = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_v1 ) );
        $existing_v2 = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_v2 ) );

        if ( $existing_v1 === $table_v1 && $existing_v2 === $table_v2 ) {
            // Make sure LIVE_TABLE_OPTION is normalized and bail.
            self::get_live_table_name();
            return;
        }

        $cols    = RSRFulfillmentSchema::get_column_definitions();
        $indexes = RSRFulfillmentSchema::get_index_definitions();

        $lines = array();

        foreach ( $cols as $name => $def ) {
            $lines[] = "{$name} {$def}";
        }

        foreach ( $indexes as $idx_def ) {
            $lines[] = $idx_def;
        }

        $create_v1 = "CREATE TABLE {$table_v1} (\n" . implode( ",\n", $lines ) . "\n) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Use dbDelta for v1 so it can manage schema changes.
        dbDelta( $create_v1 );

        // v2 is cloned from v1 (structure + indexes).
        if ( $existing_v2 !== $table_v2 ) {
            $wpdb->query( "CREATE TABLE {$table_v2} LIKE {$table_v1}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        // Normalize the live option.
        self::get_live_table_name();
    }
}





