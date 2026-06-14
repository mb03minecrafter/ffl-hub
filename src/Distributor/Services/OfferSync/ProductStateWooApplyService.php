<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

use FFLHub\Distributor\Product\DistributorProductHelper;
use FFLHub\Product\State\ProductStateStore;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies changed product_state rows to WooCommerce product fields.
 *
 * The normalized tables now decide the current offer, pricing outputs, stock,
 * and shipping dimensions. This service is the final bridge back into Woo so
 * the storefront still behaves normally while the old product-meta sync path is
 * phased out.
 */
final class ProductStateWooApplyService
{
    /**
     * Apply every changed active product_state row to its Woo product.
     *
     * @return array<string,mixed>
     */
    public static function apply_changed_product_state(): array
    {
        global $wpdb;

        $started = microtime(true);
        $result = self::base_result('changed_product_state');

        if (!$wpdb) {
            $result['ok'] = false;
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        ProductStateStore::ensure_schema();
        $table = ProductStateStore::table_name();

        $ids = array_map('intval', (array) $wpdb->get_col("
            SELECT product_id
            FROM {$table}
            WHERE status = 'active'
              AND has_changed = 1
            ORDER BY product_id ASC
        ")); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return self::apply_product_id_list($ids, 'changed_product_state', $started, $result);
    }

    /**
     * Apply specific products. Used by product creation after the normalized
     * offer pipeline recalculates the newly created product_state row.
     *
     * @param int[] $product_ids
     * @return array<string,mixed>
     */
    public static function apply_product_ids(array $product_ids): array
    {
        $started = microtime(true);
        $result = self::base_result('product_ids');

        $ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));

        return self::apply_product_id_list($ids, 'product_ids', $started, $result);
    }

    /**
     * @param int[] $product_ids
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function apply_product_id_list(array $product_ids, string $mode, float $started, array $result): array
    {
        $result['mode'] = $mode;
        $result['product_state_rows_found'] = count($product_ids);

        if (empty($product_ids)) {
            $result['ok'] = true;
            return self::finish_result($result, $started);
        }

        foreach ($product_ids as $product_id) {
            $row = ProductStateStore::get_row_for_product_id($product_id);
            if (!self::row_is_active($row)) {
                $result['skipped_missing_state']++;
                continue;
            }

            $product = wc_get_product($product_id);
            if (!($product instanceof WC_Product)) {
                $result['errors'][] = "Woo product #{$product_id} could not be loaded.";
                $result['failed_products']++;
                continue;
            }

            try {
                $changed = self::apply_row_to_product($product, $row);
                if ($changed) {
                    $product->save();
                    $result['woo_products_saved']++;
                }

                if (self::mark_row_synced($product_id)) {
                    $result['product_state_flags_cleared']++;
                }

                ProductStateStore::clear_product_cache($product_id);
                $result['products_processed']++;
            } catch (\Throwable $e) {
                $result['errors'][] = "Product #{$product_id} Woo apply failed: " . $e->getMessage();
                $result['failed_products']++;
            }
        }

        $result['ok'] = empty($result['errors']);

        return self::finish_result($result, $started);
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function apply_row_to_product(WC_Product $product, array $row): bool
    {
        $changed = false;
        $upc = self::string_value($row['upc'] ?? '');

        if ($upc !== '' && method_exists($product, 'set_global_unique_id')) {
            if ((string) $product->get_global_unique_id('edit') !== $upc) {
                $product->set_global_unique_id($upc);
                $changed = true;
            }
        }

        $regular = self::price_string($row['public_regular_price'] ?? null);
        if ($regular === '') {
            $regular = self::price_string($row['computed_sell_price'] ?? null);
        }

        $sale = self::price_string($row['public_sale_price'] ?? null);
        if ($sale !== '' && ($regular === '' || (float) $sale >= (float) $regular)) {
            $sale = '';
        }

        if ((string) $product->get_regular_price('edit') !== $regular) {
            $product->set_regular_price($regular);
            $changed = true;
        }

        if ((string) $product->get_sale_price('edit') !== $sale) {
            $product->set_sale_price($sale);
            $changed = true;
        }

        $qty = self::desired_stock_qty($row);
        $stock_status = $qty > 0 ? 'instock' : 'outofstock';

        if ((bool) $product->get_manage_stock('edit') !== true) {
            $product->set_manage_stock(true);
            $changed = true;
        }

        if ((int) $product->get_stock_quantity('edit') !== $qty) {
            $product->set_stock_quantity($qty);
            $changed = true;
        }

        if ((string) $product->get_stock_status('edit') !== $stock_status) {
            $product->set_stock_status($stock_status);
            $changed = true;
        }

        if ((int) ($row['manual_shipping_override'] ?? 0) !== 1) {
            $changed = DistributorProductHelper::sync_woo_shipping_from_fflhub_values(
                $product,
                $row['shipping_weight_oz'] ?? '',
                $row['shipping_length_in'] ?? '',
                $row['shipping_width_in'] ?? '',
                $row['shipping_height_in'] ?? ''
            ) || $changed;
        }

        return $changed;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function desired_stock_qty(array $row): int
    {
        $distributor_qty = max(0, (int) ($row['qty'] ?? 0));
        $local_qty = ProductStateStore::get_local_stock_override_qty_from_row($row);

        if ((int) ($row['stock_oos_override'] ?? 0) === 1) {
            return $local_qty;
        }

        if ($distributor_qty <= 0 && $local_qty > 0) {
            return $local_qty;
        }

        return $distributor_qty;
    }

    private static function mark_row_synced(int $product_id): bool
    {
        global $wpdb;

        if (!$wpdb || $product_id <= 0) {
            return false;
        }

        $updated = $wpdb->update(
            ProductStateStore::table_name(),
            [
                'woo_synced_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'has_changed' => 0,
            ],
            ['product_id' => $product_id],
            ['%s', '%s', '%d'],
            ['%d']
        );

        return $updated !== false;
    }

    /**
     * @param array<string,mixed>|null $row
     */
    private static function row_is_active(?array $row): bool
    {
        return is_array($row) && strtolower(trim((string) ($row['status'] ?? ''))) === 'active';
    }

    /**
     * @param mixed $value
     */
    private static function price_string($value): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        $float = (float) $value;
        if (!is_finite($float) || $float <= 0.0) {
            return '';
        }

        return wc_format_decimal($float, 2);
    }

    /**
     * @param mixed $value
     */
    private static function string_value($value): string
    {
        return trim((string) ($value ?? ''));
    }

    /**
     * @return array<string,mixed>
     */
    private static function base_result(string $mode): array
    {
        return [
            'ok' => false,
            'mode' => $mode,
            'product_state_rows_found' => 0,
            'products_processed' => 0,
            'woo_products_saved' => 0,
            'product_state_flags_cleared' => 0,
            'skipped_missing_state' => 0,
            'failed_products' => 0,
            'elapsed_ms' => '0.00',
            'errors' => [],
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function finish_result(array $result, float $started): array
    {
        $result['elapsed_ms'] = number_format((microtime(true) - $started) * 1000.0, 2, '.', '');

        return $result;
    }
}
