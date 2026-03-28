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
 * Admin page for viewing dealer-fulfilled order placement jobs.
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
            __('Dealer Fulfilled Jobs', 'ffl-hub'),
            __('Dealer Fulfilled Jobs', 'ffl-hub'),
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
?>
        <div class="wrap">
            <h1><?php esc_html_e('Dealer Fulfilled Jobs', 'ffl-hub'); ?></h1>
            <p>
                <?php esc_html_e(
                    'Operational view of order placement jobs where lane=dealer_fulfilled.',
                    'ffl-hub'
                ); ?>
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
            <p><?php esc_html_e('No dealer-fulfilled jobs found for the selected filters.', 'ffl-hub'); ?></p>
<?php
            return;
        }
?>
        <p>
            <?php
            printf(
                esc_html__('Found %d dealer-fulfilled jobs.', 'ffl-hub'),
                count($jobs)
            );
            ?>
        </p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Job ID', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Order', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Distributor', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Status', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Attempts', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Merchant PO', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('External Order', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Tracking', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Last Step', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Updated (UTC)', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Error', 'ffl-hub'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $job) : ?>
                    <?php
                    if (!($job instanceof OrderPlacementJobRow)) {
                        continue;
                    }

                    $order_edit_url = admin_url('post.php?post=' . (int) $job->order_id . '&action=edit');
                    $merchant_po = trim((string) ($job->merchant_po ?? ''));
                    $external_order_id = trim((string) ($job->external_order_id ?? ''));
                    $tracking = implode(', ', $job->tracking_numbers());
                    $tracking = trim($tracking);
                    $last_error = trim((string) ($job->last_error ?? ''));
                    if ($last_error !== '') {
                        $last_error = wp_html_excerpt($last_error, 220, '...');
                    }
                    ?>
                    <tr>
                        <td><code><?php echo esc_html((string) $job->id); ?></code></td>
                        <td>
                            <a href="<?php echo esc_url($order_edit_url); ?>">
                                #<?php echo esc_html((string) $job->order_id); ?>
                            </a>
                            <br />
                            <code><?php echo esc_html((string) $job->job_key); ?></code>
                        </td>
                        <td><?php echo esc_html((string) $job->dist_id); ?></td>
                        <td><?php echo esc_html((string) $job->status); ?></td>
                        <td><?php echo esc_html((string) $job->attempts); ?></td>
                        <td><?php echo esc_html($merchant_po !== '' ? $merchant_po : '-'); ?></td>
                        <td><?php echo esc_html($external_order_id !== '' ? $external_order_id : '-'); ?></td>
                        <td><?php echo esc_html($tracking !== '' ? $tracking : '-'); ?></td>
                        <td><?php echo esc_html((string) ($job->last_step !== '' ? $job->last_step : '-')); ?></td>
                        <td><?php echo esc_html((string) ($job->updated_at ?? '-')); ?></td>
                        <td><?php echo esc_html($last_error !== '' ? $last_error : '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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

