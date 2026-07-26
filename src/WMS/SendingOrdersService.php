<?php
declare(strict_types=1);

namespace FFLHub\WMS;

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Receiving\ReceivingEventsStore;
use FFLHub\Shipping\ShipStation\ShipStationOrderMeta;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only builder for the WMS Sending queue.
 *
 * Sending readiness is derived from the values the receiving workflow already
 * stores: successful dealer-fulfilled order-job payload lines plus accepted
 * receiving events keyed by job_id and UPC.
 */
final class SendingOrdersService
{
    public const DEFAULT_JOB_SCAN_LIMIT = 5000;
    public const MAX_JOB_SCAN_LIMIT = 20000;

    private OrderPlacementJobsTable $jobs_table;

    public function __construct(OrderPlacementJobsTable $jobs_table)
    {
        $this->jobs_table = $jobs_table;
    }

    /**
     * @return array{orders:array<int,array<string,mixed>>,stats:array<string,int>}
     */
    public function ready_orders(int $job_scan_limit = self::DEFAULT_JOB_SCAN_LIMIT, bool $debug_ready = false): array
    {
        ReceivingEventsStore::ensure_schema();

        $job_scan_limit = max(1, min(self::MAX_JOB_SCAN_LIMIT, $job_scan_limit));
        $jobs = OrderPlacementJobsRepository::find_jobs_for_dealer_fulfilled_iteration(
            $this->jobs_table,
            OrderPlacementKeys::JOB_STATUS_SUCCESS,
            $job_scan_limit
        );

        $received = $this->accepted_receiving_rows($jobs);
        $groups = [];

        foreach ($jobs as $job) {
            if (!($job instanceof OrderPlacementJobRow)) {
                continue;
            }

            $order_id = (int) $job->order_id;
            if ($order_id <= 0) {
                continue;
            }

            if (!array_key_exists($order_id, $groups)) {
                $order = wc_get_order($order_id);
                if (!($order instanceof WC_Order) || !$this->order_is_shippable_status($order)) {
                    $groups[$order_id] = null;
                    continue;
                }

                $groups[$order_id] = $this->base_order_row($order);
            }

            if (!is_array($groups[$order_id])) {
                continue;
            }

            $this->add_job_to_order_row($groups[$order_id], $job, $received);
        }

        $orders = [];
        foreach ($groups as $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->finalize_order_row($row, $debug_ready);
            if (empty($row['ready_to_ship'])) {
                continue;
            }

            $orders[] = $row;
        }

        usort(
            $orders,
            static function (array $a, array $b): int {
                $by_ready = strcmp((string) ($b['ready_at'] ?? ''), (string) ($a['ready_at'] ?? ''));
                if ($by_ready !== 0) {
                    return $by_ready;
                }

                return ((int) ($b['order_id'] ?? 0)) <=> ((int) ($a['order_id'] ?? 0));
            }
        );

        return [
            'orders' => $orders,
            'stats' => [
                'jobs_scanned' => count($jobs),
                'ready_orders' => count($orders),
                'needs_label' => count(array_filter($orders, static fn(array $row): bool => empty($row['has_active_label']))),
                'has_label' => count(array_filter($orders, static fn(array $row): bool => !empty($row['has_active_label']))),
                'debug_ready_orders' => count(array_filter($orders, static fn(array $row): bool => !empty($row['debug_ready']))),
            ],
        ];
    }

