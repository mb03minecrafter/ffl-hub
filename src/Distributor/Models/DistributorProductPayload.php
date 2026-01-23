<?php

namespace FFLHub\Distributor\Models;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Normalized distributor product.
 *
 * IMPORTANT:
 * - This object should be safe to construct even when distributor data is incomplete.
 * - Keep constructor types strict, and ensure builders supply sane defaults.
 */
final class DistributorProductPayload
{
    public string $upc;
    public string $sku;
    public string $name;
    public string $description;

    public float $price;
    public float $map;
    public float $msrp;

    public int $quantity;

    public float $shipping_cost;
    public float $true_cost;

    /**
     * All available image URLs for this product from this distributor.
     *
     * @var string[]
     */
    public array $image_urls = [];

    public bool $ffl_required;

    /**
     * Recommended unified category path for this product.
     *
     * Example:
     *   [ 'Firearms', 'Handguns', 'Pistols' ]
     *
     * @var string[]|null
     */
    public ?array $recommended_category;

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
        ?array $recommended_category,
        $raw = null
    ) {
        $this->upc = trim($upc);
        $this->sku = trim($sku);
        $this->name = trim($name);
        $this->description = trim($description);

        $this->price = self::finite_float($price);
        $this->map = self::finite_float($map);
        $this->msrp = self::finite_float($msrp);

        $this->quantity = max(0, (int) $quantity);

        $this->shipping_cost = max(0.0, self::finite_float($shipping_cost));
        $this->true_cost = max(0.0, self::finite_float($true_cost));

        $this->ffl_required = (bool) $ffl_required;
        $this->recommended_category = $recommended_category;
        $this->raw = $raw;

        $this->image_urls = [];

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
     */
    public function add_image_url(string $url): void
    {
        $url = trim($url);
        if ($url === '') {
            return;
        }

        if (! in_array($url, $this->image_urls, true)) {
            $this->image_urls[] = $url;
        }
    }

    /**
     * Convenience helper: get the primary image URL,
     * i.e., the first non-empty entry in image_urls.
     */
    public function get_primary_image_url(): ?string
    {
        foreach ($this->image_urls as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                return $url;
            }
        }
        return null;
    }

    private static function finite_float(float $v): float
    {
        // PHP has is_finite() but not always enabled depending on version; guard defensively.
        if (! is_finite($v)) {
            return 0.0;
        }
        return $v;
    }
}
