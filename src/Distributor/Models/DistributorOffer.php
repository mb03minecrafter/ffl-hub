<?php

namespace FFLHub\Distributor\Models;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Represents a *single distributor's offer* for a given UPC.
 *
 * This is intentionally a thin wrapper around a DistributorProductPayload,
 * with a small amount of distributor metadata attached.
 *
 * Why this exists:
 * - A single UPC can be carried by multiple distributors.
 * - Each distributor has its own pricing, quantity, shipping rules, etc.
 * - We want to compare offers *across distributors* without polluting the
 *   product payload itself with source-specific metadata.
 *
 * Design notes:
 * - The product payload stays distributor-agnostic.
 * - This object is the "comparison unit" when deciding:
 *   - cheapest offer
 *   - cheapest in-stock offer
 *   - which distributor to order from
 */
final class DistributorOffer
{
    /**
     * Machine ID for the distributor (e.g. "rsr", "lipseys").
     *
     * Must match:
     * - DistributorModuleInterface::id()
     * - DistributorHandler registry keys
     */
    public string $distributor_id;

    /**
     * Human-readable short label (e.g. "RSR", "Lipsey's").
     *
     * Used in UI, logs, and debugging output.
     */
    public string $label;

    /**
     * Normalized product payload for this distributor.
     *
     * This contains:
     * - UPC, SKU
     * - pricing (price / map / msrp / true_cost)
     * - quantity
     * - category, images, raw source row, etc.
     */
    public DistributorProductPayload $product;

    /**
     * @param string                    $distributor_id Canonical distributor ID
     * @param string                    $label          Human label
     * @param DistributorProductPayload $product        Normalized product payload
     */
    public function __construct(
        string $distributor_id,
        string $label,
        DistributorProductPayload $product
    ) {
        // Trim defensively; IDs are often used as array keys.
        $this->distributor_id = trim($distributor_id);
        $this->label          = $label;
        $this->product        = $product;
    }

    /**
     * Get the distributor's stored true cost.
     *
     * Returns:
     * - float > 0 if valid
     * - null if missing, invalid, or non-positive
     *
     * IMPORTANT:
     * - Returning null (instead of 0) allows callers to distinguish
     *   "unknown / invalid" from "free", which matters for sorting logic.
     */
    public function get_true_cost(): ?float
    {
        $v = $this->product->true_cost;

        // Payload constructor enforces float, but stay defensive:
        // raw distributor data is not always trustworthy.
        if (! is_numeric($v)) {
            return null;
        }

        $v = (float) $v;

        return ($v > 0) ? $v : null;
    }

    /**
     * Get a landed unit cost for cross-distributor selection.
     *
     * Policy:
     * - Prefer distributor price + shipping when price is available.
     * - Fallback to true_cost when price is missing/invalid.
     *
     * This is intentionally separate from get_true_cost() so pricing logic
     * can keep using true_cost semantics where needed.
     */
    public function get_selection_cost(): ?float
    {
        $price = $this->product->price;
        $shipping = $this->product->shipping_cost;

        if (is_numeric($price)) {
            $price = (float) $price;
            if ($price > 0) {
                $ship = is_numeric($shipping) ? (float) $shipping : 0.0;
                if (!is_finite($ship) || $ship < 0) {
                    $ship = 0.0;
                }

                return $price + $ship;
            }
        }

        return $this->get_true_cost();
    }

    /**
     * Get the available quantity for this offer.
     *
     * Returns:
     * - integer quantity if known
     * - null if missing or invalid
     *
     * NOTE:
     * - Quantity being null is semantically different from 0.
     *   null   => unknown / not provided
     *   0      => explicitly out of stock
     */
    public function get_quantity(): ?int
    {
        $q = $this->product->quantity;

        if (! is_numeric($q)) {
            return null;
        }

        return (int) $q;
    }

    /**
     * Convenience helper: is this offer currently in stock?
     *
     * Rules:
     * - quantity must be known
     * - quantity must be > 0
     */
    public function is_in_stock(): bool
    {
        $q = $this->get_quantity();
        return $q !== null && $q > 0;
    }
}
