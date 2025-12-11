<?php

namespace FFLHub\Distributor\Services\RSR;

use FFLHub\Distributor\Services\DistributorService;
use FFLHub\Distributor\Services\RSR\Cron\RSRFulfillmentCron;
use FFLHub\Distributor\Services\RSR\Cron\RSRInventoryCron;
use FFLHub\Distributor\Services\RSR\Tables\RSRFulfillmentTable;

class RSRServices implements DistributorService {
    public static function on_activate(): void
    {
        RSRFulfillmentTable::create_tables();    
        RSRFulfillmentCron::on_activation();
        RSRInventoryCron::on_activation();
    }

    public static function on_deactivate(): void
    {

        RSRFulfillmentCron::on_deactivation();
        RSRInventoryCron::on_deactivation();
        //throw new \Exception('Not implemented');
    }

    public static function register_runtime_services(): void
    {

        RSRFulfillmentCron::init();
        RSRInventoryCron::init();
        //throw new \Exception('Not implemented');
    }

    public static function get_table_class(): ?string {
        return RSRFulfillmentTable::class;
    }
}