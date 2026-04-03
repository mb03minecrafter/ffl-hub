<?php

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Product\ProductMeta;
use WC_Product;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin page for Davidson's failed job rows and failed-row cost exposure.
 */
final class DavidsonsFailedJobsPage
{
    private const PAGE_SLUG = 'fflhub-davidsons-failed-jobs';
    private const DAVIDSONS_DIST_ID = 'davidsons';
    private const CREDIT_LIMIT = 2500.00;
    private const QUERY_LIMIT = 200000;

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
            __("Davidson's Failed Jobs", 'ffl-hub'),
            __("Davidson's Failed Jobs", 'ffl-hub'),
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

        $jobs = OrderPlacementJobsRepository::find_jobs_by_distributor(
            $this->jobs_table,
            self::DAVIDSONS_DIST_ID,
            self::QUERY_LIMIT,
            OrderPlacementKeys::JOB_STATUS_FAILED
        );

        $summary = $this->build_summary($jobs);
?>
        <div class="wrap fflhub-davidsons-failed-jobs">
            <?php $this->render_styles(); ?>
            <h1><?php esc_html_e("Davidson's Failed Job Rows", 'ffl-hub'); ?></h1>
            <p>
                <?php esc_html_e("This view shows failed Davidson's placement rows and the estimated distributor-cost exposure from those failed rows.", 'ffl-hub'); ?>
            </p>

