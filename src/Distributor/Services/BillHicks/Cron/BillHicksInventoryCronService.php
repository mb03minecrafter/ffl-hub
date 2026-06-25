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
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;

/**
 * Bill Hicks inventory cron.
 *
 * Downloads /DeerfordDefense/Feeds/BHC_inventory.csv, stages Product/UPC/Qty,
 * then updates only stock-related fields in the current live Bill Hicks table.
 */
final class BillHicksInventoryCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_bill_hicks_pricing_quantity_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHUB][BillHicksInventoryCron]';

    private const DEFAULT_REMOTE_PATH = '/DeerfordDefense/Feeds/BHC_inventory.csv';
    private const LOCAL_DIR = 'fflhub-bill-hicks';
    private const LOCAL_FILE_NAME = 'BHC_inventory.csv';
    private const STAGE_TABLE_SUFFIX = 'fflhub_bill_hicks_inventory_stage';

    private const OPT_LAST_CHECKED_AT = 'fflhub_bill_hicks_inventory_last_checked_at';
    private const OPT_LAST_APPLIED_MTIME = 'fflhub_bill_hicks_inventory_last_applied_mtime';
    private const OPT_LAST_SEEN_MTIME = 'fflhub_bill_hicks_inventory_last_seen_mtime';
    private const OPT_LAST_SEEN_SIZE = 'fflhub_bill_hicks_inventory_last_seen_size';
    private const OPT_LAST_DOWNLOAD = 'fflhub_bill_hicks_inventory_last_download';
    private const OPT_LAST_DOWNLOAD_ERROR = 'fflhub_bill_hicks_inventory_last_download_error';

    /**
     * Bill Hicks publishes inventory roughly hourly. Poll every few minutes,
     * but skip FTP entirely for most of the hour after a successful apply.
     */
    private const FTP_COOLDOWN_SECONDS = 55 * MINUTE_IN_SECONDS;
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
        return 5 * MINUTE_IN_SECONDS;
    }

    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    protected function get_initial_delay_seconds(): int
    {
        return 2 * MINUTE_IN_SECONDS;
    }

    public function run(): void
    {
        $t_start = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $force_update = $this->should_force_update();
        update_option('fflhub_bill_hicks_inventory_last_run', current_time('mysql'));

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

        // 2. Prepare the local inventory CSV path.
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

        $remote_path = $this->get_remote_inventory_path();
        $local_path = trailingslashit($base_dir) . self::LOCAL_FILE_NAME;

        $this->profile('Prepare local paths', $t_paths, [
            'base_dir' => (string) $base_dir,
            'remote_csv' => (string) $remote_path,
            'local_csv' => (string) $local_path,
            'live_table' => (string) $this->table->get_live_table_name(),
        ]);

        // 3. FTP freshness gate and download.
        $fetch = (new FtpFeedFetcher())->fetch(
            FtpFeedFetchRequest::create([
                'distributor_id' => 'bill_hicks',
                'cron_name' => 'Bill Hicks inventory cron',
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
                'min_bytes' => 128,
                'force' => $force_update,
                'ftp_log_prefix' => '[FFLHub][BillHicks][FTP]',
                'no_change_log_message' => 'No update available (remote mtime unchanged) - skipping download/apply',
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

        // 4. Load the inventory CSV into a persistent stage table and update live stock.
        $t_apply = microtime(true);
        try {
            $apply_stats = $this->apply_inventory_updates_via_load_data_profiled($local_path);
        } catch (\Throwable $e) {
            $this->log('ERROR: exception applying inventory updates', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Apply inventory updates (failed)', $t_apply);
            $this->finalize_run($t_start, $mem_start, 'ERROR (apply failed)');
            return;
        }

        $this->profile('Apply inventory updates', $t_apply, $apply_stats);

        // 5. Project the same quantity stage into normalized Bill Hicks offers.
        try {
            $offers_update_stats = $this->update_distributor_offers_from_inventory_stage(
                (string) ($apply_stats['stage_table'] ?? '')
            );
        } catch (\Throwable $e) {
            $this->log('ERROR: exception updating normalized distributor offers', [
                'error' => $e->getMessage(),
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (offers update failed)');
            return;
        }

        update_option('fflhub_bill_hicks_inventory_last_update', current_time('mysql'));
        update_option('fflhub_bill_hicks_inventory_last_update_count', (int) ($apply_stats['processed_rows'] ?? 0));

        if ($fetch->remote_mtime > 0) {
            update_option(self::OPT_LAST_APPLIED_MTIME, (int) $fetch->remote_mtime);
        }

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'processed_rows' => (int) ($apply_stats['processed_rows'] ?? 0),
            'join_updated' => (int) ($apply_stats['join_updated'] ?? 0),
            'distributor_offers_bill_hicks_inventory_update_rows' => (int) ($offers_update_stats['rows'] ?? 0),
            'remote_mtime' => $fetch->remote_mtime > 0 ? (int) $fetch->remote_mtime : null,
        ]);
    }

    /**
     * Bulk-load BHC_inventory.csv into a persistent stage table, then update
     * only the stock fields on the current live Bill Hicks product table.
     *
     * Source headers:
     * Product, UPC, Qty Avail
     *
     * @return array<string,mixed>
     */
    private function apply_inventory_updates_via_load_data_profiled(string $file_path): array
    {
        global $wpdb;

        $t_start = microtime(true);

        if (!file_exists($file_path) || !is_readable($file_path)) {
            $this->log('ERROR: BHC_inventory.csv missing or unreadable', [
                'file_path' => (string) $file_path,
            ]);
            return $this->empty_apply_stats();
        }

        $live_table = (string) $this->table->get_live_table_name();
        if ($live_table === '') {
            $this->log('ERROR: could not resolve live table name');
            return $this->empty_apply_stats();
        }

        $charset = $wpdb->get_charset_collate();
        $stage_table = $wpdb->prefix . self::STAGE_TABLE_SUFFIX;

        // Stage A: Ensure and reset the persistent inventory stage table.
        $t_stage = microtime(true);
        $create_sql = "
            CREATE TABLE IF NOT EXISTS {$stage_table} (
                bill_hicks_item_number VARCHAR(64) NOT NULL DEFAULT '',
                upc VARCHAR(32) NOT NULL,
                qty INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (upc),
                KEY bill_hicks_item_number (bill_hicks_item_number)
            ) {$charset};
        ";

        $created = $wpdb->query($create_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($created === false) {
            throw new \RuntimeException('Failed to ensure inventory stage table: ' . (string) $wpdb->last_error);
        }

        $truncated = $wpdb->query("TRUNCATE TABLE {$stage_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($truncated === false) {
            throw new \RuntimeException('Failed to truncate inventory stage table: ' . (string) $wpdb->last_error);
        }
        $stage_ms = (microtime(true) - $t_stage) * 1000.0;

        // Stage B: LOAD DATA directly into the stage table.
        $t_load = microtime(true);
        $load_sql = "
            LOAD DATA LOCAL INFILE %s
            IGNORE INTO TABLE {$stage_table}
            CHARACTER SET utf8mb4
            FIELDS TERMINATED BY ',' ENCLOSED BY '\"' ESCAPED BY '\\\\'
            LINES TERMINATED BY '\\n'
            IGNORE 1 LINES
            (
                @product,
                @upc,
                @qty
            )
            SET
                bill_hicks_item_number = TRIM(BOTH '\\r' FROM TRIM(@product)),
                upc = REGEXP_REPLACE(TRIM(BOTH '\\r' FROM TRIM(@upc)), '[^0-9]', ''),
                qty = CAST(COALESCE(NULLIF(REGEXP_REPLACE(TRIM(BOTH '\\r' FROM TRIM(@qty)), '[^0-9]', ''), ''), '0') AS UNSIGNED)
        ";

        $loaded = $wpdb->query($wpdb->prepare($load_sql, $file_path)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($loaded === false) {
            throw new \RuntimeException('LOAD DATA LOCAL INFILE failed: ' . (string) $wpdb->last_error);
        }

        $deleted_blank = $wpdb->query("DELETE FROM {$stage_table} WHERE upc = ''"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($deleted_blank === false) {
            throw new \RuntimeException('Blank UPC stage cleanup failed: ' . (string) $wpdb->last_error);
        }

        $load_ms = (microtime(true) - $t_load) * 1000.0;
        $rows_loaded = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$stage_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // Stage C: Optional debug-only counts before the live-table update.
        $t_stats = microtime(true);
        $do_stats = defined(self::DEBUG_FLAG) && (bool) constant(self::DEBUG_FLAG);
        $join_matched = 0;
        $would_change = 0;

        if ($do_stats) {
            $status_expr = "CASE WHEN S.qty > 0 THEN 'in_stock' ELSE 'out_of_stock' END";
            $join_matched = (int) $wpdb->get_var("
                SELECT COUNT(*)
                FROM {$stage_table} S
                INNER JOIN {$live_table} L
                    ON S.upc = L.upc
            "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            $would_change = (int) $wpdb->get_var("
                SELECT COUNT(*)
                FROM {$stage_table} S
                INNER JOIN {$live_table} L
                    ON S.upc = L.upc
                WHERE NOT (L.inventory_quantity <=> CAST(S.qty AS CHAR))
                   OR NOT (L.allocation_status <=> {$status_expr})
            "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $stats_ms = (microtime(true) - $t_stats) * 1000.0;

        // Stage D: Changed-only live-table stock update by UPC.
        $t_update = microtime(true);
        $update_sql = "
            UPDATE {$live_table} L
            INNER JOIN {$stage_table} S
                ON S.upc = L.upc
            SET
                L.inventory_quantity = CAST(S.qty AS CHAR),
                L.allocation_status = CASE WHEN S.qty > 0 THEN 'in_stock' ELSE 'out_of_stock' END
            WHERE NOT (L.inventory_quantity <=> CAST(S.qty AS CHAR))
               OR NOT (L.allocation_status <=> CASE WHEN S.qty > 0 THEN 'in_stock' ELSE 'out_of_stock' END)
        ";

        $join_updated = $wpdb->query($update_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($join_updated === false) {
            throw new \RuntimeException('Bill Hicks inventory join update failed: ' . (string) $wpdb->last_error);
        }

        $update_ms = (microtime(true) - $t_update) * 1000.0;
        $total_ms = (microtime(true) - $t_start) * 1000.0;

        $stats = [
            'processed_rows' => (int) $rows_loaded,
            'rows_loaded' => (int) $rows_loaded,
            'load_affected_rows' => is_numeric($loaded) ? (int) $loaded : 0,
            'blank_upcs_deleted' => is_numeric($deleted_blank) ? (int) $deleted_blank : 0,
            'join_matched' => (int) $join_matched,
            'would_change' => (int) $would_change,
            'join_updated' => is_numeric($join_updated) ? (int) $join_updated : 0,
            'stage_table' => (string) $stage_table,
            'ensure_truncate_stage_ms' => number_format($stage_ms, 2, '.', ''),
            'load_ms' => number_format($load_ms, 2, '.', ''),
            'stats_ms' => number_format($stats_ms, 2, '.', ''),
            'join_update_ms' => number_format($update_ms, 2, '.', ''),
            'total_ms' => number_format($total_ms, 2, '.', ''),
        ];

        $this->log('PROFILE: apply_inventory_updates_via_load_data() breakdown', $stats);

        return $stats;
    }

    /**
     * Apply loaded quantity stage rows to existing normalized Bill Hicks offers.
     *
     * @return array{rows:int,elapsed_ms:float}
     */
    private function update_distributor_offers_from_inventory_stage(string $stage_table): array
    {
        if ($stage_table === '') {
            throw new \RuntimeException('Bill Hicks distributor offers update skipped because stage table was empty.');
        }

        $t_offers = microtime(true);
        $stats = BillHicksOfferNormalizationService::update_existing_from_inventory_stage($stage_table);

        $this->profile('Update existing distributor offers from inventory stage', $t_offers, [
            'stage_table' => (string) $stage_table,
            'distributor_offers_bill_hicks_inventory_update_rows' => (int) ($stats['rows'] ?? 0),
            'distributor_offers_bill_hicks_inventory_update_ms' => number_format((float) ($stats['elapsed_ms'] ?? 0.0), 2, '.', ''),
        ]);

        return $stats;
    }

    /**
     * @return array<string,mixed>
     */
    private function empty_apply_stats(): array
    {
        return [
            'processed_rows' => 0,
            'rows_loaded' => 0,
            'load_affected_rows' => 0,
            'blank_upcs_deleted' => 0,
            'join_matched' => 0,
            'would_change' => 0,
            'join_updated' => 0,
            'stage_table' => '',
            'ensure_truncate_stage_ms' => '0.00',
            'load_ms' => '0.00',
            'stats_ms' => '0.00',
            'join_update_ms' => '0.00',
            'total_ms' => '0.00',
        ];
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

    private function get_remote_inventory_path(): string
    {
        $path = trim((string) Options::get_distributor_option(
            'bill_hicks',
            'inventory_feed_remote_path',
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
