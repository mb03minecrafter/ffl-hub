<?php

namespace FFLHub\Distributor\Services\FTP;

use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal FTP/FTPS client (NOT SFTP) for vendor downloads.
 *
 * Supports:
 *  - ftp_connect / ftp_ssl_connect
 *  - get_remote_mtime() (MDTM)
 *  - get_remote_size() (SIZE)
 *  - list_files() (NLST)
 *  - download_file()
 *  - upload_file()
 *  - download_zip_file() (ZipArchive only)
 */
class FTPClientService
{
    /**
     * @var resource|\FTP\Connection|null
     */
    private $conn = null;

    /**
     * @var string|null
     */
    private $last_error = null;

    /**
     * @var string
     */
    private $host = '';

    /**
     * @var string
     */
    private $username = '';

    /**
     * @var string
     */
    private $password = '';

    /**
     * @var bool
     */
    private $use_ssl = true;

    /**
     * @var int
     */
    private $port = 21;

    /**
     * @var int
     */
    private $timeout = 30;

    /**
     * @var bool
     */
    private $passive = true;

    /**
     * Whether to trust PASV server-provided host for data channel.
     *
     * Some servers behind NAT return private RFC1918 addresses in PASV replies.
     * Setting this false forces reuse of the control-connection host.
     *
     * @var bool
     */
    private $use_pasv_address = true;

    /**
     * @var string
     */
    private $log_prefix = '[FFLHub][FTP]';

    /**
     * Constructor. Establishes FTP/FTPS connection immediately.
     *
     * @param string $host
     * @param string $username
     * @param string $password
     * @param bool   $use_ssl
     * @param int    $port
     * @param int    $timeout
     * @param bool   $passive
     * @param string $log_prefix
     * @param bool   $use_pasv_address
     */
    public function __construct(
        string $host,
        string $username,
        string $password,
        bool $use_ssl = true,
        int $port = 21,
        int $timeout = 30,
        bool $passive = true,
        string $log_prefix = '[FFLHub][FTP]',
        bool $use_pasv_address = true
    ) {
        $this->host       = $host;
        $this->username   = $username;
        $this->password   = $password;
        $this->use_ssl    = $use_ssl;
        $this->port       = $port;
        $this->timeout    = $timeout;
        $this->passive    = $passive;
        $this->use_pasv_address = $use_pasv_address;
        $this->log_prefix = $log_prefix !== '' ? $log_prefix : '[FFLHub][FTP]';

        if ($host === '' || $username === '' || $password === '') {
            $this->set_error('Empty host/username/password passed to FTP service constructor.');
            $this->log_debug($this->log_prefix . ' empty host/username/password passed to constructor.');
            return;
        }

        $conn = null;

        if ($use_ssl && function_exists('ftp_ssl_connect')) {
            $conn = @ftp_ssl_connect($host, $port, $timeout);
        } else {
            $conn = @ftp_connect($host, $port, $timeout);
        }

        if (!$conn) {
            $this->set_error('Could not connect to host ' . $host);
            $this->log_debug($this->log_prefix . ' could not connect to host ' . $host);
            return;
        }

        $logged_in = @ftp_login($conn, $username, $password);
        if (!$logged_in) {
            $this->set_error('Login failed for user ' . $username);
            $this->log_debug($this->log_prefix . ' login failed for user ' . $username);
            @ftp_close($conn);
            return;
        }

        if ($this->passive) {
            // When supported, control whether PASV should trust server-provided host.
            // For NATed FTP servers this is often required to avoid data-channel timeouts.
            if (function_exists('ftp_set_option') && defined('FTP_USEPASVADDRESS')) {
                $option = constant('FTP_USEPASVADDRESS');
                if (!@ftp_set_option($conn, $option, $this->use_pasv_address)) {
                    $this->log_debug(
                        sprintf(
                            '%s failed to set FTP_USEPASVADDRESS=%d',
                            $this->log_prefix,
                            $this->use_pasv_address ? 1 : 0
                        )
                    );
                }
            }

            if (!@ftp_pasv($conn, true)) {
                $this->log_debug($this->log_prefix . ' failed to enable passive mode.');
            }
        }

        $this->conn = $conn;
    }

    public function __destruct()
    {
        $this->close_internal();
    }

    public function is_connected(): bool
    {
        return (bool) $this->conn;
    }

    public function get_remote_mtime(string $remote_path): int
    {
        if (!$this->is_connected()) {
            return 0;
        }

        /** @var \FTP\Connection|resource $connection */
        $connection = $this->conn;

        $t = @ftp_mdtm($connection, $remote_path);
        return (is_int($t) && $t > 0) ? $t : 0;
    }

