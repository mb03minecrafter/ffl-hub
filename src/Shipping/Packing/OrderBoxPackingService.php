<?php
declare(strict_types=1);

namespace FFLHub\Shipping\Packing;

use DVDoug\BoxPacker\Exception\NoBoxesAvailableException;
use DVDoug\BoxPacker\ItemList;
use DVDoug\BoxPacker\Packer;
use DVDoug\BoxPacker\PackedBox;
use DVDoug\BoxPacker\PackedItem;
use DVDoug\BoxPacker\Rotation;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementProductUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsSchema;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Shipping\ShippingOptions;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Packs the dealer-fulfilled portion of a Woo order into available boxes.
 *
 * This is intentionally provider-agnostic. It does not buy labels and it does
 * not mutate WooCommerce. It simply turns our order/product_state/package data
 * into a deterministic packing plan that ShipStation, EasyPost, or a future UI
 * can consume.
 */
final class OrderBoxPackingService
{
    private const INCH_TO_MM = 25.4;
    private const OUNCE_TO_GRAM = 28.349523125;
    private const DEFAULT_MAX_WEIGHT_OZ = 1120.0; // 70 lb carrier default.

    /**
     * Pack dealer-fulfilled order lines using explicit boxes or saved package
     * presets. Passing explicit boxes is best for tests; omitting the argument
     * lets the service use the shared FFLHub package preset settings.
     *
     * Box input shape can be either the shared preset shape:
     * - id, name, package_code, length, width, height, weight_oz
     *
     * Or a fuller packing shape:
     * - outer_length_in, outer_width_in, outer_height_in
     * - inner_length_in, inner_width_in, inner_height_in
     * - empty_weight_oz, max_weight_oz
     *
     * @param array<int,array<string,mixed>> $available_boxes
     * @return array<string,mixed>
     */
    public function pack_dealer_fulfilled_order(WC_Order $order, array $available_boxes = []): array
    {
        if (!class_exists(Packer::class)) {
            return $this->empty_result($order, [
                'BoxPacker is not installed. Run composer install for dvdoug/boxpacker.',
            ]);
        }

        $box_rows = !empty($available_boxes) ? $available_boxes : ShippingOptions::package_presets();
        $boxes = $this->normalize_boxes($box_rows);
        $item_result = $this->dealer_fulfilled_items_for_order($order);
        $items = $item_result['items'];
        $ignored_items = $item_result['ignored_items'];
        $unpacked_items = $item_result['unpacked_items'];
        $errors = [];

        if (empty($boxes) && !empty($items)) {
            $errors[] = 'No packable box presets were supplied. Boxes need positive length, width, and height.';
        }

        if (empty($items) || empty($boxes)) {
            return $this->result(
                $order,
                [],
                $unpacked_items,
                $ignored_items,
                $errors,
                $item_result['dealer_fulfilled_units'],
                count($boxes),
                $this->candidate_boxes_summary($boxes, $items)
            );
        }

        $packer = new Packer();
        if (method_exists($packer, 'throwOnUnpackableItem')) {
            $packer->throwOnUnpackableItem(false);
        }

        foreach ($boxes as $box) {
            $packer->addBox($box);
        }

        foreach ($items as $entry) {
            $item = $entry['item'];
            if ($item instanceof PackingItem) {
                $packer->addItem($item, max(1, (int) ($entry['quantity'] ?? 1)));
            }
        }

        try {
            $packed_boxes = $packer->pack();
        } catch (NoBoxesAvailableException $e) {
            $errors[] = $e->getMessage();
            $unpacked_items = array_merge($unpacked_items, $this->unpacked_items_from_list($e->getAffectedItems(), 'no_matching_box'));

            return $this->result(
                $order,
                [],
                $unpacked_items,
                $ignored_items,
                $errors,
                $item_result['dealer_fulfilled_units'],
                count($boxes),
                $this->candidate_boxes_summary($boxes, $items)
            );
        } catch (\Throwable $e) {
            $errors[] = 'BoxPacker failed: ' . $e->getMessage();
            if (method_exists($e, 'getItem') && $e->getItem() instanceof PackingItem) {
                $unpacked_items[] = [
                    ...$e->getItem()->summary(),
                    'quantity' => 1,
                    'reason' => 'no_matching_box',
                ];
            }

            return $this->result(
                $order,
                [],
                $unpacked_items,
                $ignored_items,
                $errors,
                $item_result['dealer_fulfilled_units'],
                count($boxes),
                $this->candidate_boxes_summary($boxes, $items)
            );
        }

        if (method_exists($packer, 'getUnpackedItems')) {
            $unpacked_items = array_merge(
                $unpacked_items,
                $this->unpacked_items_from_list($packer->getUnpackedItems(), 'no_matching_box')
            );
        }

        $packed_box_summaries = $this->packed_boxes_summary($packed_boxes);

        return $this->result(
            $order,
            $packed_box_summaries,
            $unpacked_items,
            $ignored_items,
            $errors,
            $item_result['dealer_fulfilled_units'],
            count($boxes),
            $this->candidate_boxes_summary($boxes, $items, $packed_box_summaries)
        );
    }

