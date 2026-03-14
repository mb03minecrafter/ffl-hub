<?php

namespace FFLHub\Distributor\Services\Routing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared routing optimizer for carts/orders that contain:
 * - direct_ship eligible items (dropship_enabled=1)
 * - dealer_fulfilled required items (dropship_enabled=0)
 *
 * Cost model:
 * - Distributor lane fees (flat per active lane, per distributor):
 *   - dealer_inbound
 *   - direct_home
 *   - direct_ffl
 * - Dealer outbound fees (combined across distributors):
 *   - dealer -> home
 *   - dealer -> ffl
 *
 * Outbound formula for now:
 *   5.85 + 0.60 * ceil(weight_oz / 4)
 */
final class DealerFulfillmentRoutingPlanner
{
    private const OUTBOUND_BASE_COST = 5.85;
    private const OUTBOUND_INCREMENT = 0.60;
    private const OUTBOUND_STEP_OZ   = 4.0;
    private const TOP_CANDIDATE_LIMIT = 12;

    /**
     * @param array<int, array<string,mixed>> $lines
     * @return array<string,mixed>
     */
    public static function find_cheapest_plan(array $lines): array
    {
        $normalized = self::normalize_lines($lines);
        if (empty($normalized)) {
            return self::empty_plan();
        }

        /** @var array<string, array<string,mixed>> $lines_by_id */
        $lines_by_id = [];
        $assignment  = [];
        $decision_ids = [];
        $fixed_count = 0;

        foreach ($normalized as $line) {
            $line_id = (string) $line['line_id'];
            $lines_by_id[$line_id] = $line;

            if (!empty($line['dropship_enabled'])) {
                $decision_ids[] = $line_id;
            } else {
                $assignment[$line_id] = 'dealer_fulfilled';
                $fixed_count++;
            }
        }

        $decision_count = count($decision_ids);
        $combinations   = 0;
        $best_cost      = INF;
        $best_plan      = self::empty_plan();
        $top_candidates = [];

        self::walk_assignments(
            $decision_ids,
            0,
            $assignment,
            $lines_by_id,
            $best_cost,
            $best_plan,
            $combinations,
            $top_candidates
        );

        $best_assignment_key = self::assignment_key((array) ($best_plan['assignments'] ?? []));
        $alternatives = [];
        foreach ($top_candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidate_key = (string) ($candidate['assignment_key'] ?? '');
            if ($candidate_key === $best_assignment_key) {
                continue;
            }
            $alternatives[] = $candidate;
        }

        $best_plan['meta'] = [
            'total_lines'            => count($normalized),
            'decision_lines'         => $decision_count,
            'fixed_dealer_lines'     => $fixed_count,
            'combinations_evaluated' => $combinations,
            'best_assignment_key'    => $best_assignment_key,
            'best_formula_total'     => (float) ($best_plan['total_cost'] ?? 0.0),
            'top_candidates'         => $top_candidates,
            'alternatives'           => $alternatives,
        ];

        return $best_plan;
    }

