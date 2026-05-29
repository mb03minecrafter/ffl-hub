<?php

use FFLHub\Product\ProductMeta;
use FFLHub\Settings\Options;

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
if (($cli_args[0] ?? '') === '--') {
    array_shift($cli_args);
}

$mode = strtolower(trim((string)($cli_args[0] ?? 'dry-run')));
if (!in_array($mode, ['dry-run', 'commit'], true)) {
    fwrite(STDERR, "Usage: wp eval-file scripts/apply-map-brand-policies-to-products.php -- [dry-run|commit] [optional_limit]\n");
    exit(1);
}

$commit = ($mode === 'commit');
$limit = 0;
foreach (array_slice($cli_args, 1) as $arg) {
    $arg = trim((string)$arg);
    if ($arg !== '' && is_numeric($arg)) {
        $limit = max(0, (int)$arg);
    }
}

const FFLHUB_MAP_POLICY_FIX_BATCH_SIZE = 250;

function fflhub_map_policy_fix_positive_float($value): ?float
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
    return (is_finite($float) && $float > 0.0) ? $float : null;
}

function fflhub_map_policy_fix_decimal($value): string
{
    return function_exists('wc_format_decimal')
        ? (string)wc_format_decimal((float)$value, 2)
        : number_format((float)$value, 2, '.', '');
}

function fflhub_map_policy_fix_brand_names(int $product_id): array
{
    $names = [];
    $taxonomies = ['product_brand', 'pa_brand'];
    $all_taxonomies = get_object_taxonomies('product', 'names');
    if (is_array($all_taxonomies)) {
        foreach ($all_taxonomies as $taxonomy) {
            $taxonomy = (string)$taxonomy;
            if ($taxonomy !== '' && stripos($taxonomy, 'brand') !== false) {
                $taxonomies[] = $taxonomy;
            }
        }
    }

    foreach (array_values(array_unique($taxonomies)) as $taxonomy) {
        if (!taxonomy_exists($taxonomy)) {
            continue;
        }

        $terms = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'names']);
        if (is_wp_error($terms) || !is_array($terms)) {
            continue;
        }

        foreach ($terms as $term_name) {
            $name = trim((string)$term_name);
            if ($name !== '') {
                $names[$name] = $name;
            }
        }
    }

    return array_values($names);
}

function fflhub_map_policy_fix_brand_policy(array $brand_names): string
{
    $resolved = Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;

    foreach ($brand_names as $brand_name) {
        $policy = Options::get_map_policy_for_brand((string)$brand_name);
        if ($policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE) {
            return Options::MAP_POLICY_EMAIL_FOR_QUOTE;
        }
        if ($policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) {
            $resolved = Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART;
        }
    }

    return $resolved;
}

function fflhub_map_policy_fix_recommended_price(int $product_id): ?float
{
    $true_cost = fflhub_map_policy_fix_positive_float(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_TRUE_COST_META, true));
    $dealer = fflhub_map_policy_fix_positive_float(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, true));
    $base = $true_cost ?? $dealer;
    if ($base === null) {
        return null;
    }

    $markup = (float)Options::get_global_markup();
    if (!is_finite($markup) || $markup < 0.0) {
        $markup = 0.0;
    }
    $pct = ($markup > 1.0) ? ($markup / 100.0) : $markup;
    $sell = ceil($base * (1.0 + $pct)) - 0.01;

    return ($sell > 0.0) ? (float)$sell : null;
}

function fflhub_map_policy_fix_price_pair(float $sell_price, ?float $msrp): array
{
    $sell = fflhub_map_policy_fix_decimal($sell_price);
    if ($msrp !== null && $msrp > $sell_price) {
        return [
            'regular' => fflhub_map_policy_fix_decimal($msrp),
            'sale' => $sell,
            'price' => $sell,
        ];
    }

    return [
        'regular' => $sell,
        'sale' => '',
        'price' => $sell,
    ];
}

