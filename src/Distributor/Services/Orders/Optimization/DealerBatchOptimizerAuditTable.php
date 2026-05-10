<?php

namespace FFLHub\Distributor\Services\Orders\Optimization;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table manager + light repository for dealer-batch optimizer audit history.
 */
final class DealerBatchOptimizerAuditTable
{
    private const SCHEMA_OPTION = 'fflhub_dealer_batch_optimizer_audit_schema_version';
    private const SCHEMA_VERSION = '1';

    public function runs_table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . 'fflhub_dealer_batch_optimizer_runs');
    }

    public function moves_table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . 'fflhub_dealer_batch_optimizer_moves');
    }

    public function createTables(): void
    {
        global $wpdb;

        $charset = (string) $wpdb->get_charset_collate();
        $runs_table = $this->runs_table_name();
        $moves_table = $this->moves_table_name();

        $runs_sql = "CREATE TABLE {$runs_table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
run_id VARCHAR(32) NOT NULL,
trigger_dist_id VARCHAR(32) NOT NULL DEFAULT '',
status VARCHAR(24) NOT NULL DEFAULT '',
started_at DATETIME NOT NULL,
finished_at DATETIME NULL,
moves_count INT UNSIGNED NOT NULL DEFAULT 0,
before_json LONGTEXT NULL,
after_json LONGTEXT NULL,
config_json LONGTEXT NULL,
message TEXT NULL,
PRIMARY KEY (id),
UNIQUE KEY uq_run_id (run_id),
KEY idx_started_at (started_at),
KEY idx_status (status)
) {$charset};";

        $moves_sql = "CREATE TABLE {$moves_table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
run_id VARCHAR(32) NOT NULL,
job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
job_key_before VARCHAR(80) NOT NULL DEFAULT '',
job_key_after VARCHAR(80) NOT NULL DEFAULT '',
product_id BIGINT UNSIGNED NULL,
variation_id BIGINT UNSIGNED NULL,
upc VARCHAR(32) NOT NULL DEFAULT '',
quantity INT UNSIGNED NOT NULL DEFAULT 0,
unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
source_dist_id VARCHAR(32) NOT NULL DEFAULT '',
target_dist_id VARCHAR(32) NOT NULL DEFAULT '',
reason TEXT NULL,
created_at DATETIME NOT NULL,
PRIMARY KEY (id),
KEY idx_run_id (run_id),
KEY idx_job_id (job_id),
KEY idx_order_id (order_id),
KEY idx_created_at (created_at)
) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($runs_sql);
        dbDelta($moves_sql);

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
    }

    public function ensureTables(): void
    {
        if ((string) get_option(self::SCHEMA_OPTION, '') === self::SCHEMA_VERSION) {
            return;
        }

        $this->createTables();
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $config
     */
    public function start_run(string $run_id, string $trigger_dist_id, array $before, array $config): void
    {
        global $wpdb;

        $this->ensureTables();
        $wpdb->insert(
            $this->runs_table_name(),
            [
                'run_id' => $run_id,
                'trigger_dist_id' => $trigger_dist_id,
                'status' => 'running',
                'started_at' => gmdate('Y-m-d H:i:s'),
                'moves_count' => 0,
                'before_json' => wp_json_encode($before),
                'config_json' => wp_json_encode($config),
                'message' => '',
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );
    }

    /**
     * @param array<string,mixed> $after
     */
    public function finish_run(string $run_id, string $status, int $moves_count, array $after, string $message = ''): void
    {
        global $wpdb;

        $this->ensureTables();
        $wpdb->update(
            $this->runs_table_name(),
            [
                'status' => $status,
                'finished_at' => gmdate('Y-m-d H:i:s'),
                'moves_count' => max(0, $moves_count),
                'after_json' => wp_json_encode($after),
                'message' => $message,
            ],
            ['run_id' => $run_id],
            ['%s', '%s', '%d', '%s', '%s'],
            ['%s']
        );
    }

    /**
     * @param array<string,mixed> $move
     */
    public function record_move(string $run_id, array $move): void
    {
        global $wpdb;

        $this->ensureTables();
        $wpdb->insert(
            $this->moves_table_name(),
            [
                'run_id' => $run_id,
                'job_id' => (int) ($move['job_id'] ?? 0),
                'order_id' => (int) ($move['order_id'] ?? 0),
                'job_key_before' => (string) ($move['job_key_before'] ?? ''),
                'job_key_after' => (string) ($move['job_key_after'] ?? ''),
                'product_id' => isset($move['product_id']) ? (int) $move['product_id'] : null,
                'variation_id' => isset($move['variation_id']) ? (int) $move['variation_id'] : null,
                'upc' => (string) ($move['upc'] ?? ''),
                'quantity' => max(0, (int) ($move['quantity'] ?? 0)),
                'unit_cost' => number_format((float) ($move['unit_cost'] ?? 0.0), 4, '.', ''),
                'source_dist_id' => (string) ($move['source_dist_id'] ?? ''),
                'target_dist_id' => (string) ($move['target_dist_id'] ?? ''),
                'reason' => (string) ($move['reason'] ?? ''),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent_runs(int $limit = 50): array
    {
        global $wpdb;

        $this->ensureTables();
        $limit = max(1, min(200, $limit));
        $table = $this->runs_table_name();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY started_at DESC, id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent_moves(int $limit = 200): array
    {
        global $wpdb;

        $this->ensureTables();
        $limit = max(1, min(500, $limit));
        $table = $this->moves_table_name();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }
}
