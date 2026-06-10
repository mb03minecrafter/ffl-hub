<?php

use FFLHub\Distributor\Product\DistributorProductHelper;
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
    fwrite(STDERR, "Usage: wp eval-file scripts/holosun-map-fixed-profit-5pct-cap5.php -- [dry-run|commit] [optional_limit]\n");
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

const FFLHUB_HOLOSUN_TARGET_MARGIN = 0.05;
const FFLHUB_HOLOSUN_MAX_FIXED_PROFIT = 5.00;
const FFLHUB_HOLOSUN_BATCH_SIZE = 250;

function fflhub_holosun_float($value): ?float
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
    return is_finite($float) ? $float : null;
}

function fflhub_holosun_positive_float($value): ?float
{
    $float = fflhub_holosun_float($value);
    return ($float !== null && $float > 0.0) ? $float : null;
}

function fflhub_holosun_non_negative_float($value): ?float
{
    $float = fflhub_holosun_float($value);
    return ($float !== null && $float >= 0.0) ? $float : null;
}

function fflhub_holosun_brand_key(string $brand): string
{
    $brand = html_entity_decode($brand, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $brand = wp_strip_all_tags($brand);
    return (string)preg_replace('/[^a-z0-9]+/', '', strtolower($brand));
}

function fflhub_holosun_is_holosun_brand(string $brand): bool
{
    return in_array(fflhub_holosun_brand_key($brand), [
        'holosun',
        'holosuntechnologies',
        'holosontechnologies',
    ], true);
}

function fflhub_holosun_brand_taxonomies(): array
{
    $taxonomies = [];
    foreach (['product_brand', 'pa_brand'] as $taxonomy) {
        if (taxonomy_exists($taxonomy)) {
            $taxonomies[$taxonomy] = $taxonomy;
        }
    }

    $all = get_object_taxonomies('product', 'names');
    if (is_array($all)) {
        foreach ($all as $taxonomy) {
            $taxonomy = (string)$taxonomy;
            if ($taxonomy !== '' && stripos($taxonomy, 'brand') !== false && taxonomy_exists($taxonomy)) {
                $taxonomies[$taxonomy] = $taxonomy;
            }
        }
    }

    return array_values($taxonomies);
}

function fflhub_holosun_tax_query(): array
{
    $clauses = [];
    foreach (fflhub_holosun_brand_taxonomies() as $taxonomy) {
        $term_ids = [];
        foreach (['Holosun', 'Holosun Technologies', 'Holoson Technologies'] as $name) {
            $term = get_term_by('name', $name, $taxonomy);
            if (!$term) {
                $term = get_term_by('slug', sanitize_title($name), $taxonomy);
            }
            if ($term && !is_wp_error($term)) {
                $term_ids[] = (int)$term->term_id;
            }
        }

        $term_ids = array_values(array_unique(array_filter($term_ids)));
        if ($term_ids) {
            $clauses[] = [
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => $term_ids,
                'include_children' => false,
            ];
        }
    }

    if (!$clauses) {
        return [];
    }

    if (count($clauses) === 1) {
        return $clauses;
    }

    return array_merge(['relation' => 'OR'], $clauses);
}

function fflhub_holosun_product_has_brand(int $product_id): bool
{
    foreach (fflhub_holosun_brand_taxonomies() as $taxonomy) {
        $terms = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'names']);
        if (is_wp_error($terms) || !is_array($terms)) {
            continue;
        }

        foreach ($terms as $term_name) {
            if (fflhub_holosun_is_holosun_brand((string)$term_name)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @return array<string,float|int|string|null>
 */
function fflhub_holosun_price_plan(float $cost, float $shipping, float $fee_fraction, float $margin_fraction, float $max_fixed_profit): array
{
    $denominator = 1.0 - $fee_fraction - $margin_fraction;
    if ($denominator <= 0.0) {
        return ['error' => 'invalid_denominator'];
    }

    $exact_price = ($cost + $shipping) / $denominator;
    $floored_price = floor($exact_price);
    if ($floored_price <= 0.0) {
        return ['error' => 'invalid_floored_price'];
    }

    $exact_profit = ($floored_price * (1.0 - $fee_fraction)) - $cost - $shipping;
    if ($exact_profit < 0.0) {
        return ['error' => 'negative_profit_after_floor'];
    }

    $best = null;
    $best_score = PHP_FLOAT_MAX;

    for ($i = -250; $i <= 250; $i++) {
        $profit = round($exact_profit + ($i * 0.0001), 4);
        if ($profit < 0.0) {
            continue;
        }

        $computed_price = round(($cost + $shipping + $profit) / (1.0 - $fee_fraction), 2);
        $price_diff = abs($computed_price - $floored_price);
        $over_penalty = ($computed_price > $floored_price) ? 1000.0 : 0.0;
        $score = $over_penalty + $price_diff + (abs($profit - $exact_profit) / 100.0);

        if ($score < $best_score) {
            $best_score = $score;
            $best = [
                'fixed_profit' => $profit,
                'computed_price' => $computed_price,
            ];
        }

    }

    if (!$best) {
        return ['error' => 'unable_to_find_profit'];
    }

    $uncapped_fixed_profit = (float)$best['fixed_profit'];
    $fixed_profit = min($uncapped_fixed_profit, max(0.0, $max_fixed_profit));
    $computed_price = round(($cost + $shipping + $fixed_profit) / (1.0 - $fee_fraction), 2);
    $actual_profit = ($computed_price * (1.0 - $fee_fraction)) - $cost - $shipping;
    $actual_margin = ($computed_price > 0.0) ? ($actual_profit / $computed_price) : 0.0;

    return [
        'exact_price' => $exact_price,
        'floored_price' => $floored_price,
        'uncapped_fixed_profit' => $uncapped_fixed_profit,
        'profit_cap' => $max_fixed_profit,
        'profit_was_capped' => ($fixed_profit + 0.0001 < $uncapped_fixed_profit) ? 1 : 0,
        'fixed_profit' => $fixed_profit,
        'computed_price' => $computed_price,
        'actual_profit' => $actual_profit,
        'actual_margin' => $actual_margin,
    ];
}

/**
 * @return array<string,float|int|string|null>
 */
function fflhub_holosun_map_price_fallback_plan(float $map, float $cost, float $shipping, float $fee_fraction): array
{
    $computed_price = round($map, 2);
    $actual_profit = ($computed_price * (1.0 - $fee_fraction)) - $cost - $shipping;
    $actual_margin = ($computed_price > 0.0) ? ($actual_profit / $computed_price) : 0.0;

    return [
        'exact_price' => $map,
        'floored_price' => $computed_price,
        'fixed_profit' => null,
        'computed_price' => $computed_price,
        'actual_profit' => $actual_profit,
        'actual_margin' => $actual_margin,
        'fallback_mode' => 'map_price',
    ];
}

function fflhub_holosun_plan_loses_money(array $plan): bool
{
    return isset($plan['actual_profit']) && (float)$plan['actual_profit'] < 0.0;
}

$fee_percent = (float)Options::get_payment_processor_fee_percent();
if (!is_finite($fee_percent) || $fee_percent < 0.0) {
    $fee_percent = 0.0;
}
$fee_fraction = min(0.99, $fee_percent / 100.0);

$tax_query = fflhub_holosun_tax_query();
$report_path = '/tmp/fflhub_holosun_map_fixed_profit_5pct_cap5_' . gmdate('Y-m-d_His') . '.csv';
$report = fopen($report_path, 'wb');
if (!$report) {
    fwrite(STDERR, "Unable to write report: {$report_path}\n");
    exit(1);
}

fputcsv($report, [
    'product_id',
    'status',
    'sku',
    'upc',
    'name',
    'cost',
    'shipping',
    'map',
    'exact_5pct_price',
    'floored_quote_price',
    'uncapped_5pct_fixed_profit',
    'fixed_profit_cap',
    'profit_was_capped',
    'fixed_profit_saved',
    'computed_quote_price',
    'actual_profit_after_fee',
    'actual_margin_percent',
    'coupon_discount_from_map',
    'before_markup_mode',
    'before_map_real_mode',
    'before_fixed_profit',
    'result',
    'note',
]);

$stats = [
    'scanned' => 0,
    'holosun_with_map' => 0,
    'planned' => 0,
    'changed' => 0,
    'skipped' => 0,
];

$page = 1;
$processed = 0;

do {
    $query_args = [
        'post_type' => 'product',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'fields' => 'ids',
        'posts_per_page' => FFLHUB_HOLOSUN_BATCH_SIZE,
        'paged' => $page,
        'orderby' => 'ID',
        'order' => 'ASC',
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => ProductMeta::FFLHUB_LAST_MAP_META,
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            ],
            [
                'key' => ProductMeta::FFLHUB_MAP_POLICY_META,
                'value' => Options::MAP_POLICY_EMAIL_FOR_QUOTE,
                'compare' => '=',
            ],
        ],
    ];

    if ($tax_query) {
        $query_args['tax_query'] = $tax_query;
    }

    $query = new WP_Query($query_args);
    $ids = array_map('absint', (array)$query->posts);

    foreach ($ids as $product_id) {
        if ($limit > 0 && $processed >= $limit) {
            break 2;
        }
        $processed++;
        $stats['scanned']++;

        if (!$tax_query && !fflhub_holosun_product_has_brand($product_id)) {
            continue;
        }

        $product = wc_get_product($product_id);
        if (!($product instanceof WC_Product)) {
            $stats['skipped']++;
            continue;
        }

        $map = fflhub_holosun_positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_MAP_META, true));
        if ($map === null) {
            continue;
        }

        $stats['holosun_with_map']++;

        $cost = fflhub_holosun_positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_TRUE_COST_META, true));
        if ($cost === null) {
            $cost = fflhub_holosun_positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, true));
        }

        $shipping = fflhub_holosun_non_negative_float($product->get_meta(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, true)) ?? 0.0;
        $before_mode = (string)$product->get_meta(ProductMeta::FFLHUB_MARKUP_MODE_META, true);
        $before_real_mode = (string)$product->get_meta(ProductMeta::FFLHUB_MAP_REAL_PRICE_MODE_META, true);
        $before_profit = (string)$product->get_meta(ProductMeta::FFLHUB_MAP_REAL_PRICE_FIXED_PROFIT_META, true);
        $result = 'skipped';
        $note = '';
        $plan = null;

        if ($cost === null) {
            $stats['skipped']++;
            $note = 'missing true/dealer cost';
        } else {
            $plan = fflhub_holosun_price_plan($cost, $shipping, $fee_fraction, FFLHUB_HOLOSUN_TARGET_MARGIN, FFLHUB_HOLOSUN_MAX_FIXED_PROFIT);
            if (isset($plan['error'])) {
                $error = (string)$plan['error'];
                $plan = fflhub_holosun_map_price_fallback_plan($map, $cost, $shipping, $fee_fraction);
                if (fflhub_holosun_plan_loses_money($plan)) {
                    $stats['skipped']++;
                    $note = 'skip_map_below_cost_after_' . $error;
                } else {
                    $stats['planned']++;
                    $result = $commit ? 'committed' : 'dry-run';
                    $note = 'fallback_to_map_after_' . $error;
                }
            } elseif ((float)$plan['computed_price'] >= $map) {
                $plan = fflhub_holosun_map_price_fallback_plan($map, $cost, $shipping, $fee_fraction);
                if (fflhub_holosun_plan_loses_money($plan)) {
                    $stats['skipped']++;
                    $note = 'skip_map_below_cost_no_coupon';
                } else {
                    $stats['planned']++;
                    $result = $commit ? 'committed' : 'dry-run';
                    $note = 'fallback_to_map_no_coupon';
                }
            } else {
                $stats['planned']++;
                $result = $commit ? 'committed' : 'dry-run';
                $note = !empty($plan['profit_was_capped']) ? 'ok_capped_profit_to_5' : 'ok';
            }

            if ($commit && is_array($plan) && !isset($plan['error'])) {
                $product->update_meta_data(ProductMeta::FFLHUB_MARKUP_MODE_META, ProductMeta::MARKUP_MODE_MAP_PRICE);

                if (($plan['fallback_mode'] ?? '') === 'map_price') {
                    $product->update_meta_data(ProductMeta::FFLHUB_MAP_REAL_PRICE_MODE_META, ProductMeta::MAP_REAL_PRICE_MODE_PERCENTAGE);
                    $product->update_meta_data(ProductMeta::FFLHUB_MAP_REAL_PRICE_OFFSET_META, 0);
                    $product->update_meta_data(ProductMeta::FFLHUB_MAP_REAL_PRICE_PERCENT_META, 0);
                    $product->update_meta_data(ProductMeta::FFLHUB_MAP_REAL_PRICE_FIXED_PROFIT_META, 0);
                } else {
                    $product->update_meta_data(ProductMeta::FFLHUB_MAP_REAL_PRICE_MODE_META, ProductMeta::MAP_REAL_PRICE_MODE_FIXED_PROFIT);
                    $product->update_meta_data(ProductMeta::FFLHUB_MAP_REAL_PRICE_OFFSET_META, 0);
                    $product->update_meta_data(ProductMeta::FFLHUB_MAP_REAL_PRICE_PERCENT_META, 0);
                    $product->update_meta_data(
                        ProductMeta::FFLHUB_MAP_REAL_PRICE_FIXED_PROFIT_META,
                        (float)wc_format_decimal((float)$plan['fixed_profit'], 4)
                    );
                }

                $product->save();
                DistributorProductHelper::apply_admin_pricing_to_woo_product($product_id);
                $stats['changed']++;
            }
        }

        $computed = is_array($plan) && !isset($plan['error']) ? (float)$plan['computed_price'] : 0.0;
        $coupon_discount = ($computed > 0.0) ? max(0.0, $map - $computed) : 0.0;

        fputcsv($report, [
            $product_id,
            get_post_status($product_id),
            $product->get_sku(),
            (string)$product->get_meta(ProductMeta::FFLHUB_UPC_META, true),
            $product->get_name(),
            $cost !== null ? wc_format_decimal($cost, 4) : '',
            wc_format_decimal($shipping, 4),
            wc_format_decimal($map, 2),
            is_array($plan) && isset($plan['exact_price']) ? wc_format_decimal((float)$plan['exact_price'], 4) : '',
            is_array($plan) && isset($plan['floored_price']) ? wc_format_decimal((float)$plan['floored_price'], 2) : '',
            is_array($plan) && isset($plan['uncapped_fixed_profit']) ? wc_format_decimal((float)$plan['uncapped_fixed_profit'], 4) : '',
            is_array($plan) && isset($plan['profit_cap']) ? wc_format_decimal((float)$plan['profit_cap'], 4) : '',
            is_array($plan) && isset($plan['profit_was_capped']) ? (int)$plan['profit_was_capped'] : '',
            is_array($plan) && isset($plan['fixed_profit']) ? wc_format_decimal((float)$plan['fixed_profit'], 4) : '',
            $computed > 0.0 ? wc_format_decimal($computed, 2) : '',
            is_array($plan) && isset($plan['actual_profit']) ? wc_format_decimal((float)$plan['actual_profit'], 4) : '',
            is_array($plan) && isset($plan['actual_margin']) ? wc_format_decimal(((float)$plan['actual_margin']) * 100.0, 4) : '',
            $coupon_discount > 0.0 ? wc_format_decimal($coupon_discount, 2) : '',
            $before_mode,
            $before_real_mode,
            $before_profit,
            $result,
            $note,
        ]);
    }

    $page++;
} while ($query->max_num_pages >= $page);

fclose($report);

echo "==== Holosun MAP Fixed Profit 5% Script, Capped at $5 ====\n";
echo "Mode: {$mode}\n";
echo "Payment processor fee: " . wc_format_decimal($fee_percent, 4) . "%\n";
echo "Target net margin: 5.0000%\n";
echo "Max fixed profit saved: $" . wc_format_decimal(FFLHUB_HOLOSUN_MAX_FIXED_PROFIT, 2) . "\n";
echo "Tax query used: " . ($tax_query ? 'yes' : 'no, filtered by brand terms in PHP') . "\n";
echo "Scanned MAP products: {$stats['scanned']}\n";
echo "Holosun products with MAP: {$stats['holosun_with_map']}\n";
echo "Planned updates: {$stats['planned']}\n";
echo "Committed updates: {$stats['changed']}\n";
echo "Skipped: {$stats['skipped']}\n";
echo "Report: {$report_path}\n";

if (!$commit) {
    echo "\nDRY RUN ONLY. If this report looks right, run the same command with commit.\n";
}
