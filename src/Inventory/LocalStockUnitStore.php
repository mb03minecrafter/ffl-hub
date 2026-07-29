<?php
declare(strict_types=1);

namespace FFLHub\Inventory;

use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Receiving\ReceivingEventsStore;
use FFLHub\Receiving\ReceivingShipmentService;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Physical local inventory ledger.
 *
 * The normalized offer row for distributor_id=local_stock is only a summary.
 * This table is the durable source of truth for individual units, including
 * serial numbers, FastBound acquisition IDs, and order/job allocation.
 */
final class LocalStockUnitStore
{
    public const DISTRIBUTOR_ID = 'local_stock';

    public const STATUS_ON_HAND = 'on_hand';
    public const STATUS_ALLOCATED = 'allocated';
    public const STATUS_PACKED = 'packed';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DISPOSED = 'disposed';
    public const STATUS_QUARANTINED = 'quarantined';

    private const BASE_TABLE = 'fflhub_local_stock_units';
    private const SCHEMA_OPTION = 'fflhub_local_stock_units_schema_version';
    private const SCHEMA_VERSION = '1';

    private static bool $schema_checked = false;

    public static function table_name(): string
    {
        global $wpdb;

        return (string) ($wpdb->prefix . self::BASE_TABLE);
    }

    public static function ensure_schema(): void
    {
        global $wpdb;

        if (!$wpdb) {
            return;
        }

        if (
            self::$schema_checked
            || ((string) get_option(self::SCHEMA_OPTION, '') === self::SCHEMA_VERSION && self::table_exists())
        ) {
            self::$schema_checked = true;
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = (string) $wpdb->get_charset_collate();

        dbDelta("
            CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                upc VARCHAR(64) NOT NULL DEFAULT '',
                product_id BIGINT UNSIGNED DEFAULT NULL,
                serial_number VARCHAR(128) NOT NULL DEFAULT '',
                status VARCHAR(32) NOT NULL DEFAULT 'on_hand',
                source_distributor_id VARCHAR(64) NOT NULL DEFAULT '',
                source_contact_id VARCHAR(64) NOT NULL DEFAULT '',
                source_po VARCHAR(64) NOT NULL DEFAULT '',
                receiving_event_id BIGINT UNSIGNED DEFAULT NULL,
                fastbound_acquisition_item_id VARCHAR(64) NOT NULL DEFAULT '',
                allocated_order_id BIGINT UNSIGNED DEFAULT NULL,
                allocated_order_item_id BIGINT UNSIGNED DEFAULT NULL,
                allocated_job_id BIGINT UNSIGNED DEFAULT NULL,
                fastbound_disposition_id VARCHAR(64) NOT NULL DEFAULT '',
                cost_dealer_price DECIMAL(12,4) DEFAULT NULL,
                cost_shipping_cost DECIMAL(12,4) DEFAULT NULL,
                cost_landed_cost DECIMAL(12,4) DEFAULT NULL,
                received_at DATETIME NOT NULL,
                allocated_at DATETIME DEFAULT NULL,
                packed_at DATETIME DEFAULT NULL,
                shipped_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY upc_status (upc, status),
                KEY product_status (product_id, status),
                KEY serial_status (serial_number, status),
                KEY receiving_event_id (receiving_event_id),
                KEY allocated_order (allocated_order_id, allocated_order_item_id),
                KEY allocated_job_id (allocated_job_id),
                KEY status_updated_at (status, updated_at)
            ) {$charset};
        ");

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        self::$schema_checked = true;
    }

    public static function table_exists(): bool
    {
        global $wpdb;

        if (!$wpdb) {
            return false;
        }

        $table = self::table_name();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($found) && $found === $table;
    }

    /**
     * Create one ledger unit from a receiving event.
     *
     * @return array<string,mixed>
     */
    public static function create_unit_from_event(int $event_id, string $status = self::STATUS_ON_HAND, string $source_contact_id = ''): array
    {
        global $wpdb;

        self::ensure_schema();

        $event = (new ReceivingEventsStore())->find_by_id($event_id);
        if (!is_array($event) || (string) ($event['result'] ?? '') !== 'accepted') {
            return self::error('event_not_found', 'Accepted receiving event was not found.');
        }

        $status = self::normalize_status($status);
        $upc = ReceivingShipmentService::normalize_upc((string) ($event['upc'] ?? ''));
        if ($upc === '') {
            return self::error('missing_upc', 'Receiving event is missing a UPC.');
        }

        $product_id = (int) ($event['product_id'] ?? 0);
        $state = ProductStateStore::get_row_for_upc($upc);
        if ($product_id <= 0 && is_array($state)) {
            $product_id = (int) ($state['product_id'] ?? 0);
        }

        $costs = self::cost_snapshot($state);
        $now = current_time('mysql', true);
        $allocated = in_array($status, [self::STATUS_ALLOCATED, self::STATUS_PACKED, self::STATUS_SHIPPED, self::STATUS_DISPOSED], true);

        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'upc' => $upc,
                'product_id' => $product_id > 0 ? $product_id : null,
                'serial_number' => ReceivingShipmentService::normalize_serial((string) ($event['serial_number'] ?? '')),
                'status' => $status,
                'source_distributor_id' => strtolower(trim((string) ($event['dist_id'] ?? ''))),
                'source_contact_id' => substr(trim($source_contact_id), 0, 64),
                'source_po' => (string) ($event['merchant_po'] ?? ''),
                'receiving_event_id' => (int) ($event['id'] ?? 0),
                'fastbound_acquisition_item_id' => (string) ($event['fastbound_acquisition_item_id'] ?? ''),
                'allocated_order_id' => $allocated ? self::nullable_positive_int($event['order_id'] ?? null) : null,
                'allocated_order_item_id' => $allocated ? self::nullable_positive_int($event['order_item_id'] ?? null) : null,
                'allocated_job_id' => $allocated ? self::nullable_positive_int($event['job_id'] ?? null) : null,
                'fastbound_disposition_id' => (string) ($event['fastbound_disposition_id'] ?? ''),
                'cost_dealer_price' => $costs['dealer_price'],
                'cost_shipping_cost' => $costs['shipping_cost'],
                'cost_landed_cost' => $costs['landed_cost'],
                'received_at' => (string) ($event['received_at'] ?? $now),
                'allocated_at' => $allocated ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d',
                '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s',
            ]
        );

