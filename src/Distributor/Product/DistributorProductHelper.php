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
    private const CSSI_DISTRIBUTOR_ID = 'cssi';
    private const BRAND_TAXONOMY_CANDIDATES = ['product_brand', 'pa_brand'];
    private const BRAND_TERM_ALIAS_MIGRATION_OPTION = 'fflhub_brand_term_alias_migration_v2';
    private const BRAND_TERM_ALIAS_MIGRATIONS = [
        'Holosun Technologies' => 'Holosun',
        'Holoson Technologies' => 'Holosun',
        'Burris Optics' => 'Burris',
    ];
    private const BRAND_ALIASES = [
        'smithandwesson' => 'Smith & Wesson',
        'smithwesson' => 'Smith & Wesson',
        'holosuntechnologies' => 'Holosun',
        'holosontechnologies' => 'Holosun',
        'burrisoptics' => 'Burris',
        'burris' => 'Burris',
        'sig' => 'SIG SAUER',
        'sigsauer' => 'SIG SAUER',
        'sigsaueroffduty' => 'SIG SAUER',
        'fnamerica' => 'FN',
        'eotech' => 'EOTECH',
        'promagindustries' => 'ProMag',
        'promag' => 'ProMag',
        'keltec' => 'Kel-Tec',
        'huxwrxsafetyco' => 'HUXWRX',
        'huxwrx' => 'HUXWRX',
        'yankeehillmachineco' => 'Yankee Hill Machine',
        'yankeehillmachinecompany' => 'Yankee Hill Machine',
        'yankeehillmachine' => 'Yankee Hill Machine',
        'americantacticalinc' => 'American Tactical',
        'americantactical' => 'American Tactical',
        'henry' => 'Henry Repeating Arms',
        'savage' => 'Savage Arms',
        'warnescopemounts' => 'Warne',
        'hecklerandkochhkusa' => 'Heckler & Koch',
        'hecklerkoch' => 'Heckler & Koch',
        'iwiisraelweaponindustries' => 'IWI',
        'iwiusinc' => 'IWI',
        'thompsoncenter' => 'Thompson/Center',
        'autoordnancethompson' => 'Auto-Ordnance',
        'autoordnance' => 'Auto-Ordnance',
        'kriss' => 'KRISS USA',
        'krissusainc' => 'KRISS USA',
        'taurususa' => 'Taurus',
        'walther' => 'Walther Arms',
        'springfield' => 'Springfield Armory',
        'hi-pointfirearms' => 'Hi-Point',
        'atncorp' => 'ATN',
        'banishsuppressors' => 'BANISH',
        'bntusa' => 'B&T',
        'militaryarmscorporation' => 'Military Armament Corp',
        'uspalm' => 'US PALM',
        'magview' => 'MagView',
        'shadowsystemsdefense' => 'Shadow Systems',
        'gforcearms' => 'GForce Arms',
        'truglo' => 'TRUGLO',
        'hiviz' => 'Hi-Viz',
        'europeanamericanarmory' => 'EAA Corp',
        'advancedarmamentcompany' => 'AAC (Advanced Armament)',
        'advancedarmamentcorp' => 'AAC (Advanced Armament)',
    ];

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

        // B) Compute initial sell price for product creation.
        $default_markup_mode = self::default_markup_mode_for_payload($selected_product);
        $sell_price = self::get_creation_sell_price_from_payload($selected_product, $default_markup_mode);

        if ($sell_price === null || $sell_price <= 0) {
            $error_message = __('Could not compute a valid retail price for this product.', 'ffl-hub');
            if ($default_markup_mode === ProductMeta::MARKUP_MODE_MAP_PRICE) {
                $error_message = __('MAP Price Quote Required mode requires a valid MAP or MSRP value for this product.', 'ffl-hub');
            }

            return new WP_Error(
                'fflhub_no_price',
                $error_message
            );
        }

        // Keep LAST_COMPUTED as "global markup recommended" even when sell price mode is MAP.
        $computed_price_for_meta = self::get_recommended_price_from_payload($selected_product);
        if ($computed_price_for_meta === null || $computed_price_for_meta <= 0) {
            $computed_price_for_meta = (float) $sell_price;
        }

        // C) Build WC product core fields (name/desc/sku/price/stock/status)
        $product = self::build_wc_product_from_payload(
            $upc,
            $selected_product,
            $sell_price
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
            (float) $computed_price_for_meta,
            $offers
        );

        // NEW: persist an offers snapshot for debugging / future auto-switch logic
        self::store_offers_snapshot_meta($product, $offers, $selected_dist_id);

        // Apply Woo product brand from distributor payload.
        self::sync_product_brand_from_payload($product_id, $selected_product);

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

        self::assign_unique_sku_for_new_product($product, $sku, $upc);

        $prices = self::resolve_regular_and_sale_prices($recommended_price, $selected_product->msrp ?? null);
        $product->set_regular_price($prices['regular']);
        $product->set_sale_price($prices['sale']);

        $product->set_manage_stock(true);
        $product->set_stock_quantity($qty);
        $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');

        $product->set_status('draft');
        $product->set_catalog_visibility('visible');

        return $product;
    }

    /**
     * Assign a unique SKU for newly created products.
     *
     * Strategy:
     * - Try distributor SKU first.
     * - If already in use or rejected, fall back to UPC.
     * - If both fail, leave Woo SKU empty (creation can still continue).
     */
    private static function assign_unique_sku_for_new_product(
        WC_Product_Simple $product,
        string $preferred_sku,
        string $upc
    ): void {
        $preferred_sku = trim($preferred_sku);
        $upc = trim($upc);

        $candidates = [];
        if ($preferred_sku !== '') {
            $candidates[] = $preferred_sku;
        }
        if ($upc !== '' && !in_array($upc, $candidates, true)) {
            $candidates[] = $upc;
        }

        foreach ($candidates as $candidate) {
            $existing_id = (int) wc_get_product_id_by_sku($candidate);
            if ($existing_id > 0) {
                self::log_debug(sprintf(
                    '[FFLHub][DistributorProductHelper] SKU candidate "%s" already in use by product #%d; trying next candidate.',
                    $candidate,
                    $existing_id
                ));
                continue;
            }

            try {
                $product->set_sku($candidate);
                return;
            } catch (\WC_Data_Exception $e) {
                self::log_debug(sprintf(
                    '[FFLHub][DistributorProductHelper] SKU candidate "%s" rejected by Woo: %s',
                    $candidate,
                    $e->getMessage()
                ));
            }
        }

        if (!empty($candidates)) {
            self::log_debug(sprintf(
                '[FFLHub][DistributorProductHelper] No unique SKU candidate could be assigned (candidates=%s). Product will be created without Woo SKU.',
                implode(', ', $candidates)
            ));
        }
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
     * - LAST_* snapshots: true_cost, dealer_price, MAP, MSRP, computed price, shipping cost
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
        float $computed_price_for_meta,
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

        $product->update_meta_data(ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META, $computed_price_for_meta);

        $product->update_meta_data(ProductMeta::FFLHUB_FFL_REQUIRED_META, $ffl_required ? 1 : 0);
        $product->update_meta_data(ProductMeta::FFLHUB_DROPSHIP_ENABLED_META, $dropship_enabled ? 1 : 0);
        $product->update_meta_data(ProductMeta::FFLHUB_SHIPPING_WEIGHT_META, $shipping_weight);
        $product->update_meta_data(ProductMeta::FFLHUB_SHIPPING_LENGTH_IN_META, $dims['length']);
        $product->update_meta_data(ProductMeta::FFLHUB_SHIPPING_WIDTH_IN_META, $dims['width']);
        $product->update_meta_data(ProductMeta::FFLHUB_SHIPPING_HEIGHT_IN_META, $dims['height']);
        $product->update_meta_data(ProductMeta::FFLHUB_SOT_REQUIRED_META, $sot_required ? 1 : 0);

        $default_markup_mode = self::default_markup_mode_for_payload($selected_product);
        $product->update_meta_data(ProductMeta::FFLHUB_MARKUP_MODE_META, $default_markup_mode);
        $product->update_meta_data(ProductMeta::FFLHUB_MARKUP_PERCENT_META, 0);
        $product->update_meta_data(ProductMeta::FFLHUB_FIXED_PRICE_META, '');
        $product->update_meta_data(ProductMeta::FFLHUB_STOCK_OOS_OVERRIDE_META, 0);

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
        float $computed_price_for_meta,
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
        $manual_shipping_override = self::is_manual_shipping_override_enabled($product);

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
        $set_meta_if_diff(ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META, $computed_price_for_meta, 4);
        $set_meta_if_diff(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, $ship_cost, 4);
        $set_meta_if_diff(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, $selected_dist_id, 0);
        $set_meta_if_diff(ProductMeta::FFLHUB_FFL_REQUIRED_META, $ffl_required, 0);
        $set_meta_if_diff(ProductMeta::FFLHUB_SOT_REQUIRED_META, $sot_required, 0);
        $set_meta_if_diff(ProductMeta::FFLHUB_DROPSHIP_ENABLED_META, $dropship_enabled, 0);

        if (!$manual_shipping_override) {
            $set_meta_if_diff(ProductMeta::FFLHUB_SHIPPING_WEIGHT_META, $shipping_weight, 4);
            $set_meta_if_diff(ProductMeta::FFLHUB_SHIPPING_LENGTH_IN_META, $dims['length'], 4);
            $set_meta_if_diff(ProductMeta::FFLHUB_SHIPPING_WIDTH_IN_META, $dims['width'], 4);
            $set_meta_if_diff(ProductMeta::FFLHUB_SHIPPING_HEIGHT_IN_META, $dims['height'], 4);
        } else {
            DebugLogUtil::log_ctx(
                'FFLHUB_CRON_DEBUG',
                'DistributorProductHelper',
                'Skipping shipping meta sync because manual shipping override is enabled.',
                [
                    'product_id' => $product->get_id(),
                ]
            );
        }

        return $changed;
    }

    /**
     * Sync Woo product brand term from payload brand/manufacturer.
     *
     * Returns true when terms were changed.
     */
    public static function sync_product_brand_from_payload(
        int $product_id,
        DistributorProductPayload $selected_product
    ): bool {
        if ($product_id <= 0) {
            return false;
        }

        $taxonomy = self::resolve_brand_taxonomy();
        if ($taxonomy === '') {
            return false;
        }

        self::maybe_run_brand_term_alias_migration($taxonomy);

        $brand = self::normalize_brand_name((string) ($selected_product->brand ?? ''));
        if ($brand === '') {
            return false;
        }

        $term = term_exists($brand, $taxonomy);
        if ($term === 0 || $term === null) {
            $created = wp_insert_term($brand, $taxonomy);
            if (is_wp_error($created)) {
                if ($created->get_error_code() === 'term_exists') {
                    $term_id = (int) $created->get_error_data('term_exists');
                } else {
                    self::log_debug('[FFLHub][DistributorProductHelper] Brand term create failed: ' . $created->get_error_message());
                    return false;
                }
            } else {
                $term_id = (int) ($created['term_id'] ?? 0);
            }
        } elseif (is_array($term)) {
            $term_id = (int) ($term['term_id'] ?? $term['id'] ?? 0);
        } else {
            $term_id = (int) $term;
        }

        if ($term_id <= 0) {
            return false;
        }

        $current_term_ids = wp_get_object_terms($product_id, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($current_term_ids)) {
            return false;
        }

        $current_term_ids = array_values(array_unique(array_map('intval', is_array($current_term_ids) ? $current_term_ids : [])));
        $target_term_ids = [$term_id];

        sort($current_term_ids);
        sort($target_term_ids);
        if ($current_term_ids === $target_term_ids) {
            return false;
        }

        $set = wp_set_object_terms($product_id, $target_term_ids, $taxonomy, false);
        if (is_wp_error($set)) {
            self::log_debug('[FFLHub][DistributorProductHelper] Brand term assign failed: ' . $set->get_error_message());
            return false;
        }

        return true;
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
     * - When non-CSSI image sources are available, CSSI images are skipped
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
        $skip_cssi_images = self::should_skip_cssi_images_when_alternatives_exist($offers);
        $primary_dist_id = self::resolve_primary_image_distributor_id(
            $offers,
            $selected_dist_id,
            $skip_cssi_images
        );

        foreach ($offers as $offer) {
            if (!($offer instanceof DistributorOffer)) {
                continue;
            }

            $payload = $offer->product ?? null;
            if (!($payload instanceof DistributorProductPayload)) {
                continue;
            }

            $offer_dist_id = (string) ($offer->distributor_id ?? '');
            if ($skip_cssi_images && self::is_cssi_distributor_id($offer_dist_id)) {
                self::log_debug(
                    sprintf(
                        '[FFLHub][DistributorProductHelper] Skipping CSSI image import for UPC %s because non-CSSI image sources are available.',
                        $upc
                    )
                );
                continue;
            }

            $is_primary = (
                $offer_dist_id !== ''
                && self::normalize_distributor_id($offer_dist_id) === self::normalize_distributor_id($primary_dist_id)
            );

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
     * Prefer non-CSSI sources whenever at least one non-CSSI offer has usable image URLs.
     *
     * @param array<string,DistributorOffer> $offers
     */
    private static function should_skip_cssi_images_when_alternatives_exist(array $offers): bool
    {
        foreach ($offers as $offer) {
            if (!($offer instanceof DistributorOffer)) {
                continue;
            }

            if (self::is_cssi_distributor_id((string) ($offer->distributor_id ?? ''))) {
                continue;
            }

            if (!($offer->product instanceof DistributorProductPayload)) {
                continue;
            }

            if (self::payload_has_usable_image_urls($offer->product)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve which distributor should be treated as the primary image source.
     *
     * Rules:
     * - Prefer selected distributor when it has usable images and is not skipped by policy.
     * - Otherwise fall back to the first eligible distributor with usable images.
     *
     * @param array<string,DistributorOffer> $offers
     */
    private static function resolve_primary_image_distributor_id(
        array $offers,
        string $selected_dist_id,
        bool $skip_cssi_images
    ): string {
        $selected_norm = self::normalize_distributor_id($selected_dist_id);
        if ($selected_norm !== '') {
            foreach ($offers as $offer) {
                if (!($offer instanceof DistributorOffer)) {
                    continue;
                }

                if (!($offer->product instanceof DistributorProductPayload)) {
                    continue;
                }

                $offer_dist_id = (string) ($offer->distributor_id ?? '');
                $offer_norm = self::normalize_distributor_id($offer_dist_id);
                if ($offer_norm === '' || $offer_norm !== $selected_norm) {
                    continue;
                }

                if ($skip_cssi_images && self::is_cssi_distributor_id($offer_dist_id)) {
                    break;
                }

                if (self::payload_has_usable_image_urls($offer->product)) {
                    return $offer_dist_id;
                }

                break;
            }
        }

        foreach ($offers as $offer) {
            if (!($offer instanceof DistributorOffer)) {
                continue;
            }

            if (!($offer->product instanceof DistributorProductPayload)) {
                continue;
            }

            $offer_dist_id = (string) ($offer->distributor_id ?? '');
            if ($offer_dist_id === '') {
                continue;
            }

            if ($skip_cssi_images && self::is_cssi_distributor_id($offer_dist_id)) {
                continue;
            }

            if (self::payload_has_usable_image_urls($offer->product)) {
                return $offer_dist_id;
            }
        }

        return '';
    }

    private static function payload_has_usable_image_urls(DistributorProductPayload $payload): bool
    {
        if (empty($payload->image_urls) || !is_array($payload->image_urls)) {
            return false;
        }

        foreach ($payload->image_urls as $url) {
            if (self::is_usable_image_url((string) $url)) {
                return true;
            }
        }

        return false;
    }

    private static function is_usable_image_url(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $url = esc_url_raw($url);
        if (!is_string($url) || $url === '') {
            return false;
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        return $scheme === 'http' || $scheme === 'https';
    }

    private static function is_cssi_distributor_id(string $distributor_id): bool
    {
        return self::normalize_distributor_id($distributor_id) === self::CSSI_DISTRIBUTOR_ID;
    }

    private static function normalize_distributor_id(string $distributor_id): string
    {
        return strtolower(trim($distributor_id));
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
                'brand'         => (string) ($p->brand ?? ''),
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
     * Resolve the value to store in LAST_COMPUTED_PRICE during sync.
     *
     * Priority:
     * 1) Automatic global-markup recommendation from payload
     * 2) Optional fallback sell price
     */
    public static function resolve_recommended_price_for_sync(
        DistributorProductPayload $selected_product,
        ?float $fallback_sell_price = null
    ): float {
        $recommended = self::get_recommended_price_from_payload($selected_product);
        if (is_numeric($recommended) && (float) $recommended > 0.0) {
            return (float) $recommended;
        }

        if (is_numeric($fallback_sell_price) && (float) $fallback_sell_price > 0.0) {
            return (float) $fallback_sell_price;
        }

        return 0.0;
    }

    /**
     * Resolve MAP quote real price from product-level MAP real-price settings.
     *
     * Returns null when the product is not in MAP quote-required mode, has no MAP/MSRP base,
     * or computes to a non-positive value.
     */
    public static function get_map_real_price_for_product(WC_Product $product): ?float
    {
        $mode_raw = $product->get_meta(ProductMeta::FFLHUB_MARKUP_MODE_META, true);
        $mode = ($mode_raw === '' && (string) $mode_raw !== '0')
            ? ProductMeta::MARKUP_MODE_GLOBAL
            : (int) $mode_raw;
        if ($mode !== ProductMeta::MARKUP_MODE_MAP_PRICE) {
            return null;
        }

        $real_mode_raw = $product->get_meta(ProductMeta::FFLHUB_MAP_REAL_PRICE_MODE_META, true);
        $real_mode = ($real_mode_raw === '' && (string) $real_mode_raw !== '0')
            ? ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED
            : (int) $real_mode_raw;
        if (!in_array($real_mode, [ProductMeta::MAP_REAL_PRICE_MODE_FIXED_OFFSET, ProductMeta::MAP_REAL_PRICE_MODE_PERCENTAGE, ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED, ProductMeta::MAP_REAL_PRICE_MODE_FIXED_PROFIT], true)) {
            $real_mode = ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED;
        }

        if ($real_mode === ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED) {
            $recommended = self::to_positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META, true));
            if ($recommended === null) {
                $recommended = self::to_positive_float($product->get_regular_price());
            }
            if ($recommended === null) {
                $recommended = self::to_positive_float($product->get_price());
            }
            return $recommended;
        }

        if ($real_mode === ProductMeta::MAP_REAL_PRICE_MODE_FIXED_OFFSET) {
            $cost_base = self::to_positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_TRUE_COST_META, true));
            if ($cost_base === null) {
                $cost_base = self::to_positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, true));
            }
            if ($cost_base === null) {
                return null;
            }

            $offset = self::to_non_negative_float($product->get_meta(ProductMeta::FFLHUB_MAP_REAL_PRICE_OFFSET_META, true)) ?? 0.0;
            $real_price = round($cost_base + $offset, 2);
            return ($real_price > 0.0) ? $real_price : null;
        }

        if ($real_mode === ProductMeta::MAP_REAL_PRICE_MODE_FIXED_PROFIT) {
            $cost_base = self::to_positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_TRUE_COST_META, true));
            if ($cost_base === null) {
                $cost_base = self::to_positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_DEALER_PRICE_META, true));
            }
            if ($cost_base === null) {
                return null;
            }

            $shipping_cost = self::to_non_negative_float($product->get_meta(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, true)) ?? 0.0;
            $profit_target = self::to_non_negative_float($product->get_meta(ProductMeta::FFLHUB_MAP_REAL_PRICE_FIXED_PROFIT_META, true)) ?? 0.0;

            $fee_percent = (float) Options::get_payment_processor_fee_percent();
            if (!is_finite($fee_percent) || $fee_percent < 0.0) {
                $fee_percent = 0.0;
            }
            $fee_fraction = min(0.99, $fee_percent / 100.0);
            $denominator = 1.0 - $fee_fraction;
            if ($denominator <= 0.0) {
                return null;
            }

            // Matches the operator script formula:
            // offset = (target_profit + shipping + (true_cost * fee_fraction)) / (1 - fee_fraction)
            $offset = ($profit_target + $shipping_cost + ($cost_base * $fee_fraction)) / $denominator;
            $real_price = round($cost_base + $offset, 2);

            return ($real_price > 0.0) ? $real_price : null;
        }

        $map_base = self::resolve_map_mode_sell_price(
            $product->get_meta(ProductMeta::FFLHUB_LAST_MAP_META, true),
            $product->get_meta(ProductMeta::FFLHUB_LAST_MSRP_META, true)
        );
        if ($map_base === null) {
            $map_base = self::to_positive_float($product->get_price());
        }
        if ($map_base === null) {
            return null;
        }

        $pct = self::to_non_negative_float($product->get_meta(ProductMeta::FFLHUB_MAP_REAL_PRICE_PERCENT_META, true)) ?? 0.0;
        $discount = $map_base * ($pct / 100.0);
        $real_price = round($map_base - $discount, 2);
        return ($real_price > 0.0) ? $real_price : null;
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
     * Determine default pricing mode for a payload at product creation.
     *
     * MAP policy behavior:
     * - "Email for Quote" defaults to MAP Price mode only when payload MAP exists.
     * - "No Email, No Add to Cart" always defaults to MAP Price mode.
     * - Otherwise defaults to regular global pricing mode.
     */
    public static function default_markup_mode_for_payload(DistributorProductPayload $payload): int
    {
        $brand = self::normalize_brand_name((string) ($payload->brand ?? ''));
        $map_policy = ($brand !== '') ? Options::get_map_policy_for_brand($brand) : '';

        if ($map_policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) {
            return ProductMeta::MARKUP_MODE_MAP_PRICE;
        }

        if ($map_policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE) {
            $map_value = self::to_positive_float($payload->map ?? null);
            if ($map_value !== null) {
                return ProductMeta::MARKUP_MODE_MAP_PRICE;
            }
        }

        return ProductMeta::MARKUP_MODE_GLOBAL;
    }

    /**
     * Resolve initial creation-time sell price for a payload.
     *
     * - MAP Price mode: sell price equals payload MAP (fallback to payload MSRP)
     * - Other modes: use global recommended price
     */
    private static function get_creation_sell_price_from_payload(
        DistributorProductPayload $selected_product,
        int $default_markup_mode
    ): ?float {
        if ($default_markup_mode === ProductMeta::MARKUP_MODE_MAP_PRICE) {
            return self::resolve_map_mode_sell_price(
                $selected_product->map ?? null,
                $selected_product->msrp ?? null
            );
        }

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
        // "No Email, No Add to Cart" policy enforces MAP/MSRP pricing
        // regardless of stored per-product markup mode.
        $brand = self::normalize_brand_name((string) ($payload->brand ?? ''));
        if ($brand !== '' && Options::get_map_policy_for_brand($brand) === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) {
            return self::resolve_map_mode_sell_price($payload->map ?? null, $payload->msrp ?? null);
        }

        $settings = self::get_pricing_settings_for_product($product_id);

        if (($settings['mode'] ?? null) === ProductMeta::MARKUP_MODE_FIXED_PRICE) {
            return $settings['fixed_price'] ?? null;
        }

        if (($settings['mode'] ?? null) === ProductMeta::MARKUP_MODE_MAP_PRICE) {
            return self::resolve_map_mode_sell_price($payload->map ?? null, $payload->msrp ?? null);
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
      * - effective_percent: the percent that will actually be applied (or null for fixed/map modes)
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
        } elseif ($mode === ProductMeta::MARKUP_MODE_MAP_PRICE) {
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
      * - MAP price mode: set to LAST_MAP meta (fallback to LAST_MSRP)
      * - Percent modes: base cost comes from LAST_TRUE_COST else LAST_DEALER_PRICE
      */
    public static function apply_admin_pricing_to_woo_product(int $product_id): void
    {
        $product = wc_get_product($product_id);
        if (!($product instanceof WC_Product)) {
            return;
        }

        $settings = self::get_pricing_settings_for_product($product_id);
        $msrp = self::to_positive_float($product->get_meta(ProductMeta::FFLHUB_LAST_MSRP_META, true));

        if (($settings['mode'] ?? null) === ProductMeta::MARKUP_MODE_FIXED_PRICE) {
            $fixed = self::to_positive_float($settings['fixed_price'] ?? null);
            if ($fixed === null) {
                return;
            }

            self::set_sell_price_and_save($product, $fixed, $msrp);
            return;
        }

        if (($settings['mode'] ?? null) === ProductMeta::MARKUP_MODE_MAP_PRICE) {
            $map_or_msrp = self::resolve_map_mode_sell_price(
                $product->get_meta(ProductMeta::FFLHUB_LAST_MAP_META, true),
                $msrp
            );
            if ($map_or_msrp === null) {
                return;
            }

            self::set_sell_price_and_save($product, $map_or_msrp, $msrp);
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

        self::set_sell_price_and_save($product, (float) $sell, $msrp);
    }

    /**
     * Convert a value to a non-negative float (>= 0), otherwise null.
     *
     * @param mixed $value
     */
    private static function to_non_negative_float($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $f = (float) $value;
        return $f >= 0 ? $f : null;
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
     * Resolve MAP mode sell price.
     *
     * MAP has priority. MSRP is used only when MAP is unavailable.
     *
     * @param mixed $map_raw
     * @param mixed $msrp_raw
     */
    private static function resolve_map_mode_sell_price($map_raw, $msrp_raw): ?float
    {
        return self::to_positive_float($map_raw) ?? self::to_positive_float($msrp_raw);
    }

    /**
     * Check whether manual shipping override is enabled on a product.
     */
    private static function is_manual_shipping_override_enabled(WC_Product $product): bool
    {
        $raw = $product->get_meta(ProductMeta::FFLHUB_MANUAL_SHIPPING_OVERRIDE_META, true);
        $normalized = strtolower(trim((string) $raw));
        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    /**
     * Resolve Woo regular/sale price pair from computed sell price and optional MSRP.
     *
     * Rules:
     * - MSRP > 0 => regular price uses MSRP, sell price becomes sale price when lower than MSRP.
     * - No valid MSRP => regular price uses sell price and sale price is cleared.
     *
     * @param mixed $msrp_raw
     * @return array{regular:string,sale:string}
     */
    public static function resolve_regular_and_sale_prices(float $sell_price, $msrp_raw): array
    {
        $sell = wc_format_decimal($sell_price, 2);
        $msrp = self::to_positive_float($msrp_raw);

        if ($msrp === null) {
            return ['regular' => $sell, 'sale' => ''];
        }

        $regular = wc_format_decimal($msrp, 2);
        $sale = '';

        if ((float) $sell < (float) $regular) {
            $sale = $sell;
        }

        return [
            'regular' => $regular,
            'sale'    => $sale,
        ];
    }

    /**
     * Update regular/sale price pair then persist.
     *
     * @param mixed $msrp_raw
     */
    private static function set_sell_price_and_save(WC_Product $product, float $sell_price, $msrp_raw): void
    {
        $prices = self::resolve_regular_and_sale_prices($sell_price, $msrp_raw);
        $product->set_regular_price($prices['regular']);
        $product->set_sale_price($prices['sale']);
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

    private static function maybe_run_brand_term_alias_migration(string $taxonomy): void
    {
        if ((string) get_option(self::BRAND_TERM_ALIAS_MIGRATION_OPTION, '') === '1') {
            return;
        }

        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return;
        }

        $target_term_id_by_key = [];
        foreach (self::BRAND_TERM_ALIAS_MIGRATIONS as $source_name => $target_name) {
            $target_name = self::normalize_brand_name((string) $target_name);
            if ($target_name === '') {
                continue;
            }

            $target_key = self::brand_alias_key($target_name);
            if ($target_key === '' || isset($target_term_id_by_key[$target_key])) {
                continue;
            }

            $term = term_exists($target_name, $taxonomy);
            if ($term === 0 || $term === null) {
                $created = wp_insert_term($target_name, $taxonomy);
                if (is_wp_error($created)) {
                    self::log_debug('[FFLHub][DistributorProductHelper] Brand alias migration target create failed: ' . $created->get_error_message());
                    return;
                }
                $target_term_id = (int) ($created['term_id'] ?? 0);
            } elseif (is_array($term)) {
                $target_term_id = (int) ($term['term_id'] ?? $term['id'] ?? 0);
            } else {
                $target_term_id = (int) $term;
            }

            if ($target_term_id <= 0) {
                self::log_debug('[FFLHub][DistributorProductHelper] Brand alias migration target resolve failed for: ' . $target_name);
                return;
            }

            $target_term_id_by_key[$target_key] = $target_term_id;
        }

        if (empty($target_term_id_by_key)) {
            update_option(self::BRAND_TERM_ALIAS_MIGRATION_OPTION, '1', false);
            return;
        }

        $source_target_key_map = [];
        foreach (self::BRAND_TERM_ALIAS_MIGRATIONS as $source_name => $target_name) {
            $source_key = self::brand_alias_key((string) $source_name);
            $target_key = self::brand_alias_key(self::normalize_brand_name((string) $target_name));
            if ($source_key !== '' && $target_key !== '') {
                $source_target_key_map[$source_key] = $target_key;
            }
        }

        if (empty($source_target_key_map)) {
            update_option(self::BRAND_TERM_ALIAS_MIGRATION_OPTION, '1', false);
            return;
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms)) {
            self::log_debug('[FFLHub][DistributorProductHelper] Brand alias migration get_terms failed: ' . $terms->get_error_message());
            return;
        }

        $source_term_ids_by_target = [];
        foreach ((array) $terms as $term_obj) {
            if (!is_object($term_obj) || !isset($term_obj->term_id, $term_obj->name)) {
                continue;
            }

            $source_term_id = (int) $term_obj->term_id;
            if ($source_term_id <= 0) {
                continue;
            }

            $source_name = trim((string) $term_obj->name);
            $source_key = self::brand_alias_key($source_name);
            if ($source_key === '' || !isset($source_target_key_map[$source_key])) {
                continue;
            }

            $target_key = (string) $source_target_key_map[$source_key];
            $target_term_id = (int) ($target_term_id_by_key[$target_key] ?? 0);
            if ($target_term_id <= 0 || $target_term_id === $source_term_id) {
                continue;
            }

            if (!isset($source_term_ids_by_target[$target_term_id])) {
                $source_term_ids_by_target[$target_term_id] = [];
            }
            $source_term_ids_by_target[$target_term_id][$source_term_id] = $source_term_id;
        }

        if (empty($source_term_ids_by_target)) {
            update_option(self::BRAND_TERM_ALIAS_MIGRATION_OPTION, '1', false);
            return;
        }

        $had_errors = false;
        $updated_products = 0;
        $deleted_terms = 0;

        foreach ($source_term_ids_by_target as $target_term_id => $source_term_ids_map) {
            $source_term_ids = array_values(array_map('intval', (array) $source_term_ids_map));
            if (empty($source_term_ids)) {
                continue;
            }

            $object_id_map = [];
            foreach ($source_term_ids as $source_term_id) {
                $object_ids = get_objects_in_term($source_term_id, $taxonomy);
                if (is_wp_error($object_ids)) {
                    $had_errors = true;
                    self::log_debug('[FFLHub][DistributorProductHelper] Brand alias migration get_objects_in_term failed: ' . $object_ids->get_error_message());
                    continue;
                }

                foreach ((array) $object_ids as $object_id) {
                    $object_id = (int) $object_id;
                    if ($object_id > 0) {
                        $object_id_map[$object_id] = $object_id;
                    }
                }
            }

            foreach ($object_id_map as $object_id) {
                $current_term_ids = wp_get_object_terms($object_id, $taxonomy, ['fields' => 'ids']);
                if (is_wp_error($current_term_ids)) {
                    $had_errors = true;
                    self::log_debug('[FFLHub][DistributorProductHelper] Brand alias migration wp_get_object_terms failed: ' . $current_term_ids->get_error_message());
                    continue;
                }

                $current_term_ids = array_values(array_unique(array_map('intval', is_array($current_term_ids) ? $current_term_ids : [])));
                $next_term_ids = array_values(array_diff($current_term_ids, $source_term_ids));
                if (!in_array((int) $target_term_id, $next_term_ids, true)) {
                    $next_term_ids[] = (int) $target_term_id;
                }

                $current_sorted = $current_term_ids;
                $next_sorted = array_values(array_unique(array_map('intval', $next_term_ids)));
                sort($current_sorted);
                sort($next_sorted);
                if ($current_sorted === $next_sorted) {
                    continue;
                }

                $set = wp_set_object_terms($object_id, $next_sorted, $taxonomy, false);
                if (is_wp_error($set)) {
                    $had_errors = true;
                    self::log_debug('[FFLHub][DistributorProductHelper] Brand alias migration wp_set_object_terms failed: ' . $set->get_error_message());
                    continue;
                }

                $updated_products++;
            }

            foreach ($source_term_ids as $source_term_id) {
                $deleted = wp_delete_term($source_term_id, $taxonomy);
                if ($deleted === false || is_wp_error($deleted)) {
                    $had_errors = true;
                    $err = is_wp_error($deleted) ? $deleted->get_error_message() : 'unknown';
                    self::log_debug('[FFLHub][DistributorProductHelper] Brand alias migration wp_delete_term failed: ' . $err);
                    continue;
                }

                $deleted_terms++;
            }
        }

        if ($had_errors) {
            return;
        }

        update_option(self::BRAND_TERM_ALIAS_MIGRATION_OPTION, '1', false);
        self::log_debug(
            sprintf(
                '[FFLHub][DistributorProductHelper] Brand alias migration complete: updated_products=%d, deleted_terms=%d',
                $updated_products,
                $deleted_terms
            )
        );
    }

    private static function resolve_brand_taxonomy(): string
    {
        foreach (self::BRAND_TAXONOMY_CANDIDATES as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                return $taxonomy;
            }
        }

        return '';
    }

    private static function normalize_brand_name(string $brand): string
    {
        $brand = html_entity_decode($brand, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $brand = wp_strip_all_tags($brand);
        $brand = trim((string) preg_replace('/\s+/', ' ', $brand));
        $brand = trim($brand, " \t\n\r\0\x0B-_,.;:/\\|");
        if ($brand === '') {
            return '';
        }

        $alias_key = self::brand_alias_key($brand);
        if ($alias_key !== '' && isset(self::BRAND_ALIASES[$alias_key])) {
            return self::BRAND_ALIASES[$alias_key];
        }

        return $brand;
    }

    private static function brand_alias_key(string $brand): string
    {
        $s = strtolower(trim($brand));
        $s = (string) preg_replace('/[^a-z0-9]+/', '', $s);
        return $s;
    }
}
