<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

use FFLHub\Distributor\Offers\DistributorOffersStore;

if (!defined('ABSPATH')) {
    exit;
}

final class ProductBestOfferSelectionService
{
    /**
     * Refresh best-offer rows for UPCs whose normalized distributor offers changed.
     *
     * This is intentionally a no-op placeholder for now. The next step will
     * replace this stub with the dirty-UPC selection SQL and best-offer upsert.
     *
     * @return array<string,mixed>
     */
    public static function refresh_changed_upcs(): array
    {
        global $wpdb;

        $started = microtime(true);
        $result = self::empty_result('changed');
        $result['implemented'] = true;
        $result['stage'] = 'dirty_upc_collection_only';
        $result['dirty_upcs'] = 0;
        $result['temp_table'] = '';
        $result['errors'] = [];

        if (!$wpdb) {
            $result['ok'] = false;
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        DistributorOffersStore::ensure_schema();
        ProductBestOffersStore::ensure_schema();

        $offers_table = DistributorOffersStore::table_name();
        $temp_table = 'tmp_fflhub_best_offer_dirty_upcs';
        $result['temp_table'] = $temp_table;

        $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $created = $wpdb->query("
            CREATE TEMPORARY TABLE {$temp_table} (
                upc VARCHAR(32) NOT NULL,
                PRIMARY KEY (upc)
            ) ENGINE=MEMORY
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($created === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to create dirty UPC temp table: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $inserted = $wpdb->query("
            INSERT INTO {$temp_table} (upc)
            SELECT DISTINCT upc
            FROM {$offers_table}
            WHERE has_changed = 1
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($inserted === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to collect dirty offer UPCs: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $dirty_upcs = $wpdb->get_var("SELECT COUNT(*) FROM {$temp_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result['dirty_upcs'] = is_numeric($dirty_upcs) ? (int) $dirty_upcs : 0;
        $result['processed_upcs'] = (int) $result['dirty_upcs'];

        return self::finish_result($result, $started);
    }

    /**
     * Refresh best-offer rows for a specific UPC set.
     *
     * This should stay reserved for future admin/debug tooling. The automatic
     * distributor-cron path should use refresh_changed_upcs().
     *
     * @param string[] $upcs
     * @return array<string,mixed>
     */
    public static function refresh_upcs(array $upcs): array
    {
        return [
            'ok' => true,
            'mode' => 'upcs',
            'implemented' => false,
            'requested_upcs' => count(array_unique(array_filter(array_map('strval', $upcs)))),
            'processed_upcs' => 0,
            'updated_best_offers' => 0,
            'cleared_offer_change_flags' => 0,
            'elapsed_ms' => '0.00',
        ];
    }

    /**
     * Rebuild the selected best-offer snapshot for all active product_state rows.
     *
     * @return array<string,mixed>
     */
    public static function rebuild_all(): array
    {
        return self::empty_result('rebuild_all');
    }

    /**
     * @return array<string,mixed>
     */
    private static function empty_result(string $mode): array
    {
        return [
            'ok' => true,
            'mode' => $mode,
            'implemented' => false,
            'processed_upcs' => 0,
            'updated_best_offers' => 0,
            'cleared_offer_change_flags' => 0,
            'elapsed_ms' => '0.00',
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function finish_result(array $result, float $started): array
    {
        $elapsed_ms = (microtime(true) - $started) * 1000.0;
        $result['elapsed_ms'] = number_format($elapsed_ms, 2, '.', '');

        return $result;
    }
}
