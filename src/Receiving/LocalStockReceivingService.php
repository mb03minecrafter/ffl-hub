<?php
declare(strict_types=1);

namespace FFLHub\Receiving;

use FFLHub\Distributor\Core\DistributorRegistry;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Services\OfferSync\ProductBestOfferSelectionService;
use FFLHub\Distributor\Services\OfferSync\ProductStateBestOfferApplyService;
use FFLHub\Distributor\Services\OfferSync\ProductStateWooApplyService;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Inventory\LocalStockUnitStore;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Settings\Options;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Local-stock receiving that is not driven by a distributor shipment/tracking row.
 *
 * This service still writes the normal receiving_events records. That matters:
 * WMS Sending already reads those events for "what was physically received" and
 * for the exact serial number that must later be disposed in FastBound.
 */
final class LocalStockReceivingService
{
    private const LOCAL_STOCK_DIST_ID = 'local_stock';
    private const MAX_MATCHING_JOBS = 3000;

    private OrderPlacementJobsTable $jobs_table;
    private ReceivingEventsStore $events;
    private ReceivingFastBoundService $fastbound;

    public function __construct(
        OrderPlacementJobsTable $jobs_table,
        ?ReceivingEventsStore $events = null,
        ?ReceivingFastBoundService $fastbound = null
    ) {
        $this->jobs_table = $jobs_table;
        $this->events = $events ?: new ReceivingEventsStore();
        $this->fastbound = $fastbound ?: new ReceivingFastBoundService($this->events);
    }

