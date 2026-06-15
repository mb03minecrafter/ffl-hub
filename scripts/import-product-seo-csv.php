<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must be run through wp eval-file.\n");
    exit(1);
}

$cli_args = [];
if (isset($args) && is_array($args)) {
    $cli_args = array_values($args);
} elseif (isset($argv) && is_array($argv)) {
    $cli_args = array_slice($argv, 1);
}
if (($cli_args[0] ?? null) === '--') {
    array_shift($cli_args);
}

$mode = strtolower(trim((string) ($cli_args[0] ?? 'dry-run')));
$input_file = trim((string) ($cli_args[1] ?? ''));

if (!in_array($mode, ['dry-run', 'commit'], true) || $input_file === '') {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  wp eval-file scripts/import-product-seo-csv.php -- dry-run /path/to/file.csv\n");
    fwrite(STDERR, "  wp eval-file scripts/import-product-seo-csv.php -- commit /path/to/file.csv\n");
    exit(1);
}

if (!is_readable($input_file)) {
    fwrite(STDERR, "Input file is not readable: {$input_file}\n");
    exit(1);
}

$commit = ($mode === 'commit');

function fflhub_seo_csv_read_rows(string $path): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        return [];
    }

    $headers = fgetcsv($handle);
    if (!is_array($headers)) {
        fclose($handle);
        return [];
    }

    $headers = array_map(static function ($header): string {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header);
        return strtolower(trim((string) $header));
    }, $headers);

    $rows = [];
    while (($data = fgetcsv($handle)) !== false) {
        if (!is_array($data)) {
            continue;
        }

        $row = [];
        foreach ($headers as $idx => $key) {
            if ($key === '') {
                continue;
            }
            $row[$key] = $data[$idx] ?? '';
        }
        $rows[] = $row;
    }

    fclose($handle);
    return $rows;
}

function fflhub_seo_text($value): string
{
    return sanitize_text_field((string) $value);
}

function fflhub_seo_html($value): string
{
    return trim(wp_kses_post((string) $value));
}

function fflhub_seo_digits($value): string
{
    $digits = preg_replace('/\D+/', '', (string) $value);
    return is_string($digits) ? $digits : '';
}

function fflhub_seo_meta_value(array $row, string $key): string
{
    return isset($row[$key]) ? trim((string) $row[$key]) : '';
}

function fflhub_seo_update_or_delete_meta(int $post_id, string $key, string $value, bool $commit): bool
{
    $current = (string) get_post_meta($post_id, $key, true);
    if ($current === $value) {
        return false;
    }

    if ($commit) {
        if ($value === '') {
            delete_post_meta($post_id, $key);
        } else {
            update_post_meta($post_id, $key, $value);
        }
    }

    return true;
}

function fflhub_seo_related_keyphrases_json(array $row): string
{
    $items = [];
    for ($i = 1; $i <= 4; $i++) {
        $keyword = fflhub_seo_text($row['yoast_related_keyphrase_' . $i] ?? '');
        if ($keyword === '') {
            continue;
        }
        $items[] = [
            'keyword' => $keyword,
            'score' => 0,
        ];
    }

    return empty($items) ? '' : (string) wp_json_encode($items);
}

function fflhub_seo_synonyms_json(array $row): string
{
    $items = [];
    $items[] = fflhub_seo_text($row['yoast_focus_synonyms'] ?? '');
    for ($i = 1; $i <= 4; $i++) {
        $items[] = fflhub_seo_text($row['yoast_related_synonyms_' . $i] ?? '');
    }

    while (!empty($items) && end($items) === '') {
        array_pop($items);
    }

    return empty($items) ? '' : (string) wp_json_encode(array_values($items));
}

