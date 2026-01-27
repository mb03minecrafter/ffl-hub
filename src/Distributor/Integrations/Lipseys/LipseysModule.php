<?php

namespace FFLHub\Distributor\Integrations\Lipseys;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Contracts\DistributorModuleInterface;

use FFLHub\Distributor\Services\Lipseys\LipseysServices;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysFulfillmentCronService;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysInventoryCronService;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysShipmentsDailyCronService;
use FFLHub\Distributor\Services\Lipseys\Tables\LipseysFulfillmentSchema;
use FFLHub\Distributor\Services\Lipseys\Tables\LipseysShipmentSchema;
use FFLHub\Distributor\Services\Lipseys\Tables\LipseysShipmentTable;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;

final class LipseysModule implements DistributorModuleInterface
{
    public function id(): string { return 'lipseys'; }

    public function label(): string { return "Lipsey's"; }

    public function name(): string { return "Lipsey's"; }

    public function description(): string { return "Lipsey's Distributor"; }

    public function section_description(): string { return "Lipsey's Distributor"; }

    public function icon_url(): string
    {
        return FFLHUB_PLUGIN_URL . 'assets/icons/logo-lipseys.png';
    }

    public function settings_schema(): array
    {
        return [
            'dealer_email' => [
                'label'       => 'Dealer Email',
                'type'        => 'text',
                'placeholder' => '',
                'description' => "Your Lipsey's dealer login email.",
                'default'     => '',
            ],
            'dealer_password' => [
                'label'       => 'Dealer Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => "Password for your Lipsey's dealer account.",
                'default'     => '',
            ],
        ];
    }


    public function build_distributor(): DistributorBase
    {
        $schema = new LipseysFulfillmentSchema();
        $shipmentSchema = new LipseysShipmentSchema();

        $table = new DoubleBufferedFulfillmentTable(
            $schema,
            'fflhub_lipseys_fulfillment_last_swap'
        );

        $shipmentTable = new LipseysShipmentTable($shipmentSchema);

        $fulfillmentCron = new LipseysFulfillmentCronService($table);
        $inventoryCron   = new LipseysInventoryCronService($table);


        $shipmentCron = new LipseysShipmentsDailyCronService($shipmentTable);

        $services = new LipseysServices(
            $table,
            $fulfillmentCron,
            $inventoryCron,
            $shipmentTable,
            $shipmentCron
        );

        return new DistributorLipseys($this, $services);
    }
}
