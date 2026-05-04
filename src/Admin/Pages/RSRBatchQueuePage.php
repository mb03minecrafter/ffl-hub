<?php

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Services\Orders\Cron\RSRDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Product\HolosunProductDetector;
use FFLHub\Product\ProductMeta;

if (!defined('ABSPATH')) {
    exit;
}

final class RSRBatchQueuePage
{
    private const PAGE_SLUG = 'fflhub-rsr-dealer-batch-queue';
    private const RSR_DIST_ID = 'rsr';
    private const QUERY_LIMIT = 200000;
    private const TARGET_WOO_ORDER_STATUS = 'processing';

    private const FORM_ACTION_SAVE_SETTINGS = 'fflhub_rsr_batch_save_settings';
    private const FORM_ACTION_FORCE_RUN = 'fflhub_rsr_batch_force_run';
    private const FORM_ACTION_HOLOSUN_MANUAL_PO = 'fflhub_rsr_holosun_manual_po';
    private const NONCE_ACTION = 'fflhub_rsr_batch_page_action';
    private const NONCE_FIELD = 'fflhub_rsr_batch_nonce';

    private const OPT_BATCH_ENABLED = 'fflhub_rsr_dealer_batch_enabled';
    private const OPT_DISPATCH_TIME = 'fflhub_rsr_dealer_batch_dispatch_time';
    private const OPT_LOW_STOCK_THRESHOLD = 'fflhub_rsr_dealer_batch_low_stock_threshold';
    private const OPT_RETRY_DELAY_SECONDS = 'fflhub_rsr_dealer_batch_retry_delay_seconds';
    private const OPT_MAX_ROWS_PER_RUN = 'fflhub_rsr_dealer_batch_max_rows_per_run';
    private const OPT_FORCE_FLUSH = 'fflhub_rsr_dealer_batch_force_flush';

    private const DEFAULT_DISPATCH_TIME = '17:00';
    private const DEFAULT_LOW_STOCK_THRESHOLD = 3;
    private const DEFAULT_RETRY_DELAY_SECONDS = 300;
    private const DEFAULT_MAX_ROWS_PER_RUN = 200;
    private const DISPATCH_TZ = 'America/Chicago';

    private OrderPlacementJobsTable $jobs_table;
    private DistributorHandler $handler;

    public function __construct(OrderPlacementJobsTable $jobs_table, DistributorHandler $handler)
    {
        $this->jobs_table = $jobs_table;
        $this->handler = $handler;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            AdminPage::get_page_slug(),
            __('RSR Dealer Batch Queue', 'ffl-hub'),
            __('RSR Dealer Batch Queue', 'ffl-hub'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        $this->maybe_handle_post_action();
        $notice = $this->read_notice_from_query();

        $jobs = OrderPlacementJobsRepository::find_jobs_by_distributor(
            $this->jobs_table,
            self::RSR_DIST_ID,
            self::QUERY_LIMIT
        );
        $jobs = $this->filter_jobs_for_processing_dealer_lane($jobs);
        $settings = $this->read_settings();
        $data = $this->build_batch_queue_data($jobs, (int) $settings['low_stock_threshold']);
        ?>
        <div class="wrap fflhub-rsr-batch-status">
            <?php $this->render_styles(); ?>
            <h1><?php esc_html_e('RSR Dealer Batch Queue', 'ffl-hub'); ?></h1>
            <p><?php esc_html_e('Per-line-item UPC queue view for RSR dealer-fulfilled rows on Processing orders.', 'ffl-hub'); ?></p>
            <?php $this->render_notice($notice); ?>
            <?php $this->render_settings_form($settings); ?>
            <?php $this->render_force_run_box($data, $settings); ?>
            <?php $this->render_summary_cards($data, $settings); ?>
            <?php $this->render_holosun_manual_table($data); ?>
            <?php $this->render_low_stock_watch_table($data, $settings); ?>
            <?php $this->render_running_totals_table($data); ?>
            <?php $this->render_entries_table($data); ?>
            <?php $this->render_system_explainer($settings); ?>
        </div>
        <?php
    }

    private function maybe_handle_post_action(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $action = isset($_POST['fflhub_rsr_batch_action'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_rsr_batch_action']))
            : '';
        if (!in_array($action, [self::FORM_ACTION_SAVE_SETTINGS, self::FORM_ACTION_FORCE_RUN, self::FORM_ACTION_HOLOSUN_MANUAL_PO], true)) {
            return;
        }

        if (
            !isset($_POST[self::NONCE_FIELD]) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD])),
                self::NONCE_ACTION
            )
        ) {
            $this->redirect_with_notice('error', __('Security check failed. Please refresh and try again.', 'ffl-hub'));
        }

        if ($action === self::FORM_ACTION_SAVE_SETTINGS) {
            $this->handle_save_settings_post();
            return;
        }

        if ($action === self::FORM_ACTION_HOLOSUN_MANUAL_PO) {
            $this->handle_holosun_manual_po_post();
            return;
        }

