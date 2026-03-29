<?php

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin page for viewing dealer shipment tracker rows (dealer-fulfilled lane).
 */
final class DealerFulfilledJobsPage
{
    private const PAGE_SLUG = 'fflhub-dealer-fulfilled-jobs';
    private const DEFAULT_LIMIT = 200;
    private const MAX_LIMIT = 1000;

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
            AdminPage::get_page_slug(),
            __('Dealer Shipment Tracker', 'ffl-hub'),
            __('Dealer Shipment Tracker', 'ffl-hub'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        $filters = $this->read_filters();
        $status_filter = ($filters['status'] !== '') ? $filters['status'] : null;

        $jobs = OrderPlacementJobsRepository::find_jobs_by_lane(
            $this->jobs_table,
            OrderPlacementKeysUtil::LANE_DEALER_FULFILLED,
            (int) $filters['limit'],
            $status_filter
        );

        // Tracker should show only rows that actually have tracking numbers.
        $jobs = array_values(array_filter(
            $jobs,
            static fn($job): bool => ($job instanceof OrderPlacementJobRow) && $job->has_tracking()
        ));
?>
        <div class="wrap fflhub-dealer-shipment-tracker">
            <?php $this->render_tracker_styles(); ?>
            <h1><?php esc_html_e('Dealer Shipment Tracker', 'ffl-hub'); ?></h1>
            <p>
                <?php esc_html_e(
                    'Operational view of dealer-fulfilled shipment job rows that already have tracking numbers.',
                    'ffl-hub'
                ); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Important:', 'ffl-hub'); ?></strong>
                <?php esc_html_e('Zanders shipments must be checked manually at', 'ffl-hub'); ?>
                <a href="https://shop2.gzanders.com/" target="_blank" rel="noopener noreferrer">https://shop2.gzanders.com/</a>
            </p>

            <?php $this->render_filters_form($filters); ?>
            <?php $this->render_jobs_table($jobs); ?>
        </div>
<?php
    }

    /**
     * @return array{status:string,limit:int}
     */
    private function read_filters(): array
    {
        $status = isset($_GET['status'])
            ? sanitize_text_field(wp_unslash((string) $_GET['status']))
            : '';
        $status = strtolower(trim($status));

        $valid_statuses = self::status_options();
        if ($status !== '' && !array_key_exists($status, $valid_statuses)) {
            $status = '';
        }

        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : self::DEFAULT_LIMIT;
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        $limit = min($limit, self::MAX_LIMIT);

        return [
            'status' => $status,
            'limit'  => $limit,
        ];
    }

