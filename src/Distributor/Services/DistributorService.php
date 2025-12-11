<?php

namespace FFLHub\Distributor\Services;

if (! defined('ABSPATH')) {
    exit;
}

/*
Interface we can extend to 
implement per distributor 
activation logic and 
deactivation logic such 
as table creation and cron
event setup, etc
*/ 
interface DistributorService {
    public static function on_activate(): void;
    public static function on_deactivate(): void;
    public static function register_runtime_services(): void;


    /**
     * @return class-string<\FFLHub\Distributor\Tables\DistributorTableInterface>|null
     */
    public static function get_table_class(): ?string;


}