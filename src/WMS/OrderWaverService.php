<?php
declare(strict_types=1);

namespace FFLHub\WMS;

use FFLHub\FFL\Tables\FFLSchema;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Order\OrderProfitAuditMeta;
use FFLHub\Receiving\ReceivingEventsStore;
use FFLHub\Shipping\DTO\ShippingPackage;
use FFLHub\Shipping\EasyPost\EasyPostBatchLabelService;
use FFLHub\Shipping\EasyPost\EasyPostBatchLabelStore;
use FFLHub\Shipping\Packing\PackingSlipService;
use FFLHub\Shipping\ShipStation\ShipStationOrderMeta;
use FFLHub\Shipping\ShipStation\ShipStationShipmentService;
use WC_Order;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates the new asynchronous Order Waver pipeline.
 *
 * The admin page only creates waves. This service then lets minute-based
 * Action Scheduler workers do the expensive/risky pieces in small, logged
 * units:
 * 1. pack each order and generate packing slips;
 * 2. prepare/buy/save EasyPost labels for the successfully packed batch.
 */
final class OrderWaverService
{
    /**
     * @param array<int,array<string,mixed>> $ready_orders
     * @param int[] $selected_order_ids
     * @return array<string,mixed>|WP_Error
     */
    public function create_wave(array $ready_orders, array $selected_order_ids, int $created_by = 0)
    {
        OrderWaverStore::ensure_schema();

        $selected = array_fill_keys(array_map('intval', $selected_order_ids), true);
        if (empty($selected)) {
            return new WP_Error('fflhub_order_waver_no_selection', 'Select at least one ready order to wave.');
        }

        $active = OrderWaverStore::active_order_rows_by_order_id();
        $orders = [];
        $skipped = [];

        foreach ($ready_orders as $row) {
            if (!is_array($row)) {
                continue;
            }

            $order_id = (int) ($row['order_id'] ?? 0);
            if ($order_id <= 0 || !isset($selected[$order_id])) {
                continue;
            }

            if (!empty($row['has_active_label'])) {
                $skipped[] = [
                    'order_id' => $order_id,
                    'order_number' => (string) ($row['order_number'] ?? $order_id),
                    'message' => 'Order already has an active shipping label.',
                ];
                continue;
            }

            if (isset($active[$order_id])) {
                $skipped[] = [
                    'order_id' => $order_id,
                    'order_number' => (string) ($row['order_number'] ?? $order_id),
                    'message' => 'Order is already in an active wave.',
                ];
                continue;
            }

            $orders[] = [
                'order_id' => $order_id,
                'order_number' => (string) ($row['order_number'] ?? $order_id),
                'debug_ready' => !empty($row['debug_ready']) ? 1 : 0,
            ];
        }

        if (empty($orders)) {
            return new WP_Error(
                'fflhub_order_waver_no_wave_orders',
                'No selected orders were eligible to wave.',
                ['skipped' => $skipped]
            );
        }

        $batch_id = OrderWaverStore::create_batch($orders, $created_by);
        if ($batch_id <= 0) {
            return new WP_Error('fflhub_order_waver_create_failed', 'Could not create an Order Waver batch.');
        }

        return [
            'batch' => OrderWaverStore::get_batch($batch_id),
            'selected_count' => count($orders),
            'skipped' => $skipped,
        ];
    }

