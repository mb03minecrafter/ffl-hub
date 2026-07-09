<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

use FFLHub\Product\State\ProductStateStore;
use FFLHub\Settings\Options;

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
 */
final class ProductStateBestOfferApplyService
{
    /**
     * Apply changed best-offer rows into product_state.
     *
     * The selected-offer columns are copied directly from product_best_offers.
     * Product-specific controls stay owned by product_state, then the derived
     * pricing/MAP/public-price columns are recalculated from the freshly copied
     * offer snapshot and those existing controls.
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
            'dirty_best_offers_found' => 0,
            'processed_best_offers' => 0,
            'skipped_missing_product_state' => 0,
            'updated_product_state' => 0,
            'cleared_best_offer_flags' => 0,
            'collect_elapsed_ms' => '0.00',
            'apply_elapsed_ms' => '0.00',
            'clear_flags_elapsed_ms' => '0.00',
            'elapsed_ms' => '0.00',
            'errors' => [],
        ];

        if (!$wpdb) {
            $result['ok'] = false;
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        ProductBestOffersStore::ensure_schema();
        ProductStateStore::ensure_schema();

        $best_offers_table = ProductBestOffersStore::table_name();
        $product_state_table = ProductStateStore::table_name();
        $temp_table = 'tmp_fflhub_dirty_product_best_offers';
        $charset = $wpdb->get_charset_collate();
        $result['temp_table'] = $temp_table;

        $dirty_found = $wpdb->get_var("SELECT COUNT(*) FROM {$best_offers_table} WHERE has_changed = 1"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result['dirty_best_offers_found'] = is_numeric($dirty_found) ? (int) $dirty_found : 0;

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
            SELECT b.product_id, b.upc
            FROM {$best_offers_table} b
            INNER JOIN {$product_state_table} ps
                ON ps.product_id = b.product_id
               AND ps.status = 'active'
            WHERE b.has_changed = 1
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $result['collect_elapsed_ms'] = number_format((microtime(true) - $t_collect) * 1000.0, 2, '.', '');
        if ($inserted === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to collect dirty best-offer rows: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['processed_best_offers'] = is_numeric($inserted) ? (int) $inserted : 0;
        $result['skipped_missing_product_state'] = max(0, (int) $result['dirty_best_offers_found'] - (int) $result['processed_best_offers']);

        if ((int) $result['processed_best_offers'] === 0) {
            $result['stage'] = 'complete';
            return self::finish_result($result, $started);
        }

        $result['stage'] = 'product_state_apply';

        $t_apply = microtime(true);
        $applied = $wpdb->query(self::apply_sql($product_state_table, $best_offers_table, $temp_table)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result['apply_elapsed_ms'] = number_format((microtime(true) - $t_apply) * 1000.0, 2, '.', '');

        if ($applied === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to apply changed best offers into product_state: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['updated_product_state'] = is_numeric($applied) ? (int) $applied : 0;

        $result['stage'] = 'best_offer_flag_clear';

        $t_clear = microtime(true);
        $cleared = $wpdb->query("
            UPDATE {$best_offers_table} b
            INNER JOIN {$temp_table} d
                ON d.product_id = b.product_id
            SET b.has_changed = 0
            WHERE b.has_changed = 1
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $result['clear_flags_elapsed_ms'] = number_format((microtime(true) - $t_clear) * 1000.0, 2, '.', '');
        if ($cleared === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to clear product_best_offers change flags: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['cleared_best_offer_flags'] = is_numeric($cleared) ? (int) $cleared : 0;
        $result['stage'] = 'complete';

        return self::finish_result($result, $started);
    }

    /**
     * Recalculate denormalized product_state pricing/MAP outputs in place.
     *
     * This is intentionally separate from apply_changed_best_offers(). Use this
     * when product_state input controls were changed directly, such as a bulk
     * SQL update to pricing_mode, and the selected best-offer snapshot itself
     * did not change.
     *
     * @return array<string,mixed>
     */
    public static function recalculate_all_outputs(): array
    {
        global $wpdb;

        $started = microtime(true);
        $result = [
            'ok' => true,
            'implemented' => true,
            'stage' => 'product_state_output_recalculation',
            'total_product_state_rows' => 0,
            'updated_product_state' => 0,
            'recalculate_elapsed_ms' => '0.00',
            'elapsed_ms' => '0.00',
            'errors' => [],
        ];

        if (!$wpdb) {
            $result['ok'] = false;
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        ProductStateStore::ensure_schema();

        $product_state_table = ProductStateStore::table_name();
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$product_state_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result['total_product_state_rows'] = is_numeric($total) ? (int) $total : 0;

        $t_recalculate = microtime(true);
        $updated = $wpdb->query(self::recalculate_outputs_sql($product_state_table)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result['recalculate_elapsed_ms'] = number_format((microtime(true) - $t_recalculate) * 1000.0, 2, '.', '');

        if ($updated === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to recalculate product_state outputs: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['updated_product_state'] = is_numeric($updated) ? (int) $updated : 0;
        $result['stage'] = 'complete';

        return self::finish_result($result, $started);
    }

    private static function apply_sql(string $product_state_table, string $best_offers_table, string $temp_table): string
    {
        $computed_sell_price = ProductStatePricingSql::computed_sell_price_expr('ps', 'b');
        $effective_map_policy = ProductStatePricingSql::effective_map_policy_expr('ps', 'b');
        $effective_map_price = ProductStatePricingSql::effective_map_price_expr('ps', 'b');
        $map_applicable = ProductStatePricingSql::map_applicable_expr('ps', 'b');
        $public_regular_price = ProductStatePricingSql::public_regular_price_expr('ps', 'b', $computed_sell_price, $effective_map_policy);
        $public_sale_price = ProductStatePricingSql::public_sale_price_expr('ps', 'b', $computed_sell_price, $effective_map_policy);
        $global_percent = self::global_percent_literal();
        $manual_shipping_complete = "
            ps.manual_shipping_override = 1
            AND ps.manual_shipping_weight_oz IS NOT NULL
            AND ps.manual_shipping_weight_oz > 0
            AND ps.manual_shipping_length_in IS NOT NULL
            AND ps.manual_shipping_length_in > 0
            AND ps.manual_shipping_width_in IS NOT NULL
            AND ps.manual_shipping_width_in > 0
            AND ps.manual_shipping_height_in IS NOT NULL
            AND ps.manual_shipping_height_in > 0
        ";
        $shipping_weight_oz = "CASE WHEN {$manual_shipping_complete} THEN ps.manual_shipping_weight_oz ELSE b.shipping_weight_oz END";
        $shipping_length_in = "CASE WHEN {$manual_shipping_complete} THEN ps.manual_shipping_length_in ELSE b.shipping_length_in END";
        $shipping_width_in = "CASE WHEN {$manual_shipping_complete} THEN ps.manual_shipping_width_in ELSE b.shipping_width_in END";
        $shipping_height_in = "CASE WHEN {$manual_shipping_complete} THEN ps.manual_shipping_height_in ELSE b.shipping_height_in END";

        return "
            UPDATE {$product_state_table} ps
            INNER JOIN {$temp_table} d
                ON d.product_id = ps.product_id
            INNER JOIN {$best_offers_table} b
                ON b.product_id = ps.product_id
            SET
                ps.upc = b.upc,
                ps.distributor_id = b.distributor_id,
                ps.distributor_product_id = b.distributor_product_id,
                ps.distributor_sku = b.distributor_sku,
                ps.manufacturer_norm = b.manufacturer_norm,
                ps.qty = b.qty,
                ps.stock_status = b.stock_status,
                ps.dealer_price = b.dealer_price,
                ps.shipping_cost = b.shipping_cost,
                ps.landed_cost = b.landed_cost,
                ps.map_price = b.map_price,
                ps.effective_map_price = {$effective_map_price},
                ps.msrp = b.msrp,
                ps.ffl_required = b.ffl_required,
                ps.sot_required = b.sot_required,
                ps.dropship_enabled = b.dropship_enabled,
                ps.enabled = b.enabled,
                ps.shipping_weight_oz = {$shipping_weight_oz},
                ps.shipping_length_in = {$shipping_length_in},
                ps.shipping_width_in = {$shipping_width_in},
                ps.shipping_height_in = {$shipping_height_in},
                ps.source_updated_at = b.source_updated_at,
                ps.source_offer_normalized_at = b.source_offer_normalized_at,
                ps.selection_status = b.selection_status,
                ps.selected_at = b.selected_at,
                ps.pricing_percent = CASE
                    WHEN ps.pricing_mode = 'global_percent' THEN {$global_percent}
                    ELSE ps.pricing_percent
                END,
                ps.computed_sell_price = {$computed_sell_price},
                ps.map_applicable = {$map_applicable},
                ps.public_regular_price = {$public_regular_price},
                ps.public_sale_price = {$public_sale_price},
                ps.updated_at = NOW(),
                ps.has_changed = 1
            WHERE ps.status = 'active'
        ";
    }

    private static function recalculate_outputs_sql(string $product_state_table): string
    {
        $computed_sell_price = ProductStatePricingSql::computed_sell_price_expr('ps', 'ps');
        $effective_map_policy = ProductStatePricingSql::effective_map_policy_expr('ps', 'ps');
        $effective_map_price = ProductStatePricingSql::effective_map_price_expr('ps', 'ps');
        $map_applicable = ProductStatePricingSql::map_applicable_expr('ps', 'ps');
        $public_regular_price = ProductStatePricingSql::public_regular_price_expr('ps', 'ps', $computed_sell_price, $effective_map_policy);
        $public_sale_price = ProductStatePricingSql::public_sale_price_expr('ps', 'ps', $computed_sell_price, $effective_map_policy);
        $global_percent = self::global_percent_literal();
        $pricing_percent = "
            CASE
                WHEN ps.pricing_mode = 'global_percent' THEN {$global_percent}
                ELSE ps.pricing_percent
            END
        ";
        return "
            UPDATE {$product_state_table} ps
            SET
                ps.pricing_percent = {$pricing_percent},
                ps.effective_map_price = {$effective_map_price},
                ps.computed_sell_price = {$computed_sell_price},
                ps.map_applicable = {$map_applicable},
                ps.public_regular_price = {$public_regular_price},
                ps.public_sale_price = {$public_sale_price},
                ps.updated_at = NOW(),
                ps.has_changed = 1
            WHERE NOT (
                    ps.pricing_percent <=> {$pricing_percent}
                AND ps.effective_map_price <=> {$effective_map_price}
                AND ps.computed_sell_price <=> {$computed_sell_price}
                AND ps.map_applicable <=> {$map_applicable}
                AND ps.public_regular_price <=> {$public_regular_price}
                AND ps.public_sale_price <=> {$public_sale_price}
            )
        ";
    }

    private static function global_percent_literal(): string
    {
        return number_format(max(0.0, (float) Options::get_global_markup()), 4, '.', '');
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
