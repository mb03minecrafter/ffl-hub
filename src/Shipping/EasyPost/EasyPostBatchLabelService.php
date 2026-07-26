<?php
declare(strict_types=1);

namespace FFLHub\Shipping\EasyPost;

use FFLHub\FFL\Tables\FFLSchema;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Order\OrderProfitAuditMeta;
use FFLHub\Shipping\DTO\ShippingPackage;
use FFLHub\Shipping\Packing\PackingSlipService;
use FFLHub\Shipping\Packing\PdfDocumentService;
use FFLHub\Shipping\ShipStation\ShipStationOptions;
use FFLHub\Shipping\ShipStation\ShipStationOrderMeta;
use FFLHub\Shipping\ShipStation\ShipStationShipmentService;
use FFLHub\Shipping\ShippingOptions;
use WC_Order;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prepares and submits EasyPost batch labels for the WMS Sending queue.
 *
 * The per-order shipping UI remains the source of truth for shipment building:
 * this service uses ShipStationShipmentService to auto-pack, validate package
 * assignments, resolve FFL destinations, and build provider-neutral shipment
 * payloads. The batch-specific work is limited to choosing EasyPost rates,
 * submitting a Batch, refreshing async state, and saving purchased labels back
 * into the same order meta used by single-label purchases.
 */
final class EasyPostBatchLabelService
{
    private EasyPostClient $client;
    private EasyPostShippingProvider $provider;
    private ShipStationShipmentService $shipment_service;

    public function __construct(
        ?EasyPostClient $client = null,
        ?EasyPostShippingProvider $provider = null,
        ?ShipStationShipmentService $shipment_service = null
    ) {
        $this->client = $client ?? new EasyPostClient();
        $this->provider = $provider ?? new EasyPostShippingProvider($this->client);
        $this->shipment_service = $shipment_service ?? new ShipStationShipmentService(new FFLTable(new FFLSchema()));
    }

