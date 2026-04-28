<?php

namespace FFLHub\Admin\ProductMeta;

use FFLHub\Distributor\Core\DistributorRegistry;
use FFLHub\Distributor\Product\DistributorProductHelper;
use FFLHub\Product\ProductMeta;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;
use WC_Product;
use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Adds a meta box to WooCommerce products showing FFLHub metadata
 * and allowing certain fields (FFL required + pricing mode) to be edited.
 */
class ProductMetaBox
{
    public static function init(): void
    {
        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_box'));

        // ✅ Single admin-save entry point for your meta + pricing update
        add_action(
            'woocommerce_process_product_meta',
            array(__CLASS__, 'save_meta_and_update_price'),
            999,
            1
        );
    }

    public static function add_meta_box(): void
    {
        add_meta_box(
            'ffl_product_meta',
            __('FFLHub Product Metadata', 'ffl-hub'),
            array(__CLASS__, 'render_meta_box'),
            'product',
            'side',
            'default'
        );
    }

    public static function render_meta_box(WP_Post $post): void
    {
        wp_nonce_field('fflhub_save_product_meta', 'ProductMeta_nonce');

        /** @var WC_Product|null $product */
        $product = function_exists('wc_get_product') ? wc_get_product($post->ID) : null;

        if (! $product) {
            echo '<p style="margin:0;font-size:11px;color:#6b7280;">' .
                esc_html__('Unable to load product data.', 'ffl-hub') .
                '</p>';
            return;
        }

        // Read-only fields (exclude pricing meta we are making editable below).
        $fields = array(
            ProductMeta::FFLHUB_MANAGED_META             => __('Managed by FFLHub', 'ffl-hub'),
            ProductMeta::FFLHUB_UPC_META                 => __('UPC', 'ffl-hub'),
            ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META  => __('Primary Distributor', 'ffl-hub'),
            ProductMeta::FFLHUB_LAST_TRUE_COST_META      => __('Last True Cost', 'ffl-hub'),
            ProductMeta::FFLHUB_LAST_DEALER_PRICE_META   => __('Last Dealer Price', 'ffl-hub'),
            ProductMeta::FFLHUB_LAST_MAP_META            => __('Last MAP', 'ffl-hub'),
            ProductMeta::FFLHUB_LAST_MSRP_META           => __('Last MSRP', 'ffl-hub'),
            ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META => __('Last Computed Price', 'ffl-hub'),
            ProductMeta::FFLHUB_LAST_SHIPPING_COST_META            => __('Shipping Cost', 'ffl-hub'),
            ProductMeta::FFLHUB_BOM_TOTAL_COST_META                => __('BOM Total Cost', 'ffl-hub'),
            ProductMeta::FFLHUB_DROPSHIP_ENABLED_META    => __('Drop Ship Enabled', 'ffl-hub'),
            ProductMeta::FFLHUB_SHIPPING_WEIGHT_META     => __('Shipping Weight (oz)', 'ffl-hub'),
            ProductMeta::FFLHUB_SHIPPING_LENGTH_IN_META  => __('Shipping Length (in)', 'ffl-hub'),
            ProductMeta::FFLHUB_SHIPPING_WIDTH_IN_META   => __('Shipping Width (in)', 'ffl-hub'),
            ProductMeta::FFLHUB_SHIPPING_HEIGHT_IN_META  => __('Shipping Height (in)', 'ffl-hub'),
            ProductMeta::FFLHUB_LAST_SYNC_META           => __('Last Sync At', 'ffl-hub'),
        );

        echo '<table class="fflhub-meta-table" style="width:100%;border-collapse:collapse;">';

        foreach ($fields as $key => $label) {
            $value = $product->get_meta($key, true);
            $display_value = null;

            if ($key === ProductMeta::FFLHUB_DROPSHIP_ENABLED_META) {
                if ($value !== '' || (string) $value === '0') {
                    $normalized = strtolower(trim((string) $value));
                    $is_enabled = in_array($normalized, array('1', 'true', 'yes', 'y', 'on'), true);
                    $display_value = $is_enabled ? __('Yes', 'ffl-hub') : __('No', 'ffl-hub');
                }
            } elseif ($key === ProductMeta::FFLHUB_SHIPPING_WEIGHT_META) {
                if ($value !== '' || (string) $value === '0') {
                    $weight = trim((string) $value);
                    if (is_numeric($weight)) {
                        $weight = rtrim(rtrim(number_format((float) $weight, 2, '.', ''), '0'), '.');
                    }
                    $display_value = $weight . ' oz';
                }
            } elseif (
                $key === ProductMeta::FFLHUB_SHIPPING_LENGTH_IN_META
                || $key === ProductMeta::FFLHUB_SHIPPING_WIDTH_IN_META
                || $key === ProductMeta::FFLHUB_SHIPPING_HEIGHT_IN_META
            ) {
                if ($value !== '' || (string) $value === '0') {
                    $dim = trim((string) $value);
                    if (is_numeric($dim)) {
                        $dim = rtrim(rtrim(number_format((float) $dim, 2, '.', ''), '0'), '.');
                    }
                    $display_value = $dim . ' in';
                }
            } elseif ($key === ProductMeta::FFLHUB_BOM_TOTAL_COST_META) {
                if ($value !== '' || (string) $value === '0') {
                    $raw_total = trim((string) $value);
                    if (is_numeric($raw_total)) {
                        $display_value = '$' . number_format((float) $raw_total, 2, '.', '');
                    } else {
                        $display_value = $raw_total;
                    }
                }
            } elseif ($value !== '' || (string) $value === '0') {
                $display_value = (string) $value;
            }

            echo '<tr>';
            echo '<th style="text-align:left;padding:2px 4px;font-weight:600;font-size:11px;">' .
                esc_html($label) .
                '</th>';
            echo '<td style="text-align:right;padding:2px 4px;font-size:11px;">';

            if ($display_value === null || $display_value === '') {
                echo '<span style="color:#9ca3af;">' . esc_html__('—', 'ffl-hub') . '</span>';
            } else {
                echo esc_html($display_value);
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';

        // Editable checkboxes: FFL Required + SOT Required.
        $raw_required = $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true);
        $ffl_required = (string) $raw_required === '1' || $raw_required === 1 || $raw_required === true;
        $raw_sot_required = $product->get_meta(ProductMeta::FFLHUB_SOT_REQUIRED_META, true);
        $sot_required = (string) $raw_sot_required === '1' || $raw_sot_required === 1 || $raw_sot_required === true;
        $raw_stock_oos_override = $product->get_meta(ProductMeta::FFLHUB_STOCK_OOS_OVERRIDE_META, true);
        $stock_oos_override = self::is_truthy_meta($raw_stock_oos_override);
        $raw_local_stock_override_enabled = $product->get_meta(ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_ENABLED_META, true);
        $local_stock_override_enabled = self::is_truthy_meta($raw_local_stock_override_enabled);
        $raw_local_stock_override_qty = $product->get_meta(ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_QTY_META, true);
        $local_stock_override_qty = is_numeric((string) $raw_local_stock_override_qty)
            ? max(0, (int) $raw_local_stock_override_qty)
            : 0;
        $raw_manual_shipping_override = $product->get_meta(ProductMeta::FFLHUB_MANUAL_SHIPPING_OVERRIDE_META, true);
        $manual_shipping_override = self::is_truthy_meta($raw_manual_shipping_override);
        $shipping_weight_input = self::normalize_decimal_for_input(
            $product->get_meta(ProductMeta::FFLHUB_SHIPPING_WEIGHT_META, true)
        );
        $shipping_length_input = self::normalize_decimal_for_input(
            $product->get_meta(ProductMeta::FFLHUB_SHIPPING_LENGTH_IN_META, true)
        );
        $shipping_width_input = self::normalize_decimal_for_input(
            $product->get_meta(ProductMeta::FFLHUB_SHIPPING_WIDTH_IN_META, true)
        );
        $shipping_height_input = self::normalize_decimal_for_input(
            $product->get_meta(ProductMeta::FFLHUB_SHIPPING_HEIGHT_IN_META, true)
        );
        $distributor_lock_enabled = self::is_truthy_meta(
            $product->get_meta(ProductMeta::FFLHUB_DISTRIBUTOR_LOCK_ENABLED_META, true)
        );
        $distributor_lock_ids = self::normalize_distributor_lock_ids(
            $product->get_meta(ProductMeta::FFLHUB_DISTRIBUTOR_LOCK_IDS_META, true)
        );
        $enabled_distributors = self::enabled_distributor_options();
        if (!empty($distributor_lock_ids) && !empty($enabled_distributors)) {
            $distributor_lock_ids = array_values(
                array_intersect($distributor_lock_ids, array_keys($enabled_distributors))
            );
        }

        echo '<div style="margin-top:8px;padding-top:6px;border-top:1px solid #e5e7eb;">';
        echo '<label style="display:flex;align-items:center;font-size:11px;gap:6px;">';
        echo '<input type="checkbox" name="fflhub_ffl_required" value="1" ' .
            checked(true, $ffl_required, false) .
            ' />';
        echo '<span style="font-weight:600;">' .
            esc_html__('FFL Required', 'ffl-hub') .
            '</span>';
        echo '</label>';
        echo '<label style="display:flex;align-items:center;font-size:11px;gap:6px;margin-top:6px;">';
        echo '<input type="checkbox" name="fflhub_sot_required" value="1" ' .
            checked(true, $sot_required, false) .
            ' />';
        echo '<span style="font-weight:600;">' .
            esc_html__('SOT Required', 'ffl-hub') .
            '</span>';
        echo '</label>';
        echo '<label style="display:flex;align-items:center;font-size:11px;gap:6px;margin-top:6px;">';
        echo '<input type="checkbox" name="fflhub_stock_oos_override" value="1" ' .
            checked(true, $stock_oos_override, false) .
            ' />';
        echo '<span style="font-weight:600;">' .
            esc_html__('Out of Stock Override', 'ffl-hub') .
            '</span>';
        echo '</label>';
        echo '<span style="display:block;margin-top:4px;font-size:11px;color:#6b7280;">' .
            esc_html__('When enabled, distributor stock is treated as unavailable. Local Stock Override can still make the product purchasable.', 'ffl-hub') .
            '</span>';

        echo '<label style="display:flex;align-items:center;font-size:11px;gap:6px;margin-top:6px;">';
        echo '<input id="fflhub_local_stock_override_enabled" type="checkbox" name="fflhub_local_stock_override_enabled" value="1" ' .
            checked(true, $local_stock_override_enabled, false) .
            ' />';
        echo '<span style="font-weight:600;">' .
            esc_html__('Local Stock Override', 'ffl-hub') .
            '</span>';
        echo '</label>';

        echo '<p style="margin:6px 0 0;">';
        echo '<label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;">' .
            esc_html__('Local Stock Quantity', 'ffl-hub') .
            '</label>';
        echo '<input id="fflhub_local_stock_override_qty" type="number" step="1" min="0" ' .
            'name="fflhub_local_stock_override_qty" value="' . esc_attr((string) $local_stock_override_qty) . '" ' .
            'style="width:100%;font-size:11px;" />';
        echo '<span style="display:block;margin-top:3px;font-size:11px;color:#6b7280;">' .
            esc_html__('When enabled and quantity is above 0, order placement uses local stock and skips distributor placement for that quantity.', 'ffl-hub') .
            '</span>';
        echo '</p>';
        echo '</div>';

        // 🆕 Editable pricing controls
        echo '<div style="margin-top:8px;padding-top:8px;border-top:1px solid #e5e7eb;">';
        echo '<label style="display:flex;align-items:center;font-size:11px;gap:6px;">';
        echo '<input id="fflhub_distributor_lock_enabled" type="checkbox" name="fflhub_distributor_lock_enabled" value="1" ' .
            checked(true, $distributor_lock_enabled, false) .
            ' />';
        echo '<span style="font-weight:600;">' .
            esc_html__('Distributor Lock', 'ffl-hub') .
            '</span>';
        echo '</label>';
        echo '<span style="display:block;margin-top:4px;font-size:11px;color:#6b7280;">' .
            esc_html__('When enabled, select one or more enabled distributors to lock this managed product to.', 'ffl-hub') .
            '</span>';

        if (empty($enabled_distributors)) {
            echo '<span style="display:block;margin-top:4px;font-size:11px;color:#b45309;">' .
                esc_html__('No enabled distributors found. Enable distributors in FFLHub settings first.', 'ffl-hub') .
                '</span>';
        } else {
            $select_rows = min(6, max(3, count($enabled_distributors)));
            echo '<p style="margin:8px 0 0;">';
            echo '<label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;">' .
                esc_html__('Locked Distributors', 'ffl-hub') .
                '</label>';
            echo '<select id="fflhub_distributor_lock_ids" name="fflhub_distributor_lock_ids[]" multiple="multiple" size="' .
                esc_attr((string) $select_rows) .
                '" style="width:100%;font-size:11px;">';

            foreach ($enabled_distributors as $dist_id => $dist_label) {
                echo '<option value="' . esc_attr($dist_id) . '" ' .
                    selected(in_array($dist_id, $distributor_lock_ids, true), true, false) .
                    '>' . esc_html($dist_label . ' (' . $dist_id . ')') . '</option>';
            }

            echo '</select>';
            echo '<span style="display:block;margin-top:3px;font-size:11px;color:#6b7280;">' .
                esc_html__('Hold Ctrl (Windows) or Command (Mac) to select multiple distributors.', 'ffl-hub') .
                '</span>';
            echo '</p>';
        }

        echo '</div>';

        echo '<div style="margin-top:8px;padding-top:8px;border-top:1px solid #e5e7eb;">';
        echo '<label style="display:flex;align-items:center;font-size:11px;gap:6px;">';
        echo '<input id="fflhub_manual_shipping_override" type="checkbox" name="fflhub_manual_shipping_override" value="1" ' .
            checked(true, $manual_shipping_override, false) .
            ' />';
        echo '<span style="font-weight:600;">' .
            esc_html__('Manual Shipping Override', 'ffl-hub') .
            '</span>';
        echo '</label>';
        echo '<span style="display:block;margin-top:4px;font-size:11px;color:#6b7280;">' .
            esc_html__('When enabled, sync jobs will not overwrite these shipping values.', 'ffl-hub') .
            '</span>';

        echo '<p style="margin:8px 0 6px;">';
        echo '<label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;">' .
            esc_html__('Shipping Weight (oz)', 'ffl-hub') .
            '</label>';
        echo '<input id="fflhub_shipping_weight_manual" type="number" step="0.01" min="0" ' .
            'name="fflhub_shipping_weight_manual" value="' . esc_attr($shipping_weight_input) . '" ' .
            'style="width:100%;font-size:11px;" />';
        echo '</p>';

        echo '<p style="margin:0 0 6px;">';
        echo '<label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;">' .
            esc_html__('Shipping Length (in)', 'ffl-hub') .
            '</label>';
        echo '<input id="fflhub_shipping_length_manual" type="number" step="0.01" min="0" ' .
            'name="fflhub_shipping_length_manual" value="' . esc_attr($shipping_length_input) . '" ' .
            'style="width:100%;font-size:11px;" />';
        echo '</p>';

        echo '<p style="margin:0 0 6px;">';
        echo '<label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;">' .
            esc_html__('Shipping Width (in)', 'ffl-hub') .
            '</label>';
        echo '<input id="fflhub_shipping_width_manual" type="number" step="0.01" min="0" ' .
            'name="fflhub_shipping_width_manual" value="' . esc_attr($shipping_width_input) . '" ' .
            'style="width:100%;font-size:11px;" />';
        echo '</p>';

        echo '<p style="margin:0;">';
        echo '<label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;">' .
            esc_html__('Shipping Height (in)', 'ffl-hub') .
            '</label>';
        echo '<input id="fflhub_shipping_height_manual" type="number" step="0.01" min="0" ' .
            'name="fflhub_shipping_height_manual" value="' . esc_attr($shipping_height_input) . '" ' .
            'style="width:100%;font-size:11px;" />';
        echo '</p>';
        echo '</div>';

        $mode_raw = $product->get_meta(ProductMeta::FFLHUB_MARKUP_MODE_META, true);
        $mode     = ($mode_raw === '' && (string) $mode_raw !== '0')
            ? ProductMeta::MARKUP_MODE_GLOBAL
            : (int) $mode_raw;

        $pct_raw   = $product->get_meta(ProductMeta::FFLHUB_MARKUP_PERCENT_META, true);
        $pct_value = is_numeric($pct_raw) ? (string) $pct_raw : '';

        $fixed_raw   = $product->get_meta(ProductMeta::FFLHUB_FIXED_PRICE_META, true);
        $fixed_value = is_numeric($fixed_raw) ? (string) $fixed_raw : '';

        $map_real_mode_raw = $product->get_meta(ProductMeta::FFLHUB_MAP_REAL_PRICE_MODE_META, true);
        $map_real_mode = ($map_real_mode_raw === '' && (string) $map_real_mode_raw !== '0')
            ? ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED
            : (int) $map_real_mode_raw;
        if (! in_array($map_real_mode, [ProductMeta::MAP_REAL_PRICE_MODE_FIXED_OFFSET, ProductMeta::MAP_REAL_PRICE_MODE_PERCENTAGE, ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED, ProductMeta::MAP_REAL_PRICE_MODE_FIXED_PROFIT], true)) {
            $map_real_mode = ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED;
        }

        $map_real_offset_raw = $product->get_meta(ProductMeta::FFLHUB_MAP_REAL_PRICE_OFFSET_META, true);
        $map_real_offset_value = (is_numeric($map_real_offset_raw) && (float) $map_real_offset_raw >= 0)
            ? (string) $map_real_offset_raw
            : '';

        $map_real_percent_raw = $product->get_meta(ProductMeta::FFLHUB_MAP_REAL_PRICE_PERCENT_META, true);
        $map_real_percent_value = (is_numeric($map_real_percent_raw) && (float) $map_real_percent_raw >= 0)
            ? (string) $map_real_percent_raw
            : '';
        $map_real_profit_raw = $product->get_meta(ProductMeta::FFLHUB_MAP_REAL_PRICE_FIXED_PROFIT_META, true);
        $map_real_profit_value = (is_numeric($map_real_profit_raw) && (float) $map_real_profit_raw >= 0)
            ? (string) $map_real_profit_raw
            : '';
        $map_real_free_shipping_override = self::is_truthy_meta(
            $product->get_meta(ProductMeta::FFLHUB_MAP_REAL_PRICE_FREE_SHIPPING_OVERRIDE_META, true)
        );
        $map_real_preview_price = ($mode === ProductMeta::MARKUP_MODE_MAP_PRICE)
            ? DistributorProductHelper::get_map_real_price_for_product($product)
            : null;
        $map_real_preview_value = is_numeric($map_real_preview_price) && (float) $map_real_preview_price > 0
            ? '$' . number_format((float) $map_real_preview_price, 2, '.', '')
            : __('N/A', 'ffl-hub');

        $preview_true_cost_raw = $product->get_meta(ProductMeta::FFLHUB_LAST_TRUE_COST_META, true);
        $preview_dealer_cost_raw = $product->get_meta(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, true);
        $preview_shipping_raw = $product->get_meta(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, true);
        $preview_map_raw = $product->get_meta(ProductMeta::FFLHUB_LAST_MAP_META, true);
        $preview_msrp_raw = $product->get_meta(ProductMeta::FFLHUB_LAST_MSRP_META, true);
        $preview_recommended_raw = $product->get_meta(ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META, true);

        $preview_true_cost = (is_numeric($preview_true_cost_raw) && (float) $preview_true_cost_raw > 0.0)
            ? (float) $preview_true_cost_raw
            : 0.0;
        $preview_dealer_cost = (is_numeric($preview_dealer_cost_raw) && (float) $preview_dealer_cost_raw > 0.0)
            ? (float) $preview_dealer_cost_raw
            : 0.0;
        $preview_cost_base = ($preview_true_cost > 0.0) ? $preview_true_cost : $preview_dealer_cost;
        $preview_shipping = (is_numeric($preview_shipping_raw) && (float) $preview_shipping_raw >= 0.0)
            ? (float) $preview_shipping_raw
            : 0.0;
        $preview_map = (is_numeric($preview_map_raw) && (float) $preview_map_raw > 0.0)
            ? (float) $preview_map_raw
            : 0.0;
        $preview_msrp = (is_numeric($preview_msrp_raw) && (float) $preview_msrp_raw > 0.0)
            ? (float) $preview_msrp_raw
            : 0.0;
        $preview_map_base = ($preview_map > 0.0) ? $preview_map : $preview_msrp;
        $preview_recommended = (is_numeric($preview_recommended_raw) && (float) $preview_recommended_raw > 0.0)
            ? (float) $preview_recommended_raw
            : 0.0;
        if ($preview_recommended <= 0.0 && is_numeric($product->get_regular_price()) && (float) $product->get_regular_price() > 0.0) {
            $preview_recommended = (float) $product->get_regular_price();
        }
        if ($preview_recommended <= 0.0 && is_numeric($product->get_price()) && (float) $product->get_price() > 0.0) {
            $preview_recommended = (float) $product->get_price();
        }
        $preview_fee_percent = (float) Options::get_payment_processor_fee_percent();
        if (!is_finite($preview_fee_percent) || $preview_fee_percent < 0.0) {
            $preview_fee_percent = 0.0;
        }
        $preview_fee_fraction = min(0.99, max(0.0, $preview_fee_percent / 100.0));
        $preview_processor_fee_amount = (is_numeric($map_real_preview_price) && (float) $map_real_preview_price > 0.0)
            ? round(((float) $map_real_preview_price) * $preview_fee_fraction, 2)
            : null;
        $preview_true_cost_value = '$' . number_format($preview_cost_base, 2, '.', '');
        $preview_shipping_value = '$' . number_format($preview_shipping, 2, '.', '');
        $preview_processor_fee_value = ($preview_processor_fee_amount !== null)
            ? '$' . number_format((float) $preview_processor_fee_amount, 2, '.', '')
            : __('N/A', 'ffl-hub');
        $preview_fee_percent_label = number_format($preview_fee_percent, 2, '.', '');

        echo '<div style="margin-top:8px;padding-top:8px;border-top:1px solid #e5e7eb;">';
        echo '<div style="font-size:11px;font-weight:700;margin-bottom:6px;">' .
            esc_html__('FFLHub Pricing', 'ffl-hub') .
            '</div>';

        // Mode select
        echo '<p style="margin:0 0 6px;">';
        echo '<label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;">' .
            esc_html__('Pricing Mode', 'ffl-hub') .
            '</label>';

        echo '<select id="fflhub_markup_mode" name="fflhub_markup_mode" style="width:100%;font-size:11px;">';

        echo '<option value="' . esc_attr((string) ProductMeta::MARKUP_MODE_GLOBAL) . '" ' .
            selected($mode, ProductMeta::MARKUP_MODE_GLOBAL, false) . '>' .
            esc_html__('Global Markup', 'ffl-hub') .
            '</option>';

        echo '<option value="' . esc_attr((string) ProductMeta::MARKUP_MODE_FIXED_PCT) . '" ' .
            selected($mode, ProductMeta::MARKUP_MODE_FIXED_PCT, false) . '>' .
            esc_html__('Fixed Percent', 'ffl-hub') .
            '</option>';

        echo '<option value="' . esc_attr((string) ProductMeta::MARKUP_MODE_FIXED_PRICE) . '" ' .
            selected($mode, ProductMeta::MARKUP_MODE_FIXED_PRICE, false) . '>' .
            esc_html__('Fixed Price', 'ffl-hub') .
            '</option>';

        echo '<option value="' . esc_attr((string) ProductMeta::MARKUP_MODE_MAP_PRICE) . '" ' .
            selected($mode, ProductMeta::MARKUP_MODE_MAP_PRICE, false) . '>' .
            esc_html__('MAP Price Quote Required Mode', 'ffl-hub') .
            '</option>';

        echo '</select>';
        echo '</p>';

        // Percent input
        echo '<p style="margin:0 0 6px;">';
        echo '<label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;">' .
            esc_html__('Fixed Markup Percent', 'ffl-hub') .
            '</label>';
        echo '<input id="fflhub_markup_percent" type="number" step="0.01" min="0" ' .
            'name="fflhub_markup_percent" value="' . esc_attr($pct_value) . '" ' .
            'style="width:100%;font-size:11px;" />';
        echo '<span style="display:block;margin-top:3px;font-size:11px;color:#6b7280;">' .
            esc_html__('Used only in Fixed Percent mode (enter 25 for 25%).', 'ffl-hub') .
            '</span>';
        echo '</p>';

        // Fixed price input
        echo '<p style="margin:0;">';
        echo '<label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;">' .
            esc_html__('Fixed Price', 'ffl-hub') .
            '</label>';
        echo '<input id="fflhub_fixed_price" type="number" step="0.01" min="0" ' .
            'name="fflhub_fixed_price" value="' . esc_attr($fixed_value) . '" ' .
            'style="width:100%;font-size:11px;" />';
        echo '<span style="display:block;margin-top:3px;font-size:11px;color:#6b7280;">' .
            esc_html__('Used only in Fixed Price mode (final sell price).', 'ffl-hub') .
            '</span>';
        echo '</p>';

        echo '<p style="margin:8px 0 6px;">';
        echo '<label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;">' .
            esc_html__('MAP Price Real Price Mode', 'ffl-hub') .
            '</label>';
        echo '<select id="fflhub_map_real_price_mode" name="fflhub_map_real_price_mode" style="width:100%;font-size:11px;">';
        echo '<option value="' . esc_attr((string) ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED) . '" ' .
            selected($map_real_mode, ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED, false) . '>' .
            esc_html__('Default (Recommended Price)', 'ffl-hub') .
            '</option>';
        echo '<option value="' . esc_attr((string) ProductMeta::MAP_REAL_PRICE_MODE_FIXED_OFFSET) . '" ' .
            selected($map_real_mode, ProductMeta::MAP_REAL_PRICE_MODE_FIXED_OFFSET, false) . '>' .
            esc_html__('Fixed Offset', 'ffl-hub') .
            '</option>';
        echo '<option value="' . esc_attr((string) ProductMeta::MAP_REAL_PRICE_MODE_PERCENTAGE) . '" ' .
            selected($map_real_mode, ProductMeta::MAP_REAL_PRICE_MODE_PERCENTAGE, false) . '>' .
            esc_html__('Percentage', 'ffl-hub') .
            '</option>';
        echo '<option value="' . esc_attr((string) ProductMeta::MAP_REAL_PRICE_MODE_FIXED_PROFIT) . '" ' .
            selected($map_real_mode, ProductMeta::MAP_REAL_PRICE_MODE_FIXED_PROFIT, false) . '>' .
            esc_html__('Fixed Profit', 'ffl-hub') .
            '</option>';
        echo '</select>';
        echo '<span style="display:block;margin-top:3px;font-size:11px;color:#6b7280;">' .
            esc_html__('Available only when Pricing Mode is MAP Price Quote Required Mode.', 'ffl-hub') .
            '</span>';
        echo '</p>';

        echo '<p style="margin:0 0 6px;">';
        echo '<label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;">' .
            esc_html__('MAP Real Price Offset', 'ffl-hub') .
            '</label>';
        echo '<input id="fflhub_map_real_price_offset" type="number" step="0.01" min="0" ' .
            'name="fflhub_map_real_price_offset" value="' . esc_attr($map_real_offset_value) . '" ' .
            'style="width:100%;font-size:11px;" />';
        echo '<span style="display:block;margin-top:3px;font-size:11px;color:#6b7280;">' .
            esc_html__('Used only in Fixed Offset mode (adds this amount to true cost, with dealer price fallback).', 'ffl-hub') .
            '</span>';
        echo '</p>';

        echo '<p style="margin:0;">';
        echo '<label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;">' .
            esc_html__('MAP Real Price Percentage', 'ffl-hub') .
            '</label>';
        echo '<input id="fflhub_map_real_price_percent" type="number" step="0.01" min="0" ' .
            'name="fflhub_map_real_price_percent" value="' . esc_attr($map_real_percent_value) . '" ' .
            'style="width:100%;font-size:11px;" />';
        echo '<span style="display:block;margin-top:3px;font-size:11px;color:#6b7280;">' .
            esc_html__('Used only in Percentage mode (enter 10 for 10% below MAP/MSRP).', 'ffl-hub') .
            '</span>';
        echo '</p>';

        echo '<p style="margin:6px 0 0;">';
        echo '<label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;">' .
            esc_html__('MAP Real Price Fixed Profit', 'ffl-hub') .
            '</label>';
        echo '<input id="fflhub_map_real_price_fixed_profit" type="number" step="0.01" min="0" ' .
            'name="fflhub_map_real_price_fixed_profit" value="' . esc_attr($map_real_profit_value) . '" ' .
            'style="width:100%;font-size:11px;" />';
        echo '<span style="display:block;margin-top:3px;font-size:11px;color:#6b7280;">' .
            esc_html__('Used only in Fixed Profit mode (target net profit dollars after shipping and processor fee).', 'ffl-hub') .
            '</span>';
        echo '</p>';

        echo '<p id="fflhub_map_real_price_preview_wrap" style="margin:8px 0 0;padding:8px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;">';
        echo '<span style="display:block;font-size:11px;font-weight:700;margin-bottom:3px;">' .
            esc_html__('Resulting MAP Real Price', 'ffl-hub') .
            '</span>';
        echo '<span id="fflhub_map_real_price_preview_value" style="display:block;font-size:14px;font-weight:700;">' .
            esc_html($map_real_preview_value) .
            '</span>';
        echo '<span id="fflhub_map_real_price_preview_true_cost" style="display:block;margin-top:4px;font-size:11px;color:#111827;">' .
            esc_html(sprintf(__('True Cost: %s', 'ffl-hub'), $preview_true_cost_value)) .
            '</span>';
        echo '<span id="fflhub_map_real_price_preview_shipping_cost" style="display:block;font-size:11px;color:#111827;">' .
            esc_html(sprintf(__('Shipping Cost: %s', 'ffl-hub'), $preview_shipping_value)) .
            '</span>';
        echo '<span id="fflhub_map_real_price_preview_processor_fee" style="display:block;font-size:11px;color:#111827;">' .
            esc_html(sprintf(__('Processor Fee (%1$s%%): %2$s', 'ffl-hub'), $preview_fee_percent_label, $preview_processor_fee_value)) .
            '</span>';
        echo '<span style="display:block;margin-top:3px;font-size:11px;color:#6b7280;">' .
            esc_html__('Applies to MAP Price Quote Required mode and updates as you edit these MAP real-price fields.', 'ffl-hub') .
            '</span>';
        echo '</p>';

        echo '<p style="margin:8px 0 0;">';
        echo '<label style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:600;">';
        echo '<input id="fflhub_map_real_price_free_shipping_override" type="checkbox" name="fflhub_map_real_price_free_shipping_override" value="1" ' .
            checked($map_real_free_shipping_override, true, false) . ' />';
        echo esc_html__('MAP Price Real Price Free Shipping Override', 'ffl-hub');
        echo '</label>';
        echo '<span style="display:block;margin-top:3px;font-size:11px;color:#6b7280;">' .
            esc_html__('When enabled, quote coupons for this MAP product will force free shipping.', 'ffl-hub') .
            '</span>';
        echo '</p>';

        echo '</div>';

        // 🆕 Inline JS: enable/disable fields immediately when mode changes
?>
        <script>
            (function() {
                function applyMode() {
                    var modeEl = document.getElementById('fflhub_markup_mode');
                    var pctEl = document.getElementById('fflhub_markup_percent');
                    var fixedEl = document.getElementById('fflhub_fixed_price');
                    var mapRealModeEl = document.getElementById('fflhub_map_real_price_mode');
                    var mapOffsetEl = document.getElementById('fflhub_map_real_price_offset');
                    var mapPercentEl = document.getElementById('fflhub_map_real_price_percent');
                    var mapProfitEl = document.getElementById('fflhub_map_real_price_fixed_profit');
                    var mapFreeShipOverrideEl = document.getElementById('fflhub_map_real_price_free_shipping_override');
                    var mapPreviewWrapEl = document.getElementById('fflhub_map_real_price_preview_wrap');
                    var mapPreviewValueEl = document.getElementById('fflhub_map_real_price_preview_value');
                    var mapPreviewTrueCostEl = document.getElementById('fflhub_map_real_price_preview_true_cost');
                    var mapPreviewShippingEl = document.getElementById('fflhub_map_real_price_preview_shipping_cost');
                    var mapPreviewProcessorFeeEl = document.getElementById('fflhub_map_real_price_preview_processor_fee');
                    if (!modeEl || !pctEl || !fixedEl || !mapRealModeEl || !mapOffsetEl || !mapPercentEl || !mapProfitEl || !mapFreeShipOverrideEl || !mapPreviewWrapEl || !mapPreviewValueEl || !mapPreviewTrueCostEl || !mapPreviewShippingEl || !mapPreviewProcessorFeeEl) return;

                    var mode = parseInt(modeEl.value, 10);
                    var MODE_FIXED_PCT = <?php echo (int) ProductMeta::MARKUP_MODE_FIXED_PCT; ?>;
                    var MODE_FIXED_PRICE = <?php echo (int) ProductMeta::MARKUP_MODE_FIXED_PRICE; ?>;
                    var MODE_MAP_PRICE = <?php echo (int) ProductMeta::MARKUP_MODE_MAP_PRICE; ?>;
                    var MAP_REAL_MODE_RECOMMENDED = <?php echo (int) ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED; ?>;
                    var MAP_REAL_MODE_FIXED_OFFSET = <?php echo (int) ProductMeta::MAP_REAL_PRICE_MODE_FIXED_OFFSET; ?>;
                    var MAP_REAL_MODE_PERCENTAGE = <?php echo (int) ProductMeta::MAP_REAL_PRICE_MODE_PERCENTAGE; ?>;
                    var MAP_REAL_MODE_FIXED_PROFIT = <?php echo (int) ProductMeta::MAP_REAL_PRICE_MODE_FIXED_PROFIT; ?>;
                    var mapRealMode = parseInt(mapRealModeEl.value, 10);
                    var previewCostBase = <?php echo json_encode((float) $preview_cost_base); ?>;
                    var previewShipping = <?php echo json_encode((float) $preview_shipping); ?>;
                    var previewMapBase = <?php echo json_encode((float) $preview_map_base); ?>;
                    var previewRecommended = <?php echo json_encode((float) $preview_recommended); ?>;
                    var previewFeePercent = <?php echo json_encode((float) $preview_fee_percent); ?>;
                    var previewFeeFraction = <?php echo json_encode((float) $preview_fee_fraction); ?>;
                    var previewFeePercentLabel = <?php echo json_encode((string) $preview_fee_percent_label); ?>;

                    function asNonNegFloat(v) {
                        var n = parseFloat(v);
                        if (!isFinite(n) || n < 0) return 0;
                        return n;
                    }

                    function formatMoney(v) {
                        return "$" + v.toFixed(2);
                    }

                    function computePreviewPrice() {
                        var mapModeActive = (mode === MODE_MAP_PRICE);
                        if (!mapModeActive) return null;

                        if (mapRealMode === MAP_REAL_MODE_RECOMMENDED) {
                            return (previewRecommended > 0) ? previewRecommended : null;
                        }

                        if (mapRealMode === MAP_REAL_MODE_FIXED_OFFSET) {
                            var offset = asNonNegFloat(mapOffsetEl.value);
                            if (previewCostBase <= 0) return null;
                            var p1 = previewCostBase + offset;
                            return (p1 > 0) ? p1 : null;
                        }

                        if (mapRealMode === MAP_REAL_MODE_PERCENTAGE) {
                            if (previewMapBase <= 0) return null;
                            var pct = asNonNegFloat(mapPercentEl.value);
                            var discount = previewMapBase * (pct / 100.0);
                            var p2 = previewMapBase - discount;
                            return (p2 > 0) ? p2 : null;
                        }

                        if (mapRealMode === MAP_REAL_MODE_FIXED_PROFIT) {
                            if (previewCostBase <= 0) return null;
                            var profitTarget = asNonNegFloat(mapProfitEl.value);
                            var feeFraction = Math.min(0.99, asNonNegFloat(previewFeePercent) / 100.0);
                            var den = 1.0 - feeFraction;
                            if (den <= 0) return null;
                            var offset2 = (profitTarget + asNonNegFloat(previewShipping) + (previewCostBase * feeFraction)) / den;
                            var p3 = previewCostBase + offset2;
                            return (p3 > 0) ? p3 : null;
                        }

                        return null;
                    }

                    function updateMapRealPreview() {
                        var mapModeActive = (mode === MODE_MAP_PRICE);
                        mapPreviewWrapEl.style.opacity = mapModeActive ? "1" : "0.65";
                        mapPreviewTrueCostEl.textContent = "True Cost: " + formatMoney(Math.round(previewCostBase * 100) / 100);
                        mapPreviewShippingEl.textContent = "Shipping Cost: " + formatMoney(Math.round(previewShipping * 100) / 100);
                        var computed = computePreviewPrice();
                        if (computed === null) {
                            mapPreviewValueEl.textContent = "<?php echo esc_js(__('N/A', 'ffl-hub')); ?>";
                            mapPreviewProcessorFeeEl.textContent = "Processor Fee (" + previewFeePercentLabel + "%): <?php echo esc_js(__('N/A', 'ffl-hub')); ?>";
                            return;
                        }
                        var roundedComputed = Math.round(computed * 100) / 100;
                        mapPreviewValueEl.textContent = formatMoney(roundedComputed);
                        var feeAmount = Math.round((roundedComputed * previewFeeFraction) * 100) / 100;
                        mapPreviewProcessorFeeEl.textContent = "Processor Fee (" + previewFeePercentLabel + "%): " + formatMoney(feeAmount);
                    }

                    pctEl.disabled = (mode !== MODE_FIXED_PCT);
                    fixedEl.disabled = (mode !== MODE_FIXED_PRICE);

                    var mapModeActive = (mode === MODE_MAP_PRICE);
                    mapRealModeEl.disabled = !mapModeActive;
                    mapOffsetEl.disabled = !mapModeActive || mapRealMode !== MAP_REAL_MODE_FIXED_OFFSET;
                    mapPercentEl.disabled = !mapModeActive || mapRealMode !== MAP_REAL_MODE_PERCENTAGE;
                    mapProfitEl.disabled = !mapModeActive || mapRealMode !== MAP_REAL_MODE_FIXED_PROFIT;
                    mapFreeShipOverrideEl.disabled = !mapModeActive;
                    updateMapRealPreview();
                }

                function applyManualShippingOverride() {
                    var overrideEl = document.getElementById('fflhub_manual_shipping_override');
                    if (!overrideEl) return;

                    var fields = [
                        document.getElementById('fflhub_shipping_weight_manual'),
                        document.getElementById('fflhub_shipping_length_manual'),
                        document.getElementById('fflhub_shipping_width_manual'),
                        document.getElementById('fflhub_shipping_height_manual')
                    ];

                    var enabled = !!overrideEl.checked;
                    fields.forEach(function(field) {
                        if (field) {
                            field.disabled = !enabled;
                        }
                    });
                }

                function applyLocalStockOverride() {
                    var overrideEl = document.getElementById('fflhub_local_stock_override_enabled');
                    var qtyEl = document.getElementById('fflhub_local_stock_override_qty');
                    if (!overrideEl || !qtyEl) return;

                    qtyEl.disabled = !overrideEl.checked;
                }

                function applyDistributorLock() {
                    var lockEnabledEl = document.getElementById('fflhub_distributor_lock_enabled');
                    var lockIdsEl = document.getElementById('fflhub_distributor_lock_ids');
                    if (!lockEnabledEl || !lockIdsEl) return;

                    lockIdsEl.disabled = !lockEnabledEl.checked || lockIdsEl.options.length === 0;
                }

                document.addEventListener('DOMContentLoaded', function() {
                    applyMode();
                    applyManualShippingOverride();
                    applyLocalStockOverride();
                    applyDistributorLock();
                    var modeEl = document.getElementById('fflhub_markup_mode');
                    var mapRealModeEl = document.getElementById('fflhub_map_real_price_mode');
                    var mapOffsetEl = document.getElementById('fflhub_map_real_price_offset');
                    var mapPercentEl = document.getElementById('fflhub_map_real_price_percent');
                    var mapProfitEl = document.getElementById('fflhub_map_real_price_fixed_profit');
                    var overrideEl = document.getElementById('fflhub_manual_shipping_override');
                    var localOverrideEl = document.getElementById('fflhub_local_stock_override_enabled');
                    var distributorLockEnabledEl = document.getElementById('fflhub_distributor_lock_enabled');
                    if (modeEl) {
                        modeEl.addEventListener('change', applyMode);
                    }
                    if (mapRealModeEl) {
                        mapRealModeEl.addEventListener('change', applyMode);
                    }
                    if (mapOffsetEl) {
                        mapOffsetEl.addEventListener('input', applyMode);
                    }
                    if (mapPercentEl) {
                        mapPercentEl.addEventListener('input', applyMode);
                    }
                    if (mapProfitEl) {
                        mapProfitEl.addEventListener('input', applyMode);
                    }
                    if (overrideEl) {
                        overrideEl.addEventListener('change', applyManualShippingOverride);
                    }
                    if (localOverrideEl) {
                        localOverrideEl.addEventListener('change', applyLocalStockOverride);
                    }
                    if (distributorLockEnabledEl) {
                        distributorLockEnabledEl.addEventListener('change', applyDistributorLock);
                    }
                });
            })();
        </script>
<?php

        echo '<p style="margin-top:6px;font-size:11px;color:#6b7280;">';
        esc_html_e('Most values are managed by FFLHub and updated automatically by sync jobs.', 'ffl-hub');
        echo '</p>';
    }

    public static function save_meta_and_update_price(int $post_id): void
    {
        // Woo admin save shouldn’t be autosave, but keep this guard anyway
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (
            ! isset($_POST['ProductMeta_nonce']) ||
            ! wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['ProductMeta_nonce'])),
                'fflhub_save_product_meta'
            )
        ) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        /** @var WC_Product|null $product */
        $product = function_exists('wc_get_product') ? wc_get_product($post_id) : null;
        if (! $product) {
            return;
        }

        // -----------------------------
        // Save your editable meta fields
        // -----------------------------

        // FFL Required checkbox
        $required = isset($_POST['fflhub_ffl_required']) ? 1 : 0;
        $product->update_meta_data(ProductMeta::FFLHUB_FFL_REQUIRED_META, $required);

        // SOT Required checkbox
        $sot_required = isset($_POST['fflhub_sot_required']) ? 1 : 0;
        $product->update_meta_data(ProductMeta::FFLHUB_SOT_REQUIRED_META, $sot_required);

        // Out of stock override
        $stock_oos_override = isset($_POST['fflhub_stock_oos_override']) ? 1 : 0;
        $product->update_meta_data(ProductMeta::FFLHUB_STOCK_OOS_OVERRIDE_META, $stock_oos_override);

        $local_stock_override_enabled = isset($_POST['fflhub_local_stock_override_enabled']) ? 1 : 0;
        $product->update_meta_data(ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_ENABLED_META, $local_stock_override_enabled);

        $local_stock_override_qty = isset($_POST['fflhub_local_stock_override_qty'])
            ? absint(sanitize_text_field(wp_unslash($_POST['fflhub_local_stock_override_qty'])))
            : 0;
        $product->update_meta_data(ProductMeta::FFLHUB_LOCAL_STOCK_OVERRIDE_QTY_META, $local_stock_override_qty);

        if ($local_stock_override_enabled === 1 && $local_stock_override_qty > 0) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($local_stock_override_qty);
            $product->set_stock_status('instock');
        } elseif ($stock_oos_override === 1) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity(0);
            $product->set_stock_status('outofstock');
        }

        $distributor_lock_enabled = isset($_POST['fflhub_distributor_lock_enabled']) ? 1 : 0;
        $product->update_meta_data(ProductMeta::FFLHUB_DISTRIBUTOR_LOCK_ENABLED_META, $distributor_lock_enabled);

        $selected_distributor_locks = [];
        if ($distributor_lock_enabled === 1) {
            $raw_dist_ids = $_POST['fflhub_distributor_lock_ids'] ?? [];
            if (!is_array($raw_dist_ids)) {
                $raw_dist_ids = [$raw_dist_ids];
            }

            $enabled_dist_ids = array_keys(self::enabled_distributor_options());
            $enabled_lookup = array_fill_keys($enabled_dist_ids, true);

            foreach ($raw_dist_ids as $raw_dist_id) {
                $dist_id = strtolower(trim(sanitize_text_field(wp_unslash((string) $raw_dist_id))));
                if ($dist_id === '' || !isset($enabled_lookup[$dist_id])) {
                    continue;
                }

                $selected_distributor_locks[] = $dist_id;
            }

            $selected_distributor_locks = array_values(array_unique($selected_distributor_locks));
        }
        $product->update_meta_data(ProductMeta::FFLHUB_DISTRIBUTOR_LOCK_IDS_META, $selected_distributor_locks);

        // Manual shipping override + values
        $manual_shipping_override = isset($_POST['fflhub_manual_shipping_override']) ? 1 : 0;
        $product->update_meta_data(ProductMeta::FFLHUB_MANUAL_SHIPPING_OVERRIDE_META, $manual_shipping_override);
        if ($manual_shipping_override === 1) {
            $product->update_meta_data(
                ProductMeta::FFLHUB_SHIPPING_WEIGHT_META,
                self::sanitize_shipping_decimal_post_value('fflhub_shipping_weight_manual')
            );
            $product->update_meta_data(
                ProductMeta::FFLHUB_SHIPPING_LENGTH_IN_META,
                self::sanitize_shipping_decimal_post_value('fflhub_shipping_length_manual')
            );
            $product->update_meta_data(
                ProductMeta::FFLHUB_SHIPPING_WIDTH_IN_META,
                self::sanitize_shipping_decimal_post_value('fflhub_shipping_width_manual')
            );
            $product->update_meta_data(
                ProductMeta::FFLHUB_SHIPPING_HEIGHT_IN_META,
                self::sanitize_shipping_decimal_post_value('fflhub_shipping_height_manual')
            );
        }

        // Pricing mode
        $mode = isset($_POST['fflhub_markup_mode'])
            ? (int) sanitize_text_field(wp_unslash($_POST['fflhub_markup_mode']))
            : ProductMeta::MARKUP_MODE_GLOBAL;

        if (! in_array($mode, [
            ProductMeta::MARKUP_MODE_GLOBAL,
            ProductMeta::MARKUP_MODE_FIXED_PCT,
            ProductMeta::MARKUP_MODE_FIXED_PRICE,
            ProductMeta::MARKUP_MODE_MAP_PRICE,
        ], true)) {
            $mode = ProductMeta::MARKUP_MODE_GLOBAL;
        }

        $product->update_meta_data(ProductMeta::FFLHUB_MARKUP_MODE_META, $mode);

        // Fixed Percent value
        if ($mode === ProductMeta::MARKUP_MODE_FIXED_PCT) {
            $pct_raw = isset($_POST['fflhub_markup_percent'])
                ? sanitize_text_field(wp_unslash($_POST['fflhub_markup_percent']))
                : '';

            $pct = is_numeric($pct_raw) ? (float) $pct_raw : 0.0;
            if ($pct < 0) {
                $pct = 0.0;
            }

            $product->update_meta_data(ProductMeta::FFLHUB_MARKUP_PERCENT_META, $pct);
        } else {
            $product->update_meta_data(ProductMeta::FFLHUB_MARKUP_PERCENT_META, 0);
        }

        // Fixed Price value
        if ($mode === ProductMeta::MARKUP_MODE_FIXED_PRICE) {
            $fixed_raw = isset($_POST['fflhub_fixed_price'])
                ? sanitize_text_field(wp_unslash($_POST['fflhub_fixed_price']))
                : '';

            $fixed = is_numeric($fixed_raw) ? (float) $fixed_raw : 0.0;
            if ($fixed < 0) {
                $fixed = 0.0;
            }

            $product->update_meta_data(ProductMeta::FFLHUB_FIXED_PRICE_META, $fixed);
        } else {
            $product->update_meta_data(ProductMeta::FFLHUB_FIXED_PRICE_META, '');
        }

        if ($mode === ProductMeta::MARKUP_MODE_MAP_PRICE) {
            $map_real_mode = isset($_POST['fflhub_map_real_price_mode'])
                ? (int) sanitize_text_field(wp_unslash($_POST['fflhub_map_real_price_mode']))
                : ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED;
            if (! in_array($map_real_mode, [ProductMeta::MAP_REAL_PRICE_MODE_FIXED_OFFSET, ProductMeta::MAP_REAL_PRICE_MODE_PERCENTAGE, ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED, ProductMeta::MAP_REAL_PRICE_MODE_FIXED_PROFIT], true)) {
                $map_real_mode = ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED;
            }
            $product->update_meta_data(ProductMeta::FFLHUB_MAP_REAL_PRICE_MODE_META, $map_real_mode);

            $map_real_offset_raw = isset($_POST['fflhub_map_real_price_offset'])
                ? sanitize_text_field(wp_unslash($_POST['fflhub_map_real_price_offset']))
                : '';
            $map_real_offset = is_numeric($map_real_offset_raw) ? (float) $map_real_offset_raw : 0.0;
            if ($map_real_offset < 0.0) {
                $map_real_offset = 0.0;
            }
            $product->update_meta_data(
                ProductMeta::FFLHUB_MAP_REAL_PRICE_OFFSET_META,
                (float) wc_format_decimal($map_real_offset, 2)
            );

            $map_real_percent_raw = isset($_POST['fflhub_map_real_price_percent'])
                ? sanitize_text_field(wp_unslash($_POST['fflhub_map_real_price_percent']))
                : '';
            $map_real_percent = is_numeric($map_real_percent_raw) ? (float) $map_real_percent_raw : 0.0;
            if ($map_real_percent < 0.0) {
                $map_real_percent = 0.0;
            }
            $product->update_meta_data(
                ProductMeta::FFLHUB_MAP_REAL_PRICE_PERCENT_META,
                (float) wc_format_decimal($map_real_percent, 2)
            );

            $map_real_profit_raw = isset($_POST['fflhub_map_real_price_fixed_profit'])
                ? sanitize_text_field(wp_unslash($_POST['fflhub_map_real_price_fixed_profit']))
                : '';
            $map_real_profit = is_numeric($map_real_profit_raw) ? (float) $map_real_profit_raw : 0.0;
            if ($map_real_profit < 0.0) {
                $map_real_profit = 0.0;
            }
            $product->update_meta_data(
                ProductMeta::FFLHUB_MAP_REAL_PRICE_FIXED_PROFIT_META,
                (float) wc_format_decimal($map_real_profit, 2)
            );

            $map_real_free_shipping_override = isset($_POST['fflhub_map_real_price_free_shipping_override']) ? 1 : 0;
            $product->update_meta_data(
                ProductMeta::FFLHUB_MAP_REAL_PRICE_FREE_SHIPPING_OVERRIDE_META,
                $map_real_free_shipping_override
            );
        }

        DistributorProductHelper::sync_woo_shipping_from_fflhub_meta($product);

        // Save all updated metadata before applying pricing.
        $product->save();

        // ✅ Then update Woo regular price based on the meta we just saved
        DistributorProductHelper::apply_admin_pricing_to_woo_product($post_id);

        // Optional debug
        $product = wc_get_product($post_id);
        if ($product) {
            DebugLogUtil::log(
                'FFLHUB_ADMIN_DEBUG',
                '[FFLHub][ProductMetaBox]',
                'AFTER PRICING: regular=' . $product->get_regular_price()
                    . ' price=' . $product->get_price()
                    . ' sale=' . $product->get_sale_price()
            );
        }
    }

    /**
     * Normalize decimal product meta value for numeric input fields.
     *
     * @param mixed $value
     */
    private static function normalize_decimal_for_input($value): string
    {
        if ($value === null) {
            return '';
        }

        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw)) {
            return '';
        }

        return rtrim(rtrim(wc_format_decimal((float) $raw, 4), '0'), '.');
    }

    /**
     * Sanitize decimal shipping values posted from the meta box.
     */
    private static function sanitize_shipping_decimal_post_value(string $post_key): string
    {
        if (!isset($_POST[$post_key])) {
            return '';
        }

        $raw = sanitize_text_field(wp_unslash($_POST[$post_key]));
        $raw = trim((string) $raw);

        if ($raw === '' || !is_numeric($raw)) {
            return '';
        }

        $value = (float) $raw;
        if ($value < 0) {
            $value = 0.0;
        }

        return (string) wc_format_decimal($value, 4);
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

    /**
     * Normalize bool-like product meta values.
     *
     * @param mixed $value
     */
    private static function is_truthy_meta($value): bool
    {
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true);
    }
}