        if ($inserted === false) {
            return self::error('insert_failed', (string) $wpdb->last_error);
        }

        self::sync_offer_for_upc($upc);

        return [
            'ok' => true,
            'unit_id' => (int) $wpdb->insert_id,
            'upc' => $upc,
            'status' => $status,
        ];
    }

    /**
     * Allocate on-hand ledger units to an existing local-stock order job.
     *
     * @return array<string,mixed>
     */
    public static function allocate_units_to_order(string $upc, int $qty, int $order_id, int $order_item_id, int $job_id): array
    {
        global $wpdb;

        self::ensure_schema();

        $upc = ReceivingShipmentService::normalize_upc($upc);
        $qty = max(0, $qty);
        if ($upc === '' || $qty < 1 || $order_id <= 0 || $order_item_id <= 0 || $job_id <= 0) {
            return self::error('invalid_allocation_input', 'A valid UPC, quantity, order item, and job row are required.');
        }

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id
                 FROM " . self::table_name() . "
                 WHERE upc = %s
                   AND status = %s
                 ORDER BY
                   serial_number <> '' DESC,
                   received_at ASC,
                   id ASC
                 LIMIT %d",
                $upc,
                self::STATUS_ON_HAND,
                $qty
            )
        );

        $ids = array_values(array_filter(array_map('absint', is_array($ids) ? $ids : [])));
        if (count($ids) < $qty) {
            return self::error('insufficient_local_stock_units', sprintf(
                'Only %d local-stock unit(s) are on hand for UPC %s; %d requested.',
                count($ids),
                $upc,
                $qty
            ));
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $now = current_time('mysql', true);
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::table_name() . "
                 SET status = %s,
                     allocated_order_id = %d,
                     allocated_order_item_id = %d,
                     allocated_job_id = %d,
                     allocated_at = %s,
                     updated_at = %s
                 WHERE id IN ({$placeholders})
                   AND status = %s",
                self::STATUS_ALLOCATED,
                $order_id,
                $order_item_id,
                $job_id,
                $now,
                $now,
                ...array_merge($ids, [self::STATUS_ON_HAND])
            )
        );

        if ($updated === false) {
            return self::error('allocation_failed', (string) $wpdb->last_error);
        }

        self::attach_receiving_events_to_allocation($ids, $order_id, $order_item_id, $job_id);
        self::sync_offer_for_upc($upc);

        return [
            'ok' => true,
            'upc' => $upc,
            'allocated' => (int) $updated,
            'unit_ids' => $ids,
        ];
    }

    public static function available_qty_for_upc(string $upc): int
    {
        global $wpdb;

        self::ensure_schema();

        $upc = ReceivingShipmentService::normalize_upc($upc);
        if ($upc === '') {
            return 0;
        }

        return max(0, (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM " . self::table_name() . "
                 WHERE upc = %s
                   AND status = %s",
                $upc,
                self::STATUS_ON_HAND
            )
        ));
    }

    /**
     * Mirror a corrected receiving-event serial into the physical unit ledger.
     *
     * @return array<string,mixed>
     */
    public static function update_serial_for_receiving_event(int $event_id, string $new_serial_number): array
    {
        global $wpdb;

        self::ensure_schema();

        $event_id = absint($event_id);
        $new_serial_number = ReceivingShipmentService::normalize_serial($new_serial_number);
        if ($event_id <= 0 || $new_serial_number === '') {
            return self::error('invalid_serial_update', 'A valid receiving event and serial number are required.');
        }

        $table = self::table_name();
        $updated = $wpdb->update(
            $table,
            [
                'serial_number' => $new_serial_number,
                'updated_at' => current_time('mysql', true),
            ],
            ['receiving_event_id' => $event_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return self::error('local_stock_unit_serial_update_failed', (string) $wpdb->last_error);
        }

        return [
            'ok' => true,
            'updated' => (int) $updated,
            'event_id' => $event_id,
            'serial_number' => $new_serial_number,
        ];
    }

    /**
     * Publish one aggregated local_stock row into distributor_offers.
     *
     * @return array<string,mixed>
     */
    public static function sync_offer_for_upc(string $upc): array
    {
        global $wpdb;

        self::ensure_schema();
        DistributorOffersStore::ensure_schema();

        $upc = ReceivingShipmentService::normalize_upc($upc);
        if ($upc === '') {
            return self::error('missing_upc', 'UPC is required.');
        }

        $state = ProductStateStore::get_row_for_upc($upc);
        if (!is_array($state)) {
            return self::error('missing_product_state', 'Product state row was not found for local-stock offer sync.');
        }

        $agg = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) AS qty,
                    AVG(NULLIF(cost_dealer_price, 0)) AS dealer_price,
                    AVG(NULLIF(cost_landed_cost, 0)) AS landed_cost
                 FROM " . self::table_name() . "
                 WHERE upc = %s
                   AND status = %s",
                $upc,
                self::STATUS_ON_HAND
            ),
            ARRAY_A
        );

        $qty = max(0, (int) ($agg['qty'] ?? 0));
        $dealer_price = self::positive_float_or_null($agg['dealer_price'] ?? null);
        $landed_cost = self::positive_float_or_null($agg['landed_cost'] ?? null);
        if ($dealer_price === null) {
            $dealer_price = self::positive_float_or_null($state['dealer_price'] ?? null);
        }
        if ($landed_cost === null) {
            $landed_cost = self::positive_float_or_null($state['landed_cost'] ?? null);
        }
        if ($landed_cost === null) {
            $landed_cost = $dealer_price;
        }

        $enabled = ($qty > 0 && $landed_cost !== null && $landed_cost > 0.0) ? 1 : 0;
        $now = current_time('mysql', true);
        $offers_table = DistributorOffersStore::table_name();

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$offers_table} (
                    upc,
                    distributor_id,
                    distributor_product_id,
                    distributor_sku,
                    manufacturer_norm,
                    qty,
                    stock_status,
                    dealer_price,
                    shipping_cost,
                    landed_cost,
                    map_price,
                    msrp,
                    ffl_required,
                    sot_required,
                    dropship_enabled,
                    enabled,
                    has_changed,
                    shipping_weight_oz,
                    shipping_length_in,
                    shipping_width_in,
                    shipping_height_in,
                    source_updated_at,
                    normalized_at
                ) VALUES (
                    %s, %s, %s, %s, %s,
                    %d, %s, %f, %f, %f,
                    %f, %f, %d, %d, %d,
                    %d, %d, %f, %f, %f,
                    %f, %s, %s
                )
                ON DUPLICATE KEY UPDATE
                    distributor_product_id = VALUES(distributor_product_id),
                    distributor_sku = VALUES(distributor_sku),
                    manufacturer_norm = VALUES(manufacturer_norm),
                    qty = VALUES(qty),
                    stock_status = VALUES(stock_status),
                    dealer_price = VALUES(dealer_price),
                    shipping_cost = VALUES(shipping_cost),
                    landed_cost = VALUES(landed_cost),
                    map_price = VALUES(map_price),
                    msrp = VALUES(msrp),
                    ffl_required = VALUES(ffl_required),
                    sot_required = VALUES(sot_required),
                    dropship_enabled = VALUES(dropship_enabled),
                    enabled = VALUES(enabled),
                    has_changed = 1,
                    shipping_weight_oz = VALUES(shipping_weight_oz),
                    shipping_length_in = VALUES(shipping_length_in),
                    shipping_width_in = VALUES(shipping_width_in),
                    shipping_height_in = VALUES(shipping_height_in),
                    source_updated_at = VALUES(source_updated_at),
                    normalized_at = VALUES(normalized_at)",
                $upc,
                self::DISTRIBUTOR_ID,
                $upc,
                'LOCAL-' . $upc,
                (string) ($state['manufacturer_norm'] ?? ''),
                $qty,
                $qty > 0 ? 'instock' : 'outofstock',
                (float) ($dealer_price ?? 0.0),
                0.0,
                (float) ($landed_cost ?? 0.0),
                (float) (self::positive_float_or_null($state['map_price'] ?? null) ?? 0.0),
                (float) (self::positive_float_or_null($state['msrp'] ?? null) ?? 0.0),
                ((int) ($state['ffl_required'] ?? 0) === 1) ? 1 : 0,
                ((int) ($state['sot_required'] ?? 0) === 1) ? 1 : 0,
                0,
                $enabled,
                1,
                (float) (self::positive_float_or_null($state['shipping_weight_oz'] ?? null) ?? 0.0),
                (float) (self::positive_float_or_null($state['shipping_length_in'] ?? null) ?? 0.0),
                (float) (self::positive_float_or_null($state['shipping_width_in'] ?? null) ?? 0.0),
                (float) (self::positive_float_or_null($state['shipping_height_in'] ?? null) ?? 0.0),
                $now,
                $now
            )
        );

        if ($result === false) {
            return self::error('offer_sync_failed', (string) $wpdb->last_error);
        }

        return [
            'ok' => true,
            'upc' => $upc,
            'qty' => $qty,
            'enabled' => $enabled,
            'dealer_price' => $dealer_price,
            'landed_cost' => $landed_cost,
        ];
    }

    /**
     * @param int[] $unit_ids
     */
    private static function attach_receiving_events_to_allocation(array $unit_ids, int $order_id, int $order_item_id, int $job_id): void
    {
        global $wpdb;

        $unit_ids = array_values(array_filter(array_map('absint', $unit_ids)));
        if (empty($unit_ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($unit_ids), '%d'));
        $event_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT receiving_event_id
                 FROM " . self::table_name() . "
                 WHERE id IN ({$placeholders})
                   AND receiving_event_id IS NOT NULL",
                ...$unit_ids
            )
        );

        $event_ids = array_values(array_filter(array_map('absint', is_array($event_ids) ? $event_ids : [])));
        if (empty($event_ids)) {
            return;
        }

        $event_placeholders = implode(',', array_fill(0, count($event_ids), '%d'));
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . ReceivingEventsStore::table_name() . "
                 SET order_id = %d,
                     order_item_id = %d,
                     job_id = %d,
                     lane = %s,
                     message = %s
                 WHERE id IN ({$event_placeholders})",
                $order_id,
                $order_item_id,
                $job_id,
                'dealer_fulfilled',
                'Local-stock unit allocated to order.',
                ...$event_ids
            )
        );
    }

    /**
     * @param array<string,mixed>|null $state
     * @return array{dealer_price:?float,shipping_cost:?float,landed_cost:?float}
     */
    private static function cost_snapshot(?array $state): array
    {
        $dealer = self::positive_float_or_null($state['dealer_price'] ?? null);
        $shipping = self::non_negative_float_or_null($state['shipping_cost'] ?? null);
        $landed = self::positive_float_or_null($state['landed_cost'] ?? null);

        if ($landed === null && $dealer !== null) {
            $landed = $dealer + (float) ($shipping ?? 0.0);
        }

        return [
            'dealer_price' => $dealer,
            'shipping_cost' => $shipping,
            'landed_cost' => $landed,
        ];
    }

    private static function normalize_status(string $status): string
    {
        $status = strtolower(trim($status));
        $valid = [
            self::STATUS_ON_HAND,
            self::STATUS_ALLOCATED,
            self::STATUS_PACKED,
            self::STATUS_SHIPPED,
            self::STATUS_DISPOSED,
            self::STATUS_QUARANTINED,
        ];

        return in_array($status, $valid, true) ? $status : self::STATUS_ON_HAND;
    }

    /**
     * @param mixed $value
     */
    private static function nullable_positive_int($value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * @param mixed $value
     */
    private static function positive_float_or_null($value): ?float
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
    private static function non_negative_float_or_null($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;

        return $float >= 0.0 ? $float : null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function error(string $code, string $message): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
        ];
    }
}
