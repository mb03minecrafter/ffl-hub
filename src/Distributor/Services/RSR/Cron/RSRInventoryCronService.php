<?php

namespace FFLHub\Distributor\Services\RSR\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Distributor\Services\FTP\FTPClientService;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * Cron job for real-time inventory updates using RSR's IM-QTY-CSV.csv file.
 *
 * Runs every 5 minutes:
 *  - downloads IM-QTY-CSV.csv via FTP to uploads/fflhub-rsr/
 *  - bulk loads it into a staging table via LOAD DATA LOCAL INFILE
 *  - join-updates inventory_quantity in the LIVE fulfillment table
 */
final class RSRInventoryCronService extends AbstractTableCronService
{
    /**
     * Cron hook name for RSR inventory refresh.
     */
    public const CRON_HOOK = 'fflhub_rsr_pricing_quantity_update';

    /**
     * Debug gate constant (define('FFLHUB_CRON_DEBUG', true);).
     */
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';

    /**
     * Log prefix.
     */
    private const LOG_PREFIX = '[FFLHUB][RSRInventoryCron]';

    /**
     * Persistent stage table name (no per-run CREATE/DROP).
     */
    private const STAGE_TABLE_SUFFIX = 'fflhub_rsr_qty_stage';

    /**
     * Option key used to rate-limit FTP meta checks (avoid repeated handshakes).
     */
    private const OPT_LAST_CHECKED_AT = 'fflhub_rsr_qty_last_checked_at';

    /**
     * Cooldown seconds after we *know* we just applied a new vendor mtime.
     * During cooldown we skip FTP entirely (no connect/login/MDTM).
     *
     * Tune:
     * - 120 = faster detection, less savings
     * - 180 = more savings, still safe for a "5 min-ish" vendor cadence
     */
    private const FTP_COOLDOWN_SECONDS = 180;

    /**
     * Hard minimum gap between FTP meta checks (guards overlaps / double-runs).
     */
    private const FTP_MIN_CHECK_GAP_SECONDS = 60;

    public function __construct(DoubleBufferedFulfillmentTable $table)
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

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $this->log('---- RUN START ----', [
            'pid'          => function_exists('getmypid') ? (int) getmypid() : 0,
            'memory_kb'    => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
            'hook'         => self::CRON_HOOK,
            'group'        => $this->get_action_group(),
            'interval_sec' => $this->get_interval_seconds(),
        ]);

        // 0) Get FTP credentials.
        $t_creds = microtime(true);
        $creds   = $this->get_ftp_credentials();

        $this->profile('Credentials retrieval', $t_creds, [
            'ok'       => is_array($creds),
            'has_host' => is_array($creds) ? (bool) ($creds['host'] ?? '') : false,
            'has_user' => is_array($creds) ? (bool) ($creds['username'] ?? '') : false,
            'has_ssl'  => is_array($creds) ? (bool) ($creds['use_ssl'] ?? false) : false,
        ]);

