<?php
declare(strict_types=1);

namespace FFLHub\Shipping;

if (!defined('ABSPATH')) {
    exit;
}

final class PhoenixShippingPlanBridge
{
    /**
     * @param array<string,array<string,mixed>> $by_dist
     * @param array<string,mixed> $line
     */
    public static function add_line_to_by_dist(array &$by_dist, array $line): void
    {
        $dist_id = strtolower(trim((string) ($line['dist_id'] ?? '')));
        if ($dist_id === '') {
            $dist_id = 'phoenix';
        }

        if (!isset($by_dist[$dist_id])) {
            $by_dist[$dist_id] = [
                'dist_id' => $dist_id,
                'lane_fee' => 0.0,
                'dealer_inbound_lane_fee' => 0.0,
                'direct_home_lane_fee' => 0.0,
                'direct_ffl_lane_fee' => 0.0,
                'dealer_inbound' => false,
                'direct_home' => false,
                'direct_ffl' => false,
                'active_lanes' => 0,
                'cost' => 0.0,
                'source' => 'phoenix_product_meta',
            ];
        }

        $lane_fee = max(0.0, (float) ($line['source_shipping_unit_cost'] ?? 0.0));
        $is_drop_ship = !empty($line['dropship']);
        $is_ffl = !empty($line['ffl_required']);

        if (!$is_drop_ship) {
            $by_dist[$dist_id]['dealer_inbound'] = true;
            $by_dist[$dist_id]['dealer_inbound_lane_fee'] = max(
                (float) ($by_dist[$dist_id]['dealer_inbound_lane_fee'] ?? 0.0),
                $lane_fee
            );
        } elseif ($is_ffl) {
            $by_dist[$dist_id]['direct_ffl'] = true;
            $by_dist[$dist_id]['direct_ffl_lane_fee'] = max(
                (float) ($by_dist[$dist_id]['direct_ffl_lane_fee'] ?? 0.0),
                $lane_fee
            );
        } else {
            $by_dist[$dist_id]['direct_home'] = true;
            $by_dist[$dist_id]['direct_home_lane_fee'] = max(
                (float) ($by_dist[$dist_id]['direct_home_lane_fee'] ?? 0.0),
                $lane_fee
            );
        }

        $by_dist[$dist_id]['lane_fee'] = max((float) ($by_dist[$dist_id]['lane_fee'] ?? 0.0), $lane_fee);
        self::recalculate_dist_row_cost($by_dist[$dist_id]);
    }

    /**
     * @param array<string,array<string,mixed>> $by_dist
     */
    public static function sum_by_dist_cost(array $by_dist): float
    {
        $total = 0.0;
        foreach ($by_dist as $row) {
            if (!is_array($row)) {
                continue;
            }
            $total += max(0.0, (float) ($row['cost'] ?? 0.0));
        }

        return $total;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<int,array<string,mixed>> $phoenix_lines
     * @param array<string,array<string,mixed>> $phoenix_by_dist
     * @return array<string,mixed>
     */
    public static function merge_plan(
        array $plan,
        array $phoenix_lines,
        array $phoenix_by_dist,
        float $phoenix_shipping_cost_total,
        float $phoenix_customer_chargeable_shipping_cost_total
    ): array {
        $by_dist = (isset($plan['by_dist']) && is_array($plan['by_dist'])) ? $plan['by_dist'] : [];
        foreach ($phoenix_by_dist as $dist_id => $phoenix_row) {
            if (!is_array($phoenix_row)) {
                continue;
            }
            if (!isset($by_dist[$dist_id]) || !is_array($by_dist[$dist_id])) {
                $by_dist[$dist_id] = $phoenix_row;
                continue;
            }

            $row = $by_dist[$dist_id];
            foreach (['dealer_inbound', 'direct_home', 'direct_ffl'] as $flag) {
                $row[$flag] = !empty($row[$flag]) || !empty($phoenix_row[$flag]);
            }
            foreach (['dealer_inbound_lane_fee', 'direct_home_lane_fee', 'direct_ffl_lane_fee', 'lane_fee'] as $fee_key) {
                $row[$fee_key] = max(
                    (float) ($row[$fee_key] ?? 0.0),
                    (float) ($phoenix_row[$fee_key] ?? 0.0)
                );
            }
            $row['active_lanes'] = self::active_lane_count($row);
            $row['cost'] = max(0.0, (float) ($row['cost'] ?? 0.0)) + max(0.0, (float) ($phoenix_row['cost'] ?? 0.0));
            $row['source'] = trim((string) ($row['source'] ?? '')) !== ''
                ? (string) $row['source'] . ',phoenix_product_meta'
                : 'phoenix_product_meta';
            $by_dist[$dist_id] = $row;
        }

        $plan['by_dist'] = $by_dist;
        $plan['phoenix_meta_lines'] = $phoenix_lines;
        $plan['phoenix_meta_shipping_cost_total'] = $phoenix_shipping_cost_total;
        $plan['phoenix_meta_customer_chargeable_shipping_cost_total'] = $phoenix_customer_chargeable_shipping_cost_total;
        $plan['distributor_cost_total'] = max(0.0, (float) ($plan['distributor_cost_total'] ?? 0.0)) + $phoenix_shipping_cost_total;
        $plan['meta']['phoenix_product_meta_lines'] = count($phoenix_lines);

        return $plan;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function recalculate_dist_row_cost(array &$row): void
    {
        $cost = 0.0;

        if (!empty($row['dealer_inbound'])) {
            $cost += max(0.0, (float) ($row['dealer_inbound_lane_fee'] ?? 0.0));
        }
        if (!empty($row['direct_home'])) {
            $cost += max(0.0, (float) ($row['direct_home_lane_fee'] ?? 0.0));
        }
        if (!empty($row['direct_ffl'])) {
            $cost += max(0.0, (float) ($row['direct_ffl_lane_fee'] ?? 0.0));
        }

        $row['active_lanes'] = self::active_lane_count($row);
        $row['cost'] = $cost;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function active_lane_count(array $row): int
    {
        return (!empty($row['dealer_inbound']) ? 1 : 0)
            + (!empty($row['direct_home']) ? 1 : 0)
            + (!empty($row['direct_ffl']) ? 1 : 0);
    }
}
