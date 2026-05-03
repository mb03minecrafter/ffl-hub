<?php

use FFLHub\Order\OrderProfitAuditMeta;
use FFLHub\Product\ProductMeta;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must be run through wp eval-file.\n");
    exit(1);
}

$cli_args = [];
if (isset($args) && is_array($args)) {
    $cli_args = array_values($args);
} elseif (isset($argv) && is_array($argv)) {
    $cli_args = array_slice($argv, 1);
}

$mode = strtolower(trim((string)($cli_args[0] ?? 'dry-run')));
if (!in_array($mode, ['dry-run', 'commit'], true)) {
    fwrite(STDERR, "Usage: wp eval-file backfill-zanders-shipping-costs.php -- [dry-run|commit] [order_batch_limit] [all|products|orders]\n");
    exit(1);
}

$commit = ($mode === 'commit');
$order_batch_limit = 100;
$scope = 'all';

foreach (array_slice($cli_args, 1) as $arg) {
    $arg = strtolower(trim((string)$arg));
    if ($arg === '') {
        continue;
    }

    if (is_numeric($arg)) {
        $order_batch_limit = max(1, (int)$arg);
        continue;
    }

    $scope = $arg;
}

if (!in_array($scope, ['all', 'products', 'orders'], true)) {
    fwrite(STDERR, "Invalid scope '{$scope}'. Use all, products, or orders.\n");
    exit(1);
}

const FFLHUB_ZANDERS_SHIP = 15.00;
const FFLHUB_ZANDERS_FREE_THRESHOLD = 500.00;

function fflhub_zanders_money4(float $value): string
{
    return wc_format_decimal(max(0.0, $value), 4);
}

function fflhub_zanders_non_negative_float($value): ?float
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $num = is_numeric($raw) ? $raw : trim((string)preg_replace('/[^0-9\.\-]/', '', $raw));
    if ($num === '' || !is_numeric($num)) {
        return null;
    }

    $float = (float)$num;
    return is_finite($float) && $float >= 0.0 ? $float : null;
}

