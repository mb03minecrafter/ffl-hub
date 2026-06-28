<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

use FFLHub\Product\State\ProductStateStore;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies brand-level MAP policy rows to product_state.
 *
 * The MAP policy admin page owns brand-wide display policy. When those rows
 * change, this service updates matching product_state rows immediately so the
 * new product_state/GunDeals path does not wait for an unrelated distributor
 * offer change before seeing the policy change.
 */
final class ProductStateMapPolicyRefreshService
{
    /**
     * WordPress update_option hook for fflhub_map_brand_policies.
     *
     * @param mixed $old_value
     * @param mixed $new_value
     */
    public static function handle_map_brand_policies_updated($old_value, $new_value): void
    {
        $result = self::refresh_changed_policy_rows($old_value, $new_value);

        if (!empty($result['errors'])) {
            error_log('FFLHub MAP policy product_state refresh failed: ' . implode('; ', array_map('strval', (array) $result['errors'])));
        }
    }

    /**
     * Force-apply every currently configured brand policy row.
     *
     * This is useful after deploying the hook, or after manually correcting
     * product_state data, because it does not require the option value to change.
     *
     * @return array<string,mixed>
     */
    public static function refresh_current_configured_policies(): array
    {
        return self::refresh_policy_lookup(self::policy_lookup_from_rows(Options::get_map_brand_policies()), 'current');
    }

    /**
     * Apply only brand keys whose policy was added, changed, or removed.
     *
     * Removed brands are reset to Add to Cart for Price when MAP exists. This
     * prevents stale product_state policies from surviving after an admin removes
     * a brand row from the MAP policy page.
     *
     * @param mixed $old_rows
     * @param mixed $new_rows
     * @return array<string,mixed>
     */
    public static function refresh_changed_policy_rows($old_rows, $new_rows): array
    {
        $old_lookup = self::policy_lookup_from_rows($old_rows);
        $new_lookup = self::policy_lookup_from_rows($new_rows);
        $changed = [];

        foreach (array_unique(array_merge(array_keys($old_lookup), array_keys($new_lookup))) as $key) {
            $old_policy = $old_lookup[$key] ?? null;
            $new_policy = $new_lookup[$key] ?? Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
            if ($old_policy === $new_policy) {
                continue;
            }

            $changed[$key] = $new_policy;
        }

        return self::refresh_policy_lookup($changed, 'changed');
    }