    /**
     * @param array<string, array<string,mixed>> $lines_by_id
     * @param array<string,string> $assignment
     */
    private static function score_assignment(array $lines_by_id, array $assignment): array
    {
        /** @var array<string, array<string,mixed>> $by_dist */
        $by_dist = [];
        $assignments = [];

        $dealer_home_weight_oz = 0.0;
        $dealer_ffl_weight_oz  = 0.0;

        foreach ($lines_by_id as $line_id => $line) {
            $dist_id = (string) $line['dist_id'];
            if ($dist_id === '') {
                continue;
            }

            if (!isset($by_dist[$dist_id])) {
                $by_dist[$dist_id] = [
                    'lane_fee'       => 0.0,
                    'dealer_inbound' => false,
                    'direct_home'    => false,
                    'direct_ffl'     => false,
                ];
            }

            $lane_fee = (float) ($line['dist_lane_fee'] ?? 0.0);
            if ($lane_fee > (float) $by_dist[$dist_id]['lane_fee']) {
                $by_dist[$dist_id]['lane_fee'] = $lane_fee;
            }

            $qty       = max(1, (int) ($line['qty'] ?? 1));
            $weight_oz = max(0.0, (float) ($line['weight_oz'] ?? 0.0));
            $line_weight_oz = $weight_oz * (float) $qty;

            $ffl_required = !empty($line['ffl_required']);
            $dropship_enabled = !empty($line['dropship_enabled']);

            $route = 'dealer_fulfilled';
            if ($dropship_enabled) {
                $chosen = strtolower(trim((string) ($assignment[$line_id] ?? 'direct_ship')));
                $route = ($chosen === 'dealer_fulfilled') ? 'dealer_fulfilled' : 'direct_ship';
            }

            $assignments[$line_id] = $route;

            if ($route === 'dealer_fulfilled') {
                $by_dist[$dist_id]['dealer_inbound'] = true;

                if ($ffl_required) {
                    $dealer_ffl_weight_oz += $line_weight_oz;
                } else {
                    $dealer_home_weight_oz += $line_weight_oz;
                }

                continue;
            }

            if ($ffl_required) {
                $by_dist[$dist_id]['direct_ffl'] = true;
            } else {
                $by_dist[$dist_id]['direct_home'] = true;
            }
        }

        ksort($by_dist);
        ksort($assignments);

        $distributor_cost_total = 0.0;

        foreach ($by_dist as $dist_id => $row) {
            $lane_count = 0;
            if (!empty($row['dealer_inbound'])) {
                $lane_count++;
            }
            if (!empty($row['direct_home'])) {
                $lane_count++;
            }
            if (!empty($row['direct_ffl'])) {
                $lane_count++;
            }

            $lane_fee = max(0.0, (float) ($row['lane_fee'] ?? 0.0));
            $cost = $lane_fee * (float) $lane_count;

            $by_dist[$dist_id]['active_lanes'] = $lane_count;
            $by_dist[$dist_id]['cost'] = $cost;

            $distributor_cost_total += $cost;
        }

        $dealer_outbound_home_cost = self::dealer_outbound_cost($dealer_home_weight_oz);
        $dealer_outbound_ffl_cost  = self::dealer_outbound_cost($dealer_ffl_weight_oz);

        $total_cost = $distributor_cost_total + $dealer_outbound_home_cost + $dealer_outbound_ffl_cost;

        return [
            'total_cost'                 => $total_cost,
            'distributor_cost_total'     => $distributor_cost_total,
            'dealer_outbound_home_cost'  => $dealer_outbound_home_cost,
            'dealer_outbound_ffl_cost'   => $dealer_outbound_ffl_cost,
            'dealer_outbound_home_oz'    => $dealer_home_weight_oz,
            'dealer_outbound_ffl_oz'     => $dealer_ffl_weight_oz,
            'by_dist'                    => $by_dist,
            'assignments'                => $assignments,
            'meta'                       => [],
        ];
    }

    /**
     * @param array<int,string> $decision_ids
     * @param array<string,string> $assignment
     * @param array<string,array<string,mixed>> $lines_by_id
     * @param float $best_cost
     * @param array<string,mixed> $best_plan
     * @param int $combinations
     * @param array<int,array<string,mixed>> $top_candidates
     */
    private static function walk_assignments(
        array $decision_ids,
        int $idx,
        array &$assignment,
        array $lines_by_id,
        float &$best_cost,
        array &$best_plan,
        int &$combinations,
        array &$top_candidates
    ): void {
        if ($idx >= count($decision_ids)) {
            $combinations++;
            $plan = self::score_assignment($lines_by_id, $assignment);
            $cost = (float) ($plan['total_cost'] ?? INF);
            self::push_top_candidate($top_candidates, $plan, $cost);

            if ($cost < $best_cost - 0.000001) {
                $best_cost = $cost;
                $best_plan = $plan;
                return;
            }

            if (abs($cost - $best_cost) <= 0.000001) {
                $current = wp_json_encode($plan['assignments'] ?? []);
                $best    = wp_json_encode($best_plan['assignments'] ?? []);
                if (is_string($current) && is_string($best) && strcmp($current, $best) < 0) {
                    $best_plan = $plan;
                }
            }
            return;
        }

        $line_id = $decision_ids[$idx];

        $assignment[$line_id] = 'direct_ship';
        self::walk_assignments($decision_ids, $idx + 1, $assignment, $lines_by_id, $best_cost, $best_plan, $combinations, $top_candidates);

        $assignment[$line_id] = 'dealer_fulfilled';
        self::walk_assignments($decision_ids, $idx + 1, $assignment, $lines_by_id, $best_cost, $best_plan, $combinations, $top_candidates);

        unset($assignment[$line_id]);
    }

