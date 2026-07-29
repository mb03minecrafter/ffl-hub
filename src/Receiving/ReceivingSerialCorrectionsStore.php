<?php
declare(strict_types=1);

namespace FFLHub\Receiving;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable audit log for receiving serial-number corrections.
 *
 * A correction changes the operational serial stored on the receiving event.
 * This table keeps the old value, the new value, the operator, and whether the
 * operator confirmed a matching FastBound manual correction was handled.
 */
final class ReceivingSerialCorrectionsStore
{
    private const BASE_TABLE = 'fflhub_receiving_serial_corrections';
    private const SCHEMA_OPTION = 'fflhub_receiving_serial_corrections_schema_version';
    private const SCHEMA_VERSION = '1';

    private static bool $schema_checked = false;

    public static function table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . self::BASE_TABLE);
    }

    public static function ensure_schema(): void
    {
        global $wpdb;

        if (!$wpdb) {
            return;
        }

        if (
            self::$schema_checked
            || ((string) get_option(self::SCHEMA_OPTION, '') === self::SCHEMA_VERSION && self::table_exists())
        ) {
            self::$schema_checked = true;
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = (string) $wpdb->get_charset_collate();

        dbDelta("
            CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                shipment_key VARCHAR(191) NOT NULL DEFAULT '',
                order_id BIGINT UNSIGNED DEFAULT NULL,
                order_item_id BIGINT UNSIGNED DEFAULT NULL,
                upc VARCHAR(64) NOT NULL DEFAULT '',
                old_serial_number VARCHAR(128) NOT NULL DEFAULT '',
                new_serial_number VARCHAR(128) NOT NULL DEFAULT '',
                fastbound_acquisition_item_id VARCHAR(64) NOT NULL DEFAULT '',
                fastbound_disposition_id VARCHAR(64) NOT NULL DEFAULT '',
                fastbound_manual_confirmed TINYINT(1) NOT NULL DEFAULT 0,
                note TEXT NULL,
                wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY event_id (event_id),
                KEY shipment_key (shipment_key),
                KEY order_item_id (order_item_id),
                KEY new_serial_number (new_serial_number),
                KEY created_at (created_at)
            ) {$charset};
        ");

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        self::$schema_checked = true;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function insert(array $row): int
    {
        global $wpdb;

        self::ensure_schema();

        $wpdb->insert(
            self::table_name(),
            [
                'event_id' => max(0, (int) ($row['event_id'] ?? 0)),
                'shipment_key' => self::text($row['shipment_key'] ?? '', 191),
                'order_id' => self::nullable_int($row['order_id'] ?? null),
                'order_item_id' => self::nullable_int($row['order_item_id'] ?? null),
                'upc' => self::text($row['upc'] ?? '', 64),
                'old_serial_number' => self::text($row['old_serial_number'] ?? '', 128),
                'new_serial_number' => self::text($row['new_serial_number'] ?? '', 128),
                'fastbound_acquisition_item_id' => self::text($row['fastbound_acquisition_item_id'] ?? '', 64),
                'fastbound_disposition_id' => self::text($row['fastbound_disposition_id'] ?? '', 64),
                'fastbound_manual_confirmed' => !empty($row['fastbound_manual_confirmed']) ? 1 : 0,
                'note' => self::textarea($row['note'] ?? '', 1000),
                'wp_user_id' => max(0, (int) ($row['wp_user_id'] ?? get_current_user_id())),
                'created_at' => current_time('mysql', true),
            ],
            [
                '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s',
            ]
        );

        return (int) $wpdb->insert_id;
    }

    private static function table_exists(): bool
    {
        global $wpdb;

        $table = self::table_name();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($found) && $found === $table;
    }

    /**
     * @param mixed $value
     */
    private static function nullable_int($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    /**
     * @param mixed $value
     */
    private static function text($value, int $max): string
    {
        $text = sanitize_text_field((string) ($value ?? ''));
        if ($max > 0 && strlen($text) > $max) {
            $text = substr($text, 0, $max);
        }

        return $text;
    }

    /**
     * @param mixed $value
     */
    private static function textarea($value, int $max): string
    {
        $text = sanitize_textarea_field((string) ($value ?? ''));
        if ($max > 0 && strlen($text) > $max) {
            $text = substr($text, 0, $max);
        }

        return $text;
    }
}
