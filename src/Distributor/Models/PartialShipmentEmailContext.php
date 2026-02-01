<?php

namespace FFLHub\Distributor\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class PartialShipmentEmailContext
{
    public OrderPlacementJobRow $job;
    public ShippingUpdateResult $update;
    public DistributorShipment $shipment;

    /** @var DistributorOrderLine[] */
    public array $lines;

    /**
     * @param DistributorOrderLine[] $lines
     */
    public function __construct(
        OrderPlacementJobRow $job,
        ShippingUpdateResult $update,
        DistributorShipment $shipment,
        array $lines
    ) {
        $this->job = $job;
        $this->update = $update;
        $this->shipment = $shipment;

        // Defensive: keep only valid line objects; preserve order.
        $out = [];
        foreach ($lines as $l) {
            if ($l instanceof DistributorOrderLine) {
                $out[] = $l;
            }
        }
        $this->lines = $out;
    }

    public function should_send(): bool
    {
        // hard guard: only send when new tracking/invoices were added
        return $this->update->has_changes();
    }
}
