<?php

namespace FFLHub\Distributor\Services\Lipseys;

use FFLHub\Distributor\Services\DistributorService;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysFulfilmentCron;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysPricingQuantityCron;
use FFLHub\Distributor\Services\Lipseys\Tables\LipseysFulfillmentTable;


class LipseysServices implements DistributorService {
    public static function on_activate(): void
    {

        LipseysFulfillmentTable::create_tables();
        LipseysFulfilmentCron::on_activation();
        LipseysPricingQuantityCron::on_activation();
    }

    public static function on_deactivate(): void
    {

        //throw new \Exception('Not implemented');
        LipseysFulfilmentCron::on_deactivation();
        LipseysPricingQuantityCron::on_deactivation();
    }

    public static function register_runtime_services(): void
    {

        LipseysFulfilmentCron::init();
        LipseysPricingQuantityCron::init();
        //throw new \Exception('Not implemented');
    }


    public static function get_table_class(): ?string {
        return LipseysFulfillmentTable::class;
    }
}