<?php

namespace FFLHub\Distributor\Services\Orders\Shipping;

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Services\Orders\OrderPlacementKeys;
use FFLHub\Distributor\Services\Tables\OrderPlacementJobsTable;
use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Distributor\Registry\DistributorRegistry;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Plugin;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shipping poll scheduler + worker.
 *
 * Selects eligible placement jobs and polls distributor APIs
 * to determine if shipments have been created yet.
 */
final class OrderPlacementShippingPollCronService extends AbstractCronService
{
    private const LOG_PREFIX = '[FFLHUB][ShippingPoller]';

    /**
     * Action Scheduler hook name.
     */
    public const CRON_HOOK = 'fflhub_place_shipping_poll';

    /**
     * Minimum spacing between polls per job (minutes).
     */
    private const JOB_MIN_POLL_INTERVAL_MINUTES = 0;

    /**
     * How many jobs to process per run.
     */
    private const BATCH_LIMIT = 50;

    /**
     * Unique cron hook name.
     */
    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    /**
     * Action Scheduler group.
     */
    public function get_action_group(): string
    {
        return 'fflhub_shipping';
    }

    /**
     * How often the poller runs.
     */
    protected function get_interval_seconds(): int
    {
        return 15 * MINUTE_IN_SECONDS;
    }

    /**
     * Initial delay before first run.
     */
    protected function get_initial_delay_seconds(): int
    {
        return 60; // 1 minute
    }

    /**
     * Worker entrypoint.
     */
    public function run(): void
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();

        // Poll spacing per row
        $min_interval_seconds = self::JOB_MIN_POLL_INTERVAL_MINUTES * 60;
        $cutoff_unix          = time() - $min_interval_seconds;
        $cutoff_mysql_utc     = gmdate('Y-m-d H:i:s', $cutoff_unix);

        $limit = self::BATCH_LIMIT;
        // Optional: stop polling after X days since first shipment detected
        $max_days_after_first_ship = 14;
        $ship_cutoff_unix      = time() - ($max_days_after_first_ship * DAY_IN_SECONDS);
        $ship_cutoff_mysql_utc = gmdate('Y-m-d H:i:s', $ship_cutoff_unix);

        $sql = $wpdb->prepare(
            "
    SELECT
        id, order_id, job_key, dist_id, bucket, status,
        merchant_po, external_order_id,
        shipped_at, last_shipping_poll_at,
        tracking_numbers_json, invoice_numbers_json
    FROM {$table}
    WHERE
        status = %s
        AND merchant_po IS NOT NULL
        AND merchant_po <> ''
        AND (
            last_shipping_poll_at IS NULL
            OR last_shipping_poll_at = '0000-00-00 00:00:00'
            OR last_shipping_poll_at < %s
        )
        AND (
            shipped_at IS NULL
            OR shipped_at = '0000-00-00 00:00:00'
            OR shipped_at >= %s
        )
    ORDER BY
        last_shipping_poll_at IS NULL DESC,
        last_shipping_poll_at ASC,
        id ASC
    LIMIT %d
    ",
            OrderPlacementKeys::JOB_STATUS_SUCCESS,
            $cutoff_mysql_utc,
            $ship_cutoff_mysql_utc,
            $limit
        );


        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (!is_array($rows) || empty($rows)) {
            $this->log_debug('no jobs eligible for shipping poll');
            return;
        }

        $this->log_debug(
            sprintf(
                'eligible jobs=%d cutoff=%s',
                count($rows),
                $cutoff_mysql_utc
            )
        );

