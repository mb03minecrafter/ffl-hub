<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsSchema;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Shipping\Packing\OrderBoxPackingService;
use FFLHub\Shipping\ShippingOptions;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Historical packing audit for package sizes we do not own yet.
 *
 * This is intentionally read-only. It finds orders that have dealer-fulfilled
 * job rows, runs those orders against the "potential future boxes" preset list,
 * and aggregates which future box sizes BoxPacker would have selected most.
 */
final class ShippingPackageAuditPage
{
    private const NONCE_ACTION = 'fflhub_shipping_package_audit';
    private const NONCE_FIELD = 'fflhub_shipping_package_audit_nonce';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            ShippingAdminPage::MENU_SLUG,
            __('Package Audit', 'ffl-hub'),
            __('Package Audit', 'ffl-hub'),
            ShippingAdminPage::CAPABILITY,
            ShippingAdminPage::PACKAGE_AUDIT_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        ShippingAdminPage::ensure_access();
        $result = $this->maybe_run_audit();
        ?>
        <div class="wrap fflhub-shipping-package-audit">
            <?php ShippingAdminPage::render_styles(); ?>
            <?php $this->render_inline_styles(); ?>

            <h1><?php esc_html_e('Package Audit', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Run historical dealer-fulfilled orders against potential future boxes. This does not change orders, labels, package presets, or fulfillment routing.', 'ffl-hub'); ?>
            </p>

            <section class="fflhub-shipping-card">
                <div class="fflhub-shipping-card-head">
                    <div>
                        <h2><?php esc_html_e('Future Box Audit', 'ffl-hub'); ?></h2>
                        <p class="description">
                            <?php echo esc_html(sprintf(
                                __('Potential future boxes configured: %d.', 'ffl-hub'),
                                count(ShippingOptions::future_package_presets())
                            )); ?>
                        </p>
                    </div>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . ShippingAdminPage::PACKAGE_PRESETS_SLUG)); ?>">
                        <?php esc_html_e('Edit Package Presets', 'ffl-hub'); ?>
                    </a>
                </div>

                <form method="post" action="">
                    <input type="hidden" name="fflhub_shipping_package_audit_form" value="1" />
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                    <?php submit_button(__('Run Future Box Audit', 'ffl-hub'), 'primary', 'fflhub_run_package_audit', false); ?>
                </form>
            </section>

            <?php
            if (is_array($result)) {
                $this->render_result($result);
            }
            ?>
        </div>
        <?php
    }

    /**
     * @return array<string,mixed>|null
     */
    private function maybe_run_audit(): ?array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['fflhub_shipping_package_audit_form'])) {
            return null;
        }

        ShippingAdminPage::ensure_access();
        $nonce = isset($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD]))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(esc_html__('Security check failed. Please refresh and try again.', 'ffl-hub'));
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        return $this->run_audit();
    }

    /**
     * @return array<string,mixed>
     */
    private function run_audit(): array
    {
        $started = microtime(true);
        $future_boxes = ShippingOptions::future_package_presets();
        $order_ids = $this->dealer_fulfilled_order_ids();

        $stats = [
            'orders_found' => count($order_ids),
            'orders_loaded' => 0,
            'orders_with_dealer_units' => 0,
            'orders_fully_packed' => 0,
            'orders_with_unpacked_items' => 0,
            'orders_missing' => 0,
            'package_count' => 0,
            'packed_units' => 0,
            'unpacked_items' => 0,
            'errors' => 0,
        ];

        $box_stats = [];
        $problem_orders = [];

        if (empty($future_boxes)) {
            return [
                'stats' => $stats,
                'box_stats' => [],
                'problem_orders' => [],
                'runtime_ms' => $this->elapsed_ms($started),
                'error' => 'No potential future boxes are configured.',
            ];
        }

        $packer = new OrderBoxPackingService();

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!($order instanceof WC_Order)) {
                $stats['orders_missing']++;
                continue;
            }

            $stats['orders_loaded']++;
            $result = $packer->pack_dealer_fulfilled_order($order, $future_boxes);
            $dealer_units = max(0, (int) ($result['dealer_fulfilled_units'] ?? 0));
            if ($dealer_units <= 0) {
                continue;
            }

            $stats['orders_with_dealer_units']++;
            $stats['packed_units'] += max(0, (int) ($result['packed_units'] ?? 0));
            $stats['unpacked_items'] += max(0, (int) ($result['unpacked_item_count'] ?? 0));

            if (!empty($result['ok'])) {
                $stats['orders_fully_packed']++;
            }
            if ((int) ($result['unpacked_item_count'] ?? 0) > 0) {
                $stats['orders_with_unpacked_items']++;
            }
            if (!empty($result['errors'])) {
                $stats['errors'] += count((array) $result['errors']);
            }

            $boxes = isset($result['boxes']) && is_array($result['boxes']) ? $result['boxes'] : [];
            foreach ($boxes as $box) {
                if (!is_array($box)) {
                    continue;
                }

                $box_id = sanitize_key((string) ($box['box_id'] ?? ''));
                if ($box_id === '') {
                    continue;
                }

                if (!isset($box_stats[$box_id])) {
                    $box_stats[$box_id] = [
                        'box_id' => $box_id,
                        'box_name' => (string) ($box['box_name'] ?? $box_id),
                        'dimensions' => self::box_dimension_label($box),
                        'orders' => [],
                        'package_count' => 0,
                        'packed_units' => 0,
                        'total_weight_oz' => 0.0,
                        'total_volume_percent' => 0.0,
                        'volume_samples' => 0,
                    ];
                }

                $box_stats[$box_id]['orders'][(int) $order_id] = true;
                $box_stats[$box_id]['package_count']++;
                $stats['package_count']++;

                $box_units = 0;
                foreach ((array) ($box['items'] ?? []) as $item) {
                    $box_units += max(0, (int) ($item['quantity'] ?? 0));
                }
                $box_stats[$box_id]['packed_units'] += $box_units;

                $weight = self::positive_float($box['packed_weight_oz'] ?? null);
                if ($weight !== null) {
                    $box_stats[$box_id]['total_weight_oz'] += $weight;
                }

                $volume = self::positive_float($box['volume_utilization_percent'] ?? null);
                if ($volume !== null) {
                    $box_stats[$box_id]['total_volume_percent'] += $volume;
                    $box_stats[$box_id]['volume_samples']++;
                }
            }

            if (empty($result['ok']) || empty($boxes)) {
                $problem_orders[] = [
                    'order_id' => $order_id,
                    'dealer_units' => $dealer_units,
                    'packed_units' => (int) ($result['packed_units'] ?? 0),
                    'unpacked_count' => (int) ($result['unpacked_item_count'] ?? 0),
                    'errors' => (array) ($result['errors'] ?? []),
                ];
            }
        }

        $box_stats = array_values(array_map(static function (array $row): array {
            $orders = array_keys($row['orders']);
            rsort($orders, SORT_NUMERIC);
            $package_count = max(1, (int) $row['package_count']);
            $volume_samples = max(0, (int) $row['volume_samples']);

            return [
                'box_id' => $row['box_id'],
                'box_name' => $row['box_name'],
                'dimensions' => $row['dimensions'],
                'order_count' => count($orders),
                'package_count' => (int) $row['package_count'],
                'packed_units' => (int) $row['packed_units'],
                'avg_weight_oz' => round((float) $row['total_weight_oz'] / $package_count, 2),
                'avg_volume_percent' => $volume_samples > 0
                    ? round((float) $row['total_volume_percent'] / $volume_samples, 1)
                    : null,
                'example_orders' => array_slice($orders, 0, 8),
            ];
        }, $box_stats));

        usort($box_stats, static function (array $a, array $b): int {
            return ((int) $b['package_count'] <=> (int) $a['package_count'])
                ?: ((int) $b['order_count'] <=> (int) $a['order_count'])
                ?: strcmp((string) $a['box_name'], (string) $b['box_name']);
        });

        return [
            'stats' => $stats,
            'box_stats' => $box_stats,
            'problem_orders' => array_slice($problem_orders, 0, 50),
            'runtime_ms' => $this->elapsed_ms($started),
            'error' => '',
        ];
    }

    /**
     * @return int[]
     */
    private function dealer_fulfilled_order_ids(): array
    {
        global $wpdb;

        if (!$wpdb) {
            return [];
        }

        $jobs_table = new OrderPlacementJobsTable(new OrderPlacementJobsSchema());
        $table = $jobs_table->get_table_name();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!is_string($found) || $found !== $table) {
            return [];
        }

        $lane = OrderPlacementKeysUtil::LANE_DEALER_FULFILLED;
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT order_id FROM {$table} WHERE lane = %s ORDER BY order_id DESC",
                $lane
            )
        );

        return array_values(array_filter(array_map('absint', is_array($rows) ? $rows : [])));
    }

    /**
     * @param array<string,mixed> $result
     */
    private function render_result(array $result): void
    {
        $stats = isset($result['stats']) && is_array($result['stats']) ? $result['stats'] : [];
        $box_stats = isset($result['box_stats']) && is_array($result['box_stats']) ? $result['box_stats'] : [];
        $problem_orders = isset($result['problem_orders']) && is_array($result['problem_orders']) ? $result['problem_orders'] : [];

        echo '<section class="fflhub-shipping-card">';
        echo '<div class="fflhub-shipping-card-head">';
        echo '<div><h2>' . esc_html__('Audit Results', 'ffl-hub') . '</h2>';
        echo '<p class="description">' . esc_html(sprintf(
            __('Runtime: %s ms.', 'ffl-hub'),
            self::number_label((float) ($result['runtime_ms'] ?? 0))
        )) . '</p></div>';
        echo '</div>';

        if (!empty($result['error'])) {
            echo '<div class="notice notice-error inline"><p>' . esc_html((string) $result['error']) . '</p></div>';
        }

        $this->render_stats_grid($stats);
        $this->render_box_stats_table($box_stats);
        $this->render_problem_orders_table($problem_orders);
        echo '</section>';
    }

    /**
     * @param array<string,mixed> $stats
     */
    private function render_stats_grid(array $stats): void
    {
        $cards = [
            'Orders found' => (int) ($stats['orders_found'] ?? 0),
            'Orders loaded' => (int) ($stats['orders_loaded'] ?? 0),
            'Dealer orders' => (int) ($stats['orders_with_dealer_units'] ?? 0),
            'Fully packed' => (int) ($stats['orders_fully_packed'] ?? 0),
            'Future packages' => (int) ($stats['package_count'] ?? 0),
            'Packed units' => (int) ($stats['packed_units'] ?? 0),
            'Unpacked items' => (int) ($stats['unpacked_items'] ?? 0),
            'Errors' => (int) ($stats['errors'] ?? 0),
        ];

        echo '<div class="fflhub-shipping-grid">';
        foreach ($cards as $label => $value) {
            echo '<div class="fflhub-shipping-stat"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) $value) . '</strong></div>';
        }
        echo '</div>';
    }

    /**
     * @param array<int,mixed> $rows
     */
    private function render_box_stats_table(array $rows): void
    {
        echo '<h3>' . esc_html__('Most Used Potential Future Boxes', 'ffl-hub') . '</h3>';
        if (empty($rows)) {
            echo '<div class="fflhub-shipping-empty">' . esc_html__('No future boxes were selected by the audit.', 'ffl-hub') . '</div>';
            return;
        }

        echo '<table class="widefat striped fflhub-shipping-audit-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Box', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('Dimensions', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('Orders', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('Packages', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('Units', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('Avg Volume', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('Avg Weight', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('Example Orders', 'ffl-hub') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            echo '<tr>';
            echo '<td><strong>' . esc_html((string) ($row['box_name'] ?? 'Box')) . '</strong><br><code>' . esc_html((string) ($row['box_id'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ($row['dimensions'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) (int) ($row['order_count'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) (int) ($row['package_count'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) (int) ($row['packed_units'] ?? 0)) . '</td>';
            echo '<td>' . esc_html($row['avg_volume_percent'] === null ? 'n/a' : self::number_label((float) $row['avg_volume_percent']) . '%') . '</td>';
            echo '<td>' . esc_html(self::number_label((float) ($row['avg_weight_oz'] ?? 0)) . ' oz') . '</td>';
            echo '<td>' . $this->order_links((array) ($row['example_orders'] ?? [])) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<int,mixed> $rows
     */
    private function render_problem_orders_table(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        echo '<h3>' . esc_html__('Orders Needing Attention', 'ffl-hub') . '</h3>';
        echo '<p class="description">' . esc_html__('Showing up to 50 orders that had unpacked items, no selected future box, or BoxPacker errors.', 'ffl-hub') . '</p>';
        echo '<table class="widefat striped fflhub-shipping-audit-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Order', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('Dealer Units', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('Packed Units', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('Unpacked', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('Errors', 'ffl-hub') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            echo '<tr>';
            echo '<td>' . $this->order_links([(int) ($row['order_id'] ?? 0)]) . '</td>';
            echo '<td>' . esc_html((string) (int) ($row['dealer_units'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) (int) ($row['packed_units'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) (int) ($row['unpacked_count'] ?? 0)) . '</td>';
            echo '<td>' . esc_html(implode('; ', array_map('strval', (array) ($row['errors'] ?? [])))) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param int[] $order_ids
     */
    private function order_links(array $order_ids): string
    {
        $links = [];
        foreach ($order_ids as $order_id) {
            $order_id = absint($order_id);
            if ($order_id <= 0) {
                continue;
            }

            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
            $label = '#' . (string) $order_id;
            $url = admin_url('post.php?post=' . $order_id . '&action=edit');
            if ($order instanceof WC_Order) {
                $label = '#' . (string) $order->get_order_number();
                $url = $order->get_edit_order_url();
            }

            $links[] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }

        return implode(', ', $links);
    }

    private function elapsed_ms(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 2);
    }

    /**
     * @param mixed $value
     */
    private static function positive_float($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;
        return $float > 0.0 ? $float : null;
    }

    /**
     * @param array<string,mixed> $box
     */
    private static function box_dimension_label(array $box): string
    {
        $length = self::positive_float($box['outer_length_in'] ?? null);
        $width = self::positive_float($box['outer_width_in'] ?? null);
        $height = self::positive_float($box['outer_height_in'] ?? null);
        if ($length === null || $width === null || $height === null) {
            return '';
        }

        return self::number_label($length) . ' x ' . self::number_label($width) . ' x ' . self::number_label($height) . ' in';
    }

    private static function number_label(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function render_inline_styles(): void
    {
        ?>
        <style>
            .fflhub-shipping-package-audit .fflhub-shipping-card-head{align-items:center}
            .fflhub-shipping-audit-table td,.fflhub-shipping-audit-table th{vertical-align:top}
            .fflhub-shipping-empty{padding:12px;border:1px dashed #c3c4c7;border-radius:6px;background:#f6f7f7;color:#646970}
        </style>
        <?php
    }
}