        if (!is_array($creds)) {
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing credentials)');
            return;
        }

        $host     = (string) $creds['host'];
        $username = (string) $creds['username'];
        $password = (string) $creds['password'];
        $use_ssl  = (bool) $creds['use_ssl'];

        // Local path for the quantity file.
        $uploads  = wp_upload_dir();
        $base_dir = trailingslashit($uploads['basedir']) . 'fflhub-rsr';

        $t_paths = microtime(true);

        if (!wp_mkdir_p($base_dir)) {
            $this->log('ERROR: failed to create base directory', [
                'base_dir' => (string) $base_dir,
            ]);
            $this->profile('Prepare local paths (mkdir failed)', $t_paths);
            $this->finalize_run($t_start, $mem_start, 'ERROR (mkdir failed)');
            return;
        }

        $file_name  = 'IM-QTY-CSV.csv';
        $local_path = trailingslashit($base_dir) . $file_name;

        // Remote path on RSR FTP.
        $remote_path = '/ftpdownloads/IM-QTY-CSV.csv';

        $this->profile('Prepare local paths', $t_paths, [
            'base_dir'   => (string) $base_dir,
            'remote_csv' => (string) $remote_path,
            'local_csv'  => (string) $local_path,
            'live_table' => (string) $this->table->get_live_table_name(),
        ]);

        // ---------------------------------------------------------------------
        // FTP connect throttle (skip FTP entirely to avoid handshake cost)
        // ---------------------------------------------------------------------
        $now = time();

        // Hard rate-limit: if a recent run already checked, don't connect again.
        $last_checked_at = (int) get_option(self::OPT_LAST_CHECKED_AT, 0);
        if ($last_checked_at > 0 && ($now - $last_checked_at) < self::FTP_MIN_CHECK_GAP_SECONDS) {
            $this->log('FTP check throttled (recently checked) — skipping connect', [
                'last_checked_at' => $last_checked_at,
                'age_sec'         => (int) ($now - $last_checked_at),
                'min_gap_sec'     => (int) self::FTP_MIN_CHECK_GAP_SECONDS,
            ]);
            $this->finalize_run($t_start, $mem_start, 'SUCCESS (throttle; recent check)');
            return;
        }

        // Cooldown after we *know* we just applied a change:
        // we don't need to immediately reconnect for a few minutes.
        $last_applied_mtime = (int) get_option('fflhub_rsr_qty_last_applied_mtime', 0);
        if ($last_applied_mtime > 0 && $now < ($last_applied_mtime + self::FTP_COOLDOWN_SECONDS)) {
            $this->log('Cooldown after last applied change — skipping FTP connect', [
                'last_applied_mtime' => $last_applied_mtime,
                'cooldown_sec'       => (int) self::FTP_COOLDOWN_SECONDS,
                'skip_for_sec'       => (int) (($last_applied_mtime + self::FTP_COOLDOWN_SECONDS) - $now),
            ]);
            $this->finalize_run($t_start, $mem_start, 'SUCCESS (cooldown)');
            return;
        }

        // Record that we are about to perform an FTP check (prevents overlaps spamming FTP).
        update_option(self::OPT_LAST_CHECKED_AT, $now, false);

        // 1) Connect (and metadata gate).
        $t_ftp = microtime(true);

        $ftp = new FTPClientService(
            $host,
            $username,
            $password,
            $use_ssl,
            2222,
            30,
            true,
            '[FFLHub][RSR][FTP]'
        );
        if (!$ftp->is_connected()) {
            update_option('fflhub_rsr_inventory_last_download_error', current_time('mysql'));
            $this->log('ERROR: FTP connection not available.', [
                'host'    => $host,
                'use_ssl' => $use_ssl ? 1 : 0,
            ]);
            $this->profile('FTP connection (failed)', $t_ftp);
            $this->finalize_run($t_start, $mem_start, 'ERROR (FTP connection failed)');
            return;
        }

        // --- remote last-modified gate (skip heavy work when file unchanged) ---
        // Optimization: only call SIZE when we actually need it (i.e., when MDTM indicates "changed").
        $t_meta = microtime(true);

        $remote_mtime      = (int) ($ftp->get_remote_mtime($remote_path) ?? 0);
        $last_applied_mtime = (int) get_option('fflhub_rsr_qty_last_applied_mtime', 0);

        // Always persist last-seen mtime (useful for admin/debug).
        update_option('fflhub_rsr_qty_last_seen_mtime', $remote_mtime);

        // If MDTM works and file unchanged, skip ALL further work (including SIZE).
        if ($remote_mtime > 0 && $remote_mtime <= $last_applied_mtime) {
            $this->profile('FTP meta check (mtime only)', $t_meta, [
                'remote_mtime'       => $remote_mtime,
                'last_applied_mtime' => $last_applied_mtime > 0 ? $last_applied_mtime : null,
                'changed'            => 0,
                'size_checked'       => 0,
            ]);

            $this->log('No update available (remote mtime unchanged) — skipping download/apply', [
                'remote_mtime'       => $remote_mtime,
                'last_applied_mtime' => $last_applied_mtime,
            ]);

            $this->finalize_run($t_start, $mem_start, 'SUCCESS (no change)');
            return;
        }

        // Only now do we call SIZE (used for "file still changing" stability checks and logging).
        $remote_size = (int) ($ftp->get_remote_size($remote_path) ?? -1);
        if ($remote_size >= 0) {
            update_option('fflhub_rsr_qty_last_seen_size', $remote_size);
        }

        $this->profile('FTP meta check (mtime/size)', $t_meta, [
            'remote_mtime'        => $remote_mtime > 0 ? $remote_mtime : null,
            'remote_size_bytes'   => $remote_size >= 0 ? $remote_size : null,
            'last_applied_mtime'  => $last_applied_mtime > 0 ? $last_applied_mtime : null,
            'changed'             => ($remote_mtime > 0 && $remote_mtime > $last_applied_mtime) ? 1 : 0,
            'size_checked'        => 1,
        ]);

        // If MDTM says "changed", ensure file size is stable before downloading.
        if ($remote_mtime > 0 && $remote_mtime > $last_applied_mtime) {
            if ($remote_size >= 0) {
                usleep(250000); // 0.25s
                $remote_size2 = (int) $ftp->get_remote_size($remote_path);

                if ($remote_size2 >= 0 && $remote_size2 !== $remote_size) {
                    $this->log('Remote file still changing (size unstable) — deferring', [
                        'size1'        => $remote_size,
                        'size2'        => $remote_size2,
                        'remote_mtime' => $remote_mtime,
                    ]);
                    $this->finalize_run($t_start, $mem_start, 'SUCCESS (defer; unstable remote file)');
                    return;
                }
            }
        }

        // 2) Download the file via FTP.
        $csv_kb_before = file_exists($local_path) ? (int) round(((int) filesize($local_path)) / 1024) : 0;

        $ok = $ftp->download_file($remote_path, $local_path);

        $csv_kb_after = file_exists($local_path) ? (int) round(((int) filesize($local_path)) / 1024) : 0;

        $this->profile('FTP download', $t_ftp, [
            'ok'            => $ok ? 1 : 0,
            'csv_kb_before' => (int) $csv_kb_before,
            'csv_kb_after'  => (int) $csv_kb_after,
        ]);

        if (!$ok) {
            update_option('fflhub_rsr_inventory_last_download_error', current_time('mysql'));
            $this->log('ERROR: FTP download failed', [
                'remote_csv' => (string) $remote_path,
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (download failed)');
            return;
        }

        update_option('fflhub_rsr_inventory_last_download', current_time('mysql'));
        delete_option('fflhub_rsr_inventory_last_download_error');

        // 3) Apply inventory updates to live table (LOAD DATA + JOIN).
        $t_apply = microtime(true);

        $processed_rows = 0;
        $apply_stats    = [];

        try {
            $apply_stats    = $this->apply_inventory_updates_via_load_data_profiled($local_path);
            $processed_rows = (int) ($apply_stats['processed_rows'] ?? 0);
        } catch (\Throwable $e) {
            $this->log('ERROR: exception applying inventory updates', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Apply inventory updates (failed)', $t_apply);
            $this->finalize_run($t_start, $mem_start, 'ERROR (apply failed)');
            return;
        }

        $this->profile('Apply inventory updates', $t_apply, $apply_stats);

        update_option('fflhub_rsr_inventory_last_update', current_time('mysql'));
        update_option('fflhub_rsr_inventory_last_update_count', (int) $processed_rows);

        if ($remote_mtime > 0) {
            update_option('fflhub_rsr_qty_last_applied_mtime', $remote_mtime);
        }

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'processed_rows' => (int) $processed_rows,
            'remote_mtime'   => $remote_mtime > 0 ? $remote_mtime : null,
        ]);
    }

    /**
     * Bulk-load IM-QTY-CSV.csv into a persistent staging table via LOAD DATA LOCAL INFILE,
     * then join-update inventory_quantity in the live table.
     *
     * Optimizations:
     * - Persistent stage table + TRUNCATE (avoid per-run CREATE/DROP)
     * - Move CR trim into LOAD DATA (avoid post-load UPDATE scan/write)
     * - Gate expensive join COUNT stats behind DEBUG flag
     * - Update only rows that would change (avoid unnecessary writes/locks)
     *
     * @return array<string,mixed>
     */
    private function apply_inventory_updates_via_load_data_profiled(string $file_path): array
    {
        global $wpdb;

        $t_start = microtime(true);

        if (!file_exists($file_path) || !is_readable($file_path)) {
            $this->log('ERROR: IM-QTY-CSV file missing or unreadable', [
                'file_path' => (string) $file_path,
            ]);
            return [
                'processed_rows' => 0,
                'rows_loaded'    => 0,
                'join_matched'   => 0,
                'would_change'   => 0,
                'join_updated'   => 0,
                'create_ms'      => '0.00',
                'load_ms'        => '0.00',
                'stats_ms'       => '0.00',
                'join_ms'        => '0.00',
                'drop_ms'        => '0.00',
                'total_ms'       => '0.00',
            ];
        }

        $live_table = (string) $this->table->get_live_table_name();
        if ($live_table === '') {
            $this->log('ERROR: could not resolve live table name');
            return [
                'processed_rows' => 0,
                'rows_loaded'    => 0,
                'join_matched'   => 0,
                'would_change'   => 0,
                'join_updated'   => 0,
                'create_ms'      => '0.00',
                'load_ms'        => '0.00',
                'stats_ms'       => '0.00',
                'join_ms'        => '0.00',
                'drop_ms'        => '0.00',
                'total_ms'       => '0.00',
            ];
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $ignore_lines = 0;

        $infile_path_sql = str_replace('\\', '\\\\', $file_path);
        $infile_path_sql = str_replace("'", "\\'", $infile_path_sql);

        // Capabilities check
        $t_check  = microtime(true);
        $mysql_ok = $this->mysql_local_infile_enabled();
        $php_ok   = $this->php_local_infile_enabled();
        DebugLogUtil::log_ctx(self::DEBUG_FLAG, '[FFLHub][RSR Import][DEBUG]', 'LOAD DATA check', [
            'mysql_ok'   => $mysql_ok ? 'true' : 'false',
            'php_ok'     => $php_ok ? 'true' : 'false',
            'result'     => ($mysql_ok && $php_ok) ? 'true' : 'false',
            'elapsed_ms' => number_format((microtime(true) - $t_check) * 1000.0, 2, '.', ''),
        ]);

        $charset     = $wpdb->get_charset_collate();
        $stage_table = $wpdb->prefix . self::STAGE_TABLE_SUFFIX;

        // -----------------------
        // Ensure staging table exists + TRUNCATE
        // -----------------------
        $t_create = microtime(true);

        $create_sql = "
        CREATE TABLE IF NOT EXISTS {$stage_table} (
            rsr_stock_number varchar(64) NOT NULL,
            qty int unsigned NOT NULL,
            PRIMARY KEY (rsr_stock_number)
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

        $load_sql = "
        LOAD DATA LOCAL INFILE '{$infile_path_sql}'
        INTO TABLE {$stage_table}
        FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"'
        LINES TERMINATED BY '\n'
        " . ($ignore_lines > 0 ? "IGNORE {$ignore_lines} LINES" : "") . "
        (rsr_stock_number, qty)
        SET rsr_stock_number = TRIM(TRAILING '\r' FROM rsr_stock_number)
        ";

        $loaded = $wpdb->query($load_sql);
        if ($loaded === false) {
            throw new \RuntimeException('LOAD DATA LOCAL INFILE failed: ' . (string) $wpdb->last_error);
        }

        $load_ms = (microtime(true) - $t_load) * 1000.0;

        // Count rows loaded
        $rows_loaded = 0;
        $count_row   = $wpdb->get_row("SELECT COUNT(*) AS c FROM {$stage_table}", ARRAY_A);
        if (is_array($count_row) && isset($count_row['c'])) {
            $rows_loaded = (int) $count_row['c'];
        }

        // -----------------------
        // Pre-join stats (DEBUG only)
        // -----------------------
        $t_stats = microtime(true);

        $do_stats     = defined(self::DEBUG_FLAG) && constant(self::DEBUG_FLAG);
        $join_matched = 0;
        $would_change = 0;

        if ($do_stats) {
            $join_matched = (int) $wpdb->get_var("
                SELECT COUNT(*)
                FROM {$stage_table} S
                INNER JOIN {$live_table} L
                    ON L.rsr_stock_number = S.rsr_stock_number
            ");

            $would_change = (int) $wpdb->get_var("
                SELECT COUNT(*)
                FROM {$stage_table} S
                INNER JOIN {$live_table} L
                    ON L.rsr_stock_number = S.rsr_stock_number
                WHERE
                    L.inventory_quantity IS NULL
                    OR CAST(L.inventory_quantity AS UNSIGNED) <> S.qty
            ");
        }

        $stats_ms = (microtime(true) - $t_stats) * 1000.0;

        // -----------------------
        // JOIN update live table (only rows that change)
        // -----------------------
        $t_join = microtime(true);

        $join_sql = "
        UPDATE {$live_table} L
        INNER JOIN {$stage_table} S
            ON S.rsr_stock_number = L.rsr_stock_number
        SET L.inventory_quantity = S.qty
        WHERE
            L.inventory_quantity IS NULL
            OR CAST(L.inventory_quantity AS UNSIGNED) <> S.qty
        ";

        $join_updated = $wpdb->query($join_sql);
        if ($join_updated === false) {
            throw new \RuntimeException('JOIN update failed: ' . (string) $wpdb->last_error);
        }

        $join_ms = (microtime(true) - $t_join) * 1000.0;

        // No DROP for persistent stage table
        $drop_ms = 0.0;

        $t_total_ms = (microtime(true) - $t_start) * 1000.0;

        $stats = [
            'processed_rows' => (int) $rows_loaded,
            'rows_loaded'    => (int) $rows_loaded,
            'join_matched'   => (int) $join_matched,
            'would_change'   => (int) $would_change,
            'join_updated'   => (int) $join_updated,
            'stage_table'    => (string) $stage_table,
            'ignore_lines'   => (int) $ignore_lines,
            'create_ms'      => number_format($create_ms, 2, '.', ''),
            'load_ms'        => number_format($load_ms, 2, '.', ''),
            'stats_ms'       => number_format($stats_ms, 2, '.', ''),
            'join_ms'        => number_format($join_ms, 2, '.', ''),
            'drop_ms'        => number_format($drop_ms, 2, '.', ''),
            'total_ms'       => number_format($t_total_ms, 2, '.', ''),
        ];

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, 'PROFILE: apply_inventory_updates_via_load_data() breakdown', $stats);

        return $stats;
    }

    /**
     * MySQL server variable local_infile must be ON.
     */
    private function mysql_local_infile_enabled(): bool
    {
        global $wpdb;
        $row = $wpdb->get_row("SHOW VARIABLES LIKE 'local_infile'", ARRAY_A);
        if (!is_array($row)) {
            return false;
        }
        $val = strtolower((string) ($row['Value'] ?? $row['value'] ?? ''));
        return $val === 'on' || $val === '1' || $val === 'true';
    }

    /**
     * PHP must allow local infile for mysqli/pdo_mysql.
     */
    private function php_local_infile_enabled(): bool
    {
        $mysqli = ini_get('mysqli.allow_local_infile');
        $pdo    = ini_get('pdo_mysql.allow_local_infile');

        $ok_mysqli = ($mysqli !== false) ? $this->ini_truthy((string) $mysqli) : false;
        $ok_pdo    = ($pdo !== false) ? $this->ini_truthy((string) $pdo) : false;

        return ($ok_mysqli || $ok_pdo);
    }

    private function ini_truthy(string $v): bool
    {
        $v = strtolower(trim($v));
        return in_array($v, ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * Retrieve and validate FTP credentials from RSR distributor settings.
     *
     * @return array{host:string,username:string,password:string,use_ssl:bool}|null
     */
    public function get_ftp_credentials(): ?array
    {
        $host      = Options::get_distributor_option('rsr', 'ftp_host', '');
        $username  = Options::get_distributor_option('rsr', 'ftp_username', '');
        $password  = Options::get_distributor_option('rsr', 'ftp_password', '');
        $use_ssl_s = Options::get_distributor_option('rsr', 'ftp_use_ssl', '');

        $host     = trim((string) $host);
        $username = trim((string) $username);
        $password = trim((string) $password);
        $use_ssl  = ($use_ssl_s !== '');

        if ($host === '' || $username === '' || $password === '') {
            $this->log('Missing FTP credentials', [
                'host' => $host !== '' ? 'set' : 'empty',
                'user' => $username !== '' ? 'set' : 'empty',
            ]);
            return null;
        }

        return [
            'host'     => $host,
            'username' => $username,
            'password' => $password,
            'use_ssl'  => $use_ssl,
        ];
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
