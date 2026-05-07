<?php

namespace FFLHub\Admin\Orders;

use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

final class OrderCartComplianceMetaBox
{
    private const META_BOX_ID = 'fflhub_order_cart_compliance';
    private const META_BOX_TITLE = 'FFL Hub - Cart Compliance';

    private const META_LAST_CHECKED = 'fflhub_cart_compliance_last_checked_at';
    private const META_BLOCKED = 'fflhub_cart_compliance_blocked';
    private const META_BLOCK_COUNT = 'fflhub_cart_compliance_block_count';
    private const META_BLOCKS = 'fflhub_cart_compliance_blocks';
    private const META_CONTEXT = 'fflhub_cart_compliance_context';
    private const META_HISTORY = 'fflhub_cart_compliance_history';
    private const META_EXCEPTION = 'fflhub_cart_compliance_exception';

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

        $css_rel_path = 'assets/css/admin-order-cart-compliance.css';
        $css_abs_path = FFLHUB_PLUGIN_PATH . $css_rel_path;
        $css_version = file_exists($css_abs_path) ? (string) filemtime($css_abs_path) : FFLHUB_PLUGIN_VERSION;

        wp_enqueue_style(
            'fflhub-order-cart-compliance',
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
            echo '<div class="fflhub-compliance-empty">Order not available.</div>';
            return;
        }

        $last_checked = trim((string) $order->get_meta(self::META_LAST_CHECKED, true));
        $blocks = self::json_array($order->get_meta(self::META_BLOCKS, true));
        $context = self::json_array($order->get_meta(self::META_CONTEXT, true));
        $history = self::json_array($order->get_meta(self::META_HISTORY, true));
        $exception = self::json_array($order->get_meta(self::META_EXCEPTION, true));

        $block_count = (int) $order->get_meta(self::META_BLOCK_COUNT, true);
        if ($block_count <= 0 && $blocks) {
            $block_count = count($blocks);
        }

        $blocked = self::truthy($order->get_meta(self::META_BLOCKED, true)) || $block_count > 0;
        $has_exception = !empty($exception);
        $has_any_meta = $last_checked !== '' || $blocks || $context || $history || $exception;

        if (!$has_any_meta) {
            echo '<div class="fflhub-compliance-empty">No cart compliance check has been saved for this order yet.</div>';
            return;
        }

        $status_class = $has_exception ? 'is-exception' : ($blocked ? 'is-blocked' : 'is-passed');
        $status_label = $has_exception ? 'Exception Saved' : ($blocked ? 'Blocked' : 'Passed');
        $status_caption = $blocked
            ? sprintf('%d current block%s saved', $block_count, $block_count === 1 ? '' : 's')
            : 'No current compliance blocks saved';

        $cart = isset($context['cart']) && is_array($context['cart']) ? $context['cart'] : [];
        $latest_history = array_reverse(array_slice(array_values($history), -5));

        echo '<div class="fflhub-compliance-wrap">';
        echo '<div class="fflhub-compliance-hero ' . esc_attr($status_class) . '">';
        echo '<div>';
        echo '<div class="fflhub-compliance-eyebrow">Latest Result</div>';
        echo '<div class="fflhub-compliance-title">' . esc_html($status_label) . '</div>';
        echo '<div class="fflhub-compliance-subtitle">' . esc_html($status_caption) . '</div>';
        echo '</div>';
        echo '<div class="fflhub-compliance-checked">';
        echo '<span>Checked</span>';
        echo '<strong>' . esc_html(self::format_datetime($last_checked)) . '</strong>';
        echo '</div>';
        echo '</div>';

        echo '<div class="fflhub-compliance-summary-grid">';
        echo self::summary_tile('Run', self::text($context['run_id'] ?? ''));
        echo self::summary_tile('Customer Ship-To', self::state_zip($context['customer_state'] ?? '', $context['customer_zip'] ?? ''));
        echo self::summary_tile('Receiving FFL', self::ffl_summary($context));
        echo self::summary_tile('Cart Lines', (string) count($cart));
        echo '</div>';

