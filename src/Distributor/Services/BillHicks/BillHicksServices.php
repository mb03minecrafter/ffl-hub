<?php

namespace FFLHub\Distributor\Services\BillHicks;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\BillHicks\Cron\BillHicksInventoryCronService;
use FFLHub\Distributor\Services\BillHicks\Cron\BillHicksProductCronService;
use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * Bill Hicks distributor services bundle.
 */
final class BillHicksServices extends DistributorServicesBase
{
    public function __construct(
        DoubleBufferedProductTable $fulfillmentTable,
        BillHicksProductCronService $productCron,
        BillHicksInventoryCronService $inventoryCron
    ) {
        parent::__construct(
            $fulfillmentTable,
            $productCron,
            $inventoryCron
        );
    }
}
