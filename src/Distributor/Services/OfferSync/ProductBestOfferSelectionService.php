<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Product\State\ProductStateStore;

if (!defined('ABSPATH')) {
    exit;
}

final class ProductBestOfferSelectionService
{
    /**
     * Refresh best-offer rows for UPCs whose normalized distributor offers changed.
     *
     * @return array<string,mixed>
     */
    public static function refresh_changed_upcs(): array
    {
        global $wpdb;

        $started = microtime(true);
        $result = self::empty_result('changed');
        $result['implemented'] = true;
        $result['stage'] = 'dirty_upc_collection';
        $result['dirty_upcs'] = 0;
        $result['temp_table'] = '';
        $result['upsert_elapsed_ms'] = '0.00';
        $result['clear_flags_elapsed_ms'] = '0.00';
        $result['errors'] = [];

        if (!$wpdb) {
            $result['ok'] = false;
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        DistributorOffersStore::ensure_schema();
        ProductBestOffersStore::ensure_schema();
        ProductStateStore::ensure_schema();

        $offers_table = DistributorOffersStore::table_name();
        $best_offers_table = ProductBestOffersStore::table_name();
        $product_state_table = ProductStateStore::table_name();
        $temp_table = 'tmp_fflhub_best_offer_dirty_upcs';
        $charset = $wpdb->get_charset_collate();
        $result['temp_table'] = $temp_table;

        $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $created = $wpdb->query("
            CREATE TEMPORARY TABLE {$temp_table} (
                upc VARCHAR(32) NOT NULL,
                PRIMARY KEY (upc)
            ) ENGINE=MEMORY {$charset}
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

        if ((int) $result['dirty_upcs'] === 0) {
            return self::finish_result($result, $started);
        }

        $result['stage'] = 'best_offer_upsert';

        $t_upsert = microtime(true);
        $upserted = $wpdb->query("
            INSERT INTO {$best_offers_table} (
                product_id,
                upc,
                distributor_id,
                distributor_product_id,
                distributor_sku,
                manufacturer_norm,
                qty,
                stock_status,
                dealer_price,
                shipping_cost,
                landed_cost,
                map_price,
                msrp,
                ffl_required,
                sot_required,
                dropship_enabled,
                enabled,
                has_changed,
                shipping_weight_oz,
                shipping_length_in,
                shipping_width_in,
                shipping_height_in,
                source_updated_at,
                source_offer_normalized_at,
                selection_status,
                selected_at
            )
            SELECT
                ps.product_id,
                ps.upc,
                o.distributor_id,
                o.distributor_product_id,
                o.distributor_sku,
                o.manufacturer_norm,
                COALESCE(o.qty, 0) AS qty,
                CASE
                    WHEN o.upc IS NULL THEN 'outofstock'
                    ELSE o.stock_status
                END AS stock_status,
                o.dealer_price,
                o.shipping_cost,
                o.landed_cost,
                COALESCE(
                    NULLIF(o.map_price, 0),
                    (
                        SELECT MAX(map_o.map_price)
                        FROM {$offers_table} map_o
                        WHERE map_o.upc = ps.upc
                          AND map_o.enabled = 1
                          AND map_o.map_price IS NOT NULL
                          AND map_o.map_price > 0
                    )
                ) AS map_price,
                o.msrp,
                COALESCE(o.ffl_required, 0) AS ffl_required,
                COALESCE(o.sot_required, 0) AS sot_required,
                CASE
                    WHEN o.upc IS NULL THEN 0
                    ELSE o.dropship_enabled
                END AS dropship_enabled,
                CASE
                    WHEN o.upc IS NULL THEN 0
                    ELSE o.enabled
                END AS enabled,
                1 AS has_changed,
                o.shipping_weight_oz,
                o.shipping_length_in,
                o.shipping_width_in,
                o.shipping_height_in,
                o.source_updated_at,
                o.normalized_at AS source_offer_normalized_at,
                CASE
                    WHEN o.upc IS NULL THEN 'no_offer'
                    WHEN o.stock_status = 'instock' AND o.qty > 0 THEN 'instock'
                    ELSE 'outofstock_fallback'
                END AS selection_status,
                NOW() AS selected_at
            FROM {$temp_table} d
            INNER JOIN {$product_state_table} ps
                ON ps.upc = d.upc
               AND ps.status = 'active'
            LEFT JOIN {$offers_table} o
                ON o.upc = ps.upc
               AND o.enabled = 1
               AND o.landed_cost > 0
            LEFT JOIN {$offers_table} better
                ON better.upc = ps.upc
               AND better.enabled = 1
               AND better.landed_cost > 0
               AND (
                    (
                        better.stock_status = 'instock'
                        AND better.qty > 0
                        AND NOT (
                            o.stock_status = 'instock'
                            AND o.qty > 0
                        )
                    )
                    OR (
                        better.stock_status = 'instock'
                        AND better.qty > 0
                        AND o.stock_status = 'instock'
                        AND o.qty > 0
                        AND (
                            better.dropship_enabled > o.dropship_enabled
                            OR (
                                better.dropship_enabled = o.dropship_enabled
                                AND better.landed_cost < o.landed_cost
                            )
                            OR (
                                better.dropship_enabled = o.dropship_enabled
                                AND better.landed_cost = o.landed_cost
                                AND better.distributor_id < o.distributor_id
                            )
                        )
                    )
                    OR (
                        NOT (
                            better.stock_status = 'instock'
                            AND better.qty > 0
                        )
                        AND NOT (
                            o.stock_status = 'instock'
                            AND o.qty > 0
                        )
                        AND (
                            better.landed_cost < o.landed_cost
                            OR (
                                better.landed_cost = o.landed_cost
                                AND better.distributor_id < o.distributor_id
                            )
                        )
                    )
               )
            WHERE better.upc IS NULL
            ON DUPLICATE KEY UPDATE
                has_changed = CASE
                    WHEN
                        NOT ({$best_offers_table}.product_id <=> VALUES(product_id))
                        OR NOT ({$best_offers_table}.upc <=> VALUES(upc))
                        OR NOT ({$best_offers_table}.distributor_id <=> VALUES(distributor_id))
                        OR NOT ({$best_offers_table}.distributor_product_id <=> VALUES(distributor_product_id))
                        OR NOT ({$best_offers_table}.distributor_sku <=> VALUES(distributor_sku))
                        OR NOT ({$best_offers_table}.manufacturer_norm <=> VALUES(manufacturer_norm))
                        OR NOT ({$best_offers_table}.qty <=> VALUES(qty))
                        OR NOT ({$best_offers_table}.stock_status <=> VALUES(stock_status))
                        OR NOT ({$best_offers_table}.dealer_price <=> VALUES(dealer_price))
                        OR NOT ({$best_offers_table}.shipping_cost <=> VALUES(shipping_cost))
                        OR NOT ({$best_offers_table}.landed_cost <=> VALUES(landed_cost))
                        OR NOT ({$best_offers_table}.map_price <=> VALUES(map_price))
                        OR NOT ({$best_offers_table}.msrp <=> VALUES(msrp))
                        OR NOT ({$best_offers_table}.ffl_required <=> VALUES(ffl_required))
                        OR NOT ({$best_offers_table}.sot_required <=> VALUES(sot_required))
                        OR NOT ({$best_offers_table}.dropship_enabled <=> VALUES(dropship_enabled))
                        OR NOT ({$best_offers_table}.enabled <=> VALUES(enabled))
                        OR NOT ({$best_offers_table}.shipping_weight_oz <=> VALUES(shipping_weight_oz))
                        OR NOT ({$best_offers_table}.shipping_length_in <=> VALUES(shipping_length_in))
                        OR NOT ({$best_offers_table}.shipping_width_in <=> VALUES(shipping_width_in))
                        OR NOT ({$best_offers_table}.shipping_height_in <=> VALUES(shipping_height_in))
                        OR NOT ({$best_offers_table}.source_updated_at <=> VALUES(source_updated_at))
                        OR NOT ({$best_offers_table}.source_offer_normalized_at <=> VALUES(source_offer_normalized_at))
                        OR NOT ({$best_offers_table}.selection_status <=> VALUES(selection_status))
                    THEN 1
                    ELSE {$best_offers_table}.has_changed
                END,
                product_id = VALUES(product_id),
                upc = VALUES(upc),
                distributor_id = VALUES(distributor_id),
                distributor_product_id = VALUES(distributor_product_id),
                distributor_sku = VALUES(distributor_sku),
                manufacturer_norm = VALUES(manufacturer_norm),
                qty = VALUES(qty),
                stock_status = VALUES(stock_status),
                dealer_price = VALUES(dealer_price),
                shipping_cost = VALUES(shipping_cost),
                landed_cost = VALUES(landed_cost),
                map_price = VALUES(map_price),
                msrp = VALUES(msrp),
                ffl_required = VALUES(ffl_required),
                sot_required = VALUES(sot_required),
                dropship_enabled = VALUES(dropship_enabled),
                enabled = VALUES(enabled),
                shipping_weight_oz = VALUES(shipping_weight_oz),
                shipping_length_in = VALUES(shipping_length_in),
                shipping_width_in = VALUES(shipping_width_in),
                shipping_height_in = VALUES(shipping_height_in),
                source_updated_at = VALUES(source_updated_at),
                source_offer_normalized_at = VALUES(source_offer_normalized_at),
                selection_status = VALUES(selection_status),
                selected_at = NOW()
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $result['upsert_elapsed_ms'] = number_format((microtime(true) - $t_upsert) * 1000.0, 2, '.', '');
        if ($upserted === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to upsert product best offers: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['updated_best_offers'] = is_numeric($upserted) ? (int) $upserted : 0;

        $t_clear = microtime(true);
        $cleared = $wpdb->query("
            UPDATE {$offers_table} o
            INNER JOIN {$temp_table} d
                ON d.upc = o.upc
            SET o.has_changed = 0
            WHERE o.has_changed = 1
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $result['clear_flags_elapsed_ms'] = number_format((microtime(true) - $t_clear) * 1000.0, 2, '.', '');
        if ($cleared === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to clear distributor offer change flags: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['cleared_offer_change_flags'] = is_numeric($cleared) ? (int) $cleared : 0;
        $result['stage'] = 'complete';

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
