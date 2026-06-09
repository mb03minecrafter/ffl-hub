<?php

namespace FFLHub\Distributor\Services\RSR\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Distributor\Services\FTP\FTPClientService;
use FFLHub\Distributor\Services\FTP\FTPFreshnessGate;
use FFLHub\Distributor\Services\RSR\RSROfferNormalizationService;
use FFLHub\Distributor\Services\RSR\RSRProductImporterService;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * WP-Cron job to regularly download the RSR product catalog file
 * (rsrinventory-new.txt) from the RSR FTP server into uploads/fflhub-rsr/,
 * then import it into the staging table and swap staging ↔ live.
 */
final class RSRProductCronService extends AbstractTableCronService
{
    /**
     * Cron hook name for RSR product refresh.
     */
    public const CRON_HOOK = 'fflhub_rsr_product_update';


    /**
     * Debug gate constant (define('FFLHUB_CRON_DEBUG', true);).
     */
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';

    /**
     * Log prefix.
     */
    private const LOG_PREFIX = '[FFLHUB][RSRProductCron]';

    /**
     * Option key used to rate-limit FTP meta checks (avoid repeated handshakes).
     */
    private const OPT_LAST_CHECKED_AT = 'fflhub_rsr_fulfillment_last_checked_at';

    /**
     * Vendor updates ~ every 2 hours.
     * After we successfully applied a new mtime, skip FTP for a while to save connection cost.
     */
    private const FTP_COOLDOWN_SECONDS = 2700; // 45 minutes

    /**
     * Hard minimum gap between FTP checks (guards overlaps / double-runs).
     */
    private const FTP_MIN_CHECK_GAP_SECONDS = 1800; // 30 minutes; product ZIP updates roughly every 2 hours

    public function __construct(DoubleBufferedProductTable $table)
    {
        parent::__construct($table);
    }

    /**
     * Unique cron hook name.
     */
    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    /**
     * Interval length in seconds.
     * (You can keep this frequent; cooldown+throttle will prevent expensive FTP connects.)
     */
    protected function get_interval_seconds(): int
    {
        return 60;
    }

    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    /**
     * Delay before first run (keeps your old 5-minute initial delay).
     */
    protected function get_initial_delay_seconds(): int
    {
        return 5 * MINUTE_IN_SECONDS;
    }

    /**
     * Cron callback:
     *  1) Download rsrinventory-new.zip from RSR FTP to uploads.
     *  2) Import it into the STAGING table.
     *  3) Swap staging ↔ live if import succeeded.
     */
    public function run(): void
    {
        // ---------------------------------------------------------------------
        // Stage 0: Run setup, profiling baseline, and force-update detection.
        // ---------------------------------------------------------------------
        $t_start   = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $force_update = $this->should_force_update();

        $this->log('---- RUN START ----', [
            'pid'          => function_exists('getmypid') ? (int) getmypid() : 0,
            'memory_kb'    => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
            'hook'         => self::CRON_HOOK,
            'group'        => $this->get_action_group(),
            'interval_sec' => $this->get_interval_seconds(),
            'force_update' => $force_update ? 1 : 0,
        ]);

        if ($force_update) {
            $this->log('FORCE_UPDATE enabled - bypassing cooldown/mtime gates');
        }

        // ---------------------------------------------------------------------
        // Stage 1: Load and validate RSR FTP credentials.
        // ---------------------------------------------------------------------
        $t_creds = microtime(true);
        $creds   = $this->get_ftp_credentials();

        $this->profile('Credentials retrieval', $t_creds, [
            'ok'       => is_array($creds),
            'has_host' => is_array($creds) ? (bool) ($creds['host'] ?? '') : false,
            'has_user' => is_array($creds) ? (bool) ($creds['username'] ?? '') : false,
            'has_ssl'  => is_array($creds) ? (bool) ($creds['use_ssl'] ?? false) : false,
        ]);

        if (!is_array($creds)) {
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing credentials)');
            return;
        }

        $host     = (string) $creds['host'];
        $username = (string) $creds['username'];
        $password = (string) $creds['password'];
        $use_ssl  = (bool) $creds['use_ssl'];

        // ---------------------------------------------------------------------
        // Stage 2: Prepare local download/extract paths.
        // ---------------------------------------------------------------------
        $uploads  = wp_upload_dir();
        $base_dir = trailingslashit($uploads['basedir']) . 'fflhub-rsr';

        $t_paths = microtime(true);

        if (!wp_mkdir_p($base_dir)) {
            $this->log('ERROR: failed to create base directory', [
                'base_dir' => (string) $base_dir,
            ]);
            $this->profile('Prepare local paths (mkdir failed)', $t_paths);
            $this->finalize_run($t_start, $mem_start, 'ERROR (mkdir failed)');
            return;
        }

        $file_name      = 'rsrinventory-new.txt';
        $local_path     = trailingslashit($base_dir) . $file_name;
        $zip_name       = 'rsrinventory-new.zip';
        $local_zip_path = trailingslashit($base_dir) . $zip_name;

