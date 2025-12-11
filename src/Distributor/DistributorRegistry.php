<?php

namespace FFLHub\Distributor;

final class DistributorRegistry
{
    /** @var class-string<DistributorBase>[] */
    private const DISTRIBUTORS = [
        \FFLHub\Distributor\RSR\DistributorRSR::class,
        \FFLHub\Distributor\Lipseys\DistributorLipseys::class,
    ];

    public static function get_distributor_classes(): array
    {
        return self::DISTRIBUTORS;
    }


    public static function get_distributor_ids(): array
    {
        $ids = [];

        foreach ( self::DISTRIBUTORS as $class ) {
            // Each distributor extends DistributorBase and must implement ::get_id()
            $ids[] = $class::get_id();
        }

        return $ids;
    } 

}