    /**
     * @param array<int,array<string,mixed>> $box_rows
     * @return PackingBox[]
     */
    private function normalize_boxes(array $box_rows): array
    {
        $boxes = [];

        foreach ($box_rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = sanitize_key((string) ($row['id'] ?? $row['box_id'] ?? ''));
            $name = trim((string) ($row['name'] ?? $row['box_name'] ?? $id));
            if ($name === '') {
                $name = 'Package';
            }

            $package_code = trim((string) ($row['package_code'] ?? 'package'));
            if ($package_code === '') {
                $package_code = 'package';
            }

            $outer_length = self::positive_float($row['outer_length_in'] ?? $row['length_in'] ?? $row['length'] ?? null);
            $outer_width = self::positive_float($row['outer_width_in'] ?? $row['width_in'] ?? $row['width'] ?? null);
            $outer_height = self::positive_float($row['outer_height_in'] ?? $row['height_in'] ?? $row['height'] ?? null);
            if ($outer_length === null || $outer_width === null || $outer_height === null) {
                continue;
            }

            $inner_length = self::positive_float($row['inner_length_in'] ?? null) ?? $outer_length;
            $inner_width = self::positive_float($row['inner_width_in'] ?? null) ?? $outer_width;
            $inner_height = self::positive_float($row['inner_height_in'] ?? null) ?? $outer_height;
            $empty_weight = self::positive_float($row['empty_weight_oz'] ?? $row['weight_oz'] ?? null) ?? 0.0;
            $max_weight = self::positive_float($row['max_weight_oz'] ?? null) ?? self::DEFAULT_MAX_WEIGHT_OZ;

            $boxes[] = new PackingBox(
                $id !== '' ? $id : sanitize_key($name),
                $name,
                $package_code,
                $outer_length,
                $outer_width,
                $outer_height,
                $inner_length,
                $inner_width,
                $inner_height,
                $empty_weight,
                $max_weight,
                self::inches_to_mm($outer_length),
                self::inches_to_mm($outer_width),
                self::inches_to_mm($outer_height),
                self::inches_to_mm($inner_length),
                self::inches_to_mm($inner_width),
                self::inches_to_mm($inner_height),
                self::ounces_to_grams($empty_weight),
                self::ounces_to_grams($max_weight),
                $row
            );
        }

        return $boxes;
    }

