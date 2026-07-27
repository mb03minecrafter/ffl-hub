<?php
declare(strict_types=1);

namespace FFLHub\WMS;

use FFLHub\Product\State\ProductStateStore;
use FFLHub\Receiving\ReceivingEventsStore;
use FFLHub\Shipping\ShipStation\ShipStationOrderMeta;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal fulfillment-shaped object used when Woo's DB-backed fulfillment
 * feature is unavailable but its email template can still render tracking.
 */
final class WMSFallbackFulfillment
{
    /** @var array<int,array{item_id:int,qty:int}> */
    private array $items = [];

    /** @var array<string,mixed> */
    private array $meta = [];

    private ?string $entity_type = null;
    private ?string $entity_id = null;
    private ?string $status = null;
    private ?string $date_fulfilled = null;
    private bool $locked = false;

    public function get_id(): int
    {
        return 0;
    }

    public function set_entity_type(?string $entity_type): void
    {
        $this->entity_type = $entity_type;
    }

    public function set_entity_id(?string $entity_id): void
    {
        $this->entity_id = $entity_id;
    }

    public function set_items(array $items): void
    {
        $this->items = array_values($items);
    }

    public function get_items(): array
    {
        return $this->items;
    }

    public function set_status(?string $status): void
    {
        $this->status = $status;
    }

    public function set_date_fulfilled(string $date_fulfilled): void
    {
        $this->date_fulfilled = $date_fulfilled;
        $this->meta['_date_fulfilled'] = $date_fulfilled;
    }

    public function add_meta_data(string $key, $value, bool $unique = false): void
    {
        if ($unique || !isset($this->meta[$key])) {
            $this->meta[$key] = $value;
            return;
        }

        $this->meta[$key] = array_merge((array) $this->meta[$key], [$value]);
    }

    public function get_meta(string $key, bool $single = true)
    {
        return $this->meta[$key] ?? ($single ? '' : []);
    }

    public function get_date_deleted(): ?string
    {
        return null;
    }

    public function set_locked(bool $locked, string $message = ''): void
    {
        $this->locked = $locked;
        $this->meta['_is_locked'] = $locked;
        if ($message !== '') {
            $this->meta['_lock_message'] = $message;
        }
    }
}

/**
 * Confirms outbound WMS packages after the packing station has scanned them.
 *
 * The Sending page is a physical workflow: scan UPCs, scan firearm serials, and
 * confirm the package. This service owns the durable side effects behind that
 * button so the page does not have to know how Woo fulfillments, customer
 * emails, WMS wave state, and debug-mode safety all fit together.
 */
final class WMSShipmentConfirmationService
{
    private const CONFIRMATIONS_META_KEY = '_fflhub_wms_package_confirmations';

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>|WP_Error
     */
    public function confirm_package(array $request)
    {
        OrderWaverStore::ensure_schema();

        $wave_batch_id = max(0, (int) ($request['wave_batch_id'] ?? 0));
        $easypost_batch_id = max(0, (int) ($request['easypost_batch_id'] ?? 0));
        $order_id = max(0, (int) ($request['order_id'] ?? 0));
        $package_index = max(0, (int) ($request['package_index'] ?? 0));
        $scans = is_array($request['scans'] ?? null) ? $request['scans'] : [];

        if ($wave_batch_id <= 0 || $order_id <= 0) {
            return new WP_Error('fflhub_wms_confirm_missing_ids', 'Missing wave batch or order id.');
        }

        $batch = OrderWaverStore::batch_with_details($wave_batch_id, 12);
        if (!is_array($batch)) {
            return new WP_Error('fflhub_wms_confirm_missing_batch', 'Could not find that wave batch.');
        }

        $batch_status = (string) ($batch['status'] ?? '');
        if (!in_array($batch_status, [OrderWaverStore::BATCH_STATUS_LABELS_SAVED, OrderWaverStore::BATCH_STATUS_PARTIAL_LABELS_SAVED], true)) {
            return new WP_Error('fflhub_wms_confirm_batch_not_ready', 'That wave batch is not ready for shipment confirmation.');
        }

        $wave_order = $this->wave_order($batch, $order_id);
        if (empty($wave_order)) {
            return new WP_Error('fflhub_wms_confirm_missing_wave_order', 'That order is not part of this wave batch.');
        }

        $debug_requested = !empty($request['debug_mode']) && (current_user_can('manage_woocommerce') || current_user_can('manage_options'));
        $debug_ready = !empty($wave_order['debug_ready']) || $debug_requested;
        $order_status = (string) ($wave_order['status'] ?? '');
        if (!$debug_ready && !in_array($order_status, [OrderWaverStore::ORDER_STATUS_LABEL_SAVED, OrderWaverStore::ORDER_STATUS_SHIPPED], true)) {
            return new WP_Error('fflhub_wms_confirm_order_not_ready', 'That order does not have saved labels ready for shipment confirmation.');
        }

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            return new WP_Error('fflhub_wms_confirm_missing_order', 'Could not load the Woo order.');
        }

