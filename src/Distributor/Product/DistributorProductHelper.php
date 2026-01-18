<?php

namespace FFLHub\Distributor\Product;

use FFLHub\Product\ProductMeta;
use FFLHub\Product\CategoryInstaller;
use FFLHub\Settings\Options;
use FFLHub\Plugin;

use WP_Error;
use WC_Product_Simple;
use WP_Query;

class DistributorProductHelper
{
    /**
     * Snapshot meta key for persisting the offers map at creation time.
     * (New; intentionally does not require ProductMeta changes.)
     */
    private const OFFERS_SNAPSHOT_META_KEY = 'fflhub_offers_snapshot';

    /**
     * Create a WooCommerce product from a selected distributor payload,
     * while also importing images from all distributors that carry the UPC.
     *
     * @param string                       $upc
     * @param DistributorProductPayload    $selected_product
     * @param string                       $selected_dist_id
     * @param string                       $selected_dist_label
     * @param array<string, DistributorOffer> $offers
     */
    public static function create_woo_product_from_payload(
        string $upc,
        DistributorProductPayload $selected_product,
        string $selected_dist_id,
        string $selected_dist_label,
        array $offers
    ) {
        // A) Guard: prevent duplicates (UPC already exists)
        $existing_id = self::find_existing_product_id_by_upc($upc);
        if ($existing_id !== null) {
            return self::build_existing_product_response($existing_id);
        }

        // B) Compute retail price (based on selected payload)
        // CHANGED: use correctly spelled wrapper (keeps old function for compatibility)
        $recommended_price = self::get_recommended_price_from_payload($selected_product);

        if ($recommended_price === null || $recommended_price <= 0) {
            return new WP_Error(
                'fflhub_no_price',
                __('Could not compute a valid retail price for this product.', 'ffl-hub')
            );
        }

        // C) Build WC product core fields (name/desc/sku/price/stock/status)
        $product = self::build_wc_product_from_payload(
            $upc,
            $selected_product,
            $recommended_price
        );

        // D) Apply categories (recommended_category path)
        self::apply_categories_from_payload($product, $selected_product);

        // E) Save product and get ID
        $product_id = self::save_and_get_id($product);
        if (! $product_id) {
            return new WP_Error(
                'fflhub_insert_failed',
                __('Could not create WooCommerce product via CRUD API.', 'ffl-hub')
            );
        }

        // F) Apply FFLHub meta (selected payload snapshot)
        self::apply_fflhub_meta_from_payload(
            $product,
            $upc,
            $selected_dist_id,
            $selected_product,
            $recommended_price
        );

        // (8) NEW: persist an offers snapshot for debugging / future auto-switch logic
        self::store_offers_snapshot_meta($product, $offers, $selected_dist_id);

        // (2) CHANGED: validate offers includes selected distributor (log + continue)
        if (! isset($offers[$selected_dist_id])) {
            self::log_debug(
                "[FFLHub][DistributorProductHelper] Selected distributor '{$selected_dist_id}' not present in offers map; continuing."
            );
        }

        // G) Import images from all distributors (selected distributor marked primary)
        self::import_images_from_offers(
            $product_id,
            $upc,
            $offers,
            $selected_dist_id
        );

        // Persist meta
        $product->save();

        // H) Return success response
        return self::build_created_product_response($product_id);
    }

    /**
     * A) Find existing product by UPC meta.
     */
    private static function find_existing_product_id_by_upc(string $upc): ?int
    {
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

        if (empty($existing)) {
            return null;
        }

        return (int) $existing[0];
    }

    private static function build_existing_product_response(int $existing_id): array
    {
        $edit_link = get_edit_post_link($existing_id, '');

        $message = sprintf(
            __('A WooCommerce product already exists for this UPC (ID #%1$d). <a href="%2$s">Edit product</a>.', 'ffl-hub'),
            $existing_id,
            esc_url($edit_link)
        );

        return [
            'message' => $message,
            'type'    => 'warning',
        ];
    }

