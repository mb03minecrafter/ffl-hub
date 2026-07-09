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
if (($cli_args[0] ?? '') === '--') {
    array_shift($cli_args);
}

$mode = strtolower(trim((string)($cli_args[0] ?? 'dry-run')));
if (!in_array($mode, ['dry-run', 'commit'], true)) {
    fwrite(STDERR, "Usage: wp eval-file scripts/apply-product-category-suggestions.php -- [dry-run|commit] /path/to/reviewed.csv [optional_limit] [--backup=/path/to/backup.csv]\n");
    exit(1);
}

$csv_path = (string)($cli_args[1] ?? '');
if ($csv_path === '' || !is_readable($csv_path)) {
    fwrite(STDERR, "Readable CSV path is required.\n");
    exit(1);
}

$commit = ($mode === 'commit');
$limit = 0;
$backup_path = '';

foreach (array_slice($cli_args, 2) as $arg) {
    $arg = trim((string)$arg);
    if ($arg === '') {
        continue;
    }

    if (strncmp($arg, '--backup=', strlen('--backup=')) === 0) {
        $backup_path = substr($arg, strlen('--backup='));
        continue;
    }

    if (is_numeric($arg)) {
        $limit = max(0, (int)$arg);
    }
}

if ($backup_path === '') {
    $backup_path = '/tmp/woo-product-category-backup-' . gmdate('Ymd_His') . '.csv';
}

function fflhub_category_apply_log(string $message): void
{
    if (defined('WP_CLI') && WP_CLI) {
        WP_CLI::line($message);
        return;
    }

    echo $message . PHP_EOL;
}

function fflhub_category_apply_is_approved($value): bool
{
    return in_array(strtolower(trim((string)$value)), ['1', 'yes', 'y', 'true', 'approved'], true);
}

function fflhub_category_apply_clean_header(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
    return trim(trim((string)$header), "\"'");
}

function fflhub_category_apply_parse_ids($value): array
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[|,;\s]+/', $raw);
    $ids = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '' || !ctype_digit($part)) {
            continue;
        }
        $id = (int)$part;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

function fflhub_category_apply_term_exists(int $term_id): bool
{
    $term = get_term($term_id, 'product_cat');
    return $term && !is_wp_error($term);
}

function fflhub_category_apply_term_paths(array $term_ids): array
{
    $paths = [];
    foreach ($term_ids as $term_id) {
        $term = get_term((int)$term_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            continue;
        }

        $names = [$term->name];
        $parent_id = (int)$term->parent;
        while ($parent_id > 0) {
            $parent = get_term($parent_id, 'product_cat');
            if (!$parent || is_wp_error($parent)) {
                break;
            }
            array_unshift($names, $parent->name);
            $parent_id = (int)$parent->parent;
        }
        $paths[] = implode(' > ', $names);
    }

    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
    return $paths;
}

function fflhub_category_apply_current_ids(int $product_id): array
{
    $ids = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
    if (is_wp_error($ids)) {
        return [];
    }

    return array_values(array_unique(array_map('intval', $ids)));
}

function fflhub_category_apply_with_parents(array $term_ids): array
{
    $final = [];
    foreach ($term_ids as $term_id) {
        $term_id = (int)$term_id;
        if ($term_id <= 0 || !fflhub_category_apply_term_exists($term_id)) {
            continue;
        }

        $final[] = $term_id;
        $ancestors = get_ancestors($term_id, 'product_cat');
        foreach ($ancestors as $ancestor_id) {
            $ancestor_id = (int)$ancestor_id;
            if ($ancestor_id > 0 && fflhub_category_apply_term_exists($ancestor_id)) {
                $final[] = $ancestor_id;
            }
        }
    }

    $final = array_values(array_unique($final));
    sort($final, SORT_NUMERIC);
    return $final;
}

function fflhub_category_apply_uncategorized_id(): int
{
    static $uncategorized_id = null;
    if ($uncategorized_id !== null) {
        return $uncategorized_id;
    }

    $term = get_term_by('slug', 'uncategorized', 'product_cat');
    $uncategorized_id = ($term && !is_wp_error($term)) ? (int)$term->term_id : 0;
    return $uncategorized_id;
}

function fflhub_category_apply_row_value(array $row, string $key): string
{
    return isset($row[$key]) ? trim((string)$row[$key]) : '';
}

$handle = fopen($csv_path, 'rb');
if (!$handle) {
    fwrite(STDERR, "Unable to open CSV: {$csv_path}\n");
    exit(1);
}

$headers = fgetcsv($handle);
if (!is_array($headers)) {
    fclose($handle);
    fwrite(STDERR, "CSV is empty: {$csv_path}\n");
    exit(1);
}
$headers = array_map('fflhub_category_apply_clean_header', $headers);

$required_columns = ['product_id'];
foreach ($required_columns as $column) {
    if (!in_array($column, $headers, true)) {
        fclose($handle);
        fwrite(STDERR, "CSV missing required column: {$column}\n");
        exit(1);
    }
}

$backup_handle = null;
if ($commit) {
    $backup_handle = fopen($backup_path, 'wb');
    if (!$backup_handle) {
        fclose($handle);
        fwrite(STDERR, "Unable to write backup CSV: {$backup_path}\n");
        exit(1);
    }
    fputcsv($backup_handle, [
        'product_id',
        'status',
        'title',
        'before_category_ids',
        'before_category_paths',
        'after_category_ids',
        'after_category_paths',
    ]);
}

$stats = [
    'rows' => 0,
    'selected_for_apply' => 0,
    'not_selected_ignored' => 0,
    'changed' => 0,
    'unchanged' => 0,
    'skipped' => 0,
    'errors' => 0,
];
$skip_reasons = [];
$processed = 0;

