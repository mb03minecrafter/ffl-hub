<?php

namespace FFLHub\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Product\DistributorProductHelper;
use FFLHub\Distributor\Product\DistributorProductPayload;
use FFLHub\Distributor\Product\DistributorOffer;
use FFLHub\Distributor\Product\UpcLookupResult;
use WP_Error;

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
            AdminPage::get_page_slug(),
            __('Distributor Products', 'ffl-hub'),
            __('Distributor Products', 'ffl-hub'),
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

        $state = self::handle_request();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Distributor Products', 'ffl-hub'); ?></h1>

            <p>
                <?php esc_html_e(
                    'Use this page to search for products from your connected distributors by UPC.',
                    'ffl-hub'
                ); ?>
            </p>

            <?php self::render_notices($state); ?>
            <?php self::render_search_form($state); ?>

            <?php if ($state['selected_product'] instanceof DistributorProductPayload) : ?>
                <?php self::render_product_result($state); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle POST, lookup, selection, and create flow.
     *
     * @return array{
     *   action:string,
     *   upc_value:string,
     *   global_error:string,
     *   create_notice:string,
     *   create_notice_type:string,
     *   offers:array<string,DistributorOffer>,
     *   cheapest_in_stock:?DistributorOffer,
     *   cheapest_any:?DistributorOffer,
     *   selected_offer:?DistributorOffer,
     *   selected_product:DistributorProductPayload|null,
     *   selected_dist_id:string,
     *   selected_dist_label:string,
     *   posted_selected_dist_id:string
     * }
     */
    private static function handle_request(): array
    {
        $state = [
            'action' => '',
            'upc_value' => '',
            'global_error' => '',
            'create_notice' => '',
            'create_notice_type' => 'success',

            'offers' => [],
            'cheapest_in_stock' => null,
            'cheapest_any' => null,
            'selected_offer' => null,

            'selected_product' => null,
            'selected_dist_id' => '',
            'selected_dist_label' => '',

            'posted_selected_dist_id' => '',
        ];

        // 1) Detect action
        $action = self::detect_action();
        $state['action'] = $action;

        self::log_debug("[FFLHub][DistributorProductsPage] Action detected: " . ($action ?: '[none]'));

        if ($action === '') {
            return $state;
        }

        // 2) Read UPC
        $upc = self::read_post_upc();
        $state['upc_value'] = $upc;

        if ($upc === '') {
            $state['global_error'] = __('Please enter a UPC.', 'ffl-hub');
            return $state;
        }

        // 3) Nonce + create-only fields
        if ($action === 'search') {
            check_admin_referer('fflhub_distributor_products_search');
        } elseif ($action === 'create') {
            check_admin_referer('fflhub_distributor_products_create');
            $state['posted_selected_dist_id'] = self::read_post_selected_distributor();
        }

        // 4) Lookup (CHANGED: can return UpcLookupResult OR WP_Error)
        $lookup_result = DistributorProductHelper::get_upc_lookup_result_from_distributors($upc);

        if (is_wp_error($lookup_result)) {
            $state['global_error'] = $lookup_result->get_error_message();
            self::log_debug("[FFLHub][DistributorProductsPage] Lookup WP_Error: " . $state['global_error']);
            return $state;
        }

        if (! ($lookup_result instanceof UpcLookupResult)) {
            $state['global_error'] = __('Lookup failed for an unknown reason.', 'ffl-hub');
            self::log_debug("[FFLHub][DistributorProductsPage] Lookup failed: unexpected return type.");
            return $state;
        }

        // 5) Extract lookup results
        $state['offers'] = $lookup_result->offers();
        $state['cheapest_in_stock'] = $lookup_result->cheapest_in_stock();
        $state['cheapest_any']      = $lookup_result->cheapest_any();

        if (empty($state['offers'])) {
            $state['global_error'] = __('No products were found for this UPC in any connected distributor.', 'ffl-hub');
            self::log_debug("[FFLHub][DistributorProductsPage] No offers found for UPC {$upc}");
            return $state;
        }

        // 6) Selection logic
        self::select_product_for_display($state);

        if (! ($state['selected_product'] instanceof DistributorProductPayload)) {
            if ($state['global_error'] === '') {
                $state['global_error'] = __('Distributors carry this UPC, but no valid product payload was found.', 'ffl-hub');
            }
            self::log_debug("[FFLHub][DistributorProductsPage] Selection failed for UPC {$upc}: " . $state['global_error']);
            return $state;
        }

        // 7) Early exit for search
        if ($action === 'search') {
            return $state;
        }

        // 8) Create Woo Product
        if ($state['global_error'] === '') {
            $result = self::create_woo_product($upc, $state);
            if (is_wp_error($result)) {
                $state['create_notice']      = $result->get_error_message();
                $state['create_notice_type'] = 'error';
                return $state;
            }
        }

        return $state;
    }

    private static function detect_action(): string
    {
        if (isset($_POST['fflhub_distributor_search'])) {
            return 'search';
        }
        if (isset($_POST['fflhub_distributor_create_product'])) {
            return 'create';
        }
        return '';
    }

    private static function read_post_upc(): string
    {
        if (! isset($_POST['fflhub_distributor_upc'])) {
            return '';
        }
        return sanitize_text_field(wp_unslash($_POST['fflhub_distributor_upc']));
    }

    private static function read_post_selected_distributor(): string
    {
        if (! isset($_POST['fflhub_selected_distributor'])) {
            return '';
        }
        return sanitize_text_field(wp_unslash($_POST['fflhub_selected_distributor']));
    }

    /**
     * Mutates $state: sets selected_offer/product/dist_id/dist_label.
     *
     * Deterministic on create (honor posted_selected_dist_id if present),
     * otherwise cheapest-in-stock then cheapest-any then first offer.
     */
    private static function select_product_for_display(array &$state): void
    {
        /** @var array<string, DistributorOffer> $offers */
        $offers = (array) ($state['offers'] ?? []);

        if (
            $state['action'] === 'create'
            && $state['posted_selected_dist_id'] !== ''
            && isset($offers[$state['posted_selected_dist_id']])
            && ($offers[$state['posted_selected_dist_id']] instanceof DistributorOffer)
        ) {
            $offer = $offers[$state['posted_selected_dist_id']];

            $state['selected_offer']      = $offer;
            $state['selected_product']    = $offer->product;
            $state['selected_dist_id']    = $offer->distributor_id;
            $state['selected_dist_label'] = $offer->label;
            return;
        }

        if ($state['cheapest_in_stock'] instanceof DistributorOffer) {
            $offer = $state['cheapest_in_stock'];

            $state['selected_offer']      = $offer;
            $state['selected_product']    = $offer->product;
            $state['selected_dist_id']    = $offer->distributor_id;
            $state['selected_dist_label'] = $offer->label;
            return;
        }

        if ($state['cheapest_any'] instanceof DistributorOffer) {
            $offer = $state['cheapest_any'];

            $state['selected_offer']      = $offer;
            $state['selected_product']    = $offer->product;
            $state['selected_dist_id']    = $offer->distributor_id;
            $state['selected_dist_label'] = $offer->label;
            return;
        }

        $first = reset($offers);
        if ($first instanceof DistributorOffer) {
            $state['selected_offer']      = $first;
            $state['selected_product']    = $first->product;
            $state['selected_dist_id']    = $first->distributor_id;
            $state['selected_dist_label'] = $first->label;
            return;
        }

        $state['global_error'] = __('Distributors carry this UPC, but no valid product payload was found.', 'ffl-hub');
    }

    /**
     * Create and return WP_Error on failure.
     */
    private static function create_woo_product(string $upc, array &$state)
    {
        $result = DistributorProductHelper::create_woo_product_from_payload(
            $upc,
            $state['selected_product'],
            $state['selected_dist_id'],
            $state['selected_dist_label'],
            $state['offers']
        );

        if (is_wp_error($result)) {
            $state['create_notice']      = $result->get_error_message();
            $state['create_notice_type'] = 'error';
            return $result;
        }

        $state['create_notice']      = $result['message'] ?? '';
        $state['create_notice_type'] = $result['type'] ?? 'success';
        return $result;
    }

    private static function render_notices(array $state): void
    {
        if (! empty($state['global_error'])) : ?>
            <div class="notice notice-error">
                <p><?php echo esc_html($state['global_error']); ?></p>
            </div>
        <?php endif; ?>

        <?php if (! empty($state['create_notice'])) : ?>
            <div class="notice notice-<?php echo esc_attr($state['create_notice_type']); ?>">
                <p><?php echo wp_kses_post($state['create_notice']); ?></p>
            </div>
        <?php endif;
    }

    private static function render_search_form(array $state): void
    {
        ?>
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
                            value="<?php echo esc_attr($state['upc_value']); ?>" />
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
        <?php
    }

    private static function render_product_result(array $state): void
    {
        $selected_product = $state['selected_product'];
        if (! ($selected_product instanceof DistributorProductPayload)) {
            return;
        }

        $p_upc         = (string) ($selected_product->upc ?? '');
        $p_sku         = (string) ($selected_product->sku ?? '');
        $p_name        = (string) ($selected_product->name ?? '');
        $p_description = (string) ($selected_product->description ?? '');

        $p_price     = $selected_product->price ?? null;
        $p_map       = $selected_product->map ?? null;
        $p_msrp      = $selected_product->msrp ?? null;
        $p_quantity  = $selected_product->quantity ?? null;
        $p_shipping  = $selected_product->shipping_cost ?? null;
        $p_true_cost = $selected_product->true_cost ?? null;

        // CHANGED: prefer correctly spelled wrapper for UI usage
        $p_recommended_price = DistributorProductHelper::get_recommended_price_from_payload($selected_product);

        $p_image_url         = $selected_product->get_primary_image_url();
        $p_ffl_required      = (bool) ($selected_product->ffl_required ?? false);

        $p_recommended_category       = $selected_product->recommended_category ?? null;
        $p_recommended_category_label = '';

        if (is_array($p_recommended_category) && ! empty($p_recommended_category)) {
            $p_recommended_category_label = implode(' > ', array_map('strval', $p_recommended_category));
        } elseif (is_string($p_recommended_category) && $p_recommended_category !== '') {
            $p_recommended_category_label = $p_recommended_category;
        }

        $selected_dist_label = (string) ($state['selected_dist_label'] ?? '');
        $selected_dist_id    = (string) ($state['selected_dist_id'] ?? '');

        /** @var array<string, DistributorOffer> $offers */
        $offers = (array) ($state['offers'] ?? []);

        ?>
        <hr />

        <h2>
            <?php
            printf(
                esc_html__('Product for UPC %s', 'ffl-hub'),
                esc_html($p_upc ?: $state['upc_value'])
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
                        <?php echo ' ' . esc_html(self::format_price(is_numeric($p_price) ? (float) $p_price : null)); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('MAP:', 'ffl-hub'); ?></strong>
                        <?php echo ' ' . esc_html(self::format_price(is_numeric($p_map) ? (float) $p_map : null)); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('MSRP:', 'ffl-hub'); ?></strong>
                        <?php echo ' ' . esc_html(self::format_price(is_numeric($p_msrp) ? (float) $p_msrp : null)); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Shipping Cost:', 'ffl-hub'); ?></strong>
                        <?php echo ' ' . esc_html(self::format_price(is_numeric($p_shipping) ? (float) $p_shipping : null)); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('True Cost:', 'ffl-hub'); ?></strong>
                        <?php echo ' ' . esc_html(self::format_price(is_numeric($p_true_cost) ? (float) $p_true_cost : null)); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Recommended Price:', 'ffl-hub'); ?></strong>
                        <?php echo ' ' . esc_html(self::format_price(is_numeric($p_recommended_price) ? (float) $p_recommended_price : null)); ?>
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
                            if ((int) $p_quantity <= 0) {
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

                <div class="fflhub-product-card-actions" style="margin-top: 12px;">
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('fflhub_distributor_products_create'); ?>
                        <input type="hidden" name="fflhub_distributor_upc" value="<?php echo esc_attr($p_upc ?: $state['upc_value']); ?>" />
                        <input type="hidden" name="fflhub_selected_distributor" value="<?php echo esc_attr($selected_dist_id); ?>" />
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

        <?php if (! empty($offers)) : ?>
            <h3><?php esc_html_e('Available From', 'ffl-hub'); ?></h3>
            <ul class="fflhub-product-carriers-list">
                <?php foreach ($offers as $dist_id => $offer) : ?>
                    <?php
                    if (! ($offer instanceof DistributorOffer)) {
                        continue;
                    }

                    $label = (string) ($offer->label ?: $dist_id);
                    $payload_row = $offer->product;

                    if (! ($payload_row instanceof DistributorProductPayload)) {
                        continue;
                    }

                    $tc       = $payload_row->true_cost ?? null;
                    $dealer   = $payload_row->price ?? null;
                    $map      = $payload_row->map ?? null;
                    $msrp     = $payload_row->msrp ?? null;
                    $shipping = $payload_row->shipping_cost ?? null;
                    $qty      = $payload_row->quantity ?? null;
                    $ffl_req_row = (bool) ($payload_row->ffl_required ?? false);
                    ?>
                    <li class="fflhub-product-carrier-item">
                        <div class="fflhub-product-carrier-header">
                            <strong><?php echo esc_html($label); ?></strong>
                        </div>
                        <div class="fflhub-product-carrier-metrics">
                            <span>
                                <strong><?php esc_html_e('True Cost:', 'ffl-hub'); ?></strong>
                                <?php echo ' ' . esc_html(self::format_price(is_numeric($tc) ? (float) $tc : null)); ?>
                            </span>
                            <span>
                                <strong><?php esc_html_e('Dealer:', 'ffl-hub'); ?></strong>
                                <?php echo ' ' . esc_html(self::format_price(is_numeric($dealer) ? (float) $dealer : null)); ?>
                            </span>
                            <span>
                                <strong><?php esc_html_e('MAP:', 'ffl-hub'); ?></strong>
                                <?php echo ' ' . esc_html(self::format_price(is_numeric($map) ? (float) $map : null)); ?>
                            </span>
                            <span>
                                <strong><?php esc_html_e('MSRP:', 'ffl-hub'); ?></strong>
                                <?php echo ' ' . esc_html(self::format_price(is_numeric($msrp) ? (float) $msrp : null)); ?>
                            </span>
                            <span>
                                <strong><?php esc_html_e('Shipping:', 'ffl-hub'); ?></strong>
                                <?php echo ' ' . esc_html(self::format_price(is_numeric($shipping) ? (float) $shipping : null)); ?>
                            </span>
                            <span>
                                <strong><?php esc_html_e('Qty:', 'ffl-hub'); ?></strong>
                                <?php
                                if ($qty === null) {
                                    esc_html_e('Unknown', 'ffl-hub');
                                } else {
                                    echo ' ' . esc_html((string) $qty);
                                    if ((int) $qty <= 0) {
                                        echo ' ' . esc_html__('(Out of stock)', 'ffl-hub');
                                    }
                                }
                                ?>
                            </span>
                            <span>
                                <strong><?php esc_html_e('FFL:', 'ffl-hub'); ?></strong>
                                <?php echo ' ' . ($ffl_req_row ? esc_html__('Required', 'ffl-hub') : esc_html__('No', 'ffl-hub')); ?>
                            </span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php
    }

    private static function format_price(?float $price): string
    {
        if ($price === null) {
            return __('N/A', 'ffl-hub');
        }

        $formatted = number_format_i18n($price, 2);

        return '$' . $formatted;
    }

    private static function log_debug(string $message): void
    {
        // CHANGED: gated logging (no unconditional error_log spam)
        if (! defined('FFLHUB_ADMIN_DEBUG') || FFLHUB_ADMIN_DEBUG !== true) {
            return;
        }
        error_log($message);
    }
}
