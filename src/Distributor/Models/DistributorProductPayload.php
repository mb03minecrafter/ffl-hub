<?php

namespace FFLHub\Distributor\Models;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Normalized distributor product payload.
 *
 * Purpose
 * -------
 * A single, stable “shape” that all distributor integrations can map into, so the rest of the
 * system (pricing comparisons, compliance, product creation, etc.) doesn't care where data came from.
 *
 * Design goals
 * ------------
 * - Safe with incomplete distributor data (empty strings, missing prices, etc.)
 * - Strict constructor input types (builders must supply defaults)
 * - Defensive normalization (no NaN/INF floats, no negative money values, quantity >= 0)
 * - Small + serializable (raw can exist, but treat it as optional and potentially large)
 */
final class DistributorProductPayload
{
    // -----------------------------
    // Identity / description
    // -----------------------------

    /** Normalized UPC (digits-only is ideal; upstream normalizers should enforce). */
    public string $upc;

    /** Distributor-specific SKU / part number / item number. */
    public string $sku;

    /** Product name (may be derived from description if distributor doesn't provide one). */
    public string $name;

    /** Product description / long title / model string. */
    public string $description;

    // -----------------------------
    // Pricing & availability
    // -----------------------------

    /** Distributor unit price (your buy cost before shipping). */
    public float $price;

    /** Minimum advertised price (0.0 if unknown). */
    public float $map;

    /** MSRP (0.0 if unknown). */
    public float $msrp;

    /** Available quantity (0 if unknown or not available). */
    public int $quantity;

    /**
     * Product shipping weight as provided by the distributor catalog.
     *
     * NOTE:
     * Units are distributor-specific (do not assume lbs/oz globally).
     */
    public ?string $shipping_weight;

    /**
     * Estimated shipping cost for this line item (0.0 if unknown).
     *
     * NOTE:
     * Some distributors only provide shipping after order submission; in those cases this
     * will be a heuristic.
     */
    public float $shipping_cost;

    /**
     * “All-in” unit cost used for comparisons (price + shipping, or whatever your policy is).
     * This is computed by the builder (not here) so it can reflect your heuristics.
     */
    public float $true_cost;

    // -----------------------------
    // Media
    // -----------------------------

    /**
     * All available image URLs for this product from *this distributor*.
     *
     * - First entry is treated as the primary image (see get_primary_image_url()).
     * - add_image_url() ensures trimming and de-duplication.
     *
     * @var string[]
     */
    public array $image_urls = [];

    // -----------------------------
    // Compliance / categorization
    // -----------------------------

    /**
     * Whether this product should be treated as requiring FFL transfer.
     *
     * IMPORTANT:
     * This can be distributor-specific (some feeds include it, some don't).
     * If unknown, integrations should default conservatively where appropriate.
     */
    public bool $ffl_required;

    /**
     * Whether this product requires SOT/NFA handling.
     *
     * This is distinct from ffl_required and comes from distributor-specific fields
     * like `sot_required` where available.
     */
    public bool $sot_required;

    /**
     * Whether this product can be shipped directly from distributor to customer.
     *
     * true  => drop ship eligible
     * false => not drop ship eligible (dealer fulfillment only)
     */
    public bool $dropship_enabled;

    /**
     * Recommended unified category path for this product.
     *
     * Example:
     *   [ 'Firearms', 'Handguns', 'Pistols' ]
     *
     * Null => not mapped / not available.
     *
     * @var string[]|null
     */
    public ?array $recommended_category;

    /**
     * Arbitrary raw payload from the distributor (optional).
     *
     * Guidance:
     * - Prefer storing a *small* subset, not the full upstream response blob.
     * - Treat as “diagnostic only” and avoid logging it unredacted.
     *
     * @var mixed
     */
    public $raw;

    /**
     * @param string $upc
     * @param string $sku
     * @param string $name
     * @param string $description
     * @param float  $price
     * @param float  $map
     * @param float  $msrp
     * @param int    $quantity
     * @param float  $shipping_cost
     * @param float  $true_cost
     * @param string $image_url            Optional “primary” image seed (can be empty).
     * @param bool   $ffl_required
     * @param bool   $dropship_enabled
     * @param string[]|null $recommended_category
     * @param mixed  $raw
     * @param string|null $shipping_weight
     * @param bool $sot_required
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
        bool $dropship_enabled,
        ?array $recommended_category,
        $raw = null,
        ?string $shipping_weight = null,
        bool $sot_required = false
    ) {
        // Strings: trim only; higher-level builders decide formatting/casing rules.
        $this->upc = trim($upc);
        $this->sku = trim($sku);
        $this->name = trim($name);
        $this->description = trim($description);

        // Money floats: normalize to finite values.
        $this->price = self::finite_float($price);
        $this->map = self::finite_float($map);
        $this->msrp = self::finite_float($msrp);

        // Quantity: never negative.
        $this->quantity = max(0, (int) $quantity);

        $shipping_weight = trim((string) ($shipping_weight ?? ''));
        $this->shipping_weight = ($shipping_weight !== '') ? $shipping_weight : null;

        // Shipping / true cost: never negative (and finite).
        $this->shipping_cost = max(0.0, self::finite_float($shipping_cost));
        $this->true_cost = max(0.0, self::finite_float($true_cost));

        $this->ffl_required = (bool) $ffl_required;
        $this->sot_required = (bool) $sot_required;
        $this->dropship_enabled = (bool) $dropship_enabled;
        $this->recommended_category = $recommended_category;

        // Raw is intentionally left unmodified (caller chooses what to store).
        $this->raw = $raw;

        // Seed image list with an optional primary URL.
        $this->image_urls = array();
        $image_url = trim($image_url);
        if ($image_url !== '') {
            $this->image_urls[] = $image_url;
        }
    }

    /**
     * Add an additional image URL to this payload.
     *
     * Rules:
     * - Trims the URL.
     * - Ignores empty strings.
     * - Avoids duplicates (strict compare).
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
     * Convenience helper: return the first non-empty image URL.
     *
     * @return string|null
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

    /**
     * Normalize floats to finite values.
     *
     * Why:
     * - Some upstream math can produce INF/NaN (division by zero, invalid parse, etc.)
     * - Keeping payload values finite prevents JSON encoding issues and bad comparisons.
     */
    private static function finite_float(float $v): float
    {
        // is_finite() is available in PHP 7+, but guard anyway.
        if (! is_finite($v)) {
            return 0.0;
        }
        return $v;
    }
}
