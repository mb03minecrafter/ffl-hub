<?php


namespace FFLHub\Product;


if (! defined('ABSPATH')) {
    exit;
}

/**
 * Central registry and helpers for FFLHub product meta.
 *
 * Starting simple with only the FFL required flag.
 */
class ProductMeta
{

    /**
     * Meta key: whether this product requires an FFL.
     *
     * Stored as 0 or 1 (integer), NOT "yes"/"no".
     */
    public const FFLHUB_FFL_REQUIRED_META = '_fflhub_ffl_required';
    //Product UPC
    public const FFLHUB_UPC_META = '_fflhub_upc';
    //Is this product managed by FFL HUB? If 1, then yes, 0, then no
    public const FFLHUB_MANAGED_META = '_fflhub_managed';



    //ID of the current distributor being used as the source of product:
    //0=RSR
    //1=Lipseys
    //etc TODO: MORE DISTRIBUTORS
    public const FFLHUB_SOURCE_DISTRIBUTOR_META = '_fflhub_primary_distributor';


    //Pricing META
    public const FFLHUB_LAST_TRUE_COST_META = '_fflhub_last_true_cost'; //last true cost of product : float
    public const FFLHUB_LAST_DEALER_PRICE_META = '_fflhub_last_dealer_price'; //last dealer price of product : float
    public const FFLHUB_LAST_MAP_META = '_fflhub_last_map'; //last MAP price of product : float
    public const FFLHUB_LAST_MSRP_META = '_fflhub_last_msrp'; //last MSRP price of product : float
    public const FFLHUB_LAST_COMPUTED_PRICE_META = '_fflhub_last_computed_price'; //last computed price (using our markup) of product : float
   

    public const FFLHUB_SOT_REQUIRED_META = '_fflhub_sot_required'; // 0/1 flag
    public const FFLHUB_LAST_SYNC_META = '_fflhub_last_sync_at'; //markup percent of product for override: float


    // Pricing META
    public const FFLHUB_MARKUP_MODE_META = '_fflhub_markup_mode';

    /**
     * Markup mode values:
     * 0 = Global Markup Percent (uses Options::get_global_markup())
     * 1 = Fixed Percent (uses FFLHUB_MARKUP_PERCENT_META)
     * 2 = Fixed Price (uses FFLHUB_FIXED_PRICE_META)
     */
    public const MARKUP_MODE_GLOBAL      = 0;
    public const MARKUP_MODE_FIXED_PCT   = 1;
    public const MARKUP_MODE_FIXED_PRICE = 2;

    public const FFLHUB_MARKUP_PERCENT_META = '_fflhub_markup_percent'; // float, 0-100
    public const FFLHUB_FIXED_PRICE_META    = '_fflhub_fixed_price';    // 🆕 float, final sell price


    public const FFLHUB_LAST_SHIPPING_COST_META = '_fflhub_last_shipping_cost';
    public const FFLHUB_DROPSHIP_ENABLED_META   = '_fflhub_dropship_enabled';
    public const FFLHUB_SHIPPING_WEIGHT_META    = '_fflhub_shipping_weight';
    public const FFLHUB_SHIPPING_LENGTH_IN_META = '_fflhub_shipping_length_in';
    public const FFLHUB_SHIPPING_WIDTH_IN_META  = '_fflhub_shipping_width_in';
    public const FFLHUB_SHIPPING_HEIGHT_IN_META = '_fflhub_shipping_height_in';

    // BOM / build product metadata
    public const FFLHUB_BOM_ENABLED_META = '_fflhub_bom_enabled';
    public const FFLHUB_BOM_TOTAL_COST_META = '_fflhub_bom_total_cost';

}
