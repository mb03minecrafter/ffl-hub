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
    private const SCHEMA_VERSION = '3';

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
                serial_number VARCHAR(128) NOT NULL DEFAULT '',
                quantity INT UNSIGNED NOT NULL DEFAULT 0,
                wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                result VARCHAR(32) NOT NULL DEFAULT '',
                exception_status VARCHAR(32) NOT NULL DEFAULT '',
                message TEXT NULL,
                raw_scan VARCHAR(191) NOT NULL DEFAULT '',
                normalized_scan VARCHAR(191) NOT NULL DEFAULT '',
                request_token VARCHAR(64) NOT NULL DEFAULT '',
                fastbound_acquisition_id VARCHAR(64) DEFAULT NULL,
                fastbound_acquisition_item_id VARCHAR(64) DEFAULT NULL,
                fastbound_disposition_id VARCHAR(64) DEFAULT NULL,
                fastbound_disposition_contact_id VARCHAR(64) DEFAULT NULL,
                fastbound_status VARCHAR(32) NOT NULL DEFAULT '',
                fastbound_error TEXT NULL,
                fastbound_manufacturer VARCHAR(100) NOT NULL DEFAULT '',
                fastbound_model VARCHAR(100) NOT NULL DEFAULT '',
                fastbound_caliber VARCHAR(100) NOT NULL DEFAULT '',
                fastbound_firearm_type VARCHAR(100) NOT NULL DEFAULT '',
                fastbound_acquired_at DATETIME DEFAULT NULL,
                fastbound_disposed_at DATETIME DEFAULT NULL,
                received_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY shipment_request_token (shipment_key, request_token),
                KEY shipment_key (shipment_key),
                KEY shipment_upc_result (shipment_key, upc, result),
                KEY shipment_serial_result (shipment_key, serial_number, result),
                KEY job_upc_result (job_id, upc, result),
                KEY order_result (order_id, result),
                KEY serial_number (serial_number),
                KEY received_at (received_at),
                KEY tracking_number (tracking_number),
                KEY merchant_po (merchant_po),
                KEY fastbound_status (fastbound_status),
                KEY fastbound_acquisition_item_id (fastbound_acquisition_item_id)
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
            'serial_number' => $this->text($data['serial_number'] ?? '', 128),
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
                '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s',
            ]
        );

        return (int) $wpdb->insert_id;
    }

    public function accepted_serial_exists(string $shipment_key, string $serial_number): bool
    {
        return $this->accepted_serial_exists_except($shipment_key, $serial_number, 0);
    }

    public function accepted_serial_exists_except(string $shipment_key, string $serial_number, int $excluded_event_id): bool
    {
        global $wpdb;

        $shipment_key = trim($shipment_key);
        $serial_number = ReceivingShipmentService::normalize_serial($serial_number);
        if ($shipment_key === '' || $serial_number === '') {
            return false;
        }

        self::ensure_schema();

        $found = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM " . self::table_name() . "
                 WHERE shipment_key = %s
                   AND serial_number = %s
                   AND result = 'accepted'
                   AND id <> %d
                 LIMIT 1",
                $shipment_key,
                $serial_number,
                max(0, $excluded_event_id)
            )
        );

        return $found > 0;
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
     * @return array<string,mixed>|null
     */
    public function find_by_id(int $event_id): ?array
    {
        global $wpdb;

        if ($event_id <= 0) {
            return null;
        }

        self::ensure_schema();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table_name() . " WHERE id = %d LIMIT 1",
                $event_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update_fastbound_fields(int $event_id, array $data): bool
    {
        global $wpdb;

        if ($event_id <= 0 || empty($data)) {
            return false;
        }

        self::ensure_schema();

        $allowed = [
            'fastbound_acquisition_id' => 64,
            'fastbound_acquisition_item_id' => 64,
            'fastbound_disposition_id' => 64,
            'fastbound_disposition_contact_id' => 64,
            'fastbound_status' => 32,
            'fastbound_error' => 2000,
            'fastbound_manufacturer' => 100,
            'fastbound_model' => 100,
            'fastbound_caliber' => 100,
            'fastbound_firearm_type' => 100,
            'fastbound_acquired_at' => 32,
            'fastbound_disposed_at' => 32,
        ];

        $row = [];
        $formats = [];
        foreach ($allowed as $column => $max) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            if (in_array($column, ['fastbound_acquired_at', 'fastbound_disposed_at'], true) && ($data[$column] === null || $data[$column] === '')) {
                $row[$column] = null;
            } elseif ($column === 'fastbound_error') {
                $row[$column] = $this->textarea($data[$column] ?? '', $max);
            } else {
                $row[$column] = $this->text($data[$column] ?? '', $max);
            }
            $formats[] = '%s';
        }

        if (empty($row)) {
            return false;
        }

        $updated = $wpdb->update(
            self::table_name(),
            $row,
            ['id' => $event_id],
            $formats,
            ['%d']
        );

        return $updated !== false;
    }

    public function update_serial_number(int $event_id, string $new_serial_number, string $message): bool
    {
        global $wpdb;

        $event_id = absint($event_id);
        $new_serial_number = ReceivingShipmentService::normalize_serial($new_serial_number);
        if ($event_id <= 0 || $new_serial_number === '') {
            return false;
        }

        self::ensure_schema();

        $updated = $wpdb->update(
            self::table_name(),
            [
                'serial_number' => $this->text($new_serial_number, 128),
                'message' => $this->text($message, 1000),
            ],
            ['id' => $event_id],
            ['%s', '%s'],
            ['%d']
        );

        return $updated !== false;
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
     * Return the accepted firearm serials already captured during receiving,
     * keyed by Woo order item. WMS sending uses this to print the exact serial
     * on the outbound packing slip without making the packing service know how
     * receiving shipments are scanned.
     *
     * @param int[] $order_item_ids
     * @return array<int,string[]>
     */
    public function accepted_serials_by_order_item_ids(array $order_item_ids): array
    {
        global $wpdb;

        $order_item_ids = array_values(array_unique(array_filter(array_map('absint', $order_item_ids))));
        if (empty($order_item_ids)) {
            return [];
        }

        self::ensure_schema();

        $placeholders = implode(',', array_fill(0, count($order_item_ids), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT order_item_id, serial_number, quantity
                 FROM " . self::table_name() . "
                 WHERE result = 'accepted'
                   AND order_item_id IN ({$placeholders})
                   AND serial_number <> ''
                 ORDER BY id ASC",
                ...$order_item_ids
            ),
            ARRAY_A
        );

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $item_id = absint($row['order_item_id'] ?? 0);
            $serial = trim((string) ($row['serial_number'] ?? ''));
            if ($item_id <= 0 || $serial === '') {
                continue;
            }

            $quantity = max(1, (int) ($row['quantity'] ?? 1));
            for ($i = 0; $i < $quantity; $i++) {
                $out[$item_id][] = $serial;
            }
        }

        return $out;
    }

    /**
     * Sending/disposition needs the exact receiving event that captured a
     * firearm serial, because that row stores the FastBound acquisition item ID.
     *
     * @return array<string,mixed>|null
     */
    public function accepted_event_for_order_item_serial(int $order_item_id, string $serial_number): ?array
    {
        global $wpdb;

        $order_item_id = absint($order_item_id);
        $serial_number = ReceivingShipmentService::normalize_serial($serial_number);
        if ($order_item_id <= 0 || $serial_number === '') {
            return null;
        }

        self::ensure_schema();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM " . self::table_name() . "
                 WHERE result = 'accepted'
                   AND order_item_id = %d
                   AND serial_number = %s
                 ORDER BY id ASC
                 LIMIT 1",
                $order_item_id,
                $serial_number
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
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
    public function recent_serialized_events(int $limit = 25): array
    {
        global $wpdb;

        $limit = max(1, min(100, $limit));

        self::ensure_schema();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM " . self::table_name() . "
                 WHERE result = 'accepted'
                   AND serial_number <> ''
                 ORDER BY id DESC
                 LIMIT %d",
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

    /**
     * @param mixed $value
     */
    private function textarea($value, int $max): string
    {
        $text = sanitize_textarea_field((string) ($value ?? ''));
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
