<?php

use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\UpcLookupResult;
use FFLHub\Distributor\Product\DistributorProductHelper;
use FFLHub\Plugin;

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

$mode = strtolower(trim((string)($cli_args[0] ?? 'dry-run')));
$input_file = trim((string)($cli_args[1] ?? ''));
$third_arg = trim((string)($cli_args[2] ?? ''));
$status_mode = strtolower($third_arg !== '' ? $third_arg : 'draft');

$valid_modes = ['export', 'dry-run', 'commit'];
if (!in_array($mode, $valid_modes, true) || $input_file === '' || ($mode !== 'export' && !in_array($status_mode, ['draft', 'publish'], true))) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  wp eval-file bulk-create-products-from-upcs.php -- export /path/to/upcs.txt /path/to/source-export.csv\n");
    fwrite(STDERR, "  wp eval-file bulk-create-products-from-upcs.php -- [dry-run|commit] /path/to/upcs-or-copy.csv [draft|publish]\n");
    fwrite(STDERR, "Input can be plain UPCs, one per line, or CSV with headers: upc,title,short_description,description,seo_title,seo_keyword,seo_meta_description,image_alt\n");
    exit(1);
}

$export_file = '';
if ($mode === 'export') {
    $export_file = $third_arg;
    if ($export_file === '') {
        fwrite(STDERR, "Export mode requires an output CSV path.\n");
        exit(1);
    }
    $status_mode = 'draft';
}

if ($mode === 'export' && file_exists($export_file) && !is_writable($export_file)) {
    fwrite(STDERR, "Export file exists but is not writable: {$export_file}\n");
    exit(1);
}

if (!is_readable($input_file)) {
    fwrite(STDERR, "Input file is not readable: {$input_file}\n");
    exit(1);
}

$commit = ($mode === 'commit');
$target_status = ($status_mode === 'publish') ? 'publish' : 'draft';

function fflhub_bulk_product_digits($value): string
{
    $digits = preg_replace('/\D+/', '', (string)$value);
    return is_string($digits) ? $digits : '';
}

function fflhub_bulk_clean_html($value): string
{
    $value = is_scalar($value) ? (string)$value : '';
    return trim(wp_kses_post($value));
}

function fflhub_bulk_clean_plain_text($value): string
{
    $value = is_scalar($value) ? (string)$value : '';
    return sanitize_text_field($value);
}

function fflhub_bulk_read_rows(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $first = isset($lines[0]) ? trim((string)$lines[0]) : '';
    $is_csv = (stripos($first, 'upc') !== false && strpos($first, ',') !== false);
    $rows = [];

    if (!$is_csv) {
        foreach ($lines as $line) {
            $upc = fflhub_bulk_product_digits($line);
            if ($upc === '') {
                continue;
            }
            $rows[] = ['upc' => $upc];
        }
        return $rows;
    }

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
        $header = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header);
        return strtolower(trim(is_string($header) ? $header : ''));
    }, $headers);

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

        $upc = fflhub_bulk_product_digits($row['upc'] ?? '');
        if ($upc === '') {
            continue;
        }
        $row['upc'] = $upc;
        $rows[] = $row;
    }

    fclose($handle);
    return $rows;
}

function fflhub_bulk_find_product_id_by_upc(string $upc): ?int
{
    $upc = fflhub_bulk_product_digits($upc);
    if ($upc === '') {
        return null;
    }

    $ids = get_posts([
        'post_type' => ['product', 'product_variation'],
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'fields' => 'ids',
        'numberposts' => 1,
        'meta_key' => '_global_unique_id',
        'meta_value' => $upc,
    ]);

    if (is_array($ids) && !empty($ids)) {
        return (int)$ids[0];
    }

    return null;
}

function fflhub_bulk_select_offer(UpcLookupResult $lookup): ?DistributorOffer
{
    // Mirrors DistributorProductsPage search display selection:
    // cheapest-in-stock -> cheapest-any -> first offer.
    $offer = $lookup->cheapest_in_stock();
    if ($offer instanceof DistributorOffer) {
        return $offer;
    }

    $offer = $lookup->cheapest_any();
    if ($offer instanceof DistributorOffer) {
        return $offer;
    }

    $offers = $lookup->offers();
    $first = reset($offers);
    return ($first instanceof DistributorOffer) ? $first : null;
}

