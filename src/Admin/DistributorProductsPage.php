<?php

namespace FFLHub\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Product\DistributorProductHelper;
use FFLHub\Plugin;
use FFLHub\Distributor\Product\DistributorProductPayload;

use FFLHub_Category_Installer;
use WP_Error;
use WC_Product_Simple;

/**
 * Admin page for searching distributor products by UPC.
 */
class DistributorProductsPage
{
    /**
     * Slug for the Distributor Products page.
     */
    private const PAGE_SLUG = 'fflhub-distributor-products';

    /**
     * Initialize hooks.
     */
    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu_page']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Register the "Distributor Products" menu item under the FFL Hub menu.
     */
    public static function register_menu_page(): void
    {
        add_submenu_page(
            AdminPage::get_page_slug(),          // parent slug (FFL Hub settings page, still global for now)
            __('Distributor Products', 'ffl-hub'),         // page title
            __('Distributor Products', 'ffl-hub'),         // menu title
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Enqueue admin assets for this page.
     *
     * @param string $hook
     */
    public static function enqueue_assets(string $hook): void
    {
        // Only load on our Distributor Products page.
        if (false === strpos($hook, self::PAGE_SLUG)) {
            return;
        }

        $base_url = FFLHUB_PLUGIN_URL . 'assets/';

        wp_enqueue_style(
            'fflhub-admin',
            $base_url . 'css/admin-distributor-products.css',
            [],
            '0.1.0'
        );
    }

    /**
     * Render the Distributor Products page.
     */
    public static function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        $upc_value            = '';
        $global_error         = '';
        $selected_product     = null; // DistributorProductPayload|null
        $selected_dist_id     = '';
        $selected_dist_label  = '';
        $carrier_distributors = [];   // [dist_id => ['label' => ..., 'payload' => DistributorProductPayload, 'true_cost' => float|null, 'quantity' => int|null]]

        $cheapest_in_stock    = null; // ['product' => ..., 'true_cost' => float, 'label' => ..., 'id' => ..., 'quantity' => int]
        $cheapest_any         = null;

        $action               = '';
        $create_notice        = '';
        $create_notice_type   = 'success';

        // Figure out what action we're handling.
        if (isset($_POST['fflhub_distributor_search'])) {
            $action = 'search';
        } elseif (isset($_POST['fflhub_distributor_create_product'])) {
            $action = 'create';
        }

        if ($action) {
            // Common UPC extraction.
            $upc = '';
            if (isset($_POST['fflhub_distributor_upc'])) {
                $upc = sanitize_text_field(wp_unslash($_POST['fflhub_distributor_upc']));
            }

            $upc_value = $upc;

            if ($upc === '') {
                $global_error = __('Please enter a UPC.', 'ffl-hub');
            } else {
                if ($action === 'search') {
                    check_admin_referer('fflhub_distributor_products_search');
                } else {
                    check_admin_referer('fflhub_distributor_products_create');
                }

                $plugin  = Plugin::instance();
                $handler = $plugin->distributor_handler ?? null;

                if (! $handler || ! method_exists($handler, 'get_payloads_for_upc')) {
                    $global_error = __(
                        'Distributor handler is not available for lookups.',
                        'ffl-hub'
                    );
                } else {
                    try {
                        $lookup = $handler->get_payloads_for_upc($upc);
                    } catch (\Throwable $e) {
                        $lookup       = null;
                        $global_error = __(
                            'An error occurred while fetching products from distributors.',
                            'ffl-hub'
                        );
                    }

                    if (is_array($lookup)) {
                        $carrier_distributors = isset($lookup['carriers']) && is_array($lookup['carriers'])
                            ? $lookup['carriers']
                            : [];

                        $cheapest_in_stock = $lookup['cheapest_in_stock'] ?? null;
                        $cheapest_any      = $lookup['cheapest_any'] ?? null;
                    }

                    if (empty($carrier_distributors) && $global_error === '') {
                        $global_error = __(
                            'No products were found for this UPC in any connected distributor.',
                            'ffl-hub'
                        );
                    } elseif (! empty($carrier_distributors)) {
                        // Prefer cheapest in-stock source (quantity > 0 with valid true_cost).
                        if ($cheapest_in_stock !== null) {
                            $selected_product    = $cheapest_in_stock['product'];
                            $selected_dist_label = $cheapest_in_stock['label'];
                            $selected_dist_id    = $cheapest_in_stock['id'];
                        } elseif ($cheapest_any !== null) {
                            // Fall back to cheapest overall (even if quantity is 0).
                            $selected_product    = $cheapest_any['product'];
                            $selected_dist_label = $cheapest_any['label'];
                            $selected_dist_id    = $cheapest_any['id'];
                        } else {
                            // Distributors carry it, but no valid true_costs.
                            $global_error = __(
                                'Distributors carry this UPC, but no valid true cost was found.',
                                'ffl-hub'
                            );
                            // Fallback: just pick the first carrier so the card still renders.
                            $first = reset($carrier_distributors);
                            if ($first && isset($first['payload']) && $first['payload'] instanceof DistributorProductPayload) {
                                $selected_product    = $first['payload'];
                                $selected_dist_label = $first['label'];
                                $selected_dist_id    = array_key_first($carrier_distributors);
                            }
                        }
                    }
                }

                // If this is a create action and we have a selected product, create Woo product.
                if (
                    $action === 'create'
                    && $selected_product instanceof DistributorProductPayload
                    && empty($global_error)
                ) {
                    $create_result = DistributorProductHelper::create_woo_product_from_payload(
                        $upc,
                        $selected_product,
                        $selected_dist_id,
                        $selected_dist_label,
                        $carrier_distributors
                    );

                    if (is_wp_error($create_result)) {
                        $create_notice      = $create_result->get_error_message();
                        $create_notice_type = 'error';
                    } else {
                        $create_notice      = $create_result['message'] ?? '';
                        $create_notice_type = $create_result['type'] ?? 'success';
                    }
                }
            }
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Distributor Products', 'ffl-hub'); ?></h1>

            <p>
                <?php esc_html_e(
                    'Use this page to search for products from your connected distributors by UPC.',
                    'ffl-hub'
                ); ?>
            </p>

            <?php if ($global_error) : ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html($global_error); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($create_notice) : ?>
                <div class="notice notice-<?php echo esc_attr($create_notice_type); ?>">
                    <p><?php echo wp_kses_post($create_notice); ?></p>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('fflhub_distributor_products_search'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="fflhub_distributor_upc">
                                <?php esc_html_e('UPC', 'ffl-hub'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="fflhub_distributor_upc"
                                name="fflhub_distributor_upc"
                                class="regular-text"
                                value="<?php echo esc_attr($upc_value); ?>" />
                            <p class="description">
                                <?php esc_html_e('Enter a full UPC (numbers only are fine).', 'ffl-hub'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php
                submit_button(
                    __('Search for UPC', 'ffl-hub'),
                    'primary',
                    'fflhub_distributor_search'
                );
                ?>
            </form>

            <?php if ($selected_product instanceof DistributorProductPayload) : ?>
                <?php
                // Extract fields from the normalized payload we chose.
                $payload = get_object_vars($selected_product);

                $p_upc         = $payload['upc'] ?? '';
                $p_sku         = $payload['sku'] ?? '';
                $p_name        = $payload['name'] ?? '';
                $p_description = $payload['description'] ?? '';

                $p_price       = isset($payload['price']) ? (float) $payload['price'] : null;
                $p_map         = isset($payload['map']) ? (float) $payload['map'] : null;
                $p_msrp        = isset($payload['msrp']) ? (float) $payload['msrp'] : null;
                $p_quantity    = isset($payload['quantity']) ? (int) $payload['quantity'] : null;
                $p_shipping    = isset($payload['shipping_cost']) ? (float) $payload['shipping_cost'] : null;
                $p_true_cost   = isset($payload['true_cost']) ? (float) $payload['true_cost'] : null;

                // image_urls-based primary image
                $p_image_url = '';
                if (isset($payload['image_urls']) && is_array($payload['image_urls']) && ! empty($payload['image_urls'])) {
                    foreach ($payload['image_urls'] as $url) {
                        $url = trim((string) $url);
                        if ($url !== '') {
                            $p_image_url = $url;
                            break;
                        }
                    }
                }

                $p_ffl_required = isset($payload['ffl_required']) ? (bool) $payload['ffl_required'] : false;

                // recommended category path from payload.
                $p_recommended_category       = $payload['recommended_category'] ?? null;
                $p_recommended_category_label = '';

                if (is_array($p_recommended_category) && ! empty($p_recommended_category)) {
                    $p_recommended_category_label = implode(' > ', array_map('strval', $p_recommended_category));
                } elseif (is_string($p_recommended_category) && $p_recommended_category !== '') {
                    $p_recommended_category_label = $p_recommended_category;
                }
                ?>
                <hr />

                <h2>
                    <?php
                    printf(
                        /* translators: %s: UPC code */
                        esc_html__('Product for UPC %s', 'ffl-hub'),
                        esc_html($p_upc ?: $upc_value)
                    );
                    ?>
                </h2>

                <div class="fflhub-product-card">
                    <div class="fflhub-product-card-image">
                        <?php if (! empty($p_image_url)) : ?>
                            <img
                                src="<?php echo esc_url($p_image_url); ?>"
                                alt="<?php echo esc_attr($p_name ?: $p_sku ?: $p_upc); ?>" />
                        <?php else : ?>
                            <div class="fflhub-product-card-image-placeholder">
                                <?php esc_html_e('No image available', 'ffl-hub'); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="fflhub-product-card-main">
                        <div class="fflhub-product-card-header">
                            <h3 class="fflhub-product-card-title">
                                <?php echo esc_html($p_name ?: $p_sku ?: $p_upc); ?>
                            </h3>
                            <?php if ($selected_dist_label) : ?>
                                <span class="fflhub-product-card-distributor">
                                    <?php
                                    printf(
                                        /* translators: %s: distributor label */
                                        esc_html__('Cheapest Source (in stock if possible): %s', 'ffl-hub'),
                                        esc_html($selected_dist_label)
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <p class="fflhub-product-card-meta">
                            <?php if ($p_upc) : ?>
                                <strong><?php esc_html_e('UPC:', 'ffl-hub'); ?></strong>
                                <?php echo ' ' . esc_html($p_upc); ?>
                            <?php endif; ?>

                            <?php if ($p_sku) : ?>
                                &nbsp;&nbsp;
                                <strong><?php esc_html_e('SKU:', 'ffl-hub'); ?></strong>
                                <?php echo ' ' . esc_html($p_sku); ?>
                            <?php endif; ?>
                        </p>

                        <?php if ($p_description) : ?>
                            <p class="fflhub-product-card-description">
                                <?php echo esc_html($p_description); ?>
                            </p>
                        <?php endif; ?>

                        <div class="fflhub-product-card-pricing">
                            <p>
                                <strong><?php esc_html_e('Dealer Price:', 'ffl-hub'); ?></strong>
                                <?php echo ' ' . esc_html(self::format_price($p_price)); ?>
                            </p>
                            <p>
                                <strong><?php esc_html_e('MAP:', 'ffl-hub'); ?></strong>
                                <?php echo ' ' . esc_html(self::format_price($p_map)); ?>
                            </p>
                            <p>
                                <strong><?php esc_html_e('MSRP:', 'ffl-hub'); ?></strong>
                                <?php echo ' ' . esc_html(self::format_price($p_msrp)); ?>
                            </p>
                            <p>
                                <strong><?php esc_html_e('Shipping Cost:', 'ffl-hub'); ?></strong>
                                <?php echo ' ' . esc_html(self::format_price($p_shipping)); ?>
                            </p>
                            <p>
                                <strong><?php esc_html_e('True Cost:', 'ffl-hub'); ?></strong>
                                <?php echo ' ' . esc_html(self::format_price($p_true_cost)); ?>
                            </p>
                        </div>

                        <div class="fflhub-product-card-stock">
                            <p>
                                <strong><?php esc_html_e('Quantity Available:', 'ffl-hub'); ?></strong>
                                <?php
                                if ($p_quantity === null) {
                                    esc_html_e('Unknown', 'ffl-hub');
                                } else {
                                    echo ' ' . esc_html((string) $p_quantity);
                                    if ($p_quantity <= 0) {
                                        echo ' ';
                                        echo esc_html__('(Out of stock)', 'ffl-hub');
                                    }
                                }
                                ?>
                            </p>
                            <p>
                                <strong><?php esc_html_e('FFL Required:', 'ffl-hub'); ?></strong>
                                <?php
                                echo ' ' . ($p_ffl_required
                                    ? esc_html__('Yes', 'ffl-hub')
                                    : esc_html__('No', 'ffl-hub')
                                );
                                ?>
                            </p>

                            <p>
                                <strong><?php esc_html_e('Recommended Category:', 'ffl-hub'); ?></strong>
                                <?php
                                if ($p_recommended_category_label !== '') {
                                    echo ' ' . esc_html($p_recommended_category_label);
                                } else {
                                    echo ' ' . esc_html__('N/A', 'ffl-hub');
                                }
                                ?>
                            </p>
                        </div>

                        <!-- Create Woo Product button -->
                        <div class="fflhub-product-card-actions" style="margin-top: 12px;">
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('fflhub_distributor_products_create'); ?>
                                <input type="hidden" name="fflhub_distributor_upc" value="<?php echo esc_attr($p_upc ?: $upc_value); ?>" />
                                <?php
                                submit_button(
                                    __('Create Woo Product', 'ffl-hub'),
                                    'secondary',
                                    'fflhub_distributor_create_product',
                                    false
                                );
                                ?>
                            </form>
                        </div>
                    </div>
                </div>

                <?php if (! empty($carrier_distributors)) : ?>
                    <h3><?php esc_html_e('Available From', 'ffl-hub'); ?></h3>
                    <ul class="fflhub-product-carriers-list">
                        <?php foreach ($carrier_distributors as $dist_id => $info) : ?>
                            <?php
                            $label       = $info['label'];
                            $payload_row = get_object_vars($info['payload']);

                            $tc          = $info['true_cost'];
                            $dealer      = isset($payload_row['price']) ? (float) $payload_row['price'] : null;
                            $map         = isset($payload_row['map']) ? (float) $payload_row['map'] : null;
                            $msrp        = isset($payload_row['msrp']) ? (float) $payload_row['msrp'] : null;
                            $shipping    = isset($payload_row['shipping_cost']) ? (float) $payload_row['shipping_cost'] : null;
                            $qty         = $info['quantity'];
                            $ffl_req_row = isset($payload_row['ffl_required']) ? (bool) $payload_row['ffl_required'] : false;
                            ?>
                            <li class="fflhub-product-carrier-item">
                                <div class="fflhub-product-carrier-header">
                                    <strong><?php echo esc_html($label); ?></strong>
                                </div>
                                <div class="fflhub-product-carrier-metrics">
                                    <span>
                                        <strong><?php esc_html_e('True Cost:', 'ffl-hub'); ?></strong>
                                        <?php echo ' ' . esc_html(self::format_price($tc)); ?>
                                    </span>
                                    <span>
                                        <strong><?php esc_html_e('Dealer:', 'ffl-hub'); ?></strong>
                                        <?php echo ' ' . esc_html(self::format_price($dealer)); ?>
                                    </span>
                                    <span>
                                        <strong><?php esc_html_e('MAP:', 'ffl-hub'); ?></strong>
                                        <?php echo ' ' . esc_html(self::format_price($map)); ?>
                                    </span>
                                    <span>
                                        <strong><?php esc_html_e('MSRP:', 'ffl-hub'); ?></strong>
                                        <?php echo ' ' . esc_html(self::format_price($msrp)); ?>
                                    </span>
                                    <span>
                                        <strong><?php esc_html_e('Shipping:', 'ffl-hub'); ?></strong>
                                        <?php echo ' ' . esc_html(self::format_price($shipping)); ?>
                                    </span>
                                    <span>
                                        <strong><?php esc_html_e('Qty:', 'ffl-hub'); ?></strong>
                                        <?php
                                        if ($qty === null) {
                                            esc_html_e('Unknown', 'ffl-hub');
                                        } else {
                                            echo ' ' . esc_html((string) $qty);
                                            if ($qty <= 0) {
                                                echo ' ' . esc_html__('(Out of stock)', 'ffl-hub');
                                            }
                                        }
                                        ?>
                                    </span>
                                    <span>
                                        <strong><?php esc_html_e('FFL:', 'ffl-hub'); ?></strong>
                                        <?php
                                        echo ' ' . ($ffl_req_row
                                            ? esc_html__('Required', 'ffl-hub')
                                            : esc_html__('No', 'ffl-hub')
                                        );
                                        ?>
                                    </span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Helper to format a price value for display.
     *
     * @param float|null $price Price value.
     * @return string
     */
    private static function format_price(?float $price): string
    {
        if ($price === null) {
            return __('N/A', 'ffl-hub');
        }

        // Basic currency formatting with 2 decimals.
        $formatted = number_format_i18n($price, 2);

        return '$' . $formatted;
    }
}
