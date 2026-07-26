<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Shipping\EasyPost\EasyPostBatchLabelService;
use FFLHub\Shipping\EasyPost\EasyPostBatchLabelStore;
use FFLHub\WMS\SendingOrdersService;
use FFLHub\WMS\SendingPackingService;
use WP_Error;

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
        add_action('admin_post_fflhub_sending_download_easypost_packet', [$this, 'download_easypost_print_packet']);
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

        $batch_action_result = null;

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

        if ($batch_action_result === null && $this->should_handle_batch_action()) {
            $batch_action_result = $this->handle_batch_action($orders);
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

            <?php if (is_array($batch_action_result)) : ?>
                <?php $this->render_batch_action_notice($batch_action_result); ?>
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

            <?php $this->render_easypost_batch_panel($job_scan_limit, $debug_ready, !empty($orders)); ?>

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

    private function should_handle_batch_action(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['fflhub_sending_batch_action'])) {
            return false;
        }

        $nonce = isset($_POST['fflhub_sending_batch_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_sending_batch_nonce']))
            : '';

        return $nonce !== '' && wp_verify_nonce($nonce, 'fflhub_sending_batch_action');
    }

    public function download_easypost_print_packet(): void
    {
        WMSAdminPage::ensure_access();

        $nonce = isset($_POST['fflhub_sending_batch_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_sending_batch_nonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'fflhub_sending_batch_action')) {
            wp_die(esc_html__('Invalid EasyPost batch download request.', 'ffl-hub'));
        }

        $batch_id = $this->posted_batch_id();
        if ($batch_id <= 0) {
            wp_die(esc_html__('Missing EasyPost batch ID.', 'ffl-hub'));
        }

        $document = (new EasyPostBatchLabelService())->print_packet($batch_id);
        if (is_wp_error($document)) {
            wp_die(esc_html($document->get_error_message()));
        }

        $body = (string) ($document['body'] ?? '');
        if ($body === '') {
            wp_die(esc_html__('The EasyPost print packet was empty.', 'ffl-hub'));
        }

        nocache_headers();
        header('Content-Type: ' . (string) ($document['content_type'] ?? 'application/pdf'));
        header('Content-Disposition: attachment; filename="' . sanitize_file_name((string) ($document['filename'] ?? 'easypost-batch-print-packet.pdf')) . '"');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     * @return array<string,mixed>
     */
    private function handle_batch_action(array $orders): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $action = sanitize_key(wp_unslash((string) ($_POST['fflhub_sending_batch_action'] ?? '')));
        $service = new EasyPostBatchLabelService();

        switch ($action) {
            case 'prepare_easypost_batch':
                $result = $service->prepare_from_ready_orders($orders);
                break;
            case 'submit_buy_easypost_batch':
                $result = $service->submit_or_buy($this->posted_batch_id());
                break;
            case 'refresh_easypost_batch':
                $result = $service->refresh($this->posted_batch_id());
                break;
            default:
                return [
                    'type' => 'error',
                    'message' => __('Unknown EasyPost batch action.', 'ffl-hub'),
                    'details' => [],
                ];
        }

        if (is_wp_error($result)) {
            return $this->action_result_from_error($result);
        }

        return $this->action_result_from_batch_response((array) $result, $action);
    }

    private function posted_batch_id(): int
    {
        return isset($_POST['batch_id'])
            ? max(0, (int) sanitize_text_field(wp_unslash((string) $_POST['batch_id'])))
            : 0;
    }

    private function action_result_from_error(WP_Error $error): array
    {
        $details = [];
        $data = $error->get_error_data();
        if (is_array($data)) {
            foreach ((array) ($data['problems'] ?? []) as $problem) {
                if (is_array($problem)) {
                    $details[] = trim(
                        '#' . (string) ($problem['order_number'] ?? $problem['order_id'] ?? '-') .
                        ': ' . (string) ($problem['message'] ?? '')
                    );
                }
            }
        }

        return [
            'type' => 'error',
            'message' => $error->get_error_message(),
            'details' => array_slice(array_values(array_filter($details)), 0, 8),
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function action_result_from_batch_response(array $response, string $action): array
    {
        $batch = is_array($response['batch'] ?? null) ? $response['batch'] : [];
        $stats = is_array($response['stats'] ?? null) ? $response['stats'] : [];
        $problems = is_array($response['problems'] ?? null) ? $response['problems'] : [];
        $status = (string) ($batch['status'] ?? '-');

        if ($action === 'prepare_easypost_batch') {
            $message = sprintf(
                __('Prepared EasyPost batch #%1$d with %2$d package(s). No labels were purchased yet.', 'ffl-hub'),
                (int) ($batch['id'] ?? 0),
                (int) ($stats['packages_prepared'] ?? $batch['item_count'] ?? 0)
            );
        } elseif ($action === 'submit_buy_easypost_batch') {
            if ((string) ($response['fallback'] ?? '') === 'individual_shipments') {
                $message = sprintf(
                    __('EasyPost batch #%1$d could not be batch-bought, so FFL Hub bought the prepared shipments individually. Current status: %2$s. Labels saved: %3$d.', 'ffl-hub'),
                    (int) ($batch['id'] ?? 0),
                    $status,
                    (int) ($response['labels_saved'] ?? 0)
                );
            } else {
                $message = sprintf(
                    __('EasyPost batch #%1$d submitted/buy requested. Current status: %2$s. Labels saved: %3$d.', 'ffl-hub'),
                    (int) ($batch['id'] ?? 0),
                    $status,
                    (int) ($response['labels_saved'] ?? 0)
                );
            }
        } else {
            $message = sprintf(
                __('EasyPost batch #%1$d refreshed. Current status: %2$s. Labels saved: %3$d.', 'ffl-hub'),
                (int) ($batch['id'] ?? 0),
                $status,
                (int) ($response['labels_saved'] ?? 0)
            );
        }

        $details = [];
        foreach (array_slice($problems, 0, 8) as $problem) {
            if (is_array($problem)) {
                $details[] = trim(
                    '#' . (string) ($problem['order_number'] ?? $problem['order_id'] ?? '-') .
                    ': ' . (string) ($problem['message'] ?? '')
                );
            }
        }
        foreach (array_slice((array) ($response['errors'] ?? []), 0, 8) as $error) {
            $details[] = (string) $error;
        }

        return [
            'type' => 'success',
            'message' => $message,
            'details' => array_values(array_filter($details)),
        ];
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

    private function render_easypost_batch_panel(int $job_scan_limit, bool $debug_ready, bool $has_orders): void
    {
        $recent = EasyPostBatchLabelStore::recent(6);
        ?>
        <div class="fflhub-sending-batch-panel">
            <div class="fflhub-sending-batch-header">
                <div>
                    <h2><?php esc_html_e('EasyPost Batch Labels', 'ffl-hub'); ?></h2>
                    <p>
                        <?php esc_html_e('Prepare rates from ready orders, then explicitly submit/buy. The print packet alternates each purchased label with its matching packing slip.', 'ffl-hub'); ?>
                    </p>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">
                    <?php wp_nonce_field('fflhub_sending_batch_action', 'fflhub_sending_batch_nonce'); ?>
                    <input type="hidden" name="fflhub_sending_batch_action" value="prepare_easypost_batch" />
                    <input type="hidden" name="job_scan_limit" value="<?php echo esc_attr((string) $job_scan_limit); ?>" />
                    <?php if ($debug_ready) : ?>
                        <input type="hidden" name="debug_ready" value="1" />
                    <?php endif; ?>
                    <?php submit_button(__('Prepare EasyPost Batch', 'ffl-hub'), 'secondary', '', false, $has_orders ? [] : ['disabled' => 'disabled']); ?>
                </form>
            </div>

            <?php if (empty($recent)) : ?>
                <div class="fflhub-sending-empty">
                    <?php esc_html_e('No EasyPost batch labels have been prepared yet.', 'ffl-hub'); ?>
                </div>
            <?php else : ?>
                <table class="widefat striped fflhub-sending-batch-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Batch', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Status', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Packages', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Updated', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Actions', 'ffl-hub'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $batch) : ?>
                            <?php $this->render_easypost_batch_row($batch); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $batch
     */
    private function render_easypost_batch_row(array $batch): void
    {
        $batch_id = (int) ($batch['id'] ?? 0);
        $items = is_array($batch['items'] ?? null) ? $batch['items'] : [];
        $status = (string) ($batch['status'] ?? '-');
        $can_submit = !in_array($status, [EasyPostBatchLabelStore::STATUS_LABELS_SAVED, EasyPostBatchLabelStore::STATUS_FAILED], true);
        ?>
        <tr>
            <td>
                <strong>#<?php echo esc_html((string) $batch_id); ?></strong>
                <span class="fflhub-sending-muted"><?php echo esc_html((string) ($batch['reference'] ?? '')); ?></span>
                <?php if ((string) ($batch['provider_batch_id'] ?? '') !== '') : ?>
                    <code><?php echo esc_html((string) ($batch['provider_batch_id'] ?? '')); ?></code>
                <?php endif; ?>
            </td>
            <td>
                <span class="fflhub-sending-pill <?php echo esc_attr($this->batch_status_class($status)); ?>">
                    <?php echo esc_html($status); ?>
                </span>
                <?php if ((string) ($batch['error_message'] ?? '') !== '') : ?>
                    <span class="fflhub-sending-error-text"><?php echo esc_html((string) ($batch['error_message'] ?? '')); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php $this->render_easypost_batch_items($items); ?>
            </td>
            <td><?php echo esc_html($this->local_time((string) ($batch['updated_at'] ?? ''))); ?></td>
            <td>
                <div class="fflhub-sending-batch-actions">
                    <?php $this->render_easypost_batch_button($batch_id, 'submit_buy_easypost_batch', __('Submit / Buy', 'ffl-hub'), 'button-primary', !$can_submit); ?>
                    <?php $this->render_easypost_batch_button($batch_id, 'refresh_easypost_batch', __('Refresh / Save', 'ffl-hub')); ?>
                    <?php $this->render_easypost_batch_button($batch_id, 'download_easypost_print_packet', __('Download Alternating PDF', 'ffl-hub')); ?>
                </div>
            </td>
        </tr>
        <?php
    }

    private function render_easypost_batch_button(int $batch_id, string $action, string $label, string $class = 'button', bool $disabled = false): void
    {
        $confirm = $action === 'submit_buy_easypost_batch'
            ? __('This will submit/buy EasyPost labels for this prepared batch. Continue?', 'ffl-hub')
            : '';
        $form_action = $action === 'download_easypost_print_packet'
            ? admin_url('admin-post.php')
            : admin_url('admin.php?page=' . self::PAGE_SLUG);
        ?>
        <form method="post" action="<?php echo esc_url($form_action); ?>">
            <?php wp_nonce_field('fflhub_sending_batch_action', 'fflhub_sending_batch_nonce'); ?>
            <?php if ($action === 'download_easypost_print_packet') : ?>
                <input type="hidden" name="action" value="fflhub_sending_download_easypost_packet" />
            <?php endif; ?>
            <input type="hidden" name="fflhub_sending_batch_action" value="<?php echo esc_attr($action); ?>" />
            <input type="hidden" name="batch_id" value="<?php echo esc_attr((string) $batch_id); ?>" />
            <button
                type="submit"
                class="button <?php echo esc_attr($class); ?>"
                <?php disabled($disabled); ?>
                <?php if ($confirm !== '') : ?>
                    onclick="return confirm('<?php echo esc_js($confirm); ?>');"
                <?php endif; ?>>
                <?php echo esc_html($label); ?>
            </button>
        </form>
        <?php
    }

    /**
     * @param array<int,mixed> $items
     */
    private function render_easypost_batch_items(array $items): void
    {
        if (empty($items)) {
            echo '<span class="fflhub-sending-muted">-</span>';
            return;
        }

        echo '<ul class="fflhub-sending-mini-list">';
        foreach (array_slice($items, 0, 8) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $order = (string) ($item['order_number'] ?? $item['order_id'] ?? '-');
            $package = is_array($item['package'] ?? null) ? $item['package'] : [];
            $rate = is_array($item['rate'] ?? null) ? $item['rate'] : [];
            $package_name = trim((string) ($package['name'] ?? $package['package_name'] ?? 'Package'));
            $service = trim((string) ($rate['carrier_friendly_name'] ?? $rate['carrier_code'] ?? 'EasyPost') . ' ' . (string) ($rate['service_name'] ?? $rate['service_code'] ?? ''));
            $amount = (float) ($rate['total_amount'] ?? 0);
            echo '<li>';
            echo '<strong>#' . esc_html($order) . '</strong> ';
            echo esc_html($package_name);
            if ($service !== '') {
                echo ' <span class="fflhub-sending-muted-inline">' . esc_html($service) . '</span>';
            }
            if ($amount > 0) {
                echo ' <code>$' . esc_html(number_format($amount, 2)) . '</code>';
            }
            if ((string) ($item['batch_status'] ?? '') !== '') {
                echo ' <span class="fflhub-sending-muted-inline">' . esc_html((string) ($item['batch_status'] ?? '')) . '</span>';
            }
            echo '</li>';
        }
        if (count($items) > 8) {
            echo '<li class="fflhub-sending-muted">' . esc_html(sprintf(__('+%d more package(s)', 'ffl-hub'), count($items) - 8)) . '</li>';
        }
        echo '</ul>';
    }

    private function batch_status_class(string $status): string
    {
        if ($status === EasyPostBatchLabelStore::STATUS_LABELS_SAVED || $status === EasyPostBatchLabelStore::STATUS_LABEL_GENERATED) {
            return 'is-labeled';
        }
        if ($status === EasyPostBatchLabelStore::STATUS_FAILED) {
            return 'packing-failed';
        }

        return 'needs-label';
    }

    /**
     * @param array<string,mixed> $result
     */
    private function render_batch_action_notice(array $result): void
    {
        $type = (string) ($result['type'] ?? 'info');
        $class = $type === 'error' ? 'notice-error' : 'notice-success';
        ?>
        <div class="notice <?php echo esc_attr($class); ?> inline fflhub-sending-batch-notice">
            <p><strong><?php echo esc_html((string) ($result['message'] ?? '')); ?></strong></p>
            <?php if (!empty($result['details']) && is_array($result['details'])) : ?>
                <ul>
                    <?php foreach ((array) $result['details'] as $detail) : ?>
                        <li><?php echo esc_html((string) $detail); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
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
            .fflhub-sending-batch-notice{margin:0 0 16px}
            .fflhub-sending-batch-notice ul{margin:8px 0 0 18px;list-style:disc}
            .fflhub-sending-batch-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;margin-bottom:16px}
            .fflhub-sending-batch-header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:12px}
            .fflhub-sending-batch-header h2{margin:0 0 4px;font-size:18px;line-height:1.2}
            .fflhub-sending-batch-header p{margin:0;color:#646970;max-width:760px}
            .fflhub-sending-batch-table{border:1px solid #dcdcde}
            .fflhub-sending-batch-table td{vertical-align:top}
            .fflhub-sending-batch-actions{display:flex;align-items:flex-start;flex-wrap:wrap;gap:6px}
            .fflhub-sending-batch-actions form{margin:0}
            .fflhub-sending-muted-inline{color:#646970;font-size:12px}
            .fflhub-sending-error-text{display:block;margin-top:6px;color:#8a2424}
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
            @media (max-width:782px){.fflhub-sending-filter,.fflhub-sending-packing-form,.fflhub-sending-batch-header{display:block}.fflhub-sending-filter .button,.fflhub-sending-packing-form .button,.fflhub-sending-batch-header .button{margin-top:10px}.fflhub-sending-filter .fflhub-sending-debug-toggle{grid-template-columns:auto 1fr;margin-top:10px}}
        </style>
        <?php
    }
}