        $provider_batch_id = $easypost_batch_id > 0
            ? (string) $easypost_batch_id
            : (string) ($batch['easypost_batch_id'] ?? '');
        $label = $this->active_label_for_package($order, $provider_batch_id, $package_index);
        if (empty($label)) {
            return new WP_Error('fflhub_wms_confirm_missing_label', 'Could not find an active label for that package.');
        }

        $confirmations = $this->confirmations($order);
        $confirmation_key = $this->confirmation_key($wave_batch_id, (int) ($batch['easypost_batch_id'] ?? $easypost_batch_id), $package_index);
        if (!$debug_ready && isset($confirmations[$confirmation_key])) {
            return [
                'message' => 'That package was already confirmed earlier.',
                'debug' => false,
                'already_confirmed' => true,
                'order_completed' => $order->has_status('completed'),
            ];
        }

        $assignments = $this->package_assignments($wave_order, $label, $package_index);
        $expected = $this->expected_items($order, $assignments, $debug_ready);
        if (empty($expected)) {
            return new WP_Error('fflhub_wms_confirm_no_items', 'That package has no assigned order items to confirm.');
        }

        $validated = $this->validate_scans($expected, $scans, $debug_ready);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $fulfillment = $this->build_fulfillment($order, $expected, $label, $debug_ready);
        if (is_wp_error($fulfillment)) {
            return $fulfillment;
        }

        $fulfillment_id = 0;
        $has_real_fulfillment_record = $this->is_real_fulfillment_record($fulfillment);
        if (!$debug_ready && $has_real_fulfillment_record) {
            $fulfillment->save();
            $fulfillment_id = (int) $fulfillment->get_id();
            if ($fulfillment_id <= 0) {
                return new WP_Error('fflhub_wms_confirm_fulfillment_save_failed', 'WooCommerce did not save a fulfillment record for this package.');
            }
        }

        $recipient = $debug_ready ? $this->debug_recipient() : '';
        $email_sent = $this->send_fulfillment_email($order, $fulfillment, $debug_ready, $recipient);
        if ($debug_ready && !$email_sent) {
            return new WP_Error('fflhub_wms_confirm_debug_email_unavailable', 'WooCommerce fulfillment email could not be loaded for this debug send.');
        }

        if ($debug_ready) {
            OrderWaverStore::log($wave_batch_id, $order_id, 'info', 'debug_shipment_email', 'Debug fulfillment email sent without completing the order.', [
                'package_index' => $package_index,
                'recipient' => $recipient,
                'tracking_number' => (string) ($label['tracking_number'] ?? ''),
            ]);

            return [
                'message' => sprintf('Debug fulfillment email sent to %s. The order was not completed.', $recipient),
                'debug' => true,
                'recipient' => $recipient,
                'order_completed' => false,
                'fulfillment_id' => 0,
            ];
        }

        $confirmed_at = current_time('mysql', true);
        $confirmations[$confirmation_key] = [
            'wave_batch_id' => $wave_batch_id,
            'easypost_batch_id' => (int) ($batch['easypost_batch_id'] ?? $easypost_batch_id),
            'order_id' => $order_id,
            'package_index' => $package_index,
            'label_id' => (string) ($label['label_id'] ?? ''),
            'tracking_number' => (string) ($label['tracking_number'] ?? ''),
            'tracking_url' => (string) ($label['tracking_url'] ?? ''),
            'fulfillment_id' => $fulfillment_id,
            'scans' => $validated['scans'],
            'confirmed_at' => $confirmed_at,
            'confirmed_by' => get_current_user_id(),
        ];
        $order->update_meta_data(self::CONFIRMATIONS_META_KEY, $confirmations);
        $order->add_order_note(sprintf(
            'FFL Hub WMS package %d confirmed for shipment. Tracking: %s',
            $package_index + 1,
            (string) ($label['tracking_number'] ?? '')
        ));
        $order->save();