    /**
     * @param array<string,string> $policy_lookup normalized brand key => policy
     * @return array<string,mixed>
     */
    private static function refresh_policy_lookup(array $policy_lookup, string $mode): array
    {
        global $wpdb;

        $started = microtime(true);
        $result = [
            'ok' => true,
            'mode' => $mode,
            'policy_brand_keys' => count($policy_lookup),
            'matched_product_state_rows' => 0,
            'updated_product_state' => 0,
            'stage_elapsed_ms' => '0.00',
            'update_elapsed_ms' => '0.00',
            'elapsed_ms' => '0.00',
            'errors' => [],
        ];

        if (!$wpdb) {
            $result['ok'] = false;
            $result['errors'][] = 'WordPress database connection is unavailable.';
            return self::finish_result($result, $started);
        }

        if ($policy_lookup === []) {
            return self::finish_result($result, $started);
        }

        ProductStateStore::ensure_schema();

        $product_state_table = ProductStateStore::table_name();
        $temp_table = 'tmp_fflhub_product_state_map_policy_keys';
        $charset = $wpdb->get_charset_collate();

        $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $created = $wpdb->query("
            CREATE TEMPORARY TABLE {$temp_table} (
                brand_key VARCHAR(191) NOT NULL,
                policy VARCHAR(32) NOT NULL,
                PRIMARY KEY (brand_key)
            ) ENGINE=MEMORY {$charset}
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($created === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to create MAP policy temp table: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $t_stage = microtime(true);
        foreach ($policy_lookup as $brand_key => $policy) {
            $inserted = $wpdb->insert(
                $temp_table,
                [
                    'brand_key' => $brand_key,
                    'policy' => self::valid_policy($policy),
                ],
                ['%s', '%s']
            );

            if ($inserted === false) {
                $result['ok'] = false;
                $result['errors'][] = 'Failed to stage MAP policy key ' . $brand_key . ': ' . (string) $wpdb->last_error;
                return self::finish_result($result, $started);
            }
        }
        $result['stage_elapsed_ms'] = number_format((microtime(true) - $t_stage) * 1000.0, 2, '.', '');

        $brand_key_expr = self::manufacturer_key_expr('ps');
        $matched = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$product_state_table} ps
            INNER JOIN {$temp_table} m
                ON m.brand_key = {$brand_key_expr}
            WHERE ps.status = 'active'
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result['matched_product_state_rows'] = is_numeric($matched) ? (int) $matched : 0;

        $computed_sell_price = ProductStatePricingSql::computed_sell_price_expr('ps', 'ps');
        $target_policy = self::target_policy_expr('ps', 'm');
        $effective_map_price = ProductStatePricingSql::effective_map_price_expr('ps', 'ps');
        $map_applicable = ProductStatePricingSql::map_applicable_expr('ps', 'ps');
        $public_regular_price = ProductStatePricingSql::public_regular_price_expr('ps', 'ps', $computed_sell_price, $target_policy);
        $public_sale_price = ProductStatePricingSql::public_sale_price_expr('ps', 'ps', $computed_sell_price, $target_policy);

        $t_update = microtime(true);
        $updated = $wpdb->query("
            UPDATE {$product_state_table} ps
            INNER JOIN {$temp_table} m
                ON m.brand_key = {$brand_key_expr}
            SET
                ps.pricing_percent = CASE
                    WHEN ps.pricing_mode = 'global_percent' THEN " . self::global_percent_literal() . "
                    ELSE ps.pricing_percent
                END,
                ps.map_visibility_policy = {$target_policy},
                ps.effective_map_price = {$effective_map_price},
                ps.computed_sell_price = {$computed_sell_price},
                ps.map_applicable = {$map_applicable},
                ps.public_regular_price = {$public_regular_price},
                ps.public_sale_price = {$public_sale_price},
                ps.updated_at = NOW(),
                ps.has_changed = 1
            WHERE ps.status = 'active'
              AND NOT (
                    ps.map_visibility_policy <=> {$target_policy}
                AND ps.effective_map_price <=> {$effective_map_price}
                AND ps.computed_sell_price <=> {$computed_sell_price}
                AND ps.map_applicable <=> {$map_applicable}
                AND ps.public_regular_price <=> {$public_regular_price}
                AND ps.public_sale_price <=> {$public_sale_price}
              )
        "); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $result['update_elapsed_ms'] = number_format((microtime(true) - $t_update) * 1000.0, 2, '.', '');
        if ($updated === false) {
            $result['ok'] = false;
            $result['errors'][] = 'Failed to refresh product_state MAP policies: ' . (string) $wpdb->last_error;
            return self::finish_result($result, $started);
        }

        $result['updated_product_state'] = is_numeric($updated) ? (int) $updated : 0;

        return self::finish_result($result, $started);
    }

    /**
     * @param mixed $rows
     * @return array<string,string>
     */
    private static function policy_lookup_from_rows($rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $lookup = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = Options::normalize_brand_policy_key((string) ($row['brand'] ?? ''));
            if ($key === '') {
                continue;
            }

            $lookup[$key] = self::valid_policy((string) ($row['policy'] ?? ''));
        }

        return $lookup;
    }

    private static function valid_policy(string $policy): string
    {
        $policy = strtolower(trim($policy));
        if (
            $policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE
            || $policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART
        ) {
            return $policy;
        }

        return Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
    }

    private static function manufacturer_key_expr(string $alias): string
    {
        return "LOWER(REGEXP_REPLACE(COALESCE({$alias}.manufacturer_norm, ''), '[^[:alnum:]]+', ''))";
    }

    private static function target_policy_expr(string $state_alias, string $policy_alias): string
    {
        return "
            {$policy_alias}.policy
        ";
    }

    private static function global_percent_literal(): string
    {
        return number_format(max(0.0, (float) Options::get_global_markup()), 4, '.', '');
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
