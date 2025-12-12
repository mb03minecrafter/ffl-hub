<?php

namespace FFLHub\Distributor\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FFLHub\Distributor\Services\Cron\CronServiceInterface;
use FFLHub\Distributor\Services\Tables\DistributorTableInterface;

/**
 * Base implementation for distributors that:
 *  - have a single fulfillment table
 *  - and one or more cron services (e.g., fulfillment + inventory).
 */
abstract class DistributorServicesBase implements DistributorServicesInterface
{
    protected DistributorTableInterface $fulfillmentTable;

    /** @var CronServiceInterface[] */
    protected array $cronServices;

    /**
     * @param DistributorTableInterface $fulfillmentTable
     * @param CronServiceInterface      ...$cronServices
     */
    public function __construct(
        DistributorTableInterface $fulfillmentTable,
        CronServiceInterface ...$cronServices
    ) {
        $this->fulfillmentTable = $fulfillmentTable;
        $this->cronServices     = $cronServices;
    }

    public function get_fulfillment_table(): DistributorTableInterface
    {
        return $this->fulfillmentTable;
    }

    /**
     * @return CronServiceInterface[]
     */
    public function get_cron_services(): array
    {
        return $this->cronServices;
    }

    public function on_activate(): void
    {
        // 1) Ensure fulfillment table exists.
        if ( method_exists( $this->fulfillmentTable, 'createTables' ) ) {
            $this->fulfillmentTable->createTables();
        }

        // 2) Let each cron service schedule itself.
        foreach ( $this->cronServices as $cron ) {
            $cron->on_activation();
        }
    }

    public function on_deactivate(): void
    {
        foreach ( $this->cronServices as $cron ) {
            $cron->on_deactivation();
        }
    }

    public function register_runtime_services(): void
    {
        foreach ( $this->cronServices as $cron ) {
            $cron->register();
        }
    }
}
