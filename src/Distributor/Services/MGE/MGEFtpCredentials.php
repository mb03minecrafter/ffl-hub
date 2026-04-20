<?php

namespace FFLHub\Distributor\Services\MGE;

use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared loader for MGE FTP credentials from distributor settings.
 */
final class MGEFtpCredentials
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
        $host = trim((string) Options::get_distributor_option('mge', 'ftp_host', ''));
        $username = trim((string) Options::get_distributor_option('mge', 'ftp_username', ''));
        $password = trim((string) Options::get_distributor_option('mge', 'ftp_password', ''));
        $use_ssl_raw = trim((string) Options::get_distributor_option('mge', 'ftp_use_ssl', '0'));

        $has_host = ($host !== '');
        $has_username = ($username !== '');
        $has_password = ($password !== '');

        if (!$has_host || !$has_username || !$has_password) {
            return [
                'credentials'  => null,
                'has_host'     => $has_host,
                'has_username' => $has_username,
                'has_password' => $has_password,
            ];
        }

        return [
            'credentials' => [
                'host'     => $host,
                'username' => $username,
                'password' => $password,
                'use_ssl'  => ($use_ssl_raw === '1'),
                'port'     => 21,
            ],
            'has_host'     => true,
            'has_username' => true,
            'has_password' => true,
        ];
    }

    /**
     * @return array{host:string,username:string,password:string,use_ssl:bool,port:int}|null
     */
    public static function get(): ?array
    {
        $loaded = self::load();
        return $loaded['credentials'];
    }
}
