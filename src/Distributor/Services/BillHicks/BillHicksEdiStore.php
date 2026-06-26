<?php

namespace FFLHub\Distributor\Services\BillHicks;

use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsSchema;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistence for Bill Hicks inbound EDI files, 855 acks, and 856 shipments.
 */
final class BillHicksEdiStore
{
    private const FILES_SUFFIX = 'fflhub_bill_hicks_edi_files';
    private const ACKS_SUFFIX = 'fflhub_bill_hicks_edi_acks';
    private const SHIPMENTS_SUFFIX = 'fflhub_bill_hicks_edi_shipments';

    private static bool $tablesEnsured = false;

    public function ensure_tables(): void
    {
        global $wpdb;

        if (self::$tablesEnsured) {
            return;
        }

        $charset = (string) $wpdb->get_charset_collate();
        $files = $this->files_table();
        $acks = $this->acks_table();
        $shipments = $this->shipments_table();

        $sql_files = "CREATE TABLE {$files} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            remote_key CHAR(40) NOT NULL,
            remote_path VARCHAR(255) NOT NULL,
            file_name VARCHAR(191) NOT NULL,
            file_type VARCHAR(16) NOT NULL DEFAULT 'unknown',
            remote_mtime BIGINT NOT NULL DEFAULT 0,
            remote_size BIGINT NOT NULL DEFAULT -1,
            sha1 CHAR(40) NOT NULL DEFAULT '',
            local_path TEXT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'processed',
            message TEXT NULL,
            summary_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            processed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY remote_key (remote_key),
            KEY remote_path (remote_path),
            KEY file_type_processed_at (file_type, processed_at)
        ) {$charset};";

