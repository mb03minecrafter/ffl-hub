<?php

namespace FFLHub\Admin\Pages;

use FFLHub\Feeds\GunDeals\GunDealsAnalyticsStore;
use FFLHub\Product\ProductMeta;
use FFLHub\Settings\Options;
use WC_Order;
use WC_Order_Item_Product;

if (!defined('ABSPATH')) {
    exit;
}

final class GunDealsPerformancePage
{
    private const PAGE_SLUG = 'fflhub-gundeals-performance';
    private const PERIOD_ANCHOR = '2026-05-28';
    private const BASE_FEE = 500.0;
    private const INCLUDED_CLICKS = 2000;
    private const OVERAGE_CPC = 0.25;
    private const LEGACY_CLICK_FALLBACK_END_DAY = '2026-06-02';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            AdminPage::get_page_slug(),
            __('Gun.deals Performance', 'ffl-hub'),
            __('Gun.deals Performance', 'ffl-hub'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        GunDealsAnalyticsStore::ensure_schema();

        $filters = $this->read_filters();
        $is_export = !empty($_GET['fflhub_gundeals_export']);
        $report = $this->build_report($filters, !$is_export);

        if ($is_export) {
            $this->maybe_export_csv($report, $filters);
            return;
        }

        $this->render_styles();
        ?>
        <div class="wrap fflhub-gundeals-performance">
            <h1><?php esc_html_e('Gun.deals Performance', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Strict Gun.deals attribution only. Profit comes from the frozen FFLHub order audit snapshot, not current distributor costs.', 'ffl-hub'); ?>
            </p>

            <?php $this->render_filter_form($filters, $report); ?>
            <?php $this->render_cards($report); ?>
            <?php $this->render_table($report, $filters); ?>
        </div>
        <?php
    }

    /**
     * @return array<string,mixed>
     */
    private function read_filters(): array
    {
        $defaults = $this->default_period_dates();
        $filters = [
            'start_date' => $this->clean_date((string) ($_GET['start_date'] ?? $defaults['start_date']), $defaults['start_date']),
            'end_date' => $this->clean_date((string) ($_GET['end_date'] ?? $defaults['end_date']), $defaults['end_date']),
            'view' => sanitize_key((string) ($_GET['view'] ?? 'all')),
            'feed' => sanitize_key((string) ($_GET['feed'] ?? '')),
            'stock' => sanitize_key((string) ($_GET['stock'] ?? '')),
            'brand' => sanitize_text_field((string) ($_GET['brand'] ?? '')),
            'category' => sanitize_text_field((string) ($_GET['category'] ?? '')),
            'ffl' => sanitize_key((string) ($_GET['ffl'] ?? '')),
            'map' => sanitize_key((string) ($_GET['map'] ?? '')),
            'min_clicks' => max(0, (int) ($_GET['min_clicks'] ?? 0)),
            'max_clicks' => $this->nullable_int($_GET['max_clicks'] ?? null),
            'min_orders' => max(0, (int) ($_GET['min_orders'] ?? 0)),
            'max_orders' => $this->nullable_int($_GET['max_orders'] ?? null),
            'min_conversion' => $this->nullable_float($_GET['min_conversion'] ?? null),
            'max_conversion' => $this->nullable_float($_GET['max_conversion'] ?? null),
            'min_profit' => $this->nullable_float($_GET['min_profit'] ?? null),
            'max_profit' => $this->nullable_float($_GET['max_profit'] ?? null),
            'missing_profit' => sanitize_key((string) ($_GET['missing_profit'] ?? '')),
            'advanced' => !empty($_GET['advanced']) ? 1 : 0,
            'sort' => sanitize_key((string) ($_GET['sort'] ?? 'attention')),
            'order' => strtolower(sanitize_key((string) ($_GET['order'] ?? ''))),
            'paged' => max(1, (int) ($_GET['paged'] ?? 1)),
            'per_page' => $this->clamp_per_page((int) ($_GET['per_page'] ?? 50)),
        ];

        if (!isset($this->sortable_columns()[$filters['sort']])) {
            $filters['sort'] = 'attention';
        }

        if (!in_array($filters['order'], ['asc', 'desc'], true)) {
            $filters['order'] = $this->default_sort_order((string) $filters['sort']);
        }

        if (strcmp((string) $filters['end_date'], (string) $filters['start_date']) < 0) {
            $filters['end_date'] = $filters['start_date'];
        }

        return $filters;
    }

    /**
     * @param mixed $value
     */
    private function nullable_int($value): ?int
    {
        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    private function clamp_per_page(int $per_page): int
    {
        $allowed = [25, 50, 100, 250];
        return in_array($per_page, $allowed, true) ? $per_page : 50;
    }

    /**
     * @return array{start_date:string,end_date:string}
     */
    private function default_period_dates(): array
    {
        $today = gmdate('Y-m-d');
        $day = (int) gmdate('d');
        $start_ts = $day >= 28
            ? strtotime(gmdate('Y-m-28 00:00:00'))
            : strtotime(gmdate('Y-m-28 00:00:00', strtotime('-1 month')));

        $anchor_ts = strtotime(self::PERIOD_ANCHOR . ' 00:00:00');
        if (!$start_ts || ($anchor_ts && $start_ts < $anchor_ts)) {
            $start_ts = $anchor_ts ?: time();
        }

        return [
            'start_date' => gmdate('Y-m-d', $start_ts),
            'end_date' => $today,
        ];
    }

    private function clean_date(string $value, string $default): string
    {
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $default;
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private function nullable_float($value): ?float
    {
        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function build_report(array $filters, bool $paginate = true): array
    {
        $start_day = (string) $filters['start_date'];
        $end_day = (string) $filters['end_date'];
        $start_gmt = $start_day . ' 00:00:00';
        $end_gmt = $end_day . ' 23:59:59';

        $period_clicks = $this->query_click_rollups($start_day, $end_day);
        $cumulative_clicks = $this->query_cumulative_click_totals($this->use_legacy_click_fallback($start_day, $end_day));
        $clicks = $this->merge_click_sources($period_clicks, $cumulative_clicks, $this->use_legacy_click_fallback($start_day, $end_day));
        $feed_rows = $this->query_latest_feed_rows();
        $orders = $this->query_attributed_order_metrics($start_gmt, $end_gmt);

        $product_ids = [];
        $upcs = [];
        foreach ([$clicks, $orders['items']] as $collection) {
            foreach ($collection as $row) {
                $pid = (int) ($row['product_id'] ?? 0);
                $upc = GunDealsAnalyticsStore::normalize_upc((string) ($row['upc'] ?? ''));
                if ($pid > 0) {
                    $product_ids[$pid] = $pid;
                }
                if ($upc !== '') {
                    $upcs[$upc] = $upc;
                }
            }
        }

        $products = $this->query_products(array_values($product_ids), array_values($upcs));
        $term_maps = $this->query_product_terms(array_keys($products['by_id']));

        // Do not hydrate/render the entire feed catalog. This report is about performance,
        // so rows are products with period clicks and/or strictly attributed orders.
        $keys = array_unique(array_merge(array_keys($clicks), array_keys($orders['items'])));
        sort($keys, SORT_NATURAL);

        $period_total_clicks = 0;
        foreach ($clicks as $click_row) {
            $period_total_clicks += (int) ($click_row['clicks'] ?? 0);
        }
        $period_cost = $this->gun_deals_cost($period_total_clicks);

        $rows = [];
        foreach ($keys as $key) {
            $click_row = $clicks[$key] ?? [];
            $feed_row = $feed_rows[$key] ?? [];
            $order_row = $orders['items'][$key] ?? [];

            $product_id = (int) ($click_row['product_id'] ?? ($feed_row['product_id'] ?? ($order_row['product_id'] ?? 0)));
            $upc = GunDealsAnalyticsStore::normalize_upc((string) ($click_row['upc'] ?? ($feed_row['upc'] ?? ($order_row['upc'] ?? ''))));

            $product = $product_id > 0 && isset($products['by_id'][$product_id])
                ? $products['by_id'][$product_id]
                : ($upc !== '' && isset($products['by_upc'][$upc]) ? $products['by_upc'][$upc] : []);

            if ($product_id <= 0) {
                $product_id = (int) ($product['product_id'] ?? 0);
            }
            if ($upc === '') {
                $upc = GunDealsAnalyticsStore::normalize_upc((string) ($product['upc'] ?? ''));
            }

            $click_count = (int) ($click_row['clicks'] ?? 0);
            $orders_count = (int) ($order_row['orders'] ?? 0);
            $units = (int) ($order_row['units'] ?? 0);
            $revenue = (float) ($order_row['revenue'] ?? 0.0);
            $gross_profit = (float) ($order_row['gross_profit'] ?? 0.0);
            $allocated_cost = $period_total_clicks > 0 ? $period_cost * ($click_count / $period_total_clicks) : 0.0;
            $conversion = $click_count > 0 ? ($orders_count / $click_count) * 100.0 : 0.0;
            $margin = $revenue > 0.0 ? ($gross_profit / $revenue) * 100.0 : 0.0;

            $brand = $term_maps['brands'][$product_id] ?? '';
            $category = $term_maps['categories'][$product_id] ?? '';
            $stock_status = (string) ($product['stock_status'] ?? ($feed_row['stock_status'] ?? ''));
            $map_policy = $this->normalize_map_policy((string) ($product['map_policy'] ?? ''));
            $map_status = $this->map_status_label($map_policy);

            $row = [
                'key' => $key,
                'product_id' => $product_id,
                'upc' => $upc,
                'title' => (string) ($product['title'] ?? ($feed_row['title'] ?? 'Unknown product')),
                'edit_url' => $product_id > 0 ? get_edit_post_link($product_id, '') : '',
                'feed_included' => isset($feed_rows[$key]),
                'stock_status' => $stock_status !== '' ? $stock_status : 'unknown',
                'stock_quantity' => (string) ($product['stock_quantity'] ?? ''),
                'clicks' => $click_count,
                'period_clicks' => (int) ($click_row['period_clicks'] ?? $click_count),
                'raw_cumulative_clicks' => (int) ($click_row['raw_cumulative_clicks'] ?? ($product['raw_cumulative_clicks'] ?? 0)),
                'click_source' => (string) ($click_row['click_source'] ?? 'period'),
                'deduped_clicks' => (int) ($click_row['deduped_clicks'] ?? 0),
                'orders' => $orders_count,
                'units' => $units,
                'conversion_rate' => $conversion,
                'revenue' => $revenue,
                'dealer_cost' => (float) ($order_row['dealer_cost'] ?? 0.0),
                'true_cost' => (float) ($order_row['true_cost'] ?? 0.0),
                'gross_profit' => $gross_profit,
                'allocated_cost' => $allocated_cost,
                'marginal_click_cost' => $click_count * self::OVERAGE_CPC,
                'net_profit' => $gross_profit - $allocated_cost,
                'margin' => $margin,
                'fees_adjustments' => (float) ($order_row['fees_adjustments'] ?? 0.0),
                'ffl_required' => $this->boolish($product['ffl_required'] ?? ''),
                'sot_required' => $this->boolish($product['sot_required'] ?? ''),
                'map_policy' => $map_policy,
                'map_status' => $map_status,
                'customer_price' => $this->money_float($product['price'] ?? 0),
                'brand' => $brand,
                'category' => $category,
                'last_order_date' => (string) ($order_row['last_order_date'] ?? ''),
                'missing_profit_audit' => !empty($order_row['missing_profit_audit']),
                'source_attribution' => implode('; ', array_slice(array_unique($order_row['attribution'] ?? []), 0, 3)),
                'page_views' => '',
                'add_to_cart_count' => '',
                'email_quote_count' => '',
                'cost_per_order' => $orders_count > 0 ? $allocated_cost / $orders_count : 0.0,
                'cost_per_revenue_dollar' => $revenue > 0.0 ? $allocated_cost / $revenue : 0.0,
                'click_cost_profit_percent' => $gross_profit > 0.0 ? ($allocated_cost / $gross_profit) * 100.0 : 0.0,
                'recent_stock_window_risk' => $click_count > 0 && strtolower($stock_status) !== 'instock' && !isset($feed_rows[$key]),
                'created_at' => (string) ($product['created_at'] ?? ''),
            ];

            $rows[$key] = $row;
        }

        $medians = $this->conversion_medians($rows);
        foreach ($rows as $key => $row) {
            $rows[$key]['badges'] = $this->recommendation_badges($row, $medians);
        }

        $rows = $this->apply_saved_view($rows, (string) $filters['view']);
        $rows = $this->apply_filters($rows, $filters);
        $rows = $this->sort_rows($rows, (string) $filters['sort'], (string) $filters['order']);

        $summary = $this->summary_from_rows($rows);
        $summary['period_cost'] = $this->gun_deals_cost((int) $summary['clicks']);
        $summary['included_clicks_remaining'] = max(0, self::INCLUDED_CLICKS - (int) $summary['clicks']);
        $summary['overage_clicks'] = max(0, (int) $summary['clicks'] - self::INCLUDED_CLICKS);
        $summary['overage_cost'] = $summary['overage_clicks'] * self::OVERAGE_CPC;
        $summary['effective_cpc'] = (int) $summary['clicks'] > 0 ? $summary['period_cost'] / (int) $summary['clicks'] : 0.0;
        $summary['net_after_period_cost'] = (float) $summary['gross_profit'] - (float) $summary['period_cost'];
        $summary['break_even_gap'] = max(0.0, (float) $summary['period_cost'] - (float) $summary['gross_profit']);
        $summary['feed_upc_count'] = count($feed_rows);
        $summary['attributed_orders'] = (int) $orders['order_count'];
        $summary['clicked_out_of_stock'] = $this->count_clicked_out_of_stock($rows);

        $pagination = $this->pagination_for_rows($rows, (int) $filters['paged'], (int) $filters['per_page']);
        $visible_rows = $paginate ? $this->paginate_rows($rows, $pagination) : $rows;

        return [
            'filters' => $filters,
            'rows' => array_values($visible_rows),
            'pagination' => $pagination,
            'summary' => $summary,
            'brands' => $this->unique_column($rows, 'brand'),
            'categories' => $this->unique_column($rows, 'category'),
            'latest_feed_snapshot' => GunDealsAnalyticsStore::latest_snapshot_time(),
            'period_start_gmt' => $start_gmt,
            'period_end_gmt' => $end_gmt,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function query_click_rollups(string $start_day, string $end_day): array
    {
        global $wpdb;
        if (!$wpdb) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT upc, product_id, SUM(raw_clicks) AS clicks, SUM(deduped_clicks) AS deduped_clicks
                 FROM ' . GunDealsAnalyticsStore::click_rollups_table() . '
                 WHERE day BETWEEN %s AND %s
                 GROUP BY upc, product_id',
                $start_day,
                $end_day
            ),
            ARRAY_A
        );

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $key = $this->row_key((string) ($row['upc'] ?? ''), (int) ($row['product_id'] ?? 0));
            if ($key === '') {
                continue;
            }

            if (!isset($out[$key])) {
                $out[$key] = [
                    'upc' => GunDealsAnalyticsStore::normalize_upc((string) ($row['upc'] ?? '')),
                    'product_id' => (int) ($row['product_id'] ?? 0),
                    'clicks' => 0,
                    'period_clicks' => 0,
                    'raw_cumulative_clicks' => 0,
                    'deduped_clicks' => 0,
                    'click_source' => 'period',
                ];
            }

            $out[$key]['clicks'] += (int) ($row['clicks'] ?? 0);
            $out[$key]['period_clicks'] += (int) ($row['clicks'] ?? 0);
            $out[$key]['deduped_clicks'] += (int) ($row['deduped_clicks'] ?? 0);
        }

        return $out;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function query_cumulative_click_totals(bool $enabled): array
    {
        if (!$enabled) {
            return [];
        }

        global $wpdb;
        if (!$wpdb) {
            return [];
        }

        GunDealsAnalyticsStore::ensure_schema();
        $rows = $wpdb->get_results("
            SELECT
                product_id,
                upc,
                raw_clicks AS raw_cumulative_clicks,
                deduped_clicks
            FROM " . GunDealsAnalyticsStore::click_totals_table() . "
            WHERE raw_clicks > 0
            ORDER BY raw_clicks DESC
        ", ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $key = $this->row_key((string) ($row['upc'] ?? ''), (int) ($row['product_id'] ?? 0));
            if ($key === '') {
                continue;
            }

            $out[$key] = [
                'upc' => GunDealsAnalyticsStore::normalize_upc((string) ($row['upc'] ?? '')),
                'product_id' => (int) ($row['product_id'] ?? 0),
                'clicks' => (int) ($row['raw_cumulative_clicks'] ?? 0),
                'period_clicks' => 0,
                'raw_cumulative_clicks' => (int) ($row['raw_cumulative_clicks'] ?? 0),
                'deduped_clicks' => (int) ($row['deduped_clicks'] ?? 0),
                'click_source' => 'cumulative_total_fallback',
            ];
        }

        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $period_clicks
     * @param array<string,array<string,mixed>> $cumulative_clicks
     * @return array<string,array<string,mixed>>
     */
    private function merge_click_sources(array $period_clicks, array $cumulative_clicks, bool $use_legacy_fallback): array
    {
        if (!$use_legacy_fallback || empty($cumulative_clicks)) {
            return $period_clicks;
        }

        $merged = $period_clicks;
        foreach ($cumulative_clicks as $key => $cumulative_row) {
            if (!isset($merged[$key])) {
                $merged[$key] = $cumulative_row;
                continue;
            }

            $period_raw = (int) ($merged[$key]['period_clicks'] ?? $merged[$key]['clicks'] ?? 0);
            $cumulative_raw = (int) ($cumulative_row['raw_cumulative_clicks'] ?? $cumulative_row['clicks'] ?? 0);

            $merged[$key]['raw_cumulative_clicks'] = $cumulative_raw;
            $merged[$key]['clicks'] = max($period_raw, $cumulative_raw);
            $merged[$key]['click_source'] = $cumulative_raw > $period_raw ? 'cumulative_total_fallback' : 'period';
            $merged[$key]['deduped_clicks'] = (int) ($merged[$key]['deduped_clicks'] ?? 0);
        }

        return $merged;
    }

    private function use_legacy_click_fallback(string $start_day, string $end_day): bool
    {
        return strcmp($start_day, self::LEGACY_CLICK_FALLBACK_END_DAY) <= 0
            && strcmp($end_day, self::PERIOD_ANCHOR) >= 0;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function query_latest_feed_rows(): array
    {
        $out = [];
        foreach (GunDealsAnalyticsStore::latest_feed_rows() as $row) {
            $key = $this->row_key((string) ($row['upc'] ?? ''), (int) ($row['product_id'] ?? 0));
            if ($key === '') {
                continue;
            }

            $out[$key] = $row;
        }

        return $out;
    }

    /**
     * @return array{items:array<string,array<string,mixed>>,order_count:int}
     */
    private function query_attributed_order_metrics(string $start_gmt, string $end_gmt): array
    {
        if (!function_exists('wc_get_orders')) {
            return ['items' => [], 'order_count' => 0];
        }

        $items = [];
        $order_count = 0;
        $page = 1;

        do {
            $orders = wc_get_orders([
                'status' => ['processing', 'completed'],
                'limit' => 100,
                'page' => $page,
                'paginate' => false,
                'date_created' => $start_gmt . '...' . $end_gmt,
                'orderby' => 'date',
                'order' => 'ASC',
            ]);

            if (!is_array($orders) || empty($orders)) {
                break;
            }

            foreach ($orders as $order) {
                if (!($order instanceof WC_Order)) {
                    continue;
                }

                $attribution = $this->gun_deals_attribution($order);
                if ($attribution === []) {
                    continue;
                }

                $order_count++;
                $this->merge_order_items($items, $order, $attribution);
            }

            $page++;
        } while (count($orders) === 100);

        return [
            'items' => $items,
            'order_count' => $order_count,
        ];
    }

    /**
     * @return string[]
     */
    private function gun_deals_attribution(WC_Order $order): array
    {
        $matches = [];
        foreach ($order->get_meta_data() as $meta) {
            $key = method_exists($meta, 'get_data') ? (string) (($meta->get_data()['key'] ?? '')) : '';
            $value = method_exists($meta, 'get_data') ? ($meta->get_data()['value'] ?? '') : '';
            $key_lc = strtolower($key);

            if (
                strpos($key_lc, 'utm') === false
                && strpos($key_lc, 'source') === false
                && strpos($key_lc, 'referrer') === false
                && strpos($key_lc, 'session') === false
                && strpos($key_lc, 'attribution') === false
            ) {
                continue;
            }

            $encoded_raw = wp_json_encode($value);
            $encoded = strtolower(is_string($encoded_raw) ? $encoded_raw : '');
            if (strpos($encoded, 'gundeals') !== false || strpos($encoded, 'gun.deals') !== false || strpos($encoded, 'gun deals') !== false) {
                $matches[] = $key . '=' . $this->short_value($encoded, 90);
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * @param array<string,array<string,mixed>> $items
     * @param string[] $attribution
     */
    private function merge_order_items(array &$items, WC_Order $order, array $attribution): void
    {
        $audit_lines = $this->audit_lines_by_item_id($order);
        $audit_missing = empty($audit_lines);
        $order_date = $order->get_date_created();
        $order_date_gmt = $order_date ? $order_date->date('Y-m-d H:i:s') : '';
        $order_fee_adjustments = $this->order_fee_adjustments($order);
        $order_revenue_total = max(0.01, (float) $order->get_meta('fflhub_order_revenue_total', true));

        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product_id = (int) $item->get_product_id();
            $upc = GunDealsAnalyticsStore::normalize_upc((string) get_post_meta($product_id, ProductMeta::FFLHUB_UPC_META, true));
            $key = $this->row_key($upc, $product_id);
            if ($key === '') {
                continue;
            }

            $line = $audit_lines[(int) $item_id] ?? null;
            $line_missing = $audit_missing || !is_array($line);
            $qty = $line_missing ? max(0, (int) $item->get_quantity()) : max(0, (int) ($line['qty'] ?? $item->get_quantity()));
            $line_revenue = $line_missing ? 0.0 : $this->money_float($line['line_revenue'] ?? 0);
            $dealer_cost = $line_missing ? 0.0 : $this->money_float($line['distributor_line_cost'] ?? 0);
            $unit_cost = $line_missing ? 0.0 : $this->money_float($line['distributor_unit_cost'] ?? 0);
            $fee_share = $line_revenue > 0.0 ? $order_fee_adjustments * ($line_revenue / $order_revenue_total) : 0.0;

            if (!isset($items[$key])) {
                $items[$key] = [
                    'upc' => $upc,
                    'product_id' => $product_id,
                    'orders' => 0,
                    'order_ids' => [],
                    'units' => 0,
                    'revenue' => 0.0,
                    'dealer_cost' => 0.0,
                    'true_cost' => 0.0,
                    'gross_profit' => 0.0,
                    'fees_adjustments' => 0.0,
                    'last_order_date' => '',
                    'missing_profit_audit' => false,
                    'attribution' => [],
                ];
            }

            if (empty($items[$key]['order_ids'][(int) $order->get_id()])) {
                $items[$key]['order_ids'][(int) $order->get_id()] = true;
                $items[$key]['orders']++;
            }

            $items[$key]['units'] += $qty;
            $items[$key]['revenue'] += $line_revenue;
            $items[$key]['dealer_cost'] += $dealer_cost;
            $items[$key]['true_cost'] += $unit_cost * $qty;
            $items[$key]['gross_profit'] += $line_revenue - $dealer_cost;
            $items[$key]['fees_adjustments'] += $fee_share;
            $items[$key]['missing_profit_audit'] = !empty($items[$key]['missing_profit_audit']) || $line_missing;
            $items[$key]['attribution'] = array_values(array_unique(array_merge($items[$key]['attribution'], $attribution)));
            if ($order_date_gmt !== '' && strcmp($order_date_gmt, (string) $items[$key]['last_order_date']) > 0) {
                $items[$key]['last_order_date'] = $order_date_gmt;
            }
        }

        foreach ($items as &$item_row) {
            unset($item_row['order_ids']);
        }
        unset($item_row);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function audit_lines_by_item_id(WC_Order $order): array
    {
        $raw = $order->get_meta('fflhub_order_profit_lines', true);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = [];
        }

        $lines = [];
        foreach (is_array($decoded) ? $decoded : [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $item_id = (int) ($line['item_id'] ?? 0);
            if ($item_id > 0) {
                $lines[$item_id] = $line;
            }
        }

        return $lines;
    }

    private function order_fee_adjustments(WC_Order $order): float
    {
        $shipping_cost = $this->money_float($order->get_meta('fflhub_order_shipping_cost_total', true));
        $processor_fee = $this->money_float($order->get_meta('fflhub_order_processor_fee_amount', true));
        $customer_shipping = $this->money_float($order->get_meta('fflhub_order_customer_shipping_charge', true));

        return $shipping_cost + $processor_fee - $customer_shipping;
    }

    /**
     * @param int[] $product_ids
     * @param string[] $upcs
     * @return array{by_id:array<int,array<string,mixed>>,by_upc:array<string,array<string,mixed>>}
     */
    private function query_products(array $product_ids, array $upcs): array
    {
        global $wpdb;
        if (!$wpdb) {
            return ['by_id' => [], 'by_upc' => []];
        }

        $product_ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));
        $upcs = array_values(array_unique(array_filter(array_map([GunDealsAnalyticsStore::class, 'normalize_upc'], $upcs))));
        if (empty($product_ids) && empty($upcs)) {
            return ['by_id' => [], 'by_upc' => []];
        }

        $where = [];
        if (!empty($product_ids)) {
            $where[] = 'p.ID IN (' . implode(',', $product_ids) . ')';
        }
        if (!empty($upcs)) {
            $upc_sql = implode(',', array_map(static function (string $upc): string {
                return "'" . esc_sql($upc) . "'";
            }, $upcs));
            $where[] = "EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} upc_pm
                WHERE upc_pm.post_id = p.ID
                  AND upc_pm.meta_key = '" . esc_sql(ProductMeta::FFLHUB_UPC_META) . "'
                  AND upc_pm.meta_value IN ({$upc_sql})
            )";
        }

        $meta_keys = [
            ProductMeta::FFLHUB_UPC_META,
            ProductMeta::FFLHUB_FFL_REQUIRED_META,
            ProductMeta::FFLHUB_SOT_REQUIRED_META,
            ProductMeta::FFLHUB_MAP_POLICY_META,
            '_price',
            '_stock',
            '_stock_status',
        ];
        $meta_sql = implode(',', array_map(static function (string $key): string {
            return "'" . esc_sql($key) . "'";
        }, $meta_keys));

        $rows = $wpdb->get_results("
            SELECT
                p.ID AS product_id,
                p.post_title AS title,
                p.post_status AS post_status,
                p.post_date_gmt AS created_at,
                lookup.stock_status AS lookup_stock_status,
                lookup.stock_quantity AS lookup_stock_quantity,
                MAX(CASE WHEN pm.meta_key = '" . esc_sql(ProductMeta::FFLHUB_UPC_META) . "' THEN pm.meta_value END) AS upc,
                MAX(CASE WHEN pm.meta_key = '" . esc_sql(ProductMeta::FFLHUB_FFL_REQUIRED_META) . "' THEN pm.meta_value END) AS ffl_required,
                MAX(CASE WHEN pm.meta_key = '" . esc_sql(ProductMeta::FFLHUB_SOT_REQUIRED_META) . "' THEN pm.meta_value END) AS sot_required,
                MAX(CASE WHEN pm.meta_key = '" . esc_sql(ProductMeta::FFLHUB_MAP_POLICY_META) . "' THEN pm.meta_value END) AS map_policy,
                MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) AS price,
                MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) AS stock_quantity,
                MAX(CASE WHEN pm.meta_key = '_stock_status' THEN pm.meta_value END) AS stock_status
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup lookup ON lookup.product_id = p.ID
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ({$meta_sql})
            WHERE p.post_type = 'product'
              AND (" . implode(' OR ', $where) . ")
            GROUP BY p.ID, p.post_title, p.post_status, p.post_date_gmt, lookup.stock_status, lookup.stock_quantity
        ", ARRAY_A);

        $by_id = [];
        $by_upc = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            $upc = GunDealsAnalyticsStore::normalize_upc((string) ($row['upc'] ?? ''));
            $row['stock_status'] = (string) (($row['lookup_stock_status'] ?? '') !== '' ? $row['lookup_stock_status'] : ($row['stock_status'] ?? ''));
            $row['stock_quantity'] = (string) (($row['lookup_stock_quantity'] ?? '') !== '' ? $row['lookup_stock_quantity'] : ($row['stock_quantity'] ?? ''));
            $row['upc'] = $upc;
            if ($pid > 0) {
                $by_id[$pid] = $row;
            }
            if ($upc !== '') {
                $by_upc[$upc] = $row;
            }
        }

        return ['by_id' => $by_id, 'by_upc' => $by_upc];
    }

    /**
     * @param int[] $product_ids
     * @return array{brands:array<int,string>,categories:array<int,string>}
     */
    private function query_product_terms(array $product_ids): array
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));
        if (!$wpdb || empty($ids)) {
            return ['brands' => [], 'categories' => []];
        }

        $taxonomy_sql = "'product_cat','product_brand','pwb-brand','yith_product_brand','woocommerce_brand','product_brands','pa_brand'";
        $rows = $wpdb->get_results("
            SELECT tr.object_id AS product_id, tt.taxonomy, t.name
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE tr.object_id IN (" . implode(',', $ids) . ")
              AND tt.taxonomy IN ({$taxonomy_sql})
            ORDER BY t.name ASC
        ", ARRAY_A);

        $brands = [];
        $categories = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            $taxonomy = (string) ($row['taxonomy'] ?? '');
            $name = trim(wp_strip_all_tags((string) ($row['name'] ?? '')));
            if ($pid <= 0 || $name === '') {
                continue;
            }

            if ($taxonomy === 'product_cat') {
                $categories[$pid][] = $name;
            } elseif (!isset($brands[$pid])) {
                $brands[$pid] = $name;
            }
        }

        return [
            'brands' => $brands,
            'categories' => array_map(static function (array $names): string {
                return implode(', ', array_values(array_unique($names)));
            }, $categories),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @return array{direct:float,non_ffl:float}
     */
    private function conversion_medians(array $rows): array
    {
        $direct = [];
        $non_ffl = [];
        foreach ($rows as $row) {
            if ((int) $row['clicks'] <= 0) {
                continue;
            }

            $conversion = (float) $row['conversion_rate'];
            if ((string) $row['map_policy'] === '') {
                $direct[] = $conversion;
            }
            if (empty($row['ffl_required'])) {
                $non_ffl[] = $conversion;
            }
        }

        return [
            'direct' => $this->median($direct),
            'non_ffl' => $this->median($non_ffl),
        ];
    }

    /**
     * @param float[] $values
     */
    private function median(array $values): float
    {
        $values = array_values(array_filter($values, static fn($value): bool => is_numeric($value)));
        if (empty($values)) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = (int) floor($count / 2);
        if ($count % 2) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2.0;
    }

    /**
     * @param array<string,mixed> $row
     * @param array{direct:float,non_ffl:float} $medians
     * @return string[]
     */
    private function recommendation_badges(array $row, array $medians): array
    {
        $badges = [];
        $clicks = (int) $row['clicks'];
        $orders = (int) $row['orders'];
        $conversion = (float) $row['conversion_rate'];
        $gross = (float) $row['gross_profit'];
        $net = (float) $row['net_profit'];
        $margin = (float) $row['margin'];
        $map_policy = (string) $row['map_policy'];

        if (($clicks >= 10 && $conversion >= 5.0) || ($orders >= 2 && $net > 0.0)) {
            $badges[] = 'Good Converter';
        }
        if ($clicks >= 25 && $orders === 0) {
            $badges[] = 'High Demand / No Orders';
        }
        if ($clicks >= 25 && $conversion < 1.0) {
            $badges[] = 'Leaky Clicks';
        }
        if (!empty($row['recent_stock_window_risk'])) {
            $badges[] = 'Recent Stock-Window Risk';
        }
        if (in_array($map_policy, [Options::MAP_POLICY_EMAIL_FOR_QUOTE, Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE], true)
            && $clicks >= 25
            && $medians['direct'] > 0.0
            && $conversion < ($medians['direct'] * 0.5)
        ) {
            $badges[] = 'MAP Friction';
        }
        if (!empty($row['ffl_required'])
            && $clicks >= 25
            && $medians['non_ffl'] > 0.0
            && $conversion < ($medians['non_ffl'] * 0.5)
        ) {
            $badges[] = 'Firearm Checkout Friction';
        }
        if ($clicks >= 25 && strtolower((string) $row['stock_status']) === 'instock' && ($conversion < 1.0 || $orders === 0) && ($gross <= 0.0 || $margin < 5.0)) {
            $badges[] = 'Review Price';
        }
        if ($gross > 0.0 && (float) $row['allocated_cost'] >= ($gross * 0.5)) {
            $badges[] = 'Click Cost Eating Margin';
        }
        if ($net > 0.0) {
            $badges[] = 'Profitable After Click Cost';
        }
        if ($clicks >= 10 && $net < 0.0) {
            $badges[] = 'Unprofitable After Click Cost';
        }
        if ($clicks >= 50 && $orders === 0 && strtolower((string) $row['stock_status']) === 'instock' && $this->days_since((string) $row['created_at']) > 7 && $net <= 0.0) {
            $badges[] = 'Consider Excluding From Feed';
        }
        if (!empty($row['missing_profit_audit'])) {
            $badges[] = 'Missing Profit Audit';
        }

        $serious = [
            'High Demand / No Orders',
            'Leaky Clicks',
            'Recent Stock-Window Risk',
            'MAP Friction',
            'Firearm Checkout Friction',
            'Review Price',
            'Click Cost Eating Margin',
            'Unprofitable After Click Cost',
            'Consider Excluding From Feed',
            'Missing Profit Audit',
        ];
        if (array_intersect($serious, $badges)) {
            array_unshift($badges, 'Needs Attention');
        }

        return array_values(array_unique($badges));
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function apply_saved_view(array $rows, string $view): array
    {
        if ($view === '' || $view === 'all') {
            return $rows;
        }

        return array_filter($rows, function (array $row) use ($view): bool {
            $badges = $row['badges'] ?? [];
            switch ($view) {
                case 'needs_attention':
                    return in_array('Needs Attention', $badges, true);
                case 'high_clicks_zero_orders':
                    return (int) $row['clicks'] >= 25 && (int) $row['orders'] === 0;
                case 'high_clicks_low_conversion':
                    return (int) $row['clicks'] >= 25 && (float) $row['conversion_rate'] < 1.0;
                case 'profitable_winners':
                    return (int) $row['orders'] > 0 && (float) $row['net_profit'] > 0.0;
                case 'losing_money':
                    return (int) $row['clicks'] > 0 && (float) $row['net_profit'] < 0.0;
                case 'map_quote_review':
                    return in_array((string) $row['map_policy'], [Options::MAP_POLICY_EMAIL_FOR_QUOTE, Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE], true);
                case 'ffl_product_review':
                    return !empty($row['ffl_required']);
                case 'recent_stock_window_risk':
                    return in_array('Recent Stock-Window Risk', $badges, true);
                case 'feed_exclusion_candidates':
                    return in_array('Consider Excluding From Feed', $badges, true);
                case 'missing_profit_audit':
                    return !empty($row['missing_profit_audit']);
            }

            return true;
        });
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @param array<string,mixed> $filters
     * @return array<string,array<string,mixed>>
     */
    private function apply_filters(array $rows, array $filters): array
    {
        return array_filter($rows, function (array $row) use ($filters): bool {
            if ($filters['feed'] === 'yes' && empty($row['feed_included'])) {
                return false;
            }
            if ($filters['feed'] === 'no' && !empty($row['feed_included'])) {
                return false;
            }
            if ($filters['stock'] !== '' && strtolower((string) $row['stock_status']) !== strtolower((string) $filters['stock'])) {
                return false;
            }
            if ($filters['brand'] !== '' && strcasecmp((string) $row['brand'], (string) $filters['brand']) !== 0) {
                return false;
            }
            if ($filters['category'] !== '' && stripos((string) $row['category'], (string) $filters['category']) === false) {
                return false;
            }
            if ($filters['ffl'] === 'yes' && empty($row['ffl_required'])) {
                return false;
            }
            if ($filters['ffl'] === 'no' && !empty($row['ffl_required'])) {
                return false;
            }
            if ($filters['map'] === 'quote' && !in_array((string) $row['map_policy'], [Options::MAP_POLICY_EMAIL_FOR_QUOTE, Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE], true)) {
                return false;
            }
            if ($filters['map'] === 'direct' && (string) $row['map_policy'] !== '') {
                return false;
            }
            if ((int) $row['clicks'] < (int) $filters['min_clicks']) {
                return false;
            }
            if ($filters['max_clicks'] !== null && (int) $row['clicks'] > (int) $filters['max_clicks']) {
                return false;
            }
            if ((int) $row['orders'] < (int) $filters['min_orders']) {
                return false;
            }
            if ($filters['max_orders'] !== null && (int) $row['orders'] > (int) $filters['max_orders']) {
                return false;
            }
            if ($filters['min_conversion'] !== null && (float) $row['conversion_rate'] < (float) $filters['min_conversion']) {
                return false;
            }
            if ($filters['max_conversion'] !== null && (float) $row['conversion_rate'] > (float) $filters['max_conversion']) {
                return false;
            }
            if ($filters['min_profit'] !== null && (float) $row['net_profit'] < (float) $filters['min_profit']) {
                return false;
            }
            if ($filters['max_profit'] !== null && (float) $row['net_profit'] > (float) $filters['max_profit']) {
                return false;
            }
            if ($filters['missing_profit'] === 'yes' && empty($row['missing_profit_audit'])) {
                return false;
            }
            if ($filters['missing_profit'] === 'no' && !empty($row['missing_profit_audit'])) {
                return false;
            }

            return true;
        });
    }

    /**
     * @return array<string,array{label:string,type:string,default_order:string}>
     */
    private function sortable_columns(): array
    {
        return [
            'attention' => ['label' => 'Attention', 'type' => 'number', 'default_order' => 'desc'],
            'upc' => ['label' => 'UPC', 'type' => 'string', 'default_order' => 'asc'],
            'title' => ['label' => 'Product', 'type' => 'string', 'default_order' => 'asc'],
            'feed_included' => ['label' => 'Feed Included', 'type' => 'number', 'default_order' => 'desc'],
            'stock_status' => ['label' => 'Stock Status', 'type' => 'string', 'default_order' => 'asc'],
            'clicks' => ['label' => 'Clicks', 'type' => 'number', 'default_order' => 'desc'],
            'orders' => ['label' => 'Orders', 'type' => 'number', 'default_order' => 'desc'],
            'units' => ['label' => 'Units Sold', 'type' => 'number', 'default_order' => 'desc'],
            'conversion_rate' => ['label' => 'Conversion Rate', 'type' => 'number', 'default_order' => 'desc'],
            'revenue' => ['label' => 'Revenue', 'type' => 'number', 'default_order' => 'desc'],
            'gross_profit' => ['label' => 'Gross Profit', 'type' => 'number', 'default_order' => 'desc'],
            'allocated_cost' => ['label' => 'Allocated Gun.deals Cost', 'type' => 'number', 'default_order' => 'desc'],
            'net_profit' => ['label' => 'Channel Net', 'type' => 'number', 'default_order' => 'asc'],
            'ffl_required' => ['label' => 'FFL Required', 'type' => 'number', 'default_order' => 'desc'],
            'map_status' => ['label' => 'MAP/Quote Status', 'type' => 'string', 'default_order' => 'asc'],
            'customer_price' => ['label' => 'Customer Price', 'type' => 'number', 'default_order' => 'desc'],
            'product_id' => ['label' => 'Product ID', 'type' => 'number', 'default_order' => 'asc'],
            'raw_cumulative_clicks' => ['label' => 'Raw Cumulative Clicks', 'type' => 'number', 'default_order' => 'desc'],
            'period_clicks' => ['label' => 'Period Raw Clicks', 'type' => 'number', 'default_order' => 'desc'],
            'click_source' => ['label' => 'Click Source', 'type' => 'string', 'default_order' => 'asc'],
            'deduped_clicks' => ['label' => 'Deduped Clicks', 'type' => 'number', 'default_order' => 'desc'],
            'stock_quantity' => ['label' => 'Stock Quantity', 'type' => 'number', 'default_order' => 'desc'],
            'brand' => ['label' => 'Brand', 'type' => 'string', 'default_order' => 'asc'],
            'category' => ['label' => 'Category', 'type' => 'string', 'default_order' => 'asc'],
            'dealer_cost' => ['label' => 'Dealer Cost', 'type' => 'number', 'default_order' => 'desc'],
            'true_cost' => ['label' => 'True Cost', 'type' => 'number', 'default_order' => 'desc'],
            'margin' => ['label' => 'Margin', 'type' => 'number', 'default_order' => 'desc'],
            'fees_adjustments' => ['label' => 'Fees/Adjustments', 'type' => 'number', 'default_order' => 'desc'],
            'sot_required' => ['label' => 'SOT Required', 'type' => 'number', 'default_order' => 'desc'],
            'last_order_date' => ['label' => 'Last Order Date', 'type' => 'string', 'default_order' => 'desc'],
            'cost_per_order' => ['label' => 'Cost Per Order', 'type' => 'number', 'default_order' => 'desc'],
            'cost_per_revenue_dollar' => ['label' => 'Cost Per Revenue Dollar', 'type' => 'number', 'default_order' => 'desc'],
            'click_cost_profit_percent' => ['label' => 'Click Cost % Profit', 'type' => 'number', 'default_order' => 'desc'],
            'marginal_click_cost' => ['label' => 'Marginal Click Cost', 'type' => 'number', 'default_order' => 'desc'],
        ];
    }

    private function default_sort_order(string $sort): string
    {
        $columns = $this->sortable_columns();
        return (string) ($columns[$sort]['default_order'] ?? 'desc');
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function sort_rows(array $rows, string $sort, string $order): array
    {
        $columns = $this->sortable_columns();
        if (!isset($columns[$sort])) {
            $sort = 'attention';
        }

        $order = $order === 'asc' ? 'asc' : 'desc';
        $direction = $order === 'asc' ? 1 : -1;
        $type = (string) ($columns[$sort]['type'] ?? 'number');

        uasort($rows, function (array $a, array $b) use ($sort, $type, $direction): int {
            $cmp = $this->compare_sort_values(
                $this->sort_value($a, $sort),
                $this->sort_value($b, $sort),
                $type
            );

            if ($cmp !== 0) {
                return $cmp * $direction;
            }

            $attention_cmp = $this->attention_score($b) <=> $this->attention_score($a);
            if ($attention_cmp !== 0) {
                return $attention_cmp;
            }

            return ((int) $b['clicks'] <=> (int) $a['clicks'])
                ?: strcasecmp((string) $a['title'], (string) $b['title']);
        });

        return $rows;
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @return array{total:int,per_page:int,current_page:int,total_pages:int,offset:int,from:int,to:int}
     */
    private function pagination_for_rows(array $rows, int $paged, int $per_page): array
    {
        $total = count($rows);
        $per_page = $this->clamp_per_page($per_page);
        $total_pages = max(1, (int) ceil($total / $per_page));
        $current_page = min(max(1, $paged), $total_pages);
        $offset = ($current_page - 1) * $per_page;

        return [
            'total' => $total,
            'per_page' => $per_page,
            'current_page' => $current_page,
            'total_pages' => $total_pages,
            'offset' => $offset,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($total, $offset + $per_page),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @param array{total:int,per_page:int,current_page:int,total_pages:int,offset:int,from:int,to:int} $pagination
     * @return array<string,array<string,mixed>>
     */
    private function paginate_rows(array $rows, array $pagination): array
    {
        return array_slice($rows, (int) $pagination['offset'], (int) $pagination['per_page'], true);
    }

    /**
     * @param mixed $a
     * @param mixed $b
     */
    private function compare_sort_values($a, $b, string $type): int
    {
        if ($type === 'string') {
            return strcasecmp((string) $a, (string) $b);
        }

        return (float) $a <=> (float) $b;
    }

    /**
     * @param array<string,mixed> $row
     * @return mixed
     */
    private function sort_value(array $row, string $sort)
    {
        if ($sort === 'attention') {
            return $this->attention_score($row);
        }

        if (in_array($sort, ['feed_included', 'ffl_required', 'sot_required'], true)) {
            return !empty($row[$sort]) ? 1 : 0;
        }

        if ($sort === 'stock_quantity') {
            return is_numeric($row['stock_quantity'] ?? null) ? (float) $row['stock_quantity'] : -1.0;
        }

        return $row[$sort] ?? '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function attention_score(array $row): int
    {
        $badges = is_array($row['badges'] ?? null) ? $row['badges'] : [];
        $score = 0;

        $weights = [
            'Missing Profit Audit' => 1000,
            'Consider Excluding From Feed' => 900,
            'Unprofitable After Click Cost' => 800,
            'High Demand / No Orders' => 700,
            'Leaky Clicks' => 650,
            'Click Cost Eating Margin' => 600,
            'Review Price' => 550,
            'MAP Friction' => 500,
            'Firearm Checkout Friction' => 500,
            'Recent Stock-Window Risk' => 450,
            'Profitable After Click Cost' => 100,
            'Good Converter' => 75,
        ];

        foreach ($badges as $badge) {
            $score += (int) ($weights[(string) $badge] ?? 0);
        }

        $score += min(250, (int) $row['clicks']);
        if ((int) $row['orders'] === 0 && (int) $row['clicks'] > 0) {
            $score += 100;
        }

        return $score;
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function summary_from_rows(array $rows): array
    {
        $summary = [
            'clicks' => 0,
            'orders' => 0,
            'units' => 0,
            'revenue' => 0.0,
            'gross_profit' => 0.0,
            'zero_order_clicked' => 0,
            'high_click_poor_converters' => 0,
        ];

        foreach ($rows as $row) {
            $summary['clicks'] += (int) $row['clicks'];
            $summary['orders'] += (int) $row['orders'];
            $summary['units'] += (int) $row['units'];
            $summary['revenue'] += (float) $row['revenue'];
            $summary['gross_profit'] += (float) $row['gross_profit'];
            if ((int) $row['clicks'] > 0 && (int) $row['orders'] === 0) {
                $summary['zero_order_clicked']++;
            }
            if ((int) $row['clicks'] >= 25 && (float) $row['conversion_rate'] < 1.0) {
                $summary['high_click_poor_converters']++;
            }
        }

        $summary['conversion_rate'] = (int) $summary['clicks'] > 0 ? ((int) $summary['orders'] / (int) $summary['clicks']) * 100.0 : 0.0;

        return $summary;
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     */
    private function count_clicked_out_of_stock(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if ((int) $row['clicks'] > 0 && strtolower((string) $row['stock_status']) !== 'instock') {
                $count++;
            }
        }

        return $count;
    }

    private function gun_deals_cost(int $clicks): float
    {
        return self::BASE_FEE + max(0, $clicks - self::INCLUDED_CLICKS) * self::OVERAGE_CPC;
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @return string[]
     */
    private function unique_column(array $rows, string $column): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '') {
                $values[$value] = $value;
            }
        }
        ksort($values, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($values);
    }

    private function row_key(string $upc, int $product_id): string
    {
        $upc = GunDealsAnalyticsStore::normalize_upc($upc);
        if ($upc !== '') {
            return $upc;
        }

        return $product_id > 0 ? 'pid:' . $product_id : '';
    }

    private function normalize_map_policy(string $policy): string
    {
        $policy = strtolower(trim($policy));
        if (in_array($policy, [Options::MAP_POLICY_EMAIL_FOR_QUOTE, Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE, Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART], true)) {
            return $policy;
        }

        return '';
    }

    private function map_status_label(string $policy): string
    {
        if ($policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE) {
            return 'Email quote';
        }
        if ($policy === Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE) {
            return 'Add to cart for price';
        }
        if ($policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) {
            return 'MAP visible / no quote';
        }

        return 'Direct add to cart';
    }

    /**
     * @param mixed $value
     */
    private function boolish($value): bool
    {
        $raw = strtolower(trim((string) $value));
        return in_array($raw, ['1', 'yes', 'true', 'on'], true);
    }

    /**
     * @param mixed $value
     */
    private function money_float($value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }

        $value = (float) $value;
        return is_finite($value) ? $value : 0.0;
    }

    private function days_since(string $date_gmt): int
    {
        $ts = strtotime($date_gmt);
        if (!$ts) {
            return 9999;
        }

        return max(0, (int) floor((time() - $ts) / DAY_IN_SECONDS));
    }

    private function short_value(string $value, int $limit): string
    {
        return strlen($value) > $limit ? substr($value, 0, $limit - 3) . '...' : $value;
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $report
     */
    private function render_filter_form(array $filters, array $report): void
    {
        $views = [
            'all' => 'All',
            'needs_attention' => 'Needs Attention',
            'high_clicks_zero_orders' => 'High Clicks / Zero Orders',
            'high_clicks_low_conversion' => 'High Clicks / Low Conversion',
            'profitable_winners' => 'Profitable Winners',
            'losing_money' => 'Losing Money',
            'map_quote_review' => 'MAP / Quote Review',
            'ffl_product_review' => 'FFL Product Review',
            'recent_stock_window_risk' => 'Recent Stock Window Risk',
            'feed_exclusion_candidates' => 'Feed Exclusion Candidates',
            'missing_profit_audit' => 'Missing Profit Audit',
        ];

        $sort_options = [];
        foreach ($this->sortable_columns() as $key => $column) {
            $sort_options[$key] = (string) $column['label'];
        }

        $export_url = add_query_arg(array_merge($_GET, [
            'page' => self::PAGE_SLUG,
            'fflhub_gundeals_export' => '1',
            '_wpnonce' => wp_create_nonce('fflhub_gundeals_export'),
        ]), admin_url('admin.php'));
        ?>
        <form method="get" class="fflhub-gd-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
            <label>Start <input type="date" name="start_date" value="<?php echo esc_attr((string) $filters['start_date']); ?>" /></label>
            <label>End <input type="date" name="end_date" value="<?php echo esc_attr((string) $filters['end_date']); ?>" /></label>
            <label>View <?php $this->select('view', (string) $filters['view'], $views); ?></label>
            <label>Feed <?php $this->select('feed', (string) $filters['feed'], ['' => 'Any', 'yes' => 'Included', 'no' => 'Not Included']); ?></label>
            <label>Stock <?php $this->select('stock', (string) $filters['stock'], ['' => 'Any', 'instock' => 'In Stock', 'outofstock' => 'Out of Stock', 'unknown' => 'Unknown']); ?></label>
            <label>Brand <?php $this->select_from_values('brand', (string) $filters['brand'], $report['brands'] ?? []); ?></label>
            <label>Category <?php $this->select_from_values('category', (string) $filters['category'], $report['categories'] ?? []); ?></label>
            <label>FFL <?php $this->select('ffl', (string) $filters['ffl'], ['' => 'Any', 'yes' => 'FFL', 'no' => 'Non-FFL']); ?></label>
            <label>MAP <?php $this->select('map', (string) $filters['map'], ['' => 'Any', 'quote' => 'Quote/MAP', 'direct' => 'Direct Cart']); ?></label>
            <label>Min Clicks <input type="number" min="0" name="min_clicks" value="<?php echo esc_attr((string) $filters['min_clicks']); ?>" /></label>
            <label>Max Clicks <input type="number" min="0" name="max_clicks" value="<?php echo esc_attr($filters['max_clicks'] !== null ? (string) $filters['max_clicks'] : ''); ?>" /></label>
            <label>Min Orders <input type="number" min="0" name="min_orders" value="<?php echo esc_attr((string) $filters['min_orders']); ?>" /></label>
            <label>Max Orders <input type="number" min="0" name="max_orders" value="<?php echo esc_attr($filters['max_orders'] !== null ? (string) $filters['max_orders'] : ''); ?>" /></label>
            <label>Min Conv % <input type="number" step="0.01" name="min_conversion" value="<?php echo esc_attr($filters['min_conversion'] !== null ? (string) $filters['min_conversion'] : ''); ?>" /></label>
            <label>Max Conv % <input type="number" step="0.01" name="max_conversion" value="<?php echo esc_attr($filters['max_conversion'] !== null ? (string) $filters['max_conversion'] : ''); ?>" /></label>
            <label>Min Net $ <input type="number" step="0.01" name="min_profit" value="<?php echo esc_attr($filters['min_profit'] !== null ? (string) $filters['min_profit'] : ''); ?>" /></label>
            <label>Max Net $ <input type="number" step="0.01" name="max_profit" value="<?php echo esc_attr($filters['max_profit'] !== null ? (string) $filters['max_profit'] : ''); ?>" /></label>
            <label>Missing Audit <?php $this->select('missing_profit', (string) $filters['missing_profit'], ['' => 'Any', 'yes' => 'Yes', 'no' => 'No']); ?></label>
            <label>Sort <?php $this->select('sort', (string) $filters['sort'], $sort_options); ?></label>
            <label>Order <?php $this->select('order', (string) $filters['order'], ['desc' => 'Descending', 'asc' => 'Ascending']); ?></label>
            <label>Rows <?php $this->select('per_page', (string) $filters['per_page'], ['25' => '25', '50' => '50', '100' => '100', '250' => '250']); ?></label>
            <label><input type="checkbox" name="advanced" value="1" <?php checked(!empty($filters['advanced'])); ?> /> Advanced columns</label>
            <button class="button button-primary" type="submit">Apply</button>
            <a class="button" href="<?php echo esc_url($export_url); ?>">Export CSV</a>
            <?php if ((string) ($report['latest_feed_snapshot'] ?? '') !== '') : ?>
                <span class="fflhub-gd-snapshot">Latest feed snapshot: <?php echo esc_html((string) $report['latest_feed_snapshot']); ?> GMT</span>
            <?php endif; ?>
        </form>
        <?php
    }

    /**
     * @param array<string,string> $options
     */
    private function select(string $name, string $selected, array $options): void
    {
        echo '<select name="' . esc_attr($name) . '">';
        foreach ($options as $value => $label) {
            echo '<option value="' . esc_attr((string) $value) . '"' . selected($selected, (string) $value, false) . '>' . esc_html((string) $label) . '</option>';
        }
        echo '</select>';
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function sortable_header(string $sort, string $label, array $filters): void
    {
        $current_sort = (string) ($filters['sort'] ?? 'attention');
        $current_order = (string) ($filters['order'] ?? $this->default_sort_order($current_sort));
        $active = $current_sort === $sort;
        $next_order = $active && $current_order === 'desc'
            ? 'asc'
            : ($active ? 'desc' : $this->default_sort_order($sort));

        $args = $_GET;
        unset($args['fflhub_gundeals_export'], $args['_wpnonce']);
        $args['page'] = self::PAGE_SLUG;
        $args['sort'] = $sort;
        $args['order'] = $next_order;
        unset($args['paged']);

        $suffix = $active ? ' (' . $current_order . ')' : '';
        echo '<a href="' . esc_url(add_query_arg($args, admin_url('admin.php'))) . '">'
            . esc_html($label . $suffix)
            . '</a>';
    }

    /**
     * @param string[] $values
     */
    private function select_from_values(string $name, string $selected, array $values): void
    {
        echo '<select name="' . esc_attr($name) . '"><option value="">Any</option>';
        foreach ($values as $value) {
            echo '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($value) . '</option>';
        }
        echo '</select>';
    }

    /**
     * @param array<string,mixed> $report
     */
    private function render_cards(array $report): void
    {
        $s = $report['summary'];
        $cards = [
            'Reporting period' => esc_html((string) $report['period_start_gmt'] . ' to ' . (string) $report['period_end_gmt']),
            'Feed UPC count' => number_format_i18n((int) $s['feed_upc_count']),
            'Total Gun.deals clicks' => number_format_i18n((int) $s['clicks']),
            'Included clicks remaining' => number_format_i18n((int) $s['included_clicks_remaining']),
            'Overage clicks' => number_format_i18n((int) $s['overage_clicks']),
            'Overage cost' => $this->money((float) $s['overage_cost']),
            'Total estimated Gun.deals cost' => $this->money((float) $s['period_cost']),
            'Effective CPC' => $this->money((float) $s['effective_cpc']),
            'Gun.deals-attributed orders' => number_format_i18n((int) $s['attributed_orders']),
            'Units sold' => number_format_i18n((int) $s['units']),
            'Revenue' => $this->money((float) $s['revenue']),
            'Gross profit' => $this->money((float) $s['gross_profit']),
            'Net after Gun.deals cost' => $this->money((float) $s['net_after_period_cost']),
            'Break-even status' => ((float) $s['break_even_gap'] <= 0.0 ? 'Profitable' : 'Short'),
            'Break-even gap' => $this->money((float) $s['break_even_gap']),
            'Conversion rate' => number_format_i18n((float) $s['conversion_rate'], 2) . '%',
            'Clicks / zero orders' => number_format_i18n((int) $s['zero_order_clicked']),
            'High-click poor converters' => number_format_i18n((int) $s['high_click_poor_converters']),
            'Clicked and out of stock' => number_format_i18n((int) $s['clicked_out_of_stock']),
        ];
        ?>
        <div class="fflhub-gd-cards">
            <?php foreach ($cards as $label => $value) : ?>
                <div class="fflhub-gd-card">
                    <span><?php echo esc_html($label); ?></span>
                    <strong><?php echo esc_html((string) $value); ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $filters
     */
    private function render_table(array $report, array $filters): void
    {
        $show_advanced = !empty($filters['advanced']);
        $this->render_pagination($report, 'top');
        ?>
        <table class="widefat striped fflhub-gd-table">
            <thead>
                <tr>
                    <th><?php $this->sortable_header('attention', 'Recommendation', $filters); ?></th>
                    <th><?php $this->sortable_header('upc', 'UPC', $filters); ?></th>
                    <th><?php $this->sortable_header('title', 'Product', $filters); ?></th>
                    <th><?php $this->sortable_header('feed_included', 'Feed', $filters); ?></th>
                    <th><?php $this->sortable_header('stock_status', 'Stock', $filters); ?></th>
                    <th><?php $this->sortable_header('clicks', 'Clicks', $filters); ?></th>
                    <th><?php $this->sortable_header('orders', 'Orders', $filters); ?></th>
                    <th><?php $this->sortable_header('units', 'Units', $filters); ?></th>
                    <th><?php $this->sortable_header('conversion_rate', 'Conv.', $filters); ?></th>
                    <th><?php $this->sortable_header('revenue', 'Revenue', $filters); ?></th>
                    <th><?php $this->sortable_header('gross_profit', 'Gross Profit', $filters); ?></th>
                    <th><?php $this->sortable_header('allocated_cost', 'Allocated GD Cost', $filters); ?></th>
                    <th><?php $this->sortable_header('net_profit', 'Channel Net', $filters); ?></th>
                    <th><?php $this->sortable_header('ffl_required', 'FFL', $filters); ?></th>
                    <th><?php $this->sortable_header('map_status', 'MAP/Quote', $filters); ?></th>
                    <th><?php $this->sortable_header('customer_price', 'Visible Price', $filters); ?></th>
                    <?php if ($show_advanced) : ?>
                        <th><?php $this->sortable_header('product_id', 'Product ID', $filters); ?></th>
                        <th><?php $this->sortable_header('raw_cumulative_clicks', 'Raw Cumulative', $filters); ?></th>
                        <th><?php $this->sortable_header('period_clicks', 'Period Raw', $filters); ?></th>
                        <th><?php $this->sortable_header('click_source', 'Click Source', $filters); ?></th>
                        <th><?php $this->sortable_header('deduped_clicks', 'Deduped', $filters); ?></th>
                        <th><?php $this->sortable_header('stock_quantity', 'Stock Qty', $filters); ?></th>
                        <th><?php $this->sortable_header('brand', 'Brand', $filters); ?></th>
                        <th><?php $this->sortable_header('category', 'Category', $filters); ?></th>
                        <th><?php $this->sortable_header('dealer_cost', 'Dealer Cost', $filters); ?></th>
                        <th><?php $this->sortable_header('true_cost', 'True Cost', $filters); ?></th>
                        <th><?php $this->sortable_header('margin', 'Margin', $filters); ?></th>
                        <th><?php $this->sortable_header('fees_adjustments', 'Fees/Adjustments', $filters); ?></th>
                        <th><?php $this->sortable_header('sot_required', 'SOT', $filters); ?></th>
                        <th><?php $this->sortable_header('last_order_date', 'Last Order', $filters); ?></th>
                        <th>Views</th>
                        <th>Add to Cart</th>
                        <th>Email Quotes</th>
                        <th>Attribution</th>
                        <th><?php $this->sortable_header('cost_per_order', 'Cost / Order', $filters); ?></th>
                        <th><?php $this->sortable_header('cost_per_revenue_dollar', 'Cost / Revenue $', $filters); ?></th>
                        <th><?php $this->sortable_header('click_cost_profit_percent', 'Click Cost % Profit', $filters); ?></th>
                        <th><?php $this->sortable_header('marginal_click_cost', 'Marginal Click Cost', $filters); ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($report['rows'])) : ?>
                <tr><td colspan="<?php echo esc_attr($show_advanced ? '38' : '16'); ?>">No products match the current filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($report['rows'] as $row) : ?>
                <tr>
                    <td><?php $this->render_badges($row['badges'] ?? []); ?></td>
                    <td><code><?php echo esc_html((string) $row['upc']); ?></code></td>
                    <td>
                        <?php if ((string) $row['edit_url'] !== '') : ?>
                            <a href="<?php echo esc_url((string) $row['edit_url']); ?>"><?php echo esc_html((string) $row['title']); ?></a>
                        <?php else : ?>
                            <?php echo esc_html((string) $row['title']); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo !empty($row['feed_included']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo esc_html((string) $row['stock_status']); ?></td>
                    <td><?php echo number_format_i18n((int) $row['clicks']); ?></td>
                    <td><?php echo number_format_i18n((int) $row['orders']); ?></td>
                    <td><?php echo number_format_i18n((int) $row['units']); ?></td>
                    <td><?php echo esc_html(number_format_i18n((float) $row['conversion_rate'], 2) . '%'); ?></td>
                    <td><?php echo esc_html($this->money((float) $row['revenue'])); ?></td>
                    <td><?php echo esc_html($this->money((float) $row['gross_profit'])); ?></td>
                    <td><?php echo esc_html($this->money((float) $row['allocated_cost'])); ?></td>
                    <td><?php echo esc_html($this->money((float) $row['net_profit'])); ?></td>
                    <td><?php echo !empty($row['ffl_required']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo esc_html((string) $row['map_status']); ?></td>
                    <td><?php echo esc_html($this->money((float) $row['customer_price'])); ?></td>
                    <?php if ($show_advanced) : ?>
                        <td><?php echo esc_html((string) $row['product_id']); ?></td>
                        <td><?php echo number_format_i18n((int) $row['raw_cumulative_clicks']); ?></td>
                        <td><?php echo number_format_i18n((int) $row['period_clicks']); ?></td>
                        <td><?php echo esc_html((string) $row['click_source']); ?></td>
                        <td><?php echo number_format_i18n((int) $row['deduped_clicks']); ?></td>
                        <td><?php echo esc_html((string) $row['stock_quantity']); ?></td>
                        <td><?php echo esc_html((string) $row['brand']); ?></td>
                        <td><?php echo esc_html((string) $row['category']); ?></td>
                        <td><?php echo esc_html($this->money((float) $row['dealer_cost'])); ?></td>
                        <td><?php echo esc_html($this->money((float) $row['true_cost'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n((float) $row['margin'], 2) . '%'); ?></td>
                        <td><?php echo esc_html($this->money((float) $row['fees_adjustments'])); ?></td>
                        <td><?php echo !empty($row['sot_required']) ? 'Yes' : 'No'; ?></td>
                        <td><?php echo esc_html((string) $row['last_order_date']); ?></td>
                        <td><?php echo esc_html((string) $row['page_views']); ?></td>
                        <td><?php echo esc_html((string) $row['add_to_cart_count']); ?></td>
                        <td><?php echo esc_html((string) $row['email_quote_count']); ?></td>
                        <td><?php echo esc_html((string) $row['source_attribution']); ?></td>
                        <td><?php echo esc_html($this->money((float) $row['cost_per_order'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n((float) $row['cost_per_revenue_dollar'], 4)); ?></td>
                        <td><?php echo esc_html(number_format_i18n((float) $row['click_cost_profit_percent'], 2) . '%'); ?></td>
                        <td><?php echo esc_html($this->money((float) $row['marginal_click_cost'])); ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php $this->render_pagination($report, 'bottom'); ?>
        <?php
    }

    /**
     * @param array<string,mixed> $report
     */
    private function render_pagination(array $report, string $position): void
    {
        $pagination = $report['pagination'] ?? null;
        if (!is_array($pagination)) {
            return;
        }

        $total = (int) ($pagination['total'] ?? 0);
        $current = (int) ($pagination['current_page'] ?? 1);
        $total_pages = (int) ($pagination['total_pages'] ?? 1);
        $from = (int) ($pagination['from'] ?? 0);
        $to = (int) ($pagination['to'] ?? 0);

        $classes = 'fflhub-gd-pagination fflhub-gd-pagination-' . sanitize_html_class($position);
        ?>
        <div class="<?php echo esc_attr($classes); ?>">
            <span>
                <?php
                echo esc_html(sprintf(
                    'Showing %s-%s of %s products',
                    number_format_i18n($from),
                    number_format_i18n($to),
                    number_format_i18n($total)
                ));
                ?>
            </span>
            <?php if ($total_pages > 1) : ?>
                <span class="fflhub-gd-page-links">
                    <?php if ($current > 1) : ?>
                        <a class="button" href="<?php echo esc_url($this->pagination_url($current - 1)); ?>">Prev</a>
                    <?php endif; ?>

                    <?php foreach ($this->pagination_page_numbers($current, $total_pages) as $page_number) : ?>
                        <?php if ($page_number === 0) : ?>
                            <span class="fflhub-gd-page-gap">...</span>
                        <?php elseif ($page_number === $current) : ?>
                            <span class="button button-primary disabled"><?php echo esc_html((string) $page_number); ?></span>
                        <?php else : ?>
                            <a class="button" href="<?php echo esc_url($this->pagination_url($page_number)); ?>"><?php echo esc_html((string) $page_number); ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($current < $total_pages) : ?>
                        <a class="button" href="<?php echo esc_url($this->pagination_url($current + 1)); ?>">Next</a>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
        <?php
    }

    private function pagination_url(int $page_number): string
    {
        $args = $_GET;
        unset($args['fflhub_gundeals_export'], $args['_wpnonce']);
        $args['page'] = self::PAGE_SLUG;
        $args['paged'] = max(1, $page_number);

        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * @return int[]
     */
    private function pagination_page_numbers(int $current, int $total_pages): array
    {
        if ($total_pages <= 7) {
            return range(1, $total_pages);
        }

        $pages = [1];
        $start = max(2, $current - 2);
        $end = min($total_pages - 1, $current + 2);

        if ($start > 2) {
            $pages[] = 0;
        }

        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }

        if ($end < $total_pages - 1) {
            $pages[] = 0;
        }

        $pages[] = $total_pages;

        return $pages;
    }

    /**
     * @param string[] $badges
     */
    private function render_badges(array $badges): void
    {
        if (empty($badges)) {
            echo '<span class="fflhub-gd-badge">No action</span>';
            return;
        }

        foreach ($badges as $badge) {
            $class = $badge === 'Needs Attention' || $badge === 'Unprofitable After Click Cost' || $badge === 'Missing Profit Audit'
                ? ' is-warning'
                : '';
            echo '<span class="fflhub-gd-badge' . esc_attr($class) . '">' . esc_html($badge) . '</span> ';
        }
    }

    private function money(float $amount): string
    {
        return '$' . number_format_i18n($amount, 2);
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $filters
     */
    private function maybe_export_csv(array $report, array $filters): void
    {
        if (!check_admin_referer('fflhub_gundeals_export')) {
            wp_die(esc_html__('Invalid export request.', 'ffl-hub'));
        }

        $advanced = !empty($filters['advanced']);
        $filename = 'gundeals-performance-' . gmdate('Ymd_His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $fh = fopen('php://output', 'wb');
        if (!is_resource($fh)) {
            exit;
        }

        $columns = $this->csv_columns($advanced);
        fputcsv($fh, array_values($columns));
        foreach ($report['rows'] as $row) {
            $out = [];
            foreach ($columns as $key => $label) {
                if ($key === 'badges') {
                    $out[] = implode('; ', $row['badges'] ?? []);
                } elseif (in_array($key, ['feed_included', 'ffl_required', 'sot_required', 'missing_profit_audit'], true)) {
                    $out[] = !empty($row[$key]) ? 'yes' : 'no';
                } else {
                    $out[] = $row[$key] ?? '';
                }
            }
            fputcsv($fh, $out);
        }
        fclose($fh);
        exit;
    }

    /**
     * @return array<string,string>
     */
    private function csv_columns(bool $advanced): array
    {
        $columns = [
            'badges' => 'recommendation_badge',
            'upc' => 'upc',
            'title' => 'product_title',
            'feed_included' => 'feed_included',
            'stock_status' => 'stock_status',
            'clicks' => 'clicks',
            'orders' => 'orders',
            'units' => 'units_sold',
            'conversion_rate' => 'conversion_rate',
            'revenue' => 'revenue',
            'gross_profit' => 'gross_profit',
            'allocated_cost' => 'allocated_gundeals_cost',
            'net_profit' => 'channel_net_after_allocated_gundeals_cost',
            'ffl_required' => 'ffl_required',
            'map_status' => 'map_quote_status',
            'customer_price' => 'customer_visible_price',
        ];

        if (!$advanced) {
            return $columns;
        }

        return array_merge($columns, [
            'product_id' => 'product_id',
            'raw_cumulative_clicks' => 'raw_cumulative_clicks',
            'period_clicks' => 'period_raw_clicks',
            'click_source' => 'click_source',
            'deduped_clicks' => 'deduped_clicks',
            'stock_quantity' => 'stock_quantity',
            'brand' => 'brand',
            'category' => 'category',
            'dealer_cost' => 'dealer_cost_from_profit_audit',
            'true_cost' => 'true_cost_from_profit_audit',
            'margin' => 'margin_from_profit_audit',
            'fees_adjustments' => 'fees_cost_adjustments_from_profit_audit',
            'sot_required' => 'sot_required',
            'last_order_date' => 'last_order_date',
            'page_views' => 'product_page_views',
            'add_to_cart_count' => 'add_to_cart_count',
            'email_quote_count' => 'email_quote_count',
            'source_attribution' => 'checkout_source_attribution',
            'cost_per_order' => 'cost_per_order',
            'cost_per_revenue_dollar' => 'cost_per_revenue_dollar',
            'click_cost_profit_percent' => 'click_cost_percent_of_gross_profit',
            'marginal_click_cost' => 'marginal_click_cost',
            'missing_profit_audit' => 'missing_profit_audit',
        ]);
    }

    private function render_styles(): void
    {
        ?>
        <style>
            .fflhub-gd-filters {
                display: flex;
                flex-wrap: wrap;
                gap: 10px 14px;
                align-items: end;
                padding: 12px;
                margin: 14px 0;
                background: #fff;
                border: 1px solid #dcdcde;
            }
            .fflhub-gd-filters label {
                display: flex;
                flex-direction: column;
                gap: 3px;
                font-size: 12px;
            }
            .fflhub-gd-filters input[type="number"] { width: 92px; }
            .fflhub-gd-snapshot { align-self: center; color: #646970; }
            .fflhub-gd-cards {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
                gap: 10px;
                margin: 14px 0;
            }
            .fflhub-gd-card {
                background: #fff;
                border: 1px solid #dcdcde;
                padding: 10px 12px;
            }
            .fflhub-gd-card span {
                display: block;
                color: #646970;
                font-size: 12px;
                margin-bottom: 4px;
            }
            .fflhub-gd-card strong {
                font-size: 18px;
            }
            .fflhub-gd-table {
                margin-top: 12px;
            }
            .fflhub-gd-pagination {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                margin: 12px 0;
            }
            .fflhub-gd-page-links {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 4px;
            }
            .fflhub-gd-page-gap {
                padding: 0 4px;
                color: #646970;
            }
            .fflhub-gd-table th,
            .fflhub-gd-table td {
                vertical-align: top;
            }
            .fflhub-gd-badge {
                display: inline-block;
                margin: 0 3px 3px 0;
                padding: 2px 6px;
                border-radius: 3px;
                background: #edf7ed;
                color: #1b5e20;
                font-size: 11px;
                white-space: nowrap;
            }
            .fflhub-gd-badge.is-warning {
                background: #fcf0f1;
                color: #8a2424;
            }
        </style>
        <?php
    }
}