    /**
     * @param array{status:string,limit:int} $filters
     */
    private function render_filters_form(array $filters): void
    {
        $status_options = self::status_options();
?>
        <form method="get" action="">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="fflhub_df_jobs_status"><?php esc_html_e('Status', 'ffl-hub'); ?></label>
                        </th>
                        <td>
                            <select name="status" id="fflhub_df_jobs_status">
                                <option value=""><?php esc_html_e('All statuses', 'ffl-hub'); ?></option>
                                <?php foreach ($status_options as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['status'], $value); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fflhub_df_jobs_limit"><?php esc_html_e('Max Rows', 'ffl-hub'); ?></label>
                        </th>
                        <td>
                            <input
                                type="number"
                                min="1"
                                max="<?php echo esc_attr((string) self::MAX_LIMIT); ?>"
                                step="1"
                                class="small-text"
                                id="fflhub_df_jobs_limit"
                                name="limit"
                                value="<?php echo esc_attr((string) $filters['limit']); ?>" />
                            <p class="description">
                                <?php
                                printf(
                                    esc_html__('Showing most recent rows first. Maximum %d.', 'ffl-hub'),
                                    (int) self::MAX_LIMIT
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php submit_button(__('Apply Filters', 'ffl-hub'), 'secondary', '', false); ?>
        </form>
<?php
    }

    /**
     * @param OrderPlacementJobRow[] $jobs
     */
    private function render_jobs_table(array $jobs): void
    {
        if (empty($jobs)) {
?>
            <p><?php esc_html_e('No dealer shipment rows with tracking were found for the selected filters.', 'ffl-hub'); ?></p>
<?php
            return;
        }
?>
        <p>
            <?php
            printf(
                esc_html__('Found %d dealer shipment rows with tracking.', 'ffl-hub'),
                count($jobs)
            );
            ?>
        </p>
        <div class="fflhub-dst-grid">
            <?php foreach ($jobs as $job) : ?>
                <?php
                if (!($job instanceof OrderPlacementJobRow)) {
                    continue;
                }

                $order_edit_url     = admin_url('post.php?post=' . (int) $job->order_id . '&action=edit');
                $merchant_po        = trim((string) ($job->merchant_po ?? ''));
                $external_order_id  = trim((string) ($job->external_order_id ?? ''));
                $last_step          = trim((string) ($job->last_step ?? ''));
                $updated_at         = trim((string) ($job->updated_at ?? ''));
                $tracking_numbers   = $job->tracking_numbers();
                $shipping_service   = trim((string) ($job->shipping_service ?? ''));
                $last_error         = trim((string) ($job->last_error ?? ''));
                if ($last_error !== '') {
                    $last_error = wp_html_excerpt($last_error, 220, '...');
                }

                $status_lc = strtolower(trim((string) $job->status));
                $status_class = 'fflhub-dst-status-neutral';
                if ($status_lc === OrderPlacementKeys::JOB_STATUS_SUCCESS) {
                    $status_class = 'fflhub-dst-status-success';
                } elseif ($status_lc === OrderPlacementKeys::JOB_STATUS_FAILED) {
                    $status_class = 'fflhub-dst-status-danger';
                }
                ?>
                <section class="fflhub-dst-card">
                    <header class="fflhub-dst-card-head">
                        <div>
                            <div class="fflhub-dst-title">
                                <a href="<?php echo esc_url($order_edit_url); ?>">#<?php echo esc_html((string) $job->order_id); ?></a>
                            </div>
                            <div class="fflhub-dst-subtitle">
                                <code><?php echo esc_html((string) $job->job_key); ?></code>
                            </div>
                        </div>
                        <span class="fflhub-dst-status <?php echo esc_attr($status_class); ?>">
                            <?php echo esc_html((string) $job->status); ?>
                        </span>
                    </header>

                    <div class="fflhub-dst-meta">
                        <div><strong><?php esc_html_e('Distributor:', 'ffl-hub'); ?></strong> <?php echo esc_html((string) $job->dist_id); ?></div>
                        <div><strong><?php esc_html_e('Attempts:', 'ffl-hub'); ?></strong> <?php echo esc_html((string) $job->attempts); ?></div>
                        <div><strong><?php esc_html_e('Merchant PO:', 'ffl-hub'); ?></strong> <?php echo esc_html($merchant_po !== '' ? $merchant_po : '-'); ?></div>
                        <div><strong><?php esc_html_e('External Order:', 'ffl-hub'); ?></strong> <?php echo esc_html($external_order_id !== '' ? $external_order_id : '-'); ?></div>
                        <div><strong><?php esc_html_e('Last Step:', 'ffl-hub'); ?></strong> <?php echo esc_html($last_step !== '' ? $last_step : '-'); ?></div>
                        <div><strong><?php esc_html_e('Updated (UTC):', 'ffl-hub'); ?></strong> <?php echo esc_html($updated_at !== '' ? $updated_at : '-'); ?></div>
                    </div>

                    <div class="fflhub-dst-links-wrap">
                        <div class="fflhub-dst-links-title"><?php esc_html_e('Tracking Links', 'ffl-hub'); ?></div>
                        <ul class="fflhub-dst-links">
                            <?php foreach ($tracking_numbers as $tracking) : ?>
                                <?php
                                $tracking = trim((string) $tracking);
                                if ($tracking === '') {
                                    continue;
                                }
                                $carrier = self::carrier_for_tracking($shipping_service, $tracking);
                                $url     = self::tracking_url_for($carrier, $tracking);
                                ?>
                                <li>
                                    <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo esc_html($tracking); ?>
                                    </a>
                                    <span class="fflhub-dst-carrier">
                                        <?php echo esc_html($carrier !== '' ? $carrier : 'TRACK'); ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <?php if ($last_error !== '') : ?>
                        <div class="fflhub-dst-error">
                            <strong><?php esc_html_e('Last Error:', 'ffl-hub'); ?></strong>
                            <?php echo esc_html($last_error); ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
<?php
    }

    private static function carrier_for_tracking(string $shipping_service, string $tracking): string
    {
        $hint = strtoupper(trim($shipping_service));
        if ($hint !== '') {
            if (strpos($hint, 'USPS') !== false || strpos($hint, 'POSTAL') !== false) {
                return 'USPS';
            }
            if (strpos($hint, 'UPS') !== false) {
                return 'UPS';
            }
            if (strpos($hint, 'FEDEX') !== false || strpos($hint, 'FED EX') !== false || strpos($hint, 'FDX') !== false) {
                return 'FEDEX';
            }
        }

        $t = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $tracking));
        if ($t === '') {
            return '';
        }

