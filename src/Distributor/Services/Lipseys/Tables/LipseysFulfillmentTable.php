<?php

namespace FFLHub\Distributor\Services\Lipseys\Tables;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FFLHub\Distributor\Services\Tables\AbstractDoubleBufferedFulfillmentTable;
use FFLHub\Distributor\Services\Tables\FulfillmentSchemaInterface;

/**
 * Lipsey's fulfillment table (double-buffered).
 */
class LipseysFulfillmentTable extends AbstractDoubleBufferedFulfillmentTable {

    /**
     * @var class-string<FulfillmentSchemaInterface>
     */
    protected const SCHEMA_CLASS = LipseysFulfillmentSchema::class;

    protected const SWAP_TIMESTAMP_OPTION = 'fflhub_lipseys_fulfillment_last_swap';
}