function fflhub_map_policy_fix_update_meta(int $product_id, string $key, $value, bool $commit, array &$changes): void
{
    $old = get_post_meta($product_id, $key, true);
    $old_norm = is_scalar($old) ? trim((string)$old) : '';
    $new_norm = is_scalar($value) ? trim((string)$value) : '';
    if ($old_norm === $new_norm) {
        return;
    }

    $changes[$key] = [$old_norm, $new_norm];
    if ($commit) {
        update_post_meta($product_id, $key, $new_norm);
    }
}

$policy_lookup = Options::get_map_brand_policy_lookup();
if (empty($policy_lookup)) {
    fwrite(STDERR, "No MAP brand policies are configured. Nothing to apply.\n");
    exit(0);
}

$backup_path = '/tmp/fflhub-map-policy-product-backup-' . gmdate('Ymd_His') . '.csv';
$backup = null;
if ($commit) {
    $backup = fopen($backup_path, 'wb');
    if (!$backup) {
        fwrite(STDERR, "Could not open backup file: {$backup_path}\n");
        exit(1);
    }
    fputcsv($backup, [
        'product_id',
        'title',
        'brand_names',
        'brand_policy',
        'old_policy',
        'old_mode',
        'old_map_real_mode',
        'old_free_shipping_override',
        'old_regular_price',
        'old_sale_price',
        'old_price',
        'last_map',
        'last_msrp',
        'action',
    ]);
}

$stats = [
    'scanned' => 0,
    'changed' => 0,
    'apply_email_for_quote' => 0,
    'apply_no_email_no_add_to_cart' => 0,
    'cleared_no_map' => 0,
    'cleared_stale_policy' => 0,
    'price_updates' => 0,
];