        if (strpos($t, '1Z') === 0) {
            return 'UPS';
        }

        if ((bool) preg_match('/^9\d{15,29}$/', $t)) {
            return 'USPS';
        }

        if ((bool) preg_match('/^\d{12}$|^\d{15}$|^\d{20}$|^\d{22}$/', $t)) {
            return 'FEDEX';
        }

        return '';
    }

    private static function tracking_url_for(string $carrier, string $tracking): string
    {
        $t = trim($tracking);
        if ($t === '') {
            return '';
        }

        $carrier = strtoupper(trim($carrier));
        if ($carrier === 'UPS') {
            return 'https://www.ups.com/track?tracknum=' . rawurlencode($t);
        }
        if ($carrier === 'USPS') {
            return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . rawurlencode($t);
        }
        if ($carrier === 'FEDEX') {
            return 'https://www.fedex.com/fedextrack/?trknbr=' . rawurlencode($t);
        }

        return 'https://www.17track.net/en?nums=' . rawurlencode($t);
    }

    private function render_tracker_styles(): void
    {
        ?>
        <style>
            .fflhub-dst-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:14px; margin-top:12px; }
            .fflhub-dst-card { background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:14px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
            .fflhub-dst-card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:10px; }
            .fflhub-dst-title { font-size:16px; font-weight:700; }
            .fflhub-dst-subtitle { margin-top:2px; color:#50575e; }
            .fflhub-dst-status { border-radius:999px; padding:2px 8px; font-size:11px; font-weight:700; text-transform:uppercase; }
            .fflhub-dst-status-success { background:#e6f6ed; color:#146c43; }
            .fflhub-dst-status-danger { background:#fde8e8; color:#9f1239; }
            .fflhub-dst-status-neutral { background:#f6f7f7; color:#3c434a; }
            .fflhub-dst-meta { display:grid; grid-template-columns:1fr 1fr; gap:8px 12px; margin-bottom:10px; font-size:13px; }
            .fflhub-dst-links-wrap { border-top:1px solid #f0f0f1; padding-top:10px; }
            .fflhub-dst-links-title { font-weight:700; margin-bottom:6px; }
            .fflhub-dst-links { margin:0; padding-left:18px; }
            .fflhub-dst-links li { margin:4px 0; }
            .fflhub-dst-carrier { margin-left:6px; font-size:11px; color:#646970; text-transform:uppercase; }
            .fflhub-dst-error { margin-top:10px; background:#fff5f5; border:1px solid #fecaca; border-radius:8px; padding:8px; color:#7f1d1d; font-size:12px; }
            @media (max-width: 782px) { .fflhub-dst-meta { grid-template-columns:1fr; } }
        </style>
        <?php
    }

    /**
     * @return array<string,string>
     */
    private static function status_options(): array
    {
        return [
            OrderPlacementKeys::JOB_STATUS_QUEUED          => 'queued',
            OrderPlacementKeys::JOB_STATUS_SCHEDULED       => 'scheduled',
            OrderPlacementKeys::JOB_STATUS_RUNNING         => 'running',
            OrderPlacementKeys::JOB_STATUS_SUCCESS         => 'success',
            OrderPlacementKeys::JOB_STATUS_FAILED          => 'failed',
            OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED => 'retry_scheduled',
            OrderPlacementKeys::JOB_STATUS_PAUSED          => 'paused',
        ];
    }
}
