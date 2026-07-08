<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Services\OfferSync\ProductStatePricingSql;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

final class ProductStateBulkPricingPage
{
    private const PAGE_SLUG = 'fflhub-product-state-bulk-pricing';
    private const NONCE_ACTION = 'fflhub_product_state_bulk_pricing';
    private const NONCE_FIELD = 'fflhub_product_state_bulk_pricing_nonce';
    private const FORM_ACTION = 'apply_fixed_profit_pricing';
    private const RESULT_TRANSIENT_PREFIX = 'fflhub_product_state_bulk_pricing_result_';
    private const PREVIEW_LIMIT = 100;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            AdminPage::get_page_slug(),
            __('Bulk Product Pricing', 'ffl-hub'),
            __('Bulk Product Pricing', 'ffl-hub'),
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

        ProductStateStore::ensure_schema();
        $this->maybe_handle_post();

        $filters = $this->read_filters_from_request($_GET);
        $fixed_profit = $this->read_fixed_profit_from_request($_GET, 5.0);
        $brand_options = $this->brand_options();
        $map_policy_options = $this->map_policy_options();
        $match_count = $this->matching_count($filters);
        $preview_rows = $this->preview_rows($filters);
        $result = $this->read_result();
        ?>
        <div class="wrap fflhub-bulk-pricing">
            <h1><?php esc_html_e('FFLHub Bulk Product Pricing', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Filter product_state rows by normalized brand and MAP visibility policy, then bulk-set their pricing controls. This updates product_state only, recalculates product_state pricing outputs, and marks changed rows for the Woo apply step.', 'ffl-hub'); ?>
            </p>

            <?php $this->render_result($result); ?>
            <?php $this->render_filter_form($filters, $fixed_profit, $brand_options, $map_policy_options, $match_count); ?>
            <?php $this->render_preview_table($preview_rows, $match_count); ?>
            <?php $this->render_styles(); ?>
        </div>
        <?php
    }

