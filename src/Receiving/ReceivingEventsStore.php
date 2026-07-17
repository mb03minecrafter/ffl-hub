<?php
declare(strict_types=1);

namespace FFLHub\Receiving;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistent audit trail for warehouse receiving scans.
 *
 * The shipment/PO source of truth remains wp_fflhub_place_jobs. This table only
 * records what was scanned, which existing job/order allocation received it,
 * and why a scan was accepted or rejected.
 */
final class ReceivingEventsStore
{
    private const BASE_TABLE = 'fflhub_receiving_events';
    private const SCHEMA_OPTION = 'fflhub_receiving_events_schema_version';
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
                shipment_key VARCHAR(191) NOT NULL,
                job_id BIGINT UNSIGNED DEFAULT NULL,
                order_id BIGINT UNSIGNED DEFAULT NULL,
                order_item_id BIGINT UNSIGNED DEFAULT NULL,
                dist_id VARCHAR(32) NOT NULL DEFAULT '',
                lane VARCHAR(32) NOT NULL DEFAULT '',
                merchant_po VARCHAR(64) NOT NULL DEFAULT '',
                tracking_number VARCHAR(128) NOT NULL DEFAULT '',
                product_id BIGINT UNSIGNED DEFAULT NULL,
                upc VARCHAR(64) NOT NULL DEFAULT '',
                quantity INT UNSIGNED NOT NULL DEFAULT 0,
                wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                result VARCHAR(32) NOT NULL DEFAULT '',
                exception_status VARCHAR(32) NOT NULL DEFAULT '',
                message TEXT NULL,
                raw_scan VARCHAR(191) NOT NULL DEFAULT '',
                normalized_scan VARCHAR(191) NOT NULL DEFAULT '',
                request_token VARCHAR(64) NOT NULL DEFAULT '',
                received_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY shipment_request_token (shipment_key, request_token),
                KEY shipment_key (shipment_key),
                KEY shipment_upc_result (shipment_key, upc, result),
                KEY job_upc_result (job_id, upc, result),
                KEY order_result (order_id, result),
                KEY received_at (received_at),
                KEY tracking_number (tracking_number),
                KEY merchant_po (merchant_po)
            ) {$charset};
        ");

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        self::$schema_checked = true;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function insert_event(array $data): int
    {
        global $wpdb;

        self::ensure_schema();

        $now = current_time('mysql', true);
        $row = [
            'shipment_key' => $this->text($data['shipment_key'] ?? '', 191),
            'job_id' => $this->nullable_int($data['job_id'] ?? null),
            'order_id' => $this->nullable_int($data['order_id'] ?? null),
            'order_item_id' => $this->nullable_int($data['order_item_id'] ?? null),
            'dist_id' => $this->text($data['dist_id'] ?? '', 32),
            'lane' => $this->text($data['lane'] ?? '', 32),
            'merchant_po' => $this->text($data['merchant_po'] ?? '', 64),
            'tracking_number' => $this->text($data['tracking_number'] ?? '', 128),
            'product_id' => $this->nullable_int($data['product_id'] ?? null),
            'upc' => $this->text($data['upc'] ?? '', 64),
            'quantity' => max(0, (int) ($data['quantity'] ?? 0)),
            'wp_user_id' => max(0, (int) ($data['wp_user_id'] ?? get_current_user_id())),
            'result' => $this->text($data['result'] ?? '', 32),
            'exception_status' => $this->text($data['exception_status'] ?? '', 32),
            'message' => $this->text($data['message'] ?? '', 1000),
            'raw_scan' => $this->text($data['raw_scan'] ?? '', 191),
            'normalized_scan' => $this->text($data['normalized_scan'] ?? '', 191),
            'request_token' => $this->text($data['request_token'] ?? '', 64),
            'received_at' => $this->text($data['received_at'] ?? $now, 32),
            'created_at' => $now,
        ];

        $wpdb->insert(
            self::table_name(),
            $row,
            [
                '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s',
                '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find_by_request_token(string $shipment_key, string $request_token): ?array
    {
        global $wpdb;

        $shipment_key = trim($shipment_key);
        $request_token = trim($request_token);
        if ($shipment_key === '' || $request_token === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table_name() . " WHERE shipment_key = %s AND request_token = %s LIMIT 1",
                $shipment_key,
                $request_token
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,int>
     */
    public function accepted_quantities_by_upc(string $shipment_key): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT upc, SUM(quantity) AS qty
                 FROM " . self::table_name() . "
                 WHERE shipment_key = %s AND result = 'accepted'
                 GROUP BY upc",
                $shipment_key
            ),
            ARRAY_A
        );

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $upc = trim((string) ($row['upc'] ?? ''));
            if ($upc !== '') {
                $out[$upc] = max(0, (int) ($row['qty'] ?? 0));
            }
        }

        return $out;
    }

    /**
     * @return array<string,int>
     */
    public function accepted_quantities_by_job_upc(string $shipment_key): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT job_id, upc, SUM(quantity) AS qty
                 FROM " . self::table_name() . "
                 WHERE shipment_key = %s AND result = 'accepted'
                   AND job_id IS NOT NULL
                 GROUP BY job_id, upc",
                $shipment_key
            ),
            ARRAY_A
        );

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $job_id = (int) ($row['job_id'] ?? 0);
            $upc = trim((string) ($row['upc'] ?? ''));
            if ($job_id > 0 && $upc !== '') {
                $out[$job_id . '|' . $upc] = max(0, (int) ($row['qty'] ?? 0));
            }
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent_events(string $shipment_key, int $limit = 50): array
    {
        global $wpdb;

        $limit = max(1, min(200, $limit));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM " . self::table_name() . "
                 WHERE shipment_key = %s
                 ORDER BY id DESC
                 LIMIT %d",
                $shipment_key,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent_history(int $limit = 25): array
    {
        global $wpdb;

        $limit = max(1, min(100, $limit));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    shipment_key,
                    dist_id,
                    merchant_po,
                    MIN(tracking_number) AS tracking_number,
                    SUM(CASE WHEN result = 'accepted' THEN quantity ELSE 0 END) AS received_units,
                    MIN(created_at) AS started_at,
                    MAX(created_at) AS last_event_at,
                    MAX(wp_user_id) AS last_user_id
                 FROM " . self::table_name() . "
                 GROUP BY shipment_key, dist_id, merchant_po
                 ORDER BY last_event_at DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param mixed $value
     */
    private function nullable_int($value): ?int
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
    private function text($value, int $max): string
    {
        $text = sanitize_text_field((string) ($value ?? ''));
        if ($max > 0 && strlen($text) > $max) {
            $text = substr($text, 0, $max);
        }

        return $text;
    }

    private static function table_exists(): bool
    {
        global $wpdb;

        $table = self::table_name();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($found) && $found === $table;
    }
}
