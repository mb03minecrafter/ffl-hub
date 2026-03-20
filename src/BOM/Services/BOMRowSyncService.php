<?php
declare(strict_types=1);

namespace FFLHub\BOM\Services;

use FFLHub\BOM\Data\BOMRepository;
use FFLHub\BOM\Tables\BOMSchema;
use FFLHub\BOM\Tables\BOMTable;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Syncs cached BOM row stock/price snapshot fields.
 *
 * Cron-friendly design:
 * - No admin/UI dependencies.
 * - Stateless static methods that accept explicit dependencies.
 * - Batch helpers for paged sync jobs.
 */
final class BOMRowSyncService
{
    private const DEBUG_CONST = 'FFLHUB_ADMIN_DEBUG';
    private const LOG_PREFIX = '[FFLHub][BOM][Sync]';
    private const STOCK_MANUAL_VERIFICATION = 'manual_verification';

    /**
     * @return array{total:int,updated:int}
     */
    public static function sync_parent_rows(BOMTable $table, ?DistributorHandler $handler, int $parent_product_id): array
    {
        $rows = BOMRepository::get_rows_for_parent($table, $parent_product_id);
        self::debug_ctx('sync_parent_rows start', [
            'parent_product_id' => $parent_product_id,
            'rows' => count($rows),
        ]);
        if (empty($rows)) {
            return ['total' => 0, 'updated' => 0];
        }

        $updated = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $row_id = (int) ($row['id'] ?? 0);
            if ($row_id <= 0) {
                continue;
            }

            $resolution = self::resolve_row_snapshot($row, $handler);
            BOMRepository::update_row_resolution($table, $row_id, $resolution);
            $updated++;
        }

        self::debug_ctx('sync_parent_rows done', [
            'parent_product_id' => $parent_product_id,
            'total' => count($rows),
            'updated' => $updated,
        ]);

