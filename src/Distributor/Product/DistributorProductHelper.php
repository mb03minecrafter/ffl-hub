<?php

namespace FFLHub\Distributor\Product;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\UpcLookupResult;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Distributor\Services\OfferSync\DistributorLookupOfferIngestService;
use FFLHub\Distributor\Services\OfferSync\ProductBestOfferSelectionService;
use FFLHub\Distributor\Services\OfferSync\ProductStateBestOfferApplyService;
use FFLHub\Distributor\Services\OfferSync\ProductStateWooApplyService;
use FFLHub\Product\CategoryInstaller;
use FFLHub\Product\ProductMeta;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;
use WC_Product;
use WC_Product_Simple;
use WP_Error;

/**
 * DistributorProductHelper
 *
 * Utility methods for creating and updating WooCommerce products from
 * distributor product payloads.
 *
 * Responsibilities:
 * - Create a draft WooCommerce product for a UPC from a selected distributor payload
 * - Seed product_state and normalized distributor_offers for new products
 * - Import images from all distributor offers that match the UPC
 * - Provide pricing helpers (global markup, fixed price, fixed percent)
 * - Provide a distributor lookup wrapper that returns UpcLookupResult|WP_Error
 *
 * Notes / assumptions:
 * - UPC is set as Woo global_unique_id when non-empty.
 * - Products are created as "draft" with stock managed.
 * - Pricing uses ceil(base_price) - 0.01 behavior to land on .99-style pricing.
 * - Some methods return WP_Error for admin/UI consumption instead of throwing.
 */
class DistributorProductHelper
{
    private const CSSI_DISTRIBUTOR_ID = 'cssi';
    private const LIPSEYS_DISTRIBUTOR_ID = 'lipseys';
    private const BRAND_TAXONOMY_CANDIDATES = ['product_brand', 'pa_brand'];
    private const BRAND_TERM_ALIAS_MIGRATION_OPTION = 'fflhub_brand_term_alias_migration_v4';
    private const BRAND_TERM_ALIAS_MIGRATIONS = [
        'Holosun Technologies' => 'Holosun',
        'Holoson Technologies' => 'Holosun',
        'Burris Optics' => 'Burris',
        'MAGPUL INDUSTRIES' => 'Magpul',
        'MAGPUL INDUSTRIES CORP' => 'Magpul',
        'Magpul Industries' => 'Magpul',
        'Magpul Accessories' => 'Magpul',
        'OLIGHTSTORE USA INC' => 'Osight',
    ];
    private const OSIGHT_SOURCE_BRAND_KEYS = [
        'olightstoreusainc' => true,
        'olightstoreusa' => true,
        'olightstore' => true,
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
        'magpulindustriescorp' => 'Magpul',
        'magpulindustries' => 'Magpul',
        'magpulaccessories' => 'Magpul',
        'magpul' => 'Magpul',
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

        // B) Build a draft Woo shell. Price, stock, and shipping are applied
        // after the normalized offer/state pipeline selects the best offer.
        $product = self::build_wc_product_from_payload($upc, $selected_product);

        // C) Apply categories (prefer Lipsey's category path when available).
        self::apply_categories_from_payload($product, $selected_product, $offers);

        // D) Save product and get ID.
        $product_id = self::save_and_get_id($product);
        if (! $product_id) {
            return new WP_Error(
                'fflhub_insert_failed',
                __('Could not create WooCommerce product via CRUD API.', 'ffl-hub')
            );
        }

        // E) Seed product_state and normalized offers. This replaces the old
        // creation-time _fflhub_* product meta snapshot.
        $state_result = ProductStateStore::create_starter_row_for_product(
            $product_id,
            $upc,
            [
                'pricing_mode' => 'global_percent',
                'pricing_percent' => Options::get_global_markup(),
                'map_visibility_policy' => self::default_map_policy_for_payload($selected_product),
            ]
        );
        if (empty($state_result['ok'])) {
            return new WP_Error(
                'fflhub_product_state_seed_failed',
                (string) ($state_result['message'] ?? __('Could not create product_state row.', 'ffl-hub'))
            );
        }

        $offer_result = DistributorLookupOfferIngestService::upsert_lookup_offers($upc, $offers);
        if (empty($offer_result['ok']) || (int) ($offer_result['offers_upserted'] ?? 0) < 1) {
            return new WP_Error(
                'fflhub_offer_seed_failed',
                __('Could not seed distributor offers for this product.', 'ffl-hub')
            );
        }

        $best_result = ProductBestOfferSelectionService::refresh_changed_upcs();
        if (empty($best_result['ok'])) {
            return new WP_Error(
                'fflhub_best_offer_refresh_failed',
                __('Could not refresh normalized best offer rows.', 'ffl-hub')
            );
        }

        $state_apply_result = ProductStateBestOfferApplyService::apply_changed_best_offers();
        if (empty($state_apply_result['ok'])) {
            return new WP_Error(
                'fflhub_product_state_apply_failed',
                __('Could not apply best offer data into product_state.', 'ffl-hub')
            );
        }

        // Apply Woo product brand from distributor payload.
        self::sync_product_brand_from_payload($product_id, $selected_product);

        // Validate offers includes selected distributor (log + continue)
        if ($selected_dist_id !== '' && !isset($offers[$selected_dist_id])) {
            self::log_debug(
                "[FFLHub][DistributorProductHelper] Selected distributor '{$selected_dist_id}' not present in offers map; continuing."
            );
        }

        // F) Import images from all distributors (selected distributor marked primary).
        self::import_images_from_offers(
            $product_id,
            $upc,
            $offers,
            $selected_dist_id
        );

        // G) Apply the newly calculated product_state values back into Woo.
        $woo_apply_result = ProductStateWooApplyService::apply_product_ids([$product_id]);
        if (empty($woo_apply_result['ok'])) {
            return new WP_Error(
                'fflhub_product_state_woo_apply_failed',
                __('Could not apply product_state values to WooCommerce product.', 'ffl-hub')
            );
        }

        // H) Return success response
        return self::build_created_product_response($product_id);
    }

