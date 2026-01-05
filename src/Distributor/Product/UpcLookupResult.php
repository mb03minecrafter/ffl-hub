<?php

namespace FFLHub\Distributor\Product;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Result of a UPC lookup across distributors.
 *
 * Holds all offers plus precomputed cheapest offers.
 */
final class UpcLookupResult
{
    /** @var array<string, DistributorOffer> */
    private array $offers;

    private ?DistributorOffer $cheapest_any = null;
    private ?DistributorOffer $cheapest_in_stock = null;

    /**
     * @param array<string, DistributorOffer> $offers Map of distributor_id => offer
     */
    public function __construct(array $offers)
    {
        $this->offers = $offers;
        $this->compute_cheapest();
    }

    /** @return array<string, DistributorOffer> */
    public function offers(): array
    {
        return $this->offers;
    }

    public function cheapest_any(): ?DistributorOffer
    {
        return $this->cheapest_any;
    }

    public function cheapest_in_stock(): ?DistributorOffer
    {
        return $this->cheapest_in_stock;
    }

    /**
     * Your current UI preference order:
     * cheapest_in_stock → cheapest_any → first offer → null
     */
    public function best_default(): ?DistributorOffer
    {
        if ($this->cheapest_in_stock instanceof DistributorOffer) {
            return $this->cheapest_in_stock;
        }

        if ($this->cheapest_any instanceof DistributorOffer) {
            return $this->cheapest_any;
        }

        $first = reset($this->offers);
        return ($first instanceof DistributorOffer) ? $first : null;
    }

    private function compute_cheapest(): void
    {
        foreach ($this->offers as $offer) {
            if (! $offer instanceof DistributorOffer) {
                continue;
            }

            $true_cost = $offer->get_true_cost();
            if ($true_cost === null) {
                continue;
            }

            if (
                $this->cheapest_any === null
                || $true_cost < (float) $this->cheapest_any->get_true_cost()
            ) {
                $this->cheapest_any = $offer;
            }

            if ($offer->is_in_stock()) {
                if (
                    $this->cheapest_in_stock === null
                    || $true_cost < (float) $this->cheapest_in_stock->get_true_cost()
                ) {
                    $this->cheapest_in_stock = $offer;
                }
            }
        }
    }
}
