<?php

namespace FFLHub\Distributor\Services\Zanders\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Distributor\Services\FTP\FTPClientService;
use FFLHub\Distributor\Services\Zanders\ZandersFulfillmentImporterService;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * WP-Cron job to regularly download the Zanders inventory fulfillment CSV
 * (/Inventory/zandersinv.csv) from the Zanders FTP server into uploads/fflhub-zanders/,
 * then import it into the staging table and swap staging ↔ live.
 */
final class ZandersFulfillmentCronService extends AbstractTableCronService
{
    /**
     * Cron hook name for Zanders fulfillment refresh.
     */
    public const CRON_HOOK = 'fflhub_zanders_fulfillment_update';

    /**
     * Debug gate constant (define('FFLHUB_CRON_DEBUG', true);).
     */
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';

    /**
     * Log prefix.
     */
    private const LOG_PREFIX = '[FFLHUB][ZandersFulfillmentCron]';

    /**
     * Option key used to rate-limit FTP meta checks (avoid repeated handshakes).
     */
    private const OPT_LAST_CHECKED_AT = 'fflhub_zanders_fulfillment_last_checked_at';

    /**
     * Tune: Zanders update cadence unknown; start same as RSR.
     * After we successfully applied a new mtime, skip FTP for a while to save connection cost.
     */
    private const FTP_COOLDOWN_SECONDS = 20 * HOUR_IN_SECONDS; // 20 hours

    /**
     * Hard minimum gap between FTP checks (guards overlaps / double-runs).
     */
    private const FTP_MIN_CHECK_GAP_SECONDS = 300; // 5 minutes

    /**
     * Remote CSV path on Zanders FTP.
     */
    private const REMOTE_PATH = '/Inventory/zandersinv.csv';

    /**
     * Local directory under uploads.
     */
    private const LOCAL_DIR = 'fflhub-zanders';

    /**
     * Local filename to save as.
     */
    private const LOCAL_FILE_NAME = 'zandersinv.csv';

