<?php

namespace FFLHub\Distributor\Integrations\BillHicks;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Services\BillHicks\BillHicksFulfillmentPolicy;
use FFLHub\Distributor\Services\BillHicks\BillHicksServices;
use FFLHub\Distributor\Services\BillHicks\Cron\BillHicksInventoryCronService;
use FFLHub\Distributor\Services\BillHicks\Cron\BillHicksProductCronService;
use FFLHub\Distributor\Services\BillHicks\Tables\BillHicksProductTableSchema;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * Bill Hicks module scaffold.
 *
 * This only wires settings, the double-buffered product table, and empty cron
 * shells. The actual feed parser/importer will be added once we lock down the
 * catalog and inventory file formats.
 */
final class BillHicksModule implements DistributorModuleInterface
{
    public function id(): string
    {
        return 'bill_hicks';
    }

    public function label(): string
    {
        return 'Bill Hicks';
    }

    public function name(): string
    {
        return 'Bill Hicks & Co.';
    }

    public function description(): string
    {
        return 'Bill Hicks distributor integration.';
    }

    public function section_description(): string
    {
        return 'Bill Hicks & Co. Distributor';
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
                'placeholder' => '',
                'description' => 'Hostname for the Bill Hicks FTP/FTPS server.',
                'default'     => '',
            ],
            'ftp_port' => [
                'label'       => 'FTP Port',
                'type'        => 'text',
                'placeholder' => '21',
                'description' => 'Port for Bill Hicks FTP/FTPS connections.',
                'default'     => '21',
            ],
            'ftp_username' => [
                'label'       => 'FTP Username',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your Bill Hicks FTP username.',
                'default'     => '',
            ],
            'ftp_password' => [
                'label'       => 'FTP Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Your Bill Hicks FTP password.',
                'default'     => '',
            ],
            'ftp_use_ssl' => [
                'label'       => 'Use FTPS (SSL)',
                'type'        => 'checkbox',
                'description' => 'Connect using FTPS/SSL when Bill Hicks provides TLS-enabled FTP access.',
                'default'     => '0',
            ],
            'product_feed_remote_path' => [
                'label'       => 'Product Feed Remote Path',
                'type'        => 'text',
                'placeholder' => '/DeerfordDefense/Feeds/BHC_Catalog.csv',
                'description' => 'Remote path for the full Bill Hicks catalog/product feed.',
                'default'     => '/DeerfordDefense/Feeds/BHC_Catalog.csv',
            ],
            'inventory_feed_remote_path' => [
                'label'       => 'Inventory Feed Remote Path',
                'type'        => 'text',
                'placeholder' => '/DeerfordDefense/Feeds/BHC_inventory.csv',
                'description' => 'Remote path for the Bill Hicks inventory/pricing feed.',
                'default'     => '/DeerfordDefense/Feeds/BHC_inventory.csv',
            ],
        ] + BillHicksFulfillmentPolicy::settings_schema_fields();
    }

    public function build_distributor(): DistributorBase
    {
        $schema = new BillHicksProductTableSchema();

        $table = new DoubleBufferedProductTable(
            $schema,
            'fflhub_bill_hicks_fulfillment_last_swap'
        );

        $product_cron = new BillHicksProductCronService($table);
        $inventory_cron = new BillHicksInventoryCronService($table);

        $services = new BillHicksServices(
            $table,
            $product_cron,
            $inventory_cron
        );

        return new DistributorBillHicks($this, $services);
    }
}
