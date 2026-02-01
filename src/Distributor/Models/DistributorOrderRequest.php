<?php

namespace FFLHub\Distributor\Models;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Normalized distributor order request.
 *
 * This DTO is the single, canonical “handoff object” between:
 *  - checkout / procurement logic (Woo order/cart world)
 *  - distributor adapters (RSR, Lipsey’s, etc.)
 *
 * Why this abstraction exists
 * --------------------------
 * Different distributors have different concepts of “an order”:
 *
 * - RSR:
 *   - One place-order endpoint can accept a mixed cart *if* you structure ship-to + ShipFFL correctly.
 *   - Validation uses check-catalog, which applies restrictions and stock rules per item.
 *
 * - Lipsey’s:
 *   - Uses separate endpoints for non-FFL vs FFL (“DropShipAccessories” vs “DropShipFirearms”).
 *   - Requires different payload fields depending on bucket.
 *
 * So: we store BOTH ship-to contexts and we provide bucket helpers so distributor code can:
 *  - split lines deterministically
 *  - choose the correct ship-to
 * without checkout needing to branch per distributor.
 *
 * Invariants / expectations
 * ------------------------
 * - $lines may be passed as a mixed array; this class defensively filters to DistributorOrderLine where needed.
 * - Each DistributorOrderLine guarantees qty >= 1 (see DistributorOrderLine ctor).
 * - $dest_state and $receiving_ffl_number are stored uppercase-trimmed for stable comparisons/logging.
 * - $ship_to_customer is required (even for FFL orders, many APIs need customer identity context).
 * - $ship_to_ffl may be null; callers/distributors should enforce it when FFL bucket is used.
 */
final class DistributorOrderRequest
{
    /**
     * All requested line items (may be mixed defensively; prefer DistributorOrderLine[]).
     *
     * NOTE:
     * We do *not* normalize or re-index here because upstream code may depend on the original
     * ordering for debugging or traceability. Bucket methods preserve ordering within each bucket.
     *
     * @var DistributorOrderLine[]
     */
    public array $lines;

    /**
     * Customer ship-to address.
     *
     * Used for:
     * - non-FFL dropship orders (accessories, optics, etc.)
     * - identity context for firearm dropship validation (some distributors require customer info)
     */
    public DistributorShipTo $ship_to_customer;

    /**
     * Receiving FFL ship-to address (transfer dealer address).
     *
     * Used for:
     * - FFL-required items (firearms)
     * - Some distributors require this even for validation checks.
     *
     * Nullable because not all checkouts include firearms.
     */
    public ?DistributorShipTo $ship_to_ffl;

    /**
     * A stable, merchant-side identifier for traceability.
     *
     * Typically:
     * - WooCommerce order ID
     * - or a derived “merchant order id” string used for PO construction
     */
    public string $merchant_order_id;

    /**
     * Optional freeform notes.
     *
     * Common usage:
     * - internal / sales notes in distributor payloads (if supported)
     * - audit/debug context
     */
    public string $notes;

    /**
     * Destination state (2-letter code expected).
     *
     * Stored uppercase-trimmed.
     * Used for:
     * - compliance logic
     * - logging / telemetry context
     */
    public string $dest_state;

    /**
     * Receiving FFL license number (if applicable).
     *
     * Stored uppercase-trimmed.
     * May be empty if the cart has no firearm lines.
     */
    public string $receiving_ffl_number;

    /**
     * Fast flags computed once in ctor to avoid repeated scanning.
     *
     * These are derived from $lines and do not automatically update if $lines is mutated later.
     * (Treat this object as immutable after construction for correctness.)
     */
    public bool $contains_ffl_lines;
    public bool $contains_non_ffl_lines;

    /* ---------------- Derived split cache ---------------- */

    /**
     * Cached split of lines into buckets.
     *
     * The bucket split is deterministic:
     * - preserves original ordering within each bucket
     * - filters out non-DistributorOrderLine entries defensively
     *
     * @var array{ffl: DistributorOrderLine[], non: DistributorOrderLine[]} | null
     */
    private ?array $bucket_cache = null;