            <?php $this->render_summary_cards($summary); ?>
            <?php $this->render_jobs_table($jobs, $summary); ?>
        </div>
<?php
    }

    /**
     * @param OrderPlacementJobRow[] $jobs
     * @return array{
     *   rows:int,
     *   total_cost:float,
     *   credit_limit:float,
     *   remaining_credit:float,
     *   over_limit:float,
     *   usage_pct:float,
     *   unresolved_lines_total:int,
     *   row_cost_by_id:array<int,float>,
     *   row_unresolved_lines_by_id:array<int,int>
     * }
     */
    private function build_summary(array $jobs): array
    {
        $row_cost_by_id = [];
        $row_unresolved_lines_by_id = [];

        /** @var array<string,float|null> $unit_cost_cache */
        $unit_cost_cache = [];

        $total_cost = 0.0;
        $unresolved_lines_total = 0;

        foreach ($jobs as $job) {
            if (!($job instanceof OrderPlacementJobRow)) {
                continue;
            }

            $row_cost = 0.0;
            $row_unresolved = 0;
            $lines = $job->payload_lines();

            foreach ($lines as $line) {
                if (!($line instanceof DistributorOrderLine)) {
                    continue;
                }

                $upc = trim((string) $line->upc);
                if ($upc === '') {
                    $row_unresolved++;
                    continue;
                }

                $unit_cost = $this->resolve_unit_cost_for_upc($upc, $unit_cost_cache);
                if ($unit_cost === null || $unit_cost <= 0.0) {
                    $row_unresolved++;
                    continue;
                }

                $qty = max(1, (int) $line->quantity);
                $row_cost += ($unit_cost * $qty);
            }

            $row_cost = round($row_cost, 2);
            $row_cost_by_id[$job->id] = $row_cost;
            $row_unresolved_lines_by_id[$job->id] = $row_unresolved;
            $total_cost += $row_cost;
            $unresolved_lines_total += $row_unresolved;
        }

        $total_cost = round($total_cost, 2);
        $credit_limit = (float) self::CREDIT_LIMIT;
        $remaining_credit = round($credit_limit - $total_cost, 2);
        $over_limit = ($remaining_credit < 0.0) ? abs($remaining_credit) : 0.0;
        $usage_pct = ($credit_limit > 0.0) ? min(999.0, round(($total_cost / $credit_limit) * 100.0, 1)) : 0.0;

        return [
            'rows' => count($jobs),
            'total_cost' => $total_cost,
            'credit_limit' => $credit_limit,
            'remaining_credit' => $remaining_credit,
            'over_limit' => $over_limit,
            'usage_pct' => $usage_pct,
            'unresolved_lines_total' => $unresolved_lines_total,
            'row_cost_by_id' => $row_cost_by_id,
            'row_unresolved_lines_by_id' => $row_unresolved_lines_by_id,
        ];
    }

    /**
     * @param string $upc
     * @param array<string,float|null> $unit_cost_cache
     */
    private function resolve_unit_cost_for_upc(string $upc, array &$unit_cost_cache): ?float
    {
        $upc = trim($upc);
        if ($upc === '') {
            return null;
        }

        if (array_key_exists($upc, $unit_cost_cache)) {
            return $unit_cost_cache[$upc];
        }

        $product_id = $this->find_product_id_by_upc($upc);
        if ($product_id <= 0) {
            $unit_cost_cache[$upc] = null;
            return null;
        }

        $product = wc_get_product($product_id);
        if (!($product instanceof WC_Product)) {
            $unit_cost_cache[$upc] = null;
            return null;
        }

        $true_cost = $this->to_positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_TRUE_COST_META, true));
        if ($true_cost !== null) {
            $unit_cost_cache[$upc] = $true_cost;
            return $true_cost;
        }

        $dealer_price = $this->to_positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, true));
        $unit_cost_cache[$upc] = $dealer_price;
        return $dealer_price;
    }

    private function find_product_id_by_upc(string $upc): int
    {
        global $wpdb;

        $upc = trim($upc);
        if ($upc === '') {
            return 0;
        }

        $meta_keys = [
            ProductMeta::FFLHUB_UPC_META,
            '_upc',
            'upc',
        ];

        foreach ($meta_keys as $meta_key) {
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

    /**
     * @param mixed $value
     */
    private function to_positive_float($value): ?float
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (!is_numeric($raw)) {
            $raw = trim((string) preg_replace('/[^0-9\.\-]/', '', $raw));
        }

        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $num = (float) $raw;
        if (!is_finite($num) || $num <= 0.0) {
            return null;
        }

        return $num;
    }

    /**
     * @param array{
     *   rows:int,
     *   total_cost:float,
     *   credit_limit:float,
     *   remaining_credit:float,
     *   over_limit:float,
     *   usage_pct:float,
     *   unresolved_lines_total:int,
     *   row_cost_by_id:array<int,float>,
     *   row_unresolved_lines_by_id:array<int,int>
     * } $summary
     */
    private function render_summary_cards(array $summary): void
    {
        $is_over_limit = ((float) $summary['over_limit']) > 0.0;
        ?>
        <div class="fflhub-davidsons-summary-grid">
            <section class="fflhub-davidsons-card">
                <h2><?php esc_html_e('Failed Job Distributor Cost', 'ffl-hub'); ?></h2>
                <div class="fflhub-davidsons-money"><?php echo esc_html($this->format_money((float) $summary['total_cost'])); ?></div>
                <p><?php esc_html_e("Estimated from payload UPC + qty using each product's last true cost (dealer price fallback).", 'ffl-hub'); ?></p>
            </section>

            <section class="fflhub-davidsons-card">
                <h2><?php esc_html_e('Credit Limit', 'ffl-hub'); ?></h2>
                <div class="fflhub-davidsons-money"><?php echo esc_html($this->format_money((float) $summary['credit_limit'])); ?></div>
                <p><?php echo esc_html(sprintf(__('Usage: %s%%', 'ffl-hub'), number_format((float) $summary['usage_pct'], 1))); ?></p>
            </section>

            <section class="fflhub-davidsons-card <?php echo $is_over_limit ? 'is-danger' : 'is-ok'; ?>">
                <h2><?php echo esc_html($is_over_limit ? __('Over Limit', 'ffl-hub') : __('Remaining Credit', 'ffl-hub')); ?></h2>
                <div class="fflhub-davidsons-money">
                    <?php
                    if ($is_over_limit) {
                        echo esc_html($this->format_money((float) $summary['over_limit']));
                    } else {
                        echo esc_html($this->format_money((float) $summary['remaining_credit']));
                    }
                    ?>
                </div>
                <p>
                    <?php
                    echo esc_html(
                        sprintf(
                            __('Failed rows: %d | unresolved lines: %d', 'ffl-hub'),
                            (int) $summary['rows'],
                            (int) $summary['unresolved_lines_total']
                        )
                    );
                    ?>
                </p>
            </section>
        </div>
        <?php
    }

    /**
     * @param OrderPlacementJobRow[] $jobs
     * @param array{
     *   rows:int,
     *   total_cost:float,
     *   credit_limit:float,
     *   remaining_credit:float,
     *   over_limit:float,
     *   usage_pct:float,
     *   unresolved_lines_total:int,
     *   row_cost_by_id:array<int,float>,
     *   row_unresolved_lines_by_id:array<int,int>
     * } $summary
     */
    private function render_jobs_table(array $jobs, array $summary): void
    {
        if (empty($jobs)) {
            ?>
            <p><?php esc_html_e("No failed Davidson's job rows found.", 'ffl-hub'); ?></p>
            <?php
            return;
        }
        ?>
        <h2><?php esc_html_e("Failed Davidson's Rows", 'ffl-hub'); ?></h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Job ID', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Order', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Job Key', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Updated (UTC)', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Attempts', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Line Count', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Distributor Cost', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Last Error', 'ffl-hub'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $job) : ?>
                    <?php
                    if (!($job instanceof OrderPlacementJobRow)) {
                        continue;
                    }

                    $order_id = (int) $job->order_id;
                    $order_edit_url = admin_url('post.php?post=' . $order_id . '&action=edit');
                    $line_count = (int) $job->payload_lines_count();
                    $row_cost = (float) ($summary['row_cost_by_id'][$job->id] ?? 0.0);
                    $unresolved_lines = (int) ($summary['row_unresolved_lines_by_id'][$job->id] ?? 0);
                    $last_error = trim((string) ($job->last_error ?? ''));
                    if ($last_error !== '') {
                        $last_error = wp_html_excerpt($last_error, 260, '...');
                    } else {
                        $last_error = '-';
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html((string) $job->id); ?></td>
                        <td>
                            <a href="<?php echo esc_url($order_edit_url); ?>">
                                <?php echo esc_html('#' . (string) $order_id); ?>
                            </a>
                        </td>
                        <td><code><?php echo esc_html((string) $job->job_key); ?></code></td>
                        <td><?php echo esc_html((string) ($job->updated_at ?? '-')); ?></td>
                        <td><?php echo esc_html((string) $job->attempts); ?></td>
                        <td><?php echo esc_html((string) $line_count); ?></td>
                        <td>
                            <?php echo esc_html($this->format_money($row_cost)); ?>
                            <?php if ($unresolved_lines > 0) : ?>
                                <div class="fflhub-davidsons-note">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            __('%d line(s) missing cost meta', 'ffl-hub'),
                                            $unresolved_lines
                                        )
                                    );
                                    ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($last_error); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function format_money(float $value): string
    {
        return '$' . number_format($value, 2, '.', ',');
    }

    private function render_styles(): void
    {
        ?>
        <style>
            .fflhub-davidsons-summary-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                gap: 12px;
                margin: 14px 0 18px;
            }
            .fflhub-davidsons-card {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 10px;
                padding: 14px;
                box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
            }
            .fflhub-davidsons-card.is-ok {
                border-color: #a7f3d0;
                background: #ecfdf5;
            }
            .fflhub-davidsons-card.is-danger {
                border-color: #fecaca;
                background: #fef2f2;
            }
            .fflhub-davidsons-card h2 {
                margin: 0 0 8px;
                font-size: 15px;
            }
            .fflhub-davidsons-money {
                font-size: 24px;
                font-weight: 700;
                line-height: 1.2;
            }
            .fflhub-davidsons-card p {
                margin: 8px 0 0;
                color: #50575e;
            }
            .fflhub-davidsons-note {
                margin-top: 4px;
                font-size: 11px;
                color: #646970;
            }
            .fflhub-davidsons-failed-jobs table code {
                word-break: break-all;
            }
        </style>
        <?php
    }
}