    public function get_remote_size(string $remote_path): int
    {
        if (!$this->is_connected()) {
            return -1;
        }

        /** @var \FTP\Connection|resource $connection */
        $connection = $this->conn;

        $s = @ftp_size($connection, $remote_path);
        return (is_int($s) && $s >= 0) ? $s : -1;
    }

    /**
     * List file names/paths under a remote directory.
     *
     * @return string[]
     */
    public function list_files(string $remote_dir): array
    {
        if (!$this->is_connected()) {
            $this->set_error('list_files() called but FTP connection is not available.');
            $this->log_debug($this->log_prefix . ' list_files() called but FTP connection is not available.');
            return [];
        }

        $remote_dir = trim($remote_dir);
        if ($remote_dir === '') {
            $this->set_error('list_files() requires a remote directory.');
            $this->log_debug($this->log_prefix . ' list_files() missing remote directory.');
            return [];
        }

        /** @var \FTP\Connection|resource $connection */
        $connection = $this->conn;

        $files = @ftp_nlist($connection, $remote_dir);
        if (!is_array($files)) {
            $this->set_error(sprintf('ftp_nlist failed for remote directory %s', $remote_dir));
            $this->log_debug($this->log_prefix . ' ftp_nlist failed for remote directory ' . $remote_dir);
            return [];
        }

        $out = [];
        foreach ($files as $file) {
            $file = trim((string) $file);
            if ($file !== '' && $file !== '.' && $file !== '..') {
                $out[] = $file;
            }
        }

        return $out;
    }

    public function download_file(string $remote_path, string $local_path): bool
    {
        if (!$this->is_connected()) {
            $this->set_error('download_file() called but FTP connection is not available.');
            $this->log_debug($this->log_prefix . ' download_file() called but FTP connection is not available.');
            return false;
        }

        return $this->download_file_internal($remote_path, $local_path);
    }

    public function upload_file(string $local_path, string $remote_path, bool $use_temp_file = true): bool
    {
        if (!$this->is_connected()) {
            $this->set_error('upload_file() called but FTP connection is not available.');
            $this->log_debug($this->log_prefix . ' upload_file() called but FTP connection is not available.');
            return false;
        }

        return $this->upload_file_internal($local_path, $remote_path, $use_temp_file);
    }

    public function download_zip_file(
        string $remote_path,
        string $local_zip_path,
        string $extract_to_dir = '',
        bool $delete_zip_after = true
    ): bool {
        if (!$this->is_connected()) {
            $this->set_error('download_zip_file() called but FTP connection is not available.');
            $this->log_debug($this->log_prefix . ' download_zip_file() called but FTP connection is not available.');
            return false;
        }

        return $this->download_zip_file_internal(
            $remote_path,
            $local_zip_path,
            $extract_to_dir,
            $delete_zip_after
        );
    }

    public function get_last_error(): ?string
    {
        return $this->last_error;
    }

    public function close(): void
    {
        $this->close_internal();
    }

    private function download_file_internal(string $remote_path, string $local_path): bool
    {
        $dir = dirname($local_path);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            $this->set_error('Failed to create directory ' . $dir);
            $this->log_debug($this->log_prefix . ' failed to create directory ' . $dir);
            return false;
        }

        $tmp_path = $local_path . '.tmp';

        $start = microtime(true);

        /** @var \FTP\Connection|resource $connection */
        $connection = $this->conn;

        $success = @ftp_get($connection, $tmp_path, $remote_path, FTP_BINARY);

        $elapsed = microtime(true) - $start;

        if (!$success) {
            @unlink($tmp_path);
            $this->set_error(sprintf('ftp_get failed for remote %s', $remote_path));
            $this->log_debug(
                sprintf(
                    '%s ftp_get failed for remote %s (host %s, user %s)',
                    $this->log_prefix,
                    $remote_path,
                    $this->host,
                    $this->username
                )
            );
            return false;
        }

        if (file_exists($tmp_path)) {
            $size_bytes = (int) filesize($tmp_path);
            $size_mb    = $size_bytes / 1048576;
            $mbps       = $size_mb / max($elapsed, 0.000001);

            $this->log_debug(
                sprintf(
                    '%s downloaded %.2f MB in %.2f s (%.2f MB/s) from %s',
                    $this->log_prefix,
                    $size_mb,
                    $elapsed,
                    $mbps,
                    $remote_path
                )
            );
        }

        if (!@rename($tmp_path, $local_path)) {
            @unlink($tmp_path);
            $this->set_error('Failed to rename tmp file to ' . $local_path);
            $this->log_debug($this->log_prefix . ' failed to rename tmp file to ' . $local_path);
            return false;
        }

