<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Normalized distributor product.
 */
class FFLHub_Distributor_Product_Payload {

    /** @var string */
    public $upc;

    /** @var string */
    public $sku;

    /** @var string */
    public $name;

    /** @var string */
    public $description;

    /** @var float */
    public $price;

    /** @var float */
    public $map;

    /** @var float */
    public $msrp;

    /** @var int */
    public $quantity;

    /** @var float */
    public $shipping_cost;

    /** @var float */
    public $true_cost;

    /** @var string */
    public $image_url;

    /** @var bool */
    public $ffl_required;

    /**
     * Recommended unified category path for this product.
     *
     * Example:
     *   [ 'Firearms', 'Handguns', 'Pistols' ]
     *   [ 'Magazines', 'Rifle' ]
     *   [ 'Ammo' ]
     *
     * Typically comes directly from FFLHub_Category_Mapper::map_lipseys()
     * or FFLHub_Category_Mapper::map_rsr().
     *
     * @var string[]|null
     */
    public $recommended_category;

    /**
     * Arbitrary raw payload from the distributor.
     *
     * @var mixed
     */
    public $raw;

    /**
     * @param string        $upc
     * @param string        $sku
     * @param string        $name
     * @param string        $description
     * @param float         $price
     * @param float         $map
     * @param float         $msrp
     * @param int           $quantity
     * @param float         $shipping_cost
     * @param float         $true_cost
     * @param string        $image_url
     * @param bool          $ffl_required
     * @param string[]|null $recommended_category Unified category path, or null if unknown.
     * @param mixed         $raw                  Original distributor payload (optional).
     */
    public function __construct(
        string $upc,
        string $sku,
        string $name,
        string $description,
        float $price,
        float $map,
        float $msrp,
        int $quantity,
        float $shipping_cost,
        float $true_cost,
        string $image_url,
        bool $ffl_required,
        ?array $recommended_category,
        $raw = null
    ) {
        $this->upc                  = $upc;
        $this->sku                  = $sku;
        $this->name                 = $name;
        $this->description          = $description;
        $this->price                = $price;
        $this->map                  = $map;
        $this->msrp                 = $msrp;
        $this->quantity             = $quantity;
        $this->shipping_cost        = $shipping_cost;
        $this->true_cost            = $true_cost;
        $this->image_url            = $image_url;
        $this->ffl_required         = $ffl_required;
        $this->recommended_category = $recommended_category;
        $this->raw                  = $raw;
    }
}
