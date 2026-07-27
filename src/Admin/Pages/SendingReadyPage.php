<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Shipping\EasyPost\EasyPostBatchLabelStore;
use FFLHub\Shipping\PrintNode\PrintNodeOptions;
use FFLHub\Shipping\PrintNode\PrintNodePrintQueueService;
use FFLHub\Shipping\ShipStation\ShipStationOrderMeta;
use FFLHub\Shipping\ShipStation\ShipStationRestController;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Receiving\ReceivingEventsStore;
use FFLHub\WMS\OrderWaverStore;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
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
        $packing_batch = $this->requested_packing_batch();
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
            <?php $this->render_packing_batch_notice($packing_batch); ?>

            <?php if (!PrintNodeOptions::configured()) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <strong><?php esc_html_e('PrintNode is not fully configured.', 'ffl-hub'); ?></strong>
                        <?php esc_html_e('The Sending page can show ready batches, but printing is disabled until PrintNode has an API key and default printer.', 'ffl-hub'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=fflhub-shipping-printnode')); ?>"><?php esc_html_e('Open PrintNode settings', 'ffl-hub'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (is_array($packing_batch)) : ?>
                <?php $this->render_packing_workflow($packing_batch); ?>
            <?php else : ?>
                <?php $this->render_batches($batches); ?>
            <?php endif; ?>
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
                <div class="fflhub-sending-ready-actions">
                    <a class="button" href="<?php echo esc_url($this->packing_url($wave_batch_id)); ?>">
                        <?php esc_html_e('Start Packing', 'ffl-hub'); ?>
                    </a>
                </div>
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
     * @return array<string,mixed>|WP_Error|null
     */
    private function requested_packing_batch()
    {
        if (!isset($_GET['packing_batch'])) {
            return null;
        }

        $wave_batch_id = absint($_GET['packing_batch']);
        $nonce = isset($_GET['_wpnonce'])
            ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce']))
            : '';
        if ($wave_batch_id <= 0 || !wp_verify_nonce($nonce, 'fflhub_wms_sending_pack_batch_' . $wave_batch_id)) {
            return new WP_Error('fflhub_sending_pack_batch_invalid', 'Invalid packing batch request.');
        }

        $batch = OrderWaverStore::batch_with_details($wave_batch_id, 10);
        if (!is_array($batch)) {
            return new WP_Error('fflhub_sending_pack_batch_missing', 'Could not find that wave batch.');
        }

        $status = (string) ($batch['status'] ?? '');
        if (!in_array($status, [OrderWaverStore::BATCH_STATUS_LABELS_SAVED, OrderWaverStore::BATCH_STATUS_PARTIAL_LABELS_SAVED], true)) {
            return new WP_Error('fflhub_sending_pack_batch_not_ready', 'That wave batch does not have saved labels yet.');
        }

        $decorated = $this->decorate_batches([$batch]);

        return $decorated[0] ?? $batch;
    }

    private function packing_url(int $wave_batch_id): string
    {
        return add_query_arg([
            'page' => self::PAGE_SLUG,
            'packing_batch' => $wave_batch_id,
            '_wpnonce' => wp_create_nonce('fflhub_wms_sending_pack_batch_' . $wave_batch_id),
        ], admin_url('admin.php'));
    }

    /**
     * @param mixed $batch
     */
    private function render_packing_batch_notice($batch): void
    {
        if (!is_wp_error($batch)) {
            return;
        }
        ?>
        <div class="notice notice-error inline fflhub-sending-ready-notice">
            <p><strong><?php echo esc_html($batch->get_error_message()); ?></strong></p>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $batch
     */
    private function render_packing_workflow(array $batch): void
    {
        $wave_batch_id = (int) ($batch['id'] ?? 0);
        $packages = $this->packing_packages_for_batch($batch);
        ?>
        <div class="fflhub-pack-workflow">
            <div class="fflhub-pack-toolbar">
                <div>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">
                        <?php esc_html_e('Back to Sending', 'ffl-hub'); ?>
                    </a>
                    <h2><?php echo esc_html(sprintf(__('Packing Wave Batch #%d', 'ffl-hub'), $wave_batch_id)); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Print the package documents, scan every item UPC, and match firearm serials before confirming. Shipment confirmation is intentionally disabled until the next implementation step.', 'ffl-hub'); ?>
                    </p>
                </div>
                <div class="fflhub-pack-toolbar-summary">
                    <?php $this->render_status_pill((string) ($batch['status'] ?? '')); ?>
                    <strong><?php echo esc_html(sprintf(_n('%d package', '%d packages', count($packages), 'ffl-hub'), count($packages))); ?></strong>
                </div>
            </div>

            <?php if (empty($packages)) : ?>
                <div class="fflhub-sending-ready-empty">
                    <?php esc_html_e('No package rows with saved labels were found for this wave batch.', 'ffl-hub'); ?>
                </div>
            <?php else : ?>
                <div class="fflhub-pack-list">
                    <?php foreach ($packages as $package_index => $package) : ?>
                        <?php $this->render_packing_package($package, $package_index + 1); ?>
                    <?php endforeach; ?>
                </div>
                <?php $this->render_packing_script(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $package
     */
    private function render_packing_package(array $package, int $position): void
    {
        $order = $package['order'] ?? null;
        $order = $order instanceof WC_Order ? $order : null;
        $items = is_array($package['items'] ?? null) ? $package['items'] : [];
        $expected = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $expected[] = [
                'upc' => $this->normalize_upc((string) ($item['upc'] ?? '')),
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'fflRequired' => $this->truthy($item['ffl_required'] ?? null),
                'serials' => array_values(array_map('strval', (array) ($item['serial_numbers'] ?? []))),
            ];
        }
        $expected_json = wp_json_encode($expected);
        $has_ffl = !empty($package['has_ffl_required']);
        ?>
        <section
            class="fflhub-pack-card"
            data-expected="<?php echo esc_attr(is_string($expected_json) ? $expected_json : '[]'); ?>">
            <div class="fflhub-pack-card-head">
                <div>
                    <span class="fflhub-pack-step"><?php echo esc_html(sprintf(__('Package %d', 'ffl-hub'), $position)); ?></span>
                    <h3><?php echo esc_html((string) ($package['package_title'] ?? __('Package', 'ffl-hub'))); ?></h3>
                    <p>
                        <?php if ($order instanceof WC_Order) : ?>
                            <a href="<?php echo esc_url($order->get_edit_order_url()); ?>">
                                <?php echo esc_html('#' . (string) $order->get_order_number()); ?>
                            </a>
                            <span><?php echo esc_html($order->get_formatted_billing_full_name() ?: $order->get_formatted_shipping_full_name()); ?></span>
                        <?php endif; ?>
                    </p>
                    <span class="fflhub-sending-ready-muted"><?php echo esc_html((string) ($package['package_detail'] ?? '')); ?></span>
                </div>
                <div class="fflhub-pack-docs">
                    <?php if ((string) ($package['label_url'] ?? '') !== '') : ?>
                        <a class="button button-primary" href="<?php echo esc_url((string) $package['label_url']); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Print Label', 'ffl-hub'); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ((string) ($package['packing_slip_url'] ?? '') !== '') : ?>
                        <a class="button" href="<?php echo esc_url((string) $package['packing_slip_url']); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Print Slip', 'ffl-hub'); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ((string) ($package['combined_url'] ?? '') !== '') : ?>
                        <a class="button" href="<?php echo esc_url((string) $package['combined_url']); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Label + Slip', 'ffl-hub'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($has_ffl) : ?>
                <div class="fflhub-pack-ffl-warning">
                    <strong><?php esc_html_e('FFL required package.', 'ffl-hub'); ?></strong>
                    <?php esc_html_e('Put a copy of our FFL in this package before sealing it. Scan the UPC and the matching received serial number for every firearm.', 'ffl-hub'); ?>
                </div>
            <?php endif; ?>

            <table class="widefat striped fflhub-pack-items">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Item', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('UPC', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('Needed', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('Packed', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('Serial Match', 'ffl-hub'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $index => $item) : ?>
                        <?php if (!is_array($item)) { continue; } ?>
                        <?php
                        $serials = array_values(array_filter(array_map('strval', (array) ($item['serial_numbers'] ?? []))));
                        ?>
                        <tr data-pack-row="<?php echo esc_attr((string) $index); ?>">
                            <td>
                                <strong><?php echo esc_html((string) ($item['name'] ?? '')); ?></strong>
                                <?php if (!empty($item['ffl_required'])) : ?>
                                    <?php $this->render_status_pill('ffl'); ?>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html((string) ($item['upc'] ?? '')); ?></code></td>
                            <td><?php echo esc_html((string) max(1, (int) ($item['quantity'] ?? 1))); ?></td>
                            <td><span data-packed-count="<?php echo esc_attr((string) $index); ?>">0</span></td>
                            <td>
                                <?php if (!empty($item['ffl_required'])) : ?>
                                    <?php echo esc_html(!empty($serials) ? implode(', ', $serials) : __('No received serial saved', 'ffl-hub')); ?>
                                <?php else : ?>
                                    <span class="fflhub-sending-ready-muted"><?php esc_html_e('Not required', 'ffl-hub'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="fflhub-pack-scan-panel">
                <label>
                    <span><?php esc_html_e('Scan UPC', 'ffl-hub'); ?></span>
                    <input type="text" class="regular-text fflhub-pack-upc" inputmode="numeric" autocomplete="off" />
                </label>
                <label>
                    <span><?php esc_html_e('Serial Number', 'ffl-hub'); ?></span>
                    <input type="text" class="regular-text fflhub-pack-serial" autocomplete="off" />
                </label>
                <button type="button" class="button button-primary fflhub-pack-record">
                    <?php esc_html_e('Record Scan', 'ffl-hub'); ?>
                </button>
                <button type="button" class="button fflhub-pack-confirm" disabled>
                    <?php esc_html_e('Confirm Shipment', 'ffl-hub'); ?>
                </button>
            </div>
            <div class="fflhub-pack-message" aria-live="polite"></div>
            <ol class="fflhub-pack-log"></ol>
        </section>
        <?php
    }

    /**
     * @param array<string,mixed> $batch
     * @return array<int,array<string,mixed>>
     */
    private function packing_packages_for_batch(array $batch): array
    {
        $provider_batch_id = (string) ($batch['easypost']['provider_batch_id'] ?? '');
        $order_rows = is_array($batch['orders'] ?? null) ? $batch['orders'] : [];
        $all_item_ids = [];

        foreach ($order_rows as $row) {
            foreach ((array) (is_array($row) ? ($row['package_items'] ?? []) : []) as $package_rows) {
                foreach ((array) $package_rows as $assignment) {
                    if (is_array($assignment)) {
                        $item_id = absint($assignment['item_id'] ?? $assignment['order_item_id'] ?? 0);
                        if ($item_id > 0) {
                            $all_item_ids[] = $item_id;
                        }
                    }
                }
            }
        }

        $received_serials = (new ReceivingEventsStore())->accepted_serials_by_order_item_ids($all_item_ids);
        $out = [];

        foreach ($order_rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $order = wc_get_order((int) ($row['order_id'] ?? 0));
            if (!($order instanceof WC_Order)) {
                continue;
            }

            $labels = $this->active_labels_for_order($order, $provider_batch_id);
            $labels_by_index = [];
            foreach ($labels as $label) {
                $labels_by_index[max(0, (int) ($label['package_index'] ?? 0))] = $label;
            }

            $packages = array_values((array) ($row['packages'] ?? []));
            $package_items = array_values((array) ($row['package_items'] ?? []));
            $package_count = max(count($packages), count($package_items), count($labels), (int) ($row['package_count'] ?? 0));

            for ($index = 0; $index < $package_count; $index++) {
                $label = is_array($labels_by_index[$index] ?? null) ? $labels_by_index[$index] : (is_array($labels[$index] ?? null) ? $labels[$index] : []);
                if (empty($label)) {
                    continue;
                }

                $package = is_array($packages[$index] ?? null) ? $packages[$index] : $this->first_array((array) ($label['package_details'] ?? []));
                $assignments = is_array($package_items[$index] ?? null)
                    ? $package_items[$index]
                    : $this->first_array((array) ($label['package_items'] ?? []));
                $items = $this->hydrated_package_items($order, (array) $assignments, $received_serials, !empty($row['debug_ready']));
                $label_id = (string) ($label['label_id'] ?? '');
                $has_ffl = false;
                foreach ($items as $item) {
                    $has_ffl = $has_ffl || !empty($item['ffl_required']);
                }

                $out[] = [
                    'order' => $order,
                    'package_title' => $this->package_title((array) $package, $index),
                    'package_detail' => $this->package_detail((array) $package),
                    'items' => $items,
                    'has_ffl_required' => $has_ffl,
                    'label_url' => $label_id !== '' ? ShipStationRestController::download_url($order, $label_id, false) : '',
                    'packing_slip_url' => $label_id !== '' ? ShipStationRestController::packing_slip_pdf_url($order, $label_id, 0) : '',
                    'combined_url' => $label_id !== '' ? ShipStationRestController::print_label_with_slip_url($order, $label_id) : '',
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $order_item_serials
     * @return array<int,array<string,mixed>>
     */
    private function hydrated_package_items(WC_Order $order, array $assignments, array $order_item_serials, bool $debug_ready): array
    {
        $items = [];
        foreach ($assignments as $assignment) {
            if (!is_array($assignment)) {
                continue;
            }

            $item_id = absint($assignment['item_id'] ?? $assignment['order_item_id'] ?? 0);
            $quantity = max(0, (int) ($assignment['quantity'] ?? 0));
            if ($item_id <= 0 || $quantity <= 0) {
                continue;
            }

            $order_item = $order->get_item($item_id);
            $product = $order_item instanceof WC_Order_Item_Product ? $order_item->get_product() : null;
            $product = $product instanceof WC_Product ? $product : null;
            $state_row = $product instanceof WC_Product ? ProductStateStore::get_row_for_product($product) : null;

            $ffl_required = $this->truthy($assignment['ffl_required'] ?? null)
                || ($product instanceof WC_Product && ProductStateStore::get_ffl_required_for_product($product));
            $serials = $this->clean_serials((array) ($assignment['serial_numbers'] ?? []));
            $single_serial = trim((string) ($assignment['serial_number'] ?? ''));
            if ($single_serial !== '') {
                $serials[] = $single_serial;
            }
            if (empty($serials) && $ffl_required && !empty($order_item_serials[$item_id])) {
                $serials = array_slice($this->clean_serials((array) $order_item_serials[$item_id]), 0, $quantity);
            }
            if (empty($serials) && $debug_ready && $ffl_required) {
                $serials = array_fill(0, $quantity, 'DEBUG');
            }

            $name = trim((string) ($assignment['name'] ?? ''));
            if ($name === '' && $order_item instanceof WC_Order_Item_Product) {
                $name = (string) $order_item->get_name();
            }

            $sku = trim((string) ($assignment['sku'] ?? ''));
            if ($sku === '' && $product instanceof WC_Product) {
                $sku = (string) $product->get_sku();
            }

            $upc = $this->normalize_upc((string) ($assignment['upc'] ?? ''));
            if ($upc === '' && is_array($state_row)) {
                $upc = $this->normalize_upc((string) ($state_row['upc'] ?? ''));
            }
            if ($upc === '' && $product instanceof WC_Product && method_exists($product, 'get_global_unique_id')) {
                $upc = $this->normalize_upc((string) $product->get_global_unique_id('edit'));
            }

            $items[] = [
                'item_id' => $item_id,
                'quantity' => $quantity,
                'name' => $name !== '' ? $name : 'Order item #' . $item_id,
                'sku' => $sku,
                'upc' => $upc,
                'ffl_required' => $ffl_required ? 1 : 0,
                'serial_numbers' => array_values(array_unique($this->clean_serials($serials))),
            ];
        }

        return $items;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function active_labels_for_order(WC_Order $order, string $provider_batch_id): array
    {
        $active = array_values(array_filter(ShipStationOrderMeta::labels($order), static function (array $label) use ($provider_batch_id): bool {
            if (!ShipStationOrderMeta::label_is_active($label)) {
                return false;
            }

            return $provider_batch_id === '' || (string) ($label['easypost_batch_id'] ?? '') === $provider_batch_id;
        }));

        if (empty($active) && $provider_batch_id !== '') {
            $active = array_values(array_filter(ShipStationOrderMeta::labels($order), static function (array $label): bool {
                return ShipStationOrderMeta::label_is_active($label);
            }));
        }

        usort($active, static function (array $a, array $b): int {
            return max(0, (int) ($a['package_index'] ?? 0)) <=> max(0, (int) ($b['package_index'] ?? 0));
        });

        return $active;
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
                __('Delay: %d second(s) between print jobs', 'ffl-hub'),
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
     * @param array<int,mixed> $rows
     * @return array<string,mixed>
     */
    private function first_array(array $rows): array
    {
        foreach ($rows as $row) {
            if (is_array($row)) {
                return $row;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $package
     */
    private function package_title(array $package, int $index): string
    {
        foreach (['name', 'package_name', 'preset_name', 'package_code'] as $key) {
            $value = trim((string) ($package[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'Package ' . (string) ($index + 1);
    }

    /**
     * @param array<string,mixed> $package
     */
    private function package_detail(array $package): string
    {
        $dims = is_array($package['dimensions'] ?? null) ? $package['dimensions'] : $package;
        $length = $this->number_label($dims['length'] ?? null);
        $width = $this->number_label($dims['width'] ?? null);
        $height = $this->number_label($dims['height'] ?? null);
        $weight = is_array($package['weight'] ?? null)
            ? $this->number_label($package['weight']['value'] ?? null)
            : $this->number_label($package['weight_oz'] ?? $package['weight'] ?? null);

        $parts = [];
        if ($length !== '' && $width !== '' && $height !== '') {
            $parts[] = $length . ' x ' . $width . ' x ' . $height . ' in';
        }
        if ($weight !== '') {
            $unit = is_array($package['weight'] ?? null) ? (string) ($package['weight']['unit'] ?? 'ounce') : 'oz';
            $parts[] = $weight . ' ' . $unit;
        }

        return !empty($parts) ? implode(' | ', $parts) : 'Package details unavailable';
    }

    /**
     * @param mixed $value
     */
    private function number_label($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $number = (float) $value;
        if ($number <= 0.0) {
            return '';
        }

        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    /**
     * @param mixed[] $serials
     * @return string[]
     */
    private function clean_serials(array $serials): array
    {
        $out = [];
        foreach ($serials as $serial) {
            $serial = trim(sanitize_text_field((string) $serial));
            if ($serial !== '') {
                $out[] = $serial;
            }
        }

        return array_values($out);
    }

    private function normalize_upc(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    /**
     * @param mixed $value
     */
    private function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on', 'y'], true);
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

    private function render_packing_script(): void
    {
        ?>
        <script>
            (function () {
                function normalizeUpc(value) {
                    return String(value || '').replace(/\D+/g, '');
                }

                function normalizeSerial(value) {
                    return String(value || '').trim().toUpperCase();
                }

                function parseExpected(card) {
                    try {
                        return JSON.parse(card.getAttribute('data-expected') || '[]');
                    } catch (error) {
                        return [];
                    }
                }

                document.querySelectorAll('.fflhub-pack-card').forEach(function (card) {
                    var expected = parseExpected(card).map(function (row, index) {
                        return {
                            index: index,
                            upc: normalizeUpc(row.upc),
                            name: String(row.name || ''),
                            quantity: Math.max(1, parseInt(row.quantity || 1, 10)),
                            fflRequired: !!row.fflRequired,
                            serials: Array.isArray(row.serials) ? row.serials.map(normalizeSerial).filter(Boolean) : [],
                            scanned: 0,
                            scannedSerials: []
                        };
                    });
                    var upcInput = card.querySelector('.fflhub-pack-upc');
                    var serialInput = card.querySelector('.fflhub-pack-serial');
                    var recordButton = card.querySelector('.fflhub-pack-record');
                    var confirmButton = card.querySelector('.fflhub-pack-confirm');
                    var message = card.querySelector('.fflhub-pack-message');
                    var log = card.querySelector('.fflhub-pack-log');

                    function setMessage(text, type) {
                        if (!message) {
                            return;
                        }
                        message.textContent = text || '';
                        message.className = 'fflhub-pack-message ' + (type ? 'is-' + type : '');
                    }

                    function redraw() {
                        var complete = expected.length > 0;
                        expected.forEach(function (row) {
                            var count = card.querySelector('[data-packed-count="' + row.index + '"]');
                            if (count) {
                                count.textContent = row.scanned + ' / ' + row.quantity;
                            }
                            if (row.scanned < row.quantity) {
                                complete = false;
                            }
                        });
                        if (confirmButton) {
                            confirmButton.disabled = !complete;
                        }
                        card.classList.toggle('is-complete', complete);
                    }

                    function targetForScan(upc, serial) {
                        var candidates = expected.filter(function (row) {
                            return row.upc === upc && row.scanned < row.quantity;
                        });
                        if (!candidates.length) {
                            return {error: 'That UPC is not expected in this package, or it has already been fully packed.'};
                        }

                        var fflCandidates = candidates.filter(function (row) {
                            return row.fflRequired;
                        });
                        if (!fflCandidates.length) {
                            return {row: candidates[0]};
                        }

                        if (!serial) {
                            return {error: 'This is an FFL item. Scan or enter the serial number too.'};
                        }

                        for (var i = 0; i < fflCandidates.length; i++) {
                            var row = fflCandidates[i];
                            if (!row.serials.length) {
                                return {error: 'No received serial is saved for this FFL item, so packing cannot be confirmed yet.'};
                            }
                            if (row.serials.indexOf(serial) !== -1 && row.scannedSerials.indexOf(serial) === -1) {
                                return {row: row};
                            }
                        }

                        return {error: 'Serial number does not match the received serial for this package.'};
                    }

                    function addLog(row, serial) {
                        if (!log) {
                            return;
                        }
                        var entry = document.createElement('li');
                        entry.textContent = row.upc + ' packed' + (serial ? ' / serial ' + serial : '') + ' - ' + row.name;
                        log.insertBefore(entry, log.firstChild);
                    }

                    function recordScan() {
                        var upc = normalizeUpc(upcInput ? upcInput.value : '');
                        var serial = normalizeSerial(serialInput ? serialInput.value : '');
                        if (!upc) {
                            setMessage('Scan or enter a UPC first.', 'bad');
                            return;
                        }

                        var result = targetForScan(upc, serial);
                        if (result.error) {
                            setMessage(result.error, 'bad');
                            return;
                        }

                        var row = result.row;
                        row.scanned++;
                        if (row.fflRequired) {
                            row.scannedSerials.push(serial);
                        }
                        addLog(row, row.fflRequired ? serial : '');
                        setMessage('Scan accepted.', 'good');
                        if (upcInput) {
                            upcInput.value = '';
                            upcInput.focus();
                        }
                        if (serialInput) {
                            serialInput.value = '';
                        }
                        redraw();
                    }

                    if (recordButton) {
                        recordButton.addEventListener('click', recordScan);
                    }
                    [upcInput, serialInput].forEach(function (input) {
                        if (!input) {
                            return;
                        }
                        input.addEventListener('keydown', function (event) {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                recordScan();
                            }
                        });
                    });
                    if (confirmButton) {
                        confirmButton.addEventListener('click', function () {
                            setMessage('Shipment confirmation is not wired yet. This button is intentionally a no-op for now.', 'good');
                        });
                    }

                    redraw();
                });
            }());
        </script>
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
            .fflhub-sending-ready-actions{margin-bottom:8px}
            .fflhub-sending-ready-pill{display:inline-flex;align-items:center;white-space:nowrap;border-radius:999px;padding:4px 10px;font-size:11px;font-weight:800;text-transform:uppercase;margin:2px 4px 2px 0}
            .fflhub-sending-ready-pill.is-good{background:#e6f6ed;color:#146c43}
            .fflhub-sending-ready-pill.is-working{background:#fff4e5;color:#8a4b00}
            .fflhub-sending-ready-pill.is-bad{background:#fde7e9;color:#8a2424}
            .fflhub-sending-ready-pill.is-neutral{background:#f0f0f1;color:#1d2327}
            .fflhub-sending-ready-empty{background:#fff;border:1px dashed #c3c4c7;border-radius:8px;padding:18px;color:#646970;margin-top:16px}
            .fflhub-sending-ready-notice{margin:0 0 16px}
            .fflhub-sending-ready-notice ul{margin:8px 0 0 18px;list-style:disc}
            .fflhub-pack-workflow{margin-top:18px}
            .fflhub-pack-toolbar{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin-bottom:16px}
            .fflhub-pack-toolbar h2{margin:10px 0 4px}
            .fflhub-pack-toolbar-summary{display:grid;gap:6px;text-align:right;min-width:160px}
            .fflhub-pack-list{display:grid;gap:16px}
            .fflhub-pack-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .fflhub-pack-card.is-complete{border-color:#2c8a4b;box-shadow:0 0 0 1px rgba(44,138,75,.18)}
            .fflhub-pack-card-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:12px}
            .fflhub-pack-card-head h3{font-size:22px;line-height:1.2;margin:2px 0 4px}
            .fflhub-pack-card-head p{margin:0;color:#50575e}
            .fflhub-pack-step{display:inline-flex;align-items:center;border-radius:999px;background:#eef3f0;color:#17462a;font-size:12px;font-weight:800;text-transform:uppercase;padding:4px 10px}
            .fflhub-pack-docs{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end}
            .fflhub-pack-ffl-warning{border-left:4px solid #b32d2e;background:#fcf0f1;color:#5f1516;padding:12px;margin:12px 0;font-size:14px}
            .fflhub-pack-items{margin-top:12px}
            .fflhub-pack-items th{white-space:nowrap}
            .fflhub-pack-items code{font-size:13px}
            .fflhub-pack-scan-panel{display:grid;grid-template-columns:minmax(190px,1fr) minmax(190px,1fr) auto auto;gap:10px;align-items:end;margin-top:14px}
            .fflhub-pack-scan-panel label span{display:block;font-size:12px;font-weight:800;text-transform:uppercase;color:#646970;margin-bottom:4px}
            .fflhub-pack-scan-panel input{width:100%}
            .fflhub-pack-message{min-height:20px;margin-top:10px;font-weight:700}
            .fflhub-pack-message.is-good{color:#146c43}
            .fflhub-pack-message.is-bad{color:#8a2424}
            .fflhub-pack-log{margin:10px 0 0 20px;max-height:130px;overflow:auto;color:#50575e}
            @media (max-width:960px){
                .fflhub-pack-toolbar,.fflhub-pack-card-head{display:block}
                .fflhub-pack-toolbar-summary{text-align:left;margin-top:10px}
                .fflhub-pack-docs{justify-content:flex-start;margin-top:10px}
                .fflhub-pack-scan-panel{grid-template-columns:1fr}
            }
        </style>
        <?php
    }
}
