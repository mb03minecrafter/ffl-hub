<?php

namespace FFLHub\Distributor\Integrations\Orion;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsSchema;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Distributor\Services\Orion\Cron\OrionInventoryCronService;
use FFLHub\Distributor\Services\Orion\Cron\OrionProductCronService;
use FFLHub\Distributor\Services\Orion\OrionServices;
use FFLHub\Distributor\Services\Orion\Tables\OrionProductTableSchema;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * Orion distributor module scaffold.
 *
 * Orion's published API authenticates requests with a single Connection-Key
 * header, so the initial settings surface only asks for that credential.
 */
final class OrionModule implements DistributorModuleInterface
{
    public function id(): string
    {
        return 'orion';
    }

    public function label(): string
    {
        return 'Orion';
    }

    public function name(): string
    {
        return 'Orion Wholesale';
    }

    public function description(): string
    {
        return 'Orion Wholesale Distributor';
    }

    public function section_description(): string
    {
        return 'Orion Wholesale Distributor';
    }

    public function icon_url(): string
    {
        return '';
    }

    public function settings_schema(): array
    {
        return [
            'connection_key' => [
                'label'       => 'Connection Key',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Your Orion Wholesale API connection key. Orion sends this as the Connection-Key request header.',
                'default'     => '',
            ],
            'optimized_inventory_run' => [
                'label'       => 'Optimized Inventory Run',
                'type'        => 'checkbox',
                'description' => 'Only request inventory for Orion product IDs that already exist in normalized distributor offers. Leave disabled to pull the full Orion inventory feed.',
                'default'     => '0',
            ],
        ];
    }

    public function build_distributor(): DistributorBase
    {
        $schema = new OrionProductTableSchema();

        $table = new DoubleBufferedProductTable(
            $schema,
            'fflhub_orion_fulfillment_last_swap'
        );

        $productCron = new OrionProductCronService($table);
        $inventoryCron = new OrionInventoryCronService($table);
        $orderTable = new OrderPlacementJobsTable(new OrderPlacementJobsSchema());

        $services = new OrionServices(
            $table,
            $productCron,
            $inventoryCron,
            $orderTable
        );

        return new DistributorOrion($this, $services);
    }
}
