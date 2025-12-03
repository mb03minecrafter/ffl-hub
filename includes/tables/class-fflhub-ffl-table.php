<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Small helper responsible only for the FFL DB table.
 */
class FFLHub_FFL_Table {

    const TABLE_NAME_KEY = 'fflhub_ffls';

    /**
     * Returns the fully qualified table name (with prefix).
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME_KEY;
    }

    /**
     * Create / update the FFL table.
     *
     * Columns are modeled directly after the ATF TXT format, plus:
     * - ffl_number: derived from LIC_REGN-LIC_DIST-LIC_CNTY-LIC_TYPE-LIC_XPRDTE-LIC_SEQN
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name      = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // If the table already exists, don't run dbDelta again.
        $existing = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
        );

        if ( $existing === $table_name ) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ffl_number     VARCHAR(64)  NOT NULL,
            license_name   VARCHAR(255) NOT NULL,
            premise_street VARCHAR(255) NOT NULL,
            premise_city   VARCHAR(128) NOT NULL,
            premise_state  CHAR(2)      NOT NULL,
            premise_zip    VARCHAR(10)  NOT NULL,
            mail_street    VARCHAR(255) NULL,
            mail_city      VARCHAR(128) NULL,
            mail_state     CHAR(2)      NULL,
            mail_zip       VARCHAR(10)  NULL,
            voice_phone    VARCHAR(32)  NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ffl_number (ffl_number),
            KEY premise_zip   (premise_zip),
            KEY premise_state (premise_state)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
