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
    public const FFLHUB_MARKUP_MODE_META = '_fflhub_markup_mode'; //markup mode of the product : int (0 means global, 1 means use override percent)
    public const FFLHUB_MARKUP_PERCENT_META = '_fflhub_markup_percent'; //markup percent of product for override: float

    public const FFLHUB_NFA_ITEM_META = '_fflhub_nfa_item'; //markup percent of product for override: float
    public const FFLHUB_LAST_SYNC_META = '_fflhub_last_sync_at'; //markup percent of product for override: float






}
