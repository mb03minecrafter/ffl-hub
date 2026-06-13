<?php

namespace FFLHub\Feeds\GunDeals;

use FFLHub\Distributor\Services\Routing\DealerFulfillmentRoutingPlanner;
use FFLHub\Product\ProductMeta;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Settings\Options;
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
        $drift_tmp = $paths['product_state_drift_csv'] . '.tmp';
        $summary_tmp = $paths['summary_json'] . '.tmp';

        $summary = [
            'status' => 'running',
            'started_at_utc' => gmdate('c'),
            'finished_at_utc' => '',
            'xml_path' => $paths['xml'],
            'debug_csv_path' => $paths['debug_csv'],
            'product_state_drift_csv_path' => $paths['product_state_drift_csv'],
            'summary_json_path' => $paths['summary_json'],
            'public_url' => $paths['public_url'],
            'products_scanned' => 0,
            'offers_written' => 0,
            'products_skipped' => 0,
            'skip_reasons' => [],
            'warnings' => [],
            'elapsed_ms' => 0,
            'xml_bytes' => 0,
            'feed_snapshot_rows' => 0,
            'product_state_drift_rows' => 0,
            'product_state_drift_products' => 0,
            'product_state_drift_fields' => [],
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

        $drift = @fopen($drift_tmp, 'wb');
        if (!is_resource($drift)) {
            $writer->flush();
            fclose($debug);
            throw new \RuntimeException('Unable to open Gun.deals product_state drift CSV temp file for writing: ' . $drift_tmp);
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

        fputcsv($drift, [
            'product_id',
            'upc',
            'field',
            'product_state_value',
            'legacy_meta_value',
            'title',
        ]);

        $snapshot_rows = [];
        $drift_stats = [
            'rows' => 0,
            'products' => [],
            'fields' => [],
        ];

        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElementNS(null, 'offers', self::XML_NAMESPACE);

        $last_id = 0;
        do {
            $source_rows = $this->query_offer_source_rows($last_id);
            $source_count = count($source_rows);
            if ($source_count <= 0) {
                break;
            }

            $batch_ids = array_map(static function (array $row): int {
                return (int) ($row['product_id'] ?? 0);
            }, $source_rows);

            $term_maps = $this->query_term_maps($batch_ids);
            $image_url_map = $this->query_image_url_map($source_rows);

            foreach ($source_rows as $source_row) {
                $summary['products_scanned']++;
                $last_id = max($last_id, (int) ($source_row['product_id'] ?? 0));
                $this->write_product_state_drift_rows($drift, $source_row, $drift_stats);
                $row = $this->build_offer_row($source_row, $term_maps, $image_url_map);

                if (!$row['included']) {
                    $summary['products_skipped']++;
                    $reason = (string) $row['skip_reason'];
                    $summary['skip_reasons'][$reason] = (int) ($summary['skip_reasons'][$reason] ?? 0) + 1;
                    $this->write_debug_row($debug, $row);
                    continue;
                }

                $this->write_offer($writer, $row);
                $summary['offers_written']++;
                $snapshot_rows[] = $row;
                $this->write_debug_row($debug, $row);
            }
        } while ($source_count === self::BATCH_SIZE);

        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
        fclose($debug);
        fclose($drift);

        $validation = $this->validate_xml($xml_tmp);
        if (!$validation['ok']) {
            throw new \RuntimeException('Generated Gun.deals XML failed validation: ' . $validation['error']);
        }

        $summary['status'] = 'success';
        $summary['finished_at_utc'] = gmdate('c');
        $summary['elapsed_ms'] = number_format((microtime(true) - $started) * 1000.0, 2, '.', '');
        $summary['xml_bytes'] = is_file($xml_tmp) ? (int) filesize($xml_tmp) : 0;
        $summary['feed_snapshot_rows'] = count($snapshot_rows);
        $summary['product_state_drift_rows'] = (int) $drift_stats['rows'];
        $summary['product_state_drift_products'] = count($drift_stats['products']);
        ksort($drift_stats['fields']);
        $summary['product_state_drift_fields'] = $drift_stats['fields'];

        GunDealsAnalyticsStore::replace_feed_snapshot($summary['finished_at_utc'], $snapshot_rows);

        $this->write_summary_json($summary_tmp, $summary);

        $this->publish_file($xml_tmp, $paths['xml']);
        $this->publish_file($debug_tmp, $paths['debug_csv']);
        $this->publish_file($drift_tmp, $paths['product_state_drift_csv']);
        $this->publish_file($summary_tmp, $paths['summary_json']);

        self::debug_ctx('feed generation complete', $summary);

        return $summary;
    }

    /**
     * @return array{dir:string,xml:string,debug_csv:string,product_state_drift_csv:string,summary_json:string,public_url:string}
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
            'product_state_drift_csv' => $dir . DIRECTORY_SEPARATOR . 'gundeals-feed-product-state-drift.csv',
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
     * Query product-feed source data directly instead of hydrating WC_Product objects.
     *
     * @return array<int,array<string,mixed>>
     */
    private function query_offer_source_rows(int $last_id): array
    {
        global $wpdb;

        if (!$wpdb) {
            throw new \RuntimeException('WordPress database connection is unavailable.');
        }

        $meta_keys = $this->offer_meta_keys();
        $meta_key_sql = implode(',', array_map(static function (string $key) use ($wpdb): string {
            return "'" . esc_sql($key) . "'";
        }, $meta_keys));

        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;
        $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
        $product_state = ProductStateStore::table_name();
        $term_relationships = $wpdb->term_relationships;
        $term_taxonomy = $wpdb->term_taxonomy;
        $terms = $wpdb->terms;

        $sql = "
            SELECT
                p.ID AS product_id,
                p.post_title AS title,
                p.post_name AS slug,
                lookup.sku AS lookup_sku,
                COALESCE(lookup.stock_status, stock_pm.meta_value, '') AS stock_status,
                lookup.min_price AS lookup_min_price,
                lookup.max_price AS lookup_max_price,
                MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) AS sku,
                MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) AS meta_price,
                CAST(COALESCE(MAX(ps.public_sale_price), MAX(ps.public_regular_price), MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END)) AS CHAR) AS price,
                MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END) AS meta_regular_price,
                CAST(COALESCE(MAX(ps.public_regular_price), MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END)) AS CHAR) AS regular_price,
                MAX(CASE WHEN pm.meta_key = '_sale_price' THEN pm.meta_value END) AS meta_sale_price,
                CAST(COALESCE(MAX(ps.public_sale_price), MAX(CASE WHEN pm.meta_key = '_sale_price' THEN pm.meta_value END)) AS CHAR) AS sale_price,
                MAX(CASE WHEN pm.meta_key = '_thumbnail_id' THEN pm.meta_value END) AS thumbnail_id,
                MAX(CASE WHEN pm.meta_key = '_product_image_gallery' THEN pm.meta_value END) AS gallery_ids,
                MAX(CASE WHEN pm.meta_key = '_fflhub_upc' THEN pm.meta_value END) AS meta_fflhub_upc,
                COALESCE(
                    NULLIF(MAX(ps.upc), ''),
                    MAX(CASE WHEN pm.meta_key = '_fflhub_upc' THEN pm.meta_value END)
                ) AS fflhub_upc,
                MAX(CASE WHEN pm.meta_key = '_global_unique_id' THEN pm.meta_value END) AS global_unique_id,
                MAX(CASE WHEN pm.meta_key = '_alg_ean' THEN pm.meta_value END) AS alg_ean,
                MAX(CASE WHEN pm.meta_key = '_wpm_gtin_code' THEN pm.meta_value END) AS wpm_gtin_code,
                MAX(CASE WHEN pm.meta_key = '_ts_gtin' THEN pm.meta_value END) AS ts_gtin,
                MAX(CASE WHEN pm.meta_key = '_wc_gpf_gtin' THEN pm.meta_value END) AS wc_gpf_gtin,
                MAX(CASE WHEN pm.meta_key = '_upc' THEN pm.meta_value END) AS upc_meta,
                MAX(CASE WHEN pm.meta_key = 'upc' THEN pm.meta_value END) AS upc_plain,
                MAX(CASE WHEN pm.meta_key = 'gtin' THEN pm.meta_value END) AS gtin,
                MAX(CASE WHEN pm.meta_key = '_fflhub_managed' THEN pm.meta_value END) AS meta_fflhub_managed,
                CASE WHEN MAX(ps.product_id) IS NOT NULL THEN '1' ELSE MAX(CASE WHEN pm.meta_key = '_fflhub_managed' THEN pm.meta_value END) END AS fflhub_managed,
                MAX(CASE WHEN pm.meta_key = '_fflhub_primary_distributor' THEN pm.meta_value END) AS meta_source,
                COALESCE(NULLIF(MAX(ps.distributor_id), ''), MAX(CASE WHEN pm.meta_key = '_fflhub_primary_distributor' THEN pm.meta_value END)) AS source,
                MAX(CASE WHEN pm.meta_key = '_fflhub_last_sync_at' THEN pm.meta_value END) AS meta_last_stock_update,
                COALESCE(CAST(MAX(ps.source_offer_normalized_at) AS CHAR), CAST(MAX(ps.selected_at) AS CHAR), MAX(CASE WHEN pm.meta_key = '_fflhub_last_sync_at' THEN pm.meta_value END)) AS last_stock_update,
                MAX(CASE WHEN pm.meta_key = '_fflhub_last_true_cost' THEN pm.meta_value END) AS meta_true_cost,
                CAST(COALESCE(MAX(ps.landed_cost), MAX(CASE WHEN pm.meta_key = '_fflhub_last_true_cost' THEN pm.meta_value END)) AS CHAR) AS true_cost,
                MAX(CASE WHEN pm.meta_key = '_fflhub_last_dealer_price' THEN pm.meta_value END) AS meta_dealer_price,
                CAST(COALESCE(MAX(ps.dealer_price), MAX(CASE WHEN pm.meta_key = '_fflhub_last_dealer_price' THEN pm.meta_value END)) AS CHAR) AS dealer_price,
                MAX(CASE WHEN pm.meta_key = '_fflhub_last_map' THEN pm.meta_value END) AS meta_map_price,
                CAST(COALESCE(MAX(ps.map_price), MAX(CASE WHEN pm.meta_key = '_fflhub_last_map' THEN pm.meta_value END)) AS CHAR) AS map_price,
                MAX(CASE WHEN pm.meta_key = '_fflhub_last_msrp' THEN pm.meta_value END) AS meta_msrp,
                CAST(COALESCE(MAX(ps.msrp), MAX(CASE WHEN pm.meta_key = '_fflhub_last_msrp' THEN pm.meta_value END)) AS CHAR) AS msrp,
                MAX(CASE WHEN pm.meta_key = '_fflhub_last_computed_price' THEN pm.meta_value END) AS meta_computed_price,
                CAST(COALESCE(MAX(ps.computed_sell_price), MAX(CASE WHEN pm.meta_key = '_fflhub_last_computed_price' THEN pm.meta_value END)) AS CHAR) AS computed_price,
                CAST(COALESCE(MAX(ps.map_applicable), 0) AS CHAR) AS map_applicable,
                MAX(CASE WHEN pm.meta_key = '_fflhub_map_policy' THEN pm.meta_value END) AS meta_map_policy,
                COALESCE(NULLIF(MAX(ps.map_visibility_policy), ''), MAX(CASE WHEN pm.meta_key = '_fflhub_map_policy' THEN pm.meta_value END)) AS map_policy,
                MAX(CASE WHEN pm.meta_key = '_fflhub_markup_mode' THEN pm.meta_value END) AS meta_markup_mode,
                COALESCE(NULLIF(MAX(ps.pricing_mode), ''), MAX(CASE WHEN pm.meta_key = '_fflhub_markup_mode' THEN pm.meta_value END)) AS markup_mode,
                MAX(CASE WHEN pm.meta_key = '_fflhub_map_real_price_mode' THEN pm.meta_value END) AS map_real_price_mode,
                MAX(CASE WHEN pm.meta_key = '_fflhub_map_real_price_offset' THEN pm.meta_value END) AS map_real_price_offset,
                MAX(CASE WHEN pm.meta_key = '_fflhub_map_real_price_percent' THEN pm.meta_value END) AS map_real_price_percent,
                MAX(CASE WHEN pm.meta_key = '_fflhub_map_real_price_fixed_profit' THEN pm.meta_value END) AS meta_map_real_price_fixed_profit,
                CAST(COALESCE(MAX(ps.pricing_fixed_profit), MAX(CASE WHEN pm.meta_key = '_fflhub_map_real_price_fixed_profit' THEN pm.meta_value END)) AS CHAR) AS map_real_price_fixed_profit,
                MAX(CASE WHEN pm.meta_key = '_fflhub_map_real_price_free_shipping_override' THEN pm.meta_value END) AS meta_map_real_price_free_shipping_override,
                CAST(COALESCE(MAX(ps.quote_free_shipping_override), MAX(CASE WHEN pm.meta_key = '_fflhub_map_real_price_free_shipping_override' THEN pm.meta_value END)) AS CHAR) AS map_real_price_free_shipping_override,
                MAX(CASE WHEN pm.meta_key = '_fflhub_last_shipping_cost' THEN pm.meta_value END) AS meta_shipping_cost,
                CAST(COALESCE(MAX(ps.shipping_cost), MAX(CASE WHEN pm.meta_key = '_fflhub_last_shipping_cost' THEN pm.meta_value END)) AS CHAR) AS shipping_cost,
                MAX(CASE WHEN pm.meta_key = '_fflhub_shipping_weight' THEN pm.meta_value END) AS meta_shipping_weight_oz,
                CAST(COALESCE(MAX(ps.shipping_weight_oz), MAX(CASE WHEN pm.meta_key = '_fflhub_shipping_weight' THEN pm.meta_value END)) AS CHAR) AS shipping_weight_oz,
                MAX(CASE WHEN pm.meta_key = '_fflhub_shipping_length_in' THEN pm.meta_value END) AS meta_shipping_length_in,
                CAST(COALESCE(MAX(ps.shipping_length_in), MAX(CASE WHEN pm.meta_key = '_fflhub_shipping_length_in' THEN pm.meta_value END)) AS CHAR) AS shipping_length_in,
                MAX(CASE WHEN pm.meta_key = '_fflhub_shipping_width_in' THEN pm.meta_value END) AS meta_shipping_width_in,
                CAST(COALESCE(MAX(ps.shipping_width_in), MAX(CASE WHEN pm.meta_key = '_fflhub_shipping_width_in' THEN pm.meta_value END)) AS CHAR) AS shipping_width_in,
                MAX(CASE WHEN pm.meta_key = '_fflhub_shipping_height_in' THEN pm.meta_value END) AS meta_shipping_height_in,
                CAST(COALESCE(MAX(ps.shipping_height_in), MAX(CASE WHEN pm.meta_key = '_fflhub_shipping_height_in' THEN pm.meta_value END)) AS CHAR) AS shipping_height_in,
                MAX(CASE WHEN pm.meta_key = '_fflhub_ffl_required' THEN pm.meta_value END) AS meta_ffl_required,
                CAST(COALESCE(MAX(ps.ffl_required), MAX(CASE WHEN pm.meta_key = '_fflhub_ffl_required' THEN pm.meta_value END)) AS CHAR) AS ffl_required,
                MAX(CASE WHEN pm.meta_key = '_fflhub_dropship_enabled' THEN pm.meta_value END) AS meta_dropship_enabled,
                CAST(COALESCE(MAX(ps.dropship_enabled), MAX(CASE WHEN pm.meta_key = '_fflhub_dropship_enabled' THEN pm.meta_value END)) AS CHAR) AS dropship_enabled,
                CASE WHEN MAX(ps.product_id) IS NOT NULL THEN '1' ELSE '0' END AS product_state_exists
            FROM {$posts} p
            LEFT JOIN {$lookup} lookup
                ON lookup.product_id = p.ID
            LEFT JOIN {$product_state} ps
                ON ps.product_id = p.ID
               AND ps.status = 'active'
            LEFT JOIN {$postmeta} stock_pm
                ON stock_pm.post_id = p.ID
               AND stock_pm.meta_key = '_stock_status'
            LEFT JOIN {$postmeta} pm
                ON pm.post_id = p.ID
               AND pm.meta_key IN ({$meta_key_sql})
            WHERE p.ID > %d
              AND p.post_type = 'product'
              AND p.post_status = 'publish'
              AND (lookup.stock_status = 'instock' OR stock_pm.meta_value = 'instock')
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
            GROUP BY
                p.ID,
                p.post_title,
                p.post_name,
                lookup.sku,
                lookup.stock_status,
                lookup.min_price,
                lookup.max_price,
                stock_pm.meta_value
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
     * @return string[]
     */
    private function offer_meta_keys(): array
    {
        return [
            '_sku',
            '_price',
            '_regular_price',
            '_sale_price',
            '_thumbnail_id',
            '_product_image_gallery',
            ProductMeta::FFLHUB_UPC_META,
            '_global_unique_id',
            '_alg_ean',
            '_wpm_gtin_code',
            '_ts_gtin',
            '_wc_gpf_gtin',
            '_upc',
            'upc',
            'gtin',
            ProductMeta::FFLHUB_MANAGED_META,
            ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META,
            ProductMeta::FFLHUB_LAST_SYNC_META,
            ProductMeta::FFLHUB_LAST_TRUE_COST_META,
            ProductMeta::FFLHUB_LAST_DEALER_PRICE_META,
            ProductMeta::FFLHUB_LAST_MAP_META,
            ProductMeta::FFLHUB_LAST_MSRP_META,
            ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META,
            ProductMeta::FFLHUB_MAP_POLICY_META,
            ProductMeta::FFLHUB_MARKUP_MODE_META,
            ProductMeta::FFLHUB_MAP_REAL_PRICE_MODE_META,
            ProductMeta::FFLHUB_MAP_REAL_PRICE_OFFSET_META,
            ProductMeta::FFLHUB_MAP_REAL_PRICE_PERCENT_META,
            ProductMeta::FFLHUB_MAP_REAL_PRICE_FIXED_PROFIT_META,
            ProductMeta::FFLHUB_MAP_REAL_PRICE_FREE_SHIPPING_OVERRIDE_META,
            ProductMeta::FFLHUB_LAST_SHIPPING_COST_META,
            ProductMeta::FFLHUB_SHIPPING_WEIGHT_META,
            ProductMeta::FFLHUB_SHIPPING_LENGTH_IN_META,
            ProductMeta::FFLHUB_SHIPPING_WIDTH_IN_META,
            ProductMeta::FFLHUB_SHIPPING_HEIGHT_IN_META,
            ProductMeta::FFLHUB_FFL_REQUIRED_META,
            ProductMeta::FFLHUB_DROPSHIP_ENABLED_META,
        ];
    }

    /**
     * @param int[] $product_ids
     * @return array{brands:array<int,array<string,array<int,string>>>,categories:array<int,array<int,string>>}
     */
    private function query_term_maps(array $product_ids): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $product_ids)));
        if (empty($ids) || !$wpdb) {
            return ['brands' => [], 'categories' => []];
        }

        $id_sql = implode(',', $ids);
        $taxonomies = array_merge(['product_cat'], $this->brand_taxonomy_candidates());
        $taxonomy_sql = implode(',', array_map(static function (string $taxonomy): string {
            return "'" . esc_sql($taxonomy) . "'";
        }, array_unique($taxonomies)));

        $rows = $wpdb->get_results("
            SELECT tr.object_id AS product_id, tt.taxonomy, t.name
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE tr.object_id IN ({$id_sql})
              AND tt.taxonomy IN ({$taxonomy_sql})
            ORDER BY t.name ASC
        ", ARRAY_A);

        $brands = [];
        $categories = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $product_id = (int) ($row['product_id'] ?? 0);
            $taxonomy = (string) ($row['taxonomy'] ?? '');
            $name = $this->clean_text((string) ($row['name'] ?? ''));
            if ($product_id <= 0 || $taxonomy === '' || $name === '') {
                continue;
            }

            if ($taxonomy === 'product_cat') {
                $categories[$product_id][] = $name;
                continue;
            }

            $brands[$product_id][$taxonomy][] = $name;
        }

        foreach ($categories as $product_id => $names) {
            $names = array_values(array_unique($names));
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
            $categories[$product_id] = $names;
        }

        return [
            'brands' => $brands,
            'categories' => $categories,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $source_rows
     * @return array<int,string>
     */
    private function query_image_url_map(array $source_rows): array
    {
        global $wpdb;

        $image_ids = [];
        $product_image_ids = [];
        foreach ($source_rows as $row) {
            $product_id = (int) ($row['product_id'] ?? 0);
            $image_id = $this->first_image_id_from_row($row);
            if ($product_id <= 0 || $image_id <= 0) {
                continue;
            }

            $product_image_ids[$product_id] = $image_id;
            $image_ids[$image_id] = $image_id;
        }

        if (empty($image_ids) || !$wpdb) {
            return [];
        }

        $id_sql = implode(',', array_values($image_ids));
        $rows = $wpdb->get_results("
            SELECT p.ID, p.guid, pm.meta_value AS attached_file
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID
               AND pm.meta_key = '_wp_attached_file'
            WHERE p.ID IN ({$id_sql})
        ", ARRAY_A);

        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        $base_url = is_array($uploads) && empty($uploads['error']) && !empty($uploads['baseurl'])
            ? rtrim((string) $uploads['baseurl'], '/')
            : '';

        $attachment_urls = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int) ($row['ID'] ?? 0);
            $attached_file = ltrim((string) ($row['attached_file'] ?? ''), '/');
            $guid = (string) ($row['guid'] ?? '');

            $url = '';
            if ($base_url !== '' && $attached_file !== '') {
                $url = $base_url . '/' . $attached_file;
            } elseif ($guid !== '') {
                $url = $guid;
            }

            $url = esc_url_raw($url);
            if ($id > 0 && is_string($url) && preg_match('#^https?://#i', $url)) {
                $attachment_urls[$id] = $url;
            }
        }

        $image_url_map = [];
        foreach ($product_image_ids as $product_id => $image_id) {
            if (!empty($attachment_urls[$image_id])) {
                $image_url_map[$product_id] = $attachment_urls[$image_id];
            }
        }

        return $image_url_map;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function first_image_id_from_row(array $row): int
    {
        $thumbnail_id = (int) ($row['thumbnail_id'] ?? 0);
        if ($thumbnail_id > 0) {
            return $thumbnail_id;
        }

        $gallery_ids = trim((string) ($row['gallery_ids'] ?? ''));
        if ($gallery_ids === '') {
            return 0;
        }

        foreach (explode(',', $gallery_ids) as $gallery_id) {
            $id = (int) trim($gallery_id);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * Product State is now the feed source of truth. This CSV is the safety rail:
     * it records every product-state value that differs from the old Woo meta
     * value that used to feed Gun.deals, without writing any product meta.
     *
     * @param resource $drift
     * @param array<string,mixed> $source_row
     * @param array{rows:int,products:array<int,int>,fields:array<string,int>} $drift_stats
     */
    private function write_product_state_drift_rows($drift, array $source_row, array &$drift_stats): void
    {
        if (!$this->to_boolish($source_row['product_state_exists'] ?? null, false)) {
            return;
        }

        $product_id = (int) ($source_row['product_id'] ?? 0);
        $upc = $this->resolve_upc_from_row($source_row);
        $title = $this->clean_text((string) ($source_row['title'] ?? ''));

        foreach ($this->product_state_drift_fields() as $field => $config) {
            $state_value = $source_row[$config['state']] ?? null;
            $legacy_value = $source_row[$config['legacy']] ?? null;
            if ($this->is_expected_resolved_map_or_msrp_drift($field, $state_value, $legacy_value)) {
                continue;
            }

            if ($this->drift_values_match((string) $config['type'], $state_value, $legacy_value, $source_row)) {
                continue;
            }

            fputcsv($drift, [
                $product_id,
                $upc,
                $field,
                $this->drift_display_value($state_value),
                $this->drift_display_value($legacy_value),
                $title,
            ]);

            $drift_stats['rows']++;
            if ($product_id > 0) {
                $drift_stats['products'][$product_id] = 1;
            }
            $drift_stats['fields'][$field] = (int) ($drift_stats['fields'][$field] ?? 0) + 1;
        }
    }

    /**
     * @return array<string,array{state:string,legacy:string,type:string}>
     */
    private function product_state_drift_fields(): array
    {
        return [
            'public_price' => ['state' => 'price', 'legacy' => 'meta_price', 'type' => 'decimal'],
            'public_regular_price' => ['state' => 'regular_price', 'legacy' => 'meta_regular_price', 'type' => 'decimal'],
            'public_sale_price' => ['state' => 'sale_price', 'legacy' => 'meta_sale_price', 'type' => 'decimal'],
            'upc' => ['state' => 'fflhub_upc', 'legacy' => 'meta_fflhub_upc', 'type' => 'digits'],
            'managed' => ['state' => 'fflhub_managed', 'legacy' => 'meta_fflhub_managed', 'type' => 'bool'],
            'distributor_id' => ['state' => 'source', 'legacy' => 'meta_source', 'type' => 'text'],
            'landed_cost' => ['state' => 'true_cost', 'legacy' => 'meta_true_cost', 'type' => 'decimal'],
            'dealer_price' => ['state' => 'dealer_price', 'legacy' => 'meta_dealer_price', 'type' => 'decimal'],
            'map_price' => ['state' => 'map_price', 'legacy' => 'meta_map_price', 'type' => 'decimal'],
            'msrp' => ['state' => 'msrp', 'legacy' => 'meta_msrp', 'type' => 'decimal'],
            'computed_sell_price' => ['state' => 'computed_price', 'legacy' => 'meta_computed_price', 'type' => 'decimal'],
            'map_policy' => ['state' => 'map_policy', 'legacy' => 'meta_map_policy', 'type' => 'map_policy'],
            'pricing_mode' => ['state' => 'markup_mode', 'legacy' => 'meta_markup_mode', 'type' => 'pricing_mode'],
            'fixed_profit' => ['state' => 'map_real_price_fixed_profit', 'legacy' => 'meta_map_real_price_fixed_profit', 'type' => 'decimal'],
            'quote_free_shipping_override' => ['state' => 'map_real_price_free_shipping_override', 'legacy' => 'meta_map_real_price_free_shipping_override', 'type' => 'bool'],
            'shipping_cost' => ['state' => 'shipping_cost', 'legacy' => 'meta_shipping_cost', 'type' => 'decimal'],
            'shipping_weight_oz' => ['state' => 'shipping_weight_oz', 'legacy' => 'meta_shipping_weight_oz', 'type' => 'decimal'],
            'shipping_length_in' => ['state' => 'shipping_length_in', 'legacy' => 'meta_shipping_length_in', 'type' => 'decimal'],
            'shipping_width_in' => ['state' => 'shipping_width_in', 'legacy' => 'meta_shipping_width_in', 'type' => 'decimal'],
            'shipping_height_in' => ['state' => 'shipping_height_in', 'legacy' => 'meta_shipping_height_in', 'type' => 'decimal'],
            'ffl_required' => ['state' => 'ffl_required', 'legacy' => 'meta_ffl_required', 'type' => 'bool'],
            'dropship_enabled' => ['state' => 'dropship_enabled', 'legacy' => 'meta_dropship_enabled', 'type' => 'bool'],
        ];
    }

    /**
     * Best-offer selection intentionally rescues missing MAP/MSRP from other
     * distributor offers. If old Woo meta was zero and product_state has a real
     * value, that drift is expected signal, not an actionable mismatch.
     *
     * @param mixed $state_value
     * @param mixed $legacy_value
     */
    private function is_expected_resolved_map_or_msrp_drift(string $field, $state_value, $legacy_value): bool
    {
        if ($field !== 'map_price' && $field !== 'msrp') {
            return false;
        }

        $state_num = $this->drift_float_or_null($state_value);
        $legacy_num = $this->drift_float_or_null($legacy_value);

        return $state_num !== null
            && $state_num > 0.0
            && $legacy_num !== null
            && abs($legacy_num) < 0.0001;
    }

    /**
     * @param mixed $state_value
     * @param mixed $legacy_value
     * @param array<string,mixed> $row
     */
    private function drift_values_match(string $type, $state_value, $legacy_value, array $row): bool
    {
        if ($type === 'decimal') {
            $state_num = $this->drift_float_or_null($state_value);
            $legacy_num = $this->drift_float_or_null($legacy_value);
            if ($state_num === null || $legacy_num === null) {
                return $state_num === null && $legacy_num === null;
            }

            return abs($state_num - $legacy_num) < 0.0001;
        }

        if ($type === 'bool') {
            return $this->to_boolish($state_value, false) === $this->to_boolish($legacy_value, false);
        }

        if ($type === 'digits') {
            return $this->digits_only($state_value) === $this->digits_only($legacy_value);
        }

        if ($type === 'map_policy') {
            return $this->normalize_drift_map_policy($state_value) === $this->normalize_drift_map_policy($legacy_value);
        }

        if ($type === 'pricing_mode') {
            return $this->normalize_drift_pricing_mode($state_value, $row, false) === $this->normalize_drift_pricing_mode($legacy_value, $row, true);
        }

        return strtolower(trim((string) $state_value)) === strtolower(trim((string) $legacy_value));
    }

    /**
     * @param mixed $value
     */
    private function drift_float_or_null($value): ?float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $num = is_numeric($raw) ? $raw : trim((string) preg_replace('/[^0-9\.\-]/', '', $raw));
        if ($num === '' || !is_numeric($num)) {
            return null;
        }

        return (float) $num;
    }

    /**
     * @param mixed $value
     */
    private function digits_only($value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        return is_string($digits) ? $digits : '';
    }

    /**
     * @param mixed $value
     */
    private function normalize_drift_map_policy($value): string
    {
        $policy = strtolower(trim((string) $value));
        if ($policy === '' || $policy === 'none') {
            return $policy;
        }

        if ($policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE || $policy === Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) {
            return $policy;
        }

        return Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
    }

    /**
     * @param mixed $value
     * @param array<string,mixed> $row
     */
    private function normalize_drift_pricing_mode($value, array $row, bool $legacy): string
    {
        $raw = strtolower(trim((string) $value));
        if (!$legacy && in_array($raw, ['global_percent', 'fixed_percent', 'fixed_price', 'fixed_profit', 'map_price'], true)) {
            return $raw;
        }

        if (!is_numeric($raw)) {
            return $raw !== '' ? $raw : 'global_percent';
        }

        $markup_mode = (int) $raw;
        if ($markup_mode === ProductMeta::MARKUP_MODE_FIXED_PCT) {
            return 'fixed_percent';
        }

        if ($markup_mode === ProductMeta::MARKUP_MODE_FIXED_PRICE) {
            return 'fixed_price';
        }

        if ($markup_mode !== ProductMeta::MARKUP_MODE_MAP_PRICE) {
            return 'global_percent';
        }

        $policy = $this->normalize_drift_map_policy($row['meta_map_policy'] ?? null);
        $real_mode_raw = $row['map_real_price_mode'] ?? '';
        $real_mode = is_numeric($real_mode_raw) ? (int) $real_mode_raw : ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED;
        if ($policy === '' || $policy === 'none') {
            return 'global_percent';
        }

        if (
            $policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE
            && $real_mode === ProductMeta::MAP_REAL_PRICE_MODE_FIXED_PROFIT
            && $this->drift_float_or_null($row['meta_map_real_price_fixed_profit'] ?? null) !== null
        ) {
            return 'fixed_profit';
        }

        if (
            $policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE
            && in_array($real_mode, [ProductMeta::MAP_REAL_PRICE_MODE_FIXED_OFFSET, ProductMeta::MAP_REAL_PRICE_MODE_PERCENTAGE], true)
        ) {
            return 'fixed_price';
        }

        if ($policy === Options::MAP_POLICY_EMAIL_FOR_QUOTE && $real_mode === ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED) {
            return 'global_percent';
        }

        return 'map_price';
    }

    /**
     * @param mixed $value
     */
    private function drift_display_value($value): string
    {
        return trim((string) $value);
    }

    /**
     * @param array<string,mixed> $source_row
     * @param array{brands:array<int,array<string,array<int,string>>>,categories:array<int,array<int,string>>} $term_maps
     * @param array<int,string> $image_url_map
     * @return array<string,mixed>
     */
    private function build_offer_row(array $source_row, array $term_maps, array $image_url_map): array
    {
        $product_id = (int) ($source_row['product_id'] ?? 0);
        $actual_price = $this->resolve_price_from_row($source_row);
        $price_hide = $this->resolve_price_hide_from_row($source_row);
        $feed_price = $this->resolve_feed_price($actual_price, $source_row);
        $shipping_charge = $actual_price > 0.0 ? $this->customer_shipping_charge_for_row($source_row, $actual_price) : 0.0;

        $row = [
            'product_id' => $product_id,
            'title' => $this->clean_text((string) ($source_row['title'] ?? '')),
            'sku' => $this->clean_text((string) (($source_row['sku'] ?? '') !== '' ? $source_row['sku'] : ($source_row['lookup_sku'] ?? ''))),
            'upc' => $this->resolve_upc_from_row($source_row),
            'brand' => $this->resolve_brand_from_maps($product_id, $term_maps),
            'category' => implode(', ', $term_maps['categories'][$product_id] ?? []),
            'price' => $feed_price > 0.0 ? number_format($feed_price, 2, '.', '') : '',
            'price_hide' => $price_hide,
            'stock_status' => $this->clean_text((string) ($source_row['stock_status'] ?? '')),
            'shipping_info' => $this->format_shipping_info($shipping_charge),
            'shipping_charge' => number_format(max(0.0, $shipping_charge), 2, '.', ''),
            'included' => false,
            'skip_reason' => '',
            'product_url' => $this->resolve_product_url($product_id),
            'image_url' => $image_url_map[$product_id] ?? '',
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

    /**
     * @return string[]
     */
    private function brand_taxonomy_candidates(): array
    {
        return [
            'product_brand',
            'pwb-brand',
            'yith_product_brand',
            'woocommerce_brand',
            'product_brands',
            'pa_brand',
        ];
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
        foreach ([
            'fflhub_upc',
            'global_unique_id',
            'alg_ean',
            'wpm_gtin_code',
            'ts_gtin',
            'wc_gpf_gtin',
            'upc_meta',
            'upc_plain',
            'gtin',
        ] as $key) {
            $value = $this->clean_text((string) ($row[$key] ?? ''));
            $value = preg_replace('/\D+/', '', $value);
            $value = is_string($value) ? trim($value) : '';
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array{brands:array<int,array<string,array<int,string>>>,categories:array<int,array<int,string>>} $term_maps
     */
    private function resolve_brand_from_maps(int $product_id, array $term_maps): string
    {
        $brand_terms = $term_maps['brands'][$product_id] ?? [];
        foreach ($this->brand_taxonomy_candidates() as $taxonomy) {
            if (empty($brand_terms[$taxonomy]) || !is_array($brand_terms[$taxonomy])) {
                continue;
            }

            $name = $this->clean_text((string) reset($brand_terms[$taxonomy]));
            if ($name !== '') {
                return $name;
            }
        }

        return '';
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
            $row['lookup_min_price'] ?? null,
            $row['lookup_max_price'] ?? null,
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
        if ($this->to_boolish($row['product_state_exists'] ?? null, false)) {
            return $this->first_positive_float([
                $row['computed_price'] ?? null,
                $row['sale_price'] ?? null,
                $row['regular_price'] ?? null,
                $row['price'] ?? null,
                $row['lookup_min_price'] ?? null,
                $row['lookup_max_price'] ?? null,
            ]);
        }

        if ($this->markup_mode_from_row($row) !== ProductMeta::MARKUP_MODE_MAP_PRICE) {
            return null;
        }

        $map_base = $this->to_positive_float($row['map_price'] ?? null);
        if ($map_base === null) {
            return null;
        }

        $real_mode = $this->map_real_price_mode_from_row($row);
        if ($real_mode === ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED) {
            return $this->first_positive_float([
                $row['computed_price'] ?? null,
                $row['regular_price'] ?? null,
                $row['price'] ?? null,
                $row['lookup_min_price'] ?? null,
                $row['lookup_max_price'] ?? null,
            ]);
        }

        if ($real_mode === ProductMeta::MAP_REAL_PRICE_MODE_FIXED_OFFSET) {
            $cost_base = $this->first_positive_float([
                $row['true_cost'] ?? null,
                $row['dealer_price'] ?? null,
            ]);
            if ($cost_base === null) {
                return null;
            }

            $offset = $this->to_non_negative_float($row['map_real_price_offset'] ?? null, 0.0);
            $real_price = round($cost_base + $offset, 2);
            return $real_price > 0.0 ? $real_price : null;
        }

        if ($real_mode === ProductMeta::MAP_REAL_PRICE_MODE_FIXED_PROFIT) {
            $cost_base = $this->first_positive_float([
                $row['true_cost'] ?? null,
                $row['dealer_price'] ?? null,
            ]);
            if ($cost_base === null) {
                return null;
            }

            $shipping_cost = $this->to_non_negative_float($row['shipping_cost'] ?? null, 0.0);
            $profit_target = $this->to_non_negative_float($row['map_real_price_fixed_profit'] ?? null, 0.0);
            $fee_fraction = $this->payment_fee_fraction();
            $denominator = 1.0 - $fee_fraction;
            if ($denominator <= 0.0) {
                return null;
            }

            $offset = ($profit_target + $shipping_cost + ($cost_base * $fee_fraction)) / $denominator;
            $real_price = round($cost_base + $offset, 2);
            return $real_price > 0.0 ? $real_price : null;
        }

        $pct = $this->to_non_negative_float($row['map_real_price_percent'] ?? null, 0.0);
        $discount = $map_base * ($pct / 100.0);
        $real_price = round($map_base - $discount, 2);
        return $real_price > 0.0 ? $real_price : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function customer_shipping_charge_for_row(array $row, float $line_revenue): float
    {
        if ($this->map_real_price_free_shipping_override_enabled($row)) {
            return 0.0;
        }

        $shipping_cost_total = $this->estimate_shipping_cost_total_for_row($row);
        if ($shipping_cost_total <= 0.0) {
            return 0.0;
        }

        $fee_fraction = $this->payment_fee_fraction();
        $true_cost = $this->to_non_negative_float($row['true_cost'] ?? null, 0.0);
        $profit_net_total = ($line_revenue * (1.0 - $fee_fraction)) - $true_cost;
        $free_threshold = $this->free_shipping_cost_threshold(
            $profit_net_total,
            Options::get_free_shipping_max_profit_spend_percent()
        );

        if ($free_threshold > 0.0 && $shipping_cost_total <= ($free_threshold + 0.0001)) {
            $customer_charge = 0.0;
        } else {
            $customer_charge = $fee_fraction >= 0.99
                ? $shipping_cost_total
                : ($shipping_cost_total / (1.0 - $fee_fraction));
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
     */
    private function estimate_shipping_cost_total_for_row(array $row): float
    {
        $shipping_settings = $this->shipping_method_settings_snapshot();
        $fallback_ship = (float) ($shipping_settings['fallback_shipping'] ?? 15.0);

        $dist_id = strtolower(trim((string) ($row['source'] ?? '')));
        if ($dist_id === '') {
            return max(0.0, $fallback_ship);
        }

        $dist_lane_fee = $this->resolve_distributor_lane_fee($row['shipping_cost'] ?? null, $fallback_ship);
        if ($dist_lane_fee <= 0.0) {
            $dist_lane_fee = max(0.0, $fallback_ship);
        }

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
                'length_in' => $length_in,
                'width_in' => $width_in,
                'height_in' => $height_in,
            ],
        ]);

        return max(0.0, (float) ($plan['total_cost'] ?? 0.0));
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
        $raw_policy = strtolower(trim((string) ($row['map_policy'] ?? '')));
        if ($this->normalize_map_policy($raw_policy) !== Options::MAP_POLICY_EMAIL_FOR_QUOTE) {
            return false;
        }

        return $this->to_boolish($row['map_real_price_free_shipping_override'] ?? null, false);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function markup_mode_from_row(array $row): int
    {
        $raw = $row['markup_mode'] ?? '';
        if ($raw === '' && (string) $raw !== '0') {
            return ProductMeta::MARKUP_MODE_GLOBAL;
        }

        return is_numeric($raw) ? (int) $raw : ProductMeta::MARKUP_MODE_GLOBAL;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function map_real_price_mode_from_row(array $row): int
    {
        $raw = $row['map_real_price_mode'] ?? '';
        $mode = ($raw === '' && (string) $raw !== '0')
            ? ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED
            : (is_numeric($raw) ? (int) $raw : ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED);

        return in_array($mode, [
            ProductMeta::MAP_REAL_PRICE_MODE_FIXED_OFFSET,
            ProductMeta::MAP_REAL_PRICE_MODE_PERCENTAGE,
            ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED,
            ProductMeta::MAP_REAL_PRICE_MODE_FIXED_PROFIT,
        ], true) ? $mode : ProductMeta::MAP_REAL_PRICE_MODE_RECOMMENDED;
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
