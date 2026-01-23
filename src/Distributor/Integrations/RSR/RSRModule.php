<?php

namespace FFLHub\Distributor\Integrations\RSR;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Contracts\DistributorModuleInterface;

use FFLHub\Distributor\Services\RSR\RSRServices;
use FFLHub\Distributor\Services\RSR\Cron\RSRFulfillmentCronService;
use FFLHub\Distributor\Services\RSR\Cron\RSRInventoryCronService;
use FFLHub\Distributor\Services\RSR\Tables\RSRFulfillmentSchema;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;

final class RSRModule implements DistributorModuleInterface
{
    public function id(): string
    {
        return 'rsr';
    }

    public function label(): string
    {
        return 'RSR';
    }

    public function name(): string
    {
        return 'RSR Group';
    }

    public function description(): string
    {
        return 'RSR Group Distributor';
    }

    public function section_description(): string
    {
        return 'RSR Group Distributor';
    }

    public function icon_url(): string
    {
        return FFLHUB_PLUGIN_URL . 'assets/icons/logo-rsr.png';
    }

    public function settings_schema(): array
    {
        return [
            'main_account_number' => [
                'label'       => 'Main Account Number',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your main RSR account number (this is your RSR username).',
                'default'     => '',
            ],
            'main_account_password' => [
                'label'       => 'Main Account Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Your RSR main account password.',
                'default'     => '',
            ],
            'pos_indicator' => [
                'label'       => 'POS Indicator',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your RSR POS indicator for API requests.',
                'default'     => '',
            ],
            'dropship_account_number' => [
                'label'       => 'Drop-Ship Account Number',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your RSR drop-ship account number (different from main).',
                'default'     => '',
            ],
            'dropship_account_password' => [
                'label'       => 'Drop-Ship Account Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Password for the drop-ship account.',
                'default'     => '',
            ],
            'ftp_host' => [
                'label'       => 'FTP Host',
                'type'        => 'text',
                'placeholder' => 'ftps.rsrgroup.com',
                'description' => 'Hostname for the RSR FTP server.',
                'default'     => '',
            ],
            'ftp_username' => [
                'label'       => 'FTP Username',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your RSR FTP username.',
                'default'     => '',
            ],
            'ftp_password' => [
                'label'       => 'FTP Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Your RSR FTP password.',
                'default'     => '',
            ],
            'ftp_use_ssl' => [
                'label'       => 'Use FTPS (SSL)',
                'type'        => 'checkbox',
                'description' => 'Connect using FTPS/SSL (recommended).',
                'default'     => '1',
            ],
        ];
    }


    public function build_distributor(): DistributorBase
    {
        $schema = new RSRFulfillmentSchema();

        $table = new DoubleBufferedFulfillmentTable(
            $schema,
            'fflhub_rsr_fulfillment_last_swap'
        );

        $fulfillmentCron = new RSRFulfillmentCronService($table);
        $inventoryCron   = new RSRInventoryCronService($table);

        $services = new RSRServices(
            $table,
            $fulfillmentCron,
            $inventoryCron
        );

        return new DistributorRSR($this, $services);
    }
}
