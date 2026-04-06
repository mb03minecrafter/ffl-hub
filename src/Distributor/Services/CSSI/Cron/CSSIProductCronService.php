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
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        update_option('fflhub_cssi_fulfillment_last_run', current_time('mysql'));

        $creds = $this->get_api_credentials();
        if (!is_array($creds)) {
            update_option('fflhub_cssi_fulfillment_last_download_error', current_time('mysql'));
            return;
        }

        $path = $this->resolve_output_path();
        if (!is_string($path) || $path === '') {
            update_option('fflhub_cssi_fulfillment_last_download_error', current_time('mysql'));
            return;
        }

        $client = new CSSIClient((string) $creds['sid'], (string) $creds['token']);

        $feedRes = $client->get_product_feed_url([
            'optional_columns' => 'specifications,retail_map',
        ]);
        if (!(bool) ($feedRes['ok'] ?? false)) {
            update_option('fflhub_cssi_fulfillment_last_download_error', current_time('mysql'));
            $this->log('ERROR: CSSI product-feed URL lookup failed.', [
                'status' => (int) ($feedRes['status'] ?? 0),
                'error' => (string) ($feedRes['error'] ?? 'Unknown error'),
            ]);
            return;
        }

        $feedUrl = trim((string) ($feedRes['url'] ?? ''));
        if ($feedUrl === '') {
            update_option('fflhub_cssi_fulfillment_last_download_error', current_time('mysql'));
            $this->log('ERROR: CSSI product-feed URL was empty.');
            return;
        }

        $downloadRes = $client->download_file($feedUrl, $path);
        if (!(bool) ($downloadRes['ok'] ?? false)) {
            update_option('fflhub_cssi_fulfillment_last_download_error', current_time('mysql'));
            $this->log('ERROR: CSSI product-feed download failed.', [
                'status' => (int) ($downloadRes['status'] ?? 0),
                'error' => (string) ($downloadRes['error'] ?? 'Unknown error'),
                'url' => $feedUrl,
            ]);
            return;
        }

        $bytes = (int) ($downloadRes['bytes'] ?? 0);
        update_option('fflhub_cssi_fulfillment_last_download', current_time('mysql'));
        update_option('fflhub_cssi_fulfillment_last_download_ts', (string) time());
        update_option('fflhub_cssi_fulfillment_last_download_size', (string) $bytes);
        update_option('fflhub_cssi_fulfillment_last_download_path', $path);
        update_option('fflhub_cssi_fulfillment_last_download_url', $feedUrl);
        delete_option('fflhub_cssi_fulfillment_last_download_error');

        $importer = new CSSIProductImporterService($this->table);
        try {
            $count = (int) $importer->import_from_csv_file($path);
        } catch (\Throwable $e) {
            update_option('fflhub_cssi_fulfillment_last_import_error', current_time('mysql'));
            $this->log('ERROR: CSSI product-feed import exception.', [
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if ($count <= 0) {
            update_option('fflhub_cssi_fulfillment_last_import_error', current_time('mysql'));
            $this->log('ERROR: CSSI product-feed import completed with 0 rows; swap skipped.', [
                'path' => $path,
                'url' => $feedUrl,
            ]);
            return;
        }

        try {
            $newLive = (string) $this->table->swap_live_and_staging();
        } catch (\Throwable $e) {
            update_option('fflhub_cssi_fulfillment_last_swap_error', current_time('mysql'));
            $this->log('ERROR: CSSI product-feed swap exception.', [
                'error' => $e->getMessage(),
            ]);
            return;
        }

        update_option('fflhub_cssi_fulfillment_last_import', current_time('mysql'));
        update_option('fflhub_cssi_fulfillment_last_import_count', (int) $count);
        update_option('fflhub_cssi_fulfillment_last_swap', current_time('mysql'));
        delete_option('fflhub_cssi_fulfillment_last_import_error');
        delete_option('fflhub_cssi_fulfillment_last_swap_error');

        $this->log('CSSI full catalog refresh complete.', [
            'bytes' => $bytes,
            'path' => $path,
            'imported_rows' => $count,
            'new_live' => $newLive,
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
}
