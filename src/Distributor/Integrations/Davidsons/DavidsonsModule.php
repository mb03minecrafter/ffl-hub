<?php

namespace FFLHub\Distributor\Integrations\Davidsons;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;

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
        return new DistributorDavidsons($this);
    }
}

