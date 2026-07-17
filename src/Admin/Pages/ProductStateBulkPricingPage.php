<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Services\OfferSync\ProductStatePricingSql;
use FFLHub\Distributor\Services\OfferSync\ProductStateWooApplyService;
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
    private const FORM_ACTION = 'apply_bulk_pricing_controls';
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
        $pricing = $this->read_pricing_from_request($_GET);
        $quote_free_shipping_action = $this->read_quote_free_shipping_action_from_request($_GET);
        $apply_woo_now = $this->read_apply_woo_now_from_request($_GET, true);
        $brand_options = $this->brand_options();
        $map_policy_options = $this->map_policy_options();
        $match_count = $this->matching_count($filters);
        $preview_rows = $this->preview_rows($filters);
        $result = $this->read_result();
        ?>
        <div class="wrap fflhub-bulk-pricing">
            <h1><?php esc_html_e('FFLHub Bulk Product Pricing', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Filter product_state rows by WooCommerce brand, MAP policy, effective MAP price, dropship status, and FFL status, then bulk-set their pricing controls. This updates product_state, recalculates product_state pricing outputs, and can immediately save matching Woo products.', 'ffl-hub'); ?>
            </p>

            <?php $this->render_result($result); ?>
            <?php $this->render_filter_form($filters, $pricing, $quote_free_shipping_action, $apply_woo_now, $brand_options, $map_policy_options, $match_count); ?>
            <?php $this->render_preview_table($preview_rows, $match_count); ?>
            <?php $this->render_styles(); ?>
            <?php $this->render_scripts(); ?>
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
        $pricing = $this->read_pricing_from_request($_POST);
        $quote_free_shipping_action = $this->read_quote_free_shipping_action_from_request($_POST);
        $apply_woo_now = $this->read_apply_woo_now_from_request($_POST, true);
        $result = $this->apply_pricing_controls($filters, $pricing, $quote_free_shipping_action, $apply_woo_now);
        set_transient($this->result_transient_key(), $result, 5 * MINUTE_IN_SECONDS);

        $redirect_args = [
            'page' => self::PAGE_SLUG,
            'brand_id' => $filters['brand_id'],
            'map_policy' => $filters['map_policy'],
            'map_price_status' => $filters['map_price_status'],
            'dropship_status' => $filters['dropship_status'],
            'ffl_status' => $filters['ffl_status'],
            'pricing_mode' => $pricing['mode'],
            'pricing_value' => $pricing['value_input'],
            'fixed_profit_shipping_mode' => $pricing['fixed_profit_shipping_mode'],
            'quote_free_shipping_override_action' => $quote_free_shipping_action,
            'apply_woo_now' => $apply_woo_now ? '1' : '0',
            'ran' => self::FORM_ACTION,
        ];

        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function apply_pricing_controls(array $filters, array $pricing, string $quote_free_shipping_action, bool $apply_woo_now): array
    {
        global $wpdb;

        $started = microtime(true);
        $result = [
            'ok' => true,
            'stage' => 'pricing_controls_apply',
            'brand_id' => $filters['brand_id'],
            'brand_label' => $this->brand_label((int) $filters['brand_id']),
            'map_policy' => $filters['map_policy'],
            'map_price_status' => $filters['map_price_status'],
            'dropship_status' => $filters['dropship_status'],
            'ffl_status' => $filters['ffl_status'],
            'pricing_mode' => $pricing['mode'],
            'pricing_mode_label' => $this->pricing_mode_label($pricing['mode']),
            'pricing_value' => $pricing['value'] === null ? '' : number_format((float) $pricing['value'], 4, '.', ''),
            'fixed_profit_shipping_mode' => $pricing['fixed_profit_shipping_mode'],
            'fixed_profit_shipping_mode_label' => $this->fixed_profit_shipping_mode_label($pricing['fixed_profit_shipping_mode']),
            'quote_free_shipping_action' => $quote_free_shipping_action,
            'quote_free_shipping_action_label' => $this->quote_free_shipping_action_label($quote_free_shipping_action),
            'matched_rows' => 0,
            'pricing_control_rows' => 0,
            'quote_free_shipping_rows' => 0,
            'recalculated_rows' => 0,
            'apply_woo_now' => $apply_woo_now ? 1 : 0,
            'woo_apply' => null,
            'pricing_control_elapsed_ms' => '0.00',
            'quote_free_shipping_elapsed_ms' => '0.00',
            'recalculation_elapsed_ms' => '0.00',
            'elapsed_ms' => '0.00',
            'errors' => [],
        ];

        if (!$wpdb) {
            $result['ok'] = false;
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return $this->finish_result($result, $started);
        }

        if (!$this->has_active_filter($filters)) {
            $result['ok'] = false;
            $result['errors'][] = 'Choose at least one filter before applying a bulk pricing change.';
            return $this->finish_result($result, $started);
        }

        $mode = $pricing['mode'];
        $value = $pricing['value'];
        if ($this->pricing_mode_requires_value($mode) && $value === null) {
            $result['ok'] = false;
            $result['errors'][] = 'The selected pricing mode requires a numeric value.';
            return $this->finish_result($result, $started);
        }

        if ($mode === 'fixed_price' && (float) $value <= 0.0) {
            $result['ok'] = false;
            $result['errors'][] = 'Fixed Price mode requires a value greater than zero.';
            return $this->finish_result($result, $started);
        }

        $table = ProductStateStore::table_name();
        $result['matched_rows'] = $this->matching_count($filters);

        $where = $this->where_sql($filters, 'ps');
        $control_expr = $this->pricing_control_sql_for_mode($mode, $value, $pricing['fixed_profit_shipping_mode']);
        $fixed_profit_shipping_compare = ($mode === 'fixed_profit')
            ? "\n                AND ps.fixed_profit_shipping_mode <=> {$control_expr['fixed_profit_shipping_mode']}"
            : '';

        $t_controls = microtime(true);
        $control_sql = "
            UPDATE {$table} ps
            SET
                ps.pricing_mode = '{$control_expr['mode']}',
                ps.pricing_percent = {$control_expr['pricing_percent']},
                ps.pricing_fixed_price = {$control_expr['pricing_fixed_price']},
                ps.pricing_fixed_profit = {$control_expr['pricing_fixed_profit']},
                ps.fixed_profit_shipping_mode = {$control_expr['fixed_profit_shipping_mode']},
                ps.updated_at = NOW(),
                ps.has_changed = 1
            WHERE {$where['sql']}
              AND NOT (
                    ps.pricing_mode <=> '{$control_expr['mode']}'
                AND ps.pricing_percent <=> {$control_expr['pricing_percent']}
                AND ps.pricing_fixed_price <=> {$control_expr['pricing_fixed_price']}
                AND ps.pricing_fixed_profit <=> {$control_expr['pricing_fixed_profit']}
                {$fixed_profit_shipping_compare}
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

        if ($quote_free_shipping_action !== '') {
            $target_quote_free_shipping = $quote_free_shipping_action === 'enable' ? 1 : 0;
            $t_quote_free_shipping = microtime(true);
            $quote_free_shipping_sql = "
                UPDATE {$table} ps
                SET
                    ps.quote_free_shipping_override = {$target_quote_free_shipping},
                    ps.updated_at = NOW(),
                    ps.has_changed = 1
                WHERE {$where['sql']}
                  AND NOT (ps.quote_free_shipping_override <=> {$target_quote_free_shipping})
            ";
            $quote_free_shipping_sql = $this->prepare_sql($quote_free_shipping_sql, $where['params']);
            $quote_free_shipping_rows = $wpdb->query($quote_free_shipping_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result['quote_free_shipping_elapsed_ms'] = number_format((microtime(true) - $t_quote_free_shipping) * 1000.0, 2, '.', '');

            if ($quote_free_shipping_rows === false) {
                $result['ok'] = false;
                $result['errors'][] = 'Failed to update quote free shipping override: ' . (string) $wpdb->last_error;
                return $this->finish_result($result, $started);
            }

            $result['quote_free_shipping_rows'] = is_numeric($quote_free_shipping_rows) ? (int) $quote_free_shipping_rows : 0;
        }

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

        if ($apply_woo_now) {
            $result['stage'] = 'woo_apply';
            $result['woo_apply'] = ProductStateWooApplyService::apply_product_ids($this->matching_product_ids($filters));
            if (empty($result['woo_apply']['ok'])) {
                $result['ok'] = false;
                $result['errors'][] = 'Product state updated, but Woo apply reported errors.';
            }
        }

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
     * @return array{brand_id:int,map_policy:string,map_price_status:string,dropship_status:string,ffl_status:string}
     */
    private function read_filters_from_request(array $source): array
    {
        $brand_id = isset($source['brand_id'])
            ? absint($source['brand_id'])
            : 0;
        $map_policy = isset($source['map_policy'])
            ? sanitize_text_field(wp_unslash((string) $source['map_policy']))
            : '';
        $map_price_status = isset($source['map_price_status'])
            ? sanitize_text_field(wp_unslash((string) $source['map_price_status']))
            : '';
        $dropship_status = isset($source['dropship_status'])
            ? sanitize_text_field(wp_unslash((string) $source['dropship_status']))
            : '';
        $ffl_status = isset($source['ffl_status'])
            ? sanitize_text_field(wp_unslash((string) $source['ffl_status']))
            : '';

        if (!array_key_exists($map_policy, $this->map_policy_options())) {
            $map_policy = '';
        }
        if (!array_key_exists($map_price_status, $this->map_price_status_options())) {
            $map_price_status = '';
        }
        if (!array_key_exists($dropship_status, $this->dropship_status_options())) {
            $dropship_status = '';
        }
        if (!array_key_exists($ffl_status, $this->ffl_status_options())) {
            $ffl_status = '';
        }

        return [
            'brand_id' => $brand_id,
            'map_policy' => trim($map_policy),
            'map_price_status' => trim($map_price_status),
            'dropship_status' => trim($dropship_status),
            'ffl_status' => trim($ffl_status),
        ];
    }

    /**
     * @param array<string,mixed> $source
     * @return array{mode:string,value:?float,value_input:string,fixed_profit_shipping_mode:string}
     */
    private function read_pricing_from_request(array $source): array
    {
        $mode = isset($source['pricing_mode'])
            ? sanitize_text_field(wp_unslash((string) $source['pricing_mode']))
            : '';

        // Keep old fixed_profit URLs useful while the page moves to the
        // generic pricing-control shape.
        if ($mode === '' && isset($source['fixed_profit'])) {
            $mode = 'fixed_profit';
        }

        if (!array_key_exists($mode, $this->pricing_mode_options())) {
            $mode = 'fixed_profit';
        }

        $raw = isset($source['pricing_value'])
            ? sanitize_text_field(wp_unslash((string) $source['pricing_value']))
            : '';
        if ($raw === '' && isset($source['fixed_profit'])) {
            $raw = sanitize_text_field(wp_unslash((string) $source['fixed_profit']));
        }

        if ($raw === '' && $mode === 'fixed_profit') {
            $raw = '5.00';
        } elseif ($raw === '' && $mode === 'fixed_percent') {
            $raw = number_format((float) Options::get_global_markup(), 2, '.', '');
        }

        $value = ($raw !== '' && is_numeric($raw)) ? max(0.0, (float) $raw) : null;
        $fixed_profit_shipping_mode = isset($source['fixed_profit_shipping_mode'])
            ? sanitize_text_field(wp_unslash((string) $source['fixed_profit_shipping_mode']))
            : 'included';
        if (!array_key_exists($fixed_profit_shipping_mode, $this->fixed_profit_shipping_mode_options())) {
            $fixed_profit_shipping_mode = 'included';
        }

        return [
            'mode' => $mode,
            'value' => $value,
            'value_input' => $value === null ? '' : number_format($value, 2, '.', ''),
            'fixed_profit_shipping_mode' => $fixed_profit_shipping_mode,
        ];
    }

    /**
     * @param array<string,mixed> $source
     */
    private function read_quote_free_shipping_action_from_request(array $source): string
    {
        $action = isset($source['quote_free_shipping_override_action'])
            ? sanitize_text_field(wp_unslash((string) $source['quote_free_shipping_override_action']))
            : '';

        return array_key_exists($action, $this->quote_free_shipping_action_options()) ? $action : '';
    }

    private function pricing_mode_requires_value(string $mode): bool
    {
        return in_array($mode, ['fixed_percent', 'fixed_price', 'fixed_profit'], true);
    }

    /**
     * @return array{mode:string,pricing_percent:string,pricing_fixed_price:string,pricing_fixed_profit:string,fixed_profit_shipping_mode:string}
     */
    private function pricing_control_sql_for_mode(string $mode, ?float $value, string $fixed_profit_shipping_mode): array
    {
        $mode = array_key_exists($mode, $this->pricing_mode_options()) ? $mode : 'fixed_profit';
        $value_sql = $value === null ? 'NULL' : number_format(max(0.0, $value), 4, '.', '');
        if (!array_key_exists($fixed_profit_shipping_mode, $this->fixed_profit_shipping_mode_options())) {
            $fixed_profit_shipping_mode = 'included';
        }

        $columns = [
            'mode' => esc_sql($mode),
            'pricing_percent' => 'NULL',
            'pricing_fixed_price' => 'NULL',
            'pricing_fixed_profit' => 'NULL',
            'fixed_profit_shipping_mode' => 'ps.fixed_profit_shipping_mode',
        ];

        if ($mode === 'global_percent') {
            $columns['pricing_percent'] = number_format(max(0.0, (float) Options::get_global_markup()), 4, '.', '');
        } elseif ($mode === 'fixed_percent') {
            $columns['pricing_percent'] = $value_sql;
        } elseif ($mode === 'fixed_price') {
            $columns['pricing_fixed_price'] = $value_sql;
        } elseif ($mode === 'fixed_profit') {
            $columns['pricing_fixed_profit'] = $value_sql;
            $columns['fixed_profit_shipping_mode'] = "'" . esc_sql($fixed_profit_shipping_mode) . "'";
        }

        return $columns;
    }

    /**
     * @param array<string,mixed> $source
     */
    private function read_apply_woo_now_from_request(array $source, bool $default): bool
    {
        if (!array_key_exists('apply_woo_now', $source)) {
            return $default;
        }

        return (string) $source['apply_woo_now'] === '1';
    }

    /**
     * @return array<int,string>
     */
    private function brand_options(): array
    {
        global $wpdb;

        $options = [];
        if (!$wpdb) {
            return $options;
        }

        $table = ProductStateStore::table_name();
        $rows = $wpdb->get_results("
            SELECT
                t.term_id,
                t.name,
                COUNT(DISTINCT ps.product_id) AS product_count
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_id = t.term_id
               AND tt.taxonomy = 'product_brand'
            INNER JOIN {$wpdb->term_relationships} tr
                ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN {$table} ps
                ON ps.product_id = tr.object_id
               AND ps.status = 'active'
            GROUP BY t.term_id, t.name
            ORDER BY t.name ASC
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if (!is_array($rows)) {
            return $options;
        }

        foreach ($rows as $row) {
            $term_id = isset($row->term_id) ? (int) $row->term_id : 0;
            $name = isset($row->name) ? trim((string) $row->name) : '';
            $count = isset($row->product_count) ? (int) $row->product_count : 0;
            if ($term_id > 0 && $name !== '') {
                $options[$term_id] = sprintf('%s (%d)', $name, $count);
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
     * @return array<string,string>
     */
    private function dropship_status_options(): array
    {
        return [
            '' => __('All dropship statuses', 'ffl-hub'),
            'enabled' => __('Dropship enabled', 'ffl-hub'),
            'disabled' => __('Dropship disabled', 'ffl-hub'),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function map_price_status_options(): array
    {
        return [
            '' => __('All MAP price statuses', 'ffl-hub'),
            'has_map' => __('Has effective MAP price', 'ffl-hub'),
            'no_map' => __('No effective MAP price', 'ffl-hub'),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function ffl_status_options(): array
    {
        return [
            '' => __('All FFL statuses', 'ffl-hub'),
            'required' => __('FFL required', 'ffl-hub'),
            'not_required' => __('No FFL required', 'ffl-hub'),
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
     * @return int[]
     */
    private function matching_product_ids(array $filters): array
    {
        global $wpdb;

        if (!$wpdb) {
            return [];
        }

        $table = ProductStateStore::table_name();
        $where = $this->where_sql($filters, 'ps');
        $sql = $this->prepare_sql(
            "SELECT ps.product_id FROM {$table} ps WHERE {$where['sql']} ORDER BY ps.product_id ASC",
            $where['params']
        );
        $ids = $wpdb->get_col($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []))));
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
        $postmeta = $wpdb->postmeta;
        $where = $this->where_sql($filters, 'ps');
        $sql = "
            SELECT
                ps.product_id,
                p.post_title,
                p.post_status,
                ps.upc,
                (
                    SELECT GROUP_CONCAT(DISTINCT t_brand.name ORDER BY t_brand.name SEPARATOR ', ')
                    FROM {$wpdb->term_relationships} tr_brand
                    INNER JOIN {$wpdb->term_taxonomy} tt_brand
                        ON tt_brand.term_taxonomy_id = tr_brand.term_taxonomy_id
                       AND tt_brand.taxonomy = 'product_brand'
                    INNER JOIN {$wpdb->terms} t_brand
                        ON t_brand.term_id = tt_brand.term_id
                    WHERE tr_brand.object_id = ps.product_id
                ) AS woo_brand,
                ps.manufacturer_norm,
                ps.map_visibility_policy,
                ps.map_price,
                ps.effective_map_price,
                ps.map_applicable,
                ps.pricing_mode,
                ps.pricing_percent,
                ps.pricing_fixed_price,
                ps.pricing_fixed_profit,
                ps.fixed_profit_shipping_mode,
                ps.quote_free_shipping_override,
                ps.distributor_id,
                ps.distributor_product_id,
                ps.qty,
                ps.stock_status,
                ps.dealer_price,
                ps.shipping_cost,
                ps.landed_cost,
                ps.computed_sell_price,
                ps.public_regular_price,
                ps.public_sale_price,
                ps.ffl_required,
                ps.sot_required,
                ps.dropship_enabled,
                ps.has_changed,
                ps.woo_synced_at,
                pm_sku.meta_value AS woo_sku,
                pm_regular.meta_value AS woo_regular_price,
                pm_sale.meta_value AS woo_sale_price,
                pm_price.meta_value AS woo_active_price,
                pm_stock.meta_value AS woo_stock_qty,
                pm_stock_status.meta_value AS woo_stock_status
            FROM {$table} ps
            LEFT JOIN {$posts} p
                ON p.ID = ps.product_id
            LEFT JOIN {$postmeta} pm_sku
                ON pm_sku.post_id = ps.product_id
               AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$postmeta} pm_regular
                ON pm_regular.post_id = ps.product_id
               AND pm_regular.meta_key = '_regular_price'
            LEFT JOIN {$postmeta} pm_sale
                ON pm_sale.post_id = ps.product_id
               AND pm_sale.meta_key = '_sale_price'
            LEFT JOIN {$postmeta} pm_price
                ON pm_price.post_id = ps.product_id
               AND pm_price.meta_key = '_price'
            LEFT JOIN {$postmeta} pm_stock
                ON pm_stock.post_id = ps.product_id
               AND pm_stock.meta_key = '_stock'
            LEFT JOIN {$postmeta} pm_stock_status
                ON pm_stock_status.post_id = ps.product_id
               AND pm_stock_status.meta_key = '_stock_status'
            WHERE {$where['sql']}
            ORDER BY woo_brand ASC, ps.product_id ASC
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

        $brand_id = (int) ($filters['brand_id'] ?? 0);
        if ($brand_id > 0) {
            global $wpdb;
            $conditions[] = "EXISTS (
                SELECT 1
                FROM {$wpdb->term_relationships} tr_brand_filter
                INNER JOIN {$wpdb->term_taxonomy} tt_brand_filter
                    ON tt_brand_filter.term_taxonomy_id = tr_brand_filter.term_taxonomy_id
                   AND tt_brand_filter.taxonomy = 'product_brand'
                   AND tt_brand_filter.term_id = %d
                WHERE tr_brand_filter.object_id = {$alias}.product_id
            )";
            $params[] = $brand_id;
        }

        $map_policy = trim((string) ($filters['map_policy'] ?? ''));
        if ($map_policy !== '') {
            $conditions[] = "{$alias}.map_visibility_policy = %s";
            $params[] = $map_policy;
        }

        $map_price_status = trim((string) ($filters['map_price_status'] ?? ''));
        if ($map_price_status === 'has_map') {
            $conditions[] = "{$alias}.effective_map_price IS NOT NULL AND {$alias}.effective_map_price > 0";
        } elseif ($map_price_status === 'no_map') {
            $conditions[] = "({$alias}.effective_map_price IS NULL OR {$alias}.effective_map_price <= 0)";
        }

        $dropship_status = trim((string) ($filters['dropship_status'] ?? ''));
        if ($dropship_status === 'enabled') {
            $conditions[] = "COALESCE({$alias}.dropship_enabled, 0) = 1";
        } elseif ($dropship_status === 'disabled') {
            $conditions[] = "COALESCE({$alias}.dropship_enabled, 0) = 0";
        }

        $ffl_status = trim((string) ($filters['ffl_status'] ?? ''));
        if ($ffl_status === 'required') {
            $conditions[] = "COALESCE({$alias}.ffl_required, 0) = 1";
        } elseif ($ffl_status === 'not_required') {
            $conditions[] = "COALESCE({$alias}.ffl_required, 0) = 0";
        }

        return [
            'sql' => implode(' AND ', $conditions),
            'params' => $params,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function has_active_filter(array $filters): bool
    {
        return (int) ($filters['brand_id'] ?? 0) > 0
            || trim((string) ($filters['map_policy'] ?? '')) !== ''
            || trim((string) ($filters['map_price_status'] ?? '')) !== ''
            || trim((string) ($filters['dropship_status'] ?? '')) !== ''
            || trim((string) ($filters['ffl_status'] ?? '')) !== '';
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
     * @param array<int,string> $brand_options
     * @param array<string,string> $map_policy_options
     */
    private function render_filter_form(array $filters, array $pricing, string $quote_free_shipping_action, bool $apply_woo_now, array $brand_options, array $map_policy_options, int $match_count): void
    {
        $mode = (string) ($pricing['mode'] ?? 'fixed_profit');
        $value_input = (string) ($pricing['value_input'] ?? '');
        $fixed_profit_shipping_mode = (string) ($pricing['fixed_profit_shipping_mode'] ?? 'included');
        ?>
        <div class="fflhub-pricing-panel">
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="fflhub-pricing-form">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />

                <label>
                    <span><?php esc_html_e('Brand', 'ffl-hub'); ?></span>
                    <select name="brand_id">
                        <option value=""><?php esc_html_e('All brands', 'ffl-hub'); ?></option>
                        <?php foreach ($brand_options as $value => $label) : ?>
                            <option value="<?php echo esc_attr((string) $value); ?>" <?php selected((int) $filters['brand_id'], (int) $value); ?>>
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
                    <span><?php esc_html_e('MAP price', 'ffl-hub'); ?></span>
                    <select name="map_price_status">
                        <?php foreach ($this->map_price_status_options() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['map_price_status'], $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span><?php esc_html_e('Dropship status', 'ffl-hub'); ?></span>
                    <select name="dropship_status">
                        <?php foreach ($this->dropship_status_options() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['dropship_status'], $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span><?php esc_html_e('FFL status', 'ffl-hub'); ?></span>
                    <select name="ffl_status">
                        <?php foreach ($this->ffl_status_options() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['ffl_status'], $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span><?php esc_html_e('Pricing mode', 'ffl-hub'); ?></span>
                    <select name="pricing_mode" class="fflhub-pricing-mode-select">
                        <?php foreach ($this->pricing_mode_options() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($mode, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span><?php esc_html_e('Pricing value', 'ffl-hub'); ?></span>
                    <input type="number" min="0" step="0.01" name="pricing_value" class="fflhub-pricing-value-input" value="<?php echo esc_attr($value_input); ?>" />
                    <small class="fflhub-pricing-value-hint">
                        <?php esc_html_e('Used by Fixed Percent, Fixed Price, and Fixed Profit. Global Percent uses the sitewide setting; MAP Price uses effective MAP.', 'ffl-hub'); ?>
                    </small>
                </label>

                <label class="fflhub-pricing-fixed-profit-shipping-field">
                    <span><?php esc_html_e('Fixed profit shipping', 'ffl-hub'); ?></span>
                    <select name="fixed_profit_shipping_mode" class="fflhub-pricing-fixed-profit-shipping-select">
                        <?php foreach ($this->fixed_profit_shipping_mode_options() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($fixed_profit_shipping_mode, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="fflhub-pricing-fixed-profit-shipping-hint">
                        <?php esc_html_e('Used only by Fixed Profit. Included keeps current free-shipping style; separate lowers item price and charges shipping separately.', 'ffl-hub'); ?>
                    </small>
                </label>

                <label>
                    <span><?php esc_html_e('Quote free shipping', 'ffl-hub'); ?></span>
                    <select name="quote_free_shipping_override_action" class="fflhub-pricing-quote-ship-select">
                        <?php foreach ($this->quote_free_shipping_action_options() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($quote_free_shipping_action, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>
                        <?php esc_html_e('Only changes the per-product quote/free-shipping override flag. Leave unchanged keeps current row values.', 'ffl-hub'); ?>
                    </small>
                </label>

                <label class="fflhub-pricing-check">
                    <input type="hidden" name="apply_woo_now" value="0" />
                    <input type="checkbox" name="apply_woo_now" class="fflhub-pricing-apply-woo-checkbox" value="1" <?php checked($apply_woo_now); ?> />
                    <span><?php esc_html_e('Save matching Woo products after apply', 'ffl-hub'); ?></span>
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
                <input type="hidden" name="brand_id" value="<?php echo esc_attr((string) (int) $filters['brand_id']); ?>" />
                <input type="hidden" name="map_policy" value="<?php echo esc_attr($filters['map_policy']); ?>" />
                <input type="hidden" name="map_price_status" value="<?php echo esc_attr($filters['map_price_status']); ?>" />
                <input type="hidden" name="dropship_status" value="<?php echo esc_attr($filters['dropship_status']); ?>" />
                <input type="hidden" name="ffl_status" value="<?php echo esc_attr($filters['ffl_status']); ?>" />
                <input type="hidden" name="pricing_mode" class="fflhub-pricing-apply-mode-input" value="<?php echo esc_attr($mode); ?>" />
                <input type="hidden" name="pricing_value" class="fflhub-pricing-apply-value-input" value="<?php echo esc_attr($value_input); ?>" />
                <input type="hidden" name="fixed_profit_shipping_mode" class="fflhub-pricing-apply-fixed-profit-shipping-input" value="<?php echo esc_attr($fixed_profit_shipping_mode); ?>" />
                <input type="hidden" name="quote_free_shipping_override_action" class="fflhub-pricing-apply-quote-ship-input" value="<?php echo esc_attr($quote_free_shipping_action); ?>" />
                <input type="hidden" name="apply_woo_now" class="fflhub-pricing-apply-woo-input" value="<?php echo esc_attr($apply_woo_now ? '1' : '0'); ?>" />
                <?php
                $apply_attrs = [
                    'onclick' => "return confirm('Apply the selected pricing controls and quote free-shipping action to the currently filtered product_state rows? This marks product_state rows changed and can save matching Woo products if enabled.');",
                ];
                if (!$this->has_active_filter($filters)) {
                    $apply_attrs['disabled'] = 'disabled';
                }
                submit_button(__('Apply Bulk Changes to Filtered Rows', 'ffl-hub'), 'primary', 'submit', false, $apply_attrs);
                ?>
                <?php if (!$this->has_active_filter($filters)) : ?>
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
        <?php if (empty($rows)) : ?>
            <div class="fflhub-pricing-empty"><?php esc_html_e('No matching rows.', 'ffl-hub'); ?></div>
        <?php else : ?>
            <div class="fflhub-pricing-card-list">
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $product_id = (int) ($row['product_id'] ?? 0);
                    $edit_link = $product_id > 0 ? get_edit_post_link($product_id, '') : '';
                    $stock_status = (string) ($row['stock_status'] ?? '-');
                    $woo_stock_qty = (string) ($row['woo_stock_qty'] ?? '');
                    $woo_stock_status = (string) ($row['woo_stock_status'] ?? '');
                    $woo_sku = (string) ($row['woo_sku'] ?? '');
                    $manufacturer_norm = (string) ($row['manufacturer_norm'] ?? '');
                    $pricing_summary = $this->pricing_value_summary($row);
                    ?>
                    <article class="fflhub-pricing-card">
                        <header class="fflhub-pricing-card-header">
                            <div class="fflhub-pricing-card-title-wrap">
                                <h3 class="fflhub-pricing-card-title">
                                    <?php if ($edit_link) : ?>
                                        <a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html((string) ($row['post_title'] ?? '')); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html((string) ($row['post_title'] ?? '')); ?>
                                    <?php endif; ?>
                                </h3>
                                <div class="fflhub-pricing-card-meta">
                                    <code><?php echo esc_html('#' . (string) $product_id); ?></code>
                                    <code><?php echo esc_html((string) ($row['upc'] ?? '')); ?></code>
                                    <span><?php echo esc_html('SKU ' . ($woo_sku !== '' ? $woo_sku : '-')); ?></span>
                                    <span><?php echo esc_html('Woo brand: ' . ((string) ($row['woo_brand'] ?? '') !== '' ? (string) $row['woo_brand'] : '-')); ?></span>
                                    <span><?php echo esc_html('Source: ' . ($manufacturer_norm !== '' ? $manufacturer_norm : '-')); ?></span>
                                </div>
                            </div>
                            <div class="fflhub-pricing-card-flags">
                                <?php echo $this->pill((string) ($row['post_status'] ?? ''), 'neutral'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo !empty($row['has_changed']) ? $this->pill('Changed', 'warn') : $this->pill('Synced', 'good'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo $this->pill(!empty($row['dropship_enabled']) ? 'Dropship' : 'No dropship', !empty($row['dropship_enabled']) ? 'good' : 'bad'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo !empty($row['ffl_required']) ? $this->pill('FFL', 'warn') : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo !empty($row['sot_required']) ? $this->pill('SOT', 'bad') : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                        </header>

                        <div class="fflhub-pricing-card-grid">
                            <section class="fflhub-pricing-metric-panel is-offer">
                                <h4><?php esc_html_e('Selected Offer', 'ffl-hub'); ?></h4>
                                <dl>
                                    <dt><?php esc_html_e('Distributor', 'ffl-hub'); ?></dt>
                                    <dd><?php echo $this->pill((string) ($row['distributor_id'] ?? '-'), 'dist'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
                                    <dt><?php esc_html_e('Product ID', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html((string) ($row['distributor_product_id'] ?? '-')); ?></dd>
                                    <dt><?php esc_html_e('Stock', 'ffl-hub'); ?></dt>
                                    <dd>
                                        <?php echo $this->pill($stock_status, $stock_status === 'instock' ? 'good' : 'bad'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <span><?php echo esc_html('Qty ' . (string) (int) ($row['qty'] ?? 0)); ?></span>
                                    </dd>
                                </dl>
                            </section>

                            <section class="fflhub-pricing-metric-panel">
                                <h4><?php esc_html_e('MAP', 'ffl-hub'); ?></h4>
                                <dl>
                                    <dt><?php esc_html_e('Policy', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html($this->map_policy_label((string) ($row['map_visibility_policy'] ?? ''))); ?></dd>
                                    <dt><?php esc_html_e('Raw', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html($this->money($row['map_price'] ?? null)); ?></dd>
                                    <dt><?php esc_html_e('Effective', 'ffl-hub'); ?></dt>
                                    <dd>
                                        <?php echo esc_html($this->money($row['effective_map_price'] ?? null)); ?>
                                        <?php echo $this->pill(!empty($row['map_applicable']) ? 'Applies' : 'No MAP', !empty($row['map_applicable']) ? 'warn' : 'neutral'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </dd>
                                </dl>
                            </section>

                            <section class="fflhub-pricing-metric-panel">
                                <h4><?php esc_html_e('Pricing Control', 'ffl-hub'); ?></h4>
                                <dl>
                                    <dt><?php esc_html_e('Mode', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html($this->pricing_mode_label((string) ($row['pricing_mode'] ?? ''))); ?></dd>
                                    <dt><?php esc_html_e('Value', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html($pricing_summary !== '' ? $pricing_summary : '-'); ?></dd>
                                    <dt><?php esc_html_e('Fixed ship', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html($this->fixed_profit_shipping_mode_label((string) ($row['fixed_profit_shipping_mode'] ?? 'included'))); ?></dd>
                                    <dt><?php esc_html_e('Quote ship', 'ffl-hub'); ?></dt>
                                    <dd>
                                        <?php
                                        echo !empty($row['quote_free_shipping_override'])
                                            ? $this->pill('Free quote ship', 'good')
                                            : $this->pill('Normal quote ship', 'neutral'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                        ?>
                                    </dd>
                                </dl>
                            </section>

                            <section class="fflhub-pricing-metric-panel is-cost">
                                <h4><?php esc_html_e('Cost Basis', 'ffl-hub'); ?></h4>
                                <dl>
                                    <dt><?php esc_html_e('Dealer', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html($this->money($row['dealer_price'] ?? null)); ?></dd>
                                    <dt><?php esc_html_e('Shipping', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html($this->money($row['shipping_cost'] ?? null)); ?></dd>
                                    <dt><?php esc_html_e('Landed', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html($this->money($row['landed_cost'] ?? null)); ?></dd>
                                </dl>
                            </section>

                            <section class="fflhub-pricing-metric-panel is-state">
                                <h4><?php esc_html_e('Product State Output', 'ffl-hub'); ?></h4>
                                <dl>
                                    <dt><?php esc_html_e('Sell', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html($this->money($row['computed_sell_price'] ?? null)); ?></dd>
                                    <dt><?php esc_html_e('Regular', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html($this->money($row['public_regular_price'] ?? null)); ?></dd>
                                    <dt><?php esc_html_e('Sale', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html($this->money($row['public_sale_price'] ?? null)); ?></dd>
                                </dl>
                            </section>

                            <section class="fflhub-pricing-metric-panel is-woo">
                                <h4><?php esc_html_e('Real Woo Row', 'ffl-hub'); ?></h4>
                                <dl>
                                    <dt><?php esc_html_e('Active', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html($this->money($row['woo_active_price'] ?? null)); ?></dd>
                                    <dt><?php esc_html_e('Regular', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html($this->money($row['woo_regular_price'] ?? null)); ?></dd>
                                    <dt><?php esc_html_e('Sale', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html($this->money($row['woo_sale_price'] ?? null)); ?></dd>
                                    <dt><?php esc_html_e('Stock', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html(($woo_stock_qty !== '' ? $woo_stock_qty : '-') . ' / ' . ($woo_stock_status !== '' ? $woo_stock_status : '-')); ?></dd>
                                    <dt><?php esc_html_e('Synced', 'ffl-hub'); ?></dt>
                                    <dd><?php echo esc_html((string) ($row['woo_synced_at'] ?? '') !== '' ? (string) $row['woo_synced_at'] : '-'); ?></dd>
                                </dl>
                            </section>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
                <li><?php echo esc_html(sprintf('Brand filter: %s', (string) (($result['brand_label'] ?? '') ?: 'All'))); ?></li>
                <li><?php echo esc_html(sprintf('MAP policy filter: %s', $this->map_policy_label((string) ($result['map_policy'] ?? '')))); ?></li>
                <li><?php echo esc_html(sprintf('MAP price filter: %s', $this->map_price_status_label((string) ($result['map_price_status'] ?? '')))); ?></li>
                <li><?php echo esc_html(sprintf('Dropship filter: %s', $this->dropship_status_label((string) ($result['dropship_status'] ?? '')))); ?></li>
                <li><?php echo esc_html(sprintf('FFL filter: %s', $this->ffl_status_label((string) ($result['ffl_status'] ?? '')))); ?></li>
                <li><?php echo esc_html(sprintf('Pricing mode: %s', (string) ($result['pricing_mode_label'] ?? $this->pricing_mode_label((string) ($result['pricing_mode'] ?? ''))))); ?></li>
                <li><?php echo esc_html(sprintf('Pricing value: %s', $this->result_pricing_value_label($result))); ?></li>
                <li><?php echo esc_html(sprintf('Fixed profit shipping: %s', (string) ($result['fixed_profit_shipping_mode_label'] ?? $this->fixed_profit_shipping_mode_label((string) ($result['fixed_profit_shipping_mode'] ?? 'included'))))); ?></li>
                <li><?php echo esc_html(sprintf('Quote free shipping action: %s', (string) ($result['quote_free_shipping_action_label'] ?? $this->quote_free_shipping_action_label((string) ($result['quote_free_shipping_action'] ?? ''))))); ?></li>
                <li><?php echo esc_html(sprintf('Matched rows: %d', (int) ($result['matched_rows'] ?? 0))); ?></li>
                <li><?php echo esc_html(sprintf('Pricing controls changed: %d', (int) ($result['pricing_control_rows'] ?? 0))); ?></li>
                <li><?php echo esc_html(sprintf('Quote free shipping rows changed: %d', (int) ($result['quote_free_shipping_rows'] ?? 0))); ?></li>
                <li><?php echo esc_html(sprintf('Outputs recalculated: %d', (int) ($result['recalculated_rows'] ?? 0))); ?></li>
                <li><?php echo esc_html(sprintf('Saved Woo products now: %s', !empty($result['apply_woo_now']) ? 'yes' : 'no')); ?></li>
                <?php if (is_array($result['woo_apply'] ?? null)) : ?>
                    <li><?php echo esc_html(sprintf('Woo products processed: %d', (int) ($result['woo_apply']['products_processed'] ?? 0))); ?></li>
                    <li><?php echo esc_html(sprintf('Woo products saved: %d', (int) ($result['woo_apply']['woo_products_saved'] ?? 0))); ?></li>
                    <li><?php echo esc_html(sprintf('Product state flags cleared: %d', (int) ($result['woo_apply']['product_state_flags_cleared'] ?? 0))); ?></li>
                    <li><?php echo esc_html(sprintf('Woo apply runtime: %s ms', (string) ($result['woo_apply']['elapsed_ms'] ?? '0.00'))); ?></li>
                <?php endif; ?>
                <li><?php echo esc_html(sprintf('Controls runtime: %s ms', (string) ($result['pricing_control_elapsed_ms'] ?? '0.00'))); ?></li>
                <li><?php echo esc_html(sprintf('Quote free shipping runtime: %s ms', (string) ($result['quote_free_shipping_elapsed_ms'] ?? '0.00'))); ?></li>
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

    private function dropship_status_label(string $status): string
    {
        $options = $this->dropship_status_options();
        return $options[$status] ?? ($status !== '' ? $status : __('All dropship statuses', 'ffl-hub'));
    }

    private function map_price_status_label(string $status): string
    {
        $options = $this->map_price_status_options();
        return $options[$status] ?? ($status !== '' ? $status : __('All MAP price statuses', 'ffl-hub'));
    }

    private function ffl_status_label(string $status): string
    {
        $options = $this->ffl_status_options();
        return $options[$status] ?? ($status !== '' ? $status : __('All FFL statuses', 'ffl-hub'));
    }

    private function brand_label(int $term_id): string
    {
        if ($term_id <= 0 || !function_exists('get_term')) {
            return '';
        }

        $term = get_term($term_id, 'product_brand');
        if (!$term || is_wp_error($term)) {
            return '';
        }

        return (string) $term->name;
    }

    /**
     * @return array<string,string>
     */
    private function pricing_mode_options(): array
    {
        return [
            'global_percent' => __('Global Percent', 'ffl-hub'),
            'fixed_percent' => __('Fixed Percent', 'ffl-hub'),
            'fixed_price' => __('Fixed Price', 'ffl-hub'),
            'fixed_profit' => __('Fixed Profit', 'ffl-hub'),
            'map_price' => __('MAP Price', 'ffl-hub'),
        ];
    }

    private function pricing_mode_label(string $mode): string
    {
        $labels = $this->pricing_mode_options();

        return $labels[$mode] ?? ($mode !== '' ? $mode : __('Unset', 'ffl-hub'));
    }

    /**
     * @return array<string,string>
     */
    private function fixed_profit_shipping_mode_options(): array
    {
        return [
            'included' => __('Include shipping in item price', 'ffl-hub'),
            'separate' => __('Charge shipping separately', 'ffl-hub'),
        ];
    }

    private function fixed_profit_shipping_mode_label(string $mode): string
    {
        $labels = $this->fixed_profit_shipping_mode_options();

        return $labels[$mode] ?? $labels['included'];
    }

    /**
     * @return array<string,string>
     */
    private function quote_free_shipping_action_options(): array
    {
        return [
            '' => __('Leave unchanged', 'ffl-hub'),
            'enable' => __('Enable quote free shipping', 'ffl-hub'),
            'disable' => __('Disable quote free shipping', 'ffl-hub'),
        ];
    }

    private function quote_free_shipping_action_label(string $action): string
    {
        $labels = $this->quote_free_shipping_action_options();

        return $labels[$action] ?? ($action !== '' ? $action : __('Leave unchanged', 'ffl-hub'));
    }

    /**
     * @param array<string,mixed> $result
     */
    private function result_pricing_value_label(array $result): string
    {
        $mode = (string) ($result['pricing_mode'] ?? '');
        $value = $this->float_or_null($result['pricing_value'] ?? null);

        if ($mode === 'global_percent') {
            return number_format((float) Options::get_global_markup(), 2, '.', '') . '% global';
        }

        if ($mode === 'map_price') {
            return 'Effective MAP';
        }

        if ($value === null) {
            return '-';
        }

        if ($mode === 'fixed_percent') {
            return number_format($value, 2, '.', '') . '%';
        }

        return '$' . number_format($value, 2, '.', '');
    }

    /**
     * @param array<string,mixed> $row
     */
    private function pricing_value_summary(array $row): string
    {
        $mode = (string) ($row['pricing_mode'] ?? '');
        if ($mode === 'global_percent') {
            return number_format((float) Options::get_global_markup(), 2, '.', '') . '% global';
        }

        if ($mode === 'map_price') {
            return 'Effective MAP';
        }

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

    private function pill(string $label, string $tone): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }

        $allowed = ['good', 'warn', 'bad', 'dist', 'neutral'];
        if (!in_array($tone, $allowed, true)) {
            $tone = 'neutral';
        }

        return '<span class="fflhub-price-pill is-' . esc_attr($tone) . '">' . esc_html($label) . '</span>';
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
                max-width: 1320px;
                margin: 18px 0;
                padding: 16px;
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
            }
            .fflhub-pricing-form,
            .fflhub-pricing-apply {
                display: flex;
                flex-wrap: wrap;
                align-items: flex-end;
                gap: 12px;
            }
            .fflhub-pricing-form label:not(.fflhub-pricing-check) {
                display: flex;
                flex-direction: column;
                gap: 4px;
                min-width: 220px;
                font-weight: 600;
            }
            .fflhub-pricing-check {
                display: flex;
                align-items: center;
                gap: 8px;
                min-height: 30px;
                font-weight: 600;
            }
            .fflhub-pricing-form select,
            .fflhub-pricing-form input[type="number"] {
                min-width: 220px;
            }
            .fflhub-pricing-form small {
                max-width: 260px;
                color: #646970;
                font-size: 11px;
                font-weight: 400;
                line-height: 1.35;
            }
            .fflhub-pricing-form label.is-disabled {
                opacity: .58;
            }
            .fflhub-pricing-form input[disabled],
            .fflhub-pricing-form select[disabled] {
                background: #f6f7f7;
                color: #8c8f94;
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
            .fflhub-pricing-empty,
            .fflhub-pricing-card-list {
                max-width: 1320px;
            }
            .fflhub-pricing-empty {
                padding: 18px;
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
            }
            .fflhub-pricing-card-list {
                display: grid;
                gap: 14px;
            }
            .fflhub-pricing-card {
                padding: 16px;
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
            }
            .fflhub-pricing-card-header {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 18px;
                margin-bottom: 14px;
                padding-bottom: 12px;
                border-bottom: 1px solid #f0f0f1;
            }
            .fflhub-pricing-card-title-wrap {
                min-width: 0;
            }
            .fflhub-pricing-card-title {
                margin: 0 0 8px;
                font-size: 16px;
                line-height: 1.35;
            }
            .fflhub-pricing-card-title a {
                text-decoration: none;
            }
            .fflhub-pricing-card-title a:hover {
                text-decoration: underline;
            }
            .fflhub-pricing-card-meta,
            .fflhub-pricing-card-flags {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 6px 10px;
            }
            .fflhub-pricing-card-meta {
                color: #646970;
                font-size: 12px;
            }
            .fflhub-pricing-card-meta code {
                font-size: 12px;
            }
            .fflhub-pricing-card-flags {
                justify-content: flex-end;
                min-width: 210px;
            }
            .fflhub-pricing-card-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
                gap: 12px;
            }
            .fflhub-pricing-metric-panel {
                padding: 12px;
                min-height: 132px;
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-radius: 7px;
            }
            .fflhub-pricing-metric-panel.is-offer {
                background: #eef6fc;
                border-color: #b8d6ed;
            }
            .fflhub-pricing-metric-panel.is-cost {
                background: #fff8e5;
                border-color: #ead18a;
            }
            .fflhub-pricing-metric-panel.is-state {
                background: #edfaef;
                border-color: #b7dcb8;
            }
            .fflhub-pricing-metric-panel.is-woo {
                background: #f4f1fb;
                border-color: #cec3e6;
            }
            .fflhub-pricing-metric-panel h4 {
                margin: 0 0 10px;
                color: #50575e;
                font-size: 11px;
                line-height: 1.2;
                letter-spacing: 0.03em;
                text-transform: uppercase;
            }
            .fflhub-pricing-metric-panel dl {
                display: grid;
                grid-template-columns: minmax(76px, auto) 1fr;
                gap: 7px 10px;
                margin: 0;
            }
            .fflhub-pricing-metric-panel dt {
                color: #646970;
                font-size: 12px;
                line-height: 1.35;
            }
            .fflhub-pricing-metric-panel dd {
                margin: 0;
                color: #1d2327;
                font-size: 13px;
                font-weight: 650;
                line-height: 1.35;
                overflow-wrap: anywhere;
            }
            .fflhub-muted {
                color: #646970;
            }
            .fflhub-price-pill {
                display: inline-flex;
                align-items: center;
                min-height: 20px;
                margin: 2px 4px 2px 0;
                padding: 1px 7px;
                border-radius: 999px;
                border: 1px solid #c3c4c7;
                background: #f6f7f7;
                color: #1d2327;
                font-size: 11px;
                font-weight: 700;
                line-height: 18px;
                white-space: nowrap;
            }
            .fflhub-price-pill.is-good {
                border-color: #8bc58a;
                background: #edfaef;
                color: #0a5f1f;
            }
            .fflhub-price-pill.is-warn {
                border-color: #e7bd50;
                background: #fff8e5;
                color: #6f4e00;
            }
            .fflhub-price-pill.is-bad {
                border-color: #e28b8b;
                background: #fceeee;
                color: #8a1f1f;
            }
            .fflhub-price-pill.is-dist {
                border-color: #72aee6;
                background: #eef6fc;
                color: #0a4b78;
            }
            @media (max-width: 782px) {
                .fflhub-pricing-card-header {
                    display: block;
                }
                .fflhub-pricing-card-flags {
                    justify-content: flex-start;
                    min-width: 0;
                    margin-top: 10px;
                }
            }
        </style>
        <?php
    }

    private function render_scripts(): void
    {
        ?>
        <script>
            (function () {
                var mode = document.querySelector('.fflhub-pricing-mode-select');
                var value = document.querySelector('.fflhub-pricing-value-input');
                var hint = document.querySelector('.fflhub-pricing-value-hint');
                var fixedProfitShippingField = document.querySelector('.fflhub-pricing-fixed-profit-shipping-field');
                var fixedProfitShippingMode = document.querySelector('.fflhub-pricing-fixed-profit-shipping-select');
                var applyMode = document.querySelector('.fflhub-pricing-apply-mode-input');
                var applyValue = document.querySelector('.fflhub-pricing-apply-value-input');
                var applyFixedProfitShippingMode = document.querySelector('.fflhub-pricing-apply-fixed-profit-shipping-input');
                var quoteShipAction = document.querySelector('.fflhub-pricing-quote-ship-select');
                var applyQuoteShipAction = document.querySelector('.fflhub-pricing-apply-quote-ship-input');
                var applyWooCheckbox = document.querySelector('.fflhub-pricing-apply-woo-checkbox');
                var applyWooInput = document.querySelector('.fflhub-pricing-apply-woo-input');
                var applyForm = document.querySelector('.fflhub-pricing-apply');
                if (!mode || !value || !hint) {
                    return;
                }

                function syncPricingValueField() {
                    var selected = mode.value || '';
                    var needsValue = selected === 'fixed_percent' || selected === 'fixed_price' || selected === 'fixed_profit';
                    var fixedProfitMode = selected === 'fixed_profit';
                    value.disabled = !needsValue;
                    value.required = needsValue;
                    if (fixedProfitShippingField) {
                        fixedProfitShippingField.classList.toggle('is-disabled', !fixedProfitMode);
                    }
                    if (fixedProfitShippingMode) {
                        fixedProfitShippingMode.disabled = !fixedProfitMode;
                    }

                    if (selected === 'global_percent') {
                        hint.textContent = 'Global Percent uses the sitewide markup setting and ignores this field.';
                    } else if (selected === 'map_price') {
                        hint.textContent = 'MAP Price uses each row effective MAP price and ignores this field.';
                    } else if (selected === 'fixed_percent') {
                        hint.textContent = 'Enter a row-specific markup percentage. Example: 7 means 7%.';
                    } else if (selected === 'fixed_price') {
                        hint.textContent = 'Enter the exact sell/quote price to use for each matching row.';
                    } else {
                        hint.textContent = 'Enter the desired net profit. Product state accounts for shipping and processor cost, then rounds up to the next .99.';
                    }

                    if (applyMode) {
                        applyMode.value = selected;
                    }
                    if (applyValue) {
                        applyValue.value = needsValue ? value.value : '';
                    }
                    if (fixedProfitShippingMode && applyFixedProfitShippingMode) {
                        applyFixedProfitShippingMode.value = fixedProfitMode ? fixedProfitShippingMode.value : 'included';
                    }
                    if (quoteShipAction && applyQuoteShipAction) {
                        applyQuoteShipAction.value = quoteShipAction.value || '';
                    }
                    if (applyWooCheckbox && applyWooInput) {
                        applyWooInput.value = applyWooCheckbox.checked ? '1' : '0';
                    }
                }

                mode.addEventListener('change', syncPricingValueField);
                value.addEventListener('input', syncPricingValueField);
                if (fixedProfitShippingMode) {
                    fixedProfitShippingMode.addEventListener('change', syncPricingValueField);
                }
                if (applyWooCheckbox) {
                    applyWooCheckbox.addEventListener('change', syncPricingValueField);
                }
                if (quoteShipAction) {
                    quoteShipAction.addEventListener('change', syncPricingValueField);
                }
                if (applyForm) {
                    applyForm.addEventListener('submit', syncPricingValueField);
                }
                syncPricingValueField();
            }());
        </script>
        <?php
    }
}