    /**
     * Claim and process one queued wave batch.
     *
     * @return array<string,mixed>
     */
    public function run_packing_worker(): array
    {
        OrderWaverStore::ensure_schema();
        $started = microtime(true);
        $batch = OrderWaverStore::claim_next_batch(
            OrderWaverStore::BATCH_STATUS_QUEUED,
            OrderWaverStore::BATCH_STATUS_PACKING
        );

        if (!is_array($batch)) {
            return [
                'processed' => false,
                'message' => 'No queued Order Waver batches were waiting for packing.',
            ];
        }

        $batch_id = (int) ($batch['id'] ?? 0);
        $orders = OrderWaverStore::orders_for_batch($batch_id, [OrderWaverStore::ORDER_STATUS_QUEUED]);
        $packed = 0;
        $failed = 0;

        foreach ($orders as $wave_order) {
            $result = $this->pack_wave_order($wave_order);
            if (is_wp_error($result)) {
                $failed++;
                continue;
            }

            $packed++;
        }

        $summary = OrderWaverStore::summarize_batch($batch_id);
        $next_status = $packed > 0
            ? OrderWaverStore::BATCH_STATUS_READY_FOR_LABELS
            : OrderWaverStore::BATCH_STATUS_FAILED;

        OrderWaverStore::update_batch($batch_id, [
            'status' => $next_status,
            'packed_at' => current_time('mysql', true),
            'error_message' => $packed > 0 ? '' : 'No orders in this wave could be packed.',
        ]);
        OrderWaverStore::log($batch_id, 0, $packed > 0 ? 'info' : 'error', 'packing_complete', sprintf(
            'Packing worker completed: %d packed, %d failed.',
            $packed,
            $failed
        ), [
            'summary' => $summary,
            'runtime_ms' => $this->elapsed_ms($started),
        ]);

        return [
            'processed' => true,
            'batch_id' => $batch_id,
            'packed' => $packed,
            'failed' => $failed,
            'status' => $next_status,
            'runtime_ms' => $this->elapsed_ms($started),
        ];
    }

    /**
     * Claim and process one packed wave batch through EasyPost.
     *
     * @return array<string,mixed>
     */
    public function run_label_worker(): array
    {
        OrderWaverStore::ensure_schema();
        EasyPostBatchLabelStore::ensure_schema();

        $started = microtime(true);
        $batch = OrderWaverStore::claim_next_batch(
            OrderWaverStore::BATCH_STATUS_READY_FOR_LABELS,
            OrderWaverStore::BATCH_STATUS_LABELING
        );

        if (!is_array($batch)) {
            return [
                'processed' => false,
                'message' => 'No packed Order Waver batches were waiting for labels.',
            ];
        }

        $batch_id = (int) ($batch['id'] ?? 0);
        $easypost_batch_id = (int) ($batch['easypost_batch_id'] ?? 0);
        $packed_orders = OrderWaverStore::orders_for_batch($batch_id, [OrderWaverStore::ORDER_STATUS_PACKED]);
        $service = new EasyPostBatchLabelService();

        if ($easypost_batch_id <= 0) {
            $prepared = $service->prepare_from_packed_wave_orders(
                $packed_orders,
                (string) ($batch['batch_key'] ?? ('wave-' . $batch_id))
            );
            if (is_wp_error($prepared)) {
                return $this->fail_label_batch($batch_id, $packed_orders, $prepared);
            }

            $prepared_batch = is_array($prepared['batch'] ?? null) ? $prepared['batch'] : [];
            $easypost_batch_id = (int) ($prepared_batch['id'] ?? 0);
            OrderWaverStore::update_batch($batch_id, [
                'easypost_batch_id' => $easypost_batch_id,
            ]);
            $this->apply_prepare_problems_to_wave_orders($batch_id, $packed_orders, (array) ($prepared['problems'] ?? []));
            $packed_orders = OrderWaverStore::orders_for_batch($batch_id, [OrderWaverStore::ORDER_STATUS_PACKED]);
            OrderWaverStore::log($batch_id, 0, 'info', 'easypost_prepared', sprintf(
                'Prepared EasyPost local batch #%d for %d package(s).',
                $easypost_batch_id,
                (int) ($prepared['stats']['packages_prepared'] ?? 0)
            ), [
                'stats' => $prepared['stats'] ?? [],
                'problems' => $prepared['problems'] ?? [],
            ]);

            if (empty($packed_orders)) {
                OrderWaverStore::update_batch($batch_id, [
                    'status' => OrderWaverStore::BATCH_STATUS_FAILED,
                    'error_message' => 'No packed orders remained eligible after EasyPost preparation.',
                ]);

                return [
                    'processed' => true,
                    'batch_id' => $batch_id,
                    'easypost_batch_id' => $easypost_batch_id,
                    'status' => OrderWaverStore::BATCH_STATUS_FAILED,
                    'error' => 'No packed orders remained eligible after EasyPost preparation.',
                ];
            }
        }

        $bought = $service->submit_or_buy($easypost_batch_id);
        if (is_wp_error($bought)) {
            return $this->fail_label_batch($batch_id, $packed_orders, $bought, $easypost_batch_id);
        }

        $local_batch = EasyPostBatchLabelStore::get($easypost_batch_id);
        $status = is_array($local_batch) ? (string) ($local_batch['status'] ?? '') : '';
        $this->sync_label_results_to_wave_orders($batch_id, $packed_orders, is_array($local_batch) ? $local_batch : []);
        $summary = OrderWaverStore::summarize_batch($batch_id);

        if ($status === EasyPostBatchLabelStore::STATUS_LABELS_SAVED) {
            OrderWaverStore::update_batch($batch_id, [
                'status' => OrderWaverStore::BATCH_STATUS_LABELS_SAVED,
                'labels_at' => current_time('mysql', true),
                'error_message' => '',
            ]);
        } elseif ($status === EasyPostBatchLabelStore::STATUS_PARTIAL_LABELS_SAVED) {
            OrderWaverStore::update_batch($batch_id, [
                'status' => OrderWaverStore::BATCH_STATUS_PARTIAL_LABELS_SAVED,
                'labels_at' => current_time('mysql', true),
                'error_message' => (string) ($local_batch['error_message'] ?? ''),
            ]);
        } elseif ($status === EasyPostBatchLabelStore::STATUS_FAILED) {
            OrderWaverStore::update_batch($batch_id, [
                'status' => OrderWaverStore::BATCH_STATUS_FAILED,
                'error_message' => (string) ($local_batch['error_message'] ?? 'EasyPost label batch failed.'),
            ]);
        } else {
            // EasyPost batch work can be async. Put it back in the label queue
            // so the next minute run refreshes/buys/saves without creating a
            // second local EasyPost batch.
            OrderWaverStore::update_batch($batch_id, [
                'status' => OrderWaverStore::BATCH_STATUS_READY_FOR_LABELS,
                'error_message' => '',
            ]);
        }

        $updated = OrderWaverStore::get_batch($batch_id) ?? [];
        OrderWaverStore::log($batch_id, 0, 'info', 'label_worker_complete', sprintf(
            'Label worker completed with wave status %s and EasyPost status %s.',
            (string) ($updated['status'] ?? ''),
            $status !== '' ? $status : '-'
        ), [
            'summary' => $summary,
            'easypost_batch_id' => $easypost_batch_id,
            'runtime_ms' => $this->elapsed_ms($started),
        ]);

        return [
            'processed' => true,
            'batch_id' => $batch_id,
            'easypost_batch_id' => $easypost_batch_id,
            'status' => (string) ($updated['status'] ?? ''),
            'easypost_status' => $status,
            'runtime_ms' => $this->elapsed_ms($started),
        ];
    }

