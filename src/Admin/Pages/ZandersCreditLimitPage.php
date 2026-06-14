<?php

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Settings\Options;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only admin page for Zanders credit usage on processing orders.
 */
final class ZandersCreditLimitPage
{
    private const PAGE_SLUG = 'fflhub-zanders-credit-limit';
    private const ZANDERS_DIST_ID = 'zanders';
    private const QUERY_LIMIT = 200000;
    private const TARGET_WOO_ORDER_STATUS = 'processing';
    private const DEFAULT_CREDIT_LIMIT = 5000.0;

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
            __('Zanders Credit Limit', 'ffl-hub'),
            __('Zanders Credit Limit', 'ffl-hub'),
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
            self::ZANDERS_DIST_ID,
            self::QUERY_LIMIT
        );

        $jobs = $this->filter_jobs_for_processing_orders($jobs);
        $data = $this->build_credit_status_data($jobs);
?>
        <div class="wrap fflhub-zanders-credit-status">
            <?php $this->render_styles(); ?>
            <h1><?php esc_html_e('Zanders Credit Limit', 'ffl-hub'); ?></h1>
            <p>
                <?php esc_html_e('This page shows Zanders job-line entries for WooCommerce orders currently in Processing status.', 'ffl-hub'); ?>
            </p>
            <p>
                <?php esc_html_e('Completed orders are excluded here.', 'ffl-hub'); ?>
            </p>
            <p class="fflhub-zanders-callout">
                <?php esc_html_e('Zanders ordering is automatic. Manual ordering is not required on this page, and this page does not write to job rows.', 'ffl-hub'); ?>
            </p>

            <?php $this->render_summary_cards($data); ?>
            <?php $this->render_running_totals_table($data); ?>
            <?php $this->render_entries_table($data); ?>
        </div>
