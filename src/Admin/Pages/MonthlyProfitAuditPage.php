<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use DateTimeImmutable;
use FFLHub\Order\OrderProfitAuditMeta;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/** Read-only monthly rollup of the saved per-order profit audit. */
final class MonthlyProfitAuditPage
{
    private const PAGE_SLUG = 'fflhub-monthly-profit-audit';
    private const COUNTED_ORDER_STATUSES = ['wc-processing', 'wc-completed'];

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            AdminPage::get_page_slug(),
            __('Monthly Profit Audit', 'ffl-hub'),
            __('Monthly Profit', 'ffl-hub'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        wp_register_style('fflhub-monthly-profit-audit', false, [], FFLHUB_PLUGIN_VERSION);
        wp_enqueue_style('fflhub-monthly-profit-audit');
        wp_add_inline_style('fflhub-monthly-profit-audit', $this->inline_css());
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        $month = $this->selected_month();
        $rows = $this->orders_for_month($month);
        $totals = $this->summarize($rows);
        ?>
        <div class="wrap fflhub-monthly-profit">
            <div class="fflhub-monthly-profit__heading">
                <div>
                    <h1><?php esc_html_e('Monthly Profit Audit', 'ffl-hub'); ?></h1>
                    <p><?php esc_html_e('Order revenue minus distributor item cost, distributor freight, purchased labels, and processor fees.', 'ffl-hub'); ?></p>
                </div>
                <?php $this->render_month_filter($month); ?>
            </div>

            <?php $this->render_summary($totals, $month); ?>
            <?php $this->render_orders_table($rows); ?>
        </div>
        <?php
    }