function fflhub_seo_relative_permalink(string $url): string
{
    $home = trailingslashit(home_url());
    if (strpos($url, $home) === 0) {
        $url = substr($url, strlen($home));
    } else {
        $path = wp_parse_url($url, PHP_URL_PATH);
        $url = is_string($path) ? $path : $url;
    }

    return trim((string) $url, " \t\n\r\0\x0B/");
}

function fflhub_seo_create_yoast_redirect(string $origin, string $target): array
{
    $origin = trim($origin, " \t\n\r\0\x0B/");
    $target = trim($target, " \t\n\r\0\x0B/");
    if ($origin === '' || $target === '' || $origin === $target) {
        return ['ok' => true, 'action' => 'skipped'];
    }

    if (!class_exists(WPSEO_Redirect_Manager::class) || !class_exists(WPSEO_Redirect::class)) {
        return ['ok' => false, 'action' => 'error', 'message' => 'Yoast Premium redirect classes are unavailable.'];
    }

    $manager = new WPSEO_Redirect_Manager();
    $old = new WPSEO_Redirect($origin);
    $new = new WPSEO_Redirect($origin, $target, 301, 'plain');
    $existing = $manager->get_redirect($origin);

    if ($existing instanceof WPSEO_Redirect) {
        $ok = $manager->update_redirect($old, $new);
        return [
            'ok' => (bool) $ok,
            'action' => 'updated',
            'message' => $ok ? '' : 'Yoast redirect update failed.',
        ];
    }

    $ok = $manager->create_redirect($new);
    return [
        'ok' => (bool) $ok,
        'action' => 'created',
        'message' => $ok ? '' : 'Yoast redirect creation failed.',
    ];
}

function fflhub_seo_product_upc(WC_Product $product): string
{
    if (method_exists($product, 'get_global_unique_id')) {
        return fflhub_seo_digits($product->get_global_unique_id('edit'));
    }

    return fflhub_seo_digits(get_post_meta((int) $product->get_id(), '_global_unique_id', true));
}

$rows = fflhub_seo_csv_read_rows($input_file);
if (empty($rows)) {
    fwrite(STDERR, "No CSV rows found in {$input_file}\n");
    exit(1);
}

$slug_counts = [];
foreach ($rows as $row) {
    $slug = sanitize_title((string) ($row['product_slug'] ?? ''));
    if ($slug === '') {
        continue;
    }
    $slug_counts[$slug] = ($slug_counts[$slug] ?? 0) + 1;
}

$stats = [
    'rows' => count($rows),
    'validated' => 0,
    'changed_products' => 0,
    'post_updates' => 0,
    'yoast_meta_updates' => 0,
    'image_alt_updates' => 0,
    'redirects_created' => 0,
    'redirects_updated' => 0,
    'redirects_skipped' => 0,
    'errors' => 0,
];

echo "==== FFLHub Product SEO CSV Import ====\n";
echo "Mode: {$mode}\n";
echo "Input: {$input_file}\n";
echo "Rows: " . count($rows) . "\n\n";

