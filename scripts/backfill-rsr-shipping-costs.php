<?php

use FFLHub\Order\OrderProfitAuditMeta;
use FFLHub\Product\ProductMeta;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must be run through wp eval-file.\n");
    exit(1);
}

$mode = strtolower(trim((string)($argv[1] ?? 'dry-run')));
if (!in_array($mode, ['dry-run', 'commit'], true)) {
    fwrite(STDERR, "Usage: wp eval-file backfill-rsr-shipping-costs.php -- [dry-run|commit] [order_batch_limit]\n");
    exit(1);
}

$commit = ($mode === 'commit');
$order_batch_limit = isset($argv[2]) && is_numeric($argv[2]) ? max(1, (int)$argv[2]) : 100;

const FFLHUB_RSR_SHIP = 10.00;

function fflhub_rsr_money4(float $value): string
{
    return wc_format_decimal(max(0.0, $value), 4);
}

function fflhub_rsr_non_negative_float($value): ?float
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

function fflhub_rsr_decode_array($value): array
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

function fflhub_rsr_product_source(WC_Product $product): string
{
    return strtolower(trim((string)$product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true)));
}

function fflhub_rsr_order_line_profile(WC_Order $order): array
{
    $profile = [
        'has_rsr' => false,
        'line_count' => 0,
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
            $dist = fflhub_rsr_product_source($product);
        }

        if ($dist !== 'rsr') {
            continue;
        }

        $profile['has_rsr'] = true;
        $profile['line_count']++;
    }

    return $profile;
}

function fflhub_rsr_row_lane_count(array $row): int
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

function fflhub_rsr_desired_row_cost(array $row): float
{
    $direct_home = !empty($row['direct_home']);
    $direct_ffl = !empty($row['direct_ffl']);
    $dealer_inbound = !empty($row['dealer_inbound']);
    $inbound_waived = !empty($row['dealer_batch_free_inbound_shipping_applied']);

    $cost = 0.0;

    if ($direct_home) {
        $cost += FFLHUB_RSR_SHIP;
    }

    if ($direct_ffl) {
        $cost += FFLHUB_RSR_SHIP;
    }

    if ($dealer_inbound && !$inbound_waived) {
        $cost += FFLHUB_RSR_SHIP;
    }

    if ($cost > 0.0 || $inbound_waived) {
        return $cost;
    }

    return FFLHUB_RSR_SHIP * (float)fflhub_rsr_row_lane_count($row);
}

function fflhub_rsr_route_fee_meta(array $row): array
{
    $fees = [
        'dealer_inbound_lane_fee' => !empty($row['dealer_inbound']) ? FFLHUB_RSR_SHIP : 0.0,
        'direct_home_lane_fee' => !empty($row['direct_home']) ? FFLHUB_RSR_SHIP : 0.0,
        'direct_ffl_lane_fee' => !empty($row['direct_ffl']) ? FFLHUB_RSR_SHIP : 0.0,
    ];

    if (fflhub_rsr_row_lane_count($row) > 0 && max($fees) <= 0.0) {
        $fees['dealer_inbound_lane_fee'] = FFLHUB_RSR_SHIP;
    }

    $fees['lane_fee'] = FFLHUB_RSR_SHIP;

    return $fees;
}

function fflhub_rsr_sum_by_dist_cost(array $by_dist): float
{
    $total = 0.0;
    foreach ($by_dist as $row) {
        if (is_object($row)) {
            $row = (array)$row;
        }
        if (!is_array($row)) {
            continue;
        }

        $total += fflhub_rsr_non_negative_float($row['cost'] ?? null) ?? 0.0;
    }

    return max(0.0, $total);
}

function fflhub_rsr_adjust_plan_total(array $plan, float $old_dist_total, float $new_dist_total): float
{
    $home = fflhub_rsr_non_negative_float($plan['dealer_outbound_home_cost'] ?? null);
    $ffl = fflhub_rsr_non_negative_float($plan['dealer_outbound_ffl_cost'] ?? null);
    if ($home !== null || $ffl !== null) {
        return $new_dist_total + (float)($home ?? 0.0) + (float)($ffl ?? 0.0);
    }

    $old_total = fflhub_rsr_non_negative_float($plan['total_cost'] ?? null);
    if ($old_total !== null) {
        return max(0.0, $old_total - $old_dist_total + $new_dist_total);
    }

    return $new_dist_total;
}

