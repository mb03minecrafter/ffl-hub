<?php

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Product\ProductMeta;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin page for Davidson's manual order status worklist.
 */
final class DavidsonsFailedJobsPage
{
    private const PAGE_SLUG = 'fflhub-davidsons-manual-order-status';
    private const DAVIDSONS_DIST_ID = 'davidsons';
    private const QUERY_LIMIT = 200000;
    private const TARGET_WOO_ORDER_STATUS = 'processing';

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
            __("Davidson's Manual Order Status", 'ffl-hub'),
            __("Davidson's Manual Order Status", 'ffl-hub'),
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
            self::QUERY_LIMIT
        );

        $jobs = $this->filter_jobs_for_processing_orders($jobs);
        $data = $this->build_manual_status_data($jobs);
?>
        <div class="wrap fflhub-davidsons-manual-status">
            <?php $this->render_styles(); ?>
            <h1><?php esc_html_e("Davidson's Manual Order Status", 'ffl-hub'); ?></h1>
            <p>
                <?php esc_html_e("This page shows Davidson's job-line entries for WooCommerce orders currently in Processing status.", 'ffl-hub'); ?>
            </p>
            <p>
                <?php esc_html_e("Completed orders are excluded here.", 'ffl-hub'); ?>
            </p>

            <?php $this->render_summary_cards($data); ?>
            <?php $this->render_running_totals_table($data); ?>
            <?php $this->render_entries_table($data); ?>
        </div>
