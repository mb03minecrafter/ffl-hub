<?php

namespace FFLHub\Distributor\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FFLHub\Distributor\Services\Cron\CronServiceInterface;
use FFLHub\Distributor\Services\Tables\DistributorTableInterface;

/**
 * Per-distributor service aggregator.
 */
interface DistributorServicesInterface
{
    public function on_activate(): void;

    public function on_deactivate(): void;

    public function register_runtime_services(): void;

    /**
     * Main fulfillment table for this distributor.
     */
    public function get_fulfillment_table(): DistributorTableInterface;

    /**
     * All cron services for this distributor
     * (e.g., fulfillment cron + inventory cron).
     *
     * @return CronServiceInterface[]
     */
    public function get_cron_services(): array;
}
