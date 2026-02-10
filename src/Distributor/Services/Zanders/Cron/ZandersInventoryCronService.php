<?php

namespace FFLHub\Distributor\Services\Zanders\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\FTP\FTPClientService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * Cron job for near-real-time Zanders inventory + tier pricing updates using liveinv.csv.
 *
 * Runs frequently:
 *  - downloads /Inventory/liveinv.csv via FTP to uploads/fflhub-zanders/
 *  - bulk loads into a persistent staging table via LOAD DATA LOCAL INFILE
 *  - join-updates fast-moving columns in the LIVE fulfillment table:
 *      available, price_1/2/3, bulk_qty_1/2/3
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

    // Zanders updates this file ~every 5 minutes; mimic RSR cadence.
    private const FTP_COOLDOWN_SECONDS      = 180; // 3 minutes after applying a change
    private const FTP_MIN_CHECK_GAP_SECONDS = 60;  // 1 minute hard throttle

    private const REMOTE_PATH    = '/Inventory/liveinv.csv';
    private const LOCAL_DIR      = 'fflhub-zanders';
    private const LOCAL_FILENAME = 'liveinv.csv';

    private const FORCE_UPDATE = true;


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

        if (self::FORCE_UPDATE) {
            $this->log('FORCE_UPDATE enabled — bypassing mtime/cooldown gates');
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
        // FTP connect throttle (skip FTP entirely to avoid handshake cost)
        // ---------------------------------------------------------------------
        $now = time();

        $last_checked_at = (int) get_option(self::OPT_LAST_CHECKED_AT, 0);
        if (!self::FORCE_UPDATE && $last_checked_at > 0 && ($now - $last_checked_at) < self::FTP_MIN_CHECK_GAP_SECONDS) {
            $this->log('FTP check throttled (recently checked) — skipping connect', [
                'last_checked_at' => $last_checked_at,
                'age_sec'         => (int) ($now - $last_checked_at),
                'min_gap_sec'     => (int) self::FTP_MIN_CHECK_GAP_SECONDS,
            ]);
            $this->finalize_run($t_start, $mem_start, 'SUCCESS (throttle; recent check)');
            return;
        }


        $last_applied_mtime = (int) get_option('fflhub_zanders_qty_last_applied_mtime', 0);
        if (!self::FORCE_UPDATE && $last_applied_mtime > 0 && $now < ($last_applied_mtime + self::FTP_COOLDOWN_SECONDS)) {
            $this->log('Cooldown after last applied change — skipping FTP connect', [
                'last_applied_mtime' => $last_applied_mtime,
                'cooldown_sec'       => (int) self::FTP_COOLDOWN_SECONDS,
                'skip_for_sec'       => (int) (($last_applied_mtime + self::FTP_COOLDOWN_SECONDS) - $now),
            ]);
            $this->finalize_run($t_start, $mem_start, 'SUCCESS (cooldown)');
            return;
        }

        update_option(self::OPT_LAST_CHECKED_AT, $now, false);

        // 2) FTP connect
        $t_ftp = microtime(true);

        $ftp = new FTPClientService(
            $host,
            $username,
            $password,
            $use_ssl, // false
            $port,    // 21
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

        // 3) Remote meta gate (mtime first; SIZE only if changed)
        $t_meta = microtime(true);

        $remote_mtime      = (int) ($ftp->get_remote_mtime($remote_path) ?? 0);
        $last_applied_mtime = (int) get_option('fflhub_zanders_qty_last_applied_mtime', 0);

        update_option('fflhub_zanders_qty_last_seen_mtime', $remote_mtime);

        if (!self::FORCE_UPDATE && $remote_mtime > 0 && $remote_mtime <= $last_applied_mtime) {
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

        $remote_size = (int) ($ftp->get_remote_size($remote_path) ?? -1);
        if ($remote_size >= 0) {
            update_option('fflhub_zanders_qty_last_seen_size', $remote_size);
        }

        $this->profile('FTP meta check (mtime/size)', $t_meta, [
            'remote_mtime'       => $remote_mtime > 0 ? $remote_mtime : null,
            'remote_size_bytes'  => $remote_size >= 0 ? $remote_size : null,
            'last_applied_mtime' => $last_applied_mtime > 0 ? $last_applied_mtime : null,
            'changed'            => ($remote_mtime > 0 && $remote_mtime > $last_applied_mtime) ? 1 : 0,
            'size_checked'       => 1,
        ]);

        // Optional stability guard: ensure file isn't mid-write
        if ($remote_mtime > 0 && $remote_mtime > $last_applied_mtime && $remote_size >= 0) {
            usleep(250000);
            $remote_size2 = (int) ($ftp->get_remote_size($remote_path) ?? -1);
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

        // Header row expected.
        $ignore_lines = 1;

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
            price2      decimal(12,4) NULL,
            price3      decimal(12,4) NULL,
            qty1        int unsigned NULL,
            qty2        int unsigned NULL,
            qty3        int unsigned NULL,
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
            available  = NULLIF(TRIM(BOTH '\\r' FROM TRIM(@available)), '') + 0,
            price1     = NULLIF(TRIM(BOTH '\\r' FROM TRIM(@price1)), ''),
            price2     = NULLIF(TRIM(BOTH '\\r' FROM TRIM(@price2)), ''),
            price3     = NULLIF(TRIM(BOTH '\\r' FROM TRIM(@price3)), ''),
            qty1       = NULLIF(TRIM(BOTH '\\r' FROM TRIM(@qty1)), '') + 0,
            qty2       = NULLIF(TRIM(BOTH '\\r' FROM TRIM(@qty2)), '') + 0,
            qty3       = NULLIF(TRIM(BOTH '\\r' FROM TRIM(@qty3)), '') + 0
        ";

        $loaded = $wpdb->query($load_sql);
        if ($loaded === false) {
            throw new \RuntimeException('LOAD DATA LOCAL INFILE failed: ' . (string) $wpdb->last_error);
        }

        $load_ms = (microtime(true) - $t_load) * 1000.0;

        // Count rows loaded
        $rows_loaded = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$stage_table}");

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
                    ON L.zanders_item_number = S.itemnumber
            ");

            $would_change = (int) $wpdb->get_var("
                SELECT COUNT(*)
                FROM {$stage_table} S
                INNER JOIN {$live_table} L
                    ON L.zanders_item_number = S.itemnumber
                WHERE
                    (L.available IS NULL OR L.available = '' OR CAST(L.available AS UNSIGNED) <> IFNULL(S.available, CAST(L.available AS UNSIGNED)))
                 OR (NULLIF(L.price_1,'') IS NULL AND S.price1 IS NOT NULL)
                 OR (NULLIF(L.price_1,'') IS NOT NULL AND S.price1 IS NULL)
                 OR (NULLIF(L.price_1,'') IS NOT NULL AND S.price1 IS NOT NULL AND CAST(NULLIF(L.price_1,'') AS DECIMAL(12,4)) <> S.price1)
                 OR (NULLIF(L.price_2,'') IS NULL AND S.price2 IS NOT NULL)
                 OR (NULLIF(L.price_2,'') IS NOT NULL AND S.price2 IS NULL)
                 OR (NULLIF(L.price_2,'') IS NOT NULL AND S.price2 IS NOT NULL AND CAST(NULLIF(L.price_2,'') AS DECIMAL(12,4)) <> S.price2)
                 OR (NULLIF(L.price_3,'') IS NULL AND S.price3 IS NOT NULL)
                 OR (NULLIF(L.price_3,'') IS NOT NULL AND S.price3 IS NULL)
                 OR (NULLIF(L.price_3,'') IS NOT NULL AND S.price3 IS NOT NULL AND CAST(NULLIF(L.price_3,'') AS DECIMAL(12,4)) <> S.price3)
                 OR (NULLIF(L.bulk_qty_1,'') IS NULL AND S.qty1 IS NOT NULL)
                 OR (NULLIF(L.bulk_qty_1,'') IS NOT NULL AND S.qty1 IS NULL)
                 OR (NULLIF(L.bulk_qty_1,'') IS NOT NULL AND S.qty1 IS NOT NULL AND CAST(NULLIF(L.bulk_qty_1,'') AS UNSIGNED) <> S.qty1)
                 OR (NULLIF(L.bulk_qty_2,'') IS NULL AND S.qty2 IS NOT NULL)
                 OR (NULLIF(L.bulk_qty_2,'') IS NOT NULL AND S.qty2 IS NULL)
                 OR (NULLIF(L.bulk_qty_2,'') IS NOT NULL AND S.qty2 IS NOT NULL AND CAST(NULLIF(L.bulk_qty_2,'') AS UNSIGNED) <> S.qty2)
                 OR (NULLIF(L.bulk_qty_3,'') IS NULL AND S.qty3 IS NOT NULL)
                 OR (NULLIF(L.bulk_qty_3,'') IS NOT NULL AND S.qty3 IS NULL)
                 OR (NULLIF(L.bulk_qty_3,'') IS NOT NULL AND S.qty3 IS NOT NULL AND CAST(NULLIF(L.bulk_qty_3,'') AS UNSIGNED) <> S.qty3)
            ");
        }

        $stats_ms = (microtime(true) - $t_stats) * 1000.0;

        // -----------------------
        // JOIN update (only rows that change)
        // -----------------------
        $t_join = microtime(true);

        $join_sql = "
        UPDATE {$live_table} L
        INNER JOIN {$stage_table} S
            ON S.itemnumber = L.zanders_item_number
        SET
            L.available   = IFNULL(CAST(S.available AS CHAR), ''),
            L.price_1     = IFNULL(CAST(S.price1 AS CHAR), ''),
            L.price_2     = IFNULL(CAST(S.price2 AS CHAR), ''),
            L.price_3     = IFNULL(CAST(S.price3 AS CHAR), ''),
            L.bulk_qty_1  = IFNULL(CAST(S.qty1 AS CHAR), ''),
            L.bulk_qty_2  = IFNULL(CAST(S.qty2 AS CHAR), ''),
            L.bulk_qty_3  = IFNULL(CAST(S.qty3 AS CHAR), '')
        WHERE
            (L.available IS NULL OR L.available = '' OR CAST(L.available AS UNSIGNED) <> IFNULL(S.available, CAST(L.available AS UNSIGNED)))
         OR (NULLIF(L.price_1,'') IS NULL AND S.price1 IS NOT NULL)
         OR (NULLIF(L.price_1,'') IS NOT NULL AND S.price1 IS NULL)
         OR (NULLIF(L.price_1,'') IS NOT NULL AND S.price1 IS NOT NULL AND CAST(NULLIF(L.price_1,'') AS DECIMAL(12,4)) <> S.price1)
         OR (NULLIF(L.price_2,'') IS NULL AND S.price2 IS NOT NULL)
         OR (NULLIF(L.price_2,'') IS NOT NULL AND S.price2 IS NULL)
         OR (NULLIF(L.price_2,'') IS NOT NULL AND S.price2 IS NOT NULL AND CAST(NULLIF(L.price_2,'') AS DECIMAL(12,4)) <> S.price2)
         OR (NULLIF(L.price_3,'') IS NULL AND S.price3 IS NOT NULL)
         OR (NULLIF(L.price_3,'') IS NOT NULL AND S.price3 IS NULL)
         OR (NULLIF(L.price_3,'') IS NOT NULL AND S.price3 IS NOT NULL AND CAST(NULLIF(L.price_3,'') AS DECIMAL(12,4)) <> S.price3)
         OR (NULLIF(L.bulk_qty_1,'') IS NULL AND S.qty1 IS NOT NULL)
         OR (NULLIF(L.bulk_qty_1,'') IS NOT NULL AND S.qty1 IS NULL)
         OR (NULLIF(L.bulk_qty_1,'') IS NOT NULL AND S.qty1 IS NOT NULL AND CAST(NULLIF(L.bulk_qty_1,'') AS UNSIGNED) <> S.qty1)
         OR (NULLIF(L.bulk_qty_2,'') IS NULL AND S.qty2 IS NOT NULL)
         OR (NULLIF(L.bulk_qty_2,'') IS NOT NULL AND S.qty2 IS NULL)
         OR (NULLIF(L.bulk_qty_2,'') IS NOT NULL AND S.qty2 IS NOT NULL AND CAST(NULLIF(L.bulk_qty_2,'') AS UNSIGNED) <> S.qty2)
         OR (NULLIF(L.bulk_qty_3,'') IS NULL AND S.qty3 IS NOT NULL)
         OR (NULLIF(L.bulk_qty_3,'') IS NOT NULL AND S.qty3 IS NULL)
         OR (NULLIF(L.bulk_qty_3,'') IS NOT NULL AND S.qty3 IS NOT NULL AND CAST(NULLIF(L.bulk_qty_3,'') AS UNSIGNED) <> S.qty3)
        ";

        $join_updated = $wpdb->query($join_sql);
        if ($join_updated === false) {
            throw new \RuntimeException('JOIN update failed: ' . (string) $wpdb->last_error);
        }

        $join_ms = (microtime(true) - $t_join) * 1000.0;

        $drop_ms    = 0.0; // persistent table
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

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, 'PROFILE: apply_inventory_pricing_updates_via_load_data() breakdown', $stats);

        return $stats;
    }

    /**
     * Retrieve and validate FTP credentials from Zanders distributor settings.
     *
     * Note: Zanders is FTP (no TLS). We enforce use_ssl=false and port=21 defaults.
     *
     * @return array{host:string,username:string,password:string,use_ssl:bool,port:int}|null
     */
    public function get_ftp_credentials(): ?array
    {
        $host     = Options::get_distributor_option('zanders', 'ftp_host', '');
        $username = Options::get_distributor_option('zanders', 'ftp_username', '');
        $password = Options::get_distributor_option('zanders', 'ftp_password', '');

        $host     = trim((string) $host);
        $username = trim((string) $username);
        $password = trim((string) $password);

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
            'use_ssl'  => false,
            'port'     => 21,
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
