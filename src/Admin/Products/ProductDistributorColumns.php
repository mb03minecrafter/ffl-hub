<?php
declare(strict_types=1);

namespace FFLHub\Admin\Products;

use FFLHub\Distributor\Core\DistributorRegistry;
use FFLHub\Product\State\ProductStateStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds distributor-focused columns to the WooCommerce product list table.
 */
final class ProductDistributorColumns
{
    private const COL_DIST_PRICE = 'fflhub_distributor_price';
    private const COL_DROPSHIP = 'fflhub_dropship_status';
    private const COL_SHIPPING = 'fflhub_shipping_cost';
    private const COL_DISTRIBUTOR = 'fflhub_distributor';
    private const FILTER_DISTRIBUTOR_ARG = 'fflhub_product_distributor_filter';
    private const FILTER_DROPSHIP_ARG = 'fflhub_product_dropship_filter';

    private const DROPSHIP_FILTER_ENABLED = 'enabled';
    private const DROPSHIP_FILTER_DISABLED = 'disabled';
    private const STATE_JOIN_ALIAS = 'fflhub_product_state_columns';

    public function register(): void
    {
        add_filter('manage_edit-product_columns', [$this, 'inject_columns'], 25);
        add_action('manage_product_posts_custom_column', [$this, 'render_column'], 20, 2);
        add_action('restrict_manage_posts', [$this, 'render_filter_controls']);
        add_filter('posts_clauses', [$this, 'apply_list_filter_clauses'], 20, 2);
        add_action('admin_head', [$this, 'render_admin_styles']);
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public function inject_columns(array $columns): array
    {
        if (isset($columns[self::COL_DIST_PRICE])) {
            return $columns;
        }

        $new_columns = [];
        $inserted = false;

        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;

            if ($key === 'price') {
                $this->append_custom_columns($new_columns);
                $inserted = true;
            }
        }

        if (!$inserted) {
            $this->append_custom_columns($new_columns);
        }

        return $new_columns;
    }

    public function render_column(string $column_name, int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }

        if ($column_name === self::COL_DIST_PRICE) {
            $this->render_money_state($post_id, 'dealer_price', 'is-price', 'dashicons-tag');
            return;
        }

        if ($column_name === self::COL_SHIPPING) {
            $this->render_money_state($post_id, 'shipping_cost', 'is-ship', 'dashicons-admin-site-alt3');
            return;
        }

        if ($column_name === self::COL_DROPSHIP) {
            $this->render_dropship_badge($post_id);
            return;
        }

