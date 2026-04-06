<?php

namespace FFLHub\Distributor\Services\CSSI;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\CSSI\Cron\CSSIInventoryCronService;
use FFLHub\Distributor\Services\CSSI\Cron\CSSIProductCronService;
use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * CSSI distributor services bundle.
 */
class CSSIServices extends DistributorServicesBase
{
    public function __construct(
        DoubleBufferedProductTable $fulfillmentTable,
        CSSIProductCronService $productCron,
        CSSIInventoryCronService $inventoryCron
    ) {
        parent::__construct(
            $fulfillmentTable,
            $productCron,
            $inventoryCron
        );
    }
}
