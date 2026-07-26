<?php

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Distributor\Services\Orders\Cron\DealerBatchCronRegistry;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Optimization\DealerBatchOptimizerAuditTable;
use FFLHub\Distributor\Services\Orders\Optimization\DealerBatchOptimizerConfig;
use FFLHub\Distributor\Services\Orders\Optimization\DealerBatchShippingOptimizer;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Product\State\ProductStateStore;

if (!defined('ABSPATH')) {
    exit;
}

final class DealerBatchOptimizerPage
{
    private const PAGE_SLUG = 'fflhub-dealer-batch-optimizer';
    private const NONCE_ACTION = 'fflhub_dealer_batch_optimizer_page_action';
    private const NONCE_FIELD = 'fflhub_dealer_batch_optimizer_nonce';
    private const ACTION_SAVE = 'save_settings';
    private const ACTION_RUN_OPTIMIZER = 'run_optimizer';
    private const ACTION_FORCE_BATCHES = 'force_batches';

    private OrderPlacementJobsTable $jobs_table;
    private DistributorHandler $handler;
    private DealerBatchOptimizerAuditTable $audit_table;

    public function __construct(OrderPlacementJobsTable $jobs_table, DistributorHandler $handler)
    {
        $this->jobs_table = $jobs_table;
        $this->handler = $handler;
        $this->audit_table = new DealerBatchOptimizerAuditTable();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            DistributorOrderingAdminPage::MENU_SLUG,
            __('Dealer Batch Optimizer', 'ffl-hub'),
            __('Dealer Batch Optimizer', 'ffl-hub'),
            DistributorOrderingAdminPage::CAPABILITY,
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
        $runs = $this->audit_table->recent_runs(50);
        $moves = $this->audit_table->recent_moves(200);
        $latest_run = !empty($runs[0]) && is_array($runs[0]) ? $runs[0] : null;
        $latest_moves = is_array($latest_run)
            ? $this->audit_table->moves_for_run((string) ($latest_run['run_id'] ?? ''), 500)
            : [];
        $makeup = $this->build_latest_optimizer_makeup_data($latest_run, $latest_moves);
        ?>
        <div class="wrap fflhub-dealer-batch-optimizer">
            <h1><?php esc_html_e('Dealer Batch Optimizer', 'ffl-hub'); ?></h1>
            <p><?php esc_html_e('Central dealer-batch timing, equal-cost shipping optimization, and audit history.', 'ffl-hub'); ?></p>
            <?php $this->render_notice($notice); ?>
            <?php $this->render_explainer(); ?>
            <?php $this->render_settings_form(); ?>
            <?php $this->render_actions(); ?>
            <?php $this->render_latest_optimizer_makeup($makeup); ?>
            <?php $this->render_recent_runs($runs); ?>
            <?php $this->render_recent_moves($moves); ?>
        </div>
        <?php
    }