function fflhub_bulk_offer_summary(UpcLookupResult $lookup): string
{
    $parts = [];
    foreach ($lookup->offers() as $dist_id => $offer) {
        if (!$offer instanceof DistributorOffer) {
            continue;
        }
        $cost = $offer->get_selection_cost();
        $parts[] = sprintf(
            '%s cost=%s stock=%s dropship=%s',
            (string)$dist_id,
            $cost === null ? '-' : number_format((float)$cost, 2, '.', ''),
            $offer->is_in_stock() ? 'yes' : 'no',
            !empty($offer->product->dropship_enabled) ? 'yes' : 'no'
        );
    }
    return implode('; ', $parts);
}

function fflhub_bulk_category_path(DistributorProductPayload $payload): string
{
    $category = $payload->recommended_category ?? null;
    if (!is_array($category) || empty($category)) {
        return '';
    }

    return implode(' > ', array_map(static function ($part): string {
        return trim((string)$part);
    }, $category));
}

function fflhub_bulk_export_lookup_rows(array $rows, $handler, string $export_file): void
{
    $fh = fopen($export_file, 'wb');
    if (!$fh) {
        fwrite(STDERR, "Could not open export file for writing: {$export_file}\n");
        exit(1);
    }

    $headers = [
        'upc',
        'lookup_status',
        'selected_dist_id',
        'selected_label',
        'selected_sku',
        'selected_brand',
        'selected_name',
        'selected_description',
        'category_path',
        'price',
        'true_cost',
        'shipping_cost',
        'map',
        'msrp',
        'quantity',
        'ffl_required',
        'sot_required',
        'dropship_enabled',
        'primary_image',
        'all_images',
        'offers_summary',
        'error',
    ];
    fputcsv($fh, $headers);

    $stats = [
        'rows' => 0,
        'found' => 0,
        'errors' => 0,
    ];

    foreach ($rows as $row) {
        $stats['rows']++;
        $upc = fflhub_bulk_product_digits($row['upc'] ?? '');
        $base_error_row = array_fill_keys($headers, '');
        $base_error_row['upc'] = $upc;

        $lookup = DistributorProductHelper::get_upc_lookup_result_from_distributors($handler, $upc, true);
        if (is_wp_error($lookup)) {
            $stats['errors']++;
            $base_error_row['lookup_status'] = 'error';
            $base_error_row['error'] = $lookup->get_error_message();
            fputcsv($fh, array_values($base_error_row));
            continue;
        }

        if (!($lookup instanceof UpcLookupResult) || empty($lookup->offers())) {
            $stats['errors']++;
            $base_error_row['lookup_status'] = 'no_offers';
            $base_error_row['error'] = 'No distributor offers found.';
            fputcsv($fh, array_values($base_error_row));
            continue;
        }

        $offer = fflhub_bulk_select_offer($lookup);
        if (!($offer instanceof DistributorOffer) || !($offer->product instanceof DistributorProductPayload)) {
            $stats['errors']++;
            $base_error_row['lookup_status'] = 'no_selectable_offer';
            $base_error_row['offers_summary'] = fflhub_bulk_offer_summary($lookup);
            $base_error_row['error'] = 'Distributor offers exist, but no selectable product payload was found.';
            fputcsv($fh, array_values($base_error_row));
            continue;
        }

        $stats['found']++;
        $payload = $offer->product;
        $images = array_values(array_filter(array_map('strval', (array)$payload->image_urls)));
        $primary_image = $payload->get_primary_image_url();

        fputcsv($fh, [
            $upc,
            'found',
            (string)$offer->distributor_id,
            (string)$offer->label,
            (string)$payload->sku,
            (string)($payload->brand ?? ''),
            (string)$payload->name,
            (string)$payload->description,
            fflhub_bulk_category_path($payload),
            number_format((float)$payload->price, 4, '.', ''),
            number_format((float)$payload->true_cost, 4, '.', ''),
            number_format((float)$payload->shipping_cost, 4, '.', ''),
            number_format((float)$payload->map, 4, '.', ''),
            number_format((float)$payload->msrp, 4, '.', ''),
            (string)(int)$payload->quantity,
            $payload->ffl_required ? '1' : '0',
            $payload->sot_required ? '1' : '0',
            $payload->dropship_enabled ? '1' : '0',
            (string)($primary_image ?? ''),
            implode(' | ', $images),
            fflhub_bulk_offer_summary($lookup),
            '',
        ]);
    }

    fclose($fh);

    echo "==== FFLHub UPC Lookup Export ====\n";
    echo "Input: {$GLOBALS['input_file']}\n";
    echo "Export: {$export_file}\n";
    foreach ($stats as $key => $value) {
        echo "{$key}: {$value}\n";
    }
}

