<?php
declare(strict_types=1);

namespace FFLHub\Shipping;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Describes which shipping expense a fixed-profit product price already
 * recovers. Route planning still records every real shipping expense; this
 * policy only prevents a recovered expense from being charged a second time.
 */
final class CustomerShippingCostPolicy
{
    /**
     * @param array<string,mixed> $row
     * @return array{distributor:float,dealer_outbound:float}
     */
    public static function fixed_profit_coverage(
        array $row,
        bool $use_product_state_usps_shipping
    ): array {
        $coverage = [
            'distributor' => 0.0,
            'dealer_outbound' => 0.0,
        ];

        $pricing_mode = strtolower(trim((string) ($row['pricing_mode'] ?? ($row['markup_mode'] ?? ''))));
        if ($pricing_mode !== 'fixed_profit') {
            return $coverage;
        }

        $distributor_shipping = self::non_negative_number($row['shipping_cost'] ?? null);
        $estimated_usps_shipping = self::non_negative_number($row['estimated_usps_shipping_cost'] ?? null);
        $dropship_enabled = self::boolish($row['dropship_enabled'] ?? null, false);

        // This mirrors Product State fixed-profit pricing exactly. Dropship
        // prices recover distributor freight. Dealer-fulfilled prices recover
        // stored USPS outbound freight when that mode is enabled, with the
        // existing distributor-freight fallback when no USPS estimate exists.
        if ($use_product_state_usps_shipping && !$dropship_enabled) {
            if ($estimated_usps_shipping !== null) {
                $coverage['dealer_outbound'] = $estimated_usps_shipping;
            } elseif ($distributor_shipping !== null) {
                $coverage['distributor'] = $distributor_shipping;
            }

            return $coverage;
        }

        if ($distributor_shipping !== null) {
            $coverage['distributor'] = $distributor_shipping;
        }

        return $coverage;
    }

    /**
     * Resolve the customer-facing shipping remainder for a one-line route.
     * Feeds and quote emails use one-line plans, while checkout performs the
     * equivalent calculation across shared cart lanes.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $plan
     * @return array{economic:float,customer_chargeable:float,ignored_dealer_inbound:float,price_recovered:float,route:string}
     */
    public static function single_line_costs(
        array $row,
        array $plan,
        string $line_id,
        bool $use_product_state_usps_shipping
    ): array {
        $coverage = self::fixed_profit_coverage($row, $use_product_state_usps_shipping);
        $policy_row = $row;
        $policy_row['line_id'] = $line_id;
        // Product State's qty is inventory, not the one-unit feed/quote quantity.
        $policy_row['qty'] = 1;
        $policy_row['dist_id'] = (string) ($row['dist_id'] ?? ($row['source'] ?? ''));
        $policy_row['dropship'] = self::boolish($row['dropship_enabled'] ?? null, true) ? 1 : 0;
        $policy_row['lane_fee'] = max(0.0, (float) ($plan['distributor_cost_total'] ?? 0.0));
        $policy_row['estimated_usps_shipping'] = max(
            0.0,
            (float) ($plan['dealer_outbound_home_cost'] ?? 0.0)
                + (float) ($plan['dealer_outbound_ffl_cost'] ?? 0.0)
        );
        $policy_row['price_recovered_distributor_shipping'] = $coverage['distributor'];
        $policy_row['price_recovered_dealer_outbound_shipping'] = $coverage['dealer_outbound'];

        $summary = self::summarize_plan(
            [$policy_row],
            $plan,
            $use_product_state_usps_shipping
        );
        $route = strtolower(trim((string) ($plan['assignments'][$line_id] ?? '')));
        if ($route !== 'dealer_fulfilled' && $route !== 'direct_ship') {
            $route = !empty($policy_row['dropship']) ? 'direct_ship' : 'dealer_fulfilled';
        }

        return [
            'economic' => $summary['economic'],
            'customer_chargeable' => $summary['customer_chargeable'],
            'ignored_dealer_inbound' => $summary['ignored_dealer_inbound'],
            'price_recovered' => $summary['price_recovered'],
            'route' => $route,
        ];
    }

