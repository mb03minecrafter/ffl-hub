<?php
declare(strict_types=1);

namespace FFLHub\WMS;

use FFLHub\Shipping\Packing\OrderBoxPackingService;
use FFLHub\Shipping\ShippingOptions;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only package recommendation pass for the WMS Sending queue.
 *
 * This service intentionally does not buy labels, update Woo order status, or
 * mark anything as shipped. It simply runs the same packing engine used by the
 * order label UI against the ready-to-ship rows so the Sending page can show
 * the package FFL Hub would currently select.
 */
final class SendingPackingService
{
    /**
     * @param array<int,array<string,mixed>> $orders
     * @return array{orders:array<int,array<string,mixed>>,stats:array<string,mixed>}
     */
    public function pack_ready_orders(array $orders): array
    {
        $started = microtime(true);
        $package_presets = $this->packable_on_hand_packages();
        $package_display = $this->package_display_map($package_presets);
        $packer = new OrderBoxPackingService();

        $stats = [
            'orders_seen' => count($orders),
            'orders_loaded' => 0,
            'orders_packed' => 0,
            'orders_failed' => 0,
            'packages_selected' => 0,
            'package_presets' => count($package_presets),
            'runtime_ms' => 0.0,
        ];

        foreach ($orders as &$row) {
            $order_id = (int) ($row['order_id'] ?? 0);
            $row['packing_status'] = 'not_run';
            $row['packing_errors'] = [];
            $row['selected_package'] = '';
            $row['selected_package_details'] = [];

            if ($order_id <= 0) {
                $this->mark_failed($row, ['Missing Woo order id.']);
                $stats['orders_failed']++;
                continue;
            }

            $order = wc_get_order($order_id);
            if (!($order instanceof WC_Order)) {
                $this->mark_failed($row, ['Woo order could not be loaded.']);
                $stats['orders_failed']++;
                continue;
            }

            $stats['orders_loaded']++;
            $result = $packer->pack_dealer_fulfilled_order($order, $package_presets);
            $this->apply_packing_result($row, $result, $package_display);

            if (!empty($row['packing_ok'])) {
                $stats['orders_packed']++;
                $stats['packages_selected'] += count((array) ($row['selected_package_details'] ?? []));
            } else {
                $stats['orders_failed']++;
            }
        }
        unset($row);

        $stats['runtime_ms'] = round((microtime(true) - $started) * 1000, 2);

        return [
            'orders' => $orders,
            'stats' => $stats,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function packable_on_hand_packages(): array
    {
        $rows = [];
        foreach (ShippingOptions::package_presets() as $preset) {
            if (!is_array($preset)) {
                continue;
            }

            if (
                self::positive_float($preset['length'] ?? null) === null ||
                self::positive_float($preset['width'] ?? null) === null ||
                self::positive_float($preset['height'] ?? null) === null
            ) {
                continue;
            }

            $rows[] = $preset;
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $result
     * @param array<string,array{box_id:string,box_name:string,dimensions:string,kind:string}> $package_display
     */
    private function apply_packing_result(array &$row, array $result, array $package_display): void
    {
        $errors = array_values(array_filter(array_map('strval', (array) ($result['errors'] ?? []))));
        $unpacked = (int) ($result['unpacked_item_count'] ?? 0);
        $dealer_units = (int) ($result['dealer_fulfilled_units'] ?? 0);
        $boxes = isset($result['boxes']) && is_array($result['boxes']) ? $result['boxes'] : [];

        if ($dealer_units <= 0) {
            $this->mark_failed($row, ['No dealer-fulfilled items were available for packing.']);
            return;
        }

        if (empty($result['ok']) || empty($boxes) || $unpacked > 0) {
            if ($unpacked > 0) {
                $errors[] = sprintf('%d item(s) could not be packed.', $unpacked);
            }
            if (empty($errors)) {
                $errors[] = 'No package preset could pack all dealer-fulfilled items.';
            }

            $this->mark_failed($row, $errors);
            return;
        }

        $details = $this->package_details($boxes, $package_display);
        $row['packing_ok'] = true;
        $row['packing_status'] = 'packed';
        $row['packing_errors'] = [];
        $row['selected_package_details'] = $details;
        $row['selected_package'] = $this->package_summary_label($details);
    }

    /**
     * @param array<string,mixed> $row
     * @param string[] $errors
     */
    private function mark_failed(array &$row, array $errors): void
    {
        $row['packing_ok'] = false;
        $row['packing_status'] = 'failed';
        $row['packing_errors'] = array_values(array_filter($errors));
        $row['selected_package'] = '';
        $row['selected_package_details'] = [];
    }

    /**
     * @param array<int,array<string,mixed>> $boxes
     * @param array<string,array{box_id:string,box_name:string,dimensions:string,kind:string}> $package_display
     * @return array<int,array<string,mixed>>
     */
    private function package_details(array $boxes, array $package_display): array
    {
        $details = [];

        foreach ($boxes as $box) {
            if (!is_array($box)) {
                continue;
            }

            $identity = $this->package_identity_for_box($box, $package_display);
            $details[] = [
                'box_id' => (string) ($identity['box_id'] ?? ''),
                'box_name' => (string) ($identity['box_name'] ?? ''),
                'dimensions' => (string) ($identity['dimensions'] ?? ''),
                'kind' => (string) ($identity['kind'] ?? ''),
                'packed_weight_oz' => self::positive_float($box['packed_weight_oz'] ?? null),
                'volume_utilization_percent' => self::positive_float($box['volume_utilization_percent'] ?? null),
                'items' => is_array($box['items'] ?? null) ? $box['items'] : [],
            ];
        }

        return $details;
    }

    /**
     * @param array<int,array<string,mixed>> $details
     */
    private function package_summary_label(array $details): string
    {
        if (empty($details)) {
            return '';
        }

        $counts = [];
        foreach ($details as $detail) {
            $name = trim((string) ($detail['box_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($detail['box_id'] ?? 'Package'));
            }
            if ($name === '') {
                $name = 'Package';
            }

            $counts[$name] = (int) ($counts[$name] ?? 0) + 1;
        }

        $parts = [];
        foreach ($counts as $name => $count) {
            $parts[] = $count > 1 ? ((string) $count . 'x ' . $name) : $name;
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<int,array<string,mixed>> $packages
     * @return array<string,array{box_id:string,box_name:string,dimensions:string,kind:string}>
     */
    private function package_display_map(array $packages): array
    {
        $map = [];
        foreach ($packages as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = sanitize_key((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $map[$id] = [
                'box_id' => $id,
                'box_name' => trim((string) ($row['name'] ?? $id)),
                'dimensions' => self::package_dimension_label($row),
                'kind' => self::package_type($row['kind'] ?? 'box'),
            ];
        }

        return $map;
    }

    /**
     * Envelope candidates append virtual thickness/orientation to the source
     * preset id. Collapse those internal candidates back to the saved package
     * row so the WMS table names the actual envelope you can grab.
     *
     * @param array<string,mixed> $box
     * @param array<string,array{box_id:string,box_name:string,dimensions:string,kind:string}> $package_display
     * @return array{box_id:string,box_name:string,dimensions:string,kind:string}
     */
    private function package_identity_for_box(array $box, array $package_display): array
    {
        $box_id = sanitize_key((string) ($box['box_id'] ?? ''));
        if ($box_id !== '' && isset($package_display[$box_id])) {
            return $package_display[$box_id];
        }

        uksort($package_display, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($package_display as $id => $display) {
            if ($id !== '' && strpos($box_id, $id . '_t') === 0) {
                return $display;
            }
        }

        return [
            'box_id' => $box_id,
            'box_name' => (string) ($box['box_name'] ?? $box_id),
            'dimensions' => self::box_dimension_label($box),
            'kind' => self::package_type($box['package_type'] ?? 'box'),
        ];
    }

    /**
     * @param array<string,mixed> $box
     */
    private static function package_dimension_label(array $box): string
    {
        $length = self::positive_float($box['length'] ?? $box['outer_length_in'] ?? null);
        $width = self::positive_float($box['width'] ?? $box['outer_width_in'] ?? null);
        $height = self::positive_float($box['height'] ?? $box['outer_height_in'] ?? null);
        if ($length === null || $width === null || $height === null) {
            return '';
        }

        return self::number_label($length) . ' x ' . self::number_label($width) . ' x ' . self::number_label($height) . ' in';
    }

    /**
     * @param array<string,mixed> $box
     */
    private static function box_dimension_label(array $box): string
    {
        $length = self::positive_float($box['outer_length_in'] ?? null);
        $width = self::positive_float($box['outer_width_in'] ?? null);
        $height = self::positive_float($box['outer_height_in'] ?? null);
        if ($length === null || $width === null || $height === null) {
            return '';
        }

        return self::number_label($length) . ' x ' . self::number_label($width) . ' x ' . self::number_label($height) . ' in';
    }

    /**
     * @param mixed $value
     */
    private static function positive_float($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;
        return $float > 0.0 ? $float : null;
    }

    /**
     * @param mixed $value
     */
    private static function package_type($value): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === 'package') {
            return 'box';
        }

        return in_array($value, ['box', 'envelope'], true) ? $value : 'box';
    }

    private static function number_label(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