function fflhub_bulk_apply_copy_to_product(WC_Product $product, array $row, string $target_status, bool $commit): array
{
    $changed = [];

    $title = fflhub_bulk_clean_plain_text($row['title'] ?? '');
    $short = fflhub_bulk_clean_html($row['short_description'] ?? ($row['short_desc'] ?? ''));
    $description = fflhub_bulk_clean_html($row['description'] ?? '');
    $seo_title = fflhub_bulk_clean_plain_text($row['seo_title'] ?? ($row['yoast_title'] ?? ''));
    $seo_keyword = fflhub_bulk_clean_plain_text($row['seo_keyword'] ?? ($row['focus_keyword'] ?? ''));
    $seo_meta = fflhub_bulk_clean_plain_text($row['seo_meta_description'] ?? ($row['meta_description'] ?? ''));
    $image_alt = fflhub_bulk_clean_plain_text($row['image_alt'] ?? '');
    if ($image_alt === '' && $seo_keyword !== '') {
        $image_alt = $seo_keyword;
    }

    if ($title !== '' && $product->get_name() !== $title) {
        $changed[] = 'title';
        if ($commit) {
            $product->set_name($title);
        }
    }

    if ($short !== '' && trim((string)$product->get_short_description()) !== $short) {
        $changed[] = 'short_description';
        if ($commit) {
            $product->set_short_description($short);
        }
    }

    if ($description !== '' && trim((string)$product->get_description()) !== $description) {
        $changed[] = 'description';
        if ($commit) {
            $product->set_description($description);
        }
    }

    if ($product->get_status() !== $target_status) {
        $changed[] = 'status:' . $target_status;
        if ($commit) {
            $product->set_status($target_status);
        }
    }

    if ($commit && !empty($changed)) {
        $product->save();
    }

    $product_id = (int)$product->get_id();
    if ($seo_title !== '' && get_post_meta($product_id, '_yoast_wpseo_title', true) !== $seo_title) {
        $changed[] = 'seo_title';
        if ($commit) {
            update_post_meta($product_id, '_yoast_wpseo_title', $seo_title);
        }
    }

    if ($seo_keyword !== '' && get_post_meta($product_id, '_yoast_wpseo_focuskw', true) !== $seo_keyword) {
        $changed[] = 'seo_keyword';
        if ($commit) {
            update_post_meta($product_id, '_yoast_wpseo_focuskw', $seo_keyword);
        }
    }

    if ($seo_meta !== '' && get_post_meta($product_id, '_yoast_wpseo_metadesc', true) !== $seo_meta) {
        $changed[] = 'seo_meta_description';
        if ($commit) {
            update_post_meta($product_id, '_yoast_wpseo_metadesc', $seo_meta);
        }
    }

    if ($image_alt !== '') {
        $image_ids = [];
        $featured_id = (int)$product->get_image_id();
        if ($featured_id > 0) {
            $image_ids[] = $featured_id;
        }
        foreach ((array)$product->get_gallery_image_ids() as $gallery_id) {
            $gallery_id = (int)$gallery_id;
            if ($gallery_id > 0) {
                $image_ids[] = $gallery_id;
            }
        }
        $image_ids = array_values(array_unique($image_ids));

        foreach ($image_ids as $image_id) {
            if ((string)get_post_meta($image_id, '_wp_attachment_image_alt', true) === $image_alt) {
                continue;
            }
            $changed[] = 'image_alt:' . (string)$image_id;
            if ($commit) {
                update_post_meta($image_id, '_wp_attachment_image_alt', $image_alt);
            }
        }
    }

    return $changed;
}