$paged = 1;
do {
    $query = new WP_Query([
        'post_type' => 'product',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'fields' => 'ids',
        'posts_per_page' => FFLHUB_MAP_POLICY_FIX_BATCH_SIZE,
        'paged' => $paged,
        'orderby' => 'ID',
        'order' => 'ASC',
        'no_found_rows' => false,
    ]);

    foreach ($query->posts as $product_id_raw) {
        $product_id = (int)$product_id_raw;
        if ($product_id <= 0) {
            continue;
        }

        $stats['scanned']++;
        if ($limit > 0 && $stats['scanned'] > $limit) {
            break 2;
        }

        $brand_names = fflhub_map_policy_fix_brand_names($product_id);
        $brand_policy = fflhub_map_policy_fix_brand_policy($brand_names);
        $old_policy = strtolower(trim((string)get_post_meta($product_id, ProductMeta::FFLHUB_MAP_POLICY_META, true)));
        $old_mode = (string)get_post_meta($product_id, ProductMeta::FFLHUB_MARKUP_MODE_META, true);
        $old_real_mode = (string)get_post_meta($product_id, ProductMeta::FFLHUB_MAP_REAL_PRICE_MODE_META, true);
        $old_free_shipping = (string)get_post_meta($product_id, ProductMeta::FFLHUB_MAP_REAL_PRICE_FREE_SHIPPING_OVERRIDE_META, true);
        $old_regular = (string)get_post_meta($product_id, '_regular_price', true);
        $old_sale = (string)get_post_meta($product_id, '_sale_price', true);
        $old_price = (string)get_post_meta($product_id, '_price', true);

        $map = fflhub_map_policy_fix_positive_float(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_MAP_META, true));
        $msrp = fflhub_map_policy_fix_positive_float(get_post_meta($product_id, ProductMeta::FFLHUB_LAST_MSRP_META, true));
        $action = '';
        $changes = [];

        if (
            $map !== null
            && ($brand_policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE || $brand_policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART)
        ) {
            $action = ($brand_policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE)
                ? 'apply_email_for_quote'
                : 'apply_no_email_no_add_to_cart';
            fflhub_map_policy_fix_update_meta($product_id, ProductMeta::FFLHUB_MAP_POLICY_META, $brand_policy, $commit, $changes);
            fflhub_map_policy_fix_update_meta($product_id, ProductMeta::FFLHUB_MARKUP_MODE_META, (string)ProductMeta::MARKUP_MODE_MAP_PRICE, $commit, $changes);
            fflhub_map_policy_fix_update_meta($product_id, ProductMeta::FFLHUB_MAP_REAL_PRICE_MODE_META, (string)ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED, $commit, $changes);
            fflhub_map_policy_fix_update_meta($product_id, ProductMeta::FFLHUB_MAP_REAL_PRICE_FREE_SHIPPING_OVERRIDE_META, '0', $commit, $changes);

            $prices = fflhub_map_policy_fix_price_pair($map, $msrp);
            fflhub_map_policy_fix_update_meta($product_id, '_regular_price', $prices['regular'], $commit, $changes);
            fflhub_map_policy_fix_update_meta($product_id, '_sale_price', $prices['sale'], $commit, $changes);
            fflhub_map_policy_fix_update_meta($product_id, '_price', $prices['price'], $commit, $changes);
        } else {
            $special_policy_is_stale = (
                $old_policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE
                || $old_policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART
            );
            $old_mode_is_map = ((int)$old_mode === ProductMeta::MARKUP_MODE_MAP_PRICE);

            if ($special_policy_is_stale || ($old_mode_is_map && $map === null)) {
                $action = ($map === null) ? 'cleared_no_map' : 'cleared_stale_policy';
                fflhub_map_policy_fix_update_meta($product_id, ProductMeta::FFLHUB_MAP_POLICY_META, Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE, $commit, $changes);

                if ($old_mode_is_map && $map === null) {
                    fflhub_map_policy_fix_update_meta($product_id, ProductMeta::FFLHUB_MARKUP_MODE_META, (string)ProductMeta::MARKUP_MODE_GLOBAL, $commit, $changes);
                    $recommended = fflhub_map_policy_fix_recommended_price($product_id);
                    if ($recommended !== null) {
                        $prices = fflhub_map_policy_fix_price_pair($recommended, $msrp);
                        fflhub_map_policy_fix_update_meta($product_id, '_regular_price', $prices['regular'], $commit, $changes);
                        fflhub_map_policy_fix_update_meta($product_id, '_sale_price', $prices['sale'], $commit, $changes);
                        fflhub_map_policy_fix_update_meta($product_id, '_price', $prices['price'], $commit, $changes);
                    }
                }
            }
        }

        if (empty($changes)) {
            continue;
        }

        $stats['changed']++;
        if (isset($stats[$action])) {
            $stats[$action]++;
        }
        if (isset($changes['_price']) || isset($changes['_regular_price']) || isset($changes['_sale_price'])) {
            $stats['price_updates']++;
        }

        if ($backup) {
            fputcsv($backup, [
                $product_id,
                get_the_title($product_id),
                implode('|', $brand_names),
                $brand_policy,
                $old_policy,
                $old_mode,
                $old_real_mode,
                $old_free_shipping,
                $old_regular,
                $old_sale,
                $old_price,
                $map !== null ? fflhub_map_policy_fix_decimal($map) : '',
                $msrp !== null ? fflhub_map_policy_fix_decimal($msrp) : '',
                $action,
            ]);
        }

        if ($commit) {
            clean_post_cache($product_id);
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }
        }

        if (!$commit && $stats['changed'] <= 25) {
            echo sprintf(
                "#%d %s policy=%s action=%s changes=%s\n",
                $product_id,
                get_the_title($product_id),
                $brand_policy,
                $action,
                implode(',', array_keys($changes))
            );
        }
    }

    $paged++;
} while ($query->max_num_pages >= $paged);

if ($backup) {
    fclose($backup);
}

$summary = sprintf(
    "Mode: %s\nScanned: %d\nChanged: %d\nEmail for Quote applied: %d\nNo Email/No Cart applied: %d\nCleared no-MAP products: %d\nCleared stale policy: %d\nPrice updates: %d\n",
    $commit ? 'commit' : 'dry-run',
    $stats['scanned'],
    $stats['changed'],
    $stats['apply_email_for_quote'],
    $stats['apply_no_email_no_add_to_cart'],
    $stats['cleared_no_map'],
    $stats['cleared_stale_policy'],
    $stats['price_updates']
);

if ($commit) {
    $summary .= "Backup: {$backup_path}\n";
}

echo $summary;
