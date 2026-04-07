<?php

namespace FFLHub\Distributor\Services\CSSI\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\CSSI\API\CSSIClient;
use FFLHub\Distributor\Services\CSSI\CSSIProductImporterService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * Hourly CSSI full-catalog refresh using /items/product-feed.
 */
final class CSSIProductCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_cssi_product_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][CSSIProductCron]';
    private const DOWNLOAD_DIR = 'fflhub-cssi';
    private const DOWNLOAD_FILE = 'cssi_product_feed.csv';

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
        return HOUR_IN_SECONDS;
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
        $path = $this->resolve_output_path();
        $this->profile('resolve output path', $tPath, [
            'ok' => (is_string($path) && $path !== '') ? 1 : 0,
            'path' => is_string($path) ? $path : '',
        ]);
        if (!is_string($path) || $path === '') {
            update_option('fflhub_cssi_fulfillment_last_download_error', current_time('mysql'));
            $this->finalize_run($tStart, $memStart, 'ERROR (output path)', ['run_id' => $runId]);
            return;
        }

        $client = new CSSIClient((string) $creds['sid'], (string) $creds['token']);

        $tFeed = microtime(true);
        $feedRes = $client->get_product_feed_url([
            'optional_columns' => 'specifications,retail_map',
        ]);
        $feedOk = (bool) ($feedRes['ok'] ?? false);
        $feedUrl = trim((string) ($feedRes['url'] ?? ''));
        $this->profile('fetch product-feed URL', $tFeed, [
            'ok' => $feedOk ? 1 : 0,
            'status' => (int) ($feedRes['status'] ?? 0),
            'url_head' => $this->truncate($feedUrl, 220),
            'error' => $feedOk ? '' : (string) ($feedRes['error'] ?? 'Unknown error'),
        ]);

        if (!$feedOk) {
            update_option('fflhub_cssi_fulfillment_last_download_error', current_time('mysql'));
            $this->log('ERROR: CSSI product-feed URL lookup failed.', [
                'status' => (int) ($feedRes['status'] ?? 0),
                'error' => (string) ($feedRes['error'] ?? 'Unknown error'),
            ]);
            $this->finalize_run($tStart, $memStart, 'ERROR (feed URL)', ['run_id' => $runId]);
            return;
        }

        if ($feedUrl === '') {
            update_option('fflhub_cssi_fulfillment_last_download_error', current_time('mysql'));
            $this->log('ERROR: CSSI product-feed URL was empty.');
            $this->finalize_run($tStart, $memStart, 'ERROR (empty feed URL)', ['run_id' => $runId]);
            return;
        }

        $tDownload = microtime(true);
        $downloadRes = $client->download_file($feedUrl, $path);
        $downloadOk = (bool) ($downloadRes['ok'] ?? false);
        $bytes = (int) ($downloadRes['bytes'] ?? 0);
        $this->profile('download product-feed CSV', $tDownload, [
            'ok' => $downloadOk ? 1 : 0,
            'status' => (int) ($downloadRes['status'] ?? 0),
            'bytes' => $bytes,
            'content_type' => (string) ($downloadRes['content_type'] ?? ''),
            'error' => $downloadOk ? '' : (string) ($downloadRes['error'] ?? 'Unknown error'),
        ]);

        if (!$downloadOk) {
            update_option('fflhub_cssi_fulfillment_last_download_error', current_time('mysql'));
            $this->log('ERROR: CSSI product-feed download failed.', [
                'status' => (int) ($downloadRes['status'] ?? 0),
                'error' => (string) ($downloadRes['error'] ?? 'Unknown error'),
                'url' => $this->truncate($feedUrl, 220),
                'path' => $path,
            ]);
            $this->finalize_run($tStart, $memStart, 'ERROR (download)', ['run_id' => $runId]);
            return;
        }

        update_option('fflhub_cssi_fulfillment_last_download', current_time('mysql'));
        update_option('fflhub_cssi_fulfillment_last_download_ts', (string) time());
        update_option('fflhub_cssi_fulfillment_last_download_size', (string) $bytes);
        update_option('fflhub_cssi_fulfillment_last_download_path', $path);
        update_option('fflhub_cssi_fulfillment_last_download_url', $feedUrl);
        delete_option('fflhub_cssi_fulfillment_last_download_error');

        $importer = new CSSIProductImporterService($this->table);

        $tImport = microtime(true);
        try {
            $count = (int) $importer->import_from_csv_file($path);
        } catch (\Throwable $e) {
            update_option('fflhub_cssi_fulfillment_last_import_error', current_time('mysql'));
            $this->profile('import CSV into staging (exception)', $tImport, [
                'error' => $e->getMessage(),
            ]);
            $this->log('ERROR: CSSI product-feed import exception.', [
                'error' => $e->getMessage(),
            ]);
            $this->finalize_run($tStart, $memStart, 'ERROR (import exception)', ['run_id' => $runId]);
            return;
        }

        $this->profile('import CSV into staging', $tImport, [
            'imported_rows' => (int) $count,
        ]);

        if ($count <= 0) {
            update_option('fflhub_cssi_fulfillment_last_import_error', current_time('mysql'));
            $this->log('ERROR: CSSI product-feed import completed with 0 rows; swap skipped.', [
                'path' => $path,
                'url' => $this->truncate($feedUrl, 220),
            ]);
            $this->finalize_run($tStart, $memStart, 'NO SWAP (0 rows)', [
                'run_id' => $runId,
                'path' => $path,
            ]);
            return;
        }

        $tSwap = microtime(true);
        try {
            $newLive = (string) $this->table->swap_live_and_staging();
        } catch (\Throwable $e) {
            update_option('fflhub_cssi_fulfillment_last_swap_error', current_time('mysql'));
            $this->profile('swap staging/live (exception)', $tSwap, [
                'error' => $e->getMessage(),
                'imported_rows' => (int) $count,
            ]);
            $this->log('ERROR: CSSI product-feed swap exception.', [
                'error' => $e->getMessage(),
            ]);
            $this->finalize_run($tStart, $memStart, 'ERROR (swap exception)', ['run_id' => $runId]);
            return;
        }

        $this->profile('swap staging/live', $tSwap, [
            'new_live' => $newLive,
            'imported_rows' => (int) $count,
        ]);

        update_option('fflhub_cssi_fulfillment_last_import', current_time('mysql'));
        update_option('fflhub_cssi_fulfillment_last_import_count', (int) $count);
        update_option('fflhub_cssi_fulfillment_last_swap', current_time('mysql'));
        delete_option('fflhub_cssi_fulfillment_last_import_error');
        delete_option('fflhub_cssi_fulfillment_last_swap_error');

        $this->log('CSSI full catalog refresh complete.', [
            'run_id' => $runId,
            'bytes' => $bytes,
            'path' => $path,
            'feed_url' => $this->truncate($feedUrl, 220),
            'imported_rows' => (int) $count,
            'new_live' => $newLive,
        ]);

        $this->finalize_run($tStart, $memStart, 'SUCCESS', [
            'run_id' => $runId,
            'imported_rows' => (int) $count,
            'bytes' => $bytes,
        ]);
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
    private function log(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $message);
            return;
        }

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $message, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $ctx['elapsed_ms'] = number_format((microtime(true) - $t0) * 1000, 2);
        $this->log('PROFILE: ' . $label, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function finalize_run(float $tStart, int $memStart, string $status, array $ctx = []): void
    {
        $ctx['status'] = $status;
        $this->profile('Total cron run', $tStart, $ctx);

        $memEnd = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        if ($memStart > 0 && $memEnd > 0) {
            $this->log('Memory usage summary', [
                'start_kb' => (int) round($memStart / 1024),
                'end_kb' => (int) round($memEnd / 1024),
                'delta_kb' => (int) round(($memEnd - $memStart) / 1024),
            ]);
        }

        $this->log('---- RUN END (' . $status . ') ----');
    }
}
