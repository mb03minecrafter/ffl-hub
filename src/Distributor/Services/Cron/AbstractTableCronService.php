<?php

namespace FFLHub\Distributor\Services\Cron;

use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AbstractTableCronService
 *
 * Convenience base class for cron services that operate on a
 * {@see DoubleBufferedFulfillmentTable}.
 *
 * Responsibilities:
 * - Stores the table dependency.
 * - Provides a typed accessor for subclasses.
 *
 * Notes:
 * - This class does not schedule anything by itself; scheduling is handled by
 *   {@see AbstractCronService::register()} / {@see AbstractCronService::maybe_schedule_action()}.
 * - Subclasses are expected to implement interval and runtime behavior.
 */
abstract class AbstractTableCronService extends AbstractCronService
{
    /**
     * The double-buffered fulfillment table this cron service operates on.
     */
    protected DoubleBufferedFulfillmentTable $table;

    /**
     * @param DoubleBufferedFulfillmentTable $table Fulfillment table instance.
     */
    public function __construct(DoubleBufferedFulfillmentTable $table)
    {
        $this->table = $table;
    }

    /**
     * Get the fulfillment table instance.
     */
    public function get_table(): DoubleBufferedFulfillmentTable
    {
        return $this->table;
    }
}
