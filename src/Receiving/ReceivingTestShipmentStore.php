<?php
declare(strict_types=1);

namespace FFLHub\Receiving;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Debug-only shipment fixture store for scanner testing.
 *
 * Real Receiving still uses dealer-fulfilled order job rows. This table only
 * lets the test-label page create a fake tracking number that the Receiving
 * page can discover when debug mode is explicitly enabled.
 */
final class ReceivingTestShipmentStore
{
    private const BASE_TABLE = 'fflhub_receiving_test_shipments';
    private const SCHEMA_OPTION = 'fflhub_receiving_test_shipments_schema_version';
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

        $table = self::table_name();
        $charset = (string) $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("
            CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                shipment_key VARCHAR(64) NOT NULL,
                tracking_number VARCHAR(128) NOT NULL DEFAULT '',
                payload_json LONGTEXT NOT NULL,
                created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY shipment_key (shipment_key),
                KEY tracking_number (tracking_number),
                KEY updated_at (updated_at)
            ) {$charset};
        ");

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        self::$schema_checked = true;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function upsert(array $payload): bool
    {
        global $wpdb;

        self::ensure_schema();

        $shipment_key = trim((string) ($payload['shipment_key'] ?? ''));
        $tracking = trim((string) ($payload['primary_tracking'] ?? ''));
        if ($tracking === '') {
            $tracking_numbers = (array) ($payload['tracking_numbers'] ?? []);
            $tracking = trim((string) ($tracking_numbers[0] ?? ''));
        }
        if ($shipment_key === '' || $tracking === '') {
            return false;
        }

        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM " . self::table_name() . " WHERE shipment_key = %s LIMIT 1",
                $shipment_key
            )
        );

        $now = current_time('mysql', true);
        $json = wp_json_encode($payload);
        $json = is_string($json) ? $json : '{}';

        if ($existing_id > 0) {
            $updated = $wpdb->update(
                self::table_name(),
                [
                    'tracking_number' => $tracking,
                    'payload_json' => $json,
                    'updated_at' => $now,
                ],
                ['id' => $existing_id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            return $updated !== false;
        }

        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'shipment_key' => $shipment_key,
                'tracking_number' => $tracking,
                'payload_json' => $json,
                'created_by' => max(0, (int) get_current_user_id()),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s']
        );

        return $inserted !== false;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find_by_tracking(string $tracking): ?array
    {
        global $wpdb;

        self::ensure_schema();

        $tracking = trim($tracking);
        if ($tracking === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT payload_json FROM " . self::table_name() . " WHERE tracking_number = %s ORDER BY updated_at DESC LIMIT 1",
                $tracking
            ),
            ARRAY_A
        );

        return $this->payload_from_row(is_array($row) ? $row : []);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find_by_key(string $shipment_key): ?array
    {
        global $wpdb;

        self::ensure_schema();

        $shipment_key = trim($shipment_key);
        if ($shipment_key === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT payload_json FROM " . self::table_name() . " WHERE shipment_key = %s LIMIT 1",
                $shipment_key
            ),
            ARRAY_A
        );

        return $this->payload_from_row(is_array($row) ? $row : []);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function payload_from_row(array $row): ?array
    {
        $json = (string) ($row['payload_json'] ?? '');
        if ($json === '') {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private static function table_exists(): bool
    {
        global $wpdb;

        $table = self::table_name();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($found) && $found === $table;
    }
}
