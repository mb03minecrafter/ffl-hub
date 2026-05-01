<?php

namespace FFLHub\Distributor\Integrations\Orion;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorShipment;

/**
 * Runtime placeholder for the Orion integration.
 *
 * Product import, order placement, and shipment tracking will be added behind
 * this module once the settings scaffold is in place.
 */
final class DistributorOrion extends DistributorBase
{
    public function validate_order_request(
        DistributorOrderRequest $request,
        bool $local_only = false
    ): DistributorOrderValidationResult {
        return DistributorOrderValidationResult::allow('Orion validation is not active yet.');
    }

    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        return DistributorOrderResult::manual(
            'Orion ordering is not implemented yet.',
            [DistributorOrderResult::REASON_MANUAL_REQUIRED]
        );
    }

    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        return null;
    }
}