    /**
     * @return array{items:array<int,array{item:PackingItem,quantity:int}>,ignored_items:array<int,array<string,mixed>>,unpacked_items:array<int,array<string,mixed>>,dealer_fulfilled_units:int}
     */
    private function dealer_fulfilled_items_for_order(WC_Order $order): array
    {
        $job_quantities = $this->dealer_fulfilled_job_upc_quantities($order);
        $has_job_routing = !empty($job_quantities);
        $items = [];
        $ignored = [];
        $unpacked = [];
        $dealer_units = 0;

        foreach ($order->get_items('line_item') as $order_item) {
            if (!($order_item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $order_item->get_product();
            if (!($product instanceof WC_Product)) {
                $ignored[] = $this->ignored_order_item_summary($order_item, null, 'missing_product');
                continue;
            }

            $state_row = ProductStateStore::get_row_for_product($product);
            $upc = $this->upc_for_product($product, $state_row);
            $qty = max(0, (int) $order_item->get_quantity());
            if ($qty <= 0) {
                continue;
            }

            $pack_qty = 0;
            if ($has_job_routing) {
                $remaining = $upc !== '' ? (int) ($job_quantities[$upc] ?? 0) : 0;
                $pack_qty = max(0, min($qty, $remaining));
                if ($pack_qty > 0) {
                    $job_quantities[$upc] = max(0, $remaining - $pack_qty);
                }
            } else {
                $lane = $this->order_item_lane($order_item);
                if ($lane !== '') {
                    $pack_qty = OrderPlacementKeysUtil::is_dealer_fulfilled_lane($lane) ? $qty : 0;
                } else {
                    $dropship_enabled = self::boolish($state_row['dropship_enabled'] ?? null, true);
                    $pack_qty = $dropship_enabled ? 0 : $qty;
                }
            }

            if ($pack_qty <= 0) {
                $ignored[] = $this->ignored_order_item_summary(
                    $order_item,
                    $product,
                    $has_job_routing ? 'not_in_dealer_fulfilled_job' : 'direct_ship_or_dropship_enabled'
                );
                continue;
            }

            $dealer_units += $pack_qty;

            $measurements = $this->shipping_measurements_for_product($product, $state_row);
            $missing = $this->missing_measurement_keys($measurements);
            if (!empty($missing)) {
                $unpacked[] = [
                    ...$this->order_item_summary($order_item, $product),
                    'quantity' => $pack_qty,
                    'reason' => 'missing_measurements',
                    'missing' => $missing,
                ];
                continue;
            }

            $items[] = [
                'item' => new PackingItem(
                    'order_item_' . (int) $order_item->get_id(),
                    (int) $order_item->get_id(),
                    (int) $product->get_id(),
                    (int) $order_item->get_variation_id(),
                    $upc,
                    (string) $product->get_sku(),
                    (string) $order_item->get_name(),
                    (float) $measurements['length_in'],
                    (float) $measurements['width_in'],
                    (float) $measurements['height_in'],
                    (float) $measurements['weight_oz'],
                    self::inches_to_mm((float) $measurements['length_in']),
                    self::inches_to_mm((float) $measurements['width_in']),
                    self::inches_to_mm((float) $measurements['height_in']),
                    self::ounces_to_grams((float) $measurements['weight_oz']),
                    $this->allowed_rotation_for_product($product, $state_row)
                ),
                'quantity' => $pack_qty,
            ];
        }

        return [
            'items' => $items,
            'ignored_items' => $ignored,
            'unpacked_items' => $unpacked,
            'dealer_fulfilled_units' => $dealer_units,
        ];
    }

    /**
     * When the order-placement pipeline has job rows, those rows are the most
     * truthful source for what needs dealer packing. They reflect checkout lane
     * routing and any later dealer-batch optimizer move.
     *
     * @return array<string,int> normalized UPC => quantity
     */
    private function dealer_fulfilled_job_upc_quantities(WC_Order $order): array
    {
        global $wpdb;

        if (!$wpdb) {
            return [];
        }

        $jobs_table = new OrderPlacementJobsTable(new OrderPlacementJobsSchema());
        $table = $jobs_table->get_table_name();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!is_string($found) || $found !== $table) {
            return [];
        }

        $quantities = [];
        foreach (OrderPlacementJobsRepository::get_jobs_index($jobs_table, $order) as $job_key) {
            $job = OrderPlacementJobsRepository::get_job_for_order($jobs_table, $order, $job_key);
            if ($job === null || !OrderPlacementKeysUtil::is_dealer_fulfilled_lane($job->lane_norm())) {
                continue;
            }

            foreach ($job->payload_lines() as $line) {
                if (!($line instanceof DistributorOrderLine)) {
                    continue;
                }

                $upc = OrderPlacementProductUtil::normalize_upc((string) $line->upc);
                if ($upc === '') {
                    continue;
                }

                $quantities[$upc] = (int) ($quantities[$upc] ?? 0) + max(1, (int) $line->quantity);
            }
        }

        return $quantities;
    }

    /**
     * @param array<string,mixed>|null $state_row
     * @return array{weight_oz:?float,length_in:?float,width_in:?float,height_in:?float}
     */
    private function shipping_measurements_for_product(WC_Product $product, ?array $state_row): array
    {
        return [
            'weight_oz' => self::positive_float($state_row['shipping_weight_oz'] ?? null)
                ?? self::woo_weight_to_ounces((string) $product->get_weight()),
            'length_in' => self::positive_float($state_row['shipping_length_in'] ?? null)
                ?? self::woo_dimension_to_inches((string) $product->get_length()),
            'width_in' => self::positive_float($state_row['shipping_width_in'] ?? null)
                ?? self::woo_dimension_to_inches((string) $product->get_width()),
            'height_in' => self::positive_float($state_row['shipping_height_in'] ?? null)
                ?? self::woo_dimension_to_inches((string) $product->get_height()),
        ];
    }

    /**
     * @param array<string,mixed>|null $state_row
     */
    private function upc_for_product(WC_Product $product, ?array $state_row): string
    {
        $upc = OrderPlacementProductUtil::normalize_upc((string) ($state_row['upc'] ?? ''));
        if ($upc !== '') {
            return $upc;
        }

        return OrderPlacementProductUtil::normalize_upc((string) $product->get_global_unique_id());
    }

    private function order_item_lane(WC_Order_Item_Product $item): string
    {
        foreach ([
            'fflhub_fulfillment_lane',
            '_fflhub_fulfillment_lane',
            'fflhub_lane',
            '_fflhub_lane',
            'fflhub_route',
            '_fflhub_route',
            'fflhub_order_lane',
            '_fflhub_order_lane',
        ] as $key) {
            $lane = OrderPlacementKeysUtil::normalize_lane((string) $item->get_meta($key, true));
            if (OrderPlacementKeysUtil::is_valid_lane($lane)) {
                return $lane;
            }
            if ($lane === 'direct_ship') {
                return OrderPlacementKeysUtil::LANE_DIRECT_SHIP_NON_FFL;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed>|null $state_row
     */
    private function allowed_rotation_for_product(WC_Product $product, ?array $state_row): Rotation
    {
        $raw = strtolower(trim((string) ($state_row['packing_rotation'] ?? $product->get_meta('_fflhub_packing_rotation', true))));
        if ($raw === 'keep_flat' || $raw === 'flat') {
            return Rotation::KeepFlat;
        }
        if ($raw === 'never' || $raw === 'none') {
            return Rotation::Never;
        }

        return Rotation::BestFit;
    }

    /**
     * @param iterable<PackedBox> $packed_boxes
     * @return array<int,array<string,mixed>>
     */
    private function packed_boxes_summary(iterable $packed_boxes): array
    {
        $out = [];

        foreach ($packed_boxes as $packed_box) {
            if (!($packed_box instanceof PackedBox)) {
                continue;
            }

            $box = $this->packed_box_source_box($packed_box);
            if (!($box instanceof PackingBox)) {
                continue;
            }

            $items = [];
            foreach ($this->packed_box_items($packed_box) as $packed_item) {
                if (!($packed_item instanceof PackedItem)) {
                    continue;
                }

                $packing_item = $this->packed_item_source_item($packed_item);
                if (!($packing_item instanceof PackingItem)) {
                    continue;
                }

                $key = $packing_item->getPackingKey();
                if (!isset($items[$key])) {
                    $items[$key] = [
                        ...$packing_item->summary(),
                        'quantity' => 0,
                        'placements' => [],
                    ];
                }

                $items[$key]['quantity']++;
                $items[$key]['placements'][] = [
                    'x_in' => self::mm_to_inches(self::packed_item_int($packed_item, 'getX', 'x')),
                    'y_in' => self::mm_to_inches(self::packed_item_int($packed_item, 'getY', 'y')),
                    'z_in' => self::mm_to_inches(self::packed_item_int($packed_item, 'getZ', 'z')),
                    'length_in' => self::mm_to_inches(self::packed_item_int($packed_item, 'getLength', 'length')),
                    'width_in' => self::mm_to_inches(self::packed_item_int($packed_item, 'getWidth', 'width')),
                    'height_in' => self::mm_to_inches(self::packed_item_int($packed_item, 'getDepth', 'depth')),
                ];
            }

            $out[] = [
                ...$box->summary(),
                'packed_weight_oz' => self::grams_to_ounces($packed_box->getWeight()),
                'packed_item_weight_oz' => self::grams_to_ounces($packed_box->getItemWeight()),
                'used_length_in' => self::mm_to_inches($packed_box->getUsedLength()),
                'used_width_in' => self::mm_to_inches($packed_box->getUsedWidth()),
                'used_height_in' => self::mm_to_inches($packed_box->getUsedDepth()),
                'volume_utilization_percent' => $packed_box->getVolumeUtilisation(),
                'items' => array_values($items),
            ];
        }

        return $out;
    }

    private function packed_box_source_box(PackedBox $packed_box): ?PackingBox
    {
        $box = method_exists($packed_box, 'getBox')
            ? $packed_box->getBox()
            : self::public_object_property($packed_box, 'box');

        return $box instanceof PackingBox ? $box : null;
    }

    /**
     * @return iterable<mixed>
     */
    private function packed_box_items(PackedBox $packed_box): iterable
    {
        $items = method_exists($packed_box, 'getItems')
            ? $packed_box->getItems()
            : self::public_object_property($packed_box, 'items');

        return is_iterable($items) ? $items : [];
    }

    /**
     * Shows how each candidate box compares against the full dealer-fulfilled
     * item set. For boxes BoxPacker did not choose, these are capacity estimates
     * rather than real placements: item volume divided by inner box volume, and
     * item weight divided by remaining weight capacity.
     *
     * @param PackingBox[] $boxes
     * @param array<int,array{item:PackingItem,quantity:int}> $items
     * @param array<int,array<string,mixed>> $packed_box_summaries
     * @return array<int,array<string,mixed>>
     */
    private function candidate_boxes_summary(array $boxes, array $items, array $packed_box_summaries = []): array
    {
        $totals = $this->packable_item_totals($items);
        $used_counts = [];
        foreach ($packed_box_summaries as $packed_box) {
            $box_id = (string) ($packed_box['box_id'] ?? '');
            if ($box_id !== '') {
                $used_counts[$box_id] = (int) ($used_counts[$box_id] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($boxes as $box) {
            if (!($box instanceof PackingBox)) {
                continue;
            }

            $inner_volume = max(0, $box->getInnerWidth() * $box->getInnerLength() * $box->getInnerDepth());
            $weight_capacity = max(0, $box->getMaxWeight() - $box->getEmptyWeight());
            $box_id = $box->getId();
            $volume_percent = $inner_volume > 0
                ? round($totals['volume_mm3'] / $inner_volume * 100, 1)
                : null;
            $weight_percent = $weight_capacity > 0
                ? round($totals['weight_g'] / $weight_capacity * 100, 1)
                : null;

            $out[] = [
                ...$box->summary(),
                'used_count' => (int) ($used_counts[$box_id] ?? 0),
                'was_chosen' => !empty($used_counts[$box_id]),
                'estimated_volume_utilization_percent' => $volume_percent,
                'estimated_weight_utilization_percent' => $weight_percent,
                'can_hold_by_volume' => $inner_volume > 0 && $totals['volume_mm3'] <= $inner_volume,
                'can_hold_by_weight' => $weight_capacity > 0 && $totals['weight_g'] <= $weight_capacity,
                'total_item_volume_in3' => self::mm3_to_cubic_inches($totals['volume_mm3']),
                'inner_volume_in3' => self::mm3_to_cubic_inches($inner_volume),
                'total_item_weight_oz' => self::grams_to_ounces($totals['weight_g']),
                'weight_capacity_oz' => self::grams_to_ounces($weight_capacity),
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array{item:PackingItem,quantity:int}> $items
     * @return array{volume_mm3:int,weight_g:int}
     */
    private function packable_item_totals(array $items): array
    {
        $volume = 0;
        $weight = 0;
        foreach ($items as $entry) {
            $item = $entry['item'] ?? null;
            if (!($item instanceof PackingItem)) {
                continue;
            }

            $quantity = max(1, (int) ($entry['quantity'] ?? 1));
            $volume += $item->getWidth() * $item->getLength() * $item->getDepth() * $quantity;
            $weight += $item->getWeight() * $quantity;
        }

        return [
            'volume_mm3' => $volume,
            'weight_g' => $weight,
        ];
    }

    private function packed_item_source_item(PackedItem $packed_item): ?PackingItem
    {
        $item = method_exists($packed_item, 'getItem')
            ? $packed_item->getItem()
            : self::public_object_property($packed_item, 'item');

        return $item instanceof PackingItem ? $item : null;
    }

    private static function packed_item_int(PackedItem $packed_item, string $method, string $property): int
    {
        if (method_exists($packed_item, $method)) {
            return (int) $packed_item->{$method}();
        }

        return (int) (self::public_object_property($packed_item, $property) ?? 0);
    }

    /**
     * @return mixed|null
     */
    private static function public_object_property(object $object, string $property)
    {
        if (!property_exists($object, $property)) {
            return null;
        }

        try {
            $reflection = new \ReflectionProperty($object, $property);
            if (!$reflection->isPublic()) {
                return null;
            }

            return $reflection->getValue($object);
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function unpacked_items_from_list(ItemList $items, string $reason): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($item instanceof PackingItem) {
                $out[] = [
                    ...$item->summary(),
                    'quantity' => 1,
                    'reason' => $reason,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array{weight_oz:?float,length_in:?float,width_in:?float,height_in:?float} $measurements
     * @return string[]
     */
    private function missing_measurement_keys(array $measurements): array
    {
        $missing = [];
        foreach (['weight_oz', 'length_in', 'width_in', 'height_in'] as $key) {
            if (self::positive_float($measurements[$key] ?? null) === null) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function ignored_order_item_summary(WC_Order_Item_Product $item, ?WC_Product $product, string $reason): array
    {
        return [
            ...$this->order_item_summary($item, $product),
            'quantity' => max(0, (int) $item->get_quantity()),
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function order_item_summary(WC_Order_Item_Product $item, ?WC_Product $product): array
    {
        $state_row = $product instanceof WC_Product ? ProductStateStore::get_row_for_product($product) : null;

        return [
            'order_item_id' => (int) $item->get_id(),
            'product_id' => $product instanceof WC_Product ? (int) $product->get_id() : (int) $item->get_product_id(),
            'variation_id' => (int) $item->get_variation_id(),
            'upc' => $product instanceof WC_Product ? $this->upc_for_product($product, $state_row) : '',
            'sku' => $product instanceof WC_Product ? (string) $product->get_sku() : '',
            'name' => (string) $item->get_name(),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $boxes
     * @param array<int,array<string,mixed>> $unpacked_items
     * @param array<int,array<string,mixed>> $ignored_items
     * @param array<int,array<string,mixed>> $candidate_boxes
     * @param string[] $errors
     * @return array<string,mixed>
     */
    private function result(
        WC_Order $order,
        array $boxes,
        array $unpacked_items,
        array $ignored_items,
        array $errors,
        int $dealer_fulfilled_units,
        int $candidate_box_count,
        array $candidate_boxes = []
    ): array {
        $packed_units = 0;
        foreach ($boxes as $box) {
            foreach ((array) ($box['items'] ?? []) as $item) {
                $packed_units += max(0, (int) ($item['quantity'] ?? 0));
            }
        }

        $has_unpacked = !empty($unpacked_items);

        return [
            'ok' => empty($errors) && !$has_unpacked,
            'order_id' => (int) $order->get_id(),
            'box_count' => count($boxes),
            'candidate_box_count' => $candidate_box_count,
            'dealer_fulfilled_units' => $dealer_fulfilled_units,
            'packed_units' => $packed_units,
            'ignored_item_count' => count($ignored_items),
            'unpacked_item_count' => count($unpacked_items),
            'boxes' => $boxes,
            'candidate_boxes' => $candidate_boxes,
            'unpacked_items' => $unpacked_items,
            'ignored_items' => $ignored_items,
            'errors' => $errors,
        ];
    }

    /**
     * @param string[] $errors
     * @return array<string,mixed>
     */
    private function empty_result(WC_Order $order, array $errors): array
    {
        return $this->result($order, [], [], [], $errors, 0, 0);
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

    private static function boolish($value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        $raw = strtolower(trim((string) $value));
        if (in_array($raw, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }
        if (in_array($raw, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        return (bool) $value;
    }

    private static function woo_weight_to_ounces(string $weight): ?float
    {
        $weight = trim($weight);
        if ($weight === '') {
            return null;
        }

        $value = (float) $weight;
        if ($value <= 0.0) {
            return null;
        }

        $unit = strtolower((string) get_option('woocommerce_weight_unit', 'lbs'));
        if (in_array($unit, ['lbs', 'lb', 'pound', 'pounds'], true)) {
            return $value * 16.0;
        }
        if (in_array($unit, ['oz', 'ounce', 'ounces'], true)) {
            return $value;
        }
        if (in_array($unit, ['kg', 'kilogram', 'kilograms'], true)) {
            return $value * 35.27396195;
        }
        if (in_array($unit, ['g', 'gram', 'grams'], true)) {
            return $value * 0.03527396195;
        }

        return $value;
    }

    private static function woo_dimension_to_inches(string $dimension): ?float
    {
        $dimension = trim($dimension);
        if ($dimension === '') {
            return null;
        }

        $value = (float) $dimension;
        if ($value <= 0.0) {
            return null;
        }

        $unit = strtolower((string) get_option('woocommerce_dimension_unit', 'in'));
        if (in_array($unit, ['in', 'inch', 'inches'], true)) {
            return $value;
        }
        if (in_array($unit, ['cm', 'centimeter', 'centimeters'], true)) {
            return $value * 0.3937007874;
        }
        if (in_array($unit, ['m', 'meter', 'meters'], true)) {
            return $value * 39.37007874;
        }
        if (in_array($unit, ['mm', 'millimeter', 'millimeters'], true)) {
            return $value * 0.03937007874;
        }
        if (in_array($unit, ['yd', 'yard', 'yards'], true)) {
            return $value * 36.0;
        }

        return $value;
    }

    private static function inches_to_mm(float $value): int
    {
        return max(1, (int) ceil($value * self::INCH_TO_MM));
    }

    private static function ounces_to_grams(float $value): int
    {
        return max(0, (int) ceil($value * self::OUNCE_TO_GRAM));
    }

    private static function mm_to_inches(int $value): float
    {
        return round((float) $value / self::INCH_TO_MM, 2);
    }

    private static function grams_to_ounces(int $value): float
    {
        return round((float) $value / self::OUNCE_TO_GRAM, 2);
    }

    private static function mm3_to_cubic_inches(int $value): float
    {
        return round((float) $value / (self::INCH_TO_MM ** 3), 2);
    }
}
