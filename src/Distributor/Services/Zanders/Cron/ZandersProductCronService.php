<?php

namespace FFLHub\Distributor\Services\Zanders\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Cron\CronRunLogger;
use FFLHub\Distributor\Services\Cron\FtpFeedFetcher;
use FFLHub\Distributor\Services\Cron\FtpFeedFetchRequest;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Distributor\Services\Zanders\ZandersProductImporterService;
use FFLHub\Distributor\Services\Zanders\ZandersFtpCredentials;
use FFLHub\Distributor\Services\Zanders\ZandersOfferNormalizationService;

/**
 * WP-Cron job to regularly download the Zanders inventory fulfillment CSV
 * (/Inventory/zandersinv.csv) from the Zanders FTP server into uploads/fflhub-zanders/,
 * then import it into the staging table and swap staging/live.
 */
final class ZandersProductCronService extends AbstractTableCronService
{
    /**
     * Cron hook name for Zanders product refresh.
     */
    public const CRON_HOOK = 'fflhub_zanders_product_update';


    /**
     * Debug gate constant (define('FFLHUB_CRON_DEBUG', true);).
     */
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';

    /**
     * Log prefix.
     */
    private const LOG_PREFIX = '[FFLHUB][ZandersProductCron]';

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

    public function __construct(DoubleBufferedProductTable $table)
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
        // FTP freshness gate (pre-connect throttle/cooldown)
        // ---------------------------------------------------------------------
        $fetch = (new FtpFeedFetcher())->fetch(
            FtpFeedFetchRequest::create([
                'distributor_id' => 'zanders',
                'cron_name' => 'Zanders product cron',
                'credentials' => $creds,
                'remote_path' => $remote_path,
                'local_path' => $local_path,
                'last_checked_option' => self::OPT_LAST_CHECKED_AT,
                'last_applied_mtime_option' => 'fflhub_zanders_fulfillment_last_applied_mtime',
                'last_seen_mtime_option' => 'fflhub_zanders_fulfillment_last_seen_mtime',
                'last_seen_size_option' => 'fflhub_zanders_fulfillment_last_seen_size',
                'last_download_option' => 'fflhub_zanders_fulfillment_last_download',
                'last_download_error_option' => 'fflhub_zanders_fulfillment_last_download_error',
                'min_check_gap_seconds' => self::FTP_MIN_CHECK_GAP_SECONDS,
                'cooldown_seconds' => self::FTP_COOLDOWN_SECONDS,
                'force' => $force_update,
                'ftp_log_prefix' => '[FFLHub][Zanders][FTP]',
                'no_change_log_message' => 'No update available (remote mtime unchanged) - skipping download/import/swap',
            ]),
            $this->cron_logger()
        );

        if ($fetch->is_skipped() || $fetch->is_failed()) {
            $this->finalize_run($t_start, $mem_start, $fetch->cron_status !== '' ? $fetch->cron_status : 'ERROR (download failed)');
            return;
        }

        $remote_mtime = $fetch->remote_mtime;

        // 3) Import into staging.
        $t_import = microtime(true);

        $count = 0;
        try {
            $importer = new ZandersProductImporterService($this->table);
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

        // 4) Swap staging/live.
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

        // 5) Update existing normalized offer fields from the newly live Zanders table.
        $offers_result = $this->update_distributor_offers_from_new_live_table($new_live);

        update_option('fflhub_zanders_fulfillment_last_import', current_time('mysql'));
        update_option('fflhub_zanders_fulfillment_last_import_count', (int) $count);
        update_option('fflhub_zanders_fulfillment_last_swap', current_time('mysql'));

        if ($remote_mtime > 0) {
            update_option('fflhub_zanders_fulfillment_last_applied_mtime', $remote_mtime);
        }

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'imported_rows' => (int) $count,
            'new_live'      => (string) $new_live,
            'distributor_offers_ok' => !empty($offers_result['ok']) ? 1 : 0,
            'distributor_offers_zanders_inserted_missing_rows' => (int) ($offers_result['inserted_missing_offers'] ?? 0),
            'distributor_offers_zanders_updated_changed_rows' => (int) ($offers_result['updated_changed_offers'] ?? 0),
            'distributor_offers_zanders_stale_disabled_rows' => (int) ($offers_result['stale_disabled'] ?? 0),
            'remote_mtime'  => $remote_mtime > 0 ? $remote_mtime : null,
        ]);
    }

    /**
     * Sync normalized offer rows for carried UPCs after the product table swap.
     *
     * @return array<string,mixed>
     */
    private function update_distributor_offers_from_new_live_table(string $new_live): array
    {
        $t_offers = microtime(true);
        $offers_result = [];

        try {
            $offers_result = ZandersOfferNormalizationService::normalize_from_product_table($new_live);
        } catch (\Throwable $e) {
            $offers_result = [
                'ok' => false,
                'source_live_table' => (string) $new_live,
                'errors' => [$e->getMessage()],
            ];
        }

        $this->profile('Sync distributor offers from new live table', $t_offers, [
            'source_live_table' => (string) ($offers_result['source_live_table'] ?? $new_live),
            'active_product_state_total' => (int) ($offers_result['active_product_state_total'] ?? 0),
            'matched_active_zanders_upcs' => (int) ($offers_result['matched_active_zanders_upcs'] ?? 0),
            'distributor_offers_zanders_inserted_missing_rows' => (int) ($offers_result['inserted_missing_offers'] ?? 0),
            'distributor_offers_zanders_insert_missing_ms' => (string) ($offers_result['insert_missing_elapsed_ms'] ?? '0.00'),
            'distributor_offers_zanders_updated_changed_rows' => (int) ($offers_result['updated_changed_offers'] ?? 0),
            'distributor_offers_zanders_update_changed_ms' => (string) ($offers_result['update_changed_elapsed_ms'] ?? '0.00'),
            'distributor_offers_zanders_upsert_rows' => (int) ($offers_result['upsert_mysql_affected_rows'] ?? 0),
            'distributor_offers_zanders_upsert_ms' => (string) ($offers_result['upsert_elapsed_ms'] ?? '0.00'),
            'distributor_offers_zanders_stale_disabled_rows' => (int) ($offers_result['stale_disabled'] ?? 0),
            'distributor_offers_zanders_stale_cleanup_ms' => (string) ($offers_result['stale_cleanup_elapsed_ms'] ?? '0.00'),
            'ok' => !empty($offers_result['ok']) ? 1 : 0,
            'errors' => !empty($offers_result['errors']) ? (array) $offers_result['errors'] : [],
        ]);

        if (empty($offers_result['ok'])) {
            $this->log('ERROR: Zanders distributor offers update failed after product swap', [
                'source_live_table' => (string) ($offers_result['source_live_table'] ?? $new_live),
                'errors' => !empty($offers_result['errors']) ? (array) $offers_result['errors'] : [],
            ]);
        }

        return $offers_result;
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
        $loaded = ZandersFtpCredentials::load();
        $creds = $loaded['credentials'];

        if (!is_array($creds)) {
            $this->log('Missing FTP credentials', [
                'host' => $loaded['has_host'] ? 'set' : 'empty',
                'user' => $loaded['has_username'] ? 'set' : 'empty',
            ]);
            return null;
        }

        return $creds;
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

}