foreach ($rows as $index => $row) {
    $line = $index + 2;
    $post_id = (int) ($row['product_id'] ?? 0);
    $upc = fflhub_seo_digits($row['upc'] ?? '');
    $title = fflhub_seo_text($row['product_title'] ?? '');
    $slug = sanitize_title((string) ($row['product_slug'] ?? ''));
    $short = fflhub_seo_html($row['short_description'] ?? '');
    $description = fflhub_seo_html($row['description_html'] ?? '');

    echo "---- row {$line} product_id={$post_id} upc={$upc} ----\n";

    if ($post_id <= 0 || $upc === '' || $title === '' || $slug === '') {
        $stats['errors']++;
        echo "ERROR missing required product_id, upc, product_title, or product_slug.\n\n";
        continue;
    }

    if (($slug_counts[$slug] ?? 0) > 1) {
        $stats['errors']++;
        echo "ERROR duplicate product_slug in CSV: {$slug}\n\n";
        continue;
    }

    $product = wc_get_product($post_id);
    if (!$product instanceof WC_Product) {
        $stats['errors']++;
        echo "ERROR Woo product not found.\n\n";
        continue;
    }

    $post = get_post($post_id);
    if (!$post instanceof WP_Post || $post->post_type !== 'product') {
        $stats['errors']++;
        echo "ERROR post is not a product.\n\n";
        continue;
    }

    $current_upc = fflhub_seo_product_upc($product);
    if ($current_upc !== $upc) {
        $stats['errors']++;
        echo "ERROR UPC mismatch: current={$current_upc}\n\n";
        continue;
    }

    $unique_slug = wp_unique_post_slug($slug, $post_id, $post->post_status, 'product', (int) $post->post_parent);
    if ($unique_slug !== $slug) {
        $stats['errors']++;
        echo "ERROR desired slug is not unique. desired={$slug} wordpress_unique={$unique_slug}\n\n";
        continue;
    }

    $stats['validated']++;
    $old_slug = (string) $post->post_name;
    $old_relative = fflhub_seo_relative_permalink(get_permalink($post_id));
    $post_changed = false;

    $post_update = [
        'ID' => $post_id,
    ];
    if ((string) $post->post_title !== $title) {
        $post_update['post_title'] = $title;
        $post_changed = true;
    }
    if ((string) $post->post_name !== $slug) {
        $post_update['post_name'] = $slug;
        $post_changed = true;
    }
    if ((string) $post->post_excerpt !== $short) {
        $post_update['post_excerpt'] = $short;
        $post_changed = true;
    }
    if ((string) $post->post_content !== $description) {
        $post_update['post_content'] = $description;
        $post_changed = true;
    }

    $meta_updates = 0;
    $meta_map = [
        '_yoast_wpseo_title' => fflhub_seo_text($row['yoast_seo_title'] ?? ''),
        '_yoast_wpseo_metadesc' => fflhub_seo_text($row['yoast_meta_description'] ?? ''),
        '_yoast_wpseo_focuskw' => fflhub_seo_text($row['yoast_focus_keyphrase'] ?? ''),
        '_yoast_wpseo_focuskeywords' => fflhub_seo_related_keyphrases_json($row),
        '_yoast_wpseo_keywordsynonyms' => fflhub_seo_synonyms_json($row),
        '_yoast_wpseo_canonical' => esc_url_raw((string) ($row['yoast_canonical'] ?? '')),
        '_yoast_wpseo_bctitle' => fflhub_seo_text($row['yoast_breadcrumb_title'] ?? ''),
        '_yoast_wpseo_opengraph-title' => fflhub_seo_text($row['yoast_opengraph_title'] ?? ''),
        '_yoast_wpseo_opengraph-description' => fflhub_seo_text($row['yoast_opengraph_description'] ?? ''),
        '_yoast_wpseo_opengraph-image' => esc_url_raw((string) ($row['yoast_opengraph_image'] ?? '')),
        '_yoast_wpseo_twitter-title' => fflhub_seo_text($row['yoast_twitter_title'] ?? ''),
        '_yoast_wpseo_twitter-description' => fflhub_seo_text($row['yoast_twitter_description'] ?? ''),
        '_yoast_wpseo_twitter-image' => esc_url_raw((string) ($row['yoast_twitter_image'] ?? '')),
        '_yoast_wpseo_meta-robots-noindex' => fflhub_seo_text($row['yoast_meta_robots_noindex'] ?? ''),
        '_yoast_wpseo_meta-robots-nofollow' => fflhub_seo_text($row['yoast_meta_robots_nofollow'] ?? ''),
        '_yoast_wpseo_meta-robots-adv' => fflhub_seo_text($row['yoast_meta_robots_adv'] ?? ''),
        '_yoast_wpseo_schema_page_type' => fflhub_seo_text($row['yoast_schema_page_type'] ?? ''),
        '_yoast_wpseo_schema_article_type' => fflhub_seo_text($row['yoast_schema_article_type'] ?? ''),
    ];

    $primary_cat_id = (int) ($row['yoast_primary_product_cat_id'] ?? 0);
    if ($primary_cat_id > 0) {
        $meta_map['_yoast_wpseo_primary_product_cat'] = (string) $primary_cat_id;
    }

    $primary_brand_id = (int) ($row['yoast_primary_product_brand_id'] ?? 0);
    if ($primary_brand_id > 0) {
        $meta_map['_yoast_wpseo_primary_product_brand'] = (string) $primary_brand_id;
    }

    foreach ($meta_map as $key => $value) {
        if (fflhub_seo_update_or_delete_meta($post_id, $key, (string) $value, $commit)) {
            $meta_updates++;
        }
    }

    $image_alt_changed = false;
    $image_alt = fflhub_seo_text($row['image_alt'] ?? '');
    $image_id = (int) $product->get_image_id();
    if ($image_alt !== '' && $image_id > 0) {
        $current_alt = (string) get_post_meta($image_id, '_wp_attachment_image_alt', true);
        if ($current_alt !== $image_alt) {
            $image_alt_changed = true;
            if ($commit) {
                update_post_meta($image_id, '_wp_attachment_image_alt', $image_alt);
            }
        }
    }

    if ($commit && $post_changed) {
        $updated = wp_update_post(wp_slash($post_update), true);
        if (is_wp_error($updated)) {
            $stats['errors']++;
            echo "ERROR post update failed: " . $updated->get_error_message() . "\n\n";
            continue;
        }
        clean_post_cache($post_id);
    }

    $redirect_action = 'none';
    if ($old_slug !== '' && $old_slug !== $slug && $post->post_status === 'publish') {
        $target_relative = $commit
            ? fflhub_seo_relative_permalink(get_permalink($post_id))
            : preg_replace('/' . preg_quote($old_slug, '/') . '\/?$/', $slug, $old_relative);
        $target_relative = is_string($target_relative) ? trim($target_relative, '/') : '';

        if ($commit) {
            $redirect = fflhub_seo_create_yoast_redirect($old_relative, $target_relative);
            if (empty($redirect['ok'])) {
                $stats['errors']++;
                echo "ERROR redirect failed: " . (string) ($redirect['message'] ?? '') . "\n\n";
                continue;
            }
            $redirect_action = (string) ($redirect['action'] ?? 'created');
            update_post_meta($post_id, '_yoast_post_redirect_info', [
                'origin' => $old_relative,
                'target' => $target_relative,
                'type' => 301,
                'format' => 'plain',
            ]);
        } else {
            $redirect_action = 'would_create';
        }

        if ($redirect_action === 'created' || $redirect_action === 'would_create') {
            $stats['redirects_created']++;
        } elseif ($redirect_action === 'updated') {
            $stats['redirects_updated']++;
        } else {
            $stats['redirects_skipped']++;
        }
    }

    if ($post_changed || $meta_updates > 0 || $image_alt_changed) {
        $stats['changed_products']++;
    }
    if ($post_changed) {
        $stats['post_updates']++;
    }
    $stats['yoast_meta_updates'] += $meta_updates;
    if ($image_alt_changed) {
        $stats['image_alt_updates']++;
    }

    echo "post_changed=" . ($post_changed ? 'yes' : 'no') .
        " meta_updates={$meta_updates}" .
        " image_alt_changed=" . ($image_alt_changed ? 'yes' : 'no') .
        " redirect={$redirect_action}" .
        " old_slug={$old_slug} new_slug={$slug}\n\n";
}

echo "==== Summary ====\n";
foreach ($stats as $key => $value) {
    echo "{$key}: {$value}\n";
}

if (!$commit) {
    echo "\nDRY RUN ONLY. Run with commit to apply changes.\n";
}

if ($stats['errors'] > 0) {
    exit(1);
}
