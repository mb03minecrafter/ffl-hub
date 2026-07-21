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
            'dealer_batch_enabled' => [
                'label'       => 'Enable CSSI Dealer Batch Queue',
                'type'        => 'checkbox',
                'description' => 'When enabled, CSSI dealer-fulfilled rows are held in a batch queue instead of placing immediately.',
                'default'     => '1',
            ],
            'dealer_batch_dispatch_time' => [
                'label'       => 'CSSI Dealer Batch Dispatch Time',
                'type'        => 'text',
                'placeholder' => '17:00',
                'description' => 'Weekday local dispatch time in 24-hour HH:MM format. Dealer batches are held on Saturdays and Sundays. Example: 17:00.',
                'default'     => '17:00',
            ],
            'dealer_batch_low_stock_threshold' => [
                'label'       => 'CSSI Dealer Batch Low-Stock Threshold',
                'type'        => 'number',
                'placeholder' => '3',
                'description' => 'Rows containing low-stock UPCs at or below this threshold are placed immediately instead of waiting for the normal batch window.',
                'default'     => '3',
                'min'         => 0,
                'step'        => 1,
            ],
            'dealer_batch_retry_delay_seconds' => [
                'label'       => 'CSSI Dealer Batch Retry Delay Seconds',
                'type'        => 'number',
                'placeholder' => '300',
                'description' => 'Delay before retrying a failed CSSI aggregate batch call.',
                'default'     => '300',
                'min'         => 30,
                'step'        => 1,
            ],
            'dealer_batch_max_rows_per_run' => [
                'label'       => 'CSSI Dealer Batch Max Rows Per Run',
                'type'        => 'number',
                'placeholder' => '200',
                'description' => 'Maximum queued CSSI dealer rows to evaluate in one batch cron execution.',
                'default'     => '200',
                'min'         => 1,
                'step'        => 1,
            ],
            'dealer_batch_force_flush' => [
                'label'       => 'Force CSSI Dealer Batch Flush On Next Run',
                'type'        => 'checkbox',
                'description' => 'If enabled, the next CSSI batch cron run flushes queued dealer rows immediately, bypasses timing holds, and then auto-resets this toggle.',
                'default'     => '0',
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
