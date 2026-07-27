<?php
declare(strict_types=1);

namespace FFLHub\Shipping\PrintNode;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable PrintNode queue for thermal labels and packing slips.
 *
 * Sending a whole wave to PrintNode in one admin request can overwhelm a small
 * label printer. This table lets the Sending page enqueue every document while
 * a background worker releases them to PrintNode one at a time.
 */
final class PrintNodePrintQueueStore
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PRINTING = 'printing';
    public const STATUS_PRINTED = 'printed';
    public const STATUS_FAILED = 'failed';

    public static function table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . 'fflhub_printnode_print_jobs');
    }

    public static function ensure_schema(): void
    {
        global $wpdb;

        $table = self::table_name();
        $charset = (string) $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
run_key VARCHAR(80) NOT NULL DEFAULT '',
easypost_batch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
printer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
document_index INT UNSIGNED NOT NULL DEFAULT 0,
status VARCHAR(20) NOT NULL DEFAULT '',
title VARCHAR(190) NOT NULL DEFAULT '',
kind VARCHAR(40) NOT NULL DEFAULT '',
order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
order_number VARCHAR(80) NOT NULL DEFAULT '',
package_index INT UNSIGNED NOT NULL DEFAULT 0,
force_4x6 TINYINT(1) NOT NULL DEFAULT 0,
body_base64 LONGTEXT NULL,
print_job_id VARCHAR(80) NOT NULL DEFAULT '',
error_message TEXT NULL,
attempts INT UNSIGNED NOT NULL DEFAULT 0,
available_at DATETIME NOT NULL,
printed_at DATETIME NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY  (id),
KEY run_status_available (run_key, status, available_at),
KEY status_available (status, available_at),
KEY easypost_batch_id (easypost_batch_id),
KEY order_id (order_id)
) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * @param array<int,array<string,mixed>> $documents
     * @return array{run_key:string,job_ids:int[],queued_count:int}
     */
    public static function enqueue_documents(
        int $easypost_batch_id,
        int $printer_id,
        array $documents,
        int $delay_seconds
    ): array {
        global $wpdb;

        self::ensure_schema();
        $run_key = self::new_run_key($easypost_batch_id);
        $delay_seconds = max(0, min(3600, $delay_seconds));
        $now_ts = current_time('timestamp', true);
        $now = gmdate('Y-m-d H:i:s', $now_ts);
        $job_ids = [];

        foreach (array_values($documents) as $index => $document) {
            if (!is_array($document)) {
                continue;
            }

            $body = (string) ($document['body'] ?? '');
            if ($body === '') {
                continue;
            }

            $available_at = gmdate('Y-m-d H:i:s', $now_ts + ($index * $delay_seconds));
            $wpdb->insert(self::table_name(), [
                'run_key' => $run_key,
                'easypost_batch_id' => $easypost_batch_id,
                'printer_id' => $printer_id,
                'document_index' => $index,
                'status' => self::STATUS_QUEUED,
                'title' => sanitize_text_field((string) ($document['title'] ?? 'FFL Hub Print Job')),
                'kind' => sanitize_key((string) ($document['kind'] ?? 'document')),
                'order_id' => max(0, (int) ($document['order_id'] ?? 0)),
                'order_number' => sanitize_text_field((string) ($document['order_number'] ?? '')),
                'package_index' => max(0, (int) ($document['package_index'] ?? 0)),
                'force_4x6' => !empty($document['force_4x6']) ? 1 : 0,
                'body_base64' => base64_encode($body),
                'print_job_id' => '',
                'error_message' => '',
                'attempts' => 0,
                'available_at' => $available_at,
                'printed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                '%s',
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
            ]);

            $insert_id = (int) $wpdb->insert_id;
            if ($insert_id > 0) {
                $job_ids[] = $insert_id;
            }
        }

        return [
            'run_key' => $run_key,
            'job_ids' => $job_ids,
            'queued_count' => count($job_ids),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function claim_next_due_job(): ?array
    {
        global $wpdb;

        self::ensure_schema();
        $now = current_time('mysql', true);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_name() . ' WHERE status = %s AND available_at <= %s ORDER BY available_at ASC, id ASC LIMIT 1',
                self::STATUS_QUEUED,
                $now
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }

        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::table_name() . ' SET status = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = %s',
                self::STATUS_PRINTING,
                $now,
                $id,
                self::STATUS_QUEUED
            )
        );
        if ((int) $updated !== 1) {
            return null;
        }

        $row['status'] = self::STATUS_PRINTING;
        $row['attempts'] = (int) ($row['attempts'] ?? 0) + 1;
        $row['updated_at'] = $now;

        return self::hydrate($row);
    }

    public static function mark_printed(int $id, string $print_job_id): void
    {
        global $wpdb;

        self::ensure_schema();
        $now = current_time('mysql', true);
        $wpdb->update(self::table_name(), [
            'status' => self::STATUS_PRINTED,
            'print_job_id' => sanitize_text_field($print_job_id),
            'error_message' => '',
            'body_base64' => '',
            'printed_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id], ['%s', '%s', '%s', '%s', '%s', '%s'], ['%d']);
    }

    public static function mark_failed(int $id, string $message): void
    {
        global $wpdb;

        self::ensure_schema();
        $wpdb->update(self::table_name(), [
            'status' => self::STATUS_FAILED,
            'error_message' => wp_strip_all_tags($message),
            'body_base64' => '',
            'updated_at' => current_time('mysql', true),
        ], ['id' => $id], ['%s', '%s', '%s', '%s'], ['%d']);
    }

    /**
     * @return array{queued:int,printing:int,printed:int,failed:int,total:int,pending:int}
     */
    public static function stats_for_run(string $run_key): array
    {
        global $wpdb;

        self::ensure_schema();
        $run_key = sanitize_text_field($run_key);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, COUNT(*) AS count FROM ' . self::table_name() . ' WHERE run_key = %s GROUP BY status',
                $run_key
            ),
            ARRAY_A
        );

        $stats = [
            self::STATUS_QUEUED => 0,
            self::STATUS_PRINTING => 0,
            self::STATUS_PRINTED => 0,
            self::STATUS_FAILED => 0,
        ];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $stats)) {
                $stats[$status] = (int) ($row['count'] ?? 0);
            }
        }

        $total = array_sum($stats);

        return [
            'queued' => $stats[self::STATUS_QUEUED],
            'printing' => $stats[self::STATUS_PRINTING],
            'printed' => $stats[self::STATUS_PRINTED],
            'failed' => $stats[self::STATUS_FAILED],
            'total' => $total,
            'pending' => $stats[self::STATUS_QUEUED] + $stats[self::STATUS_PRINTING],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function errors_for_run(string $run_key, int $limit = 10): array
    {
        global $wpdb;

        self::ensure_schema();
        $limit = max(1, min(50, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_name() . ' WHERE run_key = %s AND status = %s ORDER BY updated_at DESC, id DESC LIMIT %d',
                sanitize_text_field($run_key),
                self::STATUS_FAILED,
                $limit
            ),
            ARRAY_A
        );

        return array_values(array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function printed_jobs_for_run(string $run_key, int $limit = 25): array
    {
        global $wpdb;

        self::ensure_schema();
        $limit = max(1, min(100, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_name() . ' WHERE run_key = %s AND status = %s ORDER BY document_index ASC, id ASC LIMIT %d',
                sanitize_text_field($run_key),
                self::STATUS_PRINTED,
                $limit
            ),
            ARRAY_A
        );

        return array_values(array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []));
    }

    public static function queued_jobs_remaining_for_run(string $run_key): int
    {
        $stats = self::stats_for_run($run_key);

        return (int) $stats['pending'];
    }

    public static function next_queued_available_timestamp(): ?int
    {
        global $wpdb;

        self::ensure_schema();
        $available_at = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT available_at FROM ' . self::table_name() . ' WHERE status = %s ORDER BY available_at ASC, id ASC LIMIT 1',
                self::STATUS_QUEUED
            )
        );

        $available_at = is_string($available_at) ? trim($available_at) : '';
        if ($available_at === '') {
            return null;
        }

        $timestamp = strtotime($available_at . ' UTC');

        return $timestamp !== false ? (int) $timestamp : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'run_key' => (string) ($row['run_key'] ?? ''),
            'easypost_batch_id' => (int) ($row['easypost_batch_id'] ?? 0),
            'printer_id' => (int) ($row['printer_id'] ?? 0),
            'document_index' => (int) ($row['document_index'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'kind' => (string) ($row['kind'] ?? ''),
            'order_id' => (int) ($row['order_id'] ?? 0),
            'order_number' => (string) ($row['order_number'] ?? ''),
            'package_index' => (int) ($row['package_index'] ?? 0),
            'force_4x6' => !empty($row['force_4x6']),
            'body_base64' => (string) ($row['body_base64'] ?? ''),
            'print_job_id' => (string) ($row['print_job_id'] ?? ''),
            'error_message' => (string) ($row['error_message'] ?? ''),
            'attempts' => (int) ($row['attempts'] ?? 0),
            'available_at' => (string) ($row['available_at'] ?? ''),
            'printed_at' => (string) ($row['printed_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private static function new_run_key(int $easypost_batch_id): string
    {
        $suffix = function_exists('wp_generate_password')
            ? wp_generate_password(8, false, false)
            : substr(md5((string) microtime(true)), 0, 8);

        return substr('pn-' . $easypost_batch_id . '-' . time() . '-' . $suffix, 0, 80);
    }
}
