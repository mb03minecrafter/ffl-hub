<?php

namespace FFLHub\Distributor\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class ShippingUpdateResult
{
    /** @var string[] */
    public array $added_tracking;

    /** @var string[] */
    public array $added_invoices;

    /** @var string[] */
    public array $all_tracking;

    /** @var string[] */
    public array $all_invoices;

    public function __construct(array $added_tracking, array $added_invoices, array $all_tracking = [], array $all_invoices = [])
    {
        $this->added_tracking = array_values($added_tracking);
        $this->added_invoices = array_values($added_invoices);
        $this->all_tracking   = array_values($all_tracking);
        $this->all_invoices   = array_values($all_invoices);
    }

    public function has_new_tracking(): bool
    {
        return !empty($this->added_tracking);
    }

    public function has_new_invoices(): bool
    {
        return !empty($this->added_invoices);
    }

    public function has_changes(): bool
    {
        return $this->has_new_tracking() || $this->has_new_invoices();
    }
}
