<?php
declare(strict_types=1);

namespace FFLHub\Receiving;

use FFLHub\Inventory\LocalStockUnitStore;
use FFLHub\WMS\OrderWaverStore;
use WC_Order;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safe correction path for serialized receiving mistakes.
 *
 * The receiving event is the operational source of truth for serial numbers.
 * This service keeps that row, the local-stock unit ledger, and any active WMS
 * wave package assignments in sync while preserving an immutable correction log.
 */
final class ReceivingSerialCorrectionService
{
    private ReceivingEventsStore $events;

    public function __construct(?ReceivingEventsStore $events = null)
    {
        $this->events = $events ?? new ReceivingEventsStore();
    }

    /**
     * @return array<string,mixed>
     */
    public function recent_serialized_events(int $limit = 25): array
    {
        $events = array_map([$this, 'public_event'], $this->events->recent_serialized_events($limit));

        return [
            'ok' => true,
            'events' => $events,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function correct_serial(
        int $event_id,
        string $new_serial_number,
        string $note = '',
        bool $fastbound_manual_confirmed = false
    ): array {
        global $wpdb;

        ReceivingEventsStore::ensure_schema();
        ReceivingSerialCorrectionsStore::ensure_schema();

        $event_id = absint($event_id);
        $new_serial_number = ReceivingShipmentService::normalize_serial($new_serial_number);
        $note = $this->textarea($note, 1000);

        if ($event_id <= 0 || $new_serial_number === '') {
            return $this->error('invalid_serial_correction', 'Choose a receiving event and enter the corrected serial number.');
        }

        $lock_name = 'fflhub_receiving_serial_correction_' . $event_id;
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock_name));
        if ($locked !== 1) {
            return $this->error('serial_correction_locked', 'Another serial correction is already running for this scan. Try again in a moment.');
        }

        try {
            $event = $this->events->find_by_id($event_id);
            if (!is_array($event)) {
                return $this->error('receiving_event_not_found', 'The receiving event was not found.');
            }

            if ((string) ($event['result'] ?? '') !== 'accepted') {
                return $this->error('receiving_event_not_accepted', 'Only accepted receiving scans can have their serial corrected.');
            }

            $old_serial_number = ReceivingShipmentService::normalize_serial((string) ($event['serial_number'] ?? ''));
            if ($old_serial_number === '') {
                return $this->error('receiving_event_not_serialized', 'This receiving scan does not have a serial number to correct.');
            }

            if ($new_serial_number === $old_serial_number) {
                return [
                    'ok' => true,
                    'code' => 'serial_unchanged',
                    'message' => 'The corrected serial matches the current serial. Nothing changed.',
                    'event' => $this->public_event($event),
                ];
            }

            $fastbound_status = strtolower(trim((string) ($event['fastbound_status'] ?? '')));
            $disposition_id = trim((string) ($event['fastbound_disposition_id'] ?? ''));
            if ($disposition_id !== '' || $fastbound_status === 'disposed') {
                return $this->error('serial_already_disposed', 'This firearm has already been disposed in FastBound. Do not change the local serial after disposition.');
            }

            $shipment_key = trim((string) ($event['shipment_key'] ?? ''));
            if ($this->events->accepted_serial_exists_except($shipment_key, $new_serial_number, $event_id)) {
                return $this->error('serial_already_scanned', 'That serial number is already attached to another accepted scan in this shipment.');
            }

            $acquisition_item_id = trim((string) ($event['fastbound_acquisition_item_id'] ?? ''));
            if ($acquisition_item_id !== '' && !$fastbound_manual_confirmed) {
                return [
                    'ok' => false,
                    'code' => 'fastbound_manual_confirmation_required',
                    'requires_fastbound_manual_confirm' => true,
                    'message' => 'This serial is already acquired in FastBound. Correct it in FastBound first, then confirm the local correction.',
                    'event' => $this->public_event($event),
                ];
            }

            $message = sprintf('Serial corrected from %s to %s.', $old_serial_number, $new_serial_number);
            if (!$this->events->update_serial_number($event_id, $new_serial_number, $message)) {
                return $this->error('serial_update_failed', 'FFLHub could not update the receiving event serial.');
            }

            $local_stock_update = LocalStockUnitStore::update_serial_for_receiving_event($event_id, $new_serial_number);
            $wave_updates = $this->replace_serial_in_active_waves($event, $old_serial_number, $new_serial_number);
            $correction_id = ReceivingSerialCorrectionsStore::insert([
                'event_id' => $event_id,
                'shipment_key' => $shipment_key,
                'order_id' => $event['order_id'] ?? null,
                'order_item_id' => $event['order_item_id'] ?? null,
                'upc' => (string) ($event['upc'] ?? ''),
                'old_serial_number' => $old_serial_number,
                'new_serial_number' => $new_serial_number,
                'fastbound_acquisition_item_id' => $acquisition_item_id,
                'fastbound_disposition_id' => $disposition_id,
                'fastbound_manual_confirmed' => $fastbound_manual_confirmed,
                'note' => $note,
            ]);

            $fresh = $this->events->find_by_id($event_id) ?: $event;

            return [
                'ok' => true,
                'code' => 'serial_corrected',
                'message' => $message,
                'event' => $this->public_event($fresh),
                'correction_id' => $correction_id,
                'local_stock_update' => $local_stock_update,
                'wave_updates' => $wave_updates,
            ];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function public_event(array $event): array
    {
        $product = $this->event_product((int) ($event['product_id'] ?? 0));
        $order = $this->event_order((int) ($event['order_id'] ?? 0));
        $status = strtolower(trim((string) ($event['fastbound_status'] ?? '')));
        $serial = ReceivingShipmentService::normalize_serial((string) ($event['serial_number'] ?? ''));
        $acquired = trim((string) ($event['fastbound_acquisition_item_id'] ?? '')) !== '';
        $disposed = trim((string) ($event['fastbound_disposition_id'] ?? '')) !== '' || $status === 'disposed';

        return [
            'id' => (int) ($event['id'] ?? 0),
            'received_at' => (string) ($event['received_at'] ?? ''),
            'shipment_key' => (string) ($event['shipment_key'] ?? ''),
            'dist_id' => (string) ($event['dist_id'] ?? ''),
            'merchant_po' => (string) ($event['merchant_po'] ?? ''),
            'upc' => (string) ($event['upc'] ?? ''),
            'product_id' => (int) ($event['product_id'] ?? 0),
            'product_name' => $product instanceof WC_Product ? $product->get_name() : '',
            'product_edit_url' => $product instanceof WC_Product ? get_edit_post_link((int) $product->get_id(), '') : '',
            'order_id' => (int) ($event['order_id'] ?? 0),
            'order_number' => $order instanceof WC_Order ? (string) $order->get_order_number() : '',
            'order_edit_url' => $order instanceof WC_Order ? admin_url('post.php?post=' . (int) $order->get_id() . '&action=edit') : '',
            'order_item_id' => (int) ($event['order_item_id'] ?? 0),
            'serial_number' => $serial,
            'fastbound_status' => (string) ($event['fastbound_status'] ?? ''),
            'fastbound_acquisition_item_id' => (string) ($event['fastbound_acquisition_item_id'] ?? ''),
            'fastbound_disposition_id' => (string) ($event['fastbound_disposition_id'] ?? ''),
            'serial_correction_allowed' => ((string) ($event['result'] ?? '') === 'accepted' && $serial !== '' && !$disposed) ? 1 : 0,
            'serial_correction_requires_fastbound_confirm' => ($acquired && !$disposed) ? 1 : 0,
            'serial_correction_blocked_reason' => $disposed ? 'Already disposed in FastBound' : '',
        ];
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function replace_serial_in_active_waves(array $event, string $old_serial_number, string $new_serial_number): array
    {
        global $wpdb;

        $order_id = absint($event['order_id'] ?? 0);
        $order_item_id = absint($event['order_item_id'] ?? 0);
        if ($order_id <= 0 || $order_item_id <= 0) {
            return [
                'updated_rows' => 0,
                'slips_may_need_reprint' => 0,
            ];
        }

        OrderWaverStore::ensure_schema();
        $table = OrderWaverStore::orders_table_name();
        $like = '%' . $wpdb->esc_like($old_serial_number) . '%';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table}
                 WHERE order_id = %d
                   AND status <> %s
                   AND package_items_json LIKE %s
                 ORDER BY id DESC",
                $order_id,
                OrderWaverStore::ORDER_STATUS_SHIPPED,
                $like
            ),
            ARRAY_A
        );

        $updated = 0;
        $slips_may_need_reprint = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $package_items = json_decode((string) ($row['package_items_json'] ?? ''), true);
            if (!is_array($package_items)) {
                continue;
            }

            $changed = $this->replace_serial_in_package_items($package_items, $order_item_id, $old_serial_number, $new_serial_number);
            if (!$changed) {
                continue;
            }

            OrderWaverStore::update_order((int) ($row['id'] ?? 0), [
                'package_items_json' => $package_items,
            ]);
            OrderWaverStore::log(
                (int) ($row['batch_id'] ?? 0),
                $order_id,
                'info',
                'serial_corrected',
                sprintf('Receiving serial corrected from %s to %s.', $old_serial_number, $new_serial_number),
                [
                    'wave_order_row_id' => (int) ($row['id'] ?? 0),
                    'order_item_id' => $order_item_id,
                ]
            );

            $updated++;
            if (trim((string) ($row['packing_slips_json'] ?? '')) !== '') {
                $slips_may_need_reprint++;
            }
        }

