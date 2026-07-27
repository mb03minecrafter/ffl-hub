<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Shipping\EasyPost\EasyPostBatchLabelStore;
use FFLHub\Shipping\PrintNode\PrintNodeOptions;
use FFLHub\Shipping\PrintNode\PrintNodePrintQueueService;
use FFLHub\WMS\OrderWaverStore;
use WC_Order;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WMS Sending station.
 *
 * Order Waver creates/monitors label batches. This page is deliberately the
 * physical shipping station: it only lists batches whose labels were saved and
 * exposes the PrintNode button for the finished label + packing-slip packet.
 */
final class SendingReadyPage
{
    private const PAGE_SLUG = 'fflhub-wms-sending';

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
        OrderWaverStore::ensure_schema();
        EasyPostBatchLabelStore::ensure_schema();

        $print_result = null;
        if ($this->should_print_batch()) {
            $print_result = $this->handle_print_batch();
        }

        $batches = $this->decorate_batches(OrderWaverStore::ready_to_send_batches(50));
        ?>
        <div class="wrap fflhub-sending-ready-page">
            <?php $this->render_styles(); ?>
            <h1><?php esc_html_e('Sending', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Finished wave batches whose labels are saved and ready to print for outbound shipping.', 'ffl-hub'); ?>
            </p>

            <div class="fflhub-sending-ready-stats">
                <?php $this->render_stat(__('Ready Batches', 'ffl-hub'), (string) count($batches)); ?>
                <?php $this->render_stat(__('Ready Orders', 'ffl-hub'), (string) $this->order_count($batches)); ?>
                <?php $this->render_stat(__('Saved Labels', 'ffl-hub'), (string) $this->label_count($batches)); ?>
                <?php $this->render_stat(__('PrintNode', 'ffl-hub'), PrintNodeOptions::configured() ? __('Ready', 'ffl-hub') : __('Not Ready', 'ffl-hub')); ?>
            </div>

            <?php $this->render_print_notice($print_result); ?>

            <?php if (!PrintNodeOptions::configured()) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <strong><?php esc_html_e('PrintNode is not fully configured.', 'ffl-hub'); ?></strong>
                        <?php esc_html_e('The Sending page can show ready batches, but printing is disabled until PrintNode has an API key and default printer.', 'ffl-hub'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=fflhub-shipping-printnode')); ?>"><?php esc_html_e('Open PrintNode settings', 'ffl-hub'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <?php $this->render_batches($batches); ?>
        </div>
        <?php
    }

    private function should_print_batch(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['fflhub_wms_sending_print_batch'])) {
            return false;
        }

