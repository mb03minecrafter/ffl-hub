<?php

namespace FFLHub\Distributor\Services\RSR\Tables;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FFLHub\Distributor\Services\Tables\AbstractDoubleBufferedFulfillmentTable;
use FFLHub\Distributor\Services\Tables\FulfillmentSchemaInterface;

/**
 * RSR fulfillment table (double-buffered).
 */
class RSRFulfillmentTable extends AbstractDoubleBufferedFulfillmentTable {

    /**
     * @var class-string<FulfillmentSchemaInterface>
     */
    protected const SCHEMA_CLASS = RSRFulfillmentSchema::class;

    protected const SWAP_TIMESTAMP_OPTION = 'fflhub_rsr_fulfillment_last_swap';
}
