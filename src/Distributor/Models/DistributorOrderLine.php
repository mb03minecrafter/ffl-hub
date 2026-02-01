<?php

namespace FFLHub\Distributor\Models;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Represents a single normalized order line destined for a distributor.
 *
 * This is the *canonical* line-item shape used throughout:
 * - order validation
 * - distributor bucketing (FFL vs non-FFL)
 * - check-catalog calls
 * - place-order payload construction
 *
 * Design goals:
 * - Keep this DTO extremely small and predictable.
 * - Strip away WooCommerce / cart noise early.
 * - Make downstream distributor logic dead simple.
 */
final class DistributorOrderLine
{
    /**
     * Normalized UPC for the product.
     *
     * Expectations:
     * - Trimmed string
     * - May still require distributor-specific normalization later
     *   (e.g. zero-padding, numeric-only enforcement).
     */
    public string $upc;

    /**
     * Quantity requested.
     *
     * Guaranteed:
     * - Always >= 1
     *
     * Rationale:
     * - Zero or negative quantities make no sense in distributor APIs
     *   and almost always indicate a programming or cart-state bug.
     */
    public int $quantity;

    /**
     * Whether this line requires an FFL transfer.
     *
     * Used to:
     * - split orders into FFL vs non-FFL buckets
     * - enforce ship_to_ffl + ShipFFL validation
     * - drive distributor-specific firearm logic
     */
    public bool $ffl_required;

    /**
     * @param string $upc          Raw or normalized UPC
     * @param int    $quantity     Requested quantity (coerced to >= 1)
     * @param bool   $ffl_required Whether the item requires FFL handling
     */
    public function __construct(string $upc, int $quantity, bool $ffl_required)
    {
        // Trim defensively; UPCs are frequently used as lookup keys.
        $this->upc = trim($upc);

        // Enforce sane lower bound.
        // Prevents accidental zero/negative quantities from propagating
        // into distributor APIs or inventory math.
        $this->quantity = max(1, (int) $quantity);

        // Explicit cast for safety; caller intent matters here.
        $this->ffl_required = (bool) $ffl_required;
    }
}
