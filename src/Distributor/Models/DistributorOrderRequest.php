<?php

namespace FFLHub\Distributor\Models;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Normalized distributor order request DTO.
 *
 * Notes:
 * - `lane` is optional and may be one of:
 *   - direct_ship_non_ffl
 *   - direct_ship_ffl
 *   - dealer_fulfilled
 * - Split helpers keep deterministic order and are cached.
 */
final class DistributorOrderRequest
{
    /** @var DistributorOrderLine[] */
    public array $lines;

    public DistributorShipTo $ship_to_customer;
    public ?DistributorShipTo $ship_to_ffl;

    public string $merchant_order_id;
    public string $notes;
    public string $dest_state;
    public string $receiving_ffl_number;
    public string $lane;

    public bool $contains_ffl_lines;
    public bool $contains_non_ffl_lines;

    /** @var array{direct_ship_ffl: DistributorOrderLine[], direct_ship_non_ffl: DistributorOrderLine[]} | null */
    private ?array $lane_cache = null;

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
        string $notes = '',
        string $lane = ''
    ) {
        $this->lines              = $lines;
        $this->ship_to_customer   = $ship_to_customer;
        $this->ship_to_ffl        = $ship_to_ffl;
        $this->merchant_order_id  = $merchant_order_id;
        $this->notes              = $notes;
        $this->lane               = strtolower(trim($lane));

        $this->dest_state          = strtoupper(trim($dest_state));
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

        $this->contains_ffl_lines     = $has_ffl;
        $this->contains_non_ffl_lines = $has_non;
    }

    public function ship_to_for(bool $ffl_required): DistributorShipTo
    {
        if ($ffl_required && $this->ship_to_ffl instanceof DistributorShipTo) {
            return $this->ship_to_ffl;
        }

        return $this->ship_to_customer;
    }

    public function has_ffl_lines(): bool
    {
        return $this->contains_ffl_lines === true;
    }

    public function has_non_ffl_lines(): bool
    {
        return $this->contains_non_ffl_lines === true;
    }

    /** @return DistributorOrderLine[] */
    public function ffl_lines(): array
    {
        return $this->lines_by_lane()['direct_ship_ffl'];
    }

    /** @return DistributorOrderLine[] */
    public function non_ffl_lines(): array
    {
        return $this->lines_by_lane()['direct_ship_non_ffl'];
    }

    /**
     * @return array{direct_ship_ffl: DistributorOrderLine[], direct_ship_non_ffl: DistributorOrderLine[]}
     */
    public function lines_by_lane(): array
    {
        if ($this->lane_cache !== null) {
            return $this->lane_cache;
        }

        $direct_ship_ffl = [];
        $direct_ship_non_ffl = [];

        foreach ($this->lines as $l) {
            if (! ($l instanceof DistributorOrderLine)) {
                continue;
            }

            if ($l->ffl_required) {
                $direct_ship_ffl[] = $l;
            } else {
                $direct_ship_non_ffl[] = $l;
            }
        }

        $this->lane_cache = [
            'direct_ship_ffl'     => $direct_ship_ffl,
            'direct_ship_non_ffl' => $direct_ship_non_ffl,
        ];

        return $this->lane_cache;
    }

    /** @return DistributorOrderLine[] */
    public function lines_for_lane(bool $ffl_required): array
    {
        return $ffl_required ? $this->ffl_lines() : $this->non_ffl_lines();
    }

    public function ship_to_for_lane(bool $ffl_required): DistributorShipTo
    {
        return $this->ship_to_for($ffl_required);
    }

    /** @return DistributorOrderLine[] */
    public function valid_lines(): array
    {
        if ($this->lane_cache !== null) {
            return array_merge($this->lane_cache['direct_ship_ffl'], $this->lane_cache['direct_ship_non_ffl']);
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
