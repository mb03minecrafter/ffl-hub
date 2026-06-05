<?php

namespace FFLHub\CLI;

use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsSchema;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Order\AuthorizeNetOrderRescueService;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safely moves a manually-approved Authorize.net order into processing and
 * starts the FFLHub placement queue without letting the gateway retry capture.
 */
final class RescueAuthorizeNetOrderCommand
{
    private const AUTHNET_CAPTURED_META = '_authnet_charge_captured';
    private const AUTHNET_CHARGE_ID_META = '_authnet_charge_id';
    private const AUTHNET_CAPTURE_FAILED_META = '_authnet_capture_failed';
    private const AUTHNET_FDS_HOLD_META = '_authnet_fds_hold';
    private const PIPELINE_LOCK_META = '_fflhub_order_place_pipeline_lock';

    /**
     * Move a manually approved Authorize.net order to processing and enqueue fulfillment.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : WooCommerce order ID.
     *
     * [--transaction-id=<id>]
     * : Authorize.net transaction ID to record. Defaults to the existing order transaction ID or authnet charge ID.
     *
     * [--status=<status>]
     * : Target WooCommerce status. Defaults to processing.
     *
     * [--dry-run]
     * : Show what would happen without changing the order or jobs.
     *
     * [--force]
     * : Allow rescue even if payment method is not authnet.
     *
     * [--skip-status]
     * : Do not change WooCommerce status.
     *
     * [--skip-pipeline]
     * : Do not force-start the FFLHub order placement pipeline.
     *
     * [--clear-pipeline-lock]
     * : Clear the FFLHub pipeline lock before force-starting placement. The command also clears a stale lock automatically when the pipeline has not started.
     *
     * ## EXAMPLES
     *
     *     wp fflhub rescue-authnet-order 15342 --dry-run --allow-root
     *     wp fflhub rescue-authnet-order 15342 --transaction-id=123456789 --allow-root
     *
     * @when after_wp_load
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function __invoke(array $args, array $assoc_args): void
    {
        if (!class_exists('\WooCommerce')) {
            \WP_CLI::error('WooCommerce is not active.');
        }

        $order_id = isset($args[0]) ? (int) $args[0] : 0;
        if ($order_id <= 0) {
            \WP_CLI::error('Usage: wp fflhub rescue-authnet-order <order_id> [--dry-run]');
        }

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            \WP_CLI::error("Order {$order_id} not found.");
        }

        $dry_run = $this->flag($assoc_args, 'dry-run');
        $force = $this->flag($assoc_args, 'force');
        $skip_status = $this->flag($assoc_args, 'skip-status');
        $skip_pipeline = $this->flag($assoc_args, 'skip-pipeline');
        $clear_pipeline_lock = $this->flag($assoc_args, 'clear-pipeline-lock');
        $target_status = isset($assoc_args['status']) ? sanitize_key((string) $assoc_args['status']) : 'processing';
        if ($target_status === '') {
            $target_status = 'processing';
        }

        $payment_method = (string) $order->get_payment_method();
        if ($payment_method !== 'authnet' && !$force) {
            \WP_CLI::error("Order payment method is '{$payment_method}', not 'authnet'. Use --force to override.");
        }

        $existing_transaction_id = trim((string) $order->get_transaction_id());
        $authnet_charge_id = trim((string) $order->get_meta(self::AUTHNET_CHARGE_ID_META, true));
        $transaction_id = isset($assoc_args['transaction-id'])
            ? trim((string) $assoc_args['transaction-id'])
            : ($existing_transaction_id !== '' ? $existing_transaction_id : $authnet_charge_id);

        $this->log_order_snapshot('Before', $order);
        $this->log_job_rows($order_id, 'Before job rows');

        if ($dry_run) {
            \WP_CLI::log('Dry run only. Planned changes:');
            \WP_CLI::log('- Disable Authorize.net capture-on-status-change for this order inside this request.');
            \WP_CLI::log('- Mark the existing Auth.net transaction captured/paid locally and clear failed-capture/FDS-hold metadata.');
            if ($transaction_id !== '') {
                \WP_CLI::log("- Record transaction ID: {$transaction_id}");
            }
            if ($clear_pipeline_lock || $this->has_stale_pipeline_lock($order)) {
                \WP_CLI::log('- Clear FFLHub pipeline lock before force-starting placement.');
            }
            if (!$skip_status) {
                \WP_CLI::log("- Move order to {$target_status}.");
            }
            if (!$skip_pipeline) {
                \WP_CLI::log('- Force-start FFLHub order placement pipeline; no dealer batch dispatch cron is run.');
            }
            \WP_CLI::success('Dry run complete.');
            return;
        }

        $service = new AuthorizeNetOrderRescueService();
        $result = $service->rescue($order_id, [
            'force' => $force,
            'target_status' => $target_status,
            'transaction_id' => $transaction_id,
            'skip_status' => $skip_status,
            'skip_pipeline' => $skip_pipeline,
            'clear_pipeline_lock' => $clear_pipeline_lock,
        ]);

        if (!$skip_pipeline) {
            \WP_CLI::log('Force-started FFLHub placement pipeline only; no batch dispatch cron was run by this command.');
        }

        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            $this->log_order_snapshot('After', $order);
        }
        $this->log_job_rows($order_id, 'After job rows');

        \WP_CLI::success(sprintf(
            'Authorize.net rescue complete for order %d. Status: %s -> %s.',
            $order_id,
            (string) ($result['before_status'] ?? ''),
            (string) ($result['after_status'] ?? '')
        ));
    }

    /**
     * @param array<string,mixed> $assoc_args
     */
    private function flag(array $assoc_args, string $name): bool
    {
        if (!array_key_exists($name, $assoc_args)) {
            return false;
        }

        $value = $assoc_args[$name];
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));
        return $value === '' || in_array($value, ['1', 'yes', 'true', 'on'], true);
    }

    private function has_stale_pipeline_lock(WC_Order $order): bool
    {
        $order_id = (int) $order->get_id();
        if ($order_id <= 0) {
            return false;
        }

        $lock = (string) get_post_meta($order_id, self::PIPELINE_LOCK_META, true);
        if ($lock === '') {
            return false;
        }

        return (string) $order->get_meta('fflhub_place_pipeline_started', true) !== '1';
    }

    private function log_order_snapshot(string $label, WC_Order $order): void
    {
        \WP_CLI::log(sprintf(
            '%s order: id=%d status=%s payment_method=%s paid=%s transaction_id=%s authnet_charge_id=%s authnet_captured=%s authnet_fds_hold=%s authnet_capture_failed=%s',
            $label,
            (int) $order->get_id(),
            (string) $order->get_status(),
            (string) $order->get_payment_method(),
            $order->is_paid() ? 'yes' : 'no',
            (string) $order->get_transaction_id(),
            (string) $order->get_meta(self::AUTHNET_CHARGE_ID_META, true),
            (string) $order->get_meta(self::AUTHNET_CAPTURED_META, true),
            (string) $order->get_meta(self::AUTHNET_FDS_HOLD_META, true),
            (string) $order->get_meta(self::AUTHNET_CAPTURE_FAILED_META, true)
        ));
    }

    private function log_job_rows(int $order_id, string $label): void
    {
        global $wpdb;

        $jobs_table = new OrderPlacementJobsTable(new OrderPlacementJobsSchema());
        $table = $jobs_table->get_table_name();

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            \WP_CLI::log($label . ': jobs table does not exist yet.');
            return;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, job_key, dist_id, lane, status, next_run_at, last_step, last_error
                 FROM {$table}
                 WHERE order_id = %d
                 ORDER BY id ASC",
                $order_id
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            \WP_CLI::log($label . ': none.');
            return;
        }

        \WP_CLI::log($label . ':');
        foreach ($rows as $row) {
            \WP_CLI::log(sprintf(
                '- #%d %s dist=%s lane=%s status=%s next_run_at=%s last_step=%s error=%s',
                (int) ($row['id'] ?? 0),
                (string) ($row['job_key'] ?? ''),
                (string) ($row['dist_id'] ?? ''),
                (string) ($row['lane'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ($row['next_run_at'] ?? ''),
                (string) ($row['last_step'] ?? ''),
                (string) ($row['last_error'] ?? '')
            ));
        }
    }
}
