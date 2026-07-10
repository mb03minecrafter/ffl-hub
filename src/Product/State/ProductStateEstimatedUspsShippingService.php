<?php
declare(strict_types=1);

namespace FFLHub\Product\State;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fixed-zone, destination-agnostic USPS estimate helper for internal planning.
 *
 * This intentionally does not call USPS. It fills Product State reference data
 * from the selected package measurements; checkout uses that reference only when
 * the corresponding global shipping mode is explicitly enabled.
 */
final class ProductStateEstimatedUspsShippingService
{
    private const DEFAULT_BATCH_SIZE = 500;
    private const DIMENSIONAL_WEIGHT_DIVISOR = 166.0;
    private const SIGNATURE_FEE = 3.95;
    private const OVERSIZED_ZONE_6_RATE = 228.67;

    /** USPS Ground Advantage commercial Zone 6 rates effective April 26, 2026. */
    private const ZONE_6_RATES_BY_POUND = [
        1 => 9.63, 2 => 11.58, 3 => 13.59, 4 => 15.16, 5 => 15.89,
        6 => 16.89, 7 => 17.65, 8 => 18.34, 9 => 19.13, 10 => 19.94,
        11 => 21.28, 12 => 22.20, 13 => 23.16, 14 => 24.14, 15 => 25.13,
        16 => 26.09, 17 => 26.85, 18 => 27.70, 19 => 28.52, 20 => 30.32,
        21 => 31.69, 22 => 36.73, 23 => 43.29, 24 => 51.28, 25 => 58.24,
        26 => 61.73, 27 => 65.24, 28 => 67.49, 29 => 69.69, 30 => 71.88,
        31 => 74.02, 32 => 76.13, 33 => 78.24, 34 => 80.30, 35 => 82.37,
        36 => 84.33, 37 => 86.32, 38 => 88.31, 39 => 90.27, 40 => 92.17,
        41 => 94.08, 42 => 95.94, 43 => 97.79, 44 => 99.60, 45 => 101.39,
        46 => 103.17, 47 => 104.90, 48 => 106.61, 49 => 108.29, 50 => 109.94,
        51 => 111.57, 52 => 113.18, 53 => 114.75, 54 => 116.31, 55 => 117.84,
        56 => 119.32, 57 => 120.81, 58 => 122.23, 59 => 123.67, 60 => 125.04,
        61 => 126.41, 62 => 127.76, 63 => 129.07, 64 => 130.35, 65 => 131.60,
        66 => 132.83, 67 => 134.04, 68 => 135.22, 69 => 136.34, 70 => 137.46,
    ];

    /**
     * Calculate the internal estimated USPS shipping cost.
     *
     * Product State stores shipping weight in ounces and dimensions in inches.
     * A fixed Zone 6 benchmark keeps the estimate destination-agnostic while
     * following USPS commercial weight, dimensional, and nonstandard rules.
     */
    public static function calculate_estimated_usps_shipping_cost(
        $weight_oz,
        $length_in,
        $width_in,
        $height_in,
        $ffl_required
    ): ?float {
        $weight = self::positive_float($weight_oz);
        $length = self::positive_float($length_in);
        $width = self::positive_float($width_in);
        $height = self::positive_float($height_in);

        if ($weight === null || $length === null || $width === null || $height === null) {
            return null;
        }

        $rounded_length = self::round_dimension($length);
        $rounded_width = self::round_dimension($width);
        $rounded_height = self::round_dimension($height);
        $dimensions = [$rounded_length, $rounded_width, $rounded_height];
        rsort($dimensions, SORT_NUMERIC);

        $length = (float) $dimensions[0];
        $width = (float) $dimensions[1];
        $height = (float) $dimensions[2];
        $volume = $length * $width * $height;
        $length_plus_girth = $length + (2.0 * ($width + $height));

        if ($length_plus_girth > 108.0) {
            $base_rate = self::OVERSIZED_ZONE_6_RATE;
            $dimension_fees = 0.0;
        } else {
            $actual_weight_lbs = max(1, (int) ceil($weight / 16.0));
            $dim_weight_lbs = $volume > 1728.0
                ? (int) ceil($volume / self::DIMENSIONAL_WEIGHT_DIVISOR)
                : 0;
            $billable_weight_lbs = min(70, max($actual_weight_lbs, $dim_weight_lbs));
            $base_rate = $weight < 16.0 && $dim_weight_lbs <= 1
                ? self::under_one_pound_zone_6_rate($weight)
                : self::ZONE_6_RATES_BY_POUND[$billable_weight_lbs];
            $dimension_fees = self::dimension_fees($length, $volume);
        }

        $signature_fee = self::truthy($ffl_required) ? self::SIGNATURE_FEE : 0.0;

        return round($base_rate + $dimension_fees + $signature_fee, 2);
    }

