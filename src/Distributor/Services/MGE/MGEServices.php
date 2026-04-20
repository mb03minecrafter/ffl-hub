<?php

namespace FFLHub\Distributor\Services\MGE;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\MGE\Cron\MGEInventoryCronService;
use FFLHub\Distributor\Services\MGE\Cron\MGEProductCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * MGE distributor service bundle.
 */
class MGEServices extends DistributorServicesBase
{
    public function __construct(
        DoubleBufferedProductTable $fulfillmentTable,
        MGEProductCronService $fulfillmentCron,
        MGEInventoryCronService $inventoryCron
    ) {
        parent::__construct(
            $fulfillmentTable,
            $fulfillmentCron,
            $inventoryCron
        );
    }
}
