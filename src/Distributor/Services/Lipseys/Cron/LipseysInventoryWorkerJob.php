<?php

namespace FFLHub\Distributor\Services\Lipseys\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\CronRunLogger;
use FFLHub\Distributor\Services\Lipseys\LipseysRawAPI\LipseysClient;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;

final class LipseysInventoryWorkerJob
{
    public const HOOK = 'fflhub_lipseys_pricing_quantity_worker';

    private static ?CronRunLogger $logger = null;

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHUB][LipseysInventoryWorker]';

    private const OPT_LAST_SEEN_VERSION = 'fflhub_lipseys_last_seen_version';
    private const OPT_NEXT_UPDATE_UNIX = 'fflhub_lipseys_next_update_unix';
    private const OPT_LAST_APPLIED_VERSION = 'fflhub_lipseys_last_applied_version';

    private const STAGE_TABLE_SUFFIX = 'fflhub_lipseys_pq_stage';
    private const WORKER_ARGS = ['singleton' => 1];
    private const WORKER_GROUP = 'fflhub_catalog';
    private const WORKER_SKEW_SEC = 60;
    private const FALLBACK_RETRY_SEC = 600;

    public static function run(DoubleBufferedProductTable $table): void
    {
        $started = microtime(true);
        $memory_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        $version = (string) get_option(self::OPT_LAST_SEEN_VERSION, '');
        $next_unix = (int) get_option(self::OPT_NEXT_UPDATE_UNIX, 0);

        self::logger()->runStart([
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'version' => $version,
            'next_unix' => $next_unix,
            'memory_kb' => self::logger()->memoryKb(),
        ]);

        // ------------------------------------------------------------------
        // Stage 1: Credentials and API client.
        // ------------------------------------------------------------------
        $credentials = self::load_credentials();
        if ($credentials === null) {
            self::log('Missing credentials');
            self::finish($started, $memory_start, 'ERROR (missing credentials)');
            return;
        }

        $client = self::create_client($credentials['email'], $credentials['password']);
        if (!$client instanceof LipseysClient) {
            self::finish($started, $memory_start, 'ERROR (client failed)');
            return;
        }

        // ------------------------------------------------------------------
        // Stage 2: Stream pricing/quantity and apply it to the live table.
        // ------------------------------------------------------------------
        try {
            $stats = self::run_heavy_inventory_update($table, $client);
        } catch (\Throwable $e) {
            self::log('HEAVY UPDATE FAILED', ['error' => $e->getMessage()]);
            self::schedule_next_worker_fallback('heavy_failed');
            self::finish($started, $memory_start, 'ERROR (heavy failed)');
            return;
        }

        // ------------------------------------------------------------------
        // Stage 3: Persist version markers and schedule the next worker.
        // ------------------------------------------------------------------
        if ($version !== '') {
            update_option(self::OPT_LAST_APPLIED_VERSION, $version);
        }

        $next_raw = (string) ($stats['next_update_raw'] ?? '');
        $next_unix = (int) ($stats['next_update_unix'] ?? 0);

        if ($next_raw !== '') {
            update_option(self::OPT_LAST_SEEN_VERSION, $next_raw);
        }

        if ($next_unix > 0) {
            update_option(self::OPT_NEXT_UPDATE_UNIX, $next_unix);
        }

        self::log('NEXT UPDATE (from TSV stream)', [
            'next_raw' => $next_raw !== '' ? $next_raw : null,
            'next_unix' => $next_unix > 0 ? $next_unix : null,
        ]);

        self::schedule_next_worker($next_unix, $next_raw);

        self::finish($started, $memory_start, 'SUCCESS', [
            'version_applied' => $version,
            'stream_rows_written' => (int) ($stats['rows_written'] ?? 0),
            'stream_bytes_received' => (int) ($stats['bytes_received'] ?? 0),
            'rows_loaded' => (int) ($stats['_apply']['rows_loaded'] ?? 0),
            'rows_updated' => (int) ($stats['_apply']['rows_updated'] ?? 0),
            'stage_table' => (string) ($stats['_apply']['stage_table'] ?? ''),
        ]);
    }

    /**
     * @return array{email:string,password:string}|null
     */
    private static function load_credentials(): ?array
    {
        $started = microtime(true);

        $email = (string) Options::get_distributor_option('lipseys', 'dealer_email', '');
        $password = (string) Options::get_distributor_option('lipseys', 'dealer_password', '');

        self::profile('Credentials retrieval', $started, [
            'has_email' => $email !== '' ? 1 : 0,
            'has_password' => $password !== '' ? 1 : 0,
        ]);

        if ($email === '' || $password === '') {
            return null;
        }

        return [
            'email' => $email,
            'password' => $password,
        ];
    }

    private static function create_client(string $email, string $password): ?LipseysClient
    {
        $started = microtime(true);

        try {
            $client = new LipseysClient($email, $password);
        } catch (\Throwable $e) {
            self::log('Client creation failed', ['error' => $e->getMessage()]);
            self::profile('Client creation', $started, ['ok' => 0]);
            return null;
        }

        self::profile('Client creation', $started, ['ok' => 1]);

        return $client;
    }

    /**
     * @return array<string,mixed>
     */
    private static function run_heavy_inventory_update(DoubleBufferedProductTable $table, LipseysClient $client): array
    {
        $started = microtime(true);

        [$live_table, $tsv] = self::prepare_live_table_and_tsv_path($table);

        $stats = self::stream_pricing_quantity_to_tsv($client, $tsv);

        $apply = self::apply_tsv_to_live_table($tsv, $live_table);
        self::log('DB APPLY COMPLETE', $apply);

        $stats['_apply'] = $apply;
        self::profile('Heavy inventory update', $started, [
            'live_table' => $live_table,
            'tsv_bytes' => file_exists($tsv) ? (int) filesize($tsv) : null,
            'rows_loaded' => (int) ($apply['rows_loaded'] ?? 0),
            'rows_updated' => (int) ($apply['rows_updated'] ?? 0),
        ]);

        return $stats;
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function prepare_live_table_and_tsv_path(DoubleBufferedProductTable $table): array
    {
        $started = microtime(true);

        $live_table = (string) $table->get_live_table_name();
        if ($live_table === '') {
            throw new \RuntimeException('Could not resolve live table name.');
        }

        $uploads = wp_upload_dir();
        $base_dir = trailingslashit((string) ($uploads['basedir'] ?? '')) . 'fflhub/lipseys';
        if (!wp_mkdir_p($base_dir)) {
            throw new \RuntimeException('Failed to create base directory: ' . $base_dir);
        }

        $tsv = trailingslashit($base_dir) . 'pq_' . gmdate('Ymd_His') . '.tsv';

        self::profile('Prepare live table and TSV path', $started, [
            'live_table' => $live_table,
            'tsv_path' => $tsv,
        ]);

        return [$live_table, $tsv];
    }

    /**
     * @return array<string,mixed>
     */
    private static function stream_pricing_quantity_to_tsv(LipseysClient $client, string $tsv): array
    {
        $started = microtime(true);

        self::log('Starting TSV stream', ['path' => $tsv]);

        $stats = $client->PricingAndQuantityToTsv(
            $tsv,
            [
                'lipseys_item_number',
                'inventory_quantity',
                'allocation_status',
                'distributor_price',
                'retail_map',
                'can_dropship',
            ],
            static function (array $item) {
                if (empty($item['itemNumber'])) {
                    return null;
                }

                return [
                    'lipseys_item_number' => (string) $item['itemNumber'],
                    'inventory_quantity' => (string) ((int) ($item['quantity'] ?? 0)),
                    'allocation_status' => !empty($item['allocated']) ? 'Y' : '',
                    'distributor_price' => (string) ($item['currentPrice'] ?? ''),
                    'retail_map' => (string) ($item['retailMap'] ?? ''),
                    'can_dropship' => !empty($item['canDropship']) ? '1' : '0',
                ];
            }
        );

        $stats = is_array($stats) ? $stats : ['stats' => $stats];
        self::log('STREAM COMPLETE', $stats);
        self::profile('Stream pricing/quantity TSV', $started, [
            'tsv_bytes' => file_exists($tsv) ? (int) filesize($tsv) : null,
            'items_seen' => (int) ($stats['items_seen'] ?? ($stats['rows_seen'] ?? 0)),
            'rows_written' => (int) ($stats['rows_written'] ?? 0),
            'bytes_received' => (int) ($stats['bytes_received'] ?? 0),
            'success' => !empty($stats['success']) ? 1 : 0,
            'authorized' => array_key_exists('authorized', $stats) ? (!empty($stats['authorized']) ? 1 : 0) : null,
        ]);

        if (self::debug_enabled()) {
            self::debug_tsv_sample($tsv);
        }

        return $stats;
    }

    /**
     * @return array<string,mixed>
     */
    private static function apply_tsv_to_live_table(string $tsv, string $live_table): array
    {
        global $wpdb;

        $started = microtime(true);
        $stage_table = $wpdb->prefix . self::STAGE_TABLE_SUFFIX;

        // ------------------------------------------------------------------
        // Stage A: optional DB environment/debug checks.
        // ------------------------------------------------------------------
        if (self::debug_enabled()) {
            self::db_debug_env();
        }

        // ------------------------------------------------------------------
        // Stage B: create/truncate persistent stage table.
        // ------------------------------------------------------------------
        self::ensure_stage_table($stage_table);

        // ------------------------------------------------------------------
        // Stage C: load the streamed TSV into stage with LOAD DATA.
        // ------------------------------------------------------------------
        $load_stats = self::load_stage_from_tsv($stage_table, $tsv);

        // ------------------------------------------------------------------
        // Stage D: update changed live rows from stage.
        // ------------------------------------------------------------------
        $updated = self::update_live_from_stage($live_table, $stage_table, self::changed_where_sql());

        self::profile('Apply TSV to live table', $started, [
            'stage_table' => $stage_table,
            'live_table' => $live_table,
            'rows_loaded' => (int) ($load_stats['stage_count'] ?? 0),
            'rows_updated' => (int) $updated,
        ]);

        return [
            'rows_loaded' => (int) ($load_stats['stage_count'] ?? 0),
            'rows_updated' => (int) $updated,
            'stage_table' => (string) $stage_table,
            'stage_count' => (int) ($load_stats['stage_count'] ?? 0),
        ];
    }

    private static function ensure_stage_table(string $stage_table): void
    {
        global $wpdb;

        $started = microtime(true);
        $charset = $wpdb->get_charset_collate();
        $create_sql = "
            CREATE TABLE IF NOT EXISTS {$stage_table} (
                lipseys_item_number VARCHAR(64) NOT NULL,
                inventory_quantity  VARCHAR(32) NULL,
                allocation_status   VARCHAR(64) NULL,
                distributor_price   VARCHAR(32) NULL,
                retail_map          VARCHAR(32) NULL,
                can_dropship        TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (lipseys_item_number)
            ) {$charset};
        ";

        $created = $wpdb->query($create_sql);
        if ($created === false) {
            throw new \RuntimeException('Stage create failed: ' . (string) $wpdb->last_error);
        }

        self::ensure_stage_can_dropship_column($stage_table);

        $truncated = $wpdb->query("TRUNCATE TABLE {$stage_table}");
        if ($truncated === false) {
            throw new \RuntimeException('Stage truncate failed: ' . (string) $wpdb->last_error);
        }

        self::profile('Ensure/truncate stage table', $started, [
            'stage_table' => $stage_table,
        ]);
    }

    /**
     * @return array{rows_loaded_affected:int,stage_count:int}
     */
    private static function load_stage_from_tsv(string $stage_table, string $tsv): array
    {
        global $wpdb;

        $started = microtime(true);
        $path = str_replace('\\', '\\\\', $tsv);
        $path = str_replace("'", "\\'", $path);

        $load_sql = "
            LOAD DATA LOCAL INFILE '{$path}'
            INTO TABLE {$stage_table}
            FIELDS TERMINATED BY '\t'
            LINES TERMINATED BY '\n'
            (
                @lipseys_item_number,
                @inventory_quantity,
                @allocation_status,
                @distributor_price,
                @retail_map,
                @can_dropship
            )
            SET
                lipseys_item_number = TRIM(TRAILING '\r' FROM TRIM(@lipseys_item_number)),
                inventory_quantity  = TRIM(TRAILING '\r' FROM TRIM(@inventory_quantity)),
                allocation_status   = TRIM(TRAILING '\r' FROM TRIM(@allocation_status)),
                distributor_price   = TRIM(TRAILING '\r' FROM TRIM(@distributor_price)),
                retail_map          = TRIM(TRAILING '\r' FROM TRIM(@retail_map)),
                can_dropship        = CASE
                    WHEN LOWER(TRIM(TRAILING '\r' FROM TRIM(@can_dropship))) IN ('1', 'y', 'yes', 'true')
                    THEN 1
                    ELSE 0
                END
        ";

        $loaded = $wpdb->query($load_sql);
        if ($loaded === false) {
            throw new \RuntimeException('LOAD DATA failed: ' . (string) $wpdb->last_error);
        }

        $stage_count = (int) ($wpdb->get_var("SELECT COUNT(*) FROM {$stage_table}") ?? 0);

        self::profile('LOAD DATA into stage', $started, [
            'rows_loaded_affected' => (int) $loaded,
            'stage_count' => $stage_count,
            'last_error' => (string) $wpdb->last_error,
        ]);

        return [
            'rows_loaded_affected' => (int) $loaded,
            'stage_count' => $stage_count,
        ];
    }

    private static function update_live_from_stage(string $live_table, string $stage_table, string $changed_where_sql): int
    {
        global $wpdb;

        $started = microtime(true);
        $update_sql = "
            UPDATE {$live_table} L
            INNER JOIN {$stage_table} S
                ON S.lipseys_item_number = L.lipseys_item_number
            SET
                L.inventory_quantity = S.inventory_quantity,
                L.allocation_status  = S.allocation_status,
                L.distributor_price  = S.distributor_price,
                L.retail_map         = S.retail_map,
                L.dropship_enabled   = CASE
                    WHEN S.can_dropship = 0 THEN 0
                    WHEN COALESCE(L.sot_required, 0) = 1 THEN L.dropship_enabled
                    ELSE 1
                END,
                L.dropship_block_reason = CASE
                    WHEN S.can_dropship = 0 THEN 'lipseys_inventory_canDropship_false'
                    WHEN COALESCE(L.sot_required, 0) = 1 THEN L.dropship_block_reason
                    ELSE ''
                END
            WHERE {$changed_where_sql}
        ";

        $updated = $wpdb->query($update_sql);
        if ($updated === false) {
            throw new \RuntimeException('JOIN update failed: ' . (string) $wpdb->last_error);
        }

        self::profile('Join update live table', $started, [
            'rows_updated' => (int) $updated,
            'last_error' => (string) $wpdb->last_error,
        ]);

        return (int) $updated;
    }

    private static function schedule_next_worker(int $next_unix, string $next_raw): void
    {
        if (!function_exists('as_schedule_single_action') || !function_exists('as_unschedule_all_actions')) {
            self::log('Action Scheduler unavailable - cannot chain schedule');
            return;
        }

        $target = $next_unix > 0
            ? (int) max(time() + 10, $next_unix + self::WORKER_SKEW_SEC)
            : (int) (time() + self::FALLBACK_RETRY_SEC);

        $before = self::as_count_pending(self::HOOK, self::WORKER_ARGS, self::WORKER_GROUP);

        as_unschedule_all_actions(self::HOOK, self::WORKER_ARGS, self::WORKER_GROUP);
        as_schedule_single_action($target, self::HOOK, self::WORKER_ARGS, self::WORKER_GROUP);

        self::log('Next worker scheduled (self-chain)', [
            'target_unix' => $target,
            'next_unix' => $next_unix > 0 ? $next_unix : null,
            'next_raw' => $next_raw !== '' ? $next_raw : null,
            'skew_sec' => self::WORKER_SKEW_SEC,
            'args' => self::WORKER_ARGS,
            'group' => self::WORKER_GROUP,
            'pending_before' => $before,
            'pending_after' => self::as_count_pending(self::HOOK, self::WORKER_ARGS, self::WORKER_GROUP),
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
            'reason' => $reason,
            'target_unix' => $target,
            'retry_sec' => self::FALLBACK_RETRY_SEC,
        ]);
    }

    private static function ensure_stage_can_dropship_column(string $stage): void
    {
        global $wpdb;

        $column = $wpdb->get_var("SHOW COLUMNS FROM {$stage} LIKE 'can_dropship'");
        if ($column === 'can_dropship') {
            return;
        }

        $added = $wpdb->query("ALTER TABLE {$stage} ADD COLUMN can_dropship TINYINT(1) NOT NULL DEFAULT 0 AFTER retail_map");
        if ($added === false) {
            throw new \RuntimeException('Failed to add stage can_dropship column: ' . (string) $wpdb->last_error);
        }
    }

    private static function changed_where_sql(): string
    {
        return "
            NOT (
                    NULLIF(L.inventory_quantity, '') <=> NULLIF(S.inventory_quantity, '')
                AND NULLIF(L.allocation_status, '')  <=> NULLIF(S.allocation_status, '')
                AND NULLIF(L.distributor_price, '')  <=> NULLIF(S.distributor_price, '')
                AND NULLIF(L.retail_map, '')         <=> NULLIF(S.retail_map, '')
                AND L.dropship_enabled <=> CASE
                    WHEN S.can_dropship = 0 THEN 0
                    WHEN COALESCE(L.sot_required, 0) = 1 THEN L.dropship_enabled
                    ELSE 1
                END
                AND NULLIF(L.dropship_block_reason, '') <=> NULLIF(
                    CASE
                        WHEN S.can_dropship = 0 THEN 'lipseys_inventory_canDropship_false'
                        WHEN COALESCE(L.sot_required, 0) = 1 THEN L.dropship_block_reason
                        ELSE ''
                    END,
                    ''
                )
            )
        ";
    }

    private static function as_count_pending(string $hook, array $args, string $group): ?int
    {
        try {
            if (!function_exists('as_get_scheduled_actions')) {
                return null;
            }

            $ids = as_get_scheduled_actions([
                'hook' => $hook,
                'args' => $args,
                'group' => $group,
                'status' => \ActionScheduler_Store::STATUS_PENDING,
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
            $sql_mode = $wpdb->get_var('SELECT @@SESSION.sql_mode');
        } catch (\Throwable $e) {
        }

        try {
            $local_infile = $wpdb->get_var('SELECT @@GLOBAL.local_infile');
        } catch (\Throwable $e) {
        }

        self::log('DB DEBUG: ENV', [
            'sql_mode' => is_string($sql_mode) ? $sql_mode : null,
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
                'path' => $tsv,
                'first_line' => self::truncate_str($line_trim, 500),
                'field_count' => count($fields),
            ]);
        } catch (\Throwable $e) {
            self::log('TSV DEBUG: exception', ['error' => $e->getMessage()]);
        }
    }

    private static function truncate_str(string $value, int $max): string
    {
        if ($max <= 0) {
            return '';
        }

        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max) . '...';
    }

    private static function debug_enabled(): bool
    {
        return defined(self::DEBUG_FLAG) && (bool) constant(self::DEBUG_FLAG);
    }

    private static function logger(): CronRunLogger
    {
        if (!self::$logger instanceof CronRunLogger) {
            self::$logger = CronRunLogger::create(self::DEBUG_FLAG, self::LOG_PREFIX);
        }

        return self::$logger;
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private static function log(string $message, array $ctx = []): void
    {
        self::logger()->log($message, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private static function profile(string $label, float $started, array $ctx = []): void
    {
        self::logger()->profile($label, $started, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private static function finish(float $started, int $memory_start, string $status, array $ctx = []): void
    {
        self::logger()->finishWithTotalProfile($started, $memory_start, $status, $ctx);
    }
}
