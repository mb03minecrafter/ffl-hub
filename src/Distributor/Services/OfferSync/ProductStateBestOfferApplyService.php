<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Future bridge from selected best-offer rows into product_state.
 *
 * Intent:
 * - Read changed rows from fflhub_product_best_offers.
 * - Copy the selected offer snapshot into fflhub_product_state.
 * - Mark product_state.has_changed for a later Woo/meta writer.
 * - Clear product_best_offers.has_changed only after a successful apply.
 *
 * Only the dirty-row collection step is implemented so far.
 */
final class ProductStateBestOfferApplyService
{
    /**
     * Collect changed best-offer rows into a temporary table.
     *
     * Later steps will use this same method to apply the temp-table rows into
     * product_state. For now, this stops after collection and profiling.
     *
     * @return array<string,mixed>
     */
    public static function apply_changed_best_offers(): array
    {
        global $wpdb;

        $started = microtime(true);
        $result = [
            'ok' => true,
            'implemented' => true,
            'stage' => 'dirty_best_offer_collection',
            'temp_table' => '',
            'processed_best_offers' => 0,
            'updated_product_state' => 0,
            'cleared_best_offer_flags' => 0,
            'collect_elapsed_ms' => '0.00',
            'elapsed_ms' => '0.00',
            'errors' => [],
        ];

        if (!$wpdb) {
            $result['ok'] = false;
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        ProductBestOffersStore::ensure_schema();

        $best_offers_table = ProductBestOffersStore::table_name();
        $temp_table = 'tmp_fflhub_dirty_product_best_offers';
        $charset = $wpdb->get_charset_collate();
        $result['temp_table'] = $temp_table;

        $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $created = $wpdb->query("
            CREATE TEMPORARY TABLE {$temp_table} (
                product_id BIGINT UNSIGNED NOT NULL,
                upc VARCHAR(32) NOT NULL,
                PRIMARY KEY (product_id),
                KEY upc (upc)
            ) ENGINE=MEMORY {$charset}
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($created === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to create dirty best-offer temp table: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $t_collect = microtime(true);
        $inserted = $wpdb->query("
            INSERT INTO {$temp_table} (product_id, upc)
            SELECT product_id, upc
            FROM {$best_offers_table}
            WHERE has_changed = 1
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $result['collect_elapsed_ms'] = number_format((microtime(true) - $t_collect) * 1000.0, 2, '.', '');
        if ($inserted === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to collect dirty best-offer rows: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['processed_best_offers'] = is_numeric($inserted) ? (int) $inserted : 0;

        return self::finish_result($result, $started);
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function finish_result(array $result, float $started): array
    {
        $result['elapsed_ms'] = number_format((microtime(true) - $started) * 1000.0, 2, '.', '');

        return $result;
    }
}
