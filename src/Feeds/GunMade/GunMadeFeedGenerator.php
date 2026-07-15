<?php

namespace FFLHub\Feeds\GunMade;

use FFLHub\Distributor\Services\Routing\DealerFulfillmentRoutingPlanner;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Settings\Options;
use FFLHub\Shipping\CustomerShippingCostPolicy;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

final class GunMadeFeedGenerator
{
    private const DEBUG_CONST = 'FFLHUB_GUNMADE_FEED_DEBUG';
    private const LOG_PREFIX = '[FFLHub][GunMadeFeed]';
    private const CONDITION = 'new';
    private const FREE_SHIPPING_LABEL = 'FREE SHIPPING';
    private const COMPETITOR_FEE_LABEL = 'NO SALES TAX/FEES';
    private const MIN_PROFIT_AFTER_FREE_SHIPPING = 0.01;
    private const BATCH_SIZE = 250;

    /**
     * @return array<string,mixed>
     */
    public function generate(): array
    {
        $started = microtime(true);
        $paths = $this->resolve_paths();
        $this->ensure_output_dir($paths['dir']);

        $xml_tmp = $paths['xml'] . '.tmp';
        $debug_tmp = $paths['debug_csv'] . '.tmp';
        $summary_tmp = $paths['summary_json'] . '.tmp';

        $summary = [
            'status' => 'running',
            'started_at_utc' => gmdate('c'),
            'finished_at_utc' => '',
            'xml_path' => $paths['xml'],
            'debug_csv_path' => $paths['debug_csv'],
            'summary_json_path' => $paths['summary_json'],
            'public_url' => $paths['public_url'],
            'uploads_public_url' => $paths['uploads_public_url'],
            'products_scanned' => 0,
            'products_written' => 0,
            'products_skipped' => 0,
            'skip_reasons' => [],
            'warnings' => [],
            'elapsed_ms' => 0,
            'xml_bytes' => 0,
            'feed_enabled' => Options::get_gunmade_feed_enabled() ? 1 : 0,
        ];

        if (!class_exists('\XMLWriter')) {
            throw new \RuntimeException('PHP XMLWriter extension is not available.');
        }

        if (!class_exists('\DOMDocument')) {
            throw new \RuntimeException('PHP DOM extension is not available.');
        }

        $writer = new \XMLWriter();
        if (!$writer->openURI($xml_tmp)) {
            throw new \RuntimeException('Unable to open Gunmade XML temp file for writing: ' . $xml_tmp);
        }

        $debug = @fopen($debug_tmp, 'wb');
        if (!is_resource($debug)) {
            $writer->flush();
            throw new \RuntimeException('Unable to open Gunmade debug CSV temp file for writing: ' . $debug_tmp);
        }

        $this->write_debug_header($debug);
        $this->start_feed($writer);

        if (!$summary['feed_enabled']) {
            $summary['warnings'][] = 'Gunmade feed disabled in FFL Hub settings; generated empty product feed.';
            $this->end_feed($writer);
            fclose($debug);

            return $this->finalize_generated_feed($xml_tmp, $debug_tmp, $summary_tmp, $paths, $summary, $started);
        }

        $last_id = 0;
        do {
            $source_rows = $this->query_product_source_rows($last_id);
            $source_count = count($source_rows);
            if ($source_count <= 0) {
                break;
            }

            foreach ($source_rows as $source_row) {
                $summary['products_scanned']++;
                $last_id = max($last_id, (int) ($source_row['product_id'] ?? 0));
                $row = $this->build_product_row($source_row);

                if (!$row['included']) {
                    $summary['products_skipped']++;
                    $reason = (string) $row['skip_reason'];
                    $summary['skip_reasons'][$reason] = (int) ($summary['skip_reasons'][$reason] ?? 0) + 1;
                    $this->write_debug_row($debug, $row);
                    continue;
                }

                $this->write_product($writer, $row);
                $summary['products_written']++;
                $this->write_debug_row($debug, $row);
            }
        } while ($source_count === self::BATCH_SIZE);

        $this->end_feed($writer);
        fclose($debug);

        return $this->finalize_generated_feed($xml_tmp, $debug_tmp, $summary_tmp, $paths, $summary, $started);
    }