function fflhub_zanders_decode_array($value): array
{
    if (is_array($value)) {
        return $value;
    }

    if (is_object($value)) {
        $json = wp_json_encode($value);
        $decoded = is_string($json) ? json_decode($json, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    if (is_string($value) && $value !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $unserialized = maybe_unserialize($value);
        if (is_array($unserialized)) {
            return $unserialized;
        }
    }

    return [];
}

function fflhub_zanders_digits($value): string
{
    $digits = preg_replace('/\D+/', '', (string)$value);
    return is_string($digits) ? $digits : '';
}

function fflhub_zanders_live_table_name(): string
{
    global $wpdb;

    $v1 = $wpdb->prefix . 'fflhub_zanders_product_v1';
    $v2 = $wpdb->prefix . 'fflhub_zanders_product_v2';
    $stored = get_option('fflhub_zanders_product_live_table');

    if ($stored === $v1 || $stored === $v2) {
        return (string)$stored;
    }

    if ($stored === 'v1') {
        return $v1;
    }

    if ($stored === 'v2') {
        return $v2;
    }

    return $v1;
}

function fflhub_zanders_live_table_exists(): bool
{
    global $wpdb;

    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    $table = fflhub_zanders_live_table_name();
    $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    $exists = ($existing === $table);

    return $exists;
}

function fflhub_zanders_product_upc(WC_Product $product): string
{
    $upc_candidates = [
        $product->get_meta(ProductMeta::FFLHUB_UPC_META, true),
        method_exists($product, 'get_global_unique_id') ? $product->get_global_unique_id() : '',
    ];

    foreach ($upc_candidates as $candidate) {
        $digits = fflhub_zanders_digits($candidate);
        if ($digits !== '') {
            return $digits;
        }
    }

    return '';
}

function fflhub_zanders_table_distributor_cost_by_upc(string $upc): ?float
{
    global $wpdb;

    if ($upc === '' || !fflhub_zanders_live_table_exists()) {
        return null;
    }

    $table = fflhub_zanders_live_table_name();

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $raw = $wpdb->get_var($wpdb->prepare("SELECT distributor_price FROM {$table} WHERE upc = %s LIMIT 1", $upc));

    return fflhub_zanders_non_negative_float($raw);
}

function fflhub_zanders_product_source(WC_Product $product): string
{
    return strtolower(trim((string)$product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true)));
}

function fflhub_zanders_product_distributor_cost(WC_Product $product): ?float
{
    $upc = fflhub_zanders_product_upc($product);
    $table_cost = fflhub_zanders_table_distributor_cost_by_upc($upc);
    if ($table_cost !== null) {
        return $table_cost;
    }

    return fflhub_zanders_non_negative_float($product->get_meta(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, true));
}

function fflhub_zanders_shipping_cost_for_distributor_cost(?float $distributor_cost): float
{
    if ($distributor_cost !== null && $distributor_cost >= FFLHUB_ZANDERS_FREE_THRESHOLD) {
        return 0.0;
    }

    return FFLHUB_ZANDERS_SHIP;
}

function fflhub_zanders_order_line_profile(WC_Order $order): array
{
    $profile = [
        'has_zanders' => false,
        'line_count' => 0,
        'missing_cost_count' => 0,
        'min_unit_cost' => null,
        'max_unit_cost' => 0.0,
        'zanders_cost_total' => 0.0,
        'free_shipping_basis_amount' => 0.0,
        'desired_lane_fee' => FFLHUB_ZANDERS_SHIP,
    ];

    foreach ($order->get_items('line_item') as $item) {
        if (!($item instanceof WC_Order_Item_Product)) {
            continue;
        }

        $product = $item->get_product();
        if (!($product instanceof WC_Product)) {
            $product_id = (int)$item->get_product_id();
            $product = $product_id > 0 ? wc_get_product($product_id) : null;
        }

        $saved_dist = strtolower(trim((string)$item->get_meta('_fflhub_order_source_distributor', true)));
        $dist = $saved_dist;
        if ($dist === '' && $product instanceof WC_Product) {
            $dist = fflhub_zanders_product_source($product);
        }

        if ($dist !== 'zanders') {
            continue;
        }

        $qty = max(0, (int)$item->get_quantity());
        if ($qty <= 0) {
            continue;
        }

        $profile['has_zanders'] = true;
        $profile['line_count']++;

        $unit_cost = fflhub_zanders_non_negative_float($item->get_meta('_fflhub_order_distributor_unit_cost', true));
        if ($unit_cost === null && $product instanceof WC_Product) {
            $unit_cost = fflhub_zanders_product_distributor_cost($product);
        }

        if ($unit_cost === null) {
            $profile['missing_cost_count']++;
            continue;
        }

        $profile['min_unit_cost'] = ($profile['min_unit_cost'] === null)
            ? $unit_cost
            : min((float)$profile['min_unit_cost'], $unit_cost);
        $profile['max_unit_cost'] = max((float)$profile['max_unit_cost'], $unit_cost);
        $profile['zanders_cost_total'] += $unit_cost * (float)$qty;
    }

    $basis = ($profile['min_unit_cost'] !== null) ? (float)$profile['min_unit_cost'] : 0.0;
    $profile['free_shipping_basis_amount'] = $basis;
    $profile['desired_lane_fee'] = (
        !empty($profile['has_zanders'])
        && (int)$profile['missing_cost_count'] <= 0
        && $basis >= FFLHUB_ZANDERS_FREE_THRESHOLD
    )
        ? 0.0
        : FFLHUB_ZANDERS_SHIP;

    return $profile;
}

function fflhub_zanders_row_lane_count(array $row): int
{
    $count = 0;
    foreach (['dealer_inbound', 'direct_home', 'direct_ffl'] as $key) {
        if (!empty($row[$key])) {
            $count++;
        }
    }

    if ($count > 0) {
        return $count;
    }

    return max(0, (int)($row['active_lanes'] ?? 0));
}

function fflhub_zanders_desired_row_cost(array $row, array $profile): float
{
    $lane_fee = (float)($profile['desired_lane_fee'] ?? FFLHUB_ZANDERS_SHIP);
    if ($lane_fee <= 0.0) {
        return 0.0;
    }

    $direct_home = !empty($row['direct_home']);
    $direct_ffl = !empty($row['direct_ffl']);
    $dealer_inbound = !empty($row['dealer_inbound']);
    $inbound_waived = !empty($row['dealer_batch_free_inbound_shipping_applied']);

    $cost = 0.0;

    if ($direct_home) {
        $cost += $lane_fee;
    }

    if ($direct_ffl) {
        $cost += $lane_fee;
    }

    if ($dealer_inbound && !$inbound_waived) {
        $cost += $lane_fee;
    }

    if ($cost > 0.0 || $inbound_waived) {
        return $cost;
    }

    return $lane_fee * (float)fflhub_zanders_row_lane_count($row);
}

function fflhub_zanders_route_fee_meta(array $row, array $profile): array
{
    $lane_fee = (float)($profile['desired_lane_fee'] ?? FFLHUB_ZANDERS_SHIP);
    if ($lane_fee <= 0.0) {
        return [
            'dealer_inbound_lane_fee' => 0.0,
            'direct_home_lane_fee' => 0.0,
            'direct_ffl_lane_fee' => 0.0,
            'lane_fee' => 0.0,
        ];
    }

    $fees = [
        'dealer_inbound_lane_fee' => !empty($row['dealer_inbound']) ? $lane_fee : 0.0,
        'direct_home_lane_fee' => !empty($row['direct_home']) ? $lane_fee : 0.0,
        'direct_ffl_lane_fee' => !empty($row['direct_ffl']) ? $lane_fee : 0.0,
    ];

    if (fflhub_zanders_row_lane_count($row) > 0 && max($fees) <= 0.0) {
        $fees['dealer_inbound_lane_fee'] = $lane_fee;
    }

    $fees['lane_fee'] = $lane_fee;

    return $fees;
}

function fflhub_zanders_sum_by_dist_cost(array $by_dist): float
{
    $total = 0.0;
    foreach ($by_dist as $row) {
        if (is_object($row)) {
            $row = (array)$row;
        }
        if (!is_array($row)) {
            continue;
        }

        $total += fflhub_zanders_non_negative_float($row['cost'] ?? null) ?? 0.0;
    }

    return max(0.0, $total);
}

function fflhub_zanders_adjust_plan_total(array $plan, float $old_dist_total, float $new_dist_total): float
{
    $home = fflhub_zanders_non_negative_float($plan['dealer_outbound_home_cost'] ?? null);
    $ffl = fflhub_zanders_non_negative_float($plan['dealer_outbound_ffl_cost'] ?? null);
    if ($home !== null || $ffl !== null) {
        return $new_dist_total + (float)($home ?? 0.0) + (float)($ffl ?? 0.0);
    }

    $old_total = fflhub_zanders_non_negative_float($plan['total_cost'] ?? null);
    if ($old_total !== null) {
        return max(0.0, $old_total - $old_dist_total + $new_dist_total);
    }

    return $new_dist_total;
}

function fflhub_zanders_backfill_shipping_items(WC_Order $order, array $profile, bool $commit): array
{
    $changed = false;
    $rows_seen = 0;
    $old_total = 0.0;
    $new_total = 0.0;

    foreach ($order->get_items('shipping') as $shipping_item) {
        if (!($shipping_item instanceof WC_Order_Item_Shipping)) {
            continue;
        }

        $plan = fflhub_zanders_decode_array($shipping_item->get_meta('fflhub_shipping_plan', true));
        $by_dist = isset($plan['by_dist']) && is_array($plan['by_dist'])
            ? $plan['by_dist']
            : fflhub_zanders_decode_array($shipping_item->get_meta('fflhub_shipping_by_dist', true));

        if (empty($by_dist)) {
            continue;
        }

        $item_changed = false;
        $item_old_dist_total = fflhub_zanders_sum_by_dist_cost($by_dist);

        foreach ($by_dist as $key => $row) {
            if (is_object($row)) {
                $row = (array)$row;
            }
            if (!is_array($row)) {
                continue;
            }

            $row_dist = strtolower(trim((string)($row['dist_id'] ?? $key)));
            if ($row_dist !== 'zanders') {
                continue;
            }

            $rows_seen++;
            $old_cost = fflhub_zanders_non_negative_float($row['cost'] ?? null) ?? 0.0;
            $desired = fflhub_zanders_desired_row_cost($row, $profile);
            $fee_meta = fflhub_zanders_route_fee_meta($row, $profile);
            $fee_changed = false;
            foreach ($fee_meta as $fee_key => $fee_value) {
                $current_fee = fflhub_zanders_non_negative_float($row[$fee_key] ?? null) ?? 0.0;
                if (abs($current_fee - (float)$fee_value) > 0.0001) {
                    $fee_changed = true;
                    break;
                }
            }

            $old_total += $old_cost;
            $new_total += $desired;

            if (abs($old_cost - $desired) <= 0.0001 && !$fee_changed) {
                continue;
            }

            $row['dist_id'] = 'zanders';
            $row['cost_before_zanders_shipping_backfill'] = fflhub_zanders_money4($old_cost);
            $row['cost'] = fflhub_zanders_money4($desired);
            foreach ($fee_meta as $fee_key => $fee_value) {
                $row[$fee_key] = fflhub_zanders_money4((float)$fee_value);
            }
            $row['zanders_shipping_backfill_applied'] = true;
            $row['zanders_shipping_backfill_flat_rate'] = fflhub_zanders_money4(FFLHUB_ZANDERS_SHIP);
            $row['zanders_shipping_backfill_free_threshold'] = fflhub_zanders_money4(FFLHUB_ZANDERS_FREE_THRESHOLD);
            $row['zanders_shipping_backfill_min_unit_cost'] = fflhub_zanders_money4((float)($profile['min_unit_cost'] ?? 0.0));
            $row['zanders_shipping_backfill_max_unit_cost'] = fflhub_zanders_money4((float)$profile['max_unit_cost']);
            $row['zanders_shipping_backfill_order_cost_total'] = fflhub_zanders_money4((float)$profile['zanders_cost_total']);
            $row['zanders_shipping_backfill_updated_at_utc'] = gmdate('Y-m-d H:i:s');

            $by_dist[$key] = $row;
            $item_changed = true;
            $changed = true;
        }

        if (!$item_changed) {
            continue;
        }

        $new_dist_total = fflhub_zanders_sum_by_dist_cost($by_dist);
        $plan['by_dist'] = $by_dist;
        $plan['distributor_cost_total'] = fflhub_zanders_money4($new_dist_total);
        $plan['total_cost'] = fflhub_zanders_money4(
            fflhub_zanders_adjust_plan_total($plan, $item_old_dist_total, $new_dist_total)
        );
        $plan['zanders_shipping_backfill_applied'] = true;
        $plan['zanders_shipping_backfill_updated_at_utc'] = gmdate('Y-m-d H:i:s');

        if ($commit) {
            $shipping_item->update_meta_data('fflhub_shipping_plan', wp_json_encode($plan));
            $shipping_item->update_meta_data('fflhub_shipping_by_dist', wp_json_encode($by_dist));
            $shipping_item->update_meta_data('fflhub_shipping_cost_total', $plan['total_cost']);
            $shipping_item->save();
        }
    }

    return [
        'changed' => $changed,
        'rows_seen' => $rows_seen,
        'old_total' => $old_total,
        'new_total' => $new_total,
    ];
}

global $wpdb;

$products_seen = 0;
$products_changed = 0;
$products_missing_cost = 0;
$products_free_shipping = 0;
$products_flat_shipping = 0;
$products_true_cost_changed = 0;

if ($scope === 'all' || $scope === 'products') {
    $product_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.ID
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
         WHERE p.post_type IN ('product', 'product_variation')
           AND p.post_status NOT IN ('trash', 'auto-draft')
           AND pm.meta_key = %s
           AND LOWER(pm.meta_value) = %s
         ORDER BY p.ID ASC",
        ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META,
        'zanders'
    ));

    foreach ($product_ids as $product_id) {
        $product = wc_get_product((int)$product_id);
        if (!($product instanceof WC_Product)) {
            continue;
        }

        $products_seen++;
        $cost = fflhub_zanders_product_distributor_cost($product);
        if ($cost === null) {
            $products_missing_cost++;
        }

        $desired_ship = fflhub_zanders_shipping_cost_for_distributor_cost($cost);
        if ($desired_ship <= 0.0) {
            $products_free_shipping++;
        } else {
            $products_flat_shipping++;
        }

        $current_ship = fflhub_zanders_non_negative_float($product->get_meta(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, true));
        $needs_ship_update = ($current_ship === null || abs($current_ship - $desired_ship) > 0.0001);

        $needs_true_cost_update = false;
        $desired_true_cost = null;
        if ($cost !== null) {
            $desired_true_cost = $cost + $desired_ship;
            $current_true_cost = fflhub_zanders_non_negative_float($product->get_meta(ProductMeta::FFLHUB_LAST_TRUE_COST_META, true));
            $needs_true_cost_update = ($current_true_cost === null || abs($current_true_cost - $desired_true_cost) > 0.0001);
        }

        if (!$needs_ship_update && !$needs_true_cost_update) {
            continue;
        }

        $products_changed++;
        if ($needs_true_cost_update) {
            $products_true_cost_changed++;
        }

        if ($commit) {
            update_post_meta((int)$product_id, ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, fflhub_zanders_money4($desired_ship));
            if ($desired_true_cost !== null) {
                update_post_meta((int)$product_id, ProductMeta::FFLHUB_LAST_TRUE_COST_META, fflhub_zanders_money4($desired_true_cost));
            }
        }
    }
}

