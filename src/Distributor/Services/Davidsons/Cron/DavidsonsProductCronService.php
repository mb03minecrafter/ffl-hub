<?php

namespace FFLHub\Distributor\Services\Davidsons\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Davidsons\API\DavidsonsPortalInventoryClient;
use FFLHub\Distributor\Services\Davidsons\DavidsonsOfferNormalizationService;
use FFLHub\Distributor\Services\Davidsons\DavidsonsProductImporterService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * Davidson's full catalog downloader cron.
 *
 * Behavior:
 * - Authenticates into the Davidson's portal.
 * - Downloads the `davidsons_inventory` CSV.
 * - Imports into staging.
 * - Swaps staging/live on success.
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
        return DAY_IN_SECONDS;
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

        $importer = new DavidsonsProductImporterService($this->table);
        try {
            $count = (int) $importer->import_from_downloaded_file();
        } catch (\Throwable $e) {
            update_option('fflhub_davidsons_fulfillment_last_import_error', current_time('mysql'));
            $this->log('ERROR: Davidson full-catalog import exception.', [
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if ($count <= 0) {
            update_option('fflhub_davidsons_fulfillment_last_import_error', current_time('mysql'));
            $this->log('ERROR: Davidson full-catalog import completed with 0 rows; swap skipped.', [
                'request_name' => self::DOWNLOAD_NAME,
                'path'         => $path,
            ]);
            return;
        }

        try {
            $new_live = (string) $this->table->swap_live_and_staging();
        } catch (\Throwable $e) {
            update_option('fflhub_davidsons_fulfillment_last_swap_error', current_time('mysql'));
            $this->log('ERROR: Davidson full-catalog swap exception.', [
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $offers_result = $this->update_distributor_offers_from_new_live_table();

        update_option('fflhub_davidsons_fulfillment_last_import', current_time('mysql'));
        update_option('fflhub_davidsons_fulfillment_last_import_count', (int) $count);
        update_option('fflhub_davidsons_fulfillment_last_swap', current_time('mysql'));
        delete_option('fflhub_davidsons_fulfillment_last_import_error');
        delete_option('fflhub_davidsons_fulfillment_last_swap_error');

        $this->log('Davidsons full catalog refresh complete.', [
            'request_name' => self::DOWNLOAD_NAME,
            'bytes'        => $bytes,
            'path'         => $path,
            'imported_rows' => (int) $count,
            'new_live'      => $new_live,
            'distributor_offers_ok' => !empty($offers_result['ok']) ? 1 : 0,
            'distributor_offers_davidsons_inserted_missing_rows' => (int) ($offers_result['inserted_missing_rows'] ?? 0),
            'distributor_offers_davidsons_updated_changed_rows' => (int) ($offers_result['updated_changed_rows'] ?? 0),
            'distributor_offers_davidsons_stale_disabled_rows' => (int) ($offers_result['stale_disabled_rows'] ?? 0),
        ]);
    }

    /**
     * Copy the newly live Davidson's product snapshot into distributor offers.
     *
     * The importer has already finished and the double-buffered table has
     * already swapped, so the current live table is the source of truth here.
     * The shared sync limits rows to active product_state UPCs, inserts missing
     * Davidson's offer rows, updates changed offer snapshots, and disables stale
     * Davidson's offers that no longer exist in the fresh catalog table.
     *
     * @return array<string,mixed>
     */
    private function update_distributor_offers_from_new_live_table(): array
    {
        $t0 = microtime(true);
        $live_table = (string) $this->table->get_live_table_name();

        try {
            $result = DavidsonsOfferNormalizationService::normalize_from_product_table($live_table);
            $result['ok'] = true;
        } catch (\Throwable $e) {
            $result = [
                'ok' => false,
                'source_live_table' => $live_table,
                'errors' => [$e->getMessage()],
            ];
        }

        $result['elapsed_ms'] = round((microtime(true) - $t0) * 1000.0, 2);

        $this->log('Sync distributor offers from new Davidson live table.', [
            'source_live_table' => $live_table,
            'active_product_state_total' => (int) ($result['active_product_state_total'] ?? 0),
            'matched_active_davidsons_upcs' => (int) ($result['matched_active_davidsons_upcs'] ?? 0),
            'inserted_missing_rows' => (int) ($result['inserted_missing_rows'] ?? 0),
            'insert_missing_ms' => (float) ($result['insert_missing_ms'] ?? 0.0),
            'updated_changed_rows' => (int) ($result['updated_changed_rows'] ?? 0),
            'update_changed_ms' => (float) ($result['update_changed_ms'] ?? 0.0),
            'stale_disabled_rows' => (int) ($result['stale_disabled_rows'] ?? 0),
            'stale_cleanup_ms' => (float) ($result['stale_cleanup_ms'] ?? 0.0),
            'elapsed_ms' => (float) ($result['elapsed_ms'] ?? 0.0),
            'ok' => !empty($result['ok']) ? 1 : 0,
            'errors' => (array) ($result['errors'] ?? []),
        ]);

        if (empty($result['ok'])) {
            $this->log('ERROR: Davidson distributor offers update failed after product swap.', [
                'source_live_table' => $live_table,
                'errors' => (array) ($result['errors'] ?? []),
            ]);
        }

        return $result;
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
