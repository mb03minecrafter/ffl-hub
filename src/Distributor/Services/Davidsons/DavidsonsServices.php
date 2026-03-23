<?php

namespace FFLHub\Distributor\Services\Davidsons;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * Davidson's distributor services bundle.
 *
 * For now this only manages the double-buffered fulfillment table.
 * Cron/import services can be added later as the integration expands.
 */
class DavidsonsServices extends DistributorServicesBase
{
    public function __construct(DoubleBufferedProductTable $fulfillmentTable)
    {
        parent::__construct($fulfillmentTable);
    }
}