    /**
     * @param array<string,mixed> $wave_order
     * @return array<string,mixed>|WP_Error
     */
    private function pack_wave_order(array $wave_order)
    {
        $row_id = (int) ($wave_order['id'] ?? 0);
        $batch_id = (int) ($wave_order['batch_id'] ?? 0);
        $order_id = (int) ($wave_order['order_id'] ?? 0);

        OrderWaverStore::update_order($row_id, [
            'status' => OrderWaverStore::ORDER_STATUS_PACKING,
            'fail_reason' => '',
        ]);

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            return $this->mark_packing_failed($wave_order, 'Woo order could not be loaded.');
        }

        if (ShipStationOrderMeta::has_active_label($order)) {
            return $this->mark_packing_failed($wave_order, 'Order already has an active FFL Hub label.');
        }

        $shipment_service = $this->shipment_service();
        $packed = $shipment_service->auto_pack_order($order);
        if (empty($packed['ok'])) {
            return $this->mark_packing_failed($wave_order, $this->messages_from_packing_result($packed));
        }

        $packages = isset($packed['label_packages']) && is_array($packed['label_packages'])
            ? array_values($packed['label_packages'])
            : [];
        $package_items = isset($packed['package_items']) && is_array($packed['package_items'])
            ? array_values($packed['package_items'])
            : [];
        $package_items = $this->attach_received_serials_to_package_items($package_items, !empty($wave_order['debug_ready']));

        if (empty($packages)) {
            return $this->mark_packing_failed($wave_order, 'Packing succeeded but no label package rows were produced.');
        }