        foreach ($rows as $r) {
            $order_id = isset($r['order_id']) ? (int) $r['order_id'] : 0;
            $job_key  = isset($r['job_key']) ? (string) $r['job_key'] : '';
            $job_key = strtolower(trim((string) $job_key));

            $dist_id  = isset($r['dist_id']) ? (string) $r['dist_id'] : '';
            $bucket   = isset($r['bucket']) ? (string) $r['bucket'] : '';
            $po       = isset($r['merchant_po']) ? (string) $r['merchant_po'] : '';
            $ext      = isset($r['external_order_id']) ? (string) $r['external_order_id'] : '';

            // Stamp last_shipping_poll_at now (even if we fail later)
            OrderPlacementShippingStore::touch_last_shipping_poll_at(
                $order_id,
                $job_key
            );

            // ---------------- Distributor lookup (same pattern as job runner) ----------------
            $handler = Plugin::instance()->distributor_handler ?? null;
            if (!($handler instanceof DistributorHandler)) {
                $this->log_debug('distributor handler not available');
                continue;
            }

            if (!Options::is_distributor_enabled($dist_id)) {
                $this->log_debug('distributor disabled: ' . $dist_id);
                continue;
            }

            $dist = $handler->get_distributor_by_id($dist_id);
            if (!($dist instanceof DistributorBase)) {
                $this->log_debug('distributor not found: ' . $dist_id);
                continue;
            }

            // ---------------- Shipment lookup ----------------
            if (!method_exists($dist, 'get_shipment_by_po')) {
                $this->log_debug("distributor {$dist_id} does not support shipment lookup");
                continue;
            }

            $this->log_debug(
                sprintf(
                    'polling shipment order=%d job=%s dist=%s po=%s external=%s',
                    $order_id,
                    $job_key,
                    $dist_id,
                    $po,
                    $ext
                )
            );

            try {
                $shipment = $dist->get_shipment_by_po($po);
            } catch (\Throwable $e) {
                $this->log_debug(
                    sprintf(
                        'shipment lookup threw exception dist=%s po=%s err=%s',
                        $dist_id,
                        $po,
                        $e->getMessage()
                    )
                );
                continue;
            }


            // TEMP TEST BLOCK — REMOVE AFTER VERIFYING EMAIL FLOW
            $shipment = new DistributorShipment(
                ['TEST-TRACK-12345'],
                ['TEST-INVOICE-1'],
                'UPS Ground',
                '5 lb',
                ['debug' => true]
            );

            if (!$shipment) {
                $this->log_debug(
                    sprintf(
                        'no shipment found dist=%s po=%s',
                        $dist_id,
                        $po
                    )
                );
                continue;
            }

            // ---------------- Persist shipment ----------------
            $result = OrderPlacementShippingStore::mark_job_shipped($order_id, $job_key, $shipment);
            $has_new_tracking = !empty($result['added_tracking']) && is_array($result['added_tracking']);


            if ($has_new_tracking) {
                $lines = $this->get_job_payload_lines($order_id, $job_key);


                //we have to make sure our email shit for woo is loaded... weird I know but its a thing... its ugly... really ugly 
                if (function_exists('WC') && WC()) {
                    WC()->mailer()->get_emails(); // ensures your woocommerce_email_classes filter runs
                }
                error_log('has_action(fflhub_trigger_partial_shipment_email)=' . (int) has_action('fflhub_trigger_partial_shipment_email'));

                do_action('fflhub_trigger_partial_shipment_email', $order_id, [
                    'job_key'          => $job_key,
                    'dist_id'          => $dist_id,
                    'bucket'           => $bucket,
                    'po_number'        => $po,
                    'added_tracking'   => $result['added_tracking'],
                    'added_invoices'   => $result['added_invoices'],
                    'shipping_service' => $shipment->shipping_service ?? '',
                    'shipping_weight'  => $shipment->shipping_weight ?? '',
                    'lines'            => $lines, // ✅ contains UPC/qty list
                ]);
            }



            if (OrderPlacementShippingStore::are_all_success_jobs_shipped($order_id)) {
                $order = wc_get_order($order_id);
                if ($order instanceof \WC_Order && $order->has_status(['processing', 'on-hold'])) {
                    $order->update_status('completed', 'FFL Hub: all distributor jobs have tracking numbers.');
                }
            }

            $primary_tracking = method_exists($shipment, 'tracking_number')
                ? (string) ($shipment->tracking_number() ?? '')
                : '';

            $this->log_debug(
                sprintf(
                    'shipment recorded dist=%s po=%s tracking=%s tracking_count=%d',
                    $dist_id,
                    $po,
                    $primary_tracking,
                    (property_exists($shipment, 'tracking_numbers') && is_array($shipment->tracking_numbers))
                        ? count($shipment->tracking_numbers)
                        : 0
                )
            );
        }
    }


    private function get_job_payload_lines(int $order_id, string $job_key): array
    {
        global $wpdb;

        $table = OrderPlacementJobsTable::get_table_name();

        $sql = "
        SELECT payload_json
        FROM {$table}
        WHERE order_id = %d AND job_key = %s
        LIMIT 1
    ";

        $payload_json = (string) $wpdb->get_var($wpdb->prepare($sql, $order_id, $job_key));
        if ($payload_json === '') return [];

        $payload = json_decode($payload_json, true);
        if (!is_array($payload)) return [];

        $lines = $payload['lines'] ?? [];
        return is_array($lines) ? $lines : [];
    }

    /**
     * Debug logger.
     */
    private function log_debug(string $message): void
    {


        error_log(self::LOG_PREFIX . ' ' . $message);
    }
}
