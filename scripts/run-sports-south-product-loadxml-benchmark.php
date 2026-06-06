<?php
/**
 * WP-CLI eval-file wrapper for benchmark-sports-south-product-loadxml.php.
 *
 * Some WP-CLI builds are finicky when evaluating larger files directly.
 * Keep this entrypoint intentionally tiny and let PHP require the benchmark.
 *
 * Usage:
 *   wp --path=/var/www/deerforddefense.com --allow-root --skip-themes eval-file \
 *     wp-content/plugins/ffl-hub/scripts/run-sports-south-product-loadxml-benchmark.php -- \
 *     limit=10000 load-xml-timeout=120
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must be run through WP-CLI eval-file.\n");
    exit(1);
}

require __DIR__ . '/benchmark-sports-south-product-loadxml.php';