        return [
            'updated_rows' => $updated,
            'slips_may_need_reprint' => $slips_may_need_reprint,
        ];
    }

    /**
     * @param array<int,mixed> $package_items
     */
    private function replace_serial_in_package_items(array &$package_items, int $order_item_id, string $old_serial_number, string $new_serial_number): bool
    {
        $changed = false;

        foreach ($package_items as &$package_rows) {
            if (!is_array($package_rows)) {
                continue;
            }

            foreach ($package_rows as &$row) {
                if (!is_array($row)) {
                    continue;
                }

                $row_item_id = absint($row['item_id'] ?? $row['order_item_id'] ?? 0);
                if ($row_item_id !== $order_item_id) {
                    continue;
                }

                if (isset($row['serial_numbers']) && is_array($row['serial_numbers'])) {
                    foreach ($row['serial_numbers'] as &$serial) {
                        if (ReceivingShipmentService::normalize_serial((string) $serial) === $old_serial_number) {
                            $serial = $new_serial_number;
                            $changed = true;
                        }
                    }
                    unset($serial);
                }

                if (isset($row['serial_number']) && is_string($row['serial_number']) && strpos($row['serial_number'], $old_serial_number) !== false) {
                    $row['serial_number'] = str_replace($old_serial_number, $new_serial_number, $row['serial_number']);
                    $changed = true;
                }
            }
            unset($row);
        }
        unset($package_rows);

        return $changed;
    }

    private function event_product(int $product_id): ?WC_Product
    {
        if ($product_id <= 0 || !function_exists('wc_get_product')) {
            return null;
        }

        $product = wc_get_product($product_id);

        return $product instanceof WC_Product ? $product : null;
    }

    private function event_order(int $order_id): ?WC_Order
    {
        if ($order_id <= 0 || !function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order($order_id);

        return $order instanceof WC_Order ? $order : null;
    }

    private function textarea(string $value, int $max): string
    {
        $text = sanitize_textarea_field($value);
        if ($max > 0 && strlen($text) > $max) {
            $text = substr($text, 0, $max);
        }

        return $text;
    }

    /**
     * @return array<string,mixed>
     */
    private function error(string $code, string $message): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
        ];
    }
}
