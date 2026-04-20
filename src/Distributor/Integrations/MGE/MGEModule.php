<?php

namespace FFLHub\Distributor\Integrations\MGE;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Services\MGE\Cron\MGEInventoryCronService;
use FFLHub\Distributor\Services\MGE\Cron\MGEProductCronService;
use FFLHub\Distributor\Services\MGE\MGEServices;
use FFLHub\Distributor\Services\MGE\Tables\MGEProductTableSchema;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * MGE Wholesale module scaffold.
 *
 * This registers MGE as a first-class distributor so settings/state can be
 * managed in the same schema-driven flow as other distributors.
 */
final class MGEModule implements DistributorModuleInterface
{
    public function id(): string
    {
        return 'mge';
    }

    public function label(): string
    {
        return 'MGE';
    }

    public function name(): string
    {
        return 'MGE Wholesale';
    }

    public function description(): string
    {
        return 'MGE Wholesale Distributor';
    }

    public function section_description(): string
    {
        return 'MGE Wholesale Distributor';
    }

    public function icon_url(): string
    {
        return '';
    }

    public function settings_schema(): array
    {
        return [
            'ftp_host' => [
                'label'       => 'FTP Host',
                'type'        => 'text',
                'placeholder' => 'ftp.mgegroup.com',
                'description' => 'Hostname for the MGE FTP/FTPS server.',
                'default'     => 'ftp.mgegroup.com',
            ],
            'ftp_port' => [
                'label'       => 'FTP Port',
                'type'        => 'text',
                'placeholder' => '21',
                'description' => 'Port for MGE FTP/FTPS connections (typically 21).',
                'default'     => '21',
            ],
            'ftp_username' => [
                'label'       => 'FTP Username',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your MGE FTP username.',
                'default'     => '',
            ],
            'ftp_password' => [
                'label'       => 'FTP Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Your MGE FTP password.',
                'default'     => '',
            ],
            'ftp_use_ssl' => [
                'label'       => 'Use FTPS (SSL)',
                'type'        => 'checkbox',
                'description' => 'Connect using FTPS/SSL (AUTH TLS on port 21).',
                'default'     => '1',
            ],
            'full_feed_remote_path' => [
                'label'       => 'Full Feed Remote Path',
                'type'        => 'text',
                'placeholder' => '/feeds/vendorname_items.csv',
                'description' => 'Remote path for the full MGE catalog CSV feed.',
                'default'     => '/feeds/vendorname_items.csv',
            ],
            'delta_feed_remote_path' => [
                'label'       => 'Delta Feed Remote Path',
                'type'        => 'text',
                'placeholder' => '/feeds/vendorname_cq.csv',
                'description' => 'Remote path for the MGE quantity/cost delta CSV feed.',
                'default'     => '/feeds/vendorname_cq.csv',
            ],
        ];
    }

    public function build_distributor(): DistributorBase
    {
        $schema = new MGEProductTableSchema();

        $table = new DoubleBufferedProductTable(
            $schema,
            'fflhub_mge_fulfillment_last_swap'
        );

        $fulfillmentCron = new MGEProductCronService($table);
        $inventoryCron = new MGEInventoryCronService($table);

        $services = new MGEServices(
            $table,
            $fulfillmentCron,
            $inventoryCron
        );

        return new DistributorMGE($this, $services);
    }
}