        $remote_path = '/ftpdownloads/rsrinventory-new.zip';

        $this->profile('Prepare local paths', $t_paths, [
            'base_dir'       => (string) $base_dir,
            'remote_zip'     => (string) $remote_path,
            'local_zip_path' => (string) $local_zip_path,
            'local_txt_path' => (string) $local_path,
        ]);

        // ---------------------------------------------------------------------
        // Stage 3: FTP freshness gate before connecting.
        // ---------------------------------------------------------------------
        $pre_gate = FTPFreshnessGate::evaluate_pre_connect(
            self::OPT_LAST_CHECKED_AT,
            'fflhub_rsr_fulfillment_last_applied_mtime',
            self::FTP_MIN_CHECK_GAP_SECONDS,
            self::FTP_COOLDOWN_SECONDS,
            $force_update
        );

        if ((bool) $pre_gate['skip']) {
            $this->log((string) $pre_gate['log_message'], (array) $pre_gate['log_context']);
            $this->finalize_run($t_start, $mem_start, (string) $pre_gate['status']);
            return;
        }
        // ---------------------------------------------------------------------
        // Stage 4: Connect to RSR FTP.
        // ---------------------------------------------------------------------
        $t_ftp = microtime(true);

        $ftp = new FTPClientService(
            $host,
            $username,
            $password,
            $use_ssl,
            2222, // RSR port
            30,   // timeout
            true, // passive
            '[FFLHub][RSR][FTP]'
        );
        if (!$ftp->is_connected()) {
            update_option('fflhub_rsr_fulfillment_last_download_error', current_time('mysql'));

            $this->log('ERROR: FTP connection not available.', [
                'host'    => $host,
                'use_ssl' => $use_ssl ? 1 : 0,
            ]);

            $this->profile('FTP connection (failed)', $t_ftp);
            $this->finalize_run($t_start, $mem_start, 'ERROR (FTP connection failed)');
            return;
        }

        // ---------------------------------------------------------------------
        // Stage 5: Remote freshness gate using FTP mtime/size.
        // ---------------------------------------------------------------------
        $t_meta = microtime(true);
        $last_applied_mtime = (int) get_option('fflhub_rsr_fulfillment_last_applied_mtime', 0);
        $meta_gate          = FTPFreshnessGate::evaluate_remote_meta(
            $ftp,
            $remote_path,
            'fflhub_rsr_fulfillment_last_seen_mtime',
            'fflhub_rsr_fulfillment_last_seen_size',
            $last_applied_mtime,
            $force_update,
            250000,
            'No update available (remote mtime unchanged) - skipping download/import/swap'
        );

        $this->profile((string) $meta_gate['profile_label'], $t_meta, (array) $meta_gate['profile_context']);

        $remote_mtime = (int) $meta_gate['remote_mtime'];

        if ((bool) $meta_gate['skip']) {
            $this->log((string) $meta_gate['log_message'], (array) $meta_gate['log_context']);
            $this->finalize_run($t_start, $mem_start, (string) $meta_gate['status']);
            return;
        }
        // ---------------------------------------------------------------------
        // Stage 6: Download RSR ZIP and extract the product TXT.
        // ---------------------------------------------------------------------
        $t_download = microtime(true);

        $ok = $ftp->download_zip_file(
            $remote_path,
            $local_zip_path,
            $base_dir,
            false // keep zip temporarily for logging/inspection; delete after extract below
        );

        $zip_size_after = file_exists($local_zip_path) ? (int) filesize($local_zip_path) : 0;
        $txt_exists     = file_exists($local_path);
        $txt_size_after = $txt_exists ? (int) filesize($local_path) : 0;

        $this->profile('FTP download', $t_download, [
            'ok'            => $ok ? 1 : 0,
            'zip_kb_after'  => $zip_size_after > 0 ? (int) round($zip_size_after / 1024) : 0,
            'txt_extracted' => $txt_exists ? 1 : 0,
            'txt_kb_after'  => $txt_size_after > 0 ? (int) round($txt_size_after / 1024) : 0,
        ]);

        if (!$ok) {
            update_option('fflhub_rsr_fulfillment_last_download_error', current_time('mysql'));
            $this->log('ERROR: download failed – aborting import and swap.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (download failed)');
            return;
        }

        // Don’t retain ZIP on disk.
        @unlink($local_zip_path);

        update_option('fflhub_rsr_fulfillment_last_download', current_time('mysql'));
        delete_option('fflhub_rsr_fulfillment_last_download_error');

        if (!$txt_exists) {
            $this->log('WARNING: expected extracted TXT not found after download', [
                'local_txt_path' => (string) $local_path,
            ]);
            // keep going; importer may handle its own paths
        }

        // ---------------------------------------------------------------------
        // Stage 7: Import product TXT into the inactive/staging table.
        // ---------------------------------------------------------------------
        $t_import = microtime(true);

