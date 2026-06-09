<?php

namespace FFLHub\Distributor\Services\RSR\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Cron\CronRunLogger;
use FFLHub\Distributor\Services\Cron\FtpFeedFetcher;
use FFLHub\Distributor\Services\Cron\FtpFeedFetchRequest;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Distributor\Services\RSR\RSROfferNormalizationService;
use FFLHub\Distributor\Services\RSR\RSRFtpCredentials;
use FFLHub\Distributor\Services\RSR\RSRProductImporterService;

/**
 * WP-Cron job to regularly download the RSR product catalog file
 * (rsrinventory-new.txt) from the RSR FTP server into uploads/fflhub-rsr/,
 * then import it into the staging table and swap staging/live.
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
     *  3) Swap staging/live if import succeeded.
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
        // Stage 3: Fetch the RSR product ZIP through shared FTP plumbing.
        // ---------------------------------------------------------------------
        $fetch = (new FtpFeedFetcher())->fetch(
            FtpFeedFetchRequest::create([
                'distributor_id' => 'rsr',
                'cron_name' => 'RSR product cron',
                'credentials' => $creds,
                'remote_path' => $remote_path,
                'local_path' => $local_zip_path,
                'last_checked_option' => self::OPT_LAST_CHECKED_AT,
                'last_applied_mtime_option' => 'fflhub_rsr_fulfillment_last_applied_mtime',
                'last_seen_mtime_option' => 'fflhub_rsr_fulfillment_last_seen_mtime',
                'last_seen_size_option' => 'fflhub_rsr_fulfillment_last_seen_size',
                'last_download_option' => '',
                'last_download_error_option' => 'fflhub_rsr_fulfillment_last_download_error',
                'min_check_gap_seconds' => self::FTP_MIN_CHECK_GAP_SECONDS,
                'cooldown_seconds' => self::FTP_COOLDOWN_SECONDS,
                'force' => $force_update,
                'ftp_log_prefix' => '[FFLHub][RSR][FTP]',
                'no_change_log_message' => 'No update available (remote mtime unchanged) - skipping download/import/swap',
            ]),
            $this->cron_logger()
        );

        if ($fetch->is_skipped() || $fetch->is_failed()) {
            $this->finalize_run($t_start, $mem_start, $fetch->cron_status !== '' ? $fetch->cron_status : 'ERROR (download failed)');
            return;
        }

        $remote_mtime = $fetch->remote_mtime;

        // ---------------------------------------------------------------------
        // Stage 4: Extract the downloaded RSR ZIP into the product TXT.
        // ---------------------------------------------------------------------
        $t_download = microtime(true);
        $ok = $this->extract_zip_file($local_zip_path, $base_dir);

        $zip_size_after = file_exists($local_zip_path) ? (int) filesize($local_zip_path) : 0;
        $txt_exists     = file_exists($local_path);
        $txt_size_after = $txt_exists ? (int) filesize($local_path) : 0;

        $this->profile('Extract ZIP', $t_download, [
            'ok'            => $ok ? 1 : 0,
            'zip_kb_after'  => $zip_size_after > 0 ? (int) round($zip_size_after / 1024) : 0,
            'txt_extracted' => $txt_exists ? 1 : 0,
            'txt_kb_after'  => $txt_size_after > 0 ? (int) round($txt_size_after / 1024) : 0,
        ]);

        if (!$ok) {
            update_option('fflhub_rsr_fulfillment_last_download_error', current_time('mysql'));
            $this->log('ERROR: ZIP extraction failed - aborting import and swap.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (extract failed)');
            return;
        }

        // Do not retain ZIP on disk.
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
        // Stage 5: Import product TXT into the inactive/staging table.
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
        // Stage 6: Swap staging/live by flipping the live-table option.
        // ---------------------------------------------------------------------
        $t_swap = microtime(true);

        $new_live = '';
        try {
            $new_live = (string) $this->table->swap_live_and_staging();
        } catch (\Throwable $e) {
            $this->log('ERROR: exception during swap', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Swap staging/live (failed)', $t_swap);
            $this->finalize_run($t_start, $mem_start, 'ERROR (swap exception)');
            return;
        }

        $this->profile('Swap staging/live', $t_swap, [
            'new_live' => (string) $new_live,
        ]);

        // ---------------------------------------------------------------------
        // Stage 7: Update existing normalized offer fields from the newly live RSR table.
        // ---------------------------------------------------------------------
        $offers_result = $this->update_distributor_offers_from_new_live_table();

        // ---------------------------------------------------------------------
        // Stage 8: Persist success metadata and finalize the run.
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
            'distributor_offers_rsr_inserted_missing_rows' => (int) ($offers_result['inserted_missing_offers'] ?? 0),
            'distributor_offers_rsr_updated_changed_rows' => (int) ($offers_result['updated_changed_offers'] ?? 0),
            'distributor_offers_rsr_stale_disabled_rows' => (int) ($offers_result['stale_disabled'] ?? 0),
            'remote_mtime'  => $remote_mtime > 0 ? $remote_mtime : null,
        ]);
    }

    /**
     * Sync normalized offer rows for carried UPCs after the product table swap.
     *
     * @return array<string,mixed>
     */
    private function update_distributor_offers_from_new_live_table(): array
    {
        $t_offers = microtime(true);
        $live_table = $this->table->get_live_table_name();
        $offers_result = [];

        try {
            $offers_result = RSROfferNormalizationService::normalize_from_product_table($live_table);
        } catch (\Throwable $e) {
            $offers_result = [
                'ok' => false,
                'source_live_table' => (string) $live_table,
                'errors' => [$e->getMessage()],
            ];
        }

        $this->profile('Sync distributor offers from new live table', $t_offers, [
            'source_live_table' => (string) ($offers_result['source_live_table'] ?? $live_table),
            'active_product_state_total' => (int) ($offers_result['active_product_state_total'] ?? 0),
            'matched_active_rsr_upcs' => (int) ($offers_result['matched_active_rsr_upcs'] ?? 0),
            'distributor_offers_rsr_inserted_missing_rows' => (int) ($offers_result['inserted_missing_offers'] ?? 0),
            'distributor_offers_rsr_insert_missing_ms' => (string) ($offers_result['insert_missing_elapsed_ms'] ?? '0.00'),
            'distributor_offers_rsr_updated_changed_rows' => (int) ($offers_result['updated_changed_offers'] ?? 0),
            'distributor_offers_rsr_update_changed_ms' => (string) ($offers_result['update_changed_elapsed_ms'] ?? '0.00'),
            'distributor_offers_rsr_upsert_rows' => (int) ($offers_result['upsert_mysql_affected_rows'] ?? 0),
            'distributor_offers_rsr_upsert_ms' => (string) ($offers_result['upsert_elapsed_ms'] ?? '0.00'),
            'distributor_offers_rsr_stale_disabled_rows' => (int) ($offers_result['stale_disabled'] ?? 0),
            'distributor_offers_rsr_stale_cleanup_ms' => (string) ($offers_result['stale_cleanup_elapsed_ms'] ?? '0.00'),
            'ok' => !empty($offers_result['ok']) ? 1 : 0,
            'errors' => !empty($offers_result['errors']) ? (array) $offers_result['errors'] : [],
        ]);

        if (empty($offers_result['ok'])) {
            $this->log('ERROR: RSR distributor offers update failed after product swap', [
                'source_live_table' => (string) ($offers_result['source_live_table'] ?? $live_table),
                'errors' => !empty($offers_result['errors']) ? (array) $offers_result['errors'] : [],
            ]);
        }

        return $offers_result;
    }

    private function extract_zip_file(string $zip_path, string $extract_to_dir): bool
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->log('ERROR: ZipArchive extension is not available.');
            return false;
        }

        if (!file_exists($zip_path)) {
            $this->log('ERROR: downloaded ZIP not found.', [
                'zip_path' => (string) $zip_path,
            ]);
            return false;
        }

        if (!is_dir($extract_to_dir) && !wp_mkdir_p($extract_to_dir)) {
            $this->log('ERROR: failed to create ZIP extract directory.', [
                'extract_to_dir' => (string) $extract_to_dir,
            ]);
            return false;
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($zip_path);
        if ($opened !== true) {
            $this->log('ERROR: failed to open downloaded ZIP.', [
                'zip_path' => (string) $zip_path,
                'zip_error' => (string) $opened,
            ]);
            return false;
        }

        $ok = (bool) $zip->extractTo($extract_to_dir);
        $zip->close();

        if (!$ok) {
            $this->log('ERROR: failed to extract downloaded ZIP.', [
                'zip_path' => (string) $zip_path,
                'extract_to_dir' => (string) $extract_to_dir,
            ]);
        }

        return $ok;
    }

    /**
     * Retrieve and validate FTP credentials from RSR distributor settings.
     *
     * @return array{host:string,username:string,password:string,use_ssl:bool,port:int}|null
     */
    public function get_ftp_credentials(): ?array
    {
        $loaded = RSRFtpCredentials::load();

        if ($loaded['credentials'] === null) {
            $this->log('Missing FTP credentials', [
                'host' => !empty($loaded['has_host']) ? 'set' : 'empty',
                'user' => !empty($loaded['has_username']) ? 'set' : 'empty',
            ]);
            return null;
        }

        return $loaded['credentials'];
    }

    // --------------------------------------------------
    // Debug / profiling helpers (DebugLogUtil)
    // --------------------------------------------------

    private function cron_logger(): CronRunLogger
    {
        return CronRunLogger::create(self::DEBUG_FLAG, self::LOG_PREFIX);
    }

    /** @param array<string,mixed> $ctx */
    private function log(string $msg, array $ctx = []): void
    {
        $this->cron_logger()->log($msg, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $this->cron_logger()->profile($label, $t0, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function finalize_run(float $t_start, int $mem_start, string $status, array $ctx = []): void
    {
        $this->cron_logger()->finishWithTotalProfile($t_start, $mem_start, $status, $ctx);
    }

    /**
     * Legacy method retained for compatibility if other code calls it.
     * (No longer used by this class after migrating to DebugLogUtil.)
     */
    private function log_debug(string $message): void
    {
        $this->log($message);
    }

    /**
     * Legacy method retained for compatibility if other code calls it.
     * (No longer used by this class after migrating to DebugLogUtil.)
     */
    private function log_memory_summary(int $mem_start): void
    {
        $this->cron_logger()->logMemorySummary($mem_start);
    }

}
