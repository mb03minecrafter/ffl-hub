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

    /**
     * All available image URLs for this product from this distributor.
     *
     * - The constructor-seeded $image_url (if non-empty) will be the first entry.
     * - Distributors like RSR can append additional URLs (alternate angles, etc.)
     *   via add_image_url().
     * - Distributors like Lipsey's will typically only have a single URL.
     *
     * @var string[]
     */
    public $image_urls = array();

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
     * @param string        $image_url           Initial/primary image URL (optional; may be empty string).
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
        $this->ffl_required         = (bool) $ffl_required;
        $this->recommended_category = $recommended_category;
        $this->raw                  = $raw;

        $this->image_urls = array();

        // Seed image_urls with the constructor-passed image URL if present.
        $image_url = trim($image_url);
        if ($image_url !== '') {
            $this->image_urls[] = $image_url;
        }
    }

    /**
     * Add an additional image URL to this payload.
     *
     * - Trims the URL.
     * - Ignores empty strings.
     * - Avoids duplicates.
     *
     * @param string $url
     *
     * @return void
     */
    public function add_image_url(string $url): void {
        $url = trim($url);
        if ($url === '') {
            return;
        }

        if ( ! in_array($url, $this->image_urls, true) ) {
            $this->image_urls[] = $url;
        }
    }

    /**
     * Convenience helper: get the "primary" image URL,
     * i.e., the first non-empty entry in image_urls.
     *
     * @return string|null
     */
    public function get_primary_image_url(): ?string {
        if (empty($this->image_urls) || ! is_array($this->image_urls)) {
            return null;
        }

        foreach ($this->image_urls as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }
}
