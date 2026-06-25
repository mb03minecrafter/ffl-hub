<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

use FFLHub\Distributor\Services\BillHicks\BillHicksOfferNormalizationService;
use FFLHub\Distributor\Services\BillHicks\Tables\BillHicksProductTableSchema;
use FFLHub\Distributor\Services\CSSI\CSSIOfferNormalizationService;
use FFLHub\Distributor\Services\CSSI\Tables\CSSIProductTableSchema;
use FFLHub\Distributor\Services\Davidsons\DavidsonsOfferNormalizationService;
use FFLHub\Distributor\Services\Davidsons\Tables\DavidsonsProductTableSchema;
use FFLHub\Distributor\Services\Kinseys\KinseysOfferNormalizationService;
use FFLHub\Distributor\Services\Kinseys\Tables\KinseysProductTableSchema;
use FFLHub\Distributor\Services\Lipseys\LipseysOfferNormalizationService;
use FFLHub\Distributor\Services\Lipseys\Tables\LipseysProductTableSchema;
use FFLHub\Distributor\Services\Orion\OrionOfferNormalizationService;
use FFLHub\Distributor\Services\Orion\Tables\OrionProductTableSchema;
use FFLHub\Distributor\Services\RSR\RSROfferNormalizationService;
use FFLHub\Distributor\Services\RSR\Tables\RSRProductTableSchema;
use FFLHub\Distributor\Services\SportsSouth\SportsSouthOfferNormalizationService;
use FFLHub\Distributor\Services\SportsSouth\Tables\SportsSouthProductTableSchema;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Distributor\Services\Tables\ProductSchemaInterface;
use FFLHub\Distributor\Services\Zanders\Tables\ZandersProductTableSchema;
use FFLHub\Distributor\Services\Zanders\ZandersOfferNormalizationService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rehydrates normalized offer rows when a distributor is enabled.
 *
 * Enabling already starts the distributor's cron services. This handles the
 * data side immediately: if the distributor already has a live product table
 * with rows, run that distributor's product-table normalizer so disabled offers
 * become enabled again, missing offers get inserted, stale rows get cleaned up,
 * and the best-offer/product-state/Woo apply chain runs from the same code path
 * used by product crons and manual normalize buttons.
 */
final class DistributorOfferEnableService
{
    /**
     * @return array<string,mixed>
     */
    public static function enable_distributor(string $distributor_id): array
    {
        $started = microtime(true);
        $distributor_id = trim($distributor_id);
        $result = [
            'ok' => false,
            'distributor_id' => $distributor_id,
            'supported' => false,
            'skipped' => false,
            'skip_reason' => '',
            'source_live_table' => '',
            'live_rows_present' => false,
            'normalizer_result' => null,
            'elapsed_ms' => '0.00',
            'errors' => [],
        ];

        if ($distributor_id === '') {
            $result['errors'][] = 'Distributor id is required.';
            return self::finish_result($result, $started);
        }

        $config = self::normalizer_config($distributor_id);
        if ($config === null) {
            $result['ok'] = true;
            $result['skipped'] = true;
            $result['skip_reason'] = 'No product-table offer normalizer is registered for this distributor.';
            return self::finish_result($result, $started);
        }

        $schema_class = (string) $config['schema_class'];
        /** @var ProductSchemaInterface $schema */
        $schema = new $schema_class();
        $live_table = self::resolve_live_table($schema);
        $result['supported'] = true;
        $result['source_live_table'] = $live_table;

        if ($live_table === '' || !self::table_exists($live_table)) {
            $result['ok'] = true;
            $result['skipped'] = true;
            $result['skip_reason'] = 'Live product table does not exist yet.';
            return self::finish_result($result, $started);
        }

        if (!self::table_has_rows($live_table)) {
            $result['ok'] = true;
            $result['skipped'] = true;
            $result['skip_reason'] = 'Live product table exists but has no rows.';
            return self::finish_result($result, $started);
        }

        $result['live_rows_present'] = true;

        try {
            $normalizer_class = (string) $config['normalizer_class'];
            $normalizer_result = $normalizer_class::normalize_from_product_table($live_table);
            $result['normalizer_result'] = $normalizer_result;
            $result['ok'] = !empty($normalizer_result['ok']);
            if (!$result['ok'] && !empty($normalizer_result['errors'])) {
                $result['errors'] = array_values((array) $normalizer_result['errors']);
            }
        } catch (\Throwable $e) {
            $result['errors'][] = 'Distributor offer enable normalization failed: ' . $e->getMessage();
        }

        return self::finish_result($result, $started);
    }

    /**
     * @return array{schema_class:class-string<ProductSchemaInterface>,normalizer_class:class-string<AbstractDistributorTableSyncService>}|null
     */
    private static function normalizer_config(string $distributor_id): ?array
    {
        $configs = [
            'zanders' => [
                'schema_class' => ZandersProductTableSchema::class,
                'normalizer_class' => ZandersOfferNormalizationService::class,
            ],
            'rsr' => [
                'schema_class' => RSRProductTableSchema::class,
                'normalizer_class' => RSROfferNormalizationService::class,
            ],
            'lipseys' => [
                'schema_class' => LipseysProductTableSchema::class,
                'normalizer_class' => LipseysOfferNormalizationService::class,
            ],
            'cssi' => [
                'schema_class' => CSSIProductTableSchema::class,
                'normalizer_class' => CSSIOfferNormalizationService::class,
            ],
            'sports_south' => [
                'schema_class' => SportsSouthProductTableSchema::class,
                'normalizer_class' => SportsSouthOfferNormalizationService::class,
            ],
            'kinseys' => [
                'schema_class' => KinseysProductTableSchema::class,
                'normalizer_class' => KinseysOfferNormalizationService::class,
            ],
            'orion' => [
                'schema_class' => OrionProductTableSchema::class,
                'normalizer_class' => OrionOfferNormalizationService::class,
            ],
            'davidsons' => [
                'schema_class' => DavidsonsProductTableSchema::class,
                'normalizer_class' => DavidsonsOfferNormalizationService::class,
            ],
            'bill_hicks' => [
                'schema_class' => BillHicksProductTableSchema::class,
                'normalizer_class' => BillHicksOfferNormalizationService::class,
            ],
        ];

        return $configs[$distributor_id] ?? null;
    }

    private static function resolve_live_table(ProductSchemaInterface $schema): string
    {
        $table = new DoubleBufferedProductTable($schema, '');

        return $table->get_live_table_name();
    }

    private static function table_exists(string $table): bool
    {
        global $wpdb;

        if (!$wpdb || $table === '') {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($found) && $found === $table;
    }

    private static function table_has_rows(string $table): bool
    {
        global $wpdb;

        if (!$wpdb || $table === '') {
            return false;
        }

        $has_row = $wpdb->get_var("SELECT 1 FROM {$table} LIMIT 1"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return (string) $has_row === '1';
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
