<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared SQL fragments for calculated product_state pricing fields.
 *
 * These expressions are used by both best-offer application and direct policy
 * refreshes. Keeping them here prevents the MAP/public-price math from
 * drifting between code paths.
 */
final class ProductStatePricingSql
{
    public static function computed_sell_price_expr(string $state_alias, string $offer_alias): string
    {
        $pricing_percent = self::pricing_percent_expr($state_alias);
        $cost_without_shipping = self::cost_base_without_shipping_expr($offer_alias);
        $map_price = self::effective_map_price_expr($state_alias, $offer_alias);
        $shipping = self::fixed_profit_shipping_expr($state_alias, $offer_alias);
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
                        + CASE
                            WHEN {$state_alias}.fixed_profit_shipping_mode = 'separate'
                            THEN (({$cost_without_shipping} + {$shipping}) * {$fee_fraction})
                            ELSE {$shipping} + ({$cost_without_shipping} * {$fee_fraction})
                          END
                    ) / {$denominator},
                    2
                )

                WHEN {$state_alias}.pricing_mode = 'map_price'
                    AND {$map_price} IS NOT NULL
                    AND {$map_price} > 0
                THEN ROUND({$map_price}, 2)

                WHEN {$state_alias}.pricing_mode IN ('global_percent', 'fixed_percent')
                    AND {$pricing_percent} IS NOT NULL
                    AND {$cost_without_shipping} IS NOT NULL
                    AND {$cost_without_shipping} > 0
                THEN CEIL({$cost_without_shipping} * (1.0 + ({$pricing_percent} / 100.0))) - 0.01

                ELSE {$state_alias}.computed_sell_price
            END
        ";
    }

    public static function public_regular_price_expr(
        string $state_alias,
        string $offer_alias,
        string $computed_sell_price,
        string $effective_map_policy
    ): string {
        $map_price = self::effective_map_price_expr($state_alias, $offer_alias);

        return "
            CASE
                WHEN {$map_price} IS NOT NULL
                    AND {$map_price} > 0
                    AND {$effective_map_policy} IN ('" . esc_sql(Options::MAP_POLICY_EMAIL_FOR_QUOTE) . "', '" . esc_sql(Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) . "')
                THEN CASE
                    WHEN {$offer_alias}.msrp IS NOT NULL
                        AND {$offer_alias}.msrp > {$map_price}
                    THEN ROUND({$offer_alias}.msrp, 2)
                    ELSE ROUND({$map_price}, 2)
                END

                WHEN {$computed_sell_price} IS NULL OR {$computed_sell_price} <= 0
                THEN {$state_alias}.public_regular_price

                WHEN {$map_price} IS NOT NULL
                    AND {$map_price} > {$computed_sell_price}
                THEN ROUND({$map_price}, 2)

                WHEN {$offer_alias}.msrp IS NOT NULL
                    AND {$offer_alias}.msrp > {$computed_sell_price}
                THEN ROUND({$offer_alias}.msrp, 2)

                ELSE ROUND({$computed_sell_price}, 2)
            END
        ";
    }

    public static function public_sale_price_expr(
        string $state_alias,
        string $offer_alias,
        string $computed_sell_price,
        string $effective_map_policy
    ): string {
        $map_price = self::effective_map_price_expr($state_alias, $offer_alias);

        return "
            CASE
                WHEN {$map_price} IS NOT NULL
                    AND {$map_price} > 0
                    AND {$effective_map_policy} IN ('" . esc_sql(Options::MAP_POLICY_EMAIL_FOR_QUOTE) . "', '" . esc_sql(Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) . "')
                THEN ROUND({$map_price}, 2)

                WHEN {$computed_sell_price} IS NULL OR {$computed_sell_price} <= 0
                THEN {$state_alias}.public_sale_price

                WHEN {$map_price} IS NOT NULL
                    AND {$map_price} > {$computed_sell_price}
                THEN ROUND({$computed_sell_price}, 2)

                WHEN {$offer_alias}.msrp IS NOT NULL
                    AND {$offer_alias}.msrp > {$computed_sell_price}
                THEN ROUND({$computed_sell_price}, 2)

                ELSE NULL
            END
        ";
    }

    public static function effective_map_price_expr(string $state_alias, string $offer_alias): string
    {
        return "
            CASE
                WHEN {$state_alias}.map_override_mode = 'force_no_map' THEN NULL
                WHEN {$state_alias}.map_override_mode = 'manual_price'
                    AND {$state_alias}.map_override_price IS NOT NULL
                    AND {$state_alias}.map_override_price > 0
                THEN {$state_alias}.map_override_price
                ELSE {$offer_alias}.map_price
            END
        ";
    }

    public static function map_applicable_expr(string $state_alias, string $offer_alias): string
    {
        $map_price = self::effective_map_price_expr($state_alias, $offer_alias);

        return "
            CASE
                WHEN {$map_price} IS NOT NULL AND {$map_price} > 0 THEN 1
                ELSE 0
            END
        ";
    }

    public static function effective_map_policy_expr(string $state_alias, string $offer_alias): string
    {
        return "
            CASE
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

    private static function fixed_profit_shipping_expr(string $state_alias, string $offer_alias): string
    {
        if (!Options::get_use_product_state_usps_shipping()) {
            return "GREATEST(COALESCE({$offer_alias}.shipping_cost, 0.0000), 0.0000)";
        }

        return "
            CASE
                WHEN COALESCE({$offer_alias}.dropship_enabled, 0) = 1
                THEN GREATEST(COALESCE({$offer_alias}.shipping_cost, 0.0000), 0.0000)
                ELSE GREATEST(
                    COALESCE(
                        {$state_alias}.estimated_usps_shipping_cost,
                        {$offer_alias}.shipping_cost,
                        0.0000
                    ),
                    0.0000
                )
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
}