        $slips = $this->packing_slips_for_order($order, $shipment_service, $packages, $package_items);
        if (is_wp_error($slips)) {
            return $this->mark_packing_failed($wave_order, $slips->get_error_message());
        }

        OrderWaverStore::update_order($row_id, [
            'status' => OrderWaverStore::ORDER_STATUS_PACKED,
            'fail_reason' => '',
            'packages_json' => $packages,
            'package_items_json' => $package_items,
            'packing_slips_json' => $slips,
            'package_count' => count($packages),
            'label_count' => 0,
        ]);
        OrderWaverStore::log($batch_id, $order_id, 'info', 'order_packed', sprintf(
            'Order #%s packed into %d package(s).',
            (string) $order->get_order_number(),
            count($packages)
        ), [
            'packages' => array_map(static function (array $package): array {
                return [
                    'name' => (string) ($package['name'] ?? $package['package_name'] ?? $package['preset_name'] ?? 'Package'),
                    'length' => (float) ($package['length'] ?? 0),
                    'width' => (float) ($package['width'] ?? 0),
                    'height' => (float) ($package['height'] ?? 0),
                    'weight_oz' => (float) ($package['weight_oz'] ?? $package['weight'] ?? 0),
                ];
            }, $packages),
            'serialized_item_rows' => $this->serialized_package_item_count($package_items),
        ]);