    public function __construct(DoubleBufferedFulfillmentTable $table)
    {
        parent::__construct($table);
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_interval_seconds(): int
    {
        return 15 * MINUTE_IN_SECONDS;
    }

    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    protected function get_initial_delay_seconds(): int
    {
        return 5 * MINUTE_IN_SECONDS;
    }

    public function run(): void
    {
        $t_start   = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $this->log('---- RUN START ----', [
            'pid'          => function_exists('getmypid') ? (int) getmypid() : 0,
            'memory_kb'    => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
            'hook'         => self::CRON_HOOK,
            'group'        => $this->get_action_group(),
            'interval_sec' => $this->get_interval_seconds(),
        ]);

        // 0) Load FTP credentials.
        $t_creds = microtime(true);
        $creds   = $this->get_ftp_credentials();

        $this->profile('Credentials retrieval', $t_creds, [
            'ok'       => is_array($creds),
            'has_host' => is_array($creds) ? (bool) ($creds['host'] ?? '') : false,
            'has_user' => is_array($creds) ? (bool) ($creds['username'] ?? '') : false,
            'has_ssl'  => is_array($creds) ? (bool) ($creds['use_ssl'] ?? false) : false,
            'port'     => is_array($creds) ? (int) ($creds['port'] ?? 0) : 0,
        ]);

        if (!is_array($creds)) {
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing credentials)');
            return;
        }

        $host     = (string) $creds['host'];
        $username = (string) $creds['username'];
        $password = (string) $creds['password'];
        $use_ssl  = (bool) $creds['use_ssl']; // should be false for Zanders
        $port     = (int) ($creds['port'] ?? 21);

        // Local save dir.
        $uploads  = wp_upload_dir();
        $base_dir = trailingslashit($uploads['basedir']) . self::LOCAL_DIR;

        $t_paths = microtime(true);

        if (!wp_mkdir_p($base_dir)) {
            $this->log('ERROR: failed to create base directory', [
                'base_dir' => (string) $base_dir,
            ]);
            $this->profile('Prepare local paths (mkdir failed)', $t_paths);
            $this->finalize_run($t_start, $mem_start, 'ERROR (mkdir failed)');
            return;
        }

        $local_path  = trailingslashit($base_dir) . self::LOCAL_FILE_NAME;
        $remote_path = self::REMOTE_PATH;

        $this->profile('Prepare local paths', $t_paths, [
            'base_dir'    => (string) $base_dir,
            'remote_csv'  => (string) $remote_path,
            'local_csv'   => (string) $local_path,
        ]);

        // ---------------------------------------------------------------------
        // FTP connect throttle
        // ---------------------------------------------------------------------
        $now = time();

        $last_checked_at = (int) get_option(self::OPT_LAST_CHECKED_AT, 0);
        if ($last_checked_at > 0 && ($now - $last_checked_at) < self::FTP_MIN_CHECK_GAP_SECONDS) {
            $this->log('FTP check throttled (recently checked) — skipping connect', [
                'last_checked_at' => $last_checked_at,
                'age_sec'         => (int) ($now - $last_checked_at),
                'min_gap_sec'     => (int) self::FTP_MIN_CHECK_GAP_SECONDS,
            ]);
            $this->finalize_run($t_start, $mem_start, 'SUCCESS (throttle; recent check)');
            return;
        }

        $last_applied_mtime = (int) get_option('fflhub_zanders_fulfillment_last_applied_mtime', 0);
        if ($last_applied_mtime > 0 && $now < ($last_applied_mtime + self::FTP_COOLDOWN_SECONDS)) {
            $this->log('Cooldown after last applied change — skipping FTP connect', [
                'last_applied_mtime' => $last_applied_mtime,
                'cooldown_sec'       => (int) self::FTP_COOLDOWN_SECONDS,
                'skip_for_sec'       => (int) (($last_applied_mtime + self::FTP_COOLDOWN_SECONDS) - $now),
            ]);
            $this->finalize_run($t_start, $mem_start, 'SUCCESS (cooldown)');
            return;
        }

        update_option(self::OPT_LAST_CHECKED_AT, $now, false);

        // 1) Connect FTP.
        $t_ftp = microtime(true);

        $ftp = new FTPClientService(
            $host,
            $username,
            $password,
            $use_ssl, // expected false
            $port,    // expected 21
            30,
            true,
            '[FFLHub][Zanders][FTP]'
        );

        if (!$ftp->is_connected()) {
            update_option('fflhub_zanders_fulfillment_last_download_error', current_time('mysql'));

            $this->log('ERROR: FTP connection not available.', [
                'host'    => $host,
                'use_ssl' => $use_ssl ? 1 : 0,
                'port'    => (int) $port,
            ]);

            $this->profile('FTP connection (failed)', $t_ftp);
            $this->finalize_run($t_start, $mem_start, 'ERROR (FTP connection failed)');
            return;
        }

        // -----------------------------
        // FTP meta gate (mtime first, size only if needed)
        // -----------------------------
        $t_meta = microtime(true);

        $remote_mtime       = (int) ($ftp->get_remote_mtime($remote_path) ?? 0);
        $last_applied_mtime = (int) get_option('fflhub_zanders_fulfillment_last_applied_mtime', 0);

        update_option('fflhub_zanders_fulfillment_last_seen_mtime', $remote_mtime);

        if ($remote_mtime > 0 && $remote_mtime <= $last_applied_mtime) {
            $this->profile('FTP meta check (mtime only)', $t_meta, [
                'remote_mtime'       => $remote_mtime,
                'last_applied_mtime' => $last_applied_mtime > 0 ? $last_applied_mtime : null,
                'changed'            => 0,
                'size_checked'       => 0,
            ]);

            $this->log('No update available (remote mtime unchanged) — skipping download/import/swap', [
                'remote_mtime'       => $remote_mtime,
                'last_applied_mtime' => $last_applied_mtime,
            ]);

            $this->finalize_run($t_start, $mem_start, 'SUCCESS (no change)');
            return;
        }

        $remote_size = (int) ($ftp->get_remote_size($remote_path) ?? -1);
        if ($remote_size >= 0) {
            update_option('fflhub_zanders_fulfillment_last_seen_size', $remote_size);
        }

        $this->profile('FTP meta check (mtime/size)', $t_meta, [
            'remote_mtime'       => $remote_mtime > 0 ? $remote_mtime : null,
            'remote_size_bytes'  => $remote_size >= 0 ? $remote_size : null,
            'last_applied_mtime' => $last_applied_mtime > 0 ? $last_applied_mtime : null,
            'changed'            => ($remote_mtime > 0 && $remote_mtime > $last_applied_mtime) ? 1 : 0,
            'size_checked'       => 1,
        ]);

        // Optional size stability guard
        if ($remote_mtime > 0 && $remote_mtime > $last_applied_mtime && $remote_size >= 0) {
            usleep(250000);
            $remote_size2 = (int) ($ftp->get_remote_size($remote_path) ?? -1);
            if ($remote_size2 >= 0 && $remote_size2 !== $remote_size) {
                $this->log('Remote file still changing (size unstable) — deferring', [
                    'size1'        => $remote_size,
                    'size2'        => $remote_size2,
                    'remote_mtime' => $remote_mtime,
                ]);
                $this->finalize_run($t_start, $mem_start, 'SUCCESS (defer; unstable remote file)');
                return;
            }
        }

        // 2) Download CSV
        $t_download = microtime(true);

        $ok = $ftp->download_file($remote_path, $local_path);

        $csv_exists     = file_exists($local_path);
        $csv_size_after = $csv_exists ? (int) filesize($local_path) : 0;

        $this->profile('FTP download', $t_download, [
            'ok'           => $ok ? 1 : 0,
            'csv_exists'   => $csv_exists ? 1 : 0,
            'csv_kb_after' => $csv_size_after > 0 ? (int) round($csv_size_after / 1024) : 0,
        ]);

        if (!$ok) {
            update_option('fflhub_zanders_fulfillment_last_download_error', current_time('mysql'));
            $this->log('ERROR: download failed – aborting import and swap.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (download failed)');
            return;
        }

        update_option('fflhub_zanders_fulfillment_last_download', current_time('mysql'));
        delete_option('fflhub_zanders_fulfillment_last_download_error');

        // 3) Import into staging.
        $t_import = microtime(true);

        $count = 0;
        try {
            $importer = new ZandersFulfillmentImporterService($this->table);
            $count    = (int) $importer->import_from_downloaded_file();
        } catch (\Throwable $e) {
            $this->log('ERROR: exception during import', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Import into staging (failed)', $t_import);
            $this->finalize_run($t_start, $mem_start, 'ERROR (import exception)');
            return;
        }

        $this->profile('Import into staging', $t_import, [
            'imported_rows' => (int) $count,
        ]);

        if ($count <= 0) {
            $this->log('ERROR: import completed but 0 rows processed, not swapping.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (0 rows imported)');
            return;
        }

        // 4) Swap staging ↔ live.
        $t_swap = microtime(true);

        $new_live = '';
        try {
            $new_live = (string) $this->table->swap_live_and_staging();
        } catch (\Throwable $e) {
            $this->log('ERROR: exception during swap', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Swap staging ↔ live (failed)', $t_swap);
            $this->finalize_run($t_start, $mem_start, 'ERROR (swap exception)');
            return;
        }

        $this->profile('Swap staging ↔ live', $t_swap, [
            'new_live' => (string) $new_live,
        ]);

        update_option('fflhub_zanders_fulfillment_last_import', current_time('mysql'));
        update_option('fflhub_zanders_fulfillment_last_import_count', (int) $count);
        update_option('fflhub_zanders_fulfillment_last_swap', current_time('mysql'));

        if ($remote_mtime > 0) {
            update_option('fflhub_zanders_fulfillment_last_applied_mtime', $remote_mtime);
        }

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'imported_rows' => (int) $count,
            'new_live'      => (string) $new_live,
            'remote_mtime'  => $remote_mtime > 0 ? $remote_mtime : null,
        ]);
    }

