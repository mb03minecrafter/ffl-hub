<?php

namespace FFLHub\Distributor\Integrations\Davidsons;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Services\Davidsons\DavidsonsServices;
use FFLHub\Distributor\Services\Davidsons\Tables\DavidsonsProductTableSchema;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * Davidson's module definition.
 *
 * For now this module only exposes metadata + enable/disable support
 * and intentionally has no credentials/settings fields.
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
        return [];
    }

    public function build_distributor(): DistributorBase
    {
        $schema = new DavidsonsProductTableSchema();

        $table = new DoubleBufferedProductTable(
            $schema,
            'fflhub_davidsons_fulfillment_last_swap'
        );

        $services = new DavidsonsServices($table);

        return new DistributorDavidsons($this, $services);
    }
}
