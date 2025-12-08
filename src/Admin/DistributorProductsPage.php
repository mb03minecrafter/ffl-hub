<?php

namespace FFLHub\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Plugin;
use FFLHub\Distributor\Product\DistributorProductPayload;
use FFLHub\Distributor\Product\DistributorProductImages;
use FFLHub\Product\CategoryInstaller;
use FFLHub\Product\ProductMeta;


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

                $plugin = Plugin::instance();

                // Get all configured distributors (RSR, Lipsey’s, etc.).
                $distributors = method_exists($plugin, 'get_distributors')
                    ? $plugin->distributor_handler->get_distributors()
                    : [
                        'rsr'     => $plugin->distributor_handler->get_distributor_by_id('rsr'),
                        'lipseys' => $plugin->distributor_handler->get_distributor_by_id('lipseys'),
                    ];

                foreach ($distributors as $id => $distributor) {
                    if (! $distributor) {
                        continue;
                    }

                    try {
                        $product = $distributor->get_product_by_upc($upc);

                        if ($product instanceof DistributorProductPayload) {
                            $label = method_exists($distributor, 'get_label')
                                ? $distributor->get_label()
                                : ucfirst((string) $id);

                            $payload = get_object_vars($product);

                            $true_cost = null;
                            if (isset($payload['true_cost']) && is_numeric($payload['true_cost'])) {
                                $true_cost = (float) $payload['true_cost'];
                            }

                            $quantity = null;
                            if (isset($payload['quantity']) && is_numeric($payload['quantity'])) {
                                $quantity = (int) $payload['quantity'];
                            }

                            // Record that this distributor carries the product.
                            $carrier_distributors[(string) $id] = [
                                'label'     => $label,
                                'payload'   => $product,
                                'true_cost' => $true_cost,
                                'quantity'  => $quantity,
                            ];

                            // Track cheapest overall (any quantity) if true_cost is valid.
                            if ($true_cost !== null) {
                                if ($cheapest_any === null || $true_cost < $cheapest_any['true_cost']) {
                                    $cheapest_any = [
                                        'product'   => $product,
                                        'true_cost' => $true_cost,
                                        'label'     => $label,
                                        'id'        => (string) $id,
                                        'quantity'  => $quantity,
                                    ];
                                }
                            }

                            // Track cheapest *in stock* (quantity > 0 and valid true_cost).
                            if ($true_cost !== null && $quantity !== null && $quantity > 0) {
                                if ($cheapest_in_stock === null || $true_cost < $cheapest_in_stock['true_cost']) {
                                    $cheapest_in_stock = [
                                        'product'   => $product,
                                        'true_cost' => $true_cost,
                                        'label'     => $label,
                                        'id'        => (string) $id,
                                        'quantity'  => $quantity,
                                    ];
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        // Ignore this distributor on error; others may still succeed.
                        continue;
                    }
                }

                if (empty($carrier_distributors)) {
                    $global_error = __('No products were found for this UPC in any connected distributor.', 'ffl-hub');
                } else {
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
                        $global_error = __('Distributors carry this UPC, but no valid true cost was found.', 'ffl-hub');
                        // Fallback: just pick the first carrier so the card still renders.
                        $first = reset($carrier_distributors);
                        if ($first && isset($first['payload']) && $first['payload'] instanceof DistributorProductPayload) {
                            $selected_product    = $first['payload'];
                            $selected_dist_label = $first['label'];
                            $selected_dist_id    = array_key_first($carrier_distributors);
                        }
                    }
                }

                // If this is a create action and we have a selected product, create Woo product.
                if (
                    $action === 'create'
                    && $selected_product instanceof DistributorProductPayload
                    && empty($global_error)
                ) {
                    $create_result = self::create_woo_product_from_payload(
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
     * Create a WooCommerce product from the given payload and carriers.
     *
     * @param string                             $upc
     * @param DistributorProductPayload $selected_product
     * @param string                             $selected_dist_id
     * @param string                             $selected_dist_label
     * @param array                              $carrier_distributors
     *
     * @return array|WP_Error
     */
    private static function create_woo_product_from_payload(
        string $upc,
        DistributorProductPayload $selected_product,
        string $selected_dist_id,
        string $selected_dist_label,
        array $carrier_distributors
    ) {
        // Check if a product already exists for this UPC.
        $existing = get_posts(
            [
                'post_type'      => 'product',
                'post_status'    => ['publish', 'draft', 'pending', 'private'],
                'posts_per_page' => 1,
                'meta_query'     => [
                    [
                        'key'   => ProductMeta::FFLHUB_UPC_META,
                        'value' => $upc,
                    ],
                ],
                'fields'         => 'ids',
            ]
        );

        if (! empty($existing)) {
            $existing_id = (int) $existing[0];
            $edit_link   = get_edit_post_link($existing_id, '');

            $message = sprintf(
                /* translators: 1: product ID, 2: edit URL */
                __('A WooCommerce product already exists for this UPC (ID #%1$d). <a href="%2$s">Edit product</a>.', 'ffl-hub'),
                $existing_id,
                esc_url($edit_link)
            );

            return [
                'message' => $message,
                'type'    => 'warning',
            ];
        }

        // Extract data from the selected payload.
        $payload = get_object_vars($selected_product);

        $name        = $payload['name'] ?? '';
        $sku         = $payload['sku'] ?? '';
        $description = $payload['description'] ?? '';

        $dealer_price = isset($payload['price']) ? (float) $payload['price'] : null;
        $true_cost    = isset($payload['true_cost']) ? (float) $payload['true_cost'] : null;

        // Aggregate MAP/MSRP and FFL flag across all carriers.
        $max_map      = null;
        $max_msrp     = null;
        $qty_sum      = 0;
        $ffl_required = false;

        foreach ($carrier_distributors as $info) {
            $pl = get_object_vars($info['payload']);

            if (isset($pl['map']) && is_numeric($pl['map'])) {
                $val = (float) $pl['map'];
                if ($max_map === null || $val > $max_map) {
                    $max_map = $val;
                }
            }

            if (isset($pl['msrp']) && is_numeric($pl['msrp'])) {
                $val = (float) $pl['msrp'];
                if ($max_msrp === null || $val > $max_msrp) {
                    $max_msrp = $val;
                }
            }

            if (isset($info['quantity']) && is_numeric($info['quantity'])) {
                $qty_sum += (int) $info['quantity'];
            }

            if (isset($pl['ffl_required']) && $pl['ffl_required']) {
                $ffl_required = true;
            }
        }

        // Compute recommended retail price.
        $markup_percent = self::get_global_markup_percent(); // e.g. 0.25 = 25%
        $base_price     = ($true_cost !== null) ? $true_cost * (1 + $markup_percent) : $dealer_price;
        $base_price     = (float) $base_price;
        $recommended    = $base_price;

        if ($max_map !== null && $max_map > $recommended) {
            $recommended = $max_map;
        }

        // Basic safety: if we still somehow have no price, bail.
        if ($recommended === null || $recommended <= 0) {
            return new WP_Error(
                'fflhub_no_price',
                __('Could not compute a valid retail price for this product.', 'ffl-hub')
            );
        }

        // Use WC CRUD to create a simple product:
        $product = new WC_Product_Simple();

        // Title / Description
        $product->set_name($name ?: $sku ?: $upc);
        $product->set_description($description); // full description

        // SKU
        $sku_to_use = $sku ?: $upc;
        if ($sku_to_use) {
            $product->set_sku($sku_to_use);
        }

        // Price
        $product->set_regular_price(wc_format_decimal($recommended, 2));

        // Stock / inventory
        $product->set_manage_stock(true);
        $product->set_stock_quantity((int) $qty_sum);
        $product->set_stock_status($qty_sum > 0 ? 'instock' : 'outofstock');

        // Status / visibility
        $product->set_status('draft');
        $product->set_catalog_visibility('visible');

        /**
         * Set product categories from recommended_category path, if available.
         *
         * expected payload['recommended_category'] like:
         *   [ 'Firearms', 'Handguns', 'Pistols' ]
         */
        if (
            isset($payload['recommended_category']) &&
            is_array($payload['recommended_category']) &&
            ! empty($payload['recommended_category'])
        ) {
            $term_ids = CategoryInstaller::get_term_ids_for_path($payload['recommended_category']);

            if (! empty($term_ids) && is_array($term_ids)) {
                // Ensure unique ints.
                $term_ids = array_values(array_unique(array_map('intval', $term_ids)));

                if (! empty($term_ids)) {
                    // Attach all categories in the path (top, mid, leaf).
                    $product->set_category_ids($term_ids);
                }
            }
        }

        // Save (creates the product post + saves core Woo data + categories)
        $product->save();

        $product_id = $product->get_id();

        if (! $product_id) {
            return new WP_Error(
                'fflhub_insert_failed',
                __('Could not create WooCommerce product via CRUD API.', 'ffl-hub')
            );
        }

        // Now set your custom metadata as before.
        $product->set_global_unique_id($upc);
        $product->update_meta_data(ProductMeta::FFLHUB_UPC_META, $upc);
        $product->update_meta_data(ProductMeta::FFLHUB_MANAGED_META, true);
        $product->update_meta_data(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, $selected_dist_id);
        $product->update_meta_data(ProductMeta::FFLHUB_LAST_TRUE_COST_META, $true_cost);
        $product->update_meta_data(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, $dealer_price);
        $product->update_meta_data(ProductMeta::FFLHUB_LAST_MAP_META, $max_map);
        $product->update_meta_data(ProductMeta::FFLHUB_LAST_MSRP_META, $max_msrp);
        $product->update_meta_data(ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META, $recommended);
        $product->update_meta_data(ProductMeta::FFLHUB_MARKUP_MODE_META, 1);
        $product->update_meta_data(ProductMeta::FFLHUB_MARKUP_PERCENT_META, 0);

        $product->update_meta_data(ProductMeta::FFLHUB_FFL_REQUIRED_META, $ffl_required ? 1 : 0);
        $product->update_meta_data(ProductMeta::FFLHUB_NFA_ITEM_META, 0);

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));

        // Import images from ALL distributors:
        if (class_exists(DistributorProductImages::class)) {
            foreach ($carrier_distributors as $dist_id => $info) {
                if (
                    empty($info['payload']) ||
                    ! ($info['payload'] instanceof DistributorProductPayload)
                ) {
                    continue;
                }

                $is_primary = ((string) $dist_id === (string) $selected_dist_id);

                DistributorProductImages::import_images_for_distributor(
                    $product_id,
                    $upc,
                    $info['payload'],
                    (string) $dist_id,
                    $is_primary
                );
            }
        }

        // Message / return
        $edit_link = get_edit_post_link($product_id, '');

        $message = sprintf(
            /* translators: 1: product ID, 2: edit URL */
            __('Created WooCommerce product ID #%1$d. <a href="%2$s">Edit product</a>.', 'ffl-hub'),
            $product_id,
            esc_url($edit_link)
        );

        $product->save(); // persist meta

        return [
            'message' => $message,
            'type'    => 'success',
        ];
    }

    /**
     * Helper to get the global markup percent as a decimal.
     *
     * Example: 0.25 = 25% markup.
     *
     * @return float
     */
    private static function get_global_markup_percent(): float
    {
        // Adjust the option name to whatever you use on your settings page.
        $raw = get_option('fflhub_global_markup', '25'); // default to 25%

        if (is_numeric($raw)) {
            $percent = (float) $raw;
            if ($percent > 1) {
                // Assume stored as "25" meaning 25%.
                $percent = $percent / 100.0;
            }
            return max(0.0, $percent);
        }

        return 0.25; // Fallback 25%.
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