    /**
     * Keep a bounded list of the cheapest candidate assignments for debug visibility.
     *
     * @param array<int,array<string,mixed>> $top
     * @param array<string,mixed> $plan
     */
    private static function push_top_candidate(array &$top, array $plan, float $cost): void
    {
        $assignments = [];
        if (isset($plan['assignments']) && is_array($plan['assignments'])) {
            $assignments = $plan['assignments'];
        }

        $entry = [
            'assignment_key'         => self::assignment_key($assignments),
            'total_cost'             => $cost,
            'distributor_cost_total' => (float) ($plan['distributor_cost_total'] ?? 0.0),
            'dealer_home_cost'       => (float) ($plan['dealer_outbound_home_cost'] ?? 0.0),
            'dealer_ffl_cost'        => (float) ($plan['dealer_outbound_ffl_cost'] ?? 0.0),
            'assignments'            => $assignments,
        ];

        $top[] = $entry;
        usort($top, static function (array $a, array $b): int {
            $ac = (float) ($a['total_cost'] ?? INF);
            $bc = (float) ($b['total_cost'] ?? INF);
            if (abs($ac - $bc) > 0.000001) {
                return ($ac < $bc) ? -1 : 1;
            }

            $ak = (string) ($a['assignment_key'] ?? '');
            $bk = (string) ($b['assignment_key'] ?? '');
            return strcmp($ak, $bk);
        });

        if (count($top) > self::TOP_CANDIDATE_LIMIT) {
            $top = array_slice($top, 0, self::TOP_CANDIDATE_LIMIT);
        }
    }

    /**
     * @param array<string,string> $assignments
     */
    private static function assignment_key(array $assignments): string
    {
        if (empty($assignments)) {
            return '';
        }

        ksort($assignments);
        $parts = [];
        foreach ($assignments as $line_id => $route) {
            $parts[] = (string) $line_id . '=' . (string) $route;
        }

        return implode('|', $parts);
    }

    /**
     * @param array<int, array<string,mixed>> $lines
     * @return array<int, array<string,mixed>>
     */
    private static function normalize_lines(array $lines): array
    {
        $out = [];

        foreach ($lines as $i => $row) {
            if (!is_array($row)) {
                continue;
            }

            $line = self::normalize_line($row, (int) $i);
            if ($line === null) {
                continue;
            }

            $out[] = $line;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private static function normalize_line(array $row, int $i): ?array
    {
        $dist_id = strtolower(trim((string) ($row['dist_id'] ?? '')));
        if ($dist_id === '') {
            return null;
        }

        $line_id = trim((string) ($row['line_id'] ?? ''));
        if ($line_id === '') {
            $line_id = 'line_' . (string) $i;
        }

        $qty = (int) ($row['qty'] ?? 1);
        if ($qty < 1) {
            $qty = 1;
        }

        $weight_oz = (float) ($row['weight_oz'] ?? 0.0);
        if (!is_finite($weight_oz) || $weight_oz < 0.0) {
            $weight_oz = 0.0;
        }

        $dist_lane_fee = (float) ($row['dist_lane_fee'] ?? 0.0);
        if (!is_finite($dist_lane_fee) || $dist_lane_fee < 0.0) {
            $dist_lane_fee = 0.0;
        }

        return [
            'line_id'          => $line_id,
            'dist_id'          => $dist_id,
            'qty'              => $qty,
            'weight_oz'        => $weight_oz,
            'ffl_required'     => !empty($row['ffl_required']),
            'dropship_enabled' => !empty($row['dropship_enabled']),
            'dist_lane_fee'    => $dist_lane_fee,
        ];
    }

    private static function dealer_outbound_cost(float $weight_oz): float
    {
        if (!is_finite($weight_oz) || $weight_oz <= 0.0) {
            return 0.0;
        }

        $steps = (int) ceil($weight_oz / self::OUTBOUND_STEP_OZ);
        if ($steps < 1) {
            $steps = 1;
        }

        return self::OUTBOUND_BASE_COST + (self::OUTBOUND_INCREMENT * (float) $steps);
    }

    /**
     * @return array<string,mixed>
     */
    private static function empty_plan(): array
    {
        return [
            'total_cost'                 => 0.0,
            'distributor_cost_total'     => 0.0,
            'dealer_outbound_home_cost'  => 0.0,
            'dealer_outbound_ffl_cost'   => 0.0,
            'dealer_outbound_home_oz'    => 0.0,
            'dealer_outbound_ffl_oz'     => 0.0,
            'by_dist'                    => [],
            'assignments'                => [],
            'meta'                       => [],
        ];
    }
}
