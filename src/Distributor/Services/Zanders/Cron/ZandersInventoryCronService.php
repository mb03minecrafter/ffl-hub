<?php

namespace FFLHub\Distributor\Services\Zanders\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\FTP\FTPClientService;
use FFLHub\Distributor\Services\FTP\FTPFreshnessGate;
use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Distributor\Services\Zanders\ZandersFtpCredentials;
use FFLHub\Distributor\Services\Zanders\ZandersManufacturerNormalizer;
use FFLHub\Util\DebugLogUtil;

/**
 * Cron job for near-real-time Zanders inventory + primary pricing updates using liveinv.csv.
 *
 * Runs frequently:
 *  - downloads /Inventory/liveinv.csv via FTP to uploads/fflhub-zanders/
 *  - bulk loads into a persistent staging table via LOAD DATA LOCAL INFILE
 *  - join-updates fast-moving columns in the LIVE fulfillment table:
 *      inventory_quantity, distributor_price, shipping_cost
 *
 * Source CSV (header + CRLF):
 * itemnumber,available,price1,price2,price3,qty1,qty2,qty3
 */
final class ZandersInventoryCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_zanders_pricing_quantity_update';

    private const DEBUG_FLAG  = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX  = '[FFLHUB][ZandersInventoryCron]';

    private const STAGE_TABLE_SUFFIX = 'fflhub_zanders_qty_price_stage';
    private const OPT_LAST_CHECKED_AT = 'fflhub_zanders_qty_last_checked_at';

    // Zanders updates this file ~every 5 minutes.
    private const FTP_COOLDOWN_SECONDS      = 180; // 3 minutes after applying a change
    private const FTP_MIN_CHECK_GAP_SECONDS = 60;  // 1 minute hard throttle

    private const REMOTE_PATH    = '/Inventory/liveinv.csv';
    private const LOCAL_DIR      = 'fflhub-zanders';
    private const LOCAL_FILENAME = 'liveinv.csv';

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
        $t_start   = microtime(true);
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

        // 0) Credentials
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

        $host     = (string) $creds['host'];
        $username = (string) $creds['username'];
        $password = (string) $creds['password'];
        $use_ssl  = (bool) $creds['use_ssl']; // Zanders: false
        $port     = (int) ($creds['port'] ?? 21);

        // 1) Local paths
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

        $local_path  = trailingslashit($base_dir) . self::LOCAL_FILENAME;
        $remote_path = self::REMOTE_PATH;

        $this->profile('Prepare local paths', $t_paths, [
            'base_dir'   => (string) $base_dir,
            'remote_csv' => (string) $remote_path,
            'local_csv'  => (string) $local_path,
            'live_table' => (string) $this->table->get_live_table_name(),
        ]);

        // ---------------------------------------------------------------------
        // FTP freshness gate (pre-connect throttle/cooldown)
        // ---------------------------------------------------------------------
        $pre_gate = FTPFreshnessGate::evaluate_pre_connect(
            self::OPT_LAST_CHECKED_AT,
            'fflhub_zanders_qty_last_applied_mtime',
            self::FTP_MIN_CHECK_GAP_SECONDS,
            self::FTP_COOLDOWN_SECONDS,
            $force_update
        );

        if ((bool) $pre_gate['skip']) {
            $this->log((string) $pre_gate['log_message'], (array) $pre_gate['log_context']);
            $this->finalize_run($t_start, $mem_start, (string) $pre_gate['status']);
            return;
        }
        // 2) FTP connect
        $t_ftp = microtime(true);

        $ftp = new FTPClientService(
            $host,
            $username,
            $password,
            $use_ssl,
            $port,
            30,
            true,
            '[FFLHub][Zanders][FTP]'
        );

        if (!$ftp->is_connected()) {
            update_option('fflhub_zanders_inventory_last_download_error', current_time('mysql'));

            $this->log('ERROR: FTP connection not available.', [
                'host'    => $host,
                'use_ssl' => $use_ssl ? 1 : 0,
                'port'    => (int) $port,
            ]);

            $this->profile('FTP connection (failed)', $t_ftp);
            $this->finalize_run($t_start, $mem_start, 'ERROR (FTP connection failed)');
            return;
        }

        // 3) FTP freshness gate (remote mtime/size)
        $t_meta = microtime(true);
        $last_applied_mtime = (int) get_option('fflhub_zanders_qty_last_applied_mtime', 0);
        $meta_gate          = FTPFreshnessGate::evaluate_remote_meta(
            $ftp,
            $remote_path,
            'fflhub_zanders_qty_last_seen_mtime',
            'fflhub_zanders_qty_last_seen_size',
            $last_applied_mtime,
            $force_update,
            250000,
            'No update available (remote mtime unchanged) - skipping download/apply'
        );

        $this->profile((string) $meta_gate['profile_label'], $t_meta, (array) $meta_gate['profile_context']);

        $remote_mtime = (int) $meta_gate['remote_mtime'];

        if ((bool) $meta_gate['skip']) {
            $this->log((string) $meta_gate['log_message'], (array) $meta_gate['log_context']);
            $this->finalize_run($t_start, $mem_start, (string) $meta_gate['status']);
            return;
        }
        // 4) Download
        $t_download = microtime(true);

        $csv_kb_before = file_exists($local_path) ? (int) round(((int) filesize($local_path)) / 1024) : 0;
        $ok            = $ftp->download_file($remote_path, $local_path);
        $csv_kb_after  = file_exists($local_path) ? (int) round(((int) filesize($local_path)) / 1024) : 0;

        $this->profile('FTP download', $t_download, [
            'ok'            => $ok ? 1 : 0,
            'csv_kb_before' => (int) $csv_kb_before,
            'csv_kb_after'  => (int) $csv_kb_after,
        ]);

        if (!$ok) {
            update_option('fflhub_zanders_inventory_last_download_error', current_time('mysql'));
            $this->log('ERROR: FTP download failed', [
                'remote_csv' => (string) $remote_path,
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (download failed)');
            return;
        }

        update_option('fflhub_zanders_inventory_last_download', current_time('mysql'));
        delete_option('fflhub_zanders_inventory_last_download_error');

        // 5) Apply updates (LOAD DATA + JOIN)
        $t_apply = microtime(true);

        $processed_rows = 0;
        $apply_stats    = [];

        try {
            $apply_stats    = $this->apply_inventory_pricing_updates_via_load_data_profiled($local_path);
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

        update_option('fflhub_zanders_inventory_last_update', current_time('mysql'));
        update_option('fflhub_zanders_inventory_last_update_count', (int) $processed_rows);

        if ($remote_mtime > 0) {
            update_option('fflhub_zanders_qty_last_applied_mtime', $remote_mtime);
        }

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'processed_rows' => (int) $processed_rows,
            'remote_mtime'   => $remote_mtime > 0 ? $remote_mtime : null,
        ]);
    }

    /**
     * Bulk-load liveinv.csv into a persistent stage table via LOAD DATA LOCAL INFILE,
     * then join-update fast-moving columns in the live fulfillment table.
     *
     * @return array<string,mixed>
     */
    private function apply_inventory_pricing_updates_via_load_data_profiled(string $file_path): array
    {
        global $wpdb;

        $t_start = microtime(true);

        if (!file_exists($file_path) || !is_readable($file_path)) {
            $this->log('ERROR: liveinv.csv missing or unreadable', [
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

        $ignore_lines = 1; // header row expected

        $charset     = $wpdb->get_charset_collate();
        $stage_table = $wpdb->prefix . self::STAGE_TABLE_SUFFIX;

        // -----------------------
        // Ensure stage table exists + TRUNCATE
        // -----------------------
        $t_create = microtime(true);

        $create_sql = "
            CREATE TABLE IF NOT EXISTS {$stage_table} (
                itemnumber  varchar(64) NOT NULL,
                available   int unsigned NULL,
                price1      decimal(12,4) NULL,
                PRIMARY KEY (itemnumber)
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

        // -----------------------
        // LOAD DATA LOCAL INFILE
        // -----------------------
        $t_load = microtime(true);

        // Escape path for SQL literal.
        $infile_path_sql = str_replace('\\', '\\\\', $file_path);
        $infile_path_sql = str_replace("'", "\\'", $infile_path_sql);

        $load_sql = "
            LOAD DATA LOCAL INFILE '{$infile_path_sql}'
            INTO TABLE {$stage_table}
            CHARACTER SET utf8mb4
            FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"' ESCAPED BY '\\\\'
            LINES TERMINATED BY '\\r\\n'
            IGNORE {$ignore_lines} LINES
            (
                @itemnumber,
                @available,
                @price1,
                @price2,
                @price3,
                @qty1,
                @qty2,
                @qty3
            )
            SET
                itemnumber = TRIM(BOTH '\\r' FROM TRIM(@itemnumber)),
                available  = IFNULL(NULLIF(TRIM(BOTH '\\r' FROM TRIM(@available)), '') + 0, 0),
                price1     = NULLIF(TRIM(BOTH '\\r' FROM TRIM(@price1)), '')
        ";

        $loaded = $wpdb->query($load_sql);
        if ($loaded === false) {
            throw new \RuntimeException('LOAD DATA LOCAL INFILE failed: ' . (string) $wpdb->last_error);
        }

        $load_ms = (microtime(true) - $t_load) * 1000.0;

        $rows_loaded = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$stage_table}");

        // -----------------------
        // Pre-join stats (DEBUG only)
        // -----------------------
        $t_stats = microtime(true);

        $do_stats     = defined(self::DEBUG_FLAG) && constant(self::DEBUG_FLAG);
        $join_matched = 0;
        $sig_approval_candidates = 0;
        $sig_approval_enabled = SigDropshipApproval::is_distributor_sig_approved('zanders');
        $sig_approval_where_sql = $sig_approval_enabled
            ? $this->zanders_sig_approval_where_sql('L')
            : '0 = 1';

        if ($do_stats) {
            $join_matched = (int) $wpdb->get_var("
                SELECT COUNT(*)
                FROM {$stage_table} S
                INNER JOIN {$live_table} L
                    ON L.zanders_item_number = S.itemnumber
            ");

            if ($sig_approval_enabled) {
                $sig_approval_candidates = (int) $wpdb->get_var("
                    SELECT COUNT(*)
                    FROM {$stage_table} S
                    INNER JOIN {$live_table} L
                        ON L.zanders_item_number = S.itemnumber
                    WHERE {$sig_approval_where_sql}
                ");
            }
        }

        $stats_ms = (microtime(true) - $t_stats) * 1000.0;

        // -----------------------
        // Combined inventory + price + shipping + SIG dropship approval update.
        // -----------------------
        $t_combined_update = microtime(true);

        $combined_update_sql = "
            UPDATE {$live_table} L
            INNER JOIN {$stage_table} S
                ON S.itemnumber = L.zanders_item_number
            SET
                L.inventory_quantity = IFNULL(CAST(S.available AS CHAR), ''),
                L.distributor_price = IFNULL(CAST(S.price1 AS CHAR), ''),
                L.shipping_cost      = CASE
                    WHEN S.price1 >= 500 THEN '0'
                    ELSE '15'
                END,
                L.dropship_enabled = CASE
                    WHEN {$sig_approval_where_sql} THEN 1
                    ELSE L.dropship_enabled
                END,
                L.dropship_block_reason = CASE
                    WHEN {$sig_approval_where_sql} THEN ''
                    ELSE L.dropship_block_reason
                END
        ";

        $combined_updated = $wpdb->query($combined_update_sql);
        if ($combined_updated === false) {
            throw new \RuntimeException('Combined inventory/price update failed: ' . (string) $wpdb->last_error);
        }

        $combined_update_ms = (microtime(true) - $t_combined_update) * 1000.0;

        $drop_ms    = 0.0; // persistent table
        $t_total_ms = (microtime(true) - $t_start) * 1000.0;
        $join_ms    = $combined_update_ms;

        $stats = [
            'processed_rows' => (int) $rows_loaded,
            'rows_loaded'    => (int) $rows_loaded,
            'join_matched'   => (int) $join_matched,
            'combined_update_rows' => is_numeric($combined_updated) ? (int) $combined_updated : 0,
            'sig_approval_enabled' => $sig_approval_enabled ? 1 : 0,
            'sig_approval_candidates' => (int) $sig_approval_candidates,
            'sig_approval_mode' => 'folded_into_combined_update',
            'stage_table'    => (string) $stage_table,
            'ignore_lines'   => (int) $ignore_lines,
            'create_ms'      => number_format($create_ms, 2, '.', ''),
            'load_ms'        => number_format($load_ms, 2, '.', ''),
            'stats_ms'       => number_format($stats_ms, 2, '.', ''),
            'combined_update_ms' => number_format($combined_update_ms, 2, '.', ''),
            'join_ms'        => number_format($join_ms, 2, '.', ''),
            'drop_ms'        => number_format($drop_ms, 2, '.', ''),
            'total_ms'       => number_format($t_total_ms, 2, '.', ''),
        ];

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, 'PROFILE: apply_inventory_pricing_updates_via_load_data() breakdown', $stats);

        return $stats;
    }

    private function zanders_sig_approval_where_sql(string $alias): string
    {
        $alias = trim($alias);
        $prefix = $alias !== '' ? $alias . '.' : '';
        $manufacturer_norm = ZandersManufacturerNormalizer::canonical_norm_sql_expression("{$prefix}manufacturer");

        return "
            COALESCE({$prefix}sot_required, 0) = 0
            AND {$manufacturer_norm} = 'SIG SAUER'
        ";
    }

    private function empty_apply_stats(): array
    {
        return [
            'processed_rows' => 0,
            'rows_loaded'    => 0,
            'join_matched'   => 0,
            'combined_update_rows' => 0,
            'sig_approval_enabled' => 0,
            'sig_approval_candidates' => 0,
            'sig_approval_mode' => 'folded_into_combined_update',
            'create_ms'      => '0.00',
            'load_ms'        => '0.00',
            'stats_ms'       => '0.00',
            'combined_update_ms' => '0.00',
            'join_ms'        => '0.00',
            'drop_ms'        => '0.00',
            'total_ms'       => '0.00',
        ];
    }

    /**
     * Retrieve and validate FTP credentials from Zanders distributor settings.
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

    /** @param array<string,mixed> $ctx */
    private function log(string $msg, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $msg);
            return;
        }

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $msg, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $elapsed_ms = (microtime(true) - $t0) * 1000.0;

        $ctx = array_merge($ctx, [
            'elapsed_ms' => number_format($elapsed_ms, 2, '.', ''),
        ]);

        $this->log("PROFILE: {$label}", $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function finalize_run(float $t_start, int $mem_start, string $status, array $ctx = []): void
    {
        $this->profile('Total cron run', $t_start, [
            'status' => (string) $status,
        ]);

        $mem_end = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        if ($mem_start > 0 && $mem_end > 0) {
            $this->log('Memory usage summary', [
                'start_kb' => (int) round($mem_start / 1024),
                'end_kb'   => (int) round($mem_end / 1024),
                'delta_kb' => (int) round(($mem_end - $mem_start) / 1024),
            ]);
        }

        if (!empty($ctx)) {
            $this->log("---- RUN END ({$status}) ----", $ctx);
        } else {
            $this->log("---- RUN END ({$status}) ----");
        }
    }
}
