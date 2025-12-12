<?php

namespace FFLHub\Distributor\Services\Lipseys;

use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysFulfillmentCronService;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysInventoryCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;

class LipseysServices extends DistributorServicesBase
{
    public function __construct(
        DoubleBufferedFulfillmentTable $fulfillmentTable,
        LipseysFulfillmentCronService $fulfillmentCron,
        LipseysInventoryCronService $pricingCron
    ) {
        parent::__construct(
            $fulfillmentTable,
            $fulfillmentCron,
            $pricingCron
        );
    }
}