        echo self::render_cart_snapshot($cart);
        echo self::render_blocks($blocks, $blocked);
        echo self::render_exception($exception);
        echo self::render_history($latest_history);
        echo self::render_raw_meta($order);
        echo '</div>';
    }

    /**
     * @param array<int,array<string,mixed>> $cart
     */
    private static function render_cart_snapshot(array $cart): string
    {
        if (!$cart) {
            return self::panel('Cart Snapshot', '<div class="fflhub-compliance-muted">No cart snapshot was saved with this check.</div>');
        }

        $html = '<div class="fflhub-compliance-table-scroll"><table class="fflhub-compliance-table">';
        $html .= '<thead><tr><th>Product</th><th>UPC / Distributor</th><th>Qty / Stock</th><th>Flags</th></tr></thead><tbody>';

        foreach ($cart as $row) {
            if (!is_array($row)) {
                continue;
            }

            $product_id = (int) ($row['product_id'] ?? 0);
            $variation_id = (int) ($row['variation_id'] ?? 0);
            $product_bits = '#' . $product_id;
            if ($variation_id > 0) {
                $product_bits .= ' / var #' . $variation_id;
            }

            $stock_qty = array_key_exists('stock_qty', $row) && $row['stock_qty'] !== null
                ? (string) $row['stock_qty']
                : '-';

            $html .= '<tr>';
            $html .= '<td><strong>' . esc_html(self::text($row['name'] ?? 'Product')) . '</strong><span>' . esc_html($product_bits) . '</span></td>';
            $html .= '<td><strong>' . esc_html(self::text($row['upc'] ?? '-')) . '</strong><span>' . esc_html(strtoupper(self::text($row['dist'] ?? '-'))) . '</span></td>';
            $html .= '<td><strong>' . esc_html((string) ($row['qty'] ?? '-')) . '</strong><span>' . esc_html(self::text($row['stock_status'] ?? '-') . ' / qty ' . $stock_qty) . '</span></td>';
            $html .= '<td><span class="fflhub-compliance-tag">FFL ' . esc_html(self::yes_no($row['ffl_required'] ?? 0)) . '</span><span class="fflhub-compliance-tag">Local ' . esc_html(self::yes_no($row['local_stock_override'] ?? 0)) . '</span></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        return self::panel('Cart Snapshot', $html);
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     */
    private static function render_blocks(array $blocks, bool $blocked): string
    {
        if (!$blocks) {
            $message = $blocked
                ? 'The order is marked blocked, but no current block payload was saved.'
                : 'No current block payload is saved for this order.';
            return self::panel('Current Blocks', '<div class="fflhub-compliance-passline">' . esc_html($message) . '</div>');
        }

        $html = '<div class="fflhub-compliance-blocks">';
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $label = self::text($block['label'] ?? 'Compliance block');
            $lane = self::text($block['lane'] ?? '');
            $message = self::text($block['message'] ?? '');
            $codes = isset($block['codes']) && is_array($block['codes']) ? $block['codes'] : [];
            $pretty = isset($block['pretty']) && is_array($block['pretty']) ? $block['pretty'] : [];
            $details = isset($block['details']) && is_array($block['details']) ? $block['details'] : [];

            $html .= '<div class="fflhub-compliance-block">';
            $html .= '<div class="fflhub-compliance-block-head"><strong>' . esc_html($label) . '</strong>';
            if ($lane !== '') {
                $html .= '<span class="fflhub-compliance-lane">' . esc_html($lane) . '</span>';
            }
            $html .= '</div>';

            if ($message !== '') {
                $html .= '<div class="fflhub-compliance-message">' . esc_html($message) . '</div>';
            }

            if ($pretty) {
                $html .= '<ul class="fflhub-compliance-pretty">';
                foreach ($pretty as $pretty_message) {
                    $html .= '<li>' . esc_html(self::text($pretty_message)) . '</li>';
                }
                $html .= '</ul>';
            }

            if ($codes) {
                $html .= '<div class="fflhub-compliance-tags">';
                foreach ($codes as $code) {
                    $html .= '<span class="fflhub-compliance-code">' . esc_html(self::text($code)) . '</span>';
                }
                $html .= '</div>';
            }

            if ($details) {
                $html .= self::details_json('Validation details', $details);
            }

            $html .= '</div>';
        }
        $html .= '</div>';

        return self::panel('Current Blocks', $html);
    }

    /**
     * @param array<string,mixed> $exception
     */
    private static function render_exception(array $exception): string
    {
        if (!$exception) {
            return '';
        }

        $summary = '<div class="fflhub-compliance-exception">';
        $summary .= '<strong>' . esc_html(self::text($exception['class'] ?? 'Exception')) . '</strong>';
        $summary .= '<span>' . esc_html(self::text($exception['message'] ?? 'No exception message saved.')) . '</span>';
        $summary .= '</div>';
        $summary .= self::details_json('Exception payload', $exception);

        return self::panel('Saved Exception', $summary);
    }

    /**
     * @param array<int,array<string,mixed>> $history
     */
    private static function render_history(array $history): string
    {
        if (!$history) {
            return self::panel('Recent Block History', '<div class="fflhub-compliance-muted">No block history has been saved yet.</div>');
        }

        $html = '<div class="fflhub-compliance-history">';
        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $blocks = isset($entry['blocks']) && is_array($entry['blocks']) ? $entry['blocks'] : [];
            $messages = [];
            foreach (array_slice($blocks, 0, 2) as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $messages[] = self::text($block['label'] ?? $block['message'] ?? 'Block');
            }

            $html .= '<div class="fflhub-compliance-history-row">';
            $html .= '<strong>' . esc_html(self::format_datetime((string) ($entry['checked_at_utc'] ?? ''))) . '</strong>';
            $html .= '<span>' . esc_html((string) ((int) ($entry['block_count'] ?? count($blocks))) . ' block(s) - run ' . self::text($entry['run_id'] ?? '')) . '</span>';
            if ($messages) {
                $html .= '<em>' . esc_html(implode('; ', $messages)) . '</em>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        return self::panel('Recent Block History', $html);
    }

    private static function render_raw_meta(WC_Order $order): string
    {
        $rows = [
            self::META_LAST_CHECKED,
            self::META_BLOCKED,
            self::META_BLOCK_COUNT,
            self::META_BLOCKS,
            self::META_CONTEXT,
            self::META_HISTORY,
            self::META_EXCEPTION,
        ];

        $html = '<div class="fflhub-compliance-raw-list">';
        foreach ($rows as $key) {
            $value = $order->get_meta($key, true);
            $html .= '<details><summary>' . esc_html($key) . '</summary><pre>' . esc_html(self::raw_value($value)) . '</pre></details>';
        }
        $html .= '</div>';

        return self::panel('Raw Saved Meta', $html);
    }

    private static function panel(string $title, string $html): string
    {
        return '<div class="fflhub-compliance-panel"><h4>' . esc_html($title) . '</h4>' . $html . '</div>';
    }

    private static function summary_tile(string $label, string $value): string
    {
        if ($value === '') {
            $value = '-';
        }

        return '<div class="fflhub-compliance-tile"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong></div>';
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function ffl_summary(array $context): string
    {
        $present = self::truthy($context['receiving_ffl_present'] ?? 0) ? 'Yes' : 'No';
        $tail = self::text($context['receiving_ffl_tail4'] ?? '');
        $state_zip = self::state_zip($context['ffl_state'] ?? '', $context['ffl_zip'] ?? '');

        if ($tail !== '') {
            $present .= ' - *' . $tail;
        }

        if ($state_zip !== '-') {
            $present .= ' - ' . $state_zip;
        }

        return $present;
    }

    /**
     * @param mixed $state
     * @param mixed $zip
     */
    private static function state_zip($state, $zip): string
    {
        $state = self::text($state);
        $zip = self::text($zip);

        if ($state === '' && $zip === '') {
            return '-';
        }

        if ($state === '') {
            return $zip;
        }

        if ($zip === '') {
            return $state;
        }

        return $state . ' ' . $zip;
    }

    /**
     * @param mixed $value
     */
    private static function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value > 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
    }

    /**
     * @param mixed $value
     */
    private static function yes_no($value): string
    {
        return self::truthy($value) ? 'Yes' : 'No';
    }

    private static function format_datetime(string $utc): string
    {
        $utc = trim($utc);
        if ($utc === '') {
            return 'Never';
        }

        $timestamp = strtotime($utc . ' UTC');
        if (!$timestamp) {
            return $utc;
        }

        return $utc . ' UTC / ' . wp_date('M j, Y g:i a T', $timestamp);
    }

    /**
     * @param mixed $value
     */
    private static function text($value): string
    {
        $text = trim((string) $value);
        $text = preg_replace('/\s+/', ' ', $text);

        return is_string($text) ? $text : '';
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

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $value
     */
    private static function raw_value($value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return self::pretty_json($decoded);
            }

            return $value;
        }

        return self::pretty_json($value);
    }

    /**
     * @param mixed $value
     */
    private static function pretty_json($value): string
    {
        $json = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '';
    }

    /**
     * @param mixed $value
     */
    private static function details_json(string $summary, $value): string
    {
        return '<details class="fflhub-compliance-json"><summary>' . esc_html($summary) . '</summary><pre>' . esc_html(self::pretty_json($value)) . '</pre></details>';
    }

    /**
     * @param mixed $post_or_order
     */
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

        $request_id = isset($_GET['id']) ? absint(wp_unslash($_GET['id'])) : 0;
        if ($request_id > 0) {
            $order = wc_get_order($request_id);
            return $order instanceof WC_Order ? $order : null;
        }

        return null;
    }
}