        if ($action === self::FORM_ACTION_FORCE_RUN) {
            $this->handle_force_run_post();
        }
    }

    private function handle_save_settings_post(): void
    {
        $enabled = $this->to_checkbox_string(
            isset($_POST['fflhub_rsr_dealer_batch_enabled']) ? wp_unslash((string) $_POST['fflhub_rsr_dealer_batch_enabled']) : '0'
        );
        $dispatch_time = $this->sanitize_dispatch_time(
            isset($_POST['fflhub_rsr_dealer_batch_dispatch_time'])
                ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_rsr_dealer_batch_dispatch_time']))
                : self::DEFAULT_DISPATCH_TIME
        );
        $low_stock_threshold = max(0, (int) (isset($_POST['fflhub_rsr_dealer_batch_low_stock_threshold']) ? wp_unslash((string) $_POST['fflhub_rsr_dealer_batch_low_stock_threshold']) : self::DEFAULT_LOW_STOCK_THRESHOLD));
        $retry_delay_seconds = max(30, (int) (isset($_POST['fflhub_rsr_dealer_batch_retry_delay_seconds']) ? wp_unslash((string) $_POST['fflhub_rsr_dealer_batch_retry_delay_seconds']) : self::DEFAULT_RETRY_DELAY_SECONDS));
        $max_rows_per_run = max(1, (int) (isset($_POST['fflhub_rsr_dealer_batch_max_rows_per_run']) ? wp_unslash((string) $_POST['fflhub_rsr_dealer_batch_max_rows_per_run']) : self::DEFAULT_MAX_ROWS_PER_RUN));
        $force_flush = $this->to_checkbox_string(
            isset($_POST['fflhub_rsr_dealer_batch_force_flush']) ? wp_unslash((string) $_POST['fflhub_rsr_dealer_batch_force_flush']) : '0'
        );

        update_option(self::OPT_BATCH_ENABLED, $enabled, false);
        update_option(self::OPT_DISPATCH_TIME, $dispatch_time, false);
        update_option(self::OPT_LOW_STOCK_THRESHOLD, (string) $low_stock_threshold, false);
        update_option(self::OPT_RETRY_DELAY_SECONDS, (string) $retry_delay_seconds, false);
        update_option(self::OPT_MAX_ROWS_PER_RUN, (string) $max_rows_per_run, false);
        update_option(self::OPT_FORCE_FLUSH, $force_flush, false);

        $this->redirect_with_notice('success', __('RSR dealer batch settings updated.', 'ffl-hub'));
    }

    private function handle_force_run_post(): void
    {
        update_option(self::OPT_FORCE_FLUSH, '1', false);

        $scheduled = false;
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + 1, RSRDealerBatchCronService::CRON_HOOK, [], 'fflhub_place');
            $scheduled = true;
        } elseif (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 1, RSRDealerBatchCronService::CRON_HOOK);
            $scheduled = true;
        } else {
            do_action(RSRDealerBatchCronService::CRON_HOOK);
        }

        $msg = $scheduled
            ? __('Force flush enabled and batch run scheduled.', 'ffl-hub')
            : __('Force flush enabled and batch run triggered.', 'ffl-hub');
        if ($this->is_weekend_hold_active()) {
            $msg = __('Force flush enabled. RSR weekend hold is active, so non-priority queued rows will flush at the next weekday dispatch time.', 'ffl-hub');
        }
        $this->redirect_with_notice('success', $msg);
    }

    private function handle_holosun_manual_po_post(): void
    {
        $order_id = isset($_POST['fflhub_rsr_manual_order_id']) ? (int) $_POST['fflhub_rsr_manual_order_id'] : 0;
        $job_key = isset($_POST['fflhub_rsr_manual_job_key'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_rsr_manual_job_key']))
            : '';
        $merchant_po = $this->sanitize_manual_po(
            isset($_POST['fflhub_rsr_manual_merchant_po'])
                ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_rsr_manual_merchant_po']))
                : ''
        );

        if ($order_id <= 0 || trim($job_key) === '') {
            $this->redirect_with_notice('error', __('Missing order or job key for manual RSR row.', 'ffl-hub'));
        }
        if ($merchant_po === '') {
            $this->redirect_with_notice('error', __('Please enter a valid RSR PO number.', 'ffl-hub'));
        }

        $order = wc_get_order($order_id);
        if (!$order || !method_exists($order, 'get_status')) {
            $this->redirect_with_notice('error', __('Order not found for this RSR row.', 'ffl-hub'));
        }

        $order_status = strtolower(trim((string) $order->get_status()));
        if ($order_status !== self::TARGET_WOO_ORDER_STATUS) {
            $this->redirect_with_notice('error', __('This order is no longer in Processing status.', 'ffl-hub'));
        }

        $job = OrderPlacementJobsRepository::get_job($this->jobs_table, $order_id, $job_key);
        if (!($job instanceof OrderPlacementJobRow)) {
            $this->redirect_with_notice('error', __('RSR job row not found.', 'ffl-hub'));
        }

        if (strtolower(trim((string) $job->dist_id)) !== self::RSR_DIST_ID) {
            $this->redirect_with_notice('error', __('That row is not an RSR job.', 'ffl-hub'));
        }
        if (!OrderPlacementKeysUtil::is_dealer_fulfilled_lane((string) $job->lane_norm())) {
            $this->redirect_with_notice('error', __('That RSR row is not a dealer-fulfilled row.', 'ffl-hub'));
        }
        if (!$this->job_contains_holosun_line($job)) {
            $this->redirect_with_notice('error', __('That RSR row is not a Holosun manual row.', 'ffl-hub'));
        }

        $job_status = strtolower(trim((string) $job->status));
        if (!in_array($job_status, [OrderPlacementKeys::JOB_STATUS_BATCH_PENDING, OrderPlacementKeys::JOB_STATUS_MANUAL, OrderPlacementKeys::JOB_STATUS_SUCCESS], true)) {
            $this->redirect_with_notice('error', __('That Holosun RSR row is not ready for manual PO completion yet.', 'ffl-hub'));
        }

        OrderPlacementJobWriter::apply_patch(
            $this->jobs_table,
            $order_id,
            (string) $job->job_key,
            OrderPlacementJobPatch::empty()
                ->with_status(OrderPlacementKeys::JOB_STATUS_SUCCESS)
                ->with_field('done_at', gmdate('Y-m-d H:i:s'))
                ->with_field('merchant_po', $merchant_po)
                ->with_field('last_error', '')
                ->with_last_codes([])
                ->clear_action_and_schedule()
        );

        $this->redirect_with_notice(
            'success',
            sprintf(__('Marked RSR Holosun job #%d successful with PO %s.', 'ffl-hub'), (int) $job->id, $merchant_po)
        );
    }

    private function redirect_with_notice(string $type, string $message): void
    {
        $url = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'fflhub_notice_type' => $type,
                'fflhub_notice_message' => $message,
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    private function sanitize_manual_po(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Z0-9._-]/', '', $value);
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return strlen($value) > 32 ? substr($value, 0, 32) : $value;
    }

    private function read_notice_from_query(): ?array
    {
        $type = isset($_GET['fflhub_notice_type']) ? sanitize_text_field(wp_unslash((string) $_GET['fflhub_notice_type'])) : '';
        $message = isset($_GET['fflhub_notice_message']) ? sanitize_text_field(wp_unslash((string) $_GET['fflhub_notice_message'])) : '';
        $type = strtolower(trim($type));
        if ($message === '' || !in_array($type, ['success', 'error', 'warning', 'info'], true)) {
            return null;
        }
        return ['type' => $type, 'message' => $message];
    }

    private function render_notice(?array $notice): void
    {
        if (!is_array($notice) || !isset($notice['message'])) {
            return;
        }

        $type = strtolower(trim((string) ($notice['type'] ?? 'info')));
        $class = 'notice-info';
        if ($type === 'success') {
            $class = 'notice-success';
        } elseif ($type === 'error') {
            $class = 'notice-error';
        } elseif ($type === 'warning') {
            $class = 'notice-warning';
        }
        ?>
        <div class="notice <?php echo esc_attr($class); ?> is-dismissible"><p><?php echo esc_html((string) $notice['message']); ?></p></div>
        <?php
    }

    private function read_settings(): array
    {
        $dispatch_time = $this->sanitize_dispatch_time((string) get_option(self::OPT_DISPATCH_TIME, self::DEFAULT_DISPATCH_TIME));

        return [
            'enabled' => $this->truthy_option(self::OPT_BATCH_ENABLED, true),
            'dispatch_time' => $dispatch_time,
            'low_stock_threshold' => max(0, (int) get_option(self::OPT_LOW_STOCK_THRESHOLD, self::DEFAULT_LOW_STOCK_THRESHOLD)),
            'retry_delay_seconds' => max(30, (int) get_option(self::OPT_RETRY_DELAY_SECONDS, self::DEFAULT_RETRY_DELAY_SECONDS)),
            'max_rows_per_run' => max(1, (int) get_option(self::OPT_MAX_ROWS_PER_RUN, self::DEFAULT_MAX_ROWS_PER_RUN)),
            'force_flush' => $this->truthy_option(self::OPT_FORCE_FLUSH, false),
            'weekend_hold_active' => $this->is_weekend_hold_active(),
            'next_allowed_dispatch_label' => $this->next_allowed_dispatch_label($dispatch_time),
        ];
    }

    private function render_settings_form(array $settings): void
    {
        ?>
        <section class="fflhub-rsr-batch-card">
            <h2><?php esc_html_e('Batch Settings', 'ffl-hub'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="fflhub_rsr_batch_action" value="<?php echo esc_attr(self::FORM_ACTION_SAVE_SETTINGS); ?>" />
                <table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row"><?php esc_html_e('Enable Dealer Batch Queue', 'ffl-hub'); ?></th><td>
                        <input type="hidden" name="fflhub_rsr_dealer_batch_enabled" value="0" />
                        <label><input type="checkbox" name="fflhub_rsr_dealer_batch_enabled" value="1" <?php checked((bool) $settings['enabled']); ?> />
                            <?php esc_html_e('Queue dealer rows as batch_pending', 'ffl-hub'); ?></label>
                    </td></tr>
                    <tr><th scope="row"><?php esc_html_e('Dispatch Time (Local)', 'ffl-hub'); ?></th><td>
                        <input type="text" class="regular-text" name="fflhub_rsr_dealer_batch_dispatch_time" value="<?php echo esc_attr((string) $settings['dispatch_time']); ?>" placeholder="17:00" />
                        <p class="description"><?php esc_html_e('RSR scheduled dealer batch placement is held on Saturdays and Sundays. Low-stock priority rows can still place immediately.', 'ffl-hub'); ?></p>
                    </td></tr>
                    <tr><th scope="row"><?php esc_html_e('Low Stock Threshold', 'ffl-hub'); ?></th><td>
                        <input type="number" min="0" step="1" class="small-text" name="fflhub_rsr_dealer_batch_low_stock_threshold" value="<?php echo esc_attr((string) ((int) $settings['low_stock_threshold'])); ?>" />
                    </td></tr>
                    <tr><th scope="row"><?php esc_html_e('Retry Delay (Seconds)', 'ffl-hub'); ?></th><td>
                        <input type="number" min="30" step="1" class="small-text" name="fflhub_rsr_dealer_batch_retry_delay_seconds" value="<?php echo esc_attr((string) ((int) $settings['retry_delay_seconds'])); ?>" />
                    </td></tr>
                    <tr><th scope="row"><?php esc_html_e('Max Rows Per Run', 'ffl-hub'); ?></th><td>
                        <input type="number" min="1" step="1" class="small-text" name="fflhub_rsr_dealer_batch_max_rows_per_run" value="<?php echo esc_attr((string) ((int) $settings['max_rows_per_run'])); ?>" />
                    </td></tr>
                    <tr><th scope="row"><?php esc_html_e('Force Flush Next Run', 'ffl-hub'); ?></th><td>
                        <input type="hidden" name="fflhub_rsr_dealer_batch_force_flush" value="0" />
                        <label><input type="checkbox" name="fflhub_rsr_dealer_batch_force_flush" value="1" <?php checked(!empty($settings['force_flush'])); ?> />
                            <?php esc_html_e('Keep force flush enabled until the next eligible batch cron run consumes it', 'ffl-hub'); ?></label>
                    </td></tr>
                </tbody></table>
                <?php submit_button(__('Save RSR Batch Settings', 'ffl-hub')); ?>
            </form>
        </section>
        <?php
    }

    private function render_force_run_box(array $data, array $settings): void
    {
        ?>
        <section class="fflhub-rsr-batch-force-box">
            <h2><?php esc_html_e('Force Flush / Run', 'ffl-hub'); ?></h2>
            <p>
                <?php echo esc_html(sprintf(__('Queued rows: %d | Dispatch-ready now: %d | Dispatch time: %s', 'ffl-hub'), (int) ($data['batch_pending_jobs'] ?? 0), (int) ($data['dispatch_ready_jobs'] ?? 0), (string) ($settings['dispatch_time'] ?? self::DEFAULT_DISPATCH_TIME))); ?>
            </p>
            <?php if (!empty($settings['weekend_hold_active'])) : ?>
                <p class="description">
                    <strong><?php esc_html_e('Weekend hold active:', 'ffl-hub'); ?></strong>
                    <?php
                    echo esc_html(
                        sprintf(
                            __('Non-priority RSR batch rows will not be placed again until %s. Low-stock priority rows can still place immediately.', 'ffl-hub'),
                            (string) ($settings['next_allowed_dispatch_label'] ?? '')
                        )
                    );
                    ?>
                </p>
            <?php endif; ?>
            <?php if (!empty($settings['force_flush'])) : ?>
                <p class="description"><?php esc_html_e('Force flush flag is ON and will be consumed by the next batch run.', 'ffl-hub'); ?></p>
            <?php endif; ?>
            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="fflhub_rsr_batch_action" value="<?php echo esc_attr(self::FORM_ACTION_FORCE_RUN); ?>" />
                <?php
                $button_label = !empty($settings['weekend_hold_active'])
                    ? __('Force Flush at Next Weekday Window', 'ffl-hub')
                    : __('Force Flush + Run Now', 'ffl-hub');
                submit_button($button_label, 'secondary', '', false);
                ?>
            </form>
        </section>
        <?php
    }

    private function filter_jobs_for_processing_dealer_lane(array $jobs): array
    {
        $order_status_cache = [];
        $out = [];
        foreach ($jobs as $job) {
            if (!($job instanceof OrderPlacementJobRow)) {
                continue;
            }
            if (!OrderPlacementKeysUtil::is_dealer_fulfilled_lane((string) $job->lane_norm())) {
                continue;
            }
            $order_id = (int) $job->order_id;
            if ($order_id <= 0) {
                continue;
            }
            if (!array_key_exists($order_id, $order_status_cache)) {
                $order = wc_get_order($order_id);
                $order_status_cache[$order_id] = ($order && method_exists($order, 'get_status')) ? strtolower(trim((string) $order->get_status())) : '';
            }
            if ($order_status_cache[$order_id] !== self::TARGET_WOO_ORDER_STATUS) {
                continue;
            }
            $out[] = $job;
        }
        return $out;
    }

    private function build_batch_queue_data(array $jobs, int $low_stock_threshold): array
    {
        $product_name_by_upc = [];
        $unit_cost_by_upc = [];
        $totals_by_upc = [];
        $entries = [];
        $holosun_manual_entries = [];
        $processing_order_ids = [];
        $status_counts = [];
        $total_quantity = 0;
        $line_count = 0;
        $queue_total_cost = 0.0;
        $batch_pending_jobs = 0;
        $dispatch_ready_jobs = 0;
        $now_utc_ts = (int) current_time('timestamp', true);
        $batch_pending_demand_by_upc = [];
        $batch_pending_rows_by_upc = [];
        $batch_pending_display_upc = [];
        $batch_pending_name_by_upc = [];

        foreach ($jobs as $job) {
            if (!($job instanceof OrderPlacementJobRow)) {
                continue;
            }

            $processing_order_ids[(int) $job->order_id] = true;
            $job_status = strtolower(trim((string) $job->status));
            $status_counts[$job_status] = (int) ($status_counts[$job_status] ?? 0) + 1;
            $job_contains_holosun = $this->job_contains_holosun_line($job);
            $include_in_queue_totals = ($job_status === OrderPlacementKeys::JOB_STATUS_BATCH_PENDING && !$job_contains_holosun);
            if ($include_in_queue_totals) {
                $batch_pending_jobs++;
                if ($this->is_job_dispatch_ready((string) ($job->next_run_at ?? ''), $now_utc_ts)) {
                    $dispatch_ready_jobs++;
                }
            }

            foreach ($job->payload_lines() as $line) {
                if (!($line instanceof DistributorOrderLine)) {
                    continue;
                }
                $upc = trim((string) $line->upc);
                if ($upc === '') {
                    continue;
                }
                $qty = max(1, (int) $line->quantity);
                $name = $this->resolve_product_name_for_upc($upc, $product_name_by_upc);
                $unit_cost = $this->resolve_distributor_unit_cost_for_upc($upc, $unit_cost_by_upc);
                $line_cost = $unit_cost * (float) $qty;

                $entry = [
                    'job_id' => (int) $job->id,
                    'order_id' => (int) $job->order_id,
                    'job_key' => (string) $job->job_key,
                    'updated_at' => (string) ($job->updated_at ?? ''),
                    'next_run_at' => (string) ($job->next_run_at ?? ''),
                    'attempts' => (int) $job->attempts,
                    'job_status' => (string) $job->status,
                    'merchant_po' => (string) ($job->merchant_po ?? ''),
                    'upc' => $upc,
                    'qty' => $qty,
                    'product_name' => $name,
                    'unit_cost' => $unit_cost,
                    'line_cost' => $line_cost,
                    'is_holosun_manual_row' => $job_contains_holosun,
                    'last_error' => (string) ($job->last_error ?? ''),
                ];
                $entries[] = $entry;

                if ($job_contains_holosun) {
                    $holosun_manual_entries[] = $entry;
                }

                if (!$include_in_queue_totals) {
                    continue;
                }
                if (!isset($totals_by_upc[$upc])) {
                    $totals_by_upc[$upc] = ['upc' => $upc, 'product_name' => $name, 'total_qty' => 0, 'line_count' => 0, 'total_estimated_cost' => 0.0];
                }
                if ($totals_by_upc[$upc]['product_name'] === 'Unknown product' && $name !== 'Unknown product') {
                    $totals_by_upc[$upc]['product_name'] = $name;
                }
                $totals_by_upc[$upc]['total_qty'] += $qty;
                $totals_by_upc[$upc]['line_count']++;
                $totals_by_upc[$upc]['total_estimated_cost'] += $line_cost;
                $total_quantity += $qty;
                $line_count++;
                $queue_total_cost += $line_cost;

                $upc_key = $this->normalize_upc_key($upc);
                if ($upc_key === '') {
                    continue;
                }
                if (!isset($batch_pending_demand_by_upc[$upc_key])) {
                    $batch_pending_demand_by_upc[$upc_key] = 0;
                }
                $batch_pending_demand_by_upc[$upc_key] += $qty;
                if (!isset($batch_pending_rows_by_upc[$upc_key])) {
                    $batch_pending_rows_by_upc[$upc_key] = [];
                }
                $batch_pending_rows_by_upc[$upc_key][] = [
                    'job_id' => (int) $job->id,
                    'order_id' => (int) $job->order_id,
                    'job_key' => (string) $job->job_key,
                    'qty' => $qty,
                    'next_run_at' => (string) ($job->next_run_at ?? ''),
                ];
                if (!isset($batch_pending_display_upc[$upc_key])) {
                    $batch_pending_display_upc[$upc_key] = $upc;
                }
                if (!isset($batch_pending_name_by_upc[$upc_key])) {
                    $batch_pending_name_by_upc[$upc_key] = $name;
                }
            }
        }

        $totals_rows = array_values($totals_by_upc);
        usort($totals_rows, static function (array $a, array $b): int {
            $aq = (int) ($a['total_qty'] ?? 0);
            $bq = (int) ($b['total_qty'] ?? 0);
            if ($aq !== $bq) {
                return ($aq > $bq) ? -1 : 1;
            }
            return strcmp((string) ($a['upc'] ?? ''), (string) ($b['upc'] ?? ''));
        });

        $stock_watch_rows = $this->build_stock_watch_rows(
            $batch_pending_demand_by_upc,
            $batch_pending_rows_by_upc,
            $batch_pending_display_upc,
            $batch_pending_name_by_upc,
            $low_stock_threshold
        );

        return [
            'processing_order_count' => count($processing_order_ids),
            'job_count' => count($jobs),
            'batch_pending_jobs' => $batch_pending_jobs,
            'dispatch_ready_jobs' => $dispatch_ready_jobs,
            'line_count' => $line_count,
            'distinct_upc_count' => count($totals_rows),
            'total_quantity' => $total_quantity,
            'queue_total_cost' => $queue_total_cost,
            'status_counts' => $status_counts,
            'totals_by_upc' => $totals_rows,
            'stock_watch_rows' => $stock_watch_rows,
            'holosun_manual_entries' => $holosun_manual_entries,
            'entries' => $entries,
        ];
    }

    /**
     * @param array<string,int> $demand_by_upc
     * @param array<string,array<int,array{job_id:int,order_id:int,job_key:string,qty:int,next_run_at:string}>> $rows_by_upc
     * @param array<string,string> $display_upc_by_key
     * @param array<string,string> $name_by_key
     * @return array<int,array{
     *   upc:string,
     *   product_name:string,
     *   requested_qty:int,
     *   available_qty:?int,
     *   threshold:int,
     *   remaining_after_batch:?int,
     *   distance_now:?int,
     *   distance_after_batch:?int,
     *   risk_level:string,
     *   affected_rows:array<int,array{job_id:int,order_id:int,job_key:string,qty:int,next_run_at:string}>
     * }>
     */
    private function build_stock_watch_rows(
        array $demand_by_upc,
        array $rows_by_upc,
        array $display_upc_by_key,
        array $name_by_key,
        int $threshold
    ): array {
        if (empty($demand_by_upc)) {
            return [];
        }

        $rsr = $this->get_rsr_distributor();
        if (!($rsr instanceof DistributorBase)) {
            return [];
        }

        $watch_rows = [];
        foreach ($demand_by_upc as $upc_key => $requested_qty) {
            $display_upc = (string) ($display_upc_by_key[$upc_key] ?? $upc_key);
            $product_name = (string) ($name_by_key[$upc_key] ?? 'Unknown product');
            $available = $rsr->get_stock_quantity_by_upc($display_upc);
            $remaining_after_batch = ($available === null) ? null : ((int) $available - (int) $requested_qty);
            $distance_now = ($available === null) ? null : ((int) $available - $threshold);
            $distance_after_batch = ($remaining_after_batch === null) ? null : ((int) $remaining_after_batch - $threshold);

            $is_risky = ($available === null || (int) $available <= $threshold || (int) $available < (int) $requested_qty);
            $is_approaching = (!$is_risky && $remaining_after_batch !== null && (int) $remaining_after_batch <= $threshold);

            if (!$is_risky && !$is_approaching) {
                continue;
            }

            $watch_rows[] = [
                'upc' => $display_upc,
                'product_name' => $product_name,
                'requested_qty' => (int) $requested_qty,
                'available_qty' => ($available === null) ? null : (int) $available,
                'threshold' => (int) $threshold,
                'remaining_after_batch' => ($remaining_after_batch === null) ? null : (int) $remaining_after_batch,
                'distance_now' => ($distance_now === null) ? null : (int) $distance_now,
                'distance_after_batch' => ($distance_after_batch === null) ? null : (int) $distance_after_batch,
                'risk_level' => $is_risky ? 'risky' : 'approaching',
                'affected_rows' => (array) ($rows_by_upc[$upc_key] ?? []),
            ];
        }

        usort($watch_rows, static function (array $a, array $b): int {
            $a_risky = ((string) ($a['risk_level'] ?? '')) === 'risky' ? 0 : 1;
            $b_risky = ((string) ($b['risk_level'] ?? '')) === 'risky' ? 0 : 1;
            if ($a_risky !== $b_risky) {
                return $a_risky <=> $b_risky;
            }

            $a_dist = $a['distance_after_batch'];
            $b_dist = $b['distance_after_batch'];
            if ($a_dist === null && $b_dist !== null) {
                return 1;
            }
            if ($a_dist !== null && $b_dist === null) {
                return -1;
            }
            if ($a_dist !== $b_dist) {
                return ((int) $a_dist <=> (int) $b_dist);
            }

            return strcmp((string) ($a['upc'] ?? ''), (string) ($b['upc'] ?? ''));
        });

        return $watch_rows;
    }

    private function get_rsr_distributor(): ?DistributorBase
    {
        $dist = $this->handler->get_distributor_by_id(self::RSR_DIST_ID);
        return ($dist instanceof DistributorBase) ? $dist : null;
    }

    private function normalize_upc_key(string $upc): string
    {
        $upc = trim($upc);
        if ($upc === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $upc);
        if (is_string($digits) && $digits !== '') {
            return $digits;
        }

        return strtoupper($upc);
    }

    private function render_summary_cards(array $data, array $settings): void
    {
        $status_counts = (array) ($data['status_counts'] ?? []);
        $batch_pending = (int) ($status_counts[OrderPlacementKeys::JOB_STATUS_BATCH_PENDING] ?? 0);
        $running = (int) ($status_counts[OrderPlacementKeys::JOB_STATUS_RUNNING] ?? 0);
        $scheduled = (int) ($status_counts[OrderPlacementKeys::JOB_STATUS_SCHEDULED] ?? 0);
        $retry = (int) ($status_counts[OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED] ?? 0);
        $failed = (int) ($status_counts[OrderPlacementKeys::JOB_STATUS_FAILED] ?? 0);
        $manual = (int) ($status_counts[OrderPlacementKeys::JOB_STATUS_MANUAL] ?? 0);
        $holosun_manual_entries = (array) ($data['holosun_manual_entries'] ?? []);
        $queue_total_cost = (float) ($data['queue_total_cost'] ?? 0.0);
        ?>
        <div class="fflhub-rsr-summary-grid">
            <section class="fflhub-rsr-summary-card">
                <h2><?php esc_html_e('Processing Orders', 'ffl-hub'); ?></h2>
                <div class="fflhub-rsr-metric"><?php echo esc_html((string) ((int) $data['processing_order_count'])); ?></div>
            </section>
            <section class="fflhub-rsr-summary-card">
                <h2><?php esc_html_e('Dealer Jobs', 'ffl-hub'); ?></h2>
                <div class="fflhub-rsr-metric"><?php echo esc_html((string) ((int) $data['job_count'])); ?></div>
                <p><?php echo esc_html(sprintf(__('pending=%d, manual=%d, running=%d, scheduled=%d, retry=%d, failed=%d', 'ffl-hub'), $batch_pending, $manual, $running, $scheduled, $retry, $failed)); ?></p>
            </section>
            <section class="fflhub-rsr-summary-card is-danger">
                <h2><?php esc_html_e('Holosun Manual Rows', 'ffl-hub'); ?></h2>
                <div class="fflhub-rsr-metric"><?php echo esc_html((string) count($holosun_manual_entries)); ?></div>
                <p><?php esc_html_e('Excluded from automatic RSR batch ordering.', 'ffl-hub'); ?></p>
            </section>
            <section class="fflhub-rsr-summary-card is-ok">
                <h2><?php esc_html_e('Queue Totals by UPC', 'ffl-hub'); ?></h2>
                <div class="fflhub-rsr-metric"><?php echo esc_html((string) ((int) $data['total_quantity'])); ?></div>
                <p><?php echo esc_html(sprintf(__('Distinct UPCs: %d | Line Entries: %d', 'ffl-hub'), (int) $data['distinct_upc_count'], (int) $data['line_count'])); ?></p>
            </section>
            <section class="fflhub-rsr-summary-card <?php echo !empty($settings['enabled']) ? 'is-ok' : 'is-danger'; ?>">
                <h2><?php esc_html_e('Batch Mode', 'ffl-hub'); ?></h2>
                <div class="fflhub-rsr-metric"><?php echo esc_html(!empty($settings['enabled']) ? __('Enabled', 'ffl-hub') : __('Disabled', 'ffl-hub')); ?></div>
                <p><?php echo esc_html(sprintf(__('Dispatch: %s | Ready now: %d | Est. queue cost: %s', 'ffl-hub'), (string) ($settings['dispatch_time'] ?? self::DEFAULT_DISPATCH_TIME), (int) ($data['dispatch_ready_jobs'] ?? 0), $this->format_money($queue_total_cost))); ?></p>
            </section>
        </div>
        <?php
    }

    private function render_running_totals_table(array $data): void
    {
        $rows = $data['totals_by_upc'];
        if (empty($rows)) {
            echo '<p>' . esc_html__('No batch_pending UPC totals right now.', 'ffl-hub') . '</p>';
            return;
        }
        ?>
        <h2><?php esc_html_e('Batch-Pending Totals by UPC', 'ffl-hub'); ?></h2>
        <table class="widefat fixed striped">
            <thead><tr><th><?php esc_html_e('UPC', 'ffl-hub'); ?></th><th><?php esc_html_e('Product Name', 'ffl-hub'); ?></th><th><?php esc_html_e('Total Qty', 'ffl-hub'); ?></th><th><?php esc_html_e('Line Entries', 'ffl-hub'); ?></th><th><?php esc_html_e('Est. Distributor Cost', 'ffl-hub'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td><code><?php echo esc_html((string) ($row['upc'] ?? '')); ?></code></td>
                        <td><?php echo esc_html((string) ($row['product_name'] ?? 'Unknown product')); ?></td>
                        <td><?php echo esc_html((string) ((int) ($row['total_qty'] ?? 0))); ?></td>
                        <td><?php echo esc_html((string) ((int) ($row['line_count'] ?? 0))); ?></td>
                        <td><?php echo esc_html($this->format_money((float) ($row['total_estimated_cost'] ?? 0.0))); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_holosun_manual_table(array $data): void
    {
        $entries = (array) ($data['holosun_manual_entries'] ?? []);
        ?>
        <section class="fflhub-rsr-holosun-manual-box">
            <h2><?php esc_html_e('RSR Holosun Manual Order Rows', 'ffl-hub'); ?></h2>
            <p class="description">
                <?php esc_html_e('Holosun dealer-fulfilled RSR rows are held out of automatic RSR batch placement. Manually place them with RSR, enter the PO here, then mark the row successful.', 'ffl-hub'); ?>
            </p>
            <?php if (empty($entries)) : ?>
                <p><?php esc_html_e('No Holosun RSR manual rows found for processing orders.', 'ffl-hub'); ?></p>
            <?php else : ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Job ID', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Order', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Status', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Merchant PO', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('UPC', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Product', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Qty', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Line Cost', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Manual PO / Mark Success', 'ffl-hub'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry) : ?>
                            <?php
                            $order_id = (int) ($entry['order_id'] ?? 0);
                            $job_id = (int) ($entry['job_id'] ?? 0);
                            $job_key = (string) ($entry['job_key'] ?? '');
                            $status = strtolower(trim((string) ($entry['job_status'] ?? '')));
                            $merchant_po = trim((string) ($entry['merchant_po'] ?? ''));
                            $order_edit_url = admin_url('post.php?post=' . $order_id . '&action=edit');
                            $status_class = $this->status_class($status);
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) $job_id); ?></td>
                                <td><?php if ($order_id > 0) : ?><a href="<?php echo esc_url($order_edit_url); ?>"><?php echo esc_html('#' . (string) $order_id); ?></a><?php else : ?>-<?php endif; ?></td>
                                <td><span class="fflhub-rsr-status-pill <?php echo esc_attr($status_class); ?>"><?php echo esc_html((string) ($entry['job_status'] ?? '')); ?></span></td>
                                <td><code><?php echo esc_html($merchant_po !== '' ? $merchant_po : '-'); ?></code></td>
                                <td><code><?php echo esc_html((string) ($entry['upc'] ?? '')); ?></code></td>
                                <td><?php echo esc_html((string) ($entry['product_name'] ?? 'Unknown product')); ?></td>
                                <td><?php echo esc_html((string) ((int) ($entry['qty'] ?? 0))); ?></td>
                                <td><?php echo esc_html($this->format_money((float) ($entry['line_cost'] ?? 0.0))); ?></td>
                                <td>
                                    <form method="post" action="" class="fflhub-rsr-manual-po-form">
                                        <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                                        <input type="hidden" name="fflhub_rsr_batch_action" value="<?php echo esc_attr(self::FORM_ACTION_HOLOSUN_MANUAL_PO); ?>" />
                                        <input type="hidden" name="fflhub_rsr_manual_order_id" value="<?php echo esc_attr((string) $order_id); ?>" />
                                        <input type="hidden" name="fflhub_rsr_manual_job_key" value="<?php echo esc_attr($job_key); ?>" />
                                        <input
                                            type="text"
                                            name="fflhub_rsr_manual_merchant_po"
                                            value="<?php echo esc_attr($merchant_po); ?>"
                                            maxlength="32"
                                            placeholder="<?php esc_attr_e('Enter RSR PO', 'ffl-hub'); ?>"
                                            class="regular-text fflhub-rsr-manual-po-input" />
                                        <button type="submit" class="button button-secondary button-small">
                                            <?php echo esc_html($status === OrderPlacementKeys::JOB_STATUS_SUCCESS ? __('Update PO', 'ffl-hub') : __('Save + Mark Success', 'ffl-hub')); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
        <?php
    }

    private function render_low_stock_watch_table(array $data, array $settings): void
    {
        $rows = (array) ($data['stock_watch_rows'] ?? []);
        $threshold = max(0, (int) ($settings['low_stock_threshold'] ?? self::DEFAULT_LOW_STOCK_THRESHOLD));
        ?>
        <h2><?php esc_html_e('Low-Stock / Approaching Threshold Watch', 'ffl-hub'); ?></h2>
        <p class="description">
            <?php
            echo esc_html(
                sprintf(
                    __('Shows batch-pending UPCs that are already risky or would approach threshold after the current queued demand. Threshold=%d', 'ffl-hub'),
                    $threshold
                )
            );
            ?>
        </p>
        <?php if (empty($rows)) : ?>
            <p><?php esc_html_e('No risky or approaching UPCs right now.', 'ffl-hub'); ?></p>
            <?php
            return;
        endif;
        ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Risk', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('UPC', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Product', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Queued Qty', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Available', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Threshold', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Distance Now', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Remaining After Batch', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Distance After Batch', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Affected Rows', 'ffl-hub'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $risk = strtolower(trim((string) ($row['risk_level'] ?? 'approaching')));
                    $is_risky = ($risk === 'risky');
                    $risk_label = $is_risky ? __('Risky', 'ffl-hub') : __('Approaching', 'ffl-hub');
                    $risk_class = $is_risky ? 'fflhub-rsr-status-danger' : 'fflhub-rsr-status-running';
                    $available = $row['available_qty'];
                    $distance_now = $row['distance_now'];
                    $remaining_after = $row['remaining_after_batch'];
                    $distance_after = $row['distance_after_batch'];
                    $affected_rows = (array) ($row['affected_rows'] ?? []);
                    ?>
                    <tr>
                        <td><span class="fflhub-rsr-status-pill <?php echo esc_attr($risk_class); ?>"><?php echo esc_html($risk_label); ?></span></td>
                        <td><code><?php echo esc_html((string) ($row['upc'] ?? '')); ?></code></td>
                        <td><?php echo esc_html((string) ($row['product_name'] ?? 'Unknown product')); ?></td>
                        <td><?php echo esc_html((string) ((int) ($row['requested_qty'] ?? 0))); ?></td>
                        <td><?php echo esc_html($available === null ? __('Unknown', 'ffl-hub') : (string) ((int) $available)); ?></td>
                        <td><?php echo esc_html((string) ((int) ($row['threshold'] ?? 0))); ?></td>
                        <td><?php echo esc_html($distance_now === null ? __('Unknown', 'ffl-hub') : (string) ((int) $distance_now)); ?></td>
                        <td><?php echo esc_html($remaining_after === null ? __('Unknown', 'ffl-hub') : (string) ((int) $remaining_after)); ?></td>
                        <td><?php echo esc_html($distance_after === null ? __('Unknown', 'ffl-hub') : (string) ((int) $distance_after)); ?></td>
                        <td>
                            <?php if (empty($affected_rows)) : ?>
                                <?php esc_html_e('None', 'ffl-hub'); ?>
                            <?php else : ?>
                                <details>
                                    <summary><?php echo esc_html(sprintf(_n('%d row', '%d rows', count($affected_rows), 'ffl-hub'), count($affected_rows))); ?></summary>
                                    <ul style="margin:8px 0 0 16px;list-style:disc;">
                                        <?php foreach ($affected_rows as $affected) : ?>
                                            <?php
                                            $order_id = (int) ($affected['order_id'] ?? 0);
                                            $order_label = ($order_id > 0) ? ('#' . (string) $order_id) : '-';
                                            $qty = (int) ($affected['qty'] ?? 0);
                                            $job_id = (int) ($affected['job_id'] ?? 0);
                                            $next_run_at = trim((string) ($affected['next_run_at'] ?? ''));
                                            ?>
                                            <li>
                                                <?php
                                                echo esc_html(
                                                    sprintf(
                                                        __('Job %d | Order %s | Qty %d | Next %s', 'ffl-hub'),
                                                        $job_id,
                                                        $order_label,
                                                        $qty,
                                                        $next_run_at !== '' ? $next_run_at : '-'
                                                    )
                                                );
                                                ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_entries_table(array $data): void
    {
        $entries = array_values(array_filter((array) $data['entries'], static function (array $entry): bool {
            return empty($entry['is_holosun_manual_row']);
        }));
        if (empty($entries)) {
            echo '<p>' . esc_html__('No RSR dealer line entries found for processing orders.', 'ffl-hub') . '</p>';
            return;
        }
        ?>
        <h2><?php esc_html_e('RSR Dealer Line Entries (Per UPC)', 'ffl-hub'); ?></h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Job ID', 'ffl-hub'); ?></th><th><?php esc_html_e('Order', 'ffl-hub'); ?></th><th><?php esc_html_e('Job Key', 'ffl-hub'); ?></th><th><?php esc_html_e('Status', 'ffl-hub'); ?></th><th><?php esc_html_e('Attempts', 'ffl-hub'); ?></th><th><?php esc_html_e('Next Run (UTC)', 'ffl-hub'); ?></th><th><?php esc_html_e('Updated (UTC)', 'ffl-hub'); ?></th><th><?php esc_html_e('Merchant PO', 'ffl-hub'); ?></th><th><?php esc_html_e('UPC', 'ffl-hub'); ?></th><th><?php esc_html_e('Product Name', 'ffl-hub'); ?></th><th><?php esc_html_e('Qty', 'ffl-hub'); ?></th><th><?php esc_html_e('Unit Cost', 'ffl-hub'); ?></th><th><?php esc_html_e('Line Cost', 'ffl-hub'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry) : ?>
                    <?php
                    $order_id = (int) ($entry['order_id'] ?? 0);
                    $order_edit_url = admin_url('post.php?post=' . $order_id . '&action=edit');
                    $status = strtolower(trim((string) ($entry['job_status'] ?? '')));
                    $status_class = $this->status_class($status);
                    ?>
                    <tr>
                        <td><?php echo esc_html((string) ((int) ($entry['job_id'] ?? 0))); ?></td>
                        <td><?php if ($order_id > 0) : ?><a href="<?php echo esc_url($order_edit_url); ?>"><?php echo esc_html('#' . (string) $order_id); ?></a><?php else : ?>-<?php endif; ?></td>
                        <td><code><?php echo esc_html((string) ($entry['job_key'] ?? '')); ?></code></td>
                        <td><span class="fflhub-rsr-status-pill <?php echo esc_attr($status_class); ?>"><?php echo esc_html((string) ($entry['job_status'] ?? '')); ?></span></td>
                        <td><?php echo esc_html((string) ((int) ($entry['attempts'] ?? 0))); ?></td>
                        <td><?php echo esc_html((string) (($entry['next_run_at'] ?? '') !== '' ? $entry['next_run_at'] : '-')); ?></td>
                        <td><?php echo esc_html((string) ($entry['updated_at'] ?? '')); ?></td>
                        <td><code><?php echo esc_html((string) (($entry['merchant_po'] ?? '') !== '' ? $entry['merchant_po'] : '-')); ?></code></td>
                        <td><code><?php echo esc_html((string) ($entry['upc'] ?? '')); ?></code></td>
                        <td><?php echo esc_html((string) ($entry['product_name'] ?? 'Unknown product')); ?></td>
                        <td><?php echo esc_html((string) ((int) ($entry['qty'] ?? 0))); ?></td>
                        <td><?php echo esc_html($this->format_money((float) ($entry['unit_cost'] ?? 0.0))); ?></td>
                        <td><?php echo esc_html($this->format_money((float) ($entry['line_cost'] ?? 0.0))); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_system_explainer(array $settings): void
    {
        $threshold = max(0, (int) ($settings['low_stock_threshold'] ?? self::DEFAULT_LOW_STOCK_THRESHOLD));
        $dispatch_time = (string) ($settings['dispatch_time'] ?? self::DEFAULT_DISPATCH_TIME);
        $retry_delay = max(30, (int) ($settings['retry_delay_seconds'] ?? self::DEFAULT_RETRY_DELAY_SECONDS));
        $max_rows = max(1, (int) ($settings['max_rows_per_run'] ?? self::DEFAULT_MAX_ROWS_PER_RUN));
        ?>
        <section class="fflhub-rsr-batch-card">
            <h2><?php esc_html_e('How This Works', 'ffl-hub'); ?></h2>
            <p>
                <?php esc_html_e('This page is a live, per-line-item view of RSR dealer-fulfilled jobs for Woo orders in Processing status. It helps you see what is queued, what is at risk, and what will dispatch.', 'ffl-hub'); ?>
            </p>
            <h3><?php esc_html_e('Low-Stock / Approaching Logic', 'ffl-hub'); ?></h3>
            <ul style="list-style:disc;margin-left:18px;">
                <li><?php echo esc_html(sprintf(__('Demand is summed by UPC across all batch_pending rows. This means threshold checks use total queued demand, not a single row.', 'ffl-hub'))); ?></li>
                <li><?php echo esc_html(sprintf(__('Risky UPCs are flagged when any of these are true: stock is unknown, available stock is <= threshold (%d), or available stock is less than summed queued quantity.', 'ffl-hub'), $threshold)); ?></li>
                <li><?php esc_html_e('Approaching UPCs are not currently risky, but would be at/under threshold after fulfilling the current queued demand.', 'ffl-hub'); ?></li>
                <li><?php esc_html_e('Affected Rows shows exactly which jobs and orders contribute to that UPC demand.', 'ffl-hub'); ?></li>
                <li><?php esc_html_e('Distance columns show buffer to threshold now and after the queued batch is applied.', 'ffl-hub'); ?></li>
            </ul>
            <h3><?php esc_html_e('Batch Flow', 'ffl-hub'); ?></h3>
            <ul style="list-style:disc;margin-left:18px;">
                <li><?php esc_html_e('Batch mode controls whether eligible RSR dealer rows are queued as batch_pending for grouped placement.', 'ffl-hub'); ?></li>
                <li><?php echo esc_html(sprintf(__('Dispatch time is %s (local site time). Rows wait until that window unless force flush is enabled.', 'ffl-hub'), $dispatch_time)); ?></li>
                <li><?php esc_html_e('RSR scheduled batch placement is blocked on Saturdays and Sundays; low-stock priority rows can still place immediately.', 'ffl-hub'); ?></li>
                <li><?php esc_html_e('Force Flush + Run Now sets a one-time force flag and schedules the batch cron immediately.', 'ffl-hub'); ?></li>
                <li><?php echo esc_html(sprintf(__('Retry Delay (%d sec) and Max Rows Per Run (%d) bound how aggressively each cron run processes queue entries.', 'ffl-hub'), $retry_delay, $max_rows)); ?></li>
                <li><?php esc_html_e('The queue tables above show both aggregated UPC demand and raw per-line entries so you can audit exactly what will be sent.', 'ffl-hub'); ?></li>
            </ul>
        </section>
        <?php
    }

    private function is_job_dispatch_ready(string $next_run_at, int $now_utc_ts): bool
    {
        $next_run_at = trim($next_run_at);
        if ($next_run_at === '' || $next_run_at === '0000-00-00 00:00:00') {
            return true;
        }
        $ts = strtotime($next_run_at . ' UTC');
        if ($ts === false) {
            return true;
        }
        return $ts <= $now_utc_ts;
    }

    private function job_contains_holosun_line(OrderPlacementJobRow $job): bool
    {
        foreach ($job->payload_lines() as $line) {
            if (!($line instanceof DistributorOrderLine)) {
                continue;
            }

            if (HolosunProductDetector::is_holosun_upc((string) $line->upc)) {
                return true;
            }
        }

        return false;
    }

    private function resolve_product_name_for_upc(string $upc, array &$cache): string
    {
        $upc = trim($upc);
        if ($upc === '') {
            return 'Unknown product';
        }
        if (array_key_exists($upc, $cache)) {
            return $cache[$upc];
        }
        $product_id = $this->find_product_id_by_upc($upc);
        if ($product_id <= 0) {
            $cache[$upc] = 'Unknown product';
            return $cache[$upc];
        }
        $product = wc_get_product($product_id);
        $name = ($product && method_exists($product, 'get_name')) ? trim((string) $product->get_name()) : '';
        if ($name === '') {
            $name = 'Unknown product';
        }
        $cache[$upc] = $name;
        return $name;
    }

    private function resolve_distributor_unit_cost_for_upc(string $upc, array &$cache): float
    {
        $upc = trim($upc);
        if ($upc === '') {
            return 0.0;
        }
        if (array_key_exists($upc, $cache)) {
            return (float) $cache[$upc];
        }
        $product_id = $this->find_product_id_by_upc($upc);
        if ($product_id <= 0) {
            $cache[$upc] = 0.0;
            return 0.0;
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            $cache[$upc] = 0.0;
            return 0.0;
        }
        $dealer_price = $this->to_non_negative_float($product->get_meta(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, true));
        $true_cost = $this->to_non_negative_float($product->get_meta(ProductMeta::FFLHUB_LAST_TRUE_COST_META, true));
        $unit_cost = ($dealer_price > 0.0) ? $dealer_price : $true_cost;
        $cache[$upc] = $unit_cost;
        return $unit_cost;
    }

    private function find_product_id_by_upc(string $upc): int
    {
        global $wpdb;
        $upc = trim($upc);
        if ($upc === '') {
            return 0;
        }
        foreach ([ProductMeta::FFLHUB_UPC_META, '_upc', 'upc'] as $meta_key) {
            $sql = $wpdb->prepare(
                "SELECT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                   AND pm.meta_value = %s
                   AND p.post_type = 'product'
                   AND p.post_status IN ('publish', 'private')
                 ORDER BY pm.post_id DESC
                 LIMIT 1",
                $meta_key,
                $upc
            );
            $found_id = (int) $wpdb->get_var($sql);
            if ($found_id > 0) {
                return $found_id;
            }
        }
        return 0;
    }

    private function sanitize_dispatch_time(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^([0-1]?\d|2[0-3]):([0-5]\d)$/', $value, $m)) {
            return self::DEFAULT_DISPATCH_TIME;
        }
        return sprintf('%02d:%02d', (int) ($m[1] ?? 17), (int) ($m[2] ?? 0));
    }

    private function is_weekend_hold_active(): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::DISPATCH_TZ));
        $day_of_week = (int) $now->format('N');
        return $day_of_week >= 6;
    }

    private function next_allowed_dispatch_label(string $dispatch_time): string
    {
        $dispatch_time = $this->sanitize_dispatch_time($dispatch_time);
        [$hour, $minute] = array_map('intval', explode(':', $dispatch_time, 2));

        $tz = new \DateTimeZone(self::DISPATCH_TZ);
        $now = new \DateTimeImmutable('now', $tz);
        $candidate = $now->setTime($hour, $minute, 0);
        if ($now >= $candidate) {
            $candidate = $candidate->modify('+1 day')->setTime($hour, $minute, 0);
        }

        for ($i = 0; $i < 14; $i++) {
            $day_of_week = (int) $candidate->format('N');
            if ($day_of_week >= 1 && $day_of_week <= 5) {
                return $candidate->format('D M j, Y g:i A T');
            }
            $candidate = $candidate->modify('+1 day')->setTime($hour, $minute, 0);
        }

        return $candidate->format('D M j, Y g:i A T');
    }

    private function to_non_negative_float($value): float
    {
        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw)) {
            return 0.0;
        }
        $v = (float) $raw;
        if (!is_finite($v) || $v < 0.0) {
            return 0.0;
        }
        return $v;
    }

    private function to_checkbox_string($raw): string
    {
        return $this->to_boolish($raw) ? '1' : '0';
    }

    private function to_boolish($raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return ((int) $raw) === 1;
        }
        $v = strtolower(trim((string) $raw));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    private function truthy_option(string $option_name, bool $default): bool
    {
        return $this->to_boolish(get_option($option_name, $default ? '1' : '0'));
    }

    private function format_money(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }

    private function status_class(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === OrderPlacementKeys::JOB_STATUS_BATCH_PENDING) {
            return 'fflhub-rsr-status-pending';
        }
        if ($status === OrderPlacementKeys::JOB_STATUS_SUCCESS) {
            return 'fflhub-rsr-status-success';
        }
        if ($status === OrderPlacementKeys::JOB_STATUS_FAILED) {
            return 'fflhub-rsr-status-danger';
        }
        if ($status === OrderPlacementKeys::JOB_STATUS_RUNNING) {
            return 'fflhub-rsr-status-running';
        }
        if ($status === OrderPlacementKeys::JOB_STATUS_MANUAL) {
            return 'fflhub-rsr-status-manual';
        }

        return 'fflhub-rsr-status-neutral';
    }

    private function render_styles(): void
    {
        ?>
        <style>
            .fflhub-rsr-batch-card,.fflhub-rsr-batch-force-box,.fflhub-rsr-summary-card,.fflhub-rsr-holosun-manual-box{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px;box-shadow:0 1px 2px rgba(0,0,0,.04);margin:14px 0}
            .fflhub-rsr-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin:14px 0 18px}
            .fflhub-rsr-summary-card{margin:0}
            .fflhub-rsr-summary-card.is-ok{border-color:#a7f3d0;background:#ecfdf5}
            .fflhub-rsr-summary-card.is-danger{border-color:#fecaca;background:#fef2f2}
            .fflhub-rsr-summary-card h2{margin:0 0 8px;font-size:15px}
            .fflhub-rsr-metric{font-size:24px;font-weight:700;line-height:1.2}
            .fflhub-rsr-summary-card p{margin:8px 0 0;color:#50575e}
            .fflhub-rsr-batch-status table code{word-break:break-all}
            .fflhub-rsr-status-pill{display:inline-block;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;text-transform:uppercase}
            .fflhub-rsr-status-pending{background:#e0f2fe;color:#0c4a6e}.fflhub-rsr-status-running{background:#fef3c7;color:#92400e}.fflhub-rsr-status-success{background:#dcfce7;color:#166534}.fflhub-rsr-status-danger{background:#fee2e2;color:#991b1b}.fflhub-rsr-status-manual{background:#ede9fe;color:#5b21b6}.fflhub-rsr-status-neutral{background:#f3f4f6;color:#374151}
            .fflhub-rsr-manual-po-form{display:flex;flex-direction:column;gap:6px;min-width:170px}
            .fflhub-rsr-manual-po-input{width:100%;min-width:140px}
        </style>
        <?php
    }
}

