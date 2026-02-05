<?php

namespace FFLHub\Distributor\Services\RSR;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Minimal FTP/FTPS client for talking to RSR's FTP server.
 *
 * Instance-based service. Create a new instance with host/credentials and
 * call download_file() / download_zip_file().
 */
class RSRFTPService
{

    /**
     * @var resource|\FTP\Connection|null Underlying FTP/FTPS connection.
     */
    private $conn = null;

    /**
     * @var string|null Last error message (for debugging/logging if needed).
     */
    private $last_error = null;

    /**
     * Credentials used for this connection.
     *
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
     * FTP connection settings.
     */
    private const DEFAULT_PORT    = 2222;
    private const DEFAULT_TIMEOUT = 30;

    /**
     * Constructor. Establishes FTP/FTPS connection immediately.
     *
     * @param string $host
     * @param string $username
     * @param string $password
     * @param bool   $use_ssl
     */
    public function __construct(
        string $host,
        string $username,
        string $password,
        bool $use_ssl = true
    ) {
        $this->host     = $host;
        $this->username = $username;
        $this->password = $password;
        $this->use_ssl  = $use_ssl;

        if ($host === '' || $username === '' || $password === '') {
            $this->set_error('Empty host/username/password passed to FTP service constructor.');
            $this->log_debug('[FFLHub] RSR FTP: empty host/username/password passed to constructor.');
            return;
        }

        $port    = self::DEFAULT_PORT;
        $timeout = self::DEFAULT_TIMEOUT;

        if ($use_ssl && function_exists('ftp_ssl_connect')) {
            $conn = @ftp_ssl_connect($host, $port, $timeout);
        } else {
            $conn = @ftp_connect($host, $port, $timeout);
        }

        if (! $conn) {
            $this->set_error('Could not connect to host ' . $host);
            $this->log_debug('[FFLHub] RSR FTP: could not connect to host ' . $host);
            return;
        }

        $logged_in = @ftp_login($conn, $username, $password);
        if (! $logged_in) {
            $this->set_error('Login failed for user ' . $username);
            $this->log_debug('[FFLHub] RSR FTP: login failed for user ' . $username);
            @ftp_close($conn);
            return;
        }

        // Passive mode is almost always required behind firewalls/NAT.
        if (! @ftp_pasv($conn, true)) {
            // Not fatal, but worth logging.
            $this->log_debug('[FFLHub] RSR FTP: failed to enable passive mode.');
        }

        $this->conn = $conn;
    }

    /**
     * Destructor. Ensure the FTP connection is closed when the object is destroyed.
     */
    public function __destruct()
    {
        $this->close_internal();
    }

    /**
     * Check whether the service is connected and logged in.
     *
     * @return bool
     */
    public function is_connected(): bool
    {
        return (bool) $this->conn;
    }



    /**
     * Get remote file "last modified" time (MDTM) as unix timestamp.
     * Returns 0 if not supported/unknown.
     */
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

    /**
     * Get remote file size (bytes).
     * Returns -1 if not supported/unknown.
     */
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
     * Public API: download a file from RSR FTP to a local path.
     *
     * @param string $remote_path Remote path on the RSR FTP server.
     * @param string $local_path  Absolute local filesystem path to save to.
     * @return bool True on success, false on failure.
     */
    public function download_file(string $remote_path, string $local_path): bool
    {
        if (! $this->is_connected()) {
            $this->set_error('download_file() called but FTP connection is not available.');
            $this->log_debug('[FFLHub] RSR FTP: download_file() called but FTP connection is not available.');
            return false;
        }

        return $this->download_file_internal($remote_path, $local_path);
    }

    /**
     * Public API: download a ZIP file and extract it.
     *
     * - Downloads the remote ZIP to $local_zip_path.
     * - Extracts it into $extract_to_dir (or dirname($local_zip_path) if empty).
     * - Deletes the ZIP after successful extraction (by default).
     *
     * @param string $remote_path      Remote ZIP path on the RSR FTP server.
     * @param string $local_zip_path   Absolute local filesystem path to save the ZIP.
     * @param string $extract_to_dir   Directory to extract into. If empty, uses dirname($local_zip_path).
     * @param bool   $delete_zip_after Whether to delete the ZIP after successful extraction.
     *
     * @return bool True on success, false on failure.
     */
    public function download_zip_file(
        string $remote_path,
        string $local_zip_path,
        string $extract_to_dir = '',
        bool $delete_zip_after = true
    ): bool {
        if (! $this->is_connected()) {
            $this->set_error('download_zip_file() called but FTP connection is not available.');
            $this->log_debug('[FFLHub] RSR FTP: download_zip_file() called but FTP connection is not available.');
            return false;
        }

        return $this->download_zip_file_internal(
            $remote_path,
            $local_zip_path,
            $extract_to_dir,
            $delete_zip_after
        );
    }

    /**
     * Get the last error message for this instance (if any).
     *
     * @return string|null
     */
    public function get_last_error(): ?string
    {
        return $this->last_error;
    }

    /**
     * Explicitly close the FTP connection.
     */
    public function close(): void
    {
        $this->close_internal();
    }

