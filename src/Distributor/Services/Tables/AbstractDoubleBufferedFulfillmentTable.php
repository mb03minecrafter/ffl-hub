<?php

namespace FFLHub\Distributor\Services\Tables;


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Generic double-buffered fulfillment table:
 *  - <prefix><base>_v1
 *  - <prefix><base>_v2
 *
 * "Live" vs "staging" is controlled by an option on the schema.
 */
abstract class AbstractDoubleBufferedFulfillmentTable implements DistributorTableInterface {

    /**
     * Schema class (RSR/Lipsey) that implements FulfillmentSchemaInterface.
     *
     * @var class-string<FulfillmentSchemaInterface>
     */
    protected const SCHEMA_CLASS = '';

    /**
     * Optional option name used to record last swap time.
     * e.g. "fflhub_rsr_fulfillment_last_swap"
     */
    protected const SWAP_TIMESTAMP_OPTION = '';

    /**
     * Helper to get the schema class (with a runtime safety check).
     *
     * @return class-string<FulfillmentSchemaInterface>
     */
    protected static function schema(): string {
        $schema = static::SCHEMA_CLASS;

        if ( ! is_string( $schema ) || $schema === '' ) {
            throw new \LogicException(
                'SCHEMA_CLASS must be defined in ' . static::class
            );
        }

        return $schema;
    }

    /**
     * Fully-qualified table name for a given suffix (v1 or v2).
     */
    public static function get_table_name_with_suffix( string $suffix ): string {
        global $wpdb;

        $schema_class = static::schema();

        return $wpdb->prefix . $schema_class::get_base_table_key() . '_' . $suffix;
    }

    /**
     * Returns the name of the live table (full name with prefix).
     */
    public static function get_live_table_name(): string {
        $schema_class = static::schema();

        $default     = static::get_table_name_with_suffix( 'v1' );
        $option_name = $schema_class::get_live_table_option_name();
        $stored      = get_option( $option_name );

        if ( is_string( $stored ) && $stored !== '' ) {
            $v1 = static::get_table_name_with_suffix( 'v1' );
            $v2 = static::get_table_name_with_suffix( 'v2' );

            // Already a full table name?
            if ( $stored === $v1 || $stored === $v2 ) {
                return $stored;
            }

            // Legacy simple 'v1' / 'v2' case: normalize.
            if ( $stored === 'v1' || $stored === 'v2' ) {
                $normalized = static::get_table_name_with_suffix( $stored );
                update_option( $option_name, $normalized );
                return $normalized;
            }
        }

        // Fallback: default to v1 and store that.
        update_option( $option_name, $default );
        return $default;
    }

    /**
     * Returns the staging table name (the “other” one).
     */
    public static function get_staging_table_name(): string {
        $live = static::get_live_table_name();
        $v1   = static::get_table_name_with_suffix( 'v1' );
        $v2   = static::get_table_name_with_suffix( 'v2' );

        return ( $live === $v1 ) ? $v2 : $v1;
    }

    /**
     * Swap live and staging tables by flipping the live-table option.
     *
     * @return string New live table name after swap.
     */
    public static function swap_live_and_staging(): string {
        $schema_class = static::schema();
        $option_name  = $schema_class::get_live_table_option_name();

        $current_live  = static::get_live_table_name();
        $current_stage = static::get_staging_table_name();

        update_option( $option_name, $current_stage );

        if ( static::SWAP_TIMESTAMP_OPTION !== '' ) {
            update_option(
                static::SWAP_TIMESTAMP_OPTION,
                current_time( 'mysql' )
            );
        }

        return $current_stage;
    }

    /**
     * Create both v1 and v2 tables (if missing).
     *
     * We only run dbDelta for v1; v2 is cloned via CREATE TABLE ... LIKE ...
     */
    public static function create_tables(): void {
        global $wpdb;

        $table_v1 = static::get_table_name_with_suffix( 'v1' );
        $table_v2 = static::get_table_name_with_suffix( 'v2' );
        $charset  = $wpdb->get_charset_collate();

        $existing_v1 = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_v1 )
        );
        $existing_v2 = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_v2 )
        );

        // If both exist, just normalize the live option and bail.
        if ( $existing_v1 === $table_v1 && $existing_v2 === $table_v2 ) {
            static::get_live_table_name();
            return;
        }

        $schema_class = static::schema();

        $cols    = $schema_class::get_column_definitions();
        $indexes = $schema_class::get_index_definitions();

        $lines = array();

        foreach ( $cols as $name => $def ) {
            $lines[] = "{$name} {$def}";
        }

        foreach ( $indexes as $idx_def ) {
            $lines[] = $idx_def;
        }

        $create_v1 = "CREATE TABLE {$table_v1} (\n" . implode( ",\n", $lines ) . "\n) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Use dbDelta for v1 so WP can manage future schema changes.
        dbDelta( $create_v1 );

        // v2: clone structure + indexes from v1 if v2 does not exist yet.
        if ( $existing_v2 !== $table_v2 ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( "CREATE TABLE {$table_v2} LIKE {$table_v1}" );
        }

        // Normalize the live option.
        static::get_live_table_name();
    }
}
