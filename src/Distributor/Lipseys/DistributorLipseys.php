<?php

namespace FFLHub\Distributor\Lipseys;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\DistributorBase;
use FFLHub\Distributor\Product\DistributorProductPayload;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Distributor\Services\Lipseys\LipseysServices;
use FFLHub\Distributor\DistributorModuleInterface;

/**
 * Lipsey's distributor implementation.
 *
 * Uses the official Lipsey's PHP client (lipseys/apiintegration)
 * if it is available.
 */
class DistributorLipseys extends DistributorBase
{

    public function __construct(DistributorModuleInterface $module, ?LipseysServices $services = null)
    {
        parent::__construct($module, $services);
    }


    /**
     * Lipsey's image resolver override.
     */
    protected function get_image_url_from_row(array $row, $field): string
    {
        $image_name = $this->get_string_field($row, $field);
        if ($image_name === '') {
            return '';
        }

        return 'https://www.lipseyscloud.com/images/' . $image_name;
    }

    /**
     * Look up a single product by UPC using the Lipsey's LIVE fulfillment table.
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


        $row['distributor_price'] *= 1.05; //BECAUSE I HAVE TO PAY SALES TAX ON LIPSEYS ITEMS  

        return $this->build_payload_from_row(
            $row,
            [
                'sku'         => ['lipseys_item_number'],
                'upc'         => ['upc'],
                'name'        => ['manufacturer', 'model', 'caliber_gauge'],
                'description' => ['product_description'],
                'price'       => ['distributor_price'],
                'map'         => ['retail_map'],
                'msrp'        => ['retail_msrp'],
                'quantity'    => ['inventory_quantity'],
                'category'    => ['item_group'],
                'image'       => ['image_name'],
                'ffl_required' => ['ffl_required']
            ],
            [DistributorProductCategoryMapper::class, 'map_lipseys'],
            $normalized_upc,
            true
        );
    }


    /**
     * Flat-rate Lipsey's shipping.
     */
    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        return 10.0;
    }
}