    /**
     * C) Build the WC product core fields from the selected payload.
     */
    private static function build_wc_product_from_payload(
        string $upc,
        DistributorProductPayload $selected_product,
        float $recommended_price
    ): WC_Product_Simple {
        $product = new WC_Product_Simple();

        $name        = $selected_product->name;
        $sku         = $selected_product->sku;
        $description = $selected_product->description;
        $qty         = (int) $selected_product->quantity;

        $product->set_name($name ?: $sku ?: $upc);
        $product->set_description($description);

        $sku_to_use = $sku ?: $upc;
        if ($sku_to_use) {
            $product->set_sku($sku_to_use);
        }

        $product->set_regular_price(wc_format_decimal($recommended_price, 2));

        $product->set_manage_stock(true);
        $product->set_stock_quantity($qty);
        $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');

        $product->set_status('draft');
        $product->set_catalog_visibility('visible');

        return $product;
    }

    private static function apply_categories_from_payload(
        WC_Product_Simple $product,
        DistributorProductPayload $selected_product
    ): void {
        $recommended_category = $selected_product->recommended_category ?? null;

        if (! is_array($recommended_category) || empty($recommended_category)) {
            return;
        }

        $term_ids = CategoryInstaller::get_term_ids_for_path($recommended_category);

        if (! is_array($term_ids) || empty($term_ids)) {
            return;
        }

        $term_ids = array_values(array_unique(array_map('intval', $term_ids)));

        if (! empty($term_ids)) {
            $product->set_category_ids($term_ids);
        }
    }

    private static function save_and_get_id(WC_Product_Simple $product): int
    {
        $product->save();
        return (int) $product->get_id();
    }