    private function render_explainer(): void
    {
        ?>
        <div style="max-width:1100px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;margin:16px 0;">
            <h2 style="margin-top:0;"><?php esc_html_e('How This Page Works', 'ffl-hub'); ?></h2>
            <p>
                <?php esc_html_e('Dealer-batch ordering is centralized here for the batch-enabled dealer-fulfilled distributors: Bill Hicks, Davidson\'s, RSR, Lipsey\'s, Orion, Sports South, and Zanders. Davidson\'s batch dispatch sends a manual-order email and then moves the rows to the Davidson\'s Manual Order Status workflow. CA relay batches keep their own timing because that is a different fulfillment flow.', 'ffl-hub'); ?>
            </p>
            <p>
                <?php esc_html_e('The shipping optimizer runs before a dealer-batch cron builds its final distributor order. It looks across pending dealer-batch jobs and may move a whole job row from one eligible distributor batch to another only when the item cost stays the same and the move improves free-shipping coverage.', 'ffl-hub'); ?>
            </p>

            <h3><?php esc_html_e('Setting Reference', 'ffl-hub'); ?></h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:240px;"><?php esc_html_e('Setting', 'ffl-hub'); ?></th>
                        <th><?php esc_html_e('What it does', 'ffl-hub'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e('Enable dealer batch processing', 'ffl-hub'); ?></strong></td>
                        <td><?php esc_html_e('Turns the shared dealer-batch placement system on or off for the batch-enabled dealer-fulfilled distributors. When disabled, those dealer batch crons skip placement.', 'ffl-hub'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Enable shipping optimizer', 'ffl-hub'); ?></strong></td>
                        <td><?php esc_html_e('Allows pending dealer-batch rows to be reassigned across eligible batch distributors before placement. If disabled, rows stay with the distributor selected by the original routing flow.', 'ffl-hub'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Dispatch time (Central)', 'ffl-hub'); ?></strong></td>
                        <td><?php esc_html_e('The scheduled dispatch window in America/Chicago time. Automated runs are held on Saturdays and Sundays; an explicit force flush bypasses both the clock and weekend hold.', 'ffl-hub'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Low stock threshold', 'ffl-hub'); ?></strong></td>
                        <td><?php esc_html_e('Stock level used to identify risk. Rows involving low-stock source inventory are treated as priority by the batch engine and are skipped by optional shipping optimization so the optimizer does not add risk.', 'ffl-hub'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Retry delay seconds', 'ffl-hub'); ?></strong></td>
                        <td><?php esc_html_e('How long failed batch rows wait before they are eligible for another placement attempt.', 'ffl-hub'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Max rows per run', 'ffl-hub'); ?></strong></td>
                        <td><?php esc_html_e('Maximum number of pending rows a distributor batch cron pulls in one run after optimization has had a chance to move rows.', 'ffl-hub'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Force flush token', 'ffl-hub'); ?></strong></td>
                        <td><?php esc_html_e('Requests a one-pass force flush. Each dealer-batch distributor can consume the token once, selecting queued rows regardless of future run time and bypassing the dispatch clock and weekend hold.', 'ffl-hub'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Free shipping threshold', 'ffl-hub'); ?></strong></td>
                        <td><?php esc_html_e('Dealer-cost subtotal needed for that distributor to qualify for free inbound freight. A blank or zero value means the optimizer will not try to optimize toward free shipping for that distributor.', 'ffl-hub'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Estimated paid inbound shipping cost', 'ffl-hub'); ?></strong></td>
                        <td><?php esc_html_e('Estimated inbound freight cost when that distributor has an active below-threshold batch. Empty distributors are not counted. If left at zero, known distributors use conservative defaults such as Bill Hicks $15, RSR $10, and Sports South $8.95.', 'ffl-hub'); ?></td>
                    </tr>
                </tbody>
            </table>

            <h3><?php esc_html_e('Shipping Optimization Rules', 'ffl-hub'); ?></h3>
            <ol>
                <li><?php esc_html_e('Only active pending dealer-batch source rows are considered. Direct customer drop-ship, CA relay, already-manual, failed, cancelled, refunded, already-submitted, and already-PO-stamped rows are not moved.', 'ffl-hub'); ?></li>
                <li><?php esc_html_e('Only Bill Hicks, CSSI, Davidson\'s, RSR, Lipsey\'s, Orion, Sports South, and Zanders are optimizer targets. MGE and disabled distributors are not optimizer targets.', 'ffl-hub'); ?></li>
                <li><?php esc_html_e('If a row is optimized to Davidson\'s, it stays batch pending until the Davidson\'s dealer-batch cron fires. That cron emails the manual order list, stamps the batch PO, and then shows the rows on the Davidson\'s Manual Order Status page.', 'ffl-hub'); ?></li>
                <li><?php esc_html_e('Product distributor locks are respected. If a product is locked, the target distributor must be in the product\'s allowed distributor lock list.', 'ffl-hub'); ?></li>
                <li><?php esc_html_e('The target distributor must carry the same UPC, have a distributor SKU available, and have enough stock for the whole moved job row.', 'ffl-hub'); ?></li>
                <li><?php esc_html_e('Item cost cannot increase. The source and target distributor prices must match after normal two-decimal money rounding, and both must be tied for the lowest eligible dealer-batch cost for that UPC.', 'ffl-hub'); ?></li>
                <li><?php esc_html_e('The optimizer moves whole job rows only. It does not split a quantity across multiple distributors.', 'ffl-hub'); ?></li>
                <li><?php esc_html_e('The optimizer accounts for other planned moves in the same run so it does not over-allocate target stock.', 'ffl-hub'); ?></li>
                <li><?php esc_html_e('Rows already optimized once are not bounced again by later optimizer runs.', 'ffl-hub'); ?></li>
                <li><?php esc_html_e('Before a daily dealer-batch dispatch, the first dealer-batch runner performs one shared optimizer preflight for the whole pending dealer-batch pool.', 'ffl-hub'); ?></li>
                <li><?php esc_html_e('Moves are written inside a database transaction and logged to the optimizer audit tables with before/after subtotals, estimated paid inbound shipping, and per-row move details.', 'ffl-hub'); ?></li>
            </ol>

            <h3><?php esc_html_e('Manual Actions', 'ffl-hub'); ?></h3>
            <p>
                <strong><?php esc_html_e('Run Optimizer Now', 'ffl-hub'); ?></strong>
                <?php esc_html_e('runs only the optimizer. It can move eligible pending rows, but it does not submit any distributor orders by itself.', 'ffl-hub'); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Force Flush All Dealer Batches', 'ffl-hub'); ?></strong>
                <?php esc_html_e('sets the shared force-flush token and schedules each dealer-batch distributor cron. It bypasses future run times, the dispatch clock, and the weekend hold. A single shared optimizer preflight runs first, then each distributor batch places whatever rows belong to that distributor after the final refetch.', 'ffl-hub'); ?>
            </p>
        </div>
        <?php
    }

    private function maybe_handle_post_action(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $action = isset($_POST['fflhub_dealer_batch_optimizer_action'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_dealer_batch_optimizer_action']))
            : '';
        if (!in_array($action, [self::ACTION_SAVE, self::ACTION_RUN_OPTIMIZER, self::ACTION_FORCE_BATCHES], true)) {
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

        if ($action === self::ACTION_SAVE) {
            $this->handle_save_settings();
        } elseif ($action === self::ACTION_RUN_OPTIMIZER) {
            (new DealerBatchShippingOptimizer($this->handler, $this->jobs_table, $this->audit_table))->run('admin');
            $this->redirect_with_notice('success', __('Optimizer run completed. Check the audit tables below for details.', 'ffl-hub'));
        } elseif ($action === self::ACTION_FORCE_BATCHES) {
            DealerBatchOptimizerConfig::mark_force_flush_requested();
            $this->schedule_all_dealer_batch_crons();
            $this->redirect_with_notice('success', __('Dealer batch force flush requested for all batch-enabled distributors.', 'ffl-hub'));
        }
    }

    private function handle_save_settings(): void
    {
        $enabled = $this->checkbox_post(DealerBatchOptimizerConfig::dealer_batch_option_name('enabled'));
        $optimizer_enabled = $this->checkbox_post(DealerBatchOptimizerConfig::optimizer_option_name('enabled'));
        $dispatch_time = $this->sanitize_dispatch_time($this->text_post(
            DealerBatchOptimizerConfig::dealer_batch_option_name('dispatch_time'),
            DealerBatchOptimizerConfig::DEFAULT_DISPATCH_TIME
        ));
        $low_stock_threshold = max(0, (int) $this->text_post(
            DealerBatchOptimizerConfig::dealer_batch_option_name('low_stock_threshold'),
            (string) DealerBatchOptimizerConfig::DEFAULT_LOW_STOCK_THRESHOLD
        ));
        $retry_delay_seconds = max(30, (int) $this->text_post(
            DealerBatchOptimizerConfig::dealer_batch_option_name('retry_delay_seconds'),
            (string) DealerBatchOptimizerConfig::DEFAULT_RETRY_DELAY_SECONDS
        ));
        $max_rows_per_run = max(1, (int) $this->text_post(
            DealerBatchOptimizerConfig::dealer_batch_option_name('max_rows_per_run'),
            (string) DealerBatchOptimizerConfig::DEFAULT_MAX_ROWS_PER_RUN
        ));
        $force_flush = $this->checkbox_post(DealerBatchOptimizerConfig::dealer_batch_option_name('force_flush'));

        update_option(DealerBatchOptimizerConfig::dealer_batch_option_name('enabled'), $enabled, false);
        update_option(DealerBatchOptimizerConfig::optimizer_option_name('enabled'), $optimizer_enabled, false);
        update_option(DealerBatchOptimizerConfig::dealer_batch_option_name('dispatch_time'), $dispatch_time, false);
        update_option(DealerBatchOptimizerConfig::dealer_batch_option_name('low_stock_threshold'), (string) $low_stock_threshold, false);
        update_option(DealerBatchOptimizerConfig::dealer_batch_option_name('retry_delay_seconds'), (string) $retry_delay_seconds, false);
        update_option(DealerBatchOptimizerConfig::dealer_batch_option_name('max_rows_per_run'), (string) $max_rows_per_run, false);
        if ($force_flush === '1') {
            DealerBatchOptimizerConfig::mark_force_flush_requested();
        } else {
            update_option(DealerBatchOptimizerConfig::dealer_batch_option_name('force_flush'), '0', false);
        }

        foreach (DealerBatchOptimizerConfig::optimizer_distributor_ids() as $dist_id) {
            $threshold_option = DealerBatchOptimizerConfig::free_shipping_threshold_option_name((string) $dist_id);
            $penalty_option = DealerBatchOptimizerConfig::shipping_penalty_option_name((string) $dist_id);
            update_option($threshold_option, $this->money_post($threshold_option), false);
            update_option($penalty_option, $this->money_post($penalty_option), false);
        }

        $this->redirect_with_notice('success', __('Dealer batch optimizer settings updated.', 'ffl-hub'));
    }

    private function render_settings_form(): void
    {
        $enabled = DealerBatchOptimizerConfig::dealer_batch_enabled();
        $optimizer_enabled = DealerBatchOptimizerConfig::optimizer_enabled();
        $dispatch_time = DealerBatchOptimizerConfig::dispatch_time();
        $low_stock_threshold = DealerBatchOptimizerConfig::low_stock_threshold();
        $retry_delay_seconds = DealerBatchOptimizerConfig::retry_delay_seconds();
        $max_rows_per_run = DealerBatchOptimizerConfig::max_rows_per_run();
        $force_flush = DealerBatchOptimizerConfig::force_flush_requested();
        ?>
        <form method="post" action="" style="max-width: 1100px;">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
            <input type="hidden" name="fflhub_dealer_batch_optimizer_action" value="<?php echo esc_attr(self::ACTION_SAVE); ?>" />
            <h2><?php esc_html_e('Central Dealer Batch Settings', 'ffl-hub'); ?></h2>
            <table class="form-table" role="presentation"><tbody>
                <tr><th scope="row"><?php esc_html_e('Enable dealer batch processing', 'ffl-hub'); ?></th><td>
                    <input type="hidden" name="<?php echo esc_attr(DealerBatchOptimizerConfig::dealer_batch_option_name('enabled')); ?>" value="0" />
                    <label><input type="checkbox" name="<?php echo esc_attr(DealerBatchOptimizerConfig::dealer_batch_option_name('enabled')); ?>" value="1" <?php checked($enabled); ?> /> <?php esc_html_e('Use dealer batch placement for all batch-enabled distributors.', 'ffl-hub'); ?></label>
                </td></tr>
                <tr><th scope="row"><?php esc_html_e('Enable shipping optimizer', 'ffl-hub'); ?></th><td>
                    <input type="hidden" name="<?php echo esc_attr(DealerBatchOptimizerConfig::optimizer_option_name('enabled')); ?>" value="0" />
                    <label><input type="checkbox" name="<?php echo esc_attr(DealerBatchOptimizerConfig::optimizer_option_name('enabled')); ?>" value="1" <?php checked($optimizer_enabled); ?> /> <?php esc_html_e('Move equal-cost pending dealer-batch rows when it improves free-shipping coverage.', 'ffl-hub'); ?></label>
                </td></tr>
                <tr><th scope="row"><?php esc_html_e('Dispatch time (Central)', 'ffl-hub'); ?></th><td>
                    <input type="text" class="regular-text" name="<?php echo esc_attr(DealerBatchOptimizerConfig::dealer_batch_option_name('dispatch_time')); ?>" value="<?php echo esc_attr($dispatch_time); ?>" placeholder="17:00" />
                    <p class="description"><?php esc_html_e('Central time used by automated dealer batches. Davidson\'s is optimizer-eligible, but Davidson\'s target rows are marked manual instead of automatically submitted.', 'ffl-hub'); ?></p>
                </td></tr>
                <tr><th scope="row"><?php esc_html_e('Low stock threshold', 'ffl-hub'); ?></th><td>
                    <input type="number" min="0" step="1" class="small-text" name="<?php echo esc_attr(DealerBatchOptimizerConfig::dealer_batch_option_name('low_stock_threshold')); ?>" value="<?php echo esc_attr((string) $low_stock_threshold); ?>" />
                </td></tr>
                <tr><th scope="row"><?php esc_html_e('Retry delay seconds', 'ffl-hub'); ?></th><td>
                    <input type="number" min="30" step="1" class="small-text" name="<?php echo esc_attr(DealerBatchOptimizerConfig::dealer_batch_option_name('retry_delay_seconds')); ?>" value="<?php echo esc_attr((string) $retry_delay_seconds); ?>" />
                </td></tr>
                <tr><th scope="row"><?php esc_html_e('Max rows per run', 'ffl-hub'); ?></th><td>
                    <input type="number" min="1" step="1" class="small-text" name="<?php echo esc_attr(DealerBatchOptimizerConfig::dealer_batch_option_name('max_rows_per_run')); ?>" value="<?php echo esc_attr((string) $max_rows_per_run); ?>" />
                </td></tr>
                <tr><th scope="row"><?php esc_html_e('Force flush token', 'ffl-hub'); ?></th><td>
                    <input type="hidden" name="<?php echo esc_attr(DealerBatchOptimizerConfig::dealer_batch_option_name('force_flush')); ?>" value="0" />
                    <label><input type="checkbox" name="<?php echo esc_attr(DealerBatchOptimizerConfig::dealer_batch_option_name('force_flush')); ?>" value="1" <?php checked($force_flush); ?> /> <?php esc_html_e('Request one force flush pass for each dealer-batch distributor.', 'ffl-hub'); ?></label>
                </td></tr>
            </tbody></table>

            <h2><?php esc_html_e('Free Shipping Thresholds', 'ffl-hub'); ?></h2>
            <table class="widefat striped" style="max-width: 760px;">
                <thead><tr><th><?php esc_html_e('Distributor', 'ffl-hub'); ?></th><th><?php esc_html_e('Free shipping threshold', 'ffl-hub'); ?></th><th><?php esc_html_e('Estimated paid inbound shipping cost', 'ffl-hub'); ?></th></tr></thead>
                <tbody>
                    <?php foreach (DealerBatchOptimizerConfig::optimizer_distributor_ids() as $dist_id) :
                        $threshold_option = DealerBatchOptimizerConfig::free_shipping_threshold_option_name((string) $dist_id);
                        $penalty_option = DealerBatchOptimizerConfig::shipping_penalty_option_name((string) $dist_id);
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html((string) $dist_id); ?></strong></td>
                            <td><input type="number" min="0" step="0.01" name="<?php echo esc_attr($threshold_option); ?>" value="<?php echo esc_attr((string) DealerBatchOptimizerConfig::free_shipping_threshold((string) $dist_id)); ?>" /></td>
                            <td><input type="number" min="0" step="0.01" name="<?php echo esc_attr($penalty_option); ?>" value="<?php echo esc_attr((string) DealerBatchOptimizerConfig::shipping_penalty((string) $dist_id)); ?>" /></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button(__('Save Dealer Batch Settings', 'ffl-hub')); ?>
        </form>
        <?php
    }

    private function render_actions(): void
    {
        ?>
        <h2><?php esc_html_e('Manual Actions', 'ffl-hub'); ?></h2>
        <form method="post" action="" style="display:inline-block;margin-right:12px;">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
            <input type="hidden" name="fflhub_dealer_batch_optimizer_action" value="<?php echo esc_attr(self::ACTION_RUN_OPTIMIZER); ?>" />
            <?php submit_button(__('Run Optimizer Now', 'ffl-hub'), 'secondary', '', false); ?>
        </form>
        <form method="post" action="" style="display:inline-block;">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
            <input type="hidden" name="fflhub_dealer_batch_optimizer_action" value="<?php echo esc_attr(self::ACTION_FORCE_BATCHES); ?>" />
            <?php submit_button(__('Force Flush All Dealer Batches', 'ffl-hub'), 'secondary', '', false); ?>
        </form>
        <?php
    }

    /**
     * @param array{
     *   latest_run:?array<string,mixed>,
     *   generated_at:string,
     *   total_jobs:int,
     *   total_lines:int,
     *   total_qty:int,
     *   total_subtotal:float,
     *   total_estimated_paid_shipping:float,
     *   groups:array<string,array<string,mixed>>
     * } $makeup
     */
    private function render_latest_optimizer_makeup(array $makeup): void
    {
        $latest_run = $makeup['latest_run'];
        $groups = (array) ($makeup['groups'] ?? []);
        ?>
        <section class="fflhub-dbo-card" style="max-width:1280px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;margin:22px 0;">
            <h2 style="margin-top:0;"><?php esc_html_e('Latest Optimizer Run Makeup', 'ffl-hub'); ?></h2>
            <p style="margin-top:0;color:#50575e;">
                <?php esc_html_e('Live dealer-batch queue makeup after the most recent optimizer run. This is read-only and shows the current batch_pending dealer rows by distributor.', 'ffl-hub'); ?>
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin:12px 0 16px;">
                <div style="border:1px solid #dcdcde;border-radius:8px;padding:10px 12px;background:#f6f7f7;">
                    <strong><?php esc_html_e('Latest Run', 'ffl-hub'); ?></strong><br />
                    <?php if (is_array($latest_run)) : ?>
                        <code><?php echo esc_html((string) ($latest_run['run_id'] ?? '')); ?></code><br />
                        <span><?php echo esc_html((string) ($latest_run['status'] ?? '')); ?></span>
                    <?php else : ?>
                        <span><?php esc_html_e('No run yet', 'ffl-hub'); ?></span>
                    <?php endif; ?>
                </div>
                <div style="border:1px solid #dcdcde;border-radius:8px;padding:10px 12px;background:#f6f7f7;">
                    <strong><?php esc_html_e('Queued Jobs', 'ffl-hub'); ?></strong><br />
                    <span style="font-size:22px;font-weight:700;"><?php echo esc_html((string) ((int) ($makeup['total_jobs'] ?? 0))); ?></span>
                </div>
                <div style="border:1px solid #dcdcde;border-radius:8px;padding:10px 12px;background:#f6f7f7;">
                    <strong><?php esc_html_e('Queued Qty', 'ffl-hub'); ?></strong><br />
                    <span style="font-size:22px;font-weight:700;"><?php echo esc_html((string) ((int) ($makeup['total_qty'] ?? 0))); ?></span>
                </div>
                <div style="border:1px solid #dcdcde;border-radius:8px;padding:10px 12px;background:#f6f7f7;">
                    <strong><?php esc_html_e('Dealer Subtotal', 'ffl-hub'); ?></strong><br />
                    <span style="font-size:22px;font-weight:700;"><?php echo esc_html($this->format_money((float) ($makeup['total_subtotal'] ?? 0.0))); ?></span>
                </div>
                <div style="border:1px solid #dcdcde;border-radius:8px;padding:10px 12px;background:#f6f7f7;">
                    <strong><?php esc_html_e('Est. Paid Inbound', 'ffl-hub'); ?></strong><br />
                    <span style="font-size:22px;font-weight:700;"><?php echo esc_html($this->format_money((float) ($makeup['total_estimated_paid_shipping'] ?? 0.0))); ?></span>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin:8px 0 18px;">
                <?php foreach ($groups as $group) : ?>
                    <?php
                    $subtotal = (float) ($group['subtotal'] ?? 0.0);
                    $threshold = (float) ($group['threshold'] ?? 0.0);
                    $remaining = (float) ($group['remaining_to_free'] ?? 0.0);
                    $paid_shipping = (float) ($group['estimated_paid_shipping'] ?? 0.0);
                    $has_rows = (int) ($group['line_count'] ?? 0) > 0;
                    $is_free = $has_rows && $threshold > 0.0 && $subtotal >= $threshold;
                    ?>
                    <div style="border:1px solid <?php echo esc_attr($has_rows ? '#c3c4c7' : '#e5e7eb'); ?>;border-radius:8px;padding:10px 12px;background:<?php echo esc_attr($has_rows ? '#fff' : '#fafafa'); ?>;">
                        <strong><?php echo esc_html((string) ($group['label'] ?? $group['dist_id'] ?? '')); ?></strong>
                        <div style="font-size:20px;font-weight:700;margin-top:4px;"><?php echo esc_html($this->format_money($subtotal)); ?></div>
                        <div style="color:#50575e;">
                            <?php
                            echo esc_html(sprintf(
                                __('%d jobs, %d qty', 'ffl-hub'),
                                (int) ($group['job_count'] ?? 0),
                                (int) ($group['total_qty'] ?? 0)
                            ));
                            ?>
                        </div>
                        <div style="margin-top:6px;color:<?php echo esc_attr($is_free ? '#166534' : '#92400e'); ?>;">
                            <?php if (!$has_rows) : ?>
                                <?php esc_html_e('No pending rows', 'ffl-hub'); ?>
                            <?php elseif ($is_free) : ?>
                                <?php esc_html_e('Free inbound threshold met', 'ffl-hub'); ?>
                            <?php elseif ($threshold > 0.0) : ?>
                                <?php echo esc_html(sprintf(__('Needs %s for free inbound', 'ffl-hub'), $this->format_money($remaining))); ?>
                            <?php else : ?>
                                <?php echo esc_html(sprintf(__('Est. inbound: %s', 'ffl-hub'), $this->format_money($paid_shipping))); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ((int) ($makeup['total_lines'] ?? 0) <= 0) : ?>
                <p><?php esc_html_e('No current batch_pending dealer rows are waiting for optimizer/batch processing.', 'ffl-hub'); ?></p>
                <?php
                return;
            endif;
            ?>

            <?php foreach ($groups as $group) : ?>
                <?php $rows = (array) ($group['rows'] ?? []); ?>
                <?php if (empty($rows)) : ?>
                    <?php continue; ?>
                <?php endif; ?>
                <h3 style="margin-top:22px;">
                    <?php
                    echo esc_html(sprintf(
                        __('%s Items', 'ffl-hub'),
                        (string) ($group['label'] ?? $group['dist_id'] ?? '')
                    ));
                    ?>
                </h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Job', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Order', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('UPC', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Product', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('SKU', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Qty', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Unit Cost', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Ship', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Landed', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Line Total', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Offer Stock', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Latest Move', 'ffl-hub'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <?php
                            $order_id = (int) ($row['order_id'] ?? 0);
                            $order_url = $order_id > 0 ? admin_url('post.php?post=' . $order_id . '&action=edit') : '';
                            $product_id = (int) ($row['product_id'] ?? 0);
                            $product_url = $product_id > 0 ? admin_url('post.php?post=' . $product_id . '&action=edit') : '';
                            $move_note = trim((string) ($row['latest_move_note'] ?? ''));
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) ((int) ($row['job_id'] ?? 0))); ?></td>
                                <td>
                                    <?php if ($order_url !== '') : ?>
                                        <a href="<?php echo esc_url($order_url); ?>"><?php echo esc_html('#' . (string) $order_id); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html('-'); ?>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo esc_html((string) ($row['upc'] ?? '')); ?></code></td>
                                <td>
                                    <?php if ($product_url !== '') : ?>
                                        <a href="<?php echo esc_url($product_url); ?>"><?php echo esc_html((string) ($row['product_name'] ?? 'Unknown product')); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html((string) ($row['product_name'] ?? 'Unknown product')); ?>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo esc_html((string) (($row['distributor_sku'] ?? '') !== '' ? $row['distributor_sku'] : '-')); ?></code></td>
                                <td><?php echo esc_html((string) ((int) ($row['qty'] ?? 0))); ?></td>
                                <td><?php echo esc_html($this->format_money((float) ($row['unit_cost'] ?? 0.0))); ?></td>
                                <td><?php echo esc_html($this->format_optional_money($row['shipping_cost'] ?? null)); ?></td>
                                <td><?php echo esc_html($this->format_optional_money($row['landed_cost'] ?? null)); ?></td>
                                <td><?php echo esc_html($this->format_money((float) ($row['line_total'] ?? 0.0))); ?></td>
                                <td>
                                    <?php
                                    echo esc_html(sprintf(
                                        '%s / %d',
                                        (string) (($row['stock_status'] ?? '') !== '' ? $row['stock_status'] : '-'),
                                        (int) ($row['offer_qty'] ?? 0)
                                    ));
                                    ?>
                                </td>
                                <td><?php echo esc_html($move_note !== '' ? $move_note : '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        </section>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $runs
     */
    private function render_recent_runs(array $runs): void
    {
        ?>
        <h2><?php esc_html_e('Recent Optimizer Runs', 'ffl-hub'); ?></h2>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e('Started', 'ffl-hub'); ?></th><th><?php esc_html_e('Run ID', 'ffl-hub'); ?></th><th><?php esc_html_e('Status', 'ffl-hub'); ?></th><th><?php esc_html_e('Moves', 'ffl-hub'); ?></th><th><?php esc_html_e('Before', 'ffl-hub'); ?></th><th><?php esc_html_e('After', 'ffl-hub'); ?></th><th><?php esc_html_e('Message', 'ffl-hub'); ?></th></tr></thead>
            <tbody>
                <?php if (empty($runs)) : ?>
                    <tr><td colspan="7"><?php esc_html_e('No optimizer runs recorded yet.', 'ffl-hub'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($runs as $run) : ?>
                    <tr>
                        <td><?php echo esc_html((string) ($run['started_at'] ?? '')); ?></td>
                        <td><code><?php echo esc_html((string) ($run['run_id'] ?? '')); ?></code></td>
                        <td><?php echo esc_html((string) ($run['status'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ((int) ($run['moves_count'] ?? 0))); ?></td>
                        <td><code><?php echo esc_html($this->compact_json((string) ($run['before_json'] ?? ''))); ?></code></td>
                        <td><code><?php echo esc_html($this->compact_json((string) ($run['after_json'] ?? ''))); ?></code></td>
                        <td><?php echo esc_html((string) ($run['message'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $moves
     */
    private function render_recent_moves(array $moves): void
    {
        ?>
        <h2><?php esc_html_e('Recent Optimized Moves', 'ffl-hub'); ?></h2>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e('Time', 'ffl-hub'); ?></th><th><?php esc_html_e('Order', 'ffl-hub'); ?></th><th><?php esc_html_e('Job', 'ffl-hub'); ?></th><th><?php esc_html_e('UPC', 'ffl-hub'); ?></th><th><?php esc_html_e('Qty', 'ffl-hub'); ?></th><th><?php esc_html_e('From', 'ffl-hub'); ?></th><th><?php esc_html_e('To', 'ffl-hub'); ?></th><th><?php esc_html_e('Unit Cost', 'ffl-hub'); ?></th><th><?php esc_html_e('Reason', 'ffl-hub'); ?></th></tr></thead>
            <tbody>
                <?php if (empty($moves)) : ?>
                    <tr><td colspan="9"><?php esc_html_e('No optimized moves recorded yet.', 'ffl-hub'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($moves as $move) : ?>
                    <tr>
                        <td><?php echo esc_html((string) ($move['created_at'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ((int) ($move['order_id'] ?? 0))); ?></td>
                        <td><?php echo esc_html((string) ((int) ($move['job_id'] ?? 0))); ?></td>
                        <td><code><?php echo esc_html((string) ($move['upc'] ?? '')); ?></code></td>
                        <td><?php echo esc_html((string) ((int) ($move['quantity'] ?? 0))); ?></td>
                        <td><?php echo esc_html((string) ($move['source_dist_id'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($move['target_dist_id'] ?? '')); ?></td>
                        <td><?php echo esc_html('$' . number_format((float) ($move['unit_cost'] ?? 0.0), 2)); ?></td>
                        <td><?php echo esc_html((string) ($move['reason'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * @param array<string,mixed>|null $latest_run
     * @param array<int,array<string,mixed>> $latest_moves
     * @return array{
     *   latest_run:?array<string,mixed>,
     *   generated_at:string,
     *   total_jobs:int,
     *   total_lines:int,
     *   total_qty:int,
     *   total_subtotal:float,
     *   total_estimated_paid_shipping:float,
     *   groups:array<string,array<string,mixed>>
     * }
     */
    private function build_latest_optimizer_makeup_data(?array $latest_run, array $latest_moves): array
    {
        $dist_ids = DealerBatchOptimizerConfig::optimizer_distributor_ids();
        $groups = [];
        foreach ($dist_ids as $dist_id) {
            $dist_id = OrderPlacementKeysUtil::normalize_dist_id((string) $dist_id);
            if ($dist_id === '') {
                continue;
            }

            $groups[$dist_id] = [
                'dist_id' => $dist_id,
                'label' => $this->distributor_label($dist_id),
                'job_ids' => [],
                'job_count' => 0,
                'line_count' => 0,
                'total_qty' => 0,
                'subtotal' => 0.0,
                'threshold' => DealerBatchOptimizerConfig::free_shipping_threshold($dist_id),
                'estimated_paid_shipping' => 0.0,
                'remaining_to_free' => 0.0,
                'rows' => [],
            ];
        }

        $jobs = $this->load_current_dealer_batch_makeup_jobs($dist_ids);
        $line_rows = [];
        $upcs = [];

        foreach ($jobs as $job) {
            if (!($job instanceof OrderPlacementJobRow)) {
                continue;
            }

            $dist_id = OrderPlacementKeysUtil::normalize_dist_id((string) $job->dist_id_norm());
            if ($dist_id === '' || !isset($groups[$dist_id])) {
                continue;
            }

            foreach ($job->payload_lines() as $line) {
                $upc = trim((string) $line->upc);
                if ($upc === '') {
                    continue;
                }

                $upcs[$upc] = $upc;
                $line_rows[] = [
                    'job' => $job,
                    'dist_id' => $dist_id,
                    'upc' => $upc,
                    'qty' => max(1, (int) $line->quantity),
                ];
            }
        }

        $offer_rows = $this->load_offer_rows_for_makeup(array_keys($groups), array_values($upcs));
        $products = $this->load_product_names_for_makeup(array_values($upcs));
        $move_lookup = $this->latest_move_lookup($latest_moves);

        $total_job_ids = [];
        $total_lines = 0;
        $total_qty = 0;
        $total_subtotal = 0.0;

        foreach ($line_rows as $line_row) {
            $job = $line_row['job'];
            if (!($job instanceof OrderPlacementJobRow)) {
                continue;
            }

            $dist_id = (string) ($line_row['dist_id'] ?? '');
            $upc = (string) ($line_row['upc'] ?? '');
            $qty = max(1, (int) ($line_row['qty'] ?? 0));
            if ($dist_id === '' || $upc === '' || !isset($groups[$dist_id])) {
                continue;
            }

            $offer_key = $dist_id . '|' . $upc;
            $offer = (array) ($offer_rows[$offer_key] ?? []);
            $unit_cost = $this->money_float($offer['dealer_price'] ?? 0.0);
            $shipping_cost = $this->optional_money_float($offer['shipping_cost'] ?? null);
            $landed_cost = $this->optional_money_float($offer['landed_cost'] ?? null);
            $line_total = $this->money_float($unit_cost * (float) $qty);
            $product = (array) ($products[$upc] ?? []);
            $move = (array) ($move_lookup[((int) $job->id) . '|' . $upc] ?? []);
            $move_note = '';
            if (!empty($move)) {
                $move_note = sprintf(
                    '%s -> %s (%s)',
                    (string) ($move['source_dist_id'] ?? ''),
                    (string) ($move['target_dist_id'] ?? ''),
                    (string) ($move['reason'] ?? '')
                );
            }

            $groups[$dist_id]['job_ids'][(int) $job->id] = true;
            $groups[$dist_id]['line_count'] = (int) $groups[$dist_id]['line_count'] + 1;
            $groups[$dist_id]['total_qty'] = (int) $groups[$dist_id]['total_qty'] + $qty;
            $groups[$dist_id]['subtotal'] = $this->money_float((float) $groups[$dist_id]['subtotal'] + $line_total);
            $groups[$dist_id]['rows'][] = [
                'job_id' => (int) $job->id,
                'order_id' => (int) $job->order_id,
                'upc' => $upc,
                'product_id' => (int) ($product['product_id'] ?? 0),
                'product_name' => (string) ($product['product_name'] ?? __('Unknown product', 'ffl-hub')),
                'distributor_sku' => (string) ($offer['distributor_sku'] ?? ''),
                'qty' => $qty,
                'unit_cost' => $unit_cost,
                'shipping_cost' => $shipping_cost,
                'landed_cost' => $landed_cost,
                'line_total' => $line_total,
                'offer_qty' => (int) ($offer['qty'] ?? 0),
                'stock_status' => (string) ($offer['stock_status'] ?? ''),
                'latest_move_note' => $move_note,
            ];

            $total_job_ids[(int) $job->id] = true;
            $total_lines++;
            $total_qty += $qty;
            $total_subtotal = $this->money_float($total_subtotal + $line_total);
        }

        $total_estimated_paid_shipping = 0.0;
        foreach ($groups as $dist_id => &$group) {
            $subtotal = (float) ($group['subtotal'] ?? 0.0);
            $threshold = (float) ($group['threshold'] ?? 0.0);
            $paid_shipping = $this->estimated_paid_shipping_for_makeup((string) $dist_id, $subtotal);
            $group['job_count'] = count((array) ($group['job_ids'] ?? []));
            $group['estimated_paid_shipping'] = $paid_shipping;
            $group['remaining_to_free'] = ($threshold > 0.0 && $subtotal > 0.0 && $subtotal < $threshold)
                ? $this->money_float($threshold - $subtotal)
                : 0.0;
            unset($group['job_ids']);
            $total_estimated_paid_shipping = $this->money_float($total_estimated_paid_shipping + $paid_shipping);
        }
        unset($group);

        return [
            'latest_run' => $latest_run,
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'total_jobs' => count($total_job_ids),
            'total_lines' => $total_lines,
            'total_qty' => $total_qty,
            'total_subtotal' => $total_subtotal,
            'total_estimated_paid_shipping' => $total_estimated_paid_shipping,
            'groups' => $groups,
        ];
    }

    /**
     * @param string[] $dist_ids
     * @return OrderPlacementJobRow[]
     */
    private function load_current_dealer_batch_makeup_jobs(array $dist_ids): array
    {
        global $wpdb;

        $table = (string) $this->jobs_table->get_table_name();
        $dist_ids = array_values(array_filter(array_map(
            static fn($dist_id): string => OrderPlacementKeysUtil::normalize_dist_id((string) $dist_id),
            $dist_ids
        )));
        if ($table === '' || empty($dist_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($dist_ids), '%s'));
        $args = array_merge(
            $dist_ids,
            [
                OrderPlacementKeysUtil::LANE_DEALER_FULFILLED,
                OrderPlacementKeys::JOB_STATUS_BATCH_PENDING,
                1000,
            ]
        );

        $sql = $wpdb->prepare(
            "
            SELECT
                id, order_id, job_key, dist_id, lane, status,
                attempts, created_at, updated_at,
                action_id, next_run_at,
                last_step, last_error, last_codes_json,
                done_at,
                payload_json, validate_result_json, place_result_json,
                merchant_po, external_order_ids_json, external_order_id,
                shipped_at, tracking_numbers_json, invoice_numbers_json,
                last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json
            FROM {$table}
            WHERE
                dist_id IN ({$placeholders})
                AND lane = %s
                AND status = %s
                AND (merchant_po IS NULL OR merchant_po = '')
                AND (external_order_id IS NULL OR external_order_id = '')
                AND (external_order_ids_json IS NULL OR external_order_ids_json = '' OR external_order_ids_json = '[]')
            ORDER BY dist_id ASC, created_at ASC, id ASC
            LIMIT %d
            ",
            ...$args
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $jobs = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $jobs[] = new OrderPlacementJobRow($row);
            }
        }

        return $jobs;
    }

    /**
     * @param string[] $dist_ids
     * @param string[] $upcs
     * @return array<string,array<string,mixed>>
     */
    private function load_offer_rows_for_makeup(array $dist_ids, array $upcs): array
    {
        global $wpdb;

        $dist_ids = array_values(array_filter(array_map(
            static fn($dist_id): string => OrderPlacementKeysUtil::normalize_dist_id((string) $dist_id),
            $dist_ids
        )));
        $upcs = array_values(array_filter(array_map('strval', $upcs), static fn(string $upc): bool => trim($upc) !== ''));
        if (empty($dist_ids) || empty($upcs)) {
            return [];
        }

        DistributorOffersStore::ensure_schema();
        $table = DistributorOffersStore::table_name();
        $dist_placeholders = implode(',', array_fill(0, count($dist_ids), '%s'));
        $upc_placeholders = implode(',', array_fill(0, count($upcs), '%s'));
        $args = array_merge($dist_ids, $upcs);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    distributor_id,
                    upc,
                    distributor_product_id,
                    distributor_sku,
                    qty,
                    stock_status,
                    dealer_price,
                    shipping_cost,
                    landed_cost
                FROM {$table}
                WHERE distributor_id IN ({$dist_placeholders})
                  AND upc IN ({$upc_placeholders})
                ",
                ...$args
            ),
            ARRAY_A
        );
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $dist_id = OrderPlacementKeysUtil::normalize_dist_id((string) ($row['distributor_id'] ?? ''));
            $upc = trim((string) ($row['upc'] ?? ''));
            if ($dist_id === '' || $upc === '') {
                continue;
            }

            $out[$dist_id . '|' . $upc] = $row;
        }

        return $out;
    }

    /**
     * @param string[] $upcs
     * @return array<string,array{product_id:int,product_name:string}>
     */
    private function load_product_names_for_makeup(array $upcs): array
    {
        global $wpdb;

        $upcs = array_values(array_filter(array_map('strval', $upcs), static fn(string $upc): bool => trim($upc) !== ''));
        if (empty($upcs)) {
            return [];
        }

        ProductStateStore::ensure_schema();
        $table = ProductStateStore::table_name();
        $placeholders = implode(',', array_fill(0, count($upcs), '%s'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT upc, product_id FROM {$table} WHERE upc IN ({$placeholders})",
                ...$upcs
            ),
            ARRAY_A
        );
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $upc = trim((string) ($row['upc'] ?? ''));
            $product_id = (int) ($row['product_id'] ?? 0);
            if ($upc === '' || $product_id <= 0 || isset($out[$upc])) {
                continue;
            }

            $name = '';
            if (function_exists('wc_get_product')) {
                $product = wc_get_product($product_id);
                if ($product && method_exists($product, 'get_name')) {
                    $name = trim((string) $product->get_name());
                }
            }

            $out[$upc] = [
                'product_id' => $product_id,
                'product_name' => $name !== '' ? $name : __('Unknown product', 'ffl-hub'),
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $moves
     * @return array<string,array<string,mixed>>
     */
    private function latest_move_lookup(array $moves): array
    {
        $lookup = [];
        foreach ($moves as $move) {
            if (!is_array($move)) {
                continue;
            }

            $job_id = (int) ($move['job_id'] ?? 0);
            $upc = trim((string) ($move['upc'] ?? ''));
            if ($job_id <= 0 || $upc === '') {
                continue;
            }

            $lookup[$job_id . '|' . $upc] = $move;
        }

        return $lookup;
    }

    private function estimated_paid_shipping_for_makeup(string $dist_id, float $subtotal): float
    {
        $subtotal = $this->money_float($subtotal);
        if ($subtotal <= 0.0) {
            return 0.0;
        }

        $threshold = DealerBatchOptimizerConfig::free_shipping_threshold($dist_id);
        if ($threshold > 0.0 && $subtotal >= $threshold) {
            return 0.0;
        }

        return $this->money_float(DealerBatchOptimizerConfig::shipping_penalty($dist_id));
    }

    private function distributor_label(string $dist_id): string
    {
        $dist_id = OrderPlacementKeysUtil::normalize_dist_id($dist_id);
        if ($dist_id === '') {
            return '';
        }

        $distributor = $this->handler->get_distributor_by_id($dist_id);
        if ($distributor && method_exists($distributor, 'get_label')) {
            $label = trim((string) $distributor->get_label());
            if ($label !== '') {
                return $label . ' (' . $dist_id . ')';
            }
        }

        return ucwords(str_replace('_', ' ', $dist_id)) . ' (' . $dist_id . ')';
    }

    private function money_float($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round((float) $value, 2);
    }

    private function optional_money_float($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    private function format_money(float $value): string
    {
        return '$' . number_format($value, 2);
    }

    private function format_optional_money($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return $this->format_money((float) $value);
    }

    private function schedule_all_dealer_batch_crons(): void
    {
        foreach (DealerBatchCronRegistry::hooks_by_distributor() as $hook) {
            $hook = trim((string) $hook);
            if ($hook === '') {
                continue;
            }
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + 1, $hook, [], 'fflhub_place');
            } elseif (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time() + 1, $hook);
            } else {
                do_action($hook);
            }
        }
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

    private function checkbox_post(string $field): string
    {
        return isset($_POST[$field]) && (string) wp_unslash($_POST[$field]) === '1' ? '1' : '0';
    }

    private function text_post(string $field, string $default = ''): string
    {
        return isset($_POST[$field])
            ? sanitize_text_field(wp_unslash((string) $_POST[$field]))
            : $default;
    }

    private function money_post(string $field): string
    {
        $raw = $this->text_post($field, '0');
        return (string) DealerBatchOptimizerConfig::non_negative_float($raw);
    }

    private function sanitize_dispatch_time(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^([0-1]?\d|2[0-3]):([0-5]\d)$/', $value, $m)) {
            return DealerBatchOptimizerConfig::DEFAULT_DISPATCH_TIME;
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    private function compact_json(string $json): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return '';
        }

        $encoded = wp_json_encode($decoded);
        return is_string($encoded) ? $encoded : '';
    }
}
