<?php

namespace FFLHub\Feeds\GunDeals;

use FFLHub\Distributor\Services\Routing\DealerFulfillmentRoutingPlanner;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Settings\Options;
use FFLHub\Shipping\CustomerShippingCostPolicy;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

final class GunDealsFeedGenerator
{
    private const DEBUG_CONST = 'FFLHUB_GUNDEALS_FEED_DEBUG';
    private const LOG_PREFIX = '[FFLHub][GunDealsFeed]';
    private const XML_NAMESPACE = 'https://api.gunengine.com/ingest/XMLSchema/feed/v2/offers';
    private const FREE_SHIPPING_LABEL = 'FREE SHIPPING';
    private const COMPETITOR_FEE_LABEL = 'NO SALES TAX/FEES';
    private const PRICE_HIDE_EMAIL_FOR_QUOTE = 'Email Form for Best Price';
    private const PRICE_HIDE_ADD_TO_CART = 'Add To Cart For Best Price';
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
            'products_scanned' => 0,
            'offers_written' => 0,
            'products_skipped' => 0,
            'skip_reasons' => [],
            'warnings' => [],
            'elapsed_ms' => 0,
            'xml_bytes' => 0,
            'feed_enabled' => Options::get_gundeals_feed_enabled() ? 1 : 0,
            'product_state_usps_shipping' => Options::get_use_product_state_usps_shipping() ? 1 : 0,
        ];

        if (!class_exists('\XMLWriter')) {
            throw new \RuntimeException('PHP XMLWriter extension is not available.');
        }

        if (!class_exists('\DOMDocument')) {
            throw new \RuntimeException('PHP DOM extension is not available.');
        }

        $writer = new \XMLWriter();
        if (!$writer->openURI($xml_tmp)) {
            throw new \RuntimeException('Unable to open Gun.deals XML temp file for writing: ' . $xml_tmp);
        }

        $debug = @fopen($debug_tmp, 'wb');
        if (!is_resource($debug)) {
            $writer->flush();
            throw new \RuntimeException('Unable to open Gun.deals debug CSV temp file for writing: ' . $debug_tmp);
        }

        fputcsv($debug, [
            'product_id',
            'title',
            'sku',
            'upc',
            'brand',
            'category',
            'price',
            'price_hide',
            'stock_status',
            'shipping_info',
            'shipping_charge',
            'included',
            'skip_reason',
            'product_url',
            'image_url',
            'source',
            'last_stock_update',
        ]);

        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElementNS(null, 'offers', self::XML_NAMESPACE);

        if (!$summary['feed_enabled']) {
            $summary['warnings'][] = 'Gun.deals feed disabled in FFL Hub settings; generated empty offers feed.';
            $writer->endElement();
            $writer->endDocument();
            $writer->flush();
            fclose($debug);

            return $this->finalize_generated_feed(
                $xml_tmp,
                $debug_tmp,
                $summary_tmp,
                $paths,
                $summary,
                $started
            );
        }

        $last_id = 0;
        do {
            $source_rows = $this->query_offer_source_rows($last_id);
            $source_count = count($source_rows);
            if ($source_count <= 0) {
                break;
            }

            foreach ($source_rows as $source_row) {
                $summary['products_scanned']++;
                $last_id = max($last_id, (int) ($source_row['product_id'] ?? 0));
                $row = $this->build_offer_row($source_row);

                if (!$row['included']) {
                    $summary['products_skipped']++;
                    $reason = (string) $row['skip_reason'];
                    $summary['skip_reasons'][$reason] = (int) ($summary['skip_reasons'][$reason] ?? 0) + 1;
                    $this->write_debug_row($debug, $row);
                    continue;
                }

                $this->write_offer($writer, $row);
                $summary['offers_written']++;
                $this->write_debug_row($debug, $row);
            }
        } while ($source_count === self::BATCH_SIZE);

        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
        fclose($debug);

        return $this->finalize_generated_feed(
            $xml_tmp,
            $debug_tmp,
            $summary_tmp,
            $paths,
            $summary,
            $started
        );
    }

    /**
     * @param array{dir:string,xml:string,debug_csv:string,summary_json:string,public_url:string} $paths
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
            throw new \RuntimeException('Generated Gun.deals XML failed validation: ' . $validation['error']);
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
     * @return array{dir:string,xml:string,debug_csv:string,summary_json:string,public_url:string}
     */
    private function resolve_paths(): array
    {
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        if (!is_array($uploads) || !empty($uploads['error']) || empty($uploads['basedir'])) {
            throw new \RuntimeException('Unable to resolve WordPress uploads directory.');
        }

        $dir = rtrim((string) $uploads['basedir'], "/\\") . DIRECTORY_SEPARATOR . 'feeds';
        $base_url = !empty($uploads['baseurl']) ? rtrim((string) $uploads['baseurl'], '/') : '';

        return [
            'dir' => $dir,
            'xml' => $dir . DIRECTORY_SEPARATOR . 'gundeals-feed.xml',
            'debug_csv' => $dir . DIRECTORY_SEPARATOR . 'gundeals-feed-debug.csv',
            'summary_json' => $dir . DIRECTORY_SEPARATOR . 'gundeals-feed-summary.json',
            'public_url' => $base_url !== '' ? $base_url . '/feeds/gundeals-feed.xml' : '',
        ];
    }

    private function ensure_output_dir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        $ok = function_exists('wp_mkdir_p') ? wp_mkdir_p($dir) : @mkdir($dir, 0775, true);
        if (!$ok && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create Gun.deals feed directory: ' . $dir);
        }
    }

    /**
     * Query feed values from product_state, with posts used only for URL/title.
     *
     * @return array<int,array<string,mixed>>
     */
    private function query_offer_source_rows(int $last_id): array
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
                '' AS sku,
                ps.stock_status AS stock_status,
                CAST(COALESCE(ps.public_sale_price, ps.public_regular_price, ps.computed_sell_price) AS CHAR) AS price,
                CAST(ps.public_regular_price AS CHAR) AS regular_price,
                CAST(ps.public_sale_price AS CHAR) AS sale_price,
                ps.upc AS fflhub_upc,
                '1' AS fflhub_managed,
                ps.distributor_id AS source,
                COALESCE(CAST(ps.source_offer_normalized_at AS CHAR), CAST(ps.selected_at AS CHAR)) AS last_stock_update,
                CAST(ps.landed_cost AS CHAR) AS true_cost,
                CAST(ps.dealer_price AS CHAR) AS dealer_price,
                CAST(ps.effective_map_price AS CHAR) AS map_price,
                CAST(ps.msrp AS CHAR) AS msrp,
                CAST(ps.computed_sell_price AS CHAR) AS computed_price,
                CAST(COALESCE(ps.map_applicable, 0) AS CHAR) AS map_applicable,
                ps.map_visibility_policy AS map_policy,
                ps.pricing_mode AS pricing_mode,
                ps.pricing_mode AS markup_mode,
                CAST(ps.pricing_fixed_profit AS CHAR) AS map_real_price_fixed_profit,
                CAST(ps.quote_free_shipping_override AS CHAR) AS map_real_price_free_shipping_override,
                CAST(ps.shipping_cost AS CHAR) AS shipping_cost,
                CAST(ps.estimated_usps_shipping_cost AS CHAR) AS estimated_usps_shipping_cost,
                CAST(ps.shipping_weight_oz AS CHAR) AS shipping_weight_oz,
                CAST(ps.shipping_length_in AS CHAR) AS shipping_length_in,
                CAST(ps.shipping_width_in AS CHAR) AS shipping_width_in,
                CAST(ps.shipping_height_in AS CHAR) AS shipping_height_in,
                CAST(ps.ffl_required AS CHAR) AS ffl_required,
                CAST(ps.dropship_enabled AS CHAR) AS dropship_enabled,
                ps.manufacturer_norm AS brand,
                '1' AS product_state_exists
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
    private function build_offer_row(array $source_row): array
    {
        $product_id = (int) ($source_row['product_id'] ?? 0);
        $actual_price = $this->resolve_price_from_row($source_row);
        $price_hide = $this->resolve_price_hide_from_row($source_row);
        $feed_price = $this->resolve_feed_price($actual_price, $source_row);
        $shipping_charge = $actual_price > 0.0 ? $this->customer_shipping_charge_for_row($source_row, $actual_price) : 0.0;

        $row = [
            'product_id' => $product_id,
            'title' => $this->clean_text((string) ($source_row['title'] ?? '')),
            'sku' => $this->clean_text((string) ($source_row['sku'] ?? '')),
            'upc' => $this->resolve_upc_from_row($source_row),
            'brand' => $this->clean_text((string) ($source_row['brand'] ?? '')),
            'category' => '',
            'price' => $feed_price > 0.0 ? number_format($feed_price, 2, '.', '') : '',
            'price_hide' => $price_hide,
            'stock_status' => $this->clean_text((string) ($source_row['stock_status'] ?? '')),
            'shipping_info' => $this->format_shipping_info($shipping_charge),
            'shipping_charge' => number_format(max(0.0, $shipping_charge), 2, '.', ''),
            'included' => false,
            'skip_reason' => '',
            'product_url' => $this->resolve_product_url($product_id),
            'image_url' => '',
            'source' => $this->clean_text((string) ($source_row['source'] ?? '')),
            'last_stock_update' => $this->clean_text((string) ($source_row['last_stock_update'] ?? '')),
        ];

        if ($product_id <= 0) {
            return $this->skip($row, 'missing_product_id');
        }

        if (strtolower((string) $row['stock_status']) !== 'instock') {
            return $this->skip($row, 'out_of_stock');
        }

        if ($row['upc'] === '') {
            return $this->skip($row, 'missing_upc');
        }

        if ($row['title'] === '') {
            return $this->skip($row, 'missing_name');
        }

        if ($row['product_url'] === '') {
            return $this->skip($row, 'missing_url');
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

    private function resolve_product_url(int $product_id): string
    {
        $url = get_permalink($product_id);
        if (!is_string($url) || $url === '') {
            return '';
        }

        $url = $this->append_tracking_params($url);
        $url = esc_url_raw($url);
        if (!is_string($url) || !preg_match('#^https?://#i', $url)) {
            return '';
        }

        return $url;
    }

    private function append_tracking_params(string $url): string
    {
        if (!function_exists('add_query_arg')) {
            return $url;
        }

        return add_query_arg([
            'utm_source' => 'gundeals',
        ], $url);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolve_upc_from_row(array $row): string
    {
        $value = preg_replace('/\D+/', '', (string) ($row['fflhub_upc'] ?? ''));
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolve_price_from_row(array $row): float
    {
        if (!$this->is_no_email_no_add_to_cart_policy_row($row)) {
            $map_real_price = $this->resolve_map_real_price_from_row($row);
            if ($map_real_price !== null) {
                return $map_real_price;
            }
        }

        return $this->first_positive_float([
            $row['price'] ?? null,
            $row['sale_price'] ?? null,
            $row['regular_price'] ?? null,
        ]) ?? 0.0;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolve_feed_price(float $actual_price, array $row): float
    {
        if ($actual_price <= 0.0) {
            return 0.0;
        }

        if ($this->is_no_email_no_add_to_cart_policy_row($row)) {
            return max(0.01, $actual_price - 1.00);
        }

        return $actual_price;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolve_price_hide_from_row(array $row): string
    {
        if (!$this->to_boolish($row['fflhub_managed'] ?? '', false)) {
            return '';
        }

        $map = $this->to_positive_float($row['map_price'] ?? null);
        if ($map === null) {
            return '';
        }

        $raw_policy = strtolower(trim((string) ($row['map_policy'] ?? '')));
        if ($raw_policy === '') {
            return '';
        }

        $policy = $this->normalize_map_policy($raw_policy);
        if ($policy === 'none') {
            return '';
        }

        if ($policy === Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE) {
            $price = $this->resolve_price_from_row($row);
            if ($price <= 0.0 || $price >= ($map - 0.0001)) {
                return '';
            }

            return self::PRICE_HIDE_ADD_TO_CART;
        }

        if ($policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE) {
            return self::PRICE_HIDE_EMAIL_FOR_QUOTE;
        }

        return '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function is_no_email_no_add_to_cart_policy_row(array $row): bool
    {
        if ($this->to_positive_float($row['map_price'] ?? null) === null) {
            return false;
        }

        $raw_policy = strtolower(trim((string) ($row['map_policy'] ?? '')));
        if ($raw_policy === '') {
            return false;
        }

        return $this->normalize_map_policy($raw_policy) === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolve_map_real_price_from_row(array $row): ?float
    {
        return $this->first_positive_float([
            $row['computed_price'] ?? null,
            $row['sale_price'] ?? null,
            $row['regular_price'] ?? null,
            $row['price'] ?? null,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function customer_shipping_charge_for_row(array $row, float $line_revenue): float
    {
        if ($this->map_real_price_free_shipping_override_enabled($row)) {
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

        $weight_oz = $this->to_non_negative_float($row['shipping_weight_oz'] ?? null, 0.0);
        $length_in = $this->to_non_negative_float($row['shipping_length_in'] ?? null, 0.0);
        $width_in = $this->to_non_negative_float($row['shipping_width_in'] ?? null, 0.0);
        $height_in = $this->to_non_negative_float($row['shipping_height_in'] ?? null, 0.0);
        $ffl_required = $this->to_boolish($row['ffl_required'] ?? null, false);
        $dropship_enabled = $this->to_boolish($row['dropship_enabled'] ?? null, true);

        $plan = DealerFulfillmentRoutingPlanner::find_cheapest_plan([
            [
                'line_id' => 'gundeals_line',
                'dist_id' => $dist_id,
                'qty' => 1,
                'weight_oz' => $weight_oz,
                'ffl_required' => $ffl_required ? 1 : 0,
                'dropship_enabled' => $dropship_enabled ? 1 : 0,
                'dist_lane_fee' => $dist_lane_fee,
                'dealer_outbound_unit_cost' => $estimated_usps_shipping,
                'length_in' => $length_in,
                'width_in' => $width_in,
                'height_in' => $height_in,
            ],
        ], $use_product_state_usps_shipping);

        return CustomerShippingCostPolicy::single_line_costs(
            $row,
            $plan,
            'gundeals_line',
            $use_product_state_usps_shipping
        );
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
     * @param array<string,mixed> $row
     */
    private function map_real_price_free_shipping_override_enabled(array $row): bool
    {
        if ($this->to_positive_float($row['map_price'] ?? null) === null) {
            return false;
        }

        $raw_policy = strtolower(trim((string) ($row['map_policy'] ?? '')));
        if ($this->normalize_map_policy($raw_policy) !== Options::MAP_POLICY_EMAIL_FOR_QUOTE) {
            return false;
        }

        return $this->to_boolish($row['map_real_price_free_shipping_override'] ?? null, false);
    }

    private function normalize_map_policy(string $policy): string
    {
        $policy = strtolower(trim($policy));
        if ($policy === 'none') {
            return 'none';
        }

        if ($policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE) {
            return Options::MAP_POLICY_EMAIL_FOR_QUOTE;
        }

        if ($policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) {
            return Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART;
        }

        return Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
    }

    /**
     * @return array{fallback_shipping:float,min_shipping:float,max_shipping:float}
     */
    private function shipping_method_settings_snapshot(): array
    {
        static $snapshot = null;
        if (is_array($snapshot)) {
            /** @var array{fallback_shipping:float,min_shipping:float,max_shipping:float} $snapshot */
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
                    "SELECT option_name
                     FROM {$wpdb->options}
                     WHERE option_name LIKE %s
                     ORDER BY option_name ASC",
                    $like
                )
            );

            if (is_array($option_names)) {
                foreach ($option_names as $option_name) {
                    if (!is_string($option_name) || $option_name === '') {
                        continue;
                    }

                    $value = get_option($option_name, null);
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

        $num = $raw;
        if (!is_numeric($num)) {
            $num = trim((string) preg_replace('/[^0-9\.\-]/', '', $raw));
        }

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

        $num = $raw;
        if (!is_numeric($num)) {
            $num = trim((string) preg_replace('/[^0-9\.\-]/', '', $raw));
        }

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

    /**
     * @param resource $debug
     * @param array<string,mixed> $row
     */
    private function write_debug_row($debug, array $row): void
    {
        fputcsv($debug, [
            (int) $row['product_id'],
            (string) $row['title'],
            (string) $row['sku'],
            (string) $row['upc'],
            (string) $row['brand'],
            (string) $row['category'],
            (string) $row['price'],
            (string) $row['price_hide'],
            (string) $row['stock_status'],
            (string) $row['shipping_info'],
            (string) $row['shipping_charge'],
            !empty($row['included']) ? '1' : '0',
            (string) $row['skip_reason'],
            (string) $row['product_url'],
            (string) $row['image_url'],
            (string) $row['source'],
            (string) $row['last_stock_update'],
        ]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function write_offer(\XMLWriter $writer, array $row): void
    {
        $writer->startElement('offer');
        $this->write_text_element($writer, 'upc', (string) $row['upc']);
        $this->write_text_element($writer, 'name', (string) $row['title']);

        if ((string) $row['brand'] !== '') {
            $this->write_text_element($writer, 'brand', (string) $row['brand']);
        }

        $this->write_text_element($writer, 'url', (string) $row['product_url']);
        $this->write_text_element($writer, 'availability', 'in stock');
        $this->write_price_element($writer, (string) $row['price'], (string) $row['price_hide']);
        $this->write_text_element($writer, 'shippingInfo', (string) $row['shipping_info']);

        if ((string) $row['image_url'] !== '') {
            $this->write_text_element($writer, 'imageUrl', (string) $row['image_url']);
        }

        $writer->endElement();
    }

    private function write_price_element(\XMLWriter $writer, string $price, string $hide): void
    {
        $writer->startElement('price');
        if ($hide !== '') {
            $writer->writeAttribute('hide', $hide);
        }
        $writer->text($price);
        $writer->endElement();
    }

    private function write_text_element(\XMLWriter $writer, string $name, string $value): void
    {
        $writer->startElement($name);
        $writer->text($value);
        $writer->endElement();
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
        if (!$root || $root->localName !== 'offers' || $root->namespaceURI !== self::XML_NAMESPACE) {
            return [
                'ok' => false,
                'error' => 'Root element is not the Gun.deals offers namespace.',
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
            throw new \RuntimeException('Unable to encode Gun.deals feed summary JSON.');
        }

        if (@file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write Gun.deals feed summary JSON: ' . $path);
        }
    }

    private function publish_file(string $tmp, string $final): void
    {
        if (!is_file($tmp)) {
            throw new \RuntimeException('Gun.deals temp file missing: ' . $tmp);
        }

        if (is_file($final) && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @unlink($final);
        }

        if (!@rename($tmp, $final)) {
            throw new \RuntimeException('Unable to publish Gun.deals feed file: ' . $final);
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
