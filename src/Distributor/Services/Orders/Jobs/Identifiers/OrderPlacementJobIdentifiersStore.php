<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Identifiers;

use WC_Order;

use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementProductUtil;
use FFLHub\Distributor\Services\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobIdentifiersStore
 *
 * Responsibility:
 * - Persist and retrieve correlation identifiers for a placement job:
 *     - merchant_po (our canonical external reference / correlation id)
 *     - external_order_ids_json (array of external ids from distributor)
 *
 * Semantics:
 * - Default behavior is “first writer wins”:
 *     - set_job_merchant_po(..., force=false) will only write if merchant_po is empty.
 *     - set_job_external_order_ids(..., force=false) will only write if external ids are empty/[].
 * - force=true overrides and will overwrite existing values.
 *
 * Notes:
 * - These are persisted directly via SQL to support “first-writer-wins” atomically.
 * - Callers should only persist external_order_ids on a successful place-order path.
 */
final class OrderPlacementJobIdentifiersStore
{
    /* ============================================================
     * Correlation ID: merchant_po
     * ============================================================ */

    public static function set_job_merchant_po(WC_Order $order, string $job_key, string $merchant_po, bool $force = false): void
    {
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

        $table = OrderPlacementJobsTable::get_table_name();
        $now   = OrderPlacementTimeUtil::now_mysql_utc();

        if ($force) {
            // Unconditional overwrite
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

        // First-writer-wins: only set if empty
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

    public static function get_job_merchant_po(WC_Order $order, string $job_key): string
    {
        $job = OrderPlacementJobsRepository::get_job_for_order($order, $job_key);
        return $job ? $job->merchant_po_or_empty() : '';
    }

    /* ============================================================
     * External IDs: external_order_ids_json
     * ============================================================ */

    public static function set_job_external_order_ids(WC_Order $order, string $job_key, array $external_ids, bool $force = false): void
    {
        global $wpdb;

        $oid = (int) $order->get_id();
        if ($oid <= 0) {
            return;
        }

        $job_key = OrderPlacementKeysUtil::normalize_job_key((string) $job_key);
        if ($job_key === '') {
            return;
        }

        // Pass D requirement: normalize external ids via util (Pass C lives in ProductUtil per your note).
        $external_ids = OrderPlacementProductUtil::normalize_external_ids($external_ids);
        if (empty($external_ids)) {
            return;
        }

        $json = wp_json_encode($external_ids);
        if (!is_string($json) || $json === '') {
            $json = '[]';
        }

        $table = OrderPlacementJobsTable::get_table_name();
        $now   = OrderPlacementTimeUtil::now_mysql_utc();

        if ($force) {
            // Unconditional overwrite
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

        // First-writer-wins: only set if empty
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

    public static function get_job_external_order_ids(WC_Order $order, string $job_key): array
    {
        $job = OrderPlacementJobsRepository::get_job_for_order($order, $job_key);
        return $job ? $job->external_order_ids() : [];
    }

    public static function persist_success_ids(WC_Order $order, string $job_key, string $merchant_po, array $external_ids): void
    {
        self::set_job_merchant_po($order, $job_key, $merchant_po, false);
        self::set_job_external_order_ids($order, $job_key, $external_ids, false);
    }
}
