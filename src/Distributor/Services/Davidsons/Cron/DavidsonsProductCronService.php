<?php

namespace FFLHub\Distributor\Services\Davidsons\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Davidsons\API\DavidsonsPortalInventoryClient;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * Davidson's full catalog downloader cron.
 *
 * Current behavior:
 * - Authenticates into the Davidson's portal.
 * - Downloads the `davidsons_inventory` CSV.
 * - Saves it locally for importer/swap processing.
 *
 * Import/swap logic will be wired in a follow-up step.
 */
final class DavidsonsProductCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_davidsons_product_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHUB][DavidsonsProductCron]';
    private const DOWNLOAD_DIR = 'fflhub-davidsons';
    private const DOWNLOAD_NAME = 'davidsons_inventory';
    private const DOWNLOAD_TYPE = 'csv';
    private const DOWNLOAD_FILE = 'davidsons_inventory.csv';

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
        update_option('fflhub_davidsons_fulfillment_last_run', current_time('mysql'));

        $creds = $this->get_portal_credentials();
        if (!is_array($creds)) {
            update_option('fflhub_davidsons_fulfillment_last_download_error', current_time('mysql'));
            return;
        }

        $path = $this->resolve_output_path();
        if (!is_string($path) || $path === '') {
            update_option('fflhub_davidsons_fulfillment_last_download_error', current_time('mysql'));
            return;
        }

        $client = new DavidsonsPortalInventoryClient();
        $result = $client->download_csv_to(
            (string) $creds['username'],
            (string) $creds['password'],
            self::DOWNLOAD_NAME,
            $path,
            self::DOWNLOAD_TYPE
        );

        if (!(bool) ($result['ok'] ?? false)) {
            update_option('fflhub_davidsons_fulfillment_last_download_error', current_time('mysql'));
            $this->log('ERROR: Davidson full-catalog download failed.', [
                'message' => (string) ($result['message'] ?? 'Unknown error'),
                'context' => (array) ($result['context'] ?? []),
            ]);
            return;
        }

        $bytes = (int) ($result['bytes'] ?? 0);
        update_option('fflhub_davidsons_fulfillment_last_download', current_time('mysql'));
        update_option('fflhub_davidsons_fulfillment_last_download_ts', (string) time());
        update_option('fflhub_davidsons_fulfillment_last_download_size', (string) $bytes);
        update_option('fflhub_davidsons_fulfillment_last_download_path', $path);
        delete_option('fflhub_davidsons_fulfillment_last_download_error');

        $this->log('Davidsons full catalog CSV downloaded (import/swap pending implementation).', [
            'request_name' => self::DOWNLOAD_NAME,
            'bytes'        => $bytes,
            'path'         => $path,
        ]);
    }

    /**
     * @return array{username:string,password:string}|null
     */
    private function get_portal_credentials(): ?array
    {
        $username = trim(Options::get_distributor_option('davidsons', 'portal_username', ''));
        $password = trim(Options::get_distributor_option('davidsons', 'portal_password', ''));

        if ($username === '' || $password === '') {
            $this->log('Missing Davidson portal credentials.', [
                'has_username' => $username !== '' ? 1 : 0,
                'has_password' => $password !== '' ? 1 : 0,
            ]);
            return null;
        }

        return [
            'username' => $username,
            'password' => $password,
        ];
    }

    private function resolve_output_path(): ?string
    {
        $uploads = wp_upload_dir();
        $base_dir = trailingslashit((string) ($uploads['basedir'] ?? '')) . self::DOWNLOAD_DIR;
        if ($base_dir === '' || !wp_mkdir_p($base_dir)) {
            $this->log('Failed to create Davidson download directory.', [
                'base_dir' => $base_dir,
            ]);
            return null;
        }

        return trailingslashit($base_dir) . self::DOWNLOAD_FILE;
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