    /**
     * @param DistributorOrderLine[] $lines
     * @param DistributorShipTo      $ship_to_customer Required (identity + non-FFL ship-to)
     * @param DistributorShipTo|null $ship_to_ffl      Optional; required when FFL bucket is used
     * @param string                 $merchant_order_id Stable merchant order reference (for PO)
     * @param string                 $dest_state        Destination state (normalized to uppercase)
     * @param string                 $receiving_ffl_number FFL number (normalized to uppercase)
     * @param string                 $notes             Optional notes
     */
    public function __construct(
        array $lines,
        DistributorShipTo $ship_to_customer,
        ?DistributorShipTo $ship_to_ffl,
        string $merchant_order_id,
        string $dest_state,
        string $receiving_ffl_number = '',
        string $notes = ''
    ) {
        $this->lines              = $lines;
        $this->ship_to_customer   = $ship_to_customer;
        $this->ship_to_ffl        = $ship_to_ffl;
        $this->merchant_order_id  = $merchant_order_id;
        $this->notes              = $notes;

        // Normalize once for stable downstream comparisons and logging.
        $this->dest_state          = strtoupper(trim($dest_state));
        $this->receiving_ffl_number = strtoupper(trim($receiving_ffl_number));

        // Precompute “contains” flags in a single pass.
        // NOTE: These flags assume this request is not mutated after construction.
        $has_ffl = false;
        $has_non = false;

        foreach ($lines as $l) {
            if ($l instanceof DistributorOrderLine) {
                if ($l->ffl_required) {
                    $has_ffl = true;
                } else {
                    $has_non = true;
                }
            }
        }

        $this->contains_ffl_lines     = $has_ffl;
        $this->contains_non_ffl_lines = $has_non;
    }

    /**
     * Resolve which ship-to should be used for a given line type.
     *
     * Rules:
     * - If $ffl_required=true and ship_to_ffl is present, return ship_to_ffl.
     * - Otherwise fall back to ship_to_customer.
     *
     * Important:
     * - This is a convenience method, not a validation method.
     *   Distributors that REQUIRE ship_to_ffl for firearms must still enforce it.
     */
    public function ship_to_for(bool $ffl_required): DistributorShipTo
    {
        if ($ffl_required && $this->ship_to_ffl instanceof DistributorShipTo) {
            return $this->ship_to_ffl;
        }
        return $this->ship_to_customer;
    }

    /* ---------------- Derived accessors (FFL vs Non-FFL) ---------------- */

    /**
     * True if the request contains any FFL-required lines.
     *
     * Uses precomputed flag (O(1)).
     * Reminder: if you mutate $this->lines after construction, this may become stale.
     */
    public function has_ffl_lines(): bool
    {
        return $this->contains_ffl_lines === true;
    }

    /**
     * True if the request contains any non-FFL lines.
     *
     * Uses precomputed flag (O(1)).
     * Reminder: if you mutate $this->lines after construction, this may become stale.
     */
    public function has_non_ffl_lines(): bool
    {
        return $this->contains_non_ffl_lines === true;
    }

    /**
     * Get only FFL-required lines.
     *
     * @return DistributorOrderLine[]
     */
    public function ffl_lines(): array
    {
        return $this->lines_by_bucket()['ffl'];
    }

    /**
     * Get only non-FFL lines.
     *
     * @return DistributorOrderLine[]
     */
    public function non_ffl_lines(): array
    {
        return $this->lines_by_bucket()['non'];
    }

    /**
     * Split lines into FFL and non-FFL buckets (cached).
     *
     * Notes:
     * - Filters out invalid entries defensively.
     * - Preserves original ordering within each bucket.
     * - Caches result for repeated calls (validation + ordering + logging paths).
     *
     * @return array{ffl: DistributorOrderLine[], non: DistributorOrderLine[]}
     */
    public function lines_by_bucket(): array
    {
        if ($this->bucket_cache !== null) {
            return $this->bucket_cache;
        }

        $ffl = [];
        $non = [];

        foreach ($this->lines as $l) {
            if (! ($l instanceof DistributorOrderLine)) {
                continue;
            }

            if ($l->ffl_required) {
                $ffl[] = $l;
            } else {
                $non[] = $l;
            }
        }

        $this->bucket_cache = [
            'ffl' => $ffl,
            'non' => $non,
        ];

        return $this->bucket_cache;
    }

    /**
     * Return lines for a specific bucket.
     *
     * @param bool $ffl_required true => FFL bucket, false => non-FFL bucket
     * @return DistributorOrderLine[]
     */
    public function lines_for_bucket(bool $ffl_required): array
    {
        return $ffl_required ? $this->ffl_lines() : $this->non_ffl_lines();
    }

    /**
     * Alias for ship_to_for() using “bucket” naming (matches distributor code patterns).
     */
    public function ship_to_for_bucket(bool $ffl_required): DistributorShipTo
    {
        return $this->ship_to_for($ffl_required);
    }

    /**
     * Get all valid lines (filters to DistributorOrderLine only).
     *
     * This is useful when upstream hands us a mixed array, or when you want
     * an “only trusted DTOs” list without bucketing.
     *
     * @return DistributorOrderLine[]
     */
    public function valid_lines(): array
    {
        // If bucketed already, merging is cheapest and preserves stable order:
        // - all FFL first then all non-FFL (note: this is NOT original mixed order).
        if ($this->bucket_cache !== null) {
            return array_merge($this->bucket_cache['ffl'], $this->bucket_cache['non']);
        }

        $out = [];
        foreach ($this->lines as $l) {
            if ($l instanceof DistributorOrderLine) {
                $out[] = $l;
            }
        }

        return $out;
    }
}
