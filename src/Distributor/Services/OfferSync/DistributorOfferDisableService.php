<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

use FFLHub\Distributor\Offers\DistributorOffersStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Removes a disabled distributor from normalized offer selection.
 *
 * Disabling a distributor already unschedules its cron services. This service
 * handles the data side: mark that distributor's normalized offers disabled,
 * flag their UPCs dirty, then run the normal best-offer -> product_state -> Woo
 * apply chain so storefront products stop selecting that distributor.
 */
final class DistributorOfferDisableService
{
    /**
     * Disable every normalized offer for one distributor and refresh dependent rows.
     *
     * @return array<string,mixed>
     */
    public static function disable_distributor(string $distributor_id): array
    {
        global $wpdb;

        $started = microtime(true);
        $distributor_id = trim($distributor_id);
        $result = [
            'ok' => false,
            'distributor_id' => $distributor_id,
            'offers_disabled' => 0,
            'best_offer_selection' => null,
            'product_state_best_offer_apply' => null,
            'product_state_woo_apply' => null,
            'elapsed_ms' => '0.00',
            'errors' => [],
        ];

        if (!$wpdb) {
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        if ($distributor_id === '') {
            $result['errors'][] = 'Distributor id is required.';
            return self::finish_result($result, $started);
        }

        DistributorOffersStore::ensure_schema();
        $offers_table = DistributorOffersStore::table_name();

        $updated = $wpdb->query($wpdb->prepare(
            "
                UPDATE {$offers_table}
                SET
                    enabled = 0,
                    has_changed = 1,
                    normalized_at = NOW()
                WHERE distributor_id = %s
                  AND (
                    NOT (enabled <=> 0)
                    OR NOT (has_changed <=> 1)
                  )
            ",
            $distributor_id
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($updated === false) {
            $result['errors'][] = 'Failed to disable distributor offers: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['offers_disabled'] = is_numeric($updated) ? (int) $updated : 0;

        $pipeline_ok = true;
        if ((int) $result['offers_disabled'] > 0) {
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

        $result['ok'] = empty($result['errors'])
            && $pipeline_ok;

        return self::finish_result($result, $started);
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
