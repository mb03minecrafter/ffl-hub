<?php

namespace FFLHub\Distributor\Services\Zanders;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Distributor\Services\Zanders\Cron\ZandersFulfillmentCronService;
use FFLHub\Distributor\Services\Zanders\Cron\ZandersInventoryCronService;
use FFLHub\FFL\Tables\FFLTable;

class ZandersServices extends DistributorServicesBase
{
    private FFLTable $fflTable;
    private OrderPlacementJobsTable $orderTable;

    public function __construct(
        DoubleBufferedFulfillmentTable $fulfillmentTable,
        ZandersFulfillmentCronService $fulfillmentCron,
        ZandersInventoryCronService $inventoryCron,
        FFLTable $fflTable,
        OrderPlacementJobsTable $orderTable
    ) {
        parent::__construct(
            $fulfillmentTable,
            $fulfillmentCron,
            $inventoryCron
        );

        $this->fflTable = $fflTable;
        $this->orderTable = $orderTable;
    }

    public function get_ffl_table(): FFLTable
    {
        return $this->fflTable;
    }


    public function get_order_table() : OrderPlacementJobsTable {
        return $this->orderTable;
    }

}
