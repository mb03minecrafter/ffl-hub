<?php

declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Product\State\ProductStateStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only operations page for failed FFL Hub order placement rows.
 */
final class FailedPlaceOrderJobsPage
{
    private const PAGE_SLUG = 'fflhub-failed-place-order-jobs';
    private const DEFAULT_LIMIT = 200;
    private const MAX_LIMIT = 1000;

    private OrderPlacementJobsTable $jobs_table;

    /** @var array<string,string> */
    private array $product_name_cache_by_upc = [];

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
            DistributorOrderingAdminPage::MENU_SLUG,
            __('Failed Place Order Jobs', 'ffl-hub'),
            __('Failed Place Order Jobs', 'ffl-hub'),
            DistributorOrderingAdminPage::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        DistributorOrderingAdminPage::ensure_access();

        $filters = $this->read_filters();
        $jobs = OrderPlacementJobsRepository::find_failed_jobs(
            $this->jobs_table,
            (int) $filters['limit'],
            (string) $filters['dist_id'],
            (string) $filters['lane'],
            (string) $filters['last_step'],
            (int) $filters['order_id']
        );
        $jobs = $this->filter_by_ffl_requirement($jobs, (string) $filters['ffl_required']);
        $summary = $this->summary($jobs);
        ?>
        <div class="wrap fflhub-failed-place-jobs">
            <?php $this->render_styles(); ?>
            <h1><?php esc_html_e('Failed Place Order Jobs', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Read-only triage view for FFL Hub order placement job rows with status failed.', 'ffl-hub'); ?>
            </p>

            <?php $this->render_filters($filters); ?>
            <?php $this->render_summary($summary); ?>
            <?php $this->render_jobs_table($jobs); ?>
        </div>
        <?php
    }

    /**
     * @return array{dist_id:string,lane:string,last_step:string,ffl_required:string,order_id:int,limit:int}
     */
    private function read_filters(): array
    {
        $dist_id = isset($_GET['dist_id'])
            ? sanitize_key(wp_unslash((string) $_GET['dist_id']))
            : '';
        $lane = isset($_GET['lane'])
            ? sanitize_key(wp_unslash((string) $_GET['lane']))
            : '';
        $last_step = isset($_GET['last_step'])
            ? sanitize_key(wp_unslash((string) $_GET['last_step']))
            : '';
        $ffl_required = isset($_GET['ffl_required'])
            ? sanitize_key(wp_unslash((string) $_GET['ffl_required']))
            : '';
        $order_id = isset($_GET['order_id'])
            ? absint($_GET['order_id'])
            : 0;
        $limit = isset($_GET['limit'])
            ? absint($_GET['limit'])
            : self::DEFAULT_LIMIT;

        $lane = OrderPlacementKeysUtil::is_valid_lane($lane) ? $lane : '';
        $last_step = in_array($last_step, ['validate', 'place', 'shipping'], true) ? $last_step : '';
        $ffl_required = in_array($ffl_required, ['yes', 'no'], true) ? $ffl_required : '';
        $limit = min(self::MAX_LIMIT, max(1, $limit));

        return [
            'dist_id' => $dist_id,
            'lane' => $lane,
            'last_step' => $last_step,
            'ffl_required' => $ffl_required,
            'order_id' => $order_id,
            'limit' => $limit,
        ];
    }

