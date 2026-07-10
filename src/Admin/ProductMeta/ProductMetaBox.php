<?php

namespace FFLHub\Admin\ProductMeta;

use FFLHub\Distributor\Core\DistributorRegistry;
use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Settings\Options;
use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Adds product_state controls and a read-only state row viewer to WooCommerce products.
 */
class ProductMetaBox
{
    public static function init(): void
    {
        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_box'));
        add_action(
            'woocommerce_process_product_meta',
            array(__CLASS__, 'save_product_state_controls'),
            1000,
            1
        );
    }

    public static function add_meta_box(): void
    {
        add_meta_box(
            'fflhub_product_state_editor',
            __('FFLHub Product State Editor', 'ffl-hub'),
            array(__CLASS__, 'render_product_state_editor_box'),
            'product',
            'normal',
            'high'
        );

        add_meta_box(
            'fflhub_product_state_row',
            __('FFLHub Product State Row', 'ffl-hub'),
            array(__CLASS__, 'render_product_state_row_box'),
            'product',
            'normal',
            'default'
        );
    }

    public static function render_product_state_editor_box(WP_Post $post): void
    {
        $row = self::product_state_row((int) $post->ID);
        if ($row === null) {
            echo '<p style="margin:0;color:#6b7280;">' .
                esc_html__('No product_state row exists for this product yet. Run the Product State backfill to create one.', 'ffl-hub') .
                '</p>';
            return;
        }

        wp_nonce_field('fflhub_save_product_state_controls', 'fflhub_product_state_nonce');

        $pricing_mode = self::state_value_raw($row['pricing_mode'] ?? 'global_percent', 'global_percent');
        $map_policy = self::state_value_raw($row['map_visibility_policy'] ?? 'none', 'none');
        $map_override_mode = self::state_value_raw($row['map_override_mode'] ?? 'auto', 'auto');
        $status = self::state_value_raw($row['status'] ?? 'active', 'active');
        $enabled_distributors = self::enabled_distributor_options();
        $allowed_distributor_ids = self::normalize_distributor_lock_ids($row['allowed_distributors_json'] ?? '');
        $allowed_distributor_lookup = array_fill_keys($allowed_distributor_ids, true);
        $map_applicable = self::truthy_state($row['map_applicable'] ?? null);

        echo '<style>
            #fflhub_product_state_editor .inside { background:#f8fafc; margin:0; padding:14px; }
            .fflhub-state-editor { color:#1d2327; }
            .fflhub-state-editor__header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin:0 0 14px; padding:12px 14px; border:1px solid #cbd5e1; border-left:4px solid #2271b1; border-radius:6px; background:#fff; }
            .fflhub-state-editor__eyebrow { margin:0 0 3px; color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
            .fflhub-state-editor__title { margin:0; font-size:14px; font-weight:700; }
            .fflhub-state-editor__copy { margin:4px 0 0; max-width:780px; color:#64748b; }
            .fflhub-state-editor__badge { flex:0 0 auto; padding:5px 8px; border-radius:999px; background:#e0f2fe; color:#075985; font-size:11px; font-weight:700; }
            .fflhub-state-selected-offer { margin:0 0 12px; border:1px solid #bfdbfe; border-left:4px solid #2563eb; border-radius:6px; padding:12px; background:#eff6ff; }
            .fflhub-state-selected-offer h3 { margin:0 0 8px !important; font-size:13px !important; color:#1e3a8a; }
            .fflhub-state-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:12px; align-items:start; }
            .fflhub-state-card { border:1px solid #cbd5e1 !important; border-radius:6px !important; padding:12px !important; background:#fff !important; box-shadow:0 1px 1px rgba(15,23,42,.04); }
            .fflhub-state-card h3 { display:flex; align-items:center; gap:6px; margin:0 0 10px !important; font-size:13px !important; }
            .fflhub-state-card h3:before { content:""; width:8px; height:8px; border-radius:50%; background:#2271b1; display:inline-block; }
            .fflhub-state-field { margin:0 0 10px; padding:8px; border:1px solid transparent; border-radius:5px; background:#f8fafc; transition:opacity .12s ease, background .12s ease, border-color .12s ease; }
            .fflhub-state-field:last-child { margin-bottom:0; }
            .fflhub-state-field label, .fflhub-state-label { display:block; font-weight:700; margin-bottom:4px; }
            .fflhub-state-field input[type=number], .fflhub-state-field select { width:100%; max-width:none; }
            .fflhub-state-field__hint { display:block; margin-top:4px; color:#64748b; }
            .fflhub-state-mini-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
            .fflhub-state-check { display:flex; gap:8px; align-items:flex-start; }
            .fflhub-state-check strong { display:block; margin-bottom:2px; }
            .fflhub-state-check span span { color:#64748b; }
            .fflhub-state-field.is-disabled { opacity:.52; background:#f1f5f9; border-color:#e2e8f0; }
            .fflhub-state-field.is-disabled input, .fflhub-state-field.is-disabled select { cursor:not-allowed; }
            .fflhub-state-output { margin-top:12px; border:1px solid #cbd5e1 !important; border-radius:6px !important; padding:12px !important; background:#eef6ff !important; }
            .fflhub-state-output__grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:8px; }
            .fflhub-state-output-chip { background:#fff; border:1px solid #cbd5e1; border-radius:5px; padding:8px; }
            .fflhub-state-output-chip__label { font-size:11px; color:#64748b; margin-bottom:3px; }
            .fflhub-state-output-chip code { font-size:13px; font-weight:700; }
            .fflhub-state-output-chip--distributor { border-color:#2563eb; background:#eff6ff; }
            .fflhub-state-output-chip--distributor code { color:#1d4ed8; }
            .fflhub-state-output-chip--cost { border-color:#f59e0b; background:#fffbeb; }
            .fflhub-state-output-chip--cost code { color:#92400e; }
            .fflhub-state-output-chip--price { border-color:#16a34a; background:#f0fdf4; }
            .fflhub-state-output-chip--price code { color:#166534; }
            .fflhub-state-output-chip--success { border-color:#22c55e; background:#f0fdf4; }
            .fflhub-state-output-chip--success code { color:#166534; }
            .fflhub-state-output-chip--warning { border-color:#f97316; background:#fff7ed; }
            .fflhub-state-output-chip--warning code { color:#9a3412; }
            .fflhub-state-output-chip--danger { border-color:#ef4444; background:#fef2f2; }
            .fflhub-state-output-chip--danger code { color:#991b1b; }
        </style>';

        echo '<div id="fflhub-state-editor" class="fflhub-state-editor" data-map-applicable="' . esc_attr($map_applicable ? '1' : '0') . '">';
        echo '<div class="fflhub-state-editor__header">';
        echo '<div>';
        echo '<p class="fflhub-state-editor__eyebrow">' . esc_html__('Product State Controls', 'ffl-hub') . '</p>';
        echo '<h3 class="fflhub-state-editor__title">' . esc_html__('Edit the new state row', 'ffl-hub') . '</h3>';
        echo '<p class="fflhub-state-editor__copy">' .
            esc_html__('These controls write product_state. The product-state sync path can then apply the calculated values back to WooCommerce.', 'ffl-hub') .
            '</p>';
        echo '</div>';
        echo '<span class="fflhub-state-editor__badge">' . esc_html($map_applicable ? __('MAP available', 'ffl-hub') : __('No MAP', 'ffl-hub')) . '</span>';
        echo '</div>';

        self::render_selected_offer_snapshot($row);

        echo '<div class="fflhub-state-grid">';

        echo '<div class="fflhub-state-card">';
        echo '<h3 style="margin:0 0 8px;font-size:13px;">' . esc_html__('Row Status', 'ffl-hub') . '</h3>';
        echo '<div class="fflhub-state-field">';
        echo '<label>' . esc_html__('State status', 'ffl-hub') . '</label>';
        echo '<select name="fflhub_state_status">';
        echo '<option value="active" ' . selected($status, 'active', false) . '>' . esc_html__('Active - eligible for offer selection', 'ffl-hub') . '</option>';
        echo '<option value="ignored" ' . selected($status, 'ignored', false) . '>' . esc_html__('Ignored - exclude from offer selection', 'ffl-hub') . '</option>';
        echo '</select>';
        echo '<span class="fflhub-state-field__hint">' .
            esc_html__('Active rows can receive best-offer updates. Ignored rows stay in product_state but are skipped by active-row offer selection SQL.', 'ffl-hub') .
            '</span>';
        echo '</div>';
        echo '</div>';

        echo '<div class="fflhub-state-card">';
        echo '<h3 style="margin:0 0 8px;font-size:13px;">' . esc_html__('Stock And Fulfillment Overrides', 'ffl-hub') . '</h3>';
        echo '<div class="fflhub-state-field">';
        echo '<label class="fflhub-state-check">';
        echo '<input type="checkbox" name="fflhub_state_stock_oos_override" value="1" ' .
            checked(self::truthy_state($row['stock_oos_override'] ?? null), true, false) .
            ' />';
        echo '<span><strong>' . esc_html__('Out of stock override', 'ffl-hub') . '</strong><br />' .
            '<span>' . esc_html__('Treat distributor stock as unavailable for this product_state row.', 'ffl-hub') . '</span></span>';
        echo '</label>';
        echo '</div>';

        echo '<div class="fflhub-state-field" data-local-stock-field="qty">';
        echo '<label>' . esc_html__('Local stock quantity', 'ffl-hub') . '</label>';
        echo '<input type="number" step="1" min="0" name="fflhub_state_local_stock_override_qty" value="' .
            esc_attr(self::state_int_for_input($row['local_stock_override_qty'] ?? null)) .
            '" />';
        echo '<span class="fflhub-state-field__hint">' .
            esc_html__('Blank means no local stock override. A positive value can later make local stock the source of truth for checkout/sync.', 'ffl-hub') .
            '</span>';
        echo '</div>';

        echo '<div class="fflhub-state-field" data-local-stock-field="free_shipping">';
        echo '<label class="fflhub-state-check">';
        echo '<input type="checkbox" name="fflhub_state_local_stock_free_shipping" value="1" ' .
            checked(self::truthy_state($row['local_stock_free_shipping'] ?? null), true, false) .
            ' />';
        echo '<span><strong>' . esc_html__('Local stock free shipping', 'ffl-hub') . '</strong><br />' .
            '<span>' . esc_html__('Use free shipping behavior when local stock is used.', 'ffl-hub') . '</span></span>';
        echo '</label>';
        echo '</div>';

        echo '<div class="fflhub-state-field">';
        echo '<label class="fflhub-state-check">';
        echo '<input type="checkbox" name="fflhub_state_manual_shipping_override" value="1" ' .
            checked(self::truthy_state($row['manual_shipping_override'] ?? null), true, false) .
            ' />';
        echo '<span><strong>' . esc_html__('Manual shipping override flag', 'ffl-hub') . '</strong><br />' .
            '<span>' . esc_html__('Use manually entered weight and dimensions instead of distributor-offer shipping measurements.', 'ffl-hub') . '</span></span>';
        echo '</label>';
        echo '</div>';
        echo '<div class="fflhub-state-field" data-shipping-override-field="measurements">';
        echo '<span class="fflhub-state-label">' . esc_html__('Manual shipping measurements', 'ffl-hub') . '</span>';
        echo '<div class="fflhub-state-mini-grid">';
        echo '<label>' . esc_html__('Weight oz', 'ffl-hub') .
            '<input type="number" step="0.001" min="0" name="fflhub_state_manual_shipping_weight_oz" value="' .
            esc_attr(self::state_decimal_for_input($row['manual_shipping_weight_oz'] ?? null)) .
            '" /></label>';
        echo '<label>' . esc_html__('Length in', 'ffl-hub') .
            '<input type="number" step="0.001" min="0" name="fflhub_state_manual_shipping_length_in" value="' .
            esc_attr(self::state_decimal_for_input($row['manual_shipping_length_in'] ?? null)) .
            '" /></label>';
        echo '<label>' . esc_html__('Width in', 'ffl-hub') .
            '<input type="number" step="0.001" min="0" name="fflhub_state_manual_shipping_width_in" value="' .
            esc_attr(self::state_decimal_for_input($row['manual_shipping_width_in'] ?? null)) .
            '" /></label>';
        echo '<label>' . esc_html__('Height in', 'ffl-hub') .
            '<input type="number" step="0.001" min="0" name="fflhub_state_manual_shipping_height_in" value="' .
            esc_attr(self::state_decimal_for_input($row['manual_shipping_height_in'] ?? null)) .
            '" /></label>';
        echo '</div>';
        echo '<span class="fflhub-state-field__hint">' .
            esc_html__('Fill all four fields. Product_state uses these as the effective Woo/feed shipping measurements while the override is checked.', 'ffl-hub') .
            '</span>';
        echo '</div>';

        echo '<div class="fflhub-state-field">';
        echo '<span class="fflhub-state-label">' . esc_html__('Effective shipping reference', 'ffl-hub') . '</span>';
        echo '<div class="fflhub-state-mini-grid">';
        echo '<span><strong>' . esc_html__('Weight / box', 'ffl-hub') . '</strong><br /><code>' .
            esc_html(trim(self::state_value($row['shipping_weight_oz'] ?? null) . ' oz / ' . self::state_dimensions($row))) .
            '</code></span>';
        echo '<span><strong>' . esc_html__('Estimated USPS Shipping', 'ffl-hub') . '</strong><br /><code>' .
            esc_html(self::state_estimated_usps_shipping($row['estimated_usps_shipping_cost'] ?? null)) .
            '</code></span>';
        echo '</div>';
        echo '<span class="fflhub-state-field__hint">' .
            esc_html__('Read-only product_state values. The USPS estimate is internal, formula-based reference data and does not affect checkout or pricing.', 'ffl-hub') .
            '</span>';
        echo '</div>';
        echo '</div>';

        echo '<div class="fflhub-state-card">';
        echo '<h3 style="margin:0 0 8px;font-size:13px;">' . esc_html__('Pricing Calculation', 'ffl-hub') . '</h3>';
        echo '<div class="fflhub-state-field">';
        echo '<label>' . esc_html__('Pricing mode', 'ffl-hub') . '</label>';
        echo '<select id="fflhub_state_pricing_mode" name="fflhub_state_pricing_mode">';
        foreach (self::product_state_pricing_mode_options() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($pricing_mode, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<span class="fflhub-state-field__hint">' .
            esc_html__('Controls how product_state computes the internal sell or quote price. Global percent uses the current global markup setting.', 'ffl-hub') .
            '</span>';
        echo '</div>';

        echo '<div class="fflhub-state-field" data-pricing-field="global_percent">';
        echo '<label>' . esc_html__('Current global percent', 'ffl-hub') . '</label>';
        echo '<code style="display:block;font-size:13px;">' .
            esc_html(number_format((float) Options::get_global_markup(), 2, '.', '') . '%') .
            '</code>';
        echo '<span class="fflhub-state-field__hint">' .
            esc_html__('Read-only. This is the sitewide markup used when Pricing Mode is Global Percent.', 'ffl-hub') .
            '</span>';
        echo '</div>';

        echo '<div class="fflhub-state-field" data-pricing-field="fixed_percent">';
        echo '<label>' . esc_html__('Fixed percent', 'ffl-hub') . '</label>';
        echo '<input type="number" step="0.01" min="0" name="fflhub_state_pricing_percent" value="' .
            esc_attr(self::state_decimal_for_input($row['pricing_percent'] ?? null)) .
            '" />';
        echo '<span class="fflhub-state-field__hint">' .
            esc_html__('Used only by Fixed Percent mode. Enter 7 for 7 percent.', 'ffl-hub') .
            '</span>';
        echo '</div>';

        echo '<div class="fflhub-state-field" data-pricing-field="fixed_price">';
        echo '<label>' . esc_html__('Fixed price', 'ffl-hub') . '</label>';
        echo '<input type="number" step="0.01" min="0" name="fflhub_state_pricing_fixed_price" value="' .
            esc_attr(self::state_decimal_for_input($row['pricing_fixed_price'] ?? null)) .
            '" />';
        echo '<span class="fflhub-state-field__hint">' .
            esc_html__('Used only by Fixed Price mode. This becomes the computed sell price.', 'ffl-hub') .
            '</span>';
        echo '</div>';

        echo '<div class="fflhub-state-field" data-pricing-field="fixed_profit">';
        echo '<label>' . esc_html__('Fixed profit', 'ffl-hub') . '</label>';
        echo '<input type="number" step="0.01" min="0" name="fflhub_state_pricing_fixed_profit" value="' .
            esc_attr(self::state_decimal_for_input($row['pricing_fixed_profit'] ?? null)) .
            '" />';
        echo '<span class="fflhub-state-field__hint">' .
            esc_html__('Used only by Fixed Profit mode. It accounts for shipping and payment processor cost in the product_state calculation.', 'ffl-hub') .
            '</span>';
        echo '</div>';
        echo '</div>';

        echo '<div class="fflhub-state-card">';
        echo '<h3 style="margin:0 0 8px;font-size:13px;">' . esc_html__('MAP Visibility', 'ffl-hub') . '</h3>';
        echo '<div class="fflhub-state-field">';
        echo '<span class="fflhub-state-label">' . esc_html__('MAP source values', 'ffl-hub') . '</span>';
        echo '<code style="display:block;font-size:13px;">' .
            esc_html(sprintf(
                __('Raw: %1$s | Effective: %2$s', 'ffl-hub'),
                self::state_money($row['map_price'] ?? null),
                self::state_money($row['effective_map_price'] ?? null)
            )) .
            '</code>';
        echo '<span class="fflhub-state-field__hint">' .
            esc_html__('Raw MAP is what the selected offer supplied. Effective MAP is what product_state uses after override rules.', 'ffl-hub') .
            '</span>';
        echo '</div>';
        echo '<div class="fflhub-state-field">';
        echo '<label>' . esc_html__('MAP override mode', 'ffl-hub') . '</label>';
        echo '<select id="fflhub_state_map_override_mode" name="fflhub_state_map_override_mode">';
        foreach (self::product_state_map_override_mode_options() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($map_override_mode, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<span class="fflhub-state-field__hint">' .
            esc_html__('Auto preserves distributor/source MAP behavior. Manual forces a specific MAP. Force no MAP ignores stale distributor MAP for this product.', 'ffl-hub') .
            '</span>';
        echo '</div>';
        echo '<div class="fflhub-state-field" data-map-override-field="manual_price">';
        echo '<label>' . esc_html__('Manual MAP price', 'ffl-hub') . '</label>';
        echo '<input type="number" step="0.01" min="0" id="fflhub_state_map_override_price" name="fflhub_state_map_override_price" value="' .
            esc_attr(self::state_decimal_for_input($row['map_override_price'] ?? null)) .
            '" />';
        echo '<span class="fflhub-state-field__hint">' .
            esc_html__('Used only when MAP override mode is Manual MAP Price.', 'ffl-hub') .
            '</span>';
        echo '</div>';
        echo '<div class="fflhub-state-field" data-map-field="visibility">';
        echo '<label>' . esc_html__('Visibility policy', 'ffl-hub') . '</label>';
        echo '<select id="fflhub_state_map_visibility_policy" name="fflhub_state_map_visibility_policy">';
        foreach (self::product_state_map_policy_options() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($map_policy, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<span class="fflhub-state-field__hint">' .
            esc_html__('Controls storefront price visibility when this row has a positive MAP. If MAP is missing, the save path stores this as None.', 'ffl-hub') .
            '</span>';
        echo '</div>';
        echo '<div class="fflhub-state-field" data-map-field="quote_free_shipping">';
        echo '<label class="fflhub-state-check">';
        echo '<input type="checkbox" name="fflhub_state_quote_free_shipping_override" value="1" ' .
            checked(self::truthy_state($row['quote_free_shipping_override'] ?? null), true, false) .
            ' />';
        echo '<span><strong>' . esc_html__('Quote free shipping override', 'ffl-hub') . '</strong><br />' .
            '<span>' . esc_html__('Marks quote-required MAP products as free-shipping eligible in the new state row.', 'ffl-hub') . '</span></span>';
        echo '</label>';
        echo '</div>';
        echo '</div>';

        echo '<div class="fflhub-state-card">';
        echo '<h3 style="margin:0 0 8px;font-size:13px;">' . esc_html__('Distributor Lock', 'ffl-hub') . '</h3>';
        echo '<div class="fflhub-state-field">';
        echo '<label class="fflhub-state-check">';
        echo '<input id="fflhub_state_allowed_distributors_enabled" type="checkbox" name="fflhub_state_allowed_distributors_enabled" value="1" ' .
            checked(!empty($allowed_distributor_ids), true, false) .
            ' />';
        echo '<span><strong>' . esc_html__('Restrict allowed distributors', 'ffl-hub') . '</strong><br />' .
            '<span>' . esc_html__('When enabled, best-offer selection considers only the selected distributors.', 'ffl-hub') . '</span></span>';
        echo '</label>';
        echo '</div>';

        if (empty($enabled_distributors)) {
            echo '<p style="margin:0;color:#b45309;">' . esc_html__('No enabled distributors are available.', 'ffl-hub') . '</p>';
        } else {
            echo '<div class="fflhub-state-field" data-distributor-lock-field="select">';
            echo '<select id="fflhub_state_allowed_distributors" name="fflhub_state_allowed_distributors[]" multiple="multiple" size="' .
                esc_attr((string) min(8, max(4, count($enabled_distributors)))) .
                '">';
            foreach ($enabled_distributors as $dist_id => $dist_label) {
                echo '<option value="' . esc_attr($dist_id) . '" ' .
                    selected(isset($allowed_distributor_lookup[$dist_id]), true, false) .
                    '>' . esc_html($dist_label . ' (' . $dist_id . ')') . '</option>';
            }
            echo '</select>';
            echo '<span class="fflhub-state-field__hint">' .
                esc_html__('Hold Ctrl or Command to select multiple distributors.', 'ffl-hub') .
                '</span>';
            echo '</div>';
        }
        echo '</div>';

        echo '</div>';

        $profit_metrics = self::product_state_profit_metrics($row);
        echo '<div class="fflhub-state-output">';
        echo '<h3 style="margin:0 0 8px;font-size:13px;">' . esc_html__('Cost And Profit Metrics', 'ffl-hub') . '</h3>';
        echo '<div class="fflhub-state-output__grid">';
        self::render_state_output_chip(__('Dealer cost', 'ffl-hub'), self::state_money($row['dealer_price'] ?? null), 'cost');
        self::render_state_output_chip(__('Distributor shipping', 'ffl-hub'), self::state_money($profit_metrics['distributor_shipping_cost']), self::shipping_chip_tone($profit_metrics['distributor_shipping_cost']));
        self::render_state_output_chip(__('Estimated USPS Shipping', 'ffl-hub'), self::state_estimated_usps_shipping($row['estimated_usps_shipping_cost'] ?? null), self::shipping_chip_tone($row['estimated_usps_shipping_cost'] ?? null));
        self::render_state_output_chip(__('Shipping cost used', 'ffl-hub'), self::state_money($profit_metrics['shipping_cost_used']), self::shipping_chip_tone($profit_metrics['shipping_cost_used']));
        self::render_state_output_chip(__('Dealer + shipping basis', 'ffl-hub'), self::state_money($profit_metrics['dealer_shipping_basis']), 'cost');
        self::render_state_output_chip(__('Stored landed cost', 'ffl-hub'), self::state_money($row['landed_cost'] ?? null), 'cost');
        self::render_state_output_chip(__('Profit basis used', 'ffl-hub'), self::state_money($profit_metrics['cost_basis']), 'cost');
        self::render_state_output_chip(__('Computed sell price', 'ffl-hub'), self::state_money($row['computed_sell_price'] ?? null), 'price');
        self::render_state_output_chip(
            sprintf(__('Estimated card fee (%s%%)', 'ffl-hub'), $profit_metrics['fee_percent_label']),
            self::state_money($profit_metrics['processor_fee']),
            'warning'
        );
        self::render_state_output_chip(__('Estimated net profit', 'ffl-hub'), self::state_money($profit_metrics['net_profit']), self::profit_chip_tone($profit_metrics['net_profit']));
        self::render_state_output_chip(__('Estimated margin', 'ffl-hub'), self::state_percent($profit_metrics['margin_percent']), self::margin_chip_tone($profit_metrics['margin_percent']));
        echo '</div>';
        echo '<p style="margin:8px 0 0;color:#6b7280;">' .
            esc_html($profit_metrics['shipping_explanation']) .
            '</p>';
        echo '</div>';

        echo '<div class="fflhub-state-output">';
        echo '<h3 style="margin:0 0 8px;font-size:13px;">' . esc_html__('Calculated Product State Outputs', 'ffl-hub') . '</h3>';
        echo '<div class="fflhub-state-output__grid">';
        self::render_state_output_chip(__('Computed sell price', 'ffl-hub'), self::state_money($row['computed_sell_price'] ?? null), 'price');
        self::render_state_output_chip(__('Effective MAP', 'ffl-hub'), self::state_money($row['effective_map_price'] ?? null), self::truthy_state($row['map_applicable'] ?? null) ? 'warning' : 'neutral');
        self::render_state_output_chip(__('MAP applicable', 'ffl-hub'), self::state_yes_no($row['map_applicable'] ?? null), self::truthy_state($row['map_applicable'] ?? null) ? 'warning' : 'neutral');
        self::render_state_output_chip(__('Public regular', 'ffl-hub'), self::state_money($row['public_regular_price'] ?? null), 'price');
        self::render_state_output_chip(__('Public sale', 'ffl-hub'), self::state_money($row['public_sale_price'] ?? null), 'price');
        self::render_state_output_chip(__('Public/display', 'ffl-hub'), self::state_money(self::derived_public_price($row)), 'price');
        self::render_state_output_chip(__('Has changed', 'ffl-hub'), self::state_yes_no($row['has_changed'] ?? null), self::truthy_state($row['has_changed'] ?? null) ? 'warning' : 'success');
        echo '</div>';
        echo '<p style="margin:8px 0 0;color:#6b7280;">' .
            esc_html__('These outputs refresh after saving the product. They are shown here so you can sanity-check the state row without opening the raw row viewer.', 'ffl-hub') .
            '</p>';
        echo '</div>';
        self::render_product_state_editor_script();
        echo '</div>';
    }

    public static function render_product_state_row_box(WP_Post $post): void
    {
        $row = self::product_state_row((int) $post->ID);
        if ($row === null) {
            echo '<p style="margin:0;color:#6b7280;">' .
                esc_html__('No product_state row exists for this product yet. Run the Product State backfill to create one.', 'ffl-hub') .
                '</p>';
            return;
        }

        echo '<p style="margin-top:0;color:#6b7280;">' .
            esc_html__('Read-only view of the current product_state row for this product.', 'ffl-hub') .
            '</p>';

        self::render_product_state_row_table($row);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function product_state_row(int $product_id): ?array
    {
        global $wpdb;

        ProductStateStore::ensure_schema();
        $table = ProductStateStore::table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE product_id = %d LIMIT 1", $product_id), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function render_product_state_row_table(array $row): void
    {
        $groups = [
            __('Identity / Offer', 'ffl-hub') => [
                'product_id',
                'upc',
                'status',
                'enabled',
                'distributor_id',
                'distributor_product_id',
                'distributor_sku',
                'manufacturer_norm',
                'selection_status',
            ],
            __('Stock / Fulfillment', 'ffl-hub') => [
                'qty',
                'stock_status',
                'dropship_enabled',
                'ffl_required',
                'sot_required',
                'stock_oos_override',
                'local_stock_override_qty',
                'local_stock_free_shipping',
            ],
            __('Costs / Prices', 'ffl-hub') => [
                'dealer_price',
                'shipping_cost',
                'estimated_usps_shipping_cost',
                'landed_cost',
                'map_price',
                'msrp',
            ],
            __('Pricing Rules', 'ffl-hub') => [
                'pricing_mode',
                'pricing_percent',
                'pricing_fixed_price',
                'pricing_fixed_profit',
                'map_visibility_policy',
                'map_override_mode',
                'map_override_price',
                'quote_free_shipping_override',
            ],
            __('Calculated Outputs', 'ffl-hub') => [
                'computed_sell_price',
                'effective_map_price',
                'map_applicable',
                'public_regular_price',
                'public_sale_price',
            ],
            __('Overrides / Sync', 'ffl-hub') => [
                'manual_shipping_override',
                'manual_shipping_weight_oz',
                'manual_shipping_length_in',
                'manual_shipping_width_in',
                'manual_shipping_height_in',
                'allowed_distributors_json',
                'shipping_weight_oz',
                'shipping_length_in',
                'shipping_width_in',
                'shipping_height_in',
                'has_changed',
                'created_at',
                'updated_at',
            ],
        ];

        echo '<div style="overflow-x:auto;border:1px solid #dcdcde;border-radius:4px;">';
        echo '<table class="widefat striped" style="min-width:760px;border:0;">';
        echo '<thead><tr>';
        echo '<th style="width:22%;">' . esc_html__('Group', 'ffl-hub') . '</th>';
        echo '<th style="width:28%;">' . esc_html__('Column', 'ffl-hub') . '</th>';
        echo '<th>' . esc_html__('product_state value', 'ffl-hub') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($groups as $group => $columns) {
            $first = true;
            foreach ($columns as $column) {
                echo '<tr>';
                echo '<th style="vertical-align:top;font-weight:700;color:#1d2327;">' . esc_html($first ? $group : '') . '</th>';
                echo '<td style="vertical-align:top;font-weight:600;"><code>' . esc_html($column) . '</code></td>';
                echo '<td style="vertical-align:top;"><code style="white-space:normal;">' .
                    esc_html(self::state_value_for_column($column, $row[$column] ?? null)) .
                    '</code></td>';
                echo '</tr>';
                $first = false;
            }
        }

        echo '</tbody></table></div>';
    }

    private static function state_value_for_column(string $column, $value): string
    {
        if ($column === 'estimated_usps_shipping_cost') {
            return self::state_estimated_usps_shipping($value);
        }

        if (in_array($column, [
            'dealer_price',
            'shipping_cost',
            'landed_cost',
            'map_price',
            'map_override_price',
            'effective_map_price',
            'msrp',
            'computed_sell_price',
            'public_regular_price',
            'public_sale_price',
            'pricing_fixed_price',
            'pricing_fixed_profit',
        ], true)) {
            return self::state_money($value);
        }

        if (in_array($column, [
            'enabled',
            'dropship_enabled',
            'ffl_required',
            'sot_required',
            'stock_oos_override',
            'local_stock_free_shipping',
            'map_applicable',
            'quote_free_shipping_override',
            'manual_shipping_override',
            'has_changed',
        ], true)) {
            return self::state_yes_no($value);
        }

        return self::state_value($value);
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function render_selected_offer_snapshot(array $row): void
    {
        $stock_status = strtolower(trim((string) ($row['stock_status'] ?? '')));
        $qty = (int) ($row['qty'] ?? 0);
        $dropship = self::truthy_state($row['dropship_enabled'] ?? null);
        $ffl = self::truthy_state($row['ffl_required'] ?? null);
        $sot = self::truthy_state($row['sot_required'] ?? null);

        echo '<div class="fflhub-state-selected-offer">';
        echo '<h3>' . esc_html__('Selected Offer Snapshot', 'ffl-hub') . '</h3>';
        echo '<div class="fflhub-state-output__grid">';
        self::render_state_output_chip(__('Distributor', 'ffl-hub'), self::state_distributor_label($row), 'distributor');
        self::render_state_output_chip(__('Distributor item', 'ffl-hub'), self::state_value($row['distributor_product_id'] ?? ($row['distributor_sku'] ?? null)), 'distributor');
        self::render_state_output_chip(__('Stock', 'ffl-hub'), trim((string) $qty . ' / ' . self::state_value($stock_status)), ($stock_status === 'instock' && $qty > 0) ? 'success' : 'warning');
        self::render_state_output_chip(__('Dropship', 'ffl-hub'), $dropship ? __('yes', 'ffl-hub') : __('no', 'ffl-hub'), $dropship ? 'success' : 'warning');
        self::render_state_output_chip(__('FFL / SOT', 'ffl-hub'), ($ffl ? 'FFL' : 'no FFL') . ' / ' . ($sot ? 'SOT' : 'no SOT'), ($sot || $ffl) ? 'warning' : 'neutral');
        self::render_state_output_chip(__('Dealer cost', 'ffl-hub'), self::state_money($row['dealer_price'] ?? null), 'cost');
        self::render_state_output_chip(__('Shipping cost', 'ffl-hub'), self::state_money($row['shipping_cost'] ?? null), self::shipping_chip_tone($row['shipping_cost'] ?? null));
        self::render_state_output_chip(__('Landed cost', 'ffl-hub'), self::state_money($row['landed_cost'] ?? null), 'cost');
        self::render_state_output_chip(__('Raw / Effective MAP', 'ffl-hub'), self::state_money($row['map_price'] ?? null) . ' / ' . self::state_money($row['effective_map_price'] ?? null), 'price');
        self::render_state_output_chip(__('MSRP', 'ffl-hub'), self::state_money($row['msrp'] ?? null), 'price');
        self::render_state_output_chip(
            __('Ship weight / box', 'ffl-hub'),
            trim(self::state_value($row['shipping_weight_oz'] ?? null) . ' oz / ' . self::state_dimensions($row)),
            self::truthy_state($row['manual_shipping_override'] ?? null) ? 'warning' : 'neutral'
        );
        echo '</div>';
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function state_dimensions(array $row): string
    {
        $length = self::state_value($row['shipping_length_in'] ?? null);
        $width = self::state_value($row['shipping_width_in'] ?? null);
        $height = self::state_value($row['shipping_height_in'] ?? null);

        if ($length === '-' && $width === '-' && $height === '-') {
            return '-';
        }

        return $length . ' x ' . $width . ' x ' . $height . ' in';
    }

    private static function render_state_output_chip(string $label, string $value, string $tone = 'neutral'): void
    {
        $tone = sanitize_html_class($tone);
        $class = 'fflhub-state-output-chip';
        if ($tone !== '' && $tone !== 'neutral') {
            $class .= ' fflhub-state-output-chip--' . $tone;
        }

        echo '<div class="' . esc_attr($class) . '">';
        echo '<div class="fflhub-state-output-chip__label">' . esc_html($label) . '</div>';
        echo '<code>' . esc_html($value) . '</code>';
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function state_distributor_label(array $row): string
    {
        $dist_id = strtolower(trim((string) ($row['distributor_id'] ?? '')));
        if ($dist_id === '') {
            return '-';
        }

        $labels = self::enabled_distributor_options();
        $label = trim((string) ($labels[$dist_id] ?? ''));

        return $label !== '' ? $label . ' (' . $dist_id . ')' : $dist_id;
    }

    /**
     * @param mixed $value
     */
    private static function shipping_chip_tone($value): string
    {
        $shipping = self::state_float_or_null($value);
        if ($shipping === null) {
            return 'neutral';
        }

        return $shipping <= 0.0 ? 'success' : 'warning';
    }

    /**
     * @param mixed $value
     */
    private static function profit_chip_tone($value): string
    {
        $profit = self::state_float_or_null($value);
        if ($profit === null) {
            return 'neutral';
        }

        if ($profit < 0.0) {
            return 'danger';
        }

        return $profit >= 5.0 ? 'success' : 'warning';
    }

    /**
     * @param mixed $value
     */
    private static function margin_chip_tone($value): string
    {
        $margin = self::state_float_or_null($value);
        if ($margin === null) {
            return 'neutral';
        }

        if ($margin < 0.0) {
            return 'danger';
        }

        return $margin >= 5.0 ? 'success' : 'warning';
    }

    private static function render_product_state_editor_script(): void
    {
        ?>
        <script>
            (function () {
                var root = document.getElementById('fflhub-state-editor');
                if (!root) {
                    return;
                }

                var pricingMode = root.querySelector('#fflhub_state_pricing_mode');
                var mapPolicy = root.querySelector('#fflhub_state_map_visibility_policy');
                var mapOverrideMode = root.querySelector('#fflhub_state_map_override_mode');
                var mapOverridePrice = root.querySelector('#fflhub_state_map_override_price');
                var localQty = root.querySelector('input[name="fflhub_state_local_stock_override_qty"]');
                var manualShippingOverride = root.querySelector('input[name="fflhub_state_manual_shipping_override"]');
                var distEnabled = root.querySelector('#fflhub_state_allowed_distributors_enabled');
                var mapApplicable = root.getAttribute('data-map-applicable') === '1';

                function setFieldEnabled(field, enabled) {
                    if (!field) {
                        return;
                    }

                    field.classList.toggle('is-disabled', !enabled);
                    field.querySelectorAll('input, select, textarea').forEach(function (control) {
                        control.disabled = !enabled;
                    });
                }

                function localQtyEnabled() {
                    if (!localQty) {
                        return false;
                    }

                    var parsed = parseInt(localQty.value || '0', 10);
                    return !isNaN(parsed) && parsed > 0;
                }

                function effectiveMapApplicable() {
                    var mode = mapOverrideMode ? mapOverrideMode.value : 'auto';
                    if (mode === 'force_no_map') {
                        return false;
                    }

                    if (mode === 'manual_price') {
                        var parsed = parseFloat(mapOverridePrice ? mapOverridePrice.value || '0' : '0');
                        return !isNaN(parsed) && parsed > 0;
                    }

                    return mapApplicable;
                }

                function refreshStateEditorControls() {
                    var mode = pricingMode ? pricingMode.value : '';
                    var hasEffectiveMap = effectiveMapApplicable();
                    root.querySelectorAll('[data-pricing-field]').forEach(function (field) {
                        setFieldEnabled(field, field.getAttribute('data-pricing-field') === mode);
                    });

                    root.querySelectorAll('[data-map-override-field="manual_price"]').forEach(function (field) {
                        setFieldEnabled(field, mapOverrideMode && mapOverrideMode.value === 'manual_price');
                    });

                    root.querySelectorAll('[data-map-field="visibility"]').forEach(function (field) {
                        setFieldEnabled(field, hasEffectiveMap);
                    });

                    root.querySelectorAll('[data-map-field="quote_free_shipping"]').forEach(function (field) {
                        setFieldEnabled(field, hasEffectiveMap && mapPolicy && mapPolicy.value === 'email_for_quote');
                    });

                    root.querySelectorAll('[data-local-stock-field="free_shipping"]').forEach(function (field) {
                        setFieldEnabled(field, localQtyEnabled());
                    });

                    root.querySelectorAll('[data-shipping-override-field="measurements"]').forEach(function (field) {
                        setFieldEnabled(field, !!(manualShippingOverride && manualShippingOverride.checked));
                    });

                    root.querySelectorAll('[data-distributor-lock-field="select"]').forEach(function (field) {
                        setFieldEnabled(field, !!(distEnabled && distEnabled.checked));
                    });
                }

                [pricingMode, mapPolicy, mapOverrideMode, mapOverridePrice, localQty, manualShippingOverride, distEnabled].forEach(function (control) {
                    if (!control) {
                        return;
                    }
                    control.addEventListener('change', refreshStateEditorControls);
                    control.addEventListener('input', refreshStateEditorControls);
                });

                refreshStateEditorControls();
            }());
        </script>
        <?php
    }

    private static function state_value_raw($value, string $default = ''): string
    {
        $value = trim((string) $value);
        return ($value === '') ? $default : $value;
    }

    private static function state_value($value): string
    {
        $value = trim((string) $value);
        return $value === '' ? '-' : $value;
    }

    private static function state_money($value): string
    {
        $value = preg_replace('/[^0-9.\-]/', '', trim((string) $value));
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return '-';
        }

        return '$' . number_format((float) $value, 2, '.', '');
    }

    private static function state_estimated_usps_shipping($value): string
    {
        $formatted = self::state_money($value);
        return ($formatted === '-') ? __('Not calculated', 'ffl-hub') : $formatted;
    }

    private static function state_percent($value): string
    {
        $value = preg_replace('/[^0-9.\-]/', '', trim((string) $value));
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return '-';
        }

        return number_format((float) $value, 2, '.', '') . '%';
    }

    /**
     * @param array<string,mixed> $row
     * @return array{distributor_shipping_cost:?float,shipping_cost_used:?float,dealer_shipping_basis:?float,cost_basis:?float,processor_fee:?float,net_profit:?float,margin_percent:?float,fee_percent_label:string,shipping_explanation:string}
     */
    private static function product_state_profit_metrics(array $row): array
    {
        $dealer = self::state_float_or_null($row['dealer_price'] ?? null);
        $shipping = self::state_float_or_null($row['shipping_cost'] ?? null);
        $estimated_usps_shipping = self::state_float_or_null($row['estimated_usps_shipping_cost'] ?? null);
        $landed = self::state_float_or_null($row['landed_cost'] ?? null);
        $sell = self::state_float_or_null($row['computed_sell_price'] ?? null);
        $dropship_enabled = self::truthy_state($row['dropship_enabled'] ?? null);
        $use_usps_shipping = Options::get_use_product_state_usps_shipping() && !$dropship_enabled;

        $distributor_shipping_cost = $use_usps_shipping ? 0.0 : max(0.0, $shipping ?? 0.0);
        $shipping_cost_used = $use_usps_shipping
            ? max(0.0, $estimated_usps_shipping ?? 0.0)
            : $distributor_shipping_cost;

        $dealer_shipping_basis = ($dealer !== null && $dealer > 0.0)
            ? $dealer + $shipping_cost_used
            : null;
        $cost_basis = $dealer_shipping_basis ?? (($landed !== null && $landed > 0.0) ? $landed : null);

        $fee_percent = (float) Options::get_payment_processor_fee_percent();
        if (!is_finite($fee_percent) || $fee_percent < 0.0) {
            $fee_percent = 0.0;
        }

        $fee_fraction = min(0.99, $fee_percent / 100.0);
        $processor_fee = ($sell !== null && $sell > 0.0)
            ? round($sell * $fee_fraction, 2)
            : null;

        $net_profit = null;
        $margin_percent = null;
        if ($sell !== null && $sell > 0.0 && $cost_basis !== null) {
            $net_profit = round($sell - $cost_basis - ($processor_fee ?? 0.0), 2);
            $margin_percent = round(($net_profit / $sell) * 100.0, 2);
        }

        return [
            'distributor_shipping_cost' => $distributor_shipping_cost,
            'shipping_cost_used' => $shipping_cost_used,
            'dealer_shipping_basis' => $dealer_shipping_basis,
            'cost_basis' => $cost_basis,
            'processor_fee' => $processor_fee,
            'net_profit' => $net_profit,
            'margin_percent' => $margin_percent,
            'fee_percent_label' => number_format($fee_percent, 2, '.', ''),
            'shipping_explanation' => $use_usps_shipping
                ? __('Net profit ignores distributor-to-dealer freight and uses the Product State USPS estimate, plus card processing cost. Stored landed cost remains visible for auditing.', 'ffl-hub')
                : __('Net profit uses dealer cost plus distributor shipping and estimated card processing cost. Stored landed cost remains visible for auditing.', 'ffl-hub'),
        ];
    }

    private static function state_float_or_null($value): ?float
    {
        $value = preg_replace('/[^0-9.\-]/', '', trim((string) $value));
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }

    private static function state_decimal_for_input($value): string
    {
        $value = preg_replace('/[^0-9.\-]/', '', trim((string) $value));
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    private static function state_int_for_input($value): string
    {
        $value = trim((string) $value);
        if ($value === '' || !is_numeric($value)) {
            return '';
        }

        return (string) max(0, (int) $value);
    }

    /**
     * The product_state table intentionally stores only regular/sale outputs.
     * The customer-facing active price is derived the same way Woo derives
     * `_price`: sale price wins when present, otherwise regular price.
     *
     * @param array<string,mixed> $row
     */
    private static function derived_public_price(array $row): ?string
    {
        $sale = trim((string) ($row['public_sale_price'] ?? ''));
        if ($sale !== '' && is_numeric($sale)) {
            return $sale;
        }

        $regular = trim((string) ($row['public_regular_price'] ?? ''));
        return ($regular !== '' && is_numeric($regular)) ? $regular : null;
    }

    private static function state_yes_no($value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '-';
        }

        return ((float) $value > 0.0) ? 'yes' : 'no';
    }

    private static function truthy_state($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value > 0.0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on', 'enabled'], true);
    }

    public static function save_product_state_controls(int $post_id): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (
            ! isset($_POST['fflhub_product_state_nonce']) ||
            ! wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['fflhub_product_state_nonce'])),
                'fflhub_save_product_state_controls'
            )
        ) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        $allowed_distributors = $_POST['fflhub_state_allowed_distributors'] ?? [];
        if (!is_array($allowed_distributors)) {
            $allowed_distributors = [$allowed_distributors];
        }

        $result = ProductStateStore::update_admin_controls($post_id, [
            'status' => self::sanitize_state_text_post('fflhub_state_status'),
            'pricing_mode' => self::sanitize_state_text_post('fflhub_state_pricing_mode'),
            'pricing_percent' => self::sanitize_state_decimal_post('fflhub_state_pricing_percent'),
            'pricing_fixed_price' => self::sanitize_state_decimal_post('fflhub_state_pricing_fixed_price'),
            'pricing_fixed_profit' => self::sanitize_state_decimal_post('fflhub_state_pricing_fixed_profit'),
            'map_visibility_policy' => self::sanitize_state_text_post('fflhub_state_map_visibility_policy'),
            'map_override_mode' => self::sanitize_state_text_post('fflhub_state_map_override_mode'),
            'map_override_price' => self::sanitize_state_decimal_post('fflhub_state_map_override_price'),
            'quote_free_shipping_override' => isset($_POST['fflhub_state_quote_free_shipping_override']) ? 1 : 0,
            'manual_shipping_override' => isset($_POST['fflhub_state_manual_shipping_override']) ? 1 : 0,
            'manual_shipping_weight_oz' => self::sanitize_state_decimal_post('fflhub_state_manual_shipping_weight_oz'),
            'manual_shipping_length_in' => self::sanitize_state_decimal_post('fflhub_state_manual_shipping_length_in'),
            'manual_shipping_width_in' => self::sanitize_state_decimal_post('fflhub_state_manual_shipping_width_in'),
            'manual_shipping_height_in' => self::sanitize_state_decimal_post('fflhub_state_manual_shipping_height_in'),
            'stock_oos_override' => isset($_POST['fflhub_state_stock_oos_override']) ? 1 : 0,
            'local_stock_override_qty' => self::sanitize_state_int_post('fflhub_state_local_stock_override_qty'),
            'local_stock_free_shipping' => isset($_POST['fflhub_state_local_stock_free_shipping']) ? 1 : 0,
            'allowed_distributors_enabled' => isset($_POST['fflhub_state_allowed_distributors_enabled']) ? 1 : 0,
            'allowed_distributors' => array_map(
                static fn($value): string => sanitize_key((string) wp_unslash($value)),
                $allowed_distributors
            ),
        ]);

        // A lock changes which normalized offers are eligible to win. Dirty
        // this UPC so the regular offer pipeline reselects it on its next run.
        if (!empty($result['ok']) && !empty($result['distributor_lock_changed'])) {
            DistributorOffersStore::mark_upc_changed((string) ($result['upc'] ?? ''));
        }
    }

    private static function sanitize_state_text_post(string $post_key): string
    {
        if (!isset($_POST[$post_key])) {
            return '';
        }

        return sanitize_text_field(wp_unslash((string) $_POST[$post_key]));
    }

    private static function sanitize_state_decimal_post(string $post_key): string
    {
        if (!isset($_POST[$post_key])) {
            return '';
        }

        $raw = trim(sanitize_text_field(wp_unslash((string) $_POST[$post_key])));
        if ($raw === '' || !is_numeric($raw)) {
            return '';
        }

        return (string) max(0.0, (float) $raw);
    }

    private static function sanitize_state_int_post(string $post_key): string
    {
        if (!isset($_POST[$post_key])) {
            return '';
        }

        $raw = trim(sanitize_text_field(wp_unslash((string) $_POST[$post_key])));
        if ($raw === '' || !is_numeric($raw)) {
            return '';
        }

        return (string) max(0, (int) $raw);
    }

    /**
     * @return array<string,string>
     */
    private static function product_state_pricing_mode_options(): array
    {
        return [
            'global_percent' => __('Global Percent', 'ffl-hub'),
            'fixed_percent' => __('Fixed Percent', 'ffl-hub'),
            'fixed_price' => __('Fixed Price', 'ffl-hub'),
            'fixed_profit' => __('Fixed Profit', 'ffl-hub'),
            'map_price' => __('MAP Price', 'ffl-hub'),
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function product_state_map_policy_options(): array
    {
        return [
            'none' => __('None', 'ffl-hub'),
            Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE => __('Add to Cart for Price', 'ffl-hub'),
            Options::MAP_POLICY_EMAIL_FOR_QUOTE => __('Email for Quote', 'ffl-hub'),
            Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART => __('No Email, No Add to Cart', 'ffl-hub'),
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function product_state_map_override_mode_options(): array
    {
        return [
            'auto' => __('Auto - use selected offer MAP', 'ffl-hub'),
            'manual_price' => __('Manual MAP Price', 'ffl-hub'),
            'force_no_map' => __('Force No MAP', 'ffl-hub'),
        ];
    }

    /**
     * @return array<string,string> Keyed by distributor id => distributor label.
     */
    private static function enabled_distributor_options(): array
    {
        $options = [];

        foreach (DistributorRegistry::get_modules() as $module) {
            $dist_id = strtolower(trim((string) $module->id()));
            if ($dist_id === '' || !Options::is_distributor_enabled($dist_id)) {
                continue;
            }

            $options[$dist_id] = (string) $module->label();
        }

        return $options;
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    private static function normalize_distributor_lock_ids($raw): array
    {
        if (is_array($raw)) {
            $values = $raw;
        } else {
            $raw_string = trim((string) $raw);
            if ($raw_string === '') {
                return [];
            }

            $values = [];

            $decoded = json_decode($raw_string, true);
            if (is_array($decoded)) {
                $values = $decoded;
            } else {
                $split = preg_split('/\s*,\s*/', $raw_string);
                if (is_array($split)) {
                    $values = $split;
                }
            }
        }

        $normalized = [];
        foreach ($values as $value) {
            $dist_id = strtolower(trim((string) $value));
            if ($dist_id === '') {
                continue;
            }

            $normalized[] = $dist_id;
        }

        return array_values(array_unique($normalized));
    }

}
