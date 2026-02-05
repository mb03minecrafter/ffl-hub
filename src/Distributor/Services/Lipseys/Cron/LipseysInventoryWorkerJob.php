<?php

namespace FFLHub\Distributor\Services\Lipseys\Cron;

if (!defined('ABSPATH')) exit;

use FFLHub\Distributor\Services\Lipseys\LipseysRawAPI\LipseysClient;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

final class LipseysInventoryWorkerJob
{
    public const HOOK = 'fflhub_lipseys_pricing_quantity_worker';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHUB][LipseysInventoryWorker]';

    private const OPT_LAST_SEEN_VERSION     = 'fflhub_lipseys_last_seen_version'; // we treat nextUpdate raw as "version"
    private const OPT_NEXT_UPDATE_UNIX      = 'fflhub_lipseys_next_update_unix';
    private const OPT_LAST_APPLIED_VERSION  = 'fflhub_lipseys_last_applied_version';

    private const FILE_LOG_NAME = 'lipseys_cron.log';

    // Singleton worker identity (must match cron)
    private const WORKER_ARGS  = ['singleton' => 1];
    private const WORKER_GROUP = 'fflhub_catalog';

    // Schedule next run shortly after vendor says it's ready
    private const WORKER_SKEW_SEC = 60;

    // If we fail to extract nextUpdate, keep the chain alive with a retry
    private const FALLBACK_RETRY_SEC = 600;

    // Debug limits (avoid log spam)
    private const MAX_WARNINGS_LOGGED = 25;
    private const MAX_CREATE_TABLE_CHARS = 1200;

    public static function run(DoubleBufferedFulfillmentTable $table): void
    {
        $t0 = microtime(true);

        $version = (string) get_option(self::OPT_LAST_SEEN_VERSION, '');
        $unix    = (int) get_option(self::OPT_NEXT_UPDATE_UNIX, 0);

        self::log('RUN START', [
            'pid'     => function_exists('getmypid') ? (int) getmypid() : 0,
            'version' => $version,
            'unix'    => $unix,
            'mem_kb'  => function_exists('memory_get_usage') ? (int) round(memory_get_usage(true) / 1024) : 0,
        ]);

        $email = (string) Options::get_distributor_option('lipseys', 'dealer_email', '');
        $pass  = (string) Options::get_distributor_option('lipseys', 'dealer_password', '');

        if ($email === '' || $pass === '') {
            self::log('Missing credentials');
            self::log('RUN END', ['elapsed_ms' => (int) round((microtime(true) - $t0) * 1000)]);
            return;
        }

        try {
            $client = new LipseysClient($email, $pass);
        } catch (\Throwable $e) {
            self::log('Client creation failed', ['error' => $e->getMessage()]);
            self::log('RUN END', ['elapsed_ms' => (int) round((microtime(true) - $t0) * 1000)]);
            return;
        }

        $stats = null;
        try {
            $stats = self::run_heavy_inventory_update($table, $client);
        } catch (\Throwable $e) {
            self::log('HEAVY UPDATE FAILED', ['error' => $e->getMessage()]);
            self::schedule_next_worker_fallback('heavy_failed');
            self::log('RUN END', ['elapsed_ms' => (int) round((microtime(true) - $t0) * 1000)]);
            return;
        }

        // Mark applied if we have a current version.
        if ($version !== '') {
            update_option(self::OPT_LAST_APPLIED_VERSION, $version);
        }

        self::log('HEAVY SUCCESS', [
            'version_applied' => $version,
            'elapsed_ms'      => (int) round((microtime(true) - $t0) * 1000),
        ]);

        $nextRaw  = is_array($stats) ? (string) ($stats['next_update_raw'] ?? '') : '';
        $nextUnix = is_array($stats) ? (int) ($stats['next_update_unix'] ?? 0) : 0;

        if ($nextRaw !== '') {
            update_option(self::OPT_LAST_SEEN_VERSION, $nextRaw);
        }
        if ($nextUnix > 0) {
            update_option(self::OPT_NEXT_UPDATE_UNIX, $nextUnix);
        }

        self::log('NEXT UPDATE (from TSV stream)', [
            'next_raw'  => $nextRaw !== '' ? $nextRaw : null,
            'next_unix' => $nextUnix > 0 ? $nextUnix : null,
        ]);

        self::schedule_next_worker($nextUnix, $nextRaw);

        self::log('RUN END', [
            'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);
    }

    private static function run_heavy_inventory_update(DoubleBufferedFulfillmentTable $table, LipseysClient $client): array
    {
        $t0 = microtime(true);

        $live_table = (string) $table->get_live_table_name();
        if ($live_table === '') {
            throw new \RuntimeException('Could not resolve live table name.');
        }

        $uploads  = wp_upload_dir();
        $base_dir = trailingslashit((string) ($uploads['basedir'] ?? '')) . 'fflhub/lipseys';
        if (!wp_mkdir_p($base_dir)) {
            throw new \RuntimeException('Failed to create base directory: ' . $base_dir);
        }

        $tsv = trailingslashit($base_dir) . 'pq_' . gmdate('Ymd_His') . '.tsv';

        self::log('Starting TSV stream', ['path' => $tsv]);

        $stats = $client->PricingAndQuantityToTsv(
            $tsv,
            [
                'lipseys_item_number',
                'inventory_quantity',
                'allocation_status',
                'distributor_price',
                'retail_map',
            ],
            static function (array $i) {
                if (empty($i['itemNumber'])) return null;

                return [
                    'lipseys_item_number' => (string) $i['itemNumber'],
                    'inventory_quantity'  => (string) ((int) ($i['quantity'] ?? 0)),
                    'allocation_status'   => !empty($i['allocated']) ? 'Y' : '',
                    'distributor_price'   => (string) ($i['currentPrice'] ?? ''),
                    'retail_map'          => (string) ($i['retailMap'] ?? ''),
                ];
            }
        );

        self::log('STREAM COMPLETE', is_array($stats) ? $stats : ['stats' => $stats]);

        self::debug_tsv_sample($tsv);

        $apply = self::apply_load_data($tsv, $live_table);

        self::log('DB APPLY COMPLETE', $apply);

        self::log('HEAVY DONE', [
            'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);

        return is_array($stats) ? $stats : ['success' => false];
    }

    private static function schedule_next_worker(int $next_unix, string $next_raw): void
    {
        if (!function_exists('as_schedule_single_action') || !function_exists('as_unschedule_all_actions')) {
            self::log('Action Scheduler unavailable — cannot chain schedule');
            return;
        }

        $now = time();

        $target = ($next_unix > 0)
            ? (int) max($now + 10, $next_unix + self::WORKER_SKEW_SEC)
            : (int) ($now + self::FALLBACK_RETRY_SEC);

        as_unschedule_all_actions(self::HOOK, self::WORKER_ARGS, self::WORKER_GROUP);
        as_schedule_single_action($target, self::HOOK, self::WORKER_ARGS, self::WORKER_GROUP);

        self::log('Next worker scheduled (self-chain)', [
            'target_unix' => $target,
            'next_unix'   => $next_unix > 0 ? $next_unix : null,
            'next_raw'    => $next_raw !== '' ? $next_raw : null,
            'skew_sec'    => self::WORKER_SKEW_SEC,
            'args'        => self::WORKER_ARGS,
            'group'       => self::WORKER_GROUP,
        ]);
    }

    private static function schedule_next_worker_fallback(string $reason): void
    {
        if (!function_exists('as_schedule_single_action') || !function_exists('as_unschedule_all_actions')) {
            return;
        }

        $target = time() + self::FALLBACK_RETRY_SEC;

        as_unschedule_all_actions(self::HOOK, self::WORKER_ARGS, self::WORKER_GROUP);
        as_schedule_single_action($target, self::HOOK, self::WORKER_ARGS, self::WORKER_GROUP);

        self::log('Next worker scheduled (fallback)', [
            'reason'      => $reason,
            'target_unix' => $target,
            'retry_sec'   => self::FALLBACK_RETRY_SEC,
        ]);
    }

    private static function apply_load_data(string $tsv, string $live): array
    {
        global $wpdb;

        $pid = function_exists('getmypid') ? (int) getmypid() : (int) wp_rand(1000, 9999);
        $stage = $wpdb->prefix . 'fflhub_lipseys_pq_stage_' . $pid;

        $wpdb->query("DROP TABLE IF EXISTS {$stage}");

        // Minimal stage schema that matches the TSV (5 columns, in-order)
        $charset = $wpdb->get_charset_collate();
        $create_sql = "
        CREATE TABLE {$stage} (
            lipseys_item_number VARCHAR(64) NOT NULL,
            inventory_quantity  VARCHAR(32) NULL,
            allocation_status   VARCHAR(64) NULL,
            distributor_price   VARCHAR(32) NULL,
            retail_map          VARCHAR(32) NULL,
            PRIMARY KEY (lipseys_item_number)
        ) {$charset};
    ";

        $created = $wpdb->query($create_sql);
        if ($created === false) {
            throw new \RuntimeException('Stage create failed: ' . (string) $wpdb->last_error);
        }

        // Escape file path for SQL literal
        $path = str_replace('\\', '\\\\', $tsv);
        $path = str_replace("'", "\\'", $path);

        // IMPORTANT: explicit column list (prevents "shifted columns" bug)
        $load_sql = "
        LOAD DATA LOCAL INFILE '{$path}'
        INTO TABLE {$stage}
        FIELDS TERMINATED BY '\t'
        LINES TERMINATED BY '\n'
        (lipseys_item_number, inventory_quantity, allocation_status, distributor_price, retail_map)
    ";

        $loaded = $wpdb->query($load_sql);
        if ($loaded === false) {
            $wpdb->query("DROP TABLE IF EXISTS {$stage}");
            throw new \RuntimeException('LOAD DATA failed: ' . (string) $wpdb->last_error);
        }

        // CRLF hygiene (harmless even if file is LF)
        $wpdb->query("UPDATE {$stage} SET lipseys_item_number = TRIM(TRAILING '\r' FROM lipseys_item_number)");
        $wpdb->query("UPDATE {$stage} SET inventory_quantity  = TRIM(TRAILING '\r' FROM inventory_quantity)");
        $wpdb->query("UPDATE {$stage} SET allocation_status   = TRIM(TRAILING '\r' FROM allocation_status)");
        $wpdb->query("UPDATE {$stage} SET distributor_price   = TRIM(TRAILING '\r' FROM distributor_price)");
        $wpdb->query("UPDATE {$stage} SET retail_map          = TRIM(TRAILING '\r' FROM retail_map)");

        // Truth: how many rows are actually in stage
        $stage_count = (int) ($wpdb->get_var("SELECT COUNT(*) FROM {$stage}") ?? 0);

        // Join-update LIVE (ONLY the 4 PQ fields)
        $update_sql = "
        UPDATE {$live} L
        INNER JOIN {$stage} S
            ON S.lipseys_item_number = L.lipseys_item_number
        SET
            L.inventory_quantity = S.inventory_quantity,
            L.allocation_status  = S.allocation_status,
            L.distributor_price  = S.distributor_price,
            L.retail_map         = S.retail_map
    ";

        $updated = $wpdb->query($update_sql);
        if ($updated === false) {
            $wpdb->query("DROP TABLE IF EXISTS {$stage}");
            throw new \RuntimeException('JOIN update failed: ' . (string) $wpdb->last_error);
        }

        $wpdb->query("DROP TABLE IF EXISTS {$stage}");

        return [
            // NOTE: $loaded is NOT reliable as "rows loaded" here; stage_count is.
            'rows_loaded'  => $stage_count,
            'rows_updated' => (int) $updated,
            'stage_table'  => (string) $stage,
            'stage_count'  => $stage_count,
        ];
    }


    private static function db_debug_env(): void
    {
        global $wpdb;

        $sql_mode = null;
        $local_infile = null;

        try {
            $sql_mode = $wpdb->get_var("SELECT @@SESSION.sql_mode");
        } catch (\Throwable $e) {
        }
        try {
            $local_infile = $wpdb->get_var("SELECT @@GLOBAL.local_infile");
        } catch (\Throwable $e) {
        }

        self::log('DB DEBUG: ENV', [
            'sql_mode'     => is_string($sql_mode) ? $sql_mode : null,
            'local_infile' => is_scalar($local_infile) ? (string) $local_infile : null,
        ]);
    }

    private static function db_debug_create_table(string $table, string $label): void
    {
        global $wpdb;

        try {
            $row = $wpdb->get_row("SHOW CREATE TABLE {$table}", ARRAY_A);
            $create = is_array($row) ? (string) ($row['Create Table'] ?? '') : '';
            if ($create !== '') {
                self::log("DB DEBUG: SHOW CREATE TABLE ({$label})", [
                    'table'  => $table,
                    'create' => self::truncate_str($create, self::MAX_CREATE_TABLE_CHARS),
                ]);
            } else {
                self::log("DB DEBUG: SHOW CREATE TABLE ({$label}) empty", ['table' => $table]);
            }
        } catch (\Throwable $e) {
            self::log("DB DEBUG: SHOW CREATE TABLE ({$label}) failed", [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function db_get_warnings(): array
    {
        global $wpdb;

        try {
            $rows = $wpdb->get_results("SHOW WARNINGS", ARRAY_A);
            if (!is_array($rows)) return [];

            $out = [];
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $out[] = [
                    'Level'   => $r['Level']   ?? ($r['level']   ?? null),
                    'Code'    => $r['Code']    ?? ($r['code']    ?? null),
                    'Message' => $r['Message'] ?? ($r['message'] ?? null),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [
                ['Level' => 'error', 'Code' => null, 'Message' => 'SHOW WARNINGS failed: ' . $e->getMessage()],
            ];
        }
    }

    private static function debug_tsv_sample(string $tsv): void
    {
        try {
            if (!is_readable($tsv)) {
                self::log('TSV DEBUG: not readable', ['path' => $tsv]);
                return;
            }

            $fh = @fopen($tsv, 'rb');
            if (!$fh) {
                self::log('TSV DEBUG: fopen failed', ['path' => $tsv]);
                return;
            }

            $line = (string) fgets($fh);
            fclose($fh);

            $line_trim = trim($line);
            $fields = $line_trim === '' ? [] : explode("\t", $line_trim);

            self::log('TSV DEBUG: sample', [
                'path'        => $tsv,
                'first_line'  => self::truncate_str($line_trim, 500),
                'field_count' => count($fields),
            ]);
        } catch (\Throwable $e) {
            self::log('TSV DEBUG: exception', ['error' => $e->getMessage()]);
        }
    }

    private static function truncate_str(string $s, int $max): string
    {
        if ($max <= 0) return '';
        if (strlen($s) <= $max) return $s;
        return substr($s, 0, $max) . '…';
    }

    private static function truncate_array(array $arr, int $max): array
    {
        if ($max <= 0) return [];
        if (count($arr) <= $max) return $arr;
        return array_slice($arr, 0, $max);
    }

    private static function log(string $msg, array $ctx = []): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $msg, $ctx);
        self::file_log(self::LOG_PREFIX . ' ' . $msg, $ctx);
    }

    private static function file_log(string $msg, array $ctx = []): void
    {
        try {
            $up = wp_upload_dir();
            $basedir = (string) ($up['basedir'] ?? '');
            if ($basedir === '') return;

            $dir = rtrim($basedir, '/\\') . '/fflhub/logs';
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            if (!is_dir($dir) || !is_writable($dir)) return;

            $file = $dir . '/' . self::FILE_LOG_NAME;

            $pid = function_exists('getmypid') ? (int) getmypid() : 0;
            $line = '[' . gmdate('Y-m-d H:i:s') . '][pid:' . $pid . '] ' . $msg;
            if (!empty($ctx)) $line .= ' ' . wp_json_encode($ctx);
            $line .= PHP_EOL;

            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // never break jobs
        }
    }
}
