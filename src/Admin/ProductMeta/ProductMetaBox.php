<?php

namespace FFLHub\Admin\ProductMeta;

use FFLHub\Distributor\Product\DistributorProductHelper;
use FFLHub\Product\ProductMeta;
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
            ProductMeta::FFLHUB_NFA_ITEM_META            => __('NFA Item', 'ffl-hub'),
            ProductMeta::FFLHUB_LAST_SHIPPING_COST_META            => __('Shipping Cost', 'ffl-hub'),
            ProductMeta::FFLHUB_LAST_SYNC_META           => __('Last Sync At', 'ffl-hub'),
        );

        echo '<table class="fflhub-meta-table" style="width:100%;border-collapse:collapse;">';

        foreach ($fields as $key => $label) {
            $value = $product->get_meta($key, true);

            echo '<tr>';
            echo '<th style="text-align:left;padding:2px 4px;font-weight:600;font-size:11px;">' .
                esc_html($label) .
                '</th>';
            echo '<td style="text-align:right;padding:2px 4px;font-size:11px;">';

            if ($value === '' && (string) $value !== '0') {
                echo '<span style="color:#9ca3af;">' . esc_html__('—', 'ffl-hub') . '</span>';
            } else {
                echo esc_html((string) $value);
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';

        // Editable checkbox: FFL Required.
        $raw_required = $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true);
        $ffl_required = (string) $raw_required === '1' || $raw_required === 1 || $raw_required === true;

        echo '<div style="margin-top:8px;padding-top:6px;border-top:1px solid #e5e7eb;">';
        echo '<label style="display:flex;align-items:center;font-size:11px;gap:6px;">';
        echo '<input type="checkbox" name="fflhub_ffl_required" value="1" ' .
            checked(true, $ffl_required, false) .
            ' />';
        echo '<span style="font-weight:600;">' .
            esc_html__('FFL Required', 'ffl-hub') .
            '</span>';
        echo '</label>';
        echo '</div>';

        // 🆕 Editable pricing controls
        $mode_raw = $product->get_meta(ProductMeta::FFLHUB_MARKUP_MODE_META, true);
        $mode     = ($mode_raw === '' && (string) $mode_raw !== '0')
            ? ProductMeta::MARKUP_MODE_GLOBAL
            : (int) $mode_raw;

        $pct_raw   = $product->get_meta(ProductMeta::FFLHUB_MARKUP_PERCENT_META, true);
        $pct_value = is_numeric($pct_raw) ? (string) $pct_raw : '';

        $fixed_raw   = $product->get_meta(ProductMeta::FFLHUB_FIXED_PRICE_META, true);
        $fixed_value = is_numeric($fixed_raw) ? (string) $fixed_raw : '';

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

        echo '</div>';

        // 🆕 Inline JS: enable/disable fields immediately when mode changes
?>
        <script>
            (function() {
                function applyMode() {
                    var modeEl = document.getElementById('fflhub_markup_mode');
                    var pctEl = document.getElementById('fflhub_markup_percent');
                    var fixedEl = document.getElementById('fflhub_fixed_price');
                    if (!modeEl || !pctEl || !fixedEl) return;

                    var mode = parseInt(modeEl.value, 10);
                    var MODE_FIXED_PCT = <?php echo (int) ProductMeta::MARKUP_MODE_FIXED_PCT; ?>;
                    var MODE_FIXED_PRICE = <?php echo (int) ProductMeta::MARKUP_MODE_FIXED_PRICE; ?>;

                    pctEl.disabled = (mode !== MODE_FIXED_PCT);
                    fixedEl.disabled = (mode !== MODE_FIXED_PRICE);
                }

                document.addEventListener('DOMContentLoaded', function() {
                    applyMode();
                    var modeEl = document.getElementById('fflhub_markup_mode');
                    if (modeEl) {
                        modeEl.addEventListener('change', applyMode);
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

        // Pricing mode
        $mode = isset($_POST['fflhub_markup_mode'])
            ? (int) sanitize_text_field(wp_unslash($_POST['fflhub_markup_mode']))
            : ProductMeta::MARKUP_MODE_GLOBAL;

        if (! in_array($mode, [
            ProductMeta::MARKUP_MODE_GLOBAL,
            ProductMeta::MARKUP_MODE_FIXED_PCT,
            ProductMeta::MARKUP_MODE_FIXED_PRICE,
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

        // ✅ Save meta first
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
}
