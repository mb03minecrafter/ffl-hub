<?php

namespace FFLHub\Distributor\Services\SportsSouth;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\SportsSouth\Cron\SportsSouthInventoryCronService;
use FFLHub\Distributor\Services\SportsSouth\Cron\SportsSouthProductCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

final class SportsSouthServices extends DistributorServicesBase
{
    public function __construct(
        DoubleBufferedProductTable $fulfillmentTable,
        SportsSouthProductCronService $productCron,
        SportsSouthInventoryCronService $inventoryCron
    ) {
        parent::__construct(
            $fulfillmentTable,
            $productCron,
            $inventoryCron
        );
    }
}
