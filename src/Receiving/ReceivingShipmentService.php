<?php
declare(strict_types=1);

namespace FFLHub\Receiving;

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds receiving workflows from existing dealer-fulfilled order job rows.
 *
 * There is no cross-distributor shipment table today. The durable shipment
 * source is the order placement job row: distributor, lane, merchant PO,
 * tracking_numbers_json, and payload lines. This service groups those rows into
 * an operational shipment key and writes only receiving scan events.
 */
final class ReceivingShipmentService
{
    private const RECENT_JOB_LIMIT = 3000;
    private const DEBUG_RECENT_JOB_LIMIT = 20000;
    private const HISTORY_LIMIT = 25;

    private OrderPlacementJobsTable $jobs_table;
    private ReceivingEventsStore $events;
    private bool $include_old_shipments;

    public function __construct(OrderPlacementJobsTable $jobs_table, ?ReceivingEventsStore $events = null, bool $include_old_shipments = false)
    {
        $this->jobs_table = $jobs_table;
        $this->events = $events ?: new ReceivingEventsStore();
        $this->include_old_shipments = $include_old_shipments;
    }

    /**
     * @return array<string,mixed>
     */
    public function lookup_by_tracking(string $raw_tracking): array
    {
        $normalized = self::normalize_tracking($raw_tracking);
        if ($normalized === '') {
            return $this->error('empty_tracking', __('Scan or enter a tracking number.', 'ffl-hub'));
        }

        $matches = [];
        foreach ($this->recent_shipments() as $shipment) {
            foreach ((array) ($shipment['tracking_numbers'] ?? []) as $tracking) {
                $tracking_norm = self::normalize_tracking((string) $tracking);
                if ($tracking_norm === $normalized || str_replace(' ', '', $tracking_norm) === str_replace(' ', '', $normalized)) {
                    $matches[$shipment['shipment_key']] = $shipment;
                    break;
                }
            }
        }

        return $this->lookup_result(array_values($matches), 'tracking_not_found', __('Tracking number was not found in dealer shipment tracker rows.', 'ffl-hub'));
    }

    /**
     * @return array<string,mixed>
     */
    public function lookup_by_po(string $raw_po): array
    {
        $needle = strtoupper(trim($raw_po));
        if ($needle === '') {
            return $this->error('empty_po', __('Enter an FFLHub PO or distributor order number.', 'ffl-hub'));
        }

        $matches = [];
        foreach ($this->recent_shipments() as $shipment) {
            $po = strtoupper(trim((string) ($shipment['merchant_po'] ?? '')));
            $external_ids = array_map('strtoupper', (array) ($shipment['external_order_ids'] ?? []));
            if ($po === $needle || in_array($needle, $external_ids, true)) {
                $matches[$shipment['shipment_key']] = $shipment;
            }
        }

        return $this->lookup_result(array_values($matches), 'po_not_found', __('PO number was not found in dealer shipment tracker rows.', 'ffl-hub'));
    }

