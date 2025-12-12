<?php

namespace FFLHub\Distributor\Services\Cron;

use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cron service that operates on a double-buffered fulfillment table.
 */
abstract class AbstractTableCronService extends AbstractCronService
{
    protected DoubleBufferedFulfillmentTable $table;

    public function __construct( DoubleBufferedFulfillmentTable $table )
    {
        $this->table = $table;
    }

    public function get_table(): DoubleBufferedFulfillmentTable
    {
        return $this->table;
    }
}
