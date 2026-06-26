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
    private ?BillHicksEdiOrderFileBuilder $ediOrderFileBuilder = null;

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

    public function get_edi_order_file_builder(): BillHicksEdiOrderFileBuilder
    {
        if (!$this->ediOrderFileBuilder instanceof BillHicksEdiOrderFileBuilder) {
            $this->ediOrderFileBuilder = new BillHicksEdiOrderFileBuilder($this->get_fulfillment_table());
        }

        return $this->ediOrderFileBuilder;
    }
}
