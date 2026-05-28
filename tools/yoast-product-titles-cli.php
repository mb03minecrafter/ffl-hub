<?php
/**
 * WP-CLI helper for safely reviewing and updating Yoast product SEO titles.
 *
 * Usage:
 *   wp --path=/var/www/deerforddefense.com --allow-root --require=/path/to/this-file.php yoast-product-titles <subcommand>
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

final class FFLHub_Yoast_Product_Titles_CLI_Command
{
    private const YOAST_TITLE_META = '_yoast_wpseo_title';

    /** @var string[] */
    private const BRAND_TAXONOMY_CANDIDATES = [
        'product_brand',
        'pwb-brand',
        'yith_product_brand',
        'wc_product_brand',
        'woocommerce_brand',
        'brand',
    ];

    /** @var string[] */
    private const UPC_META_KEYS = [
        '_global_unique_id',
        '_upc',
        'upc',
        '_product_upc',
        '_barcode',
        'barcode',
        '_gtin',
        'gtin',
        '_ean',
        'ean',
        '_alg_ean',
        '_wpm_gtin_code',
    ];

    /**
     * Inspect product taxonomies and relevant product meta keys.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Max meta keys to show. Default: 100.
     */
    public function inspect(array $args, array $assoc_args): void
    {
        global $wpdb;

        $limit = max(1, (int) ($assoc_args['limit'] ?? 100));
        $taxonomies = get_object_taxonomies('product', 'objects');
        $detected_brand_taxonomy = $this->detect_brand_taxonomy();
        $rows = [];

        foreach ($taxonomies as $taxonomy => $object) {
            $rows[] = [
                'taxonomy' => (string) $taxonomy,
                'label' => (string) ($object->labels->singular_name ?? $object->label ?? ''),
                'hierarchical' => empty($object->hierarchical) ? 'no' : 'yes',
                'public' => empty($object->public) ? 'no' : 'yes',
                'brand_candidate' => $taxonomy === $detected_brand_taxonomy ? 'yes' : 'no',
            ];
        }

        WP_CLI::line('Detected brand taxonomy: ' . ($detected_brand_taxonomy ?: '(none)'));
        WP_CLI::line('');
        WP_CLI::line('Product taxonomies:');
        \WP_CLI\Utils\format_items('table', $rows, ['taxonomy', 'label', 'hierarchical', 'public', 'brand_candidate']);

        $meta_keys = array_merge([self::YOAST_TITLE_META, '_sku'], self::UPC_META_KEYS);
        $placeholders = implode(', ', array_fill(0, count($meta_keys), '%s'));
        $like_clauses = [
            "pm.meta_key LIKE '%yoast%'",
            "pm.meta_key LIKE '%upc%'",
            "pm.meta_key LIKE '%gtin%'",
            "pm.meta_key LIKE '%ean%'",
            "pm.meta_key LIKE '%barcode%'",
        ];

        $sql = $wpdb->prepare(
            "
            SELECT pm.meta_key, COUNT(*) AS product_count
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'product'
              AND p.post_status = 'publish'
              AND (
                pm.meta_key IN ({$placeholders})
                OR " . implode(' OR ', $like_clauses) . "
              )
            GROUP BY pm.meta_key
            ORDER BY product_count DESC, pm.meta_key ASC
            LIMIT %d
            ",
            array_merge($meta_keys, [$limit])
        );

        $meta_rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        WP_CLI::line('');
        WP_CLI::line('Relevant product meta keys:');
        \WP_CLI\Utils\format_items('table', is_array($meta_rows) ? $meta_rows : [], ['meta_key', 'product_count']);
    }

    /**
     * Export published products, product data, and current Yoast SEO titles.
     *
     * ## OPTIONS
     *
     * [--output=<path>]
     * : CSV output path. Default: /tmp/product-yoast-title-export.csv
     *
     * [--limit=<number>]
     * : Optional first N products for testing.
     *
     * [--brand-taxonomy=<taxonomy>]
     * : Override detected brand taxonomy.
     */
    public function export(array $args, array $assoc_args): void
    {
        if (!function_exists('wc_get_product')) {
            WP_CLI::error('WooCommerce is not loaded; wc_get_product() is unavailable.');
        }

        $output = (string) ($assoc_args['output'] ?? '/tmp/product-yoast-title-export.csv');
        $limit = $this->limit_from_args($assoc_args);
        $brand_taxonomy = $this->brand_taxonomy_from_args($assoc_args);
        $fh = $this->open_output_csv($output);

        $headers = [
            'product_id',
            'product_title',
            'post_slug',
            'product_type',
            'sku',
            'upc',
            'upc_meta_key',
            'brand',
            'brand_source',
            'brand_taxonomy',
            'categories',
            'current_yoast_title',
            'regular_price',
            'sale_price',
            'stock_status',
            'permalink',
        ];
        fputcsv($fh, $headers);

        $query = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        $count = 0;
        foreach ($query->posts as $product_id) {
            $product_id = (int) $product_id;
            $product = wc_get_product($product_id);
            if (!$product) {
                WP_CLI::warning("Skipping {$product_id}: WooCommerce product object unavailable.");
                continue;
            }

            $title = get_the_title($product_id);
            [$brand, $brand_source] = $this->product_brand($product_id, $title, $brand_taxonomy);
            [$upc, $upc_key] = $this->first_product_meta($product_id, self::UPC_META_KEYS);

            fputcsv($fh, [
                $product_id,
                $title,
                (string) get_post_field('post_name', $product_id),
                (string) $product->get_type(),
                (string) $product->get_sku(),
                $upc,
                $upc_key,
                $brand,
                $brand_source,
                $brand_taxonomy,
                implode('; ', $this->term_names($product_id, 'product_cat')),
                (string) get_post_meta($product_id, self::YOAST_TITLE_META, true),
                (string) $product->get_regular_price(),
                (string) $product->get_sale_price(),
                (string) $product->get_stock_status(),
                get_permalink($product_id),
            ]);
            ++$count;
        }

        fclose($fh);
        WP_CLI::success("Exported {$count} products to {$output}");
        WP_CLI::line('Brand taxonomy used: ' . ($brand_taxonomy ?: '(none; title inference only)'));
    }

    /**
     * Generate reviewable SEO title suggestions from an export CSV.
     *
     * ## OPTIONS
     *
     * [--input=<path>]
     * : Export CSV. Default: /tmp/product-yoast-title-export.csv
     *
     * [--output=<path>]
     * : Suggestions CSV. Default: /tmp/product-yoast-title-suggestions.csv
     *
     * [--limit=<number>]
     * : Optional first N rows for testing.
     */
    public function suggest(array $args, array $assoc_args): void
    {
        $input = (string) ($assoc_args['input'] ?? '/tmp/product-yoast-title-export.csv');
        $output = (string) ($assoc_args['output'] ?? '/tmp/product-yoast-title-suggestions.csv');
        $limit = $this->limit_from_args($assoc_args);

        $fh = $this->open_output_csv($output);
        fputcsv($fh, [
            'product_id',
            'product_title',
            'current_yoast_title',
            'suggested_yoast_title',
            'approved_yoast_title',
            'approved',
        ]);

        $count = 0;
        foreach ($this->read_csv_assoc($input, $limit) as $row) {
            $suggested = $this->suggest_title($row);
            fputcsv($fh, [
                (string) ($row['product_id'] ?? ''),
                (string) ($row['product_title'] ?? ''),
                (string) ($row['current_yoast_title'] ?? ''),
                $suggested,
                '',
                '',
            ]);
            ++$count;
        }

        fclose($fh);
        WP_CLI::success("Wrote {$count} suggestions to {$output}");
        WP_CLI::line('Manual review required: fill approved_yoast_title and set approved to yes.');
    }

    /**
     * Update approved Yoast SEO titles from a reviewed CSV.
     *
     * ## OPTIONS
     *
     * [--input=<path>]
     * : Reviewed CSV. Default: /tmp/product-yoast-title-suggestions-reviewed.csv
     *
     * [--dry-run]
     * : Print changes without writing.
     *
     * [--commit]
     * : Required for real writes. Creates a backup first.
     *
     * [--backup=<path>]
     * : Backup CSV path for real updates. Default: timestamped /tmp file.
     *
     * [--log=<path>]
     * : Log path. Default: timestamped /tmp file.
     *
     * [--limit=<number>]
     * : Optional first N reviewed rows for testing.
     */
    public function update(array $args, array $assoc_args): void
    {
        $input = (string) ($assoc_args['input'] ?? '/tmp/product-yoast-title-suggestions-reviewed.csv');
        $limit = $this->limit_from_args($assoc_args);
        $commit = !empty($assoc_args['commit']);
        $dry_run = !empty($assoc_args['dry-run']) || !$commit;
        $timestamp = gmdate('Ymd-His');
        $backup = (string) ($assoc_args['backup'] ?? "/tmp/product-yoast-title-backup-{$timestamp}.csv");
        $log_path = (string) ($assoc_args['log'] ?? "/tmp/product-yoast-title-update-{$timestamp}.log");

        if ($dry_run) {
            WP_CLI::warning('Dry run only. No database writes will be made.');
        } else {
            $this->backup_all_published_products($backup);
            WP_CLI::success("Backup written before update: {$backup}");
        }

        $log = $this->open_log($log_path);
        $stats = [
            'changed' => 0,
            'would_change' => 0,
            'skipped' => 0,
            'warnings' => 0,
        ];

        foreach ($this->read_csv_assoc($input, $limit) as $row_number => $row) {
            $validation = $this->validate_update_row($row, (int) $row_number);
            foreach ($validation['warnings'] as $warning) {
                ++$stats['warnings'];
                $this->log_line($log, 'WARN', $warning);
                WP_CLI::warning($warning);
            }

            if (!empty($validation['skip'])) {
                ++$stats['skipped'];
                $this->log_line($log, 'SKIP', (string) $validation['reason']);
                continue;
            }

            $product_id = (int) $validation['product_id'];
            $new_title = (string) $validation['title'];
            $old_title = (string) get_post_meta($product_id, self::YOAST_TITLE_META, true);

            if ($old_title === $new_title) {
                ++$stats['skipped'];
                $this->log_line($log, 'SKIP', "product_id={$product_id} reason=unchanged title={$new_title}");
                continue;
            }

            if ($dry_run) {
                ++$stats['would_change'];
                $message = "product_id={$product_id} old={$old_title} new={$new_title}";
                $this->log_line($log, 'DRY-RUN', $message);
                WP_CLI::line("DRY-RUN: {$message}");
                continue;
            }

            update_post_meta($product_id, self::YOAST_TITLE_META, $new_title);
            ++$stats['changed'];
            $message = "product_id={$product_id} old={$old_title} new={$new_title}";
            $this->log_line($log, 'UPDATED', $message);
            WP_CLI::line("UPDATED: {$message}");
        }

        fclose($log);
        WP_CLI::success(sprintf(
            'Done. changed=%d would_change=%d skipped=%d warnings=%d log=%s',
            $stats['changed'],
            $stats['would_change'],
            $stats['skipped'],
            $stats['warnings'],
            $log_path
        ));
    }

    /**
     * Roll back Yoast SEO titles from a backup CSV.
     *
     * ## OPTIONS
     *
     * --backup=<path>
     * : Backup CSV created by the update command.
     *
     * [--dry-run]
     * : Print changes without writing.
     *
     * [--commit]
     * : Required for real writes.
     *
     * [--log=<path>]
     * : Log path. Default: timestamped /tmp file.
     *
     * [--limit=<number>]
     * : Optional first N backup rows for testing.
     */
    public function rollback(array $args, array $assoc_args): void
    {
        $backup = (string) ($assoc_args['backup'] ?? '');
        if ($backup === '') {
            WP_CLI::error('Missing required --backup=/path/to/product-yoast-title-backup-*.csv');
        }

        $limit = $this->limit_from_args($assoc_args);
        $commit = !empty($assoc_args['commit']);
        $dry_run = !empty($assoc_args['dry-run']) || !$commit;
        $timestamp = gmdate('Ymd-His');
        $log_path = (string) ($assoc_args['log'] ?? "/tmp/product-yoast-title-rollback-{$timestamp}.log");
        $log = $this->open_log($log_path);
        $changed = 0;
        $skipped = 0;

        if ($dry_run) {
            WP_CLI::warning('Rollback dry run only. No database writes will be made.');
        }

        foreach ($this->read_csv_assoc($backup, $limit) as $row_number => $row) {
            $product_id = (int) ($row['product_id'] ?? 0);
            $post = $product_id > 0 ? get_post($product_id) : null;
            if (!$post || $post->post_type !== 'product' || $post->post_status !== 'publish') {
                ++$skipped;
                $this->log_line($log, 'SKIP', "row={$row_number} product_id={$product_id} reason=invalid_product_or_not_published");
                continue;
            }

            $previous_exists = strtolower(trim((string) ($row['previous_meta_exists'] ?? ''))) === 'yes';
            $previous_title = (string) ($row['previous_yoast_title'] ?? '');
            $current_title = (string) get_post_meta($product_id, self::YOAST_TITLE_META, true);

            if ($previous_exists && $current_title === $previous_title) {
                ++$skipped;
                $this->log_line($log, 'SKIP', "product_id={$product_id} reason=unchanged");
                continue;
            }

            $message = "product_id={$product_id} current={$current_title} restore=" . ($previous_exists ? $previous_title : '(delete meta)');
            if ($dry_run) {
                ++$changed;
                $this->log_line($log, 'DRY-RUN', $message);
                WP_CLI::line("DRY-RUN: {$message}");
                continue;
            }

            if ($previous_exists) {
                update_post_meta($product_id, self::YOAST_TITLE_META, $previous_title);
            } else {
                delete_post_meta($product_id, self::YOAST_TITLE_META);
            }
            ++$changed;
            $this->log_line($log, 'RESTORED', $message);
            WP_CLI::line("RESTORED: {$message}");
        }

        fclose($log);
        WP_CLI::success("Rollback done. changed_or_would_change={$changed} skipped={$skipped} log={$log_path}");
    }

    private function limit_from_args(array $assoc_args): int
    {
        if (!isset($assoc_args['limit']) || $assoc_args['limit'] === '') {
            return 0;
        }

        return max(0, (int) $assoc_args['limit']);
    }

    private function brand_taxonomy_from_args(array $assoc_args): string
    {
        $override = trim((string) ($assoc_args['brand-taxonomy'] ?? ''));
        if ($override !== '') {
            if (!taxonomy_exists($override)) {
                WP_CLI::error("Brand taxonomy override does not exist: {$override}");
            }
            return $override;
        }

        return $this->detect_brand_taxonomy();
    }

    private function detect_brand_taxonomy(): string
    {
        foreach (self::BRAND_TAXONOMY_CANDIDATES as $taxonomy) {
            if (taxonomy_exists($taxonomy) && is_object_in_taxonomy('product', $taxonomy)) {
                return $taxonomy;
            }
        }

        $taxonomies = get_object_taxonomies('product', 'objects');
        foreach ($taxonomies as $taxonomy => $object) {
            $label = strtolower((string) ($object->label ?? ''));
            if (strpos(strtolower((string) $taxonomy), 'brand') !== false || strpos($label, 'brand') !== false) {
                return (string) $taxonomy;
            }
        }

        return '';
    }

    /**
     * @return array{0:string,1:string}
     */
    private function product_brand(int $product_id, string $title, string $brand_taxonomy): array
    {
        if ($brand_taxonomy !== '') {
            $terms = $this->term_names($product_id, $brand_taxonomy);
            if (!empty($terms)) {
                return [implode(', ', $terms), 'taxonomy'];
            }
        }

        return [$this->infer_brand_from_title($title), 'title_inferred'];
    }

    /**
     * @return string[]
     */
    private function term_names(int $post_id, string $taxonomy): array
    {
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return [];
        }

        $terms = get_the_terms($post_id, $taxonomy);
        if (!is_array($terms)) {
            return [];
        }

        return array_values(array_map(static function ($term): string {
            return (string) $term->name;
        }, $terms));
    }

    /**
     * @param string[] $keys
     * @return array{0:string,1:string}
     */
    private function first_product_meta(int $product_id, array $keys): array
    {
        foreach ($keys as $key) {
            $value = trim((string) get_post_meta($product_id, $key, true));
            if ($value !== '') {
                return [$value, $key];
            }
        }

        return ['', ''];
    }

    private function infer_brand_from_title(string $title): string
    {
        $title = $this->clean_title($title);
        if ($title === '') {
            return '';
        }

        $tokens = preg_split('/\s+/', $title);
        if (!is_array($tokens) || empty($tokens[0])) {
            return '';
        }

        $brand = $tokens[0];
        if (isset($tokens[1]) && in_array(strtolower($tokens[1]), ['&', 'and'], true) && isset($tokens[2])) {
            $brand .= ' ' . $tokens[1] . ' ' . $tokens[2];
        }

        return trim($brand);
    }

    /**
     * @param array<string,string> $row
     */
    private function suggest_title(array $row): string
    {
        $product_title = $this->clean_title((string) ($row['product_title'] ?? ''));
        $brand = $this->clean_title((string) ($row['brand'] ?? ''));
        $brand_source = (string) ($row['brand_source'] ?? '');
        $base = $product_title;

        if ($brand !== '' && $brand_source === 'taxonomy' && !$this->starts_with_ci($base, $brand)) {
            $base = $brand . ' ' . $base;
        }

        $base = $this->compact_variant_text($base);
        $standard = $base . ' | Deerford Defense';
        $for_sale = $base . ' for Sale | Deerford Defense';

        if ($this->looks_specific($base) && $this->title_length($for_sale) <= 65) {
            return $for_sale;
        }

        return $standard;
    }

    private function clean_title(string $title): string
    {
        $title = wp_strip_all_tags(html_entity_decode($title, ENT_QUOTES, 'UTF-8'));
        $title = preg_replace('/\s*[\|\-]\s*Deerford Defense\s*$/i', '', $title);
        $title = preg_replace('/\s+/', ' ', (string) $title);
        return trim((string) $title);
    }

    private function compact_variant_text(string $title): string
    {
        $title = preg_replace('/\bwith\b/i', 'w/', $title);
        $title = preg_replace('/\band\b/i', '&', (string) $title);
        $title = preg_replace('/\s+/', ' ', (string) $title);
        return trim((string) $title);
    }

    private function starts_with_ci(string $haystack, string $needle): bool
    {
        return strcasecmp(substr($haystack, 0, strlen($needle)), $needle) === 0;
    }

    private function looks_specific(string $title): bool
    {
        return (bool) preg_match('/\d/', $title)
            && (bool) preg_match('/\b(rifle|pistol|shotgun|revolver|scope|sight|optic|ammo|ammunition|barrel|upper|lower)\b/i', $title);
    }

    /**
     * @return resource
     */
    private function open_output_csv(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            WP_CLI::error("Output directory is not writable: {$dir}");
        }

        $fh = fopen($path, 'wb');
        if (!$fh) {
            WP_CLI::error("Unable to open output file: {$path}");
        }

        return $fh;
    }

    /**
     * @return resource
     */
    private function open_log(string $path)
    {
        $fh = fopen($path, 'ab');
        if (!$fh) {
            WP_CLI::error("Unable to open log file: {$path}");
        }

        return $fh;
    }

    /**
     * @param resource $fh
     */
    private function log_line($fh, string $level, string $message): void
    {
        fwrite($fh, sprintf("[%s] %s %s\n", gmdate('c'), $level, $message));
    }

    private function backup_all_published_products(string $path): void
    {
        $fh = $this->open_output_csv($path);
        fputcsv($fh, [
            'product_id',
            'product_title',
            'previous_yoast_title',
            'previous_meta_exists',
            'backed_up_at',
        ]);

        $query = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        foreach ($query->posts as $product_id) {
            $product_id = (int) $product_id;
            fputcsv($fh, [
                $product_id,
                get_the_title($product_id),
                (string) get_post_meta($product_id, self::YOAST_TITLE_META, true),
                metadata_exists('post', $product_id, self::YOAST_TITLE_META) ? 'yes' : 'no',
                gmdate('c'),
            ]);
        }

        fclose($fh);
    }

    /**
     * @param array<string,string> $row
     * @return array<string,mixed>
     */
    private function validate_update_row(array $row, int $row_number): array
    {
        $warnings = [];
        $product_id = (int) ($row['product_id'] ?? 0);
        $approved = strtolower(trim((string) ($row['approved'] ?? '')));
        $title = $this->clean_title((string) ($row['approved_yoast_title'] ?? ''));

        if ($approved !== 'yes') {
            return [
                'skip' => true,
                'reason' => "row={$row_number} product_id={$product_id} reason=not_approved",
                'warnings' => [],
            ];
        }

        if ($product_id <= 0) {
            return [
                'skip' => true,
                'reason' => "row={$row_number} reason=invalid_product_id",
                'warnings' => [],
            ];
        }

        $post = get_post($product_id);
        if (!$post) {
            return [
                'skip' => true,
                'reason' => "row={$row_number} product_id={$product_id} reason=product_missing",
                'warnings' => [],
            ];
        }

        if ($post->post_type !== 'product') {
            return [
                'skip' => true,
                'reason' => "row={$row_number} product_id={$product_id} reason=not_product post_type={$post->post_type}",
                'warnings' => [],
            ];
        }

        if ($post->post_status !== 'publish') {
            return [
                'skip' => true,
                'reason' => "row={$row_number} product_id={$product_id} reason=not_published post_status={$post->post_status}",
                'warnings' => [],
            ];
        }

        if ($title === '') {
            return [
                'skip' => true,
                'reason' => "row={$row_number} product_id={$product_id} reason=blank_approved_title",
                'warnings' => [],
            ];
        }

        $length = $this->title_length($title);
        if ($length > 65) {
            $warnings[] = "row={$row_number} product_id={$product_id} warning=title_over_65 length={$length}";
        }
        if ($length < 25) {
            $warnings[] = "row={$row_number} product_id={$product_id} warning=title_under_25 length={$length}";
        }

        return [
            'skip' => false,
            'product_id' => $product_id,
            'title' => $title,
            'warnings' => $warnings,
        ];
    }

    private function title_length(string $title): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($title, 'UTF-8');
        }

        return strlen($title);
    }

    /**
     * @return Generator<int,array<string,string>>
     */
    private function read_csv_assoc(string $path, int $limit = 0): Generator
    {
        if (!is_readable($path)) {
            WP_CLI::error("CSV is not readable: {$path}");
        }

        $fh = fopen($path, 'rb');
        if (!$fh) {
            WP_CLI::error("Unable to open CSV: {$path}");
        }

        $headers = fgetcsv($fh);
        if (!is_array($headers)) {
            fclose($fh);
            WP_CLI::error("CSV has no header row: {$path}");
        }

        $headers = array_map(static function ($header): string {
            return trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $header));
        }, $headers);

        $count = 0;
        $row_number = 1;
        while (($data = fgetcsv($fh)) !== false) {
            ++$row_number;
            if ($limit > 0 && $count >= $limit) {
                break;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = isset($data[$index]) ? (string) $data[$index] : '';
            }

            ++$count;
            yield $row_number => $row;
        }

        fclose($fh);
    }
}

WP_CLI::add_command('yoast-product-titles', 'FFLHub_Yoast_Product_Titles_CLI_Command');
