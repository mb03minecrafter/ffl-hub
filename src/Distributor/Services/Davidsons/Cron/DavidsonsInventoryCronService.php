<?php

namespace FFLHub\Distributor\Services\Davidsons\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Davidsons\API\DavidsonsPortalInventoryClient;
use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * Davidson's inventory/delta cron.
 *
 * Behavior:
 * - Authenticates into the Davidson's portal.
 * - Downloads the `davidsons_quantity` CSV.
 * - Bulk loads into a staging table.
 * - Updates live `inventory_quantity` using Quantity_NC + Quantity_AZ.
 */
final class DavidsonsInventoryCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_davidsons_pricing_quantity_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHUB][DavidsonsInventoryCron]';
    private const DOWNLOAD_DIR = 'fflhub-davidsons';
    private const DOWNLOAD_NAME = 'davidsons_quantity';
    private const DOWNLOAD_TYPE = 'csv';
    private const DOWNLOAD_FILE = 'davidsons_quantity.csv';
    private const DOWNLOAD_COOLDOWN_SECONDS = 300;
    private const STAGE_TABLE_SUFFIX = 'fflhub_davidsons_qty_stage';
    private const FORCE_NO_COOLDOWN_CONST  = 'FFLHUB_DAVIDSONS_INVENTORY_FORCE_NO_COOLDOWN';
    private const FORCE_NO_COOLDOWN_OPTION = 'fflhub_davidsons_inventory_force_no_cooldown';
    private const PROFILE_FLAG = 'FFLHUB_DAVIDSONS_INVENTORY_PROFILE';

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
        return 5 * MINUTE_IN_SECONDS;
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
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        update_option('fflhub_davidsons_inventory_last_run', current_time('mysql'));

        $now = time();
        $last_inventory_download = (int) get_option('fflhub_davidsons_inventory_last_download_ts', 0);
        $last_full_download = (int) get_option('fflhub_davidsons_fulfillment_last_download_ts', 0);
        $last_any_download = max($last_inventory_download, $last_full_download);
        $force_no_cooldown = $this->should_force_no_cooldown();

        if (!$force_no_cooldown && $last_any_download > 0 && ($now - $last_any_download) < self::DOWNLOAD_COOLDOWN_SECONDS) {
            $this->log('Skipping Davidson inventory download due to cooldown.', [
                'cooldown_seconds' => self::DOWNLOAD_COOLDOWN_SECONDS,
                'last_download_ts' => $last_any_download,
                'age_seconds'      => $now - $last_any_download,
            ]);
            return;
        }

        if ($force_no_cooldown) {
            $this->log('FORCE_NO_COOLDOWN enabled - bypassing Davidson inventory cooldown gate.', [
                'cooldown_seconds' => self::DOWNLOAD_COOLDOWN_SECONDS,
                'last_download_ts' => $last_any_download,
                'age_seconds'      => $last_any_download > 0 ? ($now - $last_any_download) : null,
            ]);
        }

        $creds = $this->get_portal_credentials();
        if (!is_array($creds)) {
            update_option('fflhub_davidsons_inventory_last_download_error', current_time('mysql'));
            return;
        }

        $path = $this->resolve_output_path();
        if (!is_string($path) || $path === '') {
            update_option('fflhub_davidsons_inventory_last_download_error', current_time('mysql'));
            return;
        }

        $client = new DavidsonsPortalInventoryClient();
        $result = $client->download_csv_to(
            (string) $creds['username'],
            (string) $creds['password'],
            self::DOWNLOAD_NAME,
            $path,
            self::DOWNLOAD_TYPE
        );

        if (!(bool) ($result['ok'] ?? false)) {
            update_option('fflhub_davidsons_inventory_last_download_error', current_time('mysql'));
            $this->log('ERROR: Davidson delta inventory download failed.', [
                'message' => (string) ($result['message'] ?? 'Unknown error'),
                'context' => (array) ($result['context'] ?? []),
            ]);
            return;
        }

        $bytes = (int) ($result['bytes'] ?? 0);
        update_option('fflhub_davidsons_inventory_last_download', current_time('mysql'));
        update_option('fflhub_davidsons_inventory_last_download_ts', (string) $now);
        update_option('fflhub_davidsons_inventory_last_download_size', (string) $bytes);
        update_option('fflhub_davidsons_inventory_last_download_path', $path);
        delete_option('fflhub_davidsons_inventory_last_download_error');

        try {
            $apply_stats = $this->apply_inventory_updates_via_load_data_profiled($path);
        } catch (\Throwable $e) {
            update_option('fflhub_davidsons_inventory_last_update_error', current_time('mysql'));
            $this->log('ERROR: Davidson delta apply failed.', [
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $processed_rows = (int) ($apply_stats['processed_rows'] ?? 0);

        update_option('fflhub_davidsons_inventory_last_update', current_time('mysql'));
        update_option('fflhub_davidsons_inventory_last_update_count', (int) $processed_rows);
        delete_option('fflhub_davidsons_inventory_last_update_error');

        $this->log('Davidsons delta inventory refresh complete.', [
            'request_name' => self::DOWNLOAD_NAME,
            'bytes'        => $bytes,
            'path'         => $path,
            'processed_rows' => $processed_rows,
            'join_updated'   => (int) ($apply_stats['join_updated'] ?? 0),
        ]);
    }

    /**
     * LOAD DATA + JOIN UPDATE for davidsons_quantity.csv
     *
     * Source headers:
     * Item_Number,UPC_Code,Quantity_NC,Quantity_AZ
     *
     * Quantity math:
     * total_qty = Quantity_NC + Quantity_AZ
     *
     * @return array<string,mixed>
     */
    private function apply_inventory_updates_via_load_data_profiled(string $file_path): array
    {
        global $wpdb;

        $t_start = microtime(true);

        if (!file_exists($file_path) || !is_readable($file_path)) {
            $this->log('ERROR: davidsons_quantity.csv missing or unreadable', [
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

        $infile_path_sql = str_replace('\\', '\\\\', $file_path);
        $infile_path_sql = str_replace("'", "\\'", $infile_path_sql);

        // -----------------------
        // Ensure stage table exists + TRUNCATE
        // -----------------------
        $t_create = microtime(true);

        $create_sql = "
            CREATE TABLE IF NOT EXISTS {$stage_table} (
                item_number varchar(64) NOT NULL,
                upc         varchar(32) NULL,
                qty_nc      int unsigned NOT NULL DEFAULT 0,
                qty_az      int unsigned NOT NULL DEFAULT 0,
                total_qty   int unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (item_number),
                KEY upc (upc)
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
            CHARACTER SET utf8mb4
            FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"' ESCAPED BY '\\\\'
            LINES TERMINATED BY '\\n'
            IGNORE {$ignore_lines} LINES
            (
                @item_number,
                @upc,
                @qty_nc,
                @qty_az
            )
            SET
                item_number = TRIM(BOTH '\\r' FROM TRIM(@item_number)),
                upc         = TRIM(BOTH '#' FROM TRIM(BOTH '\\r' FROM TRIM(@upc))),
                qty_nc      = IFNULL(NULLIF(TRIM(BOTH '\\r' FROM TRIM(@qty_nc)), '') + 0, 0),
                qty_az      = IFNULL(NULLIF(TRIM(BOTH '\\r' FROM TRIM(@qty_az)), '') + 0, 0),
                total_qty   = (
                    IFNULL(NULLIF(TRIM(BOTH '\\r' FROM TRIM(@qty_nc)), '') + 0, 0) +
                    IFNULL(NULLIF(TRIM(BOTH '\\r' FROM TRIM(@qty_az)), '') + 0, 0)
                )
        ";

        $loaded = $wpdb->query($load_sql);
        if ($loaded === false) {
            throw new \RuntimeException('LOAD DATA LOCAL INFILE failed: ' . (string) $wpdb->last_error);
        }

        $load_ms = (microtime(true) - $t_load) * 1000.0;
        $rows_loaded = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$stage_table}");

        // -----------------------
        // Pre-join stats (debug only)
        // -----------------------
        $t_stats = microtime(true);

        // Expensive pre-join profiling is opt-in to avoid slowing regular cron runs.
        $do_stats = defined(self::PROFILE_FLAG) && (bool) constant(self::PROFILE_FLAG);
        $join_matched = 0;
        $would_change = 0;

        if ($do_stats) {
            $join_matched = (int) $wpdb->get_var("
                SELECT COUNT(*)
                FROM {$stage_table} S
                INNER JOIN {$live_table} L
                    ON (
                        (S.upc IS NOT NULL AND S.upc <> '' AND L.upc = S.upc)
                        OR
                        (S.item_number IS NOT NULL AND S.item_number <> '' AND L.davidsons_item_number = S.item_number)
                    )
            ");

            $would_change = (int) $wpdb->get_var("
                SELECT COUNT(*)
                FROM {$stage_table} S
                INNER JOIN {$live_table} L
                    ON (
                        (S.upc IS NOT NULL AND S.upc <> '' AND L.upc = S.upc)
                        OR
                        (S.item_number IS NOT NULL AND S.item_number <> '' AND L.davidsons_item_number = S.item_number)
                    )
                WHERE
                    COALESCE(L.inventory_quantity, '') <> CAST(S.total_qty AS CHAR)
                    OR COALESCE(L.allocation_status, '') <> CASE WHEN S.total_qty > 0 THEN 'in_stock' ELSE 'out_of_stock' END
            ");
        }

        $stats_ms = (microtime(true) - $t_stats) * 1000.0;

        // -----------------------
        // Join update live table
        // -----------------------
        $t_join = microtime(true);

        // Pass 1: fast indexed join by UPC.
        $join_upc_sql = "
            UPDATE {$live_table} L
            INNER JOIN {$stage_table} S
                ON (S.upc IS NOT NULL AND S.upc <> '' AND L.upc = S.upc)
            SET
                L.inventory_quantity = CAST(S.total_qty AS CHAR),
                L.allocation_status  = CASE WHEN S.total_qty > 0 THEN 'in_stock' ELSE 'out_of_stock' END
            WHERE
                COALESCE(L.inventory_quantity, '') <> CAST(S.total_qty AS CHAR)
                OR COALESCE(L.allocation_status, '') <> CASE WHEN S.total_qty > 0 THEN 'in_stock' ELSE 'out_of_stock' END
        ";

        $join_updated_upc = $wpdb->query($join_upc_sql);
        if ($join_updated_upc === false) {
            throw new \RuntimeException('JOIN update (UPC) failed: ' . (string) $wpdb->last_error);
        }

        // Pass 2: fallback by Davidson item number only when there is no UPC match.
        $join_item_sql = "
            UPDATE {$live_table} L
            INNER JOIN {$stage_table} S
                ON (S.item_number IS NOT NULL AND S.item_number <> '' AND L.davidsons_item_number = S.item_number)
            LEFT JOIN {$live_table} LU
                ON (S.upc IS NOT NULL AND S.upc <> '' AND LU.upc = S.upc)
            SET
                L.inventory_quantity = CAST(S.total_qty AS CHAR),
                L.allocation_status  = CASE WHEN S.total_qty > 0 THEN 'in_stock' ELSE 'out_of_stock' END
            WHERE
                LU.upc IS NULL
                AND (
                    COALESCE(L.inventory_quantity, '') <> CAST(S.total_qty AS CHAR)
                    OR COALESCE(L.allocation_status, '') <> CASE WHEN S.total_qty > 0 THEN 'in_stock' ELSE 'out_of_stock' END
                )
        ";

        $join_updated_item = $wpdb->query($join_item_sql);
        if ($join_updated_item === false) {
            throw new \RuntimeException('JOIN update (itemNumber fallback) failed: ' . (string) $wpdb->last_error);
        }

        $join_updated = (int) $join_updated_upc + (int) $join_updated_item;
        $sig_approved_forced = SigDropshipApproval::apply_to_table('davidsons', $live_table);

        $join_ms = (microtime(true) - $t_join) * 1000.0;
        $drop_ms = 0.0; // persistent stage table
        $total_ms = (microtime(true) - $t_start) * 1000.0;

        $stats = [
            'processed_rows' => (int) $rows_loaded,
            'rows_loaded'    => (int) $rows_loaded,
            'join_matched'   => (int) $join_matched,
            'would_change'   => (int) $would_change,
            'join_updated'   => (int) $join_updated,
            'join_updated_upc' => (int) $join_updated_upc,
            'join_updated_item' => (int) $join_updated_item,
            'sig_approved_forced' => (int) $sig_approved_forced,
            'stage_table'    => (string) $stage_table,
            'ignore_lines'   => (int) $ignore_lines,
            'create_ms'      => number_format($create_ms, 2, '.', ''),
            'load_ms'        => number_format($load_ms, 2, '.', ''),
            'stats_ms'       => number_format($stats_ms, 2, '.', ''),
            'join_ms'        => number_format($join_ms, 2, '.', ''),
            'drop_ms'        => number_format($drop_ms, 2, '.', ''),
            'total_ms'       => number_format($total_ms, 2, '.', ''),
        ];

        $this->log('PROFILE: apply_inventory_updates_via_load_data() breakdown', $stats);

        return $stats;
    }

    /**
     * @return array<string,mixed>
     */
    private function empty_apply_stats(): array
    {
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

    private function should_force_no_cooldown(): bool
    {
        if (defined(self::FORCE_NO_COOLDOWN_CONST) && (bool) constant(self::FORCE_NO_COOLDOWN_CONST)) {
            return true;
        }

        $raw = get_option(self::FORCE_NO_COOLDOWN_OPTION, false);
        $enabled = false;

        if (is_bool($raw)) {
            $enabled = $raw;
        } elseif (is_numeric($raw)) {
            $enabled = ((int) $raw) === 1;
        } elseif (is_string($raw)) {
            $enabled = in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) apply_filters('fflhub_davidsons_inventory_force_no_cooldown', $enabled);
    }

    /**
     * @return array{username:string,password:string}|null
     */
    private function get_portal_credentials(): ?array
    {
        $username = trim(Options::get_distributor_option('davidsons', 'portal_username', ''));
        $password = trim(Options::get_distributor_option('davidsons', 'portal_password', ''));

        if ($username === '' || $password === '') {
            $this->log('Missing Davidson portal credentials.', [
                'has_username' => $username !== '' ? 1 : 0,
                'has_password' => $password !== '' ? 1 : 0,
            ]);
            return null;
        }

        return [
            'username' => $username,
            'password' => $password,
        ];
    }

    private function resolve_output_path(): ?string
    {
        $uploads = wp_upload_dir();
        $base_dir = trailingslashit((string) ($uploads['basedir'] ?? '')) . self::DOWNLOAD_DIR;
        if ($base_dir === '' || !wp_mkdir_p($base_dir)) {
            $this->log('Failed to create Davidson download directory.', [
                'base_dir' => $base_dir,
            ]);
            return null;
        }

        return trailingslashit($base_dir) . self::DOWNLOAD_FILE;
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $message);
            return;
        }
        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $message, $ctx);
    }
}
