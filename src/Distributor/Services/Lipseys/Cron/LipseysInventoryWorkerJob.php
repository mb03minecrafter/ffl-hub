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

    /**
     * Worker behavior:
     * - Reads last_seen_version/next_update_unix from options for context.
     * - ALWAYS runs the heavy PricingAndQuantityToTsv + DB apply (that call is the source of truth).
     * - Marks last_applied_version = current last_seen_version (if present).
     * - Extracts nextUpdate from the TSV streaming stats (no NextUpdateFast call).
     * - Persists nextUpdate into options and schedules the NEXT singleton worker at nextUpdate + skew.
     */
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
            // Even if heavy fails, we still attempt to keep the chain alive.
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

        // Extract nextUpdate from TSV stats (source-of-truth: the same payload we just streamed)
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

        // Chain schedule next worker
        self::schedule_next_worker($nextUnix, $nextRaw);

        self::log('RUN END', [
            'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);
    }

    /**
     * Runs the heavy stream->TSV + LOAD DATA flow.
     * Returns an array including next_update_raw/next_update_unix (added via LipseysClient changes).
     */
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

        // Enforce singleton: wipe any pending actions for this signature, then schedule exactly one.
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
            'reason'     => $reason,
            'target_unix'=> $target,
            'retry_sec'  => self::FALLBACK_RETRY_SEC,
        ]);
    }

    private static function apply_load_data(string $tsv, string $live): array
    {
        global $wpdb;

        $pid = function_exists('getmypid') ? (int) getmypid() : (int) wp_rand(1000, 9999);
        $stage = $wpdb->prefix . 'fflhub_lipseys_stage_' . $pid;

        $wpdb->query("DROP TABLE IF EXISTS {$stage}");

        $created = $wpdb->query("CREATE TABLE {$stage} LIKE {$live}");
        if ($created === false) {
            throw new \RuntimeException('Stage create failed: ' . (string) $wpdb->last_error);
        }

        $path = str_replace('\\', '\\\\', $tsv);
        $path = str_replace("'", "\\'", $path);

        $loaded = $wpdb->query("
            LOAD DATA LOCAL INFILE '{$path}'
            INTO TABLE {$stage}
            FIELDS TERMINATED BY '\t'
            LINES TERMINATED BY '\n'
        ");
        if ($loaded === false) {
            $wpdb->query("DROP TABLE IF EXISTS {$stage}");
            throw new \RuntimeException('LOAD DATA failed: ' . (string) $wpdb->last_error);
        }

        $updated = $wpdb->query("
            UPDATE {$live} L
            JOIN {$stage} S
              ON S.lipseys_item_number = L.lipseys_item_number
            SET
              L.inventory_quantity = S.inventory_quantity,
              L.allocation_status  = S.allocation_status,
              L.distributor_price  = S.distributor_price,
              L.retail_map         = S.retail_map
        ");
        if ($updated === false) {
            $wpdb->query("DROP TABLE IF EXISTS {$stage}");
            throw new \RuntimeException('JOIN update failed: ' . (string) $wpdb->last_error);
        }

        $wpdb->query("DROP TABLE IF EXISTS {$stage}");

        return [
            'rows_updated' => (int) $updated,
            'rows_loaded'  => (int) $loaded,
            'stage_table'  => (string) $stage,
        ];
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
