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

    private static function apply_sql(string $product_state_table, string $best_offers_table, string $temp_table): string
    {
        $computed_sell_price = self::computed_sell_price_expr('ps', 'b');
        $effective_map_policy = self::effective_map_policy_expr('ps', 'b');
        $public_regular_price = self::public_regular_price_expr('ps', 'b', $computed_sell_price, $effective_map_policy);
        $public_sale_price = self::public_sale_price_expr('ps', 'b', $computed_sell_price, $effective_map_policy);
        $global_percent = self::global_percent_literal();

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
                ps.msrp = b.msrp,
                ps.ffl_required = b.ffl_required,
                ps.sot_required = b.sot_required,
                ps.dropship_enabled = b.dropship_enabled,
                ps.enabled = b.enabled,
                ps.shipping_weight_oz = b.shipping_weight_oz,
                ps.shipping_length_in = b.shipping_length_in,
                ps.shipping_width_in = b.shipping_width_in,
                ps.shipping_height_in = b.shipping_height_in,
                ps.source_updated_at = b.source_updated_at,
                ps.source_offer_normalized_at = b.source_offer_normalized_at,
                ps.selection_status = b.selection_status,
                ps.selected_at = b.selected_at,
                ps.pricing_percent = CASE
                    WHEN ps.pricing_mode = 'global_percent' THEN {$global_percent}
                    ELSE ps.pricing_percent
                END,
                ps.computed_sell_price = {$computed_sell_price},
                ps.map_applicable = CASE
                    WHEN b.map_price IS NOT NULL AND b.map_price > 0 THEN 1
                    ELSE 0
                END,
                ps.public_regular_price = {$public_regular_price},
                ps.public_sale_price = {$public_sale_price},
                ps.updated_at = NOW(),
                ps.has_changed = 1
            WHERE ps.status = 'active'
        ";
    }

    private static function computed_sell_price_expr(string $state_alias, string $offer_alias): string
    {
        $pricing_percent = self::pricing_percent_expr($state_alias);
        $cost_base = self::cost_base_expr($offer_alias);
        $cost_without_shipping = self::cost_base_without_shipping_expr($offer_alias);
        $shipping = "GREATEST(COALESCE({$offer_alias}.shipping_cost, 0.0000), 0.0000)";
        $fee_fraction = self::payment_fee_fraction_literal();
        $denominator = self::payment_fee_denominator_literal();

        return "
            CASE
                WHEN {$state_alias}.pricing_mode = 'fixed_price'
                    AND {$state_alias}.pricing_fixed_price IS NOT NULL
                    AND {$state_alias}.pricing_fixed_price > 0
                THEN ROUND({$state_alias}.pricing_fixed_price, 2)

                WHEN {$state_alias}.pricing_mode = 'fixed_profit'
                    AND {$state_alias}.pricing_fixed_profit IS NOT NULL
                    AND {$state_alias}.pricing_fixed_profit >= 0
                    AND {$cost_without_shipping} IS NOT NULL
                    AND {$cost_without_shipping} > 0
                THEN ROUND(
                    {$cost_without_shipping}
                    + (
                        GREATEST({$state_alias}.pricing_fixed_profit, 0.0000)
                        + {$shipping}
                        + ({$cost_without_shipping} * {$fee_fraction})
                    ) / {$denominator},
                    2
                )

                WHEN {$state_alias}.pricing_mode = 'map_price'
                    AND {$offer_alias}.map_price IS NOT NULL
                    AND {$offer_alias}.map_price > 0
                THEN ROUND({$offer_alias}.map_price, 2)

                WHEN {$state_alias}.pricing_mode IN ('global_percent', 'fixed_percent')
                    AND {$pricing_percent} IS NOT NULL
                    AND {$cost_base} IS NOT NULL
                    AND {$cost_base} > 0
                THEN CEIL({$cost_base} * (1.0 + ({$pricing_percent} / 100.0))) - 0.01

                ELSE {$state_alias}.computed_sell_price
            END
        ";
    }

    private static function public_regular_price_expr(string $state_alias, string $offer_alias, string $computed_sell_price, string $effective_map_policy): string
    {
        return "
            CASE
                WHEN {$offer_alias}.map_price IS NOT NULL
                    AND {$offer_alias}.map_price > 0
                    AND {$effective_map_policy} IN ('" . esc_sql(Options::MAP_POLICY_EMAIL_FOR_QUOTE) . "', '" . esc_sql(Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) . "')
                THEN CASE
                    WHEN {$offer_alias}.msrp IS NOT NULL
                        AND {$offer_alias}.msrp > {$offer_alias}.map_price
                    THEN ROUND({$offer_alias}.msrp, 2)
                    ELSE ROUND({$offer_alias}.map_price, 2)
                END

                WHEN {$computed_sell_price} IS NULL OR {$computed_sell_price} <= 0
                THEN {$state_alias}.public_regular_price

                WHEN {$offer_alias}.map_price IS NOT NULL
                    AND {$offer_alias}.map_price > {$computed_sell_price}
                THEN ROUND({$offer_alias}.map_price, 2)

                WHEN {$offer_alias}.msrp IS NOT NULL
                    AND {$offer_alias}.msrp > {$computed_sell_price}
                THEN ROUND({$offer_alias}.msrp, 2)

                ELSE ROUND({$computed_sell_price}, 2)
            END
        ";
    }

    private static function public_sale_price_expr(string $state_alias, string $offer_alias, string $computed_sell_price, string $effective_map_policy): string
    {
        return "
            CASE
                WHEN {$offer_alias}.map_price IS NOT NULL
                    AND {$offer_alias}.map_price > 0
                    AND {$effective_map_policy} IN ('" . esc_sql(Options::MAP_POLICY_EMAIL_FOR_QUOTE) . "', '" . esc_sql(Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) . "')
                THEN ROUND({$offer_alias}.map_price, 2)

                WHEN {$computed_sell_price} IS NULL OR {$computed_sell_price} <= 0
                THEN {$state_alias}.public_sale_price

                WHEN {$offer_alias}.map_price IS NOT NULL
                    AND {$offer_alias}.map_price > {$computed_sell_price}
                THEN ROUND({$computed_sell_price}, 2)

                WHEN {$offer_alias}.msrp IS NOT NULL
                    AND {$offer_alias}.msrp > {$computed_sell_price}
                THEN ROUND({$computed_sell_price}, 2)

                ELSE NULL
            END
        ";
    }

    private static function effective_map_policy_expr(string $state_alias, string $offer_alias): string
    {
        return "
            CASE
                WHEN {$offer_alias}.map_price IS NULL OR {$offer_alias}.map_price <= 0 THEN 'none'
                WHEN {$state_alias}.map_visibility_policy IN (
                    'none',
                    '" . esc_sql(Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE) . "',
                    '" . esc_sql(Options::MAP_POLICY_EMAIL_FOR_QUOTE) . "',
                    '" . esc_sql(Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) . "'
                ) THEN {$state_alias}.map_visibility_policy
                ELSE '" . esc_sql(Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE) . "'
            END
        ";
    }

    private static function pricing_percent_expr(string $state_alias): string
    {
        return "
            CASE
                WHEN {$state_alias}.pricing_mode = 'global_percent' THEN " . self::global_percent_literal() . "
                ELSE {$state_alias}.pricing_percent
            END
        ";
    }

    private static function cost_base_expr(string $offer_alias): string
    {
        return "
            CASE
                WHEN {$offer_alias}.dealer_price IS NOT NULL AND {$offer_alias}.dealer_price > 0
                THEN {$offer_alias}.dealer_price + GREATEST(COALESCE({$offer_alias}.shipping_cost, 0.0000), 0.0000)
                WHEN {$offer_alias}.landed_cost IS NOT NULL AND {$offer_alias}.landed_cost > 0
                THEN {$offer_alias}.landed_cost
                ELSE NULL
            END
        ";
    }

    private static function cost_base_without_shipping_expr(string $offer_alias): string
    {
        return "
            CASE
                WHEN {$offer_alias}.dealer_price IS NOT NULL AND {$offer_alias}.dealer_price > 0
                THEN {$offer_alias}.dealer_price
                WHEN {$offer_alias}.landed_cost IS NOT NULL AND {$offer_alias}.landed_cost > 0
                THEN {$offer_alias}.landed_cost
                ELSE NULL
            END
        ";
    }

    private static function global_percent_literal(): string
    {
        return number_format(max(0.0, (float) Options::get_global_markup()), 4, '.', '');
    }

    private static function payment_fee_fraction_literal(): string
    {
        $fee_percent = (float) Options::get_payment_processor_fee_percent();
        if (!is_finite($fee_percent) || $fee_percent < 0.0) {
            $fee_percent = 0.0;
        }

        return number_format(min(0.99, $fee_percent / 100.0), 8, '.', '');
    }

    private static function payment_fee_denominator_literal(): string
    {
        $fee_fraction = (float) self::payment_fee_fraction_literal();
        $denominator = 1.0 - $fee_fraction;

        return number_format(max(0.01, $denominator), 8, '.', '');
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