    /**
     * Internal instance-level implementation of file download.
     *
     * @param string $remote_path
     * @param string $local_path
     * @return bool
     */
    private function download_file_internal(string $remote_path, string $local_path): bool
    {
        $dir = dirname($local_path);
        if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
            $this->set_error('Failed to create directory ' . $dir);
            $this->log_debug('[FFLHub] RSR FTP: failed to create directory ' . $dir);
            return false;
        }

        $tmp_path = $local_path . '.tmp';

        $start = microtime(true);

        /** @var \FTP\Connection|resource $connection */
        $connection = $this->conn;

        $success = @ftp_get($connection, $tmp_path, $remote_path, FTP_BINARY);

        $elapsed = microtime(true) - $start;

        if (! $success) {
            @unlink($tmp_path);
            $this->set_error(sprintf('ftp_get failed for remote %s', $remote_path));
            $this->log_debug(
                sprintf(
                    '[FFLHub] RSR FTP: ftp_get failed for remote %s (host %s, user %s)',
                    $remote_path,
                    $this->host,
                    $this->username
                )
            );
            return false;
        }

        // Log size + MB/s
        if (file_exists($tmp_path)) {
            $size_bytes = filesize($tmp_path);
            $size_mb    = $size_bytes / 1048576;
            $mbps       = $size_mb / max($elapsed, 0.000001);

            $this->log_debug(
                sprintf(
                    '[FFLHub] RSR FTP: downloaded %.2f MB in %.2f s (%.2f MB/s) from %s',
                    $size_mb,
                    $elapsed,
                    $mbps,
                    $remote_path
                )
            );
        }

        if (! @rename($tmp_path, $local_path)) {
            @unlink($tmp_path);
            $this->set_error('Failed to rename tmp file to ' . $local_path);
            $this->log_debug('[FFLHub] RSR FTP: failed to rename tmp file to ' . $local_path);
            return false;
        }

        return true;
    }

    /**
     * Internal instance-level implementation of ZIP download + extraction.
     *
     * @param string $remote_path
     * @param string $local_zip_path
     * @param string $extract_to_dir
     * @param bool   $delete_zip_after
     * @return bool
     */
    private function download_zip_file_internal(
        string $remote_path,
        string $local_zip_path,
        string $extract_to_dir,
        bool $delete_zip_after
    ): bool {
        // 1) Download the ZIP file itself using the existing logic.
        if (! $this->download_file_internal($remote_path, $local_zip_path)) {
            // download_file_internal already set error and logged.
            return false;
        }

        if ($extract_to_dir === '') {
            $extract_to_dir = dirname($local_zip_path);
        }

        // Ensure extraction directory exists.
        if (! is_dir($extract_to_dir) && ! wp_mkdir_p($extract_to_dir)) {
            $this->set_error('Failed to create extract directory ' . $extract_to_dir);
            $this->log_debug('[FFLHub] RSR FTP: failed to create extract directory ' . $extract_to_dir);
            return false;
        }

        if (! file_exists($local_zip_path)) {
            $this->set_error('ZIP file does not exist at ' . $local_zip_path);
            $this->log_debug('[FFLHub] RSR FTP: ZIP file missing at ' . $local_zip_path);
            return false;
        }

        // Use ZipArchive ONLY, no unzip_file / WP_Filesystem.
        if (! class_exists(\ZipArchive::class)) {
            $this->set_error('ZipArchive class not available; cannot unzip.');
            $this->log_debug(
                '[FFLHub] RSR FTP: ZipArchive not available for ' . $local_zip_path
            );
            return false;
        }

        $zip         = new \ZipArchive();
        $open_result = $zip->open($local_zip_path);

        if ($open_result !== true) {
            $this->set_error('ZipArchive::open() failed with code ' . $open_result);
            $this->log_debug(
                '[FFLHub] RSR FTP: ZipArchive::open() failed for ' . $local_zip_path .
                    ' (code ' . $open_result . ')'
            );
            return false;
        }

        if (! $zip->extractTo($extract_to_dir)) {
            $this->set_error('ZipArchive::extractTo() failed.');
            $this->log_debug(
                '[FFLHub] RSR FTP: ZipArchive::extractTo() failed for ' . $local_zip_path .
                    ' -> ' . $extract_to_dir
            );
            $zip->close();
            return false;
        }

        $zip->close();

        // 3) Optionally delete the ZIP after successful extraction.
        if ($delete_zip_after) {
            @unlink($local_zip_path);
        }

        return true;
    }

    /**
     * Close the FTP connection (instance-level).
     */
    private function close_internal(): void
    {
        if ($this->conn) {
            /** @var \FTP\Connection|resource $connection */
            $connection = $this->conn;

            @ftp_close($connection);
            $this->conn = null;
        }
    }

    /**
     * Internal helper to store last error.
     *
     * @param string $message
     */
    private function set_error(string $message): void
    {
        $this->last_error = $message;
    }


    private function log_debug(string $message): void
    {
        if (! defined('FFLHUB_CRON_DEBUG') || FFLHUB_CRON_DEBUG !== true) {
            return;
        }

        error_log($message);
    }
}