        $count = 0;
        try {
            $importer = new RSRProductImporterService($this->table);
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

        // ---------------------------------------------------------------------
        // Stage 8: Swap staging/live by flipping the live-table option.
        // ---------------------------------------------------------------------
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

        // ---------------------------------------------------------------------
        // Stage 9: Update existing normalized offer fields from the newly live RSR table.
        // ---------------------------------------------------------------------
        $offers_result = $this->update_distributor_offers_from_new_live_table($new_live);

        // ---------------------------------------------------------------------
        // Stage 10: Persist success metadata and finalize the run.
        // ---------------------------------------------------------------------
        update_option('fflhub_rsr_fulfillment_last_import', current_time('mysql'));
        update_option('fflhub_rsr_fulfillment_last_import_count', (int) $count);
        update_option('fflhub_rsr_fulfillment_last_swap', current_time('mysql'));

        // Mark applied mtime ONLY after a successful import+swap
        if ($remote_mtime > 0) {
            update_option('fflhub_rsr_fulfillment_last_applied_mtime', $remote_mtime);
        }

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'imported_rows' => (int) $count,
            'new_live'      => (string) $new_live,
            'distributor_offers_ok' => !empty($offers_result['ok']) ? 1 : 0,
            'distributor_offers_rsr_product_update_rows' => (int) ($offers_result['product_update_rows'] ?? 0),
            'distributor_offers_rsr_stale_disabled_rows' => (int) ($offers_result['stale_disabled'] ?? 0),
            'remote_mtime'  => $remote_mtime > 0 ? $remote_mtime : null,
        ]);
    }

    /**
     * Update existing normalized offer rows after the product table swap.
     *
     * @return array<string,mixed>
     */
    private function update_distributor_offers_from_new_live_table(string $new_live): array
    {
        $t_offers = microtime(true);
        $offers_result = [];

        try {
            $offers_result = RSROfferNormalizationService::update_existing_from_product_table($new_live);
        } catch (\Throwable $e) {
            $offers_result = [
                'ok' => false,
                'source_live_table' => (string) $new_live,
                'errors' => [$e->getMessage()],
            ];
        }

        $this->profile('Update existing distributor offers from new live table', $t_offers, [
            'source_live_table' => (string) ($offers_result['source_live_table'] ?? $new_live),
            'matched_existing_rsr_offers' => (int) ($offers_result['matched_existing_rsr_offers'] ?? 0),
            'distributor_offers_rsr_product_update_rows' => (int) ($offers_result['product_update_rows'] ?? 0),
            'distributor_offers_rsr_product_update_ms' => (string) ($offers_result['product_update_elapsed_ms'] ?? '0.00'),
            'distributor_offers_rsr_stale_disabled_rows' => (int) ($offers_result['stale_disabled'] ?? 0),
            'distributor_offers_rsr_stale_cleanup_ms' => (string) ($offers_result['stale_cleanup_elapsed_ms'] ?? '0.00'),
            'ok' => !empty($offers_result['ok']) ? 1 : 0,
            'errors' => !empty($offers_result['errors']) ? (array) $offers_result['errors'] : [],
        ]);

        if (empty($offers_result['ok'])) {
            $this->log('ERROR: RSR distributor offers update failed after product swap', [
                'source_live_table' => (string) ($offers_result['source_live_table'] ?? $new_live),
                'errors' => !empty($offers_result['errors']) ? (array) $offers_result['errors'] : [],
            ]);
        }

        return $offers_result;
    }

    /**
     * Retrieve and validate FTP credentials from RSR distributor settings.
     *
     * @return array{host:string,username:string,password:string,use_ssl:bool}|null
     */
    public function get_ftp_credentials(): ?array
    {
        $host      = Options::get_distributor_option('rsr', 'ftp_host', '');
        $username  = Options::get_distributor_option('rsr', 'ftp_username', '');
        $password  = Options::get_distributor_option('rsr', 'ftp_password', '');
        $use_ssl_s = Options::get_distributor_option('rsr', 'ftp_use_ssl', '');

        $host     = trim((string) $host);
        $username = trim((string) $username);
        $password = trim((string) $password);
        $use_ssl  = ($use_ssl_s !== '');

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
            'use_ssl'  => $use_ssl,
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

    /**
     * Legacy method retained for compatibility if other code calls it.
     * (No longer used by this class after migrating to DebugLogUtil.)
     */
    private function log_debug(string $message): void
    {
        DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $message);
    }

    /**
     * Legacy method retained for compatibility if other code calls it.
     * (No longer used by this class after migrating to DebugLogUtil.)
     */
    private function log_memory_summary(int $mem_start): void
    {
        $mem_end = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        if ($mem_start > 0 && $mem_end > 0) {
            $this->log_debug(
                sprintf(
                    "[FFLHub][RSR Fulfillment Cron] Memory usage summary: start=%d KB, end=%d KB, delta=%+d KB",
                    (int) round($mem_start / 1024),
                    (int) round($mem_end / 1024),
                    (int) round(($mem_end - $mem_start) / 1024)
                )
            );
        }
    }

}
