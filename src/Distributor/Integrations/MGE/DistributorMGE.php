<?php

namespace FFLHub\Distributor\Integrations\MGE;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorShipment;

/**
 * MGE runtime distributor scaffold.
 *
 * Behavior is intentionally conservative until catalog/order/shipment flows
 * are implemented.
 */
final class DistributorMGE extends DistributorBase
{
    public function validate_order_request(
        DistributorOrderRequest $request,
        bool $local_only = false
    ): DistributorOrderValidationResult {
        return DistributorOrderValidationResult::allow(
            'MGE Wholesale validation is not implemented yet (scaffold mode).'
        );
    }

    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        return DistributorOrderResult::manual(
            'MGE Wholesale ordering is not implemented yet (scaffold only).',
            [DistributorOrderResult::REASON_MANUAL_REQUIRED]
        );
    }

    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        return null;
    }
}
