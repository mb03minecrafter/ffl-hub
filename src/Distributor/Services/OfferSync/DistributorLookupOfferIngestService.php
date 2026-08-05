<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Copies one-off UPC lookup results into the normalized distributor offers table.
 *
 * Product/catalog crons already normalize from their live distributor tables.
 * Product creation is different: it starts from the DistributorHandler lookup
 * result, so this service maps those in-memory offers into the same normalized
 * shape and marks them changed for the regular best-offer pipeline.
 */
final class DistributorLookupOfferIngestService
{
    /**
     * @param array<string,DistributorOffer> $offers
     * @return array<string,mixed>
     */
    public static function upsert_lookup_offers(string $upc, array $offers): array
    {
        global $wpdb;

        $started = microtime(true);
        $result = [
            'ok' => true,
            'upc' => self::normalize_upc($upc),
            'offers_seen' => 0,
            'offers_upserted' => 0,
            'offers_skipped' => 0,
            'elapsed_ms' => '0.00',
            'errors' => [],
        ];

        if (!$wpdb) {
            $result['ok'] = false;
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        if ($result['upc'] === '') {
            $result['ok'] = false;
            $result['errors'][] = 'A valid UPC is required.';
            return self::finish_result($result, $started);
        }

        DistributorOffersStore::ensure_schema();
        $table = DistributorOffersStore::table_name();

        foreach ($offers as $key => $offer) {
            $result['offers_seen']++;

            if (!($offer instanceof DistributorOffer)) {
                $result['offers_skipped']++;
                continue;
            }

            $dist_id = self::normalize_distributor_id((string) ($offer->distributor_id ?: $key));
            $payload = $offer->product ?? null;
            if ($dist_id === '' || !($payload instanceof DistributorProductPayload)) {
                $result['offers_skipped']++;
                continue;
            }

            $row = self::row_from_offer($result['upc'], $dist_id, $payload);
            $ok = $wpdb->replace(
                $table,
                $row,
                self::formats_for_row($row)
            );

            if ($ok === false) {
                $result['ok'] = false;
                $result['errors'][] = sprintf(
                    '%s offer upsert failed: %s',
                    $dist_id,
                    (string) $wpdb->last_error
                );
                continue;
            }

            $result['offers_upserted']++;
        }

        return self::finish_result($result, $started);
    }

    /**
     * @return array<string,mixed>
     */
    private static function row_from_offer(string $upc, string $dist_id, DistributorProductPayload $payload): array
    {
        $dealer_price = self::positive_float($payload->price ?? null);
        $shipping_cost = self::non_negative_float($payload->shipping_cost ?? null) ?? 0.0;
        $landed_cost = null;
        if ($dealer_price !== null) {
            $landed_cost = round($dealer_price + $shipping_cost, 4);
        } else {
            $landed_cost = self::positive_float($payload->true_cost ?? null);
        }

        $qty = max(0, (int) ($payload->quantity ?? 0));
        $map = ($dist_id === 'davidsons') ? null : self::positive_float($payload->map ?? null);
        $msrp = self::positive_float($payload->msrp ?? null);
        $sku = trim((string) ($payload->sku ?? ''));
        $now = current_time('mysql');
        $dropship_enabled = !empty($payload->dropship_enabled) ? 1 : 0;
        $enabled = 1;
        if (
            Options::get_disable_non_dropship_offers_enabled()
            && $dist_id !== 'local_stock'
            && $dropship_enabled !== 1
        ) {
            $enabled = 0;
        }

        return [
            'upc' => $upc,
            'distributor_id' => $dist_id,
            'distributor_product_id' => $sku !== '' ? $sku : null,
            'distributor_sku' => $sku !== '' ? $sku : null,
            'manufacturer_norm' => self::manufacturer_norm($payload),
            'qty' => $qty,
            'stock_status' => $qty > 0 ? 'instock' : 'outofstock',
            'dealer_price' => $dealer_price,
            'shipping_cost' => $shipping_cost,
            'landed_cost' => $landed_cost,
            'map_price' => $map,
            'msrp' => $msrp,
            'ffl_required' => !empty($payload->ffl_required) ? 1 : 0,
            'sot_required' => !empty($payload->sot_required) ? 1 : 0,
            'dropship_enabled' => $dropship_enabled,
            'enabled' => $enabled,
            'has_changed' => 1,
            'shipping_weight_oz' => self::non_negative_float($payload->shipping_weight ?? null),
            'shipping_length_in' => self::non_negative_float($payload->shipping_length_in ?? null),
            'shipping_width_in' => self::non_negative_float($payload->shipping_width_in ?? null),
            'shipping_height_in' => self::non_negative_float($payload->shipping_height_in ?? null),
            'source_updated_at' => null,
            'normalized_at' => $now,
        ];
    }

    private static function manufacturer_norm(DistributorProductPayload $payload): ?string
    {
        $brand = trim((string) ($payload->brand ?? ''));
        if ($brand === '') {
            return null;
        }

        $key = strtolower((string) preg_replace('/[^a-z0-9]+/', '', $brand));
        $aliases = [
            'sig' => 'SIG SAUER',
            'sigsauer' => 'SIG SAUER',
            'sigsaueroffduty' => 'SIG SAUER',
            'magpulindustriescorp' => 'MAGPUL',
            'magpulindustries' => 'MAGPUL',
            'magpul' => 'MAGPUL',
            'olightstoreusainc' => 'OSIGHT',
            'olightstoreusa' => 'OSIGHT',
            'olightstore' => 'OSIGHT',
        ];

        if ($key !== '' && isset($aliases[$key])) {
            return $aliases[$key];
        }

        $brand = html_entity_decode($brand, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $brand = wp_strip_all_tags($brand);
        $brand = trim((string) preg_replace('/\s+/', ' ', $brand));

        return $brand !== '' ? strtoupper($brand) : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return string[]
     */
    private static function formats_for_row(array $row): array
    {
        $formats = [
            'upc' => '%s',
            'distributor_id' => '%s',
            'distributor_product_id' => '%s',
            'distributor_sku' => '%s',
            'manufacturer_norm' => '%s',
            'qty' => '%d',
            'stock_status' => '%s',
            'dealer_price' => '%f',
            'shipping_cost' => '%f',
            'landed_cost' => '%f',
            'map_price' => '%f',
            'msrp' => '%f',
            'ffl_required' => '%d',
            'sot_required' => '%d',
            'dropship_enabled' => '%d',
            'enabled' => '%d',
            'has_changed' => '%d',
            'shipping_weight_oz' => '%f',
            'shipping_length_in' => '%f',
            'shipping_width_in' => '%f',
            'shipping_height_in' => '%f',
            'source_updated_at' => '%s',
            'normalized_at' => '%s',
        ];

        return array_map(
            static fn(string $column): string => $formats[$column] ?? '%s',
            array_keys($row)
        );
    }

    private static function normalize_upc(string $upc): string
    {
        $upc = preg_replace('/\D+/', '', trim($upc));
        return is_string($upc) ? $upc : '';
    }

    private static function normalize_distributor_id(string $dist_id): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9_\-]+/', '', trim($dist_id)));
    }

    /**
     * @param mixed $value
     */
    private static function positive_float($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $float = (float) $value;
        return (is_finite($float) && $float > 0.0) ? $float : null;
    }

    /**
     * @param mixed $value
     */
    private static function non_negative_float($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $float = (float) $value;
        return (is_finite($float) && $float >= 0.0) ? $float : null;
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
