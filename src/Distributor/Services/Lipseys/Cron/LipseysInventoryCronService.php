<?php

namespace FFLHub\Distributor\Services\Lipseys\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Lipseys\LipseysRawAPI\LipseysClient;
use FFLHub\Distributor\Services\Tables\DoubleBufferedFulfillmentTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * Cron job to refresh Lipsey's pricing/quantity feed hourly
 * using a streaming TSV -> staging table -> JOIN update pipeline.
 *
 * Mirrors the RSR inventory update architecture:
 *  - stream API response directly to TSV (no huge JSON held in memory)
 *  - LOAD DATA LOCAL INFILE into a staging table
 *  - JOIN update the LIVE fulfillment table (4 fields only)
 */
final class LipseysInventoryCronService extends AbstractTableCronService
{
    /**
     * Cron hook name for Lipsey's pricing/quantity refresh.
     */
    public const CRON_HOOK = 'fflhub_lipseys_pricing_quantity_update';

    /**
     * Debug gate constant (define('FFLHUB_CRON_DEBUG', true);).
     */
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';

    /**
     * Log prefix.
     */
    private const LOG_PREFIX = '[FFLHUB][LipseysInventoryCron]';

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
        return HOUR_IN_SECONDS;
    }

    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    protected function get_initial_delay_seconds(): int
    {
        return 5 * MINUTE_IN_SECONDS;
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

        // 0) Credentials
        $t_creds         = microtime(true);
        $dealer_email    = trim((string) Options::get_distributor_option('lipseys', 'dealer_email', ''));
        $dealer_password = trim((string) Options::get_distributor_option('lipseys', 'dealer_password', ''));

        $this->profile('Credentials retrieval', $t_creds, [
            'has_email'    => ($dealer_email !== ''),
            'has_password' => ($dealer_password !== ''),
        ]);

        if ($dealer_email === '' || $dealer_password === '') {
            $this->log('ERROR: dealer_email or dealer_password not set.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing credentials)');
            return;
        }

        // 1) Resolve live table
        $live_table = (string) $this->table->get_live_table_name();
        if ($live_table === '') {
            $this->log('ERROR: could not resolve live table name.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (no live table)');
            return;
        }

        // 2) Build TSV path
        $uploads  = wp_upload_dir();
        $base_dir = trailingslashit($uploads['basedir']) . 'fflhub/lipseys';
        $t_paths  = microtime(true);

        if (!wp_mkdir_p($base_dir)) {
            $this->log('ERROR: failed to create base directory', [
                'base_dir' => (string) $base_dir,
            ]);
            $this->profile('Prepare local paths (mkdir failed)', $t_paths);
            $this->finalize_run($t_start, $mem_start, 'ERROR (mkdir failed)');
            return;
        }

        $tsv_path = trailingslashit($base_dir) . 'pricing_qty_' . gmdate('Ymd_His') . '.tsv';

        $this->profile('Prepare local paths', $t_paths, [
            'base_dir'   => (string) $base_dir,
            'local_tsv'  => (string) $tsv_path,
            'live_table' => (string) $live_table,
        ]);

        // 3) Client creation
        $t_client = microtime(true);
        try {
            $client = new LipseysClient(
                (string) $dealer_email,
                (string) $dealer_password
            );
        } catch (\Throwable $e) {
            $this->log('ERROR: exception creating LipseysClient', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Client creation (failed)', $t_client);
            $this->finalize_run($t_start, $mem_start, 'ERROR (client creation)');
            return;
        }
        $this->profile('Client creation', $t_client);

        // 4) Stream PricingQuantityFeed -> TSV
        $t_stream = microtime(true);

        // TSV columns order (must match LOAD DATA column list)
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
                'inventory_quantity'  => (string) $qty,                 // keep varchar convention
                'allocation_status'   => $allocated ? 'Y' : '',
                'distributor_price'   => $current_price,
                'retail_map'          => $retail_map,
            ];
        };

        try {
            $stream_stats = $client->PricingAndQuantityToTsv($tsv_path, $columns, $item_to_row);
        } catch (\Throwable $e) {
            $this->log('ERROR: exception streaming PricingAndQuantityToTsv()', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('PricingAndQuantityToTsv() (failed)', $t_stream);
            $this->finalize_run($t_start, $mem_start, 'ERROR (stream exception)');
            return;
        }

        $this->profile('PricingAndQuantityToTsv()', $t_stream, is_array($stream_stats) ? $stream_stats : [
            'result_type' => is_object($stream_stats) ? get_class($stream_stats) : gettype($stream_stats),
        ]);

        if (!is_array($stream_stats) || empty($stream_stats['success'])) {
            $this->log('ERROR: PricingAndQuantityToTsv() did not succeed.', [
                'authorized' => is_array($stream_stats) && isset($stream_stats['authorized']) ? (int) ((bool) $stream_stats['authorized']) : null,
                'errors'     => is_array($stream_stats) && isset($stream_stats['errors']) ? $stream_stats['errors'] : null,
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (stream failed)');
            return;
        }

        $rows_written = isset($stream_stats['rows_written']) ? (int) $stream_stats['rows_written'] : 0;

        if ($rows_written <= 0) {
            $this->log('WARNING: streaming produced 0 rows (not swapping)', [
                'items_seen'    => isset($stream_stats['items_seen']) ? (int) $stream_stats['items_seen'] : 0,
                'items_skipped' => isset($stream_stats['items_skipped']) ? (int) $stream_stats['items_skipped'] : 0,
            ]);
            $this->finalize_run($t_start, $mem_start, 'NO APPLY (0 rows)', [
                'rows_written' => (int) $rows_written,
            ]);
            return;
        }

        // 5) Apply updates: TSV -> staging -> JOIN update live table
        $t_apply = microtime(true);

        try {
            $apply_stats = $this->apply_pricing_quantity_updates_via_load_data_profiled(
                $tsv_path,
                $live_table
            );
        } catch (\Throwable $e) {
            $this->log('ERROR: exception applying pricing/quantity updates', [
                'error' => $e->getMessage(),
            ]);
            $this->profile('Apply pricing/quantity updates (failed)', $t_apply);
            $this->finalize_run($t_start, $mem_start, 'ERROR (apply failed)');
            return;
        }

        $this->profile('Apply pricing/quantity updates', $t_apply, $apply_stats);

        $processed_rows = (int) ($apply_stats['rows_loaded'] ?? 0);

        update_option('fflhub_lipseys_pricing_quantity_last_sync', current_time('mysql'));
        update_option('fflhub_lipseys_pricing_quantity_last_sync_count', (int) ($apply_stats['join_updated'] ?? 0));
        update_option('fflhub_lipseys_pricing_quantity_last_sync_matched_count', (int) ($apply_stats['join_matched'] ?? 0));

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'tsv_rows'        => (int) $rows_written,
            'rows_loaded'     => (int) $processed_rows,
            'join_matched'    => (int) ($apply_stats['join_matched'] ?? 0),
            'would_change'    => (int) ($apply_stats['would_change'] ?? 0),
            'join_updated'    => (int) ($apply_stats['join_updated'] ?? 0),
        ]);
    }

    /**
     * Bulk-load TSV into a staging table via LOAD DATA LOCAL INFILE,
     * then join-update the LIVE table for the 4 fields:
     *  - inventory_quantity
     *  - allocation_status
     *  - distributor_price
     *  - retail_map
     *
     * Mirrors RSR instrumentation:
     * - join_matched: number of live rows matched by staging
     * - would_change: matched rows where any of the 4 values differs
     *
     * @return array<string,mixed>
     */
    private function apply_pricing_quantity_updates_via_load_data_profiled(string $tsv_path, string $live_table): array
    {
        global $wpdb;

        $t_start = microtime(true);

        if (!file_exists($tsv_path) || !is_readable($tsv_path)) {
            $this->log('ERROR: TSV missing or unreadable', [
                'tsv_path' => (string) $tsv_path,
            ]);
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

        // No header row written by the streamer.
        $ignore_lines = 0;

        $pid    = function_exists('getmypid') ? (int) getmypid() : 0;
        $suffix = $pid > 0 ? (string) $pid : (string) wp_rand(1000, 9999);

        $stage_table = $wpdb->prefix . 'fflhub_lipseys_pq_stage_' . $suffix;

        // Escape file path for SQL string literal
        $infile_path_sql = str_replace('\\', '\\\\', $tsv_path);
        $infile_path_sql = str_replace("'", "\\'", $infile_path_sql);

        // Capabilities check (same pattern as RSR)
        $t_check = microtime(true);
        $mysql_ok = $this->mysql_local_infile_enabled();
        $php_ok   = $this->php_local_infile_enabled();

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, '[FFLHub][Lipseys Import][DEBUG]', 'LOAD DATA check', [
            'mysql_ok'   => $mysql_ok ? 'true' : 'false',
            'php_ok'     => $php_ok ? 'true' : 'false',
            'result'     => ($mysql_ok && $php_ok) ? 'true' : 'false',
            'elapsed_ms' => number_format((microtime(true) - $t_check) * 1000.0, 2, '.', ''),
        ]);

        // -----------------------
        // Create staging table
        // -----------------------
        $t_create = microtime(true);

        $wpdb->query("DROP TABLE IF EXISTS {$stage_table}");

        $charset = $wpdb->get_charset_collate();

        // Minimal definitions to make JOIN fast + simple.
        // Types align with LIVE schema (all varchar).
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

        // -----------------------
        // LOAD DATA LOCAL INFILE
        // -----------------------
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

        // Handle CRLF / trailing CR (Windows line endings)
        $wpdb->query("UPDATE {$stage_table} SET lipseys_item_number = TRIM(TRAILING '\r' FROM lipseys_item_number)");
        $wpdb->query("UPDATE {$stage_table} SET inventory_quantity = TRIM(TRAILING '\r' FROM inventory_quantity)");
        $wpdb->query("UPDATE {$stage_table} SET allocation_status  = TRIM(TRAILING '\r' FROM allocation_status)");
        $wpdb->query("UPDATE {$stage_table} SET distributor_price  = TRIM(TRAILING '\r' FROM distributor_price)");
        $wpdb->query("UPDATE {$stage_table} SET retail_map         = TRIM(TRAILING '\r' FROM retail_map)");

        $load_ms = (microtime(true) - $t_load) * 1000.0;

        // Row count loaded (truth)
        $rows_loaded = 0;
        $count_row = $wpdb->get_row("SELECT COUNT(*) AS c FROM {$stage_table}", ARRAY_A);
        if (is_array($count_row) && isset($count_row['c'])) {
            $rows_loaded = (int) $count_row['c'];
        }

        // -----------------------
        // Pre-join stats
        // -----------------------
        $t_stats = microtime(true);

        // How many staging rows match a live row?
        $join_matched = (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$stage_table} S
            INNER JOIN {$live_table} L
                ON L.lipseys_item_number = S.lipseys_item_number
        ");

        // How many matched rows would actually change any of the 4 fields?
        // Use COALESCE to treat NULL as '' so comparisons behave.
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

        // -----------------------
        // JOIN update live table
        // -----------------------
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

        // -----------------------
        // Drop staging table
        // -----------------------
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

        DebugLogUtil::log_ctx(
            self::DEBUG_FLAG,
            self::LOG_PREFIX,
            'PROFILE: apply_pricing_quantity_updates_via_load_data() breakdown',
            $stats
        );

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
