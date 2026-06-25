<?php

namespace FFLHub\Distributor\Services\BillHicks;

use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

final class BillHicksFtpCredentials
{
    /**
     * @return array{
     *   credentials:array{host:string,username:string,password:string,use_ssl:bool,port:int}|null,
     *   has_host:bool,
     *   has_username:bool,
     *   has_password:bool
     * }
     */
    public static function load(): array
    {
        $host = trim((string) Options::get_distributor_option('bill_hicks', 'ftp_host', ''));
        $username = trim((string) Options::get_distributor_option('bill_hicks', 'ftp_username', ''));
        $password = trim((string) Options::get_distributor_option('bill_hicks', 'ftp_password', ''));
        $port = max(1, (int) Options::get_distributor_option('bill_hicks', 'ftp_port', '21'));
        $use_ssl = Options::get_distributor_option('bill_hicks', 'ftp_use_ssl', '0') === '1';

        $has_host = $host !== '';
        $has_username = $username !== '';
        $has_password = $password !== '';

        if (!$has_host || !$has_username || !$has_password) {
            return [
                'credentials' => null,
                'has_host' => $has_host,
                'has_username' => $has_username,
                'has_password' => $has_password,
            ];
        }

        return [
            'credentials' => [
                'host' => $host,
                'username' => $username,
                'password' => $password,
                'use_ssl' => $use_ssl,
                'port' => $port,
            ],
            'has_host' => true,
            'has_username' => true,
            'has_password' => true,
        ];
    }
}
