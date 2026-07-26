<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\WMS\SendingOrdersService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only WMS view for orders whose dealer-fulfilled inbound products are
 * received and ready for outbound packing/label work.
 */
final class SendingPage
{
    private const PAGE_SLUG = 'fflhub-sending';

    private OrderPlacementJobsTable $jobs_table;

    public function __construct(OrderPlacementJobsTable $jobs_table)
    {
        $this->jobs_table = $jobs_table;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            WMSAdminPage::MENU_SLUG,
            __('Sending', 'ffl-hub'),
            __('Sending', 'ffl-hub'),
            WMSAdminPage::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        WMSAdminPage::ensure_access();

        $job_scan_limit = $this->read_job_scan_limit();
        $debug_ready = $this->read_bool('debug_ready');
        $result = (new SendingOrdersService($this->jobs_table))->ready_orders($job_scan_limit, $debug_ready);
        $orders = $result['orders'];
        $stats = $result['stats'];
        ?>
        <div class="wrap fflhub-sending-page">
            <?php $this->render_styles(); ?>
            <h1><?php esc_html_e('Sending', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Read-only queue of orders whose dealer-fulfilled items have been received and are ready for packing or shipping-label work.', 'ffl-hub'); ?>
            </p>

            <div class="fflhub-sending-stats">
                <?php $this->render_stat(__('Ready Orders', 'ffl-hub'), (string) ($stats['ready_orders'] ?? 0)); ?>
                <?php $this->render_stat(__('Need Labels', 'ffl-hub'), (string) ($stats['needs_label'] ?? 0)); ?>
                <?php $this->render_stat(__('Have Labels', 'ffl-hub'), (string) ($stats['has_label'] ?? 0)); ?>
                <?php $this->render_stat(__('Jobs Scanned', 'ffl-hub'), (string) ($stats['jobs_scanned'] ?? 0)); ?>
                <?php if ($debug_ready) : ?>
                    <?php $this->render_stat(__('Debug Ready', 'ffl-hub'), (string) ($stats['debug_ready_orders'] ?? 0)); ?>
                <?php endif; ?>
            </div>

            <form method="get" action="" class="fflhub-sending-filter">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                <label>
                    <span><?php esc_html_e('Dealer success job rows to scan', 'ffl-hub'); ?></span>
                    <input
                        type="number"
                        name="job_scan_limit"
                        min="1"
                        max="<?php echo esc_attr((string) SendingOrdersService::MAX_JOB_SCAN_LIMIT); ?>"
                        step="1"
                        value="<?php echo esc_attr((string) $job_scan_limit); ?>" />
                </label>
                <label class="fflhub-sending-debug-toggle">
                    <input type="checkbox" name="debug_ready" value="1" <?php checked($debug_ready); ?> />
                    <span>
                        <strong><?php esc_html_e('Debug: pretend candidates are ready', 'ffl-hub'); ?></strong>
                        <?php esc_html_e('Shows otherwise-unreceived dealer-fulfilled success orders as ready for UI testing only. No order meta, receiving events, labels, or statuses are changed.', 'ffl-hub'); ?>
                    </span>
                </label>
                <?php submit_button(__('Refresh', 'ffl-hub'), 'secondary', '', false); ?>
            </form>

            <?php $this->render_orders($orders); ?>
        </div>
        <?php
    }

    private function read_job_scan_limit(): int
    {
        $limit = isset($_GET['job_scan_limit'])
            ? (int) sanitize_text_field(wp_unslash((string) $_GET['job_scan_limit']))
            : SendingOrdersService::DEFAULT_JOB_SCAN_LIMIT;

        return max(1, min(SendingOrdersService::MAX_JOB_SCAN_LIMIT, $limit));
    }

    private function read_bool(string $key): bool
    {
        $value = isset($_GET[$key])
            ? strtolower(trim(sanitize_text_field(wp_unslash((string) $_GET[$key]))))
            : '';

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function render_stat(string $label, string $value): void
    {
        ?>
        <div class="fflhub-sending-stat">
            <span><?php echo esc_html($label); ?></span>
            <strong><?php echo esc_html($value); ?></strong>
        </div>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     */
    private function render_orders(array $orders): void
    {
        if (empty($orders)) {
            ?>
            <div class="fflhub-sending-empty">
                <?php esc_html_e('No ready-to-ship dealer-fulfilled orders were found in the scanned job window.', 'ffl-hub'); ?>
            </div>
            <?php
            return;
        }
        ?>
        <div class="fflhub-sending-table-wrap">
            <table class="widefat striped fflhub-sending-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Order', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('Customer', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('Ready', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('Received', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('Selected Package', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('Label', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('Distributor / PO', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('Items', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('Action', 'ffl-hub'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order) : ?>
                        <?php $this->render_order_row($order); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $order
     */
    private function render_order_row(array $order): void
    {
        $has_label = !empty($order['has_active_label']);
        $debug_ready = !empty($order['debug_ready']);
        $label_class = $has_label ? 'is-labeled' : 'needs-label';
        $label_text = $has_label ? __('Label purchased', 'ffl-hub') : __('Needs label', 'ffl-hub');
        $selected_package = trim((string) ($order['selected_package'] ?? ''));
        ?>
        <tr class="<?php echo $debug_ready ? 'is-debug-ready' : ''; ?>">
            <td class="fflhub-sending-order-cell">
                <a class="fflhub-sending-order-link" href="<?php echo esc_url((string) ($order['order_edit_url'] ?? '')); ?>">
                    #<?php echo esc_html((string) ($order['order_number'] ?? $order['order_id'] ?? '')); ?>
                </a>
                <span><?php echo esc_html((string) ($order['order_created_at'] ?? '')); ?></span>
            </td>
            <td>
                <?php echo esc_html((string) ($order['customer_name'] ?? __('Unknown customer', 'ffl-hub'))); ?>
                <span class="fflhub-sending-muted"><?php echo esc_html((string) ($order['order_status'] ?? '-')); ?></span>
            </td>
            <td>
                <div class="fflhub-sending-stack">
                    <strong><?php echo esc_html($this->local_time((string) ($order['ready_at'] ?? ''))); ?></strong>
                    <span><?php echo esc_html((string) ($order['readiness_source'] ?? '-')); ?></span>
                    <?php if ($debug_ready) : ?>
                        <span class="fflhub-sending-pill is-debug"><?php esc_html_e('Debug Ready', 'ffl-hub'); ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td>
                <strong><?php echo esc_html((string) ((int) ($order['received_units'] ?? 0) . ' / ' . (int) ($order['expected_units'] ?? 0))); ?></strong>
                <?php if ((int) ($order['remaining_units'] ?? 0) > 0) : ?>
                    <span class="fflhub-sending-muted">
                        <?php echo esc_html(sprintf(__('%d open', 'ffl-hub'), (int) ($order['remaining_units'] ?? 0))); ?>
                    </span>
                <?php endif; ?>
            </td>
            <td class="fflhub-sending-package-cell">
                <?php echo $selected_package !== '' ? esc_html($selected_package) : ''; ?>
            </td>
            <td>
                <span class="fflhub-sending-pill <?php echo esc_attr($label_class); ?>">
                    <?php echo esc_html($label_text); ?>
                </span>
                <?php $this->render_label_summary((array) ($order['active_labels'] ?? [])); ?>
            </td>
            <td>
                <div class="fflhub-sending-stack">
                    <span><?php echo esc_html($this->join_or_dash((array) ($order['distributors'] ?? []))); ?></span>
                    <span><?php echo esc_html($this->join_or_dash((array) ($order['merchant_pos'] ?? []))); ?></span>
                </div>
            </td>
            <td>
                <?php $this->render_items_summary((array) ($order['items'] ?? [])); ?>
            </td>
            <td>
                <a class="button button-primary" href="<?php echo esc_url((string) ($order['order_edit_url'] ?? '')); ?>">
                    <?php esc_html_e('Open', 'ffl-hub'); ?>
                </a>
            </td>
        </tr>
        <?php
    }

    /**
     * @param array<int,mixed> $items
     */
    private function render_items_summary(array $items): void
    {
        if (empty($items)) {
            echo '<span class="fflhub-sending-muted">-</span>';
            return;
        }

        echo '<ul class="fflhub-sending-mini-list">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $qty = (int) ($item['expected_qty'] ?? 0);
            $name = (string) ($item['name'] ?? '');
            $upc = (string) ($item['upc'] ?? '');
            echo '<li>';
            echo '<strong>' . esc_html((string) $qty . 'x') . '</strong> ';
            echo esc_html($name);
            if ($upc !== '') {
                echo ' <code>' . esc_html($upc) . '</code>';
            }
            if (!empty($item['ffl_required'])) {
                echo ' <span class="fflhub-sending-ffl-tag">' . esc_html__('FFL', 'ffl-hub') . '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    /**
     * @param array<int,mixed> $labels
     */
    private function render_label_summary(array $labels): void
    {
        if (empty($labels)) {
            return;
        }

        echo '<ul class="fflhub-sending-mini-list">';
        foreach ($labels as $label) {
            if (!is_array($label)) {
                continue;
            }

            $service = trim((string) ($label['service'] ?? ''));
            $carrier = trim((string) ($label['carrier'] ?? ''));
            $tracking = trim((string) ($label['tracking_number'] ?? ''));
            echo '<li>';
            echo esc_html(trim($carrier . ' ' . $service));
            if ($tracking !== '') {
                echo ' <code>' . esc_html($tracking) . '</code>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    /**
     * @param array<int,mixed> $values
     */
    private function join_or_dash(array $values): string
    {
        $values = array_values(array_filter(array_map('strval', $values)));

        return !empty($values) ? implode(', ', $values) : '-';
    }

    private function local_time(string $mysql_utc): string
    {
        $mysql_utc = trim($mysql_utc);
        if ($mysql_utc === '') {
            return '-';
        }

        $timestamp = strtotime($mysql_utc . ' UTC');
        if (!$timestamp) {
            return $mysql_utc;
        }

        return wp_date('M j, Y g:i a', $timestamp);
    }

    private function render_styles(): void
    {
        ?>
        <style>
            .fflhub-sending-page{max-width:1500px}
            .fflhub-sending-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:18px 0}
            .fflhub-sending-stat{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px}
            .fflhub-sending-stat span{display:block;color:#646970;font-size:12px;text-transform:uppercase;font-weight:700}
            .fflhub-sending-stat strong{display:block;margin-top:4px;font-size:24px;line-height:1.1;color:#1d2327}
            .fflhub-sending-filter{display:flex;align-items:flex-end;gap:10px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px;margin-bottom:16px}
            .fflhub-sending-filter label{display:grid;gap:4px}
            .fflhub-sending-filter label span{font-weight:700}
            .fflhub-sending-filter input{width:130px}
            .fflhub-sending-filter .fflhub-sending-debug-toggle{display:grid;grid-template-columns:auto minmax(260px,520px);align-items:start;gap:8px}
            .fflhub-sending-filter .fflhub-sending-debug-toggle input{width:auto;margin-top:4px}
            .fflhub-sending-filter .fflhub-sending-debug-toggle span{font-weight:400;color:#50575e}
            .fflhub-sending-filter .fflhub-sending-debug-toggle strong{display:block;color:#1d2327}
            .fflhub-sending-table-wrap{background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:auto}
            .fflhub-sending-table{border:0}
            .fflhub-sending-table th{white-space:nowrap}
            .fflhub-sending-table th,.fflhub-sending-table td{vertical-align:top}
            .fflhub-sending-table tr.is-debug-ready td{background:#fffdf5}
            .fflhub-sending-order-cell{min-width:120px}
            .fflhub-sending-order-link{display:block;font-size:16px;font-weight:700;text-decoration:none}
            .fflhub-sending-order-cell span,.fflhub-sending-muted{display:block;color:#646970;font-size:12px;margin-top:3px}
            .fflhub-sending-stack{display:grid;gap:3px}
            .fflhub-sending-pill{display:inline-flex;align-items:center;white-space:nowrap;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:800;text-transform:uppercase}
            .fflhub-sending-pill.needs-label{background:#fff4e5;color:#8a4b00}
            .fflhub-sending-pill.is-labeled{background:#e6f6ed;color:#146c43}
            .fflhub-sending-pill.is-debug{background:#1d2327;color:#fff}
            .fflhub-sending-package-cell{min-width:140px}
            .fflhub-sending-mini-list{margin:0;display:grid;gap:5px}
            .fflhub-sending-mini-list li{margin:0}
            .fflhub-sending-ffl-tag{display:inline-flex;border-radius:999px;background:#e5f0ff;color:#0a4b78;font-size:10px;font-weight:800;padding:1px 5px;vertical-align:middle}
            .fflhub-sending-empty{background:#fff;border:1px dashed #c3c4c7;border-radius:8px;padding:18px;color:#646970}
            @media (max-width:782px){.fflhub-sending-filter{display:block}.fflhub-sending-filter .button{margin-top:10px}.fflhub-sending-filter .fflhub-sending-debug-toggle{grid-template-columns:auto 1fr;margin-top:10px}}
        </style>
        <?php
    }
}