    /**
     * @return array<string,mixed>
     */
    public function get_shipment(string $shipment_key): array
    {
        $shipment = $this->shipment_by_key($shipment_key);
        if ($shipment === null) {
            return $this->error('shipment_not_found', __('Shipment was not found or is no longer available.', 'ffl-hub'));
        }

        return [
            'ok' => true,
            'shipment' => $shipment,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function scan_product(string $shipment_key, string $raw_scan, string $request_token, string $raw_serial = ''): array
    {
        global $wpdb;

        $shipment_key = trim($shipment_key);
        $request_token = $this->request_token($request_token);
        $upc = self::normalize_upc($raw_scan);
        $serial_number = self::normalize_serial($raw_serial);

        if ($shipment_key === '') {
            return $this->error('missing_shipment', __('No active shipment is selected.', 'ffl-hub'));
        }
        if ($upc === '') {
            return $this->error('empty_upc', __('Scan or enter a UPC.', 'ffl-hub'));
        }

        $existing = $this->events->find_by_request_token($shipment_key, $request_token);
        if (is_array($existing)) {
            return $this->scan_response_from_existing($shipment_key, $existing, true);
        }

        $lock_name = 'fflhub_receiving_' . md5($shipment_key);
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock_name));
        if ($locked !== 1) {
            return $this->error('lock_timeout', __('Receiving is busy for this shipment. Scan again.', 'ffl-hub'));
        }

        try {
            $existing = $this->events->find_by_request_token($shipment_key, $request_token);
            if (is_array($existing)) {
                return $this->scan_response_from_existing($shipment_key, $existing, true);
            }

            $shipment = $this->shipment_by_key($shipment_key);
            if ($shipment === null) {
                return $this->record_rejected_scan(
                    $shipment_key,
                    $raw_scan,
                    $upc,
                    $request_token,
                    'shipment_not_found',
                    __('Shipment was not found or is no longer available.', 'ffl-hub')
                );
            }

            $products = (array) ($shipment['products_by_upc'] ?? []);
            if (!isset($products[$upc])) {
                $found = $this->lookup_product_by_upc($upc);
                return $this->record_rejected_scan(
                    $shipment_key,
                    $raw_scan,
                    $upc,
                    $request_token,
                    $found ? 'unexpected' : 'unknown',
                    $found
                        ? __('This UPC exists, but it is not expected on the active shipment.', 'ffl-hub')
                        : __('UPC was not found in WooCommerce or FFL Hub product state.', 'ffl-hub'),
                    $shipment,
                    $found
                );
            }

            $product = $products[$upc];
            if ((int) ($product['remaining_qty'] ?? 0) <= 0) {
                return $this->record_rejected_scan(
                    $shipment_key,
                    $raw_scan,
                    $upc,
                    $request_token,
                    'excess',
                    __('Expected quantity for this UPC has already been received.', 'ffl-hub'),
                    $shipment,
                    $product
                );
            }

            $allocation = $this->first_open_allocation((array) ($product['orders'] ?? []));
            if (empty($allocation)) {
                return $this->record_rejected_scan(
                    $shipment_key,
                    $raw_scan,
                    $upc,
                    $request_token,
                    'allocation_missing',
                    __('No open customer-order allocation was found for this UPC.', 'ffl-hub'),
                    $shipment,
                    $product
                );
            }

            if (!empty($product['serial_required']) && $serial_number === '') {
                return $this->record_rejected_scan(
                    $shipment_key,
                    $raw_scan,
                    $upc,
                    $request_token,
                    'serial_required',
                    __('Serial number is required before this FFL/serialized item can be received.', 'ffl-hub'),
                    $shipment,
                    $product
                );
            }

            if ($serial_number !== '' && $this->events->accepted_serial_exists($shipment_key, $serial_number)) {
                return $this->record_rejected_scan(
                    $shipment_key,
                    $raw_scan,
                    $upc,
                    $request_token,
                    'duplicate_serial',
                    __('This serial number has already been received on this shipment.', 'ffl-hub'),
                    $shipment,
                    $product,
                    $serial_number
                );
            }

            $event_id = $this->events->insert_event([
                'shipment_key' => $shipment_key,
                'job_id' => (int) ($allocation['job_id'] ?? 0),
                'order_id' => (int) ($allocation['order_id'] ?? 0),
                'order_item_id' => (int) ($allocation['order_item_id'] ?? 0),
                'dist_id' => (string) ($shipment['dist_id'] ?? ''),
                'lane' => (string) ($allocation['lane'] ?? ''),
                'merchant_po' => (string) ($shipment['merchant_po'] ?? ''),
                'tracking_number' => (string) ($shipment['primary_tracking'] ?? ''),
                'product_id' => (int) ($allocation['product_id'] ?? ($product['product_id'] ?? 0)),
                'upc' => $upc,
                'serial_number' => $serial_number,
                'quantity' => 1,
                'result' => 'accepted',
                'exception_status' => '',
                'message' => __('Product received.', 'ffl-hub'),
                'raw_scan' => $raw_scan,
                'normalized_scan' => $upc,
                'request_token' => $request_token,
            ]);

            $order_ready = $this->mark_order_ready_if_complete((int) ($allocation['order_id'] ?? 0));
            $fresh = $this->shipment_by_key($shipment_key);

            return [
                'ok' => true,
                'result' => 'accepted',
                'event_id' => $event_id,
                'message' => __('Product received.', 'ffl-hub'),
                'shipment' => $fresh,
                'product' => $this->public_product_payload($product),
                'allocation' => $allocation,
                'serial_number' => $serial_number,
                'order_ready' => $order_ready,
                'shipment_complete' => !empty($fresh['complete']),
            ];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * Debug-only helper for testing old shipment labels when the physical items
     * are no longer available to scan. Normal receiving never calls this path.
     *
     * @return array<string,mixed>
     */
    public function debug_complete_shipment(string $shipment_key): array
    {
        global $wpdb;

        if (!$this->include_old_shipments) {
            return $this->error('debug_only', __('Enable the old/completed shipment debug option before using this override.', 'ffl-hub'));
        }

        $shipment_key = trim($shipment_key);
        if ($shipment_key === '') {
            return $this->error('missing_shipment', __('No active shipment is selected.', 'ffl-hub'));
        }

        $lock_name = 'fflhub_receiving_' . md5($shipment_key);
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock_name));
        if ($locked !== 1) {
            return $this->error('lock_timeout', __('Receiving is busy for this shipment. Try again.', 'ffl-hub'));
        }

        try {
            $shipment = $this->shipment_by_key($shipment_key);
            if ($shipment === null) {
                return $this->error('shipment_not_found', __('Shipment was not found or is no longer available.', 'ffl-hub'));
            }

            $events_created = 0;
            $orders_touched = [];
            foreach ((array) ($shipment['products_by_upc'] ?? []) as $upc => $product) {
                foreach ((array) ($product['orders'] ?? []) as $allocation) {
                    $remaining = max(0, (int) ($allocation['remaining_qty'] ?? 0));
                    if ($remaining <= 0) {
                        continue;
                    }

                    $this->events->insert_event([
                        'shipment_key' => $shipment_key,
                        'job_id' => (int) ($allocation['job_id'] ?? 0),
                        'order_id' => (int) ($allocation['order_id'] ?? 0),
                        'order_item_id' => (int) ($allocation['order_item_id'] ?? 0),
                        'dist_id' => (string) ($shipment['dist_id'] ?? ''),
                        'lane' => (string) ($allocation['lane'] ?? ''),
                        'merchant_po' => (string) ($shipment['merchant_po'] ?? ''),
                        'tracking_number' => (string) ($shipment['primary_tracking'] ?? ''),
                        'product_id' => (int) ($allocation['product_id'] ?? ($product['product_id'] ?? 0)),
                        'upc' => (string) $upc,
                        'quantity' => $remaining,
                        'result' => 'accepted',
                        'exception_status' => 'debug_override',
                        'message' => __('Debug override: marked received without a product scan.', 'ffl-hub'),
                        'raw_scan' => 'DEBUG_OVERRIDE',
                        'normalized_scan' => (string) $upc,
                        'request_token' => 'debug-' . md5($shipment_key . '|' . (string) ($allocation['job_id'] ?? '') . '|' . (string) $upc . '|' . microtime(true)),
                    ]);
                    $events_created++;

                    $order_id = (int) ($allocation['order_id'] ?? 0);
                    if ($order_id > 0) {
                        $orders_touched[$order_id] = true;
                    }
                }
            }

            foreach (array_keys($orders_touched) as $order_id) {
                $this->mark_order_ready_if_complete((int) $order_id);
            }

            $fresh = $this->shipment_by_key($shipment_key);

            return [
                'ok' => true,
                'result' => 'debug_override',
                'events_created' => $events_created,
                'message' => sprintf(
                    /* translators: %d: number of receiving event rows created. */
                    __('Debug override complete. Created %d receiving event rows.', 'ffl-hub'),
                    $events_created
                ),
                'shipment' => $fresh,
                'shipment_complete' => !empty($fresh['complete']),
            ];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function recent_history(): array
    {
        $history = [];
        foreach ($this->events->recent_history(self::HISTORY_LIMIT) as $row) {
            $shipment_key = (string) ($row['shipment_key'] ?? '');
            $shipment = $shipment_key !== '' ? $this->shipment_by_key($shipment_key) : null;
            $expected = is_array($shipment) ? (int) ($shipment['expected_units'] ?? 0) : 0;
            $received = (int) ($row['received_units'] ?? 0);
            $user = get_user_by('id', (int) ($row['last_user_id'] ?? 0));
            $history[] = [
                'shipment_key' => $shipment_key,
                'dist_id' => (string) ($row['dist_id'] ?? ''),
                'merchant_po' => (string) ($row['merchant_po'] ?? ''),
                'tracking_number' => (string) ($row['tracking_number'] ?? ''),
                'received_units' => $received,
                'expected_units' => $expected,
                'status' => ($expected > 0 && $received >= $expected) ? 'complete' : 'partial',
                'started_at' => (string) ($row['started_at'] ?? ''),
                'last_event_at' => (string) ($row['last_event_at'] ?? ''),
                'employee' => $user ? (string) $user->display_name : '',
            ];
        }

        return [
            'ok' => true,
            'history' => $history,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function lookup_result(array $matches, string $not_found_code, string $not_found_message): array
    {
        if (empty($matches)) {
            return $this->error($not_found_code, $not_found_message);
        }

        if (count($matches) > 1) {
            return [
                'ok' => false,
                'code' => 'multiple_matches',
                'message' => __('Multiple shipments matched. Select the correct shipment.', 'ffl-hub'),
                'matches' => array_map([$this, 'shipment_summary'], $matches),
            ];
        }

        $shipment = $matches[0];
        if ((int) ($shipment['expected_units'] ?? 0) <= 0) {
            return $this->error('no_expected_products', __('Shipment has no expected products.', 'ffl-hub'));
        }

        return [
            'ok' => true,
            'shipment' => $shipment,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recent_shipments(): array
    {
        $jobs = $this->recent_dealer_jobs();
        $groups = [];

        foreach ($jobs as $job) {
            $tracking = $job->tracking_numbers();
            if (empty($tracking)) {
                continue;
            }

            sort($tracking, SORT_STRING);
            $dist_id = $job->dist_id_norm();
            $po = strtoupper(trim($job->merchant_po_or_empty()));
            $key = $this->shipment_key($dist_id, $po, $tracking);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'shipment_key' => $key,
                    'dist_id' => $dist_id,
                    'merchant_po' => $po,
                    'tracking_numbers' => [],
                    'external_order_ids' => [],
                    'shipping_services' => [],
                    'invoice_numbers' => [],
                    'updated_at' => '',
                    'jobs' => [],
                ];
            }

            $groups[$key]['tracking_numbers'] = array_merge($groups[$key]['tracking_numbers'], $tracking);
            $groups[$key]['external_order_ids'] = array_merge($groups[$key]['external_order_ids'], $job->external_order_ids());
            $groups[$key]['shipping_services'][] = $job->shipping_service_or_empty();
            $groups[$key]['invoice_numbers'] = array_merge($groups[$key]['invoice_numbers'], $job->invoice_numbers());
            if ((string) ($job->updated_at ?? '') > (string) $groups[$key]['updated_at']) {
                $groups[$key]['updated_at'] = (string) ($job->updated_at ?? '');
            }
            $groups[$key]['jobs'][] = $job;
        }

        $shipments = [];
        foreach ($groups as $group) {
            $shipments[] = $this->hydrate_shipment($group);
        }

        usort(
            $shipments,
            static fn(array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''))
        );

        return $shipments;
    }

    /**
     * @return OrderPlacementJobRow[]
     */
    private function recent_dealer_jobs(): array
    {
        global $wpdb;

        $table = $this->jobs_table->get_table_name();
        $lane = OrderPlacementKeysUtil::LANE_DEALER_FULFILLED;
        $status = OrderPlacementKeys::JOB_STATUS_SUCCESS;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    id, order_id, job_key, dist_id, lane, status,
                    attempts, created_at, updated_at,
                    action_id, next_run_at,
                    last_step, last_error, last_codes_json,
                    done_at,
                    payload_json, validate_result_json, place_result_json,
                    merchant_po, external_order_ids_json, external_order_id,
                    shipped_at, tracking_numbers_json, invoice_numbers_json,
                    last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json
                FROM {$table}
                WHERE lane = %s
                  AND status = %s
                  AND tracking_numbers_json IS NOT NULL
                  AND tracking_numbers_json <> ''
                ORDER BY updated_at DESC, id DESC
                LIMIT %d
                ",
                $lane,
                $status,
                $this->include_old_shipments ? self::DEBUG_RECENT_JOB_LIMIT : self::RECENT_JOB_LIMIT
            ),
            ARRAY_A
        );

        $jobs = [];
        $order_status_cache = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $job = new OrderPlacementJobRow($row);
                $order_id = (int) $job->order_id;
                if ($order_id <= 0) {
                    continue;
                }

                if (!array_key_exists($order_id, $order_status_cache)) {
                    $order = wc_get_order($order_id);
                    $order_status_cache[$order_id] = ($order && method_exists($order, 'get_status'))
                        ? strtolower(trim((string) $order->get_status()))
                        : '';
                }

                if (!$this->include_old_shipments && $order_status_cache[$order_id] === 'completed') {
                    continue;
                }

                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    /**
     * @param array<string,mixed> $group
     * @return array<string,mixed>
     */
    private function hydrate_shipment(array $group): array
    {
        $tracking = array_values(array_unique(array_filter(array_map('strval', (array) ($group['tracking_numbers'] ?? [])))));
        sort($tracking, SORT_STRING);
        $group['tracking_numbers'] = $tracking;
        $group['primary_tracking'] = $tracking[0] ?? '';
        $group['external_order_ids'] = array_values(array_unique(array_filter(array_map('strval', (array) ($group['external_order_ids'] ?? [])))));
        $group['invoice_numbers'] = array_values(array_unique(array_filter(array_map('strval', (array) ($group['invoice_numbers'] ?? [])))));
        $group['shipping_services'] = array_values(array_unique(array_filter(array_map('strval', (array) ($group['shipping_services'] ?? [])))));
        $group['shipping_service'] = implode(', ', $group['shipping_services']);

        $shipment_key = (string) ($group['shipment_key'] ?? '');
        $received_by_upc = $this->events->accepted_quantities_by_upc($shipment_key);
        $received_by_job_upc = $this->events->accepted_quantities_by_job_upc($shipment_key);
        $products_by_upc = [];
        $orders_by_id = [];
        $order_ids = [];

        foreach ((array) ($group['jobs'] ?? []) as $job) {
            if (!($job instanceof OrderPlacementJobRow)) {
                continue;
            }

            $order_ids[(int) $job->order_id] = true;
            $order = wc_get_order((int) $job->order_id);
            $order_context = $this->order_context($order, (int) $job->order_id);
            $order_items_by_upc = $this->order_items_by_upc($order);

            foreach ($job->payload_lines() as $line) {
                if (!($line instanceof DistributorOrderLine)) {
                    continue;
                }

                $upc = self::normalize_upc($line->upc);
                if ($upc === '') {
                    continue;
                }

                $expected = max(1, (int) $line->quantity);
                $item_context = $order_items_by_upc[$upc] ?? [];
                $product_id = (int) ($item_context['product_id'] ?? 0);
                $name = (string) ($item_context['name'] ?? '');
                if ($name === '') {
                    $found = $this->lookup_product_by_upc($upc);
                    $name = (string) ($found['name'] ?? ('UPC ' . $upc));
                    $product_id = (int) ($found['product_id'] ?? $product_id);
                }

                if (!isset($products_by_upc[$upc])) {
                    $products_by_upc[$upc] = [
                        'upc' => $upc,
                        'product_id' => $product_id,
                        'name' => $name,
                        'ffl_required' => 0,
                        'serial_required' => 0,
                        'expected_qty' => 0,
                        'received_qty' => (int) ($received_by_upc[$upc] ?? 0),
                        'remaining_qty' => 0,
                        'orders' => [],
                    ];
                }

                $ffl_required = !empty($line->ffl_required) ? 1 : 0;
                $job_received = (int) ($received_by_job_upc[$job->id . '|' . $upc] ?? 0);
                $remaining = max(0, $expected - $job_received);

                if ($ffl_required === 1) {
                    $products_by_upc[$upc]['ffl_required'] = 1;
                    $products_by_upc[$upc]['serial_required'] = 1;
                }

                $products_by_upc[$upc]['expected_qty'] += $expected;
                $allocation_row = [
                    'job_id' => (int) $job->id,
                    'order_id' => (int) $job->order_id,
                    'order_number' => $order_context['order_number'],
                    'order_created_ts' => (int) $order_context['created_ts'],
                    'customer_name' => $order_context['customer_name'],
                    'order_edit_url' => $order_context['edit_url'],
                    'order_item_id' => (int) ($item_context['order_item_id'] ?? 0),
                    'product_id' => $product_id,
                    'lane' => $job->lane_norm(),
                    'ffl_required' => $ffl_required,
                    'serial_required' => $ffl_required,
                    'qty_expected' => $expected,
                    'qty_received' => $job_received,
                    'remaining_qty' => $remaining,
                ];
                $products_by_upc[$upc]['orders'][] = $allocation_row;

                if (!isset($orders_by_id[(int) $job->order_id])) {
                    $orders_by_id[(int) $job->order_id] = [
                        'order_id' => (int) $job->order_id,
                        'order_number' => $order_context['order_number'],
                        'customer_name' => $order_context['customer_name'],
                        'order_edit_url' => $order_context['edit_url'],
                        'expected_units' => 0,
                        'received_units' => 0,
                        'remaining_units' => 0,
                        'ready_to_pack' => false,
                        'products' => [],
                    ];
                }

                $orders_by_id[(int) $job->order_id]['expected_units'] += $expected;
                $orders_by_id[(int) $job->order_id]['received_units'] += min($expected, $job_received);
                $orders_by_id[(int) $job->order_id]['remaining_units'] += $remaining;
                $orders_by_id[(int) $job->order_id]['products'][] = [
                    'upc' => $upc,
                    'name' => $name,
                    'ffl_required' => $ffl_required,
                    'serial_required' => $ffl_required,
                    'qty_expected' => $expected,
                    'qty_received' => min($expected, $job_received),
                    'remaining_qty' => $remaining,
                ];
            }
        }

        $expected_units = 0;
        $received_units = 0;
        foreach ($products_by_upc as &$product) {
            $product['received_qty'] = min((int) $product['expected_qty'], (int) $product['received_qty']);
            $product['remaining_qty'] = max(0, (int) $product['expected_qty'] - (int) $product['received_qty']);
            $expected_units += (int) $product['expected_qty'];
            $received_units += (int) $product['received_qty'];
            usort(
                $product['orders'],
                static function (array $a, array $b): int {
                    $by_time = ((int) ($a['order_created_ts'] ?? 0)) <=> ((int) ($b['order_created_ts'] ?? 0));
                    if ($by_time !== 0) {
                        return $by_time;
                    }

                    return ((int) ($a['order_id'] ?? 0)) <=> ((int) ($b['order_id'] ?? 0));
                }
            );
        }
        unset($product);

        uasort(
            $products_by_upc,
            static fn(array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
        );

        foreach ($orders_by_id as &$order_row) {
            $order_row['ready_to_pack'] = $this->order_inbound_ready((int) ($order_row['order_id'] ?? 0));
        }
        unset($order_row);

        uasort(
            $orders_by_id,
            static function (array $a, array $b): int {
                return ((int) ($a['order_id'] ?? 0)) <=> ((int) ($b['order_id'] ?? 0));
            }
        );

        $history = $this->events->recent_events($shipment_key, 50);
        $group['products'] = array_values($products_by_upc);
        $group['products_by_upc'] = $products_by_upc;
        $group['orders'] = array_values($orders_by_id);
        $group['expected_units'] = $expected_units;
        $group['received_units'] = $received_units;
        $group['remaining_units'] = max(0, $expected_units - $received_units);
        $group['order_count'] = count($order_ids);
        $group['status'] = ($expected_units > 0 && $received_units >= $expected_units) ? 'complete' : ($received_units > 0 ? 'partial' : 'open');
        $group['complete'] = $group['status'] === 'complete';
        $group['scan_history'] = $this->public_history($history);
        $group['started_at'] = $history ? (string) end($history)['created_at'] : '';
        $group['completed_at'] = !empty($group['complete']) && $history ? (string) ($history[0]['created_at'] ?? '') : '';

        unset($group['jobs']);

        return $group;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function shipment_by_key(string $shipment_key): ?array
    {
        $shipment_key = trim($shipment_key);
        if ($shipment_key === '') {
            return null;
        }

        foreach ($this->recent_shipments() as $shipment) {
            if ((string) ($shipment['shipment_key'] ?? '') === $shipment_key) {
                return $shipment;
            }
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     * @return array<string,mixed>
     */
    private function first_open_allocation(array $orders): array
    {
        foreach ($orders as $order) {
            if ((int) ($order['remaining_qty'] ?? 0) > 0) {
                return $order;
            }
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function record_rejected_scan(
        string $shipment_key,
        string $raw_scan,
        string $upc,
        string $request_token,
        string $exception,
        string $message,
        ?array $shipment = null,
        ?array $product = null,
        string $serial_number = ''
    ): array {
        $event_id = $this->events->insert_event([
            'shipment_key' => $shipment_key,
            'dist_id' => is_array($shipment) ? (string) ($shipment['dist_id'] ?? '') : '',
            'merchant_po' => is_array($shipment) ? (string) ($shipment['merchant_po'] ?? '') : '',
            'tracking_number' => is_array($shipment) ? (string) ($shipment['primary_tracking'] ?? '') : '',
            'product_id' => is_array($product) ? (int) ($product['product_id'] ?? 0) : 0,
            'upc' => $upc,
            'serial_number' => $serial_number,
            'quantity' => 0,
            'result' => 'rejected',
            'exception_status' => $exception,
            'message' => $message,
            'raw_scan' => $raw_scan,
            'normalized_scan' => $upc,
            'request_token' => $request_token,
        ]);

        return [
            'ok' => false,
            'result' => 'rejected',
            'exception_status' => $exception,
            'event_id' => $event_id,
            'message' => $message,
            'shipment' => $this->shipment_by_key($shipment_key),
            'product' => is_array($product) ? $this->public_product_payload($product) : null,
            'serial_number' => $serial_number,
        ];
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function scan_response_from_existing(string $shipment_key, array $event, bool $duplicate): array
    {
        return [
            'ok' => (string) ($event['result'] ?? '') === 'accepted',
            'result' => (string) ($event['result'] ?? ''),
            'exception_status' => (string) ($event['exception_status'] ?? ''),
            'event_id' => (int) ($event['id'] ?? 0),
            'duplicate' => $duplicate,
            'message' => (string) ($event['message'] ?? __('Duplicate scan request ignored.', 'ffl-hub')),
            'shipment' => $this->shipment_by_key($shipment_key),
            'serial_number' => (string) ($event['serial_number'] ?? ''),
        ];
    }

    private function mark_order_ready_if_complete(int $order_id): bool
    {
        if ($order_id <= 0) {
            return false;
        }

        if (!$this->order_inbound_ready($order_id)) {
            return false;
        }

        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            $order->update_meta_data('_fflhub_receiving_ready_to_pack', '1');
            $order->update_meta_data('_fflhub_receiving_ready_at', current_time('mysql', true));
            $order->save();
        }

        return true;
    }

    private function order_inbound_ready(int $order_id): bool
    {
        global $wpdb;

        if ($order_id <= 0) {
            return false;
        }

        $table = $this->jobs_table->get_table_name();
        $lane = OrderPlacementKeysUtil::LANE_DEALER_FULFILLED;
        $status = OrderPlacementKeys::JOB_STATUS_SUCCESS;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    id, order_id, job_key, dist_id, lane, status,
                    attempts, created_at, updated_at,
                    action_id, next_run_at,
                    last_step, last_error, last_codes_json,
                    done_at,
                    payload_json, validate_result_json, place_result_json,
                    merchant_po, external_order_ids_json, external_order_id,
                    shipped_at, tracking_numbers_json, invoice_numbers_json,
                    last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json
                FROM {$table}
                WHERE order_id = %d
                  AND lane = %s
                  AND status = %s
                  AND tracking_numbers_json IS NOT NULL
                  AND tracking_numbers_json <> ''
                ORDER BY id ASC
                ",
                $order_id,
                $lane,
                $status
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            $job = new OrderPlacementJobRow($row);
            foreach ($job->payload_lines() as $line) {
                if (!($line instanceof DistributorOrderLine)) {
                    continue;
                }

                $upc = self::normalize_upc($line->upc);
                $expected = max(1, (int) $line->quantity);
                $received = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COALESCE(SUM(quantity), 0)
                         FROM " . ReceivingEventsStore::table_name() . "
                         WHERE job_id = %d AND upc = %s AND result = 'accepted'",
                        (int) $job->id,
                        $upc
                    )
                );

                if ($received < $expected) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<string,mixed>
     */
    private function lookup_product_by_upc(string $upc): array
    {
        global $wpdb;

        $upc = self::normalize_upc($upc);
        if ($upc === '') {
            return [];
        }

        $state = \FFLHub\Product\State\ProductStateStore::get_row_for_upc($upc);
        if (is_array($state)) {
            $product_id = (int) ($state['product_id'] ?? 0);
            $product = $product_id > 0 ? wc_get_product($product_id) : null;
            if ($product instanceof WC_Product) {
                return [
                    'product_id' => $product_id,
                    'name' => $product->get_name(),
                    'upc' => $upc,
                ];
            }
        }

        $product_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id
                 FROM {$wpdb->postmeta}
                 WHERE meta_key IN ('_global_unique_id', '_fflhub_upc')
                   AND meta_value = %s
                 ORDER BY FIELD(meta_key, '_global_unique_id', '_fflhub_upc')
                 LIMIT 1",
                $upc
            )
        );

        $product = $product_id > 0 ? wc_get_product($product_id) : null;
        if (!$product instanceof WC_Product) {
            return [];
        }

        return [
            'product_id' => $product_id,
            'name' => $product->get_name(),
            'upc' => $upc,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function order_items_by_upc($order): array
    {
        if (!$order instanceof WC_Order) {
            return [];
        }

        $out = [];
        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();
            if (!$product instanceof WC_Product) {
                continue;
            }

            $upc = method_exists($product, 'get_global_unique_id')
                ? self::normalize_upc((string) $product->get_global_unique_id())
                : '';
            if ($upc === '') {
                $upc = self::normalize_upc((string) get_post_meta((int) $product->get_id(), '_fflhub_upc', true));
            }
            if ($upc === '') {
                continue;
            }

            $out[$upc] = [
                'order_item_id' => (int) $item_id,
                'product_id' => (int) $product->get_id(),
                'name' => $item->get_name(),
                'quantity' => max(1, (int) $item->get_quantity()),
            ];
        }

        return $out;
    }

    /**
     * @return array{order_number:string,created_ts:int,customer_name:string,edit_url:string}
     */
    private function order_context($order, int $order_id): array
    {
        if (!$order instanceof WC_Order) {
            return [
                'order_number' => (string) $order_id,
                'created_ts' => 0,
                'customer_name' => '',
                'edit_url' => $order_id > 0 ? admin_url('post.php?post=' . $order_id . '&action=edit') : '',
            ];
        }

        $created = $order->get_date_created();
        $name = trim($order->get_formatted_billing_full_name());

        return [
            'order_number' => (string) $order->get_order_number(),
            'created_ts' => $created ? (int) $created->getTimestamp() : 0,
            'customer_name' => $name !== '' ? $name : (string) $order->get_billing_email(),
            'edit_url' => admin_url('post.php?post=' . (int) $order->get_id() . '&action=edit'),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @return array<int,array<string,mixed>>
     */
    private function public_history(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            $user = get_user_by('id', (int) ($event['wp_user_id'] ?? 0));
            $out[] = [
                'id' => (int) ($event['id'] ?? 0),
                'received_at' => (string) ($event['received_at'] ?? ''),
                'employee' => $user ? (string) $user->display_name : '',
                'upc' => (string) ($event['upc'] ?? ''),
                'product_id' => (int) ($event['product_id'] ?? 0),
                'order_id' => (int) ($event['order_id'] ?? 0),
                'order_item_id' => (int) ($event['order_item_id'] ?? 0),
                'result' => (string) ($event['result'] ?? ''),
                'exception_status' => (string) ($event['exception_status'] ?? ''),
                'serial_number' => (string) ($event['serial_number'] ?? ''),
                'message' => (string) ($event['message'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function shipment_summary(array $shipment): array
    {
        return [
            'shipment_key' => (string) ($shipment['shipment_key'] ?? ''),
            'dist_id' => (string) ($shipment['dist_id'] ?? ''),
            'merchant_po' => (string) ($shipment['merchant_po'] ?? ''),
            'tracking_numbers' => (array) ($shipment['tracking_numbers'] ?? []),
            'expected_units' => (int) ($shipment['expected_units'] ?? 0),
            'received_units' => (int) ($shipment['received_units'] ?? 0),
            'remaining_units' => (int) ($shipment['remaining_units'] ?? 0),
            'order_count' => (int) ($shipment['order_count'] ?? 0),
            'status' => (string) ($shipment['status'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function public_product_payload(?array $product): ?array
    {
        if (!is_array($product)) {
            return null;
        }

        return [
            'upc' => (string) ($product['upc'] ?? ''),
            'product_id' => (int) ($product['product_id'] ?? 0),
            'name' => (string) ($product['name'] ?? ''),
            'ffl_required' => (int) ($product['ffl_required'] ?? 0),
            'serial_required' => (int) ($product['serial_required'] ?? 0),
            'expected_qty' => (int) ($product['expected_qty'] ?? 0),
            'received_qty' => (int) ($product['received_qty'] ?? 0),
            'remaining_qty' => (int) ($product['remaining_qty'] ?? 0),
        ];
    }

    /**
     * @param string[] $tracking_numbers
     */
    private function shipment_key(string $dist_id, string $po, array $tracking_numbers): string
    {
        $tracking = array_map([self::class, 'normalize_tracking'], $tracking_numbers);
        $tracking = array_values(array_unique(array_filter($tracking)));
        sort($tracking, SORT_STRING);

        return substr(hash('sha256', strtolower($dist_id) . '|' . strtoupper($po) . '|' . implode('|', $tracking)), 0, 48);
    }

    private function request_token(string $token): string
    {
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', trim($token));
        $token = is_string($token) ? $token : '';

        return $token !== '' ? substr($token, 0, 64) : wp_generate_uuid4();
    }

    private function error(string $code, string $message): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
        ];
    }

    public static function normalize_tracking(string $raw): string
    {
        $value = str_replace(["\t", "\r", "\n"], '', trim($raw));
        $fedex_tracking = self::extract_fedex_tracking_from_scan($value);
        if ($fedex_tracking !== '') {
            return $fedex_tracking;
        }

        $value = preg_replace('/\s+/', ' ', $value);
        $value = is_string($value) ? $value : '';

        return strtoupper(trim($value));
    }

    private static function extract_fedex_tracking_from_scan(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $carrier_like = stripos($raw, '[)>') !== false
            || stripos($raw, 'FDEG') !== false
            || stripos($raw, '31Z') !== false
            || stripos($raw, '34Z') !== false;

        $payload = str_replace(["\x1D", "\x1E", "\x04", "\t", "\r", "\n"], '029', $raw);

        if (preg_match('/(?:^|029)31Z(96[0-9]{20,})(?:029|$)/i', $payload, $matches)) {
            return substr((string) $matches[1], -12);
        }

        $compact = preg_replace('/\s+/', '', $payload);
        $compact = is_string($compact) ? $compact : '';
        if (preg_match('/^96[0-9]{20,}$/', $compact)) {
            return substr($compact, -12);
        }

        if (!$carrier_like) {
            return '';
        }

        $parts = preg_split('/029/', $payload);
        foreach (is_array($parts) ? $parts : [] as $part) {
            $part = trim((string) $part);
            if (preg_match('/^[0-9]{12}$/', $part)) {
                return $part;
            }
        }

        return '';
    }

    public static function normalize_upc(string $raw): string
    {
        $value = str_replace(["\t", "\r", "\n", ' '], '', trim($raw));
        $value = preg_replace('/[^0-9A-Za-z]/', '', $value);

        return is_string($value) ? trim($value) : '';
    }

    public static function normalize_serial(string $raw): string
    {
        $value = sanitize_text_field(str_replace(["\t", "\r", "\n"], '', trim($raw)));
        $value = preg_replace('/\s+/', '', $value);
        $value = is_string($value) ? strtoupper(trim($value)) : '';

        return substr($value, 0, 128);
    }
}
