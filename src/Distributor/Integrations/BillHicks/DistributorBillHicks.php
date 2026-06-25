<?php

namespace FFLHub\Distributor\Integrations\BillHicks;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorShipment;

/**
 * Bill Hicks runtime distributor stub.
 *
 * Product lookup, ordering, and shipment polling intentionally stay inert until
 * the catalog/inventory import shape is implemented and validated.
 */
final class DistributorBillHicks extends DistributorBase
{
    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        return DistributorOrderResult::manual(
            'Bill Hicks ordering is not implemented yet.',
            [DistributorOrderResult::REASON_MANUAL_REQUIRED]
        );
    }

    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        return null;
    }
}
