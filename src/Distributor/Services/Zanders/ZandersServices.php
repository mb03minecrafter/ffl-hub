<?php

namespace FFLHub\Distributor\Services\Zanders;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Distributor\Services\Zanders\Cron\ZandersFulfillmentCronService;

class ZandersServices extends DistributorServicesBase
{
    public function __construct(
        DoubleBufferedFulfillmentTable $fulfillmentTable,
                ZandersFulfillmentCronService $fulfillmentCron,

    ) {
        parent::__construct(
            $fulfillmentTable,
            $fulfillmentCron
        );
    }

    // If you ever need RSR-specific behavior,
    // you can override on_activate(), register_runtime_services(), etc.,
    // and call parent::on_activate() where appropriate.
}
