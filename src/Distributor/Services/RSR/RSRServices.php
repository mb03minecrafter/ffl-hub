<?php

namespace FFLHub\Distributor\Services\RSR;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\RSR\Cron\RSRProductCronService;
use FFLHub\Distributor\Services\RSR\Cron\RSRInventoryCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Util\DebugLogUtil;

class RSRServices extends DistributorServicesBase
{
    private const MANUFACTURER_COLUMN_MIGRATION_OPTION = 'fflhub_rsr_migration_manufacturer_column_v2';

    public function __construct(
        DoubleBufferedProductTable $fulfillmentTable,
        RSRProductCronService $fulfillmentCron,
        RSRInventoryCronService $inventoryCron
    ) {
        parent::__construct(
            $fulfillmentTable,
            $fulfillmentCron,
            $inventoryCron
        );
    }

    public function on_activate(): void
    {
        parent::on_activate();
        // Tables were just ensured by parent::on_activate().
        $this->run_manufacturer_column_migration_once(false);
    }

    public function register_runtime_services(): void
    {
        // Catch plugin updates where activation hooks do not run.
        $this->run_manufacturer_column_migration_once(true);
        parent::register_runtime_services();
    }

    private function run_manufacturer_column_migration_once(bool $ensure_schema): void
    {
        if ((string) get_option(self::MANUFACTURER_COLUMN_MIGRATION_OPTION, '') === '1') {
            return;
        }

        if (! ($this->fulfillmentTable instanceof DoubleBufferedProductTable)) {
            update_option(self::MANUFACTURER_COLUMN_MIGRATION_OPTION, '1', false);
            return;
        }

        $table = $this->fulfillmentTable;

        if ($ensure_schema) {
            try {
                // Runs dbDelta for both v1/v2 via DoubleBufferedProductTable::createTables().
                $table->createTables();
            } catch (\Throwable $e) {
                $this->log_debug('[RSR manufacturer migration] createTables() failed: ' . $e->getMessage());
                return;
            }
        }

        global $wpdb;

        $table_names = [
            $table->get_table_name_with_suffix('v1'),
            $table->get_table_name_with_suffix('v2'),
        ];

        $had_errors = false;
        $total_backfilled = 0;
        $legacy_dropped_tables = 0;
        $manufacturer_reordered_tables = 0;

        foreach ($table_names as $table_name) {
            if (! $this->is_safe_table_name($table_name)) {
                $had_errors = true;
                $this->log_debug('[RSR manufacturer migration] unsafe table name skipped: ' . $table_name);
                continue;
            }

            $has_manufacturer = $this->table_has_column($table_name, 'manufacturer');
            $has_legacy = $this->table_has_column($table_name, 'full_manufacturer_name');

            if (! $has_manufacturer) {
                $had_errors = true;
                $this->log_debug('[RSR manufacturer migration] missing required manufacturer column: ' . $table_name);
                continue;
            }

            $has_model = $this->table_has_column($table_name, 'model');
            if (! $has_model) {
                $had_errors = true;
                $this->log_debug('[RSR manufacturer migration] missing required model column: ' . $table_name);
                continue;
            }

            $quoted_table = '`' . str_replace('`', '``', $table_name) . '`';
            $reorder_sql = "ALTER TABLE {$quoted_table} MODIFY COLUMN `manufacturer` VARCHAR(255) NULL AFTER `model`";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $reordered = $wpdb->query($reorder_sql);
            if ($reordered === false) {
                $had_errors = true;
                $this->log_debug('[RSR manufacturer migration] reorder manufacturer column failed for ' . $table_name . ': ' . (string) $wpdb->last_error);
                continue;
            }
            $manufacturer_reordered_tables++;

            if (! $has_legacy) {
                continue;
            }

            $sql = "
                UPDATE {$quoted_table}
                SET manufacturer = TRIM(full_manufacturer_name)
                WHERE (manufacturer IS NULL OR TRIM(manufacturer) = '')
                  AND full_manufacturer_name IS NOT NULL
                  AND TRIM(full_manufacturer_name) <> ''
            ";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $updated = $wpdb->query($sql);
            if ($updated === false) {
                $had_errors = true;
                $this->log_debug('[RSR manufacturer migration] backfill failed for ' . $table_name . ': ' . (string) $wpdb->last_error);
                continue;
            }

            $total_backfilled += (int) $updated;

            $drop_sql = "ALTER TABLE {$quoted_table} DROP COLUMN `full_manufacturer_name`";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $dropped = $wpdb->query($drop_sql);
            if ($dropped === false) {
                $had_errors = true;
                $this->log_debug('[RSR manufacturer migration] drop legacy column failed for ' . $table_name . ': ' . (string) $wpdb->last_error);
                continue;
            }

            $legacy_dropped_tables++;
        }

        if ($had_errors) {
            return;
        }

        update_option(self::MANUFACTURER_COLUMN_MIGRATION_OPTION, '1', false);
        $this->log_debug(sprintf('[RSR manufacturer migration] complete; backfilled_rows=%d; legacy_dropped_tables=%d; manufacturer_reordered_tables=%d', $total_backfilled, $legacy_dropped_tables, $manufacturer_reordered_tables));
    }

    private function table_has_column(string $table_name, string $column_name): bool
    {
        global $wpdb;

        if (! $this->is_safe_table_name($table_name)) {
            return false;
        }

        $quoted_table = '`' . str_replace('`', '``', $table_name) . '`';
        $sql = $wpdb->prepare(
            "SHOW COLUMNS FROM {$quoted_table} LIKE %s",
            $column_name
        );
        $result = $wpdb->get_var($sql);

        return is_string($result) && $result !== '';
    }

    private function is_safe_table_name(string $table_name): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $table_name) === 1;
    }

    private function log_debug(string $message): void
    {
        DebugLogUtil::log('FFLHUB_CRON_DEBUG', '[FFLHub][RSRServices]', $message);
    }
}
