<?php


namespace FFLHub\Distributor\Services;

use FFLHub\Distributor\DistributorRegistry;

final class DistributorServiceHandler
{

    public static function on_activate(): void
    {
        foreach (DistributorRegistry::get_distributor_classes() as $distClass) {
            $serviceClass = $distClass::get_services_class();
            $serviceClass::on_activate();
        }
    }

    public static function on_deactivate(): void
    {
        foreach (DistributorRegistry::get_distributor_classes() as $distClass) {
            $serviceClass = $distClass::get_services_class();
            $serviceClass::on_deactivate();
        }
    }

    public static function register_runtime_services(): void
    {
        foreach (DistributorRegistry::get_distributor_classes() as $distClass) {
            $serviceClass = $distClass::get_services_class();
            $serviceClass::register_runtime_services();
        }
    }


   

}
