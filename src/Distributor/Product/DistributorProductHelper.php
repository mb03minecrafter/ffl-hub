<?php

namespace FFLHub\Distributor\Product;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\UpcLookupResult;
use FFLHub\Product\CategoryInstaller;
use FFLHub\Product\ProductMeta;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;
use WC_Product;
use WC_Product_Simple;
use WP_Error;
use WP_Query;

/**
 * DistributorProductHelper
 *
 * Utility methods for creating and updating WooCommerce products from
 * distributor product payloads.
 *
 * Responsibilities:
 * - Create a draft WooCommerce product for a UPC from a selected distributor payload
 * - Persist "FFLHub snapshot" meta (costs/MAP/MSRP/selected distributor/etc.)
 * - Import images from all distributor offers that match the UPC
 * - Provide pricing helpers (global markup, fixed price, fixed percent)
 * - Provide a distributor lookup wrapper that returns UpcLookupResult|WP_Error
 *
 * Notes / assumptions:
 * - UPC is stored in ProductMeta::FFLHUB_UPC_META and also set as Woo global_unique_id when non-empty.
 * - Products are created as "draft" with stock managed.
 * - Pricing uses ceil(base_price) - 0.01 behavior to land on .99-style pricing.
 * - Some methods return WP_Error for admin/UI consumption instead of throwing.
 */
class DistributorProductHelper
{
    /**
     * Snapshot meta key for persisting the offers map at creation time.
     *
     * Stored as JSON so it can be inspected later for debugging, analytics, and
     * potential auto-switch logic.
     */
    private const OFFERS_SNAPSHOT_META_KEY = 'fflhub_offers_snapshot';

    /**
     * Create a WooCommerce product from a selected distributor payload,
     * while also importing images from all distributors that carry the UPC.
     *
     * Returns:
     * - array{message:string,type:string} on success / existing product
     * - WP_Error on failure
     *
     * @param string $upc
     * @param DistributorProductPayload $selected_product
     * @param string $selected_dist_id
     * @param array<string,DistributorOffer> $offers Map of dist_id => DistributorOffer
     * @return array<string,mixed>|WP_Error
     */
    public static function create_woo_product_from_payload(
        string $upc,
        DistributorProductPayload $selected_product,
        string $selected_dist_id,
        array $offers
    ) {
        $upc = trim($upc);
        $selected_dist_id = trim($selected_dist_id);

        // A) Guard: prevent duplicates (UPC already exists)
        $existing_id = self::find_existing_product_id_by_upc($upc);
        if ($existing_id !== null) {
            return self::build_existing_product_response($existing_id);
        }

        // B) Compute retail price (based on selected payload)
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
            $recommended_price,
            $offers
        );

        // NEW: persist an offers snapshot for debugging / future auto-switch logic
        self::store_offers_snapshot_meta($product, $offers, $selected_dist_id);

