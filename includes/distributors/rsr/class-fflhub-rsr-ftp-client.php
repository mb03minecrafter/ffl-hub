<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Minimal FTP/FTPS client for talking to RSR's FTP server.
 *
 * Singleton-style: use FFLHub_RSR_FTP_Client::download_file(...) statically.
 * Under the hood, it reuses a single FTP connection per PHP request.
 */
class FFLHub_RSR_FTP_Client
{

    /**
     * @var FFLHub_RSR_FTP_Client|null
     */
    private static $instance = null;

    /**
     * @var resource|null Underlying FTP/FTPS connection.
     */
    private $conn = null;

    /**
     * @var string|null Last error message (for debugging/logging if needed).
     */
    private $last_error = null;

    /**
     * Credentials used for this connection (so we can detect changes).
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
     * Private constructor. Use get_instance() instead.
     *
     * @param string $host
     * @param string $username
     * @param string $password
     * @param bool   $use_ssl
     */
    private function __construct(
        string $host,
        string $username,
        string $password,
        bool $use_ssl
    ) {
        $this->host     = $host;
        $this->username = $username;
        $this->password = $password;
        $this->use_ssl  = $use_ssl;

        if ($host === '' || $username === '' || $password === '') {
            $this->set_error('Empty host/username/password passed to FTP client constructor.');
            error_log('[FFLHub] RSR FTP: empty host/username/password passed to constructor.');
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
            error_log('[FFLHub] RSR FTP: could not connect to host ' . $host);
            return;
        }

        $logged_in = @ftp_login($conn, $username, $password);
        if (! $logged_in) {
            $this->set_error('Login failed for user ' . $username);
            error_log('[FFLHub] RSR FTP: login failed for user ' . $username);
            @ftp_close($conn);
            return;
        }

        // Passive mode is almost always required behind firewalls/NAT.
        if (! @ftp_pasv($conn, true)) {
            // Not fatal, but worth logging.
            error_log('[FFLHub] RSR FTP: failed to enable passive mode.');
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
     * Get (and possibly create) the singleton instance for the given credentials.
     *
     * If credentials change between calls, the previous connection is closed
     * and a new one is created.
     *
     * @param string $host
     * @param string $username
     * @param string $password
     * @param bool   $use_ssl
     * @return FFLHub_RSR_FTP_Client|null
     */
    private static function get_instance(
        string $host,
        string $username,
        string $password,
        bool $use_ssl
    ) {
        // If we already have an instance and credentials match, reuse it.
        if (
            self::$instance instanceof self &&
            self::$instance->host === $host &&
            self::$instance->username === $username &&
            self::$instance->password === $password &&
            self::$instance->use_ssl === $use_ssl &&
            self::$instance->is_connected()
        ) {
            return self::$instance;
        }

        // Credentials changed or connection died. Close old and create new.
        if (self::$instance instanceof self) {
            self::$instance->close_internal();
        }

        self::$instance = new self($host, $username, $password, $use_ssl);

        if (! self::$instance->is_connected()) {
            // Constructor already logged the error.
            return null;
        }

        return self::$instance;
    }

    /**
     * Public static API: download a file from RSR FTP to a local path.
     * Reuses a single FTP connection per request when called multiple times
     * with the same credentials.
     *
     * @param string $remote_path Remote path on the RSR FTP server.
     * @param string $local_path  Absolute local filesystem path to save to.
     * @param string $host        FTP host.
     * @param string $username    FTP username.
     * @param string $password    FTP password.
     * @param bool   $use_ssl     Whether to use FTPS (ftp_ssl_connect) if available.
     * @return bool True on success, false on failure.
     */
    public static function download_file(
        string $remote_path,
        string $local_path,
        string $host,
        string $username,
        string $password,
        bool $use_ssl = true
    ): bool {
        $client = self::get_instance($host, $username, $password, $use_ssl);

        if (! $client || ! $client->is_connected()) {
            error_log('[FFLHub] RSR FTP: download_file() called but FTP connection is not available.');
            return false;
        }

        return $client->download_file_internal($remote_path, $local_path);
    }

    /**
     * Public static API: download a ZIP file and extract it.
     *
     * - Downloads the remote ZIP to $local_zip_path.
     * - Extracts it into $extract_to_dir (or dirname($local_zip_path) if empty).
     * - Deletes the ZIP after successful extraction (by default).
     *
     * @param string $remote_path      Remote ZIP path on the RSR FTP server.
     * @param string $local_zip_path   Absolute local filesystem path to save the ZIP.
     * @param string $host             FTP host.
     * @param string $username         FTP username.
     * @param string $password         FTP password.
     * @param string $extract_to_dir   Directory to extract into. If empty, uses dirname($local_zip_path).
     * @param bool   $use_ssl          Whether to use FTPS (ftp_ssl_connect) if available.
     * @param bool   $delete_zip_after Whether to delete the ZIP after successful extraction.
     *
     * @return bool True on success, false on failure.
     */
    public static function download_zip_file(
        string $remote_path,
        string $local_zip_path,
        string $host,
        string $username,
        string $password,
        string $extract_to_dir = '',
        bool $use_ssl = true,
        bool $delete_zip_after = true
    ): bool {
        $client = self::get_instance($host, $username, $password, $use_ssl);

        if (! $client || ! $client->is_connected()) {
            error_log('[FFLHub] RSR FTP: download_zip_file() called but FTP connection is not available.');
            return false;
        }

        return $client->download_zip_file_internal(
            $remote_path,
            $local_zip_path,
            $extract_to_dir,
            $delete_zip_after
        );
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
        // ... validations ...

        $dir = dirname($local_path);
        if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
            $this->set_error('Failed to create directory ' . $dir);
            error_log('[FFLHub] RSR FTP: failed to create directory ' . $dir);
            return false;
        }

        $tmp_path = $local_path . '.tmp';

        $start = microtime(true); // NEW


        /** @var \FTP\Connection|resource $connection */
        $connection = $this->conn;

        $success = @ftp_get($connection, $tmp_path, $remote_path, FTP_BINARY);

        $elapsed = microtime(true) - $start; // NEW

        if (! $success) {
            @unlink($tmp_path);
            $this->set_error(sprintf('ftp_get failed for remote %s', $remote_path));
            error_log(
                sprintf(
                    '[FFLHub] RSR FTP: ftp_get failed for remote %s (host %s, user %s)',
                    $remote_path,
                    $this->host,
                    $this->username
                )
            );
            return false;
        }

        // NEW: log size + MB/s
        if (file_exists($tmp_path)) {
            $size_bytes = filesize($tmp_path);
            $size_mb    = $size_bytes / 1048576;
            $mbps       = $size_mb / max($elapsed, 0.000001);

            error_log(
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
            error_log('[FFLHub] RSR FTP: failed to rename tmp file to ' . $local_path);
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
            error_log('[FFLHub] RSR FTP: failed to create extract directory ' . $extract_to_dir);
            return false;
        }

        if (! file_exists($local_zip_path)) {
            $this->set_error('ZIP file does not exist at ' . $local_zip_path);
            error_log('[FFLHub] RSR FTP: ZIP file missing at ' . $local_zip_path);
            return false;
        }

        // ======================
        // CHANGED: use ZipArchive ONLY, no unzip_file / WP_Filesystem
        // ======================
        if (! class_exists('ZipArchive')) {
            $this->set_error('ZipArchive class not available; cannot unzip.');
            error_log(
                '[FFLHub] RSR FTP: ZipArchive not available for ' . $local_zip_path
            );
            return false;
        }

        $zip = new ZipArchive();
        $open_result = $zip->open($local_zip_path);

        if ($open_result !== true) {
            $this->set_error('ZipArchive::open() failed with code ' . $open_result);
            error_log(
                '[FFLHub] RSR FTP: ZipArchive::open() failed for ' . $local_zip_path .
                    ' (code ' . $open_result . ')'
            );
            return false;
        }

        if (! $zip->extractTo($extract_to_dir)) {
            $this->set_error('ZipArchive::extractTo() failed.');
            error_log(
                '[FFLHub] RSR FTP: ZipArchive::extractTo() failed for ' . $local_zip_path .
                    ' -> ' . $extract_to_dir
            );
            $zip->close();
            return false;
        }

        $zip->close();
        // ======================
        // END CHANGED SECTION
        // ======================

        // 3) Optionally delete the ZIP after successful extraction.
        if ($delete_zip_after) {
            @unlink($local_zip_path);
        }

        return true;
    }

    /**
     * Check whether the client is connected and logged in.
     *
     * @return bool
     */
    private function is_connected(): bool
    {
        return (bool) $this->conn;
    }



    /**
     * Close the FTP connection (instance-level).
     */
    private function close_internal(): void
    {
        if ($this->conn) {
        // If running on PHP 8.1+ this will be an FTP\Connection object.
        // On older PHP versions it's a resource. Both are acceptable for ftp_close().
            /** @var \FTP\Connection|resource $connection */
            $connection = $this->conn;

            @ftp_close($connection);
            $this->conn = null;
        }
    }

    /**
     * Public static method to explicitly close and reset the singleton.
     */
    public static function close(): void
    {
        if (self::$instance instanceof self) {
            self::$instance->close_internal();
            self::$instance = null;
        }
    }

    /**
     * Get the last error message from the singleton instance (if any).
     *
     * @return string|null
     */
    public static function get_last_error(): ?string
    {
        if (self::$instance instanceof self) {
            return self::$instance->last_error;
        }
        return null;
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
}
