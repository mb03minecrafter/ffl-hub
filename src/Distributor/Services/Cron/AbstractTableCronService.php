<?php

namespace FFLHub\Distributor\Services\Cron;

use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AbstractTableCronService
 *
 * Convenience base class for cron services that operate on a
 * {@see DoubleBufferedProductTable}.
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
    protected DoubleBufferedProductTable $table;

    /**
     * @param DoubleBufferedProductTable $table Fulfillment table instance.
     */
    public function __construct(DoubleBufferedProductTable $table)
    {
        $this->table = $table;
    }

    /**
     * Get the fulfillment table instance.
     */
    public function get_table(): DoubleBufferedProductTable
    {
        return $this->table;
    }

    /**
     * Global force-update flag for FTP-backed cron jobs.
     *
     * Sources (either one enables force mode):
     * - define('FFLHUB_FORCE_CRON_UPDATE', true) in wp-config.php
     * - option `fflhub_force_cron_update` set truthy in wp_options
     */
    protected function should_force_update(): bool
    {
        if (defined('FFLHUB_FORCE_CRON_UPDATE') && (bool) constant('FFLHUB_FORCE_CRON_UPDATE')) {
            return true;
        }

        $raw = get_option('fflhub_force_cron_update', false);

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_numeric($raw)) {
            return ((int) $raw) === 1;
        }

        if (is_string($raw)) {
            return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
