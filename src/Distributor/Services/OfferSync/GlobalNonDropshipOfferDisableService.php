<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

use FFLHub\Distributor\Offers\DistributorOffersStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies the global "dropship-only offers" switch to normalized offers.
 */
final class GlobalNonDropshipOfferDisableService
{
    private const LOCAL_STOCK_DISTRIBUTOR_ID = 'local_stock';

    /**
     * Disable every non-dropship distributor offer and optionally refresh all
     * dependent tables/metadata immediately.
     *
     * @return array<string,mixed>
     */
    public static function disable_existing_non_dropship_offers(bool $refresh_pipeline = true): array
    {
        $started = microtime(true);
        $result = [
            'ok' => false,
            'offers_marked_changed' => 0,
            'best_offer_selection' => null,
            'product_state_best_offer_apply' => null,
            'product_state_woo_apply' => null,
            'elapsed_ms' => '0.00',
            'errors' => [],
        ];

        try {
            $result['offers_marked_changed'] = self::disable_rows(null);
        } catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
            return self::finish_result($result, $started);
        }

        $pipeline_ok = true;
        if ($refresh_pipeline && (int) $result['offers_marked_changed'] > 0) {
            $result['best_offer_selection'] = ProductBestOfferSelectionService::refresh_changed_upcs();
            $pipeline_ok = !empty($result['best_offer_selection']['ok']);

            if ($pipeline_ok) {
                $result['product_state_best_offer_apply'] = ProductStateBestOfferApplyService::apply_changed_best_offers();
                $pipeline_ok = !empty($result['product_state_best_offer_apply']['ok']);
            }

            if ($pipeline_ok) {
                $result['product_state_woo_apply'] = ProductStateWooApplyService::apply_changed_product_state();
                $pipeline_ok = !empty($result['product_state_woo_apply']['ok']);
            }
        }

        $result['ok'] = empty($result['errors']) && $pipeline_ok;

        return self::finish_result($result, $started);
    }

    /**
     * Enforce the same setting for one distributor during product/inventory sync.
     */
    public static function disable_existing_non_dropship_offers_for_distributor(string $distributor_id): int
    {
        $distributor_id = strtolower(trim($distributor_id));
        if ($distributor_id === '' || $distributor_id === self::LOCAL_STOCK_DISTRIBUTOR_ID) {
            return 0;
        }

        return self::disable_rows($distributor_id);
    }

    private static function disable_rows(?string $distributor_id): int
    {
        global $wpdb;

        if (!$wpdb) {
            throw new \RuntimeException('WordPress database connection is unavailable.');
        }

        DistributorOffersStore::ensure_schema();
        $table = DistributorOffersStore::table_name();

        $where = "
            distributor_id <> %s
            AND COALESCE(dropship_enabled, 0) = 0
            AND (
                NOT (enabled <=> 0)
                OR NOT (has_changed <=> 1)
            )
        ";
        $params = [self::LOCAL_STOCK_DISTRIBUTOR_ID];

        if ($distributor_id !== null && $distributor_id !== '') {
            $where .= "\n            AND distributor_id = %s";
            $params[] = $distributor_id;
        }

        $sql = $wpdb->prepare(
            "
                UPDATE {$table}
                SET
                    enabled = 0,
                    has_changed = 1,
                    normalized_at = NOW()
                WHERE {$where}
            ",
            $params
        );

        $updated = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($updated === false) {
            throw new \RuntimeException('Failed to disable non-dropship offers: ' . (string) $wpdb->last_error);
        }

        return is_numeric($updated) ? (int) $updated : 0;
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
