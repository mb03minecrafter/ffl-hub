<?php

namespace FFLHub\Distributor\Integrations\Davidsons;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Services\Davidsons\Cron\DavidsonsInventoryCronService;
use FFLHub\Distributor\Services\Davidsons\Cron\DavidsonsProductCronService;
use FFLHub\Distributor\Services\Davidsons\DavidsonsServices;
use FFLHub\Distributor\Services\Davidsons\Tables\DavidsonsProductTableSchema;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * Davidson's module definition.
 */
final class DavidsonsModule implements DistributorModuleInterface
{
    public function id(): string
    {
        return 'davidsons';
    }

    public function label(): string
    {
        return "Davidson's";
    }

    public function name(): string
    {
        return "Davidson's";
    }

    public function description(): string
    {
        return "Davidson's Distributor";
    }

    public function section_description(): string
    {
        return "Davidson's Distributor";
    }

    public function icon_url(): string
    {
        return '';
    }

    public function settings_schema(): array
    {
        return [
            'portal_username' => [
                'label'       => 'Portal Username',
                'type'        => 'text',
                'placeholder' => '',
                'description' => "Your Davidson's website username/email used to download the inventory CSV.",
                'default'     => '',
            ],
            'portal_password' => [
                'label'       => 'Portal Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => "Your Davidson's website password used to download the inventory CSV.",
                'default'     => '',
            ],
        ];
    }

    public function build_distributor(): DistributorBase
    {
        $schema = new DavidsonsProductTableSchema();

        $table = new DoubleBufferedProductTable(
            $schema,
            'fflhub_davidsons_fulfillment_last_swap'
        );

        $fulfillmentCron = new DavidsonsProductCronService($table);
        $inventoryCron = new DavidsonsInventoryCronService($table);

        $services = new DavidsonsServices(
            $table,
            $fulfillmentCron,
            $inventoryCron
        );

        return new DistributorDavidsons($this, $services);
    }
}
