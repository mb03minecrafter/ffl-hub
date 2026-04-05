<?php
declare(strict_types=1);

namespace FFLHub\Admin\Orders;

use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds a fulfillment mode badge column to the WooCommerce order list.
 *
 * Modes:
 * - Drop Ship (all jobs are direct_ship_* lanes)
 * - Dealer Fulfilled (all jobs are dealer_fulfilled lane)
 * - Mixed (contains both drop-ship and dealer-fulfilled lanes)
 */
final class OrderFulfillmentModeBadge
{
    private const COLUMN_KEY = 'fflhub_fulfillment_mode';
    private const FILTER_QUERY_ARG = 'fflhub_fulfillment_mode_filter';

    private const MODE_DROP = 'drop_ship';
    private const MODE_DEALER = 'dealer_fulfilled';
    private const MODE_MIXED = 'mixed';
    private const MODE_UNKNOWN = 'unknown';

    private OrderPlacementJobsTable $jobs_table;

    /** @var array<int,string> */
    private array $mode_cache = [];

    public function __construct(OrderPlacementJobsTable $jobs_table)
    {
        $this->jobs_table = $jobs_table;
    }

    public function register(): void
    {
        // HPOS orders list.
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'inject_column_after_status'], 25);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render_column_hpos'], 20, 2);
        add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'render_hpos_filter']);
        add_filter('woocommerce_order_query_args', [$this, 'apply_hpos_filter'], 20);

        // Legacy CPT orders list.
        add_filter('manage_edit-shop_order_columns', [$this, 'inject_column_after_status'], 25);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_column_legacy'], 20, 2);
        add_action('restrict_manage_posts', [$this, 'render_legacy_filter']);
        add_action('pre_get_posts', [$this, 'apply_legacy_filter']);

        add_action('admin_head', [$this, 'render_admin_styles']);
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public function inject_column_after_status(array $columns): array
    {
        if (isset($columns[self::COLUMN_KEY])) {
            return $columns;
        }

        $new_columns = [];
        $inserted = false;

        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;

            if ($key === 'order_status') {
                $new_columns[self::COLUMN_KEY] = __('Fulfillment', 'ffl-hub');
                $inserted = true;
            }
        }

        if (!$inserted) {
            $new_columns[self::COLUMN_KEY] = __('Fulfillment', 'ffl-hub');
        }

        return $new_columns;
    }

    /**
     * HPOS callback.
     *
     * @param mixed $order
     */
    public function render_column_hpos(string $column_name, $order): void
    {
        if ($column_name !== self::COLUMN_KEY) {
            return;
        }

        if ($order instanceof WC_Order) {
            $this->print_badge_for_order_id((int) $order->get_id());
            return;
        }

        if (is_numeric($order)) {
            $this->print_badge_for_order_id((int) $order);
            return;
        }

        $this->print_badge_for_order_id(0);
    }

    /**
     * Legacy callback.
     */
    public function render_column_legacy(string $column_name, int $post_id = 0): void
    {
        if ($column_name !== self::COLUMN_KEY) {
            return;
        }

        $oid = (int) $post_id;
        if ($oid <= 0) {
            $oid = (int) get_the_ID();
        }

        if ($oid <= 0) {
            global $the_order;
            if ($the_order instanceof WC_Order) {
                $oid = (int) $the_order->get_id();
            }
        }

        $this->print_badge_for_order_id($oid);
    }

    public function render_admin_styles(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !isset($screen->id)) {
            return;
        }

        $id = (string) $screen->id;
        if ($id !== 'edit-shop_order' && strpos($id, 'wc-orders') === false) {
            return;
        }

        echo '<style>
            .column-' . self::COLUMN_KEY . ' { width: 170px; }
            select[name="' . self::FILTER_QUERY_ARG . '"] { min-width: 180px; }
            .fflhub-order-mode-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                border-radius: 999px;
                border: 1px solid #dcdcde;
                padding: 3px 8px;
                font-size: 12px;
                line-height: 1.2;
                white-space: nowrap;
                background: #fff;
            }
            .fflhub-order-mode-badge .dashicons {
                font-size: 15px;
                width: 15px;
                height: 15px;
            }
            .fflhub-order-mode-badge.is-drop { color: #055160; border-color: #7ed3ed; background: #eefcff; }
            .fflhub-order-mode-badge.is-dealer { color: #1e4d2b; border-color: #9bd3a7; background: #f2fff4; }
            .fflhub-order-mode-badge.is-mixed { color: #5c3c00; border-color: #f2cd79; background: #fff8eb; }
            .fflhub-order-mode-badge.is-unknown { color: #50575e; border-color: #dcdcde; background: #f6f7f7; }
        </style>';
    }

    public function render_hpos_filter(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !isset($screen->id) || strpos((string) $screen->id, 'wc-orders') === false) {
            return;
        }

        $this->render_filter_select();
    }

    /**
     * @param mixed $post_type
     */
    public function render_legacy_filter($post_type = ''): void
    {
        if ((string) $post_type !== 'shop_order') {
            return;
        }

        $this->render_filter_select();
    }

    /**
     * @param array<string,mixed> $query_args
     * @return array<string,mixed>
     */
    public function apply_hpos_filter(array $query_args): array
    {
        if (!is_admin()) {
            return $query_args;
        }

        $mode = $this->requested_filter_mode();
        if ($mode === '') {
            return $query_args;
        }

        $ids = $this->find_order_ids_for_mode($mode);
        // HPOS query path reliably maps "id" to an IN() clause.
        // Using "include" here is not consistently honored by the orders list table query builder.
        $query_args['id'] = $this->merge_includes($query_args['id'] ?? [], $ids);

        return $query_args;
    }

    public function apply_legacy_filter(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        global $pagenow;
        if ($pagenow !== 'edit.php') {
            return;
        }

        $post_type = (string) $query->get('post_type');
        if ($post_type !== 'shop_order') {
            return;
        }

        $mode = $this->requested_filter_mode();
        if ($mode === '') {
            return;
        }

        $ids = $this->find_order_ids_for_mode($mode);
        $query->set('post__in', $this->merge_includes($query->get('post__in'), $ids));
    }

    private function print_badge_for_order_id(int $order_id): void
    {
        $mode = $this->resolve_mode($order_id);

        if ($mode === self::MODE_DROP) {
            echo '<span class="fflhub-order-mode-badge is-drop" title="' . esc_attr__('Order lanes are all drop-ship.', 'ffl-hub') . '">'
                . '<span class="dashicons dashicons-migrate"></span>'
                . esc_html__('Drop Shipped', 'ffl-hub')
                . '</span>';
            return;
        }

        if ($mode === self::MODE_DEALER) {
            echo '<span class="fflhub-order-mode-badge is-dealer" title="' . esc_attr__('Order lanes are all dealer fulfilled.', 'ffl-hub') . '">'
                . '<span class="dashicons dashicons-store"></span>'
                . esc_html__('Dealer Fulfilled', 'ffl-hub')
                . '</span>';
            return;
        }

        if ($mode === self::MODE_MIXED) {
            echo '<span class="fflhub-order-mode-badge is-mixed" title="' . esc_attr__('Order has both drop-ship and dealer-fulfilled lanes.', 'ffl-hub') . '">'
                . '<span class="dashicons dashicons-randomize"></span>'
                . esc_html__('Mixed', 'ffl-hub')
                . '</span>';
            return;
        }

        echo '<span class="fflhub-order-mode-badge is-unknown" title="' . esc_attr__('No placement lane data found yet.', 'ffl-hub') . '">'
            . '<span class="dashicons dashicons-minus"></span>'
            . esc_html__('Pending', 'ffl-hub')
            . '</span>';
    }

    private function resolve_mode(int $order_id): string
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return self::MODE_UNKNOWN;
        }

        if (isset($this->mode_cache[$order_id])) {
            return $this->mode_cache[$order_id];
        }

        global $wpdb;
        $table = $this->jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            $this->mode_cache[$order_id] = self::MODE_UNKNOWN;
            return self::MODE_UNKNOWN;
        }

        $lanes = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT lane FROM {$table} WHERE order_id = %d",
                $order_id
            )
        );

        $has_drop = false;
        $has_dealer = false;
        $has_unknown = false;

        if (is_array($lanes)) {
            foreach ($lanes as $lane_raw) {
                $lane = OrderPlacementKeysUtil::normalize_lane((string) $lane_raw);
                if ($lane === '') {
                    continue;
                }

                if (
                    $lane === OrderPlacementKeysUtil::LANE_DIRECT_SHIP_FFL ||
                    $lane === OrderPlacementKeysUtil::LANE_DIRECT_SHIP_NON_FFL
                ) {
                    $has_drop = true;
                    continue;
                }

                if ($lane === OrderPlacementKeysUtil::LANE_DEALER_FULFILLED) {
                    $has_dealer = true;
                    continue;
                }

                $has_unknown = true;
            }
        }

        if ($has_drop && $has_dealer) {
            $mode = self::MODE_MIXED;
        } elseif ($has_drop && !$has_unknown) {
            $mode = self::MODE_DROP;
        } elseif ($has_dealer && !$has_unknown) {
            $mode = self::MODE_DEALER;
        } elseif ($has_drop || $has_dealer) {
            $mode = self::MODE_MIXED;
        } else {
            $mode = self::MODE_UNKNOWN;
        }

        $this->mode_cache[$order_id] = $mode;
        return $mode;
    }

    private function render_filter_select(): void
    {
        $selected = $this->requested_filter_mode();
        $opts = $this->filter_options();

        echo '<select name="' . esc_attr(self::FILTER_QUERY_ARG) . '">';
        foreach ($opts as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    /**
     * @return array<string,string>
     */
    private function filter_options(): array
    {
        return [
            '' => __('All Fulfillment Modes', 'ffl-hub'),
            self::MODE_DROP => __('Drop Shipped', 'ffl-hub'),
            self::MODE_DEALER => __('Dealer Fulfilled', 'ffl-hub'),
            self::MODE_MIXED => __('Mixed', 'ffl-hub'),
        ];
    }

    private function requested_filter_mode(): string
    {
        if (!isset($_GET[self::FILTER_QUERY_ARG])) {
            return '';
        }

        $raw = sanitize_text_field(wp_unslash((string) $_GET[self::FILTER_QUERY_ARG]));
        if (in_array($raw, [self::MODE_DROP, self::MODE_DEALER, self::MODE_MIXED], true)) {
            return $raw;
        }

        return '';
    }

    /**
     * @return int[]
     */
    private function find_order_ids_for_mode(string $mode): array
    {
        static $cache = [];
        if (isset($cache[$mode])) {
            return $cache[$mode];
        }

        global $wpdb;
        $table = $this->jobs_table->get_table_name();
        if (!is_string($table) || $table === '') {
            $cache[$mode] = [];
            return [];
        }

        $lane_non = OrderPlacementKeysUtil::LANE_DIRECT_SHIP_NON_FFL;
        $lane_ffl = OrderPlacementKeysUtil::LANE_DIRECT_SHIP_FFL;
        $lane_dealer = OrderPlacementKeysUtil::LANE_DEALER_FULFILLED;

        $condition = '';
        if ($mode === self::MODE_DROP) {
            $condition = '(stats.has_drop = 1 AND stats.has_dealer = 0 AND stats.has_unknown = 0)';
        } elseif ($mode === self::MODE_DEALER) {
            $condition = '(stats.has_dealer = 1 AND stats.has_drop = 0 AND stats.has_unknown = 0)';
        } elseif ($mode === self::MODE_MIXED) {
            $condition = '((stats.has_drop = 1 AND stats.has_dealer = 1) OR ((stats.has_drop = 1 OR stats.has_dealer = 1) AND stats.has_unknown = 1))';
        } else {
            $cache[$mode] = [];
            return [];
        }

        $sql = $wpdb->prepare(
            "
            SELECT stats.order_id
            FROM (
                SELECT
                    order_id,
                    MAX(CASE WHEN lane IN (%s, %s) THEN 1 ELSE 0 END) AS has_drop,
                    MAX(CASE WHEN lane = %s THEN 1 ELSE 0 END) AS has_dealer,
                    MAX(CASE WHEN lane <> '' AND lane NOT IN (%s, %s, %s) THEN 1 ELSE 0 END) AS has_unknown
                FROM {$table}
                GROUP BY order_id
            ) AS stats
            WHERE {$condition}
            ",
            $lane_non,
            $lane_ffl,
            $lane_dealer,
            $lane_non,
            $lane_ffl,
            $lane_dealer
        );

        $rows = $wpdb->get_col($sql);
        $ids = [];
        if (is_array($rows)) {
            foreach ($rows as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        $ids = array_values(array_unique($ids));
        $cache[$mode] = $ids;
        return $ids;
    }

    /**
     * @param mixed $existing
     * @param int[] $target
     * @return int[]
     */
    private function merge_includes($existing, array $target): array
    {
        $target = array_values(array_unique(array_map('intval', $target)));
        $target = array_values(array_filter($target, static fn(int $id): bool => $id > 0));
        if (empty($target)) {
            return [0];
        }

        $existing_ids = [];
        if (is_array($existing)) {
            $existing_ids = array_values(array_unique(array_map('intval', $existing)));
            $existing_ids = array_values(array_filter($existing_ids, static fn(int $id): bool => $id > 0));
        } elseif (is_numeric($existing)) {
            $eid = (int) $existing;
            if ($eid > 0) {
                $existing_ids = [$eid];
            }
        }

        if (empty($existing_ids)) {
            return $target;
        }

        $intersection = array_values(array_intersect($existing_ids, $target));
        return !empty($intersection) ? $intersection : [0];
    }
}
