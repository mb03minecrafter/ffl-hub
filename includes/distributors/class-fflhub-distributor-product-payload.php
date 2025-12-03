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
     * Arbitrary raw payload from the distributor.
     *
     * @var mixed
     */
    public $raw;

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
        $raw = null
    ) {
        $this->upc           = $upc;
        $this->sku           = $sku;
        $this->name          = $name;
        $this->description   = $description;
        $this->price         = $price;
        $this->map           = $map;
        $this->msrp          = $msrp;
        $this->quantity      = $quantity;
        $this->shipping_cost = $shipping_cost;
        $this->true_cost     = $true_cost;
        $this->image_url     = $image_url;
        $this->ffl_required  = $ffl_required;
        $this->raw           = $raw;
    }
}
