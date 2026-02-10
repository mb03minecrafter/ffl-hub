<?php

namespace FFLHub\Distributor\Services\Zanders;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Distributor\Services\Zanders\Cron\ZandersFulfillmentCronService;
use FFLHub\Distributor\Services\Zanders\Cron\ZandersInventoryCronService;

class ZandersServices extends DistributorServicesBase
{
    public function __construct(
        DoubleBufferedFulfillmentTable $fulfillmentTable,
        ZandersFulfillmentCronService $fulfillmentCron,
        ZandersInventoryCronService $inventoryCron


    ) {
        parent::__construct(
            $fulfillmentTable,
            $fulfillmentCron,
            $inventoryCron
        );
    }

    // If you ever need RSR-specific behavior,
    // you can override on_activate(), register_runtime_services(), etc.,
    // and call parent::on_activate() where appropriate.
}
