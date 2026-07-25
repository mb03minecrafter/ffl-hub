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
 * Historical packing audit for package sizes we own or might buy later.
 *
 * This is intentionally read-only. It finds orders that have dealer-fulfilled
 * job rows, runs those orders against selected on-hand package presets plus the
 * "potential future boxes" preset list, and aggregates which sizes BoxPacker
 * would have selected most.
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
        $on_hand_presets = $this->on_hand_package_rows_for_ui();
        $selected_on_hand_ids = is_array($result) && isset($result['selected_on_hand_package_ids']) && is_array($result['selected_on_hand_package_ids'])
            ? array_map('strval', $result['selected_on_hand_package_ids'])
            : [];
        $checked_on_hand_ids = array_fill_keys($selected_on_hand_ids, true);
        ?>
        <div class="wrap fflhub-shipping-package-audit">
            <?php ShippingAdminPage::render_styles(); ?>
            <?php $this->render_inline_styles(); ?>

            <h1><?php esc_html_e('Package Audit', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Run historical dealer-fulfilled orders against optional on-hand packages plus potential future boxes. This does not change orders, labels, package presets, or fulfillment routing.', 'ffl-hub'); ?>
            </p>

            <section class="fflhub-shipping-card">
                <div class="fflhub-shipping-card-head">
                    <div>
                        <h2><?php esc_html_e('Package Audit Inputs', 'ffl-hub'); ?></h2>
                        <p class="description">
                            <?php echo esc_html(sprintf(
                                __('Potential future boxes configured: %1$d. Packable on-hand packages available: %2$d.', 'ffl-hub'),
                                count($this->future_box_rows()),
                                count(array_filter($on_hand_presets, static fn(array $row): bool => !empty($row['eligible_for_packing'])))
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
                    <h3><?php esc_html_e('Add On-Hand Packages', 'ffl-hub'); ?></h3>
                    <p class="description">
                        <?php esc_html_e('Checked packages are included in the audit alongside the potential future boxes. Leave all unchecked to audit future boxes only.', 'ffl-hub'); ?>
                    </p>
                    <?php $this->render_on_hand_package_checklist($on_hand_presets, $checked_on_hand_ids); ?>
                    <?php submit_button(__('Run Package Audit', 'ffl-hub'), 'primary', 'fflhub_run_package_audit', false); ?>
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

        return $this->run_audit($this->posted_selected_on_hand_package_ids());
    }

    /**
     * @param string[] $selected_on_hand_package_ids
     * @return array<string,mixed>
     */
    private function run_audit(array $selected_on_hand_package_ids): array
    {
        $started = microtime(true);
        $on_hand_packages = $this->selected_on_hand_package_rows($selected_on_hand_package_ids);
        $future_boxes = $this->future_box_rows();
        $candidate_packages = array_merge($on_hand_packages, $future_boxes);
        $package_sources = $this->package_source_map($on_hand_packages, $future_boxes);
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
            'selected_on_hand_packages' => count($on_hand_packages),
            'future_packages' => count($future_boxes),
        ];

        $box_stats = [];
        $problem_orders = [];

        if (empty($candidate_packages)) {
            return [
                'stats' => $stats,
                'box_stats' => [],
                'problem_orders' => [],
                'selected_on_hand_package_ids' => $selected_on_hand_package_ids,
                'runtime_ms' => $this->elapsed_ms($started),
                'error' => 'No packable on-hand packages or potential future boxes are configured.',
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
            $result = $packer->pack_dealer_fulfilled_order($order, $candidate_packages);
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
                    $source = $this->package_source_for_box_id($box_id, $package_sources);
                    $box_stats[$box_id] = [
                        'box_id' => $box_id,
                        'box_name' => (string) ($box['box_name'] ?? $box_id),
                        'source' => $source,
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
                'source' => $row['source'],
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
            'selected_on_hand_package_ids' => $selected_on_hand_package_ids,
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
            'On-hand added' => (int) ($stats['selected_on_hand_packages'] ?? 0),
            'Future candidates' => (int) ($stats['future_packages'] ?? 0),
            'Packages selected' => (int) ($stats['package_count'] ?? 0),
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
        echo '<h3>' . esc_html__('Most Used Packages', 'ffl-hub') . '</h3>';
        if (empty($rows)) {
            echo '<div class="fflhub-shipping-empty">' . esc_html__('No packages were selected by the audit.', 'ffl-hub') . '</div>';
            return;
        }

        echo '<table class="widefat striped fflhub-shipping-audit-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Box', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('Source', 'ffl-hub') . '</th>';
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
            echo '<td>' . esc_html((string) ($row['source'] ?? '')) . '</td>';
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
        echo '<p class="description">' . esc_html__('Showing up to 50 orders that had unpacked items, no selected package, or BoxPacker errors.', 'ffl-hub') . '</p>';
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
     * @return string[]
     */
    private function posted_selected_on_hand_package_ids(): array
    {
        $posted = isset($_POST['on_hand_package_ids']) && is_array($_POST['on_hand_package_ids'])
            ? (array) wp_unslash($_POST['on_hand_package_ids'])
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
    private function on_hand_package_rows_for_ui(): array
    {
        return $this->package_rows_for_ui(ShippingOptions::package_presets(), ['box', 'envelope'], 'On hand');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function future_box_rows(): array
    {
        $rows = [];
        foreach ($this->package_rows_for_ui(ShippingOptions::future_package_presets(), ['box'], 'Potential future') as $preset) {
            if (!empty($preset['eligible_for_packing'])) {
                $rows[] = $preset;
            }
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $presets
     * @param string[] $allowed_kinds
     * @return array<int,array<string,mixed>>
     */
    private function package_rows_for_ui(array $presets, array $allowed_kinds, string $source): array
    {
        $rows = [];
        foreach ($presets as $preset) {
            if (!is_array($preset)) {
                continue;
            }

            $kind = self::package_type($preset['kind'] ?? 'box');
            if (!in_array($kind, $allowed_kinds, true)) {
                continue;
            }

            $preset['source'] = $source;
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
    private function selected_on_hand_package_rows(array $selected_ids): array
    {
        if (empty($selected_ids)) {
            return [];
        }

        $selected = array_fill_keys($selected_ids, true);
        $rows = [];
        foreach ($this->on_hand_package_rows_for_ui() as $preset) {
            $id = sanitize_key((string) ($preset['id'] ?? ''));
            if ($id === '' || empty($selected[$id]) || empty($preset['eligible_for_packing'])) {
                continue;
            }

            $rows[] = $preset;
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $on_hand_packages
     * @param array<int,array<string,mixed>> $future_boxes
     * @return array<string,string>
     */
    private function package_source_map(array $on_hand_packages, array $future_boxes): array
    {
        $map = [];
        foreach ($on_hand_packages as $row) {
            $id = sanitize_key((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $map[$id] = 'On hand';
            }
        }
        foreach ($future_boxes as $row) {
            $id = sanitize_key((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $map[$id] = 'Potential future';
            }
        }

        return $map;
    }

    /**
     * Envelope audit candidates append their virtual thickness to the saved
     * preset id, so source lookup checks exact ids first and generated virtual
     * ids second.
     *
     * @param array<string,string> $package_sources
     */
    private function package_source_for_box_id(string $box_id, array $package_sources): string
    {
        if (isset($package_sources[$box_id])) {
            return $package_sources[$box_id];
        }

        foreach ($package_sources as $id => $source) {
            if ($id !== '' && strpos($box_id, $id . '_t') === 0) {
                return $source;
            }
        }

        return '';
    }

    /**
     * @param array<int,array<string,mixed>> $presets
     * @param array<string,bool> $checked_ids
     */
    private function render_on_hand_package_checklist(array $presets, array $checked_ids): void
    {
        if (empty($presets)) {
            echo '<div class="fflhub-shipping-empty">' . esc_html__('No current package presets found. Add them under FFLHub Shipping > Package Presets.', 'ffl-hub') . '</div>';
            return;
        }

        echo '<div class="fflhub-shipping-package-checklist">';
        foreach ($presets as $preset) {
            $id = sanitize_key((string) ($preset['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $name = trim((string) ($preset['name'] ?? $id));
            $kind = self::package_type($preset['kind'] ?? 'box');
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

            echo '<label class="fflhub-shipping-package-choice ' . ($eligible ? '' : 'is-disabled') . '">';
            echo '<input type="checkbox" name="on_hand_package_ids[]" value="' . esc_attr($id) . '" ' . checked(!empty($checked_ids[$id]), true, false) . ' ' . disabled(!$eligible, true, false) . ' />';
            echo '<span class="fflhub-shipping-package-choice-main">';
            echo '<strong>' . esc_html($name) . '</strong>';
            echo '<span>' . esc_html($dims) . '</span>';
            echo '</span>';
            echo '<span class="fflhub-shipping-package-choice-meta">';
            echo '<code>' . esc_html($kind) . '</code>';
            echo '<span>' . esc_html($weight_label) . '</span>';
            echo '</span>';
            echo '</label>';
        }
        echo '</div>';
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
     * @param mixed $value
     */
    private static function package_type($value): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === 'package') {
            return 'box';
        }

        return in_array($value, ['box', 'envelope'], true) ? $value : 'box';
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
            .fflhub-shipping-package-checklist{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;margin:12px 0 16px}
            .fflhub-shipping-package-choice{display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid #dcdcde;border-radius:8px;background:#fff}
            .fflhub-shipping-package-choice input{margin-top:3px}
            .fflhub-shipping-package-choice-main{display:grid;gap:3px;flex:1;min-width:0}
            .fflhub-shipping-package-choice-main strong{color:#1d2327}
            .fflhub-shipping-package-choice-main span,.fflhub-shipping-package-choice-meta span{color:#646970;font-size:12px}
            .fflhub-shipping-package-choice-meta{display:grid;gap:5px;justify-items:end;text-align:right;min-width:92px}
            .fflhub-shipping-package-choice.is-disabled{opacity:.55;background:#f6f7f7}
        </style>
        <?php
    }
}