$rows = fflhub_bulk_read_rows($input_file);
if (empty($rows)) {
    fwrite(STDERR, "No usable UPC rows found in {$input_file}\n");
    exit(1);
}

$plugin = Plugin::instance();
$handler = $plugin->distributor_handler ?? null;
if (!$handler) {
    fwrite(STDERR, "Distributor handler is not available.\n");
    exit(1);
}

if ($mode === 'export') {
    fflhub_bulk_export_lookup_rows($rows, $handler, $export_file);
    exit(0);
}

$seen = [
    'rows' => 0,
    'found' => 0,
    'created_or_existing' => 0,
    'copy_changed' => 0,
    'errors' => 0,
];

echo "==== FFLHub Bulk Product Creation ====\n";
echo "Mode: {$mode}\n";
echo "Input: {$input_file}\n";
echo "Target status: {$target_status}\n";
echo "Rows: " . count($rows) . "\n\n";

foreach ($rows as $row) {
    $seen['rows']++;
    $upc = fflhub_bulk_product_digits($row['upc'] ?? '');
    echo "---- UPC {$upc} ----\n";

    $lookup = DistributorProductHelper::get_upc_lookup_result_from_distributors($handler, $upc, true);
    if (is_wp_error($lookup)) {
        $seen['errors']++;
        echo "ERROR lookup: " . $lookup->get_error_message() . "\n\n";
        continue;
    }

    if (!($lookup instanceof UpcLookupResult) || empty($lookup->offers())) {
        $seen['errors']++;
        echo "ERROR no distributor offers found.\n\n";
        continue;
    }

    $seen['found']++;
    $offer = fflhub_bulk_select_offer($lookup);
    if (!($offer instanceof DistributorOffer)) {
        $seen['errors']++;
        echo "ERROR distributor offers exist, but no selectable offer was found.\n";
        echo "Offers: " . fflhub_bulk_offer_summary($lookup) . "\n\n";
        continue;
    }

    echo "Selected: {$offer->distributor_id} ({$offer->label})\n";
    echo "Offers: " . fflhub_bulk_offer_summary($lookup) . "\n";

    $product_id = fflhub_bulk_find_product_id_by_upc($upc);
    $created_now = false;

    if ($product_id === null) {
        if ($commit) {
            $result = DistributorProductHelper::create_woo_product_from_payload(
                $upc,
                $offer->product,
                (string)$offer->distributor_id,
                $lookup->offers()
            );

            if (is_wp_error($result)) {
                $seen['errors']++;
                echo "ERROR create: " . $result->get_error_message() . "\n\n";
                continue;
            }

            $product_id = fflhub_bulk_find_product_id_by_upc($upc);
            $created_now = true;
        } else {
            echo "DRY RUN would create draft product from selected distributor offer.\n\n";
            continue;
        }
    }

    if ($product_id === null || $product_id <= 0) {
        $seen['errors']++;
        echo "ERROR product creation finished but product ID could not be resolved by UPC.\n\n";
        continue;
    }

    $product = wc_get_product($product_id);
    if (!$product instanceof WC_Product) {
        $seen['errors']++;
        echo "ERROR product #{$product_id} could not be loaded.\n\n";
        continue;
    }

    $seen['created_or_existing']++;
    $changes = fflhub_bulk_apply_copy_to_product($product, $row, $target_status, $commit);
    if (!empty($changes)) {
        $seen['copy_changed']++;
    }

    echo ($created_now ? "Created" : "Existing") . " product_id={$product_id} status=" . $product->get_status() . "\n";
    echo "Copy/meta changes: " . (empty($changes) ? 'none' : implode(', ', $changes)) . "\n";
    echo "Edit: " . get_edit_post_link($product_id, '') . "\n\n";
}

echo "==== Summary ====\n";
foreach ($seen as $key => $value) {
    echo "{$key}: {$value}\n";
}

if (!$commit) {
    echo "\nDRY RUN ONLY. Run with commit to create/update products.\n";
}