        return [
            'ok' => true,
            'packages' => count($packages),
        ];
    }

    /**
     * Accepted receiving events are the source of truth for firearm serials.
     * The packer only knows Woo order item IDs and quantities, so this step
     * decorates each package assignment with the serials captured for that
     * order item before the packing slip is generated or the label is saved.
     *
     * @param array<int,array<int,array<string,mixed>>> $package_items
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function attach_received_serials_to_package_items(array $package_items, bool $debug_ready): array
    {
        $item_ids = [];
        foreach ($package_items as $rows) {
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $item_id = absint($row['item_id'] ?? $row['order_item_id'] ?? 0);
                if ($item_id > 0) {
                    $item_ids[] = $item_id;
                }
            }
        }

        $remaining_serials = (new ReceivingEventsStore())->accepted_serials_by_order_item_ids($item_ids);
        $out = [];

        foreach ($package_items as $package_index => $rows) {
            $out[$package_index] = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $item_id = absint($row['item_id'] ?? $row['order_item_id'] ?? 0);
                $quantity = max(0, (int) ($row['quantity'] ?? 0));
                $ffl_required = $this->truthy($row['ffl_required'] ?? null);
                $serials = [];

                if ($item_id > 0 && !empty($remaining_serials[$item_id])) {
                    $serials = array_splice($remaining_serials[$item_id], 0, max(1, $quantity));
                }

                if (empty($serials) && $debug_ready && $ffl_required && $quantity > 0) {
                    $serials = array_fill(0, $quantity, 'DEBUG');
                }

                $serials = $this->clean_serials($serials);
                if (!empty($serials)) {
                    $row['serial_numbers'] = $serials;
                    $row['serial_number'] = $this->serials_label($serials);
                }

                $out[$package_index][] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<int,array<int,array<string,mixed>>> $package_items
     */
    private function serialized_package_item_count(array $package_items): int
    {
        $count = 0;
        foreach ($package_items as $rows) {
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row) && $this->truthy($row['ffl_required'] ?? null)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param mixed[] $serials
     * @return string[]
     */
    private function clean_serials(array $serials): array
    {
        $out = [];
        foreach ($serials as $serial) {
            $serial = trim(sanitize_text_field((string) $serial));
            if ($serial !== '') {
                $out[] = $serial;
            }
        }

        return $out;
    }

    /**
     * @param string[] $serials
     */
    private function serials_label(array $serials): string
    {
        $serials = $this->clean_serials($serials);
        if (empty($serials)) {
            return '';
        }

        $unique = array_values(array_unique($serials));
        if (count($unique) === 1) {
            return $unique[0];
        }

        return implode(', ', $unique);
    }

    /**
     * @param array<int,array<string,mixed>> $packages
     * @param array<int,array<int,array<string,mixed>>> $package_items
     * @return array<int,array<string,mixed>>|WP_Error
     */
    private function packing_slips_for_order(WC_Order $order, ShipStationShipmentService $shipment_service, array $packages, array $package_items)
    {
        $slip_service = new PackingSlipService();
        $slips = [];
        $package_count = count($packages);

        foreach ($packages as $index => $package) {
            if (!is_array($package)) {
                continue;
            }

            $items = is_array($package_items[$index] ?? null) ? $package_items[$index] : [];
            $shipment = $shipment_service->shipment_for_packages($order, [$package], [$items], [
                'ship_date' => gmdate('Y-m-d'),
            ]);
            if (is_wp_error($shipment)) {
                return $shipment;
            }

            $destination = is_array($shipment['ship_to'] ?? null) ? $shipment['ship_to'] : [];
            $slip = $slip_service->generate_pdf_for_package(
                $order,
                ShippingPackage::from_array($package, $items),
                $destination,
                [
                    'package_index' => $index + 1,
                    'package_count' => $package_count,
                ]
            );
            if (is_wp_error($slip)) {
                return $slip;
            }

            $slips[] = [
                'package_index' => $index,
                'filename' => (string) ($slip['filename'] ?? ('packing-slip-' . (int) $order->get_id() . '-' . ($index + 1) . '.pdf')),
                'content_type' => (string) ($slip['content_type'] ?? 'application/pdf'),
                'body_base64' => base64_encode((string) ($slip['body'] ?? '')),
                'generated_at' => current_time('mysql', true),
            ];
        }

        return $slips;
    }

    /**
     * @param array<string,mixed> $wave_order
     */
    private function mark_packing_failed(array $wave_order, string $reason): WP_Error
    {
        $row_id = (int) ($wave_order['id'] ?? 0);
        $batch_id = (int) ($wave_order['batch_id'] ?? 0);
        $order_id = (int) ($wave_order['order_id'] ?? 0);
        $reason = trim($reason) !== '' ? trim($reason) : 'Order could not be packed.';

        OrderWaverStore::update_order($row_id, [
            'status' => OrderWaverStore::ORDER_STATUS_PACKING_FAILED,
            'fail_reason' => $reason,
            'packages_json' => [],
            'package_items_json' => [],
            'packing_slips_json' => [],
            'package_count' => 0,
        ]);
        OrderWaverStore::log($batch_id, $order_id, 'error', 'packing_failed', $reason);

        return new WP_Error('fflhub_order_waver_packing_failed', $reason);
    }

    /**
     * @param array<int,array<string,mixed>> $packed_orders
     * @return array<string,mixed>
     */
    private function fail_label_batch(int $batch_id, array $packed_orders, WP_Error $error, int $easypost_batch_id = 0): array
    {
        foreach ($packed_orders as $wave_order) {
            OrderWaverStore::update_order((int) ($wave_order['id'] ?? 0), [
                'status' => OrderWaverStore::ORDER_STATUS_LABEL_FAILED,
                'fail_reason' => $error->get_error_message(),
            ]);
        }

        OrderWaverStore::update_batch($batch_id, [
            'status' => OrderWaverStore::BATCH_STATUS_FAILED,
            'easypost_batch_id' => $easypost_batch_id,
            'error_message' => $error->get_error_message(),
        ]);
        OrderWaverStore::summarize_batch($batch_id);
        OrderWaverStore::log($batch_id, 0, 'error', 'label_failed', $error->get_error_message(), [
            'error_code' => $error->get_error_code(),
            'error_data' => $error->get_error_data(),
        ]);

        return [
            'processed' => true,
            'batch_id' => $batch_id,
            'easypost_batch_id' => $easypost_batch_id,
            'status' => OrderWaverStore::BATCH_STATUS_FAILED,
            'error' => $error->get_error_message(),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $packed_orders
     * @param array<string,mixed> $easypost_batch
     */
    private function sync_label_results_to_wave_orders(int $batch_id, array $packed_orders, array $easypost_batch): void
    {
        $items_by_order = [];
        foreach ((array) ($easypost_batch['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $order_id = (int) ($item['order_id'] ?? 0);
            if ($order_id <= 0) {
                continue;
            }

            if (!isset($items_by_order[$order_id])) {
                $items_by_order[$order_id] = [];
            }
            $items_by_order[$order_id][] = $item;
        }

        $batch_status = (string) ($easypost_batch['status'] ?? '');
        foreach ($packed_orders as $wave_order) {
            $order_id = (int) ($wave_order['order_id'] ?? 0);
            $items = $items_by_order[$order_id] ?? [];
            $package_count = max(1, (int) ($wave_order['package_count'] ?? count((array) ($wave_order['packages'] ?? []))));
            $saved = 0;
            $errors = [];

            foreach ($items as $item) {
                if (!empty($item['label_saved'])) {
                    $saved++;
                }
                if (trim((string) ($item['label_error'] ?? '')) !== '') {
                    $errors[] = trim((string) ($item['label_error'] ?? ''));
                }
                if (trim((string) ($item['batch_message'] ?? '')) !== '') {
                    $errors[] = trim((string) ($item['batch_message'] ?? ''));
                }
            }

            if ($saved >= $package_count) {
                OrderWaverStore::update_order((int) ($wave_order['id'] ?? 0), [
                    'status' => OrderWaverStore::ORDER_STATUS_LABEL_SAVED,
                    'fail_reason' => '',
                    'label_count' => $saved,
                ]);
                $order = wc_get_order($order_id);
                if ($order instanceof WC_Order) {
                    OrderProfitAuditMeta::recalculate_order($order, true);
                }
                OrderWaverStore::log($batch_id, $order_id, 'info', 'label_saved', sprintf('%d label(s) saved to order.', $saved));
                continue;
            }

            if ($batch_status === EasyPostBatchLabelStore::STATUS_FAILED || !empty($errors)) {
                $reason = !empty($errors)
                    ? implode(' | ', array_values(array_unique($errors)))
                    : ((string) ($easypost_batch['error_message'] ?? 'EasyPost label purchase failed.'));
                OrderWaverStore::update_order((int) ($wave_order['id'] ?? 0), [
                    'status' => OrderWaverStore::ORDER_STATUS_LABEL_FAILED,
                    'fail_reason' => $reason,
                    'label_count' => $saved,
                ]);
                OrderWaverStore::log($batch_id, $order_id, 'error', 'label_failed', $reason);
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $packed_orders
     * @param array<int,mixed> $problems
     */
    private function apply_prepare_problems_to_wave_orders(int $batch_id, array $packed_orders, array $problems): void
    {
        if (empty($problems)) {
            return;
        }

        $rows_by_order_id = [];
        foreach ($packed_orders as $row) {
            $order_id = (int) ($row['order_id'] ?? 0);
            if ($order_id > 0) {
                $rows_by_order_id[$order_id] = $row;
            }
        }

        foreach ($problems as $problem) {
            if (!is_array($problem)) {
                continue;
            }

            $order_id = (int) ($problem['order_id'] ?? 0);
            $wave_order = $rows_by_order_id[$order_id] ?? null;
            if (!is_array($wave_order)) {
                continue;
            }

            $message = trim((string) ($problem['message'] ?? 'EasyPost could not prepare this order package.'));
            OrderWaverStore::update_order((int) ($wave_order['id'] ?? 0), [
                'status' => OrderWaverStore::ORDER_STATUS_LABEL_FAILED,
                'fail_reason' => $message,
            ]);
            OrderWaverStore::log($batch_id, $order_id, 'error', 'label_prepare_failed', $message, [
                'problem' => $problem,
            ]);
        }

        OrderWaverStore::summarize_batch($batch_id);
    }

    private function shipment_service(): ShipStationShipmentService
    {
        return new ShipStationShipmentService(new FFLTable(new FFLSchema()));
    }

    /**
     * @param array<string,mixed> $packed
     */
    private function messages_from_packing_result(array $packed): string
    {
        $messages = array_values(array_filter(array_map('strval', (array) ($packed['errors'] ?? []))));
        if ((int) ($packed['unpacked_item_count'] ?? 0) > 0) {
            $messages[] = sprintf('%d item(s) could not be packed.', (int) $packed['unpacked_item_count']);
        }
        if (trim((string) ($packed['message'] ?? '')) !== '') {
            $messages[] = trim((string) $packed['message']);
        }

        $messages = array_values(array_unique($messages));

        return !empty($messages) ? implode(' | ', $messages) : 'No package preset could pack this order.';
    }

    /**
     * @param mixed $value
     */
    private function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function elapsed_ms(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 2);
    }
}