    /**
     * @param array{dist_id:string,lane:string,last_step:string,ffl_required:string,order_id:int,limit:int} $filters
     */
    private function render_filters(array $filters): void
    {
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="fflhub-failed-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
            <label>
                <?php esc_html_e('Distributor', 'ffl-hub'); ?>
                <input type="text" name="dist_id" value="<?php echo esc_attr((string) $filters['dist_id']); ?>" placeholder="sports_south" />
            </label>
            <label>
                <?php esc_html_e('Lane', 'ffl-hub'); ?>
                <select name="lane">
                    <option value=""><?php esc_html_e('All lanes', 'ffl-hub'); ?></option>
                    <?php foreach ($this->lane_options() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected((string) $filters['lane'], $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <?php esc_html_e('Last Step', 'ffl-hub'); ?>
                <select name="last_step">
                    <option value=""><?php esc_html_e('All steps', 'ffl-hub'); ?></option>
                    <?php foreach (['validate', 'place', 'shipping'] as $step) : ?>
                        <option value="<?php echo esc_attr($step); ?>" <?php selected((string) $filters['last_step'], $step); ?>>
                            <?php echo esc_html($step); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <?php esc_html_e('FFL Required', 'ffl-hub'); ?>
                <select name="ffl_required">
                    <option value=""><?php esc_html_e('All', 'ffl-hub'); ?></option>
                    <option value="yes" <?php selected((string) $filters['ffl_required'], 'yes'); ?>><?php esc_html_e('Yes', 'ffl-hub'); ?></option>
                    <option value="no" <?php selected((string) $filters['ffl_required'], 'no'); ?>><?php esc_html_e('No', 'ffl-hub'); ?></option>
                </select>
            </label>
            <label>
                <?php esc_html_e('Order ID', 'ffl-hub'); ?>
                <input type="number" min="0" step="1" name="order_id" value="<?php echo esc_attr((string) ((int) $filters['order_id'])); ?>" />
            </label>
            <label>
                <?php esc_html_e('Limit', 'ffl-hub'); ?>
                <input type="number" min="1" max="<?php echo esc_attr((string) self::MAX_LIMIT); ?>" step="1" name="limit" value="<?php echo esc_attr((string) ((int) $filters['limit'])); ?>" />
            </label>
            <div class="fflhub-failed-filter-actions">
                <?php submit_button(__('Apply Filters', 'ffl-hub'), 'secondary', '', false); ?>
                <a class="button button-link" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">
                    <?php esc_html_e('Reset', 'ffl-hub'); ?>
                </a>
            </div>
        </form>
        <?php
    }

    /**
     * @return array<string,string>
     */
    private function lane_options(): array
    {
        return [
            OrderPlacementKeysUtil::LANE_DIRECT_SHIP_NON_FFL => __('Direct Ship Non-FFL', 'ffl-hub'),
            OrderPlacementKeysUtil::LANE_DIRECT_SHIP_FFL => __('Direct Ship FFL', 'ffl-hub'),
            OrderPlacementKeysUtil::LANE_DEALER_FULFILLED => __('Dealer Fulfilled', 'ffl-hub'),
        ];
    }

    /**
     * @param OrderPlacementJobRow[] $jobs
     * @return OrderPlacementJobRow[]
     */
    private function filter_by_ffl_requirement(array $jobs, string $mode): array
    {
        if ($mode === '') {
            return $jobs;
        }

        $want = $mode === 'yes';
        return array_values(array_filter($jobs, static function (OrderPlacementJobRow $job) use ($want): bool {
            return $job->ffl_required() === $want;
        }));
    }

    /**
     * @param OrderPlacementJobRow[] $jobs
     * @return array{rows:int,orders:int,ffl_rows:int,distributors:int}
     */
    private function summary(array $jobs): array
    {
        $orders = [];
        $distributors = [];
        $ffl_rows = 0;

        foreach ($jobs as $job) {
            if (!($job instanceof OrderPlacementJobRow)) {
                continue;
            }

            if ((int) $job->order_id > 0) {
                $orders[(int) $job->order_id] = true;
            }

            $dist_id = $job->dist_id_norm();
            if ($dist_id !== '') {
                $distributors[$dist_id] = true;
            }

            if ($job->ffl_required()) {
                $ffl_rows++;
            }
        }

        return [
            'rows' => count($jobs),
            'orders' => count($orders),
            'ffl_rows' => $ffl_rows,
            'distributors' => count($distributors),
        ];
    }

    /**
     * @param array{rows:int,orders:int,ffl_rows:int,distributors:int} $summary
     */
    private function render_summary(array $summary): void
    {
        ?>
        <div class="fflhub-failed-summary">
            <section>
                <span><?php esc_html_e('Failed Rows', 'ffl-hub'); ?></span>
                <strong><?php echo esc_html((string) ((int) $summary['rows'])); ?></strong>
            </section>
            <section>
                <span><?php esc_html_e('Orders', 'ffl-hub'); ?></span>
                <strong><?php echo esc_html((string) ((int) $summary['orders'])); ?></strong>
            </section>
            <section>
                <span><?php esc_html_e('FFL Rows', 'ffl-hub'); ?></span>
                <strong><?php echo esc_html((string) ((int) $summary['ffl_rows'])); ?></strong>
            </section>
            <section>
                <span><?php esc_html_e('Distributors', 'ffl-hub'); ?></span>
                <strong><?php echo esc_html((string) ((int) $summary['distributors'])); ?></strong>
            </section>
        </div>
        <?php
    }

    /**
     * @param OrderPlacementJobRow[] $jobs
     */
    private function render_jobs_table(array $jobs): void
    {
        if (empty($jobs)) {
            ?>
            <div class="notice notice-success">
                <p><?php esc_html_e('No failed place order jobs matched those filters.', 'ffl-hub'); ?></p>
            </div>
            <?php
            return;
        }
        ?>
        <table class="widefat striped fflhub-failed-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Job', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Order', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Distributor / Lane', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Lines', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Failure', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Timing', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Refs', 'ffl-hub'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $job) : ?>
                    <?php $this->render_job_row($job); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_job_row(OrderPlacementJobRow $job): void
    {
        $order = ((int) $job->order_id > 0) ? wc_get_order((int) $job->order_id) : false;
        $order_status = ($order && method_exists($order, 'get_status')) ? (string) $order->get_status() : '';
        $snapshot = $this->primary_snapshot($job);
        $message = $this->failure_message($job, $snapshot);
        $codes = $this->failure_codes($job, $snapshot);
        ?>
        <tr>
            <td>
                <strong>#<?php echo esc_html((string) ((int) $job->id)); ?></strong><br />
                <code><?php echo esc_html((string) $job->job_key_norm()); ?></code><br />
                <span class="fflhub-muted"><?php echo esc_html((string) $job->status); ?></span>
            </td>
            <td>
                <?php if ((int) $job->order_id > 0) : ?>
                    <a href="<?php echo esc_url($this->order_edit_url((int) $job->order_id)); ?>">
                        <?php echo esc_html('#' . (string) ((int) $job->order_id)); ?>
                    </a>
                <?php else : ?>
                    <?php echo esc_html('-'); ?>
                <?php endif; ?>
                <?php if ($order_status !== '') : ?>
                    <br /><span class="fflhub-status-pill"><?php echo esc_html($order_status); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <strong><?php echo esc_html((string) $job->dist_id_norm()); ?></strong><br />
                <span><?php echo esc_html($this->lane_label((string) $job->lane_norm())); ?></span><br />
                <?php if ($job->ffl_required()) : ?>
                    <span class="fflhub-flag is-ffl"><?php esc_html_e('FFL', 'ffl-hub'); ?></span>
                <?php else : ?>
                    <span class="fflhub-flag"><?php esc_html_e('Non-FFL', 'ffl-hub'); ?></span>
                <?php endif; ?>
            </td>
            <td><?php echo wp_kses_post($this->line_list_html($job)); ?></td>
            <td>
                <div class="fflhub-failure-step">
                    <?php esc_html_e('Step:', 'ffl-hub'); ?>
                    <strong><?php echo esc_html((string) ($job->last_step ?: '-')); ?></strong>
                    <span class="fflhub-muted">
                        <?php echo esc_html(sprintf(__('Attempts: %d', 'ffl-hub'), (int) $job->attempts)); ?>
                    </span>
                </div>
                <?php if (!empty($codes)) : ?>
                    <div class="fflhub-code-list">
                        <?php foreach ($codes as $code) : ?>
                            <code><?php echo esc_html((string) $code); ?></code>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="fflhub-error-text"><?php echo esc_html($message !== '' ? $message : '-'); ?></div>
                <?php $this->render_snapshot_details($snapshot); ?>
            </td>
            <td>
                <div>
                    <span class="fflhub-muted"><?php esc_html_e('Updated', 'ffl-hub'); ?></span><br />
                    <?php echo esc_html($this->format_mysql_utc((string) ($job->updated_at ?? ''))); ?>
                </div>
                <div class="fflhub-timing-extra">
                    <span class="fflhub-muted"><?php esc_html_e('Next', 'ffl-hub'); ?></span><br />
                    <?php echo esc_html($this->format_mysql_utc((string) ($job->next_run_at ?? ''))); ?>
                </div>
            </td>
            <td>
                <div><span class="fflhub-muted"><?php esc_html_e('PO', 'ffl-hub'); ?></span> <code><?php echo esc_html($job->merchant_po_or_empty() ?: '-'); ?></code></div>
                <div><span class="fflhub-muted"><?php esc_html_e('External', 'ffl-hub'); ?></span> <?php echo wp_kses_post($this->external_ids_html($job)); ?></div>
            </td>
        </tr>
        <?php
    }

    private function order_edit_url(int $order_id): string
    {
        return admin_url('post.php?post=' . (int) $order_id . '&action=edit');
    }

    private function lane_label(string $lane): string
    {
        $options = $this->lane_options();
        return (string) ($options[$lane] ?? $lane);
    }

    private function line_list_html(OrderPlacementJobRow $job): string
    {
        $lines = $job->payload_lines();
        if (empty($lines)) {
            return '<span class="fflhub-muted">No payload lines</span>';
        }

        $out = ['<ul class="fflhub-line-list">'];
        foreach ($lines as $line) {
            if (!($line instanceof DistributorOrderLine)) {
                continue;
            }

            $upc = trim((string) $line->upc);
            $name = $this->product_name_for_upc($upc);
            $flag = $line->ffl_required ? ' <span class="fflhub-mini-ffl">FFL</span>' : '';
            $out[] = sprintf(
                '<li><code>%s</code> x %d%s<br /><span>%s</span></li>',
                esc_html($upc),
                (int) $line->quantity,
                $flag,
                esc_html($name !== '' ? $name : 'Unknown product')
            );
        }
        $out[] = '</ul>';

        return implode('', $out);
    }

    private function product_name_for_upc(string $upc): string
    {
        $upc = trim($upc);
        if ($upc === '') {
            return '';
        }

        if (array_key_exists($upc, $this->product_name_cache_by_upc)) {
            return $this->product_name_cache_by_upc[$upc];
        }

        $name = '';
        $row = ProductStateStore::get_row_for_upc($upc);
        $product_id = is_array($row) ? (int) ($row['product_id'] ?? 0) : 0;
        if ($product_id > 0) {
            $product = wc_get_product($product_id);
            if ($product && method_exists($product, 'get_name')) {
                $name = trim((string) $product->get_name());
            }
        }

        $this->product_name_cache_by_upc[$upc] = $name;
        return $name;
    }

    /**
     * @return array<string,mixed>
     */
    private function primary_snapshot(OrderPlacementJobRow $job): array
    {
        $place = $job->place_snapshot();
        if (is_array($place) && !empty($place)) {
            return $place;
        }

        $validate = $job->validate_snapshot();
        return is_array($validate) ? $validate : [];
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function failure_message(OrderPlacementJobRow $job, array $snapshot): string
    {
        $message = trim((string) ($snapshot['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        return trim((string) ($job->last_error ?? ''));
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return string[]
     */
    private function failure_codes(OrderPlacementJobRow $job, array $snapshot): array
    {
        $codes = $job->last_codes();
        if (!empty($codes)) {
            return $codes;
        }

        $snapshot_codes = $snapshot['codes'] ?? [];
        if (!is_array($snapshot_codes)) {
            return [];
        }

        $out = [];
        foreach ($snapshot_codes as $code) {
            $code = trim((string) $code);
            if ($code !== '') {
                $out[] = $code;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function render_snapshot_details(array $snapshot): void
    {
        if (empty($snapshot)) {
            return;
        }

        $json = wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || trim($json) === '') {
            return;
        }
        ?>
        <details class="fflhub-snapshot-details">
            <summary><?php esc_html_e('Snapshot JSON', 'ffl-hub'); ?></summary>
            <pre><?php echo esc_html($json); ?></pre>
        </details>
        <?php
    }

    private function external_ids_html(OrderPlacementJobRow $job): string
    {
        $ids = $job->external_order_ids();
        if (empty($ids)) {
            return '<code>-</code>';
        }

        $out = [];
        foreach ($ids as $id) {
            $out[] = '<code>' . esc_html((string) $id) . '</code>';
        }

        return implode(' ', $out);
    }

    private function format_mysql_utc(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return '-';
        }

        $timestamp = strtotime($value . ' UTC');
        if (!$timestamp) {
            return $value;
        }

        return wp_date('M j, Y g:i a T', $timestamp);
    }

    private function render_styles(): void
    {
        ?>
        <style>
            .fflhub-failed-place-jobs{max-width:1800px}
            .fflhub-failed-filters{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px;margin:14px 0}
            .fflhub-failed-filters label{display:flex;flex-direction:column;gap:4px;font-weight:600;color:#1d2327}
            .fflhub-failed-filters input,.fflhub-failed-filters select{min-width:145px}
            .fflhub-failed-filter-actions{display:flex;align-items:center;gap:8px}
            .fflhub-failed-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin:14px 0}
            .fflhub-failed-summary section{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px}
            .fflhub-failed-summary span{display:block;color:#646970;font-size:12px;text-transform:uppercase;font-weight:700;letter-spacing:.04em}
            .fflhub-failed-summary strong{display:block;font-size:26px;line-height:1.2;margin-top:3px}
            .fflhub-failed-table th{font-weight:700}
            .fflhub-failed-table td{vertical-align:top}
            .fflhub-failed-table code{word-break:break-all}
            .fflhub-muted{color:#646970;font-size:12px}
            .fflhub-status-pill,.fflhub-flag,.fflhub-mini-ffl{display:inline-block;border-radius:999px;padding:2px 7px;font-size:11px;font-weight:700;background:#f0f0f1;color:#1d2327}
            .fflhub-flag.is-ffl,.fflhub-mini-ffl{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
            .fflhub-code-list{display:flex;gap:4px;flex-wrap:wrap;margin:6px 0}
            .fflhub-code-list code{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;border-radius:4px;padding:2px 5px}
            .fflhub-error-text{max-width:520px;white-space:pre-wrap}
            .fflhub-line-list{margin:0;padding-left:16px}
            .fflhub-line-list li{margin-bottom:7px}
            .fflhub-snapshot-details{margin-top:8px}
            .fflhub-snapshot-details summary{cursor:pointer;color:#135e96;font-weight:600}
            .fflhub-snapshot-details pre{max-width:720px;max-height:320px;overflow:auto;background:#111827;color:#f9fafb;border-radius:6px;padding:10px;font-size:11px;line-height:1.35}
            .fflhub-timing-extra{margin-top:8px}
            .fflhub-failure-step{display:flex;gap:6px;align-items:baseline;flex-wrap:wrap}
        </style>
        <?php
    }
}
