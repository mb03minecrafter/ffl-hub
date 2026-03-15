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
 * - Split helpers in this DTO are based on `ffl_required` only.
 *   They do not represent routing lanes.
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

    /** @var array{ffl_required: DistributorOrderLine[], non_ffl_required: DistributorOrderLine[]} | null */
    private ?array $ffl_requirement_cache = null;

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
    public function ffl_required_lines(): array
    {
        return $this->lines_by_ffl_requirement()['ffl_required'];
    }

    /** @return DistributorOrderLine[] */
    public function non_ffl_required_lines(): array
    {
        return $this->lines_by_ffl_requirement()['non_ffl_required'];
    }

    /**
     * Split lines by FFL requirement.
     *
     * @return array{ffl_required: DistributorOrderLine[], non_ffl_required: DistributorOrderLine[]}
     */
    public function lines_by_ffl_requirement(): array
    {
        if ($this->ffl_requirement_cache !== null) {
            return $this->ffl_requirement_cache;
        }

        $ffl_required = [];
        $non_ffl_required = [];

        foreach ($this->lines as $l) {
            if (! ($l instanceof DistributorOrderLine)) {
                continue;
            }

            if ($l->ffl_required) {
                $ffl_required[] = $l;
            } else {
                $non_ffl_required[] = $l;
            }
        }

        $this->ffl_requirement_cache = [
            'ffl_required'     => $ffl_required,
            'non_ffl_required' => $non_ffl_required,
        ];

        return $this->ffl_requirement_cache;
    }

    /** @return DistributorOrderLine[] */
    public function lines_for_ffl_requirement(bool $ffl_required): array
    {
        return $ffl_required ? $this->ffl_required_lines() : $this->non_ffl_required_lines();
    }

    public function ship_to_for_ffl_requirement(bool $ffl_required): DistributorShipTo
    {
        return $this->ship_to_for($ffl_required);
    }

    /** @return DistributorOrderLine[] */
    public function valid_lines(): array
    {
        if ($this->ffl_requirement_cache !== null) {
            return array_merge($this->ffl_requirement_cache['ffl_required'], $this->ffl_requirement_cache['non_ffl_required']);
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