        return true;
    }

    private function upload_file_internal(string $local_path, string $remote_path, bool $use_temp_file): bool
    {
        $local_path = trim($local_path);
        $remote_path = trim($remote_path);

        if ($local_path === '' || $remote_path === '') {
            $this->set_error('upload_file() requires a local path and remote path.');
            $this->log_debug($this->log_prefix . ' upload_file() missing local or remote path.');
            return false;
        }

        if (!is_file($local_path) || !is_readable($local_path)) {
            $this->set_error('Local upload file is missing or unreadable: ' . $local_path);
            $this->log_debug($this->log_prefix . ' local upload file missing or unreadable: ' . $local_path);
            return false;
        }

        $target_path = $use_temp_file ? $remote_path . '.tmp' : $remote_path;
        $start = microtime(true);

        /** @var \FTP\Connection|resource $connection */
        $connection = $this->conn;

        $success = @ftp_put($connection, $target_path, $local_path, FTP_BINARY);
        $elapsed = microtime(true) - $start;

        if (!$success) {
            $this->set_error(sprintf('ftp_put failed for remote %s', $target_path));
            $this->log_debug(
                sprintf(
                    '%s ftp_put failed for local %s -> remote %s (host %s, user %s)',
                    $this->log_prefix,
                    $local_path,
                    $target_path,
                    $this->host,
                    $this->username
                )
            );
            return false;
        }

        if ($use_temp_file && !@ftp_rename($connection, $target_path, $remote_path)) {
            @ftp_delete($connection, $target_path);
            $this->set_error(sprintf('ftp_rename failed for remote %s -> %s', $target_path, $remote_path));
            $this->log_debug(
                sprintf(
                    '%s ftp_rename failed for uploaded temp file %s -> %s',
                    $this->log_prefix,
                    $target_path,
                    $remote_path
                )
            );
            return false;
        }

        $size_bytes = (int) filesize($local_path);
        $size_mb = $size_bytes / 1048576;
        $mbps = $size_mb / max($elapsed, 0.000001);

        $this->log_debug(
            sprintf(
                '%s uploaded %.2f MB in %.2f s (%.2f MB/s) to %s',
                $this->log_prefix,
                $size_mb,
                $elapsed,
                $mbps,
                $remote_path
            )
        );

        return true;
    }

    private function download_zip_file_internal(
        string $remote_path,
        string $local_zip_path,
        string $extract_to_dir,
        bool $delete_zip_after
    ): bool {
        if (!$this->download_file_internal($remote_path, $local_zip_path)) {
            return false;
        }

        if ($extract_to_dir === '') {
            $extract_to_dir = dirname($local_zip_path);
        }

        if (!is_dir($extract_to_dir) && !wp_mkdir_p($extract_to_dir)) {
            $this->set_error('Failed to create extract directory ' . $extract_to_dir);
            $this->log_debug($this->log_prefix . ' failed to create extract directory ' . $extract_to_dir);
            return false;
        }

        if (!file_exists($local_zip_path)) {
            $this->set_error('ZIP file does not exist at ' . $local_zip_path);
            $this->log_debug($this->log_prefix . ' ZIP file missing at ' . $local_zip_path);
            return false;
        }

        if (!class_exists(\ZipArchive::class)) {
            $this->set_error('ZipArchive class not available; cannot unzip.');
            $this->log_debug($this->log_prefix . ' ZipArchive not available for ' . $local_zip_path);
            return false;
        }

        $zip         = new \ZipArchive();
        $open_result = $zip->open($local_zip_path);

        if ($open_result !== true) {
            $this->set_error('ZipArchive::open() failed with code ' . $open_result);
            $this->log_debug(
                $this->log_prefix . ' ZipArchive::open() failed for ' . $local_zip_path . ' (code ' . $open_result . ')'
            );
            return false;
        }

        if (!$zip->extractTo($extract_to_dir)) {
            $this->set_error('ZipArchive::extractTo() failed.');
            $this->log_debug(
                $this->log_prefix . ' ZipArchive::extractTo() failed for ' . $local_zip_path . ' -> ' . $extract_to_dir
            );
            $zip->close();
            return false;
        }

        $zip->close();

        if ($delete_zip_after) {
            @unlink($local_zip_path);
        }

        return true;
    }

    private function close_internal(): void
    {
        if ($this->conn) {
            /** @var \FTP\Connection|resource $connection */
            $connection = $this->conn;

            @ftp_close($connection);
            $this->conn = null;
        }
    }

    private function set_error(string $message): void
    {
        $this->last_error = $message;
    }

    private function log_debug(string $message): void
    {
        $prefix = $this->log_prefix !== '' ? $this->log_prefix : '[FFLHub][FTP]';
        $msg = trim($message);

        if (strpos($msg, $prefix) === 0) {
            $msg = ltrim(substr($msg, strlen($prefix)));
        }

        if ($msg === '') {
            $msg = '(empty message)';
        }

        DebugLogUtil::log('FFLHUB_CRON_DEBUG', $prefix, $msg);
    }
}
