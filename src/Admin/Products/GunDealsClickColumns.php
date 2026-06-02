<?php

namespace FFLHub\Admin\Products;

use FFLHub\Feeds\GunDeals\GunDealsAnalyticsStore;

if (!defined('ABSPATH')) {
    exit;
}

final class GunDealsClickColumns
{
    private const COL_GUNDEALS_CLICKS = 'fflhub_gundeals_clicks';
    private const SORT_GUNDEALS_CLICKS = 'fflhub_gundeals_clicks';
    private const SORT_QUERY_FLAG = 'fflhub_gundeals_click_sort';
    private const SORT_TABLE_ALIAS = 'fflhub_gundeals_click_sort_totals';

    /** @var array<int,array<string,mixed>>|null */
    private ?array $click_totals_cache = null;

    public function register(): void
    {
        add_filter('manage_edit-product_columns', [$this, 'inject_columns'], 30);
        add_filter('manage_edit-product_sortable_columns', [$this, 'register_sortable_columns']);
        add_action('manage_product_posts_custom_column', [$this, 'render_column'], 30, 2);
        add_action('pre_get_posts', [$this, 'apply_sorting']);
        add_action('admin_head', [$this, 'render_admin_styles']);
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public function inject_columns(array $columns): array
    {
        if (isset($columns[self::COL_GUNDEALS_CLICKS])) {
            return $columns;
        }

        $new_columns = [];
        $inserted = false;

        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;

            if ($key === 'price') {
                $new_columns[self::COL_GUNDEALS_CLICKS] = __('Gun.deals Clicks', 'ffl-hub');
                $inserted = true;
            }
        }

        if (!$inserted) {
            $new_columns[self::COL_GUNDEALS_CLICKS] = __('Gun.deals Clicks', 'ffl-hub');
        }

        return $new_columns;
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public function register_sortable_columns(array $columns): array
    {
        $columns[self::COL_GUNDEALS_CLICKS] = self::SORT_GUNDEALS_CLICKS;
        return $columns;
    }

    public function render_column(string $column_name, int $post_id): void
    {
        if ($column_name !== self::COL_GUNDEALS_CLICKS || $post_id <= 0) {
            return;
        }

        $totals = $this->click_totals_for_current_page();
        $row = $totals[$post_id] ?? [];
        $raw = max(0, (int) ($row['raw_clicks'] ?? 0));
        $last_click = trim((string) ($row['last_click_at_gmt'] ?? ''));

        if ($raw <= 0) {
            echo '<span class="fflhub-product-empty">&mdash;</span>';
            return;
        }

        echo '<span class="fflhub-product-pill is-gundeals-clicks" title="'
            . esc_attr__('Raw Gun.deals clicks.', 'ffl-hub')
            . '">'
            . esc_html((string) $raw)
            . '</span>';

        if ($last_click !== '') {
            echo '<br><span class="fflhub-gundeals-last-click">'
                . esc_html__('Last:', 'ffl-hub')
                . ' '
                . esc_html($last_click)
                . ' UTC</span>';
        }
    }

    public function render_admin_styles(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !isset($screen->id) || (string) $screen->id !== 'edit-product') {
            return;
        }

        echo '<style>
            .column-' . self::COL_GUNDEALS_CLICKS . ' { width: 145px; }
            .fflhub-product-pill.is-gundeals-clicks {
                color: #3b2f00;
                border-color: #e6ca76;
                background: #fff8db;
            }
            .fflhub-gundeals-last-click {
                display: inline-block;
                margin-top: 4px;
                color: #646970;
                font-size: 11px;
                line-height: 1.3;
            }
        </style>';
    }

    public function apply_sorting(\WP_Query $query): void
    {
        if (!$this->is_product_list_query($query)) {
            return;
        }

        if ((string) $query->get('orderby') !== self::SORT_GUNDEALS_CLICKS) {
            return;
        }

        $query->set(self::SORT_QUERY_FLAG, '1');
        add_filter('posts_clauses', [$this, 'apply_click_sort_clauses'], 20, 2);
    }

    /**
     * Sort by raw Gun.deals clicks without hiding products that have no clicks yet.
     *
     * @param array<string,string> $clauses
     * @return array<string,string>
     */
    public function apply_click_sort_clauses(array $clauses, \WP_Query $query): array
    {
        if ((string) $query->get(self::SORT_QUERY_FLAG) !== '1') {
            return $clauses;
        }

        global $wpdb;

        GunDealsAnalyticsStore::ensure_schema();

        $alias = self::SORT_TABLE_ALIAS;
        $join = " LEFT JOIN " . GunDealsAnalyticsStore::click_totals_table() . " AS {$alias}"
            . " ON ({$wpdb->posts}.ID = {$alias}.product_id) ";

        if (strpos((string) ($clauses['join'] ?? ''), " AS {$alias}") === false) {
            $clauses['join'] = (string) ($clauses['join'] ?? '') . $join;
        }

        $order = strtoupper((string) $query->get('order')) === 'ASC' ? 'ASC' : 'DESC';
        $clicks_expr = "COALESCE({$alias}.raw_clicks, 0)";
        $clauses['orderby'] = "{$clicks_expr} {$order}, {$wpdb->posts}.post_title ASC";

        return $clauses;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function click_totals_for_current_page(): array
    {
        if (is_array($this->click_totals_cache)) {
            return $this->click_totals_cache;
        }

        global $wpdb, $wp_query;
        $this->click_totals_cache = [];
        if (!$wpdb || !($wp_query instanceof \WP_Query) || empty($wp_query->posts)) {
            return $this->click_totals_cache;
        }

        $ids = [];
        foreach ($wp_query->posts as $post) {
            $post_id = is_object($post) && isset($post->ID) ? (int) $post->ID : (int) $post;
            if ($post_id > 0) {
                $ids[$post_id] = $post_id;
            }
        }

        if (empty($ids)) {
            return $this->click_totals_cache;
        }

        GunDealsAnalyticsStore::ensure_schema();
        $rows = $wpdb->get_results(
            'SELECT product_id, raw_clicks, deduped_clicks, last_click_at_gmt
             FROM ' . GunDealsAnalyticsStore::click_totals_table() . '
             WHERE product_id IN (' . implode(',', array_values($ids)) . ')',
            ARRAY_A
        );

        foreach (is_array($rows) ? $rows : [] as $row) {
            $product_id = (int) ($row['product_id'] ?? 0);
            if ($product_id > 0) {
                $this->click_totals_cache[$product_id] = $row;
            }
        }

        return $this->click_totals_cache;
    }

    private function is_product_list_query(\WP_Query $query): bool
    {
        if (!is_admin() || !$query->is_main_query()) {
            return false;
        }

        global $pagenow;
        if ($pagenow !== 'edit.php') {
            return false;
        }

        return (string) $query->get('post_type') === 'product';
    }
}
