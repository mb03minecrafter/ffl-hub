<?php


namespace FFLHub\Distributor\Lipseys\Tables;


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lipsey's fulfillment table helper.
 *
 * Manages two identical tables:
 *  - <prefix>fflhub_lipseys_fulfillment_v1
 *  - <prefix>fflhub_lipseys_fulfillment_v2
 *
 * One is "live" (used by reads), the other is "staging" (used by imports).
 * Which is live is controlled by the option fflhub_lipseys_fulfillment_live_table.
 */
class LipseysFulfillmentTable {

    /**
     * Fully-qualified table name for a given suffix (v1 or v2).
     */
    public static function get_table_name_with_suffix( string $suffix ): string {
        global $wpdb;
        return $wpdb->prefix . LipseysFulfillmentSchema::BASE_TABLE_KEY . '_' . $suffix;
    }

    /**
     * Returns the name of the live table (full name with prefix).
     *
     * If the option is missing or invalid, defaults to v1 and normalizes the option.
     */
    public static function get_live_table_name(): string {
        $default = self::get_table_name_with_suffix( 'v1' );

        $stored = get_option( LipseysFulfillmentSchema::LIVE_TABLE_OPTION );
        if ( is_string( $stored ) && $stored !== '' ) {
            $v1 = self::get_table_name_with_suffix( 'v1' );
            $v2 = self::get_table_name_with_suffix( 'v2' );

            // If it already stores the full table name and it's valid, use it.
            if ( $stored === $v1 || $stored === $v2 ) {
                return $stored;
            }

            // If it stores just 'v1' or 'v2', normalize to full name.
            if ( $stored === 'v1' || $stored === 'v2' ) {
                $normalized = self::get_table_name_with_suffix( $stored );
                update_option( LipseysFulfillmentSchema::LIVE_TABLE_OPTION, $normalized );
                return $normalized;
            }
        }

        // Fallback: default to v1 and store that.
        update_option( LipseysFulfillmentSchema::LIVE_TABLE_OPTION, $default );
        return $default;
    }

    /**
     * Returns the name of the staging table (full name with prefix).
     *
     * Staging is simply "the other one" (v2 if v1 is live, or v1 if v2 is live).
     */
    public static function get_staging_table_name(): string {
        $live = self::get_live_table_name();
        $v1   = self::get_table_name_with_suffix( 'v1' );
        $v2   = self::get_table_name_with_suffix( 'v2' );

        return ( $live === $v1 ) ? $v2 : $v1;
    }

    /**
     * Swap live and staging tables by flipping the option.
     *
     * @return string New live table name after swap.
     */
    public static function swap_live_and_staging(): string {
        $current_live  = self::get_live_table_name();
        $current_stage = self::get_staging_table_name();

        update_option( LipseysFulfillmentSchema::LIVE_TABLE_OPTION, $current_stage );
        update_option( 'fflhub_lipseys_fulfillment_last_swap', current_time( 'mysql' ) );

        return $current_stage;
    }

    /**
     * Create both v1 and v2 tables (if missing).
     *
     * We only run dbDelta for v1 (so it can manage ALTERs);
     * v2 is cloned with CREATE TABLE ... LIKE ...
     */
    public static function create_tables(): void {
        global $wpdb;

        $table_v1 = self::get_table_name_with_suffix( 'v1' );
        $table_v2 = self::get_table_name_with_suffix( 'v2' );
        $charset  = $wpdb->get_charset_collate();

        // Check existence first.
        $existing_v1 = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_v1 ) );
        $existing_v2 = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_v2 ) );

        // If both exist, ensure the live option is sane and bail.
        if ( $existing_v1 === $table_v1 && $existing_v2 === $table_v2 ) {
            self::get_live_table_name();
            return;
        }

        $cols    = LipseysFulfillmentSchema::get_column_definitions();
        $indexes = LipseysFulfillmentSchema::get_index_definitions();

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
            $wpdb->query( "CREATE TABLE {$table_v2} LIKE {$table_v1}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        // Normalize live option.
        self::get_live_table_name();
    }
}