    /**
     * Recalculate product_state.estimated_usps_shipping_cost in batches.
     *
     * Invalid rows are skipped and left null or unchanged, matching the current
     * internal-only intent of the column.
     *
     * @return array<string,mixed>
     */
    public static function generate_for_product_state(int $batch_size = self::DEFAULT_BATCH_SIZE): array
    {
        global $wpdb;

        $started = microtime(true);
        $result = [
            'ok' => true,
            'stage' => 'estimated_usps_shipping_generation',
            'rows_scanned' => 0,
            'rows_updated' => 0,
            'rows_skipped' => 0,
            'rows_unchanged' => 0,
            'elapsed_ms' => '0.00',
            'errors' => [],
        ];

        if (!$wpdb) {
            $result['ok'] = false;
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        ProductStateStore::ensure_schema();

        $table = ProductStateStore::table_name();
        $batch_size = max(1, min(5000, $batch_size));
        $last_product_id = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "
                    SELECT
                        product_id,
                        shipping_weight_oz,
                        shipping_length_in,
                        shipping_width_in,
                        shipping_height_in,
                        ffl_required,
                        estimated_usps_shipping_cost
                    FROM {$table}
                    WHERE product_id > %d
                    ORDER BY product_id ASC
                    LIMIT %d
                    ",
                    $last_product_id,
                    $batch_size
                ),
                ARRAY_A
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            if (!is_array($rows) || empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $product_id = (int) ($row['product_id'] ?? 0);
                $last_product_id = max($last_product_id, $product_id);
                $result['rows_scanned']++;

                $estimate = self::calculate_estimated_usps_shipping_cost(
                    $row['shipping_weight_oz'] ?? null,
                    $row['shipping_length_in'] ?? null,
                    $row['shipping_width_in'] ?? null,
                    $row['shipping_height_in'] ?? null,
                    $row['ffl_required'] ?? null
                );

                if ($estimate === null || $product_id <= 0) {
                    $result['rows_skipped']++;
                    continue;
                }

                if (self::stored_money_matches($row['estimated_usps_shipping_cost'] ?? null, $estimate)) {
                    $result['rows_unchanged']++;
                    continue;
                }

                $updated = $wpdb->update(
                    $table,
                    ['estimated_usps_shipping_cost' => $estimate],
                    ['product_id' => $product_id],
                    ['%f'],
                    ['%d']
                );

                if ($updated === false) {
                    $result['ok'] = false;
                    $result['errors'][] = sprintf(
                        'Failed to update estimated USPS shipping for product #%d: %s',
                        $product_id,
                        (string) $wpdb->last_error
                    );
                    continue;
                }

                if ((int) $updated > 0) {
                    $result['rows_updated'] += (int) $updated;
                    ProductStateStore::clear_product_cache($product_id);
                } else {
                    $result['rows_unchanged']++;
                }
            }
        } while (count($rows) === $batch_size);

        $result['stage'] = 'complete';

        return self::finish_result($result, $started);
    }

    private static function under_one_pound_zone_6_rate(float $weight_oz): float
    {
        if ($weight_oz <= 4.0) {
            return 6.00;
        }

        if ($weight_oz <= 8.0) {
            return 6.44;
        }

        if ($weight_oz <= 12.0) {
            return 6.74;
        }

        return 7.86;
    }

    private static function dimension_fees(float $length_in, float $volume_cubic_in): float
    {
        $fees = 0.0;

        if ($length_in > 30.0) {
            $fees += 10.00;
        } elseif ($length_in > 22.0) {
            $fees += 4.50;
        }

        if ($volume_cubic_in > 3456.0) {
            $fees += 21.00;
        }

        return $fees;
    }

    private static function round_dimension(float $dimension): float
    {
        return max(1.0, round($dimension, 0, PHP_ROUND_HALF_UP));
    }

    private static function positive_float($value): ?float
    {
        $value = preg_replace('/[^0-9.\-]/', '', trim((string) $value));
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        return (is_finite($number) && $number > 0.0) ? $number : null;
    }

    private static function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value > 0.0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on', 'enabled'], true);
    }

    private static function stored_money_matches($stored, float $estimate): bool
    {
        $value = self::positive_or_zero_float($stored);
        if ($value === null) {
            return false;
        }

        return abs(round($value, 2) - round($estimate, 2)) < 0.001;
    }

    private static function positive_or_zero_float($value): ?float
    {
        $value = preg_replace('/[^0-9.\-]/', '', trim((string) $value));
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        return (is_finite($number) && $number >= 0.0) ? $number : null;
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
