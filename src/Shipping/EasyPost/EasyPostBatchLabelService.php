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
use FFLHub\Shipping\ShippingProviderPolicy;
use FFLHub\Shipping\ShipOutdoors\ShipOutdoorsClient;
use FFLHub\Shipping\ShipOutdoors\ShipOutdoorsOptions;
use FFLHub\Shipping\ShipOutdoors\ShipOutdoorsShippingProvider;
use WC_Order;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prepares and submits provider-backed label batches for the WMS Sending queue.
 *
 * The per-order shipping UI remains the source of truth for shipment building:
 * this service uses ShipStationShipmentService to auto-pack, validate package
 * assignments, resolve FFL destinations, and build provider-neutral shipment
 * payloads. The batch-specific work is limited to choosing provider rates,
 * buying labels, and saving purchased labels back into the same order meta used
 * by single-label purchases.
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

    private function has_any_label_provider(): bool
    {
        return (EasyPostOptions::is_enabled() && $this->client->has_api_key())
            || ShipOutdoorsOptions::configured();
    }

    private function missing_label_provider_error(): WP_Error
    {
        return new WP_Error(
            'fflhub_shipping_batch_provider_missing',
            'Enable EasyPost or ShipOutdoors with an API key before preparing WMS label batches.'
        );
    }

    /**
     * @param array<int,array<string,mixed>> $ready_orders
     * @return array<string,mixed>|WP_Error
     */
    public function prepare_from_ready_orders(array $ready_orders)
    {
        if (!$this->has_any_label_provider()) {
            return $this->missing_label_provider_error();
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
                'fflhub_shipping_batch_no_items',
                'No ready order packages could be prepared for label purchase.',
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
     * Prepare an EasyPost local batch from packages that were already selected
     * by the Order Waver packing worker.
     *
     * This is intentionally separate from prepare_from_ready_orders(): the new
     * wave pipeline needs packing to be its own durable stage, then label buying
     * should use the exact package assignments that were stored for the wave.
     *
     * @param array<int,array<string,mixed>> $packed_orders
     * @return array<string,mixed>|WP_Error
     */
    public function prepare_from_packed_wave_orders(array $packed_orders, string $reference = '')
    {
        if (!$this->has_any_label_provider()) {
            return $this->missing_label_provider_error();
        }

        $started = microtime(true);
        $items = [];
        $problems = [];
        $orders_seen = 0;
        $orders_with_labels = 0;

        foreach ($packed_orders as $row) {
            if (!is_array($row)) {
                continue;
            }

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

            $packages = isset($row['packages']) && is_array($row['packages'])
                ? array_values($row['packages'])
                : [];
            $package_items = isset($row['package_items']) && is_array($row['package_items'])
                ? array_values($row['package_items'])
                : [];

            if (empty($packages)) {
                $problems[] = [
                    'order_id' => $order_id,
                    'order_number' => (string) $order->get_order_number(),
                    'message' => 'Wave order has no packed package assignments.',
                ];
                continue;
            }

            $order_items = [];
            $order_failed = false;
            foreach ($packages as $index => $package) {
                if (!is_array($package)) {
                    continue;
                }

                $item_assignments = is_array($package_items[$index] ?? null) ? $package_items[$index] : [];
                $prepared = $this->prepare_package($order, $package, $item_assignments, $index);
                if (is_wp_error($prepared)) {
                    $order_failed = true;
                    $problems[] = [
                        'order_id' => $order_id,
                        'order_number' => (string) $order->get_order_number(),
                        'package_index' => $index,
                        'message' => $prepared->get_error_message(),
                    ];
                    break;
                }

                $prepared['wave_batch_id'] = (int) ($row['batch_id'] ?? 0);
                $prepared['wave_order_row_id'] = (int) ($row['id'] ?? 0);
                $order_items[] = $prepared;
            }

            if (!$order_failed && !empty($order_items)) {
                array_push($items, ...$order_items);
            }
        }

        if (empty($items)) {
            return new WP_Error(
                'fflhub_shipping_batch_no_items',
                'No wave package assignments could be prepared for label purchase.',
                ['problems' => $problems]
            );
        }

        $reference = trim($reference) !== '' ? substr(sanitize_text_field($reference), 0, 120) : $this->batch_reference();
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

        if ($this->batch_needs_individual_provider_purchase($batch)) {
            return $this->buy_items_individually_for_provider_mix($local_batch_id, $batch);
        }

        if ($this->batch_needs_individual_signature_purchase($batch)) {
            return $this->buy_items_individually_for_signature($local_batch_id, $batch);
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

        $remote = $provider_batch_id !== '' ? $this->wait_for_buyable_batch($provider_batch_id) : null;
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

        $items = $this->merge_remote_shipments_into_items(
            is_array($batch['items'] ?? null) ? $batch['items'] : [],
            is_array($remote['shipments'] ?? null) ? $remote['shipments'] : []
        );
        if (!empty($items)) {
            EasyPostBatchLabelStore::update($local_batch_id, [
                'items_json' => $items,
                'response_json' => $remote,
                'status' => $this->local_status_from_easypost($remote_state),
                'label_url' => (string) ($remote['label_url'] ?? ''),
            ]);
            $batch = EasyPostBatchLabelStore::get($local_batch_id) ?? array_merge($batch, ['items' => $items]);
        }

        if ($remote_state === 'created') {
            $bought = $this->client->buy_batch($provider_batch_id);
            if (is_wp_error($bought)) {
                if ($this->is_batch_postage_not_allowed($bought)) {
                    return $this->buy_items_individually($local_batch_id, $batch, $provider_batch_id, $bought);
                }

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
        $documents = $this->print_documents($local_batch_id);
        if (is_wp_error($documents)) {
            return $documents;
        }

        $pdf_documents = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $body = (string) ($document['body'] ?? '');
            if ($body === '') {
                continue;
            }

            if (!empty($document['force_4x6'])) {
                $pdf_documents[] = [
                    'body' => $body,
                    'force_4x6' => true,
                ];
            } else {
                $pdf_documents[] = $body;
            }
        }

        return (new PdfDocumentService())->combine_with_options(
            $pdf_documents,
            'easypost-batch-' . $local_batch_id . '-labels-and-packing-slips.pdf'
        );
    }

    /**
     * @return array<int,array{title:string,body:string,force_4x6:bool,kind:string,order_id:int,order_number:string,package_index:int}>|WP_Error
     */
    public function print_documents(int $local_batch_id)
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

            $order = wc_get_order((int) ($item['order_id'] ?? 0));
            if (!($order instanceof WC_Order)) {
                continue;
            }

            $label_document = $this->label_print_document_for_item($order, $item);
            if (is_wp_error($label_document)) {
                continue;
            }

            $documents[] = $label_document;
            $this->append_packing_slip_document($documents, $slip_service, $order, $item, (array) ($batch['items'] ?? []));
        }

        if (empty($documents)) {
            return new WP_Error(
                'fflhub_easypost_batch_no_print_documents',
                'No purchased shipment label documents were available for this batch yet.'
            );
        }

        return $documents;
    }

    /**
     * Return only the shipping label and packing slip for one packed package in
     * an EasyPost wave. The WMS packing station uses this for package-by-package
     * PrintNode output so the operator can print exactly what they are packing,
     * instead of reprinting the whole wave.
     *
     * @return array<int,array{title:string,body:string,force_4x6:bool,kind:string,order_id:int,order_number:string,package_index:int}>|WP_Error
     */
    public function print_documents_for_package(int $local_batch_id, int $order_id, int $package_index)
    {
        $batch = EasyPostBatchLabelStore::get($local_batch_id);
        if (!is_array($batch)) {
            return new WP_Error('fflhub_easypost_batch_missing', 'Could not find that local EasyPost batch.');
        }

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            return new WP_Error('fflhub_easypost_batch_order_missing', 'Could not load the Woo order for that package.');
        }

        $documents = [];
        $slip_service = new PackingSlipService();
        $package_index = max(0, $package_index);
        foreach ((array) ($batch['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((int) ($item['order_id'] ?? 0) !== $order_id || max(0, (int) ($item['package_index'] ?? 0)) !== $package_index) {
                continue;
            }

            $label_document = $this->label_print_document_for_item($order, $item);
            if (is_wp_error($label_document)) {
                return $label_document;
            }

            $documents[] = $label_document;
            $this->append_packing_slip_document($documents, $slip_service, $order, $item, (array) ($batch['items'] ?? []));
            break;
        }

        if (empty($documents)) {
            return new WP_Error(
                'fflhub_easypost_package_no_print_documents',
                'No purchased shipment label document was available for that package yet.'
            );
        }

        return $documents;
    }

    /**
     * @param array<string,mixed> $item
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    private function download_direct_label_document(array $item, string $url)
    {
        $rate = is_array($item['rate'] ?? null) ? $item['rate'] : [];
        $provider_id = $this->provider_id_for_item($item, $rate);
        $document = $provider_id === 'shipoutdoors'
            ? (new ShipOutdoorsShippingProvider(new ShipOutdoorsClient()))->download_label($url)
            : $this->client->download_label($url);
        if (is_wp_error($document)) {
            return $document;
        }

        return $document;
    }

    /**
     * @param array<string,mixed> $item
     * @return array{title:string,body:string,force_4x6:bool,kind:string,order_id:int,order_number:string,package_index:int}|WP_Error
     */
    private function label_print_document_for_item(WC_Order $order, array $item)
    {
        $source = $this->label_document_source_for_item($order, $item);
        if (is_wp_error($source)) {
            return $source;
        }

        $document = $this->download_direct_label_document($item, (string) ($source['url'] ?? ''));
        if (is_wp_error($document)) {
            return $document;
        }

        $body = (string) ($document['body'] ?? '');
        $content_type = strtolower((string) ($document['content_type'] ?? ''));
        if ($this->document_is_pdf($body, $content_type)) {
            return $this->label_pdf_document($body, $order, $item);
        }

        if ($this->document_is_image($body, $content_type)) {
            $converted = (new PdfDocumentService())->image_to_four_by_six_pdf(
                $body,
                $content_type,
                sanitize_file_name($this->print_document_title('Shipping Label', $order, $item) . '.pdf')
            );
            if (is_wp_error($converted)) {
                return $converted;
            }

            return $this->label_pdf_document((string) ($converted['body'] ?? ''), $order, $item);
        }

        return new WP_Error(
            'fflhub_shipping_label_document_not_printable',
            'The purchased label is not available as a printable PDF or image document.'
        );
    }

    /**
     * @param array<string,mixed> $item
     * @return array{url:string,format:string}|WP_Error
     */
    private function label_document_source_for_item(WC_Order $order, array $item)
    {
        $source = $this->label_document_source_from_payload($item);
        if ($source['url'] !== '') {
            return $source;
        }

        $saved_label = $this->saved_order_label_for_item($order, $item);
        if (is_array($saved_label)) {
            $source = $this->label_document_source_from_payload($saved_label);
            if ($source['url'] !== '') {
                return $source;
            }
        }

        $shipment_id = trim((string) ($item['purchased_shipment_id'] ?? $item['batch_shipment_id'] ?? ''));
        if ($shipment_id !== '' && $this->provider_id_for_item($item, is_array($item['rate'] ?? null) ? $item['rate'] : []) === 'easypost') {
            $shipment = $this->client->retrieve_shipment($shipment_id);
            if (is_wp_error($shipment)) {
                return $shipment;
            }

            $source = $this->label_document_source_from_shipment($shipment);
            if ($source['url'] !== '') {
                return $source;
            }
        }

        return new WP_Error(
            'fflhub_shipping_label_document_missing',
            'The saved shipping label did not include a downloadable print document.'
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{url:string,format:string}
     */
    private function label_document_source_from_payload(array $payload): array
    {
        $downloads = is_array($payload['label_download'] ?? null) ? $payload['label_download'] : [];
        $format = strtolower((string) ($payload['label_format'] ?? ''));
        $legacy_pdf_url = trim((string) ($payload['label_pdf_url'] ?? ''));
        $label_url = trim((string) ($payload['label_url'] ?? ''));
        $label_document_url = trim((string) ($payload['label_document_url'] ?? ''));

        $candidates = [];
        if (trim((string) ($downloads['pdf'] ?? '')) !== '') {
            $candidates[] = ['url' => trim((string) $downloads['pdf']), 'format' => 'pdf'];
        }
        if ($format !== '' && trim((string) ($downloads[$format] ?? '')) !== '') {
            $candidates[] = ['url' => trim((string) $downloads[$format]), 'format' => $format];
        }
        if ($legacy_pdf_url !== '') {
            $candidates[] = ['url' => $legacy_pdf_url, 'format' => ''];
        }
        if ($label_url !== '') {
            $candidates[] = ['url' => $label_url, 'format' => $format];
        }
        if ($label_document_url !== '') {
            $candidates[] = ['url' => $label_document_url, 'format' => $format];
        }
        foreach (['png', 'jpg', 'jpeg', 'gif', 'zpl', 'epl2', 'href'] as $key) {
            if (trim((string) ($downloads[$key] ?? '')) !== '') {
                $candidates[] = ['url' => trim((string) $downloads[$key]), 'format' => $key === 'href' ? $format : $key];
            }
        }

        foreach ($candidates as $candidate) {
            if ((string) ($candidate['url'] ?? '') !== '') {
                return [
                    'url' => (string) $candidate['url'],
                    'format' => (string) ($candidate['format'] ?? ''),
                ];
            }
        }

        return ['url' => '', 'format' => ''];
    }

    /**
     * @param array<string,mixed> $shipment
     * @return array{url:string,format:string}
     */
    private function label_document_source_from_shipment(array $shipment): array
    {
        $postage_label = is_array($shipment['postage_label'] ?? null) ? $shipment['postage_label'] : [];
        $format = strtolower((string) ($postage_label['label_file_type'] ?? ''));
        if (trim((string) ($postage_label['label_pdf_url'] ?? '')) !== '') {
            return ['url' => trim((string) $postage_label['label_pdf_url']), 'format' => 'pdf'];
        }
        if (trim((string) ($postage_label['label_url'] ?? '')) !== '') {
            return ['url' => trim((string) $postage_label['label_url']), 'format' => $format];
        }
        if (trim((string) ($postage_label['label_zpl_url'] ?? '')) !== '') {
            return ['url' => trim((string) $postage_label['label_zpl_url']), 'format' => 'zpl'];
        }

        return ['url' => '', 'format' => ''];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null
     */
    private function saved_order_label_for_item(WC_Order $order, array $item): ?array
    {
        $label_id = trim((string) ($item['label_id'] ?? ''));
        if ($label_id !== '') {
            $label = ShipStationOrderMeta::find_label($order, $label_id);
            if (is_array($label)) {
                return $label;
            }
        }

        $package_index = max(0, (int) ($item['package_index'] ?? 0));
        $batch_id = trim((string) ($item['easypost_batch_id'] ?? $item['label_batch_id'] ?? ''));
        $reference = trim((string) ($item['reference'] ?? ''));
        $tracking = trim((string) ($item['tracking_number'] ?? ''));
        foreach (ShipStationOrderMeta::labels($order) as $label) {
            if (!is_array($label) || !ShipStationOrderMeta::label_is_active($label)) {
                continue;
            }
            if (max(0, (int) ($label['package_index'] ?? 0)) !== $package_index) {
                continue;
            }
            if ($batch_id !== '' && in_array($batch_id, [(string) ($label['easypost_batch_id'] ?? ''), (string) ($label['label_batch_id'] ?? '')], true)) {
                return ShipStationOrderMeta::find_label($order, (string) ($label['label_id'] ?? '')) ?? $label;
            }
            if ($reference !== '' && $reference === (string) ($label['easypost_batch_reference'] ?? $label['label_batch_reference'] ?? '')) {
                return ShipStationOrderMeta::find_label($order, (string) ($label['label_id'] ?? '')) ?? $label;
            }
            if ($tracking !== '' && $tracking === (string) ($label['tracking_number'] ?? $label['tracking'] ?? '')) {
                return ShipStationOrderMeta::find_label($order, (string) ($label['label_id'] ?? '')) ?? $label;
            }
        }

        return null;
    }

    private function document_is_pdf(string $body, string $content_type): bool
    {
        return strpos(ltrim($body), '%PDF') === 0 || str_contains($content_type, 'pdf');
    }

    private function document_is_image(string $body, string $content_type): bool
    {
        if (str_contains($content_type, 'image/')) {
            return true;
        }

        return is_array(@getimagesizefromstring($body));
    }

    /**
     * @param array<int,array{title:string,body:string,force_4x6:bool,kind:string,order_id:int,order_number:string,package_index:int}> $documents
     * @param array<string,mixed> $item
     * @param array<int,array<string,mixed>> $batch_items
     */
    private function append_packing_slip_document(
        array &$documents,
        PackingSlipService $slip_service,
        WC_Order $order,
        array $item,
        array $batch_items
    ): void {
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
                'package_count' => $this->package_count_for_order($batch_items, (int) $order->get_id()),
            ]
        );
        if (!is_wp_error($slip)) {
            $documents[] = [
                'title' => $this->print_document_title('Packing Slip', $order, $item),
                'body' => (string) ($slip['body'] ?? ''),
                'force_4x6' => false,
                'kind' => 'packing_slip',
                'order_id' => (int) $order->get_id(),
                'order_number' => (string) $order->get_order_number(),
                'package_index' => (int) ($item['package_index'] ?? 0),
            ];
        }
    }

    /**
     * @param array<string,mixed> $item
     * @return array{title:string,body:string,force_4x6:bool,kind:string,order_id:int,order_number:string,package_index:int}
     */
    private function label_pdf_document(string $body, WC_Order $order, array $item): array
    {
        return [
            'title' => $this->print_document_title('Shipping Label', $order, $item),
            'body' => $body,
            'force_4x6' => true,
            'kind' => 'label',
            'order_id' => (int) $order->get_id(),
            'order_number' => (string) $order->get_order_number(),
            'package_index' => (int) ($item['package_index'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $item
     */
    private function print_document_title(string $kind, WC_Order $order, array $item): string
    {
        $package_index = ((int) ($item['package_index'] ?? 0)) + 1;

        return sprintf(
            'FFL Hub %s - Order %s - Package %d',
            $kind,
            (string) $order->get_order_number(),
            $package_index
        );
    }

    /**
     * @param array<string,mixed> $package
     * @param array<int,array<string,mixed>> $package_items
     * @return array<string,mixed>|WP_Error
     */
    private function prepare_package(WC_Order $order, array $package, array $package_items, int $package_index)
    {
        $package_requires_ffl = ShippingProviderPolicy::package_items_require_ffl($package_items);
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

        $candidates = [];
        $errors = [];

        // EasyPost is still valid for FFL packages when the chosen rate is a
        // USPS rate. choose_rate() filters ordinary EasyPost UPS/FedEx
        // firearm rates below, so we can compare USPS against ShipOutdoors UPS
        // instead of blindly forcing every FFL package to ShipOutdoors.
        if (EasyPostOptions::is_enabled() && $this->client->has_api_key()) {
            $prepared = $this->prepare_easypost_package($order, $shipment, $package, $package_items, $package_index, $package_requires_ffl);
            if (is_wp_error($prepared)) {
                $errors[] = 'EasyPost: ' . $prepared->get_error_message();
            } else {
                $candidates[] = $prepared;
            }
        } elseif ($package_requires_ffl || !ShipOutdoorsOptions::configured()) {
            $errors[] = $package_requires_ffl
                ? 'EasyPost: not enabled or configured for USPS FFL rating.'
                : 'EasyPost: not enabled or configured.';
        }

        // ShipOutdoors rates both FFL and non-FFL packages. It is no longer the
        // automatic FFL winner; the prepared package with the lowest valid rate
        // wins, with EasyPost only winning exact ties.
        if (ShipOutdoorsOptions::configured()) {
            $prepared = $this->prepare_shipoutdoors_package($order, $shipment, $package, $package_items, $package_index, $package_requires_ffl);
            if (is_wp_error($prepared)) {
                $errors[] = 'ShipOutdoors: ' . $prepared->get_error_message();
            } else {
                $candidates[] = $prepared;
            }
        }

        if (empty($candidates)) {
            return new WP_Error(
                'fflhub_shipping_batch_no_provider_rate',
                implode(' ', $errors) ?: 'No enabled shipping provider returned a usable rate for this package.'
            );
        }

        return $this->cheapest_prepared_package($candidates);
    }

    /**
     * @param array<string,mixed> $shipment
     * @param array<string,mixed> $package
     * @param array<int,array<string,mixed>> $package_items
     * @return array<string,mixed>|WP_Error
     */
    private function prepare_easypost_package(
        WC_Order $order,
        array $shipment,
        array $package,
        array $package_items,
        int $package_index,
        bool $package_requires_ffl
    ) {
        $shipment_for_rates = self::easypost_shipment_for_rate_request($shipment, $package_requires_ffl);
        $response = $this->provider->get_rates(['shipment' => $shipment_for_rates]);
        if (is_wp_error($response)) {
            return $response;
        }

        $rates = isset($response['rate_response']['rates']) && is_array($response['rate_response']['rates'])
            ? array_values($response['rate_response']['rates'])
            : [];
        $rate = $this->choose_rate($rates, $package_requires_ffl);
        if (!is_array($rate)) {
            return new WP_Error(
                'fflhub_easypost_batch_no_rate',
                $package_requires_ffl
                    ? 'EasyPost returned no usable non-banned, non-UPS/FedEx rate for this FFL package.'
                    : 'EasyPost returned no usable non-banned rate for this package.'
            );
        }

        $raw = is_array($response['raw'] ?? null) ? $response['raw'] : [];
        $reference = substr('fflhub-' . (int) $order->get_id() . '-p' . ($package_index + 1) . '-' . time(), 0, 50);
        $batch_shipment = $this->batch_shipment_from_rate(
            $raw,
            $rate,
            $reference,
            (string) ($shipment_for_rates['confirmation'] ?? EasyPostOptions::confirmation())
        );
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
            'shipment' => $shipment_for_rates,
            'rated_shipment_id' => (string) ($response['shipment_id'] ?? $raw['id'] ?? ''),
            'rate' => $rate,
            'rate_request_id' => (string) ($response['_fflhub_request_id'] ?? ''),
            'provider_id' => 'easypost',
            'provider_label' => 'EasyPost',
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
     * @param array<string,mixed> $shipment
     * @param array<string,mixed> $package
     * @param array<int,array<string,mixed>> $package_items
     * @return array<string,mixed>|WP_Error
     */
    private function prepare_shipoutdoors_package(
        WC_Order $order,
        array $shipment,
        array $package,
        array $package_items,
        int $package_index,
        bool $package_requires_ffl
    ) {
        $provider = new ShipOutdoorsShippingProvider(new ShipOutdoorsClient());
        $response = $provider->get_rates([
            'shipment' => $shipment,
            'package_items' => [$package_items],
        ]);
        if (is_wp_error($response)) {
            return $response;
        }

        $rates = isset($response['rate_response']['rates']) && is_array($response['rate_response']['rates'])
            ? array_values($response['rate_response']['rates'])
            : [];
        $rate = $this->choose_rate($rates, $package_requires_ffl);
        if (!is_array($rate)) {
            return new WP_Error(
                'fflhub_shipoutdoors_batch_no_rate',
                $package_requires_ffl
                    ? 'ShipOutdoors returned no usable non-banned UPS firearm rate for this package.'
                    : 'ShipOutdoors returned no usable non-banned UPS rate for this package.'
            );
        }

        $reference = substr('fflhub-' . (int) $order->get_id() . '-p' . ($package_index + 1) . '-' . time(), 0, 50);

        return [
            'provider_id' => 'shipoutdoors',
            'provider_label' => 'ShipOutdoors',
            'order_id' => (int) $order->get_id(),
            'order_number' => (string) $order->get_order_number(),
            'package_index' => $package_index,
            'reference' => $reference,
            'package' => $package,
            'package_items' => $package_items,
            'shipment' => $shipment,
            'rated_shipment_id' => (string) ($response['shipment_id'] ?? ''),
            'rate' => $rate,
            'rate_request_id' => (string) ($response['_fflhub_request_id'] ?? ''),
            'batch_shipment' => [],
            'batch_shipment_id' => '',
            'batch_status' => 'prepared',
            'batch_message' => '',
            'tracking_number' => '',
            'label_saved' => false,
            'label_id' => '',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    private function cheapest_prepared_package(array $candidates): array
    {
        usort($candidates, static function (array $a, array $b): int {
            $a_rate = is_array($a['rate'] ?? null) ? $a['rate'] : [];
            $b_rate = is_array($b['rate'] ?? null) ? $b['rate'] : [];

            $by_total = ((float) ($a_rate['total_amount'] ?? 0.0)) <=> ((float) ($b_rate['total_amount'] ?? 0.0));
            if ($by_total !== 0) {
                return $by_total;
            }

            // Exact ties stay on EasyPost when possible, because that preserves
            // the normal EasyPost batch purchase path.
            $a_provider = (string) ($a['provider_id'] ?? $a_rate['provider_id'] ?? '');
            $b_provider = (string) ($b['provider_id'] ?? $b_rate['provider_id'] ?? '');
            if ($a_provider !== $b_provider) {
                if ($a_provider === 'easypost') {
                    return -1;
                }
                if ($b_provider === 'easypost') {
                    return 1;
                }
            }

            return strcmp((string) ($a_rate['service_type'] ?? ''), (string) ($b_rate['service_type'] ?? ''));
        });

        return $candidates[0];
    }

    /**
     * @param array<int,array<string,mixed>> $rates
     * @return array<string,mixed>|null
     */
    private function choose_rate(array $rates, bool $package_requires_ffl = false): ?array
    {
        $usable = [];
        foreach ($rates as $rate) {
            if (!is_array($rate) || ShippingOptions::rate_service_is_banned($rate)) {
                continue;
            }
            if ($package_requires_ffl && ShippingProviderPolicy::rate_is_blocked_for_ffl_package($rate)) {
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
     * EasyPost USPS can still be compared for FFL packages, but USPS should not
     * receive a signature add-on. EasyPost UPS/FedEx firearm rates are filtered
     * before selection, and ShipOutdoors owns firearm-capable UPS labels.
     *
     * @param array<string,mixed> $shipment
     * @return array<string,mixed>
     */
    private static function easypost_shipment_for_rate_request(array $shipment, bool $package_requires_ffl): array
    {
        if ($package_requires_ffl) {
            $shipment['confirmation'] = 'delivery';
        }

        return $shipment;
    }

    /**
     * @param array<string,mixed> $shipment
     * @param array<string,mixed> $rate
     */
    private static function easypost_confirmation_for_rate(array $shipment, array $rate): string
    {
        if (EasyPostShippingProvider::rate_is_usps($rate)) {
            return 'delivery';
        }

        return (string) ($shipment['confirmation'] ?? EasyPostOptions::confirmation());
    }

    /**
     * @param array<string,mixed> $shipment
     * @param array<string,mixed> $rate
     * @return array<string,mixed>|WP_Error
     */
    private function batch_shipment_from_rate(array $shipment, array $rate, string $reference, string $confirmation)
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

        $batch_shipment = [
            'reference' => $reference,
            'from_address' => ['id' => $from_id],
            'to_address' => ['id' => $to_id],
            'parcel' => ['id' => $parcel_id],
            'service' => $service,
            'carrier' => $carrier,
            'carrier_accounts' => [$carrier_account],
        ];
        $delivery_confirmation = EasyPostShippingProvider::delivery_confirmation_option(
            self::easypost_confirmation_for_rate(['confirmation' => $confirmation], $rate)
        );
        if ($delivery_confirmation !== 'NO_SIGNATURE') {
            $batch_shipment['options'] = [
                'delivery_confirmation' => $delivery_confirmation,
            ];
        }

        return $batch_shipment;
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
            $label['label_batch_id'] = $provider_batch_id;
            $label['label_batch_reference'] = (string) ($item['reference'] ?? '');
            $label['easypost_batch_id'] = $provider_batch_id;
            $label['easypost_batch_reference'] = (string) ($item['reference'] ?? '');
            $this->copy_label_document_fields_to_item($item, $api_label, $label);

            if ($this->order_has_label($order, (string) ($label['label_id'] ?? ''))) {
                $item['label_saved'] = true;
                $item['label_id'] = (string) ($label['label_id'] ?? '');
                $item['tracking_number'] = (string) ($label['tracking_number'] ?? $item['tracking_number'] ?? '');
                $item['purchased_shipment_id'] = $shipment_id;
                continue;
            }

            ShipStationOrderMeta::append_label($order, $label);
            $order->add_order_note($this->purchase_note($label));
            $this->maybe_update_status($order);
            OrderProfitAuditMeta::recalculate_order($order, true);

            $item['label_saved'] = true;
            $item['label_id'] = (string) ($label['label_id'] ?? '');
            $item['tracking_number'] = (string) ($label['tracking_number'] ?? $item['tracking_number'] ?? '');
            $item['purchased_shipment_id'] = $shipment_id;
            $saved++;
        }
        unset($item);

        return [
            'items' => $items,
            'labels_saved' => $saved,
        ];
    }

    /**
     * EasyPost can allow batch creation while refusing the batch-wide postage
     * purchase for account/postage-risk reasons. In that case the prepared
     * packages are still valid EasyPost Shipments with selected rates, so we can
     * buy those shipments one by one and keep the WMS print packet flow moving.
     *
     * @param array<string,mixed> $batch
     * @return array<string,mixed>|WP_Error
     */
    private function buy_items_individually(int $local_batch_id, array $batch, string $provider_batch_id, WP_Error $batch_error)
    {
        return $this->buy_items_individually_with_reason(
            $local_batch_id,
            $batch,
            $provider_batch_id !== '' ? $provider_batch_id : 'individual-' . $local_batch_id,
            'EasyPost refused batch postage purchase; individual shipment fallback was used.',
            'Purchased individually after EasyPost refused batch postage purchase.',
            [
                'fallback' => 'individual_shipments',
                'batch_purchase_error' => [
                    'message' => $batch_error->get_error_message(),
                    'data' => $batch_error->get_error_data(),
                ],
            ]
        );
    }

    /**
     * EasyPost batch shipment copies can lose delivery-confirmation semantics.
     * Non-USPS packages that still request confirmation are purchased directly
     * so EasyPost receives that option on the shipment buy request.
     *
     * @param array<string,mixed> $batch
     * @return array<string,mixed>|WP_Error
     */
    private function buy_items_individually_for_signature(int $local_batch_id, array $batch)
    {
        return $this->buy_items_individually_with_reason(
            $local_batch_id,
            $batch,
            'individual-' . $local_batch_id,
            'Signature-required non-USPS packages are purchased individually so EasyPost receives delivery confirmation on the shipment buy request.',
            'Purchased individually because this wave contains a signature-required non-USPS package.',
            [
                'fallback' => 'individual_shipments',
                'reason' => 'signature_required',
            ]
        );
    }

    /**
     * ShipOutdoors is a direct firearm-label purchase API, not an EasyPost
     * Shipment or Batch. Any wave containing those packages bypasses EasyPost
     * batch creation and buys each prepared package with its own provider.
     *
     * @param array<string,mixed> $batch
     * @return array<string,mixed>|WP_Error
     */
    private function buy_items_individually_for_provider_mix(int $local_batch_id, array $batch)
    {
        return $this->buy_items_individually_with_reason(
            $local_batch_id,
            $batch,
            'individual-' . $local_batch_id,
            'Mixed-provider packages are purchased individually so each prepared package uses the provider it was rated with.',
            'Purchased individually with the prepared shipping provider.',
            [
                'fallback' => 'individual_shipments',
                'reason' => 'provider_mix',
            ]
        );
    }

    /**
     * @param array<string,mixed> $batch
     * @param array<string,mixed> $response_context
     * @return array<string,mixed>|WP_Error
     */
    private function buy_items_individually_with_reason(
        int $local_batch_id,
        array $batch,
        string $provider_batch_id,
        string $error_message_prefix,
        string $item_success_message,
        array $response_context
    ) {
        $items = is_array($batch['items'] ?? null) ? $batch['items'] : [];
        if (empty($items)) {
            return new WP_Error(
                'fflhub_easypost_individual_empty_batch',
                'No prepared EasyPost package items were available to buy individually.'
            );
        }

        $saved = 0;
        $errors = [];
        $package_counts = [];
        foreach ($items as $item) {
            $order_id = (int) (is_array($item) ? ($item['order_id'] ?? 0) : 0);
            if ($order_id > 0) {
                $package_counts[$order_id] = (int) ($package_counts[$order_id] ?? 0) + 1;
            }
        }

        foreach ($items as &$item) {
            if (!is_array($item) || !empty($item['label_saved'])) {
                continue;
            }

            $order = wc_get_order((int) ($item['order_id'] ?? 0));
            if (!($order instanceof WC_Order)) {
                $item['label_error'] = 'Woo order could not be loaded.';
                $errors[] = '#' . (string) ($item['order_number'] ?? $item['order_id'] ?? '-') . ': Woo order could not be loaded.';
                continue;
            }

            $rate = is_array($item['rate'] ?? null) ? $item['rate'] : [];
            $provider_id = $this->provider_id_for_item($item, $rate);
            $rate_id = trim((string) ($rate['rate_id'] ?? ''));
            if ($rate_id === '') {
                $item['label_error'] = 'Prepared shipment rate ID was missing.';
                $errors[] = '#' . (string) $order->get_order_number() . ': Prepared shipment rate ID was missing.';
                continue;
            }

            if ($provider_id === 'shipoutdoors') {
                $api_label = (new ShipOutdoorsShippingProvider(new ShipOutdoorsClient()))->purchase_label_from_rate($rate_id, [
                    'shipment' => is_array($item['shipment'] ?? null) ? $item['shipment'] : [],
                    'rated' => $rate,
                    'package_items' => [is_array($item['package_items'] ?? null) ? $item['package_items'] : []],
                ]);
            } else {
                $shipment_id = trim((string) ($rate['shipment_id'] ?? $item['rated_shipment_id'] ?? ''));
                if ($shipment_id === '') {
                    $item['label_error'] = 'Prepared EasyPost shipment/rate ID was missing.';
                    $errors[] = '#' . (string) $order->get_order_number() . ': Prepared EasyPost shipment/rate ID was missing.';
                    continue;
                }

                $api_label = $this->provider->purchase_label_from_rate($rate_id, [
                    'shipment_id' => $shipment_id,
                    'label_format' => EasyPostOptions::label_format(),
                    'label_layout' => EasyPostOptions::label_layout(),
                    'confirmation' => self::easypost_confirmation_for_rate(
                        is_array($item['shipment'] ?? null) ? $item['shipment'] : [],
                        $rate
                    ),
                    'rate' => $rate,
                ]);
            }
            if (is_wp_error($api_label)) {
                $item['label_error'] = $api_label->get_error_message();
                $errors[] = '#' . (string) $order->get_order_number() . ': ' . $api_label->get_error_message();
                continue;
            }

            if ($this->save_api_label_for_item(
                $item,
                $order,
                (array) $api_label,
                $rate,
                $provider_batch_id,
                max(1, (int) ($package_counts[(int) $order->get_id()] ?? 1))
            )) {
                $item['batch_status'] = 'postage_purchased';
                $item['batch_message'] = $item_success_message;
                $item['batch_purchase_fallback'] = true;
                $saved++;
            }
        }
        unset($item);

        $status = $this->all_items_have_saved_labels($items)
            ? EasyPostBatchLabelStore::STATUS_LABELS_SAVED
            : ($saved > 0 ? EasyPostBatchLabelStore::STATUS_PARTIAL_LABELS_SAVED : EasyPostBatchLabelStore::STATUS_FAILED);
        $error_message = $error_message_prefix;
        if (!empty($errors)) {
            $error_message .= ' ' . implode(' ', array_slice($errors, 0, 6));
        }

        $response_context['labels_saved'] = $saved;
        $response_context['errors'] = $errors;

        EasyPostBatchLabelStore::update($local_batch_id, [
            'provider_batch_id' => $provider_batch_id,
            'status' => $status,
            'items_json' => $items,
            'response_json' => $response_context,
            'error_message' => $error_message,
        ]);

        return [
            'batch' => EasyPostBatchLabelStore::get($local_batch_id),
            'labels_saved' => $saved,
            'fallback' => 'individual_shipments',
            'message' => $error_message_prefix,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string,mixed> $batch
     */
    private function batch_needs_individual_signature_purchase(array $batch): bool
    {
        foreach ((array) ($batch['items'] ?? []) as $item) {
            if (!is_array($item) || !empty($item['label_saved'])) {
                continue;
            }

            $rate = is_array($item['rate'] ?? null) ? $item['rate'] : [];
            if ($this->provider_id_for_item($item, $rate) !== 'easypost') {
                continue;
            }
            if (EasyPostShippingProvider::rate_is_usps($rate)) {
                continue;
            }

            $confirmation = self::easypost_confirmation_for_rate(
                is_array($item['shipment'] ?? null) ? $item['shipment'] : [],
                $rate
            );
            if (EasyPostShippingProvider::delivery_confirmation_option($confirmation) !== 'NO_SIGNATURE') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $batch
     */
    private function batch_needs_individual_provider_purchase(array $batch): bool
    {
        foreach ((array) ($batch['items'] ?? []) as $item) {
            if (!is_array($item) || !empty($item['label_saved'])) {
                continue;
            }

            $rate = is_array($item['rate'] ?? null) ? $item['rate'] : [];
            if ($this->provider_id_for_item($item, $rate) !== 'easypost') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $rate
     */
    private function provider_id_for_item(array $item, array $rate = []): string
    {
        $provider_id = sanitize_key((string) ($item['provider_id'] ?? $rate['provider_id'] ?? 'easypost'));
        return $provider_id !== '' ? $provider_id : 'easypost';
    }

    /**
     * @param array<string,mixed> $api_label
     * @param array<string,mixed> $rate
     */
    private function save_api_label_for_item(
        array &$item,
        WC_Order $order,
        array $api_label,
        array $rate,
        string $provider_batch_id,
        int $package_count
    ): bool {
        $pending = [
            'shipment_snapshot' => is_array($item['shipment'] ?? null) ? $item['shipment'] : [],
            'shipment_id' => (string) ($api_label['shipment_id'] ?? $item['batch_shipment_id'] ?? $item['rated_shipment_id'] ?? ''),
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
        $label['package_count'] = max(1, $package_count);
        $label['label_batch_id'] = $provider_batch_id;
        $label['label_batch_reference'] = (string) ($item['reference'] ?? '');
        $label['easypost_batch_id'] = $provider_batch_id;
        $label['easypost_batch_reference'] = (string) ($item['reference'] ?? '');
        $this->copy_label_document_fields_to_item($item, $api_label, $label);

        if ($this->order_has_label($order, (string) ($label['label_id'] ?? ''))) {
            $item['label_saved'] = true;
            $item['label_id'] = (string) ($label['label_id'] ?? '');
            $item['tracking_number'] = (string) ($label['tracking_number'] ?? $item['tracking_number'] ?? '');
            $item['purchased_shipment_id'] = (string) ($api_label['shipment_id'] ?? $pending['shipment_id'] ?? '');
            return false;
        }

        ShipStationOrderMeta::append_label($order, $label);
        $order->add_order_note($this->purchase_note($label));
        $this->maybe_update_status($order);
        OrderProfitAuditMeta::recalculate_order($order, true);

        $item['label_saved'] = true;
        $item['label_id'] = (string) ($label['label_id'] ?? '');
        $item['tracking_number'] = (string) ($label['tracking_number'] ?? $item['tracking_number'] ?? '');
        $item['purchased_shipment_id'] = (string) ($api_label['shipment_id'] ?? $pending['shipment_id'] ?? '');

        return true;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $api_label
     * @param array<string,mixed> $label
     */
    private function copy_label_document_fields_to_item(array &$item, array $api_label, array $label): void
    {
        $downloads = is_array($api_label['label_download'] ?? null)
            ? $api_label['label_download']
            : (is_array($label['label_download'] ?? null) ? $label['label_download'] : []);
        $format = strtolower((string) ($api_label['label_format'] ?? $label['label_format'] ?? ''));
        $label_url = trim((string) ($api_label['label_url'] ?? $label['label_url'] ?? ''));
        if ($format !== '' && trim((string) ($downloads[$format] ?? '')) !== '') {
            $label_url = trim((string) $downloads[$format]);
        }
        if ($label_url === '' && trim((string) ($downloads['href'] ?? '')) !== '') {
            $label_url = trim((string) $downloads['href']);
        }

        $item['label_format'] = $format;
        $item['label_layout'] = (string) ($api_label['label_layout'] ?? $label['label_layout'] ?? '');
        $item['label_url'] = $label_url;
        $item['label_document_url'] = $label_url;
        $item['label_download'] = $downloads;
        $item['label_pdf_url'] = trim((string) ($downloads['pdf'] ?? ''));
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
     * EasyPost batches are asynchronous. After creation they often report
     * "creating" briefly before they become "created" and are allowed to be
     * bought. Submit/Buy should feel like one action, so we wait a short window
     * here instead of making the admin click Refresh and Submit/Buy again.
     *
     * @return array<string,mixed>|WP_Error
     */
    private function wait_for_buyable_batch(string $provider_batch_id)
    {
        $remote = null;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $remote = $this->client->retrieve_batch($provider_batch_id);
            if (is_wp_error($remote)) {
                return $remote;
            }

            $state = strtolower(trim((string) ($remote['state'] ?? '')));
            if (in_array($state, ['created', 'purchased', 'label_generating', 'label_generated', 'creation_failed', 'purchase_failed'], true)) {
                return $remote;
            }

            sleep(2);
        }

        return is_array($remote) ? $remote : $this->client->retrieve_batch($provider_batch_id);
    }

    private function is_batch_postage_not_allowed(WP_Error $error): bool
    {
        $data = $error->get_error_data();
        $raw = is_array($data) ? (string) ($data['raw_response'] ?? '') : '';

        return stripos($raw, 'BATCH.POSTAGE.NOT_ALLOWED') !== false
            || stripos($error->get_error_message(), 'BATCH.POSTAGE.NOT_ALLOWED') !== false;
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
        $provider = trim((string) ($label['provider_label'] ?? 'Shipping provider'));
        $carrier = trim((string) ($label['carrier_nickname'] ?? $label['carrier_friendly_name'] ?? $label['carrier_code'] ?? $provider));
        $service = trim((string) ($label['service_name'] ?? $label['service_code'] ?? ''));
        $tracking = trim((string) ($label['tracking_number'] ?? ''));
        $cost = trim((string) ($label['total_cost'] ?? '0.0000'));

        $parts = ["FFL Hub {$provider} batch label purchased via {$carrier}"];
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
            $order->update_status($status, 'FFL Hub batch shipping label purchased.');
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
            'creating' => EasyPostBatchLabelStore::STATUS_SUBMITTED,
            'created' => EasyPostBatchLabelStore::STATUS_READY_TO_BUY,
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