    /**
     * @return array<string,mixed>
     */
    public function page_context(): array
    {
        return [
            'source_contacts' => $this->source_contact_options(),
            'fastbound' => [
                'enabled' => Options::get_fastbound_enabled() ? 1 : 0,
                'configured' => (
                    Options::get_fastbound_account_number() !== ''
                    && Options::get_fastbound_api_key() !== ''
                    && Options::get_fastbound_audit_user_email() !== ''
                ) ? 1 : 0,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function lookup_product(string $raw_upc): array
    {
        $product = $this->product_payload_for_upc($raw_upc);
        if (empty($product)) {
            return $this->error('product_not_found', __('UPC was not found in active product_state / Woo product data.', 'ffl-hub'));
        }

        return [
            'ok' => true,
            'product' => $product,
        ];
    }

    /**
     * @param mixed $raw_upcs
     * @return array<string,mixed>
     */
    public function matching_orders($raw_upcs): array
    {
        $upcs = $this->normalize_upc_list(is_array($raw_upcs) ? $raw_upcs : []);
        if (empty($upcs)) {
            return $this->error('missing_upcs', __('Scan at least one UPC before looking for local-stock order matches.', 'ffl-hub'));
        }

        return [
            'ok' => true,
            'matches' => $this->matching_local_stock_orders($upcs),
        ];
    }

    /**
     * @param mixed $raw_items
     * @param array<string,mixed> $source
     * @param mixed $raw_firearm_fields
     * @return array<string,mixed>
     */
    public function commit_to_local_stock($raw_items, array $source, $raw_firearm_fields): array
    {
        $items_result = $this->normalize_items(is_array($raw_items) ? $raw_items : []);
        if (empty($items_result['ok'])) {
            return $items_result;
        }

        $items = (array) $items_result['items'];
        $source_result = $this->normalize_source($source, $this->items_require_fastbound($items));
        if (empty($source_result['ok'])) {
            return $source_result;
        }

        $firearm_fields = $this->normalize_firearm_fields(is_array($raw_firearm_fields) ? $raw_firearm_fields : []);
        $shipment_key = $this->local_shipment_key('stock');
        $merchant_po = strtoupper(substr('LOCALSTOCK-' . gmdate('Ymd-His'), 0, 64));

        $created_events = [];
        $acquisitions = [];
        $unit_results = [];
        $upcs = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $upc = (string) ($item['upc'] ?? '');
            $upcs[] = $upc;

            foreach ($this->event_units_for_item($item) as $unit) {
                $event_id = $this->events->insert_event([
                    'shipment_key' => $shipment_key,
                    'job_id' => null,
                    'order_id' => null,
                    'order_item_id' => null,
                    'dist_id' => (string) $source_result['distributor_id'],
                    'lane' => OrderPlacementKeysUtil::LANE_DEALER_FULFILLED,
                    'merchant_po' => $merchant_po,
                    'tracking_number' => '',
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'upc' => $upc,
                    'serial_number' => (string) ($unit['serial_number'] ?? ''),
                    'quantity' => (int) ($unit['quantity'] ?? 1),
                    'result' => 'accepted',
                    'exception_status' => '',
                    'message' => __('Local-stock item received.', 'ffl-hub'),
                    'raw_scan' => $upc,
                    'normalized_scan' => $upc,
                    'request_token' => $this->request_token('stock', $upc, (string) ($unit['serial_number'] ?? '')),
                ]);

                $created_events[] = $event_id;
                if ((string) ($unit['serial_number'] ?? '') !== '') {
                    $acquisitions[] = $this->acquire_event($event_id, $source_result, $firearm_fields[$upc] ?? []);
                }
            }
        }

        $failed_acquisitions = array_values(array_filter(
            $acquisitions,
            static fn(array $row): bool => empty($row['ok'])
        ));
        if (!empty($failed_acquisitions)) {
            return [
                'ok' => false,
                'code' => 'fastbound_acquire_failed',
                'message' => __('One or more FastBound acquisitions failed. Local stock was not incremented.', 'ffl-hub'),
                'events_created' => $created_events,
                'acquisitions' => $acquisitions,
            ];
        }

        foreach ($created_events as $event_id) {
            $unit_results[] = LocalStockUnitStore::create_unit_from_event(
                (int) $event_id,
                LocalStockUnitStore::STATUS_ON_HAND,
                (string) ($source_result['contact_id'] ?? '')
            );
        }

        $pipeline = $this->propagate_local_stock_offer_changes($upcs);

        return [
            'ok' => true,
            'code' => 'local_stock_committed',
            'message' => __('Local stock received into the unit ledger and published as a local-stock offer.', 'ffl-hub'),
            'shipment_key' => $shipment_key,
            'events_created' => $created_events,
            'acquisitions' => $acquisitions,
            'unit_results' => $unit_results,
            'pipeline' => $pipeline,
        ];
    }

    /**
     * @param mixed $raw_items
     * @param array<string,mixed> $source
     * @param mixed $raw_assignments
     * @param mixed $raw_firearm_fields
     * @return array<string,mixed>
     */
    public function assign_to_orders($raw_items, array $source, $raw_assignments, $raw_firearm_fields): array
    {
        $items_result = $this->normalize_items(is_array($raw_items) ? $raw_items : []);
        if (empty($items_result['ok'])) {
            return $items_result;
        }

        $items = (array) $items_result['items'];
        $source_result = $this->normalize_source($source, $this->items_require_fastbound($items));
        if (empty($source_result['ok'])) {
            return $source_result;
        }

        $assignments_result = $this->normalize_assignments(is_array($raw_assignments) ? $raw_assignments : [], $items);
        if (empty($assignments_result['ok'])) {
            return $assignments_result;
        }

        $assignments = (array) $assignments_result['assignments'];
        $firearm_fields = $this->normalize_firearm_fields(is_array($raw_firearm_fields) ? $raw_firearm_fields : []);
        $shipment_key = $this->local_shipment_key('assign');
        $merchant_po = strtoupper(substr('LOCALASSIGN-' . gmdate('Ymd-His'), 0, 64));

        $created_events = [];
        $acquisitions = [];
        $unit_results = [];
        $upcs = [];
        $ready_orders = [];
        $remaining_serials = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $remaining_serials[(string) ($item['upc'] ?? '')] = array_values((array) ($item['serials'] ?? []));
            }
        }

        foreach ($assignments as $assignment) {
            if (!is_array($assignment)) {
                continue;
            }

            $upc = (string) ($assignment['upc'] ?? '');
            $upcs[] = $upc;
            $item = $items[$upc] ?? null;
            if (!is_array($item)) {
                continue;
            }

            $job = $this->validated_local_stock_job(
                (int) ($assignment['job_id'] ?? 0),
                (int) ($assignment['order_id'] ?? 0),
                $upc,
                (int) ($assignment['qty'] ?? 0)
            );
            if (empty($job['ok'])) {
                return $job;
            }

            $order = wc_get_order((int) ($assignment['order_id'] ?? 0));
            if (!($order instanceof WC_Order)) {
                return $this->error('order_not_found', __('Selected order could not be loaded.', 'ffl-hub'));
            }

            $order_item = $this->order_item_for_assignment($order, (int) ($assignment['order_item_id'] ?? 0), $upc);
            if (!$order_item instanceof WC_Order_Item_Product) {
                return $this->error('order_item_not_found', __('Selected order item no longer matches that UPC.', 'ffl-hub'));
            }

            $qty = (int) ($assignment['qty'] ?? 0);
            $serials = [];
            if (!empty($item['serial_required'])) {
                $serials = array_splice($remaining_serials[$upc], 0, $qty);
                if (count($serials) < $qty) {
                    return $this->error('missing_serials', __('There are not enough scanned serial numbers for the selected assignment quantity.', 'ffl-hub'));
                }
            }

            $units = [];
            if (!empty($item['serial_required'])) {
                foreach ($serials as $serial) {
                    $units[] = [
                        'quantity' => 1,
                        'serial_number' => $serial,
                    ];
                }
            } else {
                for ($i = 0; $i < $qty; $i++) {
                    $units[] = [
                        'quantity' => 1,
                        'serial_number' => '',
                    ];
                }
            }

            foreach ($units as $unit) {
                $event_id = $this->events->insert_event([
                    'shipment_key' => $shipment_key,
                    'job_id' => (int) ($job['job_id'] ?? 0),
                    'order_id' => (int) $order->get_id(),
                    'order_item_id' => (int) $order_item->get_id(),
                    'dist_id' => (string) $source_result['distributor_id'],
                    'lane' => OrderPlacementKeysUtil::LANE_DEALER_FULFILLED,
                    'merchant_po' => $merchant_po,
                    'tracking_number' => '',
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'upc' => $upc,
                    'serial_number' => (string) ($unit['serial_number'] ?? ''),
                    'quantity' => (int) ($unit['quantity'] ?? 1),
                    'result' => 'accepted',
                    'exception_status' => '',
                    'message' => __('Local-stock item assigned to order.', 'ffl-hub'),
                    'raw_scan' => $upc,
                    'normalized_scan' => $upc,
                    'request_token' => $this->request_token('assign', $upc, (string) ($unit['serial_number'] ?? ''), (int) $order->get_id(), (int) $order_item->get_id()),
                ]);

                $created_events[] = $event_id;
                if ((string) ($unit['serial_number'] ?? '') !== '') {
                    $acquisition = $this->acquire_event($event_id, $source_result, $firearm_fields[$upc] ?? []);
                    $acquisitions[] = $acquisition;
                    if (empty($acquisition['ok'])) {
                        return [
                            'ok' => false,
                            'code' => 'fastbound_acquire_failed',
                            'message' => __('FastBound acquisition failed. The scanned unit was not allocated into local stock.', 'ffl-hub'),
                            'events_created' => $created_events,
                            'acquisitions' => $acquisitions,
                            'unit_results' => $unit_results,
                        ];
                    }
                }

                $unit_results[] = LocalStockUnitStore::create_unit_from_event(
                    $event_id,
                    LocalStockUnitStore::STATUS_ALLOCATED,
                    (string) ($source_result['contact_id'] ?? '')
                );
            }

            if ($this->mark_order_ready_if_complete((int) $order->get_id())) {
                $ready_orders[(int) $order->get_id()] = true;
            }
        }

        return [
            'ok' => true,
            'code' => 'assigned_to_orders',
            'message' => __('Local-stock receiving events were assigned to matching order rows.', 'ffl-hub'),
            'shipment_key' => $shipment_key,
            'events_created' => $created_events,
            'acquisitions' => $acquisitions,
            'unit_results' => $unit_results,
            'pipeline' => $this->propagate_local_stock_offer_changes($upcs),
            'ready_order_ids' => array_keys($ready_orders),
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function source_contact_options(): array
    {
        $labels = $this->distributor_labels();
        $out = [];

        foreach (Options::get_fastbound_distributor_contacts() as $contact) {
            if (empty($contact['enabled'])) {
                continue;
            }

            $dist_id = strtolower(trim((string) ($contact['distributor_id'] ?? '')));
            $contact_id = trim((string) ($contact['fastbound_contact_id'] ?? ''));
            if ($dist_id === '' || $contact_id === '') {
                continue;
            }

            $dist_label = (string) ($labels[$dist_id] ?? $dist_id);
            $contact_label = trim((string) ($contact['label'] ?? ''));
            if ($contact_label === '') {
                $contact_label = trim((string) ($contact['ffl_number'] ?? $contact_id));
            }

            $out[] = [
                'distributor_id' => $dist_id,
                'distributor_label' => $dist_label,
                'contact_id' => $contact_id,
                'label' => trim($dist_label . ' - ' . $contact_label),
                'external_id' => (string) ($contact['fastbound_contact_external_id'] ?? ''),
                'ffl_number' => (string) ($contact['ffl_number'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<string,string>
     */
    private function distributor_labels(): array
    {
        $labels = [];
        foreach (DistributorRegistry::get_modules() as $module) {
            $labels[$module->id()] = $module->label();
        }

        return $labels;
    }

    /**
     * @return array<string,mixed>
     */
    private function product_payload_for_upc(string $raw_upc): array
    {
        $upc = ReceivingShipmentService::normalize_upc($raw_upc);
        if ($upc === '') {
            return [];
        }

        $state = ProductStateStore::get_row_for_upc($upc);
        if (!is_array($state) || strtolower(trim((string) ($state['status'] ?? ''))) !== 'active') {
            return [];
        }

        $product_id = (int) ($state['product_id'] ?? 0);
        $product = $product_id > 0 ? wc_get_product($product_id) : null;
        if (!($product instanceof WC_Product)) {
            return [];
        }

        $ffl_required = ((int) ($state['ffl_required'] ?? 0)) === 1;

        $legacy_local_qty = ProductStateStore::get_local_stock_override_qty_from_row($state);
        $ledger_local_qty = LocalStockUnitStore::available_qty_for_upc($upc);

        return [
            'upc' => $upc,
            'product_id' => $product_id,
            'name' => $product->get_name(),
            'edit_url' => get_edit_post_link($product_id, ''),
            'ffl_required' => $ffl_required ? 1 : 0,
            'serial_required' => $ffl_required ? 1 : 0,
            'local_stock_qty' => $legacy_local_qty + $ledger_local_qty,
            'legacy_local_stock_qty' => $legacy_local_qty,
            'ledger_local_stock_qty' => $ledger_local_qty,
            'stock_status' => (string) ($state['stock_status'] ?? ''),
            'distributor_id' => (string) ($state['distributor_id'] ?? ''),
        ];
    }

    /**
     * @param array<int,mixed> $raw_items
     * @return array<string,mixed>
     */
    private function normalize_items(array $raw_items): array
    {
        $items = [];
        foreach ($raw_items as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $product = $this->product_payload_for_upc((string) ($raw['upc'] ?? ''));
            if (empty($product)) {
                return $this->error('product_not_found', __('One of the scanned UPCs no longer exists in product_state.', 'ffl-hub'));
            }

            $upc = (string) $product['upc'];
            $serials = [];
            foreach ((array) ($raw['serials'] ?? []) as $serial) {
                $serial = ReceivingShipmentService::normalize_serial((string) $serial);
                if ($serial !== '') {
                    $serials[] = $serial;
                }
            }
            $serials = array_values(array_unique($serials));

            $qty = max(0, (int) ($raw['qty'] ?? 0));
            if (!empty($product['serial_required'])) {
                if (empty($serials)) {
                    return $this->error('serial_required', sprintf(
                        /* translators: %s: UPC. */
                        __('UPC %s requires serial numbers before receiving can be finalized.', 'ffl-hub'),
                        $upc
                    ));
                }
                $qty = count($serials);
            }

            if ($qty < 1) {
                return $this->error('invalid_qty', __('Each scanned local-stock item must have at least quantity 1.', 'ffl-hub'));
            }

            $items[$upc] = array_merge($product, [
                'qty' => $qty,
                'serials' => $serials,
            ]);
        }

        if (empty($items)) {
            return $this->error('missing_items', __('Scan at least one item before finalizing local-stock receiving.', 'ffl-hub'));
        }

        return [
            'ok' => true,
            'items' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function normalize_source(array $source, bool $required): array
    {
        $distributor_id = strtolower(trim(sanitize_text_field((string) ($source['distributor_id'] ?? ''))));
        $contact_id = trim(sanitize_text_field((string) ($source['contact_id'] ?? '')));

        if ($distributor_id === '' || $contact_id === '') {
            return $required
                ? $this->error('missing_source_contact', __('Select the distributor/FastBound source contact these local-stock items came from.', 'ffl-hub'))
                : ['ok' => true, 'distributor_id' => self::LOCAL_STOCK_DIST_ID, 'contact_id' => ''];
        }

        foreach (Options::get_fastbound_contacts_for_distributor($distributor_id) as $contact) {
            if ((string) ($contact['fastbound_contact_id'] ?? '') === $contact_id) {
                return [
                    'ok' => true,
                    'distributor_id' => $distributor_id,
                    'contact_id' => $contact_id,
                ];
            }
        }

        return $this->error('invalid_source_contact', __('Selected FastBound source contact is not mapped to that distributor.', 'ffl-hub'));
    }

    /**
     * @param array<string,array<string,mixed>> $items
     */
    private function items_require_fastbound(array $items): bool
    {
        foreach ($items as $item) {
            if (is_array($item) && !empty($item['serial_required'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function normalize_assignments(array $raw_assignments, array $items): array
    {
        $remaining = [];
        foreach ($items as $upc => $item) {
            $remaining[(string) $upc] = (int) ($item['qty'] ?? 0);
        }

        $assignments = [];
        foreach ($raw_assignments as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $upc = ReceivingShipmentService::normalize_upc((string) ($raw['upc'] ?? ''));
            if ($upc === '' || !isset($items[$upc])) {
                continue;
            }

            $qty = max(0, (int) ($raw['qty'] ?? 0));
            if ($qty < 1) {
                continue;
            }

            $remaining[$upc] -= $qty;
            if ($remaining[$upc] < 0) {
                return $this->error('assignment_qty_exceeds_scans', sprintf(
                    /* translators: %s: UPC. */
                    __('Assigned quantity is higher than scanned quantity for UPC %s.', 'ffl-hub'),
                    $upc
                ));
            }

            $assignments[] = [
                'upc' => $upc,
                'job_id' => max(0, (int) ($raw['job_id'] ?? 0)),
                'order_id' => max(0, (int) ($raw['order_id'] ?? 0)),
                'order_item_id' => max(0, (int) ($raw['order_item_id'] ?? 0)),
                'qty' => $qty,
            ];
        }

        if (empty($assignments)) {
            return $this->error('missing_assignments', __('Choose at least one matching local-stock order row to assign these scans to.', 'ffl-hub'));
        }

        return [
            'ok' => true,
            'assignments' => $assignments,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<int,array{quantity:int,serial_number:string}>
     */
    private function event_units_for_item(array $item): array
    {
        if (empty($item['serial_required'])) {
            $units = [];
            $qty = max(1, (int) ($item['qty'] ?? 1));
            for ($i = 0; $i < $qty; $i++) {
                $units[] = [
                    'quantity' => 1,
                    'serial_number' => '',
                ];
            }

            return $units;
        }

        $units = [];
        foreach ((array) ($item['serials'] ?? []) as $serial) {
            $serial = ReceivingShipmentService::normalize_serial((string) $serial);
            if ($serial !== '') {
                $units[] = [
                    'quantity' => 1,
                    'serial_number' => $serial,
                ];
            }
        }

        return $units;
    }

    /**
     * Run the same normalized-offer propagation path used by distributor crons.
     *
     * @param string[] $upcs
     * @return array<string,mixed>
     */
    private function propagate_local_stock_offer_changes(array $upcs): array
    {
        $upcs = $this->normalize_upc_list($upcs);
        foreach ($upcs as $upc) {
            LocalStockUnitStore::sync_offer_for_upc($upc);
        }

        $best = ProductBestOfferSelectionService::refresh_changed_upcs();
        $state = ProductStateBestOfferApplyService::apply_changed_best_offers();
        $woo = ProductStateWooApplyService::apply_changed_product_state();

        return [
            'best_offer_selection' => $best,
            'product_state_best_offer_apply' => $state,
            'product_state_woo_apply' => $woo,
        ];
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function acquire_event(int $event_id, array $source, array $fields): array
    {
        $result = $this->fastbound->acquire_event($event_id, [
            'source_contact_id' => (string) ($source['contact_id'] ?? ''),
            'manufacturer' => (string) ($fields['manufacturer'] ?? ''),
            'model' => (string) ($fields['model'] ?? ''),
            'caliber' => (string) ($fields['caliber'] ?? ''),
            'firearm_type' => (string) ($fields['firearm_type'] ?? ''),
        ]);

        $result['event_id'] = $event_id;
        return $result;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,array<string,string>>
     */
    private function normalize_firearm_fields(array $raw): array
    {
        $out = [];
        foreach ($raw as $upc => $row) {
            if (!is_array($row)) {
                continue;
            }

            $norm_upc = ReceivingShipmentService::normalize_upc((string) $upc);
            if ($norm_upc === '') {
                $norm_upc = ReceivingShipmentService::normalize_upc((string) ($row['upc'] ?? ''));
            }
            if ($norm_upc === '') {
                continue;
            }

            $out[$norm_upc] = [
                'manufacturer' => $this->text($row['manufacturer'] ?? '', 100),
                'model' => $this->text($row['model'] ?? '', 100),
                'caliber' => $this->text($row['caliber'] ?? '', 100),
                'firearm_type' => $this->text($row['firearm_type'] ?? '', 100),
            ];
        }

        return $out;
    }

    /**
     * @param string[] $upcs
     * @return array<int,array<string,mixed>>
     */
    private function matching_local_stock_orders(array $upcs): array
    {
        $jobs = $this->recent_successful_local_stock_jobs();
        if (empty($jobs)) {
            return [];
        }

        $wanted = array_fill_keys($upcs, true);
        $matches = [];

        foreach ($jobs as $job) {
            if (!$job instanceof OrderPlacementJobRow) {
                continue;
            }

            $order = wc_get_order((int) $job->order_id);
            if (!($order instanceof WC_Order) || !$this->order_is_shippable_status($order)) {
                continue;
            }

            $items_by_upc = $this->order_items_by_upc($order);
            foreach ($job->payload_lines() as $line) {
                if (!$line instanceof DistributorOrderLine) {
                    continue;
                }

                $upc = ReceivingShipmentService::normalize_upc($line->upc);
                if ($upc === '' || !isset($wanted[$upc])) {
                    continue;
                }

                $expected = max(1, (int) $line->quantity);
                $received = $this->received_qty_for_job_upc((int) $job->id, $upc);
                $remaining = max(0, $expected - $received);
                if ($remaining < 1) {
                    continue;
                }

                $order_item = $items_by_upc[$upc] ?? null;
                if (!is_array($order_item)) {
                    continue;
                }

                $matches[] = [
                    'upc' => $upc,
                    'job_id' => (int) $job->id,
                    'order_id' => (int) $order->get_id(),
                    'order_item_id' => (int) ($order_item['order_item_id'] ?? 0),
                    'order_number' => (string) $order->get_order_number(),
                    'order_edit_url' => $order->get_edit_order_url(),
                    'customer_name' => trim((string) $order->get_formatted_billing_full_name()),
                    'item_name' => (string) ($order_item['name'] ?? ''),
                    'qty_expected' => $expected,
                    'qty_received' => $received,
                    'remaining_qty' => $remaining,
                    'job_updated_at' => (string) ($job->updated_at ?? ''),
                ];
            }
        }

        usort(
            $matches,
            static function (array $a, array $b): int {
                $by_order = ((int) ($b['order_id'] ?? 0)) <=> ((int) ($a['order_id'] ?? 0));
                if ($by_order !== 0) {
                    return $by_order;
                }

                return strcmp((string) ($a['upc'] ?? ''), (string) ($b['upc'] ?? ''));
            }
        );

        return $matches;
    }

    /**
     * @return OrderPlacementJobRow[]
     */
    private function recent_successful_local_stock_jobs(): array
    {
        global $wpdb;

        $table = $this->jobs_table->get_table_name();
        $lane = OrderPlacementKeysUtil::LANE_DEALER_FULFILLED;
        $status = OrderPlacementKeys::JOB_STATUS_SUCCESS;
        $job_key = OrderPlacementKeysUtil::build_job_key(self::LOCAL_STOCK_DIST_ID, $lane);

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
                WHERE status = %s
                  AND lane = %s
                  AND (dist_id = %s OR job_key = %s)
                ORDER BY updated_at DESC, id DESC
                LIMIT %d
                ",
                $status,
                $lane,
                self::LOCAL_STOCK_DIST_ID,
                $job_key,
                self::MAX_MATCHING_JOBS
            ),
            ARRAY_A
        );

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $out[] = new OrderPlacementJobRow($row);
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function validated_local_stock_job(int $job_id, int $order_id, string $upc, int $assign_qty): array
    {
        $job = $this->job_by_id($job_id);
        if (!$job instanceof OrderPlacementJobRow) {
            return $this->error('job_not_found', __('Selected local-stock job row was not found.', 'ffl-hub'));
        }

        $lane = $job->lane_norm();
        $dist_id = $job->dist_id_norm();
        if (
            (int) $job->order_id !== $order_id
            || (string) $job->status !== OrderPlacementKeys::JOB_STATUS_SUCCESS
            || !OrderPlacementKeysUtil::is_dealer_fulfilled_lane($lane)
            || $dist_id !== self::LOCAL_STOCK_DIST_ID
        ) {
            return $this->error('invalid_local_stock_job', __('Selected order row is not a successful local-stock dealer-fulfilled job.', 'ffl-hub'));
        }

        foreach ($job->payload_lines() as $line) {
            if ($line instanceof DistributorOrderLine && ReceivingShipmentService::normalize_upc($line->upc) === $upc) {
                $expected = max(1, (int) $line->quantity);
                $received = $this->received_qty_for_job_upc((int) $job->id, $upc);
                $remaining = max(0, $expected - $received);
                if ($assign_qty < 1 || $assign_qty > $remaining) {
                    return $this->error('assignment_qty_exceeds_open_job_qty', __('Assigned quantity is higher than the selected local-stock job row still needs.', 'ffl-hub'));
                }

                return [
                    'ok' => true,
                    'job_id' => (int) $job->id,
                    'job' => $job,
                ];
            }
        }

        return $this->error('job_upc_mismatch', __('Selected local-stock job row does not contain that UPC.', 'ffl-hub'));
    }

    private function job_by_id(int $job_id): ?OrderPlacementJobRow
    {
        global $wpdb;

        if ($job_id <= 0) {
            return null;
        }

        $table = $this->jobs_table->get_table_name();
        $row = $wpdb->get_row(
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
                WHERE id = %d
                LIMIT 1
                ",
                $job_id
            ),
            ARRAY_A
        );

        return is_array($row) ? new OrderPlacementJobRow($row) : null;
    }

    private function received_qty_for_job_upc(int $job_id, string $upc): int
    {
        global $wpdb;

        if ($job_id <= 0 || $upc === '') {
            return 0;
        }

        return max(0, (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(quantity), 0)
                 FROM " . ReceivingEventsStore::table_name() . "
                 WHERE result = 'accepted'
                   AND job_id = %d
                   AND upc = %s",
                $job_id,
                $upc
            )
        ));
    }

    private function mark_order_ready_if_complete(int $order_id): bool
    {
        if ($order_id <= 0 || !$this->order_inbound_ready($order_id)) {
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

                $upc = ReceivingShipmentService::normalize_upc($line->upc);
                $expected = max(1, (int) $line->quantity);
                if ($this->received_qty_for_job_upc((int) $job->id, $upc) < $expected) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function order_items_by_upc(WC_Order $order): array
    {
        $out = [];
        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $upc = $this->upc_for_order_item($item);
            if ($upc === '') {
                continue;
            }

            if (!isset($out[$upc])) {
                $out[$upc] = [
                    'order_item_id' => (int) $item_id,
                    'name' => (string) $item->get_name(),
                    'quantity' => max(1, (int) $item->get_quantity()),
                ];
            }
        }

        return $out;
    }

    private function order_item_for_assignment(WC_Order $order, int $order_item_id, string $upc): ?WC_Order_Item_Product
    {
        $item = $order_item_id > 0 ? $order->get_item($order_item_id) : null;
        if (!$item instanceof WC_Order_Item_Product) {
            return null;
        }

        return $this->upc_for_order_item($item) === $upc ? $item : null;
    }

    private function upc_for_order_item(WC_Order_Item_Product $item): string
    {
        $product = $item->get_product();
        if (!$product instanceof WC_Product) {
            return '';
        }

        $upc = method_exists($product, 'get_global_unique_id')
            ? ReceivingShipmentService::normalize_upc((string) $product->get_global_unique_id())
            : '';
        if ($upc !== '') {
            return $upc;
        }

        $state = ProductStateStore::get_row_for_product($product);
        return is_array($state) ? ReceivingShipmentService::normalize_upc((string) ($state['upc'] ?? '')) : '';
    }

    private function order_is_shippable_status(WC_Order $order): bool
    {
        return !in_array(strtolower(trim((string) $order->get_status())), ['completed', 'cancelled', 'refunded', 'failed', 'trash'], true);
    }

    /**
     * @param array<int,mixed> $upcs
     * @return string[]
     */
    private function normalize_upc_list(array $upcs): array
    {
        $out = [];
        foreach ($upcs as $upc) {
            $upc = ReceivingShipmentService::normalize_upc((string) $upc);
            if ($upc !== '') {
                $out[] = $upc;
            }
        }

        return array_values(array_unique($out));
    }

    private function local_shipment_key(string $prefix): string
    {
        return substr(hash('sha256', $prefix . '|' . get_current_user_id() . '|' . microtime(true) . '|' . wp_generate_uuid4()), 0, 48);
    }

    private function request_token(string $prefix, string $upc, string $serial = '', int $order_id = 0, int $order_item_id = 0): string
    {
        return substr(hash('sha256', implode('|', [
            $prefix,
            $upc,
            $serial,
            (string) $order_id,
            (string) $order_item_id,
            microtime(true),
            wp_generate_uuid4(),
        ])), 0, 64);
    }

    /**
     * @param mixed $value
     */
    private function text($value, int $max): string
    {
        $text = sanitize_text_field((string) ($value ?? ''));
        if ($max > 0 && strlen($text) > $max) {
            $text = substr($text, 0, $max);
        }

        return $text;
    }

    private function error(string $code, string $message): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
        ];
    }
}
