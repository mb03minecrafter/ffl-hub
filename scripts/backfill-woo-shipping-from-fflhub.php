<?php

use FFLHub\Distributor\Product\DistributorProductHelper;
use FFLHub\Product\ProductMeta;

if (!defined('ABSPATH')) {
    if (defined('STDERR')) {
        fwrite(STDERR, "This script must be run through WP-CLI eval-file.\n");
    }
    return;
}

$mode = strtolower(trim((string) ($args[0] ?? 'dry-run')));
$dry_run = ($mode !== 'run');
$batch_size = 250;
$last_id = 0;
$scanned = 0;
$updated = 0;
$unchanged = 0;
$skipped = 0;
$printed = 0;
$max_print = 100;

if (!class_exists(DistributorProductHelper::class) || !class_exists(ProductMeta::class)) {
    fwrite(STDERR, "FFLHub classes are not loaded. Confirm the ffl-hub plugin is active.\n");
    return;
}

if (!function_exists('wc_get_product')) {
    fwrite(STDERR, "WooCommerce is not loaded. Confirm WooCommerce is active.\n");
    return;
}

echo $dry_run
    ? "DRY RUN: no products will be saved. Pass 'run' to write changes.\n"
    : "RUN MODE: products with changed Woo shipping fields will be saved.\n";

global $wpdb;

while (true) {
    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} managed
                ON managed.post_id = p.ID AND managed.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} weight_meta
                ON weight_meta.post_id = p.ID AND weight_meta.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} length_meta
                ON length_meta.post_id = p.ID AND length_meta.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} width_meta
                ON width_meta.post_id = p.ID AND width_meta.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} height_meta
                ON height_meta.post_id = p.ID AND height_meta.meta_key = %s
            WHERE p.post_type = 'product'
              AND p.post_status NOT IN ('trash', 'auto-draft')
              AND p.ID > %d
              AND (
                managed.post_id IS NOT NULL
                OR weight_meta.post_id IS NOT NULL
                OR length_meta.post_id IS NOT NULL
                OR width_meta.post_id IS NOT NULL
                OR height_meta.post_id IS NOT NULL
              )
            ORDER BY p.ID ASC
            LIMIT %d
            ",
            ProductMeta::FFLHUB_MANAGED_META,
            ProductMeta::FFLHUB_SHIPPING_WEIGHT_META,
            ProductMeta::FFLHUB_SHIPPING_LENGTH_IN_META,
            ProductMeta::FFLHUB_SHIPPING_WIDTH_IN_META,
            ProductMeta::FFLHUB_SHIPPING_HEIGHT_IN_META,
            $last_id,
            $batch_size
        )
    );

    if (empty($ids)) {
        break;
    }

    foreach ($ids as $id) {
        $id = (int) $id;
        $last_id = $id;
        $scanned++;

        $product = wc_get_product($id);
        if (!$product) {
            $skipped++;
            continue;
        }

        $before = [
            'weight' => (string) $product->get_weight('edit'),
            'length' => (string) $product->get_length('edit'),
            'width'  => (string) $product->get_width('edit'),
            'height' => (string) $product->get_height('edit'),
        ];

        $changed = DistributorProductHelper::sync_woo_shipping_from_fflhub_meta($product);
        if (!$changed) {
            $unchanged++;
            continue;
        }

        $after = [
            'weight' => (string) $product->get_weight('edit'),
            'length' => (string) $product->get_length('edit'),
            'width'  => (string) $product->get_width('edit'),
            'height' => (string) $product->get_height('edit'),
        ];

        if (!$dry_run) {
            $product->save();
        }

        $updated++;

        if ($printed < $max_print) {
            printf(
                "product_id=%d sku=%s weight %s=>%s length %s=>%s width %s=>%s height %s=>%s\n",
                $id,
                (string) $product->get_sku('edit'),
                $before['weight'] === '' ? '-' : $before['weight'],
                $after['weight'] === '' ? '-' : $after['weight'],
                $before['length'] === '' ? '-' : $before['length'],
                $after['length'] === '' ? '-' : $after['length'],
                $before['width'] === '' ? '-' : $before['width'],
                $after['width'] === '' ? '-' : $after['width'],
                $before['height'] === '' ? '-' : $before['height'],
                $after['height'] === '' ? '-' : $after['height']
            );
            $printed++;
        }
    }
}

if ($updated > $printed) {
    printf("... %d additional changed products not printed.\n", $updated - $printed);
}

printf(
    "Done. scanned=%d changed=%d unchanged=%d skipped=%d mode=%s\n",
    $scanned,
    $updated,
    $unchanged,
    $skipped,
    $dry_run ? 'dry-run' : 'run'
);
