<?php

namespace FFLHub\Distributor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FFLHub\Distributor\RSR\DistributorRSR;
use FFLHub\Distributor\Lipseys\DistributorLipseys;
use FFLHub\Distributor\Product\DistributorProductSyncCronService;
// Services + tables + cron for RSR.
use FFLHub\Distributor\Services\RSR\RSRServices;
use FFLHub\Distributor\Services\RSR\Cron\RSRFulfillmentCronService;
use FFLHub\Distributor\Services\RSR\Cron\RSRInventoryCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;

// Services + tables + cron for Lipsey's.
use FFLHub\Distributor\Services\Lipseys\LipseysServices;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysFulfillmentCronService;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysInventoryCronService;
use FFLHub\Distributor\Services\Lipseys\Tables\LipseysFulfillmentSchema;
use FFLHub\Distributor\Services\RSR\Tables\RSRFulfillmentSchema;

/**
 * Central place to build and expose distributor instances.
 *
 * Each Distributor* gets its own DistributorServices* instance injected,
 * and each services instance owns:
 *  - a DoubleBufferedFulfillmentTable
 *  - its cron services (fulfillment + inventory/pricing)
 */
class DistributorHandler
{
    /**
     * @var array<string, DistributorBase> keyed by distributor ID (e.g. "rsr", "lipseys")
     */
    private array $distributors = [];


    private DistributorProductSyncCronService $productSinceCronService;

    public function __construct()
    {
        $this->register_distributors();
    }

    /**
     * Instantiate all distributors + their service graphs.
     */
    private function register_distributors(): void
    {
        
        /*
         * RSR wiring
         * --------------------------------
         * Table names here assume your double-buffered schema uses a LIVE + STAGING pair.
         * Adjust the actual table names if your DoubleBufferedFulfillmentTable
         * expects something different.
         */
        $rsrTableSchema = new RSRFulfillmentSchema();

        $rsrTable = new DoubleBufferedFulfillmentTable(
            $rsrTableSchema,
            "fflhub_rsr_fulfillment_last_swap"

        );

        $rsrFulfillmentCron = new RSRFulfillmentCronService( $rsrTable );
        $rsrInventoryCron   = new RSRInventoryCronService( $rsrTable );

        $rsrServices = new RSRServices(
            $rsrTable,
            $rsrFulfillmentCron,
            $rsrInventoryCron
        );

        $this->distributors['rsr'] = new DistributorRSR( $rsrServices );

        /*
         * Lipsey's wiring
         * --------------------------------
         */


        $lipseysTableSchema = new LipseysFulfillmentSchema();
        $lipseysTable = new DoubleBufferedFulfillmentTable(
           $lipseysTableSchema,
           "fflhub_lipseys_fulfillment_last_swap"
        );

        $lipseysFulfillmentCron = new LipseysFulfillmentCronService( $lipseysTable );
        $lipseysInventoryCron   = new LipseysInventoryCronService( $lipseysTable );

        $lipseysServices = new LipseysServices(
            $lipseysTable,
            $lipseysFulfillmentCron,
            $lipseysInventoryCron
        );

        $this->distributors['lipseys'] = new DistributorLipseys( $lipseysServices );


        $this->productSinceCronService = new DistributorProductSyncCronService();

    }

    /**
     * Get all registered distributor instances.
     *
     * @return array<string, DistributorBase>
     */
    public function get_distributors(): array
    {
        return $this->distributors;
    }

    /**
     * Get a distributor by its ID (e.g. "lipseys", "rsr").
     *
     * @param string $id
     * @return DistributorBase|null
     */
    public function get_distributor_by_id( string $id ): ?DistributorBase
    {
        return $this->distributors[ $id ] ?? null;
    }

    /**
     * Convenience hooks so your main plugin file can delegate
     * activation / deactivation / runtime registration to the services layer.
     */

    public function on_activate(): void
    {
        foreach ( $this->distributors as $distributor ) {
            if ( method_exists( $distributor, 'get_services' ) ) {
                $services = $distributor->get_services();
                if ( $services ) {
                    $services->on_activate();
                }
            }
        }


        $this->productSinceCronService->on_activation();
    }

    public function on_deactivate(): void
    {
        foreach ( $this->distributors as $distributor ) {
            if ( method_exists( $distributor, 'get_services' ) ) {
                $services = $distributor->get_services();
                if ( $services ) {
                    $services->on_deactivate();
                }
            }
        }
        $this->productSinceCronService->on_deactivation();

    }

    public function register_runtime_services(): void
    {
        foreach ( $this->distributors as $distributor ) {
            if ( method_exists( $distributor, 'get_services' ) ) {
                $services = $distributor->get_services();
                if ( $services ) {
                    $services->register_runtime_services();
                }
            }
        }

        $this->productSinceCronService->register();

    }
}
