<?php

namespace FFLHub\Distributor\Services\Orion;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\Orion\Cron\OrionInventoryCronService;
use FFLHub\Distributor\Services\Orion\Cron\OrionProductCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

final class OrionServices extends DistributorServicesBase
{
    public function __construct(
        DoubleBufferedProductTable $fulfillmentTable,
        OrionProductCronService $productCron,
        OrionInventoryCronService $inventoryCron
    ) {
        parent::__construct(
            $fulfillmentTable,
            $productCron,
            $inventoryCron
        );
    }
}