        return [
            'total'   => count($rows),
            'updated' => $updated,
        ];
    }

    /**
     * Sync a batch of parent product IDs.
     *
     * @param int[] $parent_ids
     * @return array{parents:int,total:int,updated:int}
     */
    public static function sync_parent_ids(BOMTable $table, ?DistributorHandler $handler, array $parent_ids): array
    {
        $parents = 0;
        $total = 0;
        $updated = 0;

        foreach ($parent_ids as $parent_id_raw) {
            $parent_id = (int) $parent_id_raw;
            if ($parent_id <= 0) {
                continue;
            }

            $res = self::sync_parent_rows($table, $handler, $parent_id);
            $parents++;
            $total += (int) ($res['total'] ?? 0);
            $updated += (int) ($res['updated'] ?? 0);
        }

        return [
            'parents' => $parents,
            'total'   => $total,
            'updated' => $updated,
        ];
    }

    /**
     * Discover distinct parent product IDs in pages (for cron chunking).
     *
     * @return int[]
     */
    public static function get_parent_ids_page(BOMTable $table, int $limit = 100, int $offset = 0): array
    {
        global $wpdb;

        $limit = max(1, min(1000, (int) $limit));
        $offset = max(0, (int) $offset);

        $table_name = $table->get_table_name();
        $sql = $wpdb->prepare(
            "SELECT DISTINCT parent_product_id
             FROM {$table_name}
             WHERE parent_product_id > 0
             ORDER BY parent_product_id ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        );

        $rows = $wpdb->get_col($sql);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $out[] = $id;
            }
        }

        $out = array_values(array_unique($out));
        sort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   resolved_unit_price:?float,
     *   resolved_stock_state:string,
     *   resolved_stock_qty:?int,
     *   resolved_error_code:string,
     *   resolved_at:string
     * }
     */
    private static function resolve_row_snapshot(array $row, ?DistributorHandler $handler): array
    {
        $source_type = BOMRepository::normalize_source_type((string) ($row['source_type'] ?? ''));
        $source_ref = trim((string) ($row['source_ref'] ?? ''));
        $manual_unit_price = self::to_float_or_null($row['manual_unit_price'] ?? null);
        $manual_qty_on_hand = self::to_int_or_null($row['manual_qty_on_hand'] ?? null);
        $row_id = (int) ($row['id'] ?? 0);

        $price = null;
        $stock_state = 'unknown';
        $stock_qty = null;
        $error_code = '';

        if ($source_type === BOMSchema::SOURCE_DISTRIBUTOR_UPC) {
            $upc = BOMRepository::normalize_upc($source_ref);
            if ($upc === '') {
                $error_code = 'missing_upc';
            } elseif (!($handler instanceof DistributorHandler)) {
                $error_code = 'handler_missing';
            } else {
                $lookup = $handler->get_payloads_for_upc($upc, false);
                $offer = $lookup->best_default();
                if ($offer instanceof DistributorOffer) {
                    $price = $offer->get_true_cost();
                    if ($price === null) {
                        $fallback = (float) ($offer->product->price ?? 0.0);
                        if ($fallback > 0.0) {
                            $price = $fallback;
                        }
                    }

                    $qty = $offer->get_quantity();
                    if ($qty !== null) {
                        $stock_qty = max(0, $qty);
                        $stock_state = $qty > 0 ? 'in_stock' : 'out_of_stock';
                    } else {
                        $stock_state = 'unknown';
                    }
                } else {
                    $error_code = 'offer_missing';
                }
            }
        } elseif ($source_type === BOMSchema::SOURCE_PRODUCT_LINK) {
            $resolved = ProductLinkResolver::resolve($source_ref);
            if (!empty($resolved['resolved'])) {
                $price = self::to_float_or_null($resolved['unit_price'] ?? null);
                $stock_state_raw = trim((string) ($resolved['stock_state'] ?? 'unknown'));
                $stock_state = self::normalize_stock_state($stock_state_raw);
                $resolved_error = trim((string) ($resolved['error_code'] ?? ''));
                if ($resolved_error !== '') {
                    $error_code = $resolved_error;
                }
                self::debug_ctx('resolve product_link', [
                    'row_id' => $row_id,
                    'source_ref' => $source_ref,
                    'resolved' => 1,
                    'stock_state' => $stock_state,
                    'resolved_price' => $price,
                    'resolved_error' => $resolved_error,
                    'manual_price' => $manual_unit_price,
                ]);
            } else {
                $error_code = trim((string) ($resolved['error_code'] ?? 'unresolved'));
                self::debug_ctx('resolve product_link', [
                    'row_id' => $row_id,
                    'source_ref' => $source_ref,
                    'resolved' => 0,
                    'error_code' => $error_code,
                    'manual_price' => $manual_unit_price,
                ]);
            }
        } elseif ($source_type === BOMSchema::SOURCE_INTERNAL_STOCK) {
            $price = $manual_unit_price;
            $stock_qty = $manual_qty_on_hand;
            if ($stock_qty === null) {
                $stock_state = 'unknown';
            } else {
                $stock_state = $stock_qty > 0 ? 'in_stock' : 'out_of_stock';
            }
        } else {
            $error_code = 'invalid_source_type';
        }

        // Manual price override wins for all source types.
        if ($manual_unit_price !== null) {
            $price = $manual_unit_price;
        }

        if ($stock_state === 'unknown' && self::is_blocked_stock_error($error_code)) {
            $stock_state = self::STOCK_MANUAL_VERIFICATION;
        }

        // Manual qty override wins for all source types.
        if ($manual_qty_on_hand !== null) {
            $stock_qty = max(0, $manual_qty_on_hand);
            $stock_state = $stock_qty > 0 ? 'in_stock' : 'out_of_stock';
        }

        self::debug_ctx('resolve_row_snapshot final', [
            'row_id' => $row_id,
            'source_type' => $source_type,
            'source_ref' => $source_ref,
            'manual_price' => $manual_unit_price,
            'manual_qty_on_hand' => $manual_qty_on_hand,
            'resolved_unit_price' => $price,
            'resolved_stock_state' => self::normalize_stock_state($stock_state),
            'resolved_stock_qty' => $stock_qty,
            'resolved_error_code' => $error_code,
        ]);

        return [
            'resolved_unit_price' => $price,
            'resolved_stock_state' => self::normalize_stock_state($stock_state),
            'resolved_stock_qty' => $stock_qty,
            'resolved_error_code' => $error_code,
            'resolved_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param mixed $value
     */
    private static function to_float_or_null($value): ?float
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
    private static function to_int_or_null($value): ?int
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

    private static function normalize_stock_state(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if ($raw === 'in_stock') {
            return 'in_stock';
        }
        if ($raw === 'out_of_stock') {
            return 'out_of_stock';
        }
        if ($raw === self::STOCK_MANUAL_VERIFICATION) {
            return self::STOCK_MANUAL_VERIFICATION;
        }

        return 'unknown';
    }

    private static function is_blocked_stock_error(string $error_code): bool
    {
        $error_code = strtolower(trim($error_code));
        if ($error_code === '') {
            return false;
        }

        return in_array($error_code, ['external_http_403', 'external_http_429'], true);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private static function debug_ctx(string $msg, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $msg, $ctx);
    }
}