    /**
     * Retrieve and validate FTP credentials from Zanders distributor settings.
     *
     * Note: Zanders is FTP (no TLS). We enforce use_ssl=false and port=21 defaults.
     *
     * @return array{host:string,username:string,password:string,use_ssl:bool,port:int}|null
     */
    public function get_ftp_credentials(): ?array
    {
        $host     = Options::get_distributor_option('zanders', 'ftp_host', '');
        $username = Options::get_distributor_option('zanders', 'ftp_username', '');
        $password = Options::get_distributor_option('zanders', 'ftp_password', '');

        $host     = trim((string) $host);
        $username = trim((string) $username);
        $password = trim((string) $password);

        if ($host === '' || $username === '' || $password === '') {
            $this->log('Missing FTP credentials', [
                'host' => $host !== '' ? 'set' : 'empty',
                'user' => $username !== '' ? 'set' : 'empty',
            ]);
            return null;
        }

        return [
            'host'     => $host,
            'username' => $username,
            'password' => $password,
            'use_ssl'  => false,
            'port'     => 21,
        ];
    }

    // --------------------------------------------------
    // Debug / profiling helpers (DebugLogUtil)
    // --------------------------------------------------

    /** @param array<string,mixed> $ctx */
    private function log(string $msg, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $msg);
            return;
        }

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $msg, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $elapsed_ms = (microtime(true) - $t0) * 1000.0;

        $ctx = array_merge($ctx, [
            'elapsed_ms' => number_format($elapsed_ms, 2, '.', ''),
        ]);

        $this->log("PROFILE: {$label}", $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function finalize_run(float $t_start, int $mem_start, string $status, array $ctx = []): void
    {
        $this->profile('Total cron run', $t_start, [
            'status' => (string) $status,
        ]);

        $mem_end = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        if ($mem_start > 0 && $mem_end > 0) {
            $this->log('Memory usage summary', [
                'start_kb' => (int) round($mem_start / 1024),
                'end_kb'   => (int) round($mem_end / 1024),
                'delta_kb' => (int) round(($mem_end - $mem_start) / 1024),
            ]);
        }

        if (!empty($ctx)) {
            $this->log("---- RUN END ({$status}) ----", $ctx);
        } else {
            $this->log("---- RUN END ({$status}) ----");
        }
    }
}