    /**
     * F) Write all plugin meta (based on the selected payload).
     */
    public static function apply_fflhub_meta_from_payload(
        WC_Product_Simple $product,
        string $upc,
        string $selected_dist_id,
        DistributorProductPayload $selected_product,
        float $recommended_price
    ): void {
        $dealer_price = $selected_product->price;
        $true_cost    = $selected_product->true_cost;

        $map          = $selected_product->map;
        $msrp         = $selected_product->msrp;
        $ffl_required = $selected_product->ffl_required;

        $ship_cost = $selected_product->shipping_cost ?? null;

        // (5) keep global_unique_id (UPC/GTIN-ish) but only set if non-empty
        if ($upc !== '') {
            $product->set_global_unique_id($upc);
        }

        $product->update_meta_data(ProductMeta::FFLHUB_UPC_META, $upc);

        // (7) CHANGED: store booleans consistently as 1/0
        $product->update_meta_data(ProductMeta::FFLHUB_MANAGED_META, 1);

        $product->update_meta_data(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, $selected_dist_id);

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_TRUE_COST_META, $true_cost);
        $product->update_meta_data(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, $dealer_price);

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_MAP_META, $map);
        $product->update_meta_data(ProductMeta::FFLHUB_LAST_MSRP_META, $msrp);

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META, $recommended_price);

        $product->update_meta_data(ProductMeta::FFLHUB_FFL_REQUIRED_META, $ffl_required ? 1 : 0);
        $product->update_meta_data(ProductMeta::FFLHUB_NFA_ITEM_META, 0);

        $product->update_meta_data(ProductMeta::FFLHUB_MARKUP_MODE_META, ProductMeta::MARKUP_MODE_GLOBAL);
        $product->update_meta_data(ProductMeta::FFLHUB_MARKUP_PERCENT_META, 0);
        $product->update_meta_data(ProductMeta::FFLHUB_FIXED_PRICE_META, '');

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, $ship_cost);

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));
    }

    /**
     * Sync-time update of LAST_* snapshot meta.
     */
    public static function update_fflhub_meta_from_payload_for_sync(
        WC_Product_Simple $product,
        string $selected_dist_id,
        DistributorProductPayload $selected_product,
        float $recommended_price
    ): void {
        $dealer_price = $selected_product->price;
        $true_cost    = $selected_product->true_cost;

        $map      = $selected_product->map;
        $msrp     = $selected_product->msrp;
        $ship_cost = $selected_product->shipping_cost ?? null;

        // (9) CHANGED: also sync ffl_required so the snapshot stays accurate
        $ffl_required = $selected_product->ffl_required;

        $product->update_meta_data(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, $selected_dist_id);

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_TRUE_COST_META, $true_cost);
        $product->update_meta_data(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, $dealer_price);

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_MAP_META, $map);
        $product->update_meta_data(ProductMeta::FFLHUB_LAST_MSRP_META, $msrp);

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META, $recommended_price);
        $product->update_meta_data(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, $ship_cost);

        $product->update_meta_data(ProductMeta::FFLHUB_FFL_REQUIRED_META, $ffl_required ? 1 : 0);

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));
    }

    /**
     * G) Import images from all distributors (selected distributor marked primary).
     *
     * (3) CHANGED: does not depend on array keys being correct; uses $offer->distributor_id.
     */
    private static function import_images_from_offers(
        int $product_id,
        string $upc,
        array $offers,
        string $selected_dist_id
    ): void {
        if (! class_exists(DistributorProductImages::class)) {
            return;
        }

        foreach ($offers as $offer) {
            if (! ($offer instanceof DistributorOffer)) {
                continue;
            }

            $payload = $offer->product;
            if (! ($payload instanceof DistributorProductPayload)) {
                continue;
            }

            $is_primary = ((string) $offer->distributor_id === (string) $selected_dist_id);

            DistributorProductImages::import_images_for_distributor(
                $product_id,
                $upc,
                $payload,
                (string) $offer->distributor_id,
                $is_primary
            );
        }
    }

    /**
     * (8) NEW: store a compact offers snapshot to product meta (JSON).
     */
    private static function store_offers_snapshot_meta(
        WC_Product_Simple $product,
        array $offers,
        string $selected_dist_id
    ): void {
        $snapshot = [
            'captured_at' => current_time('mysql'),
            'selected_dist_id' => (string) $selected_dist_id,
            'offers' => [],
        ];

        foreach ($offers as $offer) {
            if (! ($offer instanceof DistributorOffer)) {
                continue;
            }
            if (! ($offer->product instanceof DistributorProductPayload)) {
                continue;
            }

            $p = $offer->product;

            $snapshot['offers'][(string) $offer->distributor_id] = [
                'label' => (string) $offer->label,
                'true_cost' => $p->true_cost,
                'dealer_price' => $p->price,
                'map' => $p->map,
                'msrp' => $p->msrp,
                'shipping_cost' => $p->shipping_cost,
                'qty' => $p->quantity,
                'ffl_required' => $p->ffl_required ? 1 : 0,
                'sku' => $p->sku ?? '',
            ];
        }

        // Store as JSON for portability
        $product->update_meta_data(self::OFFERS_SNAPSHOT_META_KEY, wp_json_encode($snapshot));
    }

    /**
     * (1) CHANGED: return UpcLookupResult|WP_Error so caller can display errors.
     *
     * @return UpcLookupResult|WP_Error
     */
    public static function get_upc_lookup_result_from_distributors(string $upc)
    {
        $plugin  = Plugin::instance();
        $handler = $plugin->distributor_handler ?? null;

        if (! $handler || ! method_exists($handler, 'get_payloads_for_upc')) {
            return new WP_Error(
                'fflhub_lookup_handler_missing',
                __('Distributor handler is not available for lookups.', 'ffl-hub')
            );
        }

        try {
            $lookup = $handler->get_payloads_for_upc($upc);
        } catch (\Throwable $e) {
            self::log_debug("[FFLHub][DistributorProductHelper] Lookup exception: " . $e->getMessage());

            return new WP_Error(
                'fflhub_lookup_exception',
                __('An error occurred while fetching distributor data.', 'ffl-hub')
            );
        }

        if (! ($lookup instanceof UpcLookupResult)) {
            return new WP_Error(
                'fflhub_lookup_bad_return',
                __('Distributor lookup did not return a valid result object.', 'ffl-hub')
            );
        }

        return $lookup;
    }

    private static function build_created_product_response(int $product_id): array
    {
        $edit_link = get_edit_post_link($product_id, '');

        $message = sprintf(
            __('Created WooCommerce product ID #%1$d. <a href="%2$s">Edit product</a>.', 'ffl-hub'),
            $product_id,
            esc_url($edit_link)
        );

        return [
            'message' => $message,
            'type'    => 'success',
        ];
    }

    /**
     * (4) NEW: correct spelling wrapper. Use this everywhere going forward.
     */
    public static function get_recommended_price_from_payload(DistributorProductPayload $selected_product): ?float
    {
        // keep logic identical to prior behavior
        $dealer_price   = $selected_product->price;
        $true_cost      = $selected_product->true_cost;
        $markup_percent = Options::get_global_markup() / 100;

        $base_price = ($true_cost !== null)
            ? $true_cost * (1 + $markup_percent)
            : $dealer_price;

        $base_price = (float) $base_price;

        $recommended_price = ceil($base_price) - 0.01;

        return $recommended_price;
    }

    /**
     * (4) Back-compat misspelled method (keep existing callers working).
     * You can remove later once you’ve replaced all call sites.
     */
    public static function get_reccomended_price_from_payload(DistributorProductPayload $selected_product): ?float
    {
        return self::get_recommended_price_from_payload($selected_product);
    }

    public static function compute_sell_price_for_product(
        int $product_id,
        DistributorProductPayload $payload
    ): ?float {
        $settings = self::get_pricing_settings_for_product($product_id);

        if ($settings['mode'] === ProductMeta::MARKUP_MODE_FIXED_PRICE) {
            return $settings['fixed_price'];
        }

        $pct = $settings['effective_percent'];
        if (! is_numeric($pct) || (float) $pct < 0) {
            return null;
        }

        $base = (is_numeric($payload->true_cost) && (float) $payload->true_cost > 0)
            ? (float) $payload->true_cost
            : (float) $payload->price;

        if ($base <= 0) {
            return null;
        }

        $sell = $base * (1.0 + (float) $pct);
        $sell = ceil($sell) - 0.01;

        return (float) $sell;
    }

    public static function get_pricing_settings_for_product(int $product_id): array
    {
        $mode_raw = get_post_meta($product_id, ProductMeta::FFLHUB_MARKUP_MODE_META, true);
        $mode     = ($mode_raw === '') ? ProductMeta::MARKUP_MODE_GLOBAL : (int) $mode_raw;

        $percent_meta = get_post_meta($product_id, ProductMeta::FFLHUB_MARKUP_PERCENT_META, true);
        $fixed_meta   = get_post_meta($product_id, ProductMeta::FFLHUB_FIXED_PRICE_META, true);

        $percent = null;
        if (is_numeric($percent_meta)) {
            $p = (float) $percent_meta;
            if ($p > 1.0) {
                $p = $p / 100.0;
            }
            if ($p >= 0) {
                $percent = $p;
            }
        }

        $fixed_price = null;
        if (is_numeric($fixed_meta)) {
            $fp = (float) $fixed_meta;
            if ($fp > 0) {
                $fixed_price = $fp;
            }
        }

        $effective_percent = null;
        if ($mode === ProductMeta::MARKUP_MODE_GLOBAL) {
            $g = (float) Options::get_global_markup();
            $effective_percent = ($g > 1.0) ? ($g / 100.0) : $g;
        } elseif ($mode === ProductMeta::MARKUP_MODE_FIXED_PCT) {
            $effective_percent = $percent;
        } elseif ($mode === ProductMeta::MARKUP_MODE_FIXED_PRICE) {
            $effective_percent = null;
        }

        return [
            'mode'              => $mode,
            'percent'           => $percent,
            'fixed_price'       => $fixed_price,
            'effective_percent' => $effective_percent,
        ];
    }

    public static function query_for_managed_products(int $limit)
    {
        $args = array(
            'post_type'      => 'product',
            'post_status'    => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => max(1, (int) $limit),
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'   => ProductMeta::FFLHUB_MANAGED_META,
                    'value' => 1,
                ),
            ),
            'meta_key'       => ProductMeta::FFLHUB_LAST_SYNC_META,
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        );

        return new WP_Query($args);
    }

    public static function apply_admin_pricing_to_woo_product(int $product_id): void
    {
        $product = wc_get_product($product_id);
        if (! $product) {
            return;
        }

        $settings = self::get_pricing_settings_for_product($product_id);

        if (($settings['mode'] ?? null) === ProductMeta::MARKUP_MODE_FIXED_PRICE) {
            $fixed = self::to_positive_float($settings['fixed_price'] ?? null);
            if ($fixed === null) {
                return;
            }

            self::set_regular_price_and_save($product, $fixed);
            return;
        }

        $pct = $settings['effective_percent'] ?? null;
        if (! is_numeric($pct) || (float) $pct < 0) {
            return;
        }
        $pct = (float) $pct;

        $base =
            self::to_positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_TRUE_COST_META, true))
            ?? self::to_positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, true));

        if ($base === null) {
            return;
        }

        $sell = $base * (1.0 + $pct);
        $sell = ceil($sell) - 0.01;

        if ($sell <= 0) {
            return;
        }

        self::set_regular_price_and_save($product, $sell);
    }

    private static function to_positive_float($value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $f = (float) $value;
        return $f > 0 ? $f : null;
    }

    private static function set_regular_price_and_save(\WC_Product $product, float $price): void
    {
        $product->set_regular_price(wc_format_decimal($price, 2));
        $product->set_sale_price('');
        $product->save();
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