    /**
     * Find existing product by Woo global unique ID.
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
                'meta_key'       => '_global_unique_id',
                'meta_value'     => $upc,
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
        DistributorProductPayload $selected_product
    ): WC_Product_Simple {
        $product = new WC_Product_Simple();

        $name        = (string) ($selected_product->name ?? '');
        $sku         = (string) ($selected_product->sku ?? '');
        $description = (string) ($selected_product->description ?? '');

        $name = trim($name);
        $sku  = trim($sku);

        $product->set_name($name !== '' ? $name : ($sku !== '' ? $sku : $upc));
        $product->set_description($description);
        if ($upc !== '' && method_exists($product, 'set_global_unique_id')) {
            $product->set_global_unique_id($upc);
        }

        self::assign_unique_sku_for_new_product($product, $sku, $upc);

        $product->set_manage_stock(true);
        $product->set_stock_quantity(0);
        $product->set_stock_status('outofstock');

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
     * @param array<string,DistributorOffer> $offers
     */
    private static function apply_categories_from_payload(
        WC_Product_Simple $product,
        DistributorProductPayload $selected_product,
        array $offers = []
    ): void {
        $recommended_category = self::resolve_recommended_category_for_creation(
            $selected_product,
            $offers
        );

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
     * Resolve category path for creation.
     *
     * Preference:
     * - Lipsey's recommended_category when a Lipsey's offer has category data.
     * - Otherwise selected payload recommended_category.
     *
     * @param array<string,DistributorOffer> $offers
     * @return array<int,string>|null
     */
    private static function resolve_recommended_category_for_creation(
        DistributorProductPayload $selected_product,
        array $offers = []
    ): ?array {
        foreach ($offers as $offer) {
            if (!($offer instanceof DistributorOffer)) {
                continue;
            }

            $offer_dist_id = self::normalize_distributor_id((string) ($offer->distributor_id ?? ''));
            if ($offer_dist_id !== self::LIPSEYS_DISTRIBUTOR_ID) {
                continue;
            }

            $payload = $offer->product ?? null;
            if (!($payload instanceof DistributorProductPayload)) {
                continue;
            }

            $candidate = $payload->recommended_category ?? null;
            if (is_array($candidate) && !empty($candidate)) {
                return $candidate;
            }

            $raw = $payload->raw ?? null;
            if (is_array($raw)) {
                $raw_category_candidates = [
                    $raw['item_group'] ?? null,
                    $raw['itemGroup'] ?? null,
                    $raw['family'] ?? null,
                    $raw['item_type'] ?? null,
                    $raw['itemType'] ?? null,
                    $raw['type'] ?? null,
                ];

                foreach ($raw_category_candidates as $raw_category_candidate) {
                    $raw_category_candidate = trim((string) ($raw_category_candidate ?? ''));
                    if ($raw_category_candidate === '') {
                        continue;
                    }

                    $mapped = DistributorProductCategoryMapper::map_lipseys($raw_category_candidate);
                    if (is_array($mapped) && !empty($mapped)) {
                        return $mapped;
                    }
                }
            }
        }

        $fallback = $selected_product->recommended_category ?? null;
        return (is_array($fallback) && !empty($fallback)) ? $fallback : null;
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
     * Mirror product_state shipping dimensions into WooCommerce native shipping fields.
     *
     * The method name is kept for old callers/scripts, but product_state is now
     * the authoritative source. Weight is stored in ounces and converted to the
     * WooCommerce store weight unit.
     */
    public static function sync_woo_shipping_from_fflhub_meta(WC_Product $product): bool
    {
        $state_row = ProductStateStore::get_row_for_product($product);
        if (!is_array($state_row)) {
            return false;
        }

        return self::sync_woo_shipping_from_fflhub_values(
            $product,
            $state_row['shipping_weight_oz'] ?? '',
            $state_row['shipping_length_in'] ?? '',
            $state_row['shipping_width_in'] ?? '',
            $state_row['shipping_height_in'] ?? ''
        );
    }

    /**
     * @param mixed $weight_oz_raw
     * @param mixed $length_in_raw
     * @param mixed $width_in_raw
     * @param mixed $height_in_raw
     */
    public static function sync_woo_shipping_from_fflhub_values(
        WC_Product $product,
        $weight_oz_raw,
        $length_in_raw,
        $width_in_raw,
        $height_in_raw
    ): bool {
        $changed = false;

        $weight = self::normalize_woo_weight_from_ounces($weight_oz_raw);
        if ($weight !== null && self::normalized_product_prop($product->get_weight('edit')) !== $weight) {
            $product->set_weight($weight);
            $changed = true;
        }

        $length = self::normalize_woo_dimension_from_inches($length_in_raw);
        if ($length !== null && self::normalized_product_prop($product->get_length('edit')) !== $length) {
            $product->set_length($length);
            $changed = true;
        }

        $width = self::normalize_woo_dimension_from_inches($width_in_raw);
        if ($width !== null && self::normalized_product_prop($product->get_width('edit')) !== $width) {
            $product->set_width($width);
            $changed = true;
        }

        $height = self::normalize_woo_dimension_from_inches($height_in_raw);
        if ($height !== null && self::normalized_product_prop($product->get_height('edit')) !== $height) {
            $product->set_height($height);
            $changed = true;
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

        $brand = self::canonical_brand_name_from_payload($selected_product);
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
     * Convert FFLHub ounce weight into Woo's configured product weight unit.
     *
     * @param mixed $value
     */
    private static function normalize_woo_weight_from_ounces($value): ?string
    {
        $ounces = self::to_positive_float($value);
        if ($ounces === null) {
            return null;
        }

        $target_unit = strtolower(trim((string) get_option('woocommerce_weight_unit', 'lbs')));
        if ($target_unit === '') {
            $target_unit = 'lbs';
        }

        if (function_exists('wc_get_weight')) {
            return self::format_woo_decimal((float) wc_get_weight($ounces, $target_unit, 'oz'));
        }

        $converted = ($target_unit === 'oz') ? $ounces : ($ounces / 16);
        return self::format_woo_decimal($converted);
    }

    /**
     * Convert FFLHub inch dimensions into Woo's configured dimension unit.
     *
     * @param mixed $value
     */
    private static function normalize_woo_dimension_from_inches($value): ?string
    {
        $inches = self::to_positive_float($value);
        if ($inches === null) {
            return null;
        }

        $target_unit = strtolower(trim((string) get_option('woocommerce_dimension_unit', 'in')));
        if ($target_unit === '') {
            $target_unit = 'in';
        }

        if (function_exists('wc_get_dimension')) {
            return self::format_woo_decimal((float) wc_get_dimension($inches, $target_unit, 'in'));
        }

        switch ($target_unit) {
            case 'm':
                $converted = $inches * 0.0254;
                break;
            case 'cm':
                $converted = $inches * 2.54;
                break;
            case 'mm':
                $converted = $inches * 25.4;
                break;
            case 'yd':
                $converted = $inches / 36;
                break;
            case 'ft':
                $converted = $inches / 12;
                break;
            case 'in':
            default:
                $converted = $inches;
                break;
        }

        return self::format_woo_decimal($converted);
    }

    /**
     * Normalize an existing Woo shipping prop so comparisons do not churn saves.
     *
     * @param mixed $value
     */
    private static function normalized_product_prop($value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return '';
        }

        return is_numeric($raw) ? self::format_woo_decimal((float) $raw) : $raw;
    }

    private static function format_woo_decimal(float $value): string
    {
        $formatted = function_exists('wc_format_decimal')
            ? (string) wc_format_decimal($value, 4)
            : number_format($value, 4, '.', '');

        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted === '' ? '0' : $formatted;
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
     * Back-compat misspelled method (keep existing callers working).
     *
     * Remove later once you’ve replaced all call sites.
     */
    public static function get_reccomended_price_from_payload(DistributorProductPayload $selected_product): ?float
    {
        return self::get_recommended_price_from_payload($selected_product);
    }

    public static function default_map_policy_for_payload(DistributorProductPayload $payload): string
    {
        $brand = self::canonical_brand_name_from_payload($payload);
        $map_policy = ($brand !== '') ? Options::get_map_policy_for_brand($brand) : '';
        if ($map_policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE || $map_policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) {
            return $map_policy;
        }

        return Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
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
     * Check whether manual shipping override is enabled on a product.
     */
    private static function is_manual_shipping_override_enabled(WC_Product $product): bool
    {
        $state_row = ProductStateStore::get_row_for_product($product);
        return is_array($state_row) && ((int) ($state_row['manual_shipping_override'] ?? 0) === 1);
    }

    /**
     * Resolve Woo regular/sale price pair from computed sell price and optional MAP/MSRP.
     *
     * Rules:
     * - MAP is preferred as the regular/list anchor when it is above computed sell price.
     * - MSRP is a fallback regular/list anchor only when it is above computed sell price.
     * - If neither MAP nor MSRP is above computed sell price, regular price uses computed sell and sale price is cleared.
     *
     * @param mixed $map_raw
     * @param mixed $msrp_raw
     * @return array{regular:string,sale:string}
     */
    public static function resolve_regular_and_sale_prices(float $sell_price, $map_raw = null, $msrp_raw = null): array
    {
        $sell = wc_format_decimal($sell_price, 2);
        $map = self::to_positive_float($map_raw);
        $msrp = self::to_positive_float($msrp_raw);

        if ($map !== null && (float) $map > (float) $sell) {
            return [
                'regular' => wc_format_decimal($map, 2),
                'sale' => $sell,
            ];
        }

        if ($msrp !== null && (float) $msrp > (float) $sell) {
            return [
                'regular' => wc_format_decimal($msrp, 2),
                'sale' => $sell,
            ];
        }

        return ['regular' => $sell, 'sale' => ''];
    }

    /**
     * Update regular/sale price pair then persist.
     *
     * @param mixed $map_raw
     * @param mixed $msrp_raw
     */
    private static function set_sell_price_and_save(WC_Product $product, float $sell_price, $map_raw, $msrp_raw = null): void
    {
        $prices = self::resolve_regular_and_sale_prices($sell_price, $map_raw, $msrp_raw);
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

    private static function canonical_brand_name_from_payload(DistributorProductPayload $payload): string
    {
        $brand = self::normalize_brand_name((string) ($payload->brand ?? ''));
        if ($brand === '') {
            return '';
        }

        $brand_key = self::brand_alias_key($brand);
        if (isset(self::OSIGHT_SOURCE_BRAND_KEYS[$brand_key]) && self::payload_looks_like_osight($payload)) {
            return 'Osight';
        }

        return $brand;
    }

    private static function payload_looks_like_osight(DistributorProductPayload $payload): bool
    {
        $haystack = strtolower(trim(implode(' ', [
            (string) $payload->sku,
            (string) $payload->name,
            (string) $payload->description,
        ])));

        return $haystack !== '' && strpos($haystack, 'osight') !== false;
    }

    private static function brand_alias_key(string $brand): string
    {
        $s = strtolower(trim($brand));
        $s = (string) preg_replace('/[^a-z0-9]+/', '', $s);
        return $s;
    }

}
