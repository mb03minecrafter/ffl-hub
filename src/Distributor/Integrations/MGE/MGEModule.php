<?php

namespace FFLHub\Distributor\Integrations\MGE;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;

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
                'description' => 'Hostname for the MGE FTP server.',
                'default'     => '',
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
                'description' => 'Connect using FTPS/SSL when your MGE account supports it.',
                'default'     => '0',
            ],
        ];
    }

    public function build_distributor(): DistributorBase
    {
        return new DistributorMGE($this);
    }
}