        if ($column_name === self::COL_DISTRIBUTOR) {
            $this->render_distributor_label($post_id);
        }
    }

    public function render_admin_styles(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !isset($screen->id) || (string) $screen->id !== 'edit-product') {
            return;
        }

        echo '<style>
            .column-' . self::COL_DIST_PRICE . ', .column-' . self::COL_SHIPPING . ' { width: 120px; }
            .column-' . self::COL_DROPSHIP . ' { width: 160px; }
            .column-' . self::COL_DISTRIBUTOR . ' { width: 120px; }
            select[name="' . self::FILTER_DISTRIBUTOR_ARG . '"], select[name="' . self::FILTER_DROPSHIP_ARG . '"] { min-width: 170px; }
            .fflhub-product-empty { color: #8c8f94; }
            .fflhub-product-pill {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                border-radius: 999px;
                border: 1px solid #dcdcde;
                padding: 3px 8px;
                font-size: 12px;
                line-height: 1.2;
                white-space: nowrap;
                background: #fff;
            }
            .fflhub-product-pill .dashicons {
                font-size: 15px;
                width: 15px;
                height: 15px;
            }
            .fflhub-product-pill.is-on { color: #1e4d2b; border-color: #9bd3a7; background: #f2fff4; }
            .fflhub-product-pill.is-off { color: #6f1d1b; border-color: #f3b3b0; background: #fff2f2; }
            .fflhub-product-pill.is-unknown { color: #50575e; border-color: #dcdcde; background: #f6f7f7; }
            .fflhub-product-pill.is-dist { color: #1e3a5f; border-color: #b9d3ef; background: #f1f7ff; }
            .fflhub-product-pill.is-price { color: #17324a; border-color: #9fc2df; background: #eaf4ff; }
            .fflhub-product-pill.is-ship { color: #0f4c45; border-color: #93d8cd; background: #ebfdf8; }
        </style>';
    }

    /**
     * @param mixed $post_type
     */
    public function render_filter_controls($post_type = ''): void
    {
        if ((string) $post_type !== 'product') {
            return;
        }

        $selected_dist = $this->requested_distributor_filter();
        $selected_drop = $this->requested_dropship_filter();

        $dist_options = $this->distributor_filter_options();
        $drop_options = $this->dropship_filter_options();

        echo '<select name="' . esc_attr(self::FILTER_DISTRIBUTOR_ARG) . '">';
        foreach ($dist_options as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($selected_dist, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';

        echo '<select name="' . esc_attr(self::FILTER_DROPSHIP_ARG) . '">';
        foreach ($drop_options as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($selected_drop, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    /**
     * @param array<string,string> $clauses
     * @return array<string,string>
     */
    public function apply_list_filter_clauses(array $clauses, \WP_Query $query): array
    {
        if (!is_admin() || !$query->is_main_query()) {
            return $clauses;
        }

        global $pagenow;
        if ($pagenow !== 'edit.php') {
            return $clauses;
        }

        $post_type = (string) $query->get('post_type');
        if ($post_type !== 'product') {
            return $clauses;
        }

        $dist_filter = $this->requested_distributor_filter();
        $drop_filter = $this->requested_dropship_filter();

        if ($dist_filter === '' && $drop_filter === '') {
            return $clauses;
        }

        global $wpdb;
        if (!$wpdb) {
            return $clauses;
        }

        $alias = self::STATE_JOIN_ALIAS;
        $table = $this->sql_table_name(ProductStateStore::table_name());
        $posts_table = $this->sql_table_name($wpdb->posts);

        if (strpos((string) ($clauses['join'] ?? ''), " {$alias} ") === false) {
            $clauses['join'] = (string) ($clauses['join'] ?? '')
                . " INNER JOIN {$table} AS {$alias} ON {$alias}.product_id = {$posts_table}.ID";
        }

        $where = [];
        if ($dist_filter !== '') {
            $where[] = $wpdb->prepare("{$alias}.distributor_id = %s", $dist_filter);
        }

        if ($drop_filter === self::DROPSHIP_FILTER_ENABLED) {
            $where[] = "{$alias}.dropship_enabled = 1";
        } elseif ($drop_filter === self::DROPSHIP_FILTER_DISABLED) {
            $where[] = "{$alias}.dropship_enabled = 0";
        }

        if (!empty($where)) {
            $clauses['where'] = (string) ($clauses['where'] ?? '') . ' AND ' . implode(' AND ', $where);
        }

        return $clauses;
    }

    /**
     * @param array<string,string> $columns
     */
    private function append_custom_columns(array &$columns): void
    {
        $columns[self::COL_DIST_PRICE] = __('Distributor Price', 'ffl-hub');
        $columns[self::COL_SHIPPING] = __('Ship Cost', 'ffl-hub');
        $columns[self::COL_DROPSHIP] = __('Drop Ship', 'ffl-hub');
        $columns[self::COL_DISTRIBUTOR] = __('Distributor', 'ffl-hub');
    }

    private function render_money_state(int $post_id, string $column, string $pill_class, string $icon_class): void
    {
        $row = $this->product_state_row($post_id);
        $raw = is_array($row) ? ($row[$column] ?? null) : null;
        if ($raw === '' || $raw === null || !is_numeric($raw)) {
            echo '<span class="fflhub-product-empty">&mdash;</span>';
            return;
        }

        $value = (float) $raw;
        $money_html = '';
        if (function_exists('wc_price')) {
            $money_html = (string) wc_price($value);
        } else {
            $money_html = '$' . number_format($value, 2, '.', ',');
        }

        echo '<span class="fflhub-product-pill ' . esc_attr($pill_class) . '">'
            . '<span class="dashicons ' . esc_attr($icon_class) . '"></span>'
            . wp_kses_post($money_html)
            . '</span>';
    }

    private function render_dropship_badge(int $post_id): void
    {
        $row = $this->product_state_row($post_id);
        if (!is_array($row) || !array_key_exists('dropship_enabled', $row)) {
            echo '<span class="fflhub-product-pill is-unknown" title="' . esc_attr__('Drop-ship status has not been set yet.', 'ffl-hub') . '">'
                . '<span class="dashicons dashicons-minus"></span>'
                . esc_html__('Unknown', 'ffl-hub')
                . '</span>';
            return;
        }

        $is_enabled = ((int) ($row['dropship_enabled'] ?? 0) === 1);
        if ($is_enabled) {
            echo '<span class="fflhub-product-pill is-on" title="' . esc_attr__('Drop-ship is enabled for this product.', 'ffl-hub') . '">'
                . '<span class="dashicons dashicons-yes-alt"></span>'
                . esc_html__('Enabled', 'ffl-hub')
                . '</span>';
            return;
        }

        echo '<span class="fflhub-product-pill is-off" title="' . esc_attr__('Drop-ship is disabled for this product.', 'ffl-hub') . '">'
            . '<span class="dashicons dashicons-dismiss"></span>'
            . esc_html__('Disabled', 'ffl-hub')
            . '</span>';
    }

    private function render_distributor_label(int $post_id): void
    {
        $row = $this->product_state_row($post_id);
        $raw = is_array($row) ? trim((string) ($row['distributor_id'] ?? '')) : '';
        if ($raw === '') {
            echo '<span class="fflhub-product-empty">&mdash;</span>';
            return;
        }

        $label = $this->normalize_distributor_label($raw);
        echo '<span class="fflhub-product-pill is-dist" title="' . esc_attr__('Primary distributor on this product.', 'ffl-hub') . '">'
            . esc_html($label)
            . '</span>';
    }

    private function normalize_distributor_label(string $raw): string
    {
        $normalized = strtolower(trim($raw));
        if ($normalized === '') {
            return '';
        }

        $module = DistributorRegistry::get_module_by_id($normalized);
        if ($module !== null) {
            return $module->label();
        }

        return strtoupper($normalized);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function product_state_row(int $post_id): ?array
    {
        $row = ProductStateStore::get_row_for_product_id($post_id);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,string>
     */
    private function distributor_filter_options(): array
    {
        $options = ['' => __('All Distributors', 'ffl-hub')];

        foreach (DistributorRegistry::get_modules() as $module) {
            $id = strtolower(trim((string) $module->id()));
            if ($id === '') {
                continue;
            }

            $options[$id] = (string) $module->label();
        }

        return $options;
    }

    /**
     * @return array<string,string>
     */
    private function dropship_filter_options(): array
    {
        return [
            '' => __('All Drop Ship Statuses', 'ffl-hub'),
            self::DROPSHIP_FILTER_ENABLED => __('Drop Ship Enabled', 'ffl-hub'),
            self::DROPSHIP_FILTER_DISABLED => __('Drop Ship Disabled', 'ffl-hub'),
        ];
    }

    private function requested_distributor_filter(): string
    {
        if (!isset($_GET[self::FILTER_DISTRIBUTOR_ARG])) {
            return '';
        }

        $raw = strtolower(trim(sanitize_text_field(wp_unslash((string) $_GET[self::FILTER_DISTRIBUTOR_ARG]))));
        if ($raw === '') {
            return '';
        }

        $options = $this->distributor_filter_options();
        return isset($options[$raw]) ? $raw : '';
    }

    private function requested_dropship_filter(): string
    {
        if (!isset($_GET[self::FILTER_DROPSHIP_ARG])) {
            return '';
        }

        $raw = strtolower(trim(sanitize_text_field(wp_unslash((string) $_GET[self::FILTER_DROPSHIP_ARG]))));
        if (in_array($raw, [self::DROPSHIP_FILTER_ENABLED, self::DROPSHIP_FILTER_DISABLED], true)) {
            return $raw;
        }

        return '';
    }

    private function sql_table_name(string $table): string
    {
        return '`' . str_replace('`', '``', $table) . '`';
    }
}
