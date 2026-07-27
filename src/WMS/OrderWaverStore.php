<?php
declare(strict_types=1);

namespace FFLHub\WMS;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable workflow store for outbound WMS order waves.
 *
 * A wave batch is intentionally separate from the EasyPost label batch:
 * - the wave batch tracks our internal packing/labeling state per Woo order;
 * - the EasyPost batch tracks the provider-side label purchase state.
 *
 * Keeping both lets the Order Waver show exactly where an order failed without
 * having to infer state from Woo order meta or provider responses.
 */
final class OrderWaverStore
{
    public const BATCH_STATUS_QUEUED = 'queued';
    public const BATCH_STATUS_PACKING = 'packing';
    public const BATCH_STATUS_READY_FOR_LABELS = 'ready_for_labels';
    public const BATCH_STATUS_LABELING = 'labeling';
    public const BATCH_STATUS_LABELS_SAVED = 'labels_saved';
    public const BATCH_STATUS_PARTIAL_LABELS_SAVED = 'partial_labels_saved';
    public const BATCH_STATUS_FAILED = 'failed';

    public const ORDER_STATUS_QUEUED = 'queued';
    public const ORDER_STATUS_PACKING = 'packing';
    public const ORDER_STATUS_PACKED = 'packed';
    public const ORDER_STATUS_PACKING_FAILED = 'packing_failed';
    public const ORDER_STATUS_LABEL_SAVED = 'label_saved';
    public const ORDER_STATUS_LABEL_FAILED = 'label_failed';
    public const ORDER_STATUS_SHIPPED = 'shipped';

    /**
     * Wave order statuses that should prevent the same Woo order from being
     * selected into a second active wave.
     */
    private const ACTIVE_ORDER_STATUSES = [
        self::ORDER_STATUS_QUEUED,
        self::ORDER_STATUS_PACKING,
        self::ORDER_STATUS_PACKED,
    ];

    public static function batches_table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . 'fflhub_order_waver_batches');
    }

    public static function orders_table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . 'fflhub_order_waver_batch_orders');
    }

    public static function logs_table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . 'fflhub_order_waver_logs');
    }

    public static function ensure_schema(): void
    {
        global $wpdb;

        $charset = (string) $wpdb->get_charset_collate();
        $batches = self::batches_table_name();
        $orders = self::orders_table_name();
        $logs = self::logs_table_name();

        $sql = "CREATE TABLE {$batches} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
batch_key VARCHAR(80) NOT NULL DEFAULT '',
status VARCHAR(40) NOT NULL DEFAULT '',
created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
selected_count INT UNSIGNED NOT NULL DEFAULT 0,
queued_count INT UNSIGNED NOT NULL DEFAULT 0,
packed_count INT UNSIGNED NOT NULL DEFAULT 0,
failed_count INT UNSIGNED NOT NULL DEFAULT 0,
label_saved_count INT UNSIGNED NOT NULL DEFAULT 0,
easypost_batch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
error_message TEXT NULL,
locked_at DATETIME NULL,
packed_at DATETIME NULL,
labels_at DATETIME NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY batch_key (batch_key),
KEY status_updated_at (status, updated_at),
KEY easypost_batch_id (easypost_batch_id),
KEY created_at (created_at)
) {$charset};

CREATE TABLE {$orders} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
batch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
order_number VARCHAR(60) NOT NULL DEFAULT '',
status VARCHAR(40) NOT NULL DEFAULT '',
fail_reason TEXT NULL,
debug_ready TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
packages_json LONGTEXT NULL,
package_items_json LONGTEXT NULL,
packing_slips_json LONGTEXT NULL,
label_count INT UNSIGNED NOT NULL DEFAULT 0,
package_count INT UNSIGNED NOT NULL DEFAULT 0,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY batch_order (batch_id, order_id),
KEY order_status (order_id, status),
KEY batch_status (batch_id, status),
KEY status_updated_at (status, updated_at)
) {$charset};

