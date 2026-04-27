<?php

namespace FFLHub\Admin\Orders;

use FFLHub\Order\OrderProfitAuditMeta;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

final class OrderProfitAuditMetaBox
{
    private const META_BOX_ID = 'fflhub_order_profit_audit';
    private const META_BOX_TITLE = 'FFL Hub - Profit Cockpit';

    public function register(): void
    {
        add_action('add_meta_boxes_shop_order', [$this, 'register_metabox']);
        add_action('add_meta_boxes_woocommerce_page_wc-orders', [$this, 'register_metabox']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_metabox(): void
    {
        add_meta_box(
            self::META_BOX_ID,
            self::META_BOX_TITLE,
            [$this, 'render_metabox'],
            null,
            'normal',
            'high'
        );
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }

        $is_order_screen =
            ($screen->id === 'shop_order') ||
            ($screen->id === 'woocommerce_page_wc-orders') ||
            ($screen->post_type === 'shop_order');

        if (!$is_order_screen) {
            return;
        }

        $css_rel_path = 'assets/css/admin-order-profit-audit.css';
        $css_abs_path = FFLHUB_PLUGIN_PATH . $css_rel_path;
        $css_version = file_exists($css_abs_path) ? (string) filemtime($css_abs_path) : FFLHUB_PLUGIN_VERSION;

        wp_enqueue_style(
            'fflhub-order-profit-audit',
            plugins_url($css_rel_path, FFLHUB_PLUGIN_FILE),
            [],
            $css_version
        );
    }

    /**
     * @param mixed $post_or_order WP_Post|WC_Order|order-like object depending on screen.
     */
    public function render_metabox($post_or_order): void
    {
        $order = self::resolve_order($post_or_order);
        if (!($order instanceof WC_Order)) {
            echo '<div class="fflhub-profit-empty">Order not available.</div>';
            return;
        }

        $audit = self::read_saved_audit($order);
        $is_saved = true;
        if (empty($audit['version'])) {
            $audit = OrderProfitAuditMeta::preview_order($order);
            $audit['version'] = '';
            $is_saved = false;
        }

        $profit = self::float_value($audit['actual_profit_total'] ?? 0);
        $profit_class = $profit >= 0 ? 'is-positive' : 'is-negative';
        $profit_label = $profit >= 0 ? 'Net profit' : 'Net loss';
        $processor_percent = self::float_value($audit['processor_fee_percent'] ?? 0);
        $customer_shipping = self::float_value($audit['customer_shipping_charge'] ?? 0);
        $label_cost = self::float_value($audit['shipping_label_cost_total'] ?? 0);
        $lines = isset($audit['lines']) && is_array($audit['lines']) ? $audit['lines'] : [];
        $by_dist = isset($audit['item_cost_by_dist']) && is_array($audit['item_cost_by_dist']) ? $audit['item_cost_by_dist'] : [];
        $shipping_caption = $label_cost > 0.0
            ? 'Bought Woo labels only; customer paid ' . wp_strip_all_tags(self::money($customer_shipping, $order))
            : 'No bought labels; customer paid ' . wp_strip_all_tags(self::money($customer_shipping, $order));

        echo '<div class="fflhub-profit-wrap">';

        echo '<div class="fflhub-profit-hero ' . esc_attr($profit_class) . '">';
        echo '<div>';
        echo '<div class="fflhub-profit-eyebrow">Order Profit Snapshot</div>';
        echo '<div class="fflhub-profit-title">' . esc_html($profit_label) . '</div>';
        echo '<div class="fflhub-profit-subtitle">Revenue minus distributor item cost, bought shipping labels, and processor fee.</div>';
        if (!$is_saved) {
            echo '<div class="fflhub-profit-note">Preview only. Run the backfill command to save this snapshot on older orders.</div>';
        }
        echo '</div>';
        echo '<div class="fflhub-profit-net">' . self::money($profit, $order) . '</div>';
        echo '</div>';

        echo '<div class="fflhub-profit-metrics">';
        echo self::metric_card('Revenue', self::money(self::float_value($audit['revenue_total'] ?? 0), $order), 'Includes customer shipping; excludes tax', 'revenue');
        echo self::metric_card('Item Cost', self::money(self::float_value($audit['item_cost_total'] ?? 0), $order), 'Distributor unit cost x qty', 'cost');
        echo self::metric_card('Shipping Cost', self::money(self::float_value($audit['shipping_cost_total'] ?? 0), $order), $shipping_caption, 'shipping');
        echo self::metric_card('Processor Fee', self::money(self::float_value($audit['processor_fee_amount'] ?? 0), $order), self::percent($processor_percent) . ' of order total', 'fee');
        echo '</div>';

        echo '<div class="fflhub-profit-grid">';
        echo '<section class="fflhub-profit-panel">';
        echo '<div class="fflhub-profit-panel-head">';
        echo '<h4>Distributor Spend</h4>';
        echo '<span>Where the product cost landed</span>';
        echo '</div>';
        if (empty($by_dist)) {
            echo '<div class="fflhub-profit-empty">No distributor cost data found yet.</div>';
        } else {
            echo '<div class="fflhub-profit-dist-stack">';
            foreach ($by_dist as $dist) {
                if (!is_array($dist)) {
                    continue;
                }

                $dist_id = strtoupper((string) ($dist['dist_id'] ?? 'unknown'));
                $qty = (int) ($dist['qty'] ?? 0);
                $line_count = (int) ($dist['line_count'] ?? 0);
                $cost = self::float_value($dist['item_cost'] ?? 0);

                echo '<div class="fflhub-profit-dist-chip">';
                echo '<div class="fflhub-profit-dist-id">' . esc_html($dist_id) . '</div>';
                echo '<div class="fflhub-profit-dist-cost">' . self::money($cost, $order) . '</div>';
                echo '<div class="fflhub-profit-dist-meta">' . esc_html(sprintf('%d line(s), %d item(s)', $line_count, $qty)) . '</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</section>';

        echo '<section class="fflhub-profit-panel">';
        echo '<div class="fflhub-profit-panel-head">';
        echo '<h4>Formula</h4>';
        echo '<span>The little money machine</span>';
        echo '</div>';
        echo '<div class="fflhub-profit-formula">';
        echo self::formula_step('Revenue', self::money(self::float_value($audit['revenue_total'] ?? 0), $order), 'plus');
        echo self::formula_step('Item cost', '-' . self::money_abs(self::float_value($audit['item_cost_total'] ?? 0), $order), 'minus');
        echo self::formula_step('Shipping', '-' . self::money_abs(self::float_value($audit['shipping_cost_total'] ?? 0), $order), 'minus');
        echo self::formula_step('Processor', '-' . self::money_abs(self::float_value($audit['processor_fee_amount'] ?? 0), $order), 'minus');
        echo self::formula_step('Actual profit', self::money($profit, $order), $profit >= 0 ? 'result-good' : 'result-bad');
        echo '</div>';
        echo '</section>';
        echo '</div>';

        self::render_shipping_label_panel($audit, $order);

        echo '<section class="fflhub-profit-panel fflhub-profit-lines-panel">';
        echo '<div class="fflhub-profit-panel-head">';
        echo '<h4>Line Audit</h4>';
        echo '<span>Source distributor and cost captured per item</span>';
        echo '</div>';

        if (empty($lines)) {
            echo '<div class="fflhub-profit-empty">No line-level audit rows found.</div>';
        } else {
            echo '<div class="fflhub-profit-table-wrap">';
            echo '<table class="fflhub-profit-table">';
            echo '<thead><tr>';
            echo '<th>Product</th><th>SKU</th><th>Dist</th><th>Qty</th><th>Unit Cost</th><th>Line Cost</th><th>Revenue</th>';
            echo '</tr></thead><tbody>';
            foreach ($lines as $line) {
                if (!is_array($line)) {
                    continue;
                }

                $name = (string) ($line['name'] ?? '');
                $sku = (string) ($line['sku'] ?? '');
                $dist = strtoupper((string) ($line['source_distributor'] ?? 'unknown'));
                $qty = (int) ($line['qty'] ?? 0);
                $unit_cost = self::float_value($line['distributor_unit_cost'] ?? 0);
                $line_cost = self::float_value($line['distributor_line_cost'] ?? 0);
                $line_revenue = self::float_value($line['line_revenue'] ?? 0);

                echo '<tr>';
                echo '<td><strong>' . esc_html($name !== '' ? $name : '-') . '</strong></td>';
                echo '<td><code>' . esc_html($sku !== '' ? $sku : '-') . '</code></td>';
                echo '<td><span class="fflhub-profit-mini-pill">' . esc_html($dist) . '</span></td>';
                echo '<td>' . esc_html((string) $qty) . '</td>';
                echo '<td>' . self::money($unit_cost, $order) . '</td>';
                echo '<td>' . self::money($line_cost, $order) . '</td>';
                echo '<td>' . self::money($line_revenue, $order) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        }
        echo '</section>';

        echo '</div>';
    }

    /**
     * @return array<string,mixed>
     */
    private static function read_saved_audit(WC_Order $order): array
    {
        return [
            'version' => (string) $order->get_meta('fflhub_order_profit_audit_version', true),
            'order_total' => self::float_value($order->get_meta('fflhub_order_total', true)),
            'tax_total' => self::float_value($order->get_meta('fflhub_order_tax_total', true)),
            'revenue_total' => self::float_value($order->get_meta('fflhub_order_revenue_total', true)),
            'item_cost_total' => self::float_value($order->get_meta('fflhub_order_item_cost_total', true)),
            'item_cost_by_dist' => self::json_array($order->get_meta('fflhub_order_item_cost_by_dist', true)),
            'shipping_cost_total' => self::float_value($order->get_meta('fflhub_order_shipping_cost_total', true)),
            'shipping_label_cost_total' => self::float_value($order->get_meta('fflhub_order_shipping_label_cost_total', true)),
            'shipping_label_count' => (int) $order->get_meta('fflhub_order_shipping_label_count', true),
            'shipping_label_source' => (string) $order->get_meta('fflhub_order_shipping_label_source', true),
            'shipping_label_lines' => self::json_array($order->get_meta('fflhub_order_shipping_label_lines', true)),
            'customer_shipping_charge' => self::float_value($order->get_meta('fflhub_order_customer_shipping_charge', true)),
            'processor_fee_percent' => self::float_value($order->get_meta('fflhub_order_processor_fee_percent', true)),
            'processor_fee_amount' => self::float_value($order->get_meta('fflhub_order_processor_fee_amount', true)),
            'actual_profit_total' => self::float_value($order->get_meta('fflhub_order_actual_profit_total', true)),
            'lines' => self::json_array($order->get_meta('fflhub_order_profit_lines', true)),
        ];
    }

    /**
     * @param array<string,mixed> $audit
     */
    private static function render_shipping_label_panel(array $audit, WC_Order $order): void
    {
        $label_total = self::float_value($audit['shipping_label_cost_total'] ?? 0);
        $label_count = (int) ($audit['shipping_label_count'] ?? 0);
        $label_source = trim((string) ($audit['shipping_label_source'] ?? ''));
        $label_lines = isset($audit['shipping_label_lines']) && is_array($audit['shipping_label_lines'])
            ? $audit['shipping_label_lines']
            : [];

        echo '<section class="fflhub-profit-panel fflhub-profit-label-panel">';
        echo '<div class="fflhub-profit-panel-head">';
        echo '<h4>Shipping Labels</h4>';
        echo '<span>Bought WooCommerce label cost only</span>';
        echo '</div>';

        echo '<div class="fflhub-profit-label-summary">';
        echo self::label_stat('Bought Labels', self::money($label_total, $order), 'Actual purchased label cost');
        echo self::label_stat('Label Count', esc_html((string) $label_count), 'Non-refunded purchased labels');
        echo self::label_stat('Source', esc_html($label_source !== '' ? $label_source : 'none yet'), 'Where label data was read');
        echo '</div>';

        if ($label_total <= 0.0 || empty($label_lines)) {
            echo '<div class="fflhub-profit-empty">No purchased WooCommerce Shipping labels found. Shipping cost is stored as $0.00 for this order.</div>';
            echo '</section>';
            return;
        }

        echo '<div class="fflhub-profit-table-wrap">';
        echo '<table class="fflhub-profit-table">';
        echo '<thead><tr>';
        echo '<th>Label</th><th>Cost</th><th>Service</th><th>Status</th><th>Tracking</th><th>Source</th>';
        echo '</tr></thead><tbody>';

        foreach ($label_lines as $label) {
            if (!is_array($label)) {
                continue;
            }

            $label_id = (string) ($label['label_id'] ?? '');
            $service = trim((string) (($label['carrier_id'] ?? '') . ' ' . ($label['service_name'] ?? '')));
            $status = (string) ($label['status'] ?? '');
            $tracking = (string) ($label['tracking'] ?? '');
            $source = (string) ($label['source'] ?? '');
            $cost = self::float_value($label['cost'] ?? 0);

            echo '<tr>';
            echo '<td><code>' . esc_html($label_id !== '' ? $label_id : '-') . '</code></td>';
            echo '<td>' . self::money($cost, $order) . '</td>';
            echo '<td>' . esc_html($service !== '' ? $service : '-') . '</td>';
            echo '<td><span class="fflhub-profit-mini-pill">' . esc_html($status !== '' ? $status : 'purchased') . '</span></td>';
            echo '<td><code>' . esc_html($tracking !== '' ? $tracking : '-') . '</code></td>';
            echo '<td>' . esc_html($source !== '' ? $source : '-') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
    }

    private static function metric_card(string $label, string $value_html, string $caption, string $tone): string
    {
        return '<div class="fflhub-profit-metric is-' . esc_attr($tone) . '">'
            . '<div class="fflhub-profit-metric-label">' . esc_html($label) . '</div>'
            . '<div class="fflhub-profit-metric-value">' . $value_html . '</div>'
            . '<div class="fflhub-profit-metric-caption">' . esc_html($caption) . '</div>'
            . '</div>';
    }

    private static function formula_step(string $label, string $value_html, string $tone): string
    {
        return '<div class="fflhub-profit-formula-step is-' . esc_attr($tone) . '">'
            . '<span>' . esc_html($label) . '</span>'
            . '<strong>' . $value_html . '</strong>'
            . '</div>';
    }

    private static function label_stat(string $label, string $value_html, string $caption): string
    {
        return '<div class="fflhub-profit-label-stat">'
            . '<span>' . esc_html($label) . '</span>'
            . '<strong>' . $value_html . '</strong>'
            . '<em>' . esc_html($caption) . '</em>'
            . '</div>';
    }

    private static function resolve_order($post_or_order): ?WC_Order
    {
        if ($post_or_order instanceof WC_Order) {
            return $post_or_order;
        }

        if (is_object($post_or_order) && isset($post_or_order->ID)) {
            $order = wc_get_order((int) $post_or_order->ID);
            return $order instanceof WC_Order ? $order : null;
        }

        if (is_numeric($post_or_order)) {
            $order = wc_get_order((int) $post_or_order);
            return $order instanceof WC_Order ? $order : null;
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private static function float_value($value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param mixed $value
     * @return array<int|string,mixed>
     */
    private static function json_array($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function money(float $amount, WC_Order $order): string
    {
        return wp_kses_post(wc_price($amount, ['currency' => $order->get_currency()]));
    }

    private static function money_abs(float $amount, WC_Order $order): string
    {
        return self::money(abs($amount), $order);
    }

    private static function percent(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.') . '%';
    }
}
