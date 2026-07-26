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
        <div class="fflhub-sending-grid">
            <?php foreach ($orders as $order) : ?>
                <?php $this->render_order_card($order); ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $order
     */
    private function render_order_card(array $order): void
    {
        $has_label = !empty($order['has_active_label']);
        $debug_ready = !empty($order['debug_ready']);
        $label_class = $has_label ? 'is-labeled' : 'needs-label';
        $label_text = $has_label ? __('Label purchased', 'ffl-hub') : __('Needs label', 'ffl-hub');
        ?>
        <section class="fflhub-sending-card <?php echo $debug_ready ? 'is-debug-ready' : ''; ?>">
            <header class="fflhub-sending-card-head">
                <div>
                    <h2>
                        <a href="<?php echo esc_url((string) ($order['order_edit_url'] ?? '')); ?>">
                            #<?php echo esc_html((string) ($order['order_number'] ?? $order['order_id'] ?? '')); ?>
                        </a>
                    </h2>
                    <p>
                        <?php echo esc_html((string) ($order['customer_name'] ?? __('Unknown customer', 'ffl-hub'))); ?>
                        <span><?php echo esc_html((string) ($order['order_created_at'] ?? '')); ?></span>
                    </p>
                </div>
                <div class="fflhub-sending-pills">
                    <?php if ($debug_ready) : ?>
                        <span class="fflhub-sending-pill is-debug"><?php esc_html_e('Debug Ready', 'ffl-hub'); ?></span>
                    <?php endif; ?>
                    <span class="fflhub-sending-pill <?php echo esc_attr($label_class); ?>">
                        <?php echo esc_html($label_text); ?>
                    </span>
                </div>
            </header>

            <?php if ($debug_ready) : ?>
                <div class="fflhub-sending-debug-warning">
                    <?php esc_html_e('Debug view only: this order is being shown as ready even though the received count has not satisfied the real receiving requirement.', 'ffl-hub'); ?>
                </div>
            <?php endif; ?>

            <div class="fflhub-sending-meta">
                <div><span><?php esc_html_e('Ready At', 'ffl-hub'); ?></span><strong><?php echo esc_html($this->local_time((string) ($order['ready_at'] ?? ''))); ?></strong></div>
                <div><span><?php esc_html_e('Order Status', 'ffl-hub'); ?></span><strong><?php echo esc_html((string) ($order['order_status'] ?? '-')); ?></strong></div>
                <div><span><?php esc_html_e('Units', 'ffl-hub'); ?></span><strong><?php echo esc_html((string) ((int) ($order['received_units'] ?? 0) . ' / ' . (int) ($order['expected_units'] ?? 0))); ?></strong></div>
                <div><span><?php esc_html_e('Source', 'ffl-hub'); ?></span><strong><?php echo esc_html((string) ($order['readiness_source'] ?? '-')); ?></strong></div>
            </div>

            <div class="fflhub-sending-section">
                <h3><?php esc_html_e('Inbound Orders', 'ffl-hub'); ?></h3>
                <p>
                    <strong><?php esc_html_e('Distributors:', 'ffl-hub'); ?></strong>
                    <?php echo esc_html($this->join_or_dash((array) ($order['distributors'] ?? []))); ?>
                </p>
                <p>
                    <strong><?php esc_html_e('POs:', 'ffl-hub'); ?></strong>
                    <?php echo esc_html($this->join_or_dash((array) ($order['merchant_pos'] ?? []))); ?>
                </p>
                <p>
                    <strong><?php esc_html_e('Inbound Tracking:', 'ffl-hub'); ?></strong>
                    <?php echo esc_html($this->join_or_dash((array) ($order['inbound_tracking_numbers'] ?? []))); ?>
                </p>
            </div>

            <div class="fflhub-sending-section">
                <h3><?php esc_html_e('Items', 'ffl-hub'); ?></h3>
                <table class="widefat striped fflhub-sending-items">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Item', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('UPC', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Received', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('FFL', 'ffl-hub'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ((array) ($order['items'] ?? []) as $item) : ?>
                            <?php if (!is_array($item)) { continue; } ?>
                            <tr>
                                <td><?php echo esc_html((string) ($item['name'] ?? '')); ?></td>
                                <td><code><?php echo esc_html((string) ($item['upc'] ?? '')); ?></code></td>
                                <td><?php echo esc_html((string) ((int) ($item['received_qty'] ?? 0) . ' / ' . (int) ($item['expected_qty'] ?? 0))); ?></td>
                                <td><?php echo !empty($item['ffl_required']) ? esc_html__('Yes', 'ffl-hub') : esc_html__('No', 'ffl-hub'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="fflhub-sending-section">
                <h3><?php esc_html_e('Outbound Label', 'ffl-hub'); ?></h3>
                <?php if (empty($order['active_labels'])) : ?>
                    <p><?php esc_html_e('No active FFL Hub shipping label is stored for this order yet.', 'ffl-hub'); ?></p>
                <?php else : ?>
                    <ul class="fflhub-sending-labels">
                        <?php foreach ((array) $order['active_labels'] as $label) : ?>
                            <?php if (!is_array($label)) { continue; } ?>
                            <li>
                                <strong><?php echo esc_html((string) ($label['service'] ?? __('Label', 'ffl-hub'))); ?></strong>
                                <span><?php echo esc_html((string) ($label['carrier'] ?? '')); ?></span>
                                <?php if (!empty($label['tracking_number'])) : ?>
                                    <code><?php echo esc_html((string) $label['tracking_number']); ?></code>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url((string) ($order['order_edit_url'] ?? '')); ?>">
                        <?php esc_html_e('Open Order / Shipping Labels', 'ffl-hub'); ?>
                    </a>
                </p>
            </div>
        </section>
        <?php
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
            .fflhub-sending-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px}
            .fflhub-sending-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .fflhub-sending-card.is-debug-ready{border-color:#dba617;background:#fffdf5}
            .fflhub-sending-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;border-bottom:1px solid #f0f0f1;padding-bottom:12px;margin-bottom:12px}
            .fflhub-sending-card h2{margin:0;font-size:20px}
            .fflhub-sending-card h2 a{text-decoration:none}
            .fflhub-sending-card-head p{margin:4px 0 0;color:#50575e}
            .fflhub-sending-card-head p span{display:block;font-size:12px;color:#787c82;margin-top:2px}
            .fflhub-sending-pills{display:flex;align-items:flex-end;flex-direction:column;gap:6px}
            .fflhub-sending-pill{display:inline-flex;align-items:center;white-space:nowrap;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:800;text-transform:uppercase}
            .fflhub-sending-pill.needs-label{background:#fff4e5;color:#8a4b00}
            .fflhub-sending-pill.is-labeled{background:#e6f6ed;color:#146c43}
            .fflhub-sending-pill.is-debug{background:#1d2327;color:#fff}
            .fflhub-sending-debug-warning{background:#fff4e5;border:1px solid #f0c36d;border-radius:8px;padding:9px 10px;margin-bottom:12px;color:#5f4100;font-weight:700}
            .fflhub-sending-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:12px}
            .fflhub-sending-meta div{background:#f6f7f7;border-radius:8px;padding:9px}
            .fflhub-sending-meta span{display:block;color:#646970;font-size:11px;text-transform:uppercase;font-weight:700}
            .fflhub-sending-meta strong{display:block;margin-top:3px}
            .fflhub-sending-section{border-top:1px solid #f0f0f1;padding-top:12px;margin-top:12px}
            .fflhub-sending-section h3{margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.02em;color:#3c434a}
            .fflhub-sending-section p{margin:5px 0}
            .fflhub-sending-items th,.fflhub-sending-items td{font-size:12px}
            .fflhub-sending-labels{margin:0}
            .fflhub-sending-labels li{display:grid;grid-template-columns:1.2fr .9fr 1fr;gap:8px;margin:6px 0;align-items:center}
            .fflhub-sending-empty{background:#fff;border:1px dashed #c3c4c7;border-radius:8px;padding:18px;color:#646970}
            @media (max-width:782px){.fflhub-sending-grid{grid-template-columns:1fr}.fflhub-sending-meta{grid-template-columns:1fr 1fr}.fflhub-sending-filter{display:block}.fflhub-sending-filter .button{margin-top:10px}.fflhub-sending-filter .fflhub-sending-debug-toggle{grid-template-columns:auto 1fr;margin-top:10px}}
        </style>
        <?php
    }
}
