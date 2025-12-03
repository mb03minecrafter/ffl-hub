<?php
/**
 * Plugin Name: FFL Hub
 * Description: A WooCommerce extension for firearm-friendly dropshipping, starting with RSR and Lipsey's.
 * Version: 0.1.0
 * Author: Your Name
 * Text Domain: ffl-hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Autoload Composer dependencies (e.g. Lipsey's API client).
 * Guarded so it won't fatal if vendor/ doesn't exist yet.
 */
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require __DIR__ . '/vendor/autoload.php';
}


/**
 * Plugin constants.
 * Define these first so all included classes can rely on them.
 */
if ( ! defined( 'FFLHUB_PLUGIN_FILE' ) ) {
    define( 'FFLHUB_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'FFLHUB_PLUGIN_PATH' ) ) {
    define( 'FFLHUB_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'FFLHUB_PLUGIN_URL' ) ) {
    define( 'FFLHUB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'FFLHUB_PLUGIN_VERSION' ) ) {
    define( 'FFLHUB_PLUGIN_VERSION', '1.0.0' );
}


/**
 * Load the main plugin class.
 * This class will in turn load the rest of the plugin files.
 */
require_once FFLHUB_PLUGIN_PATH . 'includes/class-fflhub-plugin.php';

/**
 * Activation hook.
 *
 * Per WordPress docs, this must be registered in the main plugin file
 * using the main plugin file path (here, __FILE__ / FFLHUB_PLUGIN_FILE).
 */
register_activation_hook(
    FFLHUB_PLUGIN_FILE,
    array( 'FFLHub_Plugin', 'activate' )
);

/**
 * Deactivation hook.
 *
 * We delegate to a static method on our main plugin class so it can
 * handle cron cleanup, etc., after loading the required classes.
 */
register_deactivation_hook(
    FFLHUB_PLUGIN_FILE,
    array( 'FFLHub_Plugin', 'deactivate' )
);

/**
 * Initialize the main plugin singleton after all plugins are loaded.
 */
add_action(
    'plugins_loaded',
    function () {
        if ( class_exists( 'FFLHub_Plugin' ) ) {
            FFLHub_Plugin::instance();
        }
    }
);
