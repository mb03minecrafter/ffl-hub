<?php

namespace FFLHub\Distributor\Services\Zanders;

use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

final class ZandersFtpCredentials
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
        $host     = trim((string) Options::get_distributor_option('zanders', 'ftp_host', ''));
        $username = trim((string) Options::get_distributor_option('zanders', 'ftp_username', ''));
        $password = trim((string) Options::get_distributor_option('zanders', 'ftp_password', ''));

        $hasHost = ($host !== '');
        $hasUsername = ($username !== '');
        $hasPassword = ($password !== '');

        if (!$hasHost || !$hasUsername || !$hasPassword) {
            return [
                'credentials'  => null,
                'has_host'     => $hasHost,
                'has_username' => $hasUsername,
                'has_password' => $hasPassword,
            ];
        }

        return [
            'credentials' => [
                'host'     => $host,
                'username' => $username,
                'password' => $password,
                'use_ssl'  => false,
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