    private function maybe_handle_post(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'ffl-hub'));
        }

        $action = isset($_POST['fflhub_bulk_pricing_action'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_bulk_pricing_action']))
            : '';
        if ($action !== self::FORM_ACTION) {
            return;
        }

        if (
            !isset($_POST[self::NONCE_FIELD]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD])), self::NONCE_ACTION)
        ) {
            wp_die(esc_html__('Security check failed. Please refresh and try again.', 'ffl-hub'));
        }

        $filters = $this->read_filters_from_request($_POST);
        $fixed_profit = $this->read_fixed_profit_from_request($_POST, 5.0);
        $result = $this->apply_fixed_profit($filters, $fixed_profit);
        set_transient($this->result_transient_key(), $result, 5 * MINUTE_IN_SECONDS);

        $redirect_args = [
            'page' => self::PAGE_SLUG,
            'brand' => $filters['brand'],
            'map_policy' => $filters['map_policy'],
            'fixed_profit' => number_format($fixed_profit, 2, '.', ''),
            'ran' => self::FORM_ACTION,
        ];

        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function apply_fixed_profit(array $filters, float $fixed_profit): array
    {
        global $wpdb;

        $started = microtime(true);
        $result = [
            'ok' => true,
            'stage' => 'fixed_profit_apply',
            'brand' => $filters['brand'],
            'map_policy' => $filters['map_policy'],
            'fixed_profit' => number_format($fixed_profit, 4, '.', ''),
            'matched_rows' => 0,
            'pricing_control_rows' => 0,
            'recalculated_rows' => 0,
            'pricing_control_elapsed_ms' => '0.00',
            'recalculation_elapsed_ms' => '0.00',
            'elapsed_ms' => '0.00',
            'errors' => [],
        ];

        if (!$wpdb) {
            $result['ok'] = false;
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return $this->finish_result($result, $started);
        }

        if ($filters['brand'] === '' && $filters['map_policy'] === '') {
            $result['ok'] = false;
            $result['errors'][] = 'Choose at least one filter before applying a bulk pricing change.';
            return $this->finish_result($result, $started);
        }

        $fixed_profit = max(0.0, $fixed_profit);
        $table = ProductStateStore::table_name();
        $result['matched_rows'] = $this->matching_count($filters);

        $where = $this->where_sql($filters, 'ps');
        $profit_sql = number_format($fixed_profit, 4, '.', '');

        $t_controls = microtime(true);
        $control_sql = "
            UPDATE {$table} ps
            SET
                ps.pricing_mode = 'fixed_profit',
                ps.pricing_percent = NULL,
                ps.pricing_fixed_price = NULL,
                ps.pricing_fixed_profit = {$profit_sql},
                ps.updated_at = NOW(),
                ps.has_changed = 1
            WHERE {$where['sql']}
              AND NOT (
                    ps.pricing_mode <=> 'fixed_profit'
                AND ps.pricing_percent IS NULL
                AND ps.pricing_fixed_price IS NULL
                AND ps.pricing_fixed_profit <=> {$profit_sql}
              )
        ";
        $control_sql = $this->prepare_sql($control_sql, $where['params']);
        $control_rows = $wpdb->query($control_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result['pricing_control_elapsed_ms'] = number_format((microtime(true) - $t_controls) * 1000.0, 2, '.', '');

        if ($control_rows === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to update pricing controls: ' . (string) $wpdb->last_error;
            return $this->finish_result($result, $started);
        }

        $result['pricing_control_rows'] = is_numeric($control_rows) ? (int) $control_rows : 0;

        $t_recalc = microtime(true);
        $recalc_sql = $this->recalculate_filtered_outputs_sql($table, $where['sql']);
        $recalc_sql = $this->prepare_sql($recalc_sql, $where['params']);
        $recalc_rows = $wpdb->query($recalc_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result['recalculation_elapsed_ms'] = number_format((microtime(true) - $t_recalc) * 1000.0, 2, '.', '');

        if ($recalc_rows === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to recalculate pricing outputs: ' . (string) $wpdb->last_error;
            return $this->finish_result($result, $started);
        }

        $result['recalculated_rows'] = is_numeric($recalc_rows) ? (int) $recalc_rows : 0;
        $result['stage'] = 'complete';

        return $this->finish_result($result, $started);
    }

    private function recalculate_filtered_outputs_sql(string $table, string $where_sql): string
    {
        $computed_sell_price = ProductStatePricingSql::computed_sell_price_expr('ps', 'ps');
        $effective_map_policy = ProductStatePricingSql::effective_map_policy_expr('ps', 'ps');
        $effective_map_price = ProductStatePricingSql::effective_map_price_expr('ps', 'ps');
        $map_applicable = ProductStatePricingSql::map_applicable_expr('ps', 'ps');
        $public_regular_price = ProductStatePricingSql::public_regular_price_expr('ps', 'ps', $computed_sell_price, $effective_map_policy);
        $public_sale_price = ProductStatePricingSql::public_sale_price_expr('ps', 'ps', $computed_sell_price, $effective_map_policy);

        return "
            UPDATE {$table} ps
            SET
                ps.effective_map_price = {$effective_map_price},
                ps.computed_sell_price = {$computed_sell_price},
                ps.map_applicable = {$map_applicable},
                ps.public_regular_price = {$public_regular_price},
                ps.public_sale_price = {$public_sale_price},
                ps.updated_at = NOW(),
                ps.has_changed = 1
            WHERE {$where_sql}
              AND NOT (
                    ps.effective_map_price <=> {$effective_map_price}
                AND ps.computed_sell_price <=> {$computed_sell_price}
                AND ps.map_applicable <=> {$map_applicable}
                AND ps.public_regular_price <=> {$public_regular_price}
                AND ps.public_sale_price <=> {$public_sale_price}
              )
        ";
    }

    /**
     * @param array<string,mixed> $source
     * @return array{brand:string,map_policy:string}
     */
    private function read_filters_from_request(array $source): array
    {
        $brand = isset($source['brand'])
            ? sanitize_text_field(wp_unslash((string) $source['brand']))
            : '';
        $map_policy = isset($source['map_policy'])
            ? sanitize_text_field(wp_unslash((string) $source['map_policy']))
            : '';

        if (!array_key_exists($map_policy, $this->map_policy_options())) {
            $map_policy = '';
        }

        return [
            'brand' => trim($brand),
            'map_policy' => trim($map_policy),
        ];
    }

    /**
     * @param array<string,mixed> $source
     */
    private function read_fixed_profit_from_request(array $source, float $default): float
    {
        $raw = isset($source['fixed_profit'])
            ? sanitize_text_field(wp_unslash((string) $source['fixed_profit']))
            : '';
        if ($raw === '' || !is_numeric($raw)) {
            return $default;
        }

        return max(0.0, (float) $raw);
    }

    /**
     * @return array<string,string>
     */
    private function brand_options(): array
    {
        global $wpdb;

        $options = [];
        if (!$wpdb) {
            return $options;
        }

        $table = ProductStateStore::table_name();
        $rows = $wpdb->get_col("
            SELECT DISTINCT manufacturer_norm
            FROM {$table}
            WHERE status = 'active'
              AND manufacturer_norm IS NOT NULL
              AND manufacturer_norm <> ''
            ORDER BY manufacturer_norm ASC
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if (!is_array($rows)) {
            return $options;
        }

        foreach ($rows as $brand) {
            $brand = trim((string) $brand);
            if ($brand !== '') {
                $options[$brand] = $brand;
            }
        }

        return $options;
    }

    /**
     * @return array<string,string>
     */
    private function map_policy_options(): array
    {
        return [
            '' => __('All MAP policies', 'ffl-hub'),
            'none' => __('None', 'ffl-hub'),
            Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE => __('Add to Cart for Price', 'ffl-hub'),
            Options::MAP_POLICY_EMAIL_FOR_QUOTE => __('Email for Quote', 'ffl-hub'),
            Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART => __('No Email, No Add to Cart', 'ffl-hub'),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function matching_count(array $filters): int
    {
        global $wpdb;

        if (!$wpdb) {
            return 0;
        }

        $table = ProductStateStore::table_name();
        $where = $this->where_sql($filters, 'ps');
        $sql = $this->prepare_sql("SELECT COUNT(*) FROM {$table} ps WHERE {$where['sql']}", $where['params']);
        $count = $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    private function preview_rows(array $filters): array
    {
        global $wpdb;

        if (!$wpdb) {
            return [];
        }

        $table = ProductStateStore::table_name();
        $posts = $wpdb->posts;
        $where = $this->where_sql($filters, 'ps');
        $sql = "
            SELECT
                ps.product_id,
                p.post_title,
                ps.upc,
                ps.manufacturer_norm,
                ps.map_visibility_policy,
                ps.pricing_mode,
                ps.pricing_percent,
                ps.pricing_fixed_price,
                ps.pricing_fixed_profit,
                ps.dealer_price,
                ps.shipping_cost,
                ps.computed_sell_price,
                ps.public_regular_price,
                ps.public_sale_price,
                ps.has_changed
            FROM {$table} ps
            LEFT JOIN {$posts} p
                ON p.ID = ps.product_id
            WHERE {$where['sql']}
            ORDER BY ps.manufacturer_norm ASC, ps.product_id ASC
            LIMIT %d
        ";
        $params = array_merge($where['params'], [self::PREVIEW_LIMIT]);
        $sql = $this->prepare_sql($sql, $params);
        $rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{sql:string,params:array<int,mixed>}
     */
    private function where_sql(array $filters, string $alias): array
    {
        $conditions = ["{$alias}.status = 'active'"];
        $params = [];

        $brand = trim((string) ($filters['brand'] ?? ''));
        if ($brand !== '') {
            $conditions[] = "{$alias}.manufacturer_norm = %s";
            $params[] = $brand;
        }

        $map_policy = trim((string) ($filters['map_policy'] ?? ''));
        if ($map_policy !== '') {
            $conditions[] = "{$alias}.map_visibility_policy = %s";
            $params[] = $map_policy;
        }

        return [
            'sql' => implode(' AND ', $conditions),
            'params' => $params,
        ];
    }

    /**
     * @param array<int,mixed> $params
     */
    private function prepare_sql(string $sql, array $params): string
    {
        global $wpdb;

        if (!$wpdb || empty($params)) {
            return $sql;
        }

        return $wpdb->prepare($sql, ...$params); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,string> $brand_options
     * @param array<string,string> $map_policy_options
     */
    private function render_filter_form(array $filters, float $fixed_profit, array $brand_options, array $map_policy_options, int $match_count): void
    {
        ?>
        <div class="fflhub-pricing-panel">
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="fflhub-pricing-form">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />

                <label>
                    <span><?php esc_html_e('Brand', 'ffl-hub'); ?></span>
                    <select name="brand">
                        <option value=""><?php esc_html_e('All brands', 'ffl-hub'); ?></option>
                        <?php foreach ($brand_options as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['brand'], $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span><?php esc_html_e('MAP policy', 'ffl-hub'); ?></span>
                    <select name="map_policy">
                        <?php foreach ($map_policy_options as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['map_policy'], $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span><?php esc_html_e('Fixed profit', 'ffl-hub'); ?></span>
                    <input type="number" min="0" step="0.01" name="fixed_profit" value="<?php echo esc_attr(number_format($fixed_profit, 2, '.', '')); ?>" />
                </label>

                <?php submit_button(__('Preview Rows', 'ffl-hub'), 'secondary', 'submit', false); ?>
            </form>

            <div class="fflhub-pricing-summary">
                <strong><?php echo esc_html(number_format_i18n($match_count)); ?></strong>
                <span><?php esc_html_e('matching active product_state rows', 'ffl-hub'); ?></span>
            </div>

            <form method="post" action="" class="fflhub-pricing-apply">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="fflhub_bulk_pricing_action" value="<?php echo esc_attr(self::FORM_ACTION); ?>" />
                <input type="hidden" name="brand" value="<?php echo esc_attr($filters['brand']); ?>" />
                <input type="hidden" name="map_policy" value="<?php echo esc_attr($filters['map_policy']); ?>" />
                <input type="hidden" name="fixed_profit" value="<?php echo esc_attr(number_format($fixed_profit, 2, '.', '')); ?>" />
                <?php
                $apply_attrs = [
                    'onclick' => "return confirm('Apply fixed-profit pricing to the currently filtered product_state rows? This marks product_state rows changed but does not directly write Woo prices.');",
                ];
                if ($filters['brand'] === '' && $filters['map_policy'] === '') {
                    $apply_attrs['disabled'] = 'disabled';
                }
                submit_button(__('Apply Fixed Profit to Filtered Rows', 'ffl-hub'), 'primary', 'submit', false, $apply_attrs);
                ?>
                <?php if ($filters['brand'] === '' && $filters['map_policy'] === '') : ?>
                    <p class="description"><?php esc_html_e('Choose at least one filter before applying a bulk change.', 'ffl-hub'); ?></p>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function render_preview_table(array $rows, int $match_count): void
    {
        ?>
        <h2><?php esc_html_e('Preview', 'ffl-hub'); ?></h2>
        <p class="description">
            <?php echo esc_html(sprintf('Showing up to %d matching rows.', self::PREVIEW_LIMIT)); ?>
        </p>
        <table class="widefat striped fflhub-pricing-preview">
            <thead>
                <tr>
                    <th><?php esc_html_e('Product', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('UPC', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Brand', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('MAP Policy', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Pricing', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Cost', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Computed', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Changed', 'ffl-hub'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)) : ?>
                    <tr>
                        <td colspan="8"><?php esc_html_e('No matching rows.', 'ffl-hub'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html((string) ($row['post_title'] ?? '')); ?></strong><br />
                                <code>#<?php echo esc_html((string) (int) ($row['product_id'] ?? 0)); ?></code>
                            </td>
                            <td><code><?php echo esc_html((string) ($row['upc'] ?? '')); ?></code></td>
                            <td><?php echo esc_html((string) ($row['manufacturer_norm'] ?? '')); ?></td>
                            <td><?php echo esc_html($this->map_policy_label((string) ($row['map_visibility_policy'] ?? ''))); ?></td>
                            <td>
                                <?php echo esc_html($this->pricing_mode_label((string) ($row['pricing_mode'] ?? ''))); ?><br />
                                <span class="fflhub-muted">
                                    <?php echo esc_html($this->pricing_value_summary($row)); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo esc_html('Dealer ' . $this->money($row['dealer_price'] ?? null)); ?><br />
                                <?php echo esc_html('Ship ' . $this->money($row['shipping_cost'] ?? null)); ?>
                            </td>
                            <td>
                                <?php echo esc_html('Sell ' . $this->money($row['computed_sell_price'] ?? null)); ?><br />
                                <span class="fflhub-muted">
                                    <?php echo esc_html('Regular ' . $this->money($row['public_regular_price'] ?? null) . ' / Sale ' . $this->money($row['public_sale_price'] ?? null)); ?>
                                </span>
                            </td>
                            <td><?php echo !empty($row['has_changed']) ? esc_html__('Yes', 'ffl-hub') : esc_html__('No', 'ffl-hub'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if ($match_count > self::PREVIEW_LIMIT) : ?>
            <p class="description">
                <?php echo esc_html(sprintf('%d additional rows match this filter.', $match_count - self::PREVIEW_LIMIT)); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * @param array<string,mixed>|null $result
     */
    private function render_result(?array $result): void
    {
        if ($result === null) {
            return;
        }

        $has_errors = !empty($result['errors']) && is_array($result['errors']);
        ?>
        <div class="notice <?php echo esc_attr($has_errors ? 'notice-error' : 'notice-success'); ?>">
            <p><strong><?php esc_html_e('Bulk pricing update complete.', 'ffl-hub'); ?></strong></p>
            <ul style="list-style:disc;margin-left:20px;">
                <li><?php echo esc_html(sprintf('Stage: %s', (string) ($result['stage'] ?? ''))); ?></li>
                <li><?php echo esc_html(sprintf('Brand filter: %s', (string) ($result['brand'] ?: 'All'))); ?></li>
                <li><?php echo esc_html(sprintf('MAP policy filter: %s', $this->map_policy_label((string) ($result['map_policy'] ?? '')))); ?></li>
                <li><?php echo esc_html(sprintf('Fixed profit: $%s', (string) ($result['fixed_profit'] ?? '0.0000'))); ?></li>
                <li><?php echo esc_html(sprintf('Matched rows: %d', (int) ($result['matched_rows'] ?? 0))); ?></li>
                <li><?php echo esc_html(sprintf('Pricing controls changed: %d', (int) ($result['pricing_control_rows'] ?? 0))); ?></li>
                <li><?php echo esc_html(sprintf('Outputs recalculated: %d', (int) ($result['recalculated_rows'] ?? 0))); ?></li>
                <li><?php echo esc_html(sprintf('Controls runtime: %s ms', (string) ($result['pricing_control_elapsed_ms'] ?? '0.00'))); ?></li>
                <li><?php echo esc_html(sprintf('Recalculation runtime: %s ms', (string) ($result['recalculation_elapsed_ms'] ?? '0.00'))); ?></li>
                <li><?php echo esc_html(sprintf('Runtime: %s ms', (string) ($result['elapsed_ms'] ?? '0.00'))); ?></li>
            </ul>
            <?php if ($has_errors) : ?>
                <p><strong><?php esc_html_e('Errors:', 'ffl-hub'); ?></strong></p>
                <ul style="list-style:disc;margin-left:20px;">
                    <?php foreach ($result['errors'] as $message) : ?>
                        <li><?php echo esc_html((string) $message); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    private function map_policy_label(string $policy): string
    {
        $options = $this->map_policy_options();
        return $options[$policy] ?? ($policy !== '' ? $policy : __('All MAP policies', 'ffl-hub'));
    }

    private function pricing_mode_label(string $mode): string
    {
        $labels = [
            'global_percent' => __('Global Percent', 'ffl-hub'),
            'fixed_percent' => __('Fixed Percent', 'ffl-hub'),
            'fixed_price' => __('Fixed Price', 'ffl-hub'),
            'fixed_profit' => __('Fixed Profit', 'ffl-hub'),
            'map_price' => __('MAP Price', 'ffl-hub'),
        ];

        return $labels[$mode] ?? ($mode !== '' ? $mode : __('Unset', 'ffl-hub'));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function pricing_value_summary(array $row): string
    {
        $mode = (string) ($row['pricing_mode'] ?? '');
        if ($mode === 'fixed_profit') {
            return 'Profit ' . $this->money($row['pricing_fixed_profit'] ?? null);
        }

        if ($mode === 'fixed_price') {
            return 'Price ' . $this->money($row['pricing_fixed_price'] ?? null);
        }

        if ($mode === 'fixed_percent' || $mode === 'global_percent') {
            $percent = $this->float_or_null($row['pricing_percent'] ?? null);
            return $percent === null ? 'Percent unset' : number_format($percent, 2, '.', '') . '%';
        }

        return '';
    }

    private function money($value): string
    {
        $float = $this->float_or_null($value);
        return $float === null ? '-' : '$' . number_format($float, 2, '.', '');
    }

    private function float_or_null($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function finish_result(array $result, float $started): array
    {
        $result['elapsed_ms'] = number_format((microtime(true) - $started) * 1000.0, 2, '.', '');

        return $result;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function read_result(): ?array
    {
        $result = get_transient($this->result_transient_key());
        if (!is_array($result)) {
            return null;
        }

        delete_transient($this->result_transient_key());

        return $result;
    }

    private function result_transient_key(): string
    {
        return self::RESULT_TRANSIENT_PREFIX . (string) get_current_user_id();
    }

    private function render_styles(): void
    {
        ?>
        <style>
            .fflhub-pricing-panel {
                max-width: 1160px;
                margin: 18px 0;
                padding: 16px;
                background: #fff;
                border: 1px solid #dcdcde;
            }
            .fflhub-pricing-form,
            .fflhub-pricing-apply {
                display: flex;
                flex-wrap: wrap;
                align-items: flex-end;
                gap: 12px;
            }
            .fflhub-pricing-form label {
                display: flex;
                flex-direction: column;
                gap: 4px;
                min-width: 220px;
                font-weight: 600;
            }
            .fflhub-pricing-form select,
            .fflhub-pricing-form input[type="number"] {
                min-width: 220px;
            }
            .fflhub-pricing-summary {
                margin: 14px 0;
                padding: 10px 12px;
                background: #f6f7f7;
                border-left: 4px solid #2271b1;
            }
            .fflhub-pricing-summary strong {
                font-size: 20px;
                margin-right: 6px;
            }
            .fflhub-pricing-preview {
                max-width: 1160px;
            }
            .fflhub-pricing-preview td,
            .fflhub-pricing-preview th {
                vertical-align: top;
            }
            .fflhub-muted {
                color: #646970;
            }
        </style>
        <?php
    }
}