        // Validate offers includes selected distributor (log + continue)
        if ($selected_dist_id !== '' && !isset($offers[$selected_dist_id])) {
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
     * Find existing product by UPC meta.
     *
     * @return int|null Product ID if found, otherwise null
     */
    private static function find_existing_product_id_by_upc(string $upc): ?int
    {
        $upc = trim($upc);
        if ($upc === '') {
            return null;
        }

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

        $id = (int) $existing[0];
        return ($id > 0) ? $id : null;
    }

    /**
     * Standard response payload when a matching product already exists.
     *
     * @return array{message:string,type:string}
     */
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
     * Build the WC product core fields from the selected payload.
     *
     * @return WC_Product_Simple
     */
    private static function build_wc_product_from_payload(
        string $upc,
        DistributorProductPayload $selected_product,
        float $recommended_price
    ): WC_Product_Simple {
        $product = new WC_Product_Simple();

        $name        = (string) ($selected_product->name ?? '');
        $sku         = (string) ($selected_product->sku ?? '');
        $description = (string) ($selected_product->description ?? '');
        $qty         = (int) ($selected_product->quantity ?? 0);

        $name = trim($name);
        $sku  = trim($sku);

        $product->set_name($name !== '' ? $name : ($sku !== '' ? $sku : $upc));
        $product->set_description($description);

        $sku_to_use = ($sku !== '') ? $sku : $upc;
        if ($sku_to_use !== '') {
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

    /**
     * Apply categories based on payload->recommended_category.
     *
     * @param WC_Product_Simple $product
     * @param DistributorProductPayload $selected_product
     */
    private static function apply_categories_from_payload(
        WC_Product_Simple $product,
        DistributorProductPayload $selected_product
    ): void {
        $recommended_category = $selected_product->recommended_category ?? null;

        if (!is_array($recommended_category) || empty($recommended_category)) {
            return;
        }

        $term_ids = CategoryInstaller::get_term_ids_for_path($recommended_category);

        if (!is_array($term_ids) || empty($term_ids)) {
            return;
        }

        $term_ids = array_values(array_unique(array_map('intval', $term_ids)));

        if (!empty($term_ids)) {
            $product->set_category_ids($term_ids);
        }
    }

    /**
     * Persist product and return its ID.
     */
    private static function save_and_get_id(WC_Product_Simple $product): int
    {
        $product->save();
        $id = (int) $product->get_id();
        return ($id > 0) ? $id : 0;
    }

    /**
     * Write all plugin meta (based on the selected payload).
     *
     * Stores:
     * - UPC, managed flag, selected distributor id
     * - LAST_* snapshots: true_cost, dealer_price, MAP, MSRP, computed sell price, shipping cost
     * - FFL required flag
     * - Pricing mode defaults
     * - LAST_SYNC timestamp
     *
     * @param array<string,DistributorOffer> $offers
     */
    public static function apply_fflhub_meta_from_payload(
        WC_Product_Simple $product,
        string $upc,
        string $selected_dist_id,
        DistributorProductPayload $selected_product,
        float $recommended_price,
        array $offers = []
    ): void {
        $upc = trim($upc);
        $selected_dist_id = trim($selected_dist_id);

        $dealer_price = (float) ($selected_product->price ?? 0);
        $true_cost    = (float) ($selected_product->true_cost ?? 0);

        $map          = (float) ($selected_product->map ?? 0);
        $msrp         = (float) ($selected_product->msrp ?? 0);
        $ffl_required = (bool) ($selected_product->ffl_required ?? false);
        $sot_required = (bool) ($selected_product->sot_required ?? false);
        $dropship_enabled = (bool) ($selected_product->dropship_enabled ?? true);
        $shipping_weight = trim((string) ($selected_product->shipping_weight ?? ''));
        $dims = self::resolve_shipping_dimensions_for_meta($selected_product, $offers);

        $ship_cost = $selected_product->shipping_cost ?? null;

        // Keep global_unique_id (UPC/GTIN-ish) but only set if non-empty
        if ($upc !== '') {
            $product->set_global_unique_id($upc);
        }

        $product->update_meta_data(ProductMeta::FFLHUB_UPC_META, $upc);

        // Store booleans consistently as 1/0
        $product->update_meta_data(ProductMeta::FFLHUB_MANAGED_META, 1);

        $product->update_meta_data(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, $selected_dist_id);

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_TRUE_COST_META, $true_cost);
        $product->update_meta_data(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, $dealer_price);

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_MAP_META, $map);
        $product->update_meta_data(ProductMeta::FFLHUB_LAST_MSRP_META, $msrp);

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META, $recommended_price);

        $product->update_meta_data(ProductMeta::FFLHUB_FFL_REQUIRED_META, $ffl_required ? 1 : 0);
        $product->update_meta_data(ProductMeta::FFLHUB_DROPSHIP_ENABLED_META, $dropship_enabled ? 1 : 0);
        $product->update_meta_data(ProductMeta::FFLHUB_SHIPPING_WEIGHT_META, $shipping_weight);
        $product->update_meta_data(ProductMeta::FFLHUB_SHIPPING_LENGTH_IN_META, $dims['length']);
        $product->update_meta_data(ProductMeta::FFLHUB_SHIPPING_WIDTH_IN_META, $dims['width']);
        $product->update_meta_data(ProductMeta::FFLHUB_SHIPPING_HEIGHT_IN_META, $dims['height']);
        $product->update_meta_data(ProductMeta::FFLHUB_SOT_REQUIRED_META, $sot_required ? 1 : 0);

        $product->update_meta_data(ProductMeta::FFLHUB_MARKUP_MODE_META, ProductMeta::MARKUP_MODE_GLOBAL);
        $product->update_meta_data(ProductMeta::FFLHUB_MARKUP_PERCENT_META, 0);
        $product->update_meta_data(ProductMeta::FFLHUB_FIXED_PRICE_META, '');

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, $ship_cost);

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_SYNC_META, current_time('mysql'));
    }

    /**
     * Sync-time update of LAST_* snapshot meta.
     *
     * Returns true if ANY snapshot/meta value changed (excluding LAST_SYNC).
     *
     * Resilience:
     * - Normalizes values before compare to avoid float formatting noise.
     * - Avoids unnecessary writes when values are effectively identical.
     */
    public static function update_fflhub_meta_from_payload_for_sync(
        WC_Product_Simple $product,
        string $selected_dist_id,
        DistributorProductPayload $selected_product,
        float $recommended_price,
        array $offers = []
    ): bool {
        $changed = false;

        $dealer_price = (float) ($selected_product->price ?? 0);
        $true_cost    = (float) ($selected_product->true_cost ?? 0);

        $map       = (float) ($selected_product->map ?? 0);
        $msrp      = (float) ($selected_product->msrp ?? 0);
        $ship_cost = $selected_product->shipping_cost ?? null;
        $dropship_enabled = ($selected_product->dropship_enabled ?? true) ? 1 : 0;
        $shipping_weight = trim((string) ($selected_product->shipping_weight ?? ''));
        $dims = self::resolve_shipping_dimensions_for_meta($selected_product, $offers);

        $ffl_required = ($selected_product->ffl_required ?? false) ? 1 : 0;
        $sot_required = ($selected_product->sot_required ?? false) ? 1 : 0;

        /**
         * Only update meta if different (string-compare to avoid float noise).
         *
         * @param string $key
         * @param mixed  $new_val
         * @param int    $precision
         */
        $set_meta_if_diff = function (string $key, $new_val, int $precision = 4) use ($product, &$changed): void {
            $normalize = function ($v) use ($precision): string {
                if ($v === null) {
                    return '';
                }

                if (is_bool($v)) {
                    return $v ? '1' : '0';
                }

                if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
                    return (string) wc_format_decimal((float) $v, $precision);
                }

                return trim((string) $v);
            };

            $new_norm = $normalize($new_val);
            $cur_norm = $normalize($product->get_meta($key, true));

            if ($cur_norm !== $new_norm) {
                DebugLogUtil::log_ctx('FFLHUB_CRON_DEBUG', 'TEST', 'Meta changed', [
                    'product_id' => $product->get_id(),
                    'key'        => $key,
                    'cur'        => $cur_norm,
                    'new'        => $new_norm,
                ]);
                $product->update_meta_data($key, $new_norm);
                $changed = true;
            }
        };

        $set_meta_if_diff(ProductMeta::FFLHUB_LAST_TRUE_COST_META, $true_cost, 4);
        $set_meta_if_diff(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, $dealer_price, 4);
        $set_meta_if_diff(ProductMeta::FFLHUB_LAST_MAP_META, $map, 4);
        $set_meta_if_diff(ProductMeta::FFLHUB_LAST_MSRP_META, $msrp, 4);
        $set_meta_if_diff(ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META, $recommended_price, 4);
        $set_meta_if_diff(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, $ship_cost, 4);
        $set_meta_if_diff(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, $selected_dist_id, 0);
        $set_meta_if_diff(ProductMeta::FFLHUB_FFL_REQUIRED_META, $ffl_required, 0);
        $set_meta_if_diff(ProductMeta::FFLHUB_SOT_REQUIRED_META, $sot_required, 0);
        $set_meta_if_diff(ProductMeta::FFLHUB_DROPSHIP_ENABLED_META, $dropship_enabled, 0);
        $set_meta_if_diff(ProductMeta::FFLHUB_SHIPPING_WEIGHT_META, $shipping_weight, 4);
        $set_meta_if_diff(ProductMeta::FFLHUB_SHIPPING_LENGTH_IN_META, $dims['length'], 4);
        $set_meta_if_diff(ProductMeta::FFLHUB_SHIPPING_WIDTH_IN_META, $dims['width'], 4);
        $set_meta_if_diff(ProductMeta::FFLHUB_SHIPPING_HEIGHT_IN_META, $dims['height'], 4);

        return $changed;
    }

    /**
     * Resolve shipping dimensions for meta persistence.
     *
     * Strategy:
     * - Prefer selected payload dimensions when complete (L/W/H present).
     * - If incomplete, scan other distributor offers for the first complete set.
     * - If no complete fallback exists, keep selected partial values.
     *
     * @param array<string,DistributorOffer> $offers
     * @return array{length:string,width:string,height:string}
     */
    private static function resolve_shipping_dimensions_for_meta(
        DistributorProductPayload $selected_product,
        array $offers = []
    ): array {
        $selected_len = self::normalize_dimension_value($selected_product->shipping_length_in ?? null);
        $selected_wid = self::normalize_dimension_value($selected_product->shipping_width_in ?? null);
        $selected_hei = self::normalize_dimension_value($selected_product->shipping_height_in ?? null);

        if (self::has_complete_dimensions($selected_len, $selected_wid, $selected_hei)) {
            return [
                'length' => $selected_len,
                'width'  => $selected_wid,
                'height' => $selected_hei,
            ];
        }

        foreach ($offers as $offer) {
            if (!($offer instanceof DistributorOffer)) {
                continue;
            }

            $payload = $offer->product ?? null;
            if (!($payload instanceof DistributorProductPayload)) {
                continue;
            }

            $len = self::normalize_dimension_value($payload->shipping_length_in ?? null);
            $wid = self::normalize_dimension_value($payload->shipping_width_in ?? null);
            $hei = self::normalize_dimension_value($payload->shipping_height_in ?? null);

            if (self::has_complete_dimensions($len, $wid, $hei)) {
                return [
                    'length' => $len,
                    'width'  => $wid,
                    'height' => $hei,
                ];
            }
        }

        return [
            'length' => $selected_len,
            'width'  => $selected_wid,
            'height' => $selected_hei,
        ];
    }

    private static function normalize_dimension_value($value): string
    {
        $v = trim((string) ($value ?? ''));
        return ($v === '' || strtolower($v) === 'null') ? '' : $v;
    }

    private static function has_complete_dimensions(string $length, string $width, string $height): bool
    {
        return $length !== '' && $width !== '' && $height !== '';
    }

    /**
     * Import images from all distributors (selected distributor marked primary).
     *
     * Resilience:
     * - Does not depend on array keys being correct; uses $offer->distributor_id
     * - Skips offers with missing product payloads
     * - Skips entirely if DistributorProductImages class is unavailable
     *
     * @param int $product_id
     * @param string $upc
     * @param array<string,DistributorOffer> $offers
     * @param string $selected_dist_id
     */
    private static function import_images_from_offers(
        int $product_id,
        string $upc,
        array $offers,
        string $selected_dist_id
    ): void {
        if (!class_exists(DistributorProductImages::class)) {
            return;
        }

        $selected_dist_id = trim($selected_dist_id);

        foreach ($offers as $offer) {
            if (!($offer instanceof DistributorOffer)) {
                continue;
            }

            $payload = $offer->product ?? null;
            if (!($payload instanceof DistributorProductPayload)) {
                continue;
            }

            $offer_dist_id = (string) ($offer->distributor_id ?? '');
            $is_primary = ($offer_dist_id !== '' && $offer_dist_id === $selected_dist_id);

            DistributorProductImages::import_images_for_distributor(
                $product_id,
                $upc,
                $payload,
                $offer_dist_id,
                $is_primary
            );
        }
    }

    /**
     * Store a compact offers snapshot to product meta (JSON).
     *
     * This is intended to be small and stable, capturing the "decision context"
     * at the time of product creation:
     * - selected distributor id
     * - key pricing fields per offer (true_cost, MAP/MSRP, qty, etc.)
     *
     * @param WC_Product_Simple $product
     * @param array<string,DistributorOffer> $offers
     * @param string $selected_dist_id
     */
    private static function store_offers_snapshot_meta(
        WC_Product_Simple $product,
        array $offers,
        string $selected_dist_id
    ): void {
        $snapshot = [
            'captured_at'      => current_time('mysql'),
            'selected_dist_id' => (string) $selected_dist_id,
            'offers'           => [],
        ];

        foreach ($offers as $offer) {
            if (!($offer instanceof DistributorOffer)) {
                continue;
            }
            if (!($offer->product instanceof DistributorProductPayload)) {
                continue;
            }

            $p = $offer->product;

            $dist_id = (string) ($offer->distributor_id ?? '');
            if ($dist_id === '') {
                continue;
            }

            $snapshot['offers'][$dist_id] = [
                'label'         => (string) ($offer->label ?? ''),
                'true_cost'     => (float) ($p->true_cost ?? 0),
                'dealer_price'  => (float) ($p->price ?? 0),
                'map'           => (float) ($p->map ?? 0),
                'msrp'          => (float) ($p->msrp ?? 0),
                'shipping_cost' => (float) ($p->shipping_cost ?? 0),
                'shipping_weight' => (string) ($p->shipping_weight ?? ''),
                'shipping_length_in' => (string) ($p->shipping_length_in ?? ''),
                'shipping_width_in'  => (string) ($p->shipping_width_in ?? ''),
                'shipping_height_in' => (string) ($p->shipping_height_in ?? ''),
                'qty'           => (int) ($p->quantity ?? 0),
                'ffl_required'  => ($p->ffl_required ?? false) ? 1 : 0,
                'sot_required'  => ($p->sot_required ?? false) ? 1 : 0,
                'sku'           => (string) ($p->sku ?? ''),
            ];
        }

        $json = wp_json_encode($snapshot);
        if (!is_string($json) || $json === '') {
            $json = '{}';
        }

        $product->update_meta_data(self::OFFERS_SNAPSHOT_META_KEY, $json);
    }

    /**
     * Get a UPC lookup result from the DistributorHandler.
     *
     * Returns:
     * - UpcLookupResult on success
     * - WP_Error on failure (handler missing, exception, bad return type)
     *
     * @param DistributorHandler $handler
     * @param string $upc
     * @param bool $include_images
     * @return UpcLookupResult|WP_Error
     */
    public static function get_upc_lookup_result_from_distributors(
        DistributorHandler $handler,
        string $upc,
        bool $include_images = true
    ) {
        $upc = trim($upc);
        if ($upc === '') {
            return new WP_Error(
                'fflhub_lookup_bad_upc',
                __('UPC is required for distributor lookup.', 'ffl-hub')
            );
        }

        if (!$handler || !method_exists($handler, 'get_payloads_for_upc')) {
            return new WP_Error(
                'fflhub_lookup_handler_missing',
                __('Distributor handler is not available for lookups.', 'ffl-hub')
            );
        }

        try {
            $lookup = $handler->get_payloads_for_upc($upc, $include_images);
        } catch (\Throwable $e) {
            self::log_debug('[FFLHub][DistributorProductHelper] Lookup exception: ' . $e->getMessage());

            return new WP_Error(
                'fflhub_lookup_exception',
                __('An error occurred while fetching distributor data.', 'ffl-hub')
            );
        }

        if (!($lookup instanceof UpcLookupResult)) {
            return new WP_Error(
                'fflhub_lookup_bad_return',
                __('Distributor lookup did not return a valid result object.', 'ffl-hub')
            );
        }

        return $lookup;
    }

    /**
     * Standard response payload for successful product creation.
     *
     * @return array{message:string,type:string}
     */
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
     * Compute a recommended retail price using global markup.
     *
     * Behavior:
     * - Prefer true_cost when present; otherwise fall back to dealer price
     * - Apply global markup percent
     * - Round to ".99" style by doing ceil(base) - 0.01
     *
     * @return float|null
     */
    public static function get_recommended_price_from_payload(DistributorProductPayload $selected_product): ?float
    {
        $dealer_price   = (float) ($selected_product->price ?? 0);
        $true_cost      = (float) ($selected_product->true_cost ?? 0);
        $markup_percent = (float) Options::get_global_markup() / 100;

        $base_price = ($true_cost > 0)
            ? $true_cost * (1 + $markup_percent)
            : $dealer_price;

        $base_price = (float) $base_price;

        if ($base_price <= 0) {
            return null;
        }

        $recommended_price = ceil($base_price) - 0.01;

        return $recommended_price > 0 ? (float) $recommended_price : null;
    }

    /**
     * Back-compat misspelled method (keep existing callers working).
     *
     * Remove later once you’ve replaced all call sites.
     */
    public static function get_reccomended_price_from_payload(DistributorProductPayload $selected_product): ?float
    {
        return self::get_recommended_price_from_payload($selected_product);
    }

    /**
     * Compute sell price for a given product using its stored pricing settings.
     *
     * Modes:
     * - Fixed price: use stored fixed price
     * - Global percent / fixed percent: apply percent to base cost
     *
     * Base cost preference:
     * - Prefer payload->true_cost when > 0
     * - Otherwise use payload->price
     */
    public static function compute_sell_price_for_product(int $product_id, DistributorProductPayload $payload): ?float
    {
        $settings = self::get_pricing_settings_for_product($product_id);

        if (($settings['mode'] ?? null) === ProductMeta::MARKUP_MODE_FIXED_PRICE) {
            return $settings['fixed_price'] ?? null;
        }

        $pct = $settings['effective_percent'] ?? null;
        if (!is_numeric($pct) || (float) $pct < 0) {
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

    /**
     * Resolve pricing settings for a managed product.
     *
     * Returns:
     * - mode: one of ProductMeta::MARKUP_MODE_*
     * - percent: the product-level percent (normalized to decimal, e.g. 0.15)
     * - fixed_price: the fixed price if present
     * - effective_percent: the percent that will actually be applied (or null for fixed price mode)
     *
     * Normalization rules:
     * - Percent meta > 1.0 is treated as "15" meaning 15% and converted to 0.15.
     */
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

    /**
     * Query for managed products, ordered by last sync ASC (oldest first).
     *
     * @return WP_Query
     */
    public static function query_for_managed_products(int $limit)
    {
        $args = [
            'post_type'      => 'product',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => max(1, (int) $limit),
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => ProductMeta::FFLHUB_MANAGED_META,
                    'value' => 1,
                ],
            ],
            'meta_key'       => ProductMeta::FFLHUB_LAST_SYNC_META,
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        ];

        return new WP_Query($args);
    }

    /**
     * Apply the stored admin pricing settings to the WooCommerce product price.
     *
     * - Fixed price mode: set that value directly
     * - Percent modes: base cost comes from LAST_TRUE_COST else LAST_DEALER_PRICE
     */
    public static function apply_admin_pricing_to_woo_product(int $product_id): void
    {
        $product = wc_get_product($product_id);
        if (!($product instanceof WC_Product)) {
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
        if (!is_numeric($pct) || (float) $pct < 0) {
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

        self::set_regular_price_and_save($product, (float) $sell);
    }

    /**
     * Convert a value to a positive float (strictly > 0), otherwise null.
     *
     * @param mixed $value
     */
    private static function to_positive_float($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $f = (float) $value;
        return $f > 0 ? $f : null;
    }

    /**
     * Update regular price (and clear sale price) then persist.
     */
    private static function set_regular_price_and_save(WC_Product $product, float $price): void
    {
        $product->set_regular_price(wc_format_decimal($price, 2));
        $product->set_sale_price('');
        $product->save();
    }

    /**
     * Debug logger for admin-side flows.
     *
     * Guarded to prevent spamming logs unless FFLHUB_ADMIN_DEBUG === true.
     */
    private static function log_debug(string $message): void
    {
        DebugLogUtil::log('FFLHUB_ADMIN_DEBUG', '[FFLHub][DistributorProductHelper]', $message);
    }
}
