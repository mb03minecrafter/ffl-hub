<?php


namespace FFLHub\Distributor\Product;

use FFLHub\Product\ProductMeta;
use FFLHub\Product\CategoryInstaller;
use FFLHub\Settings\Options;

use WP_Error;
use WC_Product_Simple;

class DistributorProductHelper {


    public static function create_woo_product_from_payload(
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
        $markup_percent = Options::get_global_markup()/100; // e.g. 0.25 = 25%
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



}