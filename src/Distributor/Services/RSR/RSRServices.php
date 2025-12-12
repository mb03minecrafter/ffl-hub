<?php

namespace FFLHub\Distributor\Services\RSR;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\RSR\Cron\RSRFulfillmentCronService;
use FFLHub\Distributor\Services\RSR\Cron\RSRInventoryCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;

class RSRServices extends DistributorServicesBase
{
    public function __construct(
        DoubleBufferedFulfillmentTable $fulfillmentTable,
        RSRFulfillmentCronService $fulfillmentCron,
        RSRInventoryCronService $inventoryCron
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