<?php
    }

    /**
     * @param OrderPlacementJobRow[] $jobs
     * @return OrderPlacementJobRow[]
     */
    private function filter_jobs_for_processing_orders(array $jobs): array
    {
        /** @var array<int,string> $order_status_cache */
        $order_status_cache = [];
        $out = [];

        foreach ($jobs as $job) {
            if (!($job instanceof OrderPlacementJobRow)) {
                continue;
            }

            $order_id = (int) $job->order_id;
            if ($order_id <= 0) {
                continue;
            }

            if (!array_key_exists($order_id, $order_status_cache)) {
                $order = wc_get_order($order_id);
                $order_status_cache[$order_id] = ($order && method_exists($order, 'get_status'))
                    ? strtolower(trim((string) $order->get_status()))
                    : '';
            }

            if ($order_status_cache[$order_id] !== self::TARGET_WOO_ORDER_STATUS) {
                continue;
            }

            $out[] = $job;
        }

        return $out;
    }

    /**
     * @param OrderPlacementJobRow[] $jobs
     * @return array{
     *   processing_order_count:int,
     *   job_count:int,
     *   line_count:int,
     *   distinct_upc_count:int,
     *   total_quantity:int,
     *   distributor_total_cost:float,
     *   credit_limit:float,
     *   credit_remaining:float,
     *   credit_usage_pct:float,
     *   totals_by_upc:array<int,array{upc:string,product_name:string,total_qty:int,line_count:int,total_estimated_cost:float}>,
     *   entries:array<int,array{
     *     job_id:int,
     *     order_id:int,
     *     job_key:string,
     *     updated_at:string,
     *     job_status:string,
     *     merchant_po:string,
     *     upc:string,
     *     qty:int,
     *     product_name:string,
     *     unit_cost:float,
     *     line_cost:float
     *   }>
     * }
     */
    private function build_credit_status_data(array $jobs): array
    {
        /** @var array<string,string> $product_name_by_upc */
        $product_name_by_upc = [];
        /** @var array<string,float> $unit_cost_by_upc */
        $unit_cost_by_upc = [];
        /** @var array<string,array{upc:string,product_name:string,total_qty:int,line_count:int,total_estimated_cost:float}> $totals_by_upc */
        $totals_by_upc = [];
        /** @var array<int,array{
        *   job_id:int,
        *   order_id:int,
        *   job_key:string,
        *   updated_at:string,
        *   job_status:string,
        *   merchant_po:string,
        *   upc:string,
        *   qty:int,
        *   product_name:string,
        *   unit_cost:float,
        *   line_cost:float
        * }> $entries */
        $entries = [];
        /** @var array<int,bool> $processing_order_ids */
        $processing_order_ids = [];

        $total_quantity = 0;
        $line_count = 0;
        $distributor_total_cost = 0.0;

        foreach ($jobs as $job) {
            if (!($job instanceof OrderPlacementJobRow)) {
                continue;
            }

            $processing_order_ids[(int) $job->order_id] = true;
            $job_status = strtolower(trim((string) $job->status));
            $include_in_running_totals = ($job_status !== OrderPlacementKeys::JOB_STATUS_SUCCESS);
            $lines = $job->payload_lines();
            foreach ($lines as $line) {
                if (!($line instanceof DistributorOrderLine)) {
                    continue;
                }

                $upc = trim((string) $line->upc);
                if ($upc === '') {
                    continue;
                }
                $qty = max(1, (int) $line->quantity);
                $product_name = $this->resolve_product_name_for_upc($upc, $product_name_by_upc);
                $unit_cost = $this->resolve_distributor_unit_cost_for_upc($upc, $unit_cost_by_upc);
                $line_cost = $unit_cost * (float) $qty;

                $entries[] = [
                    'job_id' => (int) $job->id,
                    'order_id' => (int) $job->order_id,
                    'job_key' => (string) $job->job_key,
                    'updated_at' => (string) ($job->updated_at ?? ''),
                    'job_status' => (string) $job->status,
                    'merchant_po' => (string) ($job->merchant_po ?? ''),
                    'upc' => $upc,
                    'qty' => $qty,
                    'product_name' => $product_name,
                    'unit_cost' => $unit_cost,
                    'line_cost' => $line_cost,
                ];

                // Credit usage includes successful rows while the Woo order remains in Processing.
                $distributor_total_cost += $line_cost;

                if (!$include_in_running_totals) {
                    continue;
                }

                if (!isset($totals_by_upc[$upc])) {
                    $totals_by_upc[$upc] = [
                        'upc' => $upc,
                        'product_name' => $product_name,
                        'total_qty' => 0,
                        'line_count' => 0,
                        'total_estimated_cost' => 0.0,
                    ];
                }

                if ($totals_by_upc[$upc]['product_name'] === 'Unknown product' && $product_name !== 'Unknown product') {
                    $totals_by_upc[$upc]['product_name'] = $product_name;
                }

                $totals_by_upc[$upc]['total_qty'] += $qty;
                $totals_by_upc[$upc]['line_count']++;
                $totals_by_upc[$upc]['total_estimated_cost'] += $line_cost;
                $total_quantity += $qty;
                $line_count++;
            }
        }

        $totals_rows = array_values($totals_by_upc);
        usort(
            $totals_rows,
            static function (array $a, array $b): int {
                $aq = (int) ($a['total_qty'] ?? 0);
                $bq = (int) ($b['total_qty'] ?? 0);
                if ($aq !== $bq) {
                    return ($aq > $bq) ? -1 : 1;
                }
                return strcmp((string) ($a['upc'] ?? ''), (string) ($b['upc'] ?? ''));
            }
        );

        $credit_limit = $this->get_zanders_credit_limit();
        $credit_remaining = $credit_limit - $distributor_total_cost;
        $credit_usage_pct = ($credit_limit > 0.0)
            ? (($distributor_total_cost / $credit_limit) * 100.0)
            : 0.0;

        return [
            'processing_order_count' => count($processing_order_ids),
            'job_count' => count($jobs),
            'line_count' => $line_count,
            'distinct_upc_count' => count($totals_rows),
            'total_quantity' => $total_quantity,
            'distributor_total_cost' => $distributor_total_cost,
            'credit_limit' => $credit_limit,
            'credit_remaining' => $credit_remaining,
            'credit_usage_pct' => $credit_usage_pct,
            'totals_by_upc' => $totals_rows,
            'entries' => $entries,
        ];
    }

    /**
     * @param string $upc
     * @param array<string,string> $product_name_by_upc
     */
    private function resolve_product_name_for_upc(string $upc, array &$product_name_by_upc): string
    {
        $upc = trim($upc);
        if ($upc === '') {
            return 'Unknown product';
        }

        if (array_key_exists($upc, $product_name_by_upc)) {
            return $product_name_by_upc[$upc];
        }

        $product_id = $this->find_product_id_by_upc($upc);
        if ($product_id <= 0) {
            $product_name_by_upc[$upc] = 'Unknown product';
            return $product_name_by_upc[$upc];
        }

        $product = wc_get_product($product_id);
        if (!$product || !method_exists($product, 'get_name')) {
            $product_name_by_upc[$upc] = 'Unknown product';
            return $product_name_by_upc[$upc];
        }

        $name = trim((string) $product->get_name());
        if ($name === '') {
            $name = 'Unknown product';
        }

        $product_name_by_upc[$upc] = $name;
        return $name;
    }

    /**
     * @param string $upc
     * @param array<string,float> $unit_cost_by_upc
     */
    private function resolve_distributor_unit_cost_for_upc(string $upc, array &$unit_cost_by_upc): float
    {
        $upc = trim($upc);
        if ($upc === '') {
            return 0.0;
        }

        if (array_key_exists($upc, $unit_cost_by_upc)) {
            return (float) $unit_cost_by_upc[$upc];
        }

        $unit_cost_by_upc[$upc] = $this->offer_dealer_price_for_upc($upc);

        return (float) $unit_cost_by_upc[$upc];
    }

    private function find_product_id_by_upc(string $upc): int
    {
        $upc = trim($upc);
        if ($upc === '') {
            return 0;
        }

        $row = ProductStateStore::get_row_for_upc($upc);

        return is_array($row) ? (int) ($row['product_id'] ?? 0) : 0;
    }

    private function offer_dealer_price_for_upc(string $upc): float
    {
        global $wpdb;

        if (!$wpdb || $upc === '') {
            return 0.0;
        }

        DistributorOffersStore::ensure_schema();
        $table = DistributorOffersStore::table_name();
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT dealer_price
                FROM {$table}
                WHERE upc = %s
                  AND distributor_id = %s
                  AND enabled = 1
                LIMIT 1
                ",
                $upc,
                self::ZANDERS_DIST_ID
            )
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return $this->to_non_negative_float($value);
    }

    /**
     * @param mixed $value
     */
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

    private function get_zanders_credit_limit(): float
    {
        $limit = Options::get_distributor_credit_limit(self::ZANDERS_DIST_ID, self::DEFAULT_CREDIT_LIMIT);

        /** @var float|int|string $filtered */
        $filtered = apply_filters('fflhub_zanders_credit_limit', $limit);
        $final = $this->to_non_negative_float($filtered);
        return ($final > 0.0) ? $final : self::DEFAULT_CREDIT_LIMIT;
    }

    private function format_money(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }

    /**
     * @param array{
     *   processing_order_count:int,
     *   job_count:int,
     *   line_count:int,
     *   distinct_upc_count:int,
     *   total_quantity:int,
     *   distributor_total_cost:float,
     *   credit_limit:float,
     *   credit_remaining:float,
     *   credit_usage_pct:float,
     *   totals_by_upc:array<int,array{upc:string,product_name:string,total_qty:int,line_count:int,total_estimated_cost:float}>,
     *   entries:array<int,array{
     *     job_id:int,
     *     order_id:int,
     *     job_key:string,
     *     updated_at:string,
     *     job_status:string,
     *     merchant_po:string,
     *     upc:string,
     *     qty:int,
     *     product_name:string,
     *     unit_cost:float,
     *     line_cost:float
     *   }>
     * } $data
     */
    private function render_summary_cards(array $data): void
    {
        $distributor_total_cost = (float) ($data['distributor_total_cost'] ?? 0.0);
        $credit_limit = (float) ($data['credit_limit'] ?? self::DEFAULT_CREDIT_LIMIT);
        $credit_remaining = (float) ($data['credit_remaining'] ?? 0.0);
        $credit_usage_pct = (float) ($data['credit_usage_pct'] ?? 0.0);
        $is_over_limit = $credit_remaining < 0.0;
        $credit_card_class = $is_over_limit ? 'is-danger' : 'is-ok';
        ?>
        <div class="fflhub-zanders-summary-grid">
            <section class="fflhub-zanders-card">
                <h2><?php esc_html_e('Processing Orders', 'ffl-hub'); ?></h2>
                <div class="fflhub-zanders-metric"><?php echo esc_html((string) ((int) $data['processing_order_count'])); ?></div>
                <p><?php esc_html_e('WooCommerce orders in Processing status.', 'ffl-hub'); ?></p>
            </section>

            <section class="fflhub-zanders-card">
                <h2><?php esc_html_e('Zanders Jobs', 'ffl-hub'); ?></h2>
                <div class="fflhub-zanders-metric"><?php echo esc_html((string) ((int) $data['job_count'])); ?></div>
                <p><?php esc_html_e('Job rows attached to those processing orders.', 'ffl-hub'); ?></p>
            </section>

            <section class="fflhub-zanders-card is-ok">
                <h2><?php esc_html_e('Running Totals', 'ffl-hub'); ?></h2>
                <div class="fflhub-zanders-metric"><?php echo esc_html((string) ((int) $data['total_quantity'])); ?></div>
                <p>
                    <?php
                    echo esc_html(
                        sprintf(
                            __('Distinct UPCs: %d | Line Entries: %d', 'ffl-hub'),
                            (int) $data['distinct_upc_count'],
                            (int) $data['line_count']
                        )
                    );
                    ?>
                </p>
                <div class="fflhub-zanders-note">
                    <?php esc_html_e('Running totals exclude rows already marked success.', 'ffl-hub'); ?>
                </div>
            </section>

            <section class="fflhub-zanders-card <?php echo esc_attr($credit_card_class); ?>">
                <h2><?php esc_html_e('Credit Limit Usage', 'ffl-hub'); ?></h2>
                <div class="fflhub-zanders-metric">
                    <?php
                    echo esc_html(
                        sprintf(
                            '%s / %s',
                            $this->format_money($distributor_total_cost),
                            $this->format_money($credit_limit)
                        )
                    );
                    ?>
                </div>
                <p>
                    <?php
                    if ($is_over_limit) {
                        echo esc_html(
                            sprintf(
                                __('Over limit by %s (%.1f%% used).', 'ffl-hub'),
                                $this->format_money(abs($credit_remaining)),
                                $credit_usage_pct
                            )
                        );
                    } else {
                        echo esc_html(
                            sprintf(
                                __('Remaining credit: %s (%.1f%% used).', 'ffl-hub'),
                                $this->format_money($credit_remaining),
                                $credit_usage_pct
                            )
                        );
                    }
                    ?>
                </p>
                <div class="fflhub-zanders-note">
                    <?php esc_html_e('Credit usage includes success rows while orders are still Processing.', 'ffl-hub'); ?>
                </div>
            </section>
        </div>
        <?php
    }

    /**
     * @param array{
     *   processing_order_count:int,
     *   job_count:int,
     *   line_count:int,
     *   distinct_upc_count:int,
     *   total_quantity:int,
     *   distributor_total_cost:float,
     *   credit_limit:float,
     *   credit_remaining:float,
     *   credit_usage_pct:float,
     *   totals_by_upc:array<int,array{upc:string,product_name:string,total_qty:int,line_count:int,total_estimated_cost:float}>,
     *   entries:array<int,array{
     *     job_id:int,
     *     order_id:int,
     *     job_key:string,
     *     updated_at:string,
     *     job_status:string,
     *     merchant_po:string,
     *     upc:string,
     *     qty:int,
     *     product_name:string,
     *     unit_cost:float,
     *     line_cost:float
     *   }>
     * } $data
     */
    private function render_running_totals_table(array $data): void
    {
        $rows = $data['totals_by_upc'];
        if (empty($rows)) {
            ?>
            <p><?php esc_html_e('No Zanders line entries found for processing orders.', 'ffl-hub'); ?></p>
            <?php
            return;
        }
        ?>
        <h2><?php esc_html_e('Running Totals by UPC', 'ffl-hub'); ?></h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('UPC', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Product Name', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Total Qty', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Line Entries', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Est. Distributor Cost', 'ffl-hub'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $upc = (string) ($row['upc'] ?? '');
                    $product_name = (string) ($row['product_name'] ?? 'Unknown product');
                    $total_qty = (int) ($row['total_qty'] ?? 0);
                    $line_count = (int) ($row['line_count'] ?? 0);
                    $estimated_cost = (float) ($row['total_estimated_cost'] ?? 0.0);
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($upc); ?></code></td>
                        <td><?php echo esc_html($product_name); ?></td>
                        <td><?php echo esc_html((string) $total_qty); ?></td>
                        <td><?php echo esc_html((string) $line_count); ?></td>
                        <td><?php echo esc_html($this->format_money($estimated_cost)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * @param array{
     *   processing_order_count:int,
     *   job_count:int,
     *   line_count:int,
     *   distinct_upc_count:int,
     *   total_quantity:int,
     *   distributor_total_cost:float,
     *   credit_limit:float,
     *   credit_remaining:float,
     *   credit_usage_pct:float,
     *   totals_by_upc:array<int,array{upc:string,product_name:string,total_qty:int,line_count:int,total_estimated_cost:float}>,
     *   entries:array<int,array{
     *     job_id:int,
     *     order_id:int,
     *     job_key:string,
     *     updated_at:string,
     *     job_status:string,
     *     merchant_po:string,
     *     upc:string,
     *     qty:int,
     *     product_name:string,
     *     unit_cost:float,
     *     line_cost:float
     *   }>
     * } $data
     */
    private function render_entries_table(array $data): void
    {
        $entries = $data['entries'];
        if (empty($entries)) {
            return;
        }
        ?>
        <h2><?php esc_html_e('Processing Order Line Entries (Read-Only)', 'ffl-hub'); ?></h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Job ID', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Order', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Job Key', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Job Status', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Merchant PO', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Updated (UTC)', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('UPC', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Product Name', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Qty', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Unit Cost', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Line Cost', 'ffl-hub'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry) : ?>
                    <?php
                    $order_id = (int) ($entry['order_id'] ?? 0);
                    $job_id = (int) ($entry['job_id'] ?? 0);
                    $job_key = (string) ($entry['job_key'] ?? '');
                    $merchant_po = trim((string) ($entry['merchant_po'] ?? ''));
                    $order_edit_url = admin_url('post.php?post=' . $order_id . '&action=edit');
                    ?>
                    <tr>
                        <td><?php echo esc_html((string) $job_id); ?></td>
                        <td>
                            <?php if ($order_id > 0) : ?>
                                <a href="<?php echo esc_url($order_edit_url); ?>">
                                    <?php echo esc_html('#' . (string) $order_id); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html('-'); ?>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html($job_key); ?></code></td>
                        <td><?php echo esc_html((string) ($entry['job_status'] ?? '')); ?></td>
                        <td><code><?php echo esc_html($merchant_po !== '' ? $merchant_po : '-'); ?></code></td>
                        <td><?php echo esc_html((string) ($entry['updated_at'] ?? '')); ?></td>
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

    private function render_styles(): void
    {
        ?>
        <style>
            .fflhub-zanders-summary-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                gap: 12px;
                margin: 14px 0 18px;
            }
            .fflhub-zanders-card {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 10px;
                padding: 14px;
                box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
            }
            .fflhub-zanders-card.is-ok {
                border-color: #a7f3d0;
                background: #ecfdf5;
            }
            .fflhub-zanders-card.is-danger {
                border-color: #fecaca;
                background: #fef2f2;
            }
            .fflhub-zanders-card h2 {
                margin: 0 0 8px;
                font-size: 15px;
            }
            .fflhub-zanders-metric {
                font-size: 24px;
                font-weight: 700;
                line-height: 1.2;
            }
            .fflhub-zanders-card p {
                margin: 8px 0 0;
                color: #50575e;
            }
            .fflhub-zanders-note {
                margin-top: 4px;
                font-size: 11px;
                color: #646970;
            }
            .fflhub-zanders-callout {
                margin: 10px 0 14px;
                padding: 10px 12px;
                border: 1px solid #93c5fd;
                background: #eff6ff;
                border-radius: 8px;
                color: #1e3a8a;
                font-weight: 600;
            }
            .fflhub-zanders-credit-status table code {
                word-break: break-all;
            }
        </style>
        <?php
    }
}
