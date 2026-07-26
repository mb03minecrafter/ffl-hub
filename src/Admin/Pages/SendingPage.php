<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\WMS\SendingOrdersService;
use FFLHub\WMS\SendingPackingService;

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
        $packing_result = null;

        if ($this->should_run_packing()) {
            $packing_result = $this->run_packing($orders);
            $orders = $packing_result['orders'];
            $stats['packing_orders_packed'] = (int) ($packing_result['stats']['orders_packed'] ?? 0);
            $stats['packing_orders_failed'] = (int) ($packing_result['stats']['orders_failed'] ?? 0);
            $stats['packing_packages_selected'] = (int) ($packing_result['stats']['packages_selected'] ?? 0);
        }
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
                <?php if ($packing_result !== null) : ?>
                    <?php $this->render_stat(__('Packed', 'ffl-hub'), (string) ($stats['packing_orders_packed'] ?? 0)); ?>
                    <?php $this->render_stat(__('Packing Failed', 'ffl-hub'), (string) ($stats['packing_orders_failed'] ?? 0)); ?>
                <?php endif; ?>
            </div>

            <?php if ($packing_result !== null) : ?>
                <?php $this->render_packing_notice((array) ($packing_result['stats'] ?? [])); ?>
            <?php endif; ?>

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

            <?php $this->render_packing_form($job_scan_limit, $debug_ready, !empty($orders)); ?>

            <?php $this->render_orders($orders); ?>
        </div>
        <?php
    }

    private function read_job_scan_limit(): int
    {
        $limit = isset($_REQUEST['job_scan_limit'])
            ? (int) sanitize_text_field(wp_unslash((string) $_REQUEST['job_scan_limit']))
            : SendingOrdersService::DEFAULT_JOB_SCAN_LIMIT;

        return max(1, min(SendingOrdersService::MAX_JOB_SCAN_LIMIT, $limit));
    }

    private function read_bool(string $key): bool
    {
        $value = isset($_REQUEST[$key])
            ? strtolower(trim(sanitize_text_field(wp_unslash((string) $_REQUEST[$key]))))
            : '';

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function should_run_packing(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['fflhub_sending_run_packing'])) {
            return false;
        }

        $nonce = isset($_POST['fflhub_sending_packing_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_sending_packing_nonce']))
            : '';

        return $nonce !== '' && wp_verify_nonce($nonce, 'fflhub_sending_run_packing');
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     * @return array{orders:array<int,array<string,mixed>>,stats:array<string,mixed>}
     */
    private function run_packing(array $orders): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        return (new SendingPackingService())->pack_ready_orders($orders);
    }

    private function render_packing_form(int $job_scan_limit, bool $debug_ready, bool $has_orders): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>" class="fflhub-sending-packing-form">
            <?php wp_nonce_field('fflhub_sending_run_packing', 'fflhub_sending_packing_nonce'); ?>
            <input type="hidden" name="job_scan_limit" value="<?php echo esc_attr((string) $job_scan_limit); ?>" />
            <label class="fflhub-sending-packing-debug-toggle">
                <input type="checkbox" name="debug_ready" value="1" <?php checked($debug_ready); ?> />
                <span><?php esc_html_e('Include pretend-ready debug candidates', 'ffl-hub'); ?></span>
            </label>
            <?php submit_button(__('Run Packing Algorithm For Ready Orders', 'ffl-hub'), 'primary', 'fflhub_sending_run_packing', false); ?>
            <span>
                <?php
                echo esc_html($has_orders
                    ? __('Read-only. Uses this form\'s ready/debug setting and current on-hand package presets; it does not buy labels or change order status.', 'ffl-hub')
                    : __('Read-only. If strict ready rows are empty, enable pretend-ready debug candidates here before running.', 'ffl-hub'));
                ?>
            </span>
        </form>
        <?php
    }

    /**
     * @param array<string,mixed> $stats
     */
    private function render_packing_notice(array $stats): void
    {
        $message = sprintf(
            __('Packing pass complete: %1$d packed, %2$d failed, %3$d package(s) selected in %4$s ms.', 'ffl-hub'),
            (int) ($stats['orders_packed'] ?? 0),
            (int) ($stats['orders_failed'] ?? 0),
            (int) ($stats['packages_selected'] ?? 0),
            (string) ($stats['runtime_ms'] ?? '0')
        );
        ?>
        <div class="notice notice-info inline fflhub-sending-packing-notice">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
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
                <?php $this->render_selected_package($order, $selected_package); ?>
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
     * @param array<string,mixed> $order
     */
    private function render_selected_package(array $order, string $selected_package): void
    {
        $packing_status = trim((string) ($order['packing_status'] ?? ''));

        if ($selected_package === '' && $packing_status === '') {
            echo '<span class="fflhub-sending-muted">' . esc_html__('Not run', 'ffl-hub') . '</span>';
            return;
        }

        if (!empty($order['packing_ok']) && $selected_package !== '') {
            echo '<span class="fflhub-sending-pill is-packed">' . esc_html__('Packed', 'ffl-hub') . '</span>';
            echo '<strong class="fflhub-sending-package-name">' . esc_html($selected_package) . '</strong>';
            $this->render_package_details((array) ($order['selected_package_details'] ?? []));
            return;
        }

        echo '<span class="fflhub-sending-pill packing-failed">' . esc_html__('No Fit', 'ffl-hub') . '</span>';
        $errors = array_values(array_filter(array_map('strval', (array) ($order['packing_errors'] ?? []))));
        if (!empty($errors)) {
            echo '<ul class="fflhub-sending-mini-list fflhub-sending-error-list">';
            foreach (array_slice($errors, 0, 3) as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul>';
        }
    }

    /**
     * @param array<int,mixed> $details
     */
    private function render_package_details(array $details): void
    {
        if (empty($details)) {
            return;
        }

        echo '<ul class="fflhub-sending-mini-list fflhub-sending-package-details">';
        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $bits = [];
            $dimensions = trim((string) ($detail['dimensions'] ?? ''));
            if ($dimensions !== '') {
                $bits[] = $dimensions;
            }
            $weight = $this->number_or_null($detail['packed_weight_oz'] ?? null);
            if ($weight !== null) {
                $bits[] = $weight . ' oz packed';
            }
            $utilization = $this->number_or_null($detail['volume_utilization_percent'] ?? null);
            if ($utilization !== null) {
                $bits[] = $utilization . '% volume';
            }

            echo '<li>' . esc_html(implode(' | ', $bits)) . '</li>';
        }
        echo '</ul>';
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

    /**
     * @param mixed $value
     */
    private function number_or_null($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;
        if ($float <= 0.0) {
            return null;
        }

        return rtrim(rtrim(number_format($float, 2, '.', ''), '0'), '.');
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
            .fflhub-sending-packing-form{display:flex;align-items:center;gap:12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:12px;margin-bottom:16px}
            .fflhub-sending-packing-debug-toggle{display:inline-flex;align-items:center;gap:7px;font-weight:700;white-space:nowrap}
            .fflhub-sending-packing-debug-toggle input{margin:0}
            .fflhub-sending-packing-form span{color:#646970}
            .fflhub-sending-packing-debug-toggle span{color:#1d2327}
            .fflhub-sending-packing-notice{margin:0 0 16px}
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
            .fflhub-sending-pill.is-packed{background:#e6f6ed;color:#146c43}
            .fflhub-sending-pill.packing-failed{background:#fde7e9;color:#8a2424}
            .fflhub-sending-package-cell{min-width:190px}
            .fflhub-sending-package-name{display:block;margin-top:6px;color:#1d2327}
            .fflhub-sending-package-details{margin-top:5px}
            .fflhub-sending-error-list{margin-top:5px;color:#8a2424}
            .fflhub-sending-mini-list{margin:0;display:grid;gap:5px}
            .fflhub-sending-mini-list li{margin:0}
            .fflhub-sending-ffl-tag{display:inline-flex;border-radius:999px;background:#e5f0ff;color:#0a4b78;font-size:10px;font-weight:800;padding:1px 5px;vertical-align:middle}
            .fflhub-sending-empty{background:#fff;border:1px dashed #c3c4c7;border-radius:8px;padding:18px;color:#646970}
            @media (max-width:782px){.fflhub-sending-filter,.fflhub-sending-packing-form{display:block}.fflhub-sending-filter .button,.fflhub-sending-packing-form .button{margin-top:10px}.fflhub-sending-filter .fflhub-sending-debug-toggle{grid-template-columns:auto 1fr;margin-top:10px}}
        </style>
        <?php
    }
}
