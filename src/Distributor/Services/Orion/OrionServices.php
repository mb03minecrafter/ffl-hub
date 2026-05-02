<?php

namespace FFLHub\Distributor\Services\Orion;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Distributor\Services\Orion\Cron\OrionInventoryCronService;
use FFLHub\Distributor\Services\Orion\Cron\OrionProductCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

final class OrionServices extends DistributorServicesBase
{
    private ?OrderPlacementJobsTable $orderTable;

    public function __construct(
        DoubleBufferedProductTable $fulfillmentTable,
        OrionProductCronService $productCron,
        OrionInventoryCronService $inventoryCron,
        ?OrderPlacementJobsTable $orderTable = null
    ) {
        $this->orderTable = $orderTable;

        parent::__construct(
            $fulfillmentTable,
            $productCron,
            $inventoryCron
        );
    }

    public function get_order_table(): ?OrderPlacementJobsTable
    {
        return $this->orderTable;
    }
}