$orders_seen = 0;
$orders_with_zanders = 0;
$orders_changed = 0;
$orders_missing_cost = 0;
$shipping_rows_seen = 0;
$old_zanders_shipping_total = 0.0;
$new_zanders_shipping_total = 0.0;

if ($scope === 'all' || $scope === 'orders') {
    $statuses = array_values(array_diff(
        array_keys(wc_get_order_statuses()),
        ['wc-checkout-draft', 'checkout-draft', 'draft', 'auto-draft', 'trash']
    ));
    $page = 1;

    do {
        $order_ids = wc_get_orders([
            'type' => 'shop_order',
            'status' => $statuses,
            'limit' => $order_batch_limit,
            'paged' => $page,
            'return' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        if (empty($order_ids)) {
            break;
        }

        foreach ($order_ids as $order_id) {
            $order = wc_get_order((int)$order_id);
            if (!($order instanceof WC_Order)) {
                continue;
            }

            $orders_seen++;
            $profile = fflhub_zanders_order_line_profile($order);
            if (empty($profile['has_zanders'])) {
                continue;
            }

            $orders_with_zanders++;
            if ((int)$profile['missing_cost_count'] > 0) {
                $orders_missing_cost++;
            }

            $result = fflhub_zanders_backfill_shipping_items($order, $profile, $commit);
            $shipping_rows_seen += (int)$result['rows_seen'];
            $old_zanders_shipping_total += (float)$result['old_total'];
            $new_zanders_shipping_total += (float)$result['new_total'];

            if (empty($result['changed'])) {
                continue;
            }

            $orders_changed++;
            if ($commit) {
                OrderProfitAuditMeta::recalculate_order($order, true);
            }
        }

        $page++;
    } while (count($order_ids) === $order_batch_limit);
}

echo "==== Zanders Shipping Backfill ====\n";
echo "Mode: {$mode}\n";
echo "Scope: {$scope}\n";
echo "Flat rate: $" . number_format(FFLHUB_ZANDERS_SHIP, 2) . "\n";
echo "Free threshold: $" . number_format(FFLHUB_ZANDERS_FREE_THRESHOLD, 2) . "\n";
echo "Live table: " . fflhub_zanders_live_table_name() . (fflhub_zanders_live_table_exists() ? " (found)" : " (missing)") . "\n\n";

echo "Products scanned: {$products_seen}\n";
echo "Products needing shipping/true-cost meta update: {$products_changed}\n";
echo "Products at free shipping: {$products_free_shipping}\n";
echo "Products at flat shipping: {$products_flat_shipping}\n";
echo "Products missing distributor cost fallback data: {$products_missing_cost}\n";
echo "Products needing true-cost update: {$products_true_cost_changed}\n\n";

echo "Orders scanned: {$orders_seen}\n";
echo "Orders with Zanders lines: {$orders_with_zanders}\n";
echo "Orders with missing Zanders line cost data: {$orders_missing_cost}\n";
echo "Zanders shipping rows seen: {$shipping_rows_seen}\n";
echo "Orders needing shipping-plan/profit-audit update: {$orders_changed}\n";
echo "Old Zanders distributor shipping total in touched rows: $" . number_format($old_zanders_shipping_total, 2) . "\n";
echo "New Zanders distributor shipping total in touched rows: $" . number_format($new_zanders_shipping_total, 2) . "\n";
echo "Delta: $" . number_format($new_zanders_shipping_total - $old_zanders_shipping_total, 2) . "\n\n";

if (!$commit) {
    echo "DRY RUN ONLY. Run with 'commit' to write selected product meta, shipping-plan rows, and recalculated profit audit meta.\n";
} else {
    echo "Committed selected product meta updates and recalculated affected order profit audit meta.\n";
}
