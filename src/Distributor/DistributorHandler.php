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



use FFLHub\Distributor\Product\DistributorProductPayload;

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




    /**
     * Fetch normalized distributor payloads for a UPC across all distributors.
     *
     * Returns:
     *  - carriers: all distributors that carry this UPC
     *  - cheapest_in_stock: cheapest valid true_cost with quantity > 0
     *  - cheapest_any: cheapest valid true_cost ignoring stock
     *
     * @param string $upc
     * @return array{
     *   carriers: array<string,array{label:string,payload:DistributorProductPayload,true_cost:?float,quantity:?int}>,
     *   cheapest_in_stock: ?array{product:DistributorProductPayload,true_cost:float,label:string,id:string,quantity:?int},
     *   cheapest_any: ?array{product:DistributorProductPayload,true_cost:float,label:string,id:string,quantity:?int}
     * }
     */
    public function get_payloads_for_upc(string $upc): array
    {
        $upc = trim($upc);

        $carriers          = [];
        $cheapest_in_stock = null;
        $cheapest_any      = null;

        if ($upc === '') {
            return [
                'carriers'          => $carriers,
                'cheapest_in_stock' => $cheapest_in_stock,
                'cheapest_any'      => $cheapest_any,
            ];
        }

        foreach ($this->distributors as $id => $distributor) {
            if (! $distributor) {
                continue;
            }

            try {
                
                $product = $distributor->get_product_by_upc($upc);
                
            } catch (\Throwable $e) {
                // Ignore this distributor on error; others may still succeed.
                continue;
            }

            if (! ($product instanceof DistributorProductPayload)) {
                continue;
            }

            $label = method_exists($distributor, 'get_label')
                ? $distributor->get_label()
                : ucfirst((string) $id);

            $payload_array = get_object_vars($product);

            $true_cost = null;
            if (isset($payload_array['true_cost']) && is_numeric($payload_array['true_cost'])) {
                $true_cost = (float) $payload_array['true_cost'];
            }

            $quantity = null;
            if (isset($payload_array['quantity']) && is_numeric($payload_array['quantity'])) {
                $quantity = (int) $payload_array['quantity'];
            }

            $key = (string) $id;

            $carriers[$key] = [
                'label'     => $label,
                'payload'   => $product,
                'true_cost' => $true_cost,
                'quantity'  => $quantity,
            ];

            // Cheapest overall (any quantity) if true_cost is valid.
            if ($true_cost !== null) {
                if ($cheapest_any === null || $true_cost < $cheapest_any['true_cost']) {
                    $cheapest_any = [
                        'product'   => $product,
                        'true_cost' => $true_cost,
                        'label'     => $label,
                        'id'        => $key,
                        'quantity'  => $quantity,
                    ];
                }
            }

            // Cheapest *in stock* (quantity > 0 and valid true_cost).
            if ($true_cost !== null && $quantity !== null && $quantity > 0) {
                if ($cheapest_in_stock === null || $true_cost < $cheapest_in_stock['true_cost']) {
                    $cheapest_in_stock = [
                        'product'   => $product,
                        'true_cost' => $true_cost,
                        'label'     => $label,
                        'id'        => $key,
                        'quantity'  => $quantity,
                    ];
                }
            }
        }

        return [
            'carriers'          => $carriers,
            'cheapest_in_stock' => $cheapest_in_stock,
            'cheapest_any'      => $cheapest_any,
        ];
    }
}
