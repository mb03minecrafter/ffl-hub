<?php
/**
 * Plugin Name: FFL Hub
 * Description: A WooCommerce extension for firearm-friendly dropshipping, starting with RSR and Lipsey's.
 * Version: 1.0.0
 * Author: Matthew Bickham
 * Text Domain: ffl-hub
 */


if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Plugin;

/**
 * Autoload Composer dependencies if present.
 */
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

/**
 * Define plugin constants.
 */
define('FFLHUB_PLUGIN_FILE', __FILE__);
define('FFLHUB_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FFLHUB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FFLHUB_PLUGIN_VERSION', '1.0.0');

define('FFLHUB_CRON_DEBUG', false);

/**
 * Load plugin text domain.
 */
add_action('init', function() {
    load_plugin_textdomain('ffl-hub', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/**
 * Activation / Deactivation hooks.
 */
register_activation_hook(FFLHUB_PLUGIN_FILE, [Plugin::class, 'activate']);
register_deactivation_hook(FFLHUB_PLUGIN_FILE, [Plugin::class, 'deactivate']);

/**
 * Initialize main plugin after all plugins are loaded.
 */
add_action('plugins_loaded', function () {
    if (class_exists(Plugin::class)) {
        Plugin::instance();
    } 
});