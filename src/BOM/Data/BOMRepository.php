<?php
declare(strict_types=1);

namespace FFLHub\BOM\Data;

use FFLHub\BOM\Services\ProductLinkResolver;
use FFLHub\BOM\Tables\BOMSchema;
use FFLHub\BOM\Tables\BOMTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository for BOM rows.
 */
final class BOMRepository
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_rows_for_parent(BOMTable $table, int $parent_product_id): array
    {
        $parent_product_id = (int) $parent_product_id;
        if ($parent_product_id <= 0) {
            return [];
        }

        if (!self::table_exists($table)) {
            return [];
        }

        global $wpdb;

        $table_name = $table->get_table_name();
        $sql = $wpdb->prepare(
            "SELECT
                id,
                parent_product_id,
                sort_order,
                component_name,
                component_notes,
                quantity_required,
                source_type,
                source_ref,
                manual_unit_price,
                manual_qty_on_hand,
                resolved_unit_price,
                resolved_stock_state,
                resolved_stock_qty,
                resolved_error_code,
                resolved_at
             FROM {$table_name}
             WHERE parent_product_id = %d
             ORDER BY sort_order ASC, id ASC",
            $parent_product_id
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = [
                'id'                => (int) ($row['id'] ?? 0),
                'parent_product_id' => (int) ($row['parent_product_id'] ?? 0),
                'sort_order'        => (int) ($row['sort_order'] ?? 0),
                'name'              => trim((string) ($row['component_name'] ?? '')),
                'notes'             => (string) ($row['component_notes'] ?? ''),
                'qty'               => max(0.0001, (float) ($row['quantity_required'] ?? 1.0)),
                'source_type'       => self::normalize_source_type((string) ($row['source_type'] ?? '')),
                'source_ref'        => trim((string) ($row['source_ref'] ?? '')),
                'manual_unit_price' => is_numeric((string) ($row['manual_unit_price'] ?? null))
                    ? max(0.0, (float) $row['manual_unit_price'])
                    : null,
                'manual_qty_on_hand' => is_numeric((string) ($row['manual_qty_on_hand'] ?? null))
                    ? max(0, (int) $row['manual_qty_on_hand'])
                    : null,
                'resolved_unit_price' => is_numeric((string) ($row['resolved_unit_price'] ?? null))
                    ? max(0.0, (float) $row['resolved_unit_price'])
                    : null,
                'resolved_stock_state' => trim((string) ($row['resolved_stock_state'] ?? '')),
                'resolved_stock_qty' => is_numeric((string) ($row['resolved_stock_qty'] ?? null))
                    ? max(0, (int) $row['resolved_stock_qty'])
                    : null,
                'resolved_error_code' => trim((string) ($row['resolved_error_code'] ?? '')),
                'resolved_at' => trim((string) ($row['resolved_at'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * Replace all BOM rows for a parent product.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public static function replace_rows_for_parent(BOMTable $table, int $parent_product_id, array $rows): void
    {
        $parent_product_id = (int) $parent_product_id;
        if ($parent_product_id <= 0) {
            return;
        }

        global $wpdb;

        $table_name = $table->get_table_name();
        $wpdb->delete(
            $table_name,
            ['parent_product_id' => $parent_product_id],
            ['%d']
        );

        $now = gmdate('Y-m-d H:i:s');
        $sort_order = 0;

        foreach ($rows as $raw_row) {
            if (!is_array($raw_row)) {
                continue;
            }

            $normalized = self::normalize_input_row($raw_row);
            if ($normalized === null) {
                continue;
            }

            $wpdb->insert(
                $table_name,
                [
                    'parent_product_id' => $parent_product_id,
                    'sort_order'        => $sort_order,
                    'component_name'    => $normalized['name'],
                    'component_notes'   => $normalized['notes'],
                    'quantity_required' => $normalized['qty'],
                    'source_type'       => $normalized['source_type'],
                    'source_ref'        => $normalized['source_ref'],
                    'manual_unit_price' => $normalized['manual_unit_price'],
                    'manual_qty_on_hand' => $normalized['manual_qty_on_hand'],
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ],
                [
                    '%d',
                    '%d',
                    '%s',
                    '%s',
                    '%f',
                    '%s',
                    '%s',
                    '%f',
                    '%d',
                    '%s',
                    '%s',
                ]
            );

            $sort_order++;
        }
    }

    public static function normalize_source_type(string $source_type): string
    {
        $source_type = strtolower(trim($source_type));

        if ($source_type === BOMSchema::SOURCE_DISTRIBUTOR_UPC) {
            return BOMSchema::SOURCE_DISTRIBUTOR_UPC;
        }
        if ($source_type === BOMSchema::SOURCE_PRODUCT_LINK) {
            return BOMSchema::SOURCE_PRODUCT_LINK;
        }
        if ($source_type === BOMSchema::SOURCE_INTERNAL_STOCK) {
            return BOMSchema::SOURCE_INTERNAL_STOCK;
        }

        return '';
    }

    public static function normalize_upc(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (ctype_digit($raw)) {
            return $raw;
        }

        $v = preg_replace('/\D+/', '', $raw);
        $v = is_string($v) ? trim($v) : '';
        return ($v !== '' && ctype_digit($v)) ? $v : '';
    }

    private static function table_exists(BOMTable $table): bool
    {
        global $wpdb;

        $table_name = $table->get_table_name();
        $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $table_name);
        $exists = $wpdb->get_var($sql);

        return is_string($exists) && $exists !== '';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private static function normalize_input_row(array $row): ?array
    {
        $source_type = self::normalize_source_type((string) ($row['source_type'] ?? ''));
        if ($source_type === '') {
            return null;
        }

        $name = sanitize_text_field((string) ($row['name'] ?? ''));
        $notes = sanitize_textarea_field((string) ($row['notes'] ?? ''));

        $qty_raw = trim((string) ($row['qty'] ?? '1'));
        $qty = is_numeric($qty_raw) ? (float) $qty_raw : 1.0;
        if (!is_finite($qty) || $qty <= 0.0) {
            $qty = 1.0;
        }

        $source_ref = trim((string) ($row['source_ref'] ?? ''));
        if ($source_type === BOMSchema::SOURCE_DISTRIBUTOR_UPC) {
            $source_ref = self::normalize_upc($source_ref);
        } elseif ($source_type === BOMSchema::SOURCE_PRODUCT_LINK) {
            $source_ref = ProductLinkResolver::normalize_source_ref($source_ref);
        } else {
            $source_ref = '';
        }

        // Manual price is allowed for ANY source type and acts as a price override.
        $manual_unit_price = null;
        $price_raw = trim((string) ($row['manual_unit_price'] ?? ''));
        if ($price_raw !== '' && is_numeric($price_raw)) {
            $manual_unit_price = max(0.0, (float) $price_raw);
        }

        $manual_qty_on_hand = null;

        if ($source_type === BOMSchema::SOURCE_INTERNAL_STOCK) {
            $qty_on_hand_raw = trim((string) ($row['manual_qty_on_hand'] ?? ''));
            if ($qty_on_hand_raw !== '' && is_numeric($qty_on_hand_raw)) {
                $manual_qty_on_hand = max(0, (int) $qty_on_hand_raw);
            }
        }

        // Ignore completely blank draft/template rows from the admin repeater.
        $has_user_data =
            ($name !== '') ||
            ($notes !== '') ||
            ($source_ref !== '') ||
            ($manual_unit_price !== null) ||
            ($manual_qty_on_hand !== null) ||
            (abs($qty - 1.0) > 0.000001);

        if (!$has_user_data) {
            return null;
        }

        if ($name === '') {
            if ($source_type === BOMSchema::SOURCE_DISTRIBUTOR_UPC && $source_ref !== '') {
                $name = 'UPC ' . $source_ref;
            } elseif ($source_type === BOMSchema::SOURCE_PRODUCT_LINK && $source_ref !== '' && (int) $source_ref > 0) {
                $name = 'Product #' . (string) ((int) $source_ref);
            } else {
                $name = 'BOM Item';
            }
        }

        return [
            'name'               => $name,
            'notes'              => $notes,
            'qty'                => $qty,
            'source_type'        => $source_type,
            'source_ref'         => $source_ref,
            'manual_unit_price'  => $manual_unit_price,
            'manual_qty_on_hand' => $manual_qty_on_hand,
        ];
    }

    /**
     * @param array{
     *   resolved_unit_price:?float,
     *   resolved_stock_state:string,
     *   resolved_stock_qty:?int,
     *   resolved_error_code:string,
     *   resolved_at:string
     * } $resolution
     */
    public static function update_row_resolution(BOMTable $table, int $row_id, array $resolution): void
    {
        $row_id = (int) $row_id;
        if ($row_id <= 0) {
            return;
        }

        global $wpdb;

        $table_name = $table->get_table_name();

        $wpdb->update(
            $table_name,
            [
                'resolved_unit_price' => self::float_or_null($resolution['resolved_unit_price'] ?? null),
                'resolved_stock_state' => sanitize_text_field((string) ($resolution['resolved_stock_state'] ?? '')),
                'resolved_stock_qty' => self::int_or_null($resolution['resolved_stock_qty'] ?? null),
                'resolved_error_code' => sanitize_text_field((string) ($resolution['resolved_error_code'] ?? '')),
                'resolved_at' => sanitize_text_field((string) ($resolution['resolved_at'] ?? '')),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $row_id],
            ['%f', '%s', '%d', '%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * @param mixed $value
     */
    private static function float_or_null($value): ?float
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $num = (float) $raw;
        if (!is_finite($num)) {
            return null;
        }

        return max(0.0, $num);
    }

    /**
     * @param mixed $value
     */
    private static function int_or_null($value): ?int
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return max(0, (int) $raw);
    }

}
