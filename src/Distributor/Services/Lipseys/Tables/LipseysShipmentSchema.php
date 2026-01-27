<?php

namespace FFLHub\Distributor\Services\Lipseys\Tables;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for the Lipsey's shipment ingestion table schema.
 *
 * This table stores results from the Lipsey's:
 *   POST api/Integration/Shipping/OneDay
 *
 * Characteristics:
 *  - Append-only (no table swapping / versioning).
 *  - Idempotent via composite PRIMARY KEY (po_number, tracking_number).
 *  - Designed for daily ingestion of "previous day" shipment data.
 *  - Optimized for lookup by PO number, order number, tracking number, and ship date.
 */
final class LipseysShipmentSchema
{
    /**
     * Base key used to build the table name (without $wpdb->prefix).
     */
    private const BASE_TABLE_KEY = 'fflhub_lipseys_shipments';

    public function get_base_table_key(): string
    {
        return self::BASE_TABLE_KEY;
    }

    /**
     * @return array<string,string> column_name => SQL definition
     */
    public function get_column_definitions(): array
    {
        return array(
            // -------------------------
            // Identity / linking
            // -------------------------

            // Your internal anchor back to order placement jobs + Woo orders.
            'po_number'        => 'VARCHAR(64)   NOT NULL',

            // Lipsey\'s identifiers (kept for traceability / support).
            'invoice_number'  => 'VARCHAR(64)   NULL',
            'order_number'    => 'VARCHAR(64)   NULL',

            // Shipment identifier (often unique per carton).
            'tracking_number' => 'VARCHAR(128)  NOT NULL',

            // -------------------------
            // Shipment metadata
            // -------------------------

            'shipping_service' => 'VARCHAR(128)  NULL',
            'weight'           => 'VARCHAR(32)   NULL',

            'bill_name'        => 'VARCHAR(255)  NULL',
            'ship_name'        => 'VARCHAR(255)  NULL',

            // -------------------------
            // Ingestion / audit metadata
            // -------------------------

            // The date we queried from Lipsey's (typically "yesterday", UTC).
            'ship_date'        => 'DATE          NOT NULL',

            // When this row was inserted into our system (UTC).
            'ingested_at'      => 'DATETIME      NOT NULL',

            // Source system identifier (future-proofing if you add others later).
            'source'           => "VARCHAR(32)   NOT NULL DEFAULT 'lipseys'",

            // Optional hash of raw payload for future diffing / audits.
            'raw_hash'         => 'CHAR(40)       NULL',
        );
    }

    /**
     * @return string[]
     */
    public function get_index_definitions(): array
    {
        return array(
            // Composite primary key ensures idempotency across re-runs.
            // Allows multiple shipments per PO (different tracking numbers).
            'PRIMARY KEY (po_number, tracking_number)',

            // Fast lookup by PO (job reconciliation).
            'KEY po_number (po_number)',

            // Fast lookup by Lipsey\'s order number (some APIs key off this).
            'KEY order_number (order_number)',

            // Fast lookup by tracking number (support, shipment tracing).
            'KEY tracking_number (tracking_number)',

            // Enables date-based audits / backfills.
            'KEY ship_date (ship_date)',
        );
    }

    /**
     * @return string[]
     */
    public function get_insert_columns(): array
    {
        $columns = array_keys($this->get_column_definitions());

        return array_values(
            array_filter(
                $columns,
                static function (string $col): bool {
                    return $col !== 'id';
                }
            )
        );
    }
}