        $nonce = isset($_POST['fflhub_wms_sending_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_wms_sending_nonce']))
            : '';

        return $nonce !== '' && wp_verify_nonce($nonce, 'fflhub_wms_sending_print_batch');
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function handle_print_batch()
    {
        $wave_batch_id = isset($_POST['wave_batch_id'])
            ? max(0, (int) sanitize_text_field(wp_unslash((string) $_POST['wave_batch_id'])))
            : 0;
        $batch = OrderWaverStore::batch_with_details($wave_batch_id, 8);
        if (!is_array($batch)) {
            return new WP_Error('fflhub_sending_missing_wave_batch', 'Could not find that sending wave batch.');
        }

        $status = (string) ($batch['status'] ?? '');
        if (!in_array($status, [OrderWaverStore::BATCH_STATUS_LABELS_SAVED, OrderWaverStore::BATCH_STATUS_PARTIAL_LABELS_SAVED], true)) {
            return new WP_Error('fflhub_sending_batch_not_ready', 'That wave batch is not ready for Sending yet.');
        }

        $easypost_batch_id = (int) ($batch['easypost_batch_id'] ?? 0);
        if ($easypost_batch_id <= 0) {
            return new WP_Error('fflhub_sending_missing_easypost_batch', 'That wave batch does not have an EasyPost batch id.');
        }

        $result = (new PrintNodePrintQueueService())->queue_easypost_batch($easypost_batch_id);
        if (is_wp_error($result)) {
            OrderWaverStore::log($wave_batch_id, 0, 'error', 'print_queue_failed', $result->get_error_message(), [
                'easypost_batch_id' => $easypost_batch_id,
                'error_code' => $result->get_error_code(),
                'error_data' => $result->get_error_data(),
            ]);
            return $result;
        }

        OrderWaverStore::log($wave_batch_id, 0, 'info', 'print_queued', sprintf(
            'Queued %d PrintNode job(s) for EasyPost batch #%d with a %d second delay.',
            (int) ($result['queued_count'] ?? 0),
            $easypost_batch_id,
            (int) ($result['delay_seconds'] ?? 0)
        ), [
            'run_key' => (string) ($result['run_key'] ?? ''),
            'queue_job_ids' => $result['queue_job_ids'] ?? [],
            'errors' => $result['errors'] ?? [],
        ]);

        return [
            'wave_batch' => $batch,
            'print_result' => $result,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $batches
     * @return array<int,array<string,mixed>>
     */
    private function decorate_batches(array $batches): array
    {
        foreach ($batches as &$batch) {
            $easypost_batch_id = (int) ($batch['easypost_batch_id'] ?? 0);
            $easypost = $easypost_batch_id > 0 ? EasyPostBatchLabelStore::get($easypost_batch_id) : null;
            $batch['easypost'] = is_array($easypost) ? $easypost : [];
            $response = is_array($batch['easypost']['response'] ?? null) ? $batch['easypost']['response'] : [];
            $batch['printnode_last_print'] = is_array($response['printnode_last_print'] ?? null)
                ? $response['printnode_last_print']
                : [];
        }
        unset($batch);

        return $batches;
    }

    /**
     * @param array<int,array<string,mixed>> $batches
     */
    private function render_batches(array $batches): void
    {
        if (empty($batches)) {
            ?>
            <div class="fflhub-sending-ready-empty">
                <?php esc_html_e('No wave batches are ready for Sending right now.', 'ffl-hub'); ?>
            </div>
            <?php
            return;
        }
        ?>
        <div class="fflhub-sending-ready-table-wrap">
            <table class="widefat striped fflhub-sending-ready-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Wave Batch', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('Print Status', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('Orders', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('EasyPost', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('Action', 'ffl-hub'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batches as $batch) : ?>
                        <?php $this->render_batch_row($batch); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $batch
     */
    private function render_batch_row(array $batch): void
    {
        $wave_batch_id = (int) ($batch['id'] ?? 0);
        $easypost_batch_id = (int) ($batch['easypost_batch_id'] ?? 0);
        $print = (array) ($batch['printnode_last_print'] ?? []);
        $has_pending_print = (int) ($print['pending_count'] ?? 0) > 0
            && in_array((string) ($print['status'] ?? ''), ['queued', 'printing', 'printing_with_errors'], true);
        $can_print = PrintNodeOptions::configured() && $easypost_batch_id > 0 && !$has_pending_print;
        ?>
        <tr>
            <td>
                <strong>#<?php echo esc_html((string) $wave_batch_id); ?></strong>
                <span class="fflhub-sending-ready-muted"><?php echo esc_html((string) ($batch['batch_key'] ?? '')); ?></span>
                <?php $this->render_status_pill((string) ($batch['status'] ?? '')); ?>
                <span class="fflhub-sending-ready-muted">
                    <?php echo esc_html('Labels ready ' . $this->local_time((string) ($batch['labels_at'] ?? $batch['updated_at'] ?? ''))); ?>
                </span>
            </td>
            <td><?php $this->render_print_status((array) ($batch['printnode_last_print'] ?? [])); ?></td>
            <td><?php $this->render_orders((array) ($batch['orders'] ?? [])); ?></td>
            <td><?php $this->render_easypost((array) ($batch['easypost'] ?? [])); ?></td>
            <td>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">
                    <?php wp_nonce_field('fflhub_wms_sending_print_batch', 'fflhub_wms_sending_nonce'); ?>
                    <input type="hidden" name="wave_batch_id" value="<?php echo esc_attr((string) $wave_batch_id); ?>" />
                    <button
                        type="submit"
                        class="button button-primary"
                        name="fflhub_wms_sending_print_batch"
                        value="1"
                        <?php disabled(!$can_print); ?>
                        onclick="return confirm('<?php echo esc_js(__('Queue all labels and packing slips for throttled PrintNode printing?', 'ffl-hub')); ?>');">
                        <?php esc_html_e('Print Batch', 'ffl-hub'); ?>
                    </button>
                </form>
                <?php if ($has_pending_print) : ?>
                    <span class="fflhub-sending-ready-muted"><?php esc_html_e('Print run already queued.', 'ffl-hub'); ?></span>
                <?php elseif (!$can_print) : ?>
                    <span class="fflhub-sending-ready-muted"><?php esc_html_e('Configure PrintNode first.', 'ffl-hub'); ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * @param array<string,mixed> $print
     */
    private function render_print_status(array $print): void
    {
        if (empty($print)) {
            $this->render_status_pill('not_printed');
            return;
        }

        $status = (string) ($print['status'] ?? 'queued');
        $queued = (int) ($print['queued_count'] ?? 0);
        $submitted = (int) ($print['submitted_count'] ?? 0);
        $pending = (int) ($print['pending_count'] ?? 0);
        $errors = (int) ($print['error_count'] ?? 0);
        $this->render_status_pill($status);
        echo '<span class="fflhub-sending-ready-muted">' . esc_html(sprintf(
            __('%1$d queued, %2$d printed, %3$d pending, %4$d error(s)', 'ffl-hub'),
            $queued,
            $submitted,
            $pending,
            $errors
        )) . '</span>';
        if (isset($print['delay_seconds'])) {
            echo '<span class="fflhub-sending-ready-muted">' . esc_html(sprintf(
                __('Delay: %d second(s) between queued jobs', 'ffl-hub'),
                (int) ($print['delay_seconds'] ?? 0)
            )) . '</span>';
        }
        echo '<span class="fflhub-sending-ready-muted">' . esc_html($this->local_time((string) ($print['updated_at'] ?? $print['queued_at'] ?? ''))) . '</span>';

        foreach (array_slice((array) ($print['errors'] ?? []), 0, 3) as $error) {
            echo '<span class="fflhub-sending-ready-error">' . esc_html((string) $error) . '</span>';
        }
    }

    /**
     * @param array<int,mixed> $orders
     */
    private function render_orders(array $orders): void
    {
        if (empty($orders)) {
            echo '<span class="fflhub-sending-ready-muted">-</span>';
            return;
        }

        echo '<ul class="fflhub-sending-ready-list">';
        foreach ($orders as $row) {
            if (!is_array($row)) {
                continue;
            }

            $order_id = (int) ($row['order_id'] ?? 0);
            $order = $order_id > 0 ? wc_get_order($order_id) : null;
            $url = $order instanceof WC_Order ? $order->get_edit_order_url() : '';
            echo '<li>';
            if ($url !== '') {
                echo '<a href="' . esc_url($url) . '"><strong>#' . esc_html((string) ($row['order_number'] ?? $order_id)) . '</strong></a>';
            } else {
                echo '<strong>#' . esc_html((string) ($row['order_number'] ?? $order_id)) . '</strong>';
            }
            echo ' ';
            $this->render_status_pill((string) ($row['status'] ?? ''));
            echo '<span class="fflhub-sending-ready-muted">' . esc_html(sprintf(
                __('%1$d package(s), %2$d label(s)', 'ffl-hub'),
                (int) ($row['package_count'] ?? 0),
                (int) ($row['label_count'] ?? 0)
            )) . '</span>';
            if (trim((string) ($row['fail_reason'] ?? '')) !== '') {
                echo '<span class="fflhub-sending-ready-error">' . esc_html((string) ($row['fail_reason'] ?? '')) . '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    /**
     * @param array<string,mixed> $easypost
     */
    private function render_easypost(array $easypost): void
    {
        if (empty($easypost)) {
            echo '<span class="fflhub-sending-ready-muted">-</span>';
            return;
        }

        echo '<strong>EasyPost #' . esc_html((string) ((int) ($easypost['id'] ?? 0))) . '</strong>';
        $this->render_status_pill((string) ($easypost['status'] ?? ''));
        if ((string) ($easypost['provider_batch_id'] ?? '') !== '') {
            echo '<code>' . esc_html((string) ($easypost['provider_batch_id'] ?? '')) . '</code>';
        }
        echo '<span class="fflhub-sending-ready-muted">' . esc_html(sprintf(
            __('%d package item(s)', 'ffl-hub'),
            (int) ($easypost['item_count'] ?? 0)
        )) . '</span>';
    }

    /**
     * @param mixed $result
     */
    private function render_print_notice($result): void
    {
        if ($result === null) {
            return;
        }

        if (is_wp_error($result)) {
            $details = [];
            $data = $result->get_error_data();
            foreach ((array) (is_array($data) ? ($data['errors'] ?? []) : []) as $error) {
                $details[] = (string) $error;
            }
            ?>
            <div class="notice notice-error inline fflhub-sending-ready-notice">
                <p><strong><?php echo esc_html($result->get_error_message()); ?></strong></p>
                <?php $this->render_notice_details($details); ?>
            </div>
            <?php
            return;
        }

        $print = is_array($result['print_result'] ?? null) ? $result['print_result'] : [];
        ?>
        <div class="notice notice-success inline fflhub-sending-ready-notice">
            <p>
                <strong>
                    <?php
                    echo esc_html(sprintf(
                        __('Queued %1$d PrintNode job(s) with a %2$d second delay between jobs.', 'ffl-hub'),
                        (int) ($print['queued_count'] ?? 0),
                        (int) ($print['delay_seconds'] ?? 0)
                    ));
                    ?>
                </strong>
            </p>
            <?php $this->render_notice_details((array) ($print['errors'] ?? [])); ?>
        </div>
        <?php
    }

    /**
     * @param array<int,mixed> $details
     */
    private function render_notice_details(array $details): void
    {
        $details = array_values(array_filter(array_map('strval', $details)));
        if (empty($details)) {
            return;
        }

        echo '<ul>';
        foreach (array_slice($details, 0, 8) as $detail) {
            echo '<li>' . esc_html($detail) . '</li>';
        }
        echo '</ul>';
    }

    private function render_status_pill(string $status): void
    {
        $status = trim($status) !== '' ? trim($status) : 'unknown';
        $class = 'is-neutral';
        if (in_array($status, ['printed', OrderWaverStore::ORDER_STATUS_LABEL_SAVED, OrderWaverStore::BATCH_STATUS_LABELS_SAVED], true)) {
            $class = 'is-good';
        } elseif (strpos($status, 'error') !== false || strpos($status, 'failed') !== false) {
            $class = 'is-bad';
        } elseif (in_array($status, ['not_printed', 'queued', 'printing', 'printing_with_errors', OrderWaverStore::BATCH_STATUS_PARTIAL_LABELS_SAVED], true)) {
            $class = 'is-working';
        }

        echo '<span class="fflhub-sending-ready-pill ' . esc_attr($class) . '">' . esc_html(str_replace('_', ' ', $status)) . '</span>';
    }

    /**
     * @param array<int,array<string,mixed>> $batches
     */
    private function order_count(array $batches): int
    {
        $count = 0;
        foreach ($batches as $batch) {
            $count += count((array) ($batch['orders'] ?? []));
        }

        return $count;
    }

    /**
     * @param array<int,array<string,mixed>> $batches
     */
    private function label_count(array $batches): int
    {
        $count = 0;
        foreach ($batches as $batch) {
            $count += (int) ($batch['label_saved_count'] ?? 0);
        }

        return $count;
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
        <div class="fflhub-sending-ready-stat">
            <span><?php echo esc_html($label); ?></span>
            <strong><?php echo esc_html($value); ?></strong>
        </div>
        <?php
    }

    private function render_styles(): void
    {
        ?>
        <style>
            .fflhub-sending-ready-page{max-width:1500px}
            .fflhub-sending-ready-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:18px 0}
            .fflhub-sending-ready-stat{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px}
            .fflhub-sending-ready-stat span{display:block;color:#646970;font-size:12px;text-transform:uppercase;font-weight:700}
            .fflhub-sending-ready-stat strong{display:block;margin-top:4px;font-size:24px;line-height:1.1;color:#1d2327}
            .fflhub-sending-ready-table-wrap{background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:auto;margin-top:16px}
            .fflhub-sending-ready-table{border:0}
            .fflhub-sending-ready-table th,.fflhub-sending-ready-table td{vertical-align:top}
            .fflhub-sending-ready-table th{white-space:nowrap}
            .fflhub-sending-ready-muted{display:block;color:#646970;font-size:12px;margin-top:4px}
            .fflhub-sending-ready-error{display:block;color:#8a2424;font-size:12px;margin-top:4px;max-width:420px}
            .fflhub-sending-ready-list{margin:0;display:grid;gap:8px}
            .fflhub-sending-ready-list li{margin:0}
            .fflhub-sending-ready-pill{display:inline-flex;align-items:center;white-space:nowrap;border-radius:999px;padding:4px 10px;font-size:11px;font-weight:800;text-transform:uppercase;margin:2px 4px 2px 0}
            .fflhub-sending-ready-pill.is-good{background:#e6f6ed;color:#146c43}
            .fflhub-sending-ready-pill.is-working{background:#fff4e5;color:#8a4b00}
            .fflhub-sending-ready-pill.is-bad{background:#fde7e9;color:#8a2424}
            .fflhub-sending-ready-pill.is-neutral{background:#f0f0f1;color:#1d2327}
            .fflhub-sending-ready-empty{background:#fff;border:1px dashed #c3c4c7;border-radius:8px;padding:18px;color:#646970;margin-top:16px}
            .fflhub-sending-ready-notice{margin:0 0 16px}
            .fflhub-sending-ready-notice ul{margin:8px 0 0 18px;list-style:disc}
        </style>
        <?php
    }
}
