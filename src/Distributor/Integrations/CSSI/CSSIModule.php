<?php

namespace FFLHub\Distributor\Integrations\CSSI;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Services\CSSI\Cron\CSSIInventoryCronService;
use FFLHub\Distributor\Services\CSSI\Cron\CSSIProductCronService;
use FFLHub\Distributor\Services\CSSI\CSSIServices;
use FFLHub\Distributor\Services\CSSI\Tables\CSSIProductTableSchema;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * Chattanooga Shooting Supplies (CSSI) module definition.
 */
final class CSSIModule implements DistributorModuleInterface
{
    public function id(): string
    {
        return 'cssi';
    }

    public function label(): string
    {
        return 'CSSI';
    }

    public function name(): string
    {
        return 'Chattanooga Shooting Supplies';
    }

    public function description(): string
    {
        return 'Chattanooga Shooting Supplies Distributor';
    }

    public function section_description(): string
    {
        return 'Chattanooga Shooting Supplies Distributor';
    }

    public function icon_url(): string
    {
        return '';
    }

    public function settings_schema(): array
    {
        return [
            'sid' => [
                'label'       => 'SID',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your Chattanooga Shooting Supplies REST API SID.',
                'default'     => '',
            ],
            'token' => [
                'label'       => 'Token',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Your Chattanooga Shooting Supplies REST API token.',
                'default'     => '',
            ],
        ];
    }

    public function build_distributor(): DistributorBase
    {
        $schema = new CSSIProductTableSchema();

        $table = new DoubleBufferedProductTable(
            $schema,
            'fflhub_cssi_fulfillment_last_swap'
        );

        $productCron = new CSSIProductCronService($table);
        $inventoryCron = new CSSIInventoryCronService($table);

        $services = new CSSIServices(
            $table,
            $productCron,
            $inventoryCron
        );

        return new DistributorCSSI($this, $services);
    }
}
