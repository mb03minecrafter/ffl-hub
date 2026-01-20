<?php

namespace FFLHub\Distributor\Product;

if (! defined('ABSPATH')) {
    exit;
}

final class DistributorOrderLine
{
    public string $upc;
    public int $quantity;
    public bool $ffl_required;

    public function __construct(string $upc, int $quantity, bool $ffl_required)
    {
        $this->upc = trim($upc);
        $this->quantity = max(1, (int) $quantity);
        $this->ffl_required = (bool) $ffl_required;
    }
}
