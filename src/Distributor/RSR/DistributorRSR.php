<?php

namespace FFLHub\Distributor\RSR;

use FFLHub\Distributor\DistributorBase;
use FFLHub\Distributor\Product\DistributorProductPayload;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Distributor\Services\RSR\RSRServices;
use FFLHub\Distributor\DistributorModuleInterface;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * RSR distributor implementation.
 *
 * Uses the local RSR fulfillment table for product/price/quantity lookups.
 */
class DistributorRSR extends DistributorBase
{
    public function __construct(DistributorModuleInterface $module, ?RSRServices $services = null)
    {
        parent::__construct($module, $services);
    }


    /**
     * Full product payload by UPC (includes image probing).
     *
     * @param string $upc
     * @return DistributorProductPayload|null
     */
    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
        if (! $row) {
            return null;
        }



        // Build the base payload from the common row→payload builder (no images here).
        $payload = $this->build_payload_from_row(
            $row,
            [
                'sku'         => ['rsr_stock_number', 'sku'],
                'upc'         => ['upc'],
                // RSR model names suck; we prefer description. We'll override name after building.
                'name'        => ['model'],
                'description' => ['product_description'],
                'price'       => ['distributor_price'],
                'map'         => ['retail_map'],
                'msrp'        => ['retail_msrp'],
                'quantity'    => ['inventory_quantity'],
                'category'    => ['dept_number'],
            ],
            [DistributorProductCategoryMapper::class, 'map_rsr'],
            $normalized_upc,
            false // we'll handle RSR images manually
        );

        // Preserve your existing behavior: name = description.
        $payload->name = (string) $payload->description;

        // Preserve your existing placeholder FFL logic (TODO).
        $payload->ffl_required = false;

        // Preserve your existing "blocked from dropship" access (not currently used).
        // (We keep the value in raw row already; nothing else required.)

        // Image probing behavior (unchanged): start from image_name in row, probe for extras.
        $image_name = $this->get_string_field($row, ['image_name']);
        $image_name = trim((string) $image_name);

        $rsr_image_urls = [];
        if ($image_name !== '') {
            $rsr_image_urls = $this->build_rsr_image_urls_from_image_name($image_name);
        }

        // Seed primary image URL into payload, then append any extras.
        if (! empty($rsr_image_urls)) {
            $primary = (string) $rsr_image_urls[0];


            // Your payload type supports add_image_url(); we keep behavior the same.
            // If constructor already set one, add_image_url will dedupe if implemented.
            $payload->add_image_url($primary);

            foreach (array_slice($rsr_image_urls, 1) as $extra_url) {
                $payload->add_image_url($extra_url);
            }

        }

        return $payload;
    }







    /**
     * Lightweight pricing/stock payload for cron sync.
     *
     * Same normalized payload shape, but does NOT do any remote image probing.
     *
     * @param string $upc
     * @return DistributorProductPayload|null
     */
    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
        if (! $row) {
            return null;
        }

        $payload = $this->build_payload_from_row(
            $row,
            [
                'sku'         => ['rsr_stock_number', 'sku'],
                'upc'         => ['upc'],
                'name'        => ['model'],
                'description' => ['product_description'],
                'price'       => ['distributor_price'],
                'map'         => ['retail_map'],
                'msrp'        => ['retail_msrp'],
                'quantity'    => ['inventory_quantity'],
                'category'    => ['dept_number'],
            ],
            [DistributorProductCategoryMapper::class, 'map_rsr'],
            $normalized_upc,
            false // no images for pricing payload
        );

        // Preserve prior behavior: name = description.
        $payload->name = (string) $payload->description;

        // Preserve TODO placeholder FFL logic.
        $payload->ffl_required = false;

        return $payload;
    }



    /**
     * Shipping cost estimate by UPC.
     *
     * We assume FedEx 2 day ($15), then add another $5 for signature-required items.
     *
     * @param string $upc
     * @return float|null
     */
    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $cost = 15.0;

        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $product = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
        if (! $product) {
            return null;
        }

        $requires_signature = $this->get_bool_field($product, ['adult_sig_required']);

        if ($requires_signature == 1) {
            $cost += 5.0;
        }

        return $cost;
    }

 

    /**
     * Given an RSR image_name like "LAS981-0054_1.jpg", generate all real
     * product image URLs for that item, stopping when we hit the generic
     * "image coming soon" placeholder (110x85).
     *
     * @param string $image_name
     * @return string[]
     */
    private function build_rsr_image_urls_from_image_name(string $image_name): array
    {
        $image_name = trim($image_name);
        if ($image_name === '') {
            return [];
        }

        $base_prefix = 'https://img.rsrgroup.com/pimages/';
        $urls        = [];

        if (preg_match('/^(.*)_([0-9]+)(\.[^.]+)$/', $image_name, $matches)) {
            $base        = $matches[1];
            $start_index = (int) $matches[2];
            $ext         = $matches[3];

            $first_file = $base . '_' . $start_index . $ext;
            $first_url  = $base_prefix . $first_file;
            $urls[]     = $first_url;

            $max_extra_attempts = 15;

            for ($i = $start_index + 1; $i <= $start_index + $max_extra_attempts; $i++) {
                $file = $base . '_' . $i . $ext;
                $url  = $base_prefix . $file;

                if (! $this->rsr_is_real_image_url($url)) {
                    break;
                }

                $urls[] = $url;
            }
        } else {
            $urls[] = $base_prefix . ltrim($image_name, '/');
        }

        return array_values(array_unique($urls));
    }

    /**
     * Check whether the given RSR image URL is a real product image
     * and NOT the generic "image coming soon" placeholder.
     *
     * @param string $url
     * @return bool
     */
    private function rsr_is_real_image_url(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $response = wp_remote_get(
            $url,
            [
                'timeout'     => 5,
                'redirection' => 3,
            ]
        );

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '' || $body === null) {
            return false;
        }

        $image_info = @getimagesizefromstring($body);
        if (false === $image_info) {
            return false;
        }

        $width  = isset($image_info[0]) ? (int) $image_info[0] : 0;
        $height = isset($image_info[1]) ? (int) $image_info[1] : 0;

        // RSR "image coming soon" placeholder is exactly 110x85.
        if ($width === 110 && $height === 85) {
            return false;
        }

        return true;
    }
}