while (($data = fgetcsv($handle)) !== false) {
    $stats['rows']++;
    $data = array_slice(array_pad($data, count($headers), ''), 0, count($headers));
    $row = array_combine($headers, $data);
    if (!is_array($row)) {
        $stats['skipped']++;
        $skip_reasons['malformed_row'] = ($skip_reasons['malformed_row'] ?? 0) + 1;
        continue;
    }

    $approved_value = fflhub_category_apply_row_value($row, 'approved');
    if ($approved_value === '') {
        $approved_value = fflhub_category_apply_row_value($row, 'apply');
    }

    if (!fflhub_category_apply_is_approved($approved_value)) {
        $stats['not_selected_ignored']++;
        continue;
    }

    $stats['selected_for_apply']++;
    if ($limit > 0 && $processed >= $limit) {
        continue;
    }

    $processed++;
    $product_id = (int)fflhub_category_apply_row_value($row, 'product_id');
    $post = $product_id > 0 ? get_post($product_id) : null;
    if (!$post || $post->post_type !== 'product') {
        $stats['skipped']++;
        $reason = 'invalid_product';
        $skip_reasons[$reason] = ($skip_reasons[$reason] ?? 0) + 1;
        fflhub_category_apply_log("#{$product_id} skipped: {$reason}");
        continue;
    }

    $current_ids = fflhub_category_apply_current_ids($product_id);
    $remove_ids = fflhub_category_apply_parse_ids(fflhub_category_apply_row_value($row, 'remove_category_ids'));
    $add_ids = fflhub_category_apply_parse_ids(fflhub_category_apply_row_value($row, 'add_category_ids'));
    $suggested_ids = fflhub_category_apply_parse_ids(fflhub_category_apply_row_value($row, 'suggested_category_ids'));

    $all_referenced_ids = array_values(array_unique(array_merge($remove_ids, $add_ids, $suggested_ids)));
    foreach ($all_referenced_ids as $term_id) {
        if (!fflhub_category_apply_term_exists((int)$term_id)) {
            $stats['skipped']++;
            $reason = 'missing_term_' . (int)$term_id;
            $skip_reasons[$reason] = ($skip_reasons[$reason] ?? 0) + 1;
            fflhub_category_apply_log("#{$product_id} skipped: {$reason}");
            continue 2;
        }
    }

    $final_ids = $current_ids;
    if ($remove_ids) {
        $final_ids = array_values(array_diff($final_ids, $remove_ids));
    }
    if ($add_ids) {
        $final_ids = array_values(array_unique(array_merge($final_ids, $add_ids)));
    }

    if (!$add_ids && !$remove_ids && $suggested_ids) {
        $final_ids = $suggested_ids;
    }

    $remove_uncategorized = in_array(strtolower(fflhub_category_apply_row_value($row, 'remove_uncategorized')), ['1', 'yes', 'y', 'true'], true);
    $uncategorized_id = fflhub_category_apply_uncategorized_id();
    if ($remove_uncategorized && $uncategorized_id > 0 && count(array_diff($final_ids, [$uncategorized_id])) > 0) {
        $final_ids = array_values(array_diff($final_ids, [$uncategorized_id]));
    }

    $final_ids = fflhub_category_apply_with_parents($final_ids);
    if (!$final_ids) {
        $stats['skipped']++;
        $reason = 'empty_final_categories';
        $skip_reasons[$reason] = ($skip_reasons[$reason] ?? 0) + 1;
        fflhub_category_apply_log("#{$product_id} skipped: {$reason}");
        continue;
    }

    $current_sorted = $current_ids;
    sort($current_sorted, SORT_NUMERIC);
    if ($current_sorted === $final_ids) {
        $stats['unchanged']++;
        continue;
    }

    $before_paths = fflhub_category_apply_term_paths($current_sorted);
    $after_paths = fflhub_category_apply_term_paths($final_ids);

    fflhub_category_apply_log(sprintf(
        '#%d %s: %s => %s',
        $product_id,
        $commit ? 'update' : 'would update',
        implode('|', $before_paths),
        implode('|', $after_paths)
    ));

    if ($commit) {
        fputcsv($backup_handle, [
            $product_id,
            $post->post_status,
            html_entity_decode(get_the_title($product_id), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            implode('|', $current_sorted),
            implode('|', $before_paths),
            implode('|', $final_ids),
            implode('|', $after_paths),
        ]);

        $result = wp_set_object_terms($product_id, $final_ids, 'product_cat', false);
        if (is_wp_error($result)) {
            $stats['errors']++;
            $reason = 'wp_set_object_terms_failed';
            $skip_reasons[$reason] = ($skip_reasons[$reason] ?? 0) + 1;
            fflhub_category_apply_log("#{$product_id} error: " . $result->get_error_message());
            continue;
        }
    }

    $stats['changed']++;
}

fclose($handle);
if (is_resource($backup_handle)) {
    fclose($backup_handle);
}

fflhub_category_apply_log('Mode: ' . $mode);
fflhub_category_apply_log('CSV: ' . $csv_path);
if ($commit) {
    fflhub_category_apply_log('Backup: ' . $backup_path);
}
foreach ($stats as $key => $value) {
    fflhub_category_apply_log(ucwords(str_replace('_', ' ', $key)) . ': ' . $value);
}

if ($skip_reasons) {
    fflhub_category_apply_log('Skip/error reasons:');
    foreach ($skip_reasons as $reason => $count) {
        fflhub_category_apply_log('  ' . $reason . ': ' . $count);
    }
}