    /**
     * Apply customer-facing shipping policy to a shared routing-plan result.
     * The route planner remains the authority for actual cost and assignments;
     * this method only identifies the customer-chargeable remainder.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $plan
     * @param array{home:float,ffl:float}|null $customer_outbound_override
     * @return array{economic:float,customer_chargeable:float,ignored_dealer_inbound:float,price_recovered:float,free_shipping_credit:float,distributor_chargeable:float,dealer_outbound_home_chargeable:float,dealer_outbound_ffl_chargeable:float,by_dist:array<string,array<string,mixed>>}
     */
    public static function summarize_plan(
        array $rows,
        array $plan,
        bool $use_product_state_usps_shipping,
        ?array $customer_outbound_override = null
    ): array {
        $assignments = is_array($plan['assignments'] ?? null) ? $plan['assignments'] : [];
        $economic = max(0.0, (float) ($plan['total_cost'] ?? 0.0));
        $distributor = self::distributor_summary(
            $rows,
            $assignments,
            $use_product_state_usps_shipping
        );

        if ($customer_outbound_override !== null) {
            $home_chargeable = max(0.0, (float) ($customer_outbound_override['home'] ?? 0.0));
            $ffl_chargeable = max(0.0, (float) ($customer_outbound_override['ffl'] ?? 0.0));
            $outbound_recovered = 0.0;
        } elseif ($use_product_state_usps_shipping) {
            $home = self::stored_outbound_summary($rows, $assignments, false);
            $ffl = self::stored_outbound_summary($rows, $assignments, true);
            $home_chargeable = $home['chargeable'];
            $ffl_chargeable = $ffl['chargeable'];
            $outbound_recovered = $home['recovered'] + $ffl['recovered'];
        } else {
            $home_chargeable = max(0.0, (float) ($plan['dealer_outbound_home_cost'] ?? 0.0));
            $ffl_chargeable = max(0.0, (float) ($plan['dealer_outbound_ffl_cost'] ?? 0.0));
            $outbound_recovered = 0.0;
        }

        $ignored_inbound = $use_product_state_usps_shipping
            ? self::dealer_inbound_cost_total((array) ($plan['by_dist'] ?? []))
            : 0.0;
        $price_recovered = max(
            0.0,
            $distributor['price_recovered'] + $outbound_recovered
        );
        $customer_chargeable = max(
            0.0,
            $distributor['chargeable'] + $home_chargeable + $ffl_chargeable
        );

        return [
            'economic' => $economic,
            'customer_chargeable' => min($economic, $customer_chargeable),
            'ignored_dealer_inbound' => min($economic, $ignored_inbound),
            'price_recovered' => min($economic, $price_recovered),
            'free_shipping_credit' => max(
                0.0,
                $economic - $customer_chargeable - $ignored_inbound - $price_recovered
            ),
            'distributor_chargeable' => max(0.0, $distributor['chargeable']),
            'dealer_outbound_home_chargeable' => $home_chargeable,
            'dealer_outbound_ffl_chargeable' => $ffl_chargeable,
            'by_dist' => $distributor['by_dist'],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,string> $assignments
     * @return array{chargeable:float,price_recovered:float,by_dist:array<string,array<string,mixed>>}
     */
    private static function distributor_summary(
        array $rows,
        array $assignments,
        bool $ignore_dealer_inbound
    ): array {
        $by_dist = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $customer_free = !empty($row['customer_free_shipping']);

            $dist_id = strtolower(trim((string) ($row['dist_id'] ?? ($row['source'] ?? ''))));
            if ($dist_id === '') {
                continue;
            }
            if (!isset($by_dist[$dist_id])) {
                $by_dist[$dist_id] = [
                    'dist_id' => $dist_id,
                    'dealer_inbound' => false,
                    'direct_home' => false,
                    'direct_ffl' => false,
                    'dealer_inbound_lane_fee' => 0.0,
                    'direct_home_lane_fee' => 0.0,
                    'direct_ffl_lane_fee' => 0.0,
                    'dealer_inbound_price_recovered' => 0.0,
                    'direct_home_price_recovered' => 0.0,
                    'direct_ffl_price_recovered' => 0.0,
                    'active_lanes' => 0,
                    'cost' => 0.0,
                    'price_recovered_cost' => 0.0,
                ];
            }

            $line_id = (string) ($row['line_id'] ?? '');
            $route = strtolower(trim((string) ($assignments[$line_id] ?? '')));
            if ($route !== 'dealer_fulfilled' && $route !== 'direct_ship') {
                $route = self::boolish($row['dropship'] ?? ($row['dropship_enabled'] ?? null), true)
                    ? 'direct_ship'
                    : 'dealer_fulfilled';
            }

            if ($route === 'dealer_fulfilled') {
                if ($ignore_dealer_inbound) {
                    continue;
                }
                $lane = 'dealer_inbound';
            } elseif (self::boolish($row['ffl_required'] ?? null, false)) {
                $lane = 'direct_ffl';
            } else {
                $lane = 'direct_home';
            }

            $lane_fee = max(0.0, (float) ($row['lane_fee'] ?? ($row['dist_lane_fee'] ?? 0.0)));
            if (!$customer_free) {
                $by_dist[$dist_id][$lane] = true;
            }
            $fee_key = $lane . '_lane_fee';
            $by_dist[$dist_id][$fee_key] = max((float) $by_dist[$dist_id][$fee_key], $lane_fee);

            $recovered = max(0.0, (float) ($row['price_recovered_distributor_shipping'] ?? 0.0));
            $recovered_key = $lane . '_price_recovered';
            $by_dist[$dist_id][$recovered_key] = max(
                (float) $by_dist[$dist_id][$recovered_key],
                $recovered
            );
        }

        $chargeable = 0.0;
        $price_recovered = 0.0;
        foreach ($by_dist as $dist_id => $dist_row) {
            $lane_count = 0;
            $dist_chargeable = 0.0;
            $dist_recovered = 0.0;
            foreach (['dealer_inbound', 'direct_home', 'direct_ffl'] as $lane) {
                $lane_fee = max(0.0, (float) ($dist_row[$lane . '_lane_fee'] ?? 0.0));
                $lane_recovered = min(
                    $lane_fee,
                    max(0.0, (float) ($dist_row[$lane . '_price_recovered'] ?? 0.0))
                );
                if (!empty($dist_row[$lane])) {
                    $lane_count++;
                    $dist_chargeable += max(0.0, $lane_fee - $lane_recovered);
                }
                $dist_recovered += $lane_recovered;
            }

            $by_dist[$dist_id]['active_lanes'] = $lane_count;
            $by_dist[$dist_id]['cost'] = $dist_chargeable;
            $by_dist[$dist_id]['price_recovered_cost'] = $dist_recovered;
            $chargeable += $dist_chargeable;
            $price_recovered += $dist_recovered;
        }
        ksort($by_dist);

        return [
            'chargeable' => max(0.0, $chargeable),
            'price_recovered' => max(0.0, $price_recovered),
            'by_dist' => $by_dist,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,string> $assignments
     * @return array{chargeable:float,recovered:float}
     */
    private static function stored_outbound_summary(
        array $rows,
        array $assignments,
        bool $ffl_lane
    ): array {
        $chargeable = 0.0;
        $recovered = 0.0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $customer_free = !empty($row['customer_free_shipping']);
            $line_id = (string) ($row['line_id'] ?? '');
            $route = strtolower(trim((string) ($assignments[$line_id] ?? '')));
            if ($route !== 'dealer_fulfilled' && $route !== 'direct_ship') {
                $route = self::boolish($row['dropship'] ?? ($row['dropship_enabled'] ?? null), true)
                    ? 'direct_ship'
                    : 'dealer_fulfilled';
            }
            if ($route !== 'dealer_fulfilled' || self::boolish($row['ffl_required'] ?? null, false) !== $ffl_lane) {
                continue;
            }

            $qty = max(1, (int) ($row['qty'] ?? 1));
            $unit_cost = max(
                0.0,
                (float) ($row['estimated_usps_shipping'] ?? ($row['dealer_outbound_unit_cost'] ?? 0.0))
            );
            $unit_recovered = min(
                $unit_cost,
                max(0.0, (float) ($row['price_recovered_dealer_outbound_shipping'] ?? 0.0))
            );
            if (!$customer_free) {
                $chargeable += max(0.0, $unit_cost - $unit_recovered) * $qty;
            }
            $recovered += $unit_recovered * $qty;
        }

        return [
            'chargeable' => max(0.0, $chargeable),
            'recovered' => max(0.0, $recovered),
        ];
    }

    /** @param array<string,array<string,mixed>> $by_dist */
    private static function dealer_inbound_cost_total(array $by_dist): float
    {
        $total = 0.0;
        foreach ($by_dist as $row) {
            if (!is_array($row) || empty($row['dealer_inbound'])) {
                continue;
            }
            $lane_fee = max(0.0, (float) ($row['dealer_inbound_lane_fee'] ?? 0.0));
            if ($lane_fee <= 0.0) {
                $lane_fee = max(0.0, (float) ($row['lane_fee'] ?? 0.0));
            }
            $total += $lane_fee;
        }

        return max(0.0, $total);
    }

    /** @param mixed $value */
    private static function non_negative_number($value): ?float
    {
        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $number = (float) $raw;
        return (is_finite($number) && $number >= 0.0) ? $number : null;
    }

    /** @param mixed $value */
    private static function boolish($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return $default;
        }
        if (in_array($raw, ['1', 'true', 't', 'yes', 'y', 'on'], true)) {
            return true;
        }
        if (in_array($raw, ['0', 'false', 'f', 'no', 'n', 'off'], true)) {
            return false;
        }

        return is_numeric($raw) ? ((float) $raw !== 0.0) : $default;
    }
}