    private function selected_month(): DateTimeImmutable
    {
        $raw = isset($_GET['month']) ? sanitize_text_field(wp_unslash((string) $_GET['month'])) : '';
        if (!preg_match('/^\d{4}-\d{2}$/', $raw)) {
            $raw = wp_date('Y-m', null, wp_timezone());
        }

        $month = DateTimeImmutable::createFromFormat('!Y-m', $raw, wp_timezone());
        return $month instanceof DateTimeImmutable && $month->format('Y-m') === $raw
            ? $month
            : new DateTimeImmutable('first day of this month 00:00:00', wp_timezone());
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function orders_for_month(DateTimeImmutable $month): array
    {
        $start = $month->setTime(0, 0, 0);
        $end = $start->modify('first day of next month');
        $ids = wc_get_orders([
            'type' => 'shop_order',
            'status' => self::COUNTED_ORDER_STATUSES,
            'date_created' => $start->getTimestamp() . '...' . ($end->getTimestamp() - 1),
            'limit' => -1,
            'return' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $rows = [];
        foreach ((array) $ids as $order_id) {
            $order = wc_get_order((int) $order_id);
            if (!($order instanceof WC_Order)) {
                continue;
            }

            $saved = (string) $order->get_meta('fflhub_order_profit_audit_version', true) === OrderProfitAuditMeta::version();
            $audit = $saved ? $this->saved_audit($order) : OrderProfitAuditMeta::preview_order($order);
            $revenue = $this->number($audit['revenue_total'] ?? 0);
            $profit = $this->number($audit['actual_profit_total'] ?? 0);
            $rows[] = [
                'order' => $order,
                'saved' => $saved,
                'revenue' => $revenue,
                'item_cost' => $this->number($audit['item_cost_total'] ?? 0),
                'distributor_shipping' => $this->number($audit['distributor_shipping_cost_total'] ?? 0),
                'label_shipping' => $this->number($audit['shipping_label_cost_total'] ?? 0),
                'shipping' => $this->number($audit['shipping_cost_total'] ?? 0),
                'processor_fee' => $this->number($audit['processor_fee_amount'] ?? 0),
                'customer_shipping' => $this->number($audit['customer_shipping_charge'] ?? 0),
                'profit' => $profit,
                'margin' => $revenue > 0.0 ? ($profit / $revenue) * 100.0 : 0.0,
                'distributors' => $this->distributor_summary($audit['item_cost_by_dist'] ?? []),
            ];
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function saved_audit(WC_Order $order): array
    {
        return [
            'revenue_total' => $order->get_meta('fflhub_order_revenue_total', true),
            'item_cost_total' => $order->get_meta('fflhub_order_item_cost_total', true),
            'item_cost_by_dist' => $this->decode_array($order->get_meta('fflhub_order_item_cost_by_dist', true)),
            'shipping_cost_total' => $order->get_meta('fflhub_order_shipping_cost_total', true),
            'distributor_shipping_cost_total' => $order->get_meta('fflhub_order_distributor_shipping_cost_total', true),
            'shipping_label_cost_total' => $order->get_meta('fflhub_order_shipping_label_cost_total', true),
            'customer_shipping_charge' => $order->get_meta('fflhub_order_customer_shipping_charge', true),
            'processor_fee_amount' => $order->get_meta('fflhub_order_processor_fee_amount', true),
            'actual_profit_total' => $order->get_meta('fflhub_order_actual_profit_total', true),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,float|int>
     */
    private function summarize(array $rows): array
    {
        $totals = [
            'orders' => count($rows),
            'saved' => 0,
            'revenue' => 0.0,
            'item_cost' => 0.0,
            'shipping' => 0.0,
            'processor_fee' => 0.0,
            'customer_shipping' => 0.0,
            'profit' => 0.0,
            'margin' => 0.0,
        ];

        foreach ($rows as $row) {
            $totals['saved'] += !empty($row['saved']) ? 1 : 0;
            foreach (['revenue', 'item_cost', 'shipping', 'processor_fee', 'customer_shipping', 'profit'] as $key) {
                $totals[$key] += (float) ($row[$key] ?? 0.0);
            }
        }
        $totals['margin'] = $totals['revenue'] > 0.0
            ? ($totals['profit'] / $totals['revenue']) * 100.0
            : 0.0;

        return $totals;
    }

    private function render_month_filter(DateTimeImmutable $month): void
    {
        $base = admin_url('admin.php?page=' . self::PAGE_SLUG);
        $previous = $month->modify('-1 month')->format('Y-m');
        $next = $month->modify('+1 month')->format('Y-m');
        ?>
        <form method="get" class="fflhub-month-filter">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
            <a class="button" href="<?php echo esc_url(add_query_arg('month', $previous, $base)); ?>" aria-label="<?php esc_attr_e('Previous month', 'ffl-hub'); ?>">&larr;</a>
            <label>
                <span class="screen-reader-text"><?php esc_html_e('Report month', 'ffl-hub'); ?></span>
                <input type="month" name="month" value="<?php echo esc_attr($month->format('Y-m')); ?>" />
            </label>
            <button type="submit" class="button button-primary"><?php esc_html_e('View Month', 'ffl-hub'); ?></button>
            <a class="button" href="<?php echo esc_url(add_query_arg('month', $next, $base)); ?>" aria-label="<?php esc_attr_e('Next month', 'ffl-hub'); ?>">&rarr;</a>
        </form>
        <?php
    }

    /** @param array<string,float|int> $totals */
    private function render_summary(array $totals, DateTimeImmutable $month): void
    {
        $profit = (float) $totals['profit'];
        ?>
        <section class="fflhub-month-summary">
            <div class="fflhub-month-summary__label">
                <span><?php echo esc_html($month->format('F Y')); ?></span>
                <strong><?php echo esc_html(sprintf('%d orders', (int) $totals['orders'])); ?></strong>
                <small><?php echo esc_html(sprintf('%d saved audits, %d previews', (int) $totals['saved'], (int) $totals['orders'] - (int) $totals['saved'])); ?></small>
            </div>
            <?php echo $this->summary_metric('Revenue', (float) $totals['revenue']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->summary_metric('Item Cost', (float) $totals['item_cost']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->summary_metric('Shipping', (float) $totals['shipping']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->summary_metric('Processor Fees', (float) $totals['processor_fee']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <div class="fflhub-month-summary__metric <?php echo $profit >= 0 ? 'is-profit' : 'is-loss'; ?>">
                <span><?php esc_html_e('Net Profit', 'ffl-hub'); ?></span>
                <strong><?php echo esc_html($this->money($profit)); ?></strong>
                <small><?php echo esc_html(number_format((float) $totals['margin'], 2) . '% margin'); ?></small>
            </div>
        </section>
        <?php
    }

    private function summary_metric(string $label, float $value): string
    {
        return '<div class="fflhub-month-summary__metric"><span>' . esc_html($label) . '</span><strong>'
            . esc_html($this->money($value)) . '</strong></div>';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function render_orders_table(array $rows): void
    {
        if (empty($rows)) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('No orders were created during this month.', 'ffl-hub') . '</p></div>';
            return;
        }
        ?>
        <div class="fflhub-month-table-wrap">
            <table class="widefat striped fflhub-month-table">
                <thead><tr>
                    <th><?php esc_html_e('Order', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Date / Customer', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Status', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Distributor Spend', 'ffl-hub'); ?></th>
                    <th class="num"><?php esc_html_e('Revenue', 'ffl-hub'); ?></th>
                    <th class="num"><?php esc_html_e('Item Cost', 'ffl-hub'); ?></th>
                    <th class="num"><?php esc_html_e('Dist. Freight', 'ffl-hub'); ?></th>
                    <th class="num"><?php esc_html_e('Labels', 'ffl-hub'); ?></th>
                    <th class="num"><?php esc_html_e('Total Shipping', 'ffl-hub'); ?></th>
                    <th class="num"><?php esc_html_e('Processor', 'ffl-hub'); ?></th>
                    <th class="num"><?php esc_html_e('Customer Shipping', 'ffl-hub'); ?></th>
                    <th class="num"><?php esc_html_e('Profit', 'ffl-hub'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php /** @var WC_Order $order */ $order = $row['order']; ?>
                    <?php $profit = (float) $row['profit']; ?>
                    <tr>
                        <td>
                            <a class="fflhub-order-link" href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html((string) $order->get_order_number()); ?></a>
                            <span class="fflhub-audit-source <?php echo !empty($row['saved']) ? 'is-saved' : 'is-preview'; ?>"><?php echo !empty($row['saved']) ? esc_html__('Saved audit', 'ffl-hub') : esc_html__('Preview', 'ffl-hub'); ?></span>
                        </td>
                        <td>
                            <strong><?php echo esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n('M j, Y g:i a') : '-'); ?></strong>
                            <span><?php echo esc_html($order->get_formatted_billing_full_name() ?: __('Guest', 'ffl-hub')); ?></span>
                        </td>
                        <td><span class="fflhub-status"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></span></td>
                        <td><?php echo $this->render_distributors((array) $row['distributors']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                        <td class="num"><?php echo esc_html($this->money((float) $row['revenue'])); ?></td>
                        <td class="num is-cost"><?php echo esc_html($this->money((float) $row['item_cost'])); ?></td>
                        <td class="num is-cost"><?php echo esc_html($this->money((float) $row['distributor_shipping'])); ?></td>
                        <td class="num is-cost"><?php echo esc_html($this->money((float) $row['label_shipping'])); ?></td>
                        <td class="num is-cost"><?php echo esc_html($this->money((float) $row['shipping'])); ?></td>
                        <td class="num is-cost"><?php echo esc_html($this->money((float) $row['processor_fee'])); ?></td>
                        <td class="num"><?php echo esc_html($this->money((float) $row['customer_shipping'])); ?></td>
                        <td class="num fflhub-profit-cell <?php echo $profit >= 0 ? 'is-positive' : 'is-negative'; ?>">
                            <strong><?php echo esc_html($this->money($profit)); ?></strong>
                            <span><?php echo esc_html(number_format((float) $row['margin'], 2) . '%'); ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** @param mixed $value @return array<int,array{id:string,cost:float}> */
    private function distributor_summary($value): array
    {
        $rows = is_array($value) ? $value : $this->decode_array($value);
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => strtoupper((string) ($row['dist_id'] ?? 'unknown')),
                'cost' => $this->number($row['item_cost'] ?? 0),
            ];
        }
        return $result;
    }

    /** @param array<int,array{id:string,cost:float}> $rows */
    private function render_distributors(array $rows): string
    {
        if (empty($rows)) {
            return '<span class="fflhub-muted">-</span>';
        }
        $html = '<div class="fflhub-dist-list">';
        foreach ($rows as $row) {
            $html .= '<span><b>' . esc_html((string) $row['id']) . '</b>' . esc_html($this->money((float) $row['cost'])) . '</span>';
        }
        return $html . '</div>';
    }

    /** @param mixed $value @return array<int|string,mixed> */
    private function decode_array($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param mixed $value */
    private function number($value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function money(float $value): string
    {
        return ($value < 0 ? '-$' : '$') . number_format(abs($value), 2, '.', ',');
    }

    private function inline_css(): string
    {
        return '.fflhub-monthly-profit{max-width:1800px}.fflhub-monthly-profit__heading{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin:18px 0}.fflhub-monthly-profit__heading h1{margin:0 0 5px}.fflhub-monthly-profit__heading p{margin:0;color:#646970}.fflhub-month-filter{display:flex;align-items:center;gap:7px}.fflhub-month-filter input[type=month]{min-height:32px}.fflhub-month-summary{display:grid;grid-template-columns:1.15fr repeat(5,minmax(140px,1fr));border:1px solid #c3c4c7;background:#fff;margin:0 0 18px}.fflhub-month-summary>div{padding:16px;border-right:1px solid #dcdcde}.fflhub-month-summary>div:last-child{border-right:0}.fflhub-month-summary span,.fflhub-month-summary small{display:block;color:#646970}.fflhub-month-summary strong{display:block;margin:4px 0;font-size:20px}.fflhub-month-summary__label strong{font-size:16px}.fflhub-month-summary__metric.is-profit{border-top:4px solid #16803c;background:#f0fdf4}.fflhub-month-summary__metric.is-loss{border-top:4px solid #b32d2e;background:#fef2f2}.fflhub-month-table-wrap{overflow:auto;border:1px solid #c3c4c7;background:#fff}.fflhub-month-table{border:0;min-width:1500px}.fflhub-month-table th{white-space:nowrap}.fflhub-month-table td{vertical-align:middle}.fflhub-month-table .num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.fflhub-order-link{display:block;font-size:15px;font-weight:700}.fflhub-audit-source{display:inline-block;margin-top:5px;padding:2px 6px;border-radius:3px;font-size:10px;font-weight:700;text-transform:uppercase}.fflhub-audit-source.is-saved{background:#dcfce7;color:#166534}.fflhub-audit-source.is-preview{background:#fef3c7;color:#92400e}.fflhub-month-table td:nth-child(2) strong,.fflhub-month-table td:nth-child(2) span{display:block;white-space:nowrap}.fflhub-month-table td:nth-child(2) span{margin-top:3px;color:#646970}.fflhub-status{display:inline-block;padding:4px 7px;border:1px solid #c3c4c7;border-radius:3px;background:#f6f7f7;white-space:nowrap}.fflhub-dist-list{display:flex;flex-direction:column;gap:4px;min-width:130px}.fflhub-dist-list span{display:flex;justify-content:space-between;gap:8px;padding:3px 6px;background:#eef4ff;border-left:3px solid #2271b1;font-size:11px}.fflhub-dist-list b{color:#1d4ed8}.fflhub-month-table .is-cost{color:#7f1d1d}.fflhub-profit-cell strong,.fflhub-profit-cell span{display:block}.fflhub-profit-cell span{font-size:11px;opacity:.8}.fflhub-profit-cell.is-positive{color:#166534;background:#f0fdf4}.fflhub-profit-cell.is-negative{color:#991b1b;background:#fef2f2}.fflhub-muted{color:#8c8f94}@media(max-width:1100px){.fflhub-monthly-profit__heading{align-items:flex-start;flex-direction:column}.fflhub-month-summary{grid-template-columns:repeat(3,1fr)}.fflhub-month-summary>div{border-bottom:1px solid #dcdcde}}';
    }
}
