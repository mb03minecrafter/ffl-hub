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

        // Legacy CPT orders list.
        add_filter('manage_edit-shop_order_columns', [$this, 'inject_column_after_status'], 25);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_column_legacy'], 20, 2);

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
}
