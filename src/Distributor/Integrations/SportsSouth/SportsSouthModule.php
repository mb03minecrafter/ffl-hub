<?php

namespace FFLHub\Distributor\Integrations\SportsSouth;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Services\SportsSouth\Cron\SportsSouthInventoryCronService;
use FFLHub\Distributor\Services\SportsSouth\Cron\SportsSouthProductCronService;
use FFLHub\Distributor\Services\SportsSouth\SportsSouthFulfillmentPolicy;
use FFLHub\Distributor\Services\SportsSouth\SportsSouthServices;
use FFLHub\Distributor\Services\SportsSouth\Tables\SportsSouthProductTableSchema;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * Sports South module definition.
 */
final class SportsSouthModule implements DistributorModuleInterface
{
    public function id(): string
    {
        return 'sports_south';
    }

    public function label(): string
    {
        return 'Sports South';
    }

    public function name(): string
    {
        return 'Sports South';
    }

    public function description(): string
    {
        return 'Sports South Distributor';
    }

    public function section_description(): string
    {
        return 'Sports South Distributor';
    }

    public function icon_url(): string
    {
        return '';
    }

    public function settings_schema(): array
    {
        return array_merge([
            'customer_number' => [
                'label' => 'Customer Number',
                'type' => 'text',
                'placeholder' => '99994',
                'description' => 'Sports South account/customer number.',
                'default' => '',
            ],
            'username' => [
                'label' => 'UserName',
                'type' => 'text',
                'placeholder' => '99994',
                'description' => 'Sports South web services UserName. Live usually matches your account number.',
                'default' => '',
            ],
            'password' => [
                'label' => 'Password',
                'type' => 'password',
                'placeholder' => '',
                'description' => 'Sports South web services password.',
                'default' => '',
            ],
            'source' => [
                'label' => 'Source',
                'type' => 'text',
                'placeholder' => 'Account number or provider code',
                'description' => 'Use your account number unless Sports South gave you a provider Source code.',
                'default' => '',
            ],
            'inventory_api_base_url' => [
                'label' => 'Inventory API Base URL',
                'type' => 'text',
                'placeholder' => 'https://webservices.theshootingwarehouse.com/smart/inventory.asmx',
                'description' => 'Sports South inventory ASMX endpoint.',
                'default' => 'https://webservices.theshootingwarehouse.com/smart/inventory.asmx',
            ],
            'daily_item_last_update' => [
                'label' => 'DailyItemUpdate LastUpdate',
                'type' => 'text',
                'placeholder' => '1/1/1990',
                'description' => 'Keep 1/1/1990 for a full double-buffer table rebuild. Sports South uses mm/dd/yyyy.',
                'default' => '1/1/1990',
            ],
            'daily_item_last_item' => [
                'label' => 'DailyItemUpdate LastItem',
                'type' => 'text',
                'placeholder' => '-1',
                'description' => 'Use -1 to bypass 1000-row paging and return the full DailyItemUpdate payload.',
                'default' => '-1',
            ],
            'inventory_since_datetime' => [
                'label' => 'Initial IncrementalOnhand SinceDateTime',
                'type' => 'text',
                'placeholder' => '2020-12-12T15:05:00.00+00.00',
                'description' => 'Optional first-run cursor. After the first run, FFLHub stores Sports South inventory cursor automatically.',
                'default' => '',
            ],
        ], SportsSouthFulfillmentPolicy::settings_schema_fields());
    }

    public function build_distributor(): DistributorBase
    {
        $schema = new SportsSouthProductTableSchema();

        $table = new DoubleBufferedProductTable(
            $schema,
            'fflhub_sports_south_fulfillment_last_swap'
        );

        $productCron = new SportsSouthProductCronService($table);
        $inventoryCron = new SportsSouthInventoryCronService($table);

        $services = new SportsSouthServices(
            $table,
            $productCron,
            $inventoryCron
        );

        return new DistributorSportsSouth($this, $services);
    }
}