    /**
     * @param array<int,array<string,mixed>> $ready_orders
     * @return array<string,mixed>|WP_Error
     */
    public function prepare_from_ready_orders(array $ready_orders)
    {
        if (!EasyPostOptions::is_enabled()) {
            return new WP_Error('fflhub_easypost_batch_disabled', 'EasyPost is not enabled.');
        }

        if (!$this->client->has_api_key()) {
            return new WP_Error('fflhub_easypost_batch_missing_key', 'EasyPost API key is not configured.');
        }

        $started = microtime(true);
        $items = [];
        $problems = [];
        $orders_seen = 0;
        $orders_with_labels = 0;

        foreach ($ready_orders as $row) {
            $order_id = (int) ($row['order_id'] ?? 0);
            if ($order_id <= 0) {
                continue;
            }

            $orders_seen++;
            $order = wc_get_order($order_id);
            if (!($order instanceof WC_Order)) {
                $problems[] = [
                    'order_id' => $order_id,
                    'message' => 'Woo order could not be loaded.',
                ];
                continue;
            }

            if (ShipStationOrderMeta::has_active_label($order)) {
                $orders_with_labels++;
                $problems[] = [
                    'order_id' => $order_id,
                    'order_number' => (string) $order->get_order_number(),
                    'message' => 'Order already has an active FFL Hub label.',
                ];
                continue;
            }

            $packed = $this->shipment_service->auto_pack_order($order);
            if (empty($packed['ok'])) {
                $problems[] = [
                    'order_id' => $order_id,
                    'order_number' => (string) $order->get_order_number(),
                    'message' => $this->messages_from_packing_result($packed),
                ];
                continue;
            }

            $packages = isset($packed['label_packages']) && is_array($packed['label_packages'])
                ? array_values($packed['label_packages'])
                : [];
            $package_items = isset($packed['package_items']) && is_array($packed['package_items'])
                ? array_values($packed['package_items'])
                : [];

            foreach ($packages as $index => $package) {
                if (!is_array($package)) {
                    continue;
                }

                $item_assignments = is_array($package_items[$index] ?? null) ? $package_items[$index] : [];
                $prepared = $this->prepare_package($order, $package, $item_assignments, $index);
                if (is_wp_error($prepared)) {
                    $problems[] = [
                        'order_id' => $order_id,
                        'order_number' => (string) $order->get_order_number(),
                        'package_index' => $index,
                        'message' => $prepared->get_error_message(),
                    ];
                    continue;
                }

                $items[] = $prepared;
            }
        }

        if (empty($items)) {
            return new WP_Error(
                'fflhub_easypost_batch_no_items',
                'No ready order packages could be prepared for EasyPost batch purchase.',
                ['problems' => $problems]
            );
        }

        $reference = $this->batch_reference();
        $local_id = EasyPostBatchLabelStore::create_prepared($reference, $items, [
            'problems' => $problems,
            'stats' => [
                'orders_seen' => $orders_seen,
                'orders_with_labels' => $orders_with_labels,
                'packages_prepared' => count($items),
                'runtime_ms' => $this->elapsed_ms($started),
            ],
        ]);

        return [
            'batch' => EasyPostBatchLabelStore::get($local_id),
            'problems' => $problems,
            'stats' => [
                'orders_seen' => $orders_seen,
                'orders_with_labels' => $orders_with_labels,
                'packages_prepared' => count($items),
                'runtime_ms' => $this->elapsed_ms($started),
            ],
        ];
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function submit_or_buy(int $local_batch_id)
    {
        $batch = EasyPostBatchLabelStore::get($local_batch_id);
        if (!is_array($batch)) {
            return new WP_Error('fflhub_easypost_batch_missing', 'Could not find that local EasyPost batch.');
        }

        $provider_batch_id = trim((string) ($batch['provider_batch_id'] ?? ''));
        if ($provider_batch_id === '') {
            $shipments = [];
            foreach ((array) ($batch['items'] ?? []) as $item) {
                if (is_array($item['batch_shipment'] ?? null)) {
                    $shipments[] = $item['batch_shipment'];
                }
            }

            $created = $this->client->create_batch($shipments, (string) ($batch['reference'] ?? ''));
            if (is_wp_error($created)) {
                EasyPostBatchLabelStore::update($local_batch_id, [
                    'status' => EasyPostBatchLabelStore::STATUS_FAILED,
                    'error_message' => $created->get_error_message(),
                    'response_json' => ['error' => $created->get_error_data()],
                ]);
                return $created;
            }

            $provider_batch_id = (string) ($created['id'] ?? '');
            EasyPostBatchLabelStore::update($local_batch_id, [
                'provider_batch_id' => $provider_batch_id,
                'status' => $this->local_status_from_easypost((string) ($created['state'] ?? 'submitted')),
                'response_json' => $created,
                'label_url' => (string) ($created['label_url'] ?? ''),
            ]);
            $batch = EasyPostBatchLabelStore::get($local_batch_id) ?? $batch;
        }

        $remote = $provider_batch_id !== '' ? $this->client->retrieve_batch($provider_batch_id) : null;
        if (is_wp_error($remote)) {
            EasyPostBatchLabelStore::update($local_batch_id, [
                'status' => EasyPostBatchLabelStore::STATUS_FAILED,
                'error_message' => $remote->get_error_message(),
            ]);
            return $remote;
        }

        $remote_state = strtolower(trim((string) ($remote['state'] ?? '')));
        if ($remote_state !== '' && !in_array($remote_state, ['created', 'purchased', 'label_generated'], true)) {
            EasyPostBatchLabelStore::update($local_batch_id, [
                'status' => $this->local_status_from_easypost($remote_state),
                'response_json' => $remote,
                'label_url' => (string) ($remote['label_url'] ?? ''),
            ]);

            return [
                'batch' => EasyPostBatchLabelStore::get($local_batch_id),
                'message' => 'EasyPost batch is not ready to buy yet. Refresh the batch status and try again.',
            ];
        }

        if ($remote_state === 'created') {
            $bought = $this->client->buy_batch($provider_batch_id);
            if (is_wp_error($bought)) {
                EasyPostBatchLabelStore::update($local_batch_id, [
                    'status' => EasyPostBatchLabelStore::STATUS_FAILED,
                    'error_message' => $bought->get_error_message(),
                    'response_json' => ['error' => $bought->get_error_data()],
                ]);
                return $bought;
            }

            EasyPostBatchLabelStore::update($local_batch_id, [
                'status' => $this->local_status_from_easypost((string) ($bought['state'] ?? 'purchasing')),
                'response_json' => $bought,
                'label_url' => (string) ($bought['label_url'] ?? ''),
            ]);
        }

        return $this->refresh($local_batch_id);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function refresh(int $local_batch_id)
    {
        $batch = EasyPostBatchLabelStore::get($local_batch_id);
        if (!is_array($batch)) {
            return new WP_Error('fflhub_easypost_batch_missing', 'Could not find that local EasyPost batch.');
        }

        $provider_batch_id = trim((string) ($batch['provider_batch_id'] ?? ''));
        if ($provider_batch_id === '') {
            return [
                'batch' => $batch,
                'labels_saved' => 0,
                'message' => 'Local batch has not been submitted to EasyPost yet.',
            ];
        }

        $remote = $this->client->retrieve_batch($provider_batch_id);
        if (is_wp_error($remote)) {
            EasyPostBatchLabelStore::update($local_batch_id, [
                'status' => EasyPostBatchLabelStore::STATUS_FAILED,
                'error_message' => $remote->get_error_message(),
            ]);
            return $remote;
        }

        $items = $this->merge_remote_shipments_into_items(
            is_array($batch['items'] ?? null) ? $batch['items'] : [],
            is_array($remote['shipments'] ?? null) ? $remote['shipments'] : []
        );
        $state = $this->local_status_from_easypost((string) ($remote['state'] ?? ''));
        $label_url = (string) ($remote['label_url'] ?? $batch['label_url'] ?? '');

        $labels_saved = 0;
        if ($this->batch_has_purchased_shipments($remote)) {
            $save_result = $this->save_purchased_labels($items, $provider_batch_id);
            $items = $save_result['items'];
            $labels_saved = $save_result['labels_saved'];

            if ($label_url === '') {
                $format = strtoupper(EasyPostOptions::label_format());
                if ($format !== 'PDF') {
                    $format = 'PDF';
                }
                $label_result = $this->client->create_batch_label($provider_batch_id, $format);
                if (!is_wp_error($label_result)) {
                    $label_url = (string) ($label_result['label_url'] ?? '');
                    $remote = $label_result;
                    $state = $this->local_status_from_easypost((string) ($label_result['state'] ?? $state));
                }
            }
        }

        if ($label_url !== '') {
            $state = EasyPostBatchLabelStore::STATUS_LABEL_GENERATED;
        }
        if ($labels_saved > 0 && $this->all_items_have_saved_labels($items)) {
            $state = EasyPostBatchLabelStore::STATUS_LABELS_SAVED;
        }

        EasyPostBatchLabelStore::update($local_batch_id, [
            'status' => $state,
            'items_json' => $items,
            'response_json' => $remote,
            'label_url' => $label_url,
            'error_message' => $this->batch_error_message($remote),
        ]);

        return [
            'batch' => EasyPostBatchLabelStore::get($local_batch_id),
            'labels_saved' => $labels_saved,
        ];
    }

    /**
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    public function print_packet(int $local_batch_id)
    {
        $batch = EasyPostBatchLabelStore::get($local_batch_id);
        if (!is_array($batch)) {
            return new WP_Error('fflhub_easypost_batch_missing', 'Could not find that local EasyPost batch.');
        }

        $documents = [];
        $slip_service = new PackingSlipService();
        foreach ((array) ($batch['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $shipment_id = trim((string) ($item['batch_shipment_id'] ?? ''));
            if ($shipment_id === '') {
                continue;
            }

            $order = wc_get_order((int) ($item['order_id'] ?? 0));
            if (!($order instanceof WC_Order)) {
                continue;
            }

            $shipment = $this->client->retrieve_shipment($shipment_id);
            if (is_wp_error($shipment)) {
                continue;
            }

            $label_url = $this->label_pdf_url_from_shipment($shipment);
            if ($label_url === '') {
                continue;
            }

            $label_pdf = $this->client->download_label($label_url);
            if (is_wp_error($label_pdf)) {
                continue;
            }

            $documents[] = (string) ($label_pdf['body'] ?? '');

            $package = is_array($item['package'] ?? null) ? $item['package'] : [];
            $package_items = is_array($item['package_items'] ?? null) ? $item['package_items'] : [];
            $shipment_snapshot = is_array($item['shipment'] ?? null) ? $item['shipment'] : [];
            $destination = is_array($shipment_snapshot['ship_to'] ?? null) ? $shipment_snapshot['ship_to'] : [];
            $slip = $slip_service->generate_pdf_for_package(
                $order,
                ShippingPackage::from_array($package, $package_items),
                $destination,
                [
                    'package_index' => ((int) ($item['package_index'] ?? 0)) + 1,
                    'package_count' => $this->package_count_for_order((array) ($batch['items'] ?? []), (int) $order->get_id()),
                ]
            );
            if (!is_wp_error($slip)) {
                $documents[] = (string) ($slip['body'] ?? '');
            }
        }

        if (empty($documents)) {
            return new WP_Error(
                'fflhub_easypost_batch_no_print_documents',
                'No purchased shipment label PDFs were available for this batch yet.'
            );
        }

        return (new PdfDocumentService())->combine(
            $documents,
            'easypost-batch-' . (int) ($batch['id'] ?? $local_batch_id) . '-labels-and-packing-slips.pdf'
        );
    }

    /**
     * @param array<string,mixed> $package
     * @param array<int,array<string,mixed>> $package_items
     * @return array<string,mixed>|WP_Error
     */
    private function prepare_package(WC_Order $order, array $package, array $package_items, int $package_index)
    {
        $shipment = $this->shipment_service->shipment_for_packages(
            $order,
            [$package],
            [$package_items],
            [
                'ship_date' => gmdate('Y-m-d'),
            ]
        );
        if (is_wp_error($shipment)) {
            return $shipment;
        }

        $response = $this->provider->get_rates(['shipment' => $shipment]);
        if (is_wp_error($response)) {
            return $response;
        }

        $rates = isset($response['rate_response']['rates']) && is_array($response['rate_response']['rates'])
            ? array_values($response['rate_response']['rates'])
            : [];
        $rate = $this->choose_rate($rates);
        if (!is_array($rate)) {
            return new WP_Error('fflhub_easypost_batch_no_rate', 'EasyPost returned no usable non-banned rate for this package.');
        }

        $raw = is_array($response['raw'] ?? null) ? $response['raw'] : [];
        $reference = substr('fflhub-' . (int) $order->get_id() . '-p' . ($package_index + 1) . '-' . time(), 0, 50);
        $batch_shipment = $this->batch_shipment_from_rate($raw, $rate, $reference);
        if (is_wp_error($batch_shipment)) {
            return $batch_shipment;
        }

        return [
            'order_id' => (int) $order->get_id(),
            'order_number' => (string) $order->get_order_number(),
            'package_index' => $package_index,
            'reference' => $reference,
            'package' => $package,
            'package_items' => $package_items,
            'shipment' => $shipment,
            'rated_shipment_id' => (string) ($response['shipment_id'] ?? $raw['id'] ?? ''),
            'rate' => $rate,
            'rate_request_id' => (string) ($response['_fflhub_request_id'] ?? ''),
            'batch_shipment' => $batch_shipment,
            'batch_shipment_id' => '',
            'batch_status' => 'prepared',
            'batch_message' => '',
            'tracking_number' => '',
            'label_saved' => false,
            'label_id' => '',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rates
     * @return array<string,mixed>|null
     */
    private function choose_rate(array $rates): ?array
    {
        $usable = [];
        foreach ($rates as $rate) {
            if (!is_array($rate) || ShippingOptions::rate_service_is_banned($rate)) {
                continue;
            }
            if ((string) ($rate['rate_id'] ?? '') === '' || (float) ($rate['total_amount'] ?? 0) <= 0.0) {
                continue;
            }
            $usable[] = $rate;
        }

        usort($usable, static function (array $a, array $b): int {
            $by_total = ((float) ($a['total_amount'] ?? 0)) <=> ((float) ($b['total_amount'] ?? 0));
            if ($by_total !== 0) {
                return $by_total;
            }
            return strcmp((string) ($a['service_type'] ?? ''), (string) ($b['service_type'] ?? ''));
        });

        return $usable[0] ?? null;
    }

    /**
     * @param array<string,mixed> $shipment
     * @param array<string,mixed> $rate
     * @return array<string,mixed>|WP_Error
     */
    private function batch_shipment_from_rate(array $shipment, array $rate, string $reference)
    {
        $from_id = (string) ($shipment['from_address']['id'] ?? '');
        $to_id = (string) ($shipment['to_address']['id'] ?? '');
        $parcel_id = (string) ($shipment['parcel']['id'] ?? '');
        $carrier = (string) ($rate['carrier_code'] ?? '');
        $service = (string) ($rate['service_code'] ?? '');
        $carrier_account = (string) ($rate['carrier_id'] ?? '');

        if ($from_id === '' || $to_id === '' || $parcel_id === '' || $carrier === '' || $service === '' || $carrier_account === '') {
            return new WP_Error(
                'fflhub_easypost_batch_incomplete_rate',
                'EasyPost rate did not include all fields needed for batch buying.'
            );
        }

        return [
            'reference' => $reference,
            'from_address' => ['id' => $from_id],
            'to_address' => ['id' => $to_id],
            'parcel' => ['id' => $parcel_id],
            'service' => $service,
            'carrier' => $carrier,
            'carrier_accounts' => [$carrier_account],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @param array<int,array<string,mixed>> $remote_shipments
     * @return array<int,array<string,mixed>>
     */
    private function merge_remote_shipments_into_items(array $items, array $remote_shipments): array
    {
        $by_reference = [];
        foreach ($remote_shipments as $remote) {
            if (!is_array($remote)) {
                continue;
            }
            $reference = (string) ($remote['reference'] ?? '');
            if ($reference !== '') {
                $by_reference[$reference] = $remote;
            }
        }

        foreach ($items as $index => &$item) {
            if (!is_array($item)) {
                continue;
            }

            $remote = $by_reference[(string) ($item['reference'] ?? '')] ?? ($remote_shipments[$index] ?? null);
            if (!is_array($remote)) {
                continue;
            }

            $item['batch_shipment_id'] = (string) ($remote['id'] ?? $item['batch_shipment_id'] ?? '');
            $item['batch_status'] = (string) ($remote['batch_status'] ?? $item['batch_status'] ?? '');
            $item['batch_message'] = (string) ($remote['batch_message'] ?? $item['batch_message'] ?? '');
            $item['tracking_number'] = (string) ($remote['tracking_code'] ?? $item['tracking_number'] ?? '');
        }
        unset($item);

        return $items;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array{items:array<int,array<string,mixed>>,labels_saved:int}
     */
    private function save_purchased_labels(array $items, string $provider_batch_id): array
    {
        $saved = 0;
        $package_counts = [];
        foreach ($items as $item) {
            $order_id = (int) ($item['order_id'] ?? 0);
            if ($order_id > 0) {
                $package_counts[$order_id] = (int) ($package_counts[$order_id] ?? 0) + 1;
            }
        }

        foreach ($items as &$item) {
            if (!is_array($item) || !empty($item['label_saved'])) {
                continue;
            }

            $status = strtolower(trim((string) ($item['batch_status'] ?? '')));
            if ($status !== 'postage_purchased') {
                continue;
            }

            $shipment_id = trim((string) ($item['batch_shipment_id'] ?? ''));
            if ($shipment_id === '') {
                continue;
            }

            $order = wc_get_order((int) ($item['order_id'] ?? 0));
            if (!($order instanceof WC_Order)) {
                continue;
            }

            $shipment = $this->client->retrieve_shipment($shipment_id);
            if (is_wp_error($shipment)) {
                $item['label_error'] = $shipment->get_error_message();
                continue;
            }

            $rate = is_array($item['rate'] ?? null) ? $item['rate'] : [];
            $api_label = $this->provider->normalize_purchased_shipment($shipment, (string) ($rate['rate_id'] ?? ''));
            $pending = [
                'shipment_snapshot' => is_array($item['shipment'] ?? null) ? $item['shipment'] : [],
                'shipment_id' => $shipment_id,
                'rate_request_id' => (string) ($item['rate_request_id'] ?? ''),
                'package_items' => [is_array($item['package_items'] ?? null) ? $item['package_items'] : []],
                'package_details' => [is_array($item['package'] ?? null) ? $item['package'] : []],
            ];
            $label = ShipStationOrderMeta::normalize_purchased_label(
                $api_label,
                $rate,
                $pending,
                (string) ($rate['rate_id'] ?? ''),
                (string) ($api_label['_fflhub_request_id'] ?? '')
            );
            $label['package_index'] = max(0, (int) ($item['package_index'] ?? 0));
            $label['package_count'] = max(1, (int) ($package_counts[(int) $order->get_id()] ?? 1));
            $label['easypost_batch_id'] = $provider_batch_id;
            $label['easypost_batch_reference'] = (string) ($item['reference'] ?? '');

            if ($this->order_has_label($order, (string) ($label['label_id'] ?? ''))) {
                $item['label_saved'] = true;
                $item['label_id'] = (string) ($label['label_id'] ?? '');
                continue;
            }

            ShipStationOrderMeta::append_label($order, $label);
            $order->add_order_note($this->purchase_note($label));
            $this->maybe_update_status($order);
            OrderProfitAuditMeta::recalculate_order($order, true);

            $item['label_saved'] = true;
            $item['label_id'] = (string) ($label['label_id'] ?? '');
            $saved++;
        }
        unset($item);

        return [
            'items' => $items,
            'labels_saved' => $saved,
        ];
    }

    /**
     * @param array<string,mixed> $remote
     */
    private function batch_has_purchased_shipments(array $remote): bool
    {
        $status = is_array($remote['status'] ?? null) ? $remote['status'] : [];
        if ((int) ($status['postage_purchased'] ?? 0) > 0) {
            return true;
        }

        foreach ((array) ($remote['shipments'] ?? []) as $shipment) {
            if (is_array($shipment) && (string) ($shipment['batch_status'] ?? '') === 'postage_purchased') {
                return true;
            }
        }

        return in_array(strtolower((string) ($remote['state'] ?? '')), ['purchased', 'label_generating', 'label_generated'], true);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function all_items_have_saved_labels(array $items): bool
    {
        if (empty($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (!is_array($item) || empty($item['label_saved'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function package_count_for_order(array $items, int $order_id): int
    {
        $count = 0;
        foreach ($items as $item) {
            if (is_array($item) && (int) ($item['order_id'] ?? 0) === $order_id) {
                $count++;
            }
        }

        return max(1, $count);
    }

    /**
     * @param array<string,mixed> $shipment
     */
    private function label_pdf_url_from_shipment(array $shipment): string
    {
        $postage_label = is_array($shipment['postage_label'] ?? null) ? $shipment['postage_label'] : [];
        $url = trim((string) ($postage_label['label_pdf_url'] ?? ''));
        if ($url !== '') {
            return $url;
        }

        $format = strtolower((string) ($postage_label['label_file_type'] ?? ''));
        if (strpos($format, 'pdf') !== false) {
            return trim((string) ($postage_label['label_url'] ?? ''));
        }

        return '';
    }

    private function order_has_label(WC_Order $order, string $label_id): bool
    {
        if ($label_id === '') {
            return false;
        }

        return ShipStationOrderMeta::find_label($order, $label_id) !== null;
    }

    /**
     * @param array<string,mixed> $label
     */
    private function purchase_note(array $label): string
    {
        $carrier = trim((string) ($label['carrier_nickname'] ?? $label['carrier_friendly_name'] ?? $label['carrier_code'] ?? 'EasyPost'));
        $service = trim((string) ($label['service_name'] ?? $label['service_code'] ?? ''));
        $tracking = trim((string) ($label['tracking_number'] ?? ''));
        $cost = trim((string) ($label['total_cost'] ?? '0.0000'));

        $parts = ["FFL Hub EasyPost batch label purchased via {$carrier}"];
        if ($service !== '') {
            $parts[] = "service {$service}";
        }
        if ($tracking !== '') {
            $parts[] = "tracking {$tracking}";
        }
        if ($cost !== '') {
            $parts[] = "cost {$cost}";
        }

        return implode(', ', $parts) . '.';
    }

    private function maybe_update_status(WC_Order $order): void
    {
        $status = ShipStationOptions::after_purchase_status();
        if ($status !== '') {
            $order->update_status($status, 'FFL Hub EasyPost batch shipping label purchased.');
        }
    }

    /**
     * @param array<string,mixed> $packing
     */
    private function messages_from_packing_result(array $packing): string
    {
        $messages = array_values(array_filter(array_map('strval', (array) ($packing['errors'] ?? []))));
        if ((int) ($packing['unpacked_item_count'] ?? 0) > 0) {
            $messages[] = (string) ((int) ($packing['unpacked_item_count'] ?? 0)) . ' item(s) could not be packed.';
        }

        return !empty($messages) ? implode(' ', $messages) : 'Order could not be packed.';
    }

    /**
     * @param array<string,mixed> $remote
     */
    private function batch_error_message(array $remote): string
    {
        $messages = [];
        foreach ((array) ($remote['shipments'] ?? []) as $shipment) {
            if (is_array($shipment) && trim((string) ($shipment['batch_message'] ?? '')) !== '') {
                $messages[] = trim((string) $shipment['batch_message']);
            }
        }

        return implode(' ', array_values(array_unique($messages)));
    }

    private function local_status_from_easypost(string $state): string
    {
        $state = strtolower(trim($state));
        return match ($state) {
            'creating', 'created' => EasyPostBatchLabelStore::STATUS_SUBMITTED,
            'purchasing' => EasyPostBatchLabelStore::STATUS_PURCHASING,
            'purchased' => EasyPostBatchLabelStore::STATUS_PURCHASED,
            'label_generating' => EasyPostBatchLabelStore::STATUS_LABEL_GENERATING,
            'label_generated' => EasyPostBatchLabelStore::STATUS_LABEL_GENERATED,
            'creation_failed', 'purchase_failed' => EasyPostBatchLabelStore::STATUS_FAILED,
            default => $state !== '' ? $state : EasyPostBatchLabelStore::STATUS_SUBMITTED,
        };
    }

    private function batch_reference(): string
    {
        return substr('fflhub-wms-' . gmdate('Ymd-His'), 0, 50);
    }

    private function elapsed_ms(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 2);
    }
}