<?php
    }

    /**
     * @param OrderPlacementJobRow[] $jobs
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
     *   totals_by_upc:array<int,array{upc:string,product_name:string,total_qty:int,line_count:int}>,
     *   entries:array<int,array{
     *     job_id:int,
     *     order_id:int,
     *     job_key:string,
     *     updated_at:string,
     *     job_status:string,
     *     upc:string,
     *     qty:int,
     *     product_name:string
     *   }>
     * }
     */
    private function build_manual_status_data(array $jobs): array
    {
        /** @var array<string,string> $product_name_by_upc */
        $product_name_by_upc = [];
        /** @var array<string,array{upc:string,product_name:string,total_qty:int,line_count:int}> $totals_by_upc */
        $totals_by_upc = [];
        /** @var array<int,array{
        *   job_id:int,
        *   order_id:int,
        *   job_key:string,
        *   updated_at:string,
        *   job_status:string,
        *   upc:string,
        *   qty:int,
        *   product_name:string
        * }> $entries */
        $entries = [];
        /** @var array<int,bool> $processing_order_ids */
        $processing_order_ids = [];

        $total_quantity = 0;
        $line_count = 0;

        foreach ($jobs as $job) {
            if (!($job instanceof OrderPlacementJobRow)) {
                continue;
            }

            $processing_order_ids[(int) $job->order_id] = true;
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

                $entries[] = [
                    'job_id' => (int) $job->id,
                    'order_id' => (int) $job->order_id,
                    'job_key' => (string) $job->job_key,
                    'updated_at' => (string) ($job->updated_at ?? ''),
                    'job_status' => (string) $job->status,
                    'upc' => $upc,
                    'qty' => $qty,
                    'product_name' => $product_name,
                ];

                if (!isset($totals_by_upc[$upc])) {
                    $totals_by_upc[$upc] = [
                        'upc' => $upc,
                        'product_name' => $product_name,
                        'total_qty' => 0,
                        'line_count' => 0,
                    ];
                }

                if ($totals_by_upc[$upc]['product_name'] === 'Unknown product' && $product_name !== 'Unknown product') {
                    $totals_by_upc[$upc]['product_name'] = $product_name;
                }

                $totals_by_upc[$upc]['total_qty'] += $qty;
                $totals_by_upc[$upc]['line_count']++;
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

        return [
            'processing_order_count' => count($processing_order_ids),
            'job_count' => count($jobs),
            'line_count' => $line_count,
            'distinct_upc_count' => count($totals_rows),
            'total_quantity' => $total_quantity,
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
     * @param array{
     *   processing_order_count:int,
     *   job_count:int,
     *   line_count:int,
     *   distinct_upc_count:int,
     *   total_quantity:int,
     *   totals_by_upc:array<int,array{upc:string,product_name:string,total_qty:int,line_count:int}>,
     *   entries:array<int,array{
     *     job_id:int,
     *     order_id:int,
     *     job_key:string,
     *     updated_at:string,
     *     job_status:string,
     *     upc:string,
     *     qty:int,
     *     product_name:string
     *   }>
     * } $data
     */
    private function render_summary_cards(array $data): void
    {
        ?>
        <div class="fflhub-davidsons-summary-grid">
            <section class="fflhub-davidsons-card">
                <h2><?php esc_html_e('Processing Orders', 'ffl-hub'); ?></h2>
                <div class="fflhub-davidsons-metric"><?php echo esc_html((string) ((int) $data['processing_order_count'])); ?></div>
                <p><?php esc_html_e('WooCommerce orders in Processing status.', 'ffl-hub'); ?></p>
            </section>

            <section class="fflhub-davidsons-card">
                <h2><?php esc_html_e("Davidson's Jobs", 'ffl-hub'); ?></h2>
                <div class="fflhub-davidsons-metric"><?php echo esc_html((string) ((int) $data['job_count'])); ?></div>
                <p><?php esc_html_e('Job rows attached to those processing orders.', 'ffl-hub'); ?></p>
            </section>

            <section class="fflhub-davidsons-card is-ok">
                <h2><?php esc_html_e('Running Totals', 'ffl-hub'); ?></h2>
                <div class="fflhub-davidsons-metric"><?php echo esc_html((string) ((int) $data['total_quantity'])); ?></div>
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
     *   totals_by_upc:array<int,array{upc:string,product_name:string,total_qty:int,line_count:int}>,
     *   entries:array<int,array{
     *     job_id:int,
     *     order_id:int,
     *     job_key:string,
     *     updated_at:string,
     *     job_status:string,
     *     upc:string,
     *     qty:int,
     *     product_name:string
     *   }>
     * } $data
     */
    private function render_running_totals_table(array $data): void
    {
        $rows = $data['totals_by_upc'];
        if (empty($rows)) {
            ?>
            <p><?php esc_html_e("No Davidson's line entries found for processing orders.", 'ffl-hub'); ?></p>
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
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $upc = (string) ($row['upc'] ?? '');
                    $product_name = (string) ($row['product_name'] ?? 'Unknown product');
                    $total_qty = (int) ($row['total_qty'] ?? 0);
                    $line_count = (int) ($row['line_count'] ?? 0);
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($upc); ?></code></td>
                        <td><?php echo esc_html($product_name); ?></td>
                        <td><?php echo esc_html((string) $total_qty); ?></td>
                        <td><?php echo esc_html((string) $line_count); ?></td>
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
     *   totals_by_upc:array<int,array{upc:string,product_name:string,total_qty:int,line_count:int}>,
     *   entries:array<int,array{
     *     job_id:int,
     *     order_id:int,
     *     job_key:string,
     *     updated_at:string,
     *     job_status:string,
     *     upc:string,
     *     qty:int,
     *     product_name:string
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
        <h2><?php esc_html_e("Processing Order Line Entries", 'ffl-hub'); ?></h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Job ID', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Order', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Job Key', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Job Status', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Updated (UTC)', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('UPC', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Product Name', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Qty', 'ffl-hub'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry) : ?>
                    <?php
                    $order_id = (int) ($entry['order_id'] ?? 0);
                    $order_edit_url = admin_url('post.php?post=' . $order_id . '&action=edit');
                    ?>
                    <tr>
                        <td><?php echo esc_html((string) ((int) ($entry['job_id'] ?? 0))); ?></td>
                        <td>
                            <?php if ($order_id > 0) : ?>
                                <a href="<?php echo esc_url($order_edit_url); ?>">
                                    <?php echo esc_html('#' . (string) $order_id); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html('-'); ?>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html((string) ($entry['job_key'] ?? '')); ?></code></td>
                        <td><?php echo esc_html((string) ($entry['job_status'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($entry['updated_at'] ?? '')); ?></td>
                        <td><code><?php echo esc_html((string) ($entry['upc'] ?? '')); ?></code></td>
                        <td><?php echo esc_html((string) ($entry['product_name'] ?? 'Unknown product')); ?></td>
                        <td><?php echo esc_html((string) ((int) ($entry['qty'] ?? 0))); ?></td>
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
            .fflhub-davidsons-metric {
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
            .fflhub-davidsons-manual-status table code {
                word-break: break-all;
            }
        </style>
        <?php
    }
}