        $sql_acks = "CREATE TABLE {$acks} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            file_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            merchant_po VARCHAR(64) NOT NULL,
            bhc_order_number VARCHAR(64) NOT NULL DEFAULT '',
            quantity_ordered INT UNSIGNED NOT NULL DEFAULT 0,
            quantity_committed INT UNSIGNED NOT NULL DEFAULT 0,
            ack_status VARCHAR(24) NOT NULL DEFAULT '',
            raw_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY file_po (file_id, merchant_po),
            KEY merchant_po (merchant_po),
            KEY ack_status (ack_status)
        ) {$charset};";

        $sql_shipments = "CREATE TABLE {$shipments} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            file_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            merchant_po VARCHAR(64) NOT NULL,
            bhc_order_number VARCHAR(64) NOT NULL DEFAULT '',
            tracking_number VARCHAR(128) NOT NULL DEFAULT '',
            carrier VARCHAR(64) NOT NULL DEFAULT '',
            ship_date VARCHAR(32) NOT NULL DEFAULT '',
            quantity_ordered INT UNSIGNED NOT NULL DEFAULT 0,
            quantity_shipped INT UNSIGNED NOT NULL DEFAULT 0,
            raw_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY file_tracking (file_id, tracking_number),
            KEY merchant_po (merchant_po),
            KEY tracking_number (tracking_number)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_files);
        dbDelta($sql_acks);
        dbDelta($sql_shipments);

        self::$tablesEnsured = true;
    }

    public function remote_key(string $remote_path, int $mtime, int $size): string
    {
        return sha1(trim($remote_path) . '|' . (int) $mtime . '|' . (int) $size);
    }

    public function is_file_processed(string $remote_path, int $mtime, int $size): bool
    {
        global $wpdb;

        $key = $this->remote_key($remote_path, $mtime, $size);
        $found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->files_table()} WHERE remote_key = %s LIMIT 1",
                $key
            )
        );

        return is_numeric($found) && (int) $found > 0;
    }

    /**
     * @param array<string,mixed> $summary
     */
    public function insert_file_record(
        string $remote_path,
        int $mtime,
        int $size,
        string $local_path,
        string $file_type,
        string $status,
        string $message,
        array $summary
    ): int {
        global $wpdb;

        $local_path = trim($local_path);
        $sha1 = ($local_path !== '' && is_file($local_path)) ? (string) sha1_file($local_path) : '';
        $now = OrderPlacementTimeUtil::now_mysql_utc();
        $summary_json = wp_json_encode($summary);
        if (!is_string($summary_json) || $summary_json === '') {
            $summary_json = '{}';
        }

        $wpdb->insert(
            $this->files_table(),
            [
                'remote_key' => $this->remote_key($remote_path, $mtime, $size),
                'remote_path' => trim($remote_path),
                'file_name' => basename(trim($remote_path)),
                'file_type' => trim($file_type) !== '' ? trim($file_type) : 'unknown',
                'remote_mtime' => (int) $mtime,
                'remote_size' => (int) $size,
                'sha1' => $sha1,
                'local_path' => $local_path,
                'status' => trim($status) !== '' ? trim($status) : 'processed',
                'message' => trim($message),
                'summary_json' => $summary_json,
                'created_at' => $now,
                'processed_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<int,array<string,string>> $rows
     * @return array<string,array{ordered:int,committed:int,external_ids:string[],rows:array<int,array<string,string>>,status:string}>
     */
    public function group_ack_rows_by_po(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $po = trim((string) ($row['PO Number'] ?? ''));
            if ($po === '') {
                continue;
            }

            if (!isset($out[$po])) {
                $out[$po] = [
                    'ordered' => 0,
                    'committed' => 0,
                    'external_ids' => [],
                    'rows' => [],
                    'status' => '',
                ];
            }

            $out[$po]['ordered'] += $this->int_field($row['Quantity Ordered'] ?? '');
            $out[$po]['committed'] += $this->int_field($row['Quantity Committed'] ?? '');
            $ext = trim((string) ($row['BHC Order Number'] ?? ''));
            if ($ext !== '') {
                $out[$po]['external_ids'][] = $ext;
            }
            $out[$po]['rows'][] = $row;
        }

        foreach ($out as $po => $group) {
            $ordered = (int) $group['ordered'];
            $committed = (int) $group['committed'];
            $status = 'partial';
            if ($ordered > 0 && $committed >= $ordered) {
                $status = 'accepted';
            } elseif ($committed <= 0) {
                $status = 'rejected';
            }

            $out[$po]['external_ids'] = array_values(array_unique($group['external_ids']));
            $out[$po]['status'] = $status;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $group
     */
    public function insert_ack_group(int $file_id, string $po, array $group): void
    {
        global $wpdb;

        $raw = wp_json_encode($group['rows'] ?? []);
        if (!is_string($raw) || $raw === '') {
            $raw = '[]';
        }

        $external_ids = is_array($group['external_ids'] ?? null) ? $group['external_ids'] : [];

        $wpdb->replace(
            $this->acks_table(),
            [
                'file_id' => (int) $file_id,
                'merchant_po' => trim($po),
                'bhc_order_number' => (string) ($external_ids[0] ?? ''),
                'quantity_ordered' => (int) ($group['ordered'] ?? 0),
                'quantity_committed' => (int) ($group['committed'] ?? 0),
                'ack_status' => (string) ($group['status'] ?? ''),
                'raw_json' => $raw,
                'created_at' => OrderPlacementTimeUtil::now_mysql_utc(),
            ],
            ['%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s']
        );
    }

    /**
     * @param array<string,string> $row
     */
    public function insert_shipment_row(int $file_id, array $row): void
    {
        global $wpdb;

        $po = trim((string) ($row['PO Number'] ?? ''));
        $tracking = trim((string) ($row['Tracking Number'] ?? ''));
        if ($po === '' || $tracking === '') {
            return;
        }

        $raw = wp_json_encode($row);
        if (!is_string($raw) || $raw === '') {
            $raw = '{}';
        }

        $wpdb->replace(
            $this->shipments_table(),
            [
                'file_id' => (int) $file_id,
                'merchant_po' => $po,
                'bhc_order_number' => trim((string) ($row['BHC Order Number'] ?? '')),
                'tracking_number' => $tracking,
                'carrier' => trim((string) ($row['Carrier'] ?? '')),
                'ship_date' => trim((string) ($row['Ship Date'] ?? '')),
                'quantity_ordered' => $this->int_field($row['Quantity Ordered'] ?? ''),
                'quantity_shipped' => $this->int_field($row['Quantity Shipped'] ?? ''),
                'raw_json' => $raw,
                'created_at' => OrderPlacementTimeUtil::now_mysql_utc(),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
        );
    }

    public function get_shipment_by_po(string $po): ?DistributorShipment
    {
        global $wpdb;

        $this->ensure_tables();

        $po = trim((string) $po);
        if ($po === '') {
            return null;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tracking_number, carrier, bhc_order_number, raw_json
                 FROM {$this->shipments_table()}
                 WHERE merchant_po = %s
                   AND tracking_number <> ''
                 ORDER BY id ASC",
                $po
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return null;
        }

        $tracking = [];
        $invoices = [];
        $carrier = '';
        $raw_rows = [];

        foreach ($rows as $row) {
            $tracking[] = (string) ($row['tracking_number'] ?? '');
            $invoices[] = (string) ($row['bhc_order_number'] ?? '');
            if ($carrier === '') {
                $carrier = trim((string) ($row['carrier'] ?? ''));
            }
            $decoded = json_decode((string) ($row['raw_json'] ?? ''), true);
            $raw_rows[] = is_array($decoded) ? $decoded : $row;
        }

        return new DistributorShipment(
            $tracking,
            $invoices,
            $carrier !== '' ? $carrier : null,
            null,
            [
                'distributor' => 'bill_hicks',
                'po' => $po,
                'rows' => $raw_rows,
            ]
        );
    }

    /**
     * Apply a parsed 855 PO group to awaiting Bill Hicks job rows.
     *
     * @param array<string,mixed> $group
     * @return int Number of job rows touched.
     */
    public function apply_ack_to_jobs(string $po, array $group): int
    {
        global $wpdb;

        $po = trim((string) $po);
        if ($po === '') {
            return 0;
        }

        $jobs_table = new OrderPlacementJobsTable(new OrderPlacementJobsSchema());
        $table = $jobs_table->get_table_name();
        $jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT order_id, job_key
                 FROM {$table}
                 WHERE dist_id = %s
                   AND merchant_po = %s
                   AND status = %s",
                'bill_hicks',
                $po,
                OrderPlacementKeys::JOB_STATUS_AWAITING_ACK
            ),
            ARRAY_A
        );

        if (!is_array($jobs) || empty($jobs)) {
            return 0;
        }

        $status = (string) ($group['status'] ?? '');
        $external_ids = is_array($group['external_ids'] ?? null) ? array_values(array_unique($group['external_ids'])) : [];
        $external_json = wp_json_encode($external_ids);
        if (!is_string($external_json) || $external_json === '') {
            $external_json = '[]';
        }

        $touched = 0;
        foreach ($jobs as $job) {
            $order_id = (int) ($job['order_id'] ?? 0);
            $job_key = (string) ($job['job_key'] ?? '');
            if ($order_id <= 0 || $job_key === '') {
                continue;
            }

            if ($status === 'accepted') {
                $patch = OrderPlacementJobPatch::empty()
                    ->with_status(OrderPlacementKeys::JOB_STATUS_SUCCESS)
                    ->with_field('done_at', OrderPlacementTimeUtil::now_mysql_utc())
                    ->with_field('external_order_ids_json', $external_json)
                    ->with_field('external_order_id', (string) ($external_ids[0] ?? ''))
                    ->with_last_error('')
                    ->with_last_codes([])
                    ->clear_action_and_schedule();
            } else {
                $message = sprintf(
                    'Bill Hicks 855 %s for PO %s: ordered %d, committed %d.',
                    $status !== '' ? $status : 'unaccepted',
                    $po,
                    (int) ($group['ordered'] ?? 0),
                    (int) ($group['committed'] ?? 0)
                );

                $patch = OrderPlacementJobPatch::empty()
                    ->with_status(OrderPlacementKeys::JOB_STATUS_MANUAL)
                    ->with_last_step('place')
                    ->with_last_error($message)
                    ->with_last_codes(['BILL_HICKS_855_' . strtoupper($status !== '' ? $status : 'UNACCEPTED')])
                    ->clear_action_and_schedule();
            }

            OrderPlacementJobWriter::apply_patch($jobs_table, $order_id, $job_key, $patch);
            $touched++;
        }

        return $touched;
    }

    private function files_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::FILES_SUFFIX;
    }

    private function acks_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::ACKS_SUFFIX;
    }

    private function shipments_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::SHIPMENTS_SUFFIX;
    }

    private function int_field($value): int
    {
        $value = trim((string) $value);
        if ($value === '' || !is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }
}
