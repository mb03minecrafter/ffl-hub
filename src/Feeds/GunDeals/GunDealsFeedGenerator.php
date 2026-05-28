<?php

namespace FFLHub\Feeds\GunDeals;

use FFLHub\Product\ProductMeta;
use FFLHub\Util\DebugLogUtil;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

final class GunDealsFeedGenerator
{
    private const DEBUG_CONST = 'FFLHUB_GUNDEALS_FEED_DEBUG';
    private const LOG_PREFIX = '[FFLHub][GunDealsFeed]';
    private const XML_NAMESPACE = 'https://api.gunengine.com/ingest/XMLSchema/feed/v2/offers';
    private const SHIPPING_INFO = 'Free Shipping, No Sales Tax';
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
            'stock_status',
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

        $page = 1;
        do {
            $product_ids = $this->query_product_ids($page);
            foreach ($product_ids as $product_id) {
                $summary['products_scanned']++;
                $row = $this->build_offer_row((int) $product_id);

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

            $page++;
        } while (count($product_ids) === self::BATCH_SIZE);

        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
        fclose($debug);

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
     * @return int[]
     */
    private function query_product_ids(int $page): array
    {
        if (!function_exists('wc_get_products')) {
            throw new \RuntimeException('WooCommerce is not available; wc_get_products() is missing.');
        }

        $ids = wc_get_products([
            'status' => 'publish',
            'limit' => self::BATCH_SIZE,
            'page' => max(1, $page),
            'orderby' => 'ID',
            'order' => 'ASC',
            'return' => 'ids',
        ]);

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    /**
     * @return array<string,mixed>
     */
    private function build_offer_row(int $product_id): array
    {
        $row = [
            'product_id' => $product_id,
            'title' => '',
            'sku' => '',
            'upc' => '',
            'brand' => '',
            'category' => '',
            'price' => '',
            'stock_status' => '',
            'included' => false,
            'skip_reason' => '',
            'product_url' => '',
            'image_url' => '',
            'source' => '',
            'last_stock_update' => '',
        ];

        $post = get_post($product_id);
        if (!$post || $post->post_type !== 'product' || $post->post_status !== 'publish') {
            return $this->skip($row, 'not_published_product');
        }

        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!($product instanceof WC_Product)) {
            return $this->skip($row, 'product_unavailable');
        }

        $row['title'] = $this->clean_text($product->get_name());
        $row['sku'] = $this->clean_text((string) $product->get_sku());
        $row['upc'] = $this->resolve_upc($product);
        $row['brand'] = $this->resolve_brand($product);
        $row['category'] = implode(', ', $this->resolve_categories($product_id));
        $row['price'] = $this->resolve_price($product);
        $row['stock_status'] = $this->clean_text((string) $product->get_stock_status());
        $row['product_url'] = $this->resolve_product_url($product_id);
        $row['image_url'] = $this->resolve_image_url($product);
        $row['source'] = $this->clean_text((string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true));
        $row['last_stock_update'] = $this->clean_text((string) $product->get_meta(ProductMeta::FFLHUB_LAST_SYNC_META, true));

        if ((string) $product->get_catalog_visibility() === 'hidden') {
            return $this->skip($row, 'catalog_hidden');
        }

        if (!$product->is_purchasable()) {
            return $this->skip($row, 'not_purchasable');
        }

        if ($product->is_on_backorder()) {
            return $this->skip($row, 'backorder');
        }

        if (!$product->is_in_stock() || strtolower((string) $product->get_stock_status()) !== 'instock') {
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
        $row['skip_reason'] = '';

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

    private function resolve_upc(WC_Product $product): string
    {
        $keys = [
            ProductMeta::FFLHUB_UPC_META,
            '_global_unique_id',
            '_alg_ean',
            '_wpm_gtin_code',
            '_ts_gtin',
            '_wc_gpf_gtin',
            '_upc',
            'upc',
            'gtin',
        ];

        foreach ($keys as $key) {
            $value = $this->clean_text((string) $product->get_meta($key, true));
            $value = preg_replace('/\D+/', '', $value);
            $value = is_string($value) ? trim($value) : '';
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolve_brand(WC_Product $product): string
    {
        $product_id = $product->get_id();
        foreach ($this->brand_taxonomy_candidates() as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_the_terms($product_id, $taxonomy);
            if (!is_array($terms) || empty($terms)) {
                continue;
            }

            $term = reset($terms);
            if ($term instanceof \WP_Term) {
                $name = $this->clean_text($term->name);
                if ($name !== '') {
                    return $name;
                }
            }
        }

        $attribute_brand = $this->clean_text((string) $product->get_attribute('pa_brand'));
        if ($attribute_brand !== '') {
            return $attribute_brand;
        }

        foreach (['brand', '_brand', 'manufacturer', '_manufacturer'] as $key) {
            $value = $this->clean_text((string) $product->get_meta($key, true));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
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

    /**
     * @return string[]
     */
    private function resolve_categories(int $product_id): array
    {
        $terms = get_the_terms($product_id, 'product_cat');
        if (!is_array($terms) || empty($terms)) {
            return [];
        }

        $names = [];
        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $name = $this->clean_text($term->name);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    private function resolve_price(WC_Product $product): string
    {
        $price = $product->get_price();
        if (!is_numeric($price)) {
            return '';
        }

        $price = (float) $price;
        if ($price <= 0.0) {
            return '';
        }

        return number_format($price, 2, '.', '');
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
            'utm_medium' => 'referral',
            'utm_campaign' => 'gundeals_feed',
        ], $url);
    }

    private function resolve_image_url(WC_Product $product): string
    {
        $image_id = (int) $product->get_image_id();
        if ($image_id <= 0) {
            $gallery_ids = $product->get_gallery_image_ids();
            $image_id = !empty($gallery_ids) ? (int) reset($gallery_ids) : 0;
        }

        if ($image_id <= 0) {
            return '';
        }

        $url = wp_get_attachment_image_url($image_id, 'full');
        if (!is_string($url) || $url === '') {
            return '';
        }

        $url = esc_url_raw($url);
        return is_string($url) && preg_match('#^https?://#i', $url) ? $url : '';
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
            (string) $row['stock_status'],
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
        $this->write_text_element($writer, 'price', (string) $row['price']);
        $this->write_text_element($writer, 'shippingInfo', self::SHIPPING_INFO);

        if ((string) $row['image_url'] !== '') {
            $this->write_text_element($writer, 'imageUrl', (string) $row['image_url']);
        }

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