    /**
     * @return array{dir:string,xml:string,debug_csv:string,summary_json:string,public_url:string,uploads_public_url:string}
     */
    public function resolve_paths(): array
    {
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        if (!is_array($uploads) || !empty($uploads['error']) || empty($uploads['basedir'])) {
            throw new \RuntimeException('Unable to resolve WordPress uploads directory.');
        }

        $dir = rtrim((string) $uploads['basedir'], "/\\") . DIRECTORY_SEPARATOR . 'feeds';
        $base_url = !empty($uploads['baseurl']) ? rtrim((string) $uploads['baseurl'], '/') : '';
        $home = function_exists('home_url') ? home_url('/gunmade-feed.xml') : '';

        return [
            'dir' => $dir,
            'xml' => $dir . DIRECTORY_SEPARATOR . 'gunmade-feed.xml',
            'debug_csv' => $dir . DIRECTORY_SEPARATOR . 'gunmade-feed-debug.csv',
            'summary_json' => $dir . DIRECTORY_SEPARATOR . 'gunmade-feed-summary.json',
            'public_url' => is_string($home) ? $home : '',
            'uploads_public_url' => $base_url !== '' ? $base_url . '/feeds/gunmade-feed.xml' : '',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function query_product_source_rows(int $last_id): array
    {
        global $wpdb;

        if (!$wpdb) {
            throw new \RuntimeException('WordPress database connection is unavailable.');
        }

        ProductStateStore::ensure_schema();

        $posts = $wpdb->posts;
        $product_state = ProductStateStore::table_name();
        $term_relationships = $wpdb->term_relationships;
        $term_taxonomy = $wpdb->term_taxonomy;
        $terms = $wpdb->terms;

        $sql = "
            SELECT
                p.ID AS product_id,
                p.post_title AS title,
                p.post_name AS slug,
                ps.upc AS upc,
                ps.distributor_id AS source,
                ps.distributor_product_id,
                ps.distributor_sku,
                ps.stock_status,
                ps.qty,
                ps.manufacturer_norm AS manufacturer_name,
                CAST(ps.computed_sell_price AS CHAR) AS computed_sell_price,
                CAST(ps.public_regular_price AS CHAR) AS public_regular_price,
                CAST(ps.public_sale_price AS CHAR) AS public_sale_price,
                CAST(ps.effective_map_price AS CHAR) AS map_price,
                CAST(COALESCE(ps.map_applicable, 0) AS CHAR) AS map_applicable,
                ps.map_visibility_policy,
                ps.pricing_mode,
                CAST(ps.shipping_cost AS CHAR) AS shipping_cost,
                CAST(ps.estimated_usps_shipping_cost AS CHAR) AS estimated_usps_shipping_cost,
                CAST(ps.landed_cost AS CHAR) AS landed_cost,
                CAST(ps.dealer_price AS CHAR) AS dealer_price,
                CAST(ps.quote_free_shipping_override AS CHAR) AS quote_free_shipping_override,
                CAST(ps.shipping_weight_oz AS CHAR) AS shipping_weight_oz,
                CAST(ps.shipping_length_in AS CHAR) AS shipping_length_in,
                CAST(ps.shipping_width_in AS CHAR) AS shipping_width_in,
                CAST(ps.shipping_height_in AS CHAR) AS shipping_height_in,
                CAST(ps.ffl_required AS CHAR) AS ffl_required,
                CAST(ps.dropship_enabled AS CHAR) AS dropship_enabled,
                COALESCE(CAST(ps.source_offer_normalized_at AS CHAR), CAST(ps.selected_at AS CHAR)) AS last_stock_update
            FROM {$posts} p
            INNER JOIN {$product_state} ps
                ON ps.product_id = p.ID
               AND ps.status = 'active'
            WHERE p.ID > %d
              AND p.post_type = 'product'
              AND p.post_status = 'publish'
              AND ps.upc <> ''
              AND ps.enabled = 1
              AND ps.stock_status = 'instock'
              AND ps.qty > 0
              AND NOT EXISTS (
                  SELECT 1
                  FROM {$term_relationships} tr_vis
                  INNER JOIN {$term_taxonomy} tt_vis
                      ON tt_vis.term_taxonomy_id = tr_vis.term_taxonomy_id
                     AND tt_vis.taxonomy = 'product_visibility'
                  INNER JOIN {$terms} t_vis
                      ON t_vis.term_id = tt_vis.term_id
                     AND t_vis.slug = 'exclude-from-catalog'
                  WHERE tr_vis.object_id = p.ID
              )
            ORDER BY p.ID ASC
            LIMIT %d
        ";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, max(0, $last_id), self::BATCH_SIZE),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string,mixed> $source_row
     * @return array<string,mixed>
     */
    private function build_product_row(array $source_row): array
    {
        $product_id = (int) ($source_row['product_id'] ?? 0);
        $map = $this->to_positive_float($source_row['map_price'] ?? null);
        $map_applicable = $this->to_boolish($source_row['map_applicable'] ?? null, false) && $map !== null;
        $policy = $this->normalize_map_policy((string) ($source_row['map_visibility_policy'] ?? ''));
        $price = $this->resolve_feed_price($source_row, $map, $map_applicable, $policy);
        $price_below_map = $map_applicable && $price > 0.0 && $map !== null && $price < ($map - 0.0001);
        $shipping_charge = $price > 0.0 ? $this->customer_shipping_charge_for_row($source_row, $price) : 0.0;

        $row = [
            'product_id' => $product_id,
            'title' => $this->clean_text((string) ($source_row['title'] ?? '')),
            'url' => $this->resolve_product_url($product_id),
            'image_url' => $this->resolve_image_url($product_id),
            'manufacturer_name' => $this->clean_text((string) ($source_row['manufacturer_name'] ?? '')),
            'model' => $this->resolve_model($source_row),
            'upc' => $this->resolve_upc_from_row($source_row),
            'mfg_number' => $this->resolve_mfg_number($source_row),
            'price' => $price > 0.0 ? number_format($price, 2, '.', '') : '',
            'priced_below_map' => $price_below_map,
            'add_to_cart_for_price' => $price_below_map && $policy === Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE,
            'email_for_price' => $price_below_map && $policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE,
            'in_stock' => strtolower(trim((string) ($source_row['stock_status'] ?? ''))) === 'instock'
                && ((int) ($source_row['qty'] ?? 0)) > 0,
            'in_store' => false,
            'condition' => self::CONDITION,
            'shipping_info' => $this->format_shipping_info($shipping_charge),
            'included' => false,
            'skip_reason' => '',
            'source' => $this->clean_text((string) ($source_row['source'] ?? '')),
            'last_stock_update' => $this->clean_text((string) ($source_row['last_stock_update'] ?? '')),
        ];

        if ($product_id <= 0) {
            return $this->skip($row, 'missing_product_id');
        }

        if (!$row['in_stock']) {
            return $this->skip($row, 'out_of_stock');
        }

        if ($row['title'] === '') {
            return $this->skip($row, 'missing_title');
        }

        if ($row['url'] === '') {
            return $this->skip($row, 'missing_url');
        }

        if ($row['upc'] === '') {
            return $this->skip($row, 'missing_upc');
        }

        if ($row['mfg_number'] === '') {
            return $this->skip($row, 'missing_mfg_number');
        }

        if ($row['price'] === '') {
            return $this->skip($row, 'missing_price');
        }

        $row['included'] = true;
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function skip(array $row, string $reason): array
    {
        $row['included'] = false;
        $row['skip_reason'] = $reason;
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolve_feed_price(array $row, ?float $map, bool $map_applicable, string $policy): float
    {
        $computed = $this->first_positive_float([
            $row['computed_sell_price'] ?? null,
            $row['public_sale_price'] ?? null,
            $row['public_regular_price'] ?? null,
        ]) ?? 0.0;

        if (!$map_applicable || $map === null) {
            return $computed;
        }

        if ($policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE || $policy === Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE) {
            return $computed;
        }

        if ($policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART && $computed < $map) {
            return $map;
        }

        return $computed;
    }

    private function resolve_product_url(int $product_id): string
    {
        $url = get_permalink($product_id);
        if (!is_string($url) || $url === '') {
            return '';
        }

        $url = function_exists('add_query_arg')
            ? add_query_arg(['utm_source' => 'gunmade'], $url)
            : $url;
        $url = esc_url_raw($url);

        return is_string($url) && preg_match('#^https?://#i', $url) ? $url : '';
    }

    private function resolve_image_url(int $product_id): string
    {
        $url = function_exists('get_the_post_thumbnail_url')
            ? get_the_post_thumbnail_url($product_id, 'full')
            : '';

        if (!is_string($url) || $url === '') {
            return '';
        }

        $url = esc_url_raw($url);
        return is_string($url) && preg_match('#^https?://#i', $url) ? $url : '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolve_model(array $row): string
    {
        return $this->clean_text((string) ($row['distributor_sku'] ?: ($row['distributor_product_id'] ?: '')));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolve_mfg_number(array $row): string
    {
        return $this->clean_text((string) ($row['distributor_sku'] ?: ($row['distributor_product_id'] ?: ($row['upc'] ?? ''))));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolve_upc_from_row(array $row): string
    {
        $value = preg_replace('/\D+/', '', (string) ($row['upc'] ?? ''));
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function customer_shipping_charge_for_row(array $row, float $line_revenue): float
    {
        if ($this->quote_free_shipping_override_enabled($row)) {
            return 0.0;
        }

        $shipping_costs = $this->estimate_shipping_costs_for_row($row);
        $shipping_cost_total = (float) ($shipping_costs['economic'] ?? 0.0);
        $customer_chargeable_shipping = (float) ($shipping_costs['customer_chargeable'] ?? $shipping_cost_total);
        if ($customer_chargeable_shipping <= 0.0) {
            return 0.0;
        }

        $fee_fraction = $this->payment_fee_fraction();
        // Match checkout: dealer cost excludes distributor freight, which is
        // evaluated separately as the shipping cost that may be absorbed.
        $dealer_cost = $this->to_non_negative_float($row['dealer_price'] ?? null, 0.0);
        $profit_net_total = ($line_revenue * (1.0 - $fee_fraction)) - $dealer_cost;
        $free_threshold = $this->free_shipping_cost_threshold(
            $profit_net_total,
            Options::get_free_shipping_max_profit_spend_percent()
        );

        if ($free_threshold > 0.0 && $shipping_cost_total <= ($free_threshold + 0.0001)) {
            $customer_charge = 0.0;
        } else {
            $customer_charge = $fee_fraction >= 0.99
                ? $customer_chargeable_shipping
                : ($customer_chargeable_shipping / (1.0 - $fee_fraction));
        }

        $shipping_settings = $this->shipping_method_settings_snapshot();
        $min_cart_ship = (float) ($shipping_settings['min_shipping'] ?? 0.0);
        $max_cart_ship = (float) ($shipping_settings['max_shipping'] ?? 0.0);

        $customer_charge = max($min_cart_ship, $customer_charge);
        if ($max_cart_ship > 0.0) {
            $customer_charge = min($max_cart_ship, $customer_charge);
        }

        return max(0.0, $customer_charge);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{economic:float,customer_chargeable:float}
     */
    private function estimate_shipping_costs_for_row(array $row): array
    {
        $shipping_settings = $this->shipping_method_settings_snapshot();
        $fallback_ship = (float) ($shipping_settings['fallback_shipping'] ?? 15.0);

        $dist_id = strtolower(trim((string) ($row['source'] ?? '')));
        if ($dist_id === '') {
            return [
                'economic' => max(0.0, $fallback_ship),
                'customer_chargeable' => max(0.0, $fallback_ship),
            ];
        }

        $dist_lane_fee = $this->resolve_distributor_lane_fee($row['shipping_cost'] ?? null, $fallback_ship);
        $estimated_usps_shipping = $this->resolve_distributor_lane_fee(
            $row['estimated_usps_shipping_cost'] ?? null,
            $fallback_ship
        );
        $use_product_state_usps_shipping = Options::get_use_product_state_usps_shipping();

        $plan = DealerFulfillmentRoutingPlanner::find_cheapest_plan([
            [
                'line_id' => 'gunmade_line',
                'dist_id' => $dist_id,
                'qty' => 1,
                'weight_oz' => $this->to_non_negative_float($row['shipping_weight_oz'] ?? null, 0.0),
                'ffl_required' => $this->to_boolish($row['ffl_required'] ?? null, false) ? 1 : 0,
                'dropship_enabled' => $this->to_boolish($row['dropship_enabled'] ?? null, true) ? 1 : 0,
                'dist_lane_fee' => $dist_lane_fee,
                'dealer_outbound_unit_cost' => $estimated_usps_shipping,
                'length_in' => $this->to_non_negative_float($row['shipping_length_in'] ?? null, 0.0),
                'width_in' => $this->to_non_negative_float($row['shipping_width_in'] ?? null, 0.0),
                'height_in' => $this->to_non_negative_float($row['shipping_height_in'] ?? null, 0.0),
            ],
        ], $use_product_state_usps_shipping);

        return CustomerShippingCostPolicy::single_line_costs(
            $row,
            $plan,
            'gunmade_line',
            $use_product_state_usps_shipping
        );
    }

    private function quote_free_shipping_override_enabled(array $row): bool
    {
        if ($this->to_positive_float($row['map_price'] ?? null) === null) {
            return false;
        }

        $policy = $this->normalize_map_policy((string) ($row['map_visibility_policy'] ?? ''));
        if ($policy !== Options::MAP_POLICY_EMAIL_FOR_QUOTE) {
            return false;
        }

        return $this->to_boolish($row['quote_free_shipping_override'] ?? null, false);
    }

    private function normalize_map_policy(string $policy): string
    {
        $policy = strtolower(trim($policy));
        if ($policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE) {
            return Options::MAP_POLICY_EMAIL_FOR_QUOTE;
        }

        if ($policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) {
            return Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART;
        }

        return Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
    }

    private function format_shipping_info(float $shipping_charge): string
    {
        $shipping = $shipping_charge <= 0.0001
            ? self::FREE_SHIPPING_LABEL
            : '$' . number_format($shipping_charge, 2, '.', '') . ' Shipping';

        return $shipping . ' | ' . self::COMPETITOR_FEE_LABEL;
    }

    private function free_shipping_cost_threshold(float $profit_net_total, float $max_profit_spend_percent): float
    {
        if ($profit_net_total <= self::MIN_PROFIT_AFTER_FREE_SHIPPING) {
            return 0.0;
        }

        $max_profit_spend_percent = max(0.0, min(100.0, $max_profit_spend_percent));
        $percent_threshold = $profit_net_total * ($max_profit_spend_percent / 100.0);
        $penny_profit_threshold = $profit_net_total - self::MIN_PROFIT_AFTER_FREE_SHIPPING;

        return max(0.0, min($percent_threshold, $penny_profit_threshold));
    }

    /**
     * @return array{fallback_shipping:float,min_shipping:float,max_shipping:float}
     */
    private function shipping_method_settings_snapshot(): array
    {
        static $snapshot = null;
        if (is_array($snapshot)) {
            return $snapshot;
        }

        $defaults = [
            'fallback_shipping' => 15.0,
            'min_shipping' => 0.0,
            'max_shipping' => 0.0,
        ];

        $settings = null;
        global $wpdb;
        if (isset($wpdb) && $wpdb) {
            $like = $wpdb->esc_like('woocommerce_fflhub_shipping_') . '%_settings';
            $option_names = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
                    $like
                )
            );

            if (is_array($option_names)) {
                foreach ($option_names as $option_name) {
                    $value = get_option((string) $option_name, null);
                    if (is_array($value)) {
                        $settings = $value;
                        break;
                    }
                }
            }
        }

        if (!is_array($settings)) {
            $legacy = get_option('woocommerce_fflhub_shipping_settings', null);
            if (is_array($legacy)) {
                $settings = $legacy;
            }
        }

        if (!is_array($settings)) {
            $snapshot = $defaults;
            return $snapshot;
        }

        $snapshot = [
            'fallback_shipping' => $this->to_non_negative_float($settings['fallback_shipping'] ?? null, $defaults['fallback_shipping']),
            'min_shipping' => $this->to_non_negative_float($settings['min_shipping'] ?? null, $defaults['min_shipping']),
            'max_shipping' => $this->to_non_negative_float($settings['max_shipping'] ?? null, $defaults['max_shipping']),
        ];

        return $snapshot;
    }

    private function payment_fee_fraction(): float
    {
        $fee_percent = Options::get_payment_processor_fee_percent();
        $fraction = $fee_percent / 100.0;
        if ($fraction < 0.0) {
            return 0.0;
        }

        return min(0.99, $fraction);
    }

    /**
     * @param array<int,mixed> $values
     */
    private function first_positive_float(array $values): ?float
    {
        foreach ($values as $value) {
            $parsed = $this->to_positive_float($value);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function to_positive_float($value): ?float
    {
        $parsed = $this->to_non_negative_float($value, -1.0);
        return $parsed > 0.0 ? $parsed : null;
    }

    /**
     * @param mixed $value
     */
    private function to_non_negative_float($value, float $default = 0.0): float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return max(0.0, $default);
        }

        $num = is_numeric($raw) ? $raw : trim((string) preg_replace('/[^0-9\.\-]/', '', $raw));
        if ($num === '' || !is_numeric($num)) {
            return max(0.0, $default);
        }

        $v = (float) $num;
        if (!is_finite($v) || $v < 0.0) {
            return max(0.0, $default);
        }

        return $v;
    }

    /**
     * @param mixed $value
     */
    private function resolve_distributor_lane_fee($value, float $fallback): float
    {
        $fallback = max(0.0, $fallback);
        $raw = trim((string) $value);
        if ($raw === '') {
            return $fallback;
        }

        $num = is_numeric($raw) ? $raw : trim((string) preg_replace('/[^0-9\.\-]/', '', $raw));
        if ($num === '' || !is_numeric($num)) {
            return $fallback;
        }

        $fee = (float) $num;
        if (!is_finite($fee) || $fee < 0.0) {
            return $fallback;
        }

        return $fee;
    }

    /**
     * @param mixed $value
     */
    private function to_boolish($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return $default;
        }

        if (in_array($raw, ['1', 'true', 't', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($raw, ['0', 'false', 'f', 'no', 'n', 'off'], true)) {
            return false;
        }

        if (is_numeric($raw)) {
            return ((float) $raw) !== 0.0;
        }

        return $default;
    }

    private function start_feed(\XMLWriter $writer): void
    {
        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElement('channel');

        $writer->startElement('dealer');
        $this->write_cdata_element($writer, 'domainName', home_url('/'));
        $writer->endElement();

        $writer->startElement('products');
    }

    private function end_feed(\XMLWriter $writer): void
    {
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
    }

    /**
     * @param array<string,mixed> $row
     */
    private function write_product(\XMLWriter $writer, array $row): void
    {
        $writer->startElement('product');
        $this->write_cdata_element($writer, 'title', (string) $row['title']);
        $this->write_cdata_element($writer, 'url', (string) $row['url']);

        if ((string) $row['image_url'] !== '') {
            $this->write_cdata_element($writer, 'imageUrl', (string) $row['image_url']);
        }

        if ((string) $row['manufacturer_name'] !== '') {
            $this->write_cdata_element($writer, 'manufacturerName', (string) $row['manufacturer_name']);
        }

        if ((string) $row['model'] !== '') {
            $this->write_cdata_element($writer, 'model', (string) $row['model']);
        }

        $this->write_text_element($writer, 'upc', (string) $row['upc']);
        $this->write_cdata_element($writer, 'mfgNumber', (string) $row['mfg_number']);

        $writer->startElement('locations');
        $writer->startElement('location');
        $this->write_text_element($writer, 'price', (string) $row['price']);
        $this->write_bool_element($writer, 'pricedBelowMAP', (bool) $row['priced_below_map']);
        $this->write_bool_element($writer, 'addToCartForPrice', (bool) $row['add_to_cart_for_price']);
        $this->write_bool_element($writer, 'emailForPrice', (bool) $row['email_for_price']);
        $this->write_bool_element($writer, 'inStock', (bool) $row['in_stock']);
        $this->write_bool_element($writer, 'inStore', (bool) $row['in_store']);
        $this->write_cdata_element($writer, 'condition', (string) $row['condition']);
        $this->write_cdata_element($writer, 'shippingInfo', (string) $row['shipping_info']);
        $writer->endElement();
        $writer->endElement();

        $writer->endElement();
    }

    /**
     * @param resource $debug
     */
    private function write_debug_header($debug): void
    {
        fputcsv($debug, [
            'product_id',
            'title',
            'upc',
            'mfg_number',
            'manufacturer_name',
            'model',
            'price',
            'priced_below_map',
            'add_to_cart_for_price',
            'email_for_price',
            'in_stock',
            'shipping_info',
            'included',
            'skip_reason',
            'url',
            'image_url',
            'source',
            'last_stock_update',
        ]);
    }

    /**
     * @param resource $debug
     * @param array<string,mixed> $row
     */
    private function write_debug_row($debug, array $row): void
    {
        fputcsv($debug, [
            (int) $row['product_id'],
            (string) $row['title'],
            (string) $row['upc'],
            (string) $row['mfg_number'],
            (string) $row['manufacturer_name'],
            (string) $row['model'],
            (string) $row['price'],
            !empty($row['priced_below_map']) ? '1' : '0',
            !empty($row['add_to_cart_for_price']) ? '1' : '0',
            !empty($row['email_for_price']) ? '1' : '0',
            !empty($row['in_stock']) ? '1' : '0',
            (string) $row['shipping_info'],
            !empty($row['included']) ? '1' : '0',
            (string) $row['skip_reason'],
            (string) $row['url'],
            (string) $row['image_url'],
            (string) $row['source'],
            (string) $row['last_stock_update'],
        ]);
    }

    private function write_cdata_element(\XMLWriter $writer, string $name, string $value): void
    {
        $writer->startElement($name);
        $parts = explode(']]>', $value);
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $writer->text(']]>');
            }
            $writer->writeCData($part);
        }
        $writer->endElement();
    }

    private function write_text_element(\XMLWriter $writer, string $name, string $value): void
    {
        $writer->startElement($name);
        $writer->text($value);
        $writer->endElement();
    }

    private function write_bool_element(\XMLWriter $writer, string $name, bool $value): void
    {
        $this->write_text_element($writer, $name, $value ? 'true' : 'false');
    }

    private function ensure_output_dir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        $ok = function_exists('wp_mkdir_p') ? wp_mkdir_p($dir) : @mkdir($dir, 0775, true);
        if (!$ok && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create Gunmade feed directory: ' . $dir);
        }
    }

    /**
     * @param array{dir:string,xml:string,debug_csv:string,summary_json:string,public_url:string,uploads_public_url:string} $paths
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function finalize_generated_feed(
        string $xml_tmp,
        string $debug_tmp,
        string $summary_tmp,
        array $paths,
        array $summary,
        float $started
    ): array {
        $validation = $this->validate_xml($xml_tmp);
        if (!$validation['ok']) {
            throw new \RuntimeException('Generated Gunmade XML failed validation: ' . $validation['error']);
        }

        $summary['status'] = 'success';
        $summary['finished_at_utc'] = gmdate('c');
        $summary['elapsed_ms'] = number_format((microtime(true) - $started) * 1000.0, 2, '.', '');
        $summary['xml_bytes'] = is_file($xml_tmp) ? (int) filesize($xml_tmp) : 0;

        $this->write_summary_json($summary_tmp, $summary);
        $this->publish_file($xml_tmp, $paths['xml']);
        $this->publish_file($debug_tmp, $paths['debug_csv']);
        $this->publish_file($summary_tmp, $paths['summary_json']);

        self::debug_ctx('feed generation complete', $summary);

        return $summary;
    }

    /**
     * @return array{ok:bool,error:string}
     */
    private function validate_xml(string $path): array
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->load($path);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[] = trim((string) $error->message);
            }

            return [
                'ok' => false,
                'error' => implode('; ', array_filter($messages)) ?: 'XML parse failed.',
            ];
        }

        $root = $dom->documentElement;
        if (!$root || $root->tagName !== 'channel') {
            return [
                'ok' => false,
                'error' => 'Root element is not channel.',
            ];
        }

        return [
            'ok' => true,
            'error' => '',
        ];
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function write_summary_json(string $path, array $summary): void
    {
        $json = wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to encode Gunmade feed summary JSON.');
        }

        if (@file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write Gunmade feed summary JSON: ' . $path);
        }
    }

    private function publish_file(string $tmp, string $final): void
    {
        if (!is_file($tmp)) {
            throw new \RuntimeException('Gunmade temp file missing: ' . $tmp);
        }

        if (is_file($final) && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @unlink($final);
        }

        if (!@rename($tmp, $final)) {
            throw new \RuntimeException('Unable to publish Gunmade feed file: ' . $final);
        }
    }

    private function clean_text(string $value): string
    {
        $value = wp_strip_all_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace('&', ' and ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim(is_string($value) ? $value : '');
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private static function debug_ctx(string $message, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $message, $ctx);
    }
}
