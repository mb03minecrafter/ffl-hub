<?php
declare(strict_types=1);

namespace FFLHub\Shipping\EasyPost;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Local recovery store for outbound EasyPost batch label purchases.
 *
 * EasyPost batch operations are asynchronous, so the Sending page needs a
 * durable local record between "prepared", "submitted", "purchased", and
 * "labels saved". This store keeps that state separate from Woo order meta
 * until labels are actually purchased and attached to their orders.
 */
final class EasyPostBatchLabelStore
{
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PURCHASING = 'purchasing';
    public const STATUS_PURCHASED = 'purchased';
    public const STATUS_LABEL_GENERATING = 'label_generating';
    public const STATUS_LABEL_GENERATED = 'label_generated';
    public const STATUS_LABELS_SAVED = 'labels_saved';
    public const STATUS_FAILED = 'failed';

    public static function table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . 'fflhub_easypost_label_batches');
    }

    public static function ensure_schema(): void
    {
        global $wpdb;

        $table = self::table_name();
        $charset = (string) $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
provider_batch_id VARCHAR(80) NOT NULL DEFAULT '',
reference VARCHAR(120) NOT NULL DEFAULT '',
status VARCHAR(40) NOT NULL DEFAULT '',
mode VARCHAR(20) NOT NULL DEFAULT '',
item_count INT UNSIGNED NOT NULL DEFAULT 0,
label_url TEXT NULL,
items_json LONGTEXT NULL,
response_json LONGTEXT NULL,
error_message TEXT NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY  (id),
KEY provider_batch_id (provider_batch_id),
KEY status_updated_at (status, updated_at),
KEY created_at (created_at)
) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $response
     */
    public static function create_prepared(string $reference, array $items, array $response = []): int
    {
        global $wpdb;

        self::ensure_schema();
        $now = current_time('mysql', true);
        $wpdb->insert(self::table_name(), [
            'provider_batch_id' => '',
            'reference' => sanitize_text_field($reference),
            'status' => self::STATUS_PREPARED,
            'mode' => EasyPostOptions::mode(),
            'item_count' => count($items),
            'label_url' => '',
            'items_json' => wp_json_encode(array_values($items)),
            'response_json' => wp_json_encode($response),
            'error_message' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            '%s',
            '%s',
            '%s',
            '%s',
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get(int $id): ?array
    {
        global $wpdb;

        self::ensure_schema();
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $id),
            ARRAY_A
        );

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $limit = 5): array
    {
        global $wpdb;

        self::ensure_schema();
        $limit = max(1, min(20, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::table_name() . ' ORDER BY id DESC LIMIT %d', $limit),
            ARRAY_A
        );

        return array_values(array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []));
    }

    /**
     * @param array<string,mixed> $fields
     */
    public static function update(int $id, array $fields): void
    {
        global $wpdb;

        self::ensure_schema();
        $allowed = [
            'provider_batch_id' => '%s',
            'reference' => '%s',
            'status' => '%s',
            'mode' => '%s',
            'item_count' => '%d',
            'label_url' => '%s',
            'items_json' => '%s',
            'response_json' => '%s',
            'error_message' => '%s',
        ];

        $data = [];
        $formats = [];
        foreach ($allowed as $field => $format) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }

            $value = $fields[$field];
            if (in_array($field, ['items_json', 'response_json'], true) && is_array($value)) {
                $value = wp_json_encode($value);
            }
            if ($field === 'item_count') {
                $value = max(0, (int) $value);
            }

            $data[$field] = $value;
            $formats[] = $format;
        }

        $data['updated_at'] = current_time('mysql', true);
        $formats[] = '%s';

        if (empty($data)) {
            return;
        }

        $wpdb->update(self::table_name(), $data, ['id' => $id], $formats, ['%d']);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $items = json_decode((string) ($row['items_json'] ?? ''), true);
        $response = json_decode((string) ($row['response_json'] ?? ''), true);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'provider_batch_id' => (string) ($row['provider_batch_id'] ?? ''),
            'reference' => (string) ($row['reference'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'mode' => (string) ($row['mode'] ?? ''),
            'item_count' => (int) ($row['item_count'] ?? 0),
            'label_url' => (string) ($row['label_url'] ?? ''),
            'items' => is_array($items) ? $items : [],
            'response' => is_array($response) ? $response : [],
            'error_message' => (string) ($row['error_message'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
