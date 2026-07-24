<?php
declare(strict_types=1);

namespace FFLHub\Admin\Orders;

use FFLHub\Admin\Pages\ShippingAdminPage;
use FFLHub\Shipping\Packing\OrderBoxPackingService;
use FFLHub\Shipping\ShippingOptions;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only order admin tester for the dealer-fulfilled box-packing service.
 *
 * This UI intentionally does not create labels, update order meta, or change
 * fulfillment routing. It only lets us choose candidate box presets and inspect
 * what the packing algorithm would do for the dealer-fulfilled part of an order.
 */
final class OrderBoxPackingMetaBox
{
    private const META_BOX_ID = 'fflhub_box_packing_test';
    private const ACTION = 'fflhub_test_order_box_packing';
    private const NONCE_ACTION_PREFIX = 'fflhub_test_order_box_packing_';
    private const TRANSIENT_PREFIX = 'fflhub_box_packing_result_';

    public function register(): void
    {
        add_action('add_meta_boxes_shop_order', [$this, 'register_metabox']);
        add_action('add_meta_boxes_woocommerce_page_wc-orders', [$this, 'register_metabox']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_' . self::ACTION, [$this, 'handle_post']);
        add_action('wp_ajax_' . self::ACTION, [$this, 'handle_ajax']);
    }

    public function register_metabox(): void
    {
        add_meta_box(
            self::META_BOX_ID,
            __('FFL Hub - Box Packing Test', 'ffl-hub'),
            [$this, 'render_metabox'],
            null,
            'normal',
            'default'
        );
    }

    public function enqueue_assets(): void
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

        wp_register_style('fflhub-box-packing-order', false, [], FFLHUB_PLUGIN_VERSION);
        wp_enqueue_style('fflhub-box-packing-order');
        wp_add_inline_style('fflhub-box-packing-order', $this->inline_css());

        $js_rel_path = 'assets/js/fflhub-box-packing-order.js';
        $js_abs_path = FFLHUB_PLUGIN_PATH . $js_rel_path;
        wp_enqueue_script(
            'fflhub-box-packing-order',
            plugins_url($js_rel_path, FFLHUB_PLUGIN_FILE),
            [],
            file_exists($js_abs_path) ? (string) filemtime($js_abs_path) : FFLHUB_PLUGIN_VERSION,
            true
        );
        wp_localize_script('fflhub-box-packing-order', 'FFLHubBoxPacking', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::ACTION,
            'runningText' => __('Packing...', 'ffl-hub'),
            'buttonText' => __('Run Box Packing Test', 'ffl-hub'),
            'errorText' => __('Box packing test failed.', 'ffl-hub'),
        ]);
    }

    /**
     * @param mixed $post_or_order WP_Post|WC_Order depending on screen.
     */
    public function render_metabox($post_or_order): void
    {
        $order = self::resolve_order($post_or_order);
        if (!($order instanceof WC_Order)) {
            echo '<div class="fflhub-box-pack-empty">' . esc_html__('Order not available.', 'ffl-hub') . '</div>';
            return;
        }

        $order_id = (int) $order->get_id();
        $result = $this->consume_result($order_id);
        $selected_ids = is_array($result) && isset($result['selected_box_ids']) && is_array($result['selected_box_ids'])
            ? array_map('strval', $result['selected_box_ids'])
            : [];
        $presets = $this->box_presets_for_ui();
        $checked_ids = !empty($selected_ids)
            ? array_fill_keys($selected_ids, true)
            : $this->default_checked_ids($presets);

        echo '<div class="fflhub-box-pack-panel">';
        echo '<p class="description">' . esc_html__('Test how BoxPacker would split only dealer-fulfilled order items across the selected box presets. Envelopes are hidden for now.', 'ffl-hub') . '</p>';

        echo '<div class="fflhub-box-pack-result-slot" data-fflhub-box-pack-result aria-live="polite">';
        if (!empty($result)) {
            $this->render_result($result);
        }
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-fflhub-box-pack-form>';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
        echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order_id) . '" />';
        echo '<input type="hidden" name="redirect_to" value="' . esc_attr($this->current_url()) . '" />';
        wp_nonce_field(self::NONCE_ACTION_PREFIX . $order_id, 'fflhub_box_packing_nonce');

        echo '<div class="fflhub-box-pack-title-row">';
        echo '<h4>' . esc_html__('Candidate Boxes', 'ffl-hub') . '</h4>';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=' . ShippingAdminPage::PACKAGE_PRESETS_SLUG)) . '">' . esc_html__('Edit Package Presets', 'ffl-hub') . '</a>';
        echo '</div>';

        $this->render_box_checklist($presets, $checked_ids);

        echo '<p class="fflhub-box-pack-actions">';
        submit_button(__('Run Box Packing Test', 'ffl-hub'), 'primary', 'submit', false);
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }

    public function handle_post(): void
    {
        if (!self::can_manage()) {
            wp_die(esc_html__('You do not have permission to test box packing.', 'ffl-hub'));
        }

        $order_id = $this->posted_order_id();
        if (!$this->posted_request_is_valid($order_id)) {
            wp_die(esc_html__('Security check failed. Please refresh and try again.', 'ffl-hub'));
        }

        $result = $this->build_packing_result($order_id, $this->posted_selected_box_ids());
        set_transient($this->result_transient_key($order_id), $result, 10 * MINUTE_IN_SECONDS);

        $redirect = isset($_POST['redirect_to'])
            ? esc_url_raw(wp_unslash((string) $_POST['redirect_to']))
            : '';
        if ($redirect === '') {
            $redirect = admin_url('post.php?post=' . $order_id . '&action=edit');
        }

        wp_safe_redirect(add_query_arg('fflhub_box_packing_test', '1', $redirect));
        exit;
    }

    public function handle_ajax(): void
    {
        if (!self::can_manage()) {
            wp_send_json_error(['message' => __('You do not have permission to test box packing.', 'ffl-hub')], 403);
        }

        $order_id = $this->posted_order_id();
        if (!$this->posted_request_is_valid($order_id)) {
            wp_send_json_error(['message' => __('Security check failed. Please refresh and try again.', 'ffl-hub')], 403);
        }

        $result = $this->build_packing_result($order_id, $this->posted_selected_box_ids());

        ob_start();
        $this->render_result($result);
        $html = (string) ob_get_clean();

        wp_send_json_success([
            'html' => $html,
            'ok' => !empty($result['ok']),
        ]);
    }

    /**
     * @param string[] $selected_ids
     * @return array<string,mixed>
     */
    private function build_packing_result(int $order_id, array $selected_ids): array
    {
        $order = wc_get_order($order_id);
        $boxes = $this->selected_box_rows($selected_ids);
        $ran_at = current_time('mysql');

        if (!($order instanceof WC_Order)) {
            return [
                'ok' => false,
                'order_id' => $order_id,
                'selected_box_ids' => $selected_ids,
                'errors' => ['Order not found.'],
                'boxes' => [],
                'unpacked_items' => [],
                'ignored_items' => [],
                'dealer_fulfilled_units' => 0,
                'packed_units' => 0,
                'ran_at' => $ran_at,
            ];
        }

        if (empty($boxes)) {
            return [
                'ok' => false,
                'order_id' => $order_id,
                'selected_box_ids' => $selected_ids,
                'errors' => ['Select at least one package preset that has length, width, and height.'],
                'boxes' => [],
                'unpacked_items' => [],
                'ignored_items' => [],
                'dealer_fulfilled_units' => 0,
                'packed_units' => 0,
                'ran_at' => $ran_at,
            ];
        }

        $result = (new OrderBoxPackingService())->pack_dealer_fulfilled_order($order, $boxes);
        $result['selected_box_ids'] = $selected_ids;
        $result['ran_at'] = $ran_at;

        return $result;
    }

    private function posted_order_id(): int
    {
        return isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    }

    private function posted_request_is_valid(int $order_id): bool
    {
        if ($order_id <= 0) {
            return false;
        }

        $nonce = isset($_POST['fflhub_box_packing_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_box_packing_nonce']))
            : '';

        return $nonce !== '' && wp_verify_nonce($nonce, self::NONCE_ACTION_PREFIX . $order_id);
    }

    /**
     * @return string[]
     */
    private function posted_selected_box_ids(): array
    {
        $posted = isset($_POST['box_ids']) && is_array($_POST['box_ids'])
            ? (array) wp_unslash($_POST['box_ids'])
            : [];

        $selected_ids = [];
        foreach ($posted as $id) {
            $id = sanitize_key((string) $id);
            if ($id !== '') {
                $selected_ids[] = $id;
            }
        }

        return array_values(array_unique($selected_ids));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function box_presets_for_ui(): array
    {
        $rows = [];
        foreach (ShippingOptions::package_presets() as $preset) {
            if (!is_array($preset)) {
                continue;
            }

            $kind = strtolower(trim((string) ($preset['kind'] ?? 'package')));
            $name = trim((string) ($preset['name'] ?? ''));
            $code = strtolower(trim((string) ($preset['package_code'] ?? 'package')));
            if ($kind !== 'package' || strpos($code, 'envelope') !== false || stripos($name, 'envelope') !== false) {
                continue;
            }

            $preset['eligible_for_packing'] = (
                self::positive_float($preset['length'] ?? null) !== null &&
                self::positive_float($preset['width'] ?? null) !== null &&
                self::positive_float($preset['height'] ?? null) !== null
            );
            $rows[] = $preset;
        }

        return $rows;
    }

    /**
     * @param string[] $selected_ids
     * @return array<int,array<string,mixed>>
     */
    private function selected_box_rows(array $selected_ids): array
    {
        if (empty($selected_ids)) {
            return [];
        }

        $selected = array_fill_keys($selected_ids, true);
        $rows = [];
        foreach ($this->box_presets_for_ui() as $preset) {
            $id = sanitize_key((string) ($preset['id'] ?? ''));
            if ($id === '' || empty($selected[$id]) || empty($preset['eligible_for_packing'])) {
                continue;
            }

            $rows[] = $preset;
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $presets
     * @return array<string,bool>
     */
    private function default_checked_ids(array $presets): array
    {
        $ids = [];
        foreach ($presets as $preset) {
            $id = sanitize_key((string) ($preset['id'] ?? ''));
            if ($id !== '' && !empty($preset['eligible_for_packing'])) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @param array<int,array<string,mixed>> $presets
     * @param array<string,bool> $checked_ids
     */
    private function render_box_checklist(array $presets, array $checked_ids): void
    {
        if (empty($presets)) {
            echo '<div class="fflhub-box-pack-empty">' . esc_html__('No package presets found. Add box presets under FFLHub Shipping > Package Presets.', 'ffl-hub') . '</div>';
            return;
        }

        echo '<div class="fflhub-box-pack-checklist">';
        foreach ($presets as $preset) {
            $id = sanitize_key((string) ($preset['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $name = trim((string) ($preset['name'] ?? $id));
            $code = trim((string) ($preset['package_code'] ?? 'package'));
            $length = self::positive_float($preset['length'] ?? null);
            $width = self::positive_float($preset['width'] ?? null);
            $height = self::positive_float($preset['height'] ?? null);
            $weight = self::positive_float($preset['weight_oz'] ?? null);
            $eligible = !empty($preset['eligible_for_packing']);
            $dims = $eligible
                ? self::number_label((float) $length) . ' x ' . self::number_label((float) $width) . ' x ' . self::number_label((float) $height) . ' in'
                : __('Missing dimensions', 'ffl-hub');
            $weight_label = $weight !== null
                ? self::number_label($weight) . ' oz package'
                : __('No package weight', 'ffl-hub');

            echo '<label class="fflhub-box-pack-choice ' . ($eligible ? '' : 'is-disabled') . '">';
            echo '<input type="checkbox" name="box_ids[]" value="' . esc_attr($id) . '" ' . checked(!empty($checked_ids[$id]), true, false) . ' ' . disabled(!$eligible, true, false) . ' />';
            echo '<span class="fflhub-box-pack-choice-main">';
            echo '<strong>' . esc_html($name) . '</strong>';
            echo '<span>' . esc_html($dims) . '</span>';
            echo '</span>';
            echo '<span class="fflhub-box-pack-choice-meta">';
            echo '<code>' . esc_html($code) . '</code>';
            echo '<span>' . esc_html($weight_label) . '</span>';
            echo '</span>';
            echo '</label>';
        }
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $result
     */
    private function render_result(array $result): void
    {
        $ok = !empty($result['ok']);
        echo '<section class="fflhub-box-pack-result ' . ($ok ? 'is-ok' : 'is-warning') . '">';
        echo '<div class="fflhub-box-pack-result-head">';
        echo '<h4>' . esc_html($ok ? __('Packing Result', 'ffl-hub') : __('Packing Needs Attention', 'ffl-hub')) . '</h4>';
        if (!empty($result['ran_at'])) {
            echo '<span>' . esc_html((string) $result['ran_at']) . '</span>';
        }
        echo '</div>';

        $stats = [
            __('Candidate boxes', 'ffl-hub') => (int) ($result['candidate_box_count'] ?? 0),
            __('Packed boxes', 'ffl-hub') => (int) ($result['box_count'] ?? 0),
            __('Dealer units', 'ffl-hub') => (int) ($result['dealer_fulfilled_units'] ?? 0),
            __('Packed units', 'ffl-hub') => (int) ($result['packed_units'] ?? 0),
            __('Unpacked', 'ffl-hub') => (int) ($result['unpacked_item_count'] ?? count((array) ($result['unpacked_items'] ?? []))),
            __('Ignored', 'ffl-hub') => (int) ($result['ignored_item_count'] ?? count((array) ($result['ignored_items'] ?? []))),
        ];

        echo '<div class="fflhub-box-pack-stats">';
        foreach ($stats as $label => $value) {
            echo '<div><span>' . esc_html((string) $label) . '</span><strong>' . esc_html((string) $value) . '</strong></div>';
        }
        echo '</div>';

        $errors = isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : [];
        if (!empty($errors)) {
            echo '<div class="fflhub-box-pack-errors">';
            foreach ($errors as $error) {
                echo '<p>' . esc_html((string) $error) . '</p>';
            }
            echo '</div>';
        }

        $boxes = isset($result['boxes']) && is_array($result['boxes']) ? $result['boxes'] : [];
        if (!empty($boxes)) {
            echo '<div class="fflhub-box-pack-boxes">';
            foreach ($boxes as $index => $box) {
                if (is_array($box)) {
                    $this->render_packed_box((int) $index + 1, $box);
                }
            }
            echo '</div>';
        }

        $this->render_item_debug_list(__('Unpacked Items', 'ffl-hub'), (array) ($result['unpacked_items'] ?? []));
        $this->render_item_debug_list(__('Ignored Direct-Ship Items', 'ffl-hub'), (array) ($result['ignored_items'] ?? []));
        echo '</section>';
    }

    /**
     * @param array<string,mixed> $box
     */
    private function render_packed_box(int $number, array $box): void
    {
        $name = (string) ($box['box_name'] ?? $box['box_id'] ?? 'Box');
        $dims = self::box_dimension_label($box);
        $weight = self::positive_float($box['packed_weight_oz'] ?? null);
        $utilization = self::positive_float($box['volume_utilization_percent'] ?? null);

        echo '<article class="fflhub-box-pack-packed-box">';
        echo '<div class="fflhub-box-pack-packed-box-head">';
        echo '<strong>' . esc_html(sprintf(__('Box %d: %s', 'ffl-hub'), $number, $name)) . '</strong>';
        echo '<span>' . esc_html((string) ($box['package_code'] ?? 'package')) . '</span>';
        echo '</div>';
        echo '<div class="fflhub-box-pack-packed-box-meta">';
        if ($dims !== '') {
            echo '<span>' . esc_html($dims) . '</span>';
        }
        if ($weight !== null) {
            echo '<span>' . esc_html(self::number_label($weight) . ' oz packed') . '</span>';
        }
        if ($utilization !== null) {
            echo '<span>' . esc_html(self::number_label($utilization) . '% volume used') . '</span>';
        }
        echo '</div>';

        $items = isset($box['items']) && is_array($box['items']) ? $box['items'] : [];
        if (!empty($items)) {
            echo '<table class="widefat striped fflhub-box-pack-items"><thead><tr>';
            echo '<th>' . esc_html__('Item', 'ffl-hub') . '</th>';
            echo '<th>' . esc_html__('UPC / SKU', 'ffl-hub') . '</th>';
            echo '<th>' . esc_html__('Qty', 'ffl-hub') . '</th>';
            echo '<th>' . esc_html__('Item Size', 'ffl-hub') . '</th>';
            echo '<th>' . esc_html__('Weight', 'ffl-hub') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $upc = trim((string) ($item['upc'] ?? ''));
                $sku = trim((string) ($item['sku'] ?? ''));
                $code = trim(implode(' / ', array_filter([$upc, $sku])));
                echo '<tr>';
                echo '<td>' . esc_html((string) ($item['name'] ?? 'Item')) . '</td>';
                echo '<td>' . esc_html($code) . '</td>';
                echo '<td>' . esc_html((string) max(0, (int) ($item['quantity'] ?? 0))) . '</td>';
                echo '<td>' . esc_html(self::item_dimension_label($item)) . '</td>';
                echo '<td>' . esc_html(self::item_weight_label($item)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</article>';
    }

    /**
     * @param array<int,mixed> $items
     */
    private function render_item_debug_list(string $title, array $items): void
    {
        if (empty($items)) {
            return;
        }

        echo '<details class="fflhub-box-pack-debug-list" open>';
        echo '<summary>' . esc_html($title) . ' (' . esc_html((string) count($items)) . ')</summary>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Item', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('UPC', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('Qty', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('Reason', 'ffl-hub') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            echo '<tr>';
            echo '<td>' . esc_html((string) ($item['name'] ?? 'Item')) . '</td>';
            echo '<td>' . esc_html((string) ($item['upc'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) max(0, (int) ($item['quantity'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) ($item['reason'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</details>';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function consume_result(int $order_id): ?array
    {
        if (!isset($_GET['fflhub_box_packing_test'])) {
            return null;
        }

        $value = get_transient($this->result_transient_key($order_id));
        return is_array($value) ? $value : null;
    }

    private function result_transient_key(int $order_id): string
    {
        return self::TRANSIENT_PREFIX . get_current_user_id() . '_' . $order_id;
    }

    private function current_url(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '';

        return $host !== '' ? $scheme . $host . $uri : admin_url();
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

    private static function can_manage(): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('edit_shop_orders');
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

    private static function item_dimension_label(array $item): string
    {
        $length = self::positive_float($item['length_in'] ?? null);
        $width = self::positive_float($item['width_in'] ?? null);
        $height = self::positive_float($item['height_in'] ?? null);
        if ($length === null || $width === null || $height === null) {
            return '';
        }

        return self::number_label($length) . ' x ' . self::number_label($width) . ' x ' . self::number_label($height) . ' in';
    }

    private static function item_weight_label(array $item): string
    {
        $weight = self::positive_float($item['weight_oz'] ?? null);
        return $weight !== null ? self::number_label($weight) . ' oz' : '';
    }

    private static function number_label(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function inline_css(): string
    {
        return '
            .fflhub-box-pack-panel { display:grid; gap:14px; }
            .fflhub-box-pack-title-row,
            .fflhub-box-pack-result-head,
            .fflhub-box-pack-packed-box-head {
                display:flex; align-items:center; justify-content:space-between; gap:12px;
            }
            .fflhub-box-pack-title-row h4,
            .fflhub-box-pack-result h4 { margin:0; font-size:14px; }
            .fflhub-box-pack-checklist {
                display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:10px;
            }
            .fflhub-box-pack-choice {
                display:flex; gap:10px; align-items:flex-start; padding:12px;
                border:1px solid #dcdcde; border-radius:8px; background:#fff;
            }
            .fflhub-box-pack-choice input { margin-top:3px; }
            .fflhub-box-pack-choice-main { display:grid; gap:3px; flex:1; min-width:0; }
            .fflhub-box-pack-choice-main strong { color:#1d2327; }
            .fflhub-box-pack-choice-main span,
            .fflhub-box-pack-choice-meta span { color:#646970; font-size:12px; }
            .fflhub-box-pack-choice-meta {
                display:grid; gap:5px; justify-items:end; text-align:right; min-width:92px;
            }
            .fflhub-box-pack-choice.is-disabled { opacity:.55; background:#f6f7f7; }
            .fflhub-box-pack-actions { margin:2px 0 0; }
            .fflhub-box-pack-result {
                border:1px solid #dcdcde; border-left-width:4px; border-radius:8px;
                padding:12px; background:#fff;
            }
            .fflhub-box-pack-result.is-ok { border-left-color:#008a20; }
            .fflhub-box-pack-result.is-warning { border-left-color:#d63638; }
            .fflhub-box-pack-stats {
                display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr));
                gap:8px; margin:12px 0;
            }
            .fflhub-box-pack-stats div {
                border:1px solid #e0e0e0; border-radius:8px; padding:8px; background:#f6f7f7;
            }
            .fflhub-box-pack-stats span { display:block; color:#646970; font-size:11px; text-transform:uppercase; letter-spacing:.03em; }
            .fflhub-box-pack-stats strong { display:block; margin-top:2px; font-size:16px; color:#1d2327; }
            .fflhub-box-pack-errors { padding:8px 10px; border-radius:6px; background:#fcf0f1; color:#8a2424; }
            .fflhub-box-pack-errors p { margin:0 0 4px; }
            .fflhub-box-pack-errors p:last-child { margin-bottom:0; }
            .fflhub-box-pack-boxes { display:grid; gap:12px; }
            .fflhub-box-pack-packed-box {
                border:1px solid #dcdcde; border-radius:8px; padding:10px; background:#fbfbfc;
            }
            .fflhub-box-pack-packed-box-head span {
                border:1px solid #c3c4c7; border-radius:999px; padding:2px 8px; background:#fff; color:#50575e;
            }
            .fflhub-box-pack-packed-box-meta {
                display:flex; flex-wrap:wrap; gap:8px; margin:8px 0; color:#50575e; font-size:12px;
            }
            .fflhub-box-pack-items th,
            .fflhub-box-pack-items td { font-size:12px; }
            .fflhub-box-pack-debug-list { margin-top:10px; }
            .fflhub-box-pack-debug-list summary { cursor:pointer; font-weight:600; }
            .fflhub-box-pack-empty {
                padding:10px; border:1px dashed #c3c4c7; border-radius:8px; color:#646970; background:#f6f7f7;
            }
        ';
    }
}
