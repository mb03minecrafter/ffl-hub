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

    private const OPT_LAST_SEEN_VERSION     = 'fflhub_lipseys_last_seen_version';
    private const OPT_NEXT_UPDATE_UNIX      = 'fflhub_lipseys_next_update_unix';
    private const OPT_LAST_APPLIED_VERSION  = 'fflhub_lipseys_last_applied_version';

    private const FILE_LOG_NAME = 'lipseys_cron.log';

    private const WORKER_ARGS  = ['singleton' => 1];
    private const WORKER_GROUP = 'fflhub_catalog';

    private const WORKER_SKEW_SEC = 60;
    private const FALLBACK_RETRY_SEC = 600;

    // Debug limits (avoid log spam)
    private const MAX_WARNINGS_LOGGED = 25;
    private const MAX_CREATE_TABLE_CHARS = 1200;

    public static function run(DoubleBufferedFulfillmentTable $table): void
    {
        $t_total = microtime(true);

        $version = (string) get_option(self::OPT_LAST_SEEN_VERSION, '');
        $unix    = (int) get_option(self::OPT_NEXT_UPDATE_UNIX, 0);

        self::log('RUN START', [
            'pid'     => function_exists('getmypid') ? (int) getmypid() : 0,
            'version' => $version,
            'unix'    => $unix,
            'mem_kb'  => function_exists('memory_get_usage') ? (int) round(memory_get_usage(true) / 1024) : 0,
        ]);

        // --- Creds timing ---
        $t_creds = microtime(true);
        $email = (string) Options::get_distributor_option('lipseys', 'dealer_email', '');
        $pass  = (string) Options::get_distributor_option('lipseys', 'dealer_password', '');

        self::log('PROFILE: creds', [
            'has_email' => $email !== '' ? 1 : 0,
            'has_pass'  => $pass !== '' ? 1 : 0,
            'elapsed_ms' => self::ms_since($t_creds),
        ]);

        if ($email === '' || $pass === '') {
            self::log('Missing credentials');
            self::log('RUN END', [
                'elapsed_ms' => self::ms_since($t_total),
            ]);
            return;
        }

        // --- Client timing ---
        $t_client = microtime(true);
        try {
            $client = new LipseysClient($email, $pass);
        } catch (\Throwable $e) {
            self::log('Client creation failed', ['error' => $e->getMessage()]);
            self::log('PROFILE: client', [
                'ok'         => 0,
                'elapsed_ms' => self::ms_since($t_client),
            ]);
            self::log('RUN END', [
                'elapsed_ms' => self::ms_since($t_total),
            ]);
            return;
        }

        self::log('PROFILE: client', [
            'ok'         => 1,
            'elapsed_ms' => self::ms_since($t_client),
        ]);

        // --- Heavy timing (stream + apply) ---
        $t_heavy = microtime(true);
        $stats = null;

        try {
            $stats = self::run_heavy_inventory_update($table, $client);
        } catch (\Throwable $e) {
            self::log('HEAVY UPDATE FAILED', [
                'error' => $e->getMessage(),
                'elapsed_ms' => self::ms_since($t_heavy),
            ]);

            self::schedule_next_worker_fallback('heavy_failed');

            self::log('RUN END', [
                'elapsed_ms' => self::ms_since($t_total),
            ]);
            return;
        }

        // Mark applied if we have a current version.
        if ($version !== '') {
            update_option(self::OPT_LAST_APPLIED_VERSION, $version);
        }

        self::log('HEAVY SUCCESS', [
            'version_applied' => $version,
            'elapsed_ms'      => self::ms_since($t_heavy),
        ]);

        $nextRaw  = is_array($stats) ? (string) ($stats['next_update_raw'] ?? '') : '';
        $nextUnix = is_array($stats) ? (int) ($stats['next_update_unix'] ?? 0) : 0;

        if ($nextRaw !== '') update_option(self::OPT_LAST_SEEN_VERSION, $nextRaw);
        if ($nextUnix > 0)   update_option(self::OPT_NEXT_UPDATE_UNIX, $nextUnix);

        self::log('NEXT UPDATE (from TSV stream)', [
            'next_raw'  => $nextRaw !== '' ? $nextRaw : null,
            'next_unix' => $nextUnix > 0 ? $nextUnix : null,
        ]);

        self::schedule_next_worker($nextUnix, $nextRaw);

        self::log('RUN END', [
            'elapsed_ms' => self::ms_since($t_total),
        ]);
    }

    private static function run_heavy_inventory_update(DoubleBufferedFulfillmentTable $table, LipseysClient $client): array
    {
        $t_heavy = microtime(true);

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

        // --- Stream timing ---
        $t_stream = microtime(true);

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
        self::log('PROFILE: stream', [
            'elapsed_ms' => self::ms_since($t_stream),
            'tsv_bytes'  => (is_string($tsv) && file_exists($tsv)) ? (int) filesize($tsv) : null,
        ]);

        self::debug_tsv_sample($tsv);

        // --- Apply timing ---
        $t_apply = microtime(true);
        $apply = self::apply_load_data($tsv, $live_table);
        self::log('PROFILE: db_apply', [
            'elapsed_ms' => self::ms_since($t_apply),
        ]);

        self::log('DB APPLY COMPLETE', $apply);

        self::log('HEAVY DONE', [
            'elapsed_ms' => self::ms_since($t_heavy),
        ]);

        // merge apply stats into stream stats (handy for one-line greps)
        if (is_array($stats)) {
            $stats['_apply'] = $apply;
            $stats['_timing_ms'] = [
                'stream' => self::ms_since($t_stream),
                'apply'  => self::ms_since($t_apply),
                'heavy'  => self::ms_since($t_heavy),
            ];
        }

        return is_array($stats) ? $stats : ['success' => false, '_apply' => $apply];
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

        // Optional: observe before/after pending counts (super useful for “why is it queued?”)
        $before = self::as_count_pending(self::HOOK, self::WORKER_ARGS, self::WORKER_GROUP);

        as_unschedule_all_actions(self::HOOK, self::WORKER_ARGS, self::WORKER_GROUP);
        as_schedule_single_action($target, self::HOOK, self::WORKER_ARGS, self::WORKER_GROUP);

        $after = self::as_count_pending(self::HOOK, self::WORKER_ARGS, self::WORKER_GROUP);

        self::log('Next worker scheduled (self-chain)', [
            'target_unix' => $target,
            'next_unix'   => $next_unix > 0 ? $next_unix : null,
            'next_raw'    => $next_raw !== '' ? $next_raw : null,
            'skew_sec'    => self::WORKER_SKEW_SEC,
            'args'        => self::WORKER_ARGS,
            'group'       => self::WORKER_GROUP,
            'pending_before' => $before,
            'pending_after'  => $after,
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

        $t_total = microtime(true);

        $pid = function_exists('getmypid') ? (int) getmypid() : (int) wp_rand(1000, 9999);
        $stage = $wpdb->prefix . 'fflhub_lipseys_pq_stage_' . $pid;

        // Basic env (fast)
        self::db_debug_env();

        // LIVE table rowcount (sanity; optional but very helpful)
        $t_live_count = microtime(true);
        $live_count = (int) ($wpdb->get_var("SELECT COUNT(*) FROM {$live}") ?? 0);
        self::log('PROFILE: live_count', [
            'live_count'  => $live_count,
            'elapsed_ms'  => self::ms_since($t_live_count),
        ]);

        // -----------------------
        // Create stage
        // -----------------------
        $t_create = microtime(true);

        $wpdb->query("DROP TABLE IF EXISTS {$stage}");

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

        self::log('PROFILE: stage_create', [
            'stage_table' => $stage,
            'elapsed_ms'  => self::ms_since($t_create),
        ]);

        // -----------------------
        // LOAD DATA
        // -----------------------
        $t_load = microtime(true);

        $path = str_replace('\\', '\\\\', $tsv);
        $path = str_replace("'", "\\'", $path);

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

        // CRLF hygiene
        $wpdb->query("UPDATE {$stage} SET lipseys_item_number = TRIM(TRAILING '\r' FROM lipseys_item_number)");
        $wpdb->query("UPDATE {$stage} SET inventory_quantity  = TRIM(TRAILING '\r' FROM inventory_quantity)");
        $wpdb->query("UPDATE {$stage} SET allocation_status   = TRIM(TRAILING '\r' FROM allocation_status)");
        $wpdb->query("UPDATE {$stage} SET distributor_price   = TRIM(TRAILING '\r' FROM distributor_price)");
        $wpdb->query("UPDATE {$stage} SET retail_map          = TRIM(TRAILING '\r' FROM retail_map)");

        $stage_count = (int) ($wpdb->get_var("SELECT COUNT(*) FROM {$stage}") ?? 0);

        self::log('PROFILE: load', [
            // $loaded is often 0 in some MySQL configs; stage_count is truth.
            'rows_loaded_affected' => (int) $loaded,
            'stage_count'          => $stage_count,
            'elapsed_ms'           => self::ms_since($t_load),
            'last_error'           => (string) $wpdb->last_error,
        ]);

        // -----------------------
        // Pre-join stats
        // -----------------------
        $t_stats = microtime(true);

        $join_matched = (int) ($wpdb->get_var("
            SELECT COUNT(*)
            FROM {$stage} S
            INNER JOIN {$live} L
                ON L.lipseys_item_number = S.lipseys_item_number
        ") ?? 0);

        $would_change = (int) ($wpdb->get_var("
            SELECT COUNT(*)
            FROM {$stage} S
            INNER JOIN {$live} L
                ON L.lipseys_item_number = S.lipseys_item_number
            WHERE
                COALESCE(L.inventory_quantity,'') <> COALESCE(S.inventory_quantity,'')
                OR COALESCE(L.allocation_status,'') <> COALESCE(S.allocation_status,'')
                OR COALESCE(L.distributor_price,'') <> COALESCE(S.distributor_price,'')
                OR COALESCE(L.retail_map,'') <> COALESCE(S.retail_map,'')
        ") ?? 0);

        self::log('PROFILE: prejoin_stats', [
            'join_matched' => $join_matched,
            'would_change' => $would_change,
            'elapsed_ms'   => self::ms_since($t_stats),
        ]);

        // -----------------------
        // JOIN update
        // -----------------------
        $t_join = microtime(true);

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

        self::log('PROFILE: join_update', [
            'rows_updated' => (int) $updated,
            'elapsed_ms'   => self::ms_since($t_join),
            'last_error'   => (string) $wpdb->last_error,
        ]);

        // -----------------------
        // Drop stage
        // -----------------------
        $t_drop = microtime(true);
        $wpdb->query("DROP TABLE IF EXISTS {$stage}");

        self::log('PROFILE: drop_stage', [
            'elapsed_ms' => self::ms_since($t_drop),
        ]);

        self::log('PROFILE: apply_total', [
            'elapsed_ms' => self::ms_since($t_total),
        ]);

        return [
            'rows_loaded'   => $stage_count,
            'rows_updated'  => (int) $updated,
            'stage_table'   => (string) $stage,
            'stage_count'   => $stage_count,
            'live_count'    => $live_count,
            'join_matched'  => $join_matched,
            'would_change'  => $would_change,
        ];
    }

    private static function as_count_pending(string $hook, array $args, string $group): ?int
    {
        try {
            if (!function_exists('as_get_scheduled_actions')) {
                return null;
            }

            $ids = as_get_scheduled_actions([
                'hook'     => $hook,
                'args'     => $args,
                'group'    => $group,
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 1000,
            ]);

            return is_array($ids) ? count($ids) : null;
        } catch (\Throwable $e) {
            return null;
        }
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

    private static function ms_since(float $t0): int
    {
        return (int) round((microtime(true) - $t0) * 1000);
    }

    private static function log(string $msg, array $ctx = []): void
    {
        return; //off
        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $msg, $ctx);
    }

    
}
