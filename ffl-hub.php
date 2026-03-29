<?php
/**
 * Plugin Name: FFL Hub
 * Description: A WooCommerce extension for firearm-friendly dropshipping, starting with RSR and Lipsey's.
 * Version: 1.0.0
 * Author: Matthew Bickham
 * Text Domain: ffl-hub
 */

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Plugin;

/**
 * -------------------------------------------------------------------------
 * Composer Autoloader
 * -------------------------------------------------------------------------
 * If the plugin is installed with Composer dependencies (vendor/ present),
 * load the autoloader so classes resolve via PSR-4.
 */
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

/**
 * -------------------------------------------------------------------------
 * Core Plugin Constants
 * -------------------------------------------------------------------------
 * These are used throughout the codebase for resolving paths/urls and for
 * displaying/reporting the plugin version.
 */
define('FFLHUB_PLUGIN_FILE', __FILE__);
define('FFLHUB_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FFLHUB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FFLHUB_PLUGIN_VERSION', '1.0.0');

/**
 * -------------------------------------------------------------------------
 * Debug / Profiling Flags
 * -------------------------------------------------------------------------
 * These are intentionally constants (not options) so they can be toggled per
 * environment without needing DB writes.
 *
 * NOTE: Some services read specific flags (e.g., CartCompliance uses its own).
 */
if (!function_exists('fflhub_define_bool')) {
    /**
     * Define a boolean constant only when not already provided by wp-config.php.
     */
    function fflhub_define_bool($name, $default): void
    {
        if (!defined($name)) {
            define($name, (bool) $default);
        }
    }
}

fflhub_define_bool('FFLHUB_CRON_DEBUG', true);
fflhub_define_bool('FFLHUB_ADMIN_DEBUG', true);

// Checkout / cart compliance.
fflhub_define_bool('FFLHUB_CART_COMPLIANCE_DEBUG', true);
fflhub_define_bool('FFLHUB_CART_COMPLIANCE_PROFILE', true);

// Order placement pipeline.
fflhub_define_bool('FFLHUB_ORDERING_DRY_RUN', true);

// Distributor API debugging.
fflhub_define_bool('FFLHUB_RSR_API_DEBUG', true);
fflhub_define_bool('FFLHUB_RSR_API_DEBUG_RAW', true);
fflhub_define_bool('FFLHUB_LIPSEYS_DEBUG', true);



// Order orchestration / state machine.
fflhub_define_bool('FFLHUB_PLACE_ORCH_DEBUG', true);
fflhub_define_bool('FFLHUB_DEBUG_PLACE_DISPATCH', true);
fflhub_define_bool('FFLHUB_STATE_MACHINE_DEBUG', true);
fflhub_define_bool('FFLHUB_PLACE_ORDER_JOB_RUNNER_DEBUG', true);
fflhub_define_bool('FFLHUB_TRASH_ORDER_JOBS_DEBUG', true);

// Shipping / tracking.
fflhub_define_bool('FFLHUB_DEBUG_SHIPPING', true);
fflhub_define_bool('FFLHUB_DEALER_SHIPPING_FORCE_NO_COOLDOWN', false);
fflhub_define_bool('FFLHUB_DAVIDSONS_INVENTORY_FORCE_NO_COOLDOWN', false);


//init profiling
fflhub_define_bool('FFLHUB_DEBUG_BOOT', true);



fflhub_define_bool('FFLHUB_ZANDERS_SOAP_DEBUG', true);
fflhub_define_bool('FFLHUB_ZANDERS_DEBUG', true);

/**
 * -------------------------------------------------------------------------
 * i18n + Woo Checkout Requirements
 * -------------------------------------------------------------------------
 * - Loads text domain for translations.
 * - Forces WooCommerce checkout phone field to required, because at least one
 *   distributor may require a phone number for fulfillment.
 */
add_action('init', function (): void {
    load_plugin_textdomain('ffl-hub', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // Ensure checkout phone field is required for downstream distributor needs. This was the only way I could get it to force requirement 
    if (get_option('woocommerce_checkout_phone_field') !== 'required') {
        update_option('woocommerce_checkout_phone_field', 'required');
    }
});

/**
 * -------------------------------------------------------------------------
 * WooCommerce Email Registration
 * -------------------------------------------------------------------------
 * Registers the Partial Shipment email class with WooCommerce.
 *
 * We key by fully-qualified class name to avoid collisions and to ensure the
 * same key is used consistently anywhere else we check for this email.
 */
add_filter('woocommerce_email_classes', function (array $emails): array {
    $class = \FFLHub\Woo\Emails\FFLHubPartialShipment::class;

    if (!isset($emails[$class])) {
        $emails[$class] = new $class();
    }

    return $emails;
});

/**
 * -------------------------------------------------------------------------
 * Activation / Deactivation
 * -------------------------------------------------------------------------
 * Delegated to Plugin class to keep the entry point thin.
 */
register_activation_hook(FFLHUB_PLUGIN_FILE, [Plugin::class, 'activate']);
register_deactivation_hook(FFLHUB_PLUGIN_FILE, [Plugin::class, 'deactivate']);

/**
 * -------------------------------------------------------------------------
 * WP-CLI Commands
 * -------------------------------------------------------------------------
 * Only registered when WP-CLI is present.
 */
if (defined('WP_CLI')) {
    \WP_CLI::add_command('fflhub upc-lookup-test', \FFLHub\CLI\UpcLookupTestCommand::class);
    \WP_CLI::add_command('fflhub audit-upc-lookup', \FFLHub\CLI\LookupAuditCommand::class);
    \WP_CLI::add_command('fflhub audit-order-splitting', \FFLHub\CLI\OrderSplitAuditCommand::class);
    \WP_CLI::add_command('fflhub audit-cart-compliance', \FFLHub\CLI\ValidateOrderAuditCommand::class);
    \WP_CLI::add_command('fflhub seed-orders', \FFLHub\CLI\SeedOrdersCommand::class);
    \WP_CLI::add_command('fflhub stress-create-products', \FFLHub\CLI\StressCreateProductsCommand::class);
}

/**
 * -------------------------------------------------------------------------
 * Bootstrap
 * -------------------------------------------------------------------------
 * Initialize the main plugin singleton after all plugins are loaded.
 */
add_action('plugins_loaded', function (): void {
    if (class_exists(Plugin::class)) {
        Plugin::instance();
    }
});
