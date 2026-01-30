<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Identifiers;

use WC_Order;

use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementProductUtil;

use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobIdentifiersStore
 *
 * Responsibility:
 * - Persist and retrieve correlation identifiers for a placement job:
 *   - merchant_po             (our canonical external reference / correlation id)
 *   - external_order_ids_json (JSON array of external IDs from the distributor)
 *
 * Semantics:
 * - Default behavior is "first writer wins":
 *   - set_job_merchant_po(..., force=false) writes ONLY if merchant_po is empty.
 *   - set_job_external_order_ids(..., force=false) writes ONLY if external IDs are empty / [].
 * - force=true overrides and overwrites existing values.
 *
 * Notes:
 * - Uses direct SQL UPDATE statements so the "first-writer-wins" check + write is atomic
 *   (single statement with a WHERE predicate that requires emptiness).
 * - Callers should only persist external IDs on a successful place-order path.
 *
 * Dependency:
 * - Requires an instantiated OrderPlacementJobsTable manager for table name resolution.
 */
final class OrderPlacementJobIdentifiersStore
{
    /* ============================================================
     * Correlation ID: merchant_po
     * ============================================================ */

    public static function set_job_merchant_po(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        string $merchant_po,
        bool $force = false
    ): void {
        global $wpdb;

        $oid = (int) $order->get_id();
        if ($oid <= 0) {
            return;
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return;
        }

        $merchant_po = trim((string) $merchant_po);
        if ($merchant_po === '') {
            return;
        }

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return;
        }

        $now = OrderPlacementTimeUtil::now_mysql_utc();

        if ($force) {
            // Unconditional overwrite.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                     SET merchant_po = %s, updated_at = %s
                     WHERE order_id = %d AND job_key = %s",
                    $merchant_po,
                    $now,
                    $oid,
                    $job_key
                )
            );
            return;
        }

        // First-writer-wins: only set if empty.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET merchant_po = %s, updated_at = %s
                 WHERE order_id = %d AND job_key = %s
                   AND (merchant_po IS NULL OR merchant_po = '')",
                $merchant_po,
                $now,
                $oid,
                $job_key
            )
        );
    }

    public static function get_job_merchant_po(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key
    ): string {
        $job = OrderPlacementJobsRepository::get_job_for_order($jobs_table, $order, $job_key);
        return $job ? $job->merchant_po_or_empty() : '';
    }

    /* ============================================================
     * External IDs: external_order_ids_json
     * ============================================================ */

    public static function set_job_external_order_ids(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        array $external_ids,
        bool $force = false
    ): void {
        global $wpdb;

        $oid = (int) $order->get_id();
        if ($oid <= 0) {
            return;
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return;
        }

        // Normalize and de-dupe external IDs.
        $external_ids = OrderPlacementProductUtil::normalize_external_ids($external_ids);
        if (empty($external_ids)) {
            return;
        }

        $json = wp_json_encode($external_ids);
        if (!is_string($json) || $json === '') {
            $json = '[]';
        }

        $table = $jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            return;
        }

        $now = OrderPlacementTimeUtil::now_mysql_utc();

        if ($force) {
            // Unconditional overwrite.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                     SET external_order_ids_json = %s, updated_at = %s
                     WHERE order_id = %d AND job_key = %s",
                    $json,
                    $now,
                    $oid,
                    $job_key
                )
            );
            return;
        }

        // First-writer-wins: only set if empty.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET external_order_ids_json = %s, updated_at = %s
                 WHERE order_id = %d AND job_key = %s
                   AND (
                        external_order_ids_json IS NULL
                        OR external_order_ids_json = ''
                        OR external_order_ids_json = '[]'
                   )",
                $json,
                $now,
                $oid,
                $job_key
            )
        );
    }

    public static function get_job_external_order_ids(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key
    ): array {
        $job = OrderPlacementJobsRepository::get_job_for_order($jobs_table, $order, $job_key);
        return $job ? $job->external_order_ids() : [];
    }

    /**
     * Convenience: persist correlation IDs from a successful place-order call.
     *
     * First-writer-wins semantics are used (force=false).
     */
    public static function persist_success_ids(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        string $merchant_po,
        array $external_ids
    ): void {
        self::set_job_merchant_po($jobs_table, $order, $job_key, $merchant_po, false);
        self::set_job_external_order_ids($jobs_table, $order, $job_key, $external_ids, false);
    }
}
