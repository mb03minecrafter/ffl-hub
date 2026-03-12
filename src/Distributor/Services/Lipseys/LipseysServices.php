<?php

namespace FFLHub\Distributor\Services\Lipseys;

use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysProductCronService;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysInventoryCronService;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysShipmentsDailyCronService;
use FFLHub\Distributor\Services\Lipseys\Tables\LipseysShipmentTable;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

class LipseysServices extends DistributorServicesBase
{
    private LipseysShipmentTable $shipmentTable;
    private LipseysShipmentsDailyCronService $shipmentsCron;

    public function __construct(
        DoubleBufferedProductTable $fulfillmentTable,
        LipseysProductCronService $fulfillmentCron,
        LipseysInventoryCronService $pricingCron,
        LipseysShipmentTable $shipmentTable,
        LipseysShipmentsDailyCronService $shipmentsCron
    ) {
        parent::__construct(
            $fulfillmentTable,
            $fulfillmentCron,
            $pricingCron
        );

        $this->shipmentTable  = $shipmentTable;
        $this->shipmentsCron  = $shipmentsCron;
    }

    public function on_activate(): void
    {
        parent::on_activate();

        // Ensure shipment table exists.
        $this->shipmentTable->createTables();

        // Schedule daily shipments job.
        $this->shipmentsCron->on_activation();
    }

    public function on_deactivate(): void
    {
        parent::on_deactivate();
        $this->shipmentsCron->on_deactivation();
    }

    public function register_runtime_services(): void
    {
        parent::register_runtime_services();
        $this->shipmentsCron->register();
    }


    public function get_shipment_table(): LipseysShipmentTable
    {
        return $this->shipmentTable;
    }
}
