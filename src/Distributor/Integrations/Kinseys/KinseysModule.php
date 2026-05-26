<?php

namespace FFLHub\Distributor\Integrations\Kinseys;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Services\Kinseys\Cron\KinseysInventoryCronService;
use FFLHub\Distributor\Services\Kinseys\Cron\KinseysProductCronService;
use FFLHub\Distributor\Services\Kinseys\KinseysServices;
use FFLHub\Distributor\Services\Kinseys\Tables\KinseysProductTableSchema;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * Kinsey's module definition.
 */
final class KinseysModule implements DistributorModuleInterface
{
    public function id(): string
    {
        return 'kinseys';
    }

    public function label(): string
    {
        return 'Kinsey\'s';
    }

    public function name(): string
    {
        return 'Kinsey\'s';
    }

    public function description(): string
    {
        return 'Kinsey\'s Distributor';
    }

    public function section_description(): string
    {
        return 'Kinsey\'s Customer API v2. Product catalog refreshes daily by default; inventory refreshes hourly because the full inventory endpoint is documented as a very large file.';
    }

    public function icon_url(): string
    {
        return '';
    }

    public function settings_schema(): array
    {
        return [
            'api_identifier' => [
                'label' => 'API Identifier',
                'type' => 'text',
                'placeholder' => '',
                'description' => 'Your Kinsey\'s dealer API Identifier. Kinsey\'s requires this value with every query.',
                'default' => '',
            ],
            'api_key' => [
                'label' => 'API Key',
                'type' => 'password',
                'placeholder' => '',
                'description' => 'Your Kinsey\'s API key sent as the X-API-KEY header.',
                'default' => '',
            ],
            'source' => [
                'label' => 'Kinsey-Source',
                'type' => 'text',
                'placeholder' => 'FFLHub',
                'description' => 'Optional source label Kinsey\'s uses for troubleshooting.',
                'default' => 'FFLHub',
            ],
        ];
    }

    public function build_distributor(): DistributorBase
    {
        $schema = new KinseysProductTableSchema();

        $table = new DoubleBufferedProductTable(
            $schema,
            'fflhub_kinseys_fulfillment_last_swap'
        );

        $productCron = new KinseysProductCronService($table);
        $inventoryCron = new KinseysInventoryCronService($table);

        $services = new KinseysServices(
            $table,
            $productCron,
            $inventoryCron
        );

        return new DistributorKinseys($this, $services);
    }
}
