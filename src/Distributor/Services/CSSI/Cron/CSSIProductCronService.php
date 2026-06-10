<?php

namespace FFLHub\Distributor\Services\CSSI\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Cron\CronRunLogger;
use FFLHub\Distributor\Services\CSSI\API\CSSIClient;
use FFLHub\Distributor\Services\CSSI\CSSIOfferNormalizationService;
use FFLHub\Distributor\Services\CSSI\CSSIProductImporterService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;

/**
 * Minute worker for CSSI full catalog refresh.
 *
 * Flow:
 * - After a successful import/swap, enforce a 24h cooldown.
 * - When cooldown expires, request product-feed URL once and cache it.
 * - On the next run(s), try downloading/importing from cached URL each minute
 *   until success, then restart cooldown.
 */
final class CSSIProductCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_cssi_product_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][CSSIProductCron]';
    private const DOWNLOAD_DIR = 'fflhub-cssi';
    private const DOWNLOAD_FILE = 'cssi_product_feed.csv';
    private const PRODUCT_FEED_OPTIONAL_COLUMNS = 'retail_map';
    private const FEED_REFRESH_INTERVAL_SECONDS = DAY_IN_SECONDS;
    private const OPT_LAST_SUCCESS_TS = 'fflhub_cssi_fulfillment_last_success_ts';
    private const OPT_PENDING_FEED_URL = 'fflhub_cssi_fulfillment_pending_feed_url';
    private const OPT_PENDING_FEED_URL_TS = 'fflhub_cssi_fulfillment_pending_feed_url_ts';
    private const OPT_FEED_URL_RETRY_NOT_BEFORE_TS = 'fflhub_cssi_fulfillment_feed_url_retry_not_before_ts';

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
        return MINUTE_IN_SECONDS;
    }

    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    protected function get_initial_delay_seconds(): int
    {
        return MINUTE_IN_SECONDS;
    }

    public function run(): void
    {
        $tStart = microtime(true);
        $memStart = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        $runId = substr(sha1((string) microtime(true) . '|' . mt_rand()), 0, 10);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        update_option('fflhub_cssi_fulfillment_last_run', current_time('mysql'));

        $this->log('---- RUN START ----', [
            'run_id' => $runId,
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'memory_kb' => $memStart > 0 ? (int) round($memStart / 1024) : 0,
            'hook' => self::CRON_HOOK,
            'interval_seconds' => (int) $this->get_interval_seconds(),
            'group' => $this->get_action_group(),
        ]);

        $tCreds = microtime(true);
        $creds = $this->get_api_credentials();
        $this->profile('resolve API credentials', $tCreds, [
            'ok' => is_array($creds) ? 1 : 0,
            'sid_prefix' => is_array($creds) ? $this->mask_sid((string) ($creds['sid'] ?? '')) : '[missing]',
        ]);
        if (!is_array($creds)) {
            update_option('fflhub_cssi_fulfillment_last_download_error', current_time('mysql'));
            $this->finalize_run($tStart, $memStart, 'ERROR (missing credentials)', ['run_id' => $runId]);
            return;
        }

        $tPath = microtime(true);
        $outputPath = $this->resolve_output_path();
        $this->profile('resolve output path', $tPath, [
            'ok' => is_string($outputPath) ? 1 : 0,
            'path' => is_string($outputPath) ? $outputPath : '',
        ]);
        if (!is_string($outputPath) || $outputPath === '') {
            update_option('fflhub_cssi_fulfillment_last_download_error', current_time('mysql'));
            $this->finalize_run($tStart, $memStart, 'ERROR (path)', ['run_id' => $runId]);
            return;
        }

        $client = new CSSIClient((string) $creds['sid'], (string) $creds['token']);
        $now = time();

        $lastSuccessTs = (int) get_option(self::OPT_LAST_SUCCESS_TS, 0);
        $pendingFeedUrl = trim((string) get_option(self::OPT_PENDING_FEED_URL, ''));
        $pendingFeedUrlTs = (int) get_option(self::OPT_PENDING_FEED_URL_TS, 0);

        $this->profile('read feed state', microtime(true), [
            'last_success_ts' => $lastSuccessTs > 0 ? $lastSuccessTs : null,
            'pending_url_present' => $pendingFeedUrl !== '' ? 1 : 0,
            'pending_url_age_sec' => ($pendingFeedUrlTs > 0) ? max(0, $now - $pendingFeedUrlTs) : null,
            'refresh_interval_sec' => self::FEED_REFRESH_INTERVAL_SECONDS,
        ]);

        if ($pendingFeedUrl !== '' && $pendingFeedUrlTs > 0 && ($now - $pendingFeedUrlTs) >= self::FEED_REFRESH_INTERVAL_SECONDS) {
            $this->log('Pending product-feed URL exceeded 24h age; clearing and requesting a fresh URL.', [
                'pending_url_age_sec' => (int) ($now - $pendingFeedUrlTs),
                'pending_url_head' => $this->truncate($pendingFeedUrl, 220),
            ]);
            delete_option(self::OPT_PENDING_FEED_URL);
            delete_option(self::OPT_PENDING_FEED_URL_TS);
            $pendingFeedUrl = '';
            $pendingFeedUrlTs = 0;
        }

        if ($lastSuccessTs > 0) {
            $nextEligibleTs = $lastSuccessTs + self::FEED_REFRESH_INTERVAL_SECONDS;
            if ($now < $nextEligibleTs) {
                $remaining = (int) max(0, $nextEligibleTs - $now);
                $this->log('Cooldown active after successful CSSI import; skipping URL request/download/import.', [
                    'last_success_ts' => $lastSuccessTs,
                    'next_eligible_ts' => $nextEligibleTs,
                    'remaining_sec' => $remaining,
                    'remaining_min' => (int) ceil($remaining / 60),
                    'run_id' => $runId,
                ]);
                $this->finalize_run($tStart, $memStart, 'SUCCESS (cooldown)', [
                    'run_id' => $runId,
                    'remaining_sec' => $remaining,
                ]);
                return;
            }
        }

        if ($pendingFeedUrl === '') {
            $feedRetryNotBeforeTs = (int) get_option(self::OPT_FEED_URL_RETRY_NOT_BEFORE_TS, 0);
            if ($feedRetryNotBeforeTs > 0 && $now < $feedRetryNotBeforeTs) {
                $remaining = (int) max(0, $feedRetryNotBeforeTs - $now);
                $this->log('CSSI product-feed URL request backoff active after prior rate-limit response; skipping request.', [
                    'retry_not_before_ts' => $feedRetryNotBeforeTs,
                    'remaining_sec' => $remaining,
                    'remaining_min' => (int) ceil($remaining / 60),
                    'run_id' => $runId,
                ]);
                $this->finalize_run($tStart, $memStart, 'SUCCESS (feed URL backoff)', [
                    'run_id' => $runId,
                    'remaining_sec' => $remaining,
                ]);
                return;
            }

            $tFeed = microtime(true);
            $feedRes = $client->get_product_feed_url([
                'optional_columns' => self::PRODUCT_FEED_OPTIONAL_COLUMNS,
            ]);
            $feedOk = (bool) ($feedRes['ok'] ?? false);
            $feedUrl = trim((string) ($feedRes['url'] ?? ''));
            $this->profile('fetch product-feed URL', $tFeed, [
                'ok' => $feedOk ? 1 : 0,
                'status' => (int) ($feedRes['status'] ?? 0),
                'optional_columns' => self::PRODUCT_FEED_OPTIONAL_COLUMNS,
                'url_head' => $this->truncate($feedUrl, 220),
                'error' => $feedOk ? '' : (string) ($feedRes['error'] ?? 'Unknown error'),
            ]);

            if (!$feedOk || $feedUrl === '') {
                update_option('fflhub_cssi_fulfillment_last_download_error', current_time('mysql'));
                $feedStatus = (int) ($feedRes['status'] ?? 0);
                $feedError = (string) ($feedRes['error'] ?? 'Unknown error');
                if ($feedStatus === 429) {
                    $waitSeconds = $this->extract_cssi_wait_seconds($feedError);
                    $retryNotBeforeTs = $now + $waitSeconds;
                    update_option(self::OPT_FEED_URL_RETRY_NOT_BEFORE_TS, $retryNotBeforeTs, false);
                    $this->log('CSSI product-feed endpoint rate-limited; next URL request deferred.', [
                        'wait_seconds' => $waitSeconds,
                        'retry_not_before_ts' => $retryNotBeforeTs,
                        'run_id' => $runId,
                    ]);
                }
                $this->log('ERROR: CSSI product-feed URL lookup failed.', [
                    'status' => $feedStatus,
                    'error' => $feedError,
                    'run_id' => $runId,
                ]);
                $this->finalize_run($tStart, $memStart, 'ERROR (feed URL)', ['run_id' => $runId]);
                return;
            }

            delete_option(self::OPT_FEED_URL_RETRY_NOT_BEFORE_TS);
            update_option(self::OPT_PENDING_FEED_URL, $feedUrl, false);
            update_option(self::OPT_PENDING_FEED_URL_TS, $now, false);
            update_option('fflhub_cssi_fulfillment_last_download_url', $feedUrl);
            delete_option('fflhub_cssi_fulfillment_last_download_error');

            $this->log('CSSI product-feed URL cached; download/import will begin on next cron run.', [
                'run_id' => $runId,
                'optional_columns' => self::PRODUCT_FEED_OPTIONAL_COLUMNS,
                'feed_url_head' => $this->truncate($feedUrl, 220),
                'feed_url_cached_ts' => $now,
            ]);
            $this->finalize_run($tStart, $memStart, 'SUCCESS (URL cached)', [
                'run_id' => $runId,
            ]);
            return;
        }

        $feedUrl = $pendingFeedUrl;
        $tDownload = microtime(true);
        $downloadRes = $client->download_file($feedUrl, $outputPath);
        $downloadOk = (bool) ($downloadRes['ok'] ?? false);
        $downloadBytes = (int) ($downloadRes['bytes'] ?? 0);
        $this->profile('download product-feed CSV', $tDownload, [
            'ok' => $downloadOk ? 1 : 0,
            'status' => (int) ($downloadRes['status'] ?? 0),
            'bytes' => $downloadBytes,
            'path' => $outputPath,
            'optional_columns' => self::PRODUCT_FEED_OPTIONAL_COLUMNS,
            'feed_url_age_sec' => ($pendingFeedUrlTs > 0) ? max(0, $now - $pendingFeedUrlTs) : null,
            'error' => $downloadOk ? '' : (string) ($downloadRes['error'] ?? 'Unknown error'),
        ]);

        if (!$downloadOk || $downloadBytes <= 0) {
            update_option('fflhub_cssi_fulfillment_last_download_error', current_time('mysql'));
            $this->log('ERROR: CSSI product-feed CSV download failed.', [
                'status' => (int) ($downloadRes['status'] ?? 0),
                'bytes' => $downloadBytes,
                'path' => $outputPath,
                'feed_url_head' => $this->truncate($feedUrl, 220),
                'feed_url_age_sec' => ($pendingFeedUrlTs > 0) ? max(0, $now - $pendingFeedUrlTs) : null,
                'error' => (string) ($downloadRes['error'] ?? 'Unknown error'),
                'run_id' => $runId,
            ]);
            $this->finalize_run($tStart, $memStart, 'ERROR (download)', ['run_id' => $runId]);
            return;
        }

        update_option('fflhub_cssi_fulfillment_last_download', current_time('mysql'));
        update_option('fflhub_cssi_fulfillment_last_download_ts', (string) time());
        update_option('fflhub_cssi_fulfillment_last_download_size', (string) $downloadBytes);
        update_option('fflhub_cssi_fulfillment_last_download_path', $outputPath);
        update_option('fflhub_cssi_fulfillment_last_download_url', $feedUrl);
        delete_option('fflhub_cssi_fulfillment_last_download_error');

        $importer = new CSSIProductImporterService($this->table);
        $tImport = microtime(true);
        try {
            $imported = (int) $importer->import_from_csv_file($outputPath);
        } catch (\Throwable $e) {
            update_option('fflhub_cssi_fulfillment_last_import_error', current_time('mysql'));
            $this->log('ERROR: CSSI product-feed import exception.', [
                'error' => $e->getMessage(),
                'path' => $outputPath,
                'run_id' => $runId,
            ]);
            $this->profile('import product-feed CSV (failed)', $tImport);
            $this->finalize_run($tStart, $memStart, 'ERROR (import exception)', ['run_id' => $runId]);
            return;
        }
        $this->profile('import product-feed CSV', $tImport, [
            'imported_rows' => $imported,
            'path' => $outputPath,
        ]);

        if ($imported <= 0) {
            update_option('fflhub_cssi_fulfillment_last_import_error', current_time('mysql'));
            $this->log('ERROR: CSSI product-feed import produced 0 rows; swap skipped.', [
                'path' => $outputPath,
                'run_id' => $runId,
            ]);
            $this->finalize_run($tStart, $memStart, 'ERROR (0 imported)', ['run_id' => $runId]);
            return;
        }

        $tSwap = microtime(true);
        try {
            $newLive = (string) $this->table->swap_live_and_staging();
        } catch (\Throwable $e) {
            update_option('fflhub_cssi_fulfillment_last_swap_error', current_time('mysql'));
            $this->log('ERROR: CSSI product-feed swap exception.', [
                'error' => $e->getMessage(),
                'run_id' => $runId,
            ]);
            $this->profile('swap staging/live (failed)', $tSwap);
            $this->finalize_run($tStart, $memStart, 'ERROR (swap exception)', ['run_id' => $runId]);
            return;
        }
        $this->profile('swap staging/live', $tSwap, ['new_live' => $newLive]);

        $offersResult = $this->update_distributor_offers_from_new_live_table();

        update_option('fflhub_cssi_fulfillment_last_import', current_time('mysql'));
        update_option('fflhub_cssi_fulfillment_last_import_count', (int) $imported);
        update_option('fflhub_cssi_fulfillment_last_swap', current_time('mysql'));
        update_option(self::OPT_LAST_SUCCESS_TS, $now, false);
        delete_option(self::OPT_PENDING_FEED_URL);
        delete_option(self::OPT_PENDING_FEED_URL_TS);
        delete_option('fflhub_cssi_fulfillment_last_import_error');
        delete_option('fflhub_cssi_fulfillment_last_swap_error');

        $this->log('CSSI full catalog refresh complete.', [
            'run_id' => $runId,
            'optional_columns' => self::PRODUCT_FEED_OPTIONAL_COLUMNS,
            'product_feed_url_head' => $this->truncate($feedUrl, 220),
            'pending_url_age_sec' => ($pendingFeedUrlTs > 0) ? max(0, $now - $pendingFeedUrlTs) : null,
            'download_bytes' => $downloadBytes,
            'imported_rows' => $imported,
            'last_success_ts' => $now,
            'new_live' => $newLive,
            'distributor_offers_ok' => !empty($offersResult['ok']) ? 1 : 0,
            'distributor_offers_cssi_inserted_missing_rows' => (int) ($offersResult['inserted_missing_offers'] ?? 0),
            'distributor_offers_cssi_updated_changed_rows' => (int) ($offersResult['updated_changed_offers'] ?? 0),
            'distributor_offers_cssi_stale_disabled_rows' => (int) ($offersResult['stale_disabled'] ?? 0),
        ]);

        $this->finalize_run($tStart, $memStart, 'SUCCESS', [
            'run_id' => $runId,
            'download_bytes' => $downloadBytes,
            'imported_rows' => $imported,
            'last_success_ts' => $now,
            'distributor_offers_ok' => !empty($offersResult['ok']) ? 1 : 0,
            'distributor_offers_cssi_inserted_missing_rows' => (int) ($offersResult['inserted_missing_offers'] ?? 0),
            'distributor_offers_cssi_updated_changed_rows' => (int) ($offersResult['updated_changed_offers'] ?? 0),
            'distributor_offers_cssi_stale_disabled_rows' => (int) ($offersResult['stale_disabled'] ?? 0),
        ]);
    }

    /**
     * Sync normalized offer rows for carried UPCs after the product table swap.
     *
     * @return array<string,mixed>
     */
    private function update_distributor_offers_from_new_live_table(): array
    {
        $tOffers = microtime(true);
        $liveTable = $this->table->get_live_table_name();
        $offersResult = [];

        try {
            $offersResult = CSSIOfferNormalizationService::normalize_from_product_table($liveTable);
        } catch (\Throwable $e) {
            $offersResult = [
                'ok' => false,
                'source_live_table' => (string) $liveTable,
                'errors' => [$e->getMessage()],
            ];
        }

        $this->profile('Sync distributor offers from new live table', $tOffers, [
            'source_live_table' => (string) ($offersResult['source_live_table'] ?? $liveTable),
            'active_product_state_total' => (int) ($offersResult['active_product_state_total'] ?? 0),
            'matched_active_cssi_upcs' => (int) ($offersResult['matched_active_cssi_upcs'] ?? 0),
            'distributor_offers_cssi_inserted_missing_rows' => (int) ($offersResult['inserted_missing_offers'] ?? 0),
            'distributor_offers_cssi_insert_missing_ms' => (string) ($offersResult['insert_missing_elapsed_ms'] ?? '0.00'),
            'distributor_offers_cssi_updated_changed_rows' => (int) ($offersResult['updated_changed_offers'] ?? 0),
            'distributor_offers_cssi_update_changed_ms' => (string) ($offersResult['update_changed_elapsed_ms'] ?? '0.00'),
            'distributor_offers_cssi_upsert_rows' => (int) ($offersResult['upsert_mysql_affected_rows'] ?? 0),
            'distributor_offers_cssi_upsert_ms' => (string) ($offersResult['upsert_elapsed_ms'] ?? '0.00'),
            'distributor_offers_cssi_stale_disabled_rows' => (int) ($offersResult['stale_disabled'] ?? 0),
            'distributor_offers_cssi_stale_cleanup_ms' => (string) ($offersResult['stale_cleanup_elapsed_ms'] ?? '0.00'),
            'ok' => !empty($offersResult['ok']) ? 1 : 0,
            'errors' => !empty($offersResult['errors']) ? (array) $offersResult['errors'] : [],
        ]);

        if (empty($offersResult['ok'])) {
            $this->log('ERROR: CSSI distributor offers update failed after product swap', [
                'source_live_table' => (string) ($offersResult['source_live_table'] ?? $liveTable),
                'errors' => !empty($offersResult['errors']) ? (array) $offersResult['errors'] : [],
            ]);
        }

        return $offersResult;
    }

    private function extract_cssi_wait_seconds(string $error): int
    {
        $error = trim($error);
        if ($error === '') {
            return 15 * MINUTE_IN_SECONDS;
        }

        if (preg_match('/wait\s+(\d+)\s+minutes?/i', $error, $m) === 1) {
            $minutes = (int) ($m[1] ?? 0);
            if ($minutes > 0) {
                return $minutes * MINUTE_IN_SECONDS;
            }
        }

        if (preg_match('/wait\s+(\d+)\s+seconds?/i', $error, $m) === 1) {
            $seconds = (int) ($m[1] ?? 0);
            if ($seconds > 0) {
                return $seconds;
            }
        }

        return 15 * MINUTE_IN_SECONDS;
    }

    /**
     * @return array{sid:string,token:string}|null
     */
    private function get_api_credentials(): ?array
    {
        $sid = trim((string) Options::get_distributor_option('cssi', 'sid', ''));
        $token = trim((string) Options::get_distributor_option('cssi', 'token', ''));

        if ($sid === '' || $token === '') {
            $this->log('Missing CSSI API credentials.', [
                'has_sid' => $sid !== '' ? 1 : 0,
                'has_token' => $token !== '' ? 1 : 0,
            ]);
            return null;
        }

        return [
            'sid' => $sid,
            'token' => $token,
        ];
    }

    private function resolve_output_path(): ?string
    {
        $uploads = wp_upload_dir();
        $baseDir = trailingslashit((string) ($uploads['basedir'] ?? '')) . self::DOWNLOAD_DIR;

        if ($baseDir === '' || !wp_mkdir_p($baseDir)) {
            $this->log('Failed to create CSSI download directory.', [
                'base_dir' => $baseDir,
            ]);
            return null;
        }

        return trailingslashit($baseDir) . self::DOWNLOAD_FILE;
    }

    private function mask_sid(string $sid): string
    {
        $sid = trim($sid);
        if ($sid === '') {
            return '[empty]';
        }

        if (strlen($sid) <= 4) {
            return str_repeat('*', strlen($sid));
        }

        return substr($sid, 0, 2) . str_repeat('*', strlen($sid) - 4) . substr($sid, -2);
    }

    private function truncate(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '' || $max <= 0) {
            return '';
        }

        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max) . '...';
    }

    /**
     * @param array<string,mixed> $ctx
     */
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
    private function finalize_run(float $tStart, int $memStart, string $status, array $ctx = []): void
    {
        $this->cron_logger()->finishWithProfileAndEndMessage($tStart, $memStart, $status, $ctx);
    }
}
