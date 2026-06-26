<?php

namespace FFLHub\Distributor\Services\BillHicks;

use FFLHub\Distributor\Services\FTP\FTPClientService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * FTP transport for Bill Hicks simplified EDI files.
 *
 * The feed and EDI exchange use the same HostedFTP credentials. Only the
 * remote folders differ:
 * - 850 uploads: /DeerfordDefense/To BHC
 * - 855/856 downloads: /DeerfordDefense/From BHC
 */
final class BillHicksEdiFtpExchange
{
    /**
     * Upload a generated 850 order file.
     *
     * @return array{ok:bool,remote_path:string,error:string}
     */
    public function upload_order_file(string $local_path, string $filename): array
    {
        $filename = basename(trim($filename));
        $remote_dir = BillHicksFtpCredentials::edi_order_outbound_remote_dir();
        $remote_path = $this->join_remote_path($remote_dir, $filename);

        if ($filename === '' || $remote_path === '') {
            return ['ok' => false, 'remote_path' => '', 'error' => 'Missing Bill Hicks EDI filename or outbound directory.'];
        }

        $client = $this->client();
        if (!$client instanceof FTPClientService) {
            return ['ok' => false, 'remote_path' => $remote_path, 'error' => 'Missing or invalid Bill Hicks FTP credentials.'];
        }

        $ok = $client->upload_file($local_path, $remote_path, true);
        $error = $ok ? '' : (string) ($client->get_last_error() ?: 'Bill Hicks EDI FTP upload failed.');
        $client->close();

        return ['ok' => (bool) $ok, 'remote_path' => $remote_path, 'error' => $error];
    }

    /**
     * List inbound 855/856 files from the shared Bill Hicks inbound folder.
     *
     * @return string[]
     */
    public function list_inbound_files(): array
    {
        $remote_dir = BillHicksFtpCredentials::edi_inbound_remote_dir();
        if ($remote_dir === '') {
            return [];
        }

        $client = $this->client();
        if (!$client instanceof FTPClientService) {
            return [];
        }

        $files = $client->list_files($remote_dir);
        $client->close();

        $out = [];
        foreach ($files as $file) {
            $file = trim((string) $file);
            if ($file === '') {
                continue;
            }
            $out[] = $this->looks_like_full_remote_path($file)
                ? $file
                : $this->join_remote_path($remote_dir, $file);
        }

        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * Download an inbound file to a local directory.
     *
     * @return array{ok:bool,local_path:string,mtime:int,size:int,error:string}
     */
    public function download_inbound_file(string $remote_path, string $local_dir): array
    {
        $remote_path = trim($remote_path);
        $local_dir = rtrim(trim($local_dir), "/\\");
        $filename = basename($remote_path);

        if ($remote_path === '' || $local_dir === '' || $filename === '') {
            return ['ok' => false, 'local_path' => '', 'mtime' => 0, 'size' => -1, 'error' => 'Missing Bill Hicks inbound remote path or local directory.'];
        }

        if (function_exists('wp_mkdir_p') && !wp_mkdir_p($local_dir)) {
            return ['ok' => false, 'local_path' => '', 'mtime' => 0, 'size' => -1, 'error' => 'Unable to create local Bill Hicks inbound directory.'];
        }

        $client = $this->client();
        if (!$client instanceof FTPClientService) {
            return ['ok' => false, 'local_path' => '', 'mtime' => 0, 'size' => -1, 'error' => 'Missing or invalid Bill Hicks FTP credentials.'];
        }

        $mtime = $client->get_remote_mtime($remote_path);
        $size = $client->get_remote_size($remote_path);
        $local_path = $local_dir . DIRECTORY_SEPARATOR . $filename;
        $ok = $client->download_file($remote_path, $local_path);
        $error = $ok ? '' : (string) ($client->get_last_error() ?: 'Bill Hicks EDI FTP download failed.');
        $client->close();

        return [
            'ok' => (bool) $ok,
            'local_path' => $ok ? $local_path : '',
            'mtime' => (int) $mtime,
            'size' => (int) $size,
            'error' => $error,
        ];
    }

    public function remote_mtime(string $remote_path): int
    {
        $client = $this->client();
        if (!$client instanceof FTPClientService) {
            return 0;
        }

        $mtime = $client->get_remote_mtime($remote_path);
        $client->close();
        return (int) $mtime;
    }

    public function remote_size(string $remote_path): int
    {
        $client = $this->client();
        if (!$client instanceof FTPClientService) {
            return -1;
        }

        $size = $client->get_remote_size($remote_path);
        $client->close();
        return (int) $size;
    }

    private function client(): ?FTPClientService
    {
        $loaded = BillHicksFtpCredentials::load();
        $creds = is_array($loaded['credentials'] ?? null) ? $loaded['credentials'] : null;
        if (!is_array($creds)) {
            return null;
        }

        $client = new FTPClientService(
            (string) ($creds['host'] ?? ''),
            (string) ($creds['username'] ?? ''),
            (string) ($creds['password'] ?? ''),
            (bool) ($creds['use_ssl'] ?? false),
            (int) ($creds['port'] ?? 21),
            60,
            true,
            '[FFLHub][BillHicks][EDI][FTP]'
        );

        return $client->is_connected() ? $client : null;
    }

    private function join_remote_path(string $dir, string $file): string
    {
        $dir = rtrim(trim($dir), '/');
        $file = ltrim(trim($file), '/');

        if ($dir === '' || $file === '') {
            return '';
        }

        return $dir . '/' . $file;
    }

    private function looks_like_full_remote_path(string $path): bool
    {
        return strpos($path, '/') !== false || strpos($path, '\\') !== false;
    }
}