CREATE TABLE {$logs} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
batch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
level VARCHAR(20) NOT NULL DEFAULT 'info',
stage VARCHAR(40) NOT NULL DEFAULT '',
message TEXT NULL,
context_json LONGTEXT NULL,
created_at DATETIME NOT NULL,
PRIMARY KEY  (id),
KEY batch_created_at (batch_id, created_at),
KEY order_created_at (order_id, created_at),
KEY stage_created_at (stage, created_at)
) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * @param array<int,array{order_id:int,order_number:string,debug_ready?:bool|int}> $orders
     */
    public static function create_batch(array $orders, int $created_by = 0): int
    {
        global $wpdb;

        self::ensure_schema();

        $orders = array_values(array_filter($orders, static function (array $row): bool {
            return (int) ($row['order_id'] ?? 0) > 0;
        }));
        if (empty($orders)) {
            return 0;
        }

        $now = current_time('mysql', true);
        $batch_key = self::new_batch_key();

        $wpdb->insert(self::batches_table_name(), [
            'batch_key' => $batch_key,
            'status' => self::BATCH_STATUS_QUEUED,
            'created_by' => max(0, $created_by),
            'selected_count' => count($orders),
            'queued_count' => count($orders),
            'packed_count' => 0,
            'failed_count' => 0,
            'label_saved_count' => 0,
            'easypost_batch_id' => 0,
            'error_message' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            '%s',
            '%s',
            '%d',
            '%d',
            '%d',
            '%d',
            '%d',
            '%d',
            '%d',
            '%s',
            '%s',
            '%s',
        ]);

        $batch_id = (int) $wpdb->insert_id;
        if ($batch_id <= 0) {
            return 0;
        }

        foreach ($orders as $row) {
            $wpdb->insert(self::orders_table_name(), [
                'batch_id' => $batch_id,
                'order_id' => (int) $row['order_id'],
                'order_number' => sanitize_text_field((string) ($row['order_number'] ?? '')),
                'status' => self::ORDER_STATUS_QUEUED,
                'fail_reason' => '',
                'debug_ready' => !empty($row['debug_ready']) ? 1 : 0,
                'packages_json' => '',
                'package_items_json' => '',
                'packing_slips_json' => '',
                'label_count' => 0,
                'package_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%s',
                '%s',
            ]);
        }

        self::log($batch_id, 0, 'info', 'wave_created', sprintf('Wave %s created with %d order(s).', $batch_key, count($orders)), [
            'selected_count' => count($orders),
            'created_by' => $created_by,
        ]);

        return $batch_id;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get_batch(int $batch_id): ?array
    {
        global $wpdb;

        self::ensure_schema();
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::batches_table_name() . ' WHERE id = %d', $batch_id),
            ARRAY_A
        );

        return is_array($row) ? self::hydrate_batch($row) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function claim_next_batch(string $from_status, string $to_status): ?array
    {
        global $wpdb;

        self::ensure_schema();
        $table = self::batches_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT 1",
                $from_status
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }

        $batch_id = (int) ($row['id'] ?? 0);
        $now = current_time('mysql', true);
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = %s, locked_at = %s, updated_at = %s
             WHERE id = %d AND status = %s",
            $to_status,
            $now,
            $now,
            $batch_id,
            $from_status
        ));

        if ((int) $updated !== 1) {
            return null;
        }

        self::log($batch_id, 0, 'info', $to_status, sprintf('Batch claimed for %s.', $to_status));

        return self::get_batch($batch_id);
    }

    /**
     * @param array<string,mixed> $fields
     */
    public static function update_batch(int $batch_id, array $fields): void
    {
        global $wpdb;

        self::ensure_schema();
        $allowed = [
            'status' => '%s',
            'selected_count' => '%d',
            'queued_count' => '%d',
            'packed_count' => '%d',
            'failed_count' => '%d',
            'label_saved_count' => '%d',
            'easypost_batch_id' => '%d',
            'error_message' => '%s',
            'locked_at' => '%s',
            'packed_at' => '%s',
            'labels_at' => '%s',
        ];

        [$data, $formats] = self::filter_fields($fields, $allowed);
        if (empty($data)) {
            return;
        }

        $data['updated_at'] = current_time('mysql', true);
        $formats[] = '%s';

        $wpdb->update(self::batches_table_name(), $data, ['id' => $batch_id], $formats, ['%d']);
    }

    /**
     * @param array<string,mixed> $fields
     */
    public static function update_order(int $row_id, array $fields): void
    {
        global $wpdb;

        self::ensure_schema();
        $allowed = [
            'status' => '%s',
            'fail_reason' => '%s',
            'debug_ready' => '%d',
            'packages_json' => '%s',
            'package_items_json' => '%s',
            'packing_slips_json' => '%s',
            'label_count' => '%d',
            'package_count' => '%d',
        ];

        foreach (['packages_json', 'package_items_json', 'packing_slips_json'] as $json_field) {
            if (array_key_exists($json_field, $fields) && is_array($fields[$json_field])) {
                $fields[$json_field] = wp_json_encode($fields[$json_field]);
            }
        }

        [$data, $formats] = self::filter_fields($fields, $allowed);
        if (empty($data)) {
            return;
        }

        $data['updated_at'] = current_time('mysql', true);
        $formats[] = '%s';

        $wpdb->update(self::orders_table_name(), $data, ['id' => $row_id], $formats, ['%d']);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function orders_for_batch(int $batch_id, array $statuses = []): array
    {
        global $wpdb;

        self::ensure_schema();
        $table = self::orders_table_name();
        $sql = "SELECT * FROM {$table} WHERE batch_id = %d";
        $args = [$batch_id];

        if (!empty($statuses)) {
            $statuses = array_values(array_filter(array_map('strval', $statuses)));
            $sql .= ' AND status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')';
            $args = array_merge($args, $statuses);
        }

        $sql .= ' ORDER BY id ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_values(array_map([self::class, 'hydrate_order'], is_array($rows) ? $rows : []));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function active_order_rows(): array
    {
        global $wpdb;

        self::ensure_schema();
        $statuses = self::ACTIVE_ORDER_STATUSES;
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::orders_table_name() . " WHERE status IN ({$placeholders}) ORDER BY id DESC",
                ...$statuses
            ),
            ARRAY_A
        );

        return array_values(array_map([self::class, 'hydrate_order'], is_array($rows) ? $rows : []));
    }

    /**
     * @return array<int,array<string,mixed>> order_id => active wave row
     */
    public static function active_order_rows_by_order_id(): array
    {
        $out = [];
        foreach (self::active_order_rows() as $row) {
            $order_id = (int) ($row['order_id'] ?? 0);
            if ($order_id > 0 && !isset($out[$order_id])) {
                $out[$order_id] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>> order_id => failed wave row
     */
    public static function latest_failure_rows_by_order_id(int $limit = 300): array
    {
        global $wpdb;

        self::ensure_schema();
        $limit = max(1, min(1000, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::orders_table_name() . "
                 WHERE status IN (%s, %s)
                 ORDER BY updated_at DESC, id DESC
                 LIMIT %d",
                self::ORDER_STATUS_PACKING_FAILED,
                self::ORDER_STATUS_LABEL_FAILED,
                $limit
            ),
            ARRAY_A
        );

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $hydrated = self::hydrate_order($row);
            $order_id = (int) ($hydrated['order_id'] ?? 0);
            if ($order_id > 0 && !isset($out[$order_id])) {
                $out[$order_id] = $hydrated;
            }
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function recent_batches(int $limit = 8): array
    {
        global $wpdb;

        self::ensure_schema();
        $limit = max(1, min(30, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::batches_table_name() . ' ORDER BY id DESC LIMIT %d', $limit),
            ARRAY_A
        );
        $batches = array_values(array_map([self::class, 'hydrate_batch'], is_array($rows) ? $rows : []));

        foreach ($batches as &$batch) {
            $batch_id = (int) ($batch['id'] ?? 0);
            $batch['orders'] = self::orders_for_batch($batch_id);
            $batch['logs'] = self::logs_for_batch($batch_id, 6);
        }
        unset($batch);

        return $batches;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function batch_with_details(int $batch_id, int $log_limit = 10): ?array
    {
        $batch = self::get_batch($batch_id);
        if (!is_array($batch)) {
            return null;
        }

        $batch['orders'] = self::orders_for_batch($batch_id);
        $batch['logs'] = self::logs_for_batch($batch_id, $log_limit);

        return $batch;
    }

    /**
     * Batches whose labels have been saved are ready for the outbound Sending
     * station. These are the only wave batches that should expose the PrintNode
     * print button.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function ready_to_send_batches(int $limit = 30): array
    {
        global $wpdb;

        self::ensure_schema();
        $limit = max(1, min(100, $limit));
        $statuses = [
            self::BATCH_STATUS_LABELS_SAVED,
            self::BATCH_STATUS_PARTIAL_LABELS_SAVED,
        ];
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::batches_table_name() . "
                 WHERE status IN ({$placeholders})
                   AND easypost_batch_id > 0
                   AND label_saved_count > 0
                 ORDER BY labels_at DESC, id DESC
                 LIMIT %d",
                ...array_merge($statuses, [$limit])
            ),
            ARRAY_A
        );

        $batches = array_values(array_map([self::class, 'hydrate_batch'], is_array($rows) ? $rows : []));
        foreach ($batches as &$batch) {
            $batch_id = (int) ($batch['id'] ?? 0);
            $batch['orders'] = self::orders_for_batch($batch_id, [self::ORDER_STATUS_LABEL_SAVED]);
            $batch['logs'] = self::logs_for_batch($batch_id, 6);
        }
        unset($batch);

        return $batches;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function logs_for_batch(int $batch_id, int $limit = 10): array
    {
        global $wpdb;

        self::ensure_schema();
        $limit = max(1, min(50, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::logs_table_name() . ' WHERE batch_id = %d ORDER BY id DESC LIMIT %d',
                $batch_id,
                $limit
            ),
            ARRAY_A
        );

        return array_values(array_map([self::class, 'hydrate_log'], is_array($rows) ? $rows : []));
    }

    /**
     * Re-count order statuses and update the batch summary columns.
     *
     * @return array<string,int>
     */
    public static function summarize_batch(int $batch_id): array
    {
        global $wpdb;

        self::ensure_schema();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, COUNT(*) AS count FROM ' . self::orders_table_name() . ' WHERE batch_id = %d GROUP BY status',
                $batch_id
            ),
            ARRAY_A
        );

        $counts = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $counts[(string) ($row['status'] ?? '')] = (int) ($row['count'] ?? 0);
        }

        $queued = (int) ($counts[self::ORDER_STATUS_QUEUED] ?? 0) + (int) ($counts[self::ORDER_STATUS_PACKING] ?? 0);
        $packed = (int) ($counts[self::ORDER_STATUS_PACKED] ?? 0);
        $failed = (int) ($counts[self::ORDER_STATUS_PACKING_FAILED] ?? 0) + (int) ($counts[self::ORDER_STATUS_LABEL_FAILED] ?? 0);
        $label_saved = (int) ($counts[self::ORDER_STATUS_LABEL_SAVED] ?? 0);

        self::update_batch($batch_id, [
            'queued_count' => $queued,
            'packed_count' => $packed,
            'failed_count' => $failed,
            'label_saved_count' => $label_saved,
        ]);

        return [
            'queued' => $queued,
            'packed' => $packed,
            'failed' => $failed,
            'label_saved' => $label_saved,
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function log(int $batch_id, int $order_id, string $level, string $stage, string $message, array $context = []): void
    {
        global $wpdb;

        self::ensure_schema();
        $wpdb->insert(self::logs_table_name(), [
            'batch_id' => max(0, $batch_id),
            'order_id' => max(0, $order_id),
            'level' => sanitize_key($level) ?: 'info',
            'stage' => sanitize_key($stage),
            'message' => $message,
            'context_json' => !empty($context) ? wp_json_encode($context) : '',
            'created_at' => current_time('mysql', true),
        ], [
            '%d',
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
        ]);
    }

    private static function new_batch_key(): string
    {
        return substr('WAVE-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false), 0, 80);
    }

    /**
     * @param array<string,mixed> $fields
     * @param array<string,string> $allowed
     * @return array{0:array<string,mixed>,1:string[]}
     */
    private static function filter_fields(array $fields, array $allowed): array
    {
        $data = [];
        $formats = [];

        foreach ($allowed as $field => $format) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }

            $value = $fields[$field];
            if ($format === '%d') {
                $value = max(0, (int) $value);
            }
            if ($format === '%s' && $value === null) {
                $value = '';
            }

            $data[$field] = $value;
            $formats[] = $format;
        }

        return [$data, $formats];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate_batch(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'batch_key' => (string) ($row['batch_key'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'created_by' => (int) ($row['created_by'] ?? 0),
            'selected_count' => (int) ($row['selected_count'] ?? 0),
            'queued_count' => (int) ($row['queued_count'] ?? 0),
            'packed_count' => (int) ($row['packed_count'] ?? 0),
            'failed_count' => (int) ($row['failed_count'] ?? 0),
            'label_saved_count' => (int) ($row['label_saved_count'] ?? 0),
            'easypost_batch_id' => (int) ($row['easypost_batch_id'] ?? 0),
            'error_message' => (string) ($row['error_message'] ?? ''),
            'locked_at' => (string) ($row['locked_at'] ?? ''),
            'packed_at' => (string) ($row['packed_at'] ?? ''),
            'labels_at' => (string) ($row['labels_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate_order(array $row): array
    {
        $packages = json_decode((string) ($row['packages_json'] ?? ''), true);
        $package_items = json_decode((string) ($row['package_items_json'] ?? ''), true);
        $packing_slips = json_decode((string) ($row['packing_slips_json'] ?? ''), true);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'batch_id' => (int) ($row['batch_id'] ?? 0),
            'order_id' => (int) ($row['order_id'] ?? 0),
            'order_number' => (string) ($row['order_number'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'fail_reason' => (string) ($row['fail_reason'] ?? ''),
            'debug_ready' => !empty($row['debug_ready']),
            'packages' => is_array($packages) ? $packages : [],
            'package_items' => is_array($package_items) ? $package_items : [],
            'packing_slips' => is_array($packing_slips) ? $packing_slips : [],
            'label_count' => (int) ($row['label_count'] ?? 0),
            'package_count' => (int) ($row['package_count'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate_log(array $row): array
    {
        $context = json_decode((string) ($row['context_json'] ?? ''), true);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'batch_id' => (int) ($row['batch_id'] ?? 0),
            'order_id' => (int) ($row['order_id'] ?? 0),
            'level' => (string) ($row['level'] ?? ''),
            'stage' => (string) ($row['stage'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            'context' => is_array($context) ? $context : [],
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
