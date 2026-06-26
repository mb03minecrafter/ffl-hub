<?php

namespace FFLHub\Distributor\Services\BillHicks;

use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

final class BillHicksFtpCredentials
{
    public const EDI_OUTBOUND_REMOTE_DIR = '/DeerfordDefense/To BHC';
    public const EDI_INBOUND_REMOTE_DIR = '/DeerfordDefense/From BHC';

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
        return self::load_credentials('ftp');
    }

    /**
     * @return array{
     *   credentials:array{host:string,username:string,password:string,use_ssl:bool,port:int}|null,
     *   has_host:bool,
     *   has_username:bool,
     *   has_password:bool
     * }
     */
    public static function load_edi(): array
    {
        return self::load_credentials('edi_ftp');
    }

    public static function edi_customer_number(): string
    {
        return trim((string) Options::get_distributor_option('bill_hicks', 'edi_customer_number', ''));
    }

    public static function edi_default_ship_method(): string
    {
        $method = strtoupper(trim((string) Options::get_distributor_option('bill_hicks', 'edi_default_ship_method', 'UPSF')));

        return $method !== '' ? $method : 'UPSF';
    }

    public static function edi_order_outbound_remote_dir(): string
    {
        return self::normalize_remote_dir((string) Options::get_distributor_option('bill_hicks', 'edi_order_outbound_remote_dir', self::EDI_OUTBOUND_REMOTE_DIR));
    }

    public static function edi_ack_inbound_remote_dir(): string
    {
        return self::normalize_remote_dir((string) Options::get_distributor_option('bill_hicks', 'edi_ack_inbound_remote_dir', self::EDI_INBOUND_REMOTE_DIR));
    }

    public static function edi_asn_inbound_remote_dir(): string
    {
        return self::normalize_remote_dir((string) Options::get_distributor_option('bill_hicks', 'edi_asn_inbound_remote_dir', self::EDI_INBOUND_REMOTE_DIR));
    }

    public static function edi_inbound_remote_dir(): string
    {
        return self::normalize_remote_dir((string) Options::get_distributor_option('bill_hicks', 'edi_inbound_remote_dir', self::EDI_INBOUND_REMOTE_DIR));
    }

    /**
     * @return array{
     *   credentials:array{host:string,username:string,password:string,use_ssl:bool,port:int}|null,
     *   has_host:bool,
     *   has_username:bool,
     *   has_password:bool
     * }
     */
    private static function load_credentials(string $prefix): array
    {
        $host = trim((string) Options::get_distributor_option('bill_hicks', $prefix . '_host', ''));
        $username = trim((string) Options::get_distributor_option('bill_hicks', $prefix . '_username', ''));
        $password = trim((string) Options::get_distributor_option('bill_hicks', $prefix . '_password', ''));
        $port = max(1, (int) Options::get_distributor_option('bill_hicks', $prefix . '_port', '21'));
        $use_ssl = Options::get_distributor_option('bill_hicks', $prefix . '_use_ssl', '0') === '1';

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

    private static function normalize_remote_dir(string $dir): string
    {
        $dir = trim($dir);
        if ($dir === '') {
            return '';
        }

        return rtrim($dir, "/\\");
    }
}
