<?php

namespace FFLHub\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Product\DistributorProductHelper;
use FFLHub\Distributor\Product\DistributorProductPayload;
use FFLHub\Distributor\Product\DistributorOffer;          // 🆕 ADDED
use FFLHub\Distributor\Product\UpcLookupResult;           // 🆕 ADDED
use FFLHub\Plugin;

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
     * Returns a single "state" array used by render methods.
     *
     * ✅ CHANGED: state now stores offers + UpcLookupResult-derived fields,
     *            not the old "carriers" array of nested arrays.
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
        error_log('--- FFLHub handle_request START ---');

        $state = [
            'action' => '',
            'upc_value' => '',
            'global_error' => '',
            'create_notice' => '',
            'create_notice_type' => 'success',

            // ✅ CHANGED: replace carrier_distributors with offers
            'offers' => [],
            'cheapest_in_stock' => null,
            'cheapest_any' => null,
            'selected_offer' => null,          // 🆕 ADDED

            'selected_product' => null,
            'selected_dist_id' => '',
            'selected_dist_label' => '',

            'posted_selected_dist_id' => '',
        ];

        // 1️⃣ Detect action
        $action = self::detect_action();
        $state['action'] = $action;

        error_log('Action detected: ' . ($action ?: '[none]'));

        if ($action === '') {
            error_log('No action detected → initial page load, returning empty state');
            error_log('--- FFLHub handle_request END ---');
            return $state;
        }

        // 2️⃣ Read UPC
        $upc = self::read_post_upc();
        $state['upc_value'] = $upc;

        error_log('UPC read from POST: ' . ($upc ?: '[empty]'));

        if ($upc === '') {
            $state['global_error'] = __('Please enter a UPC.', 'ffl-hub');
            error_log('ERROR: UPC empty, aborting');
            error_log('--- FFLHub handle_request END ---');
            return $state;
        }

        // 3️⃣ Nonce + create-only fields
        if ($action === 'search') {
            error_log('Action is SEARCH → checking search nonce');
            check_admin_referer('fflhub_distributor_products_search');
        } elseif ($action === 'create') {
            error_log('Action is CREATE → checking create nonce');
            check_admin_referer('fflhub_distributor_products_create');

            $state['posted_selected_dist_id'] = self::read_post_selected_distributor();
            error_log('Posted selected distributor ID: ' . ($state['posted_selected_dist_id'] ?: '[none]'));
        }

        // 4️⃣ Lookup
        error_log('Performing distributor lookup for UPC: ' . $upc);

        $lookup_result = DistributorProductHelper::get_upc_lookup_result_from_distributors($upc); 

        if (! ($lookup_result instanceof UpcLookupResult)) {               // ✅ CHANGED
            error_log('Lookup FAILED');
            error_log('Global error: ' . ($state['global_error'] ?: '[none]'));
            error_log('--- FFLHub handle_request END ---');
            return $state;
        }

        error_log('Lookup SUCCESS');

        // 5️⃣ Extract lookup results
        $state['offers'] = $lookup_result->offers();                       // ✅ CHANGED
        $state['cheapest_in_stock'] = $lookup_result->cheapest_in_stock(); // ✅ CHANGED
        $state['cheapest_any']      = $lookup_result->cheapest_any();      // ✅ CHANGED

        error_log('Carrier distributor IDs: ' . implode(', ', array_keys($state['offers'])));

        if ($state['cheapest_in_stock'] instanceof DistributorOffer) {
            error_log('Cheapest IN-STOCK distributor: ' . $state['cheapest_in_stock']->distributor_id);
        } else {
            error_log('No cheapest_in_stock found');
        }

        if ($state['cheapest_any'] instanceof DistributorOffer) {
            error_log('Cheapest ANY distributor: ' . $state['cheapest_any']->distributor_id);
        } else {
            error_log('No cheapest_any found');
        }

        if (empty($state['offers']) && $state['global_error'] === '') {
            $state['global_error'] = __('No products were found for this UPC in any connected distributor.', 'ffl-hub');
            error_log('ERROR: No offers returned');
            error_log('--- FFLHub handle_request END ---');
            return $state;
        }

        // 6️⃣ Selection logic
        error_log('Selecting product for display / create');
        self::select_product_for_display($state); // ✅ CHANGED: now selects offer + payload

        if ($state['selected_product'] instanceof DistributorProductPayload) {
            error_log('Selected distributor ID: ' . $state['selected_dist_id']);
            error_log('Selected distributor label: ' . $state['selected_dist_label']);
            error_log('Selected product UPC: ' . ($state['selected_product']->upc ?? '[none]'));
        } else {
            error_log('ERROR: No selected product after selection');
            error_log('Global error: ' . ($state['global_error'] ?: '[none]'));
        }

        // 7️⃣ Early exit for search
        if ($action === 'search') {
            error_log('Action is SEARCH → skipping create step');
            error_log('--- FFLHub handle_request END ---');
            return $state;
        }

        // 8️⃣ Create Woo Product
        error_log('Action is CREATE → evaluating create conditions');

        if (
            $state['selected_product'] instanceof DistributorProductPayload
            && $state['global_error'] === ''
        ) {
            error_log('Create conditions met → creating Woo product');

            self::create_woo_product($upc, $state);

            error_log('Create notice type: ' . $state['create_notice_type']);
            error_log('Create notice message: ' . ($state['create_notice'] ?: '[none]'));
        } else {
            error_log('Create skipped due to missing product or global error');
        }

        error_log('--- FFLHub handle_request END ---');

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
     * Deterministic on create (honor posted_selected_dist_id if present),
     * otherwise cheapest-in-stock then cheapest-any then first offer.
     *
     * ✅ CHANGED: uses DistributorOffer instances instead of nested arrays.
     */
    private static function select_product_for_display(array &$state): void
    {
        /** @var array<string, DistributorOffer> $offers */
        $offers = (array) ($state['offers'] ?? []);

        // Deterministic create: honor posted distributor if present and valid.
        if (
            $state['action'] === 'create'
            && $state['posted_selected_dist_id'] !== ''
            && isset($offers[$state['posted_selected_dist_id']])
            && ($offers[$state['posted_selected_dist_id']] instanceof DistributorOffer)
        ) {
            $offer = $offers[$state['posted_selected_dist_id']];

            $state['selected_offer']     = $offer;                 // ✅ CHANGED
            $state['selected_product']   = $offer->product;         // ✅ CHANGED
            $state['selected_dist_id']   = $offer->distributor_id;  // ✅ CHANGED
            $state['selected_dist_label']= $offer->label;           // ✅ CHANGED
            return;
        }

        // Normal selection: cheapest in-stock, else cheapest any.
        if ($state['cheapest_in_stock'] instanceof DistributorOffer) {
            $offer = $state['cheapest_in_stock'];

            $state['selected_offer']      = $offer;                // ✅ CHANGED
            $state['selected_product']    = $offer->product;        // ✅ CHANGED
            $state['selected_dist_id']    = $offer->distributor_id; // ✅ CHANGED
            $state['selected_dist_label'] = $offer->label;          // ✅ CHANGED
            return;
        }

        if ($state['cheapest_any'] instanceof DistributorOffer) {
            $offer = $state['cheapest_any'];

            $state['selected_offer']      = $offer;                // ✅ CHANGED
            $state['selected_product']    = $offer->product;        // ✅ CHANGED
            $state['selected_dist_id']    = $offer->distributor_id; // ✅ CHANGED
            $state['selected_dist_label'] = $offer->label;          // ✅ CHANGED
            return;
        }

        // Absolute fallback: first offer if present.
        $first = reset($offers);
        if ($first instanceof DistributorOffer) {
            $state['selected_offer']      = $first;                // ✅ CHANGED
            $state['selected_product']    = $first->product;        // ✅ CHANGED
            $state['selected_dist_id']    = $first->distributor_id; // ✅ CHANGED
            $state['selected_dist_label'] = $first->label;          // ✅ CHANGED
            return;
        }

        $state['global_error'] = __('Distributors carry this UPC, but no valid product payload was found.', 'ffl-hub');
    }

    private static function create_woo_product(string $upc, array &$state): void
    {
        // ✅ CHANGED: carrier_distributors replaced by offers map
        $result = DistributorProductHelper::create_woo_product_from_payload(
            $upc,
            $state['selected_product'],
            $state['selected_dist_id'],
            $state['selected_dist_label'],
            $state['offers'] // ✅ CHANGED: pass offers (you'll update helper signature accordingly)
        );

        if (is_wp_error($result)) {
            $state['create_notice']      = $result->get_error_message();
            $state['create_notice_type'] = 'error';
            return;
        }

        $state['create_notice']      = $result['message'] ?? '';
        $state['create_notice_type'] = $result['type'] ?? 'success';
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

        $p_reccomended_price = DistributorProductHelper::get_reccomended_price_from_payload($selected_product);
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
        $offers = (array) ($state['offers'] ?? []); // ✅ CHANGED

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
                        <strong><?php esc_html_e('Reccomended Price:', 'ffl-hub'); ?></strong>
                        <?php echo ' ' . esc_html(self::format_price(is_numeric($p_reccomended_price) ? (float) $p_reccomended_price : null)); ?>
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

                <!-- Create Woo Product button -->
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

        <?php if (! empty($offers)) : ?> <!-- ✅ CHANGED -->
            <h3><?php esc_html_e('Available From', 'ffl-hub'); ?></h3>
            <ul class="fflhub-product-carriers-list">
                <?php foreach ($offers as $dist_id => $offer) : ?> <!-- ✅ CHANGED -->
                    <?php
                    if (! ($offer instanceof DistributorOffer)) {
                        continue;
                    }

                    $label = (string) ($offer->label ?: $dist_id);     // ✅ CHANGED
                    $payload_row = $offer->product;                    // ✅ CHANGED

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

        $formatted = number_format_i18n($price, 2);

        return '$' . $formatted;
    }
}
