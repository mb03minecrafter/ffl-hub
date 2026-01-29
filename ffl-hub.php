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
use FFLHub\Woo\Emails\FFLHubPartialShipment;

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

define('FFLHUB_CRON_DEBUG', true);
define('FFLHUB_ADMIN_DEBUG', false);



define('FFLHUB_CART_COMPLIANCE_DEBUG', false);
define('FFLHUB_CART_COMPLIANCE_PROFILE', false);

define('FFLHUB_ORDERING_DRY_RUN', false);



define('FFLHUB_RSR_API_DEBUG', false);
define('FFLHUB_RSR_API_DEBUG_RAW', false);



define('FFLHUB_LIPSEYS_DEBUG', false);


define('FFLHUB_PLACE_ORCH_DEBUG', false);
define('FFLHUB_DEBUG_PLACE_DISPATCH', false);
define('FFLHUB_STATE_MACHINE_DEBUG', false);
define('FFLHUB_PLACE_ORDER_JOB_RUNNER_DEBUG', false);
define('FFLHUB_TRASH_ORDER_JOBS_DEBUG', false);




define('FFLHUB_DEBUG_SHIPPING', false);

/**
 * Load plugin text domain. And also require phone number in the checkout fields, since our distributors sometimes require a phone number 
 */
add_action('init', function () {
    load_plugin_textdomain('ffl-hub', false, dirname(plugin_basename(__FILE__)) . '/languages');
    if (get_option('woocommerce_checkout_phone_field') !== 'required') {
        update_option('woocommerce_checkout_phone_field', 'required');
    }
});

add_filter('woocommerce_email_classes', function (array $emails): array {

    // Force mailer load safety isn’t needed here, but this ensures correct keying.
    $class = \FFLHub\Woo\Emails\FFLHubPartialShipment::class;

    if (!isset($emails[$class])) {
        $emails[$class] = new \FFLHub\Woo\Emails\FFLHubPartialShipment();
    }

    return $emails;
});


/**
 * Activation / Deactivation hooks.
 */
register_activation_hook(FFLHUB_PLUGIN_FILE, [Plugin::class, 'activate']);
register_deactivation_hook(FFLHUB_PLUGIN_FILE, [Plugin::class, 'deactivate']);


if (defined('WP_CLI')) {
    \WP_CLI::add_command('fflhub upc-lookup-test', \FFLHub\CLI\UpcLookupTestCommand::class);
}


if (defined('WP_CLI')) {
    \WP_CLI::add_command('fflhub audit-upc-lookup', \FFLHub\CLI\LookupAuditCommand::class);
}

if (defined('WP_CLI')) {
    \WP_CLI::add_command('fflhub audit-order-splitting', \FFLHub\CLI\OrderSplitAuditCommand::class);
}

if (defined('WP_CLI')) {
    \WP_CLI::add_command('fflhub audit-cart-compliance', \FFLHub\CLI\ValidateOrderAuditCommand::class);
}


if (defined('WP_CLI')) {
    \WP_CLI::add_command('fflhub seed-orders', \FFLHub\CLI\SeedOrdersCommand::class);
}

/**
 * Initialize main plugin after all plugins are loaded.
 */
add_action('plugins_loaded', function () {
    if (class_exists(Plugin::class)) {
        Plugin::instance();
    }
});
