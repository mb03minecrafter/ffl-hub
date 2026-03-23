<?php

namespace FFLHub\Distributor\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * UpcLookupResult
 *
 * Value object representing the result of a UPC lookup across multiple distributors.
 *
 * Responsibilities:
 * - Hold the set of offers keyed by distributor_id.
 * - Precompute (once) the cheapest offer overall and the cheapest offer that is in stock.
 * - Provide a deterministic "best default" selection for UI/business logic.
 *
 * Notes / invariants:
 * - Offers are expected to be a map of distributor_id => DistributorOffer.
 * - Computation uses DistributorOffer::get_true_cost() for comparisons.
 * - Offers with missing/invalid true_cost are ignored for "cheapest" computations.
 * - "In stock" is defined by DistributorOffer::is_in_stock().
 * - No sorting or mutation of the offers map is performed.
 */
final class UpcLookupResult
{
    private const DIST_ID_RSR = 'rsr';
    private const DIST_ID_ZANDERS = 'zanders';
    private const RSR_ZANDERS_PREFERENCE_DELTA = 1.00;
    private const FLOAT_EPSILON = 0.000001;

    /**
     * Map of distributor_id => offer.
     *
     * @var array<string,DistributorOffer>
     */
    private array $offers;

    /**
     * Cheapest offer by true_cost among all offers (may include out-of-stock offers).
     */
    private ?DistributorOffer $cheapest_any = null;

    /**
     * Cheapest offer by true_cost among offers currently in stock.
     */
    private ?DistributorOffer $cheapest_in_stock = null;

    /**
     * @param array<string,DistributorOffer> $offers Map of distributor_id => offer
     */
    public function __construct(array $offers)
    {
        // Defensive: keep only valid offers and normalize keys.
        $clean = [];
        foreach ($offers as $dist_id => $offer) {
            if (!$offer instanceof DistributorOffer) {
                continue;
            }

            $k = is_string($dist_id) ? strtolower(trim($dist_id)) : '';
            if ($k === '') {
                // If a caller passed a weird key, don't drop the offer—stash under its own id.
                $k = strtolower(trim((string) $offer->distributor_id));
            }
            if ($k === '') {
                // Last-resort: keep it but avoid empty key collisions.
                $k = 'unknown_' . spl_object_hash($offer);
            }

            $clean[$k] = $offer;
        }

        $this->offers = $clean;
        $this->compute_cheapest();
    }

    /**
     * All offers keyed by distributor_id.
     *
     * @return array<string,DistributorOffer>
     */
    public function offers(): array
    {
        return $this->offers;
    }

    /**
     * Cheapest offer by true_cost (may be out of stock), or null if none were comparable.
     */
    public function cheapest_any(): ?DistributorOffer
    {
        return $this->cheapest_any;
    }

    /**
     * Cheapest in-stock offer by true_cost, or null if none are in stock/comparable.
     */
    public function cheapest_in_stock(): ?DistributorOffer
    {
        return $this->cheapest_in_stock;
    }

    /**
     * UI preference order:
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

    /**
     * Compute cheapest offers once.
     *
     * Comparison key:
     * - DistributorOffer::get_true_cost() (null => not comparable)
     *
     * In-stock determination:
     * - DistributorOffer::is_in_stock()
     */
    private function compute_cheapest(): void
    {
        $this->cheapest_any = null;
        $this->cheapest_in_stock = null;

        foreach ($this->offers as $offer) {
            if (!$offer instanceof DistributorOffer) {
                continue;
            }

            $true_cost = $offer->get_true_cost();
            if ($true_cost === null) {
                continue;
            }

            $best_any_cost = ($this->cheapest_any instanceof DistributorOffer)
                ? $this->cheapest_any->get_true_cost()
                : null;

            if ($this->should_replace_best_offer($offer, (float) $true_cost, $this->cheapest_any, $best_any_cost)) {
                $this->cheapest_any = $offer;
            }

            if ($offer->is_in_stock()) {
                $best_stock_cost = ($this->cheapest_in_stock instanceof DistributorOffer)
                    ? $this->cheapest_in_stock->get_true_cost()
                    : null;

                if ($this->should_replace_best_offer($offer, (float) $true_cost, $this->cheapest_in_stock, $best_stock_cost)) {
                    $this->cheapest_in_stock = $offer;
                }
            }
        }
    }

    private function should_replace_best_offer(
        DistributorOffer $candidate_offer,
        float $candidate_cost,
        ?DistributorOffer $current_offer,
        ?float $current_cost
    ): bool {
        if (!($current_offer instanceof DistributorOffer) || $current_cost === null) {
            return true;
        }

        if ($this->is_rsr_zanders_pair($candidate_offer, $current_offer)) {
            $delta = abs($candidate_cost - (float) $current_cost);
            if ($delta <= (self::RSR_ZANDERS_PREFERENCE_DELTA + self::FLOAT_EPSILON)) {
                $candidate_id = $this->offer_dist_id($candidate_offer);
                $current_id = $this->offer_dist_id($current_offer);

                if ($candidate_id === self::DIST_ID_ZANDERS && $current_id === self::DIST_ID_RSR) {
                    return true;
                }

                if ($candidate_id === self::DIST_ID_RSR && $current_id === self::DIST_ID_ZANDERS) {
                    return false;
                }
            }
        }

        return $candidate_cost < ((float) $current_cost - self::FLOAT_EPSILON);
    }

    private function is_rsr_zanders_pair(DistributorOffer $a, DistributorOffer $b): bool
    {
        $a_id = $this->offer_dist_id($a);
        $b_id = $this->offer_dist_id($b);

        return ($a_id === self::DIST_ID_RSR && $b_id === self::DIST_ID_ZANDERS)
            || ($a_id === self::DIST_ID_ZANDERS && $b_id === self::DIST_ID_RSR);
    }

    private function offer_dist_id(DistributorOffer $offer): string
    {
        return strtolower(trim((string) $offer->distributor_id));
    }
}
