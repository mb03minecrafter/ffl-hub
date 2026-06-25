<?php

namespace FFLHub\Distributor\Services\BillHicks\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Cron\CronRunLogger;
use FFLHub\Distributor\Services\Cron\FtpFeedFetcher;
use FFLHub\Distributor\Services\Cron\FtpFeedFetchRequest;
use FFLHub\Distributor\Services\BillHicks\BillHicksFtpCredentials;
use FFLHub\Distributor\Services\BillHicks\BillHicksOfferNormalizationService;
use FFLHub\Distributor\Services\BillHicks\BillHicksProductImporterService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;

/**
 * Bill Hicks full catalog/product cron.
 *
 * Downloads /DeerfordDefense/Feeds/BHC_Catalog.csv from HostedFTP, imports it
 * into the staging table, swaps staging/live, then syncs normalized offer rows
 * for active product_state UPCs.
 */
final class BillHicksProductCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_bill_hicks_product_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHUB][BillHicksProductCron]';

    private const DEFAULT_REMOTE_PATH = '/DeerfordDefense/Feeds/BHC_Catalog.csv';
    private const LOCAL_DIR = 'fflhub-bill-hicks';
    private const LOCAL_FILE_NAME = 'BHC_Catalog.csv';

    private const OPT_LAST_CHECKED_AT = 'fflhub_bill_hicks_product_last_checked_at';
    private const OPT_LAST_APPLIED_MTIME = 'fflhub_bill_hicks_product_last_applied_mtime';
    private const OPT_LAST_SEEN_MTIME = 'fflhub_bill_hicks_product_last_seen_mtime';
    private const OPT_LAST_SEEN_SIZE = 'fflhub_bill_hicks_product_last_seen_size';
    private const OPT_LAST_DOWNLOAD = 'fflhub_bill_hicks_product_last_download';
    private const OPT_LAST_DOWNLOAD_ERROR = 'fflhub_bill_hicks_product_last_download_error';

    private const FTP_COOLDOWN_SECONDS = 20 * HOUR_IN_SECONDS;
    private const FTP_MIN_CHECK_GAP_SECONDS = 5 * MINUTE_IN_SECONDS;

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
        $t_start = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $force_update = $this->should_force_update();
        update_option('fflhub_bill_hicks_product_last_run', current_time('mysql'));

        $this->log('---- RUN START ----', [
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'memory_kb' => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
            'hook' => self::CRON_HOOK,
            'group' => $this->get_action_group(),
            'interval_sec' => $this->get_interval_seconds(),
            'force_update' => $force_update ? 1 : 0,
        ]);

        if ($force_update) {
            $this->log('FORCE_UPDATE enabled - bypassing cooldown/mtime gates');
        }

        // 1. Load FTP credentials from Bill Hicks distributor settings.
        $t_credentials = microtime(true);
        $creds = $this->get_ftp_credentials();
        $this->profile('Credentials retrieval', $t_credentials, [
            'ok' => is_array($creds) ? 1 : 0,
            'has_host' => is_array($creds) ? (bool) ($creds['host'] ?? '') : false,
            'has_user' => is_array($creds) ? (bool) ($creds['username'] ?? '') : false,
            'has_ssl' => is_array($creds) ? (bool) ($creds['use_ssl'] ?? false) : false,
            'port' => is_array($creds) ? (int) ($creds['port'] ?? 0) : 0,
        ]);

        if (!is_array($creds)) {
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing credentials)');
            return;
        }

        // 2. Resolve local save path and configured remote catalog path.
        $t_paths = microtime(true);
        $uploads = wp_upload_dir();
        $base_dir = trailingslashit((string) ($uploads['basedir'] ?? '')) . self::LOCAL_DIR;

        if (!wp_mkdir_p($base_dir)) {
            $this->log('ERROR: failed to create base directory', [
                'base_dir' => (string) $base_dir,
            ]);
            $this->profile('Prepare local paths (mkdir failed)', $t_paths);
            $this->finalize_run($t_start, $mem_start, 'ERROR (mkdir failed)');
            return;
        }

        $remote_path = $this->get_remote_product_path();
        $local_path = trailingslashit($base_dir) . self::LOCAL_FILE_NAME;

        $this->profile('Prepare local paths', $t_paths, [
            'base_dir' => (string) $base_dir,
            'remote_csv' => (string) $remote_path,
            'local_csv' => (string) $local_path,
        ]);

        // 3. FTP freshness gate and download.
        $fetch = (new FtpFeedFetcher())->fetch(
            FtpFeedFetchRequest::create([
                'distributor_id' => 'bill_hicks',
                'cron_name' => 'Bill Hicks product cron',
                'credentials' => $creds,
                'remote_path' => $remote_path,
                'local_path' => $local_path,
                'last_checked_option' => self::OPT_LAST_CHECKED_AT,
                'last_applied_mtime_option' => self::OPT_LAST_APPLIED_MTIME,
                'last_seen_mtime_option' => self::OPT_LAST_SEEN_MTIME,
                'last_seen_size_option' => self::OPT_LAST_SEEN_SIZE,
                'last_download_option' => self::OPT_LAST_DOWNLOAD,
                'last_download_error_option' => self::OPT_LAST_DOWNLOAD_ERROR,
                'min_check_gap_seconds' => self::FTP_MIN_CHECK_GAP_SECONDS,
                'cooldown_seconds' => self::FTP_COOLDOWN_SECONDS,
                'timeout_seconds' => 60,
                'min_bytes' => 1024,
                'force' => $force_update,
                'ftp_log_prefix' => '[FFLHub][BillHicks][FTP]',
                'no_change_log_message' => 'No update available (remote mtime unchanged) - skipping download/import/swap',
            ]),
            $this->cron_logger()
        );

        if ($fetch->is_skipped() || $fetch->is_failed()) {
            $this->finalize_run(
                $t_start,
                $mem_start,
                $fetch->cron_status !== '' ? $fetch->cron_status : 'ERROR (download failed)'
            );
            return;
        }

        // 4. Import the downloaded catalog into staging.
        $t_import = microtime(true);
        try {
            $imported_rows = (int) (new BillHicksProductImporterService($this->table))->import_file($local_path);
        } catch (\Throwable $e) {
            $this->log('ERROR: exception during import', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Import into staging (failed)', $t_import);
            $this->finalize_run($t_start, $mem_start, 'ERROR (import exception)');
            return;
        }

        $this->profile('Import into staging', $t_import, [
            'imported_rows' => (int) $imported_rows,
        ]);

        if ($imported_rows <= 0) {
            $this->log('ERROR: import completed but 0 rows processed, not swapping.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (0 rows imported)');
            return;
        }

        // 5. Swap staging/live only after the import has produced rows.
        $t_swap = microtime(true);
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

        // 6. Sync normalized offer rows from the newly live Bill Hicks table.
        $offers_result = $this->update_distributor_offers_from_new_live_table();

        update_option('fflhub_bill_hicks_product_last_import', current_time('mysql'));
        update_option('fflhub_bill_hicks_product_last_import_count', (int) $imported_rows);
        update_option('fflhub_bill_hicks_product_last_swap', current_time('mysql'));

        if ($fetch->remote_mtime > 0) {
            update_option(self::OPT_LAST_APPLIED_MTIME, (int) $fetch->remote_mtime);
        }

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'imported_rows' => (int) $imported_rows,
            'new_live' => (string) $new_live,
            'distributor_offers_ok' => !empty($offers_result['ok']) ? 1 : 0,
            'distributor_offers_bill_hicks_inserted_missing_rows' => (int) ($offers_result['inserted_missing_offers'] ?? 0),
            'distributor_offers_bill_hicks_updated_changed_rows' => (int) ($offers_result['updated_changed_offers'] ?? 0),
            'distributor_offers_bill_hicks_stale_disabled_rows' => (int) ($offers_result['stale_disabled'] ?? 0),
            'remote_mtime' => $fetch->remote_mtime > 0 ? (int) $fetch->remote_mtime : null,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function update_distributor_offers_from_new_live_table(): array
    {
        $t_offers = microtime(true);
        $live_table = $this->table->get_live_table_name();

        try {
            $offers_result = BillHicksOfferNormalizationService::normalize_from_product_table($live_table);
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
            'matched_active_bill_hicks_upcs' => (int) ($offers_result['matched_active_bill_hicks_upcs'] ?? 0),
            'distributor_offers_bill_hicks_inserted_missing_rows' => (int) ($offers_result['inserted_missing_offers'] ?? 0),
            'distributor_offers_bill_hicks_insert_missing_ms' => (string) ($offers_result['insert_missing_elapsed_ms'] ?? '0.00'),
            'distributor_offers_bill_hicks_updated_changed_rows' => (int) ($offers_result['updated_changed_offers'] ?? 0),
            'distributor_offers_bill_hicks_update_changed_ms' => (string) ($offers_result['update_changed_elapsed_ms'] ?? '0.00'),
            'distributor_offers_bill_hicks_upsert_rows' => (int) ($offers_result['upsert_mysql_affected_rows'] ?? 0),
            'distributor_offers_bill_hicks_upsert_ms' => (string) ($offers_result['upsert_elapsed_ms'] ?? '0.00'),
            'distributor_offers_bill_hicks_stale_disabled_rows' => (int) ($offers_result['stale_disabled'] ?? 0),
            'distributor_offers_bill_hicks_stale_cleanup_ms' => (string) ($offers_result['stale_cleanup_elapsed_ms'] ?? '0.00'),
            'ok' => !empty($offers_result['ok']) ? 1 : 0,
            'errors' => !empty($offers_result['errors']) ? (array) $offers_result['errors'] : [],
        ]);

        if (empty($offers_result['ok'])) {
            $this->log('ERROR: Bill Hicks distributor offers update failed after product swap', [
                'source_live_table' => (string) ($offers_result['source_live_table'] ?? $live_table),
                'errors' => !empty($offers_result['errors']) ? (array) $offers_result['errors'] : [],
            ]);
        }

        return $offers_result;
    }

    /**
     * @return array{host:string,username:string,password:string,use_ssl:bool,port:int}|null
     */
    private function get_ftp_credentials(): ?array
    {
        $loaded = BillHicksFtpCredentials::load();
        $creds = $loaded['credentials'];

        if (!is_array($creds)) {
            $this->log('Missing FTP credentials', [
                'host' => !empty($loaded['has_host']) ? 'set' : 'empty',
                'user' => !empty($loaded['has_username']) ? 'set' : 'empty',
                'password' => !empty($loaded['has_password']) ? 'set' : 'empty',
            ]);
            return null;
        }

        return $creds;
    }

    private function get_remote_product_path(): string
    {
        $path = trim((string) Options::get_distributor_option(
            'bill_hicks',
            'product_feed_remote_path',
            self::DEFAULT_REMOTE_PATH
        ));

        return $path !== '' ? $path : self::DEFAULT_REMOTE_PATH;
    }

    private function cron_logger(): CronRunLogger
    {
        return CronRunLogger::create(self::DEBUG_FLAG, self::LOG_PREFIX);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        $this->cron_logger()->log($message, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $this->cron_logger()->profile($label, $t0, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function finalize_run(float $t_start, int $mem_start, string $status, array $ctx = []): void
    {
        $this->cron_logger()->finishWithTotalProfile($t_start, $mem_start, $status, $ctx);
    }
}
