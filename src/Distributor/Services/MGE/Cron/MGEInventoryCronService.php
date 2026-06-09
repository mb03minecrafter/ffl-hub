<?php

namespace FFLHub\Distributor\Services\MGE\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Cron\CronRunLogger;
use FFLHub\Distributor\Services\Cron\FtpFeedFetcher;
use FFLHub\Distributor\Services\Cron\FtpFeedFetchRequest;
use FFLHub\Distributor\Services\MGE\MGEFtpCredentials;
use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * MGE delta inventory/pricing cron:
 * - Downloads the CQ CSV feed
 * - Loads into a staging table
 * - Join-updates live quantity/cost (+ MAP when provided)
 */
final class MGEInventoryCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_mge_pricing_quantity_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHUB][MGEInventoryCron]';

    private const STAGE_TABLE_SUFFIX = 'fflhub_mge_qty_price_stage';
    private const OPT_LAST_CHECKED_AT = 'fflhub_mge_qty_last_checked_at';

    private const FTP_COOLDOWN_SECONDS = 120;
    private const FTP_MIN_CHECK_GAP_SECONDS = 60;

    private const DEFAULT_REMOTE_PATH = '/feeds/vendorname_cq.csv';
    private const LOCAL_DIR = 'fflhub-mge';
    private const LOCAL_DEFAULT_FILENAME = 'vendorname_cq.csv';

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
        return 1 * MINUTE_IN_SECONDS;
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
        $force_update = $this->should_force_update();

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

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

        $t_creds = microtime(true);
        $creds = $this->get_ftp_credentials();

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

        $uploads = wp_upload_dir();
        $base_dir = trailingslashit((string) ($uploads['basedir'] ?? '')) . self::LOCAL_DIR;
        $remote_path = $this->get_remote_feed_path();
        $local_file_name = $this->resolve_local_filename($remote_path, self::LOCAL_DEFAULT_FILENAME);
        $local_path = trailingslashit($base_dir) . $local_file_name;

        $t_paths = microtime(true);
        if (!wp_mkdir_p($base_dir)) {
            $this->log('ERROR: failed to create base directory', [
                'base_dir' => (string) $base_dir,
            ]);
            $this->profile('Prepare local paths (mkdir failed)', $t_paths);
            $this->finalize_run($t_start, $mem_start, 'ERROR (mkdir failed)');
            return;
        }

        $this->profile('Prepare local paths', $t_paths, [
            'base_dir' => (string) $base_dir,
            'remote_csv' => (string) $remote_path,
            'local_csv' => (string) $local_path,
            'live_table' => (string) $this->table->get_live_table_name(),
        ]);

        $fetch = (new FtpFeedFetcher())->fetch(
            FtpFeedFetchRequest::create([
                'distributor_id' => 'mge',
                'cron_name' => 'MGE inventory cron',
                'credentials' => $creds,
                'remote_path' => $remote_path,
                'local_path' => $local_path,
                'last_checked_option' => self::OPT_LAST_CHECKED_AT,
                'last_applied_mtime_option' => 'fflhub_mge_qty_last_applied_mtime',
                'last_seen_mtime_option' => 'fflhub_mge_qty_last_seen_mtime',
                'last_seen_size_option' => 'fflhub_mge_qty_last_seen_size',
                'last_download_option' => 'fflhub_mge_inventory_last_download',
                'last_download_error_option' => 'fflhub_mge_inventory_last_download_error',
                'min_check_gap_seconds' => self::FTP_MIN_CHECK_GAP_SECONDS,
                'cooldown_seconds' => self::FTP_COOLDOWN_SECONDS,
                'force' => $force_update,
                'ftp_log_prefix' => '[FFLHub][MGE][FTP]',
                'use_pasv_address' => false,
                'no_change_log_message' => 'No update available (remote mtime unchanged) - skipping download/apply',
            ]),
            $this->cron_logger()
        );

        if ($fetch->is_skipped() || $fetch->is_failed()) {
            $this->finalize_run($t_start, $mem_start, $fetch->cron_status !== '' ? $fetch->cron_status : 'ERROR (download failed)');
            return;
        }

        $remote_mtime = $fetch->remote_mtime;

        $t_apply = microtime(true);
        $processed_rows = 0;
        $apply_stats = [];

        try {
            $apply_stats = $this->apply_inventory_pricing_updates_via_load_data_profiled($local_path);
            $processed_rows = (int) ($apply_stats['processed_rows'] ?? 0);
        } catch (\Throwable $e) {
            $this->log('ERROR: exception applying updates', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Apply updates (failed)', $t_apply);
            $this->finalize_run($t_start, $mem_start, 'ERROR (apply failed)');
            return;
        }

        $this->profile('Apply updates', $t_apply, $apply_stats);

        update_option('fflhub_mge_inventory_last_update', current_time('mysql'));
        update_option('fflhub_mge_inventory_last_update_count', (int) $processed_rows);

        if ($remote_mtime > 0) {
            update_option('fflhub_mge_qty_last_applied_mtime', $remote_mtime);
        }

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'processed_rows' => (int) $processed_rows,
            'remote_mtime' => $remote_mtime > 0 ? $remote_mtime : null,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function apply_inventory_pricing_updates_via_load_data_profiled(string $file_path): array
    {
        global $wpdb;

        $t_start = microtime(true);

        if (!file_exists($file_path) || !is_readable($file_path)) {
            $this->log('ERROR: MGE delta file missing or unreadable', [
                'file_path' => (string) $file_path,
            ]);
            return $this->empty_apply_stats();
        }

        $live_table = (string) $this->table->get_live_table_name();
        if ($live_table === '') {
            $this->log('ERROR: could not resolve live table name');
            return $this->empty_apply_stats();
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $ignore_lines = 1;
        $charset = $wpdb->get_charset_collate();
        $stage_table = $wpdb->prefix . self::STAGE_TABLE_SUFFIX;

        // Ensure stage table exists + truncate.
        $t_create = microtime(true);

        $create_sql = "
            CREATE TABLE IF NOT EXISTS {$stage_table} (
                id            varchar(64) NOT NULL,
                qty           decimal(12,4) NOT NULL DEFAULT 0,
                cost          decimal(12,4) NULL,
                map_value     varchar(32) NULL,
                item_vend_no  varchar(64) NULL,
                PRIMARY KEY (id),
                KEY item_vend_no (item_vend_no)
            ) {$charset};
        ";

        $created = $wpdb->query($create_sql);
        if ($created === false) {
            throw new \RuntimeException('Failed to ensure staging table: ' . (string) $wpdb->last_error);
        }

        $truncated = $wpdb->query("TRUNCATE TABLE {$stage_table}");
        if ($truncated === false) {
            throw new \RuntimeException('Failed to truncate staging table: ' . (string) $wpdb->last_error);
        }

        $create_ms = (microtime(true) - $t_create) * 1000.0;

        // LOAD DATA LOCAL INFILE.
        $t_load = microtime(true);

        $infile_path_sql = str_replace('\\', '\\\\', $file_path);
        $infile_path_sql = str_replace("'", "\\'", $infile_path_sql);

        $load_sql = "
            LOAD DATA LOCAL INFILE '{$infile_path_sql}'
            INTO TABLE {$stage_table}
            CHARACTER SET utf8mb4
            FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"' ESCAPED BY '\\\\'
            LINES TERMINATED BY '\\n'
            IGNORE {$ignore_lines} LINES
            (
                @map,
                @cost,
                @id,
                @qty,
                @item_vend_no
            )
            SET
                id           = TRIM(BOTH '\\r' FROM TRIM(@id)),
                qty          = IFNULL(NULLIF(TRIM(BOTH '\\r' FROM TRIM(@qty)), '') + 0, 0),
                cost         = NULLIF(REPLACE(REPLACE(TRIM(BOTH '\\r' FROM TRIM(@cost)), '$', ''), ',', ''), ''),
                map_value    = CASE
                                  WHEN UPPER(TRIM(BOTH '\\r' FROM TRIM(@map))) IN ('', 'N/A') THEN NULL
                                  ELSE REPLACE(REPLACE(TRIM(BOTH '\\r' FROM TRIM(@map)), '$', ''), ',', '')
                               END,
                item_vend_no = TRIM(BOTH '\\r' FROM TRIM(@item_vend_no))
        ";

        $loaded = $wpdb->query($load_sql);
        if ($loaded === false) {
            throw new \RuntimeException('LOAD DATA LOCAL INFILE failed: ' . (string) $wpdb->last_error);
        }

        $wpdb->query("DELETE FROM {$stage_table} WHERE id IS NULL OR TRIM(id) = ''"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $load_ms = (microtime(true) - $t_load) * 1000.0;
        $rows_loaded = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$stage_table}");

        // Stats (debug only).
        $t_stats = microtime(true);

        $do_stats = defined(self::DEBUG_FLAG) && constant(self::DEBUG_FLAG);
        $join_matched = 0;
        $would_change = 0;

        if ($do_stats) {
            $join_matched = (int) $wpdb->get_var("
                SELECT COUNT(*)
                FROM {$stage_table} S
                INNER JOIN {$live_table} L
                    ON L.mge_item_number = S.id
            ");

            $would_change = (int) $wpdb->get_var("
                SELECT COUNT(*)
                FROM {$stage_table} S
                INNER JOIN {$live_table} L
                    ON L.mge_item_number = S.id
                WHERE
                    COALESCE(L.inventory_quantity, '') <> CAST(S.qty AS CHAR)
                    OR (
                        S.cost IS NOT NULL
                        AND (
                            NULLIF(L.distributor_price, '') IS NULL
                            OR CAST(NULLIF(L.distributor_price, '') AS DECIMAL(12,4)) <> S.cost
                        )
                    )
                    OR (
                        S.map_value IS NOT NULL
                        AND COALESCE(L.retail_map, '') <> S.map_value
                    )
            ");
        }

        $stats_ms = (microtime(true) - $t_stats) * 1000.0;

        // Join update.
        $t_join = microtime(true);

        $join_sql = "
            UPDATE {$live_table} L
            INNER JOIN {$stage_table} S
                ON S.id = L.mge_item_number
            SET
                L.inventory_quantity = CAST(S.qty AS CHAR),
                L.distributor_price  = IFNULL(CAST(S.cost AS CHAR), L.distributor_price),
                L.retail_map         = CASE WHEN S.map_value IS NULL THEN L.retail_map ELSE S.map_value END
            WHERE
                COALESCE(L.inventory_quantity, '') <> CAST(S.qty AS CHAR)
                OR (
                    S.cost IS NOT NULL
                    AND (
                        NULLIF(L.distributor_price, '') IS NULL
                        OR CAST(NULLIF(L.distributor_price, '') AS DECIMAL(12,4)) <> S.cost
                    )
                )
                OR (
                    S.map_value IS NOT NULL
                    AND COALESCE(L.retail_map, '') <> S.map_value
                )
        ";

        $join_updated = $wpdb->query($join_sql);
        if ($join_updated === false) {
            throw new \RuntimeException('JOIN update failed: ' . (string) $wpdb->last_error);
        }

        $sig_approved_forced = SigDropshipApproval::apply_to_table('mge', $live_table);
        $join_ms = (microtime(true) - $t_join) * 1000.0;

        $drop_ms = 0.0;
        $total_ms = (microtime(true) - $t_start) * 1000.0;

        $stats = [
            'processed_rows' => (int) $rows_loaded,
            'rows_loaded' => (int) $rows_loaded,
            'join_matched' => (int) $join_matched,
            'would_change' => (int) $would_change,
            'join_updated' => (int) $join_updated,
            'sig_approved_forced' => (int) $sig_approved_forced,
            'stage_table' => (string) $stage_table,
            'ignore_lines' => (int) $ignore_lines,
            'create_ms' => number_format($create_ms, 2, '.', ''),
            'load_ms' => number_format($load_ms, 2, '.', ''),
            'stats_ms' => number_format($stats_ms, 2, '.', ''),
            'join_ms' => number_format($join_ms, 2, '.', ''),
            'drop_ms' => number_format($drop_ms, 2, '.', ''),
            'total_ms' => number_format($total_ms, 2, '.', ''),
        ];

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, 'PROFILE: apply_inventory_pricing_updates_via_load_data() breakdown', $stats);

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
            'join_matched' => 0,
            'would_change' => 0,
            'join_updated' => 0,
            'create_ms' => '0.00',
            'load_ms' => '0.00',
            'stats_ms' => '0.00',
            'join_ms' => '0.00',
            'drop_ms' => '0.00',
            'total_ms' => '0.00',
        ];
    }

    /**
     * @return array{host:string,username:string,password:string,use_ssl:bool,port:int}|null
     */
    public function get_ftp_credentials(): ?array
    {
        $loaded = MGEFtpCredentials::load();
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

    private function get_remote_feed_path(): string
    {
        $raw = trim((string) Options::get_distributor_option('mge', 'delta_feed_remote_path', self::DEFAULT_REMOTE_PATH));
        if ($raw === '') {
            return self::DEFAULT_REMOTE_PATH;
        }

        return (strpos($raw, '/') === 0) ? $raw : ('/' . $raw);
    }

    private function resolve_local_filename(string $remote_path, string $fallback): string
    {
        $path = (string) parse_url($remote_path, PHP_URL_PATH);
        $file = basename($path);
        $file = trim((string) $file);

        if ($file === '' || $file === '.' || $file === '..') {
            return $fallback;
        }

        return $file;
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
    private function log(string $msg, array $ctx = []): void
    {
        $this->cron_logger()->log($msg, $ctx);
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
