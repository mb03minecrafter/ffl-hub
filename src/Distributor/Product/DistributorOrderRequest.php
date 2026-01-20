<?php

namespace FFLHub\Distributor\Product;

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
}
