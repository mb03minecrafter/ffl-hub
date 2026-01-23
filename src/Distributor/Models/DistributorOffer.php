<?php

namespace FFLHub\Distributor\Models;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A single distributor's offer for a UPC:
 * - which distributor it came from (id/label)
 * - the normalized product payload
 *
 * Keeps "source metadata" separate from the product payload itself.
 */
final class DistributorOffer
{
    public string $distributor_id;
    public string $label;
    public DistributorProductPayload $product;

    public function __construct(
        string $distributor_id,
        string $label,
        DistributorProductPayload $product
    ) {
        $this->distributor_id = trim($distributor_id);
        $this->label          = $label;
        $this->product        = $product;
    }

    /**
     * True cost normalized for comparisons:
     * - returns null if missing/invalid
     */
    public function get_true_cost(): ?float
    {
        $v = $this->product->true_cost;

        // Payload constructor enforces float, but keep this defensive.
        if (! is_numeric($v)) {
            return null;
        }

        $v = (float) $v;
        return ($v > 0) ? $v : null;
    }

    /**
     * Quantity normalized for comparisons:
     * - returns null if missing/invalid
     */
    public function get_quantity(): ?int
    {
        $q = $this->product->quantity;

        if (! is_numeric($q)) {
            return null;
        }

        return (int) $q;
    }

    public function is_in_stock(): bool
    {
        $q = $this->get_quantity();
        return $q !== null && $q > 0;
    }
}
