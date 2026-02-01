<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Orders\Jobs;

use WC_Order;
use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobWriter
 *
 * Single low-level write primitive for the Order Placement Jobs table.
 *
 * See earlier notes: allowlist enforced, NULL-safe updates, stamps updated_at.
 */
final class OrderPlacementJobWriter
{
    /**
     * Columns that should be written as integers when non-null.
     *
     * NOTE:
     * - order_id is part of the WHERE clause, not a writable column here.
     * - action_id is BIGINT, but %d is still correct in wpdb for numeric values.
     */
    private const INT_COLUMNS = [
        'attempts'   => true,
        'action_id'  => true,
    ];

    /**
     * Apply a patch to a job row identified by (order_id, job_key).
     *
     * @param OrderPlacementJobsTable $jobs_table
     * @param int $order_id
     * @param string $job_key
     * @param OrderPlacementJobPatch $patch
     */
    public static function apply_patch(
        OrderPlacementJobsTable $jobs_table,
        int $order_id,
        string $job_key,
        OrderPlacementJobPatch $patch
    ): void {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        $job_key_norm = OrderPlacementKeysUtil::normalize_job_key($job_key);
        if ($job_key_norm === '') {
            return;
        }

        $fields = $patch->to_write_array();
        if (empty($fields)) {
            return;
        }

        self::update_job_fields($jobs_table, $order_id, $job_key_norm, $fields);
    }

    /**
     * Convenience wrapper using WC_Order.
     *
     * @param OrderPlacementJobsTable $jobs_table
     * @param WC_Order $order
     * @param string $job_key
     * @param OrderPlacementJobPatch $patch
     */
    public static function apply_patch_for_order(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        OrderPlacementJobPatch $patch
    ): void {
        self::apply_patch($jobs_table, (int) $order->get_id(), $job_key, $patch);
    }

    /**
     * NULL-safe partial update keyed by (order_id, job_key).
     *
     * @param OrderPlacementJobsTable $jobs_table
     * @param int $order_id
     * @param string $job_key_norm Normalized non-empty job key.
     * @param array<string,mixed> $fields
     */
    private static function update_job_fields(
        OrderPlacementJobsTable $jobs_table,
        int $order_id,
        string $job_key_norm,
        array $fields
    ): void {
        global $wpdb;

        $table = (string) $jobs_table->get_table_name();
        if ($table === '') {
            return;
        }

        if ($order_id <= 0 || $job_key_norm === '' || empty($fields)) {
            return;
        }

        // Always stamp updated_at.
        $fields['updated_at'] = OrderPlacementTimeUtil::now_mysql_utc();

        // Filter to schema allowlist.
        $allowed = $jobs_table->get_writable_columns();
        if (empty($allowed)) {
            return;
        }

        $allowed_set = array_fill_keys(array_map('strval', $allowed), true);

        /** @var array<string,mixed> $data */
        $data = [];
        foreach ($fields as $k => $v) {
            if (!is_string($k) || $k === '' || !isset($allowed_set[$k])) {
                continue;
            }
            $data[$k] = $v;
        }

        if (empty($data)) {
            return;
        }

        // Check for NULLs.
        $has_null = false;
        foreach ($data as $v) {
            if ($v === null) {
                $has_null = true;
                break;
            }
        }

        // Fast path: no NULLs => wpdb->update.
        if (!$has_null) {
            $format = [];
            foreach ($data as $col => $v) {
                $format[] = isset(self::INT_COLUMNS[$col]) ? '%d' : '%s';

                // Normalize ints for int columns.
                if (isset(self::INT_COLUMNS[$col])) {
                    $data[$col] = (int) $v;
                } else {
                    $data[$col] = (string) $v;
                }
            }

            $wpdb->update(
                $table,
                $data,
                ['order_id' => $order_id, 'job_key' => $job_key_norm],
                $format,
                ['%d', '%s']
            );

            return;
        }

        // NULL-safe manual UPDATE.
        $sets = [];
        $args = [];

        foreach ($data as $col => $v) {
            // Column name is allowlisted, safe to interpolate.
            if ($v === null) {
                $sets[] = "{$col} = NULL";
                continue;
            }

            if (isset(self::INT_COLUMNS[$col])) {
                $sets[] = "{$col} = %d";
                $args[] = (int) $v;
            } else {
                $sets[] = "{$col} = %s";
                $args[] = (string) $v;
            }
        }

        if (empty($sets)) {
            return;
        }

        $args[] = $order_id;
        $args[] = $job_key_norm;

        $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE order_id = %d AND job_key = %s";
        $wpdb->query($wpdb->prepare($sql, $args));
    }
}
