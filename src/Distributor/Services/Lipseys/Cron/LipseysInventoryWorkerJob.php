<?php

namespace FFLHub\Distributor\Services\Lipseys\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Lipseys\LipseysRawAPI\LipseysClient;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

final class LipseysInventoryWorkerJob
{
    public const HOOK = 'fflhub_lipseys_pricing_quantity_worker';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHUB][LipseysInventoryWorker]';

    // Retry controls
    private const MAX_ATTEMPTS = 12;          // bounded
    private const BACKOFF_CAP  = 15 * 60;     // 15 minutes max

    // Persist attempt count per expected epoch
    private const OPT_ATTEMPT_PREFIX = 'fflhub_lipseys_pq_worker_attempt_';

    public static function run(DoubleBufferedFulfillmentTable $table, array $args): void
    {
        $t_start   = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        self::log('---- RUN START ----', [
            'pid'       => function_exists('getmypid') ? (int) getmypid() : 0,
            'memory_kb' => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
            'hook'      => self::HOOK,
            'args'      => $args,
        ]);

        $expected_unix = isset($args['expected_next_update_unix']) ? (int) $args['expected_next_update_unix'] : 0;
        $expected_raw  = isset($args['expected_next_update_raw']) ? (string) $args['expected_next_update_raw'] : '';

        $job_key = self::job_key($expected_unix, $expected_raw);
        $attempt_opt = self::OPT_ATTEMPT_PREFIX . $job_key;
        $attempt = (int) get_option($attempt_opt, 0);

        // 0) Credentials
        $dealer_email    = trim((string) Options::get_distributor_option('lipseys', 'dealer_email', ''));
        $dealer_password = trim((string) Options::get_distributor_option('lipseys', 'dealer_password', ''));

        if ($dealer_email === '' || $dealer_password === '') {
            self::finalize($t_start, $mem_start, 'ERROR (missing credentials)');
            return;
        }

        // 1) Client + re-check nextUpdate (fast)
        try {
            $client = new LipseysClient($dealer_email, $dealer_password);
        } catch (\Throwable $e) {
            self::log('ERROR: exception creating LipseysClient', ['error' => $e->getMessage()]);
            self::finalize($t_start, $mem_start, 'ERROR (client creation)');
            return;
        }

        $r = [];
        try {
            $r = $client->PricingAndQuantityNextUpdateFast();
        } catch (\Throwable $e) {
            self::log('ERROR: PricingAndQuantityNextUpdateFast exception', ['error' => $e->getMessage()]);
            self::finalize($t_start, $mem_start, 'ERROR (nextUpdate fast exception)');
            return;
        }

        if (empty($r['success'])) {
            self::log('ERROR: nextUpdate fast failed', $r);
            self::finalize($t_start, $mem_start, 'ERROR (nextUpdate fast failed)');
            return;
        }

        $current_unix = !empty($r['next_update_unix']) ? (int) $r['next_update_unix'] : 0;
        $current_raw  = is_string($r['next_update_raw'] ?? null) ? (string) $r['next_update_raw'] : '';

        self::log('nextUpdate check', [
            'expected_unix' => (int) $expected_unix,
            'expected_raw'  => $expected_raw !== '' ? $expected_raw : null,
            'current_unix'  => (int) $current_unix,
            'current_raw'   => $current_raw !== '' ? $current_raw : null,
            'attempt'       => (int) $attempt,
        ]);

        // 2) If current epoch matches expected epoch, we ran too early: reschedule with backoff.
        if (self::epochs_equal($expected_unix, $expected_raw, $current_unix, $current_raw)) {
            $attempt++;

            update_option($attempt_opt, $attempt);

            if ($attempt > self::MAX_ATTEMPTS) {
                self::log('GIVING UP: max attempts reached (still same epoch)', [
                    'attempt' => (int) $attempt,
                    'job_key' => (string) $job_key,
                ]);
                delete_option($attempt_opt);
                self::finalize($t_start, $mem_start, 'NO APPLY (too early, max attempts)');
                return;
            }

            $delay = self::backoff_seconds($attempt);
            $run_at = time() + $delay;

            self::schedule_self($run_at, [
                'expected_next_update_unix' => $expected_unix,
                'expected_next_update_raw'  => $expected_raw,
            ]);

            self::finalize($t_start, $mem_start, 'RESCHEDULE (too early)', [
                'attempt'     => (int) $attempt,
                'delay_sec'   => (int) $delay,
                'run_at_unix' => (int) $run_at,
            ]);
            return;
        }

        // We have a new epoch now: clear attempt tracking.
        delete_option($attempt_opt);

        // 3) Run heavy update (your existing pipeline moved here)
        try {
            self::run_heavy_inventory_update($table, $client);
        } catch (\Throwable $e) {
            self::log('ERROR: heavy update threw', ['error' => $e->getMessage()]);
            self::finalize($t_start, $mem_start, 'ERROR (heavy update exception)');
            return;
        }

        // (Optional) persist the new epoch so gatekeeper knows what's next
        if ($current_unix > 0) {
            update_option('fflhub_lipseys_pq_next_update_unix', $current_unix);
            update_option('fflhub_lipseys_pq_next_update_raw',  $current_raw);
        }

        self::finalize($t_start, $mem_start, 'SUCCESS');
    }

    // -------------------------
    // Heavy update (moved code)
    // -------------------------

    private static function run_heavy_inventory_update(DoubleBufferedFulfillmentTable $table, LipseysClient $client): void
    {
        $t_start = microtime(true);

        // Resolve live table
        $live_table = (string) $table->get_live_table_name();
        if ($live_table === '') {
            throw new \RuntimeException('Could not resolve live table name.');
        }

        // Build TSV path
        $uploads  = wp_upload_dir();
        $base_dir = trailingslashit($uploads['basedir']) . 'fflhub/lipseys';

        if (!wp_mkdir_p($base_dir)) {
            throw new \RuntimeException('Failed to create base directory: ' . (string) $base_dir);
        }

        $tsv_path = trailingslashit($base_dir) . 'pricing_qty_' . gmdate('Ymd_His') . '.tsv';

        // TSV columns
        $columns = [
            'lipseys_item_number',
            'inventory_quantity',
            'allocation_status',
            'distributor_price',
            'retail_map',
        ];

        $item_to_row = static function (array $item): ?array {
            $item_number = isset($item['itemNumber']) ? trim((string) $item['itemNumber']) : '';
            if ($item_number === '') {
                return null;
            }

            $qty = isset($item['quantity']) && is_numeric($item['quantity']) ? (int) $item['quantity'] : 0;

            $allocated = false;
            if (isset($item['allocated'])) {
                $v = $item['allocated'];
                if (is_bool($v)) {
                    $allocated = $v;
                } elseif (is_numeric($v)) {
                    $allocated = ((int) $v) !== 0;
                } else {
                    $allocated = in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'y'], true);
                }
            }

            $current_price = (isset($item['currentPrice']) && is_numeric($item['currentPrice']))
                ? (string) $item['currentPrice']
                : '';

            $retail_map = (isset($item['retailMap']) && is_numeric($item['retailMap']))
                ? (string) $item['retailMap']
                : '';

            return [
                'lipseys_item_number' => $item_number,
                'inventory_quantity'  => (string) $qty,
                'allocation_status'   => $allocated ? 'Y' : '',
                'distributor_price'   => $current_price,
                'retail_map'          => $retail_map,
            ];
        };

        $stream_stats = $client->PricingAndQuantityToTsv($tsv_path, $columns, $item_to_row);

        if (!is_array($stream_stats) || empty($stream_stats['success'])) {
            $authorized = is_array($stream_stats) && isset($stream_stats['authorized']) ? (bool) $stream_stats['authorized'] : null;
            $errors     = is_array($stream_stats) && isset($stream_stats['errors']) ? $stream_stats['errors'] : null;
            throw new \RuntimeException('PricingAndQuantityToTsv failed. authorized=' . var_export($authorized, true) . ' errors=' . json_encode($errors));
        }

        $rows_written = isset($stream_stats['rows_written']) ? (int) $stream_stats['rows_written'] : 0;
        if ($rows_written <= 0) {
            self::log('WARNING: streaming produced 0 rows (not applying)', [
                'items_seen'    => (int) ($stream_stats['items_seen'] ?? 0),
                'items_skipped' => (int) ($stream_stats['items_skipped'] ?? 0),
            ]);
            return;
        }

        $apply_stats = self::apply_pricing_quantity_updates_via_load_data_profiled($tsv_path, $live_table);

        update_option('fflhub_lipseys_pricing_quantity_last_sync', current_time('mysql'));
        update_option('fflhub_lipseys_pricing_quantity_last_sync_count', (int) ($apply_stats['join_updated'] ?? 0));
        update_option('fflhub_lipseys_pricing_quantity_last_sync_matched_count', (int) ($apply_stats['join_matched'] ?? 0));

        self::log('Heavy update complete', [
            'tsv_rows'     => (int) $rows_written,
            'rows_loaded'  => (int) ($apply_stats['rows_loaded'] ?? 0),
            'join_matched' => (int) ($apply_stats['join_matched'] ?? 0),
            'would_change' => (int) ($apply_stats['would_change'] ?? 0),
            'join_updated' => (int) ($apply_stats['join_updated'] ?? 0),
            'elapsed_ms'   => number_format((microtime(true) - $t_start) * 1000.0, 2, '.', ''),
        ]);
    }

    /**
     * NOTE: this is your existing apply method moved verbatim (just made static + class-local).
     *
     * @return array<string,mixed>
     */
    private static function apply_pricing_quantity_updates_via_load_data_profiled(string $tsv_path, string $live_table): array
    {
        global $wpdb;

        $t_start = microtime(true);

        if (!file_exists($tsv_path) || !is_readable($tsv_path)) {
            self::log('ERROR: TSV missing or unreadable', ['tsv_path' => (string) $tsv_path]);
            return [
                'rows_loaded'  => 0,
                'join_matched' => 0,
                'would_change' => 0,
                'join_updated' => 0,
                'create_ms'    => '0.00',
                'load_ms'      => '0.00',
                'stats_ms'     => '0.00',
                'join_ms'      => '0.00',
                'drop_ms'      => '0.00',
                'total_ms'     => '0.00',
            ];
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $ignore_lines = 0;

        $pid    = function_exists('getmypid') ? (int) getmypid() : 0;
        $suffix = $pid > 0 ? (string) $pid : (string) wp_rand(1000, 9999);

        $stage_table = $wpdb->prefix . 'fflhub_lipseys_pq_stage_' . $suffix;

        $infile_path_sql = str_replace('\\', '\\\\', $tsv_path);
        $infile_path_sql = str_replace("'", "\\'", $infile_path_sql);

        $t_check  = microtime(true);
        $mysql_ok = self::mysql_local_infile_enabled();
        $php_ok   = self::php_local_infile_enabled();

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, '[FFLHub][Lipseys Import][DEBUG]', 'LOAD DATA check', [
            'mysql_ok'   => $mysql_ok ? 'true' : 'false',
            'php_ok'     => $php_ok ? 'true' : 'false',
            'result'     => ($mysql_ok && $php_ok) ? 'true' : 'false',
            'elapsed_ms' => number_format((microtime(true) - $t_check) * 1000.0, 2, '.', ''),
        ]);

        $t_create = microtime(true);

        $wpdb->query("DROP TABLE IF EXISTS {$stage_table}");

        $charset = $wpdb->get_charset_collate();

        $create_sql = "
            CREATE TABLE {$stage_table} (
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
            throw new \RuntimeException('Failed to create staging table: ' . (string) $wpdb->last_error);
        }

        $create_ms = (microtime(true) - $t_create) * 1000.0;

        $t_load = microtime(true);

        $load_sql = "
            LOAD DATA LOCAL INFILE '{$infile_path_sql}'
            INTO TABLE {$stage_table}
            FIELDS TERMINATED BY '\t'
            LINES TERMINATED BY '\n'
            " . ($ignore_lines > 0 ? "IGNORE {$ignore_lines} LINES" : "") . "
            (lipseys_item_number, inventory_quantity, allocation_status, distributor_price, retail_map)
        ";

        $loaded = $wpdb->query($load_sql);
        if ($loaded === false) {
            $wpdb->query("DROP TABLE IF EXISTS {$stage_table}");
            throw new \RuntimeException('LOAD DATA LOCAL INFILE failed: ' . (string) $wpdb->last_error);
        }

        $wpdb->query("UPDATE {$stage_table} SET lipseys_item_number = TRIM(TRAILING '\r' FROM lipseys_item_number)");
        $wpdb->query("UPDATE {$stage_table} SET inventory_quantity = TRIM(TRAILING '\r' FROM inventory_quantity)");
        $wpdb->query("UPDATE {$stage_table} SET allocation_status  = TRIM(TRAILING '\r' FROM allocation_status)");
        $wpdb->query("UPDATE {$stage_table} SET distributor_price  = TRIM(TRAILING '\r' FROM distributor_price)");
        $wpdb->query("UPDATE {$stage_table} SET retail_map         = TRIM(TRAILING '\r' FROM retail_map)");

        $load_ms = (microtime(true) - $t_load) * 1000.0;

        $rows_loaded = 0;
        $count_row = $wpdb->get_row("SELECT COUNT(*) AS c FROM {$stage_table}", ARRAY_A);
        if (is_array($count_row) && isset($count_row['c'])) {
            $rows_loaded = (int) $count_row['c'];
        }

        $t_stats = microtime(true);

        $join_matched = (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$stage_table} S
            INNER JOIN {$live_table} L
                ON L.lipseys_item_number = S.lipseys_item_number
        ");

        $would_change = (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$stage_table} S
            INNER JOIN {$live_table} L
                ON L.lipseys_item_number = S.lipseys_item_number
            WHERE
                COALESCE(L.inventory_quantity,'') <> COALESCE(S.inventory_quantity,'')
                OR COALESCE(L.allocation_status,'') <> COALESCE(S.allocation_status,'')
                OR COALESCE(L.distributor_price,'') <> COALESCE(S.distributor_price,'')
                OR COALESCE(L.retail_map,'') <> COALESCE(S.retail_map,'')
        ");

        $stats_ms = (microtime(true) - $t_stats) * 1000.0;

        $t_join = microtime(true);

        $join_sql = "
            UPDATE {$live_table} L
            INNER JOIN {$stage_table} S
                ON S.lipseys_item_number = L.lipseys_item_number
            SET
                L.inventory_quantity = S.inventory_quantity,
                L.allocation_status  = S.allocation_status,
                L.distributor_price  = S.distributor_price,
                L.retail_map         = S.retail_map
        ";

        $join_updated = $wpdb->query($join_sql);
        if ($join_updated === false) {
            $wpdb->query("DROP TABLE IF EXISTS {$stage_table}");
            throw new \RuntimeException('JOIN update failed: ' . (string) $wpdb->last_error);
        }

        $join_ms = (microtime(true) - $t_join) * 1000.0;

        $t_drop = microtime(true);
        $wpdb->query("DROP TABLE IF EXISTS {$stage_table}");
        $drop_ms = (microtime(true) - $t_drop) * 1000.0;

        $total_ms = (microtime(true) - $t_start) * 1000.0;

        $stats = [
            'rows_loaded'  => (int) $rows_loaded,
            'join_matched' => (int) $join_matched,
            'would_change' => (int) $would_change,
            'join_updated' => (int) $join_updated,
            'stage_table'  => (string) $stage_table,
            'ignore_lines' => (int) $ignore_lines,
            'create_ms'    => number_format($create_ms, 2, '.', ''),
            'load_ms'      => number_format($load_ms, 2, '.', ''),
            'stats_ms'     => number_format($stats_ms, 2, '.', ''),
            'join_ms'      => number_format($join_ms, 2, '.', ''),
            'drop_ms'      => number_format($drop_ms, 2, '.', ''),
            'total_ms'     => number_format($total_ms, 2, '.', ''),
        ];

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, 'PROFILE: apply_pricing_quantity_updates_via_load_data() breakdown', $stats);

        return $stats;
    }

    // -------------------------
    // Helpers
    // -------------------------

    private static function schedule_self(int $run_at_unix, array $args): void
    {
        if (!function_exists('as_next_scheduled_action') || !function_exists('as_schedule_single_action')) {
            return;
        }

        $group = 'fflhub_catalog';

        // Idempotent by stable args
        $already = as_next_scheduled_action(self::HOOK, $args, $group);
        if ($already !== false) {
            return;
        }

        as_schedule_single_action($run_at_unix, self::HOOK, $args, $group);
    }

    private static function epochs_equal(int $a_unix, string $a_raw, int $b_unix, string $b_raw): bool
    {
        if ($a_unix > 0 && $b_unix > 0) {
            return $a_unix === $b_unix;
        }
        if ($a_raw !== '' && $b_raw !== '') {
            return $a_raw === $b_raw;
        }
        return false;
    }

    private static function job_key(int $expected_unix, string $expected_raw): string
    {
        if ($expected_raw !== '') {
            return sha1('raw:' . $expected_raw);
        }
        if ($expected_unix > 0) {
            return sha1('unix:' . (string) $expected_unix);
        }
        return sha1('unknown');
    }

    private static function backoff_seconds(int $attempt): int
    {
        // attempt=1 => 60s, 2 => 120s, 3 => 240s, ... capped
        $delay = 60 * (1 << max(0, $attempt - 1));
        if ($delay > self::BACKOFF_CAP) {
            $delay = self::BACKOFF_CAP;
        }
        return (int) $delay;
    }

    private static function mysql_local_infile_enabled(): bool
    {
        global $wpdb;
        $row = $wpdb->get_row("SHOW VARIABLES LIKE 'local_infile'", ARRAY_A);
        if (!is_array($row)) {
            return false;
        }
        $val = strtolower((string) ($row['Value'] ?? $row['value'] ?? ''));
        return $val === 'on' || $val === '1' || $val === 'true';
    }

    private static function php_local_infile_enabled(): bool
    {
        $mysqli = ini_get('mysqli.allow_local_infile');
        $pdo    = ini_get('pdo_mysql.allow_local_infile');

        $ok_mysqli = ($mysqli !== false) ? self::ini_truthy((string) $mysqli) : false;
        $ok_pdo    = ($pdo !== false) ? self::ini_truthy((string) $pdo) : false;

        return ($ok_mysqli || $ok_pdo);
    }

    private static function ini_truthy(string $v): bool
    {
        $v = strtolower(trim($v));
        return in_array($v, ['1', 'on', 'true', 'yes'], true);
    }

    /** @param array<string,mixed> $ctx */
    private static function log(string $msg, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $msg);
            return;
        }
        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $msg, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private static function finalize(float $t_start, int $mem_start, string $status, array $ctx = []): void
    {
        self::log('PROFILE: Total worker run', [
            'status'     => (string) $status,
            'elapsed_ms' => number_format((microtime(true) - $t_start) * 1000.0, 2, '.', ''),
        ]);

        $mem_end = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        if ($mem_start > 0 && $mem_end > 0) {
            self::log('Memory usage summary', [
                'start_kb' => (int) round($mem_start / 1024),
                'end_kb'   => (int) round($mem_end / 1024),
                'delta_kb' => (int) round(($mem_end - $mem_start) / 1024),
            ]);
        }

        if (!empty($ctx)) {
            self::log("---- RUN END ({$status}) ----", $ctx);
        } else {
            self::log("---- RUN END ({$status}) ----");
        }
    }
}
