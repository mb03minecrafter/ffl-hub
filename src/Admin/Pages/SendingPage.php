<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\WMS\OrderWaverService;
use FFLHub\WMS\OrderWaverStore;
use FFLHub\WMS\SendingOrdersService;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WMS page for selecting ready dealer-fulfilled orders into outbound waves.
 *
 * The page no longer packs or buys labels inside the admin request. Clicking
 * "Wave Orders For Sending" only creates durable batch rows; Action Scheduler
 * workers move those batches through packing and EasyPost label purchase.
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
            __('Order Waver', 'ffl-hub'),
            __('Order Waver', 'ffl-hub'),
            WMSAdminPage::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        WMSAdminPage::ensure_access();
        OrderWaverStore::ensure_schema();

        $job_scan_limit = $this->read_job_scan_limit();
        $debug_ready = $this->read_bool('debug_ready');
        $result = (new SendingOrdersService($this->jobs_table))->ready_orders($job_scan_limit, $debug_ready);
        $orders = $result['orders'];
        $stats = $result['stats'];
        $wave_result = null;

        if ($this->should_create_wave()) {
            $wave_result = $this->handle_wave_submission($orders);
        }

        $orders = $this->decorate_orders($orders);
        $recent_batches = OrderWaverStore::recent_batches(10);
        ?>
        <div class="wrap fflhub-sending-page">
            <?php $this->render_styles(); ?>
            <h1><?php esc_html_e('Order Waver', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Select ready dealer-fulfilled orders into sending waves. Packing and EasyPost label buying run asynchronously once per minute.', 'ffl-hub'); ?>
            </p>

            <div class="fflhub-sending-stats">
                <?php $this->render_stat(__('Ready Orders', 'ffl-hub'), (string) ($stats['ready_orders'] ?? 0)); ?>
                <?php $this->render_stat(__('Selectable', 'ffl-hub'), (string) $this->selectable_count($orders)); ?>
                <?php $this->render_stat(__('Need Labels', 'ffl-hub'), (string) ($stats['needs_label'] ?? 0)); ?>
                <?php $this->render_stat(__('Have Labels', 'ffl-hub'), (string) ($stats['has_label'] ?? 0)); ?>
                <?php $this->render_stat(__('Jobs Scanned', 'ffl-hub'), (string) ($stats['jobs_scanned'] ?? 0)); ?>
                <?php if ($debug_ready) : ?>
                    <?php $this->render_stat(__('Debug Ready', 'ffl-hub'), (string) ($stats['debug_ready_orders'] ?? 0)); ?>
                <?php endif; ?>
            </div>

            <?php $this->render_wave_notice($wave_result); ?>

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
                        <?php esc_html_e('Shows otherwise-unreceived dealer-fulfilled success orders as ready for UI testing only.', 'ffl-hub'); ?>
                    </span>
                </label>
                <?php submit_button(__('Refresh', 'ffl-hub'), 'secondary', '', false); ?>
            </form>

            <?php $this->render_batches($recent_batches); ?>

            <?php $this->render_orders_form($orders, $job_scan_limit, $debug_ready); ?>
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

    private function should_create_wave(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['fflhub_order_waver_create_wave'])) {
            return false;
        }

        $nonce = isset($_POST['fflhub_order_waver_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_order_waver_nonce']))
            : '';

        return $nonce !== '' && wp_verify_nonce($nonce, 'fflhub_order_waver_create_wave');
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     * @return array<string,mixed>|WP_Error
     */
    private function handle_wave_submission(array $orders)
    {
        $selected = [];
        foreach ((array) ($_POST['order_ids'] ?? []) as $raw) {
            $selected[] = (int) sanitize_text_field(wp_unslash((string) $raw));
        }

        return (new OrderWaverService())->create_wave($orders, array_values(array_unique($selected)), get_current_user_id());
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     * @return array<int,array<string,mixed>>
     */
    private function decorate_orders(array $orders): array
    {
        $active = OrderWaverStore::active_order_rows_by_order_id();
        $failures = OrderWaverStore::latest_failure_rows_by_order_id();

        foreach ($orders as &$order) {
            $order_id = (int) ($order['order_id'] ?? 0);
            $active_row = $active[$order_id] ?? null;
            $failure_row = $failures[$order_id] ?? null;

            $order['wave_active'] = is_array($active_row);
            $order['wave_batch_id'] = is_array($active_row) ? (int) ($active_row['batch_id'] ?? 0) : 0;
            $order['wave_status'] = is_array($active_row) ? (string) ($active_row['status'] ?? '') : '';
            $order['wave_fail_reason'] = is_array($failure_row) ? (string) ($failure_row['fail_reason'] ?? '') : '';
            $order['wave_failed_at'] = is_array($failure_row) ? (string) ($failure_row['updated_at'] ?? '') : '';
            $order['wave_selectable'] = empty($order['has_active_label']) && empty($order['debug_ready']) && !is_array($active_row);
        }
        unset($order);

        return $orders;
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     */
    private function selectable_count(array $orders): int
    {
        return count(array_filter($orders, static fn(array $row): bool => !empty($row['wave_selectable'])));
    }

    /**
     * @param mixed $result
     */
    private function render_wave_notice($result): void
    {
        if ($result === null) {
            return;
        }

        if (is_wp_error($result)) {
            $details = [];
            $data = $result->get_error_data();
            foreach ((array) (is_array($data) ? ($data['skipped'] ?? []) : []) as $row) {
                if (is_array($row)) {
                    $details[] = '#' . (string) ($row['order_number'] ?? $row['order_id'] ?? '-') . ': ' . (string) ($row['message'] ?? '');
                }
            }
            ?>
            <div class="notice notice-error inline fflhub-sending-notice">
                <p><strong><?php echo esc_html($result->get_error_message()); ?></strong></p>
                <?php $this->render_notice_details($details); ?>
            </div>
            <?php
            return;
        }

        $batch = is_array($result['batch'] ?? null) ? $result['batch'] : [];
        $details = [];
        foreach ((array) ($result['skipped'] ?? []) as $row) {
            if (is_array($row)) {
                $details[] = '#' . (string) ($row['order_number'] ?? $row['order_id'] ?? '-') . ': ' . (string) ($row['message'] ?? '');
            }
        }
        ?>
        <div class="notice notice-success inline fflhub-sending-notice">
            <p>
                <strong>
                    <?php
                    echo esc_html(sprintf(
                        __('Wave #%1$d created with %2$d order(s). The packing worker will pick it up on the next minute run.', 'ffl-hub'),
                        (int) ($batch['id'] ?? 0),
                        (int) ($result['selected_count'] ?? 0)
                    ));
                    ?>
                </strong>
            </p>
            <?php $this->render_notice_details($details); ?>
        </div>
        <?php
    }

    /**
     * @param string[] $details
     */
    private function render_notice_details(array $details): void
    {
        $details = array_values(array_filter(array_map('strval', $details)));
        if (empty($details)) {
            return;
        }

        echo '<ul>';
        foreach (array_slice($details, 0, 10) as $detail) {
            echo '<li>' . esc_html($detail) . '</li>';
        }
        echo '</ul>';
    }

    /**
     * @param array<int,array<string,mixed>> $batches
     */
    private function render_batches(array $batches): void
    {
        ?>
        <section class="fflhub-sending-panel">
            <div class="fflhub-sending-panel-head">
                <div>
                    <h2><?php esc_html_e('Wave Batches', 'ffl-hub'); ?></h2>
                    <p><?php esc_html_e('Each batch moves queued -> packing -> ready for labels -> labeling -> labels saved. Logs and failed order reasons are stored in SQL.', 'ffl-hub'); ?></p>
                </div>
            </div>

            <?php if (empty($batches)) : ?>
                <div class="fflhub-sending-empty"><?php esc_html_e('No Order Waver batches have been created yet.', 'ffl-hub'); ?></div>
            <?php else : ?>
                <table class="widefat striped fflhub-sending-batch-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Batch', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Stage', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Counts', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Orders', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Latest Logs', 'ffl-hub'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batches as $batch) : ?>
                            <?php $this->render_batch_row($batch); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * @param array<string,mixed> $batch
     */
    private function render_batch_row(array $batch): void
    {
        $status = (string) ($batch['status'] ?? '');
        ?>
        <tr>
            <td>
                <strong>#<?php echo esc_html((string) ($batch['id'] ?? 0)); ?></strong>
                <span class="fflhub-sending-muted"><?php echo esc_html((string) ($batch['batch_key'] ?? '')); ?></span>
                <?php if ((int) ($batch['easypost_batch_id'] ?? 0) > 0) : ?>
                    <code>EasyPost #<?php echo esc_html((string) ((int) ($batch['easypost_batch_id'] ?? 0))); ?></code>
                <?php endif; ?>
                <span class="fflhub-sending-muted"><?php echo esc_html($this->local_time((string) ($batch['created_at'] ?? ''))); ?></span>
            </td>
            <td>
                <?php $this->render_status_pill($status); ?>
                <?php if (trim((string) ($batch['error_message'] ?? '')) !== '') : ?>
                    <span class="fflhub-sending-error-text"><?php echo esc_html((string) ($batch['error_message'] ?? '')); ?></span>
                <?php endif; ?>
                <span class="fflhub-sending-muted"><?php echo esc_html('Updated ' . $this->local_time((string) ($batch['updated_at'] ?? ''))); ?></span>
            </td>
            <td>
                <div class="fflhub-sending-counts">
                    <span>Selected <strong><?php echo esc_html((string) ((int) ($batch['selected_count'] ?? 0))); ?></strong></span>
                    <span>Packed <strong><?php echo esc_html((string) ((int) ($batch['packed_count'] ?? 0))); ?></strong></span>
                    <span>Failed <strong><?php echo esc_html((string) ((int) ($batch['failed_count'] ?? 0))); ?></strong></span>
                    <span>Labels <strong><?php echo esc_html((string) ((int) ($batch['label_saved_count'] ?? 0))); ?></strong></span>
                </div>
            </td>
            <td><?php $this->render_wave_orders_summary((array) ($batch['orders'] ?? [])); ?></td>
            <td><?php $this->render_wave_logs((array) ($batch['logs'] ?? [])); ?></td>
        </tr>
        <?php
    }

    /**
     * @param array<int,mixed> $orders
     */
    private function render_wave_orders_summary(array $orders): void
    {
        if (empty($orders)) {
            echo '<span class="fflhub-sending-muted">-</span>';
            return;
        }

        echo '<ul class="fflhub-sending-mini-list">';
        foreach (array_slice($orders, 0, 8) as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo '<li>';
            echo '<strong>#' . esc_html((string) ($row['order_number'] ?? $row['order_id'] ?? '-')) . '</strong> ';
            $this->render_status_pill((string) ($row['status'] ?? ''));
            if (trim((string) ($row['fail_reason'] ?? '')) !== '') {
                echo '<span class="fflhub-sending-error-text">' . esc_html((string) ($row['fail_reason'] ?? '')) . '</span>';
            }
            echo '</li>';
        }
        if (count($orders) > 8) {
            echo '<li class="fflhub-sending-muted">' . esc_html(sprintf(__('+%d more order(s)', 'ffl-hub'), count($orders) - 8)) . '</li>';
        }
        echo '</ul>';
    }

    /**
     * @param array<int,mixed> $logs
     */
    private function render_wave_logs(array $logs): void
    {
        if (empty($logs)) {
            echo '<span class="fflhub-sending-muted">-</span>';
            return;
        }

        echo '<ul class="fflhub-sending-mini-list">';
        foreach ($logs as $log) {
            if (!is_array($log)) {
                continue;
            }
            echo '<li class="fflhub-sending-log-line">';
            echo '<strong>' . esc_html((string) ($log['stage'] ?? 'log')) . '</strong> ';
            echo esc_html((string) ($log['message'] ?? ''));
            echo '<span class="fflhub-sending-muted">' . esc_html($this->local_time((string) ($log['created_at'] ?? ''))) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     */
    private function render_orders_form(array $orders, int $job_scan_limit, bool $debug_ready): void
    {
        if (empty($orders)) {
            ?>
            <div class="fflhub-sending-empty">
                <?php esc_html_e('No ready-to-ship dealer-fulfilled orders were found in the scanned job window.', 'ffl-hub'); ?>
            </div>
            <?php
            return;
        }

        $selectable = $this->selectable_count($orders);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>" class="fflhub-sending-wave-form">
            <?php wp_nonce_field('fflhub_order_waver_create_wave', 'fflhub_order_waver_nonce'); ?>
            <input type="hidden" name="job_scan_limit" value="<?php echo esc_attr((string) $job_scan_limit); ?>" />
            <?php if ($debug_ready) : ?>
                <input type="hidden" name="debug_ready" value="1" />
            <?php endif; ?>

            <div class="fflhub-sending-wave-toolbar">
                <div>
                    <h2><?php esc_html_e('Ready Orders', 'ffl-hub'); ?></h2>
                    <p><?php echo esc_html(sprintf(__('%d order(s) are selectable. Ready orders are selected by default unless already labeled, already in a wave, or shown only by debug mode.', 'ffl-hub'), $selectable)); ?></p>
                </div>
                <?php submit_button(__('Wave Orders For Sending', 'ffl-hub'), 'primary', 'fflhub_order_waver_create_wave', false, $selectable > 0 ? [] : ['disabled' => 'disabled']); ?>
            </div>

            <div class="fflhub-sending-table-wrap">
                <table class="widefat striped fflhub-sending-table">
                    <thead>
                        <tr>
                            <th class="check-column"><span class="screen-reader-text"><?php esc_html_e('Select', 'ffl-hub'); ?></span></th>
                            <th><?php esc_html_e('Order', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Customer', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Ready', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Received', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Wave / Label', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Distributor / PO', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Items', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Open', 'ffl-hub'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order) : ?>
                            <?php $this->render_order_row($order); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
        <?php
    }

    /**
     * @param array<string,mixed> $order
     */
    private function render_order_row(array $order): void
    {
        $debug_ready = !empty($order['debug_ready']);
        $selectable = !empty($order['wave_selectable']);
        ?>
        <tr class="<?php echo $debug_ready ? 'is-debug-ready' : ''; ?>">
            <th class="check-column">
                <input
                    type="checkbox"
                    name="order_ids[]"
                    value="<?php echo esc_attr((string) ((int) ($order['order_id'] ?? 0))); ?>"
                    <?php checked($selectable); ?>
                    <?php disabled(!$selectable); ?> />
            </th>
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
            <td>
                <?php $this->render_order_wave_state($order); ?>
            </td>
            <td>
                <div class="fflhub-sending-stack">
                    <span><?php echo esc_html($this->join_or_dash((array) ($order['distributors'] ?? []))); ?></span>
                    <span><?php echo esc_html($this->join_or_dash((array) ($order['merchant_pos'] ?? []))); ?></span>
                </div>
            </td>
            <td><?php $this->render_items_summary((array) ($order['items'] ?? [])); ?></td>
            <td>
                <a class="button" href="<?php echo esc_url((string) ($order['order_edit_url'] ?? '')); ?>">
                    <?php esc_html_e('Open', 'ffl-hub'); ?>
                </a>
            </td>
        </tr>
        <?php
    }

    /**
     * @param array<string,mixed> $order
     */
    private function render_order_wave_state(array $order): void
    {
        if (!empty($order['has_active_label'])) {
            $this->render_status_pill('label_purchased');
            $this->render_label_summary((array) ($order['active_labels'] ?? []));
            return;
        }

        if (!empty($order['wave_active'])) {
            $this->render_status_pill((string) ($order['wave_status'] ?? 'active_wave'));
            echo '<span class="fflhub-sending-muted">' . esc_html(sprintf(__('Wave #%d', 'ffl-hub'), (int) ($order['wave_batch_id'] ?? 0))) . '</span>';
            return;
        }

        if (trim((string) ($order['wave_fail_reason'] ?? '')) !== '') {
            $this->render_status_pill('last_wave_failed');
            echo '<span class="fflhub-sending-error-text">' . esc_html((string) ($order['wave_fail_reason'] ?? '')) . '</span>';
            echo '<span class="fflhub-sending-muted">' . esc_html($this->local_time((string) ($order['wave_failed_at'] ?? ''))) . '</span>';
            return;
        }

        $this->render_status_pill('ready_to_wave');
    }

    private function render_status_pill(string $status): void
    {
        $status = trim($status) !== '' ? trim($status) : 'unknown';
        $class = 'is-neutral';
        if (in_array($status, ['ready_to_wave', OrderWaverStore::BATCH_STATUS_LABELS_SAVED, OrderWaverStore::ORDER_STATUS_LABEL_SAVED, 'label_purchased'], true)) {
            $class = 'is-good';
        } elseif (strpos($status, 'failed') !== false) {
            $class = 'is-bad';
        } elseif (in_array($status, [OrderWaverStore::BATCH_STATUS_PACKING, OrderWaverStore::BATCH_STATUS_LABELING, OrderWaverStore::BATCH_STATUS_READY_FOR_LABELS, OrderWaverStore::ORDER_STATUS_PACKING, OrderWaverStore::ORDER_STATUS_PACKED], true)) {
            $class = 'is-working';
        }

        echo '<span class="fflhub-sending-pill ' . esc_attr($class) . '">' . esc_html(str_replace('_', ' ', $status)) . '</span>';
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

    private function render_stat(string $label, string $value): void
    {
        ?>
        <div class="fflhub-sending-stat">
            <span><?php echo esc_html($label); ?></span>
            <strong><?php echo esc_html($value); ?></strong>
        </div>
        <?php
    }

    private function render_styles(): void
    {
        ?>
        <style>
            .fflhub-sending-page{max-width:1540px}
            .fflhub-sending-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:18px 0}
            .fflhub-sending-stat,.fflhub-sending-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px}
            .fflhub-sending-stat{padding:14px}
            .fflhub-sending-stat span{display:block;color:#646970;font-size:12px;text-transform:uppercase;font-weight:700}
            .fflhub-sending-stat strong{display:block;margin-top:4px;font-size:24px;line-height:1.1;color:#1d2327}
            .fflhub-sending-filter,.fflhub-sending-wave-toolbar{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px;margin-bottom:16px}
            .fflhub-sending-filter{justify-content:flex-start}
            .fflhub-sending-filter label{display:grid;gap:4px}
            .fflhub-sending-filter label span{font-weight:700}
            .fflhub-sending-filter input{width:130px}
            .fflhub-sending-filter .fflhub-sending-debug-toggle{display:grid;grid-template-columns:auto minmax(260px,520px);align-items:start;gap:8px}
            .fflhub-sending-filter .fflhub-sending-debug-toggle input{width:auto;margin-top:4px}
            .fflhub-sending-filter .fflhub-sending-debug-toggle span{font-weight:400;color:#50575e}
            .fflhub-sending-filter .fflhub-sending-debug-toggle strong{display:block;color:#1d2327}
            .fflhub-sending-notice{margin:0 0 16px}
            .fflhub-sending-notice ul{margin:8px 0 0 18px;list-style:disc}
            .fflhub-sending-panel{padding:14px;margin-bottom:16px}
            .fflhub-sending-panel-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:12px}
            .fflhub-sending-panel h2,.fflhub-sending-wave-toolbar h2{margin:0 0 4px;font-size:18px;line-height:1.2}
            .fflhub-sending-panel p,.fflhub-sending-wave-toolbar p{margin:0;color:#646970}
            .fflhub-sending-wave-form{margin-top:16px}
            .fflhub-sending-table-wrap{background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:auto}
            .fflhub-sending-table,.fflhub-sending-batch-table{border:0}
            .fflhub-sending-table th,.fflhub-sending-table td,.fflhub-sending-batch-table th,.fflhub-sending-batch-table td{vertical-align:top}
            .fflhub-sending-table th:not(.check-column),.fflhub-sending-batch-table th{white-space:nowrap}
            .fflhub-sending-table tr.is-debug-ready td,.fflhub-sending-table tr.is-debug-ready th{background:#fffdf5}
            .fflhub-sending-order-cell{min-width:120px}
            .fflhub-sending-order-link{display:block;font-size:16px;font-weight:700;text-decoration:none}
            .fflhub-sending-order-cell span,.fflhub-sending-muted{display:block;color:#646970;font-size:12px;margin-top:3px}
            .fflhub-sending-stack{display:grid;gap:3px}
            .fflhub-sending-pill{display:inline-flex;align-items:center;white-space:nowrap;border-radius:999px;padding:4px 10px;font-size:11px;font-weight:800;text-transform:uppercase}
            .fflhub-sending-pill.is-good{background:#e6f6ed;color:#146c43}
            .fflhub-sending-pill.is-working{background:#fff4e5;color:#8a4b00}
            .fflhub-sending-pill.is-bad{background:#fde7e9;color:#8a2424}
            .fflhub-sending-pill.is-neutral{background:#f0f0f1;color:#1d2327}
            .fflhub-sending-pill.is-debug{background:#1d2327;color:#fff}
            .fflhub-sending-mini-list{margin:0;display:grid;gap:5px}
            .fflhub-sending-mini-list li{margin:0}
            .fflhub-sending-error-text{display:block;margin-top:6px;color:#8a2424;max-width:360px}
            .fflhub-sending-counts{display:grid;grid-template-columns:repeat(2,minmax(86px,1fr));gap:6px}
            .fflhub-sending-counts span{display:block;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:6px;color:#646970}
            .fflhub-sending-counts strong{display:block;color:#1d2327;font-size:16px}
            .fflhub-sending-log-line strong{display:block}
            .fflhub-sending-ffl-tag{display:inline-flex;border-radius:999px;background:#e5f0ff;color:#0a4b78;font-size:10px;font-weight:800;padding:1px 5px;vertical-align:middle}
            .fflhub-sending-empty{background:#fff;border:1px dashed #c3c4c7;border-radius:8px;padding:18px;color:#646970}
            @media (max-width:782px){.fflhub-sending-filter,.fflhub-sending-wave-toolbar,.fflhub-sending-panel-head{display:block}.fflhub-sending-filter .button,.fflhub-sending-wave-toolbar .button{margin-top:10px}.fflhub-sending-filter .fflhub-sending-debug-toggle{grid-template-columns:auto 1fr;margin-top:10px}}
        </style>
        <?php
    }
}