    /**
     * @param OrderPlacementJobRow[] $jobs
     * @return array<string,array{qty:int,last_received_at:string}>
     */
    private function accepted_receiving_rows(array $jobs): array
    {
        global $wpdb;

        $job_ids = [];
        foreach ($jobs as $job) {
            if ($job instanceof OrderPlacementJobRow && (int) $job->id > 0) {
                $job_ids[] = (int) $job->id;
            }
        }

        $job_ids = array_values(array_unique($job_ids));
        if (empty($job_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($job_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT job_id, upc, SUM(quantity) AS qty, MAX(created_at) AS last_received_at
             FROM " . ReceivingEventsStore::table_name() . "
             WHERE result = 'accepted'
               AND job_id IN ({$placeholders})
             GROUP BY job_id, upc",
            ...$job_ids
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $job_id = (int) ($row['job_id'] ?? 0);
            $upc = self::normalize_upc((string) ($row['upc'] ?? ''));
            if ($job_id <= 0 || $upc === '') {
                continue;
            }

            $out[$job_id . '|' . $upc] = [
                'qty' => max(0, (int) ($row['qty'] ?? 0)),
                'last_received_at' => (string) ($row['last_received_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function base_order_row(WC_Order $order): array
    {
        $labels = ShipStationOrderMeta::labels($order);
        $active_labels = array_values(array_filter(
            $labels,
            static fn(array $label): bool => ShipStationOrderMeta::label_is_active($label)
        ));

        return [
            'order_id' => (int) $order->get_id(),
            'order_number' => (string) $order->get_order_number(),
            'order_edit_url' => $order->get_edit_order_url(),
            'order_status' => (string) $order->get_status(),
            'order_created_at' => $order->get_date_created() ? $order->get_date_created()->date_i18n('M j, Y g:i a') : '',
            'customer_name' => trim((string) $order->get_formatted_billing_full_name()),
            'ready_meta' => self::truthy($order->get_meta('_fflhub_receiving_ready_to_pack', true)),
            'ready_meta_at' => (string) $order->get_meta('_fflhub_receiving_ready_at', true),
            'has_active_label' => !empty($active_labels),
            'active_label_count' => count($active_labels),
            'label_count' => count($labels),
            'active_labels' => $this->label_summaries($active_labels),
            'jobs' => [],
            'items' => [],
            'distributors' => [],
            'merchant_pos' => [],
            'inbound_tracking_numbers' => [],
            'expected_units' => 0,
            'received_units' => 0,
            'remaining_units' => 0,
            'last_received_at' => '',
            'ready_at' => '',
            'ready_to_ship' => false,
            'debug_ready' => false,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array{qty:int,last_received_at:string}> $received
     */
    private function add_job_to_order_row(array &$row, OrderPlacementJobRow $job, array $received): void
    {
        $line_names = $this->order_item_names_by_upc((int) $job->order_id);
        $job_expected = 0;
        $job_received = 0;
        $job_remaining = 0;

        foreach ($job->payload_lines() as $line) {
            if (!($line instanceof DistributorOrderLine)) {
                continue;
            }

            $upc = self::normalize_upc($line->upc);
            if ($upc === '') {
                continue;
            }

            $expected = max(1, (int) $line->quantity);
            $receive_key = (int) $job->id . '|' . $upc;
            $received_row = $received[$receive_key] ?? ['qty' => 0, 'last_received_at' => ''];
            $received_qty = min($expected, max(0, (int) ($received_row['qty'] ?? 0)));
            $remaining = max(0, $expected - $received_qty);
            $last_received_at = (string) ($received_row['last_received_at'] ?? '');

            $job_expected += $expected;
            $job_received += $received_qty;
            $job_remaining += $remaining;
            $row['expected_units'] += $expected;
            $row['received_units'] += $received_qty;
            $row['remaining_units'] += $remaining;
            if ($last_received_at !== '' && $last_received_at > (string) ($row['last_received_at'] ?? '')) {
                $row['last_received_at'] = $last_received_at;
            }

            if (!isset($row['items'][$upc])) {
                $row['items'][$upc] = [
                    'upc' => $upc,
                    'name' => (string) ($line_names[$upc] ?? ('UPC ' . $upc)),
                    'ffl_required' => false,
                    'expected_qty' => 0,
                    'received_qty' => 0,
                    'remaining_qty' => 0,
                ];
            }

            $row['items'][$upc]['ffl_required'] = !empty($row['items'][$upc]['ffl_required']) || $line->ffl_required;
            $row['items'][$upc]['expected_qty'] += $expected;
            $row['items'][$upc]['received_qty'] += $received_qty;
            $row['items'][$upc]['remaining_qty'] += $remaining;
        }

        $row['jobs'][] = [
            'job_id' => (int) $job->id,
            'dist_id' => (string) $job->dist_id_norm(),
            'merchant_po' => (string) ($job->merchant_po ?? ''),
            'tracking_numbers' => $job->tracking_numbers(),
            'expected_units' => $job_expected,
            'received_units' => $job_received,
            'remaining_units' => $job_remaining,
        ];

        $dist_id = trim((string) $job->dist_id_norm());
        if ($dist_id !== '') {
            $row['distributors'][] = $dist_id;
        }

        $merchant_po = trim((string) ($job->merchant_po ?? ''));
        if ($merchant_po !== '') {
            $row['merchant_pos'][] = $merchant_po;
        }

        $row['inbound_tracking_numbers'] = array_merge(
            (array) ($row['inbound_tracking_numbers'] ?? []),
            $job->tracking_numbers()
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function finalize_order_row(array &$row, bool $debug_ready): void
    {
        $row['items'] = array_values($row['items']);
        $row['distributors'] = array_values(array_unique(array_filter(array_map('strval', (array) $row['distributors']))));
        $row['merchant_pos'] = array_values(array_unique(array_filter(array_map('strval', (array) $row['merchant_pos']))));
        $row['inbound_tracking_numbers'] = array_values(array_unique(array_filter(array_map('strval', (array) $row['inbound_tracking_numbers']))));

        $expected = (int) ($row['expected_units'] ?? 0);
        $remaining = (int) ($row['remaining_units'] ?? 0);
        $live_ready = $expected > 0 && $remaining === 0;
        $meta_ready = !empty($row['ready_meta']);
        $debug_ready = $debug_ready && $expected > 0 && !$live_ready && !$meta_ready;
        $row['ready_to_ship'] = $live_ready || $meta_ready || $debug_ready;
        $row['debug_ready'] = $debug_ready;
        $row['readiness_source'] = $live_ready ? 'receiving_events' : ($meta_ready ? 'order_meta' : ($debug_ready ? 'debug_override' : ''));

        $ready_at = trim((string) ($row['ready_meta_at'] ?? ''));
        if ($ready_at === '') {
            $ready_at = trim((string) ($row['last_received_at'] ?? ''));
        }
        if ($ready_at === '' && $debug_ready) {
            $ready_at = current_time('mysql', true);
        }
        $row['ready_at'] = $ready_at;
    }

    /**
     * @return array<string,string>
     */
    private function order_item_names_by_upc(int $order_id): array
    {
        static $cache = [];
        if (array_key_exists($order_id, $cache)) {
            return $cache[$order_id];
        }

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            $cache[$order_id] = [];
            return [];
        }

        $out = [];
        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            if (!($product instanceof WC_Product)) {
                continue;
            }

            $upc = method_exists($product, 'get_global_unique_id')
                ? self::normalize_upc((string) $product->get_global_unique_id())
                : '';
            if ($upc === '') {
                $upc = self::normalize_upc((string) get_post_meta((int) $product->get_id(), '_global_unique_id', true));
            }

            if ($upc !== '' && !isset($out[$upc])) {
                $out[$upc] = (string) $item->get_name();
            }
        }

        $cache[$order_id] = $out;
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $labels
     * @return array<int,array<string,string>>
     */
    private function label_summaries(array $labels): array
    {
        $out = [];
        foreach ($labels as $label) {
            if (!is_array($label)) {
                continue;
            }

            $out[] = [
                'provider' => (string) ($label['provider_label'] ?? $label['provider_id'] ?? ''),
                'carrier' => (string) ($label['carrier_friendly_name'] ?? $label['carrier_nickname'] ?? $label['carrier_code'] ?? ''),
                'service' => (string) ($label['service_name'] ?? $label['service_code'] ?? ''),
                'tracking_number' => (string) ($label['tracking_number'] ?? ''),
                'total_cost' => (string) ($label['total_cost'] ?? $label['cost'] ?? ''),
                'purchased_at' => (string) ($label['purchased_at'] ?? ''),
            ];
        }

        return $out;
    }

    private function order_is_shippable_status(WC_Order $order): bool
    {
        $status = strtolower(trim((string) $order->get_status()));

        return !in_array($status, ['completed', 'cancelled', 'refunded', 'failed', 'trash'], true);
    }

    private static function normalize_upc(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $value);
        return is_string($digits) ? $digits : '';
    }

    /**
     * @param mixed $value
     */
    private static function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
