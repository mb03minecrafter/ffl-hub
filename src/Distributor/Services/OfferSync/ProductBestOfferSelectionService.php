<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

final class ProductBestOfferSelectionService
{
    /**
     * Distributor MAP feeds we do not trust for product-state MAP resolution.
     *
     * These distributors may still win the selected offer on cost/stock, but
     * their MAP values are ignored when building the selected best-offer row.
     */
    private const MAP_FALLBACK_IGNORED_DISTRIBUTORS = [
        'cssi',
        'davidsons',
        'kinseys',
        'orion',
    ];

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
        $result['missing_product_state_upcs'] = 0;
        $result['lock_mismatch_upcs'] = 0;
        $result['msrp_rows'] = 0;
        $result['shipping_measurement_rows'] = 0;
        $result['shipping_weight_rows'] = 0;
        $result['shipping_dimension_rows'] = 0;
        $result['prefer_dropship_best_offers'] = Options::get_prefer_dropship_best_offers_enabled() ? 1 : 0;
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
        $map_table = 'tmp_fflhub_best_offer_dirty_maps';
        $shipping_measurements_table = 'tmp_fflhub_best_offer_dirty_shipping_measurements';
        $regulatory_flags_table = 'tmp_fflhub_best_offer_dirty_regulatory_flags';
        $charset = $wpdb->get_charset_collate();
        $lock_allows_offer_sql = static function (string $offer_alias): string {
            return "(
                ps.allowed_distributors_json IS NULL
                OR JSON_CONTAINS(
                    ps.allowed_distributors_json,
                    JSON_QUOTE({$offer_alias}.distributor_id)
                ) = 1
            )";
        };
        $offer_lock_sql = $lock_allows_offer_sql('o');
        $better_offer_lock_sql = $lock_allows_offer_sql('better');
        $result['temp_table'] = $temp_table;
        $result['map_temp_table'] = $map_table;
        $result['shipping_measurements_temp_table'] = $shipping_measurements_table;
        $result['regulatory_flags_temp_table'] = $regulatory_flags_table;

        $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$map_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$shipping_measurements_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$regulatory_flags_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

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

        $map_created = $wpdb->query("
            CREATE TEMPORARY TABLE {$map_table} (
                upc VARCHAR(32) NOT NULL,
                map_price DECIMAL(12,4) DEFAULT NULL,
                msrp DECIMAL(12,4) DEFAULT NULL,
                PRIMARY KEY (upc)
            ) ENGINE=MEMORY {$charset}
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($map_created === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to create dirty UPC MAP temp table: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $shipping_measurements_created = $wpdb->query("
            CREATE TEMPORARY TABLE {$shipping_measurements_table} (
                upc VARCHAR(32) NOT NULL,
                shipping_weight_oz DECIMAL(10,3) DEFAULT NULL,
                shipping_length_in DECIMAL(10,3) DEFAULT NULL,
                shipping_width_in DECIMAL(10,3) DEFAULT NULL,
                shipping_height_in DECIMAL(10,3) DEFAULT NULL,
                PRIMARY KEY (upc)
            ) ENGINE=MEMORY {$charset}
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($shipping_measurements_created === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to create dirty UPC shipping measurement temp table: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $regulatory_flags_created = $wpdb->query("
            CREATE TEMPORARY TABLE {$regulatory_flags_table} (
                upc VARCHAR(32) NOT NULL,
                ffl_required TINYINT(1) NOT NULL DEFAULT 0,
                sot_required TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (upc)
            ) ENGINE=MEMORY {$charset}
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($regulatory_flags_created === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to create dirty UPC regulatory flag temp table: ' . (string) $wpdb->last_error;
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

        $missing_inserted = $wpdb->query("
            INSERT IGNORE INTO {$temp_table} (upc)
            SELECT ps.upc
            FROM {$product_state_table} ps
            LEFT JOIN {$best_offers_table} pbo
                ON pbo.product_id = ps.product_id
            WHERE ps.status = 'active'
              AND ps.upc <> ''
              AND pbo.product_id IS NULL
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($missing_inserted === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to collect missing product-state UPCs: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['missing_product_state_upcs'] = is_numeric($missing_inserted) ? (int) $missing_inserted : 0;

        // Repair already-selected rows that predate lock-aware selection. New
        // lock edits dirty their UPC offers directly in the product editor.
        $lock_mismatches_inserted = $wpdb->query("
            INSERT IGNORE INTO {$temp_table} (upc)
            SELECT ps.upc
            FROM {$product_state_table} ps
            INNER JOIN {$best_offers_table} pbo
                ON pbo.product_id = ps.product_id
               AND pbo.distributor_id IS NOT NULL
            WHERE ps.status = 'active'
              AND ps.allowed_distributors_json IS NOT NULL
              AND JSON_CONTAINS(
                    ps.allowed_distributors_json,
                    JSON_QUOTE(pbo.distributor_id)
                  ) <> 1
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($lock_mismatches_inserted === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to collect distributor-lock mismatches: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['lock_mismatch_upcs'] = is_numeric($lock_mismatches_inserted) ? (int) $lock_mismatches_inserted : 0;

        $dirty_upcs = $wpdb->get_var("SELECT COUNT(*) FROM {$temp_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result['dirty_upcs'] = is_numeric($dirty_upcs) ? (int) $dirty_upcs : 0;
        $result['processed_upcs'] = (int) $result['dirty_upcs'];

        if ((int) $result['dirty_upcs'] === 0) {
            return self::finish_result($result, $started);
        }

        $result['stage'] = 'best_offer_upsert';

        $t_map = microtime(true);
        $map_fallback_ignored_ids = "'" . implode("', '", array_map('esc_sql', self::MAP_FALLBACK_IGNORED_DISTRIBUTORS)) . "'";
        $map_inserted = $wpdb->query("
            INSERT INTO {$map_table} (upc, map_price, msrp)
            SELECT
                d.upc,
                MAX(CASE
                    WHEN o.distributor_id NOT IN ({$map_fallback_ignored_ids})
                        AND o.map_price IS NOT NULL
                        AND o.map_price > 0
                    THEN o.map_price
                    ELSE NULL
                END) AS map_price,
                MAX(CASE WHEN o.msrp IS NOT NULL AND o.msrp > 0 THEN o.msrp ELSE NULL END) AS msrp
            FROM {$temp_table} d
            INNER JOIN {$product_state_table} ps
                ON ps.upc = d.upc
               AND ps.status = 'active'
            INNER JOIN {$offers_table} o
                ON o.upc = d.upc
               AND o.enabled = 1
               AND {$offer_lock_sql}
            GROUP BY d.upc
            HAVING map_price IS NOT NULL
                OR msrp IS NOT NULL
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $result['map_elapsed_ms'] = number_format((microtime(true) - $t_map) * 1000.0, 2, '.', '');
        if ($map_inserted === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to build dirty UPC MAP lookup: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $map_rows = $wpdb->get_var("SELECT COUNT(*) FROM {$map_table} WHERE map_price IS NOT NULL"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $msrp_rows = $wpdb->get_var("SELECT COUNT(*) FROM {$map_table} WHERE msrp IS NOT NULL"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result['map_rows'] = is_numeric($map_rows) ? (int) $map_rows : 0;
        $result['msrp_rows'] = is_numeric($msrp_rows) ? (int) $msrp_rows : 0;

        // Build one dirty-UPC measurement lookup, mirroring the MAP/MSRP
        // fallback shape above. The selected offer still controls cost,
        // stock, and distributor choice; this table only fills blank shipping
        // measurements from sibling offers when the winner lacks them.
        //
        // Weight can safely fall back independently. Dimensions are selected as
        // a complete length/width/height set from one offer, so we do not build
        // impossible boxes by mixing dimensions from different distributors.
        $t_shipping_measurements = microtime(true);
        $shipping_measurements_inserted = $wpdb->query("
            INSERT INTO {$shipping_measurements_table} (
                upc,
                shipping_weight_oz,
                shipping_length_in,
                shipping_width_in,
                shipping_height_in
            )
            SELECT
                d.upc,
                MAX(CASE
                    WHEN o.shipping_weight_oz IS NOT NULL
                        AND o.shipping_weight_oz > 0
                    THEN o.shipping_weight_oz
                    ELSE NULL
                END) AS shipping_weight_oz,
                CAST(SUBSTRING_INDEX(GROUP_CONCAT(CASE
                    WHEN o.shipping_length_in IS NOT NULL
                        AND o.shipping_length_in > 0
                        AND o.shipping_width_in IS NOT NULL
                        AND o.shipping_width_in > 0
                        AND o.shipping_height_in IS NOT NULL
                        AND o.shipping_height_in > 0
                    THEN o.shipping_length_in
                    ELSE NULL
                END ORDER BY (o.shipping_length_in * o.shipping_width_in * o.shipping_height_in) DESC SEPARATOR ','), ',', 1) AS DECIMAL(10,3)) AS shipping_length_in,
                CAST(SUBSTRING_INDEX(GROUP_CONCAT(CASE
                    WHEN o.shipping_length_in IS NOT NULL
                        AND o.shipping_length_in > 0
                        AND o.shipping_width_in IS NOT NULL
                        AND o.shipping_width_in > 0
                        AND o.shipping_height_in IS NOT NULL
                        AND o.shipping_height_in > 0
                    THEN o.shipping_width_in
                    ELSE NULL
                END ORDER BY (o.shipping_length_in * o.shipping_width_in * o.shipping_height_in) DESC SEPARATOR ','), ',', 1) AS DECIMAL(10,3)) AS shipping_width_in,
                CAST(SUBSTRING_INDEX(GROUP_CONCAT(CASE
                    WHEN o.shipping_length_in IS NOT NULL
                        AND o.shipping_length_in > 0
                        AND o.shipping_width_in IS NOT NULL
                        AND o.shipping_width_in > 0
                        AND o.shipping_height_in IS NOT NULL
                        AND o.shipping_height_in > 0
                    THEN o.shipping_height_in
                    ELSE NULL
                END ORDER BY (o.shipping_length_in * o.shipping_width_in * o.shipping_height_in) DESC SEPARATOR ','), ',', 1) AS DECIMAL(10,3)) AS shipping_height_in
            FROM {$temp_table} d
            INNER JOIN {$product_state_table} ps
                ON ps.upc = d.upc
               AND ps.status = 'active'
            INNER JOIN {$offers_table} o
                ON o.upc = d.upc
               AND o.enabled = 1
               AND {$offer_lock_sql}
            GROUP BY d.upc
            HAVING shipping_weight_oz IS NOT NULL
                OR shipping_length_in IS NOT NULL
                OR shipping_width_in IS NOT NULL
                OR shipping_height_in IS NOT NULL
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $result['shipping_measurements_elapsed_ms'] = number_format((microtime(true) - $t_shipping_measurements) * 1000.0, 2, '.', '');
        if ($shipping_measurements_inserted === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to build dirty UPC shipping measurement lookup: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $shipping_measurement_rows = $wpdb->get_var("SELECT COUNT(*) FROM {$shipping_measurements_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $shipping_weight_rows = $wpdb->get_var("SELECT COUNT(*) FROM {$shipping_measurements_table} WHERE shipping_weight_oz IS NOT NULL"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $shipping_dimension_rows = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$shipping_measurements_table}
            WHERE shipping_length_in IS NOT NULL
               OR shipping_width_in IS NOT NULL
               OR shipping_height_in IS NOT NULL
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result['shipping_measurement_rows'] = is_numeric($shipping_measurement_rows) ? (int) $shipping_measurement_rows : 0;
        $result['shipping_weight_rows'] = is_numeric($shipping_weight_rows) ? (int) $shipping_weight_rows : 0;
        $result['shipping_dimension_rows'] = is_numeric($shipping_dimension_rows) ? (int) $shipping_dimension_rows : 0;

        // Regulatory flags describe the product, not just the currently
        // winning offer. Preserve them even when every offer is disabled or
        // locked out, so a no-offer firearm does not become non-serialized.
        $t_regulatory_flags = microtime(true);
        $regulatory_flags_inserted = $wpdb->query("
            INSERT INTO {$regulatory_flags_table} (upc, ffl_required, sot_required)
            SELECT
                d.upc,
                MAX(CASE WHEN COALESCE(o.ffl_required, 0) = 1 THEN 1 ELSE 0 END) AS ffl_required,
                MAX(CASE WHEN COALESCE(o.sot_required, 0) = 1 THEN 1 ELSE 0 END) AS sot_required
            FROM {$temp_table} d
            INNER JOIN {$product_state_table} ps
                ON ps.upc = d.upc
               AND ps.status = 'active'
            INNER JOIN {$offers_table} o
                ON o.upc = d.upc
            GROUP BY d.upc
            HAVING ffl_required = 1
                OR sot_required = 1
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $result['regulatory_flags_elapsed_ms'] = number_format((microtime(true) - $t_regulatory_flags) * 1000.0, 2, '.', '');
        if ($regulatory_flags_inserted === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to build dirty UPC regulatory flag lookup: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $regulatory_flag_rows = $wpdb->get_var("SELECT COUNT(*) FROM {$regulatory_flags_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result['regulatory_flag_rows'] = is_numeric($regulatory_flag_rows) ? (int) $regulatory_flag_rows : 0;

        $best_offer_changed_sql = "
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
        ";

        $prefer_dropship = Options::get_prefer_dropship_best_offers_enabled();
        $better_cost_condition = $prefer_dropship
            ? "
                            better.dropship_enabled > o.dropship_enabled
                            OR (
                                better.dropship_enabled = o.dropship_enabled
                                AND better.landed_cost < o.landed_cost
                            )
            "
            : "
                            better.landed_cost < o.landed_cost
            ";

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
                CASE
                    WHEN o.distributor_id NOT IN ({$map_fallback_ignored_ids})
                        AND o.map_price IS NOT NULL
                        AND o.map_price > 0
                    THEN o.map_price
                    ELSE dm.map_price
                END AS map_price,
                COALESCE(NULLIF(o.msrp, 0), dm.msrp) AS msrp,
                GREATEST(COALESCE(o.ffl_required, 0), COALESCE(rf.ffl_required, 0)) AS ffl_required,
                GREATEST(COALESCE(o.sot_required, 0), COALESCE(rf.sot_required, 0)) AS sot_required,
                CASE
                    WHEN o.upc IS NULL THEN 0
                    ELSE o.dropship_enabled
                END AS dropship_enabled,
                CASE
                    WHEN o.upc IS NULL THEN 0
                    ELSE o.enabled
                END AS enabled,
                1 AS has_changed,
                -- Prefer the selected offer's weight. Fall back to the
                -- pre-aggregated sibling-offer lookup only when the selected
                -- value is missing or zero.
                CASE
                    WHEN o.shipping_weight_oz IS NOT NULL
                        AND o.shipping_weight_oz > 0
                    THEN o.shipping_weight_oz
                    ELSE sm.shipping_weight_oz
                END AS shipping_weight_oz,
                -- Prefer the selected offer's complete dimension set. If any
                -- dimension is missing, use the complete sibling-offer set.
                CASE
                    WHEN o.shipping_length_in IS NOT NULL
                        AND o.shipping_length_in > 0
                        AND o.shipping_width_in IS NOT NULL
                        AND o.shipping_width_in > 0
                        AND o.shipping_height_in IS NOT NULL
                        AND o.shipping_height_in > 0
                    THEN o.shipping_length_in
                    ELSE sm.shipping_length_in
                END AS shipping_length_in,
                CASE
                    WHEN o.shipping_length_in IS NOT NULL
                        AND o.shipping_length_in > 0
                        AND o.shipping_width_in IS NOT NULL
                        AND o.shipping_width_in > 0
                        AND o.shipping_height_in IS NOT NULL
                        AND o.shipping_height_in > 0
                    THEN o.shipping_width_in
                    ELSE sm.shipping_width_in
                END AS shipping_width_in,
                CASE
                    WHEN o.shipping_length_in IS NOT NULL
                        AND o.shipping_length_in > 0
                        AND o.shipping_width_in IS NOT NULL
                        AND o.shipping_width_in > 0
                        AND o.shipping_height_in IS NOT NULL
                        AND o.shipping_height_in > 0
                    THEN o.shipping_height_in
                    ELSE sm.shipping_height_in
                END AS shipping_height_in,
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
            LEFT JOIN {$map_table} dm
                ON dm.upc = ps.upc
            LEFT JOIN {$shipping_measurements_table} sm
                ON sm.upc = ps.upc
            LEFT JOIN {$regulatory_flags_table} rf
                ON rf.upc = ps.upc
            LEFT JOIN {$offers_table} o
                ON o.upc = ps.upc
               AND o.enabled = 1
               AND o.landed_cost > 0
               AND {$offer_lock_sql}
            LEFT JOIN {$offers_table} better
                ON better.upc = ps.upc
               AND better.enabled = 1
               AND better.landed_cost > 0
               AND {$better_offer_lock_sql}
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
{$better_cost_condition}
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
{$better_cost_condition}
                        )
                    )
               )
            WHERE better.upc IS NULL
            ON DUPLICATE KEY UPDATE
                has_changed = CASE
                    WHEN {$best_offer_changed_sql} THEN 1
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
                selected_at = CASE
                    WHEN {$best_offer_changed_sql} THEN NOW()
                    ELSE {$best_offers_table}.selected_at
                END
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