        OrderWaverStore::log($wave_batch_id, $order_id, 'info', 'package_confirmed', 'Package confirmed and Woo fulfillment email sent.', [
            'package_index' => $package_index,
            'fulfillment_id' => $fulfillment_id,
            'tracking_number' => (string) ($label['tracking_number'] ?? ''),
        ]);

        $order_completed = false;
        if ($this->all_packages_confirmed($order, $wave_batch_id, (int) ($batch['easypost_batch_id'] ?? $easypost_batch_id))) {
            OrderWaverStore::update_order((int) ($wave_order['id'] ?? 0), [
                'status' => OrderWaverStore::ORDER_STATUS_SHIPPED,
            ]);
            OrderWaverStore::summarize_batch($wave_batch_id);
            OrderWaverStore::log($wave_batch_id, $order_id, 'info', 'order_shipped', 'All packages for this order were confirmed; completing Woo order.');

            if (!$order->has_status('completed')) {
                $order->update_status('completed', 'FFL Hub WMS: all outbound packages were confirmed and fulfillment tracking was emailed.');
                $order_completed = true;
            } else {
                $order_completed = true;
            }
        }

        return [
            'message' => $order_completed
                ? 'Package confirmed. All packages for this order are confirmed, so the order was completed.'
                : 'Package confirmed and customer fulfillment email sent.',
            'debug' => false,
            'fulfillment_id' => $fulfillment_id,
            'order_completed' => $order_completed,
        ];
    }

    /**
     * @param array<string,mixed> $batch
     * @return array<string,mixed>
     */
    private function wave_order(array $batch, int $order_id): array
    {
        foreach ((array) ($batch['orders'] ?? []) as $row) {
            if (is_array($row) && (int) ($row['order_id'] ?? 0) === $order_id) {
                return $row;
            }
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function active_label_for_package(WC_Order $order, string $provider_batch_id, int $package_index): array
    {
        foreach ($this->active_labels($order, $provider_batch_id) as $label) {
            if (max(0, (int) ($label['package_index'] ?? 0)) === $package_index) {
                return $label;
            }
        }

        return [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function active_labels(WC_Order $order, string $provider_batch_id): array
    {
        $active = array_values(array_filter(ShipStationOrderMeta::labels($order), static function (array $label) use ($provider_batch_id): bool {
            if (!ShipStationOrderMeta::label_is_active($label)) {
                return false;
            }

            return $provider_batch_id === '' || (string) ($label['easypost_batch_id'] ?? '') === $provider_batch_id;
        }));

        if (empty($active) && $provider_batch_id !== '') {
            $active = array_values(array_filter(ShipStationOrderMeta::labels($order), static function (array $label): bool {
                return ShipStationOrderMeta::label_is_active($label);
            }));
        }

        usort($active, static function (array $a, array $b): int {
            return max(0, (int) ($a['package_index'] ?? 0)) <=> max(0, (int) ($b['package_index'] ?? 0));
        });

        return $active;
    }

    /**
     * @param array<string,mixed> $wave_order
     * @param array<string,mixed> $label
     * @return array<int,array<string,mixed>>
     */
    private function package_assignments(array $wave_order, array $label, int $package_index): array
    {
        $package_items = array_values((array) ($wave_order['package_items'] ?? []));
        if (is_array($package_items[$package_index] ?? null)) {
            return array_values(array_filter($package_items[$package_index], 'is_array'));
        }

        foreach ((array) ($label['package_items'] ?? []) as $rows) {
            if (is_array($rows)) {
                return array_values(array_filter($rows, 'is_array'));
            }
        }

        return [];
    }

    /**
     * @param array<int,array<string,mixed>> $assignments
     * @return array<int,array<string,mixed>>
     */
    private function expected_items(WC_Order $order, array $assignments, bool $debug_ready): array
    {
        $item_ids = [];
        foreach ($assignments as $assignment) {
            $item_id = absint($assignment['item_id'] ?? $assignment['order_item_id'] ?? 0);
            if ($item_id > 0) {
                $item_ids[] = $item_id;
            }
        }
        $received_serials = (new ReceivingEventsStore())->accepted_serials_by_order_item_ids($item_ids);

        $expected = [];
        foreach ($assignments as $assignment) {
            $item_id = absint($assignment['item_id'] ?? $assignment['order_item_id'] ?? 0);
            $quantity = max(0, (int) ($assignment['quantity'] ?? 0));
            if ($item_id <= 0 || $quantity <= 0) {
                continue;
            }

            $order_item = $order->get_item($item_id);
            $product = $order_item instanceof WC_Order_Item_Product ? $order_item->get_product() : null;
            $product = $product instanceof WC_Product ? $product : null;
            $state_row = $product instanceof WC_Product ? ProductStateStore::get_row_for_product($product) : null;

            $ffl_required = $this->truthy($assignment['ffl_required'] ?? null)
                || ($product instanceof WC_Product && ProductStateStore::get_ffl_required_for_product($product));
            $serials = $this->clean_serials((array) ($assignment['serial_numbers'] ?? []));
            $single_serial = trim((string) ($assignment['serial_number'] ?? ''));
            if ($single_serial !== '') {
                $serials[] = $single_serial;
            }
            if (empty($serials) && $ffl_required && !empty($received_serials[$item_id])) {
                $serials = array_slice($this->clean_serials((array) $received_serials[$item_id]), 0, $quantity);
            }
            if (empty($serials) && $debug_ready && $ffl_required) {
                $serials = ['DEBUG'];
            }

            $upc = $this->normalize_upc((string) ($assignment['upc'] ?? ''));
            if ($upc === '' && is_array($state_row)) {
                $upc = $this->normalize_upc((string) ($state_row['upc'] ?? ''));
            }
            if ($upc === '' && $product instanceof WC_Product && method_exists($product, 'get_global_unique_id')) {
                $upc = $this->normalize_upc((string) $product->get_global_unique_id('edit'));
            }

            $name = trim((string) ($assignment['name'] ?? ''));
            if ($name === '' && $order_item instanceof WC_Order_Item_Product) {
                $name = (string) $order_item->get_name();
            }

            $expected[] = [
                'item_id' => $item_id,
                'upc' => $upc,
                'quantity' => $quantity,
                'name' => $name !== '' ? $name : 'Order item #' . $item_id,
                'ffl_required' => $ffl_required,
                'serials' => array_values(array_unique(array_map([$this, 'normalize_serial'], $serials))),
            ];
        }

        return $expected;
    }

    /**
     * @param array<int,array<string,mixed>> $expected
     * @param array<int,mixed> $scans
     * @return array{scans:array<int,array<string,mixed>>}|WP_Error
     */
    private function validate_scans(array $expected, array $scans, bool $debug_ready)
    {
        $remaining = [];
        $used_serials = [];
        foreach ($expected as $index => $row) {
            $remaining[$index] = max(1, (int) ($row['quantity'] ?? 1));
            $used_serials[$index] = [];
            if ((string) ($row['upc'] ?? '') === '') {
                return new WP_Error('fflhub_wms_confirm_missing_upc', sprintf('Package item "%s" does not have a UPC to scan.', (string) ($row['name'] ?? '')));
            }
        }

        $accepted = [];
        foreach ($scans as $scan) {
            if (!is_array($scan)) {
                continue;
            }

            $upc = $this->normalize_upc((string) ($scan['upc'] ?? ''));
            $item_id = absint($scan['item_id'] ?? 0);
            $serial = $this->normalize_serial((string) ($scan['serial'] ?? ''));
            if ($upc === '' && $item_id <= 0) {
                continue;
            }

            $target = $this->scan_target($expected, $remaining, $upc, $item_id);
            if ($target < 0) {
                return new WP_Error('fflhub_wms_confirm_unexpected_scan', sprintf('Unexpected or extra package scan: %s.', $upc !== '' ? $upc : 'item #' . $item_id));
            }

            $row = $expected[$target];
            if (!empty($row['ffl_required'])) {
                if ($serial === '') {
                    return new WP_Error('fflhub_wms_confirm_missing_serial', sprintf('Serial number is required for %s.', (string) ($row['name'] ?? 'this firearm')));
                }

                $valid_serials = array_values(array_filter((array) ($row['serials'] ?? [])));
                if (!$debug_ready && empty($valid_serials)) {
                    return new WP_Error('fflhub_wms_confirm_no_serial_saved', sprintf('No received serial is saved for %s.', (string) ($row['name'] ?? 'this firearm')));
                }
                if (!$debug_ready && !in_array($serial, $valid_serials, true)) {
                    return new WP_Error('fflhub_wms_confirm_serial_mismatch', sprintf('Serial %s does not match the received serial for %s.', $serial, (string) ($row['name'] ?? 'this firearm')));
                }
                if (!$debug_ready && in_array($serial, $used_serials[$target], true)) {
                    return new WP_Error('fflhub_wms_confirm_duplicate_serial', sprintf('Serial %s was already scanned for this package.', $serial));
                }

                $used_serials[$target][] = $serial;
            }

            $remaining[$target]--;
            $accepted[] = [
                'item_id' => (int) ($row['item_id'] ?? 0),
                'upc' => (string) ($row['upc'] ?? ''),
                'serial' => !empty($row['ffl_required']) ? $serial : '',
            ];
        }

        foreach ($remaining as $index => $count) {
            if ($count > 0) {
                return new WP_Error('fflhub_wms_confirm_missing_scan', sprintf(
                    '%d scan(s) still needed for %s.',
                    $count,
                    (string) ($expected[$index]['name'] ?? 'an item')
                ));
            }
        }

        return ['scans' => $accepted];
    }

    /**
     * @param array<int,array<string,mixed>> $expected
     * @param array<int,int> $remaining
     */
    private function scan_target(array $expected, array $remaining, string $upc, int $item_id): int
    {
        foreach ($expected as $index => $row) {
            if (($remaining[$index] ?? 0) <= 0) {
                continue;
            }

            if ($item_id > 0 && (int) ($row['item_id'] ?? 0) === $item_id) {
                return $index;
            }

            if ($upc !== '' && (string) ($row['upc'] ?? '') === $upc) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * @param array<int,array<string,mixed>> $expected
     * @param array<string,mixed> $label
     * @return object|WP_Error
     */
    private function build_fulfillment(WC_Order $order, array $expected, array $label, bool $debug_ready)
    {
        $items = [];
        foreach ($expected as $row) {
            $item_id = (int) ($row['item_id'] ?? 0);
            if ($item_id <= 0) {
                continue;
            }
            $items[$item_id] = [
                'item_id' => $item_id,
                'qty' => (int) (($items[$item_id]['qty'] ?? 0) + max(1, (int) ($row['quantity'] ?? 1))),
            ];
        }

        if (empty($items)) {
            return new WP_Error('fflhub_wms_confirm_no_fulfillment_items', 'Could not build Woo fulfillment item list for this package.');
        }

        $tracking_number = trim((string) ($label['tracking_number'] ?? ''));
        if ($tracking_number === '' && $debug_ready) {
            $tracking_number = 'DEBUG-TRACKING';
        }
        $tracking_url = trim((string) ($label['tracking_url'] ?? ''));
        if ($tracking_url === '' && $tracking_number !== '') {
            $tracking_url = $this->tracking_url((string) ($label['carrier_code'] ?? ''), $tracking_number);
        }

        $provider = $this->shipment_provider($label);
        $fulfillment = $this->new_woo_fulfillment();
        if (!is_object($fulfillment)) {
            $fulfillment = new WMSFallbackFulfillment();
        }

        $fulfillment->set_entity_type(WC_Order::class);
        $fulfillment->set_entity_id((string) $order->get_id());
        $fulfillment->set_items(array_values($items));
        $fulfillment->set_status('fulfilled');
        $fulfillment->set_date_fulfilled(current_time('mysql', true));
        $fulfillment->add_meta_data('_tracking_number', $tracking_number, true);
        $fulfillment->add_meta_data('_tracking_url', $tracking_url, true);
        $fulfillment->add_meta_data('_shipment_provider', $provider, true);
        $fulfillment->add_meta_data('_provider_name', $provider, true);
        $fulfillment->add_meta_data('_shipping_option', 'tracking-number', true);
        $fulfillment->add_meta_data('_fflhub_wms_package_index', max(0, (int) ($label['package_index'] ?? 0)), true);
        $fulfillment->add_meta_data('_fflhub_wms_label_id', (string) ($label['label_id'] ?? ''), true);
        $fulfillment->set_locked(true, 'Created by FFL Hub WMS package confirmation.');

        return $fulfillment;
    }

    /**
     * @return object|null
     */
    private function new_woo_fulfillment(): ?object
    {
        foreach ($this->woo_fulfillment_classes() as $class) {
            if (!class_exists($class)) {
                continue;
            }

            try {
                return new $class();
            } catch (\Throwable $exception) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function woo_fulfillment_classes(): array
    {
        return [
            'Automattic\\WooCommerce\\Admin\\Features\\Fulfillments\\Fulfillment',
            'Automattic\\WooCommerce\\Internal\\Fulfillments\\Fulfillment',
        ];
    }

    private function is_real_fulfillment_record(object $fulfillment): bool
    {
        return in_array(get_class($fulfillment), $this->woo_fulfillment_classes(), true)
            && method_exists($fulfillment, 'save')
            && method_exists($fulfillment, 'get_id');
    }

    private function send_fulfillment_email(WC_Order $order, object $fulfillment, bool $debug_ready, string $debug_recipient): bool
    {
        if (function_exists('WC') && WC()) {
            WC()->mailer();
        }

        $recipient_filter = null;
        $enabled_filter = null;
        if ($debug_ready) {
            $recipient_filter = static function ($recipient, $object, $email) use ($debug_recipient) {
                return $debug_recipient;
            };
            $enabled_filter = static function ($enabled, $object, $email) {
                return true;
            };
            add_filter('woocommerce_email_recipient_customer_fulfillment_created', $recipient_filter, 10, 3);
            add_filter('woocommerce_email_enabled_customer_fulfillment_created', $enabled_filter, 10, 3);
        }

        try {
            if ($debug_ready) {
                return $this->trigger_fulfillment_email_directly($order, $fulfillment);
            }

            do_action('woocommerce_fulfillment_created_notification', $order->get_id(), $fulfillment, $order);
            return true;
        } finally {
            if ($recipient_filter !== null) {
                remove_filter('woocommerce_email_recipient_customer_fulfillment_created', $recipient_filter, 10);
            }
            if ($enabled_filter !== null) {
                remove_filter('woocommerce_email_enabled_customer_fulfillment_created', $enabled_filter, 10);
            }
        }
    }

    private function trigger_fulfillment_email_directly(WC_Order $order, object $fulfillment): bool
    {
        if (!function_exists('WC') || !WC()) {
            return false;
        }

        $emails = WC()->mailer()->get_emails();
        foreach ($emails as $email) {
            if (is_object($email) && (string) ($email->id ?? '') === 'customer_fulfillment_created' && method_exists($email, 'trigger')) {
                $email->trigger($order->get_id(), $fulfillment, $order);
                return true;
            }
        }

        return false;
    }

    private function all_packages_confirmed(WC_Order $order, int $wave_batch_id, int $easypost_batch_id): bool
    {
        $labels = $this->active_labels($order, $easypost_batch_id > 0 ? (string) $easypost_batch_id : '');
        if (empty($labels)) {
            return false;
        }

        $confirmations = $this->confirmations($order);
        foreach ($labels as $label) {
            $index = max(0, (int) ($label['package_index'] ?? 0));
            $key = $this->confirmation_key($wave_batch_id, $easypost_batch_id, $index);
            if (empty($confirmations[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function confirmations(WC_Order $order): array
    {
        $value = $order->get_meta(self::CONFIRMATIONS_META_KEY, true);
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function confirmation_key(int $wave_batch_id, int $easypost_batch_id, int $package_index): string
    {
        return implode(':', [
            max(0, $wave_batch_id),
            max(0, $easypost_batch_id),
            max(0, $package_index),
        ]);
    }

    /**
     * @param array<string,mixed> $label
     */
    private function shipment_provider(array $label): string
    {
        foreach (['carrier_friendly_name', 'carrier_nickname', 'carrier_code', 'provider_label', 'provider_id'] as $key) {
            $value = trim((string) ($label[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'Shipping carrier';
    }

    private function tracking_url(string $carrier, string $tracking_number): string
    {
        $carrier = strtolower($carrier);
        $tracking_number = rawurlencode($tracking_number);
        if (strpos($carrier, 'usps') !== false) {
            return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $tracking_number;
        }
        if (strpos($carrier, 'ups') !== false) {
            return 'https://www.ups.com/track?tracknum=' . $tracking_number;
        }
        if (strpos($carrier, 'fedex') !== false) {
            return 'https://www.fedex.com/fedextrack/?trknbr=' . $tracking_number;
        }

        return '';
    }

    private function debug_recipient(): string
    {
        $user = wp_get_current_user();
        $email = $user && is_email((string) $user->user_email) ? (string) $user->user_email : '';
        if ($email === '') {
            $email = (string) get_option('admin_email');
        }

        return is_email($email) ? $email : 'admin@localhost.test';
    }

    /**
     * @param mixed[] $serials
     * @return string[]
     */
    private function clean_serials(array $serials): array
    {
        $out = [];
        foreach ($serials as $serial) {
            $serial = $this->normalize_serial((string) $serial);
            if ($serial !== '') {
                $out[] = $serial;
            }
        }

        return array_values($out);
    }

    private function normalize_upc(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    private function normalize_serial(string $value): string
    {
        return strtoupper(trim(sanitize_text_field($value)));
    }

    /**
     * @param mixed $value
     */
    private function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on', 'y'], true);
    }
}
