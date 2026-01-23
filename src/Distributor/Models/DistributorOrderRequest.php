<?php

namespace FFLHub\Distributor\Models;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Normalized distributor order request.
 *
 * Key abstraction for handling distributors like:
 * - RSR: one endpoint regardless of FFL/non-FFL
 * - Lipsey's: separate endpoints for FFL vs non-FFL
 *
 * We include BOTH ship-to contexts so each distributor can pick what it needs
 * without the procurement layer branching.
 */
final class DistributorOrderRequest
{
    /** @var DistributorOrderLine[] */
    public array $lines;

    /** Customer ship-to (non-FFL items typically) */
    public DistributorShipTo $ship_to_customer;

    /** Receiving FFL ship-to (FFL-required items typically) */
    public ?DistributorShipTo $ship_to_ffl;

    /** Your Woo order ID or another stable ID for traceability */
    public string $merchant_order_id;

    /** Optional freeform notes to distributor */
    public string $notes;

    /** Destination state used for compliance/logging context */
    public string $dest_state;

    /** Receiving FFL number if applicable (may be empty) */
    public string $receiving_ffl_number;

    public bool $contains_ffl_lines;
    public bool $contains_non_ffl_lines;

    /* ---------------- Derived split cache ---------------- */

    /** @var array{ffl: DistributorOrderLine[], non: DistributorOrderLine[]} | null */
    private ?array $bucket_cache = null;

    /**
     * @param DistributorOrderLine[] $lines
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
        $this->lines = $lines;
        $this->ship_to_customer = $ship_to_customer;
        $this->ship_to_ffl = $ship_to_ffl;
        $this->merchant_order_id = $merchant_order_id;
        $this->notes = $notes;
        $this->dest_state = strtoupper(trim($dest_state));
        $this->receiving_ffl_number = strtoupper(trim($receiving_ffl_number));

        // Keep existing booleans, but compute them in a single pass.
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
        $this->contains_ffl_lines = $has_ffl;
        $this->contains_non_ffl_lines = $has_non;
    }

    /**
     * Convenience: get the appropriate ship-to for a given line type.
     * If an FFL address isn't available, falls back to customer.
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
     * Returns true if the request contains any FFL-required lines.
     */
    public function has_ffl_lines(): bool
    {
        // Use the existing precomputed flag (fast).
        return $this->contains_ffl_lines === true;
    }

    /**
     * Returns true if the request contains any non-FFL lines.
     */
    public function has_non_ffl_lines(): bool
    {
        // Use the existing precomputed flag (fast).
        return $this->contains_non_ffl_lines === true;
    }

    /**
     * Returns only the FFL-required lines.
     *
     * @return DistributorOrderLine[]
     */
    public function ffl_lines(): array
    {
        return $this->lines_by_bucket()['ffl'];
    }

    /**
     * Returns only the non-FFL lines.
     *
     * @return DistributorOrderLine[]
     */
    public function non_ffl_lines(): array
    {
        return $this->lines_by_bucket()['non'];
    }

    /**
     * Returns lines split into FFL and non-FFL buckets.
     *
     * Notes:
     * - Filters out non-DistributorOrderLine entries defensively.
     * - Preserves original ordering within each bucket.
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
     * Returns the lines for a specific bucket.
     *
     * @param bool $ffl_required true => FFL bucket, false => non-FFL bucket
     * @return DistributorOrderLine[]
     */
    public function lines_for_bucket(bool $ffl_required): array
    {
        return $ffl_required ? $this->ffl_lines() : $this->non_ffl_lines();
    }

    /**
     * Ship-to resolved for a specific bucket (alias for ship_to_for()).
     */
    public function ship_to_for_bucket(bool $ffl_required): DistributorShipTo
    {
        return $this->ship_to_for($ffl_required);
    }

    /**
     * All lines, normalized to only valid line objects.
     * (Useful if callers passed a mixed array defensively.)
     *
     * @return DistributorOrderLine[]
     */
    public function valid_lines(): array
    {
        // If we've already bucketed, merge those (cheapest).
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
