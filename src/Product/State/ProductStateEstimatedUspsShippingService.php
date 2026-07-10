<?php
declare(strict_types=1);

namespace FFLHub\Product\State;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formula-based, zone-agnostic USPS estimate helper for internal admin review.
 *
 * This intentionally does not call USPS. It fills Product State reference data
 * from the selected package measurements; checkout uses that reference only when
 * the corresponding global shipping mode is explicitly enabled.
 */
final class ProductStateEstimatedUspsShippingService
{
    private const DEFAULT_BATCH_SIZE = 500;

    /**
     * Calculate the internal estimated USPS shipping cost.
     *
     * Product State stores shipping weight in ounces and dimensions in inches.
     * The 139 divisor is used only for formula-based dimensional weight.
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

        $rounded_length = (float) ceil($length);
        $rounded_width = (float) ceil($width);
        $rounded_height = (float) ceil($height);
        $dim_weight_lbs = (int) ceil(($rounded_length * $rounded_width * $rounded_height) / 139.0);

        if ($weight < 16.0 && $dim_weight_lbs <= 1) {
            $base_rate = self::under_one_pound_rate($weight);
        } else {
            $actual_weight_lbs = (int) ceil($weight / 16.0);
            $billable_weight_lbs = max($actual_weight_lbs, $dim_weight_lbs);
            $base_rate = self::round_up_to_95(4.00 + (1.45 * $billable_weight_lbs));
        }

        $dimension_fees = self::dimension_fees($rounded_length, $rounded_width, $rounded_height);
        $signature_fee = self::truthy($ffl_required) ? 3.95 : 0.0;

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

    private static function under_one_pound_rate(float $weight_oz): float
    {
        if ($weight_oz <= 4.0) {
            return 4.95;
        }

        if ($weight_oz <= 8.0) {
            return 5.95;
        }

        if ($weight_oz <= 12.0) {
            return 6.95;
        }

        return 7.95;
    }

    private static function dimension_fees(float $length_in, float $width_in, float $height_in): float
    {
        $longest = max($length_in, $width_in, $height_in);
        $volume = $length_in * $width_in * $height_in;
        $fees = 0.0;

        if ($longest > 30.0) {
            $fees += 10.00;
        } elseif ($longest > 22.0) {
            $fees += 4.50;
        }

        if ($volume > 3456.0) {
            $fees += 21.00;
        }

        return $fees;
    }

    private static function round_up_to_95(float $amount): float
    {
        $whole = floor($amount);
        $candidate = $whole + 0.95;

        if ($candidate + 0.00001 < $amount) {
            $candidate = $whole + 1.95;
        }

        return round($candidate, 2);
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