function fflhub_rsr_backfill_shipping_items(WC_Order $order, bool $commit): array
{
    $changed = false;
    $rows_seen = 0;
    $old_total = 0.0;
    $new_total = 0.0;

    foreach ($order->get_items('shipping') as $shipping_item) {
        if (!($shipping_item instanceof WC_Order_Item_Shipping)) {
            continue;
        }

        $plan = fflhub_rsr_decode_array($shipping_item->get_meta('fflhub_shipping_plan', true));
        $by_dist = isset($plan['by_dist']) && is_array($plan['by_dist'])
            ? $plan['by_dist']
            : fflhub_rsr_decode_array($shipping_item->get_meta('fflhub_shipping_by_dist', true));

        if (empty($by_dist)) {
            continue;
        }

        $item_changed = false;
        $item_old_dist_total = fflhub_rsr_sum_by_dist_cost($by_dist);

        foreach ($by_dist as $key => $row) {
            if (is_object($row)) {
                $row = (array)$row;
            }
            if (!is_array($row)) {
                continue;
            }

            $row_dist = strtolower(trim((string)($row['dist_id'] ?? $key)));
            if ($row_dist !== 'rsr') {
                continue;
            }

            $rows_seen++;
            $old_cost = fflhub_rsr_non_negative_float($row['cost'] ?? null) ?? 0.0;
            $desired = fflhub_rsr_desired_row_cost($row);
            $fee_meta = fflhub_rsr_route_fee_meta($row);
            $fee_changed = false;
            foreach ($fee_meta as $fee_key => $fee_value) {
                $current_fee = fflhub_rsr_non_negative_float($row[$fee_key] ?? null) ?? 0.0;
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

            $row['dist_id'] = 'rsr';
            $row['cost_before_rsr_shipping_backfill'] = fflhub_rsr_money4($old_cost);
            $row['cost'] = fflhub_rsr_money4($desired);
            foreach ($fee_meta as $fee_key => $fee_value) {
                $row[$fee_key] = fflhub_rsr_money4((float)$fee_value);
            }
            $row['rsr_shipping_backfill_applied'] = true;
            $row['rsr_shipping_backfill_rate'] = fflhub_rsr_money4(FFLHUB_RSR_SHIP);
            $row['rsr_shipping_backfill_updated_at_utc'] = gmdate('Y-m-d H:i:s');

            $by_dist[$key] = $row;
            $item_changed = true;
            $changed = true;
        }

        if (!$item_changed) {
            continue;
        }

        $new_dist_total = fflhub_rsr_sum_by_dist_cost($by_dist);
        $plan['by_dist'] = $by_dist;
        $plan['distributor_cost_total'] = fflhub_rsr_money4($new_dist_total);
        $plan['total_cost'] = fflhub_rsr_money4(
            fflhub_rsr_adjust_plan_total($plan, $item_old_dist_total, $new_dist_total)
        );
        $plan['rsr_shipping_backfill_applied'] = true;
        $plan['rsr_shipping_backfill_updated_at_utc'] = gmdate('Y-m-d H:i:s');

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
    'rsr'
));

$products_seen = 0;
$products_changed = 0;

foreach ($product_ids as $product_id) {
    $product = wc_get_product((int)$product_id);
    if (!($product instanceof WC_Product)) {
        continue;
    }

    $products_seen++;
    $current = fflhub_rsr_non_negative_float($product->get_meta(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, true));
    if ($current !== null && abs($current - FFLHUB_RSR_SHIP) <= 0.0001) {
        continue;
    }

    $products_changed++;
    if ($commit) {
        update_post_meta((int)$product_id, ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, fflhub_rsr_money4(FFLHUB_RSR_SHIP));
    }
}

$orders_seen = 0;
$orders_with_rsr = 0;
$orders_changed = 0;
$shipping_rows_seen = 0;
$old_rsr_shipping_total = 0.0;
$new_rsr_shipping_total = 0.0;

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
        $profile = fflhub_rsr_order_line_profile($order);
        if (empty($profile['has_rsr'])) {
            continue;
        }

        $orders_with_rsr++;
        $result = fflhub_rsr_backfill_shipping_items($order, $commit);
        $shipping_rows_seen += (int)$result['rows_seen'];
        $old_rsr_shipping_total += (float)$result['old_total'];
        $new_rsr_shipping_total += (float)$result['new_total'];

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

echo "==== RSR Shipping Backfill ====\n";
echo "Mode: {$mode}\n";
echo "Product/order lane rate: $" . number_format(FFLHUB_RSR_SHIP, 2) . "\n\n";

echo "Products scanned: {$products_seen}\n";
echo "Products needing shipping meta update: {$products_changed}\n\n";

echo "Orders scanned: {$orders_seen}\n";
echo "Orders with RSR lines: {$orders_with_rsr}\n";
echo "RSR shipping rows seen: {$shipping_rows_seen}\n";
echo "Orders needing shipping-plan/profit-audit update: {$orders_changed}\n";
echo "Old RSR distributor shipping total in touched rows: $" . number_format($old_rsr_shipping_total, 2) . "\n";
echo "New RSR distributor shipping total in touched rows: $" . number_format($new_rsr_shipping_total, 2) . "\n";
echo "Delta: $" . number_format($new_rsr_shipping_total - $old_rsr_shipping_total, 2) . "\n\n";

if (!$commit) {
    echo "DRY RUN ONLY. Run with 'commit' to write product meta, shipping-plan rows, and recalculated profit audit meta.\n";
} else {
    echo "Committed product shipping meta updates and recalculated affected order profit audit meta.\n";
}
